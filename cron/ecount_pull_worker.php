<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

/**
 * ecount_pull_worker.php
 * - 이카운트 재고현황(getInventoryBalance)을 조회해 우리 DB(tt_products.stock)에 반영한다.
 * - [핵심] 여기서 갱신한 재고는 ecount_queue_stock_change()를 호출하지 않는다.
 *   (이카운트 -> 우리 DB 방향 갱신이 다시 이카운트로 되돌아가는 핑퐁을 구조적으로 차단)
 * - CLI(가비아 cron 등)와 HTTP(외부 cron 서비스) 양쪽에서 실행 가능하도록
 *   "CLI이거나, 올바른 비밀 토큰을 가진 HTTP 요청"만 허용한다.
 */

$isCli = (php_sapi_name() === 'cli');

$secretFile = __DIR__ . '/../config/secrets.php';
$ECOUNT_CRON_SECRET = null;
if (is_file($secretFile)) {
    $secrets = require $secretFile;
    $ECOUNT_CRON_SECRET = $secrets['ecount_cron_secret'] ?? null;
}

$token = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
$isAuthorizedHttp = (!$isCli)
    && $ECOUNT_CRON_SECRET !== null
    && hash_equals((string)$ECOUNT_CRON_SECRET, (string)$token);

if (!$isCli && !$isAuthorizedHttp) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("인증되지 않은 요청입니다.\n");
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function ecpull_out(string $line): void
{
    global $isCli;
    echo $line . "\n";
    if (!$isCli) {
        @ob_flush();
        @flush();
    }
}

ecpull_out('================================================');
ecpull_out('[ecount_pull_worker] 시작 ' . date('Y-m-d H:i:s'));
ecpull_out('================================================');

try {
    $pdo = Database::connection();
    ensure_ecount_tables($pdo);
} catch (Throwable $e) {
    ecpull_out('[FATAL] DB 연결/테이블 준비 실패: ' . $e->getMessage());
    error_log('[ecount_pull_worker] ' . $e->getMessage());
    exit(1);
}

try {
    $client = new EcountClient($pdo);
    $raw = $client->getInventoryBalance(date('Ymd'));
} catch (Throwable $e) {
    ecpull_out('[FATAL] 이카운트 재고현황 조회 실패: ' . $e->getMessage());
    error_log('[ecount_pull_worker] 조회 실패: ' . $e->getMessage());
    try {
        $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('pull', ?, 0, 1)")
            ->execute(['조회 실패: ' . $e->getMessage()]);
    } catch (Throwable $e2) {
        error_log('[ecount_pull_worker] sync_log insert fail: ' . $e2->getMessage());
    }
    exit(1);
}

function ecount_extract_balance_list($raw): array
{
    $candidates = [
        $raw['Data']['Result'] ?? null,
        $raw['Data']['Datas'] ?? null,
        $raw['Data']['ResultDetails'] ?? null,
        $raw['Data'] ?? null,
        $raw['Result'] ?? null,
        $raw,
    ];
    foreach ($candidates as $c) {
        if (is_array($c) && isset($c[0]) && is_array($c[0])) {
            $first = $c[0];
            if (array_key_exists('PROD_CD', $first) || array_key_exists('ITEM_CD', $first)) {
                return $c;
            }
        }
    }
    return [];
}

function ecount_pick(array $row, array $keys)
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row)) return $row[$k];
    }
    return null;
}

$balances = ecount_extract_balance_list($raw);

if (!$balances) {
    ecpull_out('[경고] 이카운트 응답 구조를 인식하지 못했습니다. 원본 응답을 storage에 저장합니다.');
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/ecount_pull_raw_unknown.json', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    error_log('[ecount_pull_worker] 알 수 없는 응답 구조 — storage/ecount_pull_raw_unknown.json 확인 필요');
    try {
        $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('pull', ?, 0, 1)")
            ->execute(['응답 구조 인식 실패 — storage/ecount_pull_raw_unknown.json 저장됨']);
    } catch (Throwable $e) {
        error_log('[ecount_pull_worker] sync_log insert: ' . $e->getMessage());
    }
    exit(1);
}

ecpull_out('이카운트 재고현황 ' . count($balances) . '건 수신. 우리 DB와 비교 중...');

$successCnt = 0;
$failCnt = 0;
$skipCnt = 0;

foreach ($balances as $b) {
    $itemCd = ecount_pick($b, ['PROD_CD', 'ITEM_CD']);
    $ecountQtyRaw = ecount_pick($b, ['BAL_QTY', 'QTY', 'STOCK_QTY']);
    if (!$itemCd || $ecountQtyRaw === null) {
        $skipCnt++;
        continue;
    }
    $ecountQty = (int)$ecountQtyRaw;

    try {
        $stmt = $pdo->prepare("SELECT id, name, stock FROM tt_products WHERE ecount_item_cd = :cd LIMIT 1");
        $stmt->execute(['cd' => $itemCd]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            $skipCnt++;
            continue; // 매핑 안 된 품목은 건드리지 않음
        }

        $localQty = (int)$product['stock'];
        if ($localQty === $ecountQty) continue; // 차이 없으면 스킵

        $pdo->prepare("UPDATE tt_products SET stock = :qty, ecount_synced_at = NOW() WHERE id = :id")
            ->execute(['qty' => $ecountQty, 'id' => $product['id']]);

        // [핵심] 여기서는 ecount_queue_stock_change()를 절대 호출하지 않습니다.
        ecpull_out("  - {$itemCd} ({$product['name']}): {$localQty} -> {$ecountQty} 반영 완료");
        $successCnt++;
    } catch (Throwable $e) {
        $failCnt++;
        ecpull_out("  - {$itemCd} 반영 실패: " . $e->getMessage());
        error_log('[ecount_pull_worker] ' . $itemCd . ' 반영 실패: ' . $e->getMessage());
    }
}

try {
    $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('pull', ?, ?, ?)")
        ->execute(['재고현황 조회 반영 ' . count($balances) . '건 (스킵 ' . $skipCnt . '건)', $successCnt, $failCnt]);
} catch (Throwable $e) {
    error_log('[ecount_pull_worker] sync_log insert: ' . $e->getMessage());
}

ecpull_out('================================================');
ecpull_out("[ecount_pull_worker] 종료 — 반영 {$successCnt}건 / 실패 {$failCnt}건 / 스킵 {$skipCnt}건");
ecpull_out('================================================');

exit($failCnt > 0 ? 1 : 0);
