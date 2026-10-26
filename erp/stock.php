<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require __DIR__ . '/inc/stock_list.php';
$pdo = db();

$isAdmin = ((current_user()['role'] ?? '') === 'admin');

if (is_post()) {
    require_write();
    csrf_check();
    $act = in_str($_POST, 'act', 20);

    // ---- 수불부 조정 기록 삭제 (관리자 전용) ----
    if ($act === 'del_ledger') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('관리자만 삭제할 수 있습니다.');
        }
        $itemId   = in_int($_POST, 'item_id');
        $ledgerId = in_int($_POST, 'ledger_id');
        $deleted  = null;
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM stock_ledger WHERE id = ? AND item_id = ? FOR UPDATE');
            $st->execute([$ledgerId, $itemId]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException('기록을 찾을 수 없습니다. 이미 삭제되었을 수 있습니다.');
            }
            if ($row['entry_kind'] !== 'adjust' || $row['trade_line_id'] !== null) {
                throw new RuntimeException('전표와 연결된 기록은 삭제할 수 없습니다. 전표 화면에서 취소하세요.');
            }
            $pdo->prepare('DELETE FROM stock_ledger WHERE id = ? AND entry_kind = \'adjust\' AND trade_line_id IS NULL')
                ->execute([$ledgerId]);
            $pdo->commit();
            $deleted = $row;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($e->getMessage(), 'err');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ERP] delete stock ledger: ' . $e->getMessage());
            flash('삭제 중 DB 오류가 나서 취소했습니다. 아무것도 지워지지 않았습니다.', 'err');
        }
        if ($deleted !== null) {
            audit('stock_ledger_delete', 'item', $itemId,
                'ledger_id=' . $ledgerId . ' qty=' . $deleted['qty'] . ' date=' . $deleted['move_date'] . ' memo=' . $deleted['memo']);
            $st = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_ledger WHERE item_id = ?');
            $st->execute([$itemId]);
            $left = (string)$st->fetchColumn();
            if ($left !== '' && $left[0] === '-') {
                flash('조정 기록을 삭제했습니다. 단, 현재고가 마이너스(' . fmt_qty($left) . ')가 되었습니다. 확인하세요.', 'err');
            } else {
                flash('조정 기록을 삭제했습니다.');
            }
        }
        redirect('stock.php?item=' . $itemId);
    }

    // ---- 재고 조정 ----
    $itemId = in_int($_POST, 'item_id');
    $raw    = str_replace([',', ' ', "\u{00A0}"], '', in_str($_POST, 'qty', 20));
    // 모바일 키보드의 유니코드 부호를 ASCII로 통일
    $raw    = str_replace(["\u{2212}", "\u{FF0D}", "\u{2013}"], '-', $raw);
    $raw    = str_replace("\u{FF0B}", '+', $raw);
    if ($raw !== '' && $raw[0] === '+') {
        $raw = substr($raw, 1);
    }
    $reason = in_str($_POST, 'reason', 200);
    $neg    = isset($raw[0]) && $raw[0] === '-';
    $m      = qty_to_milli($neg ? substr($raw, 1) : $raw);
    $st = $pdo->prepare('SELECT COUNT(*) FROM items WHERE id = ? AND is_stock_item = 1');
    $st->execute([$itemId]);
    if (!(int)$st->fetchColumn()) {
        flash('재고 관리 품목이 아닙니다.', 'err');
    } elseif ($m === null || $m === 0) {
        flash('조정 수량은 0이 아닌 숫자여야 합니다. (감소는 -3 처럼 입력)', 'err');
    } elseif ($reason === '') {
        flash('조정 사유를 입력하세요.', 'err');
    } else {
        $signed = milli_to_str($neg ? -$m : $m);
        $pdo->prepare(
            'INSERT INTO stock_ledger (item_id, move_date, qty, trade_line_id, entry_kind, memo, created_by, created_at)
             VALUES (?, ?, ?, NULL, \'adjust\', ?, ?, ?)'
        )->execute([$itemId, date('Y-m-d'), $signed, '조정: ' . $reason, (int)current_user()['id'], date('Y-m-d H:i:s')]);
        audit('stock_adjust', 'item', $itemId, $signed . ' / ' . $reason);
        flash('재고를 조정했습니다.');
    }
    redirect('stock.php?item=' . $itemId);
}

$itemId = in_int($_GET, 'item');
$cats = category_paths(false);

