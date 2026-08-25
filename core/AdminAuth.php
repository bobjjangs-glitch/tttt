<?php
declare(strict_types=1);

final class AdminAuth
{
    private const SESSION_KEY = 'tt_admin_id';

    /** 역할별 접근 가능한 모듈 목록. super는 항상 통과(별도 처리). */
    private const PERMISSIONS = [
        'product' => ['products', 'brands', 'category-icons', 'banners', 'coupons', 'stock-requests'],
        'order'   => ['orders', 'stock-requests'],
        'cs'      => ['reviews', 'notices', 'users'],
    ];

    public static function attemptLogin(string $username, string $password): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, password_hash, name, role, status FROM tt_admins WHERE username = :u LIMIT 1');
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

    /** 특정 모듈에 접근 가능한지 판별. super는 항상 true. */
    public static function can(string $module): bool
    {
        if (self::isSuper()) {
            return true;
        }
        $role = self::currentRole();
        return in_array($module, self::PERMISSIONS[$role] ?? [], true);
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
        unset($_SESSION[self::SESSION_KEY], $_SESSION['tt_admin_role'], $_SESSION['tt_admin_name']);
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
