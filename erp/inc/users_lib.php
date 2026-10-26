<?php
// inc/users_lib.php — 사용자·비밀번호 공통 로직. 테이블은 erp_users (erp_ 접두어 명시).
require_once __DIR__ . '/functions.php';

const PW_MIN = 10;
const PW_MAX_BYTES = 72;       // bcrypt 는 72바이트를 넘는 부분을 무시합니다
const PW_FAIL_LIMIT = 5;
const PW_FAIL_LOCK_SEC = 300;

/** code 1 = 현재 비밀번호 불일치 (실패 횟수 집계 대상) */
class UserLibError extends RuntimeException
{
}

/** erp_users 컬럼 정보: [소문자 컬럼명 => SHOW COLUMNS 행] */
function ul_columns(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cols = [];
    foreach (db()->query('SHOW COLUMNS FROM erp_users')->fetchAll() as $r) {
        $cols[strtolower((string)$r['Field'])] = $r;
    }
    return $cache = $cols;
}

/** 비밀번호 해시가 저장된 컬럼명. 못 찾으면 실제 컬럼 목록과 함께 예외. */
function ul_pw_col(): string
{
    $cols = ul_columns();
    foreach (['password_hash', 'password', 'passwd', 'pw_hash', 'pass_hash'] as $c) {
        if (isset($cols[$c])) {
            return $c;
        }
    }
    throw new UserLibError(
        'erp_users 에서 비밀번호 컬럼을 찾지 못했습니다. 실제 컬럼: ' . implode(', ', array_keys($cols))
        . ' — 이 목록을 알려 주시면 맞춰 드립니다.'
    );
}

/** 선택 가능한 권한 값. ENUM 이면 그 값을, 아니면 기본 3종. */
function ul_roles(): array
{
    $cols = ul_columns();
    $type = (string)($cols['role']['Type'] ?? '');
    if (preg_match('/^enum\((.*)\)$/i', $type, $m)) {
        $vals = array_values(array_filter(str_getcsv($m[1], ',', "'"), 'strlen'));
        if ($vals) {
            return $vals;
        }
    }
    return ['admin', 'office', 'viewer'];
}

function ul_role_label(string $role): string
{
    $map = ['admin' => '관리자', 'office' => '사무 (입력·수정)', 'viewer' => '조회 전용'];
    return $map[$role] ?? $role;
}

function ul_valid_login_id(string $s): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $s);
}

/** 비밀번호 규칙 검사. 오류 문장 배열을 돌려주며 비어 있으면 통과. */
function ul_validate_password(string $pw, string $loginId = ''): array
{
    $e = [];
    if (strpos($pw, "\0") !== false) {
        $e[] = '사용할 수 없는 문자가 포함되어 있습니다.';
    }
    if (mb_strlen($pw) < PW_MIN) {
        $e[] = '비밀번호는 ' . PW_MIN . '자 이상이어야 합니다.';
    }
    if (strlen($pw) > PW_MAX_BYTES) {
        $e[] = '비밀번호가 너무 깁니다. (영문·숫자 72자, 한글은 24자 이내)';
    }
    $kinds = 0;
    $kinds += preg_match('/[A-Za-z]/', $pw) ? 1 : 0;
    $kinds += preg_match('/\d/', $pw) ? 1 : 0;
    $kinds += preg_match('/[^A-Za-z\d]/u', $pw) ? 1 : 0;
    if ($kinds < 2) {
        $e[] = '영문, 숫자, 기호 중 2가지 이상을 섞어 주세요.';
    }
    if (preg_match('/^(.)\1+$/su', $pw)) {
        $e[] = '같은 문자만 반복할 수 없습니다.';
    }
    if ($loginId !== '' && stripos($pw, $loginId) !== false) {
        $e[] = '비밀번호에 아이디를 포함할 수 없습니다.';
    }
    return $e;
}

/** 임시 비밀번호 12자 (헷갈리는 문자 I, l, O, 0, 1 제외). 검사 규칙을 만족할 때까지 다시 뽑음. */
function ul_gen_password(): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alpha) - 1;
    for ($try = 0; $try < 50; $try++) {
        $pw = '';
        for ($i = 0; $i < 12; $i++) {
            $pw .= $alpha[random_int(0, $max)];
        }
        if (preg_match('/[A-Z]/', $pw) && preg_match('/[a-z]/', $pw) && preg_match('/\d/', $pw)) {
            return $pw;
        }
    }
    throw new RuntimeException('임시 비밀번호를 만들지 못했습니다.');
}

function ul_hash_ok(string $hash): bool
{
    $info = password_get_info($hash);
    return $info['algo'] !== null && $info['algo'] !== 0;
}

// ── 현재 비밀번호 오입력 제한 (세션 기준) ────────────────

function ul_throttle_wait(): int
{
    $f = $_SESSION['ul_pw_fail'] ?? null;
    if (!is_array($f) || (int)($f['n'] ?? 0) < PW_FAIL_LIMIT) {
        return 0;
    }
    $left = PW_FAIL_LOCK_SEC - (time() - (int)($f['t'] ?? 0));
    if ($left <= 0) {
        unset($_SESSION['ul_pw_fail']);
        return 0;
    }
    return $left;
}

function ul_throttle_fail(): void
{
    $f = $_SESSION['ul_pw_fail'] ?? ['n' => 0, 't' => 0];
    $_SESSION['ul_pw_fail'] = ['n' => (int)($f['n'] ?? 0) + 1, 't' => time()];
}

function ul_throttle_reset(): void
{
    unset($_SESSION['ul_pw_fail']);
}

// ── 본인 비밀번호 변경 ───────────────────────────────────

