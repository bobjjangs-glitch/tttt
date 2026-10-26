<?php
// ---------- 설정 / DB ----------
function cfg(string $key, $default = null)
{
    $v = $GLOBALS['CFG'] ?? [];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

/**
 * 쇼핑몰 DB와 테이블 이름이 겹치지 않도록 ERP 테이블에 erp_ 접두사를 붙이는 PDO.
 *
 * - TABLES 목록에 있는 이름만 변환한다. 앞뒤에 영문/숫자/밑줄/점/백틱이 붙은 이름
 *   (이미 erp_ 가 붙은 이름, uq_users_login 같은 인덱스명, 컬럼명)은 건드리지 않는다.
 * - 외래키 이름(CONSTRAINT fk_...)은 DB 전체에서 유일해야 하므로 erp_fk_ 로 바꾼다.
 * - 주의: 새 ERP 테이블을 만들면 반드시 TABLES 에 추가해야 한다.
 *   빠뜨리면 접두사가 붙지 않아 쇼핑몰 테이블과 충돌한다.
 */
final class ErpPdo extends PDO
{
    private const TABLES = [
        'users', 'categories', 'items', 'partners', 'doc_sequences',
        'trades', 'trade_lines', 'stock_ledger', 'audit_logs',
    ];

    public static function t(string $sql): string
    {
        $re = '/(?<![\w.`$])(' . implode('|', self::TABLES) . ')(?![\w`])/';
        $sql = preg_replace($re, 'erp_$1', $sql);
        return preg_replace('/\bCONSTRAINT(\s+)fk_/i', 'CONSTRAINT${1}erp_fk_', $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(self::t($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(self::t($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $q = self::t($query);
        return $fetchMode === null ? parent::query($q) : parent::query($q, $fetchMode, ...$fetchModeArgs);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=' . $c['charset'];
        $pdo = new ErpPdo($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ---------- 출력 / 요청 ----------
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function in_str(array $src, string $key, int $max = 255): string
{
    $v = $src[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim($v), 0, $max);
}

function in_int(array $src, string $key): int
{
    $v = $src[$key] ?? 0;
    return is_scalar($v) ? (int)$v : 0;
}

function valid_date(string $s): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

// ---------- CSRF / 플래시 ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('잘못된 요청입니다. 페이지를 새로고침한 뒤 다시 시도하세요.');
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $out .= '<div class="flash ' . h($f[0]) . '">' . h($f[1]) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

// ---------- 인증 / 권한 ----------
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid > 0) {
        $st = db()->prepare('SELECT id, login_id, name, role FROM users WHERE id = ? AND is_active = 1');
        $st->execute([$uid]);
        $u = $st->fetch();
        if ($u) {
            $cache = $u;
        }
    }
    return $cache;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

function can_write(): bool
{
    $u = current_user();
    return $u && in_array($u['role'], ['admin', 'office'], true);
}

function require_write(): void
{
    if (!can_write()) {
        http_response_code(403);
        exit('권한이 없습니다.');
    }
}

function audit(string $action, string $target = '', ?int $targetId = null, string $detail = ''): void
{
    $st = db()->prepare('INSERT INTO audit_logs (user_id, action, target, target_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,?)');
    $st->execute([
        $_SESSION['uid'] ?? null,
        $action,
        $target,
        $targetId,
        mb_substr($detail, 0, 500),
        $_SERVER['REMOTE_ADDR'] ?? '',
        date('Y-m-d H:i:s'),
    ]);
}

// ---------- 금액 / 수량 (정수 연산만 사용, float 금지) ----------
function parse_won($v): ?int
{
    $s = str_replace([',', ' '], '', (string)$v);
    if ($s === '') {
        return 0;
    }
    return preg_match('/^\d{1,12}$/', $s) ? (int)$s : null;
}

/** '4', '4.5', '1,000.125' -> 1/1000 단위 정수. 형식 오류면 null */
function qty_to_milli($v): ?int
{
    $s = str_replace([',', ' '], '', (string)$v);
    if (!preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/', $s, $m)) {
        return null;
    }
    $frac = isset($m[2]) ? str_pad($m[2], 3, '0', STR_PAD_RIGHT) : '000';
    return ((int)$m[1]) * 1000 + (int)$frac;
}

function milli_to_str(int $m): string
{
    $sign = $m < 0 ? '-' : '';
    $a = abs($m);
    return sprintf('%s%d.%03d', $sign, intdiv($a, 1000), $a % 1000);
}

function neg_dec(string $q): string
{
    return (isset($q[0]) && $q[0] === '-') ? substr($q, 1) : '-' . $q;
}

function fmt_qty($s): string
{
    $s = (string)$s;
    return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s;
}

function fmt_won($v): string
{
    return number_format((int)$v);
}

/** 라인 공급가액 = 수량 × 단가, 원 단위 반올림(0.5 올림). 양수 전용 */
function line_amount(int $qtyMilli, int $price): int
{
    return intdiv($qtyMilli * $price + 500, 1000);
}

/** 부가세 = 과세 공급가액의 10%, 원 단위 반올림. 세무 기준 반올림 방식은 세무사 확인 필요 */
function vat_of(int $taxableSupply): int
{
    return intdiv($taxableSupply + 5, 10);
}

// ---------- 사업자번호 / 타이어 규격 ----------
function is_valid_biz_no(string $d): bool
{
    if (!preg_match('/^\d{10}$/', $d)) {
        return false;
    }
    $w = [1, 3, 7, 1, 3, 7, 1, 3, 5];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$d[$i] * $w[$i];
    }
    $sum += intdiv((int)$d[8] * 5, 10);
    return ((10 - ($sum % 10)) % 10) === (int)$d[9];
}

function fmt_biz_no($d): string
{
    $d = (string)$d;
    return strlen($d) === 10 ? substr($d, 0, 3) . '-' . substr($d, 3, 2) . '-' . substr($d, 5) : $d;
}

/**
 * 검색용 규격 키 (205/55R16, 205 55 16, 2055516 -> 2055516).
 * 승용 규격만 처리하며 화물(7.00R16, 11R22.5)은 빈 문자열을 반환한다. 정답 검증이 아니라 검색 보조용이다.
 */
function normalize_tire_spec(string $raw): string
{
    $s = strtoupper(preg_replace('/\s+/', '', $raw));
    if (preg_match('/(\d{3})[\/\-]?(\d{2})(?:ZR|R|-)?(\d{2})/', $s, $m)) {
        $w = (int)$m[1];
        $a = (int)$m[2];
        $r = (int)$m[3];
        if ($w >= 125 && $w <= 355 && $a >= 25 && $a <= 85 && $a % 5 === 0 && $r >= 12 && $r <= 24) {
            return $m[1] . $m[2] . $m[3];
        }
    }
    return '';
}

// ---------- 채번 / 분류 / 라벨 ----------
/** 호출자가 연 트랜잭션 안에서 사용. 행 잠금으로 동시 채번을 직렬화한다. */
function next_no(string $prefix, string $date): int
{
    $pdo = db();
    $st = $pdo->prepare(
        'INSERT INTO doc_sequences (prefix, seq_date, last_no) VALUES (?, ?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)'
    );
    $st->execute([$prefix, $date]);
    return (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

/** id => '타이어 > 승용·SUV' (트리 순서) */
function category_paths(bool $onlyActive = true): array
{
    $rows = db()->query('SELECT id, parent_id, name, is_active FROM categories ORDER BY sort_order, id')->fetchAll();
    $kids = [];
    foreach ($rows as $r) {
        $kids[(int)$r['parent_id']][] = $r;
    }
    $out = [];
    $walk = function (int $parent, string $prefix, int $depth) use (&$walk, &$kids, &$out, $onlyActive) {
        if ($depth > 5 || empty($kids[$parent])) {
            return;
        }
        foreach ($kids[$parent] as $r) {
            if ($onlyActive && !(int)$r['is_active']) {
                continue;
            }
            $path = $prefix === '' ? $r['name'] : $prefix . ' > ' . $r['name'];
            $out[(int)$r['id']] = $path;
            $walk((int)$r['id'], $path, $depth + 1);
        }
    };
    $walk(0, '', 0);
    return $out;
}

function partner_label(array $p): string
{
    $tail = !empty($p['business_no']) ? fmt_biz_no($p['business_no']) : '#' . $p['id'];
    return $p['name'] . ' (' . $tail . ')';
}

function status_label(string $s): string
{
    $m = ['draft' => '임시', 'confirmed' => '확정', 'canceled' => '취소'];
    return $m[$s] ?? $s;
}

function channel_label($c): string
{
    $m = ['retail' => '소매', 'wholesale' => '도매'];
    return $m[(string)$c] ?? '-';
}
