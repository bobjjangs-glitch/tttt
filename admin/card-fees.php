<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('card-fees');
ensure_work_log_tables();
ensure_order_tracking_columns();
ensure_tax_invoice_table();

$pageTitle = '카드 수수료 설정';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');

/* ===== 요율 저장 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/card-fees.php');
    }
    $rateSite = (float)str_replace(',', '.', (string)($_POST['rate_site'] ?? '0'));
    $rateOnline = (float)str_replace(',', '.', (string)($_POST['rate_online'] ?? '0'));
    if ($rateSite < 0 || $rateSite > 15 || $rateOnline < 0 || $rateOnline > 15) {
        flash('admin_error', '카드 수수료율은 각각 0~15% 사이로 입력해 주세요.');
    } else {
        set_setting('card_fee_rate_site', (string)$rateSite);
        set_setting('card_fee_rate_online', (string)$rateOnline);
        /* 구 단일요율 키도 함께 갱신 — v41 이하 페이지/스냅샷 호환 */
        set_setting('card_fee_rate', (string)round(($rateSite + $rateOnline) / 2, 2));
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'card_fee_rate',
            "카드 수수료율 설정 — 현장 {$rateSite}% / 온라인 {$rateOnline}%");
        flash('admin_success', "카드 수수료율을 저장했습니다 — 현장 {$rateSite}% / 온라인 {$rateOnline}%. 일·월 정산 마감에 바로 반영됩니다.");
    }
    redirect('/admin/card-fees.php');
}

