<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

/**
 * ecount_push_worker.php
 * - 우리 DB(tt_ecount_sync_queue)에 쌓인 재고 증감 요청을 모아 이카운트에 실제 전표(구매/판매)로 반영한다.
 * - CLI(가비아 cron 등)와 HTTP(cron-job.org 같은 외부 스케줄러) 양쪽에서 실행 가능하도록
 *   "CLI이거나, 올바른 비밀 토큰을 가진 HTTP 요청"만 허용한다.
 *
 * [테스트 모드] ?dry_run=1 을 붙이면 실제 이카운트 API를 호출하지 않고
 * "무엇을 보낼 것인지"만 화면에 출력한다. 운영 전 반드시 이 모드로 먼저 확인할 것.
 */

// ---------- 1) 실행 주체 인증 ----------
$isCli = (php_sapi_name() === 'cli');

$secretFile = __DIR__ . '/../config/secrets.php';
$ECOUNT_CRON_SECRET = null;
if (is_file($secretFile)) {
    $secrets = require $secretFile; // ['ecount_cron_secret' => '...']
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

function ecp_out(string $line): void
{
    global $isCli;
    echo $line . "\n";
    if (!$isCli) {
        @ob_flush();
        @flush();
    }
}

$dryRun = (($_GET['dry_run'] ?? '') === '1') || (getenv('ECOUNT_DRY_RUN') === '1');

ecp_out('================================================');
ecp_out('[ecount_push_worker] 시작 ' . date('Y-m-d H:i:s') . ($dryRun ? ' (DRY-RUN — 실제 전송 안 함)' : ''));
ecp_out('================================================');

try {
    $pdo = Database::connection();
    ensure_ecount_tables($pdo);
} catch (Throwable $e) {
    ecp_out('[FATAL] DB 연결/테이블 준비 실패: ' . $e->getMessage());
    error_log('[ecount_push_worker] ' . $e->getMessage());
    exit(1);
}

try {
    $client = new EcountClient($pdo);
} catch (Throwable $e) {
    ecp_out('[FATAL] EcountClient 초기화 실패: ' . $e->getMessage());
    error_log('[ecount_push_worker] client init: ' . $e->getMessage());
    exit(1);
}

// ---------- 2) 대기 중인 큐 가져오기 (최대 300건) ----------
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT q.id, q.product_id, q.order_id, q.ecount_item_cd, q.delta_qty, q.reason,
               p.ecount_wh_cd, p.name AS product_name
        FROM tt_ecount_sync_queue q
        LEFT JOIN tt_products p ON p.id = q.product_id
        WHERE q.status = 'pending'
        ORDER BY q.id ASC
        LIMIT 300
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    ecp_out('[FATAL] 큐 조회 실패: ' . $e->getMessage());
    error_log('[ecount_push_worker] queue select: ' . $e->getMessage());
    exit(1);
}

if (!$rows) {
    ecp_out('대기 중인 재고 동기화 요청이 없습니다. (tt_ecount_sync_queue 비어있음)');
    ecp_out('[ecount_push_worker] 종료 — 처리 0건');
    exit(0);
}

ecp_out('대기 중인 요청 ' . count($rows) . '건 발견. 품목코드 기준으로 묶는 중...');

// ---------- 3) 품목코드별로 증감 합산 ----------
$grouped = [];
foreach ($rows as $r) {
    $itemCd = trim((string)($r['ecount_item_cd'] ?? ''));
    if ($itemCd === '') {
        try {
            $pdo->prepare("UPDATE tt_ecount_sync_queue SET status='failed', last_error=:err, attempt_count = attempt_count + 1 WHERE id = :id")
                ->execute(['err' => '이카운트 품목코드가 매핑되지 않은 상품입니다.', 'id' => $r['id']]);
        } catch (Throwable $e) {
            error_log('[ecount_push_worker] mark failed error: ' . $e->getMessage());
        }
        ecp_out("  - 큐#{$r['id']} 건너뜀: 상품#{$r['product_id']} 품목코드 미매핑");
        continue;
    }
    $whCd = trim((string)($r['ecount_wh_cd'] ?? '')) ?: '100';
    if (!isset($grouped[$itemCd])) {
        $grouped[$itemCd] = ['whCd' => $whCd, 'qty' => 0, 'name' => (string)($r['product_name'] ?? $itemCd), 'queueIds' => []];
    }
    $grouped[$itemCd]['qty'] += (int)$r['delta_qty'];
    $grouped[$itemCd]['queueIds'][] = (int)$r['id'];
}

if (!$grouped) {
    ecp_out('품목코드가 매핑된 요청이 없어 전송할 내용이 없습니다.');
    exit(0);
}

$purchaseItems = []; // 재고 증가(입고) -> Purchases
$saleItems = [];     // 재고 감소(출고) -> Sale
foreach ($grouped as $itemCd => $g) {
    if ($g['qty'] === 0) continue;
    $item = [
        'WH_CD'    => $g['whCd'],
        'PROD_CD'  => $itemCd,
        'PROD_DES' => $g['name'],
        'QTY'      => abs($g['qty']),
    ];
    if ($g['qty'] > 0) {
        $purchaseItems[$itemCd] = $item;
    } else {
        $saleItems[$itemCd] = $item;
    }
}

