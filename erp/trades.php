<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/trades_query.php';
require_once __DIR__ . '/inc/footbar.php';
$pdo = db();

$P    = tq_params($_GET);
$rows = tq_fetch($pdo, $P, TQ_LIST_MAX);

$isAdmin  = (current_user()['role'] ?? '') === 'admin';
$isSale   = $P['type'] === 'sale';
$title    = $isSale ? '판매전표' : '매입전표';
$newUrl   = can_write() ? 'trade_form.php?type=' . $P['type'] : '';
$excelUrl = 'trades_excel.php?' . http_build_query(tq_qs($P));

$confirmedSum = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'confirmed') {
        $confirmedSum += (int)$r['total_amount'];
    }
}
$colspan = 9 + ($isAdmin ? 1 : 0);

page_header($title, 'trades');
?>
<h2 style="margin:0 0 12px" class="no-print"><?= h($title) ?></h2>

<form method="get" class="toolbar no-print" data-fb-search
      style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px">
  <input type="hidden" name="type" value="<?= h($P['type']) ?>">
  <select name="status">
    <option value="">전체 상태</option>
    <?php foreach (['draft', 'confirmed', 'canceled'] as $s): ?>
      <option value="<?= h($s) ?>" <?= $P['status'] === $s ? 'selected' : '' ?>><?= h(status_label($s)) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($isSale): ?>
    <select name="channel">
      <option value="">소매+도매</option>
      <?php foreach (['retail', 'wholesale'] as $c): ?>
        <option value="<?= h($c) ?>" <?= $P['channel'] === $c ? 'selected' : '' ?>><?= h(channel_label($c)) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
  <input type="date" name="from" value="<?= h($P['from']) ?>">
  <span>~</span>
  <input type="date" name="to" value="<?= h($P['to']) ?>">
  <input type="text" name="q" value="<?= h($P['q']) ?>" maxlength="50" placeholder="전표번호 / 거래처 / 고객 / 차량번호">
  <button class="btn" type="submit">조회</button>
</form>

<div class="fb-print-title">
  <h2 style="margin:0 0 4px"><?= h($title) ?></h2>
  <div><?= h($P['from']) ?> ~ <?= h($P['to']) ?><?= $P['status'] !== '' ? ' · ' . h(status_label($P['status'])) : '' ?><?= $P['q'] !== '' ? ' · 검색: ' . h($P['q']) : '' ?></div>
</div>

<div class="card" style="padding:0;overflow:auto">
  <table style="width:100%">
    <thead>
      <tr>
        <?php if ($isAdmin): ?>
          <th class="fb-chk" style="width:36px;text-align:center"><input type="checkbox" id="fbAll" title="전체 선택"></th>
        <?php endif; ?>
        <th style="text-align:left">전표번호</th>
        <th>일자</th>
        <th>구분</th>
        <th style="text-align:left">거래처/고객</th>
        <th>차량번호</th>
        <th style="text-align:right">공급가액</th>
        <th style="text-align:right">부가세</th>
        <th style="text-align:right">합계</th>
        <th>상태</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="<?= (int)$colspan ?>" style="text-align:center;padding:24px">조건에 맞는 전표가 없습니다.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r):
        if ($r['trade_type'] === 'purchase') {
            $kind = '매입';
        } elseif (($r['channel'] ?? '') !== '') {
            $kind = channel_label($r['channel']);
        } else {
            $kind = '-';
        }
        $who = ($r['partner_name'] ?? '') !== '' ? $r['partner_name'] : ($r['customer_name'] ?? '');
        $deletable = in_array($r['status'], ['draft', 'canceled'], true);
    ?>
      <tr class="<?= $r['status'] === 'canceled' ? 'muted' : '' ?>">
        <?php if ($isAdmin): ?>
          <td class="fb-chk" style="text-align:center">
            <?php if ($deletable): ?>
              <input type="checkbox" class="fb-row-chk" value="<?= (int)$r['id'] ?>">
            <?php endif; ?>
          </td>
        <?php endif; ?>
        <td><a href="trade_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['trade_no']) ?></a></td>
        <td style="text-align:center"><?= h($r['trade_date']) ?></td>
        <td style="text-align:center"><?= h($kind) ?></td>
        <td><?= h($who) ?></td>
        <td style="text-align:center"><?= h($r['vehicle_no'] ?? '') ?></td>
        <td style="text-align:right"><?= h(fmt_won($r['supply_amount'])) ?></td>
        <td style="text-align:right"><?= h(fmt_won($r['vat_amount'])) ?></td>
        <td style="text-align:right"><?= h(fmt_won($r['total_amount'])) ?></td>
        <td style="text-align:center"><?= h(status_label((string)$r['status'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="<?= (int)$colspan ?>" style="text-align:right;padding:10px 12px">
          표시 <?= number_format(count($rows)) ?>건 · 확정 전표 합계 (표시된 건 기준)
          <b><?= h(fmt_won($confirmedSum)) ?></b>
        </td>
      </tr>
    </tfoot>
  </table>
</div>

<?php if (count($rows) >= TQ_LIST_MAX): ?>
  <p class="no-print" style="color:#6b7280">최대 <?= (int)TQ_LIST_MAX ?>건까지만 표시됩니다. 인쇄도 표시된 건만 나옵니다. 기간이나 검색어로 좁혀 주세요. Excel은 최대 <?= number_format(TQ_EXCEL_MAX) ?>건까지 받을 수 있습니다.</p>
<?php endif; ?>
<?php if ($isAdmin): ?>
  <p class="no-print" style="color:#6b7280">선택 삭제는 임시·취소 전표만 체크할 수 있습니다. 확정 전표는 상세 화면에서 먼저 취소하세요.</p>
<?php endif; ?>

<?php
footbar_render([
    'new_url'       => $newUrl,
    'excel_url'     => $excelUrl,
    'delete_action' => $isAdmin ? 'trades_bulk_delete.php' : '',
    'delete_hidden' => ['type' => $P['type']],
]);
page_footer();