if ($itemId > 0) {
    // ---- 수불부 ----
    $st = $pdo->prepare('SELECT * FROM items WHERE id = ?');
    $st->execute([$itemId]);
    $it = $st->fetch();
    if (!$it) {
        http_response_code(404);
        exit('품목을 찾을 수 없습니다.');
    }
    $st = $pdo->prepare('SELECT * FROM stock_ledger WHERE item_id = ? ORDER BY move_date, id');
    $st->execute([$itemId]);
    $rows = $st->fetchAll();
    $bal = 0;
    foreach ($rows as $k => $r) {
        $bal += (int)str_replace('.', '', (string)$r['qty']); // DECIMAL(12,3) 고정 소수 3자리
        $rows[$k]['bal'] = $bal;
    }
    $rows = array_reverse($rows);
    $colCount = $isAdmin && can_write() ? 7 : 6;

    page_header('수불부', 'stock');
    ?>
<h1>수불부 · <?= h($it['name']) ?> <small><?= h($it['spec']) ?></small></h1>
<p>현재고: <strong><?= h(fmt_qty(milli_to_str($bal))) ?></strong> <?= h($it['unit']) ?> · <a href="stock.php">← 현재고 목록</a></p>
<?php if (can_write() && (int)$it['is_stock_item']): ?>
<form method="post" class="card toolbar" onsubmit="var b=this.querySelector('button[type=submit]'); if (b) { b.disabled = true; }">
  <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
  <input name="qty" placeholder="조정수량 (+5 / -3)" required>
  <input name="reason" placeholder="사유 (실사 차이 등)" required maxlength="200">
  <button class="btn" type="submit">재고 조정</button>
</form>
<?php endif; ?>
<table class="table">
  <thead><tr><th>일자</th><th>구분</th><th class="num">증감</th><th class="num">잔량</th><th>메모</th><th>기록시각</th><?php if ($colCount === 7): ?><th class="no-print">삭제</th><?php endif; ?></tr></thead>
  <tbody>
  <?php $kind = ['post' => '전표', 'reverse' => '취소', 'adjust' => '조정']; foreach ($rows as $r):
      $canDel = $colCount === 7 && $r['entry_kind'] === 'adjust' && $r['trade_line_id'] === null; ?>
    <tr><td><?= h($r['move_date']) ?></td><td><?= h($kind[$r['entry_kind']] ?? $r['entry_kind']) ?></td>
        <td class="num"><?= h(fmt_qty($r['qty'])) ?></td><td class="num"><?= h(fmt_qty(milli_to_str($r['bal']))) ?></td>
        <td><?= h($r['memo']) ?></td><td><?= h($r['created_at']) ?></td>
        <?php if ($colCount === 7): ?>
        <td class="no-print">
          <?php if ($canDel): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('이 조정 기록을 삭제합니다. 되돌릴 수 없습니다. 계속할까요?');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_ledger">
            <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
            <input type="hidden" name="ledger_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm" type="submit">삭제</button>
          </form>
          <?php else: ?>
          <span title="전표와 연결된 기록은 전표 화면에서 취소하세요">-</span>
          <?php endif; ?>
        </td>
        <?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="<?= (int)$colCount ?>" class="empty">입출고 기록이 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
    <?php
    page_footer();
    exit;
}

// ---- 현재고 목록 ----
$res     = stock_fetch($pdo, $_GET, STOCK_LIST_MAX);
$rows    = $res['rows'];
$q       = $res['q'];
$onlyNeg = $res['neg'];
$catSel  = $res['cat'];
$exportQs = http_build_query(array_filter([
    'q'   => $q,
    'neg' => $onlyNeg ? '1' : '',
    'cat' => $catSel > 0 ? (string)$catSel : '',
], static fn($v) => $v !== ''));

page_header('재고', 'stock');
?>
<h1>현재고</h1>
<?php cat_tree_open($pdo, 'stock.php', $catSel, true); ?>
<form method="get" class="toolbar no-print">
  <?php if ($catSel > 0): ?><input type="hidden" name="cat" value="<?= (int)$catSel ?>"><?php endif; ?>
  <input name="q" value="<?= h($q) ?>" placeholder="품목명 / 코드 / 규격">
  <label class="check"><input type="checkbox" name="neg" value="1" <?= $onlyNeg ? 'checked' : '' ?>> 마이너스 재고만</label>
  <button class="btn" type="submit">조회</button>
</form>
<?php if ($res['more']): ?>
<p class="hint">(최대 <?= (int)STOCK_LIST_MAX ?>건까지만 표시됩니다. 검색어나 분류로 좁혀 주세요. 인쇄는 표시된 건만 나옵니다.)</p>
<?php endif; ?>
<table class="table">
  <thead><tr><th>코드</th><th>품목</th><th>분류</th><th>규격</th><th class="num">현재고</th><th class="num">공장도가</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $qtyStr = (string)$r['qty'];
      $isNeg  = $qtyStr !== '' && $qtyStr[0] === '-'; ?>
    <tr class="<?= $isNeg ? 'danger-row' : '' ?>">
      <td><?= h($r['item_code']) ?></td><td><?= h($r['name']) ?></td>
      <td><?= h($cats[(int)$r['category_id']] ?? '-') ?></td><td><?= h($r['spec']) ?></td>
      <td class="num"><?= h(fmt_qty($r['qty'])) ?></td><td class="num"><?= fmt_won($r['factory_price']) ?></td>
      <td><a href="stock.php?item=<?= (int)$r['id'] ?>">수불부</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">조건에 맞는 품목이 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
cat_tree_close();
require_once __DIR__ . '/inc/list_bar.php';
listbar_render([
    'title'     => '현재고 현황',
    'new_url'   => 'items.php?new=1',
    'excel_url' => 'stock_excel.php' . ($exportQs !== '' ? '?' . $exportQs : ''),
]);
page_footer();
