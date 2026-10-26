<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();
$id = is_post() ? in_int($_POST, 'id') : in_int($_GET, 'id');

/** 소수 문자열("4.000", "-2.500") → 1/1000 단위 정수 */
function tv_milli(string $s): int
{
    $s = trim($s);
    $neg = false;
    if ($s !== '' && $s[0] === '-') {
        $neg = true;
        $s = substr($s, 1);
    }
    $p    = explode('.', $s, 2);
    $int  = preg_replace('/\D/', '', $p[0]) ?? '';
    $frac = substr(str_pad(preg_replace('/\D/', '', $p[1] ?? '') ?? '', 3, '0'), 0, 3);
    $v    = (int)($int === '' ? '0' : $int) * 1000 + (int)$frac;
    return $neg ? -$v : $v;
}

/** 1/1000 단위 정수 → 표시 문자열 (천 단위 콤마, 끝의 0 제거) */
function tv_qty(int $m): string
{
    $neg = $m < 0;
    $m   = abs($m);
    $s   = number_format(intdiv($m, 1000));
    $f   = $m % 1000;
    if ($f !== 0) {
        $s .= '.' . rtrim(str_pad((string)$f, 3, '0', STR_PAD_LEFT), '0');
    }
    return ($neg ? '-' : '') . $s;
}

