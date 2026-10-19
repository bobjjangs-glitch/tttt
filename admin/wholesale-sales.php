<?php
// admin/wholesale-sales.php — 도매매출 관리 (일반 도매 · 로컬 거래처) [v43]
// 온라인 도매주문(사업자 택배) 외에, 거래처에서 전화 등으로 직접 주문하는
// 일반 도매매출을 등록·관리한다. 일/월 정산의 도매매출(도매 그룹)에 합산된다.
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('wholesale-sales');
ensure_wholesale_sales_table();

$pdo = Database::connection();
$pageTitle = '도매매출 관리 (일반 도매)';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');

$payLabels = [
    'card'     => '💳 카드결제',
    'cash'     => '💵 현금결제',
    'transfer' => '🏦 계좌이체',
    'unpaid'   => '⏳ 외상',
];

/* ===== POST 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/wholesale-sales.php');
    }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    /* 대상 행 (수정/삭제/결제/발행 토글 공용) */
    $row = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM tt_wholesale_sales WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
    }

    /* 마감 잠금 방어 — 일정산 마감된 날짜의 행은 수정/삭제 불가 */
    if ($row && in_array($action, ['save', 'delete', 'pay_register', 'toggle_tax'], true)
        && $row['settlement_id'] !== null) {
        flash('admin_error', "{$row['sale_date']} 은(는) 일정산 마감이 완료된 날짜입니다. 마감을 해제한 뒤 수정해 주세요.");
        redirect('/admin/wholesale-sales.php?month=' . date('Y-m', strtotime((string)$row['sale_date'])));
    }

    if ($action === 'save') {
        $saleDate = (string)($_POST['sale_date'] ?? '');
        $clientName = trim((string)($_POST['client_name'] ?? ''));
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $qty = (int)($_POST['qty'] ?? 1);
        $unitPrice = (int)str_replace(',', '', (string)($_POST['unit_price'] ?? 0));
        $costAmount = (int)str_replace(',', '', (string)($_POST['cost_amount'] ?? 0));
        $payMethod = (string)($_POST['pay_method'] ?? 'unpaid');
        $payAmount = (int)str_replace(',', '', (string)($_POST['pay_amount'] ?? 0));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
            flash('admin_error', '매출 일자를 올바르게 입력해 주세요.');
            redirect('/admin/wholesale-sales.php');
        }
        if ($clientName === '' || $itemName === '') {
            flash('admin_error', '거래처명과 품목명은 필수입니다.');
            redirect('/admin/wholesale-sales.php');
        }
        if ($qty < 1 || $unitPrice < 0 || $costAmount < 0) {
            flash('admin_error', '수량·단가·매입원가를 올바르게 입력해 주세요.');
            redirect('/admin/wholesale-sales.php');
        }
        if (!isset($payLabels[$payMethod])) $payMethod = 'unpaid';

        /* 마감된 날짜로 신규 등록/수정 방지 */
        if (get_settlement($saleDate) && get_settlement($saleDate)['reopened_at'] === null) {
            flash('admin_error', "{$saleDate} 은(는) 일정산 마감이 완료된 날짜입니다. 마감을 해제한 뒤 등록해 주세요.");
            redirect('/admin/wholesale-sales.php');
        }

        $supply = $qty * $unitPrice;
        $vat = (int)round($supply * 0.1);
        $total = $supply + $vat;
        if ($payMethod === 'unpaid' || $payAmount <= 0) {
            $payAmount = $payMethod === 'unpaid' ? 0 : $total;
        }

        $fields = [
            'sale_date', 'client_name', 'client_biz_number', 'client_ceo', 'client_address',
            'item_name', 'spec', 'qty', 'unit_price', 'supply_amount', 'vat_amount', 'total_amount',
            'cost_amount', 'pay_method', 'pay_amount', 'memo',
        ];
        $vals = [
            $saleDate, $clientName, trim((string)($_POST['client_biz_number'] ?? '')),
            trim((string)($_POST['client_ceo'] ?? '')), trim((string)($_POST['client_address'] ?? '')),
            $itemName, trim((string)($_POST['spec'] ?? '')), $qty, $unitPrice, $supply, $vat, $total,
            $costAmount, $payMethod, $payAmount, trim((string)($_POST['memo'] ?? '')),
        ];

        try {
            if ($row) {
                $set = implode(', ', array_map(fn($f) => "{$f} = ?", $fields));
                $vals[] = $id;
                $pdo->prepare("UPDATE tt_wholesale_sales SET {$set}, paid_at = " . ($payMethod !== 'unpaid' ? 'NOW()' : 'NULL') . " WHERE id = ?")->execute($vals);
                AdminAuth::log((int)AdminAuth::currentAdminId(), 'wholesale_sale_update',
                    "일반 도매매출 수정 — {$saleDate} {$clientName} " . number_format($total) . '원');
                flash('admin_success', '도매매출을 수정했습니다 — ' . h($clientName) . ' ' . number_format($total) . '원 (정산 집계에 바로 반영).');
            } else {
                $cols = implode(', ', $fields) . ', created_by';
                $ph = implode(', ', array_fill(0, count($fields), '?')) . ', ?';
                $vals[] = AdminAuth::currentAdminName();
                $pdo->prepare("INSERT INTO tt_wholesale_sales ({$cols}) VALUES ({$ph})")->execute($vals);
                AdminAuth::log((int)AdminAuth::currentAdminId(), 'wholesale_sale_create',
                    "일반 도매매출 등록 — {$saleDate} {$clientName} " . number_format($total) . '원');
                flash('admin_success', '도매매출을 등록했습니다 — ' . h($clientName) . ' ' . number_format($total) . '원 (도매매출에 합산).');
            }
        } catch (Throwable $e) {
            sg_error_log('[wholesale-sales:save] ' . $e->getMessage());
            flash('admin_error', '저장 중 오류가 발생했습니다.');
        }
        redirect('/admin/wholesale-sales.php?month=' . substr($saleDate, 0, 7));
    }

    if ($row && $action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM tt_wholesale_sales WHERE id = :id')->execute(['id' => $id]);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'wholesale_sale_delete',
                '일반 도매매출 삭제 — ' . $row['sale_date'] . ' ' . $row['client_name']);
            flash('admin_success', '삭제했습니다.');
        } catch (Throwable $e) {
            sg_error_log('[wholesale-sales:delete] ' . $e->getMessage());
            flash('admin_error', '삭제 중 오류가 발생했습니다.');
        }
        redirect('/admin/wholesale-sales.php?month=' . date('Y-m', strtotime((string)$row['sale_date'])));
    }

    if ($row && $action === 'pay_register') {
        $m = (string)($_POST['pay_method'] ?? 'unpaid');
        if (!isset($payLabels[$m])) $m = 'unpaid';
        $amount = (int)$row['total_amount'];
        try {
            $pdo->prepare('UPDATE tt_wholesale_sales SET pay_method = :m, pay_amount = :a, paid_at = ' . ($m !== 'unpaid' ? 'NOW()' : 'NULL') . ' WHERE id = :id')
                ->execute(['m' => $m, 'a' => $m === 'unpaid' ? 0 : $amount, 'id' => $id]);
            flash('admin_success', '결제를 등록했습니다 — ' . $payLabels[$m]);
        } catch (Throwable $e) {
            sg_error_log('[wholesale-sales:pay] ' . $e->getMessage());
            flash('admin_error', '결제 등록 중 오류가 발생했습니다.');
        }
        redirect('/admin/wholesale-sales.php?month=' . date('Y-m', strtotime((string)$row['sale_date'])));
    }

    if ($row && $action === 'toggle_tax') {
        $next = ((int)$row['tax_invoice_issued'] === 1) ? 0 : 1;
        try {
            $pdo->prepare('UPDATE tt_wholesale_sales SET tax_invoice_issued = :t WHERE id = :id')
                ->execute(['t' => $next, 'id' => $id]);
            flash('admin_success', $next === 1 ? '세금계산서 발행 처리했습니다.' : '세금계산서 발행을 해제했습니다.');
        } catch (Throwable $e) {
            sg_error_log('[wholesale-sales:tax] ' . $e->getMessage());
            flash('admin_error', '처리 중 오류가 발생했습니다.');
        }
        redirect('/admin/wholesale-sales.php?month=' . date('Y-m', strtotime((string)$row['sale_date'])));
    }
}

