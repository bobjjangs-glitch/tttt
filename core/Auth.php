<?php
declare(strict_types=1);

final class Auth
{
    public const SESSION_KEY = 'tt_user_id';
    private const MAX_FAIL    = 5;
    private const LOCK_MIN    = 15;

    public static function attemptLogin(string $email, string $password): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, password_hash, name, status, login_fail_cnt, locked_until
                                FROM tt_users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['ok' => false, 'msg' => '이메일 또는 비밀번호가 올바르지 않습니다.'];
        }
        if ($user['status'] !== 'active') {
            return ['ok' => false, 'msg' => '이용이 제한된 계정입니다. 고객센터에 문의해주세요.'];
        }
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return ['ok' => false, 'msg' => '로그인 시도가 많아 계정이 잠겼습니다. 잠시 후 다시 시도해주세요.'];
        }
        if (!password_verify($password, $user['password_hash'])) {
            self::increaseFailCount($pdo, (int)$user['id'], (int)$user['login_fail_cnt']);
            return ['ok' => false, 'msg' => '이메일 또는 비밀번호가 올바르지 않습니다.'];
        }

        $pdo->prepare('UPDATE tt_users SET login_fail_cnt = 0, locked_until = NULL,
                       last_login_at = NOW() WHERE id = :id')->execute(['id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int)$user['id'];

        return ['ok' => true];
    }

    private static function increaseFailCount(PDO $pdo, int $userId, int $currentFail): void
    {
        $fail = $currentFail + 1;
        $lockUntil = $fail >= self::MAX_FAIL
            ? date('Y-m-d H:i:s', time() + self::LOCK_MIN * 60)
            : null;
        $pdo->prepare('UPDATE tt_users SET login_fail_cnt = :fail, locked_until = :lock WHERE id = :id')
            ->execute(['fail' => $fail, 'lock' => $lockUntil, 'id' => $userId]);
    }

    public static function currentUserId(): ?int { return $_SESSION[self::SESSION_KEY] ?? null; }
    public static function isLoggedIn(): bool { return self::currentUserId() !== null; }
    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }
}
