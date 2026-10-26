<?php
// ledger.php - 거래처 원장 + 미수금 현황. 미실행 코드
// erp_partner_openings 실제 구조: partner_id, as_of_date(NOT NULL), ar_amount, ap_amount, updated_at
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ledger_lib.php';
require_once __DIR__ . '/inc/ledger_bar.php';
require_login();
$pdo = db();
$me = current_user();
$isAdmin = ($me['role'] ?? '') === 'admin';

$notReady = lg_ready_error();
if ($notReady !== null) {
    page_header('거래처 원장', 'ledger');
    echo '<div class="card"><p class="err">' . h($notReady) . '</p>';
    echo '<p>DB 구조가 코드와 맞지 않습니다. 관리자에게 이 문구를 그대로 전달하세요.</p></div>';
    page_footer();
    exit;
}

// ── 외상 지정 / 기초잔액 저장 (관리자 전용)
if (is_post()) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('관리자만 변경할 수 있습니다.');
    }
    csrf_check();
    $pid      = in_int($_POST, 'partner_id');
    $isCredit = isset($_POST['is_credit']) ? 1 : 0;
    $openRaw  = in_str($_POST, 'opening', 20);
    $asOf     = in_str($_POST, 'as_of', 10);
    $open     = $openRaw === '' ? 0 : lg_won($openRaw);

    $st = $pdo->prepare('SELECT id, is_credit FROM erp_partners WHERE id = ?');
    $st->execute([$pid]);
    $p = $st->fetch();

    $errors = [];
    if (!$p) {
        $errors[] = '거래처를 찾을 수 없습니다.';
    }
    if ($open === null) {
        $errors[] = '기초잔액은 0 이상의 정수(원)로 입력하세요.';
    }
    if ($open !== null && ($open > 0 || $asOf !== '') && !valid_date($asOf)) {
        $errors[] = '기초잔액 기준일을 YYYY-MM-DD 형식으로 입력하세요.';
    }
    if (!$errors && !$isCredit && (int)$p['is_credit'] === 1 && lg_balance($pid) !== 0) {
        $errors[] = '미수금 잔액이 0원이 아니라서 외상 지정을 해제할 수 없습니다.';
    }
    if ($errors) {
        foreach ($errors as $e) {
            flash($e, 'err');
        }
        redirect('ledger.php?partner=' . $pid);
    }

    [$oldOpen, $oldAsOf] = lg_opening($pid);
    $now     = date('Y-m-d H:i:s');
    $userErr = null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE erp_partners SET is_credit = ? WHERE id = ?')->execute([$isCredit, $pid]);

        $ex = $pdo->prepare('SELECT ap_amount FROM erp_partner_openings WHERE partner_id = ? FOR UPDATE');
        $ex->execute([$pid]);
        $exRow = $ex->fetch();

        if ($open === 0 && $asOf === '') {
            // 기초잔액을 비우는 경우: 행 삭제. 단, 미지급 기초잔액(ap_amount)이 있으면 삭제하지 않고 막는다.
            if ($exRow && (int)$exRow['ap_amount'] !== 0) {
                $userErr = '이 거래처에는 미지급 기초잔액이 있어 기초잔액 행을 비울 수 없습니다. 기준일을 입력하세요.';
            } elseif ($exRow) {
                $pdo->prepare('DELETE FROM erp_partner_openings WHERE partner_id = ?')->execute([$pid]);
            }
        } elseif ($exRow) {
            $pdo->prepare('UPDATE erp_partner_openings SET ar_amount = ?, as_of_date = ?, updated_at = ? WHERE partner_id = ?')
                ->execute([$open, $asOf, $now, $pid]);
        } else {
            $pdo->prepare('INSERT INTO erp_partner_openings (partner_id, ar_amount, as_of_date, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$pid, $open, $asOf, $now]);
        }

        if ($userErr !== null) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ERP] ledger settings: ' . $e->getMessage());
        flash('저장 중 오류가 나서 전부 취소했습니다.', 'err');
        redirect('ledger.php?partner=' . $pid);
    }
    if ($userErr !== null) {
        flash($userErr, 'err');
        redirect('ledger.php?partner=' . $pid);
    }
    audit('ledger_setting', 'partner', $pid, 'credit=' . $isCredit . ' open=' . $oldOpen . '->' . $open . ' as_of=' . ($oldAsOf ?? '-') . '->' . ($asOf ?: '-'));
    flash('저장했습니다.');
    redirect('ledger.php?partner=' . $pid);
}

