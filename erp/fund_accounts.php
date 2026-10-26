<?php
// erp/fund_accounts.php — 자금 계좌 관리 (관리자 전용)
require __DIR__ . '/inc/bootstrap.php';
require_login();

$me      = current_user();
$isAdmin = (($me['role'] ?? '') === 'admin');

$lb = __DIR__ . '/inc/list_bar.php';
if (is_file($lb)) {
    require_once $lb;
}

$KINDS = [
    'bank'  => '은행 계좌',
    'cash'  => '현금',
    'card'  => '카드',
    'mall'  => '쇼핑몰 정산',
    'other' => '기타',
];

// 금액 파싱: 쉼표 허용, 음수 허용, 최대 12자리 정수. 잘못된 값이면 null
function fa_amount(string $s): ?int
{
    $s = str_replace([',', ' '], '', trim($s));
    if ($s === '') {
        return 0;
    }
    if (!preg_match('/^-?\d{1,12}$/', $s)) {
        return null;
    }
    return (int)$s;
}

function fa_won($v): string
{
    return number_format((int)$v);
}

if (!$isAdmin) {
    page_header('자금 계좌', 'fund');
    echo '<p style="color:#dc2626"><b>자금 계좌는 관리자만 볼 수 있습니다.</b></p>';
    page_footer();
    exit;
}

$pdo  = db();
$errs = [];
$form = null;

if (is_post()) {
    csrf_check();

    $id       = in_int($_POST, 'id');
    $name     = in_str($_POST, 'name', 60);
    $kind     = in_str($_POST, 'kind', 10);
    $openRaw  = in_str($_POST, 'opening_amount', 20);
    $openDate = in_str($_POST, 'opening_date', 10);
    $sort     = in_int($_POST, 'sort_no');
    $memo     = in_str($_POST, 'memo', 200);
    $active   = isset($_POST['is_active']) ? 1 : 0;
    $open     = fa_amount($openRaw);

    if ($sort < 0)    { $sort = 0; }
    if ($sort > 9999) { $sort = 9999; }

    if ($name === '') {
        $errs[] = '계좌명을 입력하세요.';
    }
    if (preg_match_all('/\d/', $name) >= 10) {
        $errs[] = '계좌명에 전체 계좌번호를 넣지 마세요. 뒷 3~4자리만 남기세요. 예: 국민 ..947';
    }
    if (!isset($KINDS[$kind])) {
        $errs[] = '계좌 종류가 올바르지 않습니다.';
    }
    if ($open === null) {
        $errs[] = '개시 잔액은 정수(원)로 입력하세요. (최대 12자리, 음수 가능)';
    }
    if (!valid_date($openDate)) {
        $errs[] = '개시일을 YYYY-MM-DD 형식으로 입력하세요.';
    }

    $form = [
        'id' => $id, 'name' => $name, 'kind' => $kind, 'opening_amount' => $openRaw,
        'opening_date' => $openDate, 'sort_no' => $sort, 'memo' => $memo, 'is_active' => $active,
    ];

    if (!$errs) {
        $now = date('Y-m-d H:i:s');
        try {
            if ($id > 0) {
                $st = $pdo->prepare('SELECT * FROM erp_fund_accounts WHERE id = ?');
                $st->execute([$id]);
                $old = $st->fetch();
                if (!$old) {
                    $errs[] = '수정할 계좌를 찾을 수 없습니다.';
                } else {
                    $pdo->prepare(
                        'UPDATE erp_fund_accounts SET name = ?, kind = ?, opening_amount = ?, opening_date = ?,'
                        . ' sort_no = ?, is_active = ?, memo = ?, updated_at = ? WHERE id = ?'
                    )->execute([$name, $kind, $open, $openDate, $sort, $active, $memo, $now, $id]);
                    $detail = sprintf(
                        'name=%s | open=%d->%d | date=%s->%s | active=%d->%d',
                        $name, (int)$old['opening_amount'], $open, $old['opening_date'], $openDate,
                        (int)$old['is_active'], $active
                    );
                    try {
                        audit('fund_acct_update', 'fund_account', $id, $detail);
                    } catch (Throwable $e) {
                        error_log('[ERP] fund_accounts audit: ' . $e->getMessage());
                    }
                    flash('계좌를 수정했습니다.');
                    redirect('fund_accounts.php');
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO erp_fund_accounts (name, kind, opening_amount, opening_date, sort_no, is_active, memo, created_at, updated_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$name, $kind, $open, $openDate, $sort, $active, $memo, $now, $now]);
                $newId = (int)$pdo->lastInsertId();
                try {
                    audit('fund_acct_create', 'fund_account', $newId, sprintf('name=%s | open=%d | date=%s', $name, $open, $openDate));
                } catch (Throwable $e) {
                    error_log('[ERP] fund_accounts audit: ' . $e->getMessage());
                }
                flash('계좌를 등록했습니다.');
                redirect('fund_accounts.php');
            }
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $errs[] = '이미 같은 이름의 계좌가 있습니다.';
            } else {
                error_log('[ERP] fund_accounts: ' . $e->getMessage());
                $errs[] = '저장 중 오류가 발생했습니다.'
                    . (cfg('app.debug', false) ? ' ' . $e->getMessage() : '');
            }
        }
    }
}

