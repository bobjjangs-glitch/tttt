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
    if (ob_get_level()) {
        ob_clean();
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

        $couponCols = $pdo->query("SHOW COLUMNS FROM tt_coupons")->fetchAll(PDO::FETCH_COLUMN);
        $couponRequired = [
            'name'                 => "ALTER TABLE tt_coupons ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT '' AFTER code",
            'description'          => "ALTER TABLE tt_coupons ADD COLUMN description VARCHAR(255) NULL AFTER name",
            'image_url'            => "ALTER TABLE tt_coupons ADD COLUMN image_url VARCHAR(255) NULL AFTER description",
            'discount_type'        => "ALTER TABLE tt_coupons ADD COLUMN discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'fixed' AFTER image_url",
            'discount_value'       => "ALTER TABLE tt_coupons ADD COLUMN discount_value INT NOT NULL DEFAULT 0 AFTER discount_type",
            'max_discount_amount'  => "ALTER TABLE tt_coupons ADD COLUMN max_discount_amount INT NULL AFTER discount_value",
            'min_order_amount'     => "ALTER TABLE tt_coupons ADD COLUMN min_order_amount INT NOT NULL DEFAULT 0 AFTER max_discount_amount",
            'valid_from'           => "ALTER TABLE tt_coupons ADD COLUMN valid_from DATETIME NULL AFTER min_order_amount",
            'valid_until'          => "ALTER TABLE tt_coupons ADD COLUMN valid_until DATETIME NULL AFTER valid_from",
            'total_limit'          => "ALTER TABLE tt_coupons ADD COLUMN total_limit INT NULL AFTER valid_until",
            'issued_count'         => "ALTER TABLE tt_coupons ADD COLUMN issued_count INT NOT NULL DEFAULT 0 AFTER total_limit",
            'status'               => "ALTER TABLE tt_coupons ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER issued_count",
            'created_at'           => "ALTER TABLE tt_coupons ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        ];
        foreach ($couponRequired as $col => $sql) {
            if (!in_array($col, $couponCols, true)) {
                try {
                    $pdo->exec($sql);
                    error_log("[ensure_coupon_tables] tt_coupons 누락 컬럼 '{$col}' 추가");
                } catch (Throwable $e) {
                    error_log("[ensure_coupon_tables] tt_coupons 컬럼 '{$col}' 추가 실패: " . $e->getMessage());
                }
            }
        }
        try { $pdo->exec("ALTER TABLE tt_coupons MODIFY COLUMN code VARCHAR(30) NULL"); } catch (Throwable $e) {}

        $ucCols = $pdo->query("SHOW COLUMNS FROM tt_user_coupons")->fetchAll(PDO::FETCH_COLUMN);
        $ucRequired = [
            'status'     => "ALTER TABLE tt_user_coupons ADD COLUMN status ENUM('unused','used','expired') NOT NULL DEFAULT 'unused' AFTER coupon_id",
            'issued_at'  => "ALTER TABLE tt_user_coupons ADD COLUMN issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status",
            'used_at'    => "ALTER TABLE tt_user_coupons ADD COLUMN used_at DATETIME NULL AFTER issued_at",
            'order_id'   => "ALTER TABLE tt_user_coupons ADD COLUMN order_id INT NULL AFTER used_at",
        ];
        foreach ($ucRequired as $col => $sql) {
            if (!in_array($col, $ucCols, true)) {
                try {
                    $pdo->exec($sql);
                    error_log("[ensure_coupon_tables] tt_user_coupons 누락 컬럼 '{$col}' 추가");
                } catch (Throwable $e) {
                    error_log("[ensure_coupon_tables] tt_user_coupons 컬럼 '{$col}' 추가 실패: " . $e->getMessage());
                }
            }
        }
        try { $pdo->exec("ALTER TABLE tt_user_coupons ADD INDEX idx_uc_user (user_id)"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE tt_user_coupons ADD INDEX idx_uc_coupon (coupon_id)"); } catch (Throwable $e) {}

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

function coupon_status_label(string $status): string
{
    return ['unused' => '사용가능', 'used' => '사용완료', 'expired' => '기간만료'][$status] ?? $status;
}

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

function stock_request_status_meta(): array
{
    return [
        'pending'    => ['label' => '대기',   'color' => '#f59e0b', 'bg' => '#fff7ed', 'icon' => '⏳'],
        'processing' => ['label' => '처리중', 'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => '🔧'],
        'done'       => ['label' => '완료',   'color' => '#22c55e', 'bg' => '#f0fdf4', 'icon' => '✅'],
        'cancelled'  => ['label' => '취소',   'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => '✕'],
    ];
}

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

/* =====================================================================
   [NEW] 홈 화면 "브랜드별 베스트셀러" 섹션 — 어드민에서 여러 개 생성 가능
   ===================================================================== */
function ensure_brand_best_sections_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        Database::connection()->exec("
            CREATE TABLE IF NOT EXISTS tt_brand_best_sections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                brand_id INT NULL COMMENT 'NULL이면 브랜드 무관 전체 베스트',
                section_title VARCHAR(100) NOT NULL,
                kicker_text VARCHAR(150) NULL COMMENT '상단 문구 (예: 타이어픽 최저가 N사 최저가 도전!)',
                view_all_text VARCHAR(60) NULL DEFAULT '전체보기',
                view_all_url VARCHAR(255) NULL COMMENT '비워두면 브랜드 기준으로 자동 생성',
                sub_text VARCHAR(150) NULL COMMENT '하단 문구 (예: 타이어픽에서 저렴하게 만나보세요!)',
                period_days INT NOT NULL DEFAULT 30,
                display_limit TINYINT NOT NULL DEFAULT 5,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_bbs_brand FOREIGN KEY (brand_id) REFERENCES tt_brands(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('[ensure_brand_best_sections_table] ' . $e->getMessage());
    }
}

/**
 * 지정한 브랜드(또는 전체)의 "최근 N일 기준 베스트셀러"를 조회한다.
 * tt_products.sales_count는 전체 누적치라서 기간 필터링이 불가능하므로
 * tt_order_items + tt_orders(created_at, status)를 직접 집계한다.
 */
function get_brand_best_products(PDO $pdo, ?int $brandId, int $periodDays, int $limit): array
{
    $limit      = max(1, min(5, $limit));       // 한 줄 최대 5개 강제
    $periodDays = max(1, $periodDays);

    $sql = "
        SELECT p.*, b.name AS brand_name,
               COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.qty ELSE 0 END), 0) AS period_sales
        FROM tt_products p
        JOIN tt_brands b ON b.id = p.brand_id
        LEFT JOIN tt_order_items oi ON oi.product_id = p.id
        LEFT JOIN tt_orders o
            ON o.id = oi.order_id
           AND o.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
           AND o.status != 'cancelled'
        WHERE p.status = 'active'"
        . ($brandId !== null ? " AND p.brand_id = :brand_id" : "") . "
        GROUP BY p.id
        ORDER BY period_sales DESC, p.sales_count DESC, p.id DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':days', $periodDays, PDO::PARAM_INT);
    if ($brandId !== null) {
        $stmt->bindValue(':brand_id', $brandId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/* =====================================================================
   [NEW] 리뷰 작성 시 선택 가능한 서비스 유형 화이트리스트
   - review-submit.php, product-detail.php 에서 공통으로 참조
   - 현재 UI(리뷰 모달)에서는 사용하지 않지만, 하위호환을 위해 함수/컬럼은 유지한다.
   ===================================================================== */
function review_service_type_options(): array
{
    return ['타이어교체', '매장방문', '출장교체', '발렛', '자동세차', '엔진오일교체', '배터리교체'];
}

/* =====================================================================
   [수정] 리뷰 작성 모달에서 선택 가능한 "이런 점이 좋았어요" 태그 목록
   - [기존] 하드코딩 배열로 반환하던 방식 → [변경] tt_review_option_tags 테이블 조회로 전환
   - 이렇게 바꾸는 이유: 어드민(admin/reviews.php)에서 코드 수정 없이 태그를
     추가/삭제/숨김 처리할 수 있어야 하기 때문. review-submit.php 는 이 목록을
     화이트리스트로 사용해 제출된 태그를 검증하므로, 여기서 비활성 처리된 태그는
     더 이상 새 리뷰에 선택될 수 없다.
   ===================================================================== */
function review_option_tag_options(): array
{
    ensure_review_extra_columns();
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = Database::connection()->query(
            "SELECT label FROM tt_review_option_tags WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        $cache = $rows ?: [];
    } catch (Throwable $e) {
        error_log('[review_option_tag_options] ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

/**
 * [NEW] 어드민 관리 화면(admin/reviews.php)용 — id / label / is_active / sort_order
 * 를 모두 포함한 전체 목록(비활성 태그도 포함)을 조회한다.
 */
function review_option_tag_options_admin(): array
{
    ensure_review_extra_columns();
    try {
        return Database::connection()->query(
            "SELECT id, label, is_active, sort_order FROM tt_review_option_tags ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[review_option_tag_options_admin] ' . $e->getMessage());
        return [];
    }
}

/**
 * [NEW] tt_reviews.option_tags 에 저장된 콤마 구분 문자열("a,b,c")을
 * product-detail.php / review.php / admin/reviews.php 에서 안전하게 배열로 변환할 때 사용.
 */
function review_parse_option_tags(?string $csv): array
{
    if ($csv === null || $csv === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $csv))));
}

/* =====================================================================
   [기존 + 수정] tt_reviews 에 service_type / option_tags 컬럼 추가,
   리뷰 사진 저장용 tt_review_photos 테이블 생성.
   [추가] 리뷰 태그를 어드민에서 관리할 수 있는 tt_review_option_tags 테이블을
   신규 생성하고, 데이터가 하나도 없을 때만 기본 5개 문구를 자동 시딩한다.
   ===================================================================== */
function ensure_review_extra_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = Database::connection();

    try {
        if (!$pdo->query("SHOW COLUMNS FROM tt_reviews LIKE 'service_type'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_reviews ADD COLUMN service_type VARCHAR(30) NULL AFTER rating");
        }
        if (!$pdo->query("SHOW COLUMNS FROM tt_reviews LIKE 'option_tags'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_reviews ADD COLUMN option_tags VARCHAR(255) NULL AFTER service_type");
        }
    } catch (Throwable $e) {
        error_log('[ensure_review_extra_columns:alter] ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tt_review_photos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                review_id INT NOT NULL,
                image_url VARCHAR(500) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_review_id (review_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('[ensure_review_extra_columns:photos] ' . $e->getMessage());
    }

    /* [NEW] 리뷰 작성 모달의 "이런 점이 좋았어요" 태그 관리 테이블 */
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tt_review_option_tags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(30) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_review_tag_label (label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM tt_review_option_tags")->fetchColumn();
        if ($cnt === 0) {
            $defaults = ['승차감이 편안해요', '소음이 없어요', '가성비가 좋아요', '최신 제품이에요', '부드러워요'];
            $ins = $pdo->prepare("INSERT INTO tt_review_option_tags (label, is_active, sort_order) VALUES (:label, 1, :sort)");
            foreach ($defaults as $i => $label) {
                $ins->execute(['label' => $label, 'sort' => $i]);
            }
            error_log('[ensure_review_extra_columns:tags] 기본 리뷰 태그 5개를 시딩했습니다.');
        }
    } catch (Throwable $e) {
        error_log('[ensure_review_extra_columns:tags] ' . $e->getMessage());
    }
}

/* =====================================================================
   [기존] 홈 화면 "베스트 리뷰" 섹션에서 사용하는 최근 우수 리뷰 조회
   index.php 가 호출하지만 파일에 정의가 없어서 500 에러(Fatal error:
   Call to undefined function get_home_best_reviews())를 유발하던 함수.
   ===================================================================== */
function get_home_best_reviews(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(30, $limit));

    try {
        $stmt = $pdo->prepare("
            SELECT
                r.id, r.rating, r.content, r.created_at,
                u.name AS user_name,
                p.name AS product_name, p.thumbnail_url,
                b.name AS brand_name
            FROM tt_reviews r
            JOIN tt_users u ON u.id = r.user_id
            JOIN tt_products p ON p.id = r.product_id
            LEFT JOIN tt_brands b ON b.id = p.brand_id
            WHERE r.rating >= 4
              AND r.content IS NOT NULL
              AND r.content != ''
            ORDER BY r.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $name = (string)($row['user_name'] ?? '');
            $row['user_name_masked'] = $name !== ''
                ? mb_substr($name, 0, 1) . str_repeat('*', max(0, mb_strlen($name) - 1))
                : '익명';
        }
        unset($row);

        return $rows;
    } catch (Throwable $e) {
        error_log('[get_home_best_reviews] ' . $e->getMessage());
        return [];
    }
}