// ── 조회
$pid  = in_int($_GET, 'partner');
$from = in_str($_GET, 'from', 10);
$to   = in_str($_GET, 'to', 10);
if (!valid_date($from)) {
    $from = date('Y-m-01', strtotime('-2 months'));
}
if (!valid_date($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$all = $pdo->query('SELECT id, name, business_no, is_credit FROM erp_partners WHERE is_active = 1 ORDER BY name LIMIT 1000')->fetchAll();
$cur = null;
foreach ($all as $p) {
    if ((int)$p['id'] === $pid) {
        $cur = $p;
    }
}
if ($cur === null && $pid > 0) {   // 비활성 거래처도 원장은 볼 수 있게
    $st = $pdo->prepare('SELECT id, name, business_no, is_credit FROM erp_partners WHERE id = ?');
    $st->execute([$pid]);
    $cur = $st->fetch() ?: null;
}

page_header('거래처 원장', 'ledger');
?>
<div class="card no-print">
  <form method="get">
    <label>거래처
      <select name="partner" onchange="this.form.submit()">
        <option value="0">전체 미수금 현황</option>
        <?php foreach ($all as $p): ?>
          <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $pid ? ' selected' : '' ?>>
            <?= h($p['name']) ?><?= (int)$p['is_credit'] === 1 ? ' (외상)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($cur): ?>
      <label>기간 <input type="date" name="from" value="<?= h($from) ?>"> ~ <input type="date" name="to" value="<?= h($to) ?>"></label>
      <button class="btn btn-sm" type="submit">조회</button>
    <?php endif; ?>
  </form>
</div>

<?php if ($cur === null): ?>
<?php $sum = lg_summary(); $tot = 0; ?>
<div class="lgb-printhead"><h2>외상 거래처 미수금 현황</h2><p>출력일 <?= h(date('Y-m-d')) ?></p></div>
<div class="card">
  <h2>외상 거래처 미수금 현황</h2>
  <table>
    <tr><th>거래처</th><th>사업자번호</th><th>기초잔액</th><th>매출(기준일 이후)</th><th>입금</th><th>미수금</th><th class="no-print"></th></tr>
    <?php foreach ($sum as $r): $tot += $r['bal']; ?>
    <tr>
      <td><?= h($r['name']) ?></td>
      <td><?= h($r['biz'] !== '' ? fmt_biz_no($r['biz']) : '') ?></td>
      <td style="text-align:right"><?= h(fmt_won($r['open'])) ?><?= $r['as_of'] ? ' <small>(' . h((string)$r['as_of']) . ')</small>' : '' ?></td>
      <td style="text-align:right"><?= h(fmt_won($r['sale'])) ?></td>
      <td style="text-align:right"><?= h(fmt_won($r['pay'])) ?></td>
      <td style="text-align:right"><strong<?= $r['bal'] < 0 ? ' class="err"' : '' ?>><?= h(fmt_won($r['bal'])) ?></strong></td>
      <td class="no-print"><a href="ledger.php?partner=<?= (int)$r['id'] ?>">원장</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$sum): ?><tr><td colspan="7">외상으로 지정된 거래처가 없습니다. 위에서 거래처를 고르면 외상 지정을 할 수 있습니다.</td></tr><?php endif; ?>
    <?php if ($sum): ?><tr><td colspan="5"><strong>미수금 합계</strong></td><td style="text-align:right"><strong><?= h(fmt_won($tot)) ?></strong></td><td class="no-print"></td></tr><?php endif; ?>
  </table>
</div>

<?php else: ?>
<?php
$isCredit = (int)$cur['is_credit'] === 1;
[$oOpen, $oAsOf] = lg_opening((int)$cur['id']);
?>
<?php if ($isCredit): $L = lg_ledger((int)$cur['id'], $from, $to); ?>
<div class="lgb-printhead"><h2>거래처 원장 - <?= h($cur['name']) ?></h2><p>기간 <?= h($from) ?> ~ <?= h($to) ?> / 출력일 <?= h(date('Y-m-d')) ?></p></div>
<div class="card">
  <h2><?= h($cur['name']) ?> 원장 <small><?= h($from) ?> ~ <?= h($to) ?></small></h2>
  <table>
    <tr><th>일자</th><th>구분</th><th>전표/메모</th><th>매출(청구)</th><th>입금</th><th>잔액</th></tr>
    <tr><td colspan="5"><strong>이월 잔액</strong></td><td style="text-align:right"><strong><?= h(fmt_won($L['carry'])) ?></strong></td></tr>
    <?php foreach ($L['rows'] as $r): ?>
    <tr>
      <td><?= h($r['date']) ?></td>
      <td><?= h($r['kind']) ?></td>
      <td>
        <?php if ($r['kind'] === '매출'): ?>
          <a href="trade_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['no']) ?></a> <?= h(channel_label($r['channel'])) ?>
        <?php else: ?>
          <?= h($r['memo']) ?>
        <?php endif; ?>
      </td>
      <td style="text-align:right"><?= $r['sale'] ? h(fmt_won($r['sale'])) : '' ?></td>
      <td style="text-align:right"><?= $r['pay'] ? h(fmt_won($r['pay'])) : '' ?></td>
      <td style="text-align:right"><?= h(fmt_won($r['bal'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr>
      <td colspan="3"><strong>기간 합계 / 기말 잔액</strong></td>
      <td style="text-align:right"><strong><?= h(fmt_won($L['sale_sum'])) ?></strong></td>
      <td style="text-align:right"><strong><?= h(fmt_won($L['pay_sum'])) ?></strong></td>
      <td style="text-align:right"><strong><?= h(fmt_won($L['close'])) ?></strong></td>
    </tr>
  </table>
  <p><small>
    매출은 '확정' 전표만 반영합니다(임시·취소 제외).
    <?= $oAsOf ? '기초잔액 기준일(' . h($oAsOf) . ') 이전 내역은 계산에 넣지 않고 기초잔액으로 대신합니다. 기준일 당일 내역도 기초잔액에 포함된 것으로 봅니다.' : '' ?>
  </small></p>
</div>
<?php else: ?>
<div class="card no-print"><p>이 거래처는 외상 거래처로 지정되어 있지 않습니다.<?= $isAdmin ? ' 아래에서 지정할 수 있습니다.' : ' 관리자에게 지정을 요청하세요.' ?></p></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="card no-print">
  <h2>외상 설정 (관리자)</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="partner_id" value="<?= (int)$cur['id'] ?>">
    <p><label><input type="checkbox" name="is_credit" value="1"<?= $isCredit ? ' checked' : '' ?>> 외상 거래처로 관리</label></p>
    <p>
      <label>기초잔액(원) <input type="text" name="opening" inputmode="numeric" value="<?= $oOpen ? h((string)$oOpen) : '' ?>" placeholder="이카운트 이관 시점 미수금"></label>
      <label>기준일 <input type="date" name="as_of" value="<?= h((string)($oAsOf ?? '')) ?>"></label>
    </p>
    <p><small>기준일 이후의 전표·입금부터 새로 계산합니다. 기초잔액을 바꾸면 현재 미수금이 바로 달라지므로, 변경 전후 값은 감사 로그에 남습니다.</small></p>
    <button class="btn" type="submit">저장</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
<?php
$barPartner = $cur ? (int)$cur['id'] : 0;
$barIsCredit = $cur ? ((int)$cur['is_credit'] === 1) : false;
lgbar_render(
    $barPartner,
    $from,
    $to,
    $cur === null || $barIsCredit,          // 전체 현황이거나 외상 거래처일 때만 Excel
    $cur !== null && $barIsCredit && can_write()
);
page_footer();
