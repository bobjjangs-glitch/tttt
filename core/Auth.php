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
        $stmt = $pdo->prepare('SELECT id, password_hash, name, status, user_type, biz_status, login_fail_cnt, locked_until
                                FROM tt_users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['ok' => false, 'msg' => '이메일 또는 비밀번호가 올바르지 않습니다.'];
        }
        if ($user['status'] !== 'active') {
            return ['ok' => false, 'msg' => '이용이 제한된 계정입니다. 고객센터에 문의해주세요.'];
        }
        // 사업자 회원은 승인 상태 확인
        $bizStatus = $user['biz_status'] ?? 'approved';
        if (($user['user_type'] ?? 'personal') === 'business' && $bizStatus === 'pending') {
            return ['ok' => false, 'msg' => '사업자 회원가입 승인 대기 중입니다. 관리자 승인 후 로그인 가능합니다.'];
        }
        if (($user['user_type'] ?? 'personal') === 'business' && $bizStatus === 'rejected') {
            return ['ok' => false, 'msg' => '사업자 회원가입이 반려되었습니다. 고객센터에 문의해주세요.'];
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
        $_SESSION['tt_user_type']    = $user['user_type'] ?? 'personal';
        $_SESSION['tt_user_name']    = $user['name'] ?? '';

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

    /** 현재 로그인한 사용자의 user_type 반환 ('personal' | 'business') */
    public static function currentUserType(): string
    {
        // 세션에 없으면 DB에서 조회
        if (isset($_SESSION['tt_user_type'])) {
            return $_SESSION['tt_user_type'];
        }
        $uid = self::currentUserId();
        if ($uid === null) return 'personal';
        try {
            $stmt = Database::connection()->prepare('SELECT user_type FROM tt_users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $uid]);
            $type = $stmt->fetchColumn();
            $_SESSION['tt_user_type'] = $type ?: 'personal';
            return $_SESSION['tt_user_type'];
        } catch (Throwable $e) {
            return 'personal';
        }
    }

    /** 사업자 회원인지 확인 */
    public static function isBusiness(): bool
    {
        return self::currentUserType() === 'business';
    }

    /** 일반 회원인지 확인 */
    public static function isPersonal(): bool
    {
        return self::currentUserType() === 'personal';
    }

    /** 현재 사용자의 표시명 */
    public static function currentUserName(): string
    {
        return $_SESSION['tt_user_name'] ?? '';
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION['tt_user_type'], $_SESSION['tt_user_name']);
        session_regenerate_id(true);
    }
}