// 수정 대상 불러오기
if ($form === null) {
    $editId = in_int($_GET, 'edit');
    if ($editId > 0) {
        $st = $pdo->prepare('SELECT * FROM erp_fund_accounts WHERE id = ?');
        $st->execute([$editId]);
        $row = $st->fetch();
        if ($row) {
            $form = [
                'id' => (int)$row['id'], 'name' => $row['name'], 'kind' => $row['kind'],
                'opening_amount' => (string)(int)$row['opening_amount'], 'opening_date' => $row['opening_date'],
                'sort_no' => (int)$row['sort_no'], 'memo' => $row['memo'], 'is_active' => (int)$row['is_active'],
            ];
        }
    }
}
if ($form === null) {
    $form = [
        'id' => 0, 'name' => '', 'kind' => 'bank', 'opening_amount' => '0',
        'opening_date' => date('Y-m-d'), 'sort_no' => 0, 'memo' => '', 'is_active' => 1,
    ];
}

// 목록 + 연결 건수
$list = [];
$listErr = '';
try {
    $list = $pdo->query(
        'SELECT a.*,'
        . ' (SELECT COUNT(*) FROM erp_trades t WHERE t.account_id = a.id)'
        . ' + (SELECT COUNT(*) FROM erp_payments p WHERE p.account_id = a.id)'
        . ' + (SELECT COUNT(*) FROM erp_fund_entries e WHERE e.account_id = a.id) AS use_cnt'
        . ' FROM erp_fund_accounts a ORDER BY a.is_active DESC, a.sort_no, a.id'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('[ERP] fund_accounts list: ' . $e->getMessage());
    $listErr = '목록을 불러오지 못했습니다.' . (cfg('app.debug', false) ? ' ' . $e->getMessage() : '');
}

$sumOpen = 0;
$dates   = [];
foreach ($list as $r) {
    if ((int)$r['is_active'] === 1) {
        $sumOpen += (int)$r['opening_amount'];
        $dates[$r['opening_date']] = true;
    }
}

$isEdit = ((int)$form['id'] > 0);
$editUse = 0;
if ($isEdit) {
    foreach ($list as $r) {
        if ((int)$r['id'] === (int)$form['id']) {
            $editUse = (int)$r['use_cnt'];
        }
    }
}

page_header('자금 계좌', 'fund');
?>
<h2 style="margin:0 0 12px">자금 계좌</h2>

<?php if ($errs): ?>
  <div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px 14px;border-radius:8px;margin-bottom:12px">
    <?php foreach ($errs as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($listErr): ?>
  <p style="color:#dc2626"><b><?= h($listErr) ?></b></p>
<?php endif; ?>
<?php if (count($dates) > 1): ?>
  <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:10px 14px;border-radius:8px;margin-bottom:12px">
    사용 중인 계좌의 개시일이 서로 다릅니다. 자금일보 이월잔액은 계좌마다 자기 개시일을 기준으로 계산되므로,
    전환일 하루로 통일하지 않으면 E카운트와 합계가 어긋날 수 있습니다.
  </div>
<?php endif; ?>

<form method="post" action="fund_accounts.php" onsubmit="var b=this.querySelector('button[type=submit]'); if(b){b.disabled=true;}"
      style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:20px">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
  <h3 style="margin:0 0 12px"><?= $isEdit ? '계좌 수정' : '계좌 등록' ?></h3>

  <?php if ($isEdit && $editUse > 0): ?>
    <p style="color:#92400e;margin:0 0 12px">
      이 계좌에는 연결된 거래가 <?= (int)$editUse ?>건 있습니다. 개시 잔액이나 개시일을 바꾸면 이후 모든 잔액이 달라집니다.
    </p>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
    <label>계좌명 (최대 60자, 전체 계좌번호 금지)<br>
      <input type="text" id="faName" name="name" maxlength="60" required value="<?= h($form['name']) ?>" style="width:100%">
    </label>
    <label>종류<br>
      <select name="kind" style="width:100%">
        <?php foreach ($KINDS as $k => $lbl): ?>
          <option value="<?= h($k) ?>" <?= $form['kind'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>개시 잔액 (원)<br>
      <input type="text" name="opening_amount" inputmode="numeric" value="<?= h($form['opening_amount']) ?>" style="width:100%;text-align:right">
    </label>
    <label>개시일 (YYYY-MM-DD)<br>
      <input type="text" name="opening_date" maxlength="10" required value="<?= h($form['opening_date']) ?>" style="width:100%">
    </label>
    <label>정렬 순서<br>
      <input type="number" name="sort_no" min="0" max="9999" value="<?= (int)$form['sort_no'] ?>" style="width:100%">
    </label>
    <label>메모<br>
      <input type="text" name="memo" maxlength="200" value="<?= h($form['memo']) ?>" style="width:100%">
    </label>
  </div>

  <p style="margin:12px 0 0">
    <label><input type="checkbox" name="is_active" value="1" <?= (int)$form['is_active'] === 1 ? 'checked' : '' ?>> 사용 (해제하면 선택 목록에서 숨김, 잔액 계산은 유지)</label>
  </p>
  <p style="margin:12px 0 0">
    <button type="submit" class="btn"><?= $isEdit ? '수정 저장' : '등록' ?></button>
    <?php if ($isEdit): ?><a class="btn" href="fund_accounts.php">취소</a><?php endif; ?>
  </p>
</form>

<table>
  <thead>
    <tr>
      <th>계좌명</th>
      <th>종류</th>
      <th style="text-align:right">개시 잔액</th>
      <th>개시일</th>
      <th style="text-align:right">연결 건수</th>
      <th>사용</th>
      <th>메모</th>
      <th>수정</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($list as $r): ?>
    <tr class="<?= (int)$r['is_active'] === 1 ? '' : 'muted' ?>">
      <td><?= h($r['name']) ?></td>
      <td><?= h($KINDS[$r['kind']] ?? $r['kind']) ?></td>
      <td style="text-align:right"><?= fa_won($r['opening_amount']) ?></td>
      <td><?= h($r['opening_date']) ?></td>
      <td style="text-align:right"><?= (int)$r['use_cnt'] ?></td>
      <td><?= (int)$r['is_active'] === 1 ? 'Y' : 'N' ?></td>
      <td><?= h($r['memo']) ?></td>
      <td><a href="fund_accounts.php?edit=<?= (int)$r['id'] ?>">수정</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?>
    <tr><td colspan="8">등록된 계좌가 없습니다.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<p style="margin:12px 0">
  <b>사용 중 계좌 개시 잔액 합계:</b> <?= fa_won($sumOpen) ?>원
  <span style="color:#6b7280">(E카운트 자금일보의 같은 날짜 이월잔액 합계와 같아야 합니다)</span>
</p>

<?php
if (function_exists('listbar_render')) {
    listbar_render(['title' => '자금 계좌 목록', 'new_url' => '#faName']);
}
page_footer();
