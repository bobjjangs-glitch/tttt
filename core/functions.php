<?php
declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/* =====================================================================
   [v43] SchemaGuard — 스키마 자동점검(ensure_*) 결과 캐시

   문제: ensure_* 함수가 매 요청마다 SHOW COLUMNS / CREATE TABLE IF NOT
   EXISTS / ALTER TABLE 왕복을 수십 번 실행 → 공유호스팅 운영에서 페이지가
   느리게 열리는 주 원인. 게다가 ensure_coupon_tables 는 구 스키마 완화용
   ALTER를 매 요청 무조건 실행하고 있었다.

   해법: 점검을 통과한 ensure 키를 파일에 기록해 두고, 코드 지문(핵심 파일
   mtime 해시)이 같고 TTL 안이면 재점검을 생략한다.
   - ensure 본문 안의 에러 로그(sg_error_log)가 한 건이라도 발생하면 성공으로
     기록하지 않는다 → 다음 요청이 재점검해 자동 복구.
   - 스키마 관련 오류(테이블/컬럼 없음: 42S02/42S22)가 전역으로 터지면
     부트스트랩 예외 핸들러가 캐시를 무효화 → 다음 요청에서 자동 복구.
   - 코드 배포(파일 mtime 변화)는 지문 불일치로 전체 자동 무효화.
   - 파일이 없거나 쓸 수 없으면 종전대로 매 요청 점검 (안전 폴백).
   ===================================================================== */
final class SchemaGuard
{
    private const TTL = 900;              // 15분 — 이 안에 재점검 생략
    private static ?array $store = null;  // ['fp' => 지문, 'keys' => [key => ts]]
    private static bool $loaded = false;
    private static bool $errors = false;   // 이번 ensure 실행 중 에러 여부 (mark 판정용)

    private static function file(): string
    {
        $dir = __DIR__ . '/../storage';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/schema-guard.json';
    }

    /** 코드 지문 — ensure 로직이 담긴 핵심 파일들의 mtime 해시 (배포 직후 자동 무효화) */
    private static function fingerprint(): string
    {
        $files = [__DIR__ . '/functions.php', __DIR__ . '/AdminAuth.php', __DIR__ . '/../config/constants.php'];
        $sig = '';
        foreach ($files as $f) $sig .= @filemtime($f) . '|';
        return md5($sig);
    }

    private static function load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        self::$store = null;
        $f = self::file();
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') return;
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['fp'] ?? '') !== self::fingerprint()) return; // 코드 바뀌면 전부 무효
        self::$store = is_array($data['keys'] ?? null) ? $data : null;
    }

    /** 해당 ensure 키를 최근에 점검했으면 true (재점검 생략 가능) */
    public static function verified(string $key): bool
    {
        self::load();
        if (self::$store === null) return false;
        $ts = self::$store['keys'][$key] ?? 0;
        return ($ts > 0) && ((time() - $ts) < self::TTL);
    }

    /** 점검 통과 기록 (파일 저장 실패는 무시 — 다음 요청이 다시 점검하므로 안전) */
    public static function mark(string $key): void
    {
        self::load();
        if (self::$store === null) {
            if (!self::canWrite()) return;   // 읽기도 실패한 환경 — 캐시 미사용
            self::$store = ['fp' => self::fingerprint(), 'keys' => []];
        }
        self::$store['keys'][$key] = time();
        $f = self::file();
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(self::$store)) !== false) {
            @rename($tmp, $f);
        }
    }

    /** 스키마 오류 발생 시 전체 무효화 — 다음 요청이 즉시 재점검하도록 */
    public static function invalidate(): void
    {
        self::$loaded = true;
        self::$store = null;
        @unlink(self::file());
    }

    private static function canWrite(): bool
    {
        $dir = dirname(self::file());
        return is_dir($dir) && is_writable($dir);
    }

    /** ensure 실행 시작 — 에러 플래그 초기화 */
    public static function resetErrors(): void { self::$errors = false; }

    /** ensure 본문 안에서 에러 기록 발생 */
    public static function noteError(): void { self::$errors = true; }

    /** 이번 ensure 실행 중 에러가 없었는가 (mark 판정) */
    public static function hadErrors(): bool { return self::$errors; }
}

/** [v43] ensure_* 공용 게이트 — static 중복호출 방지 + SchemaGuard 캐시 확인
 *  반환 true = 점검 생략 가능 (이 요청 내 이미 확인됨 or 최근 점검 통과) */
function sg_skip(string $key, bool &$checked): bool
{
    if ($checked || SchemaGuard::verified($key)) return true;
    $checked = true;
    SchemaGuard::resetErrors();
    return false;
}

/** [v43] ensure 본문 안의 에러 로그 — SchemaGuard가 점검 실패를 감지해
 *  스키마 캐시에 성공으로 기록하지 않게 한다 (다음 요청이 재점검) */
function sg_error_log(string $msg): void
{
    SchemaGuard::noteError();
    error_log($msg);
}

/** [v43] 쿼리 예외가 스키마 오류(테이블/컬럼 없음: SQLSTATE 42S*)면 스키마 점검 캐시를 무효화.
 *  방어적 try/catch 안에서도 다음 요청이 ensure_* 를 재점검해 자동 복구되게 한다.
 *  (전역 예외 핸들러에서도 같은 판정 사용) */
function sg_maybe_invalidate(Throwable $e): void
{
    if ($e instanceof PDOException && strpos((string)$e->getCode(), '42S') === 0) {
        SchemaGuard::invalidate();
    }
}

/** 리뷰 사진 URL 정규화 — BASE_URL이 없으면 붙여서 반환 */
function normalize_photo_url(string $url): string {
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, '/') === 0) return BASE_URL . $url;
    return BASE_URL . '/' . $url;
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
    if (sg_skip('settings_table', $checked)) return;
    try {
        Database::connection()->exec(
            "CREATE TABLE IF NOT EXISTS tt_site_settings (
                setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!SchemaGuard::hadErrors()) SchemaGuard::mark('settings_table');
    } catch (Throwable $e) {
        sg_error_log('[ensure_settings_table] ' . $e->getMessage());
    }
}

function get_setting(string $key, string $default = ''): string
{
    /* [v43] 설정 전체를 1회 쿼리로 선로드 — 키당 1쿼리 왕복 제거
     * 캐시는 $GLOBALS 에 두어 set_setting 이 같은 요청 내 즉시 반영되게 한다 */
    if (!($GLOBALS['tt_settings_preloaded'] ?? false)) {
        $GLOBALS['tt_settings_preloaded'] = true;
        $GLOBALS['tt_settings_cache'] = [];
        ensure_settings_table();
        try {
            $rows = Database::connection()
                ->query('SELECT setting_key, setting_value FROM tt_site_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) $GLOBALS['tt_settings_cache'][(string)$k] = $v;
        } catch (Throwable $e) {
            sg_error_log('[get_setting:preload] ' . $e->getMessage());
        }
    }
    $cache = &$GLOBALS['tt_settings_cache'];
    if (array_key_exists($key, $cache)) {
        $v = $cache[$key];
        return ($v !== false && $v !== null && $v !== '') ? (string)$v : $default;
    }
    return $default;
}

function set_setting(string $key, string $value): void
{
    ensure_settings_table();
    Database::connection()->prepare(
        'INSERT INTO tt_site_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = :v2'
    )->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
    /* [v43] 같은 요청 내 get_setting 캐시에도 즉시 반영 */
    if ($GLOBALS['tt_settings_preloaded'] ?? false) {
        $GLOBALS['tt_settings_cache'][$key] = $value;
    }
}

function ensure_promo_placement_column(): void
{
    static $checked = false;
    if (sg_skip('ensure_promo_placement_column', $checked)) return;
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
        sg_error_log('[ensure_promo_placement_column] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_promo_placement_column');
}

/* [v45] 관리자에서 입력한 링크(link_url)를 사이트 경로에 맞게 정규화
   - 값이 비었으면 $fallback 반환
   - http:// / https:// 로 시작하면 외부 링크로 그대로 사용
   - "/" 로 시작하면 BASE_URL 뒤에 이어 붙임
     (사이트가 https://.../tire 처럼 하위 폴더에 설치돼 있어도 정확히 이동 —
      "/product-list.php"를 그대로 href에 넣으면 도메인 루트로 가서 404가 뜨던 문제 해결)
   - 그 외(상대경로 "product-list.php?cat=1" 등)는 BASE_URL + "/" + 값 */
function front_link_url(?string $raw, string $fallback = ''): string
{
    $raw = trim((string)$raw);
    if ($raw === '') return $fallback;
    if (preg_match('~^https?://~i', $raw)) return $raw;
    $base = rtrim(BASE_URL, '/');
    if ($raw[0] === '/') return $base . $raw;
    return $base . '/' . $raw;
}

function ensure_banner_size_columns(): void
{
    static $checked = false;
    if (sg_skip('ensure_banner_size_columns', $checked)) return;
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
        sg_error_log('[ensure_banner_size_columns] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_banner_size_columns');
}

function ensure_coupon_tables(): void
{
    static $checked = false;
    if (sg_skip('ensure_coupon_tables', $checked)) return;

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
            'is_signup_pack'       => "ALTER TABLE tt_coupons ADD COLUMN is_signup_pack TINYINT(1) NOT NULL DEFAULT 0 COMMENT '신규 가입 쿠폰팩 자동 지급' AFTER created_at",
        ];
        foreach ($couponRequired as $col => $sql) {
            if (!in_array($col, $couponCols, true)) {
                try {
                    $pdo->exec($sql);
                    sg_error_log("[ensure_coupon_tables] tt_coupons 누락 컬럼 '{$col}' 추가");
                } catch (Throwable $e) {
                    sg_error_log("[ensure_coupon_tables] tt_coupons 컬럼 '{$col}' 추가 실패: " . $e->getMessage());
                }
            }
        }

        /* [v43] 구 스키마 완화 ALTER는 매 요청 무조건 실행하지 않는다!
          * 정상(신규) 스키마에서는 type/value/valid_to 컬럼이 아예 없으므로
          * MODIFY 도 매번 에러만 내고 메타데이터 부하만 줬다 — 컬럼 존재(구 스키마)할 때만 실행.
          * (구 DB에서 value/type/valid_from/valid_to 가 NOT NULL 무기본값이라 쿠폰 생성이 500났던 실제 버그 완화) */
        if (in_array('type', $couponCols, true) || in_array('value', $couponCols, true)
            || in_array('valid_to', $couponCols, true)) {
            foreach ([
                "ALTER TABLE tt_coupons MODIFY type ENUM('percent','fixed') NULL DEFAULT NULL",
                'ALTER TABLE tt_coupons MODIFY value INT UNSIGNED NULL DEFAULT NULL',
                'ALTER TABLE tt_coupons MODIFY valid_from DATETIME NULL DEFAULT NULL',
                'ALTER TABLE tt_coupons MODIFY valid_to DATETIME NULL DEFAULT NULL',
            ] as $relaxSql) {
                try { $pdo->exec($relaxSql); } catch (Throwable $e) { /* 신규 설치 등 컬럼 없으면 무시 */ }
            }
            if (in_array('code', $couponCols, true)) {
                try { $pdo->exec("ALTER TABLE tt_coupons MODIFY COLUMN code VARCHAR(30) NULL"); } catch (Throwable $e) {}
            }
        }

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
                    sg_error_log("[ensure_coupon_tables] tt_user_coupons 누락 컬럼 '{$col}' 추가");
                } catch (Throwable $e) {
                    sg_error_log("[ensure_coupon_tables] tt_user_coupons 컬럼 '{$col}' 추가 실패: " . $e->getMessage());
                }
            }
        }
        /* [v43] 인덱스도 실제 존재 확인 후에만 추가 — 매 요청 ADD INDEX 시도 제거 */
        $ucIndexes = [];
        try {
            foreach ($pdo->query("SHOW INDEX FROM tt_user_coupons")->fetchAll(PDO::FETCH_ASSOC) as $ix) {
                $ucIndexes[$ix['Key_name']] = 1;
            }
        } catch (Throwable $e) {}
        if (!isset($ucIndexes['idx_uc_user'])) {
            try { $pdo->exec("ALTER TABLE tt_user_coupons ADD INDEX idx_uc_user (user_id)"); } catch (Throwable $e) {}
        }
        if (!isset($ucIndexes['idx_uc_coupon'])) {
            try { $pdo->exec("ALTER TABLE tt_user_coupons ADD INDEX idx_uc_coupon (coupon_id)"); } catch (Throwable $e) {}
        }

        $cols = $pdo->query("SHOW COLUMNS FROM tt_orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('discount_amount', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_orders ADD COLUMN discount_amount INT NOT NULL DEFAULT 0 AFTER shipping_fee");
        }
        if (!in_array('user_coupon_id', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_orders ADD COLUMN user_coupon_id INT NULL AFTER discount_amount");
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_coupon_tables] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_coupon_tables');
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

/* ===== 쿠폰 다운로드(고객측) + 신규 가입 쿠폰팩 ===== */

/** 쿠폰 할인 표기 — "5,000원 할인" / "10% 할인 (최대 30,000원)" */
function coupon_discount_label(array $c): string
{
    if (($c['discount_type'] ?? '') === 'percent') {
        $label = number_format((int)$c['discount_value']) . '% 할인';
        if (!empty($c['max_discount_amount'])) $label .= ' (최대 ' . number_format((int)$c['max_discount_amount']) . '원)';
        return $label;
    }
    return number_format((int)$c['discount_value']) . '원 할인';
}

/** 다운로드(수령) 가능한 쿠폰 목록 — 활성 + 유효기간 + 수량 여유. 로그인 사용자는 이미 보유한 것 표시 */
function get_downloadable_coupons(?int $userId): array
{
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT id, name, description, discount_type, discount_value, max_discount_amount,
                    min_order_amount, valid_until
             FROM tt_coupons
             WHERE status = 'active'
               AND (valid_from IS NULL OR valid_from <= NOW())
               AND (valid_until IS NULL OR valid_until >= NOW())
               AND (total_limit IS NULL OR issued_count < total_limit)
               AND TRIM(COALESCE(name, '')) <> ''
               AND COALESCE(discount_value, 0) > 0
             ORDER BY min_order_amount ASC, id ASC");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($userId && $rows) {
            $held = $pdo->prepare("SELECT coupon_id FROM tt_user_coupons WHERE user_id = :uid AND status = 'unused'");
            $held->execute(['uid' => $userId]);
            $heldMap = array_flip(array_map('intval', $held->fetchAll(PDO::FETCH_COLUMN)));
            foreach ($rows as &$r) $r['already_held'] = isset($heldMap[(int)$r['id']]);
        }
        return $rows;
    } catch (Throwable $e) {
        error_log('[get_downloadable_coupons] ' . $e->getMessage());
        return [];
    }
}

/** 쿠폰 1장을 회원 쿠폰함에 지급 (다운로드/가입팩 공용) — 수량·중복 가드 포함 */
function issue_coupon_to_user(int $couponId, int $userId): array
{
    $pdo = Database::connection();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM tt_coupons WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $couponId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c || $c['status'] !== 'active') {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '쿠폰이 존재하지 않거나 종료되었습니다.'];
        }
        if (!empty($c['valid_until']) && strtotime($c['valid_until']) < time()) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '유효기간이 지난 쿠폰입니다.'];
        }
        if ($c['total_limit'] !== null && (int)$c['issued_count'] >= (int)$c['total_limit']) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '쿠폰 수량이 소진되었습니다.'];
        }
        $dup = $pdo->prepare("SELECT id FROM tt_user_coupons WHERE user_id = :uid AND coupon_id = :cid AND status = 'unused' LIMIT 1");
        $dup->execute(['uid' => $userId, 'cid' => $couponId]);
        if ($dup->fetch()) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => '이미 받은 쿠폰입니다. 마이페이지 쿠폰함에서 확인해주세요.', 'already' => true];
        }
        $pdo->prepare('INSERT INTO tt_user_coupons (user_id, coupon_id, status) VALUES (:uid, :cid, "unused")')
            ->execute(['uid' => $userId, 'cid' => $couponId]);
        $pdo->prepare('UPDATE tt_coupons SET issued_count = issued_count + 1 WHERE id = :id')
            ->execute(['id' => $couponId]);
        $pdo->commit();
        return ['ok' => true, 'msg' => '쿠폰이 내 쿠폰함에 저장되었습니다. 결제 때 사용하세요!'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[issue_coupon_to_user] ' . $e->getMessage());
        return ['ok' => false, 'msg' => '쿠폰 받기 처리 중 오류가 발생했습니다.'];
    }
}

/** 신규 가입 쿠폰팩 — is_signup_pack 쿠폰을 새 회원에게 자동 지급, 지급 수 반환 */
function issue_signup_coupon_pack(int $userId): int
{
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT id FROM tt_coupons
             WHERE is_signup_pack = 1 AND status = 'active'
               AND (valid_until IS NULL OR valid_until >= NOW())
               AND (total_limit IS NULL OR issued_count < total_limit)");
        $stmt->execute();
        $issued = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            if (issue_coupon_to_user((int)$cid, $userId)['ok']) $issued++;
        }
        return $issued;
    } catch (Throwable $e) {
        error_log('[issue_signup_coupon_pack] ' . $e->getMessage());
        return 0;
    }
}

/** 신규 가입 쿠폰팩 요약 — [장 수, 최대 할인 총액 원] (배너 표기용) */
function signup_coupon_pack_summary(): array
{
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN discount_type = 'percent' THEN max_discount_amount ELSE discount_value END), 0) AS won
             FROM tt_coupons
             WHERE is_signup_pack = 1 AND status = 'active'
               AND (valid_until IS NULL OR valid_until >= NOW())");
        $stmt->execute();
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'won' => 0];
        return [(int)$r['cnt'], (int)$r['won']];
    } catch (Throwable $e) {
        return [0, 0];
    }
}

function ensure_stock_requests_table(PDO $pdo): void
{
    static $checked = false;
    if (sg_skip('ensure_stock_requests_table', $checked)) return;

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
                sg_error_log("[ensure_stock_requests_table] 누락된 컬럼 '{$col}' 을 추가했습니다.");
            }
        }

        try { $pdo->exec("ALTER TABLE tt_stock_requests ADD INDEX idx_status (status)"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE tt_stock_requests ADD INDEX idx_product (product_id)"); } catch (Throwable $e) {}

    } catch (Throwable $e) {
        sg_error_log('[ensure_stock_requests_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_stock_requests_table');
}

function ensure_admin_logs_table(PDO $pdo): void
{
    static $checked = false;
    if (sg_skip('ensure_admin_logs_table', $checked)) return;
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
        sg_error_log('[ensure_admin_logs_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_admin_logs_table');
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
    if (sg_skip('ensure_user_extra_columns', $checked)) return;

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM tt_users")->fetchAll(PDO::FETCH_COLUMN);

        $required = [
            'user_type'         => "ALTER TABLE tt_users ADD COLUMN user_type ENUM('personal','business') NOT NULL DEFAULT 'personal' COMMENT '일반/사업자 구분' AFTER name",
            'biz_number'        => "ALTER TABLE tt_users ADD COLUMN biz_number VARCHAR(20) NULL COMMENT '사업자등록번호' AFTER user_type",
            'biz_name'          => "ALTER TABLE tt_users ADD COLUMN biz_name VARCHAR(100) NULL COMMENT '상호명' AFTER biz_number",
            'biz_owner_name'    => "ALTER TABLE tt_users ADD COLUMN biz_owner_name VARCHAR(50) NULL COMMENT '대표자명' AFTER biz_name",
            'biz_address'       => "ALTER TABLE tt_users ADD COLUMN biz_address VARCHAR(255) NULL COMMENT '사업장 주소' AFTER biz_owner_name",
            'biz_status'        => "ALTER TABLE tt_users ADD COLUMN biz_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' COMMENT '사업자 승인 상태' AFTER biz_address",
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
                    sg_error_log("[ensure_user_extra_columns] 누락된 컬럼 '{$col}' 을 추가했습니다.");
                } catch (Throwable $e) {
                    sg_error_log("[ensure_user_extra_columns] 컬럼 '{$col}' 추가 실패: " . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_user_extra_columns] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_user_extra_columns');
}

function ensure_brand_best_sections_table(): void
{
    static $checked = false;
    if (sg_skip('ensure_brand_best_sections_table', $checked)) return;
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
        sg_error_log('[ensure_brand_best_sections_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_brand_best_sections_table');
}

function get_brand_best_products(PDO $pdo, ?int $brandId, int $periodDays, int $limit): array
{
    $limit      = max(1, min(5, $limit));
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

function review_service_type_options(): array
{
    return [
        'tire'         => '타이어교체',
        'alignment'    => '휠얼라인먼트',
        'maintenance'  => '차량정비',
        'battery'      => '배터리',
        'engine_oil'   => '엔진오일교체',
        'washer_fluid' => '워셔액교체',
        'brake'        => '브레이크교체',
    ];
}

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

function review_parse_option_tags(?string $csv): array
{
    if ($csv === null || $csv === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $csv))));
}

/* =====================================================================
   [NEW] 방문형태(매장방문/택배수령) 화이트리스트
   ===================================================================== */
function review_visit_type_options(): array
{
    return [
        'store'    => '매장방문',
        'delivery' => '택배수령',
    ];
}

/* =====================================================================
   [NEW] 리뷰 작성 모달의 "추가로 진행한 서비스" 체크박스 목록
   ===================================================================== */
