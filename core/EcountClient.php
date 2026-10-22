<?php
declare(strict_types=1);

/**
 * 이카운트 Open API 클라이언트 + 동기화 테이블 관리.
 *
 * [중요] 이카운트는 재고를 "절대값으로 덮어쓰는" API가 없다. 전표(판매/구매) 기준으로
 * 증감만 반영 가능하므로, 출고(재고감소)는 Sale/SaveSale, 입고(재고증가)는
 * Purchases/SavePurchases 로 나눠서 호출한다.
 *
 * [중요] Zone/로그인 API는 10분당 1회 제한이 있어 세션을 DB에 캐싱해 재사용한다.
 * 저장(Save)성 API는 10초 간격 제한이 있으므로 한 번에 모아서(batch) 보낸다.
 *
 * [FIX] admin/api-settings.php에서 저장한 'ecount_domain' 값을 실제로 사용하도록 수정.
 *       기존에는 'sboapi'(테스트/샌드박스)로 하드코딩되어 있어, 실제 운영 계정으로
 *       연동 시도 시 Zone 조회부터 실패할 수 있었다.
 *       - 테스트(샌드박스) 계정: ecount_domain = "sboapi"
 *       - 실제 운영 계정:       ecount_domain = "oapi"
 *       (admin/api-settings.php 의 "도메인" 입력란에 그대로 입력하면 됨)
 */

