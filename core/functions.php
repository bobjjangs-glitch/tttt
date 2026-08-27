<?php
declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function format_price(int $n): string {
    return number_format($n) . '원';
}

function redirect(string $path): never {
    if (!preg_match('#^https?://#i', $path)) {
        $path = BASE_URL . $path;
    }
    header('Location: ' . $path);
    exit;
}

function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function old(string $key, string $default = ''): string {
    return h($_SESSION['_old'][$key] ?? $default);
}

function flash(string $key, ?string $msg = null): ?string {
    if ($msg !== null) {
        $_SESSION['_flash'][$key] = $msg;
        return null;
    }
    $v = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $v;
}

function json_response(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
   홈 화면 섹션 제목 등 key-value 설정
   admin/banners.php에서 저장 → index.php에서 조회하여 "가장 많이 팔린
   타이어(BEST)" 등의 문구를 하드코딩 없이 관리한다.
   ===================================================================== */
function ensure_settings_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        Database::connection()->exec(
            "CREATE TABLE IF NOT EXISTS tt_site_settings (
                setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('[ensure_settings_table] ' . $e->getMessage());
    }
}

function get_setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    ensure_settings_table();
    try {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM tt_site_settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = ($val !== false && $val !== null && $val !== '') ? (string)$val : $default;
    } catch (Throwable $e) {
        error_log('[get_setting] ' . $e->getMessage());
        $cache[$key] = $default;
    }
    return $cache[$key];
}

function set_setting(string $key, string $value): void
{
    ensure_settings_table();
    Database::connection()->prepare(
        'INSERT INTO tt_site_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = :v2'
    )->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
}

/* =====================================================================
   tt_promo_banners.placement 컬럼 자동 보강
   ===================================================================== */
function ensure_promo_placement_column(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tt_promo_banners' AND COLUMN_NAME = 'placement'"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE tt_promo_banners ADD COLUMN placement VARCHAR(20) NOT NULL DEFAULT 'grid' AFTER image_url");
        }
    } catch (Throwable $e) {
        error_log('[ensure_promo_placement_column] ' . $e->getMessage());
    }
}

/* =====================================================================
   [NEW] tt_banners.target_w / target_h 컬럼 자동 보강
   메인 배너를 "풀블리드 히어로"가 아니라 "카드 슬라이드"로 바꾸면서
   배너별로 카드 크기를 다르게 지정할 수 있도록 추가.
   ===================================================================== */
function ensure_banner_size_columns(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = Database::connection();
        $cols = $pdo->query("SHOW COLUMNS FROM tt_banners")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('target_w', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_banners ADD COLUMN target_w INT NOT NULL DEFAULT 1200 AFTER image_url");
        }
        if (!in_array('target_h', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_banners ADD COLUMN target_h INT NOT NULL DEFAULT 400 AFTER target_w");
        }
    } catch (Throwable $e) {
        error_log('[ensure_banner_size_columns] ' . $e->getMessage());
    }
}

/* =====================================================================
   [NEW] 쿠폰 시스템 테이블/컬럼 자동 생성
   ===================================================================== */
function ensure_coupon_tables(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = Database::connection();

        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            image_url VARCHAR(255) NULL,
            discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'fixed',
            discount_value INT NOT NULL DEFAULT 0,
            max_discount_amount INT NULL,
            min_order_amount INT NOT NULL DEFAULT 0,
            valid_from DATETIME NULL,
            valid_until DATETIME NULL,
            total_limit INT NULL,
            issued_count INT NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_coupon_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_user_coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            coupon_id INT NOT NULL,
            status ENUM('unused','used','expired') NOT NULL DEFAULT 'unused',
            issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at DATETIME NULL,
            order_id INT NULL,
            INDEX idx_uc_user (user_id),
            INDEX idx_uc_coupon (coupon_id),
            CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE,
            CONSTRAINT fk_uc_coupon FOREIGN KEY (coupon_id) REFERENCES tt_coupons(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = $pdo->query("SHOW COLUMNS FROM tt_orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('discount_amount', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_orders ADD COLUMN discount_amount INT NOT NULL DEFAULT 0 AFTER shipping_fee");
        }
        if (!in_array('user_coupon_id', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_orders ADD COLUMN user_coupon_id INT NULL AFTER discount_amount");
        }
    } catch (Throwable $e) {
        error_log('[ensure_coupon_tables] ' . $e->getMessage());
    }
}