function review_extra_service_options(): array
{
    ensure_review_extra_columns();
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = Database::connection()->query(
            "SELECT label FROM tt_review_extra_services WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        $cache = $rows ?: [];
    } catch (Throwable $e) {
        error_log('[review_extra_service_options] ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

function review_extra_service_options_admin(): array
{
    ensure_review_extra_columns();
    try {
        return Database::connection()->query(
            "SELECT id, label, is_active, sort_order FROM tt_review_extra_services ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[review_extra_service_options_admin] ' . $e->getMessage());
        return [];
    }
}

function review_parse_extra_service(?string $csv): array
{
    return review_parse_option_tags($csv);
}

/* =====================================================================
   [NEW] 매장 정보 — 리뷰 작성 시 "방문 매장" 선택용, 카드 노출용
   ===================================================================== */
function store_active_options(): array
{
    ensure_review_extra_columns();
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = Database::connection()->query(
            "SELECT id, name, address FROM tt_stores WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[store_active_options] ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

function get_store_by_id(int $storeId): ?array
{
    if ($storeId <= 0) return null;
    try {
        $stmt = Database::connection()->prepare("SELECT id, name, address FROM tt_stores WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $storeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[get_store_by_id] ' . $e->getMessage());
        return null;
    }
}

/* =====================================================================
   [NEW] "도움이 돼요" HOT 뱃지 판정 기준
   ===================================================================== */
if (!defined('REVIEW_HOT_HELPFUL_THRESHOLD')) {
    define('REVIEW_HOT_HELPFUL_THRESHOLD', 5);
}

function is_review_hot(int $helpfulCount): bool
{
    return $helpfulCount >= REVIEW_HOT_HELPFUL_THRESHOLD;
}

/* =====================================================================
   [NEW] 예약(방문 예약) 시스템 테이블
   - 타이어 소매 구매 시 "방문 예약" 후 매장에서 결제/장착하는 흐름
   - 상태: pending(예약접수) → confirmed(예약확정) → visited(방문완료/결제)
           / canceled(예약취소) / noshow(노쇼)
   ===================================================================== */
/* =====================================================================
   [NEW] AI 챗봇 — 어드민에서 등록한 FAQ/키워드 기반 응답 시스템
   ===================================================================== */
/* =====================================================================
   [NEW] 세금계산서 발행 — 사업자 회원 요청 + 어드민 발행 처리
   ===================================================================== */
function ensure_tax_invoice_table(): void
{
    static $done = false;
    if (sg_skip('ensure_tax_invoice_table', $done)) return;

    $pdo = Database::connection();
    try {
        $pdo->query("SELECT id FROM tt_tax_invoices LIMIT 1");
        return; // 이미 있음
    } catch (Throwable $e) {
        // 테이블 없음 → 생성
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_tax_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            req_no VARCHAR(30) NOT NULL UNIQUE COMMENT '요청번호',
            user_id INT UNSIGNED NOT NULL,
            target_type ENUM('order','reservation') NOT NULL COMMENT '대상(주문/예약)',
            target_id INT UNSIGNED NOT NULL,
            target_no VARCHAR(30) NULL COMMENT '대상 번호(주문번호/예약번호)',
            amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '발행 금액',
            buyer_biz_number VARCHAR(20) NOT NULL COMMENT '공급받는자 사업자번호',
            buyer_biz_name VARCHAR(100) NOT NULL COMMENT '공급받는자 상호',
            buyer_ceo VARCHAR(50) NOT NULL COMMENT '공급받는자 대표자',
            buyer_address VARCHAR(255) NOT NULL COMMENT '공급받는자 주소',
            buyer_uptae VARCHAR(50) NULL COMMENT '공급받는자 업태',
            buyer_jongmok VARCHAR(50) NULL COMMENT '공급받는자 종목',
            contact_email VARCHAR(191) NULL COMMENT '수신 이메일',
            contact_phone VARCHAR(20) NULL COMMENT '담당자 연락처',
            fax_number VARCHAR(20) NULL COMMENT '수신 팩스번호',
            fax_status ENUM('none','sent','failed') NOT NULL DEFAULT 'none' COMMENT '팩스 발송 상태',
            fax_sent_at DATETIME NULL COMMENT '최종 팩스 발송일시',
            memo TEXT NULL COMMENT '요청사항',
            status ENUM('pending','issued','rejected') NOT NULL DEFAULT 'pending',
            admin_memo TEXT NULL COMMENT '관리자 메모',
            issued_at DATETIME NULL COMMENT '발행 처리일',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            INDEX idx_target (target_type, target_id)
        ) ENGINE=InnoDB");
        sg_error_log("[ensure_tax_invoice_table] tt_tax_invoices 테이블 생성 완료");
    } catch (Throwable $e) {
        sg_error_log('[ensure_tax_invoice_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_tax_invoice_table');
}

/* 공급자(쇼핑몰) 세금계산서 정보 — tt_site_settings에서 읽음
   [v44] 기본값 = 크리에이터가 보낸 실물 세금계산서(사진) 기준 사업자 정보.
   발행관리 하단 "공급자 정보" 폼에서 저장하면 저장값이 우선 적용된다. */
function get_supplier_info(): array
{
    return [
        'biz_number' => get_setting('supplier_biz_number', '426-02-01904'),
        'biz_name'   => get_setting('supplier_biz_name', '시카스튜디오(SICASTUDIO)'),
        'ceo'        => get_setting('supplier_ceo', '나지원'),
        'address'    => get_setting('supplier_address', ''),
        'uptae'      => get_setting('supplier_uptae', '도매 및 소매업'),
        'jongmok'    => get_setting('supplier_jongmok', '전자상거래 판매'),
    ];
}

/* =====================================================================
   [NEW] 세금계산서 팩스 — 기존 테이블에 컬럼 추가 + 발송 대장 테이블
   (호스팅 DB가 MySQL이라 ADD COLUMN IF NOT EXISTS를 못 쓰므로
    SHOW COLUMNS로 컬럼 존재를 확인한 뒤 ALTER TABLE)
   ===================================================================== */
function ensure_tax_invoice_fax_schema(): void
{
    static $done = false;
    if (sg_skip('ensure_tax_invoice_fax_schema', $done)) return;

    ensure_tax_invoice_table(); // 기본 테이블 (없으면 생성 — 신규 컬럼 포함)

    $pdo = Database::connection();

    /* 1) 기존 tt_tax_invoices에 팩스 컬럼 추가 */
    try {
        $cols = [];
        $res = $pdo->query('SHOW COLUMNS FROM tt_tax_invoices');
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $cols[strtolower((string)$row['Field'])] = true;
        }
        if (!isset($cols['fax_number'])) {
            $pdo->exec("ALTER TABLE tt_tax_invoices ADD COLUMN fax_number VARCHAR(20) NULL COMMENT '수신 팩스번호' AFTER contact_phone");
        }
        if (!isset($cols['fax_status'])) {
            $pdo->exec("ALTER TABLE tt_tax_invoices ADD COLUMN fax_status ENUM('none','sent','failed') NOT NULL DEFAULT 'none' COMMENT '팩스 발송 상태' AFTER fax_number");
        }
        if (!isset($cols['fax_sent_at'])) {
            $pdo->exec("ALTER TABLE tt_tax_invoices ADD COLUMN fax_sent_at DATETIME NULL COMMENT '최종 팩스 발송일시' AFTER fax_status");
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_tax_invoice_fax_schema][columns] ' . $e->getMessage());
    }

    /* 2) 팩스 발송 대장 테이블 */
    try {
        $pdo->query('SELECT id FROM tt_tax_invoice_faxes LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tt_tax_invoice_faxes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT UNSIGNED NOT NULL COMMENT '세금계산서 id',
                req_no VARCHAR(30) NOT NULL COMMENT '요청번호(스냅샷)',
                receiver_fax VARCHAR(20) NOT NULL COMMENT '수신 팩스번호',
                sender_fax VARCHAR(20) NULL COMMENT '발신 팩스번호',
                mode ENUM('manual','api') NOT NULL DEFAULT 'manual' COMMENT '발송 방식',
                status ENUM('sent','failed') NOT NULL DEFAULT 'sent' COMMENT '발송 결과',
                result_message TEXT NULL COMMENT '결과 메시지/API 응답',
                admin_id INT UNSIGNED NULL COMMENT '발송 처리 관리자',
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_invoice (invoice_id),
                INDEX idx_sent (sent_at)
            ) ENGINE=InnoDB");
            sg_error_log('[ensure_tax_invoice_fax_schema] tt_tax_invoice_faxes 테이블 생성 완료');
        } catch (Throwable $e2) {
            sg_error_log('[ensure_tax_invoice_fax_schema][create] ' . $e2->getMessage());
        }
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_tax_invoice_fax_schema');
}

/* 팩스번호 정규화 — 숫자만 추출 */
function normalize_fax_number(string $fax): string
{
    return preg_replace('/[^0-9]/', '', $fax) ?? '';
}

/* 팩스번호 표시용 하이픈 (02-1234-5678 / 031-123-4567 / 1588-1234) */
function format_fax_number(string $fax): string
{
    $d = normalize_fax_number($fax);
    if ($d === '' || strlen($d) < 8) return $fax;
    if (strlen($d) === 8)  return substr($d, 0, 4) . '-' . substr($d, 4);
    if (strncmp($d, '02', 2) === 0) {
        if (strlen($d) === 9)  return substr($d, 0, 2) . '-' . substr($d, 2, 3) . '-' . substr($d, 5);
        if (strlen($d) === 10) return substr($d, 0, 2) . '-' . substr($d, 2, 4) . '-' . substr($d, 6);
        return $d;
    }
    if (strlen($d) === 10) return substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6);
    if (strlen($d) === 11) return substr($d, 0, 3) . '-' . substr($d, 3, 4) . '-' . substr($d, 7);
    return $d;
}

/* =====================================================================
   세금계산서 팩스 발송
   - manual 모드(기본): 인쇄물을 팩스기로 발송하는 것으로 대장에 기록
     → 호출부에서 인쇄 화면(fax 표지 포함)을 열어주는 흐름으로 사용
   - api 모드: 설정된 팩스 게이트웨이 URL로 JSON POST 발송 시도
   반환: ['ok'=>bool, 'mode'=>string, 'status'=>'sent'|'failed', 'message'=>string]
   ===================================================================== */
function send_tax_invoice_fax(array $invoice, string $receiverFax, ?int $adminId = null): array
{
    ensure_tax_invoice_fax_schema();

    $receiverFax = normalize_fax_number($receiverFax);
    // 입력이 비어 있으면 요청 시 회원이 남긴 팩스번호로 폴백
    if ($receiverFax === '' && !empty($invoice['fax_number'])) {
        $receiverFax = normalize_fax_number((string)$invoice['fax_number']);
    }
    if (!preg_match('/^\d{8,11}$/', $receiverFax)) {
        return ['ok' => false, 'mode' => 'manual', 'status' => 'failed',
                'message' => '팩스번호는 지역번호 포함 숫자 8~11자리로 입력해주세요. (예: 02-1234-5678)'];
    }

    $pdo = Database::connection();
    $mode = get_setting('tax_fax_mode', 'manual');
    if ($mode !== 'api') $mode = 'manual';
    $senderFax = normalize_fax_number(get_setting('tax_fax_sender', ''));

    $status  = 'sent';
    $message = '수동 발송 — 팩스 표지+세금계산서 인쇄물을 팩스기로 발송할 예정입니다.';

    if ($mode === 'api') {
        $apiUrl = trim((string)get_setting('tax_fax_api_url', ''));
        $apiKey = trim((string)get_setting('tax_fax_api_key', ''));
        if ($apiUrl === '') {
            return ['ok' => false, 'mode' => 'api', 'status' => 'failed',
                    'message' => '팩스 API 주소가 설정되지 않았습니다. (세금계산서 ▸ 팩스 발송 관리에서 설정)'];
        }

        $payload = [
            'to'       => $receiverFax,
            'from'     => $senderFax,
            'subject'  => '세금계산서 [' . (string)($invoice['req_no'] ?? '') . '] ' . (string)($invoice['buyer_biz_name'] ?? ''),
            'file_url' => BASE_URL . '/admin/tax-invoice-print.php?id=' . (int)($invoice['id'] ?? 0),
            'amount'   => (int)($invoice['amount'] ?? 0),
            'ref'      => (string)($invoice['req_no'] ?? ''),
        ];

        $httpCode = 0;
        $resBody  = '';
        $err      = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $res      = curl_exec($ch);
            $resBody  = is_string($res) ? $res : '';
            $err      = (string)curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $err = '이 서버는 PHP cURL을 지원하지 않습니다.';
        }

        if ($err !== '' || $httpCode < 200 || $httpCode >= 300) {
            $status  = 'failed';
            $message = 'API 발송 실패' . ($httpCode > 0 ? ' (HTTP ' . $httpCode . ')' : '') . ($err !== '' ? ' · ' . $err : '');
        } else {
            $message = 'API 발송 요청 완료 (HTTP ' . $httpCode . ') · 응답: ' . mb_substr(trim($resBody), 0, 180);
        }
    }

    /* 대장 기록 + 인보이스 팩스 상태 갱신 */
    try {
        $pdo->prepare('INSERT INTO tt_tax_invoice_faxes
                (invoice_id, req_no, receiver_fax, sender_fax, mode, status, result_message, admin_id)
                VALUES (:iid, :rno, :rto, :sfrom, :mode, :st, :msg, :aid)')
            ->execute([
                'iid'  => (int)($invoice['id'] ?? 0),
                'rno'  => (string)($invoice['req_no'] ?? ''),
                'rto'  => $receiverFax,
                'sfrom' => $senderFax !== '' ? $senderFax : null,
                'mode' => $mode,
                'st'   => $status,
                'msg'  => $message,
                'aid'  => $adminId,
            ]);

        if ($status === 'sent') {
            $pdo->prepare('UPDATE tt_tax_invoices SET fax_status = "sent", fax_sent_at = NOW() WHERE id = :id')
                ->execute(['id' => (int)($invoice['id'] ?? 0)]);
        } else {
            $pdo->prepare('UPDATE tt_tax_invoices SET fax_status = "failed" WHERE id = :id')
                ->execute(['id' => (int)($invoice['id'] ?? 0)]);
        }
    } catch (Throwable $e) {
        error_log('[send_tax_invoice_fax] ' . $e->getMessage());
        return ['ok' => false, 'mode' => $mode, 'status' => 'failed',
                'message' => '팩스 발송 대장 기록 중 오류가 발생했습니다: ' . $e->getMessage()];
    }

    return ['ok' => $status === 'sent', 'mode' => $mode, 'status' => $status, 'message' => $message];
}


function ensure_chatbot_table(): void
{
    static $done = false;
    if (sg_skip('ensure_chatbot_table', $done)) return;

    $pdo = Database::connection();
    try {
        $pdo->query("SELECT id FROM tt_chatbot_entries LIMIT 1");
        return; // 이미 있음
    } catch (Throwable $e) {
        // 테이블 없음 → 생성
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_chatbot_entries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question VARCHAR(200) NOT NULL COMMENT '대표 질문 (추천칩에 표시)',
            keywords VARCHAR(255) NOT NULL COMMENT '매칭 키워드 (쉼표 구분)',
            answer TEXT NOT NULL COMMENT '응답 내용',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB");

        // 기본 시드 데이터 (자주 묻는 질문)
        $seed = [
            ['배송 얼마나 걸려요', '배송,얼마,기간,걸리,배송기간,도착', "결제 완료 후 2~5영업일 내 배송됩니다.\n도서산간 지역은 3~7영업일 소요될 수 있습니다.\n배송 조회는 마이페이지 > 주문내역에서 가능합니다."],
            ['쿠폰은 어떻게 사용하나요', '쿠폰,할인,사용법,쿠폰사용', "결제창에서 '쿠폰 적용' 항목에 보유한 쿠폰이 자동으로 표시됩니다.\n쿠폰을 선택하면 결제 금액이 실시간으로 재계산됩니다.\n보유 쿠폰은 마이페이지 > 쿠폰함에서 확인하실 수 있습니다."],
            ['방문 예약은 어떻게 하나요', '예약,방문예약,예약방법,타이어교체', "상품 상세페이지에서 '🗓️ 방문예약' 버튼을 눌러 예약하실 수 있습니다.\n방문일/시간/수량을 선택하시면 예약 접수 후 결제창으로 이어집니다.\n예약 내역은 마이페이지 > 내 예약에서 확인하실 수 있습니다."],
            ['사업자 회원 가입은 어떻게 하나요', '사업자,회원가입,승인,도매', "로그인 페이지에서 '사업자 회원가입' 탭을 선택해주세요.\n사업자등록번호, 상호명, 대표자명, 주소를 입력하시면 접수됩니다.\n관리자 승인(1~2영업일) 후 로그인 및 사업자 우대가가 적용됩니다."],
            ['교환/반품이 가능한가요', '교환,반품,환불,취소', "상품 수령 후 7일 이내 교환/반품 가능합니다.\n단, 타이어 장착 후(사용 흔적)는 교환/반품이 불가합니다.\n자세한 사항은 고객센터(1588-0000)로 문의해주세요."],
            ['타이어 규격은 어떻게 확인하나요', '규격,사이즈,타이어번호,확인,225', "운전석 도어 프레임 또는 차량 사용설명서에 표기되어 있습니다.\n기존 타이어 측면의 숫자(예: 225/45R18)를 확인하셔도 됩니다.\n225=폭(mm), 45=편평비(%), 18=림 직경(inch)입니다."],
        ];
        $stmt = $pdo->prepare('INSERT INTO tt_chatbot_entries (question, keywords, answer, sort_order, is_active) VALUES (:q, :k, :a, :s, 1)');
        foreach ($seed as $i => $row) {
            $stmt->execute(['q' => $row[0], 'k' => $row[1], 'a' => $row[2], 's' => $i]);
        }
        sg_error_log("[ensure_chatbot_table] tt_chatbot_entries 테이블 생성 + 시드 6건 등록");
    } catch (Throwable $e) {
        sg_error_log('[ensure_chatbot_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_chatbot_table');
}

/* 챗봇 키워드 매칭 — 가장 많이 겹치는 항목 반환 */
function chatbot_match_answer(PDO $pdo, string $message): ?array
{
    try {
        $stmt = $pdo->query("SELECT id, question, keywords, answer FROM tt_chatbot_entries WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$entries) return null;

    // 사용자 메시지 정규화 (공백/특수문자 제거)
    $norm = preg_replace('/[\s\?!\.,~。ㅋㅎㅠㅡ]+/u', '', mb_strtolower(trim($message), 'UTF-8'));
    if ($norm === '') return null;

    $best = null;
    $bestScore = 0;
    foreach ($entries as $e) {
        $keywords = array_filter(array_map('trim', explode(',', $e['keywords'])));
        if (!$keywords) continue;
        $score = 0;
        foreach ($keywords as $kw) {
            $kw = preg_replace('/[\s\?!\.,~。]+/u', '', mb_strtolower($kw, 'UTF-8'));
            if ($kw === '') continue;
            // 키워드가 메시지 안에 포함되면 득점 (긴 키워드일수록 가점)
            if (mb_strpos($norm, $kw, 0, 'UTF-8') !== false) $score += mb_strlen($kw, 'UTF-8') + 1;
        }
        // 대표 질문 앞부분이 메시지에 그대로 포함된 경우 추가 가점
        $qNorm = preg_replace('/[\s\?!\.,~。]+/u', '', mb_strtolower($e['question'], 'UTF-8'));
        $qHead = mb_substr($qNorm, 0, 4, 'UTF-8');
        if ($qHead !== '' && mb_strpos($norm, $qHead, 0, 'UTF-8') !== false) $score += 3;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $e;
        }
    }
    return $best ?: null;
}

function ensure_reservations_table(): void
{
    static $done = false;
    if (sg_skip('ensure_reservations_table', $done)) return;

    $pdo = Database::connection();
    try {
        $pdo->query("SELECT id FROM tt_reservations LIMIT 1");
        // 테이블이 이미 있으면 결제 관련 컬럼 보강
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM tt_reservations")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('discount_amount', $cols, true)) {
                $pdo->exec("ALTER TABLE tt_reservations ADD COLUMN discount_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '쿠폰 할인액' AFTER unit_price");
            }
            if (!in_array('final_amount', $cols, true)) {
                $pdo->exec("ALTER TABLE tt_reservations ADD COLUMN final_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '최종 결제액' AFTER discount_amount");
            }
            if (!in_array('user_coupon_id', $cols, true)) {
                $pdo->exec("ALTER TABLE tt_reservations ADD COLUMN user_coupon_id INT UNSIGNED NULL COMMENT '사용한 쿠폰 ID' AFTER final_amount");
            }
            if (!in_array('payment_method', $cols, true)) {
                $pdo->exec("ALTER TABLE tt_reservations ADD COLUMN payment_method ENUM('bank','virtual','card') NULL COMMENT '결제수단' AFTER user_coupon_id");
            }
            if (!in_array('payment_status', $cols, true)) {
                $pdo->exec("ALTER TABLE tt_reservations ADD COLUMN payment_status ENUM('none','unpaid','paid') NOT NULL DEFAULT 'none' COMMENT '결제상태' AFTER payment_method");
            }
            if (!in_array('visit_type', $cols, true)) {
                $pdo->exec("ALTER TABLE tt_reservations ADD COLUMN visit_type ENUM('tire','repair','etc') NOT NULL DEFAULT 'tire' COMMENT '방문 구분(타이어/정비/기타)' AFTER memo");
            }
            /* [v47] 결제수단 5종 통일 — tt_reservations.payment_method가 구 ENUM('bank','virtual','card')이면
               VARCHAR(30)으로 전환하고 기존 값을 공통 코드(bank_transfer/virtual_account)로 마이그레이션한다.
               ENUM인 채로 naver_pay/kakao_pay를 저장하면 1265(Data truncated)로 결제가 실패한다. */
            try {
                $pmCol = $pdo->query("SHOW COLUMNS FROM tt_reservations LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
                if ($pmCol && stripos((string)$pmCol['Type'], 'enum') === 0) {
                    $pdo->exec("ALTER TABLE tt_reservations MODIFY COLUMN payment_method VARCHAR(30) NULL COMMENT '결제수단(주문과 공통 코드)'");
                    $pdo->exec("UPDATE tt_reservations SET payment_method = 'bank_transfer' WHERE payment_method = 'bank'");
                    $pdo->exec("UPDATE tt_reservations SET payment_method = 'virtual_account' WHERE payment_method = 'virtual'");
                    sg_error_log('[ensure_reservations_table] payment_method ENUM→VARCHAR 전환 + 기존값 마이그레이션 완료');
                }
            } catch (Throwable $e3) {
                sg_error_log('[ensure_reservations_table:payment_method] ' . $e3->getMessage());
            }
        } catch (Throwable $e2) {
            sg_error_log('[ensure_reservations_table:cols] ' . $e2->getMessage());
        }
        return; // 이미 있음
    } catch (Throwable $e) {
        // 테이블 없음 → 생성
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_reservations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            res_no VARCHAR(30) NOT NULL UNIQUE COMMENT '예약번호',
            user_id INT UNSIGNED NULL COMMENT '로그인 사용자 ID (비회원 예약 NULL)',
            product_id INT UNSIGNED NOT NULL,
            option_id INT UNSIGNED NULL,
            product_name_snap VARCHAR(150) NOT NULL,
            size_snap VARCHAR(50) NULL,
            qty INT UNSIGNED NOT NULL DEFAULT 1,
            unit_price INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '예약 시점 표시가격(스냅샷)',
            discount_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '쿠폰 할인액',
            final_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '최종 결제액',
            user_coupon_id INT UNSIGNED NULL COMMENT '사용한 쿠폰 ID',
            payment_method VARCHAR(30) NULL COMMENT '결제수단(주문과 공통 코드 — bank_transfer/virtual_account/card/naver_pay/kakao_pay)',
            payment_status ENUM('none','unpaid','paid') NOT NULL DEFAULT 'none' COMMENT '결제상태',
            visitor_name VARCHAR(50) NOT NULL,
            visitor_phone VARCHAR(20) NOT NULL,
            visit_date DATE NOT NULL COMMENT '방문 예정일',
            visit_time VARCHAR(10) NOT NULL COMMENT '방문 예정 시간대(10:00 등)',
            car_info VARCHAR(100) NULL COMMENT '차량정보(차종/차량번호)',
            memo TEXT NULL COMMENT '요청사항',
            visit_type ENUM('tire','repair','etc') NOT NULL DEFAULT 'tire' COMMENT '방문 구분(타이어/정비/기타)',
            status ENUM('pending','confirmed','visited','canceled','noshow') NOT NULL DEFAULT 'pending',
            admin_memo TEXT NULL COMMENT '관리자 메모',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_product (product_id),
            INDEX idx_status (status),
            INDEX idx_visit_date (visit_date)
        ) ENGINE=InnoDB");
        sg_error_log("[ensure_reservations_table] tt_reservations 테이블 생성 완료");
    } catch (Throwable $e) {
        sg_error_log('[ensure_reservations_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_reservations_table');
}

/* ===== 예약 시간대별 최대 인원 (관리자 설정) ===== */

/** 예약 방문 구분 라벨 (타이어/정비/기타) */
function resv_visit_type_labels(): array
{
    return ['tire' => '타이어 예약', 'repair' => '정비', 'etc' => '기타'];
}

/** 예약 접수 가능한 표준 시간대 목록 (고객 예약 폼 · 인원 확인 공용) */
function resv_time_slots(): array
{
    return ['10:00','10:30','11:00','11:30','13:00','13:30','14:00','14:30','15:00','15:30','16:00','16:30','17:00','17:30'];
}

/** 시간대별 최대 예약 인원 — 관리자 설정(resv_max_tire/repair/etc), 기본 타이어 4 · 정비 2 · 기타 4 */
function get_resv_slot_max(string $visitType): int
{
    $defaults = ['tire' => 4, 'repair' => 2, 'etc' => 4];
    if (!array_key_exists($visitType, $defaults)) $visitType = 'tire';
    $max = (int)get_setting('resv_max_' . $visitType, (string)$defaults[$visitType]);
    return max(1, min(50, $max));
}

/** 지정 날짜의 시간대·방문구분별 기예약 인원("시간|구분" => 건수) — 취소/노쇼 제외 */
function resv_slot_usage(string $date): array
{
    static $cache = [];
    if (isset($cache[$date])) return $cache[$date];
    $usage = [];
    try {
        $stmt = Database::connection()->prepare(
            "SELECT visit_time, visit_type, COUNT(*) AS cnt
             FROM tt_reservations
             WHERE visit_date = :d AND status NOT IN ('canceled','noshow')
             GROUP BY visit_time, visit_type");
        $stmt->execute(['d' => $date]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = $row['visit_type'] ?? 'tire';
            if (!array_key_exists($type, resv_visit_type_labels())) $type = 'tire';
            $usage[substr((string)$row['visit_time'], 0, 5) . '|' . $type] = (int)$row['cnt'];
        }
    } catch (Throwable $e) {
        error_log('[resv_slot_usage] ' . $e->getMessage());
    }
    return $cache[$date] = $usage;
}

/** 해당 날짜·시간대·방문구분의 잔여 예약 가능 인원 */
function resv_slot_remaining(string $date, string $time, string $visitType): int
{
    $usage = resv_slot_usage($date);
    $used = $usage[substr($time, 0, 5) . '|' . $visitType] ?? 0;
    return max(0, get_resv_slot_max($visitType) - $used);
}

function ensure_review_extra_columns(): void
{
    static $done = false;
    if (sg_skip('ensure_review_extra_columns', $done)) return;

    $pdo = Database::connection();

    /* ===============================================================
       [FIX] order_item_id 컬럼 누락 수정
       - review-submit.php의 INSERT 문에서 order_item_id 컬럼을 사용하는데
         실제 tt_reviews 테이블에는 이 컬럼이 없어서
         "SQLSTATE 42S22: Unknown column 'order_item_id'" 에러가 발생했음.
       - 구매확정된 주문상품(order_item_id)을 근거로 리뷰를 작성했다는
         증거 컬럼이므로 필수. NULL 허용으로 추가하고 조회 성능을 위해
         인덱스도 함께 건다.
       =============================================================== */
    try {
        if (!$pdo->query("SHOW COLUMNS FROM tt_reviews LIKE 'order_item_id'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_reviews ADD COLUMN order_item_id INT NULL COMMENT '리뷰 작성 근거 주문상품 ID' AFTER user_id");
            sg_error_log("[ensure_review_extra_columns:order_item_id] 누락된 컬럼 'order_item_id' 을 추가했습니다.");
        }
        try { $pdo->exec("ALTER TABLE tt_reviews ADD INDEX idx_order_item (order_item_id)"); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        sg_error_log('[ensure_review_extra_columns:order_item_id] ' . $e->getMessage());
    }

    try {
        if (!$pdo->query("SHOW COLUMNS FROM tt_reviews LIKE 'service_type'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_reviews ADD COLUMN service_type VARCHAR(30) NULL AFTER rating");
        }
        if (!$pdo->query("SHOW COLUMNS FROM tt_reviews LIKE 'option_tags'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_reviews ADD COLUMN option_tags VARCHAR(255) NULL AFTER service_type");
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_review_extra_columns:alter] ' . $e->getMessage());
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
        sg_error_log('[ensure_review_extra_columns:photos] ' . $e->getMessage());
    }

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
            sg_error_log('[ensure_review_extra_columns:tags] 기본 리뷰 태그 5개를 시딩했습니다.');
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_review_extra_columns:tags] ' . $e->getMessage());
    }

    /* [NEW] 차량모델 / 방문형태 / 추가서비스 / 매장 / 도움돼요 카운트 컬럼 */
    try {
        $reviewCols = $pdo->query("SHOW COLUMNS FROM tt_reviews")->fetchAll(PDO::FETCH_COLUMN);
        $reviewCardCols = [
            'vehicle_model' => "ALTER TABLE tt_reviews ADD COLUMN vehicle_model VARCHAR(60) NULL COMMENT '차량 모델' AFTER option_tags",
            'visit_type'    => "ALTER TABLE tt_reviews ADD COLUMN visit_type ENUM('store','delivery') NOT NULL DEFAULT 'store' COMMENT '매장방문/택배수령' AFTER vehicle_model",
            'extra_service' => "ALTER TABLE tt_reviews ADD COLUMN extra_service VARCHAR(255) NULL COMMENT '추가서비스(콤마구분)' AFTER visit_type",
            'store_id'      => "ALTER TABLE tt_reviews ADD COLUMN store_id INT NULL COMMENT '방문 매장 ID' AFTER extra_service",
            'helpful_count' => "ALTER TABLE tt_reviews ADD COLUMN helpful_count INT NOT NULL DEFAULT 0 COMMENT '도움돼요 누적수' AFTER store_id",
        ];
        foreach ($reviewCardCols as $col => $sql) {
            if (!in_array($col, $reviewCols, true)) {
                $pdo->exec($sql);
                sg_error_log("[ensure_review_extra_columns:card] 누락된 컬럼 '{$col}' 을 추가했습니다.");
            }
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_review_extra_columns:card] ' . $e->getMessage());
    }

    /* [NEW] 매장 테이블 — 기본 매장 1개 시딩 */
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tt_stores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                address VARCHAR(255) NOT NULL,
                phone VARCHAR(20) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $storeCnt = (int)$pdo->query("SELECT COUNT(*) FROM tt_stores")->fetchColumn();
        if ($storeCnt === 0) {
            $pdo->prepare("INSERT INTO tt_stores (name, address, is_active, sort_order) VALUES (:n, :a, 1, 0)")
                ->execute(['n' => '타이어&피트(두꺼비카서비스)', 'a' => '서울특별시 서대문구 연희동']);
            sg_error_log('[ensure_review_extra_columns:stores] 기본 매장 1개를 시딩했습니다.');
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_review_extra_columns:stores] ' . $e->getMessage());
    }

    /* [NEW] "도움이 돼요" 중복 방지 매핑 테이블 (사용자당 리뷰당 1표) */
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tt_review_helpful (
                id INT AUTO_INCREMENT PRIMARY KEY,
                review_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_review_user (review_id, user_id),
                INDEX idx_rh_review (review_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        sg_error_log('[ensure_review_extra_columns:helpful] ' . $e->getMessage());
    }

    /* [NEW] 추가서비스 체크박스 관리 테이블 — 기본 4개 시딩 */
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tt_review_extra_services (
                id INT AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(40) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_extra_service_label (label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $esCnt = (int)$pdo->query("SELECT COUNT(*) FROM tt_review_extra_services")->fetchColumn();
        if ($esCnt === 0) {
            $defaultServices = ['휠 얼라인먼트 추가', '질소가스 충전', '밸브 교체', '폐타이어 처리'];
            $ins = $pdo->prepare("INSERT INTO tt_review_extra_services (label, is_active, sort_order) VALUES (:label, 1, :sort)");
            foreach ($defaultServices as $i => $label) {
                $ins->execute(['label' => $label, 'sort' => $i]);
            }
            sg_error_log('[ensure_review_extra_columns:extra_services] 기본 추가서비스 4개를 시딩했습니다.');
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_review_extra_columns:extra_services] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_review_extra_columns');
}
/* =====================================================================
   [NEW] 회원 유형별 차등 가격 시스템
   - 상품 DB는 하나(price_original, price_sale)를 공유
   - 사업자 회원에게는 price_sale에서 추가 할인율(business_discount_pct) 적용
   - 일반 회원에게는 price_sale 그대로 표시
   - business_discount_pct는 tt_site_settings에서 관리 (어드민에서 설정)
   ===================================================================== */

function ensure_price_tier_columns(): void
{
    static $checked = false;
    if (sg_skip('ensure_price_tier_columns', $checked)) return;
    try {
        $pdo = Database::connection();
        $cols = $pdo->query("SHOW COLUMNS FROM tt_products")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('business_price', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_products ADD COLUMN business_price INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '사업자 전용 가격(0이면 price_sale에서 할인율 적용)' AFTER price_sale");
        }
        if (!in_array('biz_price_original', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_products ADD COLUMN biz_price_original INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '사업자 대표 공장도가' AFTER business_price");
        }
        if (!in_array('biz_supply_price', $cols, true)) {
            $pdo->exec("ALTER TABLE tt_products ADD COLUMN biz_supply_price INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '사업자 공급가' AFTER biz_price_original");
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_price_tier_columns] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_price_tier_columns');
}

/** 사업자 할인 설정 가져오기 — 유형(percent%/fixed금액) + 값 */
function get_business_discount(): array
{
    $type = get_setting('business_discount_type', 'percent');
    if ($type !== 'fixed') $type = 'percent';
    $pct = max(0, min(50, (int)get_setting('business_discount_pct', '5')));
    $amt = max(0, (int)get_setting('business_discount_amount', '0'));
    return ['type' => $type, 'pct' => $pct, 'amount' => $amt];
}

/** 사업자 할인율(%만 사용하는 곳 호환용) */
function get_business_discount_pct(): int
{
    return get_business_discount()['pct'];
}

/** 사용자 유형에 맞는 표시 가격 계산 — %할인 또는 금액할인 지원 */
function get_display_price(int $priceSale, int $businessPrice = 0, string $userType = 'personal'): int
{
    if ($userType === 'business') {
        // 상품별 개별 사업자가격이 최우선
        if ($businessPrice > 0) {
            return $businessPrice;
        }
        $dc = get_business_discount();
        if ($dc['type'] === 'fixed') {
            if ($dc['amount'] > 0) {
                return max(0, $priceSale - $dc['amount']);
            }
        } else {
            if ($dc['pct'] > 0) {
                return (int)round($priceSale * (1 - $dc['pct'] / 100));
            }
        }
    }
    return $priceSale;
}

/** 상품 배열의 가격을 현재 사용자 유형에 맞게 변환 */
function apply_price_tier(array $product, string $userType = null): array
{
    if ($userType === null) {
        $userType = Auth::isLoggedIn() ? Auth::currentUserType() : 'personal';
    }
    $businessPrice = (int)($product['business_price'] ?? 0);
    $priceSale = (int)$product['price_sale'];
    $displayPrice = get_display_price($priceSale, $businessPrice, $userType);

    $product['display_price'] = $displayPrice;
    $product['is_business_price'] = ($userType === 'business' && $displayPrice < $priceSale);

    return $product;
}

function get_home_best_reviews(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(30, $limit));

    try {
        $stmt = $pdo->prepare("
            SELECT
                r.id, r.rating, r.content, r.created_at,
                r.service_type, r.option_tags,
                u.name AS user_name,
                p.id AS product_id, p.name AS product_name, p.thumbnail_url,
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

        if (!$rows) return [];

        /* [NEW] 홈 화면 리뷰카드에도 review-list.php와 동일하게 사진을 최대 3장씩 매핑 */
        $ids = array_column($rows, 'id');
        $photosByReview = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $photoStmt = $pdo->prepare("SELECT review_id, image_url FROM tt_review_photos WHERE review_id IN ($ph) ORDER BY sort_order ASC");
            $photoStmt->execute($ids);
            foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $prow) {
                $photosByReview[$prow['review_id']][] = $prow['image_url'];
            }
        }

        $serviceOptions = review_service_type_options();

        foreach ($rows as &$row) {
            $name = (string)($row['user_name'] ?? '');
            $row['user_name_masked'] = $name !== ''
                ? mb_substr($name, 0, 1) . str_repeat('*', max(0, mb_strlen($name) - 1))
                : '익명';

            $row['photos'] = array_slice($photosByReview[$row['id']] ?? [], 0, 3);
            // 정규화: BASE_URL이 없는 상대경로를 절대경로로 변환
            $row['photos'] = array_map('normalize_photo_url', $row['photos']);

            $row['service_label'] = (!empty($row['service_type']) && isset($serviceOptions[$row['service_type']]))
                ? $serviceOptions[$row['service_type']]
                : '';

            /* review_parse_option_tags()는 product-detail.php에서 쓰는 방식과 동일하게
               CSV로 저장된 태그 라벨을 그대로 배열로 반환 (review-list.php의 룩업 방식과
               달리 실제로 동작이 보장되는 방식입니다) */
            $row['option_tag_list'] = review_parse_option_tags($row['option_tags'] ?? null);
        }
        unset($row);

        return $rows;
    } catch (Throwable $e) {
        error_log('[get_home_best_reviews] ' . $e->getMessage());
        return [];
    }
}

/* ============================================================
 * 태블릿 작업일지 + 일정산 마감 (v30)
 * - tt_workers / tt_work_logs / tt_daily_settlements 자동 생성

 * ============================================================ */

function ensure_work_log_tables(): void
{
    static $done = false;
    if (sg_skip('ensure_work_log_tables', $done)) return;

    $pdo = Database::connection();

    /* 작업자(기사) */
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_workers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            role VARCHAR(50) NULL COMMENT '직책(기사/책임자 등)',
            phone VARCHAR(20) NULL,
            pin_hash VARCHAR(255) NULL COMMENT '태블릿 로그인 PIN 해시',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        sg_error_log('[ensure_work_log_tables:tt_workers] ' . $e->getMessage());
    }

    /* 작업일지 */
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_work_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            work_date DATE NOT NULL COMMENT '작업일',
            work_time VARCHAR(8) NOT NULL COMMENT '작업 시작 시각(HH:MM)',
            worker_id INT UNSIGNED NULL COMMENT '작업자',
            job_type ENUM('tire','repair','etc') NOT NULL DEFAULT 'tire' COMMENT '작업구분: 타이어/정비/기타',
            work_content TEXT NOT NULL COMMENT '작업내용',
            work_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '작업금액(고객 청구액)',
            source ENUM('reservation','walkin') NOT NULL DEFAULT 'walkin' COMMENT '예약작업/일반 로드',
            reservation_id INT UNSIGNED NULL COMMENT '연동된 예약',
            res_no_snap VARCHAR(30) NULL COMMENT '예약번호 스냅샷',
            customer_name VARCHAR(50) NULL,
            car_info VARCHAR(100) NULL,
            product_id INT UNSIGNED NULL COMMENT '작업 상품(타이어)',
            product_name_snap VARCHAR(150) NULL,
            qty INT UNSIGNED NOT NULL DEFAULT 1,
            cost_factory INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '공장도가(개당 스냅샷)',
            cost_supply INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '매입단가(개당 스냅샷)',
            status ENUM('working','done') NOT NULL DEFAULT 'done' COMMENT '작업상태',
            settlement_id INT UNSIGNED NULL COMMENT '마감 시 잠금(일정산 연결)',
            pay_method ENUM('card','cash','transfer') NULL COMMENT '현장 결제수단(카드/현금/계좌이체) — NULL=미결제',
            pay_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 결제금액(결제등록 시)',
            paid_at DATETIME NULL COMMENT '현장 결제등록 시각',
            cash_receipt_issued TINYINT(1) NOT NULL DEFAULT 0 COMMENT '현금영수증 발행 [v42]',
            created_by VARCHAR(50) NULL COMMENT '등록자(작업자명/관리자)',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_date (work_date),
            INDEX idx_worker (worker_id),
            INDEX idx_reservation (reservation_id),
            INDEX idx_settlement (settlement_id)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        sg_error_log('[ensure_work_log_tables:tt_work_logs] ' . $e->getMessage());
    }

    /* 일정산 마감 기록 */
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_daily_settlements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            settle_date DATE NOT NULL UNIQUE COMMENT '정산일',
            tire_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '타이어판매금액',
            repair_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정비금액',
            etc_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '기타 작업매출',
            site_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 매출 합계',
            online_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 주문 매출(참고)',
            total_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '종합 매출',
            cost_factory_total INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '공장도가 합계',
            cost_supply_total INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '매입단가 합계',
            margin_total INT NOT NULL DEFAULT 0 COMMENT '현장 마진(매출-매입단가)',
            card_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 카드결제 합계',
            cash_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 현금결제 합계',
            transfer_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 계좌이체 합계',
            unpaid_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 미결제 합계',
            online_retail_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 소매(일반회원) 주문 매출',
            online_biz_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 도매(사업자) 주문 매출',
            reservation_count INT UNSIGNED NOT NULL DEFAULT 0,
            walkin_count INT UNSIGNED NOT NULL DEFAULT 0,
            work_count INT UNSIGNED NOT NULL DEFAULT 0,
            worker_stats TEXT NULL COMMENT '작업자별 집계(JSON)',
            closed_by VARCHAR(50) NULL COMMENT '마감한 관리자',
            closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reopened_at DATETIME NULL COMMENT '마감 해제 시각(재마감시 갱신)',
            INDEX idx_date (settle_date)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        sg_error_log('[ensure_work_log_tables:tt_daily_settlements] ' . $e->getMessage());
    }

    /* [v40] 구버전 운영 DB 보강 — 결제수단·결제금액/결제구분 스냅샷 컬럼 자동 추가
       (CREATE TABLE IF NOT EXISTS 는 이미 존재하는 테이블엔 무효 → SHOW COLUMNS 확인 후 ALTER) */
    foreach ([
        ['tt_work_logs', 'pay_method',        "ALTER TABLE tt_work_logs ADD COLUMN pay_method ENUM('card','cash','transfer') NULL COMMENT '현장 결제수단(카드/현금/계좌이체) — NULL=미결제' AFTER settlement_id"],
        ['tt_work_logs', 'pay_amount',        "ALTER TABLE tt_work_logs ADD COLUMN pay_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 결제금액(결제등록 시)' AFTER pay_method"],
        ['tt_work_logs', 'paid_at',           "ALTER TABLE tt_work_logs ADD COLUMN paid_at DATETIME NULL COMMENT '현장 결제등록 시각' AFTER pay_amount"],
        ['tt_work_logs', 'cash_receipt_issued', "ALTER TABLE tt_work_logs ADD COLUMN cash_receipt_issued TINYINT(1) NOT NULL DEFAULT 0 COMMENT '현금영수증 발행 [v42]' AFTER paid_at"],
        ['tt_daily_settlements', 'card_sales',          "ALTER TABLE tt_daily_settlements ADD COLUMN card_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 카드결제 합계' AFTER margin_total"],
        ['tt_daily_settlements', 'cash_sales',          "ALTER TABLE tt_daily_settlements ADD COLUMN cash_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 현금결제 합계' AFTER card_sales"],
        ['tt_daily_settlements', 'transfer_sales',      "ALTER TABLE tt_daily_settlements ADD COLUMN transfer_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 계좌이체 합계' AFTER cash_sales"],
        ['tt_daily_settlements', 'unpaid_sales',        "ALTER TABLE tt_daily_settlements ADD COLUMN unpaid_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 미결제 합계' AFTER transfer_sales"],
        ['tt_daily_settlements', 'online_retail_sales',  "ALTER TABLE tt_daily_settlements ADD COLUMN online_retail_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 소매(일반회원) 주문 매출' AFTER unpaid_sales"],
        ['tt_daily_settlements', 'online_biz_sales',    "ALTER TABLE tt_daily_settlements ADD COLUMN online_biz_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 도매(사업자) 주문 매출' AFTER online_retail_sales"],
        /* [v41] 매출 대분류(소매/도매/온라인) + 마진 분류 + 카드 수수료 스냅샷 */
        ['tt_daily_settlements', 'retail_sales',        "ALTER TABLE tt_daily_settlements ADD COLUMN retail_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '소매매출(현장 로드손님)' AFTER online_biz_sales"],
        ['tt_daily_settlements', 'resv_sales',          "ALTER TABLE tt_daily_settlements ADD COLUMN resv_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 매출 — 예약방문 작업' AFTER retail_sales"],
        ['tt_daily_settlements', 'wholesale_sales',     "ALTER TABLE tt_daily_settlements ADD COLUMN wholesale_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '도매매출(온라인 도매주문)' AFTER resv_sales"],
        ['tt_daily_settlements', 'online_channel_sales', "ALTER TABLE tt_daily_settlements ADD COLUMN online_channel_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인매출(예약+온라인 소매주문)' AFTER wholesale_sales"],
        ['tt_daily_settlements', 'retail_margin',       "ALTER TABLE tt_daily_settlements ADD COLUMN retail_margin INT NOT NULL DEFAULT 0 COMMENT '소매마진' AFTER online_channel_sales"],
        ['tt_daily_settlements', 'online_margin',      "ALTER TABLE tt_daily_settlements ADD COLUMN online_margin INT NOT NULL DEFAULT 0 COMMENT '온라인마진' AFTER retail_margin"],
        ['tt_daily_settlements', 'wholesale_margin',   "ALTER TABLE tt_daily_settlements ADD COLUMN wholesale_margin INT NOT NULL DEFAULT 0 COMMENT '도매마진' AFTER online_margin"],
        ['tt_daily_settlements', 'online_cost_total',  "ALTER TABLE tt_daily_settlements ADD COLUMN online_cost_total INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 주문 원가(매입단가)' AFTER wholesale_margin"],
        ['tt_daily_settlements', 'card_total_sales',   "ALTER TABLE tt_daily_settlements ADD COLUMN card_total_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '전체 카드결제 매출(현장+온라인)' AFTER online_cost_total"],
        ['tt_daily_settlements', 'card_fee_rate',     "ALTER TABLE tt_daily_settlements ADD COLUMN card_fee_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '카드 수수료율(%) 스냅샷' AFTER card_total_sales"],
        ['tt_daily_settlements', 'card_fee_rate_site',   "ALTER TABLE tt_daily_settlements ADD COLUMN card_fee_rate_site DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '현장 카드 수수료율(%) [v42]' AFTER card_fee_rate"],
        ['tt_daily_settlements', 'card_fee_rate_online', "ALTER TABLE tt_daily_settlements ADD COLUMN card_fee_rate_online DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '온라인 카드 수수료율(%) [v42]' AFTER card_fee_rate_site"],
        ['tt_daily_settlements', 'card_fee_amount',    "ALTER TABLE tt_daily_settlements ADD COLUMN card_fee_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '카드 수수료 금액' AFTER card_fee_rate"],
        ['tt_daily_settlements', 'card_net_sales',     "ALTER TABLE tt_daily_settlements ADD COLUMN card_net_sales INT NOT NULL DEFAULT 0 COMMENT '카드 순매출(수수료 차감)' AFTER card_fee_amount"],
        /* [v43] 일반 도매매출(로컬 거래처) 스냅샷 */
        ['tt_daily_settlements', 'local_wholesale_sales',  "ALTER TABLE tt_daily_settlements ADD COLUMN local_wholesale_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '일반 도매매출(로컬 거래처) [v43]' AFTER card_net_sales"],
        ['tt_daily_settlements', 'wholesale_group_sales',   "ALTER TABLE tt_daily_settlements ADD COLUMN wholesale_group_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '도매매출 합계(온라인 도매+일반 도매) [v43]' AFTER local_wholesale_sales"],
        ['tt_daily_settlements', 'wholesale_card_sales',    "ALTER TABLE tt_daily_settlements ADD COLUMN wholesale_card_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '일반도매 카드결제 합계 [v43]' AFTER wholesale_group_sales"],
        ['tt_daily_settlements', 'local_wholesale_margin',  "ALTER TABLE tt_daily_settlements ADD COLUMN local_wholesale_margin INT NOT NULL DEFAULT 0 COMMENT '일반도매 마진 [v43]' AFTER wholesale_card_sales"],
        ['tt_daily_settlements', 'wholesale_margin_total',  "ALTER TABLE tt_daily_settlements ADD COLUMN wholesale_margin_total INT NOT NULL DEFAULT 0 COMMENT '도매마진 합계(온라인 도매+일반 도매) [v43]' AFTER local_wholesale_margin"],
        ['tt_daily_settlements', 'local_wholesale_count',   "ALTER TABLE tt_daily_settlements ADD COLUMN local_wholesale_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '일반도매 거래 건수 [v43]' AFTER wholesale_margin_total"],
    ] as [$tbl, $col, $ddl]) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM {$tbl} LIKE '{$col}'")->fetch()) {
                $pdo->exec($ddl);
            }
        } catch (Throwable $e) {
            sg_error_log("[ensure_work_log_tables:{$tbl}.{$col}] " . $e->getMessage());
        }
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_work_log_tables');
}

/** 작업구분 라벨 */
function work_job_type_labels(): array
{
    return [
        'tire'   => '타이어',
        'repair' => '정비',
        'etc'    => '기타',
    ];
}

/** 활성 작업자 목록 */
function get_workers(bool $activeOnly = true): array
{
    try {
        $pdo = Database::connection();
        $sql = 'SELECT * FROM tt_workers' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, name ASC';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** 작업자명 조회 (id → name) */
function worker_name(?int $id): string
{
    static $cache = null;
    if ($id === null || $id <= 0) return '';
    if ($cache === null) {
        $cache = [];
        foreach (get_workers(false) as $w) $cache[(int)$w['id']] = $w['name'];
    }
    return $cache[$id] ?? '';
}

/** [v41] 카드 결제 수수료율(%) — 사이트 설정 (기본 0) */
function card_fee_rate(): float
{
    $v = (float)get_setting('card_fee_rate', '0');
    return ($v >= 0 && $v <= 15) ? $v : 0.0;
}

/** [v42] 현장 카드 수수료율(%) — 태블릿 작업일지 카드결제에 적용 (기본: 구 단일요율 승계) */
function card_fee_rate_site(): float
{
    $v = (float)get_setting('card_fee_rate_site', (string)card_fee_rate());
    return ($v >= 0 && $v <= 15) ? $v : 0.0;
}

/** [v42] 온라인 카드 수수료율(%) — 온라인 주문 카드결제에 적용 (기본: 구 단일요율 승계) */
function card_fee_rate_online(): float
{
    $v = (float)get_setting('card_fee_rate_online', (string)card_fee_rate());
    return ($v >= 0 && $v <= 15) ? $v : 0.0;
}

/** [v43] 집계 기본 골격 — 일/월 정산 공용
 *  대분류 [v43]: 소매(현장 로드손님) / 온라인(소매 예약장착+소매 택배) / 도매(온라인 도매주문+일반 도매·로컬 거래처) + 종합 */
function settle_empty_aggregate(): array
{
    $newCat = fn(): array => ['count' => 0, 'card' => 0, 'cash' => 0, 'transfer' => 0, 'unpaid' => 0, 'total' => 0,
                              'receipt' => 0, 'taxinv' => 0];
    return [
        /* 대분류 [v43]: 소매매출(현장 로드손님 전용) / 온라인매출(예약장착+소매택배) / 도매매출(온라인 도매+일반 도매) */
        'retail_sales' => 0, 'resv_sales' => 0, 'online_retail_sales' => 0, 'online_biz_sales' => 0,
        'online_channel_sales' => 0, 'wholesale_sales' => 0, 'online_group_sales' => 0, 'total_sales' => 0,
        /* [v43] 일반 도매매출(로컬 거래처 전화주문) */
        'local_wholesale_sales' => 0, 'local_wholesale_count' => 0, 'wholesale_group_sales' => 0,
        'wholesale_card_sales' => 0, 'wholesale_cash_sales' => 0, 'wholesale_unpaid_sales' => 0,
        /* 현장 상세 (작업일지 전체 = 소매+예약) */
        'tire_sales' => 0, 'repair_sales' => 0, 'etc_sales' => 0, 'site_sales' => 0,
        'online_sales' => 0,
        'cost_factory_total' => 0, 'cost_supply_total' => 0, 'online_cost_total' => 0,
        /* 결제수단 (작업일지 현장결제 — 소매+예약 합산) */
        'card_sales' => 0, 'cash_sales' => 0, 'transfer_sales' => 0, 'unpaid_sales' => 0, 'unpaid_count' => 0,
        'paid_total' => 0,
        'online_retail_count' => 0, 'online_biz_count' => 0, 'online_count' => 0,
        'online_card_sales' => 0, 'online_bank_sales' => 0,
        /* 카드 수수료 [v42] — 현장/온라인 요율 분리 */
        'card_total_sales' => 0, 'card_fee_rate' => 0.0, 'card_fee_rate_site' => 0.0, 'card_fee_rate_online' => 0.0,
        'card_fee_site_amount' => 0, 'card_fee_online_amount' => 0, 'card_fee_amount' => 0, 'card_net_sales' => 0,
        /* 마진 (소매/온라인/도매 + 토탈) */
        'retail_margin' => 0, 'online_margin' => 0, 'wholesale_margin' => 0, 'online_margin_group' => 0, 'margin_total' => 0,
        'local_wholesale_margin' => 0, 'wholesale_margin_total' => 0,
        /* 현금영수증 / 세금계산서 발행 건수 [v42] */
        'receipt_count' => 0, 'receipt_order_count' => 0, 'receipt_log_count' => 0,
        'taxinv_count' => 0, 'taxinv_order_count' => 0, 'taxinv_log_count' => 0,
        'reservation_count' => 0, 'walkin_count' => 0, 'work_count' => 0,
        'working_count' => 0, 'reservation_sales' => 0, 'walkin_sales' => 0,
        'cats' => [
            'tire' => $newCat(), 'repair' => $newCat(), 'etc' => $newCat(), 'retail_total' => $newCat(),
            'online_resv' => $newCat(), 'online_retail' => $newCat(),
            'online_biz' => $newCat(), 'wholesale_local' => $newCat(),
            'online_total' => $newCat(), 'wholesale_total' => $newCat(),
        ],
        'workers' => [], 'logs' => [],
    ];
}

/** [v42] 작업일지 로그 배열 → 소매(walk-in)/온라인(예약장착) 분리 집계 (일/월 공용, 참조로 결과 누적) */
function settle_aggregate_logs(array $logs, array &$result): void
{
    $labels = work_job_type_labels();
    $result['logs'] = $logs;
    $result['work_count'] = count($logs);

    foreach ($logs as $log) {
        $amount = (int)$log['work_amount'];
        $qty = max(1, (int)$log['qty']);
        $factory = (int)$log['cost_factory'] * $qty;
        $supply  = (int)$log['cost_supply'] * $qty;
        $isResv = ($log['source'] ?? 'walkin') === 'reservation';

        /* 결제수단 — 등록된 결제가 없으면 외상으로 계산 (결제금액은 등록값 우선) */
        $payMethod = $log['pay_method'] ?? null;
        $payAmount = (int)($log['pay_amount'] ?? 0) ?: $amount;

        /* [v42] 현금영수증/세금계산서 발행 여부 */
        $receiptIssued = (int)($log['cash_receipt_issued'] ?? 0) === 1;
        $taxInvIssued  = !empty($log['tax_invoice_issued']);

        $result['site_sales'] += $amount;                 // 현장 작업 전체(소매+예약)
        $result['cost_factory_total'] += $factory;
        $result['cost_supply_total'] += $supply;

        if ($isResv) {
            /* 온라인 매출 — 소매자 예약장착(온라인 주문→예약 방문 작업) */
            $result['resv_sales'] += $amount;
            $result['cats']['online_resv']['count']++;
            $result['cats']['online_resv']['total'] += $amount;
            $result['cats']['online_total']['count']++;
            $result['cats']['online_total']['total'] += $amount;
            $result['reservation_count']++;
            $result['reservation_sales'] += $amount;
            $result['online_margin'] += $amount - $supply;
            if ($receiptIssued) {
                $result['receipt_count']++;
                $result['receipt_log_count']++;
                $result['cats']['online_resv']['receipt']++;
                $result['cats']['online_total']['receipt']++;
            }
            if ($taxInvIssued) {
                $result['taxinv_count']++;
                $result['taxinv_log_count']++;
                $result['cats']['online_resv']['taxinv']++;
                $result['cats']['online_total']['taxinv']++;
            }
        } else {
            /* 소매매출 — 현장 로드손님(walk-in): 타이어판매/정비/기타작업 (예약·택배 제외) */
            $result['retail_sales'] += $amount;
            $jobCat = in_array($log['job_type'], ['tire', 'repair', 'etc'], true) ? $log['job_type'] : 'etc';
            if ($jobCat === 'tire') $result['tire_sales'] += $amount;
            elseif ($jobCat === 'repair') $result['repair_sales'] += $amount;
            else $result['etc_sales'] += $amount;
            $result['cats'][$jobCat]['count']++;
            $result['cats'][$jobCat]['total'] += $amount;
            $result['cats']['retail_total']['count']++;
            $result['cats']['retail_total']['total'] += $amount;
            $result['walkin_count']++;
            $result['walkin_sales'] += $amount;
            $result['retail_margin'] += $amount - $supply;
            if ($receiptIssued) {
                $result['receipt_count']++;
                $result['receipt_log_count']++;
                $result['cats'][$jobCat]['receipt']++;
                $result['cats']['retail_total']['receipt']++;
            }
            if ($taxInvIssued) {
                $result['taxinv_count']++;
                $result['taxinv_log_count']++;
                $result['cats'][$jobCat]['taxinv']++;
                $result['cats']['retail_total']['taxinv']++;
            }
        }

        if ($payMethod === 'card' || $payMethod === 'cash' || $payMethod === 'transfer') {
            $result[$payMethod . '_sales'] += $payAmount;
            $result['paid_total'] += $payAmount;
            foreach ($isResv ? ['online_resv', 'online_total'] : [$log['job_type'], 'retail_total'] as $ck) {
                if (isset($result['cats'][$ck])) $result['cats'][$ck][$payMethod] += $payAmount;
            }
        } else {
            $result['unpaid_sales'] += $amount;
            $result['unpaid_count']++;
            foreach ($isResv ? ['online_resv', 'online_total'] : [$log['job_type'], 'retail_total'] as $ck) {
                if (isset($result['cats'][$ck])) $result['cats'][$ck]['unpaid'] += $amount;
            }
        }

        if (($log['status'] ?? '') === 'working') $result['working_count']++;

        $wid = (int)$log['worker_id'];
        $wname = worker_name($wid) ?: '미지정';
        if (!isset($result['workers'][$wname])) {
            $result['workers'][$wname] = [
                'name' => $wname, 'count' => 0, 'amount' => 0,
                'tire' => 0, 'repair' => 0, 'etc' => 0,
                'card' => 0, 'cash' => 0, 'transfer' => 0, 'unpaid' => 0, 'margin' => 0,
            ];
        }
        $result['workers'][$wname]['count']++;
        $result['workers'][$wname]['amount'] += $amount;
        $result['workers'][$wname][in_array($log['job_type'], ['tire', 'repair', 'etc'], true) ? $log['job_type'] : 'etc'] += $amount;
        $result['workers'][$wname]['margin'] += $amount - $supply;
        if ($payMethod === 'card' || $payMethod === 'cash' || $payMethod === 'transfer') {
            $result['workers'][$wname][$payMethod] += $payAmount;
        } else {
            $result['workers'][$wname]['unpaid'] += $amount;
        }

        // 라벨 부착 (표시용)
        $log['job_type_label'] = $labels[$log['job_type']] ?? $log['job_type'];
    }
}

/** [v43] 온라인 주문 배열(매출/결제/회원유형/원가/증빙 포함) → 소매택배/사업자택배 분리 집계 (일/월 공용)
 *  사업자 택배(온라인 도매)는 [v43]부터 온라인 그룹이 아니라 도매 그룹(wholesale_total)에 합산된다. */
function settle_aggregate_orders(array $orders, array &$result): void
{
    foreach ($orders as $o) {
        $amt = (int)$o['total_amount'];
        $cost = (int)($o['order_cost'] ?? 0);
        $isBiz = ($o['user_type'] ?? 'personal') === 'business';
        $ocKey = $isBiz ? 'online_biz' : 'online_retail';
        $grpKey = $isBiz ? 'wholesale_total' : 'online_total';   /* [v43] 대분류 그룹 키 */
        $receiptIssued = (int)($o['cash_receipt_issued'] ?? 0) === 1;
        $taxInvIssued  = !empty($o['tax_invoice_issued']);

        $result['online_sales'] += $amt;
        $result['online_cost_total'] += $cost;
        $result['cats'][$ocKey]['count']++;
        $result['cats'][$ocKey]['total'] += $amt;
        $result['cats'][$grpKey]['count']++;
        $result['cats'][$grpKey]['total'] += $amt;
        if ($receiptIssued) {
            $result['receipt_count']++;
            $result['receipt_order_count']++;
            $result['cats'][$ocKey]['receipt']++;
            $result['cats'][$grpKey]['receipt']++;
        }
        if ($taxInvIssued) {
            $result['taxinv_count']++;
            $result['taxinv_order_count']++;
            $result['cats'][$ocKey]['taxinv']++;
            $result['cats'][$grpKey]['taxinv']++;
        }
        if ($isBiz) {
            /* 도매매출 — 온라인 도매주문(사업자 택배) */
            $result['online_biz_sales'] += $amt;
            $result['wholesale_sales'] += $amt;
            $result['online_biz_count']++;
            $result['wholesale_margin'] += $amt - $cost;
        } else {
            /* 온라인 매출 — 소매자 택배(온라인 소매주문) */
            $result['online_retail_sales'] += $amt;
            $result['online_retail_count']++;
            $result['online_margin'] += $amt - $cost;
        }
        if (($o['payment_method'] ?? '') === 'card') {
            $result['online_card_sales'] += $amt;
            $result['cats'][$ocKey]['card'] += $amt;
            $result['cats'][$grpKey]['card'] += $amt;
        } else {
            $result['online_bank_sales'] += $amt;
            $result['cats'][$ocKey]['transfer'] += $amt;
            $result['cats'][$grpKey]['transfer'] += $amt;
        }
    }
    $result['online_count'] = $result['online_retail_count'] + $result['online_biz_count'];
}

/** [v43] 일반 도매매출(로컬 거래처 — tt_wholesale_sales 행) → 도매 그룹 집계 (일/월 공용) */
function settle_aggregate_wholesale(array $sales, array &$result): void
{
    foreach ($sales as $s) {
        $amt = (int)$s['total_amount'];
        $cost = (int)($s['cost_amount'] ?? 0);
        $payMethod = $s['pay_method'] ?? 'unpaid';
        $payAmount = (int)($s['pay_amount'] ?? 0) ?: $amt;
        $taxInvIssued = (int)($s['tax_invoice_issued'] ?? 0) === 1;

        $result['local_wholesale_sales'] += $amt;
        $result['local_wholesale_count']++;
        $result['local_wholesale_margin'] += $amt - $cost;
        $result['cats']['wholesale_local']['count']++;
        $result['cats']['wholesale_local']['total'] += $amt;
        $result['cats']['wholesale_total']['count']++;
        $result['cats']['wholesale_total']['total'] += $amt;

        if ($taxInvIssued) {
            $result['taxinv_count']++;
            $result['cats']['wholesale_local']['taxinv']++;
            $result['cats']['wholesale_total']['taxinv']++;
        }

        if ($payMethod === 'card' || $payMethod === 'cash' || $payMethod === 'transfer') {
            $result['cats']['wholesale_local'][$payMethod] += $payAmount;
            $result['cats']['wholesale_total'][$payMethod] += $payAmount;
            if ($payMethod === 'card') $result['wholesale_card_sales'] += $payAmount;
            else $result['wholesale_cash_sales'] += $payAmount;
        } else {
            $result['wholesale_unpaid_sales'] += $amt;
            $result['cats']['wholesale_local']['unpaid'] += $amt;
            $result['cats']['wholesale_total']['unpaid'] += $amt;
        }
    }
}

/** [v43] 일반 도매매출 조회 — $dateExpr 에 바인딩할 날짜/월 값과 함께 사용 */
function settle_fetch_wholesale(string $dateExpr, string $bindVal): array
{
    try {
        ensure_wholesale_sales_table();
        $stmt = Database::connection()->prepare(
            "SELECT total_amount, cost_amount, pay_method, pay_amount, tax_invoice_issued
             FROM tt_wholesale_sales
             WHERE {$dateExpr} = :dv");
        $stmt->execute(['dv' => $bindVal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        sg_error_log('[settle_fetch_wholesale] ' . $e->getMessage());
        return [];
    }
}

/** [v43] 일반 도매매출(로컬 거래처) 테이블 자동 생성 — 관리자 도매매출 관리 + 일/월 정산에서 사용 */
function ensure_wholesale_sales_table(): void
{
    static $done = false;
    if (sg_skip('ensure_wholesale_sales_table', $done)) return;
    try {
        Database::connection()->exec("
            CREATE TABLE IF NOT EXISTS tt_wholesale_sales (
                id                 INT AUTO_INCREMENT PRIMARY KEY,
                sale_date          DATE NOT NULL COMMENT '매출 일자',
                client_name        VARCHAR(100) NOT NULL COMMENT '거래처명',
                client_biz_number  VARCHAR(20) NULL COMMENT '거래처 사업자번호 (세금계산서용)',
                client_ceo         VARCHAR(50) NULL COMMENT '거래처 대표자명',
                client_address     VARCHAR(255) NULL COMMENT '거래처 주소',
                item_name          VARCHAR(255) NOT NULL COMMENT '품목명',
                spec               VARCHAR(100) NULL COMMENT '규격',
                qty                INT NOT NULL DEFAULT 1,
                unit_price         INT NOT NULL DEFAULT 0 COMMENT '단가(공급가 기준)',
                supply_amount      INT NOT NULL DEFAULT 0 COMMENT '공급가액 (단가×수량)',
                vat_amount         INT NOT NULL DEFAULT 0 COMMENT '세액(기본 10%)',
                total_amount       INT NOT NULL DEFAULT 0 COMMENT '합계금액(공급가액+세액) = 매출액',
                cost_amount        INT NOT NULL DEFAULT 0 COMMENT '매입원가 합계(마진용)',
                pay_method         ENUM('card','cash','transfer','unpaid') NOT NULL DEFAULT 'unpaid' COMMENT '결제수단',
                pay_amount         INT NOT NULL DEFAULT 0 COMMENT '결제금액',
                paid_at            DATETIME NULL COMMENT '결제등록 시각',
                tax_invoice_issued TINYINT(1) NOT NULL DEFAULT 0 COMMENT '세금계산서 발행 완료 [v43]',
                memo               TEXT NULL,
                settlement_id      INT NULL COMMENT '일정산 마감 잠금 [v43]',
                created_by         VARCHAR(50) NULL,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ws_date (sale_date),
                INDEX idx_ws_client (client_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_wholesale_sales_table');
    } catch (Throwable $e) {
        sg_error_log('[ensure_wholesale_sales_table] ' . $e->getMessage());
    }
}

/** [v43] 카드 수수료 — 현장/온라인 요율 분리 적용, 순매출·마진률 산출 (일/월 공용)
 *  일반도매 카드결제는 현장(단말기) 결제로 보아 현장 요율에 포함한다. */
function settle_apply_card_fee(array &$result): void
{
    $result['card_total_sales'] = $result['card_sales'] + $result['wholesale_card_sales'] + $result['online_card_sales'];
    $result['card_fee_rate_site'] = card_fee_rate_site();
    $result['card_fee_rate_online'] = card_fee_rate_online();
    $result['card_fee_site_amount'] = (int)round(($result['card_sales'] + $result['wholesale_card_sales']) * $result['card_fee_rate_site'] / 100);
    $result['card_fee_online_amount'] = (int)round($result['online_card_sales'] * $result['card_fee_rate_online'] / 100);
    $result['card_fee_amount'] = $result['card_fee_site_amount'] + $result['card_fee_online_amount'];
    $result['card_fee_rate'] = $result['card_total_sales'] > 0
        ? round($result['card_fee_amount'] / $result['card_total_sales'] * 100, 2)
        : 0.0;
    $result['card_net_sales'] = $result['card_total_sales'] - $result['card_fee_amount'];
    /* 대분류 정리 [v43]:
       소매매출 = 현장 로드손님 / 온라인매출 = 소매 예약장착 + 소매 택배
       도매매출 = 온라인 도매주문(사업자 택배) + 일반 도매(로컬 거래처)
       종합매출 = 소매 + 온라인 + 도매 */
    $result['online_channel_sales'] = $result['resv_sales'] + $result['online_retail_sales'];
    $result['online_group_sales'] = $result['resv_sales'] + $result['online_retail_sales'];
    $result['wholesale_group_sales'] = $result['wholesale_sales'] + $result['local_wholesale_sales'];
    $result['total_sales'] = $result['retail_sales'] + $result['online_group_sales'] + $result['wholesale_group_sales'];
    $result['online_margin_group'] = $result['online_margin'];
    $result['wholesale_margin_total'] = $result['wholesale_margin'] + $result['local_wholesale_margin'];
    $result['margin_total'] = $result['retail_margin'] + $result['online_margin_group'] + $result['wholesale_margin_total'];
}

/** [v42] 온라인 주문 조회(원가·현금영수증·세금계산서 포함) — $whereDate 에 바인딩할 날짜/월 값과 함께 사용 */
function settle_fetch_orders(string $dateExpr, string $bindVal): array
{
    try {
        $stmt = Database::connection()->prepare(
            "SELECT o.total_amount, o.payment_method, o.cash_receipt_issued, (u.user_type) AS user_type,
                    (SELECT ti.id FROM tt_tax_invoices ti
                      WHERE ti.target_type = 'order' AND ti.target_id = o.id AND ti.status = 'issued' LIMIT 1) AS tax_invoice_issued,
                    COALESCE((SELECT SUM(oi.qty * COALESCE(p.supply_price, 0))
                              FROM tt_order_items oi LEFT JOIN tt_products p ON p.id = oi.product_id
                              WHERE oi.order_id = o.id), 0) AS order_cost
             FROM tt_orders o
             LEFT JOIN tt_users u ON u.id = o.user_id
             WHERE {$dateExpr} = :dv AND o.status NOT IN ('canceled','refunded')");
        $stmt->execute(['dv' => $bindVal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[settle_fetch_orders] ' . $e->getMessage());
        return [];
    }
}

/**
 * 특정일 작업일지 집계 (마감 전 라이브 계산/마감 스냅샷 공용)
 * [v42] 대분류: 소매매출(현장 로드손님 전용) / 온라인매출(사업자 택배+소매 예약장착+소매 택배) + 종합매출
 *       마진: 소매마진 / 온라인마진 / 토탈마진, 카드 수수료 현장·온라인 요율 분리, 현금영수증·세금계산서 발행 건수
 */
function compute_day_settlement(string $date): array
{
    $pdo = Database::connection();
    $result = settle_empty_aggregate();
    $result['date'] = $date;
    try {
        $stmt = $pdo->prepare(
            'SELECT w.*, r.res_no AS live_res_no,
                    (SELECT ti.id FROM tt_tax_invoices ti
                      WHERE ti.target_type = "reservation" AND ti.target_id = w.reservation_id AND ti.status = "issued" LIMIT 1) AS tax_invoice_issued
             FROM tt_work_logs w
             LEFT JOIN tt_reservations r ON r.id = w.reservation_id
             WHERE w.work_date = :d
             ORDER BY w.work_time ASC, w.id ASC');
        $stmt->execute(['d' => $date]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $result;
    }

    /* 현장 로그 1건 → 결제수단별/매출구분별/작업자별 집계 */
    settle_aggregate_logs($logs, $result);

    /* 온라인 주문 매출 — 소매(일반회원 택배)/도매(사업자 택배) 분리 + 주문 원가(매입단가) */
    settle_aggregate_orders(settle_fetch_orders('DATE(o.created_at)', $date), $result);
    /* [v43] 일반 도매매출(로컬 거래처 전화주문) — 도매 그룹에 합산 */
    settle_aggregate_wholesale(settle_fetch_wholesale('sale_date', $date), $result);
    settle_apply_card_fee($result);

    return $result;
}
/** 현장 결제수단 라벨 (카드/현금/계좌/미결제) */
function work_pay_method_labels(): array
{
    return [
        'card'     => '💳 카드결제',
        'cash'     => '💵 현금결제',
        'transfer' => '🏦 계좌이체',
    ];
}

/** 특정일 마감 기록 조회 */
function get_settlement(string $date): ?array
{
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM tt_daily_settlements WHERE settle_date = :d LIMIT 1');
        $stmt->execute(['d' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** 일정산 마감 처리: 집계 스냅샷 저장 + 그날 작업일지 잠금 */
function close_day_settlement(string $date, int $adminId, string $adminName): array
{
    $pdo = Database::connection();
    /* [v41] ensure(DDL)는 트랜잭션 밖에서 먼저 — 스냅샷 컬럼 자동 보강 */
    ensure_work_log_tables();
    $pdo->beginTransaction();
    try {
        $calc = compute_day_settlement($date);
        $workersJson = json_encode(array_values($calc['workers']), JSON_UNESCAPED_UNICODE);

        $settlement = get_settlement($date);
        if ($settlement) {
            $pdo->prepare(
                'UPDATE tt_daily_settlements SET
                    tire_sales=:tire, repair_sales=:repair, etc_sales=:etc, site_sales=:site,
                    online_sales=:online, total_sales=:total,
                    cost_factory_total=:cft, cost_supply_total=:cst, margin_total=:margin,
                    card_sales=:card, cash_sales=:cash, transfer_sales=:tf, unpaid_sales=:unpaid,
                    online_retail_sales=:ort, online_biz_sales=:obz,
                    retail_sales=:rtl, resv_sales=:rsv, wholesale_sales=:whl, online_channel_sales=:onch,
                    retail_margin=:rtlmg, online_margin=:onmg, wholesale_margin=:whlmg,
                    online_cost_total=:oncost,
                    card_total_sales=:cardtot, card_fee_rate=:cfr, card_fee_rate_site=:cfrs, card_fee_rate_online=:cfro, card_fee_amount=:cfa, card_net_sales=:cnet,
                    local_wholesale_sales=:lws, wholesale_group_sales=:wgs, wholesale_card_sales=:wcs,
                    local_wholesale_margin=:lwm, wholesale_margin_total=:wmt, local_wholesale_count=:lwc,
                    reservation_count=:rcnt, walkin_count=:wcnt, work_count=:cnt,
                    worker_stats=:ws, closed_by=:by, closed_at=NOW(), reopened_at=NULL
                 WHERE id=:id')
                ->execute([
                    'tire'=>$calc['tire_sales'], 'repair'=>$calc['repair_sales'], 'etc'=>$calc['etc_sales'],
                    'site'=>$calc['site_sales'], 'online'=>$calc['online_sales'], 'total'=>$calc['total_sales'],
                    'cft'=>$calc['cost_factory_total'], 'cst'=>$calc['cost_supply_total'], 'margin'=>$calc['margin_total'],
                    'card'=>$calc['card_sales'], 'cash'=>$calc['cash_sales'], 'tf'=>$calc['transfer_sales'],
                    'unpaid'=>$calc['unpaid_sales'], 'ort'=>$calc['online_retail_sales'], 'obz'=>$calc['online_biz_sales'],
                    'rtl'=>$calc['retail_sales'], 'rsv'=>$calc['resv_sales'], 'whl'=>$calc['wholesale_sales'], 'onch'=>$calc['online_channel_sales'],
                    'rtlmg'=>$calc['retail_margin'], 'onmg'=>$calc['online_margin'], 'whlmg'=>$calc['wholesale_margin'],
                    'oncost'=>$calc['online_cost_total'],
                    'cardtot'=>$calc['card_total_sales'], 'cfr'=>number_format($calc['card_fee_rate'], 2, '.', ''),
                    'cfrs'=>number_format($calc['card_fee_rate_site'], 2, '.', ''),
                    'cfro'=>number_format($calc['card_fee_rate_online'], 2, '.', ''),
                    'cfa'=>$calc['card_fee_amount'], 'cnet'=>$calc['card_net_sales'],
                    'lws'=>$calc['local_wholesale_sales'], 'wgs'=>$calc['wholesale_group_sales'], 'wcs'=>$calc['wholesale_card_sales'],
                    'lwm'=>$calc['local_wholesale_margin'], 'wmt'=>$calc['wholesale_margin_total'], 'lwc'=>$calc['local_wholesale_count'],
                    'rcnt'=>$calc['reservation_count'], 'wcnt'=>$calc['walkin_count'], 'cnt'=>$calc['work_count'],
                    'ws'=>$workersJson, 'by'=>$adminName, 'id'=>(int)$settlement['id'],
                ]);
            $sid = (int)$settlement['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO tt_daily_settlements
                    (settle_date, tire_sales, repair_sales, etc_sales, site_sales, online_sales, total_sales,
                     cost_factory_total, cost_supply_total, margin_total,
                     card_sales, cash_sales, transfer_sales, unpaid_sales, online_retail_sales, online_biz_sales,
                     retail_sales, resv_sales, wholesale_sales, online_channel_sales,
                     retail_margin, online_margin, wholesale_margin, online_cost_total,
                     card_total_sales, card_fee_rate, card_fee_rate_site, card_fee_rate_online, card_fee_amount, card_net_sales,
                      local_wholesale_sales, wholesale_group_sales, wholesale_card_sales,
                      local_wholesale_margin, wholesale_margin_total, local_wholesale_count,
                     reservation_count, walkin_count,
                     work_count, worker_stats, closed_by)
                 VALUES
                    (:d, :tire, :repair, :etc, :site, :online, :total,
                     :cft, :cst, :margin,
                     :card, :cash, :tf, :unpaid, :ort, :obz,
                     :rtl, :rsv, :whl, :onch,
                     :rtlmg, :onmg, :whlmg, :oncost,
                     :cardtot, :cfr, :cfrs, :cfro, :cfa, :cnet,
                     :lws, :wgs, :wcs, :lwm, :wmt, :lwc,
                     :rcnt, :wcnt,
                     :cnt, :ws, :by)')
                ->execute([
                    'd'=>$date,
                    'tire'=>$calc['tire_sales'], 'repair'=>$calc['repair_sales'], 'etc'=>$calc['etc_sales'],
                    'site'=>$calc['site_sales'], 'online'=>$calc['online_sales'], 'total'=>$calc['total_sales'],
                    'cft'=>$calc['cost_factory_total'], 'cst'=>$calc['cost_supply_total'], 'margin'=>$calc['margin_total'],
                    'card'=>$calc['card_sales'], 'cash'=>$calc['cash_sales'], 'tf'=>$calc['transfer_sales'],
                    'unpaid'=>$calc['unpaid_sales'], 'ort'=>$calc['online_retail_sales'], 'obz'=>$calc['online_biz_sales'],
                    'rtl'=>$calc['retail_sales'], 'rsv'=>$calc['resv_sales'], 'whl'=>$calc['wholesale_sales'], 'onch'=>$calc['online_channel_sales'],
                    'rtlmg'=>$calc['retail_margin'], 'onmg'=>$calc['online_margin'], 'whlmg'=>$calc['wholesale_margin'],
                    'oncost'=>$calc['online_cost_total'],
                    'cardtot'=>$calc['card_total_sales'], 'cfr'=>number_format($calc['card_fee_rate'], 2, '.', ''),
                    'cfrs'=>number_format($calc['card_fee_rate_site'], 2, '.', ''),
                    'cfro'=>number_format($calc['card_fee_rate_online'], 2, '.', ''),
                    'cfa'=>$calc['card_fee_amount'], 'cnet'=>$calc['card_net_sales'],
                    'lws'=>$calc['local_wholesale_sales'], 'wgs'=>$calc['wholesale_group_sales'], 'wcs'=>$calc['wholesale_card_sales'],
                    'lwm'=>$calc['local_wholesale_margin'], 'wmt'=>$calc['wholesale_margin_total'], 'lwc'=>$calc['local_wholesale_count'],
                    'rcnt'=>$calc['reservation_count'], 'wcnt'=>$calc['walkin_count'], 'cnt'=>$calc['work_count'],
                    'ws'=>$workersJson, 'by'=>$adminName,
                ]);
            $sid = (int)$pdo->lastInsertId();
        }

        /* 그날 작업일지 잠금 */
        $pdo->prepare('UPDATE tt_work_logs SET settlement_id = :sid WHERE work_date = :d AND settlement_id IS NULL')
            ->execute(['sid'=>$sid, 'd'=>$date]);

        /* [v43] 그날 일반 도매매출(로컬 거래처) 행 잠금 */
        try {
            ensure_wholesale_sales_table();
            $pdo->prepare('UPDATE tt_wholesale_sales SET settlement_id = :sid WHERE sale_date = :d AND settlement_id IS NULL')
                ->execute(['sid'=>$sid, 'd'=>$date]);
        } catch (Throwable $e) {
            sg_error_log('[close_day_settlement:wholesale_lock] ' . $e->getMessage());
        }

        $pdo->commit();
        AdminAuth::log($adminId, 'daily_settlement_close', "일정산 마감 {$date} — 현장매출 " . number_format($calc['site_sales']) . '원');
        return ['ok'=>true, 'settlement_id'=>$sid, 'calc'=>$calc];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[close_day_settlement] ' . $e->getMessage());
        return ['ok'=>false, 'msg'=>'마감 처리 중 오류가 발생했습니다.'];
    }
}

/** 일정산 마감 해제 */
function reopen_day_settlement(string $date, int $adminId): array
{
    $pdo = Database::connection();
    try {
        $settlement = get_settlement($date);
        if (!$settlement) return ['ok'=>false, 'msg'=>'마감 이력이 없습니다.'];
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE tt_work_logs SET settlement_id = NULL WHERE settlement_id = :sid')
            ->execute(['sid'=>(int)$settlement['id']]);
        $pdo->prepare('UPDATE tt_wholesale_sales SET settlement_id = NULL WHERE settlement_id = :sid')
            ->execute(['sid'=>(int)$settlement['id']]);
        $pdo->prepare('UPDATE tt_daily_settlements SET reopened_at = NOW() WHERE id = :id')
            ->execute(['id'=>(int)$settlement['id']]);
        $pdo->commit();
        AdminAuth::log($adminId, 'daily_settlement_reopen', "일정산 마감 해제 {$date}");
        return ['ok'=>true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[reopen_day_settlement] ' . $e->getMessage());
        return ['ok'=>false, 'msg'=>'마감 해제 중 오류가 발생했습니다.'];
    }
}

/** 예약 → 작업일지 연동 생성 (태블릿 예약판 "작업 시작") */
function create_work_log_from_reservation(int $reservationId, int $workerId, string $jobType = ''): array
{
    $pdo = Database::connection();
    try {
        /* 공장도가 = price_original(정상가), 매입단가 = supply_price(공급가) 스냅샷 */
        $stmt = $pdo->prepare('SELECT r.*, p.price_original AS factory_price, p.supply_price FROM tt_reservations r LEFT JOIN tt_products p ON p.id = r.product_id WHERE r.id = :id LIMIT 1');
        $stmt->execute(['id'=>$reservationId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok'=>false, 'msg'=>'예약을 찾을 수 없습니다.'];

        /* 작업구분: 호출자가 지정하지 않으면 예약의 방문 구분(타이어/정비/기타)을 그대로 이어받음 */
        if ($jobType === '' || !array_key_exists($jobType, work_job_type_labels())) {
            $visitType = $r['visit_type'] ?? 'tire';
            $jobType = array_key_exists($visitType, work_job_type_labels()) ? $visitType : 'tire';
        }

        /* 중복 방지: 같은 예약에 이미 작업일지가 있으면 그걸 반환 */
        $chk = $pdo->prepare('SELECT id FROM tt_work_logs WHERE reservation_id = :rid LIMIT 1');
        $chk->execute(['rid'=>$reservationId]);
        $exist = $chk->fetchColumn();
        if ($exist) return ['ok'=>false, 'msg'=>'이미 작업일지가 연동된 예약입니다.', 'log_id'=>(int)$exist];

        /* 마감된 날짜면 연동 금지 (일정산 스냅샷 보호) */
        $settle = get_settlement((string)$r['visit_date']);
        if ($settle && $settle['reopened_at'] === null) {
            return ['ok'=>false, 'msg'=>'해당 날짜는 일정산 마감이 완료되어 예약 작업을 연동할 수 없습니다.'];
        }

        $amount = (int)$r['final_amount'] ?: ((int)$r['unit_price'] * (int)$r['qty']);
        $workTime = date('H:i');
        $visitTime = trim((string)$r['visit_time']);
        if ($visitTime !== '' && preg_match('/^\d{1,2}:\d{2}/', $visitTime)) {
            $workTime = substr($visitTime, 0, 5);
        }

        $pdo->prepare(
            'INSERT INTO tt_work_logs
                (work_date, work_time, worker_id, job_type, work_content, work_amount, source,
                 reservation_id, res_no_snap, customer_name, car_info, product_id, product_name_snap,
                 qty, cost_factory, cost_supply, status, created_by)
             VALUES
                (:wd, :wt, :worker, :jt, :content, :amount, "reservation",
                 :rid, :resno, :cname, :car, :pid, :pname,
                 :qty, :cf, :cs, "working", :by)')
            ->execute([
                'wd'=>$r['visit_date'], 'wt'=>$workTime, 'worker'=>$workerId, 'jt'=>$jobType,
                'content'=>'[예약·' . (work_job_type_labels()[$jobType] ?? '타이어') . '] ' . $r['product_name_snap'] . ' 외 ' . ((int)$r['qty']) . '본 교체' . ($r['memo'] ? ' — 요청: ' . $r['memo'] : ''),
                'amount'=>$amount, 'rid'=>$reservationId, 'resno'=>$r['res_no'],
                'cname'=>$r['visitor_name'], 'car'=>$r['car_info'],
                'pid'=>$r['product_id'], 'pname'=>$r['product_name_snap'],
                'qty'=>(int)$r['qty'],
                'cf'=>(int)($r['factory_price'] ?? 0), 'cs'=>(int)($r['supply_price'] ?? 0),
                'by'=>worker_name($workerId) ?: '태블릿',
            ]);
        $logId = (int)$pdo->lastInsertId();

        /* 차량번호가 있으면 차량 마스터에 자동 등록 (예약 고객 연락처까지 확보) */
        $rPlate = extract_plate_no((string)($r['car_info'] ?? ''));
        if ($rPlate !== '') {
            capture_vehicle([
                'plate_no'    => $rPlate,
                'user_id'     => $r['user_id'] ?? null,
                'owner_name'  => (string)$r['visitor_name'],
                'owner_phone' => (string)$r['visitor_phone'],
                'car_model'   => car_model_from_info((string)($r['car_info'] ?? '')),
                'tire_date'   => (string)$r['visit_date'],
                'tire_product'=> (string)$r['product_name_snap'],
            ]);
        }

        /* 예약 상태 → 방문완료(작업 진행 표시) */
        try {
            $pdo->prepare("UPDATE tt_reservations SET status = 'visited' WHERE id = :id AND status IN ('pending','confirmed')")
                ->execute(['id'=>$reservationId]);
        } catch (Throwable $e2) {}

        return ['ok'=>true, 'log_id'=>$logId];
    } catch (Throwable $e) {
        error_log('[create_work_log_from_reservation] ' . $e->getMessage());
        return ['ok'=>false, 'msg'=>'작업일지 생성 중 오류가 발생했습니다.'];
    }
}

/**
 * 태블릿 실시간 상태 (작업일지 + 예약 원본) — api-state.php / 태블릿 페이지 공용
 * 새 예약 접수나 작업일지 변경이 일어나면 해시가 변해 30초 폴링이 자동 갱신한다.
 * 페이지와 api가 반드시 같은 계산을 써야 서버 상태 변화 없이는 새로고침이 일어나지 않는다.
 * 반환: hash + log_count + reservation_count(당일 미취소 예약)
 */
function tablet_state_data(string $date): array
{
    $pdo = Database::connection();
    $logs = [];
    $reservations = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT w.id, w.work_time, w.worker_id, w.job_type, w.work_amount, w.source,
                    w.reservation_id, w.res_no_snap, w.customer_name, w.status, w.settlement_id,
                    w.pay_method, w.pay_amount, w.cash_receipt_issued
             FROM tt_work_logs w WHERE w.work_date = :d ORDER BY w.work_time ASC, w.id ASC');
        $stmt->execute(['d'=>$date]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try {
        $stmt = $pdo->prepare(
            "SELECT id, res_no, visit_time, visitor_name, status, product_id
             FROM tt_reservations
             WHERE visit_date = :d AND status NOT IN ('canceled','noshow')
             ORDER BY visit_time ASC");
        $stmt->execute(['d'=>$date]);
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    return [
        'hash' => md5(json_encode([$logs, $reservations])),
        'log_count' => count($logs),
        'reservation_count' => count($reservations),
    ];
}

/* ============================================================
 * v31 추가 기능 — 운송장·배송조회 / 차량 교체주기 / 작업일지 사진 /
 * 예약 리마인더 / 월간 정산·인센티브 / 재고 입출고 이력 / 태블릿 PIN 잠금
 * ============================================================ */

/* ---------- 운송장 · 배송조회 ---------- */

/** 택배사 라벨 (코드 => 표시명) */
function carrier_labels(): array
{
    return [
        'cj'     => 'CJ대한통운',
        'hanjin' => '한진택배',
        'lotte'  => '롯데택배',
        'logen'  => '로젠택배',
        'kd'     => '경동택배',
        'epost'  => '우체국택배',
        'etc'    => '기타',
    ];
}

/** 택배사 공식 배송조회 URL (없으면 빈 문자열) */
function carrier_tracking_url(?string $carrier, string $trackingNo): string
{
    if ($trackingNo === '' || $carrier === null || $carrier === '') return '';
    $no = rawurlencode($trackingNo);
    switch ($carrier) {
        case 'cj':     return "https://www.doortodoor.co.kr/parcel/doortodoor.do?fsl_action=parcIntroTracing&s_type=2&s_no=0&slipno={$no}&search_type=inv_no";
        case 'hanjin': return "https://www.hanjin.com/kor/customer/ems/tracking/index.do?wbl_num_text={$no}";
        case 'lotte':  return "https://www.lotteglotech.com:9443/tracking/detail?invno={$no}";
        case 'logen':  return "https://www.logen.com/tracking/detail?slipno={$no}";
        case 'kd':     return "https://www.kdexp.com/sub3_shipping1.php?barcode={$no}";
        case 'epost':  return "https://service.epost.go.kr/trace.RetrieveEmsRigiTraceList.ems?ems_gubun=E&sid1={$no}";
        default:       return '';
    }
}

/** 결제수단 라벨 (프론트·어드민 공용) [v46]
 *  payment_method 컬럼은 ENUM이 아닌 VARCHAR(30) — 이후 수단 추가 시 스키마 변경 없이 값만 추가하면 됨. */
function payment_method_labels(): array
{
    return [
        'bank_transfer'   => '🏦 무통장입금',
        'virtual_account' => '💳 가상계좌',
        'card'             => '💠 신용·체크카드',
        'naver_pay'        => '🟢 네이버페이',
        'kakao_pay'        => '🟡 카카오페이',
    ];
}

/** 현금결제(현금영수증 대상) 수단인지 — 무통장입금/가상계좌 [v46] */
function is_cash_payment_method(string $m): bool
{
    return in_array($m, ['bank_transfer', 'virtual_account'], true);
}

/* ===== [v48] 주문 결제상태 통일 — "결제완료" 판정·동기화·현금영수증 자동 발행 ===== */

/** 주문 상태 중 "결제완료 이상"에 해당하는 값들 [v48]
 *  어드민 주문관리 라벨(shipped/done)과 신규설치 SQL 라벨(installed/completed) 계열을 전부 포함 —
 *  payment_status 컬럼이 생기기 전 legacy 주문(status='paid' 등)도 결제완료로 인정하기 위함. */
function order_paid_statuses(): array
{
    return ['paid', 'preparing', 'shipped', 'done', 'installed', 'completed'];
}

/** 주문(또는 조회 row)이 결제완료인지 [v48] — payment_status 동기화 여부와 무관하게 판정 */
function order_is_paid(array $o): bool
{
    return (string)($o['payment_status'] ?? '') === 'paid'
        || in_array((string)($o['status'] ?? ''), order_paid_statuses(), true);
}

/** 소득공제 현금영수증 자동 발행 [v48]
 *  결제가 완료(또는 관리자가 결제완료로 확정)된 시점에, 주문 시 소득공제(휴대폰 번호)를
 *  요청한 건은 관리자 수동 처리 없이 자동으로 발행 처리한다. 지출증빙(사업자번호)은 종전대로 관리자 발행.
 *  멱등(idempotent) — 이미 발행됐거나 대상이 아니면 아무것도 하지 않는다. */
function auto_issue_income_cash_receipt(PDO $pdo, int $orderId): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT id, status, payment_status, cash_receipt_type, cash_receipt_no, cash_receipt_issued
             FROM tt_orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$o || (int)$o['cash_receipt_issued'] === 1) return false;
        if ((string)$o['cash_receipt_type'] !== 'income') return false;
        if (!order_is_paid($o)) return false;

        $pdo->prepare('UPDATE tt_orders SET cash_receipt_issued = 1 WHERE id = :id')
            ->execute(['id' => $orderId]);
        $no = trim((string)$o['cash_receipt_no']);
        $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:oid, :s, :memo)')
            ->execute([
                'oid'  => $orderId,
                's'    => $o['status'],
                'memo' => '현금영수증(소득공제) 자동 발행 처리' . ($no !== '' ? " — {$no}" : ''),
            ]);
        return true;
    } catch (Throwable $e) {
        sg_error_log('[auto_issue_income_cash_receipt] ' . $e->getMessage());
        return false;
    }
}

/** 현금영수증 요청 유형 라벨 [v46] */
function cash_receipt_type_label(string $t): string
{
    return match ($t) {
        'income'  => '소득공제 (휴대폰)',
        'expense' => '지출증빙 (사업자번호)',
        default   => '미발행',
    };
}

/** tt_orders 에 운송장 컬럼 자동 추가 (+ 구매확정 confirmed_at — 마이페이지 신규 설치 500 방지) */
function ensure_order_tracking_columns(): void
{
    static $done = false;
    if (sg_skip('ensure_order_tracking_columns', $done)) return;
    try {
        $pdo = Database::connection();
        foreach ([
            ['carrier',        "ALTER TABLE tt_orders ADD COLUMN carrier VARCHAR(20) NULL COMMENT '택배사' AFTER status"],
            ['tracking_number',"ALTER TABLE tt_orders ADD COLUMN tracking_number VARCHAR(40) NULL COMMENT '운송장번호' AFTER carrier"],
            ['shipped_at',     "ALTER TABLE tt_orders ADD COLUMN shipped_at DATETIME NULL COMMENT '운송장 등록(출고) 시각' AFTER tracking_number"],
            ['confirmed_at',   "ALTER TABLE tt_orders ADD COLUMN confirmed_at DATETIME NULL COMMENT '구매확정 시각'"],
            ['recipient_addr', "ALTER TABLE tt_orders ADD COLUMN recipient_addr VARCHAR(255) NULL COMMENT '배송지 주소(합쳐서 저장)'"],
            ['cash_receipt_issued', "ALTER TABLE tt_orders ADD COLUMN cash_receipt_issued TINYINT(1) NOT NULL DEFAULT 0 COMMENT '현금영수증(지출증빙) 발행 [v42]'"],
            /* [v46] 현금영수증 요청 정보 — 주문 시 소득공제/지출증빙 선택 + 대상 번호 저장 */
            ['cash_receipt_type', "ALTER TABLE tt_orders ADD COLUMN cash_receipt_type VARCHAR(10) NOT NULL DEFAULT 'none' COMMENT '현금영수증 요청 유형(none/income/expense) [v46]'"],
            ['cash_receipt_no',   "ALTER TABLE tt_orders ADD COLUMN cash_receipt_no VARCHAR(30) NULL COMMENT '현금영수증 대상 번호(휴대폰/사업자번호) [v46]'"],
        ] as [$col, $ddl]) {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM tt_orders LIKE '{$col}'")->fetch()) {
                    $pdo->exec($ddl);
                }
            } catch (Throwable $e) {
                sg_error_log("[ensure_order_tracking_columns:{$col}] " . $e->getMessage());
            }
        }
        /* [v46] tt_orders.payment_method — 신규 설치 SQL에만 존재해 운영(마이그레이션) DB에는
           아예 없는 것이 "사업자 주문 → 상세페이지로 튕김"의 원인이었다(INSERT가 42S22로 실패).
           - 없으면 추가, ENUM 구버전이면(네이버페이 등 신규 수단 미포함) VARCHAR(30)으로 전환.
           - VARCHAR 전환은 ENUM 값을 문자열 그대로 보존하므로 기존 데이터에 안전하다. */
        try {
            $pmCol = $pdo->query("SHOW COLUMNS FROM tt_orders LIKE 'payment_method'")->fetch();
            if (!$pmCol) {
                $pdo->exec("ALTER TABLE tt_orders ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'bank_transfer' COMMENT '결제수단 [v45]' AFTER status");
            } elseif (isset($pmCol['Type']) && stripos((string)$pmCol['Type'], 'enum') === 0) {
                $pdo->exec("ALTER TABLE tt_orders MODIFY COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'bank_transfer' COMMENT '결제수단 [v46]'");
            }
        } catch (Throwable $e) {
            sg_error_log('[ensure_order_tracking_columns:payment_method] ' . $e->getMessage());
        }
        /* [v47] tt_orders.payment_status — payment_method와 같은 스키마 드리프트 계열로
           운영(마이그레이션) DB에는 아예 없는 경우가 있다. 이 컬럼이 없으면 세금계산서
           페이지의 "결제완료 주문" 조회가 1054(42S22)로 실패해 500(내부서버 오류)이 났다.
           신규 설치 SQL과 동일한 ENUM으로 자동 보강한다. */
        try {
            if (!$pdo->query("SHOW COLUMNS FROM tt_orders LIKE 'payment_status'")->fetch()) {
                $pdo->exec("ALTER TABLE tt_orders ADD COLUMN payment_status ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid' COMMENT '결제상태' AFTER payment_method");
                sg_error_log('[ensure_order_tracking_columns] tt_orders.payment_status 컬럼 자동 보강 완료');
            }
        } catch (Throwable $e) {
            sg_error_log('[ensure_order_tracking_columns:payment_status] ' . $e->getMessage());
        }
        /* [v48] tt_orders.status ENUM 드리프트 — 신규설치 SQL 계열은
           'installed/completed/canceled' 인데 어드민 주문관리는 'shipped/done/cancelled'
           라벨을 쓴다. 어느 계열 DB에도 앱이 쓰는 상태값이 전부 저장되도록 통합 ENUM으로
           전환한다(MODIFY ENUM은 기존 값을 문자열 그대로 보존하므로 데이터에 안전). */
        try {
            $stCol = $pdo->query("SHOW COLUMNS FROM tt_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
            if ($stCol && preg_match('/^enum\((.*)\)$/i', (string)$stCol['Type'], $m)) {
                $vals = [];
                foreach (explode(',', $m[1]) as $s) {
                    $v = trim($s, " '");
                    if ($v !== '') $vals[] = $v;
                }
                $need = ['pending', 'paid', 'preparing', 'shipped', 'done', 'installed', 'completed', 'cancelled', 'canceled', 'refunded'];
                if (array_diff($need, $vals)) {
                    $pdo->exec("ALTER TABLE tt_orders MODIFY COLUMN status ENUM('pending','paid','preparing','shipped','done','installed','completed','cancelled','canceled','refunded') NOT NULL DEFAULT 'pending' COMMENT '주문상태 [v48]'");
                    sg_error_log('[ensure_order_tracking_columns] tt_orders.status ENUM 통합 전환 완료(레거시·앱 라벨 공존)');
                }
            }
        } catch (Throwable $e) {
            sg_error_log('[ensure_order_tracking_columns:status] ' . $e->getMessage());
        }
        /* tt_order_items 도 스키마 파일 드리프트 보정 (운영 DB 기준 컬럼) */
        foreach ([
            ['product_name', "ALTER TABLE tt_order_items ADD COLUMN product_name VARCHAR(150) NULL COMMENT '상품명 스냅샷'"],
            ['price',        "ALTER TABLE tt_order_items ADD COLUMN price INT NOT NULL DEFAULT 0 COMMENT '단가 스냅샷'"],
        ] as [$col, $ddl]) {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM tt_order_items LIKE '{$col}'")->fetch()) {
                    $pdo->exec($ddl);
                }
            } catch (Throwable $e) {
                sg_error_log("[ensure_order_tracking_columns:items:{$col}] " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_order_tracking_columns] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_order_tracking_columns');
}

/* ---------- 차량 교체주기 관리 ---------- */

/** 차량 마스터 + 리마인더 이력 테이블 자동 생성 */
function ensure_vehicle_tables(): void
{
    static $done = false;
    if (sg_skip('ensure_vehicle_tables', $done)) return;
    $pdo = Database::connection();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_vehicles (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id            INT UNSIGNED NULL COMMENT '연결된 회원(NULL=비회원)',
            owner_name         VARCHAR(50) NULL COMMENT '고객명',
            owner_phone        VARCHAR(20) NULL COMMENT '연락처(리마인더 발송용)',
            plate_no           VARCHAR(20) NOT NULL COMMENT '차량번호(정규화: 12가3456)',
            car_model          VARCHAR(50) NULL COMMENT '차종(그랜저 IG 등)',
            last_tire_date     DATE NULL COMMENT '최근 타이어 교체일',
            last_tire_product  VARCHAR(150) NULL COMMENT '최근 장착 타이어',
            last_tire_mileage  INT UNSIGNED NULL COMMENT '교체 시 주행거리(km)',
            cycle_months       INT NOT NULL DEFAULT 24 COMMENT '권장 교체주기(개월)',
            reminder_sent_at   DATETIME NULL COMMENT '최근 리마인더 발송 시각',
            memo               TEXT NULL,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_plate (plate_no),
            INDEX idx_user (user_id),
            INDEX idx_last_tire (last_tire_date)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        sg_error_log('[ensure_vehicle_tables:tt_vehicles] ' . $e->getMessage());
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_vehicle_reminders (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_id  INT UNSIGNED NOT NULL,
            sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_by     VARCHAR(50) NULL,
            channel      ENUM('sms','manual') NOT NULL DEFAULT 'sms',
            message      TEXT NULL,
            result_ok    TINYINT(1) NOT NULL DEFAULT 0,
            result_msg  VARCHAR(255) NULL,
            INDEX idx_vehicle (vehicle_id),
            INDEX idx_sent (sent_at)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        sg_error_log('[ensure_vehicle_tables:tt_vehicle_reminders] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_vehicle_tables');
}

/** 차량번호 정규화 (공백/하이픈 제거, 대문자) — 12가3456 */
function normalize_plate_no(string $raw): string
{
    $s = strtoupper(preg_replace('/[\s\-·.]/u', '', $raw) ?? $raw);
    return mb_substr($s, 0, 20);
}

/** 정규화된 차량번호 형식 검증 (u 플래그 필수 — 한글 바이트 매칭 방지) */
function is_valid_plate_no(string $plate): bool
{
    return (bool)preg_match('/^\d{2,3}[가-힣]\d{4}$/u', $plate);
}

/** 자유 텍스트에서 차량번호 패턴 추출 — 없으면 빈 문자열 */
function extract_plate_no(string $text): string
{
    if (preg_match('/(?<!\d)\d{2,3}\s*[가-힣]\s*\d{4}(?!\d)/u', $text, $m)) {
        return normalize_plate_no($m[0]);
    }
    return '';
}

/** 차량정보 텍스트에서 차량번호를 제거해 차종만 남긴다 ("그랜저 IG / 12가3456" → "그랜저 IG") */
function car_model_from_info(string $carInfo): string
{
    $model = preg_replace('/(?<!\d)\d{2,3}\s*[가-힣]\s*\d{4}(?!\d)/u', '', $carInfo) ?? $carInfo;
    $model = trim(preg_replace('/[\s\/\|,·\-]+$/u', '', trim($model)) ?? $model);
    return mb_substr($model, 0, 50);
}

/**
 * 차량 마스터 upsert (작업일지/예약 저장 시 자동 캡처 + 관리자 수동 등록 공용)
 * $data: plate_no(필수), owner_name, owner_phone, user_id, car_model,
 *        tire_date(타이어 작업일 — 타이어 작업일 때만 갱신), tire_product
 * 기존 차량이면 최신 타이어 교체일/정보를 더 최신 날짜일 때만 갱신한다.
 */
function capture_vehicle(array $data): int
{
    ensure_vehicle_tables();
    $plate = normalize_plate_no((string)($data['plate_no'] ?? ''));
    if ($plate === '' || !is_valid_plate_no($plate)) return 0;

    $pdo = Database::connection();
    try {
        $stmt = $pdo->prepare('SELECT * FROM tt_vehicles WHERE plate_no = :p LIMIT 1');
        $stmt->execute(['p' => $plate]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);

        $tireDate  = !empty($data['tire_date']) ? date('Y-m-d', strtotime((string)$data['tire_date'])) : null;
        $tireProd  = trim((string)($data['tire_product'] ?? '')) ?: null;
        $ownerName = trim((string)($data['owner_name'] ?? '')) ?: null;
        $ownerPhone= trim((string)($data['owner_phone'] ?? '')) ?: null;
        $userId    = (int)($data['user_id'] ?? 0) ?: null;
        $carModel  = trim((string)($data['car_model'] ?? '')) ?: null;

        if ($v) {
            $keepDate = $v['last_tire_date'] ?: '0000-00-00';
            $newDateOk = $tireDate && $tireDate > $keepDate;
            $pdo->prepare('UPDATE tt_vehicles SET
                    owner_name = COALESCE(:oname, owner_name),
                    owner_phone= COALESCE(:ophone, owner_phone),
                    user_id    = COALESCE(:uid, user_id),
                    car_model  = COALESCE(:cmodel, car_model),
                    last_tire_date    = :tdate,
                    last_tire_product = :tprod,
                    cycle_months     = :cyc
                 WHERE id = :id')
                ->execute([
                    'oname'=>$ownerName, 'ophone'=>$ownerPhone, 'uid'=>$userId, 'cmodel'=>$carModel,
                    'tdate'=>$newDateOk ? $tireDate : $v['last_tire_date'],
                    'tprod'=>$newDateOk ? $tireProd : $v['last_tire_product'],
                    'cyc'=>(int)($v['cycle_months'] ?: vehicle_cycle_months()),
                    'id'=>(int)$v['id'],
                ]);
            return (int)$v['id'];
        }

        $pdo->prepare('INSERT INTO tt_vehicles
                (user_id, owner_name, owner_phone, plate_no, car_model, last_tire_date, last_tire_product, cycle_months)
            VALUES
                (:uid, :oname, :ophone, :p, :cmodel, :tdate, :tprod, :cyc)')
            ->execute([
                'uid'=>$userId, 'oname'=>$ownerName, 'ophone'=>$ownerPhone, 'p'=>$plate,
                'cmodel'=>$carModel, 'tdate'=>$tireDate, 'tprod'=>$tireProd, 'cyc'=>vehicle_cycle_months(),
            ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[capture_vehicle] ' . $e->getMessage());
        return 0;
    }
}

/** 기본 권장 교체주기(개월) — 사이트 설정 */
function vehicle_cycle_months(): int
{
    $v = (int)get_setting('vehicle_cycle_months', '24');
    return ($v >= 6 && $v <= 96) ? $v : 24;
}

/** 차량별 교체 시기 정보: due_date(권장일), days_left(남은 일수, 음수=경과), status(ok|due|over) */
function vehicle_due_info(array $v): array
{
    $cycle = max(1, (int)($v['cycle_months'] ?: vehicle_cycle_months()));
    $due = $v['last_tire_date'] ? date('Y-m-d', strtotime($v['last_tire_date'] . " +{$cycle} months")) : null;
    if ($due === null) {
        return ['due_date' => null, 'days_left' => null, 'status' => 'none', 'status_label' => '교체 이력 없음', 'cycle_months' => $cycle];
    }
    $days = (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
    if ($days > 30)      $st = ['ok',   '정상'];
    elseif ($days >= 0) $st = ['due',  '교체 시기 임박'];
    else                $st = ['over', '교체 시기 경과'];
    return ['due_date' => $due, 'days_left' => $days, 'status' => $st[0], 'status_label' => $st[1], 'cycle_months' => $cycle];
}

/** 차량 리마인더 SMS 발송 + 이력 기록 */
function send_vehicle_reminder(int $vehicleId, string $sentBy): array
{
    ensure_vehicle_tables();
    $pdo = Database::connection();
    try {
        $stmt = $pdo->prepare('SELECT * FROM tt_vehicles WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $vehicleId]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) return ['ok' => false, 'msg' => '차량을 찾을 수 없습니다.'];

        $info = vehicle_due_info($v);
        $phone = preg_replace('/[^0-9]/', '', (string)$v['owner_phone']);
        if ($phone === '') return ['ok' => false, 'msg' => '연락처가 없어 발송할 수 없습니다.'];

        $msg = '[' . (defined('SITE_NAME') ? SITE_NAME : '타이어탑') . '] ' . ($v['owner_name'] ?: '고객') . '님, ' .
               ($v['car_model'] ?: $v['plate_no']) . ' 차량 타이어 교체 시기가 ' .
               ($info['days_left'] !== null && $info['days_left'] < 0 ? '지나' : '다가오') . '습니다 (' . ($info['due_date'] ?: '-') . ' 권장). ' .
               '안전한 주행을 위해 방문 예약을 부탁드립니다.';
        $result = Sms::send($phone, $msg);

        $pdo->prepare('INSERT INTO tt_vehicle_reminders (vehicle_id, sent_by, channel, message, result_ok, result_msg)
                       VALUES (:vid, :by, "sms", :msg, :ok, :rmsg)')
            ->execute(['vid'=>$vehicleId, 'by'=>$sentBy, 'msg'=>$msg, 'ok'=>$result['ok'] ? 1 : 0, 'rmsg'=>mb_substr($result['msg'],0,255)]);
        if ($result['ok']) {
            $pdo->prepare('UPDATE tt_vehicles SET reminder_sent_at = NOW() WHERE id = :id')->execute(['id'=>$vehicleId]);
        }
        return $result;
    } catch (Throwable $e) {
        error_log('[send_vehicle_reminder] ' . $e->getMessage());
        return ['ok' => false, 'msg' => '발송 처리 중 오류가 발생했습니다.'];
    }
}

/* ---------- 작업일지 사진 첨부 ---------- */

/** 작업일지 사진 테이블 자동 생성 */
function ensure_work_log_photos_table(): void
{
    static $done = false;
    if (sg_skip('ensure_work_log_photos_table', $done)) return;
    try {
        Database::connection()->exec("CREATE TABLE IF NOT EXISTS tt_work_log_photos (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            work_log_id INT UNSIGNED NOT NULL,
            image_url   VARCHAR(255) NOT NULL,
            sort_order  INT NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_log (work_log_id)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        sg_error_log('[ensure_work_log_photos_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_work_log_photos_table');
}

/**
 * 작업일지 사진 업로드 저장 (log_add / log_edit 공용)
 * $files: $_FILES['photos'] 형태의 다중 파일 배열
 * 반환: ['ok'=>건수, 'error'=>[오류메시지...]]
 */
function save_work_log_photos(int $logId, array $files, int $maxCount = 5): array
{
    ensure_work_log_photos_table();
    $result = ['ok' => 0, 'error' => []];
    if ($logId <= 0 || empty($files['name']) || !is_array($files['name'])) return $result;

    $existing = 0;
    try {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM tt_work_log_photos WHERE work_log_id = :id');
        $stmt->execute(['id' => $logId]);
        $existing = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    $count = min(count($files['name']), $maxCount - $existing);
    if ($count <= 0) {
        $result['error'][] = "사진은 최대 {$maxCount}장까지 첨부할 수 있습니다.";
        return $result;
    }

    $subDir = 'uploads/worklogs/' . date('Ym');
    $destDir = dirname(__DIR__) . '/' . $subDir;
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
        $result['error'][] = '사진 저장 폴더를 생성할 수 없습니다.';
        return $result;
    }

    for ($i = 0; $i < $count; $i++) {
        $name = (string)$files['name'][$i];
        $tmp  = (string)$files['tmp_name'][$i];
        $err  = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE || $name === '') continue;
        if ($err !== UPLOAD_ERR_OK) { $result['error'][] = "'{$name}' 업로드 실패 (code {$err})"; continue; }
        $size = (int)$files['size'][$i];
        if ($size > 5 * 1024 * 1024) { $result['error'][] = "'{$name}' 은(는) 5MB를 초과합니다."; continue; }
        $info = @getimagesize($tmp);
        if ($info === false || empty($info[0])) { $result['error'][] = "'{$name}' 은(는) 이미지 파일이 아닙니다."; continue; }
        $ext = image_type_to_extension($info[2], false);
        if (!in_array($ext, ['jpeg', 'jpg', 'png', 'gif', 'webp'], true)) { $result['error'][] = "'{$name}' 은(는) 지원하지 않는 형식입니다 (JPG/PNG/GIF/WEBP)."; continue; }

        $fileName = 'work_' . $logId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $destDir . '/' . $fileName)) {
            $result['error'][] = "'{$name}' 저장에 실패했습니다.";
            continue;
        }
        try {
            Database::connection()->prepare('INSERT INTO tt_work_log_photos (work_log_id, image_url, sort_order) VALUES (:id, :url, :o)')
                ->execute(['id' => $logId, 'url' => BASE_URL . '/' . $subDir . '/' . $fileName, 'o' => $existing + $result['ok']]);
            $result['ok']++;
        } catch (Throwable $e) {
            error_log('[save_work_log_photos] ' . $e->getMessage());
            $result['error'][] = "'{$name}' DB 저장에 실패했습니다.";
        }
    }
    return $result;
}

/** 사진 ID 목록 삭제 (행 + 파일) */
function delete_work_log_photos(array $photoIds): int
{
    ensure_work_log_photos_table();
    $ids = array_values(array_filter(array_map('intval', $photoIds), fn($v) => $v > 0));
    if (empty($ids)) return 0;
    $pdo = Database::connection();
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT id, image_url FROM tt_work_log_photos WHERE id IN ({$ph})");
        $stmt->execute($ids);
        $deleted = 0;
        foreach ($stmt->fetchAll() as $row) {
            $rel = ltrim(str_replace(BASE_URL, '', (string)$row['image_url']), '/');
            @unlink(dirname(__DIR__) . '/' . $rel);
            $pdo->prepare('DELETE FROM tt_work_log_photos WHERE id = :id')->execute(['id' => (int)$row['id']]);
            $deleted++;
        }
        return $deleted;
    } catch (Throwable $e) {
        error_log('[delete_work_log_photos] ' . $e->getMessage());
        return 0;
    }
}

/** 로그 ID 목록 → 사진 행 맵 [log_id => [ [id, image_url, sort_order], ... ]] (일괄 표시용) */
function get_work_log_photos_by_logs(array $logIds): array
{
    ensure_work_log_photos_table();
    $ids = array_values(array_filter(array_map('intval', $logIds), fn($v) => $v > 0));
    if (empty($ids)) return [];
    $map = [];
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare("SELECT id, work_log_id, image_url, sort_order FROM tt_work_log_photos WHERE work_log_id IN ({$ph}) ORDER BY sort_order ASC, id ASC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $r) $map[(int)$r['work_log_id']][] = $r;
    } catch (Throwable $e) {}
    return $map;
}

/* ---------- 재고 입출고 이력 + 부족 알림 ---------- */

/** 재고 이력 테이블 + 상품 최소재고(min_stock) 컬럼 자동 생성 */
function ensure_stock_movement_tables(): void
{
    static $done = false;
    if (sg_skip('ensure_stock_movement_tables', $done)) return;
    $pdo = Database::connection();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_stock_movements (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id  INT UNSIGNED NOT NULL,
            option_id   INT UNSIGNED NULL,
            change_type ENUM('in','out','adjust') NOT NULL COMMENT '입고/출고/조정',
            qty         INT NOT NULL COMMENT '변경 수량(출고는 음수로 기록)',
            stock_after INT NOT NULL COMMENT '변경 후 재고',
            note        VARCHAR(255) NULL,
            created_by  VARCHAR(50) NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product (product_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        sg_error_log('[ensure_stock_movement_tables:movements] ' . $e->getMessage());
    }
    try {
        if (!$pdo->query("SHOW COLUMNS FROM tt_products LIKE 'min_stock'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_products ADD COLUMN min_stock INT NOT NULL DEFAULT 5 COMMENT '부족 알림 기준 재고' AFTER stock");
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_stock_movement_tables:min_stock] ' . $e->getMessage());
    }
    /* [v39] sku(품번) 컬럼 — 신규 설치 sql에는 있지만 구버전에서 올라온 운영 DB에는 없어
       재고 입출고 이력 SELECT(p.sku)가 500(내부서버 오류)로 죽었던 실제 원인. 자동 보강. */
    try {
        if (!$pdo->query("SHOW COLUMNS FROM tt_products LIKE 'sku'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_products ADD COLUMN sku VARCHAR(50) NULL COMMENT '품번/SKU' AFTER dot_code");
            $pdo->exec("ALTER TABLE tt_products ADD INDEX idx_products_sku (sku)");
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_stock_movement_tables:sku] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_stock_movement_tables');
}

/**
 * 재고 이력 기록 (INSERT 전용 — DDL/ensure 없음: 트랜잭션 안에서 호출해도
 * 암시적 커밋이 일어나지 않는다. 호출부는 미리 ensure_stock_movement_tables() 실행)
 */
function record_stock_movement(int $productId, ?int $optionId, string $type, int $qty, int $stockAfter, string $note, string $by = ''): void
{
    try {
        if (!in_array($type, ['in', 'out', 'adjust'], true)) $type = 'adjust';
        Database::connection()->prepare('INSERT INTO tt_stock_movements (product_id, option_id, change_type, qty, stock_after, note, created_by)
                                         VALUES (:pid, :oid, :t, :q, :sa, :n, :by)')
            ->execute(['pid'=>$productId, 'oid'=>$optionId, 't'=>$type, 'q'=>$qty, 'sa'=>$stockAfter, 'n'=>mb_substr($note,0,255) ?: null, 'by'=>$by ?: null]);
    } catch (Throwable $e) {
        error_log('[record_stock_movement] ' . $e->getMessage());
    }
}

/* ---------- 예약 리마인더 ---------- */

/** 예약 리마인더 이력 테이블 자동 생성 */
function ensure_reservation_reminder_table(): void
{
    static $done = false;
    if (sg_skip('ensure_reservation_reminder_table', $done)) return;
    try {
        Database::connection()->exec("CREATE TABLE IF NOT EXISTS tt_reservation_reminders (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT UNSIGNED NOT NULL,
            send_date      DATE NOT NULL COMMENT '발송 기준일(예약 visit_date 하루 전 발송이 원칙)',
            sent_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_by        VARCHAR(50) NULL,
            result_ok      TINYINT(1) NOT NULL DEFAULT 0,
            result_msg     VARCHAR(255) NULL,
            INDEX idx_resv (reservation_id),
            INDEX idx_date (send_date),
            UNIQUE KEY uq_resv_date (reservation_id, send_date)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        sg_error_log('[ensure_reservation_reminder_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_reservation_reminder_table');
}

/**
 * 특정 방문일 예약 전체에 리마인더 발송 (중복 발송 자동 방지)
 * $date: 방문 예정일(기본 내일). 반환: [전체, 성공, 실패, 상세[]]
 */
function send_reservation_reminders(string $date = '', string $sentBy = '시스템'): array
{
    ensure_reservation_reminder_table();
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d', strtotime('+1 day'));
    $pdo = Database::connection();

    $out = ['date' => $date, 'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'detail' => []];
    try {
        $stmt = $pdo->prepare("SELECT id, res_no, visitor_name, visitor_phone, visit_date, visit_time, product_name_snap
                              FROM tt_reservations
                              WHERE visit_date = :d AND status IN ('pending','confirmed')");
        $stmt->execute(['d' => $date]);
        $resvs = $stmt->fetchAll();
    } catch (Throwable $e) {
        $out['detail'][] = '예약 조회 실패: ' . $e->getMessage();
        return $out;
    }

    foreach ($resvs as $r) {
        $out['total']++;
        $phone = preg_replace('/[^0-9]/', '', (string)$r['visitor_phone']);
        $msg = '[' . (defined('SITE_NAME') ? SITE_NAME : '타이어탑') . '] 내일 ' . date('n월 j일', strtotime($r['visit_date'])) . ' ' .
               $r['visit_time'] . ' 방문 예약이 확정되어 있습니다 (' . ($r['product_name_snap'] ?: '타이어 교체') . '). ' .
               '예약번호 ' . $r['res_no'] . '. 변경이 필요하면 연락 주세요.';
        $sendResult = $phone === '' ? ['ok'=>false, 'msg'=>'연락처 없음'] : Sms::send($phone, $msg);

        try {
            $ins = $pdo->prepare('INSERT IGNORE INTO tt_reservation_reminders (reservation_id, send_date, sent_by, result_ok, result_msg)
                                  VALUES (:rid, :d, :by, :ok, :m)');
            $ins->execute(['rid'=>(int)$r['id'], 'd'=>date('Y-m-d'), 'by'=>$sentBy, 'ok'=>$sendResult['ok']?1:0, 'm'=>mb_substr($sendResult['msg'],0,255)]);
            if ($ins->rowCount() === 0) { $out['skipped']++; $out['detail'][] = "{$r['res_no']}: 이미 발송됨(생략)"; continue; }
        } catch (Throwable $e) {
            $out['detail'][] = "{$r['res_no']}: 이력 기록 실패";
        }

        if ($sendResult['ok']) { $out['sent']++; $out['detail'][] = "{$r['res_no']} → {$r['visitor_name']}님 발송 OK"; }
        else { $out['failed']++; $out['detail'][] = "{$r['res_no']} → 발송 실패: {$sendResult['msg']}"; }
    }
    return $out;
}

/* ---------- 월간 정산 · 인센티브 지급대장 ---------- */

/** 월간 정산 스냅샷 테이블 자동 생성 */
function ensure_monthly_settlement_table(): void
{
    static $done = false;
    if (sg_skip('ensure_monthly_settlement_table', $done)) return;
    try {
        Database::connection()->exec("CREATE TABLE IF NOT EXISTS tt_monthly_settlements (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            settle_month       CHAR(7) NOT NULL UNIQUE COMMENT '정산 월 (YYYY-MM)',
            tire_sales         INT UNSIGNED NOT NULL DEFAULT 0,
            repair_sales       INT UNSIGNED NOT NULL DEFAULT 0,
            etc_sales          INT UNSIGNED NOT NULL DEFAULT 0,
            site_sales         INT UNSIGNED NOT NULL DEFAULT 0,
            online_sales       INT UNSIGNED NOT NULL DEFAULT 0,
            total_sales        INT UNSIGNED NOT NULL DEFAULT 0,
            cost_factory_total INT UNSIGNED NOT NULL DEFAULT 0,
            cost_supply_total  INT UNSIGNED NOT NULL DEFAULT 0,
            margin_total       INT NOT NULL DEFAULT 0,
            card_sales         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 카드결제 합계 [v40]',
            cash_sales         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 현금결제 합계 [v40]',
            transfer_sales     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 계좌이체 합계 [v40]',
            unpaid_sales       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 미결제 합계 [v40]',
            online_retail_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 소매(일반회원) 주문 매출 [v40]',
            online_biz_sales   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 도매(사업자) 주문 매출 [v40]',
            reservation_count   INT UNSIGNED NOT NULL DEFAULT 0,
            walkin_count        INT UNSIGNED NOT NULL DEFAULT 0,
            work_count          INT UNSIGNED NOT NULL DEFAULT 0,
            worker_stats        TEXT NULL COMMENT '작업자별 집계+인센티브(JSON)',
            incentive_rate      DECIMAL(5,2) NOT NULL DEFAULT 5.00 COMMENT '인센티브율(%)',
            incentive_total     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '인센티브 총액(마진 x 율%)',
            paid_status         ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid' COMMENT '지급 확정 여부',
            paid_at             DATETIME NULL,
            paid_by             VARCHAR(50) NULL COMMENT '지급 확정자',
            closed_by           VARCHAR(50) NULL,
            closed_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reopened_at         DATETIME NULL,
            INDEX idx_month (settle_month)
        ) ENGINE=InnoDB");
        /* [v40] 구버전 운영 DB 보강 — 결제수단 스냅샷 컬럼 */
        $pdo = Database::connection();
        foreach ([
            ['card_sales',           "ALTER TABLE tt_monthly_settlements ADD COLUMN card_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 카드결제 합계 [v40]' AFTER margin_total"],
            ['cash_sales',           "ALTER TABLE tt_monthly_settlements ADD COLUMN cash_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 현금결제 합계 [v40]' AFTER card_sales"],
            ['transfer_sales',       "ALTER TABLE tt_monthly_settlements ADD COLUMN transfer_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 계좌이체 합계 [v40]' AFTER cash_sales"],
            ['unpaid_sales',         "ALTER TABLE tt_monthly_settlements ADD COLUMN unpaid_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '현장 미결제 합계 [v40]' AFTER transfer_sales"],
            ['online_retail_sales',  "ALTER TABLE tt_monthly_settlements ADD COLUMN online_retail_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 소매(일반회원) 주문 매출 [v40]' AFTER unpaid_sales"],
            ['online_biz_sales',    "ALTER TABLE tt_monthly_settlements ADD COLUMN online_biz_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 도매(사업자) 주문 매출 [v40]' AFTER online_retail_sales"],
            /* [v41] 매출 대분류(소매/도매/온라인) + 마진 분류 + 카드 수수료 스냅샷 */
            ['retail_sales',        "ALTER TABLE tt_monthly_settlements ADD COLUMN retail_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '소매매출(현장 로드손님) [v41]' AFTER online_biz_sales"],
            ['resv_sales',          "ALTER TABLE tt_monthly_settlements ADD COLUMN resv_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 매출 — 예약방문 작업 [v41]' AFTER retail_sales"],
            ['wholesale_sales',     "ALTER TABLE tt_monthly_settlements ADD COLUMN wholesale_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '도매매출(온라인 도매주문) [v41]' AFTER resv_sales"],
            ['online_channel_sales', "ALTER TABLE tt_monthly_settlements ADD COLUMN online_channel_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인매출(예약+온라인 소매주문) [v41]' AFTER wholesale_sales"],
            ['retail_margin',       "ALTER TABLE tt_monthly_settlements ADD COLUMN retail_margin INT NOT NULL DEFAULT 0 COMMENT '소매마진 [v41]' AFTER online_channel_sales"],
            ['online_margin',       "ALTER TABLE tt_monthly_settlements ADD COLUMN online_margin INT NOT NULL DEFAULT 0 COMMENT '온라인마진 [v41]' AFTER retail_margin"],
            ['wholesale_margin',    "ALTER TABLE tt_monthly_settlements ADD COLUMN wholesale_margin INT NOT NULL DEFAULT 0 COMMENT '도매마진 [v41]' AFTER online_margin"],
            ['online_cost_total',   "ALTER TABLE tt_monthly_settlements ADD COLUMN online_cost_total INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '온라인 주문 원가(매입단가) [v41]' AFTER wholesale_margin"],
            ['card_total_sales',    "ALTER TABLE tt_monthly_settlements ADD COLUMN card_total_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '전체 카드결제 매출(현장+온라인) [v41]' AFTER online_cost_total"],
            ['card_fee_rate',       "ALTER TABLE tt_monthly_settlements ADD COLUMN card_fee_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '카드 수수료율(%) 스냅샷 [v41]' AFTER card_total_sales"],
            ['card_fee_rate_site',   "ALTER TABLE tt_monthly_settlements ADD COLUMN card_fee_rate_site DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '현장 카드 수수료율(%) [v42]' AFTER card_fee_rate"],
            ['card_fee_rate_online', "ALTER TABLE tt_monthly_settlements ADD COLUMN card_fee_rate_online DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '온라인 카드 수수료율(%) [v42]' AFTER card_fee_rate_site"],
            ['card_fee_amount',     "ALTER TABLE tt_monthly_settlements ADD COLUMN card_fee_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '카드 수수료 금액 [v41]' AFTER card_fee_rate"],
            ['card_net_sales',      "ALTER TABLE tt_monthly_settlements ADD COLUMN card_net_sales INT NOT NULL DEFAULT 0 COMMENT '카드 순매출(수수료 차감) [v41]' AFTER card_fee_amount"],
            /* [v43] 일반 도매매출(로컬 거래처) 스냅샷 */
            ['local_wholesale_sales',  "ALTER TABLE tt_monthly_settlements ADD COLUMN local_wholesale_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '일반 도매매출(로컬 거래처) [v43]' AFTER card_net_sales"],
            ['wholesale_group_sales',   "ALTER TABLE tt_monthly_settlements ADD COLUMN wholesale_group_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '도매매출 합계(온라인 도매+일반 도매) [v43]' AFTER local_wholesale_sales"],
            ['wholesale_card_sales',    "ALTER TABLE tt_monthly_settlements ADD COLUMN wholesale_card_sales INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '일반도매 카드결제 합계 [v43]' AFTER wholesale_group_sales"],
            ['local_wholesale_margin',  "ALTER TABLE tt_monthly_settlements ADD COLUMN local_wholesale_margin INT NOT NULL DEFAULT 0 COMMENT '일반도매 마진 [v43]' AFTER wholesale_card_sales"],
            ['wholesale_margin_total',  "ALTER TABLE tt_monthly_settlements ADD COLUMN wholesale_margin_total INT NOT NULL DEFAULT 0 COMMENT '도매마진 합계(온라인 도매+일반 도매) [v43]' AFTER local_wholesale_margin"],
            ['local_wholesale_count',   "ALTER TABLE tt_monthly_settlements ADD COLUMN local_wholesale_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '일반도매 거래 건수 [v43]' AFTER wholesale_margin_total"],
        ] as [$col, $ddl]) {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM tt_monthly_settlements LIKE '{$col}'")->fetch()) {
                    $pdo->exec($ddl);
                }
            } catch (Throwable $e) {
                sg_error_log("[ensure_monthly_settlement_table:{$col}] " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_monthly_settlement_table] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_monthly_settlement_table');
}

/** 인센티브율(%) — 사이트 설정 (기본 5) */
function incentive_rate(): float
{
    $v = (float)get_setting('incentive_rate', '5');
    return ($v >= 0 && $v <= 50) ? $v : 5.0;
}

/**
 * 특정 월 집계 (마감 전 라이브/마감 스냅샷 공용)
 * 작업일지는 일정산 마감으로 잠기므로 tt_work_logs 기준이 항상 확정값.
 * [v41] 일정산과 동일한 매출 분류(소매/도매/온라인 + 종합)·마진(소매/온라인/도매/토탈)·카드수수료 적용.
 */
function compute_month_settlement(string $month): array
{
    ensure_monthly_settlement_table();
    $pdo = Database::connection();
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');

    $result = settle_empty_aggregate();
    $result['month'] = $month;
    $result['incentive_rate'] = incentive_rate();
    $result['incentive_total'] = 0;
    $result['days'] = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT w.*, r.res_no AS live_res_no,
                    (SELECT ti.id FROM tt_tax_invoices ti
                      WHERE ti.target_type = 'reservation' AND ti.target_id = w.reservation_id AND ti.status = 'issued' LIMIT 1) AS tax_invoice_issued
             FROM tt_work_logs w
             LEFT JOIN tt_reservations r ON r.id = w.reservation_id
             WHERE w.work_date LIKE :ym
             ORDER BY w.work_date ASC, w.work_time ASC");
        $stmt->execute(['ym' => $month . '-%']);
        $logs = $stmt->fetchAll();
    } catch (Throwable $e) {
        return $result;
    }

    $daySet = [];
    foreach ($logs as $log) $daySet[$log['work_date']] = 1;

    /* 현장 로그 → 소매(walk-in)/온라인(예약) 분리 집계 (일정산과 동일 로직) */
    settle_aggregate_logs($logs, $result);
    /* 월 작업자 통계에 기여도/인센티브 필드 추가 */
    foreach ($result['workers'] as &$mw) {
        $mw['share'] = 0.0;
        $mw['incentive'] = 0;
    }
    unset($mw);

    /* 온라인 주문 — 소매(일반 택배)/도매(사업자 택배) + 주문 원가 */
    settle_aggregate_orders(settle_fetch_orders("DATE_FORMAT(o.created_at, '%Y-%m')", $month), $result);
    /* [v43] 일반 도매매출(로컬 거래처) — 도매 그룹에 합산 */
    settle_aggregate_wholesale(settle_fetch_wholesale("DATE_FORMAT(sale_date, '%Y-%m')", $month), $result);
    settle_apply_card_fee($result);
    $result['days'] = count($daySet);

    /* 인센티브 = 토탈 마진 × 인센티브율, 작업자별 작업 매출 기여도로 배분 */
    $result['incentive_total'] = max(0, (int)floor($result['margin_total'] * $result['incentive_rate'] / 100));
    if ($result['site_sales'] > 0) {
        foreach ($result['workers'] as &$w) {
            $w['share'] = round($w['amount'] / $result['site_sales'] * 100, 1);
            $w['incentive'] = (int)floor($result['incentive_total'] * $w['amount'] / $result['site_sales']);
        }
        unset($w);
    }
    return $result;
}

/** 특정 월 마감 기록 조회 */
function get_month_settlement(string $month): ?array
{
    try {
        $stmt = Database::connection()->prepare('SELECT * FROM tt_monthly_settlements WHERE settle_month = :m LIMIT 1');
        $stmt->execute(['m' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** 월간 정산 마감: 집계 스냅샷 저장 (작업일지는 일정산에서 이미 잠김) */
function close_month_settlement(string $month, int $adminId, string $adminName): array
{
    ensure_monthly_settlement_table();
    $pdo = Database::connection();

    /* 집계/ensure 는 트랜잭션 밖에서 먼저 계산한다
       (ensure의 CREATE TABLE DDL이 트랜잭션을 암시적 커밋시키기 때문 — orders.php [FIX]와 동일 원인) */
    $calc = compute_month_settlement($month);
    $workersJson = json_encode(array_values($calc['workers']), JSON_UNESCAPED_UNICODE);
    $rate = number_format($calc['incentive_rate'], 2, '.', '');

    $pdo->beginTransaction();
    try {
        $settlement = get_month_settlement($month);
        if ($settlement) {
            if ($settlement['paid_status'] === 'paid') {
                $pdo->rollBack();
                return ['ok'=>false, 'msg'=>'지급 확정된 월은 다시 마감할 수 없습니다.'];
            }
            $pdo->prepare('UPDATE tt_monthly_settlements SET
                    tire_sales=:tire, repair_sales=:repair, etc_sales=:etc, site_sales=:site,
                    online_sales=:online, total_sales=:total,
                    cost_factory_total=:cft, cost_supply_total=:cst, margin_total=:margin,
                    card_sales=:card, cash_sales=:cash, transfer_sales=:tf, unpaid_sales=:unpaid,
                    online_retail_sales=:ort, online_biz_sales=:obz,
                    retail_sales=:rtl, resv_sales=:rsv, wholesale_sales=:whl, online_channel_sales=:onch,
                    retail_margin=:rtlmg, online_margin=:onmg, wholesale_margin=:whlmg,
                    online_cost_total=:oncost,
                    card_total_sales=:cardtot, card_fee_rate=:cfr, card_fee_rate_site=:cfrs, card_fee_rate_online=:cfro, card_fee_amount=:cfa, card_net_sales=:cnet,
                    local_wholesale_sales=:lws, wholesale_group_sales=:wgs, wholesale_card_sales=:wcs,
                    local_wholesale_margin=:lwm, wholesale_margin_total=:wmt, local_wholesale_count=:lwc,
                    reservation_count=:rcnt, walkin_count=:wcnt, work_count=:cnt,
                    worker_stats=:ws, incentive_rate=:rate, incentive_total=:inc,
                    closed_by=:by, closed_at=NOW(), reopened_at=NULL
                 WHERE id=:id')
                ->execute([
                    'tire'=>$calc['tire_sales'], 'repair'=>$calc['repair_sales'], 'etc'=>$calc['etc_sales'],
                    'site'=>$calc['site_sales'], 'online'=>$calc['online_sales'], 'total'=>$calc['total_sales'],
                    'cft'=>$calc['cost_factory_total'], 'cst'=>$calc['cost_supply_total'], 'margin'=>$calc['margin_total'],
                    'card'=>$calc['card_sales'], 'cash'=>$calc['cash_sales'], 'tf'=>$calc['transfer_sales'],
                    'unpaid'=>$calc['unpaid_sales'], 'ort'=>$calc['online_retail_sales'], 'obz'=>$calc['online_biz_sales'],
                    'rtl'=>$calc['retail_sales'], 'rsv'=>$calc['resv_sales'], 'whl'=>$calc['wholesale_sales'], 'onch'=>$calc['online_channel_sales'],
                    'rtlmg'=>$calc['retail_margin'], 'onmg'=>$calc['online_margin'], 'whlmg'=>$calc['wholesale_margin'],
                    'oncost'=>$calc['online_cost_total'],
                    'cardtot'=>$calc['card_total_sales'], 'cfr'=>number_format($calc['card_fee_rate'], 2, '.', ''),
                    'cfrs'=>number_format($calc['card_fee_rate_site'], 2, '.', ''),
                    'cfro'=>number_format($calc['card_fee_rate_online'], 2, '.', ''),
                    'cfa'=>$calc['card_fee_amount'], 'cnet'=>$calc['card_net_sales'],
                    'lws'=>$calc['local_wholesale_sales'], 'wgs'=>$calc['wholesale_group_sales'], 'wcs'=>$calc['wholesale_card_sales'],
                    'lwm'=>$calc['local_wholesale_margin'], 'wmt'=>$calc['wholesale_margin_total'], 'lwc'=>$calc['local_wholesale_count'],
                    'rcnt'=>$calc['reservation_count'], 'wcnt'=>$calc['walkin_count'], 'cnt'=>$calc['work_count'],
                    'ws'=>$workersJson, 'rate'=>$rate, 'inc'=>$calc['incentive_total'],
                    'by'=>$adminName, 'id'=>(int)$settlement['id'],
                ]);
        } else {
            $pdo->prepare('INSERT INTO tt_monthly_settlements
                    (settle_month, tire_sales, repair_sales, etc_sales, site_sales, online_sales, total_sales,
                     cost_factory_total, cost_supply_total, margin_total,
                     card_sales, cash_sales, transfer_sales, unpaid_sales, online_retail_sales, online_biz_sales,
                     retail_sales, resv_sales, wholesale_sales, online_channel_sales,
                     retail_margin, online_margin, wholesale_margin, online_cost_total,
                      card_total_sales, card_fee_rate, card_fee_rate_site, card_fee_rate_online, card_fee_amount, card_net_sales,
                      local_wholesale_sales, wholesale_group_sales, wholesale_card_sales,
                      local_wholesale_margin, wholesale_margin_total, local_wholesale_count,
                      reservation_count, walkin_count,
                      work_count, worker_stats, incentive_rate, incentive_total, closed_by)
                  VALUES
                     (:m, :tire, :repair, :etc, :site, :online, :total,
                      :cft, :cst, :margin,
                      :card, :cash, :tf, :unpaid, :ort, :obz,
                      :rtl, :rsv, :whl, :onch,
                      :rtlmg, :onmg, :whlmg, :oncost,
                      :cardtot, :cfr, :cfrs, :cfro, :cfa, :cnet,
                      :lws, :wgs, :wcs, :lwm, :wmt, :lwc,
                      :rcnt, :wcnt,
                      :cnt, :ws, :rate, :inc, :by)')
                ->execute([
                    'm'=>$month,
                    'tire'=>$calc['tire_sales'], 'repair'=>$calc['repair_sales'], 'etc'=>$calc['etc_sales'],
                    'site'=>$calc['site_sales'], 'online'=>$calc['online_sales'], 'total'=>$calc['total_sales'],
                    'cft'=>$calc['cost_factory_total'], 'cst'=>$calc['cost_supply_total'], 'margin'=>$calc['margin_total'],
                    'card'=>$calc['card_sales'], 'cash'=>$calc['cash_sales'], 'tf'=>$calc['transfer_sales'],
                    'unpaid'=>$calc['unpaid_sales'], 'ort'=>$calc['online_retail_sales'], 'obz'=>$calc['online_biz_sales'],
                    'rtl'=>$calc['retail_sales'], 'rsv'=>$calc['resv_sales'], 'whl'=>$calc['wholesale_sales'], 'onch'=>$calc['online_channel_sales'],
                    'rtlmg'=>$calc['retail_margin'], 'onmg'=>$calc['online_margin'], 'whlmg'=>$calc['wholesale_margin'],
                    'oncost'=>$calc['online_cost_total'],
                    'cardtot'=>$calc['card_total_sales'], 'cfr'=>number_format($calc['card_fee_rate'], 2, '.', ''),
                    'cfrs'=>number_format($calc['card_fee_rate_site'], 2, '.', ''),
                    'cfro'=>number_format($calc['card_fee_rate_online'], 2, '.', ''),
                    'cfa'=>$calc['card_fee_amount'], 'cnet'=>$calc['card_net_sales'],
                    'lws'=>$calc['local_wholesale_sales'], 'wgs'=>$calc['wholesale_group_sales'], 'wcs'=>$calc['wholesale_card_sales'],
                    'lwm'=>$calc['local_wholesale_margin'], 'wmt'=>$calc['wholesale_margin_total'], 'lwc'=>$calc['local_wholesale_count'],
                    'rcnt'=>$calc['reservation_count'], 'wcnt'=>$calc['walkin_count'], 'cnt'=>$calc['work_count'],
                    'ws'=>$workersJson, 'rate'=>$rate, 'inc'=>$calc['incentive_total'], 'by'=>$adminName,
                ]);
        }
        $pdo->commit();
        AdminAuth::log($adminId, 'monthly_settlement_close', "월간 정산 마감 {$month} — 현장매출 " . number_format($calc['site_sales']) . '원 / 인센티브 ' . number_format($calc['incentive_total']) . '원');
        return ['ok'=>true, 'calc'=>$calc];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[close_month_settlement] ' . $e->getMessage());
        return ['ok'=>false, 'msg'=>'마감 처리 중 오류가 발생했습니다.'];
    }
}

/** 월간 정산 지급 확정/해제 */
function set_month_settlement_paid(string $month, bool $paid, int $adminId, string $adminName): array
{
    $pdo = Database::connection();
    try {
        $settlement = get_month_settlement($month);
        if (!$settlement) return ['ok'=>false, 'msg'=>'마감되지 않은 월입니다. 먼저 마감을 실행해 주세요.'];
        $pdo->prepare('UPDATE tt_monthly_settlements SET paid_status = :ps, paid_at = :at, paid_by = :by WHERE id = :id')
            ->execute([
                'ps'=>$paid ? 'paid' : 'unpaid',
                'at'=>$paid ? date('Y-m-d H:i:s') : null,
                'by'=>$paid ? $adminName : null,
                'id'=>(int)$settlement['id'],
            ]);
        AdminAuth::log($adminId, $paid ? 'monthly_incentive_paid' : 'monthly_incentive_unpaid',
            ($paid ? '월간 인센티브 지급 확정 ' : '월간 인센티브 지급 해제 ') . $month);
        return ['ok'=>true];
    } catch (Throwable $e) {
        error_log('[set_month_settlement_paid] ' . $e->getMessage());
        return ['ok'=>false, 'msg'=>'처리 중 오류가 발생했습니다.'];
    }
}

/* ---------- 태블릿 PIN 연속 실패 잠금 ---------- */

/** tt_workers 에 PIN 잠금 컬럼 자동 추가 */
function ensure_worker_pin_lock_columns(): void
{
    static $done = false;
    if (sg_skip('ensure_worker_pin_lock_columns', $done)) return;
    try {
        $pdo = Database::connection();
        if (!$pdo->query("SHOW COLUMNS FROM tt_workers LIKE 'pin_failed_attempts'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_workers ADD COLUMN pin_failed_attempts INT NOT NULL DEFAULT 0 COMMENT 'PIN 연속 실패 횟수' AFTER pin_hash");
        }
        if (!$pdo->query("SHOW COLUMNS FROM tt_workers LIKE 'pin_locked_until'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_workers ADD COLUMN pin_locked_until DATETIME NULL COMMENT 'PIN 잠금 해제 시각' AFTER pin_failed_attempts");
        }
    } catch (Throwable $e) {
        sg_error_log('[ensure_worker_pin_lock_columns] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_worker_pin_lock_columns');
}

/** PIN 잠금 상태 — ['locked'=>bool, 'minutes'=>남은 분, 'seconds'=>남은 초] */
function worker_pin_lock_state(array $worker): array
{
    $until = $worker['pin_locked_until'] ?? null;
    if (!$until) return ['locked' => false, 'minutes' => 0, 'seconds' => 0];
    $left = strtotime((string)$until) - time();
    if ($left <= 0) return ['locked' => false, 'minutes' => 0, 'seconds' => 0];
    return ['locked' => true, 'minutes' => (int)ceil($left / 60), 'seconds' => $left];
}

/* ---------- [v51] 이카운트 ERP 연동 ---------- */

/** 이카운트 연동 테이블 자동 생성 (운영 DB에도 ALTER 로 자동 보강된다) */
function ensure_ecount_tables(): void
{
    static $done = false;
    if (sg_skip('ensure_ecount_tables', $done)) return;
    $pdo = Database::connection();

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_items (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prod_cd    VARCHAR(20)  NOT NULL COMMENT 'ERP 품목코드',
            prod_des   VARCHAR(100) NULL     COMMENT '품목명',
            size_des   VARCHAR(100) NULL     COMMENT '규격',
            unit       VARCHAR(10)  NULL,
            in_price   VARCHAR(30)  NULL     COMMENT '입고단가',
            out_price  VARCHAR(30)  NULL     COMMENT '출고단가',
            class_cd   VARCHAR(10)  NULL     COMMENT '품목그룹',
            bal_flag   VARCHAR(2)   NULL,
            use_yn     VARCHAR(2)   NULL,
            local_product_id INT UNSIGNED NULL COMMENT '매핑된 로컬 상품(tt_products.id)',
            synced_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ecount_item (prod_cd)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:items] ' . $e->getMessage()); }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_partners (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cust_cd     VARCHAR(30)  NOT NULL COMMENT '거래처코드(BUSINESS_NO)',
            cust_name   VARCHAR(100) NOT NULL COMMENT '거래처명',
            boss_name   VARCHAR(50)  NULL,
            tel         VARCHAR(50)  NULL,
            addr        VARCHAR(500) NULL,
            uptae       VARCHAR(50)  NULL COMMENT '업태',
            jongmok     VARCHAR(50)  NULL COMMENT '종목',
            email       VARCHAR(100) NULL,
            remarks     VARCHAR(500) NULL,
            source      VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual=직접등록 / orders=발주서 수집 / excel=구매현황 엑셀 업로드',
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ecount_partner (cust_cd)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:partners] ' . $e->getMessage()); }

    /* [v62] 기존 설치의 거래처 source 컬럼 확장(10→20) — excel=구매현황 엑셀 업로드 표기 저장 */
    try {
        $pdo->exec("ALTER TABLE tt_ecount_partners MODIFY COLUMN source VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual=직접등록 / orders=발주서 수집 / excel=구매현황 엑셀 업로드'");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:partner_source] ' . $e->getMessage()); }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_stock (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prod_cd    VARCHAR(20)  NOT NULL,
            wh_cd      VARCHAR(20)  NOT NULL COMMENT '창고코드',
            wh_des     VARCHAR(100) NULL     COMMENT '창고명',
            prod_des   VARCHAR(100) NULL,
            bal_qty    VARCHAR(30)  NULL     COMMENT '재고수량(ERP 기준)',
            synced_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ecount_stock (prod_cd, wh_cd)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:stock] ' . $e->getMessage()); }

    /* [v60] 총재고(전 창고 합계) 캐시 */
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_stock_total (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prod_cd    VARCHAR(20)  NOT NULL COMMENT 'ERP 품목코드',
            prod_des   VARCHAR(100) NULL,
            bal_qty    VARCHAR(30)  NULL     COMMENT '총재고(전 창고 합계, ERP 기준)',
            synced_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ecount_stock_total (prod_cd)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:stock_total] ' . $e->getMessage()); }

    /* [v64] 구매관리 원장 — 직접 구매입고(ERP 구매입력 전송) + 작업일지 완료 자동 적용(재고 차감) */
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_purchases (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            io_date     VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '일자(YYYYMMDD)',
            cust_cd     VARCHAR(30)  NULL COMMENT '거래처코드',
            cust_name   VARCHAR(100) NULL COMMENT '거래처명',
            prod_cd     VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'ERP 품목코드',
            prod_des    VARCHAR(150) NULL COMMENT '품목명',
            product_id  INT UNSIGNED NULL COMMENT '매핑된 쇼핑몰 상품',
            qty         INT NOT NULL DEFAULT 0,
            unit_price  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '단가',
            amt         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '금액(단가×수량)',
            remark      VARCHAR(200) NULL,
            source      ENUM('manual','worklog') NOT NULL DEFAULT 'manual' COMMENT 'manual=직접 구매입고 / worklog=작업일지 완료 자동',
            work_log_id INT UNSIGNED NULL COMMENT '작업일지 연결(중복 방지 유니크)',
            erp_status  ENUM('pending','sent','fail','skip') NOT NULL DEFAULT 'pending' COMMENT 'sent=ERP 구매입력 전송 완료 / skip=작업 소모(ERP 전송 안 함) / fail=전송 실패(재전송 가능)',
            erp_sent_at DATETIME NULL,
            erp_error   VARCHAR(300) NULL,
            stock_moved TINYINT(1) NOT NULL DEFAULT 0 COMMENT '사이트 재고 반영 여부',
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ecount_purchase_worklog (work_log_id),
            INDEX idx_ep_date (io_date),
            INDEX idx_ep_cust (cust_name)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:purchases] ' . $e->getMessage()); }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_sync_log (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            direction  VARCHAR(10)  NOT NULL COMMENT 'push=몰→ERP / pull=ERP→몰',
            type       VARCHAR(30)  NOT NULL COMMENT 'sale/items/stock/partners/purchase/orders',
            status     VARCHAR(10)  NOT NULL COMMENT 'ok/fail',
            message    VARCHAR(300) NULL,
            detail     TEXT NULL,
            admin_id   INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ecount_log (created_at)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:log] ' . $e->getMessage()); }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_queue (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action     VARCHAR(30)  NOT NULL COMMENT 'order_sale/reservation_sale',
            payload    TEXT NOT NULL COMMENT 'JSON {order_id|reservation_id}',
            status     VARCHAR(10)  NOT NULL DEFAULT 'pending' COMMENT 'pending/done/fail',
            attempts   INT NOT NULL DEFAULT 0,
            last_error VARCHAR(300) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            INDEX idx_ecount_queue (status)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:queue] ' . $e->getMessage()); }

    /* 로컬 상품 ↔ ERP 품목 매핑 컬럼 */
    try {
        if (!$pdo->query("SHOW COLUMNS FROM tt_products LIKE 'ecount_prod_cd'")->fetch()) {
            $pdo->exec("ALTER TABLE tt_products ADD COLUMN ecount_prod_cd VARCHAR(20) NULL COMMENT '이카운트 품목코드 매핑' AFTER sku");
            $pdo->exec("ALTER TABLE tt_products ADD INDEX idx_products_ecount (ecount_prod_cd)");
        }
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_tables:prod_cd] ' . $e->getMessage()); }

    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_ecount_tables');
}

/** 연동 활성 여부 (설정 + 동기화 스위치) */
function ecount_enabled(): bool
{
    return EcountAPI::configured();
}

/** 다건조회(10분 제한) API 호출 가드 — 마지막 호출 후 $sec 초 지났는지 */
function ecount_rate_ok(string $key, int $sec): bool
{
    ensure_ecount_tables();
    $last = (int)get_setting($key, '0');
    return ($last === 0) || ((time() - $last) >= $sec);
}

function ecount_rate_mark(string $key): void
{
    set_setting($key, (string)time());
}

function ecount_rate_wait(string $key): int
{
    $last = (int)get_setting($key, '0');
    if ($last === 0) return 0;
    return max(0, ecount_pull_interval() - (time() - $last));
}

/** [v57] ERP 다건조회(품목·재고·발주 전체조회) 재시도 간격(초) — 관리자 설정값(30~600).
 *  이카운트 실서버 공식 규칙은 다건조회 10분(600초) 1회 — 기본값.
 *  그보다 짧게 잡으면 이카운트 Rate Limit(412/302)에 걸릴 수 있어,
 *  Rate Limit 실패 시 자동으로 10분 페널티 백오프가 동작한다(아래 pull 3종). */
function ecount_pull_interval(): int
{
    $v = (int)get_setting('ecount_pull_interval', '600');
    return max(30, min(600, $v));
}

/** [v57] Rate Limit 실패 시 페널티 마킹 — 실패한 시점부터 10분 뒤에 재시도 가능하게 */
function ecount_rate_penalty(string $key): void
{
    set_setting($key, (string)(time() + 600 - ecount_pull_interval()));
}

/** [v57] 버튼용 대기 라벨 — "N초 후 가능" / "N분 후 가능" */
function ecount_wait_label(int $waitSec): string
{
    if ($waitSec <= 0) return '';
    return $waitSec < 90
        ? ' (' . $waitSec . '초 후 가능)'
        : ' (' . (int)ceil($waitSec / 60) . '분 후 가능)';
}

/** 연동 로그 기록 */
function ecount_log(string $direction, string $type, string $status, string $message = '', ?string $detail = null, ?int $adminId = null): void
{
    try {
        Database::connection()->prepare('INSERT INTO tt_ecount_sync_log (direction, type, status, message, detail, admin_id)
                                         VALUES (:d, :t, :s, :m, :dt, :a)')
            ->execute(['d'=>$direction, 't'=>$type, 's'=>$status, 'm'=>mb_substr($message,0,300) ?: null, 'dt'=>$detail, 'a'=>$adminId]);
    } catch (Throwable $e) {
        error_log('[ecount_log] ' . $e->getMessage());
    }
}

/** 상품의 ERP 품목코드 (매핑 > 품번sku > 자동 T+id) */
function ecount_product_code(array $product): string
{
    $code = trim((string)($product['ecount_prod_cd'] ?? ''));
    if ($code !== '') return $code;
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '') return $sku;
    return 'T' . str_pad((string)(int)$product['id'], 5, '0', STR_PAD_LEFT);
}

/** 상품 1건을 로컬 DB에서 조회 */
function ecount_get_product(int $productId): ?array
{
    try {
        $row = Database::connection()->prepare('SELECT * FROM tt_products WHERE id = :id');
        $row->execute(['id' => $productId]);
        $p = $row->fetch(PDO::FETCH_ASSOC);
        return $p ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ---------- 전송 큐 (몰 → ERP push) ---------- */

function ecount_queue_add(string $action, array $payload): int
{
    ensure_ecount_tables();
    try {
        Database::connection()->prepare('INSERT INTO tt_ecount_queue (action, payload) VALUES (:a, :p)')
            ->execute(['a'=>$action, 'p'=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        return (int)Database::connection()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[ecount_queue_add] ' . $e->getMessage());
        return 0;
    }
}

/**
 * 결제완료 → ERP 판매입력(SaveSale) 전송.
 * 실패 시 재시도(attempts<8)하며 pending 유지 — ecount-cron 이 나중에 다시 처리.
 * Rate Limit 에러는 attempts 를 올리지 않고 pending 유지.
 */
function ecount_push_sale(array $payload): array
{
    $kind = $payload['kind'] ?? 'order';
    $refId = (int)($payload['id'] ?? 0);
    if ($refId <= 0) return ['ok' => false, 'message' => '대상 ID 없음'];

    $api = EcountAPI::fromSettings();
    if (!EcountAPI::configured()) return ['ok' => false, 'message' => '이카운트 연동 설정이 비어 있습니다(회사코드/ID/인증키).'];

    $pdo = Database::connection();
    $whCd = get_setting('ecount_wh_cd', '');
    $custCd = get_setting('ecount_cust_cd', '');
    $items = [];
    $ioDate = date('Ymd');
    $custDes = '쇼핑몰 판매';

    if ($kind === 'order') {
        $order = $pdo->prepare('SELECT * FROM tt_orders WHERE id = :id');
        $order->execute(['id' => $refId]);
        $o = $order->fetch(PDO::FETCH_ASSOC);
        if (!$o) return ['ok' => false, 'message' => '주문을 찾을 수 없습니다.'];
        if (($o['payment_status'] ?? '') !== 'paid') return ['ok' => false, 'message' => '결제완료 주문이 아닙니다.'];
        $custDes = '쇼핑몰 주문 ' . $o['order_no'];
        $itemStmt = $pdo->prepare('SELECT oi.*, p.ecount_prod_cd, p.sku FROM tt_order_items oi
                                   LEFT JOIN tt_products p ON p.id = oi.product_id WHERE oi.order_id = :oid');
        $itemStmt->execute(['oid' => $refId]);
        $ser = 0;
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $ser++;
            $prodCd = trim((string)($it['ecount_prod_cd'] ?? '')) ?: (trim((string)($it['sku'] ?? '')) ?: 'T' . str_pad((string)(int)$it['product_id'], 5, '0', STR_PAD_LEFT));
            $qty = (int)$it['qty'];
            $unit = (int)$it['price'];
            $supply = (int)round($unit * $qty / 1.1);
            $vat = ($unit * $qty) - $supply;
            $items[] = [
                'UPLOAD_SER_NO' => (string)$ser,
                'IO_DATE'   => $ioDate,
                'CUST'      => $custCd,
                'CUST_DES'  => $custDes,
                'WH_CD'     => $whCd,
                'PROD_CD'   => $prodCd,
                'PROD_DES'  => (string)$it['product_name'],
                'QTY'       => (string)$qty,
                'PRICE'     => (string)(int)round($unit / 1.1),
                'SUPPLY_AMT' => (string)$supply,
                'VAT_AMT'   => (string)$vat,
                'REMARKS'   => '쇼핑몰 주문 자동전송(' . $o['order_no'] . ')',
            ];
        }
    } else { // reservation
        $res = $pdo->prepare('SELECT * FROM tt_reservations WHERE id = :id');
        $res->execute(['id' => $refId]);
        $r = $res->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'message' => '예약을 찾을 수 없습니다.'];
        if (($r['payment_status'] ?? '') !== 'paid') return ['ok' => false, 'message' => '결제완료 예약이 아닙니다.'];
        $custDes = '쇼핑몰 예약 ' . $r['res_no'];
        $p = ecount_get_product((int)$r['product_id']);
        $prodCd = $p ? ecount_product_code($p) : ('T' . str_pad((string)(int)$r['product_id'], 5, '0', STR_PAD_LEFT));
        $qty = (int)$r['qty'];
        $unit = (int)$r['unit_price'];
        $supply = (int)round($unit * $qty / 1.1);
        $items[] = [
            'UPLOAD_SER_NO' => '1',
            'IO_DATE'   => $ioDate,
            'CUST'      => $custCd,
            'CUST_DES'  => $custDes,
            'WH_CD'     => $whCd,
            'PROD_CD'   => $prodCd,
            'PROD_DES'  => (string)$r['product_name_snap'],
            'QTY'       => (string)$qty,
            'PRICE'     => (string)(int)round($unit / 1.1),
            'SUPPLY_AMT' => (string)$supply,
            'VAT_AMT'   => (string)(($unit * $qty) - $supply),
            'REMARKS'   => '쇼핑몰 예약결제 자동전송(' . $r['res_no'] . ')',
        ];
    }

    if (!$items) return ['ok' => false, 'message' => '전송할 품목이 없습니다.'];

    try {
        $data = $api->saveSales($items);
        $okCnt = (int)($data['SuccessCnt'] ?? 0);
        $failCnt = (int)($data['FailCnt'] ?? 0);
        if ($okCnt > 0 && $failCnt === 0) {
            return ['ok' => true, 'message' => 'ERP 판매입력 전송 성공 (' . $okCnt . '건)' . (!empty($data['SlipNos']) ? ' 전표:' . implode(',', (array)$data['SlipNos']) : '')];
        }
        $details = is_string($data['ResultDetails'] ?? null) ? (string)$data['ResultDetails'] : json_encode($data['ResultDetails'] ?? [], JSON_UNESCAPED_UNICODE);
        return ['ok' => false, 'message' => 'ERP 저장 결과 실패(' . $failCnt . '건): ' . mb_substr($details, 0, 200)];
    } catch (EcountException $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'rate' => (stripos($e->getMessage(), '호출 제한') !== false)];
    }
}

/** 관리자의 수동 재고 채움 → ERP 생산입고 전송 (ERP 재고 증가) */
function ecount_push_goods_receipt(int $productId, int $qty, string $remark = ''): array
{
    $api = EcountAPI::fromSettings();
    if (!EcountAPI::configured()) return ['ok' => false, 'message' => '이카운트 연동 설정이 비어 있습니다.'];
    $p = ecount_get_product($productId);
    if (!$p) return ['ok' => false, 'message' => '상품을 찾을 수 없습니다.'];
    if ($qty <= 0) return ['ok' => false, 'message' => '수량은 1 이상이어야 합니다.'];
    $whCd = get_setting('ecount_wh_cd', '');
    try {
        $data = $api->saveGoodsReceipts([[
            'UPLOAD_SER_NO' => '1',
            'IO_DATE'  => date('Ymd'),
            'WH_CD_F'  => $whCd,
            'WH_CD_T'  => $whCd,
            'PROD_CD'  => ecount_product_code($p),
            'PROD_DES' => (string)$p['name'],
            'QTY'      => (string)$qty,
            'REMARKS'  => ($remark !== '' ? $remark : '쇼핑몰 관리자 재고 입고 전송'),
        ]]);
        $okCnt = (int)($data['SuccessCnt'] ?? 0);
        if ($okCnt > 0) {
            ecount_log('push', 'receipt', 'ok', '상품 #' . $productId . ' ERP 입고 전송 (' . $qty . '개)');
            return ['ok' => true, 'message' => 'ERP 입고 전송 성공 (' . $qty . '개)'];
        }
        $details = is_string($data['ResultDetails'] ?? null) ? (string)$data['ResultDetails'] : json_encode($data['ResultDetails'] ?? [], JSON_UNESCAPED_UNICODE);
        return ['ok' => false, 'message' => 'ERP 입고 저장 실패: ' . mb_substr($details, 0, 200)];
    } catch (EcountException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/** 보류 중 큐 처리 (결제완료 건의 ERP 전송 재시도 포함) */
function ecount_queue_process(int $limit = 10): array
{
    ensure_ecount_tables();
    $pdo = Database::connection();
    $stmt = $pdo->prepare("SELECT * FROM tt_ecount_queue WHERE status = 'pending' AND attempts < 8 ORDER BY id LIMIT :n");
    $stmt->bindValue('n', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $done = 0; $still = 0; $failed = 0;

    foreach ($rows as $q) {
        $payload = json_decode((string)$q['payload'], true) ?: [];
        $result = ecount_push_sale($payload);
        if ($result['ok'] ?? false) {
            $pdo->prepare("UPDATE tt_ecount_queue SET status='done', processed_at=NOW(), last_error=NULL WHERE id=:id")
                ->execute(['id' => (int)$q['id']]);
            ecount_log('push', 'sale', 'ok', ($payload['kind'] ?? 'order') . ' #' . ($payload['id'] ?? '?') . ' ' . ($result['message'] ?? ''));
            $done++;
        } elseif ($result['rate'] ?? false) {
            $still++; // rate limit — 펜딩 유지, attempts 미증가
        } else {
            $msg = (string)($result['message'] ?? '전송 실패');
            $attempts = (int)$q['attempts'] + 1;
            $newStatus = $attempts >= 8 ? 'fail' : 'pending';
            $pdo->prepare('UPDATE tt_ecount_queue SET attempts=:a, status=:s, last_error=:e WHERE id=:id')
                ->execute(['a'=>$attempts, 's'=>$newStatus, 'e'=>mb_substr($msg,0,300), 'id'=>(int)$q['id']]);
            ecount_log('push', 'sale', 'fail', ($payload['kind'] ?? 'order') . ' #' . ($payload['id'] ?? '?') . ' ' . $msg);
            if ($newStatus === 'fail') $failed++; else $still++;
        }
    }
    return ['done' => $done, 'pending' => $still, 'failed' => $failed];
}

/** 결제완료 시점 호출 — 큐 적재 후 응답(리다이렉트)이 끝난 뒤 즉시 전송 시도.
 *  실패분은 큐에 pending 으로 남아 cron·ajax 핑이 재시도한다.
 *  shutdown 실행이라 사용자의 결제 화면 응답속도에 영향을 주지 않는다. */
function ecount_on_paid(string $kind, int $id): void
{
    if (!EcountAPI::configured()) return;
    if (get_setting('ecount_auto_push', '1') !== '1') return;
    ecount_queue_add($kind === 'order' ? 'order_sale' : 'reservation_sale', ['kind' => $kind, 'id' => $id]);
    register_shutdown_function(static function (): void {
        try {
            ecount_queue_process(10);
        } catch (Throwable $e) {
            error_log('[ecount_on_paid:shutdown] ' . $e->getMessage());
        }
    });
}

/* ---------- ERP → 몰 (pull) ---------- */

/** ERP 품목 목록 → tt_ecount_items 캐시 ([v57] 간격 설정 기반 가드) */
function ecount_pull_items(bool $force = false): array
{
    ensure_ecount_tables();
    if (!$force && !ecount_rate_ok('ecount_last_items_pull', ecount_pull_interval())) {
        return ['ok' => false, 'message' => '품목 전체조회는 지금 설정 간격(' . ecount_pull_interval() . '초) 안에서 1회만 가능합니다. ' . ecount_rate_wait('ecount_last_items_pull') . '초 후 재시도하세요. (이카운트 실서버 규칙은 다건조회 10분 1회 — 간격은 이카운트 ERP 화면의 동기화 설정에서 바꿀 수 있어요)', 'count' => 0];
    }
    try {
        $api = EcountAPI::fromSettings();
        // [v57] 호출 전에 가드 표시 — 실패해도 10분 백오프 (반복 재시도가 이카운트 연속 오류 제한에 걸리는 것 방지)
        ecount_rate_mark('ecount_last_items_pull');
        $g = $api->getItems();
        $rows = $g['rows'];
        $pdo = Database::connection();
        $up = $pdo->prepare('INSERT INTO tt_ecount_items (prod_cd, prod_des, size_des, unit, in_price, out_price, class_cd, bal_flag, use_yn, synced_at)
                             VALUES (:cd, :des, :sz, :u, :ip, :op, :cl, :bf, :uy, NOW())
                             ON DUPLICATE KEY UPDATE prod_des=VALUES(prod_des), size_des=VALUES(size_des), unit=VALUES(unit),
                               in_price=VALUES(in_price), out_price=VALUES(out_price), class_cd=VALUES(class_cd),
                               bal_flag=VALUES(bal_flag), use_yn=VALUES(use_yn), synced_at=NOW()');
        $n = 0;
        foreach ($rows as $r) {
            $cd = trim((string)($r['PROD_CD'] ?? ''));
            if ($cd === '') continue;
            $up->execute([
                'cd' => $cd,
                'des' => mb_substr(trim((string)($r['PROD_DES'] ?? '')), 0, 100),
                'sz' => mb_substr(trim((string)($r['SIZE_DES'] ?? '')), 0, 100),
                'u' => mb_substr(trim((string)($r['UNIT'] ?? '')), 0, 10),
                'ip' => (string)($r['IN_PRICE'] ?? ''),
                'op' => (string)($r['OUT_PRICE'] ?? ''),
                'cl' => (string)($r['CLASS_CD'] ?? ''),
                'bf' => (string)($r['BAL_FLAG'] ?? ''),
                'uy' => (string)($r['USE_YN'] ?? ($r['DEL_GUBUN'] ?? '')),
            ]);
            $n++;
        }
        /* [v62] ERP에서 삭제된 품목 동기화 — 전체 조회가 완전(수신 행 >= TotalCnt)하면
         * 이번 응답에 없는 캐시 품목은 ERP에서 삭제/제외된 것 → 캐시에서 제거(상품 매핑은 보존) */
        $pruned = 0;
        if ($n > 0 && count($rows) >= (int)$g['total']) {
            $seen = [];
            foreach ($rows as $r) {
                $cd = trim((string)($r['PROD_CD'] ?? ''));
                if ($cd !== '') $seen[$cd] = true;
            }
            foreach ($pdo->query('SELECT prod_cd FROM tt_ecount_items')->fetchAll(PDO::FETCH_COLUMN) as $cachedCd) {
                if (!isset($seen[$cachedCd])) {
                    try { $pdo->prepare('DELETE FROM tt_ecount_items WHERE prod_cd = :cd')->execute(['cd' => $cachedCd]); $pruned++; }
                    catch (Throwable $ignore) {}
                }
            }
        }
        ecount_log('pull', 'items', 'ok', 'ERP 품목 ' . $n . '건 캐시 갱신' . ($pruned > 0 ? ' · ERP 삭제 반영 ' . $pruned . '건 제거' : ''));
        return ['ok' => true, 'message' => 'ERP 품목 ' . $n . '건을 가져왔습니다.' . ($pruned > 0 ? ' (ERP에서 삭제된 ' . $pruned . '건을 목록에서 제거했어요)' : ''), 'count' => $n];
    } catch (Throwable $e) {
        // [v57] 이카운트 Rate Limit(412/302) 실패 시 10분 페널티 백오프 — 짧은 간격 설정 방어
        if (stripos($e->getMessage(), 'Rate Limit') !== false) { ecount_rate_penalty('ecount_last_items_pull'); }
        ecount_log('pull', 'items', 'fail', $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage(), 'count' => 0];
    }
}

/** ERP 창고별 재고 → tt_ecount_stock 캐시 + (동기화 켜짐 시) 매핑 상품 로컬 재고 자동 반영 */
function ecount_pull_stock(bool $force = false): array
{
    ensure_ecount_tables();
    ensure_stock_movement_tables();
    if (!$force && !ecount_rate_ok('ecount_last_stock_pull', ecount_pull_interval())) {
        return ['ok' => false, 'message' => '창고별 재고 전체조회는 지금 설정 간격(' . ecount_pull_interval() . '초) 안에서 1회만 가능합니다. ' . ecount_rate_wait('ecount_last_stock_pull') . '초 후 재시도하세요. (이카운트 실서버 규칙은 다건조회 10분 1회 — 간격은 이카운트 ERP 화면의 동기화 설정에서 바꿀 수 있어요. 특정 상품 1개는 재고 페이지에서 언제든 1초 단위 실시간 조회가 돼요)', 'applied' => 0];
    }
    try {
        $api = EcountAPI::fromSettings();
        // [v54] 호출 전에 가드 표시 — 실패해도 10분 백오프 (반복 재시도가 이카운트 연속 오류 제한에 걸리는 것 방지)
        ecount_rate_mark('ecount_last_stock_pull');
        $rows = $api->getStockByWarehouseList();
        $pdo = Database::connection();
        $up = $pdo->prepare('INSERT INTO tt_ecount_stock (prod_cd, wh_cd, wh_des, prod_des, bal_qty, synced_at)
                             VALUES (:cd, :wh, :wd, :pd, :q, NOW())
                             ON DUPLICATE KEY UPDATE wh_des=VALUES(wh_des), prod_des=VALUES(prod_des), bal_qty=VALUES(bal_qty), synced_at=NOW()');
        $n = 0;
        foreach ($rows as $r) {
            $cd = trim((string)($r['PROD_CD'] ?? ''));
            $wh = trim((string)($r['WH_CD'] ?? ''));
            if ($cd === '' || $wh === '') continue;
            $up->execute([
                'cd' => $cd, 'wh' => $wh,
                'wd' => mb_substr(trim((string)($r['WH_DES'] ?? '')), 0, 100),
                'pd' => mb_substr(trim((string)($r['PROD_DES'] ?? '')), 0, 100),
                'q' => (string)($r['BAL_QTY'] ?? '0'),
            ]);
            $n++;
        }

        /* 매핑된 상품의 로컬 재고를 ERP 총재고(전 창고 합) 기준으로 자동 반영 */
        $applied = 0;
        if (get_setting('ecount_sync_enabled', '1') === '1') {
            $mapStmt = $pdo->prepare("SELECT p.id, p.stock, p.name, COALESCE(SUM(CAST(s.bal_qty AS DECIMAL(18,4))), 0) AS erp_qty
                                      FROM tt_products p
                                      JOIN tt_ecount_stock s ON s.prod_cd = p.ecount_prod_cd
                                      GROUP BY p.id, p.stock, p.name");
            $mapStmt->execute();
            $upd = $pdo->prepare('UPDATE tt_products SET stock = :st WHERE id = :id');
            foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $erpQty = (int)round((float)$m['erp_qty']);
                $local  = (int)$m['stock'];
                if ($erpQty === $local) continue;
                $upd->execute(['st' => $erpQty, 'id' => (int)$m['id']]);
                record_stock_movement((int)$m['id'], null, 'adjust', $erpQty - $local, $erpQty,
                    '이카운트 자동 동기화 (ERP ' . $erpQty . ' ← 로컬 ' . $local . ')', 'ecount-sync');
                $applied++;
            }
        }
        set_setting('ecount_last_stock_sync', (string)time());
        ecount_log('pull', 'stock', 'ok', 'ERP 창고별 재고 ' . $n . '행 갱신 · 로컬 상품 ' . $applied . '건 자동 반영');
        return ['ok' => true, 'message' => 'ERP 창고별 재고 ' . $n . '행을 가져왔습니다. 매핑 상품 ' . $applied . '건이 자동 반영되었습니다.', 'applied' => $applied];
    } catch (Throwable $e) {
        // [v57] 이카운트 Rate Limit(412/302) 실패 시 10분 페널티 백오프 — 짧은 간격 설정 방어
        if (stripos($e->getMessage(), 'Rate Limit') !== false) { ecount_rate_penalty('ecount_last_stock_pull'); }
        ecount_log('pull', 'stock', 'fail', $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage(), 'applied' => 0];
    }
}

/** [v60] ERP 총재고(전 창고 합계) → tt_ecount_stock_total 캐시 (재고현황 API — 10분 1회) */
function ecount_pull_stock_total(bool $force = false): array
{
    ensure_ecount_tables();
    if (!$force && !ecount_rate_ok('ecount_last_stock_total_pull', ecount_pull_interval())) {
        return ['ok' => false, 'message' => '총재고(재고현황) 조회는 지금 설정 간격(' . ecount_pull_interval() . '초) 안에서 1회만 가능합니다. ' . ecount_rate_wait('ecount_last_stock_total_pull') . '초 후 재시도하세요.', 'count' => 0];
    }
    try {
        $api = EcountAPI::fromSettings();
        ecount_rate_mark('ecount_last_stock_total_pull');
        $rows = $api->getStockTotalList();
        $pdo = Database::connection();
        $up = $pdo->prepare('INSERT INTO tt_ecount_stock_total (prod_cd, prod_des, bal_qty, synced_at)
                             VALUES (:cd, :pd, :q, NOW())
                             ON DUPLICATE KEY UPDATE prod_des=VALUES(prod_des), bal_qty=VALUES(bal_qty), synced_at=NOW()');
        $n = 0;
        foreach ($rows as $r) {
            $cd = trim((string)($r['PROD_CD'] ?? ''));
            if ($cd === '') continue;
            $up->execute([
                'cd' => $cd,
                'pd' => mb_substr(trim((string)($r['PROD_DES'] ?? ($r['PROD_SIZE_DES'] ?? ''))), 0, 100),
                'q'  => (string)($r['BAL_QTY'] ?? '0'),
            ]);
            $n++;
        }
        ecount_log('pull', 'stock_total', 'ok', 'ERP 총재고 ' . $n . '품목 갱신');
        return ['ok' => true, 'message' => 'ERP 총재고 ' . $n . '품목을 가져왔습니다.', 'count' => $n];
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Rate Limit') !== false) { ecount_rate_penalty('ecount_last_stock_total_pull'); }
        ecount_log('pull', 'stock_total', 'fail', $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage(), 'count' => 0];
    }
}

/** 단일 품목 실시간 창고별 재고 조회(1초 제한 — 버튼 클릭용) */
function ecount_query_stock_single(string $prodCd): array
{
    ensure_ecount_tables();
    try {
        $api = EcountAPI::fromSettings();
        $rows = $api->getStockByWarehouse($prodCd);
        $pdo = Database::connection();
        $up = $pdo->prepare('INSERT INTO tt_ecount_stock (prod_cd, wh_cd, wh_des, prod_des, bal_qty, synced_at)
                             VALUES (:cd, :wh, :wd, :pd, :q, NOW())
                             ON DUPLICATE KEY UPDATE wh_des=VALUES(wh_des), prod_des=VALUES(prod_des), bal_qty=VALUES(bal_qty), synced_at=NOW()');
        foreach ($rows as $r) {
            $wh = trim((string)($r['WH_CD'] ?? ''));
            if ($wh === '') continue;
            $up->execute([
                'cd' => $prodCd, 'wh' => $wh,
                'wd' => mb_substr(trim((string)($r['WH_DES'] ?? '')), 0, 100),
                'pd' => mb_substr(trim((string)($r['PROD_DES'] ?? '')), 0, 100),
                'q' => (string)($r['BAL_QTY'] ?? '0'),
            ]);
        }
        $total = 0;
        foreach ($rows as $r) $total += (float)($r['BAL_QTY'] ?? 0);
        ecount_log('pull', 'stock', 'ok', '품목 ' . $prodCd . ' 실시간 조회 (' . count($rows) . '창고)');
        return ['ok' => true, 'rows' => $rows, 'total' => (int)round($total)];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/** ERP 거래처 저장(등록) — 몰 관리자 → ERP + 로컬 캐시 */
function ecount_save_partner(array $fields): array
{
    ensure_ecount_tables();
    $custCd = trim((string)($fields['cust_cd'] ?? ''));
    $custName = trim((string)($fields['cust_name'] ?? ''));
    if ($custCd === '' || $custName === '') return ['ok' => false, 'message' => '거래처코드와 거래처명은 필수입니다.'];
    $bulk = [
        'BUSINESS_NO' => $custCd,
        'CUST_NAME'   => $custName,
    ];
    foreach ([
        'boss_name' => 'BOSS_NAME', 'uptae' => 'UPTAE', 'jongmok' => 'JONGMOK',
        'tel' => 'TEL', 'email' => 'EMAIL', 'addr' => 'ADDR', 'fax' => 'FAX',
    ] as $k => $apiField) {
        $v = trim((string)($fields[$k] ?? ''));
        if ($v !== '') $bulk[$apiField] = mb_substr($v, 0, 200);
    }
    if (!empty($fields['remarks'])) $bulk['REMARKS'] = mb_substr(trim((string)$fields['remarks']), 0, 500);

    try {
        $api = EcountAPI::fromSettings();
        $data = $api->savePartner($bulk);
        $okCnt = (int)($data['SuccessCnt'] ?? 0);
        if ($okCnt < 1) {
            $details = is_string($data['ResultDetails'] ?? null) ? (string)$data['ResultDetails'] : json_encode($data['ResultDetails'] ?? [], JSON_UNESCAPED_UNICODE);
            return ['ok' => false, 'message' => 'ERP 거래처 등록 실패: ' . mb_substr($details, 0, 200)];
        }
    } catch (EcountException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    // 로컬 캐시 upsert
    try {
        Database::connection()->prepare('INSERT INTO tt_ecount_partners (cust_cd, cust_name, boss_name, tel, addr, uptae, jongmok, email, remarks, source)
                                        VALUES (:cd, :nm, :boss, :tel, :addr, :ut, :jm, :em, :rk, :src)
                                        ON DUPLICATE KEY UPDATE cust_name=VALUES(cust_name), boss_name=VALUES(boss_name), tel=VALUES(tel),
                                          addr=VALUES(addr), uptae=VALUES(uptae), jongmok=VALUES(jongmok), email=VALUES(email), remarks=VALUES(remarks)')
            ->execute([
                'cd'=>$custCd, 'nm'=>$custName,
                'boss'=>trim((string)($fields['boss_name'] ?? '')) ?: null,
                'tel'=>trim((string)($fields['tel'] ?? '')) ?: null,
                'addr'=>trim((string)($fields['addr'] ?? '')) ?: null,
                'ut'=>trim((string)($fields['uptae'] ?? '')) ?: null,
                'jm'=>trim((string)($fields['jongmok'] ?? '')) ?: null,
                'em'=>trim((string)($fields['email'] ?? '')) ?: null,
                'rk'=>trim((string)($fields['remarks'] ?? '')) ?: null,
                'src'=>trim((string)($fields['source'] ?? 'manual')) ?: 'manual',
            ]);
    } catch (Throwable $e) {
        error_log('[ecount_save_partner:local] ' . $e->getMessage());
    }
    ecount_log('push', 'partners', 'ok', '거래처 등록: ' . $custName . '(' . $custCd . ')');
    return ['ok' => true, 'message' => 'ERP + 관리자에 거래처가 등록되었습니다.'];
}

/** ERP 품목 등록 — 몰 관리자 → ERP + 로컬 캐시 */
function ecount_save_item(array $fields): array
{
    ensure_ecount_tables();
    $prodCd = trim((string)($fields['prod_cd'] ?? ''));
    $prodDes = trim((string)($fields['prod_des'] ?? ''));
    if ($prodCd === '' || $prodDes === '') return ['ok' => false, 'message' => '품목코드와 품목명은 필수입니다.'];
    $bulk = ['PROD_CD' => $prodCd, 'PROD_DES' => $prodDes];
    foreach ([
        'size_des' => 'SIZE_DES', 'unit' => 'UNIT', 'in_price' => 'IN_PRICE', 'out_price' => 'OUT_PRICE',
        'class_cd' => 'CLASS_CD',
    ] as $k => $apiField) {
        $v = trim((string)($fields[$k] ?? ''));
        if ($v !== '') $bulk[$apiField] = $v;
    }
    // [v58] WH_CD 는 SaveBasicProduct 에서 생산공정 코드로 해석되어 실서버가
    // 미등록 코드(예: 005)를 "생산공정(미등록코드)" 오류로 거부 — 품목 등록에서는 전송하지 않는다.
    if (!empty($fields['remarks'])) $bulk['REMARKS'] = mb_substr(trim((string)$fields['remarks']), 0, 200);

    try {
        $api = EcountAPI::fromSettings();
        $data = $api->saveItems([$bulk]);
        $okCnt = (int)($data['SuccessCnt'] ?? 0);
        if ($okCnt < 1) {
            $details = is_string($data['ResultDetails'] ?? null) ? (string)$data['ResultDetails'] : json_encode($data['ResultDetails'] ?? [], JSON_UNESCAPED_UNICODE);
            return ['ok' => false, 'message' => 'ERP 품목 등록 실패: ' . mb_substr($details, 0, 200)];
        }
    } catch (EcountException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    try {
        Database::connection()->prepare('INSERT INTO tt_ecount_items (prod_cd, prod_des, size_des, unit, in_price, out_price, class_cd, synced_at)
                                        VALUES (:cd, :des, :sz, :u, :ip, :op, :cl, NOW())
                                        ON DUPLICATE KEY UPDATE prod_des=VALUES(prod_des), size_des=VALUES(size_des), unit=VALUES(unit),
                                          in_price=VALUES(in_price), out_price=VALUES(out_price), class_cd=VALUES(class_cd), synced_at=NOW()')
            ->execute([
                'cd'=>$prodCd, 'des'=>$prodDes,
                'sz'=>trim((string)($fields['size_des'] ?? '')) ?: null,
                'u'=>trim((string)($fields['unit'] ?? '')) ?: null,
                'ip'=>trim((string)($fields['in_price'] ?? '')) ?: null,
                'op'=>trim((string)($fields['out_price'] ?? '')) ?: null,
                'cl'=>trim((string)($fields['class_cd'] ?? '')) ?: null,
            ]);
    } catch (Throwable $e) {
        error_log('[ecount_save_item:local] ' . $e->getMessage());
    }
    ecount_log('push', 'items', 'ok', '품목 등록: ' . $prodDes . '(' . $prodCd . ')');
    return ['ok' => true, 'message' => 'ERP + 관리자에 품목이 등록되었습니다.'];
}

/** 발주서 입력(SavePurchases) — 여러 라인을 한 전표(UPLOAD_SER_NO=1)로 */
function ecount_save_purchase_order(array $lines, string $custCd, string $custName, string $ioDateYmd = ''): array
{
    ensure_ecount_tables();
    $custCd = trim($custCd);
    $custName = trim($custName);
    if ($custCd === '') return ['ok' => false, 'message' => '거래처를 선택하세요.'];
    if (!$lines) return ['ok' => false, 'message' => '품목 라인이 없습니다.'];
    if ($ioDateYmd === '') $ioDateYmd = date('Ymd');
    $whCd = get_setting('ecount_wh_cd', '');

    $bulk = [];
    $ser = 0;
    foreach ($lines as $ln) {
        $prodCd = trim((string)($ln['prod_cd'] ?? ''));
        $qty = (int)($ln['qty'] ?? 0);
        $price = (int)($ln['price'] ?? 0);
        if ($prodCd === '' || $qty <= 0) continue;
        $ser++;
        $supply = $price * $qty;
        /* [v61] 발주서(SavePurchaseOrder)로 전환 — 발주서조회(GetPurchasesOrderList)에 바로 조회되는 전표.
         * 이전의 구매입력(SavePurchases)은 구매(입고) 전표라서 발주서조회에 나오지 않았음(구매현황 0건의 원인). */
        $bulk[] = [
            'VPM_LINE_SER_NO' => (string)$ser,
            'UPLOAD_SER_NO' => '1',
            'IO_DATE'    => $ioDateYmd,
            'CUST'       => $custCd,
            'CUST_DES'   => $custName,
            'WH_CD'      => $whCd,
            'PROD_CD'    => $prodCd,
            'PROD_DES'   => mb_substr(trim((string)($ln['prod_des'] ?? '')), 0, 100),
            'QTY'        => (string)$qty,
            'PRICE'      => (string)$price,
            'SUPPLY_AMT' => (string)$supply,
            'VAT_AMT'    => (string)(int)round($supply * 0.1),
            'REMARKS'    => '쇼핑몰 관리자 발주 입력',
        ];
    }
    if (!$bulk) return ['ok' => false, 'message' => '유효한 품목 라인이 없습니다(품목·수량 확인).'];

    try {
        $api = EcountAPI::fromSettings();
        $data = $api->savePurchaseOrder($bulk);
        $okCnt = (int)($data['SuccessCnt'] ?? 0);
        $failCnt = (int)($data['FailCnt'] ?? 0);
        $slips = $data['SlipNos'] ?? null;
        if ($okCnt > 0) {
            ecount_log('push', 'purchase', 'ok', '발주(구매) 입력 ' . $okCnt . '라인' . ($slips ? ' 구매번호:' . implode(',', (array)$slips) : ''));
            return ['ok' => true, 'message' => 'ERP에 발주(구매)가 입력되었습니다 (' . $okCnt . '라인' . ($failCnt ? ', 실패 ' . $failCnt . '라인' : '') . ')' . ($slips ? ' · 구매번호: ' . implode(', ', (array)$slips) : '')];
        }
        $details = is_string($data['ResultDetails'] ?? null) ? (string)$data['ResultDetails'] : json_encode($data['ResultDetails'] ?? [], JSON_UNESCAPED_UNICODE);
        ecount_log('push', 'purchase', 'fail', '발주 입력 실패: ' . mb_substr($details, 0, 200));
        return ['ok' => false, 'message' => 'ERP 발주 입력 실패: ' . mb_substr($details, 0, 250)];
    } catch (EcountException $e) {
        ecount_log('push', 'purchase', 'fail', '발주 입력 실패: ' . $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/* ============================================================ [v64] 구매관리 (구매입고 · 작업소모) ============================================================ */

/** [v64] 직접 구매입고 입력 — ERP 구매입력(SavePurchases) 전송(ERP 재고 증가) + 구매관리 원장 저장 + 매핑 상품 사이트 재고 증가 */
function ecount_save_purchase_entry(string $custName, string $prodInput, int $qty, int $unitPrice, string $ioDateYmd = '', string $remark = ''): array
{
    ensure_ecount_tables();
    $pdo = Database::connection();
    $custName = trim($custName);
    $prodInput = trim($prodInput);
    if ($custName === '') return ['ok' => false, 'message' => '거래처를 입력·선택해 주세요.'];
    if ($prodInput === '') return ['ok' => false, 'message' => '품목을 입력·선택해 주세요.'];
    if ($qty <= 0) return ['ok' => false, 'message' => '수량을 1 이상 입력해 주세요.'];
    if ($unitPrice < 0) return ['ok' => false, 'message' => '단가를 확인해 주세요.'];
    if ($ioDateYmd === '') $ioDateYmd = date('Ymd');
    $ioDateYmd = preg_replace('/[^0-9]/', '', $ioDateYmd);
    if (strlen($ioDateYmd) !== 8) return ['ok' => false, 'message' => '일자를 YYYY-MM-DD로 입력해 주세요.'];

    /* 거래처 해결 — 이름으로 코드 찾기 */
    $cp = $pdo->prepare('SELECT cust_cd, cust_name FROM tt_ecount_partners WHERE cust_name = :nm LIMIT 1');
    $cp->execute(['nm' => $custName]);
    $partner = $cp->fetch(PDO::FETCH_ASSOC) ?: null;
    $custCd = $partner ? (string)$partner['cust_cd'] : '';

    /* 품목 해결 — "품목명 [ERP코드]" 후缀 파싱 → 코드 → 이름 */
    $prodCd = '';
    $prodDes = $prodInput;
    if (preg_match('/^(.*)\s\[([A-Za-z0-9_-]{2,30})\]$/u', $prodInput, $m)) {
        $prodDes = trim($m[1]);
        $prodCd = $m[2];
    }
    if ($prodCd === '') {
        $ip = $pdo->prepare('SELECT prod_cd, prod_des FROM tt_ecount_items WHERE prod_des = :d ORDER BY prod_cd LIMIT 2');
        $ip->execute(['d' => $prodDes]);
        $hits = $ip->fetchAll(PDO::FETCH_ASSOC);
        if (count($hits) === 1) { $prodCd = (string)$hits[0]['prod_cd']; $prodDes = (string)$hits[0]['prod_des']; }
        elseif (count($hits) > 1) return ['ok' => false, 'message' => '같은 이름의 ERP 품목이 여러 개예요 — 자동완성에서 "[품목코드]"까지 포함해 선택해 주세요.'];
    } else {
        $ip = $pdo->prepare('SELECT prod_des FROM tt_ecount_items WHERE prod_cd = :cd LIMIT 1');
        $ip->execute(['cd' => $prodCd]);
        $des = $ip->fetchColumn();
        if ($des !== false) $prodDes = (string)$des;
    }
    if ($prodCd === '') return ['ok' => false, 'message' => 'ERP 품목을 찾지 못했어요 — 자동완성 목록에서 선택해 주세요. (ERP 품목이 안 나오면 품목 관리에서 "ERP에서 가져오기"를 먼저 눌러 주세요)'];

    /* 매핑 상품 */
    $mp = $pdo->prepare('SELECT id, stock FROM tt_products WHERE ecount_prod_cd = :cd LIMIT 1');
    $mp->execute(['cd' => $prodCd]);
    $mRow = $mp->fetch(PDO::FETCH_ASSOC) ?: null;
    $productId = $mRow ? (int)$mRow['id'] : null;
    $amt = $unitPrice * $qty;

    $ins = $pdo->prepare('INSERT INTO tt_ecount_purchases (io_date, cust_cd, cust_name, prod_cd, prod_des, product_id, qty, unit_price, amt, remark, source, erp_status)
                          VALUES (:d, :cd, :cn, :pc, :pd, :pid, :q, :up, :am, :rk, "manual", "pending")');
    $ins->execute(['d' => $ioDateYmd, 'cd' => $custCd, 'cn' => $custName, 'pc' => $prodCd, 'pd' => mb_substr($prodDes, 0, 150),
                   'pid' => $productId, 'q' => $qty, 'up' => $unitPrice, 'am' => $amt, 'rk' => mb_substr(trim($remark), 0, 200)]);
    $purchaseId = (int)$pdo->lastInsertId();

    /* ERP 구매입력 전송 (구매 전표 → ERP 재고 증가 + ERP 구매현황 화면에 기록) */
    $r = ecount_push_purchase_to_erp($purchaseId, $qty, $unitPrice, $ioDateYmd, $custCd, $custName, $prodCd, $prodDes, '쇼핑몰 구매입고 입력');

    /* 매핑 상품이면 사이트 재고 증가 */
    if ($productId) {
        try {
            $pdo->prepare('UPDATE tt_products SET stock = stock + :q WHERE id = :id')->execute(['q' => $qty, 'id' => $productId]);
            $pdo->prepare('UPDATE tt_ecount_purchases SET stock_moved = 1 WHERE id = :id')->execute(['id' => $purchaseId]);
        } catch (Throwable $e) { sg_error_log('[ecount_purchase stock+] ' . $e->getMessage()); }
    }
    $extra = $productId ? ' · 쇼핑몰 재고도 ' . $qty . '만큼 늘렸어요' : '';
    if ($r['ok']) return ['ok' => true, 'message' => '구매입고가 저장되고 ERP에 전송되었어요(ERP 재고 증가)' . $extra];
    return ['ok' => true, 'message' => '구매입고는 저장했지만 ERP 전송이 실패했어요: ' . $r['message'] . ' — 목록에서 "ERP 재전송"으로 다시 보낼 수 있어요' . $extra];
}

/** [v64] ERP 구매입력(SavePurchases) 전송 공통 — 신규 입력/재전송 공용 */
function ecount_push_purchase_to_erp(int $purchaseId, int $qty, int $unitPrice, string $ioDateYmd, string $custCd, string $custName, string $prodCd, string $prodDes, string $remarks): array
{
    $pdo = Database::connection();
    $whCd = get_setting('ecount_wh_cd', '');
    try {
        $api = EcountAPI::fromSettings();
        $supply = $unitPrice * $qty;
        $data = $api->savePurchases([[
            'VPM_LINE_SER_NO' => '1',
            'UPLOAD_SER_NO' => '1',
            'IO_DATE'    => $ioDateYmd,
            'CUST'       => $custCd,
            'CUST_DES'   => $custName,
            'WH_CD'      => $whCd,
            'PROD_CD'    => $prodCd,
            'PROD_DES'   => mb_substr($prodDes, 0, 100),
            'QTY'        => (string)$qty,
            'PRICE'      => (string)$unitPrice,
            'SUPPLY_AMT' => (string)$supply,
            'VAT_AMT'    => (string)(int)round($supply * 0.1),
            'REMARKS'    => $remarks,
        ]]);
        $okCnt = (int)($data['SuccessCnt'] ?? 0);
        if ($okCnt > 0) {
            $pdo->prepare("UPDATE tt_ecount_purchases SET erp_status='sent', erp_sent_at=NOW(), erp_error=NULL WHERE id=:id")->execute(['id' => $purchaseId]);
            ecount_log('push', 'purchase_entry', 'ok', '구매입고 전송 #' . $purchaseId . ' (' . $prodCd . ' x' . $qty . ')');
            return ['ok' => true, 'message' => '전송 성공'];
        }
        $details = is_string($data['ResultDetails'] ?? null) ? (string)$data['ResultDetails'] : json_encode($data['ResultDetails'] ?? [], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("UPDATE tt_ecount_purchases SET erp_status='fail', erp_error=:e WHERE id=:id")->execute(['e' => mb_substr($details, 0, 300), 'id' => $purchaseId]);
        ecount_log('push', 'purchase_entry', 'fail', '구매입고 전송 실패 #' . $purchaseId . ': ' . mb_substr($details, 0, 200));
        return ['ok' => false, 'message' => mb_substr($details, 0, 250)];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE tt_ecount_purchases SET erp_status='fail', erp_error=:e WHERE id=:id")->execute(['e' => mb_substr($e->getMessage(), 0, 300), 'id' => $purchaseId]);
        ecount_log('push', 'purchase_entry', 'fail', '구매입고 전송 실패 #' . $purchaseId . ': ' . $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/** [v64] 구매관리 재전송 — fail/skip/pending 행을 ERP 구매입력으로 보내기 */
function ecount_resend_purchase(int $purchaseId): array
{
    ensure_ecount_tables();
    $pdo = Database::connection();
    $st = $pdo->prepare('SELECT * FROM tt_ecount_purchases WHERE id = :id LIMIT 1');
    $st->execute(['id' => $purchaseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'message' => '구매입고 행을 찾지 못했습니다.'];
    $r = ecount_push_purchase_to_erp($purchaseId, (int)$row['qty'], (int)$row['unit_price'], (string)$row['io_date'], (string)$row['cust_cd'], (string)$row['cust_name'], (string)$row['prod_cd'], (string)$row['prod_des'],
        $row['source'] === 'worklog' ? '쇼핑몰 작업일지 구매 전송' : '쇼핑몰 구매입고 재전송');
    return $r['ok'] ? ['ok' => true, 'message' => 'ERP에 구매입력으로 전송했어요 (ERP 재고가 증가합니다).'] : ['ok' => false, 'message' => 'ERP 전송 실패: ' . $r['message']];
}

/** [v64] 작업일지 완료 → 구매관리 자동 적용 (단가=매입단가) + 사이트 재고 차감 / 진행중 복원 시 되돌리기 */
function ecount_apply_worklog_purchase(int $workLogId, bool $completed): array
{
    ensure_ecount_tables();
    $pdo = Database::connection();
    $lp = $pdo->prepare('SELECT * FROM tt_work_logs WHERE id = :id LIMIT 1');
    $lp->execute(['id' => $workLogId]);
    $log = $lp->fetch(PDO::FETCH_ASSOC);
    if (!$log) return ['ok' => false, 'message' => '작업일지를 찾지 못했습니다.'];

    $existing = $pdo->prepare('SELECT * FROM tt_ecount_purchases WHERE work_log_id = :id LIMIT 1');
    $existing->execute(['id' => $workLogId]);
    $prev = $existing->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$completed) {
        /* 진행중으로 되돌림 — 작업소모 항목 제거 + 차감했던 재고 복원 */
        if ($prev && $prev['source'] === 'worklog') {
            if ((int)$prev['stock_moved'] === 1 && $prev['product_id']) {
                try { $pdo->prepare('UPDATE tt_products SET stock = stock + :q WHERE id = :id')->execute(['q' => (int)$prev['qty'], 'id' => (int)$prev['product_id']]); }
                catch (Throwable $e) { sg_error_log('[ecount_worklog stock restore] ' . $e->getMessage()); }
            }
            $pdo->prepare('DELETE FROM tt_ecount_purchases WHERE id = :id')->execute(['id' => (int)$prev['id']]);
            return ['ok' => true, 'message' => '작업일지를 진행중으로 되돌렸어요 — 구매관리 자동 항목을 제거하고 차감한 재고를 복원했어요.'];
        }
        return ['ok' => true, 'message' => ''];
    }

    $productId = $log['product_id'] ? (int)$log['product_id'] : null;
    $prodCd = '';
    if ($productId) {
        $pp = $pdo->prepare('SELECT ecount_prod_cd FROM tt_products WHERE id = :id LIMIT 1');
        $pp->execute(['id' => $productId]);
        $cd = $pp->fetchColumn();
        if ($cd !== false && $cd !== null && (string)$cd !== '') $prodCd = (string)$cd;
    }
    $qty = max(1, (int)$log['qty']);
    $price = (int)$log['cost_supply'];
    $name = (string)($log['product_name_snap'] ?: '작업 상품');
    $ioDate = preg_replace('/[^0-9]/', '', (string)$log['work_date']);
    if (strlen($ioDate) !== 8) $ioDate = date('Ymd');
    $remark = '작업일지 #' . $workLogId . ' 완료 자동 (' . mb_substr((string)$log['work_content'], 0, 80) . ')';

    /* 사이트 재고 차감 — "재고가 있으면 차감" (첫 적용 때만; 재적용은 항목만 갱신해 이중 차감 방지) */
    $stockMoved = 0;
    if ($prev) {
        $stockMoved = (int)$prev['stock_moved'];
    } elseif ($productId) {
        try {
            $dec = $pdo->prepare('UPDATE tt_products SET stock = GREATEST(0, stock - :q) WHERE id = :id AND stock > 0');
            $dec->execute(['q' => $qty, 'id' => $productId]);
            $stockMoved = $dec->rowCount() > 0 ? 1 : 0;
        } catch (Throwable $e) { sg_error_log('[ecount_worklog stock-] ' . $e->getMessage()); }
    }

    if ($prev) {
        $pdo->prepare("UPDATE tt_ecount_purchases SET io_date=:d, prod_cd=:pc, prod_des=:pd, product_id=:pid, qty=:q, unit_price=:up, amt=:am, remark=:rk, erp_status='skip', erp_error=NULL, stock_moved=:sm WHERE id=:id")
            ->execute(['d' => $ioDate, 'pc' => $prodCd, 'pd' => mb_substr($name, 0, 150), 'pid' => $productId, 'q' => $qty, 'up' => $price, 'am' => $price * $qty, 'rk' => mb_substr($remark, 0, 200), 'sm' => $stockMoved, 'id' => (int)$prev['id']]);
    } else {
        $pdo->prepare('INSERT INTO tt_ecount_purchases (io_date, cust_cd, cust_name, prod_cd, prod_des, product_id, qty, unit_price, amt, remark, source, work_log_id, erp_status, stock_moved)
                        VALUES (:d, NULL, :cn, :pc, :pd, :pid, :q, :up, :am, :rk, "worklog", :wid, "skip", :sm)')
            ->execute(['d' => $ioDate, 'cn' => mb_substr((string)($log['customer_name'] ?: '작업 고객'), 0, 100), 'pc' => $prodCd, 'pd' => mb_substr($name, 0, 150),
                       'pid' => $productId, 'q' => $qty, 'up' => $price, 'am' => $price * $qty, 'rk' => mb_substr($remark, 0, 200), 'wid' => $workLogId, 'sm' => $stockMoved]);
    }
    $note = $productId ? ' · 사이트 재고 ' . $qty . ' 차감' : '';
    $prodNote = $prodCd !== '' ? ' (ERP 매핑:' . $prodCd . ')' : ' (ERP 미매핑 — 창고별 재고 화면에서 자동 매핑해 보세요)';
    return ['ok' => true, 'message' => '작업 완료 — 구매관리(작업 소모)에 자동 적용했어요: ' . mb_substr($name, 0, 30) . ' x' . $qty . ' · 단가 ' . number_format($price) . '원' . $prodNote . $note];
}

/** [v64] 미매핑 쇼핑몰 상품 ↔ ERP 품목 자동 매핑 — 정규화 이름이 정확히 1:1로 일치할 때만 */
function ecount_auto_map_products(): array
{
    ensure_ecount_tables();
    $pdo = Database::connection();
    $norm = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[\s\-\/\*\.\,\(\)\[\]ㆍ·:;_]+/u', '', $s);
        return (string)$s;
    };
    $items = $pdo->query('SELECT prod_cd, prod_des FROM tt_ecount_items')->fetchAll(PDO::FETCH_ASSOC);
    $byName = [];
    foreach ($items as $it) {
        $k = $norm((string)$it['prod_des']);
        if ($k === '') continue;
        $byName[$k][] = (string)$it['prod_cd'];
    }
    $used = [];
    foreach ($pdo->query("SELECT ecount_prod_cd FROM tt_products WHERE ecount_prod_cd IS NOT NULL AND ecount_prod_cd <> ''")->fetchAll(PDO::FETCH_COLUMN) as $u) $used[(string)$u] = true;
    $unmapped = $pdo->query("SELECT id, name FROM tt_products WHERE ecount_prod_cd IS NULL OR ecount_prod_cd = ''")->fetchAll(PDO::FETCH_ASSOC);
    $mapped = 0;
    $upd = $pdo->prepare('UPDATE tt_products SET ecount_prod_cd = :cd WHERE id = :id');
    foreach ($unmapped as $p) {
        $k = $norm((string)$p['name']);
        if ($k === '' || !isset($byName[$k]) || count($byName[$k]) !== 1) continue;
        $cd = $byName[$k][0];
        if (isset($used[$cd])) continue; /* 이미 다른 상품이 쓰는 코드 */
        $upd->execute(['cd' => $cd, 'id' => (int)$p['id']]);
        $used[$cd] = true;
        $mapped++;
    }
    ecount_log('sync', 'auto_map', 'ok', '자동 매핑 ' . $mapped . '건 (대상 미매핑 ' . count($unmapped) . '건)');
    return ['ok' => true, 'mapped' => $mapped, 'candidates' => count($unmapped),
            'message' => $mapped > 0 ? 'ERP 품목과 이름이 일치하는 상품 ' . $mapped . '건을 자동 매핑했어요.' : '이름이 정확히 일치하는 미매핑 상품이 없어요 (자동 매핑은 이름이 1:1로 같을 때만 돼요 — 나머지는 상품 수정에서 ERP 코드를 직접 연결해 주세요).'];
}

/** 발주서 조회 → 화면 표시용 rows 정규화 + (거래처 수집 옵션) */
function ecount_fetch_purchase_orders(string $fromYmd, string $toYmd, bool $collectPartners = false, int $maxPages = 3, bool $bypassGuard = false): array
{
    ensure_ecount_tables();
    /* [v59] bypassGuard=true — 년도 전체조회(자동 분할)용: 거친 간격 가드를 건너뛰고
     * 호출자(JS 순차+pacing)가 간격을 지배. 실패(Rate Limit) 페널티는 그대로 적용돼 안전. */
    if (!$bypassGuard && !ecount_rate_ok('ecount_last_orders_pull', ecount_pull_interval())) {
        return ['ok' => false, 'message' => '발주서(구매현황) 전체조회는 지금 설정 간격(' . ecount_pull_interval() . '초) 안에서 1회만 가능합니다. ' . ecount_rate_wait('ecount_last_orders_pull') . '초 후 재시도하세요. (이미 조회한 목록에서 거래처 검색·필터는 언제든 즉시 돼요)', 'rows' => [], 'total' => 0];
    }
    try {
        $api = EcountAPI::fromSettings();
        $out = [];
        $total = 0;
        $pdo = Database::connection();
        $upPartner = null;
        if ($collectPartners) {
            $upPartner = $pdo->prepare('INSERT INTO tt_ecount_partners (cust_cd, cust_name, source)
                                       VALUES (:cd, :nm, "orders")
                                       ON DUPLICATE KEY UPDATE cust_name=VALUES(cust_name)');
        }
        for ($page = 1; $page <= $maxPages; $page++) {
            if ($page === 1) {
                // [v54] 첫 호출 전에 가드 표시 — 실패해도 10분 백오프 (반복 재시도가 이카운트 연속 오류 제한에 걸리는 것 방지)
                ecount_rate_mark('ecount_last_orders_pull');
            }
            $res = $api->getPurchaseOrders($fromYmd, $toYmd, $page, 100);
            $total = max($total, (int)$res['total']);
            foreach ($res['rows'] as $r) {
                $out[] = $r;
                if ($upPartner) {
                    $cd = trim((string)($r['CUST'] ?? ''));
                    $nm = trim((string)($r['CUST_DES'] ?? ''));
                    if ($cd !== '' && $nm !== '') {
                        try { $upPartner->execute(['cd' => $cd, 'nm' => mb_substr($nm, 0, 100)]); } catch (Throwable $ignore) {}
                    }
                }
            }
            if (count($res['rows']) < 100) break;
        }
        ecount_log('pull', 'orders', 'ok', '발주서 ' . count($out) . '건 조회' . ($collectPartners ? ' · 거래처 수집' : ''));
        return ['ok' => true, 'message' => '발주서 ' . count($out) . '건을 조회했습니다.', 'rows' => $out, 'total' => $total];
    } catch (Throwable $e) {
        // [v57] 이카운트 Rate Limit(412/302) 실패 시 10분 페널티 백오프 — 짧은 간격 설정 방어
        if (stripos($e->getMessage(), 'Rate Limit') !== false) { ecount_rate_penalty('ecount_last_orders_pull'); }
        ecount_log('pull', 'orders', 'fail', $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage(), 'rows' => [], 'total' => 0];
    }
}

/** [v54] 구매현황(발주서) 행을 거래처 키워드(거래처명·거래처코드 부분일치, 대소문자 무시)로 필터링 */
function ecount_filter_po_rows(array $rows, string $keyword): array
{
    $kw = mb_strtolower(trim($keyword));
    if ($kw === '') {
        return $rows;
    }
    return array_values(array_filter($rows, static function (array $r) use ($kw): bool {
        $hay = mb_strtolower(trim((string)($r['CUST_DES'] ?? '')) . ' ' . trim((string)($r['CUST'] ?? '')));
        return mb_strpos($hay, $kw) !== false;
    }));
}

/** 로컬 상품 재고를 ERP 값으로 수동 반영 (재고이력 '조정' 기록) */
function ecount_apply_stock_to_local(int $productId, int $erpQty): array
{
    ensure_stock_movement_tables();
    $p = ecount_get_product($productId);
    if (!$p) return ['ok' => false, 'message' => '상품을 찾을 수 없습니다.'];
    $local = (int)$p['stock'];
    if ($erpQty === $local) return ['ok' => true, 'message' => '이미 ERP 재고(' . $erpQty . ')와 동일합니다.', 'changed' => false];
    Database::connection()->prepare('UPDATE tt_products SET stock = :st WHERE id = :id')
        ->execute(['st' => $erpQty, 'id' => $productId]);
    record_stock_movement($productId, null, 'adjust', $erpQty - $local, $erpQty,
        '이카운트 재고 수동 반영 (ERP ' . $erpQty . ' ← 로컬 ' . $local . ')', 'ecount-manual');
    ecount_log('pull', 'stock', 'ok', '상품 #' . $productId . ' 재고 수동 반영 (' . $local . '→' . $erpQty . ')');
    return ['ok' => true, 'message' => '재고가 ' . $local . ' → ' . $erpQty . ' 로 반영되었습니다.', 'changed' => true];
}

/** [v51] 연결 상태 요약 (대시보드·ajax 공용) */
function ecount_status_summary(): array
{
    ensure_ecount_tables();
    $pdo = Database::connection();
    $cfg = EcountAPI::configured();
    $summary = [
        'configured' => $cfg,
        'test_mode' => get_setting('ecount_test_mode', '0') === '1',
        'sync_enabled' => get_setting('ecount_sync_enabled', '1') === '1',
        'auto_push' => get_setting('ecount_auto_push', '1') === '1',
        'wh_cd' => get_setting('ecount_wh_cd', ''),
        'cust_cd' => get_setting('ecount_cust_cd', ''),
        'zone' => get_setting('ecount_zone', ''),
        'last_stock_sync' => (int)get_setting('ecount_last_stock_sync', '0'),
        'items_cached' => 0, 'partners_cached' => 0, 'stock_rows' => 0,
        'queue_pending' => 0, 'queue_done' => 0,
        'stock_pull_wait' => ecount_rate_wait('ecount_last_stock_pull'),
        'items_pull_wait' => ecount_rate_wait('ecount_last_items_pull'),
        'orders_pull_wait' => ecount_rate_wait('ecount_last_orders_pull'),
        'stock_total_pull_wait' => ecount_rate_wait('ecount_last_stock_total_pull'),
    ];
    try {
        $summary['items_cached'] = (int)$pdo->query('SELECT COUNT(*) FROM tt_ecount_items')->fetchColumn();
        $summary['partners_cached'] = (int)$pdo->query('SELECT COUNT(*) FROM tt_ecount_partners')->fetchColumn();
        $summary['stock_rows'] = (int)$pdo->query('SELECT COUNT(*) FROM tt_ecount_stock')->fetchColumn();
        $summary['stock_total_rows'] = (int)$pdo->query('SELECT COUNT(*) FROM tt_ecount_stock_total')->fetchColumn();
        $q = $pdo->query("SELECT status, COUNT(*) c FROM tt_ecount_queue GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $summary['queue_pending'] = (int)($q['pending'] ?? 0);
        $summary['queue_done'] = (int)($q['done'] ?? 0);
    } catch (Throwable $e) {
        error_log('[ecount_status_summary] ' . $e->getMessage());
    }
    return $summary;
}

/**
 * [v51] 주기 자동화 엔트리 — 관리자 대시보드 ajax(60초) 또는 호스팅 cron(예: 5분)이 호출.
 *  1) 보류 큐(결제완료 ERP 전송 실패분) 재시도
 *  2) 재고 자동 동기화가 켜져 있고 마지막 풀 후 10분 이상 지났으면 창고별 재고 풀 → 로컬 자동 반영
 */
function ecount_cron_run(bool $forceStock = false): array
{
    ensure_ecount_tables();
    $out = ['queue' => null, 'stock' => null, 'at' => date('Y-m-d H:i:s')];
    if (!EcountAPI::configured()) {
        $out['note'] = '이카운트 설정이 비어 있습니다.';
        return $out;
    }
    $out['queue'] = ecount_queue_process(10);
    $lastStock = (int)get_setting('ecount_last_stock_sync', '0');
    /* [v61] cron 자동 재고풀은 최소 10분 주기 고정 — 수동 조회 간격 설정(짧게 잡아도)을
     * 따라가면 대시보드 60초 핑이 ERP를 매분 호출해 관리자가 느려지고 오류 누적 위험이 생긴다 */
    if (get_setting('ecount_sync_enabled', '1') === '1' && ($forceStock || (time() - $lastStock) >= max(600, ecount_pull_interval()))) {
        $out['stock'] = ecount_pull_stock(true);
        // [v57] Rate Limit 실패 시 cron 쪽 간격도 10분 백오프 (짧은 간격 설정에서 반복 412 방어)
        if (($out['stock']['ok'] ?? true) === false && stripos((string)($out['stock']['message'] ?? ''), 'Rate Limit') !== false) {
            set_setting('ecount_last_stock_sync', (string)(time() + 600 - ecount_pull_interval()));
        }
    }
    return $out;
}
