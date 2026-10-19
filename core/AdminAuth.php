<?php
declare(strict_types=1);

final class AdminAuth
{
    private const SESSION_KEY = 'tt_admin_id';

    /** 관리자 메뉴(모듈) 목록 — 관리자 계정 관리에서 메뉴별 권한 지정에 사용.
     *  사이드바의 각 메뉴가 이 키 하나씩과 1:1로 대응한다. */
    public const MODULES = [
        'orders'            => '주문 관리',
        'business-orders'   => '사업자 주문 관리',
        'reservations'      => '예약 관리 · 리마인더',
        'tax-invoices'      => '세금계산서 관리',
        'products'          => '상품 관리 · 일괄 업로드',
        'brands'            => '브랜드 관리',
        'stock-movements'   => '재고 입출고 이력',
        'stock-requests'    => '재고 요청 관리',
        'coupons'           => '쿠폰 관리 · 혜택 설정',
        'reviews'           => '리뷰 관리',
        'banners'           => '홈 화면 · 팝업 광고',
        'shipping'          => '배송비 설정',
        'biz-pricing'       => '사업자가 설정',
        'work-logs'         => '작업일지 관리',
        'workers'           => '작업자 관리',
        'daily-settlement'  => '일정산 마감',
        'monthly-settlement'=> '월간 정산 · 인센티브',
        'card-fees'         => '카드 수수료 설정',
        'wholesale-sales'   => '도매매출 관리 (일반 도매)',
        'users'             => '회원 관리',
        'vehicles'          => '차량 교체주기 관리',
        'business-approvals'=> '사업자 승인',
        'inquiries'         => '1:1 문의 관리',
        'chatbot'           => 'AI 챗봇 관리',
    ];

    /** 역할별 기본 접근 모듈. super는 항상 통과(별도 처리).
     *  관리자 계정에 메뉴 권한을 직접 지정(tt_admins.permissions)하면 역할 기본값 대신 그 목록만 적용된다. */
    private const PERMISSIONS = [
        'product' => ['products', 'brands', 'stock-movements', 'stock-requests', 'coupons', 'banners', 'shipping', 'biz-pricing'],
        'order'   => ['orders', 'business-orders', 'reservations', 'tax-invoices', 'work-logs', 'workers', 'daily-settlement', 'monthly-settlement', 'card-fees', 'wholesale-sales', 'stock-requests', 'shipping', 'biz-pricing'],
        'cs'      => ['reviews', 'users', 'vehicles', 'business-approvals', 'inquiries', 'chatbot'],
    ];

    public static function attemptLogin(string $username, string $password): array
    {
        $pdo = Database::connection();
        self::ensure_permissions_column($pdo);
        $stmt = $pdo->prepare('SELECT id, password_hash, name, role, status, permissions FROM tt_admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return ['ok' => false, 'msg' => '아이디 또는 비밀번호가 일치하지 않습니다.'];
        }

        if ($admin['status'] !== 'active') {
            return ['ok' => false, 'msg' => '비활성화된 계정입니다. 관리자에게 문의하세요.'];
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int)$admin['id'];
        $_SESSION['tt_admin_role']   = $admin['role'];
        $_SESSION['tt_admin_name']   = $admin['name'];
        /* 메뉴별 권한 직접 지정이 있으면 그 목록, 없으면 null(역할 기본값 사용) */
        $_SESSION['tt_admin_perms']  = self::decode_permissions($admin['permissions'] ?? null);

        $pdo->prepare('UPDATE tt_admins SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $admin['id']]);

        self::log((int)$admin['id'], 'login', '관리자 로그인');

        return ['ok' => true];
    }

    public static function currentAdminId(): ?int { return $_SESSION[self::SESSION_KEY] ?? null; }
    public static function isLoggedIn(): bool { return self::currentAdminId() !== null; }

    public static function currentAdminName(): string
    {
        return $_SESSION['tt_admin_name'] ?? '관리자';
    }

    public static function currentRole(): string
    {
        return $_SESSION['tt_admin_role'] ?? 'cs';
    }

    public static function isSuper(): bool
    {
        return self::currentRole() === 'super';
    }

    /** 특정 모듈에 접근 가능한지 판별.
     *  super는 항상 true. 메뉴 권한을 직접 지정받은 관리자는 그 메뉴만, 아니면 역할 기본값. */
    public static function can(string $module): bool
    {
        if (self::isSuper()) {
            return true;
        }
        $perms = $_SESSION['tt_admin_perms'] ?? null;
        if (is_array($perms)) {
            return in_array($module, $perms, true);
        }
        $role = self::currentRole();
        return in_array($module, self::PERMISSIONS[$role] ?? [], true);
    }

    /** 현재 로그인 관리자의 유효(적용 중) 메뉴 목록 — 사이드바·권한 표시용 */
    public static function effectivePermissions(): array
    {
        if (self::isSuper()) {
            return array_keys(self::MODULES);
        }
        $perms = $_SESSION['tt_admin_perms'] ?? null;
        if (is_array($perms)) {
            return array_values(array_intersect($perms, array_keys(self::MODULES)));
        }
        return self::PERMISSIONS[self::currentRole()] ?? [];
    }

    /** tt_admins.permissions 컬럼 자동 보강 (TEXT, JSON 배열 | NULL=역할 기본값) */
    public static function ensure_permissions_column(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM tt_admins')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('permissions', $cols, true)) {
                $pdo->exec("ALTER TABLE tt_admins ADD COLUMN permissions TEXT NULL COMMENT '메뉴별 권한(JSON 배열, NULL=역할 기본값)' AFTER role");
            }
        } catch (Throwable $e) {
            error_log('[ensure_permissions_column] ' . $e->getMessage());
        }
    }

    /** permissions 컬럼값 해석 — '["a","b"]' → 배열, null/빈 값 → null(기본값) */
    public static function decode_permissions(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_intersect($decoded, array_keys(self::MODULES))) : null;
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            redirect('/admin/login.php');
        }
    }

    public static function requireSuper(): void
    {
        self::requireLogin();
        if (!self::isSuper()) {
            flash('admin_error', '최고관리자만 접근할 수 있습니다.');
            redirect('/admin/index.php');
        }
    }

    /** 각 admin/*.php 상단에 넣는 표준 권한 검증 진입점 */
    public static function requirePermission(string $module): void
    {
        self::requireLogin();
        if (!self::can($module)) {
            flash('admin_error', '이 화면에 접근할 권한이 없습니다.');
            redirect('/admin/index.php');
        }
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION['tt_admin_role'], $_SESSION['tt_admin_name'], $_SESSION['tt_admin_perms']);
        session_regenerate_id(true);
    }

    /**
     * [FIX] 로그 기록 실패가 본 기능(로그인, 상태 변경 등)을 절대 막아서는 안 되므로
     * try/catch로 감싸고, tt_admin_logs 테이블이 없으면 자동으로 생성한다.
     * 예전에는 이 테이블을 만드는 코드가 어디에도 없어서, 테이블이 없는 서버에서는
     * 관리자 액션 시점에 500 오류가 발생했다.
     */
    public static function log(int $adminId, string $action, string $memo = ''): void
    {
        try {
            $pdo = Database::connection();
            ensure_admin_logs_table($pdo);
            $pdo->prepare('INSERT INTO tt_admin_logs (admin_id, action, memo, created_at) VALUES (:aid, :act, :memo, NOW())')
                ->execute(['aid' => $adminId, 'act' => $action, 'memo' => $memo]);
        } catch (Throwable $e) {
            error_log('[AdminAuth::log] ' . $e->getMessage());
        }
    }
}
