<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$worker = tablet_require_worker();

$pageTitle = '작업일지 수정';
$pageKey = 'today';

$pdo = Database::connection();
$labels = work_job_type_labels();
$workers = get_workers(true);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM tt_work_logs WHERE id = :id LIMIT 1');
$stmt->execute(['id'=>$id]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);

$errMsg = flash('tablet_error');
$backDate = $log ? (string)$log['work_date'] : date('Y-m-d');

/* 없거나 마감된 건은 수정 불가 */
if (!$log) {
    flash('tablet_error', '작업일지를 찾을 수 없습니다.');
    redirect('/tablet/index.php');
}
if (!empty($log['settlement_id'])) {
    flash('tablet_error', '일정산 마감이 완료된 작업일지는 수정할 수 없습니다.');
    redirect('/tablet/index.php?date=' . $backDate);
}

$products = [];
try {
    $products = $pdo->query('SELECT id, name, spec, price_sale, price_original AS factory_price, supply_price FROM tt_products WHERE status = "active" ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* 첨부된 사진 */
$logPhotos = get_work_log_photos_by_logs([(int)$log['id']])[(int)$log['id']] ?? [];
require __DIR__ . '/includes/header.php';
?>

<?php if ($errMsg): ?><div class="notice notice-err">⚠ <?= h($errMsg) ?></div><?php endif; ?>

<form method="post" action="<?= BASE_URL ?>/tablet/action.php"
      onsubmit="return confirm('이 작업일지를 삭제할까요?');" style="margin-bottom:14px;">
  <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
  <input type="hidden" name="action" value="log_delete">
  <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
  <input type="hidden" name="return_to" value="/tablet/index.php?date=<?= h($backDate) ?>">
  <button type="submit" class="tablet-btn tablet-btn-red">🗑 이 작업일지 삭제</button>
</form>

<form method="post" action="<?= BASE_URL ?>/tablet/action.php" class="tablet-card" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
  <input type="hidden" name="action" value="log_save">
  <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
  <input type="hidden" name="source" value="<?= h($log['source']) ?>">
  <input type="hidden" name="reservation_id" value="<?= (int)$log['reservation_id'] ?>">

  <div class="tablet-card-title">
    ✏️ 작업일지 수정
    <span class="count-chip"><?= $log['source'] === 'reservation' ? '🗓️ 예약작업 ' . h($log['res_no_snap'] ?: '') : '🚶 일반 로드' ?></span>
  </div>

  <div class="tablet-form">

    <div class="form-row-3">
      <div class="form-item">
        <label>작업 날짜</label>
        <input type="date" name="work_date" value="<?= h((string)$log['work_date']) ?>" required>
      </div>
      <div class="form-item">
        <label>작업 시작 시각</label>
        <input type="time" name="work_time" value="<?= h(substr((string)$log['work_time'], 0, 5)) ?>" required>
      </div>
      <div class="form-item">
        <label>작업자</label>
        <select name="worker_id" required>
          <option value="0">미지정</option>
          <?php foreach ($workers as $w): ?>
          <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === (int)$log['worker_id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-item">
      <label>작업구분</label>
      <div class="seg">
        <?php foreach ($labels as $key => $label): ?>
        <input type="radio" name="job_type" id="jt-<?= h($key) ?>" value="<?= h($key) ?>" <?= $log['job_type'] === $key ? 'checked' : '' ?>>
        <label for="jt-<?= h($key) ?>"><?= $key === 'tire' ? '🛞' : ($key === 'repair' ? '🔧' : '📄') ?> <?= h($label) ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-item pen-field" data-pen-name="work-content" data-pen-fallback="(✍️ 손글씨로 작성 — 첨부 필기 이미지 참고)">
      <div class="pen-field-head">
        <label>작업내용</label>
        <div class="pen-toggle">
          <button type="button" class="pen-mode on" data-mode="text">⌨️ 텍스트</button>
          <button type="button" class="pen-mode" data-mode="pen">✍️ 펜글씨</button>
        </div>
      </div>
      <textarea name="work_content" class="pen-text" required><?= h((string)$log['work_content']) ?></textarea>
      <div class="pen-pad" hidden>
        <div class="pen-canvas-wrap"><canvas></canvas></div>
        <div class="pen-tools">
          <button type="button" class="pen-tool pen-w" data-w="3">얇게</button>
          <button type="button" class="pen-tool pen-w on" data-w="5">보통</button>
          <button type="button" class="pen-tool pen-w" data-w="9">굵게</button>
          <button type="button" class="pen-tool pen-undo">↩ 되돌리기</button>
          <button type="button" class="pen-tool pen-clear">🧽 전체 지우기</button>
        </div>
        <span class="hint">✍️ 터치펜·손가락으로 직접 쓰세요 — 저장하면 '작업내용 필기' 이미지가 첨부됩니다</span>
      </div>
    </div>

    <div class="form-row">
      <div class="form-item">
        <label>작업금액 (원)</label>
        <input type="number" name="work_amount" id="workAmount" min="0" step="1000" value="<?= (string)(int)$log['work_amount'] ?>" required>
      </div>
      <div class="form-item">
        <label>작업상태</label>
        <select name="status">
          <option value="done" <?= $log['status'] === 'done' ? 'selected' : '' ?>>✅ 완료</option>
          <option value="working" <?= $log['status'] === 'working' ? 'selected' : '' ?>>🔧 작업중</option>
        </select>
      </div>
    </div>

    <div class="form-item">
      <label>현장 결제수단 <span class="hint">결제등록을 수정하거나 외상으로 되돌릴 수 있습니다</span></label>
      <div class="seg">
        <?php $curPay = (string)($log['pay_method'] ?? '') ?: 'none'; ?>
        <input type="radio" name="pay_method" id="pm-none" value="none" <?= $curPay === 'none' ? 'checked' : '' ?>>
        <label for="pm-none">⏳ 외상 (결제 전)</label>
        <input type="radio" name="pay_method" id="pm-card" value="card" <?= $curPay === 'card' ? 'checked' : '' ?>>
        <label for="pm-card">💳 카드</label>
        <input type="radio" name="pay_method" id="pm-cash" value="cash" <?= $curPay === 'cash' ? 'checked' : '' ?>>
        <label for="pm-cash">💵 현금</label>
        <input type="radio" name="pay_method" id="pm-transfer" value="transfer" <?= $curPay === 'transfer' ? 'checked' : '' ?>>
        <label for="pm-transfer">🏦 계좌이체</label>
      </div>
    </div>

    <div class="form-row" id="payAmountRow" style="<?= $curPay === 'none' ? 'display:none;' : '' ?>">
      <div class="form-item">
        <label>결제금액 (원)</label>
        <input type="number" name="pay_amount" id="payAmount" min="0" step="1000" value="<?= (string)(int)($log['pay_amount'] ?? 0) ?: (string)(int)$log['work_amount'] ?>" placeholder="비워두면 작업금액 그대로">
        <span class="hint">비워두면 작업금액으로 결제등록됩니다</span>
      </div>
    </div>

    <div class="form-row">
      <div class="form-item pen-field" data-pen-name="customer-name" data-pen-fallback="✍️ 손글씨 (첨부 필기 참고)">
        <div class="pen-field-head">
          <label>고객명</label>
          <div class="pen-toggle">
            <button type="button" class="pen-mode on" data-mode="text">⌨️ 텍스트</button>
            <button type="button" class="pen-mode" data-mode="pen">✍️ 펜글씨</button>
          </div>
        </div>
        <input type="text" name="customer_name" class="pen-text" value="<?= h((string)$log['customer_name']) ?>">
        <div class="pen-pad" hidden>
          <div class="pen-canvas-wrap"><canvas></canvas></div>
          <div class="pen-tools">
            <button type="button" class="pen-tool pen-w" data-w="3">얇게</button>
            <button type="button" class="pen-tool pen-w on" data-w="5">보통</button>
            <button type="button" class="pen-tool pen-w" data-w="9">굵게</button>
            <button type="button" class="pen-tool pen-undo">↩ 되돌리기</button>
            <button type="button" class="pen-tool pen-clear">🧽 전체 지우기</button>
          </div>
          <span class="hint">✍️ 고객명을 손으로 직접 쓰세요 — 저장하면 필기 이미지로 첨부됩니다</span>
        </div>
      </div>
      <div class="form-item">
        <label>차량정보</label>
        <input type="text" name="car_info" value="<?= h((string)$log['car_info']) ?>">
      </div>
    </div>

    <div class="form-item">
      <label>작업 상품 (타이어)</label>
      <select name="product_id" id="productSelect">
        <option value="0">— 상품 없음 / 수작업 —</option>
        <?php foreach ($products as $p): ?>
        <option value="<?= (int)$p['id'] ?>" data-factory="<?= (int)$p['factory_price'] ?>" data-supply="<?= (int)$p['supply_price'] ?>"
                <?= (int)$p['id'] === (int)$log['product_id'] ? 'selected' : '' ?>>
          <?= h($p['name'] . ($p['spec'] ? ' [' . $p['spec'] . ']' : '')) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-item pen-field" data-pen-name="product-name" data-pen-fallback="✍️ 손글씨 (첨부 필기 참고)">
      <div class="pen-field-head">
        <label>작업상품 직접 쓰기 <span class="hint">목록에 없거나 직접 적을 때 — 텍스트 / 펜글씨</span></label>
        <div class="pen-toggle">
          <button type="button" class="pen-mode on" data-mode="text">⌨️ 텍스트</button>
          <button type="button" class="pen-mode" data-mode="pen">✍️ 펜글씨</button>
        </div>
      </div>
      <input type="text" name="product_name_snap" class="pen-text" value="<?= h((string)$log['product_name_snap']) ?>" placeholder="예: 한국타이어 225/55R17 컴포트">
      <div class="pen-pad" hidden>
        <div class="pen-canvas-wrap"><canvas></canvas></div>
        <div class="pen-tools">
          <button type="button" class="pen-tool pen-w" data-w="3">얇게</button>
          <button type="button" class="pen-tool pen-w on" data-w="5">보통</button>
          <button type="button" class="pen-tool pen-w" data-w="9">굵게</button>
          <button type="button" class="pen-tool pen-undo">↩ 되돌리기</button>
          <button type="button" class="pen-tool pen-clear">🧽 전체 지우기</button>
        </div>
        <span class="hint">✍️ 작업상품을 손으로 직접 쓰세요 — 저장하면 '상품명 필기' 이미지로 첨부됩니다</span>
      </div>
    </div>

    <div class="form-row-3">
      <div class="form-item">
        <label>수량 (본)</label>
        <input type="number" name="qty" min="1" max="99" value="<?= (int)$log['qty'] ?>">
      </div>
      <div class="form-item">
        <label>공장도가 (원/본)</label>
        <input type="number" name="cost_factory" id="costFactory" min="0" step="1000" value="<?= (string)(int)$log['cost_factory'] ?>">
      </div>
      <div class="form-item">
        <label>매입단가 (원/본)</label>
        <input type="number" name="cost_supply" id="costSupply" min="0" step="1000" value="<?= (string)(int)$log['cost_supply'] ?>">
      </div>
    </div>

    <div class="form-item">
      <label>첨부된 사진<?= count($logPhotos) > 0 ? ' ' . count($logPhotos) . '장' : '' ?></label>
      <?php if (empty($logPhotos)): ?>
        <span class="hint">첨부된 사진이 없습니다</span>
      <?php else: ?>
      <div class="photo-strip">
        <?php foreach ($logPhotos as $photo): ?>
        <div class="photo-thumb-wrap">
          <a href="<?= h($photo['image_url']) ?>" target="_blank" rel="noopener" style="display:block;">
            <div class="photo-thumb" style="background-image:url('<?= h($photo['image_url']) ?>')"></div>
          </a>
          <label class="photo-del">
            <input type="checkbox" name="photo_delete_ids[]" value="<?= (int)$photo['id'] ?>">
            삭제
          </label>
        </div>
        <?php endforeach; ?>
      </div>
      <span class="hint">삭제를 체크하고 저장하면 사진이 지워집니다</span>
      <?php endif; ?>
    </div>

    <div class="form-item">
      <label>사진 추가 (선택 · 최대 5장)</label>
      <input type="file" name="photos[]" id="photoInput" accept="image/*" capture="environment" multiple class="photo-input">
      <div class="photo-preview" id="photoPreview"></div>
    </div>

    <div class="action-bar">
      <a class="tablet-btn tablet-btn-ghost" href="<?= BASE_URL ?>/tablet/index.php?date=<?= h($backDate) ?>">취소</a>
      <button type="submit" class="tablet-btn tablet-btn-xl">💾 수정 저장</button>
    </div>
  </div>
</form>

<script>
(function(){
  var input = document.getElementById('photoInput');
  var preview = document.getElementById('photoPreview');
  if (!input || !preview) return;
  input.addEventListener('change', function(){
    preview.innerHTML = '';
    var files = Array.prototype.slice.call(input.files || []).slice(0, 5);
    files.forEach(function(f){
      if (!/^image\//.test(f.type)) return;
      var item = document.createElement('div');
      item.className = 'photo-thumb';
      item.style.backgroundImage = 'url(' + URL.createObjectURL(f) + ')';
      preview.appendChild(item);
    });
  });
})();
</script>

<script>
/* 결제수단 선택 시 결제금액 입력 표시 + 작업금액 자동 채움 */
(function(){
  var row = document.getElementById('payAmountRow');
  var payInput = document.getElementById('payAmount');
  var workInput = document.getElementById('workAmount');
  if (!row) return;
  function sync(){
    var checked = document.querySelector('input[name="pay_method"]:checked');
    var paid = checked && checked.value !== 'none';
    row.style.display = paid ? '' : 'none';
    if (paid && payInput && !payInput.value && workInput) payInput.value = workInput.value;
  }
  document.querySelectorAll('input[name="pay_method"]').forEach(function(r){
    r.addEventListener('change', sync);
  });
  sync();
})();
</script>

<script src="<?= BASE_URL ?>/tablet/assets/js/pen-field.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
