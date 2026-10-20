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
 * 저장(Save)성 API는 10초 간격 제한이 있으므로 cron 워커 쪽에서 1분 주기로만 돌리고
 * 한 번에 모아서(batch) 보낸다.
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
            ecount_item_cd VARCHAR(30) NOT NULL,
            delta_qty INT NOT NULL,
            reason VARCHAR(150) NOT NULL DEFAULT '',
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            attempt_count INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            INDEX idx_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_session_cache (
            id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
            zone VARCHAR(10) NULL,
            session_id VARCHAR(100) NULL,
            session_issued_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_sync_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            direction ENUM('push','pull') NOT NULL,
            summary VARCHAR(255) NOT NULL,
            success_count INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        ensure_ecount_tables($pdo);

        $this->comCode = trim(get_setting('ecount_com_code', ''));
        $this->userId  = trim(get_setting('ecount_user_id', ''));
        $this->apiKey  = trim(get_setting('ecount_api_key', ''));

        if ($this->comCode === '' || $this->userId === '' || $this->apiKey === '') {
            throw new RuntimeException('이카운트 연동 정보가 비어있습니다. admin/api-settings.php 에서 회사코드/사용자ID/API키를 먼저 등록하세요.');
        }
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
            'https://sboapi.ecount.com/OAPI/V2/Zone?COM_CODE=' . urlencode($this->comCode),
            ['COM_CODE' => $this->comCode]
        );
        $zone = $zoneRes['Data']['ZONE'] ?? null;
        if (!$zone) {
            throw new RuntimeException('이카운트 Zone 조회 실패: ' . json_encode($zoneRes, JSON_UNESCAPED_UNICODE));
        }

        $loginRes = $this->httpPost("https://oapi{$zone}.ecount.com/OAPI/V2/OAPILogin", [
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

    /** 창고별 재고현황 전체 조회 (대량조회 — 10분 간격 제한, 호출빈도는 워커에서 통제). */
    public function getInventoryBalance(string $baseDate): array
    {
        $s = $this->getValidSession();
        $url = "https://oapi{$s['zone']}.ecount.com/OAPI/V2/InventoryBalance/GetListInventoryBalanceStatusByLocation?SESSION_ID={$s['session_id']}";
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
        $url = "https://oapi{$s['zone']}.ecount.com/OAPI/V2/Sale/SaveSale?SESSION_ID={$s['session_id']}";
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
        $url = "https://oapi{$s['zone']}.ecount.com/OAPI/V2/Purchases/SavePurchases?SESSION_ID={$s['session_id']}";
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