function ensure_ecount_tables(PDO $pdo): void
{
    static $checked = false;
    if (sg_skip('ensure_ecount_tables', $checked)) return;

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM tt_products")->fetchAll(PDO::FETCH_COLUMN);
        $newCols = [
            'ecount_item_cd'   => "VARCHAR(30) NULL COMMENT '이카운트 품목코드'",
            'ecount_wh_cd'     => "VARCHAR(20) NULL COMMENT '이카운트 창고코드(개별지정)'",
            'ecount_synced_at' => "DATETIME NULL COMMENT '이카운트 마지막 동기화 시각'",
        ];
        foreach ($newCols as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE tt_products ADD COLUMN {$col} {$def}");
            }
        }
        try {
            $idx = $pdo->query("SHOW INDEX FROM tt_products WHERE Key_name = 'idx_ecount_item_cd'")->fetchAll();
            if (empty($idx)) {
                $pdo->exec("ALTER TABLE tt_products ADD UNIQUE INDEX idx_ecount_item_cd (ecount_item_cd)");
            }
        } catch (Throwable $e) {
            sg_error_log('[ensure_ecount_tables] idx_ecount_item_cd skip: ' . $e->getMessage());
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_sync_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NULL,
            ecount_item_cd VARCHAR(30) NOT NULL,
            delta_qty INT NOT NULL,
            reason VARCHAR(150) NOT NULL DEFAULT '',
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            attempt_count INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            INDEX idx_status_created (status, created_at),
            INDEX idx_order_id (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* 기존 설치본(= order_id 컬럼이 없는 과거 버전)에도 안전하게 추가 */
        $qCols = $pdo->query("SHOW COLUMNS FROM tt_ecount_sync_queue")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('order_id', $qCols, true)) {
            try {
                $pdo->exec("ALTER TABLE tt_ecount_sync_queue ADD COLUMN order_id INT UNSIGNED NULL AFTER product_id");
                $pdo->exec("ALTER TABLE tt_ecount_sync_queue ADD INDEX idx_order_id (order_id)");
            } catch (Throwable $e) {
                sg_error_log('[ensure_ecount_tables] order_id 컬럼 추가 실패: ' . $e->getMessage());
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_session_cache (
            id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
            zone VARCHAR(10) NULL,
            session_id VARCHAR(100) NULL,
            session_issued_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_sync_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            direction ENUM('push','pull','test') NOT NULL,
            summary VARCHAR(255) NOT NULL,
            success_count INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* 기존 설치본의 ENUM에 'test' 값이 없을 수 있으므로 보강 */
        try {
            $pdo->exec("ALTER TABLE tt_ecount_sync_log MODIFY direction ENUM('push','pull','test') NOT NULL");
        } catch (Throwable $e) {
            sg_error_log('[ensure_ecount_tables] sync_log direction enum 보강 실패: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_ecount_tables] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_ecount_tables');
}

final class EcountClient
{
    private PDO $pdo;
    private string $comCode;
    private string $userId;
    private string $apiKey;
    private string $domainBase; // 'sboapi'(테스트) 또는 'oapi'(실서버)

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        ensure_ecount_tables($pdo);

        $this->comCode = trim(get_setting('ecount_com_code', ''));
        $this->userId  = trim(get_setting('ecount_user_id', ''));
        $this->apiKey  = trim(get_setting('ecount_api_key', ''));

        // [FIX] 도메인 설정을 실제로 반영. 비어있으면 테스트(sboapi)를 기본값으로 사용.
        $domainRaw = strtolower(trim(get_setting('ecount_domain', 'sboapi')));
        $domainRaw = preg_replace('/[^a-z]/', '', $domainRaw) ?: 'sboapi';
        $this->domainBase = in_array($domainRaw, ['sboapi', 'oapi'], true) ? $domainRaw : 'sboapi';

        if ($this->comCode === '' || $this->userId === '' || $this->apiKey === '') {
            throw new RuntimeException('이카운트 연동 정보가 비어있습니다. admin/api-settings.php 에서 회사코드/사용자ID/API키를 먼저 등록하세요.');
        }
    }

    public function domainBase(): string
    {
        return $this->domainBase;
    }

    /** 세션 캐시. 발급 후 8분 이내면 재사용(10분 제한 안전마진), 아니면 재로그인. */
    private function getValidSession(): array
    {
        $row = $this->pdo->query('SELECT zone, session_id, session_issued_at FROM tt_ecount_session_cache WHERE id = 1')->fetch();
        if ($row && $row['session_id'] && $row['session_issued_at']) {
            if (time() - strtotime($row['session_issued_at']) < 8 * 60) {
                return ['zone' => $row['zone'], 'session_id' => $row['session_id']];
            }
        }
        return $this->loginAndCache();
    }

    private function loginAndCache(): array
    {
        $zoneRes = $this->httpPost(
            "https://{$this->domainBase}.ecount.com/OAPI/V2/Zone?COM_CODE=" . urlencode($this->comCode),
            ['COM_CODE' => $this->comCode]
        );
        $zone = $zoneRes['Data']['ZONE'] ?? null;
        if (!$zone) {
            throw new RuntimeException('이카운트 Zone 조회 실패 (도메인=' . $this->domainBase . '): ' . json_encode($zoneRes, JSON_UNESCAPED_UNICODE));
        }

        $loginRes = $this->httpPost("https://{$this->domainBase}{$zone}.ecount.com/OAPI/V2/OAPILogin", [
            'COM_CODE' => $this->comCode, 'USER_ID' => $this->userId,
            'API_CERT_KEY' => $this->apiKey, 'LAN_TYPE' => 'ko-KR', 'ZONE' => $zone,
        ]);
        $sessionId = $loginRes['Data']['Datas']['SESSION_ID'] ?? null;
        if (!$sessionId) {
            throw new RuntimeException('이카운트 로그인 실패: ' . json_encode($loginRes, JSON_UNESCAPED_UNICODE));
        }

        $this->pdo->prepare(
            'INSERT INTO tt_ecount_session_cache (id, zone, session_id, session_issued_at)
             VALUES (1, :zone, :sid, NOW())
             ON DUPLICATE KEY UPDATE zone = :zone2, session_id = :sid2, session_issued_at = NOW()'
        )->execute(['zone' => $zone, 'sid' => $sessionId, 'zone2' => $zone, 'sid2' => $sessionId]);

        return ['zone' => $zone, 'session_id' => $sessionId];
    }

    /** [신규] 연결 테스트 전용 — 실제 전표 생성 없이 로그인/세션 발급만 확인한다. */
    public function testConnection(): array
    {
        $this->pdo->exec('DELETE FROM tt_ecount_session_cache WHERE id = 1'); // 캐시 무시하고 강제 재로그인
        $s = $this->loginAndCache();
        return [
            'domain_base' => $this->domainBase,
            'zone'        => $s['zone'],
            'session_id'  => substr($s['session_id'], 0, 6) . '••••••', // 로그/화면 노출용 마스킹
        ];
    }

    /** 창고별 재고현황 전체 조회 (대량조회 — 10분 간격 제한). */
    public function getInventoryBalance(string $baseDate): array
    {
        $s = $this->getValidSession();
        $url = "https://{$this->domainBase}{$s['zone']}.ecount.com/OAPI/V2/InventoryBalance/GetListInventoryBalanceStatusByLocation?SESSION_ID={$s['session_id']}";
        return $this->httpPost($url, [
            'SESSION_ID' => $s['session_id'], 'BASE_DATE' => $baseDate,
            'COM_CODE' => $this->comCode, 'USER_ID' => $this->userId, 'ZONE' => $s['zone'],
            'API_CERT_KEY' => $this->apiKey, 'LAN_TYPE' => 'ko-KR',
        ]);
    }

    /**
     * 출고(재고감소) 반영 — 판매입력 전표.
     * $items = [['WH_CD'=>창고코드, 'PROD_CD'=>품목코드, 'PROD_DES'=>품목명, 'QTY'=>'수량(양수)'], ...]
     */
    public function saveSaleOut(array $items): array
    {
        $s = $this->getValidSession();
        $saleList = [];
        foreach (array_values($items) as $i => $it) {
            $saleList[] = ['Line' => (string)$i, 'BulkDatas' => [
                'SESSION_ID' => $s['session_id'], 'WH_CD' => $it['WH_CD'],
                'PROD_CD' => $it['PROD_CD'], 'PROD_DES' => $it['PROD_DES'], 'QTY' => (string)$it['QTY'],
            ]];
        }
        $url = "https://{$this->domainBase}{$s['zone']}.ecount.com/OAPI/V2/Sale/SaveSale?SESSION_ID={$s['session_id']}";
        return $this->httpPost($url, ['SaleList' => $saleList]);
    }

    /**
     * 입고(재고증가) 반영 — 구매입력 전표.
     * $items 형식은 saveSaleOut()과 동일.
     */
    public function savePurchaseIn(array $items): array
    {
        $s = $this->getValidSession();
        $purchaseList = [];
        foreach (array_values($items) as $i => $it) {
            $purchaseList[] = ['Line' => (string)$i, 'BulkDatas' => [
                'SESSION_ID' => $s['session_id'], 'WH_CD' => $it['WH_CD'],
                'PROD_CD' => $it['PROD_CD'], 'PROD_DES' => $it['PROD_DES'], 'QTY' => (string)$it['QTY'],
            ]];
        }
        $url = "https://{$this->domainBase}{$s['zone']}.ecount.com/OAPI/V2/Purchases/SavePurchases?SESSION_ID={$s['session_id']}";
        return $this->httpPost($url, ['PurchasesList' => $purchaseList]);
    }

    private function httpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException('이카운트 API 통신 실패 (curl errno=' . $errno . ')');
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('이카운트 API 응답 파싱 실패: ' . substr((string)$response, 0, 300));
        }
        return $decoded;
    }
}

/* =====================================================================
   [신규] 크론과 관리자 "지금 동기화" 버튼이 공용으로 쓰는 실제 동기화 로직.
   크론 워커(cron/ecount_push_worker.php, cron/ecount_pull_worker.php)의
   핵심 처리부와 동일한 로직을 여기로 뽑아, 관리자 화면에서 크론 없이도
   즉시 호출할 수 있게 했다. (크론이 403으로 막혀있어도 연동 자체는 바로 테스트 가능)
   ===================================================================== */

function ecount_run_push(PDO $pdo, bool $dryRun = false): array
{
    ensure_ecount_tables($pdo);
    $log = [];
    $result = ['dry_run' => $dryRun, 'purchase_items' => [], 'sale_items' => [], 'success' => 0, 'fail' => 0, 'log' => &$log, 'error' => null];

    try {
        $rows = $pdo->query(
            "SELECT q.id, q.product_id, q.delta_qty, q.ecount_item_cd,
                    COALESCE(p.ecount_wh_cd, '100') AS wh_cd, p.name AS product_name
             FROM tt_ecount_sync_queue q
             JOIN tt_products p ON p.id = q.product_id
             WHERE q.status = 'pending'
             ORDER BY q.id ASC
             LIMIT 300"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $result['error'] = '대기열 조회 실패: ' . $e->getMessage();
        return $result;
    }

    if (empty($rows)) {
        $log[] = '전송 대기 중인 재고 변경 요청이 없습니다.';
        return $result;
    }

    /* 품목코드 단위로 증감 합산 */
    $grouped = []; // itemCd => ['wh_cd'=>, 'name'=>, 'delta'=>, 'queue_ids'=>[]]
    foreach ($rows as $r) {
        $cd = $r['ecount_item_cd'];
        if (!isset($grouped[$cd])) {
            $grouped[$cd] = ['wh_cd' => $r['wh_cd'], 'name' => $r['product_name'], 'delta' => 0, 'queue_ids' => []];
        }
        $grouped[$cd]['delta'] += (int)$r['delta_qty'];
        $grouped[$cd]['queue_ids'][] = (int)$r['id'];
    }

    $purchaseItems = []; // 입고(증가)
    $saleItems = [];     // 출고(감소)
    $queueIdsByCd = [];
    foreach ($grouped as $cd => $g) {
        $queueIdsByCd[$cd] = $g['queue_ids'];
        if ($g['delta'] > 0) {
            $purchaseItems[] = ['WH_CD' => $g['wh_cd'], 'PROD_CD' => $cd, 'PROD_DES' => $g['name'], 'QTY' => $g['delta']];
        } elseif ($g['delta'] < 0) {
            $saleItems[] = ['WH_CD' => $g['wh_cd'], 'PROD_CD' => $cd, 'PROD_DES' => $g['name'], 'QTY' => abs($g['delta'])];
        }
    }
    $result['purchase_items'] = $purchaseItems;
    $result['sale_items'] = $saleItems;

    if ($dryRun) {
        $log[] = 'DRY-RUN 모드 — 실제 이카운트 전표는 생성하지 않았습니다.';
        $log[] = '입고(구매) 예정: ' . count($purchaseItems) . '건, 출고(판매) 예정: ' . count($saleItems) . '건';
        return $result;
    }

    try {
        $client = new EcountClient($pdo);
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        return $result;
    }

    $successCds = [];
    $failCds = [];

    if (!empty($purchaseItems)) {
        try {
            $res = $client->savePurchaseIn($purchaseItems);
            $log[] = '[입고 전송] 응답: ' . json_encode($res, JSON_UNESCAPED_UNICODE);
            foreach ($purchaseItems as $it) $successCds[] = $it['PROD_CD'];
        } catch (Throwable $e) {
            $log[] = '[입고 전송 실패] ' . $e->getMessage();
            foreach ($purchaseItems as $it) $failCds[$it['PROD_CD']] = $e->getMessage();
        }
    }

    if (!empty($purchaseItems) && !empty($saleItems)) {
        sleep(10); // Save API 10초 간격 제한
    }

    if (!empty($saleItems)) {
        try {
            $res = $client->saveSaleOut($saleItems);
            $log[] = '[출고 전송] 응답: ' . json_encode($res, JSON_UNESCAPED_UNICODE);
            foreach ($saleItems as $it) $successCds[] = $it['PROD_CD'];
        } catch (Throwable $e) {
            $log[] = '[출고 전송 실패] ' . $e->getMessage();
            foreach ($saleItems as $it) $failCds[$it['PROD_CD']] = $e->getMessage();
        }
    }

    foreach ($queueIdsByCd as $cd => $ids) {
        $ids = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if (isset($failCds[$cd])) {
            $stmt = $pdo->prepare("UPDATE tt_ecount_sync_queue SET status='failed', attempt_count = attempt_count + 1, last_error = ? WHERE id IN ({$ph})");
            $stmt->execute(array_merge([$failCds[$cd]], $ids));
            $result['fail'] += count($ids);
        } else {
            $stmt = $pdo->prepare("UPDATE tt_ecount_sync_queue SET status='sent', attempt_count = attempt_count + 1, sent_at = NOW() WHERE id IN ({$ph})");
            $stmt->execute($ids);
            $result['success'] += count($ids);
        }
    }

    try {
        $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('push', ?, ?, ?)")
            ->execute(['관리자 수동 전송 — 입고 ' . count($purchaseItems) . '건 / 출고 ' . count($saleItems) . '건', $result['success'], $result['fail']]);
    } catch (Throwable $e) {
        $log[] = '[로그 기록 실패] ' . $e->getMessage();
    }

    return $result;
}

function ecount_run_pull(PDO $pdo): array
{
    ensure_ecount_tables($pdo);
    $log = [];
    $result = ['success' => 0, 'fail' => 0, 'skip' => 0, 'log' => &$log, 'error' => null, 'sample' => null];

    try {
        $client = new EcountClient($pdo);
        $raw = $client->getInventoryBalance(date('Ymd'));
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        return $result;
    }

    /* 이카운트 응답 구조가 계정/API버전에 따라 다를 수 있어 후보 경로를 순서대로 탐색 */
    $candidates = [
        $raw['Data']['Result'] ?? null,
        $raw['Data']['Datas'] ?? null,
        $raw['Data']['ResultDetails'] ?? null,
        $raw['Data'] ?? null,
        $raw['Result'] ?? null,
    ];
    $list = null;
    foreach ($candidates as $c) {
        if (is_array($c) && isset($c[0]) && is_array($c[0])) {
            $first = $c[0];
            if (isset($first['PROD_CD']) || isset($first['ITEM_CD'])) {
                $list = $c;
                break;
            }
        }
    }

    if ($list === null) {
        $result['error'] = '이카운트 응답 구조를 인식하지 못했습니다. (관리자 화면 하단 원본 응답 참고)';
        $result['sample'] = $raw;
        return $result;
    }

    foreach ($list as $b) {
        $itemCd = $b['PROD_CD'] ?? $b['ITEM_CD'] ?? null;
        $qty = $b['BAL_QTY'] ?? $b['QTY'] ?? $b['STOCK_QTY'] ?? null;
        if (!$itemCd || $qty === null) { $result['skip']++; continue; }
        $ecountQty = (int)$qty;

        try {
            $stmt = $pdo->prepare("SELECT id, stock FROM tt_products WHERE ecount_item_cd = :cd LIMIT 1");
            $stmt->execute(['cd' => $itemCd]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) { $result['skip']++; continue; }

            $localQty = (int)$product['stock'];
            if ($localQty === $ecountQty) { $result['skip']++; continue; }

            $pdo->prepare("UPDATE tt_products SET stock = :qty, ecount_synced_at = NOW() WHERE id = :id")
                ->execute(['qty' => $ecountQty, 'id' => $product['id']]);
            // [핵심] 여기서 ecount_queue_stock_change()를 호출하지 않는다 — 핑퐁 루프 차단.
            $log[] = "품목 {$itemCd}: {$localQty} → {$ecountQty}";
            $result['success']++;
        } catch (Throwable $e) {
            $log[] = "품목 {$itemCd} 반영 실패: " . $e->getMessage();
            $result['fail']++;
        }
    }

    try {
        $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('pull', ?, ?, ?)")
            ->execute(['관리자 수동 조회 반영 ' . count($list) . '건 (스킵 ' . $result['skip'] . '건)', $result['success'], $result['fail']]);
    } catch (Throwable $e) {
        $log[] = '[로그 기록 실패] ' . $e->getMessage();
    }

    return $result;
}