/** 쿠폰 1장의 할인액을 계산 (서버/클라 공통 로직, 서버가 최종 권위를 가짐) */
function calc_coupon_discount(array $coupon, int $subtotal): int
{
    if ($subtotal < (int)$coupon['min_order_amount']) return 0;

    if ($coupon['discount_type'] === 'percent') {
        $discount = (int)floor($subtotal * ((int)$coupon['discount_value'] / 100));
        if (!empty($coupon['max_discount_amount'])) {
            $discount = min($discount, (int)$coupon['max_discount_amount']);
        }
    } else {
        $discount = (int)$coupon['discount_value'];
    }
    return max(0, min($discount, $subtotal));
}

/** 쿠폰 상태 뱃지 라벨 */
function coupon_status_label(string $status): string
{
    return ['unused' => '사용가능', 'used' => '사용완료', 'expired' => '기간만료'][$status] ?? $status;
}

/* =====================================================================
   [FIX] tt_stock_requests 컬럼 자동 보강.
   과거 여러 번의 스키마 변경 과정에서 CREATE TABLE IF NOT EXISTS만 사용했기 때문에,
   운영 DB에 예전 버전의 컬럼 구성이 그대로 남아 있을 수 있다.
   이 함수는 테이블이 없으면 최신 구조로 새로 만들고, 이미 있으면 부족한 컬럼만
   찾아서 ALTER TABLE로 추가한다. 기존 데이터는 절대 삭제/변경하지 않는다.
   [주의] 이 함수는 admin/stock-requests.php, ajax-stock-request.php 두 곳에서
   공용으로 호출한다. 저 두 파일 안에 같은 이름의 함수를 절대 다시 선언하지 말 것
   (PHP는 함수 중복 선언 시 Fatal error로 500을 낸다).
   ===================================================================== */
function ensure_stock_requests_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tt_stock_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT NULL COMMENT '연결된 상품ID (없으면 NULL)',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $existingCols = $pdo->query("SHOW COLUMNS FROM tt_stock_requests")->fetchAll(PDO::FETCH_COLUMN);

        $required = [
            'brand_text'     => "ALTER TABLE tt_stock_requests ADD COLUMN brand_text VARCHAR(100) NULL COMMENT '요청 브랜드'",
            'size_text'      => "ALTER TABLE tt_stock_requests ADD COLUMN size_text VARCHAR(60) NOT NULL DEFAULT '' COMMENT '요청 사이즈'",
            'requested_qty'  => "ALTER TABLE tt_stock_requests ADD COLUMN requested_qty INT NOT NULL DEFAULT 1 COMMENT '요청 수량'",
            'customer_name'  => "ALTER TABLE tt_stock_requests ADD COLUMN customer_name VARCHAR(50) NOT NULL DEFAULT '' COMMENT '주문자명'",
            'customer_phone' => "ALTER TABLE tt_stock_requests ADD COLUMN customer_phone VARCHAR(20) NOT NULL DEFAULT '' COMMENT '주문자 연락처'",
            'customer_email' => "ALTER TABLE tt_stock_requests ADD COLUMN customer_email VARCHAR(120) NULL COMMENT '주문자 이메일'",
            'memo'           => "ALTER TABLE tt_stock_requests ADD COLUMN memo TEXT NULL COMMENT '고객 요청 메모'",
            'status'         => "ALTER TABLE tt_stock_requests ADD COLUMN status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending' COMMENT '처리 상태'",
            'admin_memo'     => "ALTER TABLE tt_stock_requests ADD COLUMN admin_memo TEXT NULL COMMENT '관리자 처리 메모'",
            'processed_by'   => "ALTER TABLE tt_stock_requests ADD COLUMN processed_by INT NULL COMMENT '처리한 관리자 ID'",
            'processed_at'   => "ALTER TABLE tt_stock_requests ADD COLUMN processed_at DATETIME NULL COMMENT '처리 완료 시각'",
            'ip_address'     => "ALTER TABLE tt_stock_requests ADD COLUMN ip_address VARCHAR(45) NULL COMMENT '요청자 IP'",
        ];

        foreach ($required as $col => $alterSql) {
            if (!in_array($col, $existingCols, true)) {
                $pdo->exec($alterSql);
                error_log("[ensure_stock_requests_table] 누락된 컬럼 '{$col}' 을 추가했습니다.");
            }
        }

        try { $pdo->exec("ALTER TABLE tt_stock_requests ADD INDEX idx_status (status)"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE tt_stock_requests ADD INDEX idx_product (product_id)"); } catch (Throwable $e) {}

    } catch (Throwable $e) {
        error_log('[ensure_stock_requests_table] ' . $e->getMessage());
    }
}

