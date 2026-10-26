<?php
// inc/ledger_bar.php - 거래처 원장 전용 하단 바 (인쇄 / Excel / 입금 등록 / 목록). 미실행 코드
// 전제: functions.php 로드 후 사용 (h 사용). 클래스 이름은 lgb- 접두어로 다른 화면과 분리.

/**
 * @param int    $partnerId  0 이면 전체 현황 화면
 * @param string $from       조회 시작일 (Y-m-d)
 * @param string $to         조회 종료일 (Y-m-d)
 * @param bool   $exportable Excel 버튼 표시 여부 (외상 거래처 원장 또는 전체 현황일 때만 true)
 * @param bool   $showPay    입금 등록 버튼 표시 여부
 */
function lgbar_render(int $partnerId, string $from, string $to, bool $exportable, bool $showPay): void
{
    $q = ['from' => $from, 'to' => $to];
    if ($partnerId > 0) {
        $q['partner'] = $partnerId;
    }
    $exportUrl = 'ledger_export.php?' . http_build_query($q);
    ?>
<style>
.lgb-spacer{height:60px}
.lgb{position:fixed;left:0;right:0;bottom:0;z-index:50;display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px;background:#1f2937;border-top:1px solid #111827}
.lgb a,.lgb button{display:inline-block;box-sizing:border-box;height:32px;padding:0 14px;border:1px solid #6b7280;border-radius:6px;background:#374151;color:#fff;font-family:inherit;font-size:13px;font-weight:600;line-height:30px;text-decoration:none;cursor:pointer}
.lgb a:hover,.lgb button:hover{background:#4b5563}
.lgb .lgb-right{margin-left:auto}
.lgb-printhead{display:none}
@media print{
  .lgb,.lgb-spacer,.gnb,.snb,.sidebar,.topbar,.no-print{display:none!important}
  .lgb-printhead{display:block!important;margin:0 0 10px}
}
</style>
<div class="lgb-spacer no-print"></div>
<div class="lgb no-print">
  <?php if ($partnerId > 0): ?>
    <a href="ledger.php">전체 현황</a>
  <?php endif; ?>
  <?php if ($showPay && $partnerId > 0): ?>
    <a href="payments.php?partner=<?= (int)$partnerId ?>">입금 등록</a>
  <?php endif; ?>
  <span class="lgb-right"></span>
  <button type="button" onclick="window.print()">인쇄 / PDF</button>
  <?php if ($exportable): ?>
    <a href="<?= h($exportUrl) ?>">Excel(CSV)</a>
  <?php endif; ?>
</div>
<?php
}
