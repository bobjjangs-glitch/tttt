<?php
// payments.php - 입금 등록/취소. 미실행 코드
// erp_payments 실제 구조: memo, cancel_reason, created_by 는 NOT NULL (NULL 금지, 빈 문자열/0 사용)
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ledger_lib.php';
require_login();
$pdo = db();

$notReady = lg_ready_error();
if ($notReady !== null) {
    page_header('입금 등록', 'payments');
    echo '<div class="card"><p class="err">' . h($notReady) . '</p>';
    echo '<p>DB 구조가 코드와 맞지 않습니다. 관리자에게 이 문구를 그대로 전달하세요.</p></div>';
    page_footer();
    exit;
}

if (is_post()) {
    require_write();
    csrf_check();
    $act = in_str($_POST, 'act', 10);
    $now = date('Y-m-d H:i:s');
    $me  = current_user();

    if ($act === 'add') {
        $pid    = in_int($_POST, 'partner_id');
        $date   = in_str($_POST, 'pay_date', 10);
        $amount = lg_won(in_str($_POST, 'amount', 20));
        $method = in_str($_POST, 'method', 10);
        $memo   = in_str($_POST, 'memo', 200);

        $st = $pdo->prepare('SELECT id, name FROM erp_partners WHERE id = ? AND is_credit = 1');
        $st->execute([$pid]);
        $p = $st->fetch();

        $errors = [];
        if (!$p) {
            $errors[] = '외상 거래처로 지정된 거래처만 선택할 수 있습니다.';
        }
        if (!valid_date($date)) {
            $errors[] = '입금일을 YYYY-MM-DD 형식으로 입력하세요.';
        }
        if ($amount === null || $amount <= 0) {
            $errors[] = '금액은 1원 이상의 정수로 입력하세요.';
        }
        if (!isset(LG_METHODS[$method])) {
            $errors[] = '입금 방법이 올바르지 않습니다.';
        }
        if (!$errors) {
            $bal = lg_balance($pid);
            if ($amount > $bal) {
                $errors[] = '입금액(' . fmt_won($amount) . ')이 현재 미수금(' . fmt_won($bal) . ')보다 큽니다. 금액을 확인하세요.';
            }
        }
        if ($errors) {
            foreach ($errors as $e) {
                flash($e, 'err');
            }
            redirect('payments.php?partner=' . $pid);
        }
        $ins = $pdo->prepare('INSERT INTO erp_payments (partner_id, direction, pay_date, amount, method, memo, status, created_by, created_at)
                              VALUES (?, \'in\', ?, ?, ?, ?, \'active\', ?, ?)');
        $ins->execute([$pid, $date, $amount, $method, $memo, (int)($me['id'] ?? 0), $now]);
        audit('payment_add', 'payment', (int)$pdo->lastInsertId(), 'partner=' . $pid . ' amount=' . $amount);
        flash('입금을 등록했습니다.');
        redirect('ledger.php?partner=' . $pid);
    }

    if ($act === 'cancel') {
        $id     = in_int($_POST, 'id');
        $reason = in_str($_POST, 'reason', 200);
        if ($reason === '') {
            flash('취소 사유를 입력하세요.', 'err');
            redirect('payments.php');
        }
        $up = $pdo->prepare("UPDATE erp_payments SET status = 'canceled', cancel_reason = ?, canceled_at = ? WHERE id = ? AND status = 'active'");
        $up->execute([$reason, $now, $id]);
        if ($up->rowCount() === 1) {
            audit('payment_cancel', 'payment', $id, mb_substr($reason, 0, 200));
            flash('입금을 취소 처리했습니다.');
        } else {
            flash('이미 취소되었거나 없는 입금입니다.', 'err');
        }
        redirect('payments.php');
    }
    redirect('payments.php');
}

$sel = in_int($_GET, 'partner');
$partners = $pdo->query('SELECT id, name FROM erp_partners WHERE is_credit = 1 ORDER BY name')->fetchAll();
$list = $pdo->query('SELECT y.id, y.pay_date, y.amount, y.method, y.memo, y.status, y.cancel_reason, p.name AS pname
                     FROM erp_payments y JOIN erp_partners p ON p.id = y.partner_id
                     ORDER BY y.pay_date DESC, y.id DESC LIMIT 100')->fetchAll();

page_header('입금 등록', 'payments');
?>
<?php if (can_write()): ?>
<div class="card">
  <h2>입금 등록</h2>
  <?php if (!$partners): ?>
    <p>외상 거래처가 없습니다. 거래처 원장 화면에서 관리자가 거래처를 외상으로 지정해야 합니다.</p>
  <?php else: ?>
  <form method="post" autocomplete="off"
        onsubmit="var b=this.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true;},0);">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="add">
    <p>
      <label>거래처
        <select name="partner_id" required>
          <option value="">선택</option>
          <?php foreach ($partners as $p): ?>
            <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $sel ? ' selected' : '' ?>><?= h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>입금일 <input type="date" name="pay_date" value="<?= h(date('Y-m-d')) ?>" required></label>
      <label>금액(원) <input type="text" name="amount" inputmode="numeric" placeholder="예: 1500000" required></label>
      <label>방법
        <select name="method">
          <?php foreach (LG_METHODS as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>메모 <input type="text" name="memo" maxlength="200"></label>
      <button class="btn" type="submit">등록</button>
    </p>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>최근 입금 내역 (최대 100건)</h2>
  <table>
    <tr><th>입금일</th><th>거래처</th><th>금액</th><th>방법/메모</th><th>상태</th><th></th></tr>
    <?php foreach ($list as $r): $active = $r['status'] === 'active'; ?>
    <tr<?= $active ? '' : ' class="muted"' ?>>
      <td><?= h($r['pay_date']) ?></td>
      <td><?= h($r['pname']) ?></td>
      <td style="text-align:right"><?= h(fmt_won((int)$r['amount'])) ?></td>
      <td><?= h(trim((LG_METHODS[$r['method']] ?? $r['method']) . ' ' . (string)$r['memo'])) ?></td>
      <td><?= $active ? '유효' : '취소: ' . h((string)$r['cancel_reason']) ?></td>
      <td>
        <?php if ($active && can_write()): ?>
        <form method="post" style="display:flex;gap:4px" onsubmit="return confirm('이 입금을 취소 처리할까요?');">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="cancel">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="reason" maxlength="200" placeholder="취소 사유(필수)" required>
          <button class="btn btn-sm" type="submit">취소</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="6">입금 내역이 없습니다.</td></tr><?php endif; ?>
  </table>
</div>
<?php page_footer();