/** 비고만 저장. 수량·금액·재고·상태는 건드리지 않는다. 취소 전표는 거부 */
function tv_save_memo(PDO $pdo, int $id, array $post, int $uid): void
{
    $clip = function ($v, int $max): string {
        return mb_substr(trim((string)$v), 0, $max);
    };
    $memos = (isset($post['memo']) && is_array($post['memo'])) ? $post['memo'] : [];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT status FROM trades WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('전표를 찾을 수 없습니다.');
        }
        if ((string)$row['status'] === 'canceled') {
            throw new RuntimeException('취소된 전표는 비고를 수정할 수 없습니다.');
        }

        $st = $pdo->prepare('SELECT id FROM trade_lines WHERE trade_id = ?');
        $st->execute([$id]);
        $own = array_flip(array_map('intval', array_column($st->fetchAll(), 'id')));

        $up = $pdo->prepare('UPDATE trade_lines SET memo = ? WHERE id = ? AND trade_id = ?');
        foreach ($memos as $lineId => $text) {
            $lineId = (int)$lineId;
            if (isset($own[$lineId])) {              // 이 전표의 줄만 수정
                $up->execute([$clip($text, 200), $lineId, $id]);
            }
        }
        if (array_key_exists('trade_memo', $post)) {
            $pdo->prepare('UPDATE trades SET memo = ?, updated_at = ? WHERE id = ?')
                ->execute([$clip($post['trade_memo'], 500), date('Y-m-d H:i:s'), $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    audit('trade_memo', 'trades', $id, '비고 수정 (사용자 ' . $uid . ')');
}

/**
 * 취소된 전표 삭제 (관리자 전용). 되돌릴 수 없음.
 * 안전장치: 취소 상태만, 재고 이력의 품목별 합이 0일 때만 삭제.
 * 삭제 순서: 재고 이력 → 전표 줄 → 전표. 전표번호를 반환.
 */
function tv_delete_canceled(PDO $pdo, int $id, int $uid): string
{
    $me = current_user();
    if (($me['role'] ?? '') !== 'admin') {
        throw new RuntimeException('취소된 전표의 삭제는 관리자만 할 수 있습니다.');
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT trade_no, status, total_amount, cancel_reason FROM trades WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('전표를 찾을 수 없습니다.');
        }
        if ((string)$row['status'] !== 'canceled') {
            throw new RuntimeException('취소된 전표만 삭제할 수 있습니다. 먼저 전표를 취소하세요.');
        }

        $st = $pdo->prepare('SELECT id FROM trade_lines WHERE trade_id = ?');
        $st->execute([$id]);
        $lineIds = array_map('intval', array_column($st->fetchAll(), 'id'));

        if ($lineIds) {
            $ph = implode(',', array_fill(0, count($lineIds), '?'));
            $st = $pdo->prepare("SELECT item_id, SUM(qty) AS q FROM stock_ledger WHERE trade_line_id IN ($ph) GROUP BY item_id");
            $st->execute($lineIds);
            foreach ($st->fetchAll() as $r) {
                if (tv_milli((string)$r['q']) !== 0) {
                    throw new RuntimeException('재고 이력의 합이 0이 아니어서 삭제할 수 없습니다. 재고가 틀어질 수 있으니 관리자에게 문의하세요.');
                }
            }
            $pdo->prepare("DELETE FROM stock_ledger WHERE trade_line_id IN ($ph)")->execute($lineIds);
        }
        $pdo->prepare('DELETE FROM trade_lines WHERE trade_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM trades WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ERP] delete canceled trade: ' . get_class($e) . ' ' . $e->getMessage());
        throw new RuntimeException('삭제 중 DB 오류가 나서 전부 취소했습니다. 아무것도 지워지지 않았습니다.');
    }
    $no = (string)$row['trade_no'];
    audit('trade_delete', 'trades', $id, mb_substr($no . ' 합계 ' . (int)$row['total_amount'] . ' 사유: ' . (string)$row['cancel_reason'], 0, 400));
    return $no;
}

$st = $pdo->prepare(
    'SELECT t.*, p.name AS partner_name, p.business_no, p.ceo_name, p.address AS p_address, p.phone AS p_phone
       FROM trades t LEFT JOIN partners p ON p.id = t.partner_id WHERE t.id = ?'
);
$st->execute([$id]);
$t = $st->fetch();
if (!$t) {
    http_response_code(404);
    exit('전표를 찾을 수 없습니다.');
}

if (is_post()) {
    require_write();
    csrf_check();
    $act = in_str($_POST, 'act', 20);
    $uid = (int)current_user()['id'];
    try {
        if ($act === 'confirm') {
            confirm_trade($id, $uid);
            flash('전표를 확정했습니다. 재고가 반영되었습니다.');
        } elseif ($act === 'cancel') {
            cancel_trade($id, $uid, in_str($_POST, 'reason', 200));
            flash('전표를 취소했습니다. 재고를 되돌렸습니다.');
        } elseif ($act === 'delete') {
            delete_draft($id);
            flash('임시 전표를 삭제했습니다.');
            redirect('trades.php?type=' . $t['trade_type']);
        } elseif ($act === 'purge') {
            $no = tv_delete_canceled($pdo, $id, $uid);
            flash('취소된 전표 ' . $no . '을(를) 삭제했습니다.');
            redirect('trades.php?type=' . $t['trade_type']);
        } elseif ($act === 'memo') {
            tv_save_memo($pdo, $id, $_POST, $uid);
            flash('비고를 저장했습니다.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('trade_view.php?id=' . $id);
}

$st = $pdo->prepare('SELECT * FROM trade_lines WHERE trade_id = ? ORDER BY line_no');
$st->execute([$id]);
$lines = $st->fetchAll();
$isSale = $t['trade_type'] === 'sale';

// 임시 원가(최근 매입단가 스냅샷) 기준 마진 — 관리자에게만 보이는 참고값
$costTotal = 0;
if ($isSale && $t['status'] === 'confirmed') {
    foreach ($lines as $l) {
        $qm = (int)str_replace('.', '', (string)$l['qty']);
        $costTotal += intdiv((int)$l['cost_price'] * $qm + 500, 1000);
    }
}

if (isset($_GET['print'])) {
    $me = cfg('company', []);
    $other = [
        'name' => $t['partner_name'] ?: $t['customer_name'] ?: '-',
        'biz'  => fmt_biz_no($t['business_no'] ?? ''),
        'ceo'  => $t['ceo_name'] ?? '',
        'addr' => $t['p_address'] ?? '',
        'tel'  => $t['p_phone'] ?? '',
    ];
    $self = ['name' => $me['name'] ?? '', 'biz' => $me['biz_no'] ?? '', 'ceo' => $me['ceo'] ?? '', 'addr' => $me['address'] ?? '', 'tel' => $me['tel'] ?? ''];
    $supplier = $isSale ? $self : $other;
    $buyer = $isSale ? $other : $self;
    ?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title><?= h($t['trade_no']) ?> 거래명세서</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="print-body">
<div class="no-print" style="padding:8px"><button class="btn btn-primary" onclick="window.print()">인쇄</button></div>
<div class="print-sheet">
  <h1>거 래 명 세 서 <?= $t['status'] !== 'confirmed' ? '<small>(' . h(status_label($t['status'])) . ' · 효력 없음)</small>' : '' ?></h1>
  <p>전표번호 <?= h($t['trade_no']) ?> / 일자 <?= h($t['trade_date']) ?><?= $t['vehicle_no'] !== '' ? ' / 차량 ' . h($t['vehicle_no']) : '' ?></p>
  <table class="party"><tr>
    <?php foreach ([['공급자', $supplier], ['공급받는자', $buyer]] as $pp): ?>
    <td><strong><?= h($pp[0]) ?></strong><br>상호: <?= h($pp[1]['name']) ?><br>사업자번호: <?= h($pp[1]['biz']) ?><br>대표: <?= h($pp[1]['ceo']) ?><br>주소: <?= h($pp[1]['addr']) ?><br>전화: <?= h($pp[1]['tel']) ?></td>
    <?php endforeach; ?>
  </tr></table>
  <table class="table">
    <thead><tr><th>No</th><th>품목</th><th>규격</th><th class="num">수량</th><th class="num">단가</th><th class="num">공급가액</th><th>비고</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr><td><?= (int)$l['line_no'] ?></td><td><?= h($l['item_name']) ?></td><td><?= h($l['spec']) ?></td>
          <td class="num"><?= h(fmt_qty($l['qty'])) ?></td><td class="num"><?= fmt_won($l['unit_price']) ?></td>
          <td class="num"><?= fmt_won($l['amount']) ?></td><td><?= h($l['memo']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="5" class="num">공급가액</td><td class="num"><?= fmt_won($t['supply_amount']) ?></td><td></td></tr>
      <tr><td colspan="5" class="num">부가세</td><td class="num"><?= fmt_won($t['vat_amount']) ?></td><td></td></tr>
      <tr><td colspan="5" class="num"><strong>합계</strong></td><td class="num"><strong><?= fmt_won($t['total_amount']) ?></strong></td><td></td></tr>
    </tfoot>
  </table>
  <?php if ($t['memo'] !== ''): ?><p>비고: <?= h($t['memo']) ?></p><?php endif; ?>
  <p class="hint">본 문서는 거래명세서이며 전자세금계산서가 아닙니다.</p>
</div></body></html>
    <?php
    exit;
}

/* ---------- 재고 수량 (화면 표시용, 읽기 전용) ---------- */
$isDraft      = $t['status'] === 'draft';
$isConfirmed  = $t['status'] === 'confirmed';
$isCanceled   = $t['status'] === 'canceled';
$canWrite     = can_write();
$isAdmin      = (current_user()['role'] ?? '') === 'admin';
$editableMemo = $canWrite && !$isCanceled;
$isStockItem  = [];
$stockNow     = [];
$delta        = [];   // 임시 전표가 확정되면 품목별로 늘고 줄 수량
$itemIds = array_values(array_unique(array_map('intval', array_column($lines, 'item_id'))));
if ($itemIds) {
    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    $st = $pdo->prepare("SELECT id, is_stock_item FROM items WHERE id IN ($ph)");
    $st->execute($itemIds);
    foreach ($st->fetchAll() as $r) {
        $isStockItem[(int)$r['id']] = ((int)$r['is_stock_item'] === 1);
    }
    $st = $pdo->prepare("SELECT item_id, SUM(qty) AS q FROM stock_ledger WHERE item_id IN ($ph) GROUP BY item_id");
    $st->execute($itemIds);
    foreach ($st->fetchAll() as $r) {
        $stockNow[(int)$r['item_id']] = tv_milli((string)$r['q']);
    }
    if ($isDraft) {
        foreach ($lines as $l) {
            $iid = (int)$l['item_id'];
            $m   = tv_milli((string)$l['qty']);
            $delta[$iid] = ($delta[$iid] ?? 0) + ($isSale ? -$m : $m);
        }
    }
}
$tailCols = ($isDraft ? 3 : 2);   // 현재고(+확정 후 예상) + 비고

require_once __DIR__ . '/inc/bottom_bar.php';

page_header('전표 ' . $t['trade_no'], $t['trade_type']);
?>
<style>
.tv-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.tv-head h1{margin:0}
</style>

<div class="tv-head">
  <h1><?= h($t['trade_no']) ?> <span class="badge <?= h($t['status']) ?>"><?= h(status_label($t['status'])) ?></span></h1>
  <?php if ($canWrite && $isDraft): ?>
    <form method="post" class="no-print" onsubmit="return confirm('임시 전표를 삭제할까요?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="delete"><button class="btn btn-sm" type="submit">삭제</button></form>
  <?php elseif ($isCanceled && $isAdmin): ?>
    <form method="post" class="no-print" onsubmit="return confirm('취소된 전표 <?= h($t['trade_no']) ?>을(를) 완전히 삭제합니다. 되돌릴 수 없습니다. 계속할까요?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="purge"><button class="btn btn-sm" type="submit">삭제</button></form>
  <?php endif; ?>
</div>
<div class="card kv">
  <div>구분: <?= $isSale ? '판매(' . h(channel_label($t['channel'])) . ')' : '매입' ?></div>
  <div>일자: <?= h($t['trade_date']) ?></div>
  <div>거래처: <?= h($t['partner_name'] ?: '-') ?></div>
  <?php if ($isSale): ?><div>고객: <?= h($t['customer_name'] ?: '-') ?> / 차량: <?= h($t['vehicle_no'] ?: '-') ?></div><?php endif; ?>
  <?php if ($t['memo'] !== ''): ?><div>메모: <?= h($t['memo']) ?></div><?php endif; ?>
  <?php if ($isCanceled): ?><div class="err">취소 사유: <?= h($t['cancel_reason']) ?> (<?= h($t['canceled_at']) ?>)</div><?php endif; ?>
</div>

<?php if ($editableMemo): ?>
<form method="post" action="trade_view.php" id="memoForm">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="act" value="memo">
<?php endif; ?>
<table class="table">
  <thead><tr><th>No</th><th>품목</th><th>규격</th><th class="num">수량</th><th class="num">단가</th><th class="num">공급가액</th><th class="num">현재고</th><?php if ($isDraft): ?><th class="num">확정 후 예상</th><?php endif; ?><th>비고</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <?php $iid = (int)$l['item_id']; $stockOn = !empty($isStockItem[$iid]); $now = $stockNow[$iid] ?? 0; ?>
    <tr><td><?= (int)$l['line_no'] ?></td><td><?= h($l['item_name']) ?></td><td><?= h($l['spec']) ?></td>
        <td class="num"><?= h(fmt_qty($l['qty'])) ?></td><td class="num"><?= fmt_won($l['unit_price']) ?></td>
        <td class="num"><?= fmt_won($l['amount']) ?></td>
        <td class="num"><?= $stockOn ? h(tv_qty($now)) : '-' ?></td>
        <?php if ($isDraft): ?>
          <?php $proj = $now + ($delta[$iid] ?? 0); ?>
          <td class="num<?= ($stockOn && $proj < 0) ? ' err' : '' ?>"><?= $stockOn ? h(tv_qty($proj)) : '-' ?></td>
        <?php endif; ?>
        <td><?php if ($editableMemo): ?><input name="memo[<?= (int)$l['id'] ?>]" maxlength="200" style="width:100%" value="<?= h($l['memo']) ?>"><?php else: ?><?= h($l['memo']) ?><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><td colspan="5" class="num">공급가액</td><td class="num"><?= fmt_won($t['supply_amount']) ?></td><td colspan="<?= $tailCols ?>"></td></tr>
    <tr><td colspan="5" class="num">부가세</td><td class="num"><?= fmt_won($t['vat_amount']) ?></td><td colspan="<?= $tailCols ?>"></td></tr>
    <tr><td colspan="5" class="num"><strong>합계</strong></td><td class="num"><strong><?= fmt_won($t['total_amount']) ?></strong></td><td colspan="<?= $tailCols ?>"></td></tr>
  </tfoot>
</table>
<?php if ($editableMemo): ?>
  <p class="no-print"><label>전표 비고 <input name="trade_memo" maxlength="500" size="70" value="<?= h($t['memo']) ?>"></label></p>
</form>
<?php endif; ?>

<?php if ($itemIds): ?>
<p class="hint">현재고는 재고 원장의 합계입니다.<?= $isDraft ? ' 확정 후 예상은 이 전표를 확정했을 때의 값이며, 같은 품목이 여러 줄이면 합산합니다.' : '' ?></p>
<?php endif; ?>

<?php if ($isAdmin && $isSale && $isConfirmed): ?>
<p class="hint">참고(관리자 전용): 최근 매입단가 기준 임시 원가 <?= fmt_won($costTotal) ?>원 / 임시 마진 <?= fmt_won((int)$t['supply_amount'] - $costTotal) ?>원. 이동평균 원가는 2단계에서 교체합니다.</p>
<?php endif; ?>

<?php if (!$canWrite): ?>
  <p class="hint no-print">현재 계정(<?= h((string)(current_user()['role'] ?? '')) ?>)은 읽기 전용이라 수정·삭제·확정 버튼이 표시되지 않습니다.</p>
<?php elseif ($isConfirmed): ?>
  <p class="hint no-print">확정 전표는 재고 이력과 연결되어 있어 수량·금액을 직접 고칠 수 없습니다. 비고는 위 표에서 수정할 수 있습니다. 내용을 바꾸거나 삭제하려면 하단 [상태처리 ▲] 메뉴에서 [전표 취소]를 먼저 하세요. 취소 후에는 관리자가 삭제할 수 있습니다.</p>
<?php elseif ($isCanceled): ?>
  <p class="hint no-print">취소된 전표입니다. 삭제는 관리자만 할 수 있고, 삭제하면 되돌릴 수 없습니다.</p>
<?php endif; ?>

<?php ebar_assets(); ?>
<div class="ebar-spacer no-print"></div>
<div class="ebar no-print">
  <a class="ebar-btn" href="trades.php?type=<?= h($t['trade_type']) ?>">목록</a>

  <?php if ($editableMemo): ?>
    <button class="ebar-btn pri" type="submit" form="memoForm">비고 저장</button>
  <?php endif; ?>

  <?php if ($canWrite): ?>
  <div class="ebar-dd">
    <button class="ebar-btn" type="button" data-dd>상태처리 ▲</button>
    <div class="ebar-menu">
      <?php if ($isDraft): ?>
        <a class="ebar-item" href="trade_form.php?id=<?= (int)$id ?>">수정</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="confirm"><button class="ebar-item" type="submit">확정 (재고 반영)</button></form>
        <form method="post" onsubmit="return confirm('임시 전표를 삭제할까요?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="delete"><button class="ebar-item" type="submit">삭제</button></form>
      <?php elseif ($isConfirmed): ?>
        <button class="ebar-item" type="button" disabled title="확정 전표는 직접 수정할 수 없습니다.">수정 (확정 전표 불가)</button>
        <button class="ebar-item" type="button" disabled title="확정 전표는 취소 후에만 삭제할 수 있습니다.">삭제 (취소 후 가능)</button>
        <form method="post" onsubmit="return confirm('전표를 취소하고 재고를 되돌립니다. 계속할까요?')">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="cancel">
          <input class="ebar-reason" name="reason" placeholder="취소 사유 (필수)" required maxlength="200">
          <button class="ebar-item" type="submit">전표 취소</button>
        </form>
      <?php elseif ($isCanceled): ?>
        <?php if ($isAdmin): ?>
          <form method="post" onsubmit="return confirm('취소된 전표 <?= h($t['trade_no']) ?>을(를) 완전히 삭제합니다. 되돌릴 수 없습니다. 계속할까요?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="purge"><button class="ebar-item" type="submit">삭제 (되돌릴 수 없음)</button></form>
        <?php else: ?>
          <div class="ebar-note">취소된 전표의 삭제는 관리자만 할 수 있습니다.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="ebar-dd">
    <button class="ebar-btn" type="button" data-dd>인쇄 ▲</button>
    <div class="ebar-menu">
      <a class="ebar-item" href="trade_view.php?id=<?= (int)$id ?>&amp;print=1" target="_blank" rel="noopener">거래명세서 인쇄</a>
    </div>
  </div>

  <div class="ebar-dd">
    <button class="ebar-btn" type="button" data-dd>Excel ▲</button>
    <div class="ebar-menu">
      <a class="ebar-item" href="trade_excel.php?id=<?= (int)$id ?>">엑셀 다운로드</a>
      <button class="ebar-item" type="button" disabled title="준비중: 금액·부가세 계산을 전표 입력 화면과 공유한 뒤 만들 예정">엑셀 업로드 (준비중)</button>
    </div>
  </div>

  <?php if ($canWrite && $isSale && $isConfirmed): ?>
    <button class="ebar-btn" type="button" disabled title="2단계에서 구현 예정">세금계산서 (2단계)</button>
  <?php endif; ?>
</div>
<?php page_footer();