/* ===== 이번 달 실적 미리보기 ===== */
$month = date('Y-m');
$calc = compute_month_settlement($month);
$fmt = fn(int $n): string => number_format($n);
$profitRate = $calc['total_sales'] > 0 ? round($calc['margin_total'] / $calc['total_sales'] * 100, 1) : 0;

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">💳 카드 수수료 설정</h2>
      <p class="admin-list-sub">현장(태블릿 작업일지 결제)과 온라인(주문 결제)의 카드 수수료율을 <b>각각 따로</b> 설정합니다 — 일정산·월간 정산 마감의 순매출에 반영됩니다.</p>
    </div>
    <a class="btn" href="<?= BASE_URL ?>/admin/daily-settlement.php">💰 일정산 마감으로</a>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- ===== 요율 설정 ===== -->
  <form method="post" class="cf-grid">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <div class="cf-card cf-site">
      <div class="cf-ico">🏬</div>
      <div class="cf-body">
        <div class="cf-k">현장 카드 수수료율 <span class="cf-tag">태블릿 작업일지 결제</span></div>
        <div class="cf-input-row">
          <input type="number" name="rate_site" min="0" max="15" step="0.1"
                 value="<?= h((string)card_fee_rate_site()) ?>" required>
          <span class="cf-pct">%</span>
          <button type="submit" class="btn btn-primary">저장</button>
        </div>
        <div class="cf-sub">카드 결제건(현장 로드손님·예약장착) 매출에서 이 율만큼 차감한 순매출로 정산에 반영됩니다.</div>
      </div>
    </div>
    <div class="cf-card cf-online">
      <div class="cf-ico">🛒</div>
      <div class="cf-body">
        <div class="cf-k">온라인 카드 수수료율 <span class="cf-tag" style="color:#0891b2;background:#ecfeff;">온라인 주문 결제</span></div>
        <div class="cf-input-row">
          <input type="number" name="rate_online" min="0" max="15" step="0.1"
                 value="<?= h((string)card_fee_rate_online()) ?>" required>
          <span class="cf-pct">%</span>
          <button type="submit" class="btn btn-primary">저장</button>
        </div>
        <div class="cf-sub">온라인 주문(소매 택배·사업자 택배)의 카드결제 매출에 적용됩니다 — 무통장·계좌 결제에는 적용되지 않습니다.</div>
      </div>
    </div>
  </form>

  <!-- ===== 이번 달 미리보기 ===== -->
  <h3 class="admin-list-title" style="margin:24px 0 10px;">👀 이번 달(<?= h($month) ?>) 적용 미리보기</h3>
  <div class="admin-table-wrap" style="overflow-x:auto;">
  <table class="admin-table cf-preview">
    <thead><tr><th>구분</th><th>카드 결제매출</th><th>수수료율</th><th>카드 수수료</th><th>카드 순매출</th></tr></thead>
    <tbody>
      <tr>
        <td><b>🏬 현장·일반도매</b> <small class="text-muted">(작업일지+일반 도매 카드결제)</small></td>
        <td><b><?= $fmt((int)$calc['card_sales'] + (int)$calc['wholesale_card_sales']) ?></b>원</td>
        <td><?= number_format(card_fee_rate_site(), 1) ?>%</td>
        <td style="color:#c2410c;">-<?= $fmt((int)$calc['card_fee_site_amount']) ?>원</td>
        <td style="color:#16a34a;"><?= $fmt((int)$calc['card_sales'] + (int)$calc['wholesale_card_sales'] - (int)$calc['card_fee_site_amount']) ?>원</td>
      </tr>
      <tr>
        <td><b>🛒 온라인</b> <small class="text-muted">(주문 카드결제)</small></td>
        <td><b><?= $fmt((int)$calc['online_card_sales']) ?></b>원</td>
        <td><?= number_format(card_fee_rate_online(), 1) ?>%</td>
        <td style="color:#c2410c;">-<?= $fmt((int)$calc['card_fee_online_amount']) ?>원</td>
        <td style="color:#16a34a;"><?= $fmt((int)$calc['online_card_sales'] - (int)$calc['card_fee_online_amount']) ?>원</td>
      </tr>
      <tr class="cf-grand">
        <td>합계</td>
        <td><b><?= $fmt((int)$calc['card_total_sales']) ?></b>원</td>
        <td>평균 <?= number_format((float)$calc['card_fee_rate'], 1) ?>%</td>
        <td style="color:#c2410c;font-weight:800;">-<?= $fmt((int)$calc['card_fee_amount']) ?>원</td>
        <td style="color:#16a34a;font-weight:800;"><?= $fmt((int)$calc['card_net_sales']) ?>원</td>
      </tr>
    </tbody>
  </table>
  </div>
  <p style="font-size:12.5px;color:#64748b;margin:10px 0 0;">
    이번 달 종합매출 <?= $fmt((int)$calc['total_sales']) ?>원 · 토탈마진 <?= $fmt((int)$calc['margin_total']) ?>원 (수익률 <?= $profitRate ?>%).
    수수료율을 바꾸면 마감 전 라이브 집계와 다음 마감 스냅샷에 바로 반영됩니다.
  </p>
</div>

<style>
.cf-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
.cf-card{display:flex;align-items:flex-start;gap:14px;background:#fff;border:1px solid #e5e7eb;border-left:5px solid #2563eb;border-radius:14px;padding:18px;}
.cf-online{border-left-color:#0891b2;}
.cf-ico{flex:none;width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;background:#eff6ff;}
.cf-online .cf-ico{background:#ecfeff;}
.cf-body{min-width:0;flex:1;}
.cf-k{font-size:13.5px;font-weight:800;color:#334155;}
.cf-tag{display:inline-block;font-size:10.5px;font-weight:800;color:#2563eb;background:#eff6ff;border-radius:99px;padding:1px 8px;margin-left:5px;vertical-align:1px;}
.cf-input-row{display:flex;align-items:center;gap:8px;margin:10px 0 6px;}
.cf-input-row input{width:110px;padding:10px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:17px;font-weight:800;text-align:right;}
.cf-input-row input:focus{border-color:#2563eb;outline:none;}
.cf-pct{font-size:15px;font-weight:800;color:#64748b;}
.cf-sub{font-size:12px;font-weight:600;color:#94a3b8;line-height:1.55;word-break:keep-all;}
.cf-preview .cf-grand td{background:#eff6ff;font-weight:800;}
@media(max-width:900px){.cf-grid{grid-template-columns:1fr;}}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