function ul_change_password(int $uid, string $current, string $new): void
{
    $pwCol = ul_pw_col();
    $st = db()->prepare("SELECT `$pwCol` FROM erp_users WHERE id = ?");
    $st->execute([$uid]);
    $hash = $st->fetchColumn();
    if (!is_string($hash) || $hash === '') {
        throw new UserLibError('계정 정보를 찾을 수 없습니다.');
    }
    if (!ul_hash_ok($hash)) {
        throw new UserLibError(
            '저장된 비밀번호가 PHP 표준 해시 형식이 아니라서 안전하게 바꿀 수 없습니다. 아무것도 변경하지 않았습니다. 관리자에게 문의하세요.'
        );
    }
    if (!password_verify($current, $hash)) {
        throw new UserLibError('현재 비밀번호가 맞지 않습니다.', 1);
    }
    $set = ["`$pwCol` = ?"];
    $args = [password_hash($new, PASSWORD_DEFAULT)];
    if (isset(ul_columns()['updated_at'])) {
        $set[] = '`updated_at` = ?';
        $args[] = date('Y-m-d H:i:s');
    }
    $args[] = $uid;
    db()->prepare('UPDATE erp_users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
}

// ── 관리자: 사용자 조회 / 추가 / 수정 / 재발급 ─────────────

function ul_fetch_users(): array
{
    $cols = ul_columns();
    $sel = ['id', 'login_id', 'name', 'role', 'is_active'];
    if (isset($cols['created_at'])) {
        $sel[] = 'created_at';
    }
    return db()->query(
        'SELECT ' . implode(', ', $sel) . ' FROM erp_users ORDER BY is_active DESC, id ASC LIMIT 200'
    )->fetchAll();
}

function ul_active_admin_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM erp_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
}

/** 새 사용자 추가. 모르는 필수 컬럼이 있으면 시도하지 않고 예외. 새 id 반환. */
function ul_insert_user(string $loginId, string $name, string $role, string $plainPw): int
{
    $cols = ul_columns();
    $pwCol = ul_pw_col();
    $now = date('Y-m-d H:i:s');

    $ins = ['login_id' => $loginId, 'name' => $name, 'role' => $role, $pwCol => password_hash($plainPw, PASSWORD_DEFAULT)];
    if (isset($cols['is_active'])) {
        $ins['is_active'] = 1;
    }
    foreach (['created_at', 'updated_at'] as $c) {
        if (isset($cols[$c])) {
            $ins[$c] = $now;
        }
    }
    foreach ($cols as $cname => $c) {
        if ($cname === 'id' || array_key_exists($cname, $ins)) {
            continue;
        }
        $extra = strtolower((string)($c['Extra'] ?? ''));
        $needs = strtoupper((string)($c['Null'] ?? '')) === 'NO'
            && $c['Default'] === null
            && strpos($extra, 'auto_increment') === false
            && strpos($extra, 'generated') === false;
        if ($needs) {
            throw new UserLibError('erp_users 에 제가 모르는 필수 컬럼이 있어 추가하지 않았습니다: ' . $cname . ' — 알려 주시면 맞춰 드립니다.');
        }
    }

    $names = array_keys($ins);
    $sql = 'INSERT INTO erp_users (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
    try {
        db()->prepare($sql)->execute(array_values($ins));
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new UserLibError('이미 사용 중인 아이디입니다.');
        }
        throw $e;
    }
    return (int)db()->lastInsertId();
}

/** 이름·권한·사용 여부 수정. 본인 강등과 마지막 관리자 제거는 막는다. */
function ul_update_user(int $actorId, int $id, string $name, string $role, int $active): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, role, is_active FROM erp_users WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur) {
            throw new UserLibError('사용자를 찾을 수 없습니다.');
        }
        $wasAdmin = $cur['role'] === 'admin' && (int)$cur['is_active'] === 1;
        $willAdmin = $role === 'admin' && $active === 1;

        if ($id === $actorId && !$willAdmin) {
            throw new UserLibError('본인 계정의 관리자 권한과 사용 상태는 바꿀 수 없습니다.');
        }
        if ($wasAdmin && !$willAdmin) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM erp_users WHERE role = 'admin' AND is_active = 1 AND id <> ? FOR UPDATE");
            $c->execute([$id]);
            if ((int)$c->fetchColumn() < 1) {
                throw new UserLibError('마지막 관리자는 강등하거나 비활성화할 수 없습니다. 먼저 다른 관리자를 만드세요.');
            }
        }

        $set = ['`name` = ?', '`role` = ?', '`is_active` = ?'];
        $args = [$name, $role, $active];
        if (isset(ul_columns()['updated_at'])) {
            $set[] = '`updated_at` = ?';
            $args[] = date('Y-m-d H:i:s');
        }
        $args[] = $id;
        $pdo->prepare('UPDATE erp_users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** 관리자가 임시 비밀번호로 재설정. 로그인 아이디 반환. */
function ul_reset_password(int $id, string $plainPw): string
{
    $pwCol = ul_pw_col();
    $st = db()->prepare('SELECT login_id FROM erp_users WHERE id = ?');
    $st->execute([$id]);
    $login = $st->fetchColumn();
    if (!is_string($login)) {
        throw new UserLibError('사용자를 찾을 수 없습니다.');
    }
    $set = ["`$pwCol` = ?"];
    $args = [password_hash($plainPw, PASSWORD_DEFAULT)];
    if (isset(ul_columns()['updated_at'])) {
        $set[] = '`updated_at` = ?';
        $args[] = date('Y-m-d H:i:s');
    }
    $args[] = $id;
    db()->prepare('UPDATE erp_users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    return $login;
}
