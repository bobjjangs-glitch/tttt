<?php
/** 작업일지 엔트리 카드 1건 — $log, $labels, $fmt, $isClosed, $date 컨텍스트 필요 */
$locked = !empty($log['settlement_id']) || $isClosed;
$isReservation = $log['source'] === 'reservation';
$jobBadge = $log['job_type'] === 'tire' ? 'badge-tire' : ($log['job_type'] === 'repair' ? 'badge-repair' : 'badge-etc');
$qty = max(1, (int)$log['qty']);
$costFactoryUnit = (int)$log['cost_factory'];
$costSupplyUnit = (int)$log['cost_supply'];
$payLabels = work_pay_method_labels();
$payMethod = (string)($log['pay_method'] ?? '') ?: '';
$payAmount = (int)($log['pay_amount'] ?? 0) ?: (int)$log['work_amount'];
?>
<div class="log-entry <?= $isReservation ? 'is-reservation' : 'is-walkin' ?> <?= $log['status'] === 'working' ? 'is-working' : '' ?> <?= $log['settlement_id'] ? 'is-settled' : '' ?>">
  <div class="log-time"><?= h(substr((string)$log['work_time'], 0, 5)) ?></div>
  <div class="log-main">
    <div class="log-title-row">
      <span class="badge <?= $isReservation ? 'badge-resv' : 'badge-walk' ?>"><?= $isReservation ? '🗓️ 예약 ' . h($log['res_no_snap'] ?: '예약건') : '🚶 일반 로드' ?></span>
      <span class="badge <?= $jobBadge ?>"><?= h($labels[$log['job_type']] ?? $log['job_type']) ?><?= $log['product_name_snap'] ? ' · ' . h($log['product_name_snap']) : '' ?><?= $log['job_type'] === 'tire' && $log['product_name_snap'] ? ' ×' . $qty : '' ?></span>
      <?php if ($log['status'] === 'working'): ?><span class="badge badge-working">🔧 작업중</span>
      <?php else: ?><span class="badge badge-done">✅ 작업완료</span><?php endif; ?>
      <?php if ($payMethod !== '' && isset($payLabels[$payMethod])): ?>
      <span class="badge badge-pay badge-pay-<?= h($payMethod) ?>"><?= $payLabels[$payMethod] ?> <?= $fmt($payAmount) ?>원</span>
      <?php else: ?>
      <span class="badge badge-unpaid">⏳ 외상</span>
      <?php endif; ?>
      <?php if ((int)($log['cash_receipt_issued'] ?? 0) === 1): ?><span class="badge badge-receipt">🧾 현금영수증 발행</span><?php endif; ?>
      <?php if (!empty($log['tax_invoice_issued'])): ?><span class="badge badge-taxinv">📄 세금계산서 발행</span><?php endif; ?>
      <?php if ($log['settlement_id']): ?><span class="badge badge-settled">🔒 마감</span><?php endif; ?>
    </div>
    <div class="log-content"><?= h($log['work_content']) ?></div>
    <?php $logPhotos = $photosMap[(int)$log['id']] ?? []; ?>
    <?php if (!empty($logPhotos)): ?>
    <div class="log-photos">
      <span class="badge badge-photo">📷 <?= count($logPhotos) ?>장</span>
      <?php foreach (array_slice($logPhotos, 0, 5) as $photo): ?>
      <a href="<?= h($photo['image_url']) ?>" target="_blank" rel="noopener" class="log-photo-thumb" style="background-image:url('<?= h($photo['image_url']) ?>')" title="사진 크게 보기"></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="log-sub">
      작업자 <b><?= h(worker_name((int)$log['worker_id']) ?: '미지정') ?></b>
      <?php if ($log['customer_name']): ?> · 고객 <b><?= h($log['customer_name']) ?></b><?php endif; ?>
      <?php if ($log['car_info']): ?> · 차량 <b><?= h($log['car_info']) ?></b><?php endif; ?>
      <?php if ($log['job_type'] === 'tire' && ($costFactoryUnit || $costSupplyUnit)): ?> · 공장도가 <b><?= $fmt($costFactoryUnit) ?></b>/본 · 매입 <b><?= $fmt($costSupplyUnit) ?></b>/본<?php endif; ?>
    </div>
  </div>
  <div class="log-amount">
    <?= $fmt((int)$log['work_amount']) ?><small>작업금액(원)</small>
  </div>
  <?php if (!$locked): ?>
  <div class="log-actions">
    <button type="button" class="tablet-btn tablet-btn-sm <?= $payMethod !== '' ? 'tablet-btn-ghost' : 'tablet-btn-pay' ?> pay-open"
            data-id="<?= (int)$log['id'] ?>"
            data-amount="<?= (int)$log['work_amount'] ?>"
            data-method="<?= h($payMethod) ?>"
            data-pamount="<?= (int)($log['pay_amount'] ?? 0) ?>"
            data-receipt="<?= (int)($log['cash_receipt_issued'] ?? 0) === 1 ? '1' : '0' ?>"
            data-resv="<?= $isReservation ? '1' : '0' ?>">
      <?= $payMethod !== '' ? '💳 결제수정' : '💰 결제등록' ?>
    </button>
    <?php if ($log['status'] === 'working'): ?>
    <form method="post" action="<?= BASE_URL ?>/tablet/action.php">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="log_status">
      <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
      <input type="hidden" name="return_to" value="/tablet/index.php?date=<?= h($date) ?>">
      <button type="submit" class="tablet-btn tablet-btn-sm tablet-btn-green">✓ 완료</button>
    </form>
    <?php else: ?>
    <span class="tablet-btn tablet-btn-sm tablet-btn-green log-done-chip">✅ 작업완료</span>
    <form method="post" action="<?= BASE_URL ?>/tablet/action.php">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="log_status">
      <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
      <input type="hidden" name="return_to" value="/tablet/index.php?date=<?= h($date) ?>">
      <button type="submit" class="tablet-btn tablet-btn-sm tablet-btn-ghost" title="완료를 취소하고 작업중으로 되돌립니다">↩ 진행중으로</button>
    </form>
    <?php endif; ?>
    <a class="tablet-btn tablet-btn-ghost tablet-btn-sm" href="<?= BASE_URL ?>/tablet/log-edit.php?id=<?= (int)$log['id'] ?>">✏️ 수정</a>
  </div>
  <?php endif; ?>
</div>
