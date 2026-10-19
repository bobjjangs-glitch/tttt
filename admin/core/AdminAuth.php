<?php
declare(strict_types=1);

final class AdminAuth
{
    private const SESSION_KEY = 'tt_admin_id';

    public static function attemptLogin(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'msg' => '아이디와 비밀번호를 입력해 주세요.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, password_hash, name, role, status FROM tt_admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return ['ok' => false, 'msg' => '아이디 또는 비밀번호가 올바르지 않습니다.'];
        }

        if (($admin['status'] ?? 'active') !== 'active') {
            return ['ok' => false, 'msg' => '비활성화된 관리자 계정입니다. 최고관리자에게 문의해 주세요.'];
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY]  = (int)$admin['id'];
        $_SESSION['tt_admin_role']    = $admin['role'];
        $_SESSION['tt_admin_name']    = $admin['name'];

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $pdo->prepare('UPDATE tt_admins SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id')
            ->execute(['ip' => $ip, 'id' => (int)$admin['id']]);

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
        return $_SESSION['tt_admin_role'] ?? 'staff';
    }

    public static function isSuper(): bool
    {
        return self::currentRole() === 'super';
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
            flash('admin_error', '최고관리자만 접근할 수 있는 페이지입니다.');
            redirect('/admin/index.php');
        }
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION['tt_admin_role'], $_SESSION['tt_admin_name']);
        session_regenerate_id(true);
    }

    public static function log(int $adminId, string $action, string $memo = ''): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO tt_admin_logs (admin_id, action, memo, created_at) VALUES (:aid, :act, :memo, NOW())')
            ->execute(['aid' => $adminId, 'act' => $action, 'memo' => $memo]);
    }
}
