<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

/* 1분마다 cron으로 실행. 외부에서 직접 호출 못 하게 CLI 전용으로 제한. */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI 전용 스크립트입니다.');
}

ensure_ecount_tables();
$pdo = Database::connection();

try {
    $client = new EcountClient();
} catch (Throwable $e) {
    error_log('[ecount_push_worker] 클라이언트 초기화 실패: ' . $e->getMessage());
    exit(1);
}

// 같은 품목코드끼리 delta를 합산해서 전송 횟수를 최소화 (Save API 10초 1회 제한 대응)
$rows = $pdo->query("
    SELECT id, ecount_item_cd, delta_qty
    FROM tt_ecount_sync_queue
    WHERE status = 'pending'
    ORDER BY id ASC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { exit(0); }

$grouped = [];       // ecount_item_cd => 합산 delta
$idsByCode = [];      // ecount_item_cd => [queue id, ...]
foreach ($rows as $r) {
    $code = $r['ecount_item_cd'];
    $grouped[$code] = ($grouped[$code] ?? 0) + (int)$r['delta_qty'];
    $idsByCode[$code][] = (int)$r['id'];
}

$successCnt = 0;
$failCnt = 0;

foreach ($grouped as $itemCd => $delta) {
    if ($delta === 0) {
        // 상쇄되어 변화 없음 — 그냥 처리완료 처리
        $ids = $idsByCode[$itemCd];
        $pdo->prepare("UPDATE tt_ecount_sync_queue SET status='sent', sent_at=NOW() WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")
            ->execute($ids);
        continue;
    }

    try {
        if ($delta > 0) {
            // 입고 → 구매입력
            $client->savePurchase($itemCd, $delta);
        } else {
            // 출고 → 판매입력
            $client->saveSale($itemCd, abs($delta));
        }
        $ids = $idsByCode[$itemCd];
        $pdo->prepare("UPDATE tt_ecount_sync_queue SET status='sent', sent_at=NOW() WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")
            ->execute($ids);
        $successCnt++;
    } catch (Throwable $e) {
        $ids = $idsByCode[$itemCd];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE tt_ecount_sync_queue SET status='failed', attempt_count = attempt_count + 1, last_error = ? WHERE id IN ($in)")
            ->execute(array_merge([$e->getMessage()], $ids));
        $failCnt++;
        error_log('[ecount_push_worker] ' . $itemCd . ' 전송 실패: ' . $e->getMessage());
    }

    // Save API Rate Limit(10초당 1회) 대응 — 품목코드별 호출 사이에 대기
    sleep(10);
}

$pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('push', ?, ?, ?)")
    ->execute(['재고 변경 ' . count($grouped) . '건 처리', $successCnt, $failCnt]);