ecp_out('입고(구매) 전표 대상: ' . count($purchaseItems) . '건, 출고(판매) 전표 대상: ' . count($saleItems) . '건');

$successIds = [];
$failIds = [];
$successCnt = 0;
$failCnt = 0;

// ---------- 4) 입고 전표 전송 ----------
if ($purchaseItems) {
    ecp_out('--- 입고(구매) 전표 전송 ---');
    foreach ($purchaseItems as $cd => $it) {
        ecp_out("  + {$cd} ({$it['PROD_DES']}) 수량 {$it['QTY']} 입고 예정");
    }
    if ($dryRun) {
        ecp_out('  [DRY-RUN] 실제 전송하지 않음.');
    } else {
        try {
            $result = $client->savePurchaseIn(array_values($purchaseItems));
            ecp_out('  입고 전표 전송 완료. 응답: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            foreach ($grouped as $cd => $g) {
                if (isset($purchaseItems[$cd])) {
                    foreach ($g['queueIds'] as $qid) { $successIds[] = $qid; }
                    $successCnt++;
                }
            }
        } catch (Throwable $e) {
            ecp_out('  [실패] 입고 전표 전송 실패: ' . $e->getMessage());
            error_log('[ecount_push_worker] savePurchaseIn: ' . $e->getMessage());
            foreach ($purchaseItems as $cd => $it) {
                foreach ($grouped[$cd]['queueIds'] as $qid) { $failIds[$qid] = $e->getMessage(); }
                $failCnt++;
            }
        }
    }
}

// Save성 API는 10초 간격 제한이 있으므로 두 번째 호출 전에 대기
if ($purchaseItems && $saleItems && !$dryRun) {
    ecp_out('이카운트 API 호출 간격 제한(10초) 대기 중...');
    sleep(10);
}

// ---------- 5) 출고 전표 전송 ----------
if ($saleItems) {
    ecp_out('--- 출고(판매) 전표 전송 ---');
    foreach ($saleItems as $cd => $it) {
        ecp_out("  - {$cd} ({$it['PROD_DES']}) 수량 {$it['QTY']} 출고 예정");
    }
    if ($dryRun) {
        ecp_out('  [DRY-RUN] 실제 전송하지 않음.');
    } else {
        try {
            $result = $client->saveSaleOut(array_values($saleItems));
            ecp_out('  출고 전표 전송 완료. 응답: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            foreach ($grouped as $cd => $g) {
                if (isset($saleItems[$cd])) {
                    foreach ($g['queueIds'] as $qid) { $successIds[] = $qid; }
                    $successCnt++;
                }
            }
        } catch (Throwable $e) {
            ecp_out('  [실패] 출고 전표 전송 실패: ' . $e->getMessage());
            error_log('[ecount_push_worker] saveSaleOut: ' . $e->getMessage());
            foreach ($saleItems as $cd => $it) {
                foreach ($grouped[$cd]['queueIds'] as $qid) { $failIds[$qid] = $e->getMessage(); }
                $failCnt++;
            }
        }
    }
}

// ---------- 6) 큐 상태 업데이트 ----------
if (!$dryRun) {
    if ($successIds) {
        $in = implode(',', array_fill(0, count($successIds), '?'));
        try {
            $pdo->prepare("UPDATE tt_ecount_sync_queue SET status='done', last_error=NULL WHERE id IN ($in)")
                ->execute($successIds);
        } catch (Throwable $e) {
            error_log('[ecount_push_worker] mark done error: ' . $e->getMessage());
        }
    }
    foreach ($failIds as $qid => $msg) {
        try {
            $pdo->prepare("UPDATE tt_ecount_sync_queue SET status='failed', last_error=:err, attempt_count = attempt_count + 1 WHERE id = :id")
                ->execute(['err' => mb_substr($msg, 0, 150), 'id' => $qid]);
        } catch (Throwable $e) {
            error_log('[ecount_push_worker] mark failed error: ' . $e->getMessage());
        }
    }
}

// ---------- 7) 동기화 로그 기록 ----------
try {
    $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('push', ?, ?, ?)")
        ->execute([
            ($dryRun ? '[DRY-RUN] ' : '') . '입고 ' . count($purchaseItems) . '건 / 출고 ' . count($saleItems) . '건 전송',
            $successCnt,
            $failCnt,
        ]);
} catch (Throwable $e) {
    error_log('[ecount_push_worker] sync_log insert: ' . $e->getMessage());
}

ecp_out('================================================');
ecp_out("[ecount_push_worker] 종료 — 성공 {$successCnt}건 / 실패 {$failCnt}건" . ($dryRun ? ' (DRY-RUN)' : ''));
ecp_out('================================================');

exit($failCnt > 0 ? 1 : 0);
