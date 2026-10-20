<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI 전용 스크립트입니다.');
}

ensure_ecount_tables();
$pdo = Database::connection();

try {
    $client = new EcountClient();
    $balances = $client->getInventoryBalance(); // [['PROD_CD' => ..., 'BAL_QTY' => ...], ...]
} catch (Throwable $e) {
    error_log('[ecount_pull_worker] 조회 실패: ' . $e->getMessage());
    exit(1);
}

$successCnt = 0;
$failCnt = 0;

foreach ($balances as $b) {
    $itemCd = $b['PROD_CD'] ?? null;
    $ecountQty = isset($b['BAL_QTY']) ? (int)$b['BAL_QTY'] : null;
    if (!$itemCd || $ecountQty === null) continue;

    try {
        $stmt = $pdo->prepare("SELECT id, stock FROM tt_products WHERE ecount_item_cd = :cd LIMIT 1");
        $stmt->execute(['cd' => $itemCd]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) continue; // 매핑 안 된 품목은 건드리지 않음

        $localQty = (int)$product['stock'];
        if ($localQty === $ecountQty) continue; // 차이 없으면 스킵 — 불필요한 기록 방지

        $pdo->prepare("UPDATE tt_products SET stock = :qty, ecount_synced_at = NOW() WHERE id = :id")
            ->execute(['qty' => $ecountQty, 'id' => $product['id']]);

        // [핵심] 여기서는 ecount_queue_stock_change()를 절대 호출하지 않습니다.
        // 이카운트 → 우리 DB 방향 갱신이 다시 이카운트로 되돌아가는 핑퐁을 구조적으로 차단합니다.

        $successCnt++;
    } catch (Throwable $e) {
        $failCnt++;
        error_log('[ecount_pull_worker] ' . $itemCd . ' 반영 실패: ' . $e->getMessage());
    }
}

$pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('pull', ?, ?, ?)")
    ->execute(['재고현황 조회 반영 ' . count($balances) . '건', $successCnt, $failCnt]);
