<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/partner_lib.php';
require_once __DIR__ . '/inc/partner_bar.php';
if (is_file(__DIR__ . '/inc/ledger_lib.php')) {
    require_once __DIR__ . '/inc/ledger_lib.php';
}
if (is_file(__DIR__ . '/inc/partner_code.php')) {
    require_once __DIR__ . '/inc/partner_code.php';
}
if (!defined('PC_PREFIX')) {
    define('PC_PREFIX', 'C');
    define('PC_DIGITS', 5);
}
require_login();
$pdo = db();

$types    = ['customer' => '매출처', 'supplier' => '매입처', 'both' => '매출·매입'];
$channels = ['retail' => '소매', 'wholesale' => '도매'];

$cols       = pt_cols($pdo);
$hasRegion  = in_array('region', $cols, true);
$hasCredit  = in_array('is_credit', $cols, true);
$hasEcode   = in_array('ecount_code', $cols, true);
$hasPCode   = in_array('partner_code', $cols, true);
$canAssign  = $hasPCode && function_exists('pc_assign_new');
$extCols    = array_values(array_filter(array_keys(PT_EXT), fn($k) => in_array($k, $cols, true)));
$missingExt = count($extCols) < count(PT_EXT);

$ledgerOk = false;
if ($hasCredit && function_exists('lg_ready_error') && function_exists('lg_balance')) {
    try {
        $ledgerOk = lg_ready_error() === null;
    } catch (Throwable $e) {
        $ledgerOk = false;
    }
}
$me      = current_user();
$isAdmin = is_array($me) && ($me['role'] ?? '') === 'admin';

$form   = null;
$errors = [];

