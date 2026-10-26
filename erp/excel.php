<?php
// excel.php - 거래처/품목 엑셀 일괄 업로드·다운로드 (미실행 코드)
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_write();
$me = current_user();
if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 사용할 수 있습니다.');
}
require_once __DIR__ . '/inc/excel_io.php';
$pdo = db();

$TARGETS = ['partners' => '거래처', 'items' => '품목'];

/* ---------- 열 정의 (첫 라벨 = 내려받기 헤더, 나머지는 인식용 별칭) ---------- */
function xl_spec(string $target): array
{
    if ($target === 'partners') {
        return [
            'name' => ['상호'], 'business_no' => ['사업자번호'], 'branch_no' => ['종사업장번호'],
            'ceo_name' => ['대표자'], 'phone' => ['전화'], 'tax_email' => ['세금계산서 이메일', '이메일'],
            'address' => ['주소'], 'partner_type' => ['유형'], 'default_channel' => ['기본매출구분', '기본구분'],
            'memo' => ['메모'], 'is_active' => ['사용'],
        ];
    }
    return [
        'item_code' => ['품목코드'], 'name' => ['품명'], 'category' => ['분류'], 'brand' => ['브랜드'],
        'pattern_name' => ['패턴'], 'spec' => ['규격'], 'unit' => ['단위'], 'tax_type' => ['과세구분'],
        'retail_price' => ['소매가'], 'wholesale_price' => ['도매가'], 'last_cost' => ['최근매입가'],
        'is_stock_item' => ['재고관리'], 'is_active' => ['사용'],
    ];
}
function xl_required(string $target): array
{
    return $target === 'partners' ? ['name'] : ['item_code', 'name'];
}
function xl_partner_types(): array { return ['customer' => '매출처', 'supplier' => '매입처', 'both' => '매출·매입']; }
function xl_channels(): array      { return ['retail' => '소매', 'wholesale' => '도매']; }
function xl_tax_types(): array     { return ['taxable' => '과세', 'exempt' => '면세']; }

/** 라벨 또는 코드 → 코드. 해당 없으면 null */
function xl_pick(array $map, string $v): ?string
{
    if (isset($map[$v])) {
        return $v;
    }
    $k = array_search($v, $map, true);
    return $k === false ? null : (string)$k;
}
function xl_norm_path(string $p): string
{
    return preg_replace('/\s*>\s*/u', ' > ', trim($p)) ?? $p;
}
function xl_trim(string $s): string
{
    return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $s) ?? $s;
}