/* =====================================================================
   [FIX] tt_admin_logs 테이블 자동 생성.
   AdminAuth::log()가 이 테이블에 INSERT하는데, 저장소 어디에도 이 테이블을
   만드는 코드가 없어서 운영 DB에 테이블이 없을 경우 관리자 액션 시점에
   500 오류가 발생할 수 있었다.
   ===================================================================== */
function ensure_admin_logs_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tt_admin_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                memo TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('[ensure_admin_logs_table] ' . $e->getMessage());
    }
}

/** 재고 요청 상태 라벨 + 트렌디 UI용 색상/아이콘 매핑 (여러 파일에서 공용으로 사용) */
function stock_request_status_meta(): array
{
    return [
        'pending'    => ['label' => '대기',   'color' => '#f59e0b', 'bg' => '#fff7ed', 'icon' => '⏳'],
        'processing' => ['label' => '처리중', 'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => '🔧'],
        'done'       => ['label' => '완료',   'color' => '#22c55e', 'bg' => '#f0fdf4', 'icon' => '✅'],
        'cancelled'  => ['label' => '취소',   'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => '✕'],
    ];
}
/* =====================================================================
   [NEW] tt_users 테이블에 사업자/휴대폰인증/약관동의 관련 컬럼 자동 보강.
   기존 스키마(sql/sql-data-fixed)에는 이 컬럼들이 없는데 login.php가
   INSERT 시점에 이 컬럼들을 사용하고 있어서, 컬럼이 없으면 회원가입이
   SQL 오류로 그대로 죽는다. 다른 admin 폼들과 동일한 "자동 보강" 패턴 사용.
   ===================================================================== */
function ensure_user_extra_columns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM tt_users")->fetchAll(PDO::FETCH_COLUMN);

        $required = [
            'user_type'         => "ALTER TABLE tt_users ADD COLUMN user_type ENUM('personal','business') NOT NULL DEFAULT 'personal' COMMENT '일반/사업자 구분' AFTER name",
            'biz_number'        => "ALTER TABLE tt_users ADD COLUMN biz_number VARCHAR(20) NULL COMMENT '사업자등록번호' AFTER user_type",
            'biz_name'          => "ALTER TABLE tt_users ADD COLUMN biz_name VARCHAR(100) NULL COMMENT '상호명' AFTER biz_number",
            'biz_owner_name'    => "ALTER TABLE tt_users ADD COLUMN biz_owner_name VARCHAR(50) NULL COMMENT '대표자명' AFTER biz_name",
            'biz_address'       => "ALTER TABLE tt_users ADD COLUMN biz_address VARCHAR(255) NULL COMMENT '사업장 주소' AFTER biz_owner_name",
            'biz_verified'      => "ALTER TABLE tt_users ADD COLUMN biz_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT '국세청 진위확인 통과여부' AFTER biz_address",
            'biz_verified_at'   => "ALTER TABLE tt_users ADD COLUMN biz_verified_at DATETIME NULL AFTER biz_verified",
            'phone_verified'    => "ALTER TABLE tt_users ADD COLUMN phone_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER phone",
            'phone_verified_at' => "ALTER TABLE tt_users ADD COLUMN phone_verified_at DATETIME NULL AFTER phone_verified",
            'terms_agreed_at'   => "ALTER TABLE tt_users ADD COLUMN terms_agreed_at DATETIME NULL",
            'privacy_agreed_at' => "ALTER TABLE tt_users ADD COLUMN privacy_agreed_at DATETIME NULL",
        ];

        foreach ($required as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    $pdo->exec($sql);
                    error_log("[ensure_user_extra_columns] 누락된 컬럼 '{$col}' 을 추가했습니다.");
                } catch (Throwable $e) {
                    error_log("[ensure_user_extra_columns] 컬럼 '{$col}' 추가 실패: " . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[ensure_user_extra_columns] ' . $e->getMessage());
    }
}