/* ===== 조회 (월 단위) ===== */
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');

/* 수정 대상 */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM tt_wholesale_sales WHERE id = :id AND settlement_id IS NULL LIMIT 1');
    $st->execute(['id' => $editId]);
    $editRow = $st->fetch();
}

/* 목록 */
$list = [];
try {
    $st = $pdo->prepare('SELECT * FROM tt_wholesale_sales WHERE sale_date LIKE :ym ORDER BY sale_date DESC, id DESC');
    $st->execute(['ym' => $month . '-%']);
    $list = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sg_error_log('[wholesale-sales:list] ' . $e->getMessage());
}

/* 요약 */
$sum = ['count' => 0, 'supply' => 0, 'vat' => 0, 'total' => 0, 'cost' => 0, 'margin' => 0,
        'card' => 0, 'cash' => 0, 'unpaid' => 0, 'taxinv' => 0];
foreach ($list as $r) {
    $sum['count']++;
    $sum['supply'] += (int)$r['supply_amount'];
    $sum['vat'] += (int)$r['vat_amount'];
    $sum['total'] += (int)$r['total_amount'];
    $sum['cost'] += (int)$r['cost_amount'];
    $sum['margin'] += (int)$r['total_amount'] - (int)$r['cost_amount'];
    if ($r['pay_method'] === 'card') $sum['card'] += (int)$r['pay_amount'];
    elseif ($r['pay_method'] === 'unpaid') $sum['unpaid'] += (int)$r['total_amount'];
    else $sum['cash'] += (int)$r['pay_amount'];
    if ((int)$r['tax_invoice_issued'] === 1) $sum['taxinv']++;
}

