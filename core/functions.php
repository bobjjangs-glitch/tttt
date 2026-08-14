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
   tt_coupons(쿠폰 원본) / tt_user_coupons(회원별 발급 내역) 을 최초 실행 시
   자동으로 만들고, tt_orders 에 discount_amount / user_coupon_id 컬럼을
   자동으로 보강한다. 수동 SQL 실행이 필요 없다.
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