/* ───────── 저장 ───────── */
if (is_post()) {
    require_write();
    csrf_check();

    $id        = in_int($_POST, 'id');
    $name      = in_str($_POST, 'name', 100);
    $biz       = (string)preg_replace('/\D/', '', in_str($_POST, 'business_no', 20));
    $branchRaw = (string)preg_replace('/\D/', '', in_str($_POST, 'branch_no', 10));
    $ceo       = in_str($_POST, 'ceo_name', 50);
    $phone     = in_str($_POST, 'phone', 30);
    $email     = in_str($_POST, 'tax_email', 100);
    $addr      = in_str($_POST, 'address', 200);
    $ptype     = in_str($_POST, 'partner_type', 10);
    $chan      = in_str($_POST, 'default_channel', 10);
    $memo      = in_str($_POST, 'memo', 500);
    $regionIn  = in_str($_POST, 'region', 10);
    $active    = isset($_POST['is_active']) ? 1 : 0;
    $credit    = ($hasCredit && isset($_POST['is_credit'])) ? 1 : 0;

    $extIn = [];
    foreach ($extCols as $k) {
        $extIn[$k] = in_str($_POST, $k, PT_EXT[$k][1]);
    }
    if (isset($extIn['ecount_code']) && $extIn['ecount_code'] !== ''
        && !preg_match('/^[A-Za-z0-9._\-]{1,20}$/', $extIn['ecount_code'])) {
        $errors[] = 'E카운트 코드는 영문/숫자/-/_/. 만 20자 이하로 입력하세요.';
    }

    if (strlen($branchRaw) > 4) {
        $errors[] = '종사업장번호는 4자리 이하 숫자입니다.';
    }
    $branch = pt_norm_branch($branchRaw);

    $region = '';
    if ($hasRegion) {
        if ($regionIn !== '') {
            $region = pt_norm_region($regionIn);
            if ($region === '') {
                $errors[] = '지역 값이 올바르지 않습니다.';
            }
        } else {
            $region = pt_region_from_address($addr) ?: pt_region_from_name($name);
        }
    }

    $old = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM erp_partners WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetch() ?: null;
        if (!$old) {
            $errors[] = '수정할 거래처를 찾을 수 없습니다.';
        }
    }

    if ($name === '') {
        $errors[] = '상호를 입력하세요.';
    }
    if ($biz !== '' && !is_valid_biz_no($biz)) {
        $errors[] = '사업자번호가 올바르지 않습니다. (10자리, 체크섬 불일치)';
    }
    if ($branch !== '' && $biz === '') {
        $errors[] = '종사업장번호는 사업자번호와 함께 입력하세요.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '세금계산서 이메일 형식이 올바르지 않습니다.';
    }
    if (!isset($types[$ptype])) {
        $errors[] = '거래처 유형이 올바르지 않습니다.';
    }
    if (!isset($channels[$chan])) {
        $errors[] = '기본 매출구분이 올바르지 않습니다.';
    }

    if ($hasCredit) {
        $wasCredit = $old && (int)($old['is_credit'] ?? 0) === 1;
        if ($credit === 1 && $ptype === 'supplier') {
            $errors[] = '매입처 전용 거래처는 외상(미수) 대상으로 지정할 수 없습니다.';
        }
        if ($credit === 1 && !$wasCredit && !$ledgerOk) {
            $errors[] = '외상 원장 준비가 되어 있지 않아 외상으로 지정할 수 없습니다. (거래처 원장 메뉴 확인)';
        }
        if ($wasCredit && $credit === 0) {
            if (!$ledgerOk) {
                $errors[] = '원장 상태를 확인할 수 없어 외상 해제를 할 수 없습니다.';
            } elseif ((int)lg_balance($id) !== 0) {
                $errors[] = '미수 잔액이 남아 있어 외상을 해제할 수 없습니다. 거래처 원장에서 잔액을 정리하세요.';
            }
        }
    }

    $form = array_merge([
        'id' => $id, 'name' => $name, 'business_no' => $biz, 'branch_no' => $branch, 'ceo_name' => $ceo,
        'phone' => $phone, 'tax_email' => $email, 'address' => $addr, 'partner_type' => $ptype,
        'default_channel' => $chan, 'memo' => $memo, 'is_active' => $active,
        'region' => $regionIn, 'is_credit' => $credit,
        'partner_code' => (string)($old['partner_code'] ?? ''),   // 코드는 화면에서 수정 불가, 표시용
    ], $extIn);

    if (!$errors) {
        $now   = date('Y-m-d H:i:s');
        $bizDb = $biz === '' ? null : $biz;
        try {
            if ($id > 0) {
                $set  = ['name = ?', 'business_no = ?', 'branch_no = ?', 'ceo_name = ?', 'phone = ?',
                         'tax_email = ?', 'address = ?', 'partner_type = ?', 'default_channel = ?',
                         'memo = ?', 'is_active = ?', 'updated_at = ?'];
                $args = [$name, $bizDb, $branch, $ceo, $phone, $email, $addr, $ptype, $chan, $memo, $active, $now];
                if ($hasRegion) {
                    $set[]  = 'region = ?';
                    $args[] = $region;
                }
                if ($hasCredit) {
                    $set[]  = 'is_credit = ?';
                    $args[] = $credit;
                }
                foreach ($extIn as $k => $val) {
                    $set[]  = $k . ' = ?';
                    $args[] = ($k === 'ecount_code' && $val === '') ? null : $val;
                }
                $args[] = $id;
                $pdo->prepare('UPDATE erp_partners SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
                audit('partner_update', 'partner', $id, $name);
                flash('거래처를 수정했습니다.');
            } else {
                $row = ['name' => $name, 'business_no' => $bizDb, 'branch_no' => $branch, 'ceo_name' => $ceo,
                        'phone' => $phone, 'tax_email' => $email, 'address' => $addr, 'partner_type' => $ptype,
                        'default_channel' => $chan, 'memo' => $memo, 'is_active' => $active,
                        'created_at' => $now, 'updated_at' => $now];
                if ($hasRegion) {
                    $row['region'] = $region;
                }
                if ($hasCredit) {
                    $row['is_credit'] = $credit;
                }
                foreach ($extIn as $k => $val) {
                    $row[$k] = ($k === 'ecount_code' && $val === '') ? null : $val;
                }
                $pdo->prepare('INSERT INTO erp_partners (' . implode(', ', array_keys($row)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
                $newId = (int)$pdo->lastInsertId();   // INSERT 직후, 다른 쿼리 전에 읽는다

                // 거래처 코드 자동 부여: 실패해도 저장은 성공으로 두고 채우기 도구로 나중에 채운다
                $newCode = '';
                if ($canAssign) {
                    try {
                        $newCode = pc_assign_new($pdo, $newId);
                    } catch (Throwable $e) {
                        error_log('[ERP] partners partner_code assign: ' . $e->getMessage());
                    }
                }
                audit('partner_create', 'partner', $newId, $name);
                flash('거래처를 등록했습니다.' . ($newCode !== '' ? ' (코드 ' . $newCode . ')' : ''));
            }
            redirect('partners.php');
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $msg = $e->getMessage();
                if (stripos($msg, 'partner_code') !== false) {
                    $errors[] = '거래처 코드가 중복됐습니다. 잠시 후 다시 저장하세요.';
                } elseif (stripos($msg, 'ecount') !== false) {
                    $errors[] = '이미 등록된 E카운트 코드입니다.';
                } else {
                    $errors[] = '이미 등록된 사업자번호(종사업장번호)입니다.';
                }
            } else {
                throw $e;
            }
        }
    }
}

/* ───────── 수정 대상 / 신규 기본값 ───────── */
if ($form === null) {
    $editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
    if ($editId > 0) {
        $st = $pdo->prepare('SELECT * FROM erp_partners WHERE id = ?');
        $st->execute([$editId]);
        $row = $st->fetch();
        if ($row) {
            $form = $row;
            $form['business_no'] = (string)($row['business_no'] ?? '');
        }
    }
}
if ($form === null) {
    $form = ['id' => 0, 'name' => '', 'business_no' => '', 'branch_no' => '', 'ceo_name' => '', 'phone' => '',
             'tax_email' => '', 'address' => '', 'partner_type' => 'customer', 'default_channel' => 'wholesale',
             'memo' => '', 'is_active' => 1, 'region' => '', 'is_credit' => 0, 'partner_code' => ''];
}
$fv = fn(string $k) => (string)($form[$k] ?? '');

/* ───────── 조회 조건 / 목록 ───────── */
$f = [
    'q'      => in_str($_GET, 'q', 50),
    'region' => in_str($_GET, 'region', 10),
    'type'   => in_str($_GET, 'type', 10),
    'credit' => in_str($_GET, 'credit', 1),
    'active' => in_str($_GET, 'active', 1),
];

$regionCounts = [];
$totalAll     = 0;
if ($hasRegion) {
    foreach ($pdo->query('SELECT region, COUNT(*) AS c FROM erp_partners GROUP BY region')->fetchAll() as $r) {
        $regionCounts[(string)$r['region']] = (int)$r['c'];
        $totalAll += (int)$r['c'];
    }
} else {
    $totalAll = (int)$pdo->query('SELECT COUNT(*) FROM erp_partners')->fetchColumn();
}

// 검색어가 "C00012" 형태면 코드 앞부분 일치로 검색한다. (pt_filter 는 수정하지 않음)
$fq     = $f;
$codeQ  = '';
if ($hasPCode && preg_match('/^' . preg_quote(PC_PREFIX, '/') . '\d{1,' . (int)PC_DIGITS . '}$/i', $f['q'])) {
    $codeQ     = strtoupper($f['q']);
    $fq['q']   = '';
}
[$whereSql, $args] = pt_filter($fq, $cols);
if ($codeQ !== '') {
    $whereSql .= (trim($whereSql) === '' ? ' WHERE ' : ' AND ') . 'p.partner_code LIKE ?';
    $args[]    = $codeQ . '%';
}

$perPage = 100;
$cnt = $pdo->prepare('SELECT COUNT(*) FROM erp_partners p' . $whereSql);
$cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$order = $hasRegion ? "(p.region = ''), p.region, p.name, p.id" : 'p.name, p.id';
$st = $pdo->prepare('SELECT p.* FROM erp_partners p' . $whereSql . ' ORDER BY ' . $order
    . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($args);
$list = $st->fetchAll();

// 관리자 안내용: 코드가 비어 있는 거래처 수
$noCodeCount = 0;
if ($isAdmin && $hasPCode) {
    try {
        $noCodeCount = (int)$pdo->query("SELECT COUNT(*) FROM erp_partners WHERE partner_code IS NULL OR partner_code = ''")->fetchColumn();
    } catch (Throwable $e) {
        error_log('[ERP] partners no-code count: ' . $e->getMessage());
    }
}

$selJs = @filemtime(__DIR__ . '/assets/partner_select.js') ?: 1;

page_header('거래처', 'partners');
?>
<style>
.pt-card{background:var(--card,#fff);border:1px solid var(--line,#dfe3ea);border-radius:12px;padding:16px 18px;margin-bottom:14px}
.pt-card h2{margin:0 0 12px;font-size:16px}
.pt-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:10px 12px}
.pt-f{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--sub,#4b5563)}
.pt-f input[type=text],.pt-f input[type=email],.pt-f select{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--line,#cfd6e0);border-radius:8px;font-size:14px;color:var(--text,#111827);background:#fff}
.pt-f input[readonly]{background:#f1f4f9;color:#4b5563}
.pt-s2{grid-column:span 2}.pt-s3{grid-column:span 3}.pt-s4{grid-column:span 4}.pt-s6{grid-column:span 6}.pt-s12{grid-column:span 12}
@media(max-width:900px){.pt-s2,.pt-s3,.pt-s4,.pt-s6{grid-column:span 6}}
@media(max-width:560px){.pt-s2,.pt-s3,.pt-s4,.pt-s6{grid-column:span 12}}
.pt-row{display:flex;flex-wrap:wrap;gap:12px;align-items:center;font-size:14px}
.pt-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.pt-chip{padding:5px 11px;border-radius:999px;border:1px solid var(--line,#cfd6e0);background:#fff;color:var(--text,#111827);text-decoration:none;font-size:13px}
.pt-chip b{color:var(--primary,#1b64da);margin-left:4px}
.pt-chip.on{background:var(--primary,#1b64da);border-color:var(--primary,#1b64da);color:#fff}
.pt-chip.on b{color:#fff}
.pt-chip.zero{opacity:.55}
.pt-scroll{overflow-x:auto}
.pt-table{width:100%;border-collapse:collapse;font-size:13px}
.pt-table th,.pt-table td{padding:8px 10px;border-bottom:1px solid var(--line-soft,#e5e9f0);text-align:left;white-space:nowrap}
.pt-table th{background:#f1f4f9;color:#374151}
.pt-table tr.off td{color:#8a93a0}
.pt-err{background:#fdecea;border:1px solid #f5c2c0;color:#8a1f17;padding:10px 14px;border-radius:8px;margin-bottom:12px}
.pt-info{background:#eef4ff;border:1px solid #c9dafc;color:#12306f;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:13px}
.pt-hint{font-size:12px;color:#6b7684}
.pt-code{font-variant-numeric:tabular-nums;font-weight:600}
</style>

<?php if (!$hasRegion): ?>
  <div class="pt-info no-print">
    지역 기능이 꺼져 있습니다. <code>erp_partners.region</code> 컬럼이 없습니다.
    <?= $isAdmin ? '<a href="partners_region_setup.php">partners_region_setup.php</a> 를 먼저 실행하세요.' : '관리자에게 컬럼 설치를 요청하세요.' ?>
  </div>
<?php endif; ?>
<?php if ($missingExt): ?>
  <div class="pt-info no-print">
    E카운트 연동 컬럼(E카운트 코드·핸드폰·입금자명 등)이 아직 없습니다. 이 컬럼이 없으면 E카운트 파일을 업로드할 수 없습니다.
    <?= $isAdmin ? '<a href="partners_ecount_setup.php">partners_ecount_setup.php</a> 를 실행하세요.' : '관리자에게 컬럼 설치를 요청하세요.' ?>
  </div>
<?php endif; ?>
<?php if (!$hasPCode && $isAdmin): ?>
  <div class="pt-info no-print">
    거래처 코드 컬럼(<code>partner_code</code>)이 아직 없습니다.
    <a href="partner_code_migrate.php">partner_code_migrate.php</a> 를 실행하세요.
  </div>
<?php elseif ($noCodeCount > 0 && $isAdmin): ?>
  <div class="pt-info no-print">
    거래처 코드가 없는 거래처가 <?= (int)$noCodeCount ?>건 있습니다.
    <?php if ($canAssign): ?>
      <a href="partner_code_fill.php">코드 채우기</a>
    <?php else: ?>
      <code>inc/partner_code.php</code> 를 올린 뒤 <code>partner_code_fill.php</code> 에서 채우세요.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="pt-err no-print"><?php foreach ($errors as $e): ?><div>· <?= h($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (can_write()): ?>
<div class="pt-card no-print" id="pform">
  <h2><?= (int)($form['id'] ?? 0) > 0 ? '거래처 수정' : '거래처 등록' ?></h2>
  <form method="post" action="partners.php#pform">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
    <div class="pt-grid">
      <?php if ($hasPCode): ?>
        <?php $pcShow = $fv('partner_code'); ?>
        <label class="pt-f pt-s2">거래처 코드
          <input type="text" value="<?= h($pcShow !== '' ? $pcShow : ((int)($form['id'] ?? 0) > 0 ? '(미부여)' : '')) ?>" placeholder="저장 시 자동" readonly tabindex="-1">
        </label>
      <?php endif; ?>
      <label class="pt-f pt-s4">상호 *<input type="text" name="name" maxlength="100" value="<?= h($fv('name')) ?>" required></label>
      <label class="pt-f pt-s3">사업자번호 (세금계산서용)<input type="text" name="business_no" maxlength="20" inputmode="numeric" placeholder="000-00-00000" value="<?= h($fv('business_no') !== '' ? fmt_biz_no($fv('business_no')) : '') ?>"></label>
      <label class="pt-f pt-s3">종사업장번호<input type="text" name="branch_no" maxlength="10" inputmode="numeric" placeholder="0001" value="<?= h($fv('branch_no')) ?>"></label>
      <label class="pt-f pt-s3">대표자<input type="text" name="ceo_name" maxlength="50" value="<?= h($fv('ceo_name')) ?>"></label>

      <label class="pt-f pt-s3">전화번호<input type="text" name="phone" maxlength="30" value="<?= h($fv('phone')) ?>"></label>
      <?php foreach ($extCols as $k): if ($k === 'ecount_code' || $k === 'search_keyword') { continue; } ?>
        <label class="pt-f pt-s3"><?= h(PT_EXT[$k][0]) ?><input type="text" name="<?= h($k) ?>" maxlength="<?= (int)PT_EXT[$k][1] ?>" value="<?= h($fv($k)) ?>"></label>
      <?php endforeach; ?>
      <label class="pt-f pt-s4">세금계산서 이메일<input type="text" name="tax_email" maxlength="100" value="<?= h($fv('tax_email')) ?>"></label>

      <?php if ($hasEcode): ?>
        <label class="pt-f pt-s3">E카운트 코드 (참고용)<input type="text" name="ecount_code" maxlength="20" value="<?= h($fv('ecount_code')) ?>"></label>
      <?php endif; ?>
      <?php if (in_array('search_keyword', $extCols, true)): ?>
        <label class="pt-f pt-s3">검색창내용<input type="text" name="search_keyword" maxlength="100" value="<?= h($fv('search_keyword')) ?>"></label>
      <?php endif; ?>
      <?php if ($hasRegion): ?>
      <label class="pt-f pt-s2">지역
        <select name="region">
          <option value="">(자동)</option>
          <?php foreach (PT_REGIONS as $r): ?>
            <option value="<?= h($r) ?>" <?= $fv('region') === $r ? 'selected' : '' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label class="pt-f pt-s3">거래처 유형
        <select name="partner_type">
          <?php foreach ($types as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $fv('partner_type') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="pt-f pt-s2">기본 매출구분
        <select name="default_channel">
          <?php foreach ($channels as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $fv('default_channel') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="pt-f pt-s6">주소<input type="text" name="address" maxlength="200" value="<?= h($fv('address')) ?>"></label>
      <label class="pt-f pt-s6">비고<input type="text" name="memo" maxlength="500" value="<?= h($fv('memo')) ?>"></label>
    </div>
    <div class="pt-row" style="margin-top:12px">
      <label><input type="checkbox" name="is_active" value="1" <?= (int)($form['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> 사용</label>
      <?php if ($hasCredit): ?>
        <label><input type="checkbox" name="is_credit" value="1" <?= (int)($form['is_credit'] ?? 0) === 1 ? 'checked' : '' ?>> 외상 거래처 (월말 입금)</label>
        <?php if (!$ledgerOk): ?><span class="pt-hint">※ 원장 준비 전이라 외상 지정은 저장되지 않습니다.</span><?php endif; ?>
      <?php endif; ?>
      <button class="btn" type="submit">저장</button>
      <?php if ((int)($form['id'] ?? 0) > 0): ?><a class="btn btn-sm" href="partners.php#pform">새 거래처 입력으로</a><?php endif; ?>
    </div>
    <?php if ($hasRegion): ?><p class="pt-hint" style="margin:8px 0 0">지역을 비워 두면 주소 첫 단어, 없으면 상호 끝의 괄호(예: "…(서울)")로 자동 지정합니다.</p><?php endif; ?>
    <?php if ($hasPCode): ?><p class="pt-hint" style="margin:4px 0 0">거래처 코드는 저장할 때 자동으로 부여되며 수정할 수 없습니다. 사업자번호와는 별개입니다.</p><?php endif; ?>
  </form>
</div>
<?php else: ?>
<div class="pt-info no-print">
  현재 계정(<?= h((string)($me['name'] ?? '')) ?> / 권한: <?= h((string)($me['role'] ?? '')) ?>)은 읽기 전용이라 등록·수정·엑셀 업로드가 표시되지 않습니다.
  쓰기 권한은 admin 또는 office 입니다. 사용자 관리에서 권한을 확인하세요.
</div>
<?php endif; ?>

<?php if ($hasRegion): ?>
<div class="pt-chips no-print">
  <a class="pt-chip <?= $f['region'] === '' ? 'on' : '' ?>" href="<?= h(pt_url('partners.php', array_merge($f, ['region' => '']))) ?>">전체<b><?= $totalAll ?></b></a>
  <?php foreach (PT_REGIONS as $r): $c = $regionCounts[$r] ?? 0; ?>
    <a class="pt-chip <?= $f['region'] === $r ? 'on' : '' ?> <?= $c === 0 ? 'zero' : '' ?>" href="<?= h(pt_url('partners.php', array_merge($f, ['region' => $r]))) ?>"><?= h($r) ?><b><?= $c ?></b></a>
  <?php endforeach; ?>
  <a class="pt-chip <?= $f['region'] === '_none' ? 'on' : '' ?>" href="<?= h(pt_url('partners.php', array_merge($f, ['region' => '_none']))) ?>">미지정<b><?= $regionCounts[''] ?? 0 ?></b></a>
</div>
<?php endif; ?>

<form method="get" action="partners.php" class="pt-card pt-row no-print">
  <input type="hidden" name="region" value="<?= h($f['region']) ?>">
  <input type="text" id="qbox" name="q" value="<?= h($f['q']) ?>" placeholder="상호 / 코드(C00001) / 대표자 / 연락처 / 입금자명 / 주소 / 사업자번호" style="min-width:300px;padding:8px 10px;border:1px solid #cfd6e0;border-radius:8px">
  <select name="type">
    <option value="">전체 유형</option>
    <?php foreach ($types as $k => $v): ?><option value="<?= h($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
  </select>
  <select name="active">
    <option value="">사용여부 전체</option>
    <option value="1" <?= $f['active'] === '1' ? 'selected' : '' ?>>사용</option>
    <option value="0" <?= $f['active'] === '0' ? 'selected' : '' ?>>미사용</option>
  </select>
  <?php if ($hasCredit): ?><label><input type="checkbox" name="credit" value="1" <?= $f['credit'] === '1' ? 'checked' : '' ?>> 외상만</label><?php endif; ?>
  <button class="btn" type="submit">조회</button>
  <a class="btn btn-sm" href="partners.php">초기화</a>
  <span class="pt-hint">조회 결과 <?= number_format($total) ?>건</span>
</form>

<div class="pt-card pt-scroll">
  <table class="pt-table">
    <thead>
      <tr>
        <?php if ($hasRegion): ?><th>지역</th><?php endif; ?>
        <?php if ($hasPCode): ?><th>코드</th><?php endif; ?>
        <th>상호</th><th>사업자번호</th><th>대표자</th><th>전화번호</th>
        <?php if (in_array('mobile', $extCols, true)): ?><th>핸드폰</th><?php endif; ?>
        <?php if (in_array('depositor', $extCols, true)): ?><th>입금자명</th><?php endif; ?>
        <th>세금계산서 이메일</th><th>유형</th><th>매출구분</th>
        <?php if ($hasCredit): ?><th>외상</th><?php endif; ?>
        <th>사용</th><?php if (can_write()): ?><th class="no-print"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (!$list): ?>
      <tr><td colspan="14" class="pt-hint">거래처가 없습니다.</td></tr>
    <?php endif; ?>
    <?php foreach ($list as $r): ?>
      <?php $bn = (string)($r['business_no'] ?? ''); $br = (string)($r['branch_no'] ?? ''); $pc = (string)($r['partner_code'] ?? ''); ?>
      <tr class="<?= (int)$r['is_active'] === 1 ? '' : 'off' ?>">
        <?php if ($hasRegion): ?><td><?= h((string)($r['region'] ?? '') !== '' ? (string)$r['region'] : '-') ?></td><?php endif; ?>
        <?php if ($hasPCode): ?><td class="pt-code"><?= h($pc !== '' ? $pc : '-') ?></td><?php endif; ?>
        <td><?= h((string)$r['name']) ?></td>
        <td><?= h($bn !== '' ? fmt_biz_no($bn) : '') ?><?= $br !== '' ? ' (' . h($br) . ')' : '' ?></td>
        <td><?= h((string)($r['ceo_name'] ?? '')) ?></td>
        <td><?= h((string)($r['phone'] ?? '')) ?></td>
        <?php if (in_array('mobile', $extCols, true)): ?><td><?= h((string)($r['mobile'] ?? '')) ?></td><?php endif; ?>
        <?php if (in_array('depositor', $extCols, true)): ?><td><?= h((string)($r['depositor'] ?? '')) ?></td><?php endif; ?>
        <td><?= h((string)($r['tax_email'] ?? '')) ?></td>
        <td><?= h($types[$r['partner_type']] ?? (string)$r['partner_type']) ?></td>
        <td><?= h($channels[$r['default_channel']] ?? (string)$r['default_channel']) ?></td>
        <?php if ($hasCredit): ?><td><?= (int)($r['is_credit'] ?? 0) === 1 ? '외상' : '-' ?></td><?php endif; ?>
        <td><?= (int)$r['is_active'] === 1 ? 'Y' : 'N' ?></td>
        <?php if (can_write()): ?><td class="no-print"><a href="partners.php?edit=<?= (int)$r['id'] ?>#pform">수정</a></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<div class="pt-row no-print" style="margin-bottom:20px">
  <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= h(pt_url('partners.php', array_merge($f, ['page' => (string)($page - 1)]))) ?>">이전</a><?php endif; ?>
  <span class="pt-hint"><?= $page ?> / <?= $pages ?> 페이지 (페이지당 <?= $perPage ?>건)</span>
  <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= h(pt_url('partners.php', array_merge($f, ['page' => (string)($page + 1)]))) ?>">다음</a><?php endif; ?>
</div>
<?php endif; ?>

<?php ptbar_render(can_write(), $f); ?>
<?php if ($isAdmin): ?>
<form id="pbulk" method="post" action="partner_bulk_delete.php" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="step" value="confirm">
</form>
<script src="assets/partner_select.js?v=<?= (int)$selJs ?>"></script>
<?php endif; ?>

<?php page_footer();