$fmt = fn(int $n): string => number_format($n);
require __DIR__ . '/includes/header.php';
?>

<style>
.ws-form-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px 12px;margin:14px 0 4px;}
.ws-form-grid label{font-size:12.5px;font-weight:700;color:#334155;display:block;margin-bottom:4px;}
.ws-form-grid input, .ws-form-grid select, .ws-form-grid textarea{
  width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13.5px;}
.ws-form-grid .ws-col2{grid-column:span 2;}
.ws-form-grid .ws-col3{grid-column:span 3;}
.ws-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin:16px 0;}
.ws-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;}
.ws-stat .k{font-size:11.5px;font-weight:800;color:#64748b;}
.ws-stat .v{font-size:16px;font-weight:900;margin-top:3px;}
.ws-stat.ws-hero{background:linear-gradient(135deg,#0b3a8f,#2563eb);border:none;}
.ws-stat.ws-hero .k{color:#c7d7f5;}
.ws-stat.ws-hero .v{color:#fff;}
.ws-stat.ws-good{background:#f0fdf4;border-color:#bbf7d0;}
@media (max-width:1100px){.ws-form-grid{grid-template-columns:repeat(3,1fr);}.ws-stats{grid-template-columns:repeat(3,1fr);}}
@media (max-width:640px){.ws-form-grid{grid-template-columns:repeat(2,1fr);}.ws-form-grid .ws-col2,.ws-form-grid .ws-col3{grid-column:span 2;}.ws-stats{grid-template-columns:repeat(2,1fr);}}
</style>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">📦 도매매출 관리 (일반 도매)</h2>
      <p class="admin-list-sub">온라인 도매주문(사업자 택배) 외에 <b>거래처에서 연락(전화 등)으로 직접 주문하는 일반 도매매출</b>을 등록합니다 — 일정산·월간 정산의 <b>도매매출</b>에 자동 합산됩니다.</p>
    </div>
    <div style="display:flex;gap:8px;">
      <a class="btn" href="?month=<?= date('Y-m', strtotime($month . ' -1 month')) ?>">◀ 지난달</a>
      <a class="btn" href="?month=">이번 달</a>
      <a class="btn" href="<?= BASE_URL ?>/admin/daily-settlement.php">💰 일정산 마감으로</a>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- ===== 등록/수정 폼 ===== -->
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <form method="get" style="display:flex;gap:8px;align-items:center;">
      <label style="font-weight:700;">조회 월</label>
      <input type="month" name="month" value="<?= h($month) ?>" style="padding:8px 10px;border:1px solid #ddd;border-radius:8px;">
      <button class="btn btn-primary" type="submit">조회</button>
    </form>
    <span style="font-size:12.5px;color:#64748b;">결제 미등록(외상) 건은 마감 집계에서 외상으로 표시됩니다.</span>
  </div>

  <form method="post" class="ws-form-grid">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="save">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>

    <div><label>매출 일자 *</label><input type="date" name="sale_date" required value="<?= h($editRow['sale_date'] ?? date('Y-m-d')) ?>"></div>
    <div class="ws-col2"><label>거래처명 *</label><input type="text" name="client_name" required maxlength="100" value="<?= h($editRow['client_name'] ?? '') ?>" placeholder="예) ○○정비소"></div>
    <div class="ws-col3"><label>품목명 *</label><input type="text" name="item_name" required maxlength="255" value="<?= h($editRow['item_name'] ?? '') ?>" placeholder="예) 타이어 205/55R16 ○○브랜드"></div>

    <div><label>규격</label><input type="text" name="spec" maxlength="100" value="<?= h($editRow['spec'] ?? '') ?>"></div>
    <div><label>수량 *</label><input type="number" name="qty" min="1" required value="<?= (int)($editRow['qty'] ?? 1) ?>"></div>
    <div><label>단가 (공급가 기준) *</label><input type="text" name="unit_price" required inputmode="numeric" value="<?= (int)($editRow['unit_price'] ?? 0) ?>"></div>
    <div><label>매입원가 합계 (마진용)</label><input type="text" name="cost_amount" inputmode="numeric" value="<?= (int)($editRow['cost_amount'] ?? 0) ?>"></div>
    <div><label>결제수단</label>
      <select name="pay_method">
        <?php foreach ($payLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= ($editRow['pay_method'] ?? 'unpaid') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>결제금액 (비우면 자동)</label><input type="text" name="pay_amount" inputmode="numeric" value="<?= (int)($editRow['pay_amount'] ?? 0) ?>"></div>

    <div class="ws-col2"><label>거래처 사업자번호 (세금계산서용)</label><input type="text" name="client_biz_number" maxlength="20" value="<?= h($editRow['client_biz_number'] ?? '') ?>" placeholder="123-45-67890"></div>
    <div><label>거래처 대표자</label><input type="text" name="client_ceo" maxlength="50" value="<?= h($editRow['client_ceo'] ?? '') ?>"></div>
    <div class="ws-col3"><label>거래처 주소 (세금계산서용)</label><input type="text" name="client_address" maxlength="255" value="<?= h($editRow['client_address'] ?? '') ?>"></div>

    <div class="ws-col3"><label>메모</label><input type="text" name="memo" maxlength="500" value="<?= h($editRow['memo'] ?? '') ?>"></div>
    <div style="display:flex;align-items:end;gap:8px;grid-column:span 2;">
      <button type="submit" class="btn btn-primary" style="font-size:15px;padding:11px 22px;"><?= $editRow ? '💾 수정 저장' : '＋ 도매매출 등록' ?></button>
      <?php if ($editRow): ?><a class="btn" href="?month=<?= h($month) ?>">취소</a><?php endif; ?>
    </div>
  </form>

  <!-- ===== 월 요약 ===== -->
  <div class="ws-stats">
    <div class="ws-stat"><div class="k">거래 건수</div><div class="v"><?= (int)$sum['count'] ?>건</div></div>
    <div class="ws-stat"><div class="k">공급가액 합계</div><div class="v"><?= $fmt($sum['supply']) ?>원</div></div>
    <div class="ws-stat"><div class="k">세액(10%)</div><div class="v"><?= $fmt($sum['vat']) ?>원</div></div>
    <div class="ws-stat ws-hero"><div class="k">도매매출 합계 (공급+세액)</div><div class="v"><?= $fmt($sum['total']) ?>원</div></div>
    <div class="ws-stat ws-good"><div class="k">마진 (매출−매입원가)</div><div class="v"><?= $fmt($sum['margin']) ?>원</div></div>
    <div class="ws-stat"><div class="k">결제 / 외상 / 📄발행</div><div class="v" style="font-size:12.5px;"><?= $fmt($sum['card'] + $sum['cash']) ?>원 / <?= $fmt($sum['unpaid']) ?>원 / <?= (int)$sum['taxinv'] ?>건</div></div>
  </div>

  <!-- ===== 목록 ===== -->
  <h3 class="admin-list-title" style="margin:8px 0 10px;">🧾 <?= h($month) ?> 도매매출 내역</h3>
  <div class="admin-table-wrap" style="overflow-x:auto;">
  <table class="admin-table">
    <thead><tr>
      <th>매출일자</th><th>거래처</th><th>품목 (규격)</th><th>수량</th><th>단가</th>
      <th>공급가액</th><th>세액</th><th>매출합계</th><th>마진</th>
      <th>결제</th><th>📄 세금계산서</th><th>마감</th><th>관리</th>
    </tr></thead>
    <tbody>
      <?php if (empty($list)): ?>
      <tr><td colspan="13" style="text-align:center;color:#94a3b8;padding:26px 0;">등록된 일반 도매매출이 없습니다 — 위 폼에서 첫 거래를 등록해 보세요.</td></tr>
      <?php endif; ?>
      <?php foreach ($list as $r):
          $locked = $r['settlement_id'] !== null;
          $margin = (int)$r['total_amount'] - (int)$r['cost_amount'];
      ?>
      <tr>
        <td style="white-space:nowrap;"><?= h($r['sale_date']) ?></td>
        <td style="white-space:nowrap;"><?= h($r['client_name']) ?><?= $r['client_biz_number'] ? '<br><span style="font-size:11.5px;color:#64748b;">' . h($r['client_biz_number']) . '</span>' : '' ?></td>
        <td><?= h($r['item_name']) ?><?= $r['spec'] ? ' <span style="color:#64748b;font-size:12px;">(' . h($r['spec']) . ')</span>' : '' ?></td>
        <td style="text-align:center;"><?= (int)$r['qty'] ?></td>
        <td style="text-align:right;"><?= $fmt((int)$r['unit_price']) ?></td>
        <td style="text-align:right;"><?= $fmt((int)$r['supply_amount']) ?></td>
        <td style="text-align:right;"><?= $fmt((int)$r['vat_amount']) ?></td>
        <td style="text-align:right;font-weight:800;"><?= $fmt((int)$r['total_amount']) ?></td>
        <td style="text-align:right;color:<?= $margin >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= $fmt($margin) ?></td>
        <td style="white-space:nowrap;"><?= $payLabels[$r['pay_method']] ?? $r['pay_method'] ?></td>
        <td style="white-space:nowrap;">
          <?php if ((int)$r['tax_invoice_issued'] === 1): ?>
            <span style="color:#16a34a;font-weight:800;">📄 발행완료</span>
          <?php else: ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('세금계산서 발행 처리할까요? (정산 마감의 세금계산서 건수에 반영)');">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="action" value="toggle_tax">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm">발행 처리</button>
          </form>
          <?php endif; ?>
        </td>
        <td><?= $locked ? '🔒 마감' : '-' ?></td>
        <td style="white-space:nowrap;">
          <a class="btn btn-sm" href="wholesale-sale-print.php?id=<?= (int)$r['id'] ?>" target="_blank" title="전자세금계산서 양식 인쇄">🖨️ 인쇄</a>
          <?php if (!$locked): ?>
          <a class="btn btn-sm" href="?month=<?= h($month) ?>&edit=<?= (int)$r['id'] ?>#ws-edit">✏️</a>
          <?php if ((int)$r['tax_invoice_issued'] === 1): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="action" value="toggle_tax">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm" title="발행 해제">↩</button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('이 도매매출 기록을 삭제할까요?');">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm" style="color:#dc2626;">🗑</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p style="font-size:12.5px;color:#64748b;margin:10px 0 0;">
    🖨️ 인쇄 버튼을 누르면 <b>국세청 표준 전자세금계산서 양식</b>(공급받는자보관용)으로 출력되고 자동으로 발행 처리됩니다. 일정산 마감이 완료된 날짜의 기록은 🔒 표시와 함께 수정·삭제가 차단됩니다.
  </p>
</div>