/* ---------- 내려받기 ---------- */
function xl_export_rows(PDO $pdo, string $target, bool $headerOnly): array
{
    $rows = [array_map(function ($labels) { return $labels[0]; }, array_values(xl_spec($target)))];
    if ($headerOnly) {
        return $rows;
    }
    if ($target === 'partners') {
        $types = xl_partner_types();
        $chs   = xl_channels();
        $st = $pdo->query('SELECT name, business_no, branch_no, ceo_name, phone, tax_email, address,
                                  partner_type, default_channel, memo, is_active
                           FROM partners ORDER BY id');
        foreach ($st as $r) {
            $rows[] = [
                (string)$r['name'],
                ($r['business_no'] ?? '') === '' ? '' : fmt_biz_no((string)$r['business_no']),
                (string)$r['branch_no'], (string)$r['ceo_name'], (string)$r['phone'],
                (string)$r['tax_email'], (string)$r['address'],
                $types[$r['partner_type']] ?? (string)$r['partner_type'],
                $chs[$r['default_channel']] ?? (string)$r['default_channel'],
                (string)$r['memo'], $r['is_active'] ? 'Y' : 'N',
            ];
        }
        return $rows;
    }
    $paths = category_paths(false);
    $taxes = xl_tax_types();
    $st = $pdo->query('SELECT item_code, name, category_id, brand, pattern_name, spec, unit, tax_type,
                              retail_price, wholesale_price, last_cost, is_stock_item, is_active
                       FROM items ORDER BY item_code');
    foreach ($st as $r) {
        $rows[] = [
            (string)$r['item_code'], (string)$r['name'],
            $r['category_id'] === null ? '' : (string)($paths[(int)$r['category_id']] ?? ''),
            (string)$r['brand'], (string)$r['pattern_name'], (string)$r['spec'], (string)$r['unit'],
            $taxes[$r['tax_type']] ?? (string)$r['tax_type'],
            (int)$r['retail_price'], (int)$r['wholesale_price'], (int)$r['last_cost'],
            $r['is_stock_item'] ? 'Y' : 'N', $r['is_active'] ? 'Y' : 'N',
        ];
    }
    return $rows;
}

/* ---------- 행 검증 ---------- */
function xl_parse_partner(callable $get): array
{
    $rec = [];
    $err = [];
    $name = $get('name');
    if ($name === '') {
        $err[] = '상호가 비어 있습니다.';
    } elseif (mb_strlen($name) > 100) {
        $err[] = '상호는 100자 이하여야 합니다.';
    }
    $rec['name'] = $name;

    $bizRaw = $get('business_no');
    $biz    = preg_replace('/\D/', '', $bizRaw) ?? '';
    if ($biz !== '' && !is_valid_biz_no($biz)) {
        $err[] = '사업자번호가 올바르지 않습니다(체크섬 불일치): ' . $bizRaw;
    }
    $branch = preg_replace('/\D/', '', $get('branch_no')) ?? '';
    if (strlen($branch) > 4) {
        $err[] = '종사업장번호는 숫자 4자리 이하여야 합니다.';
    }
    if ($biz === '' && $branch !== '') {
        $err[] = '종사업장번호는 사업자번호와 함께 입력해야 합니다.';
    }
    $rec['business_no'] = $biz;
    $rec['branch_no']   = $branch;

    $opt = ['ceo_name' => ['대표자', 50], 'phone' => ['전화', 30], 'tax_email' => ['세금계산서 이메일', 100],
            'address' => ['주소', 200], 'memo' => ['메모', 500]];
    foreach ($opt as $k => [$label, $max]) {
        $v = $get($k);
        if (mb_strlen($v) > $max) {
            $err[] = $label . '은(는) ' . $max . '자 이하여야 합니다.';
        }
        if ($v !== '') {
            $rec[$k] = $v;
        }
    }
    if (isset($rec['tax_email']) && !filter_var($rec['tax_email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = '세금계산서 이메일 형식이 올바르지 않습니다: ' . $rec['tax_email'];
    }
    $t = $get('partner_type');
    if ($t !== '') {
        $p = xl_pick(xl_partner_types(), $t);
        if ($p === null) { $err[] = '유형은 매출처/매입처/매출·매입 중 하나여야 합니다: ' . $t; } else { $rec['partner_type'] = $p; }
    }
    $c = $get('default_channel');
    if ($c !== '') {
        $p = xl_pick(xl_channels(), $c);
        if ($p === null) { $err[] = '기본매출구분은 소매/도매 중 하나여야 합니다: ' . $c; } else { $rec['default_channel'] = $p; }
    }
    $a = xl_yn($get('is_active'));
    if ($a === false) { $err[] = '사용 칸은 Y 또는 N이어야 합니다.'; } elseif ($a !== null) { $rec['is_active'] = $a; }
    return [$rec, $err];
}

function xl_parse_item(callable $get, array $catMap): array
{
    $rec = [];
    $err = [];
    $code = $get('item_code');
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,40}\z/', $code)) {
        $err[] = '품목코드는 영문·숫자·_ . - 만 1~40자까지 가능합니다: ' . ($code === '' ? '(비어 있음)' : $code);
    }
    $rec['item_code'] = $code;

    $name = $get('name');
    if ($name === '') { $err[] = '품명이 비어 있습니다.'; }
    $rec['name'] = $name;

    $txt = ['name' => ['품명', 200], 'brand' => ['브랜드', 50], 'pattern_name' => ['패턴', 80],
            'spec' => ['규격', 40], 'unit' => ['단위', 10]];
    foreach ($txt as $k => [$label, $max]) {
        $v = $get($k);
        if (mb_strlen($v) > $max) {
            $err[] = $label . '은(는) ' . $max . '자 이하여야 합니다.';
        }
        if ($v !== '' && $k !== 'name') {
            $rec[$k] = $v;
        }
    }
    if (isset($rec['spec'])) {
        $rec['spec_code'] = substr((string)normalize_tire_spec($rec['spec']), 0, 12);
    }
    $cat = $get('category');
    if ($cat !== '') {
        $k = xl_norm_path($cat);
        if (isset($catMap[$k])) { $rec['category_id'] = $catMap[$k]; } else { $err[] = '분류를 찾을 수 없습니다(전체 경로로 입력, 사용 중인 분류만): ' . $cat; }
    }
    $tx = $get('tax_type');
    if ($tx !== '') {
        $p = xl_pick(xl_tax_types(), $tx);
        if ($p === null) { $err[] = '과세구분은 과세/면세 중 하나여야 합니다: ' . $tx; } else { $rec['tax_type'] = $p; }
    }
    foreach (['retail_price' => '소매가', 'wholesale_price' => '도매가', 'last_cost' => '최근매입가'] as $k => $label) {
        $v = xl_int($get($k));
        if ($v === false) { $err[] = $label . '는 0 이상의 정수여야 합니다: ' . $get($k); } elseif ($v !== null) { $rec[$k] = $v; }
    }
    foreach (['is_stock_item' => '재고관리', 'is_active' => '사용'] as $k => $label) {
        $v = xl_yn($get($k));
        if ($v === false) { $err[] = $label . ' 칸은 Y 또는 N이어야 합니다.'; } elseif ($v !== null) { $rec[$k] = $v; }
    }
    return [$rec, $err];
}

/* ---------- 저장 ---------- */
function xl_insert(PDO $pdo, string $table, array $rec): void
{
    $now = date('Y-m-d H:i:s');
    $rec['created_at'] = $now;
    $rec['updated_at'] = $now;
    $cols = array_keys($rec);
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($rec));
}
function xl_update(PDO $pdo, string $table, int $id, array $rec): void
{
    $rec['updated_at'] = date('Y-m-d H:i:s');
    $sets = [];
    foreach (array_keys($rec) as $c) {
        $sets[] = $c . '=?';
    }
    $args = array_values($rec);
    $args[] = $id;
    $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE id=?')->execute($args);
}
function xl_save_partner(PDO $pdo, int $id, array $rec): void
{
    if ($id > 0) {
        unset($rec['business_no'], $rec['branch_no']);   // 매칭 키는 수정하지 않음
        xl_update($pdo, 'partners', $id, $rec);
        return;
    }
    $rec['business_no'] = ($rec['business_no'] === '') ? null : $rec['business_no'];
    xl_insert($pdo, 'partners', array_merge([
        'ceo_name' => '', 'phone' => '', 'tax_email' => '', 'address' => '', 'memo' => '',
        'partner_type' => 'both', 'default_channel' => 'wholesale', 'is_active' => 1,
    ], $rec));
}
function xl_save_item(PDO $pdo, int $id, array $rec): void
{
    if ($id > 0) {
        unset($rec['item_code']);
        xl_update($pdo, 'items', $id, $rec);
        return;
    }
    xl_insert($pdo, 'items', array_merge([
        'category_id' => null, 'brand' => '', 'pattern_name' => '', 'spec' => '', 'spec_code' => '',
        'unit' => '개', 'tax_type' => 'taxable', 'retail_price' => 0, 'wholesale_price' => 0,
        'last_cost' => 0, 'is_stock_item' => 1, 'is_active' => 1,
    ], $rec));
}

/* ---------- 업로드 처리 ---------- */
function xl_process(PDO $pdo, string $target, array $file, bool $dry): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('파일 업로드에 실패했습니다. (코드 ' . (int)($file['error'] ?? -1) . ') 파일을 선택했는지, 서버 업로드 용량 제한을 확인하세요.');
    }
    if ((int)$file['size'] > XL_MAX_BYTES) {
        throw new RuntimeException('파일은 5MB 이하만 올릴 수 있습니다.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('올바른 업로드 파일이 아닙니다.');
    }
    $ext  = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $rows = xl_read_rows((string)$file['tmp_name'], $ext);
    if (count($rows) < 2) {
        throw new RuntimeException('첫 행에 제목(헤더)이 있고 그 아래에 데이터가 있어야 합니다.');
    }
    $hNo    = array_key_first($rows);
    $header = $rows[$hNo];
    unset($rows[$hNo]);

    [$map, $unknown, $missing] = xl_map_header($header, xl_spec($target), xl_required($target));
    if ($missing) {
        throw new RuntimeException('필수 열이 없습니다: ' . implode(', ', $missing) . ' (내려받은 양식의 첫 행을 그대로 쓰세요)');
    }

    $byKey = [];
    $byName = [];
    $byCode = [];
    $catMap = [];
    if ($target === 'partners') {
        foreach ($pdo->query('SELECT id, name, business_no, branch_no FROM partners') as $r) {
            if (($r['business_no'] ?? '') !== '') {
                $byKey[$r['business_no'] . '|' . $r['branch_no']] = (int)$r['id'];
            } else {
                $byName[(string)$r['name']][] = (int)$r['id'];
            }
        }
    } else {
        foreach ($pdo->query('SELECT id, item_code FROM items') as $r) {
            $byCode[strtolower((string)$r['item_code'])] = (int)$r['id'];
        }
        foreach (category_paths(true) as $cid => $path) {
            $catMap[xl_norm_path((string)$path)] = (int)$cid;
        }
    }

    $errors = [];
    $plan = [];
    $seen = [];
    foreach ($rows as $no => $cells) {
        $get = function (string $k) use ($cells, $map): string {
            return isset($map[$k]) ? xl_trim((string)($cells[$map[$k]] ?? '')) : '';
        };
        $id = 0;
        if ($target === 'partners') {
            [$rec, $errs] = xl_parse_partner($get);
            if (!$errs) {
                if ($rec['business_no'] !== '') {
                    $key = $rec['business_no'] . '|' . $rec['branch_no'];
                    $id  = $byKey[$key] ?? 0;
                } else {
                    $key  = 'n|' . mb_strtolower($rec['name']);
                    $cand = $byName[$rec['name']] ?? [];
                    if (count($cand) > 1) { $errs[] = '같은 상호의 사업자번호 없는 거래처가 여러 개라 어느 쪽인지 정할 수 없습니다.'; }
                    $id = $cand[0] ?? 0;
                }
            }
        } else {
            [$rec, $errs] = xl_parse_item($get, $catMap);
            if (!$errs) {
                $key = strtolower($rec['item_code']);
                $id  = $byCode[$key] ?? 0;
            }
        }
        if (!$errs) {
            if (isset($seen[$key])) { $errs[] = '파일 안의 ' . $seen[$key] . '행과 같은 데이터입니다(중복).'; } else { $seen[$key] = $no; }
        }
        if ($errs) {
            foreach ($errs as $m) { $errors[] = [$no, $m]; }
            continue;
        }
        $plan[] = [$id, $rec];
    }

    $ins = 0;
    $upd = 0;
    foreach ($plan as [$pid, $prec]) {
        if ($pid > 0) { $upd++; } else { $ins++; }
    }
    $saved = false;
    if (!$errors && !$dry && $plan) {
        $pdo->beginTransaction();
        try {
            foreach ($plan as [$pid, $prec]) {
                if ($target === 'partners') { xl_save_partner($pdo, $pid, $prec); } else { xl_save_item($pdo, $pid, $prec); }
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[ERP] excel import: ' . $e->getMessage());
            throw new RuntimeException('저장 중 DB 오류가 나서 전부 취소했습니다. (오류 코드 ' . (int)($e->errorInfo[1] ?? 0) . ')');
        }
        audit('excel_import', $target, null, '신규 ' . $ins . ', 수정 ' . $upd);
        $saved = true;
    }
    return ['ins' => $ins, 'upd' => $upd, 'errors' => $errors, 'unknown' => $unknown, 'saved' => $saved, 'dry' => $dry];
}

/* ---------- 요청 처리 ---------- */
$dl  = (string)($_GET['dl'] ?? '');
$tpl = (string)($_GET['tpl'] ?? '');
if ($dl !== '' || $tpl !== '') {
    $t = $dl !== '' ? $dl : $tpl;
    if (isset($TARGETS[$t])) {
        xl_send($t . ($dl !== '' ? '' : '_template'), xl_export_rows($pdo, $t, $dl === ''));
    }
}

$result = null;
$fatal  = '';
$target = (string)($_POST['target'] ?? 'partners');
if (is_post()) {
    csrf_check();
    if (!isset($TARGETS[$target])) {
        $fatal = '대상을 선택하세요.';
    } else {
        try {
            $result = xl_process($pdo, $target, $_FILES['file'] ?? [], isset($_POST['dry']));
        } catch (RuntimeException $e) {
            $fatal = $e->getMessage();
        }
    }
}

page_header('엑셀 입출력', 'excel');
?>
<h2>엑셀 입출력</h2>
<?php if (!xl_zip_ok()): ?>
  <p style="color:#b45309">이 서버는 .xlsx를 지원하지 않아 CSV로 내려받고 올립니다.</p>
<?php endif; ?>

<h3>내려받기</h3>
<p>
  <?php foreach ($TARGETS as $k => $label): ?>
    <a class="btn" href="excel.php?dl=<?= h($k) ?>"><?= h($label) ?> 전체</a>
    <a class="btn" href="excel.php?tpl=<?= h($k) ?>"><?= h($label) ?> 빈 양식</a>
  <?php endforeach; ?>
</p>

<h3>올리기</h3>
<form method="post" action="excel.php" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <p>
    <select name="target">
      <?php foreach ($TARGETS as $k => $label): ?>
        <option value="<?= h($k) ?>"<?= $target === $k ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="file" name="file" accept=".xlsx,.csv" required>
  </p>
  <p><label><input type="checkbox" name="dry" value="1" checked> 검증만 하기 (저장하지 않음). 오류가 없는 것을 확인한 뒤 체크를 풀고 다시 올리세요.</label></p>
  <p><button class="btn" type="submit">업로드</button></p>
</form>

<?php if ($fatal !== ''): ?>
  <p style="color:#b91c1c;font-weight:600"><?= h($fatal) ?></p>
<?php endif; ?>

<?php if ($result): ?>
  <h3>결과</h3>
  <p>신규 <?= (int)$result['ins'] ?>건, 수정 <?= (int)$result['upd'] ?>건, 오류 <?= count($result['errors']) ?>건</p>
  <?php if ($result['errors']): ?>
    <p style="color:#b91c1c;font-weight:600">오류가 있어 아무것도 저장하지 않았습니다. 고친 뒤 다시 올려 주세요.</p>
    <table>
      <thead><tr><th>엑셀 행</th><th>내용</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($result['errors'], 0, 200) as [$no, $msg]): ?>
        <tr><td><?= (int)$no ?></td><td><?= h($msg) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($result['errors']) > 200): ?><p>처음 200건만 표시했습니다.</p><?php endif; ?>
  <?php elseif ($result['saved']): ?>
    <p style="color:#15803d;font-weight:600">저장했습니다.</p>
  <?php elseif ($result['dry']): ?>
    <p>검증만 했습니다. 저장되지 않았습니다. 이상이 없으면 "검증만 하기" 체크를 풀고 같은 파일을 다시 올리세요.</p>
  <?php else: ?>
    <p>저장할 데이터가 없습니다.</p>
  <?php endif; ?>
  <?php if ($result['unknown']): ?>
    <p>인식하지 못해 무시한 열: <?= h(implode(', ', $result['unknown'])) ?></p>
  <?php endif; ?>
<?php endif; ?>

<h3>작성 규칙</h3>
<p>첫 행은 내려받은 양식의 제목 그대로 쓰세요. 빈 칸은 기존 값을 유지합니다. 한 번에 <?= XL_MAX_ROWS ?>행, 5MB까지 올릴 수 있습니다.</p>
<p>거래처: 상호만 필수입니다. 유형은 매출처·매입처·매출·매입, 기본매출구분은 소매·도매, 사용은 Y/N입니다. 사업자번호가 있으면 체크섬을 검증합니다.</p>
<p>품목: 품목코드와 품명이 필수입니다. 분류는 <b>전체 경로</b>(예: 타이어 &gt; 승용·SUV)로 쓰고, 이미 등록된 분류만 인식합니다. 과세구분은 과세·면세입니다. 재고 수량은 이 화면으로 입력되지 않습니다.</p>
<?php page_footer();
