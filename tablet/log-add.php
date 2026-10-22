<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$worker = tablet_require_worker();

$pageTitle = '작업일지 등록';
$pageKey = 'add';

$pdo = Database::connection();
$labels = work_job_type_labels();
$workers = get_workers(true);

/* 날짜/옵션 */
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$fromReservation = (int)($_GET['reservation_id'] ?? 0);

/* 폼 기본값 */
$defaults = [
    'id' => 0,
    'work_date' => $date,
    'work_time' => date('H:i'),
    'worker_id' => (int)($worker['id'] ?? 0),
    'job_type' => 'tire',
    'source' => 'walkin',
    'reservation_id' => 0,
    'work_content' => '',
    'work_amount' => '',
    'customer_name' => '',
    'car_info' => '',
    'product_id' => 0,
    'product_name_snap' => '',
    'qty' => 4,
    'cost_factory' => '',
    'cost_supply' => '',
    'status' => 'done',
    'pay_method' => 'none',
    'pay_amount' => '',
];

/* 예약에서 미리 채우기 */
$resv = null;
if ($fromReservation > 0) {
    try {
        $stmt = $pdo->prepare('SELECT r.*, p.price_original AS factory_price, p.supply_price FROM tt_reservations r LEFT JOIN tt_products p ON p.id = r.product_id WHERE r.id = :id LIMIT 1');
        $stmt->execute(['id'=>$fromReservation]);
        $resv = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if ($resv) {
        $defaults['source'] = 'reservation';
        $defaults['reservation_id'] = (int)$resv['id'];
        $defaults['work_date'] = (string)$resv['visit_date'];
        $defaults['work_time'] = substr((string)$resv['visit_time'], 0, 5) ?: date('H:i');
        $defaults['job_type'] = 'tire';
        $defaults['work_content'] = '[예약] ' . $resv['product_name_snap'] . ' 외 ' . (int)$resv['qty'] . '본 교체' . ($resv['memo'] ? ' — 요청: ' . $resv['memo'] : '');
        $defaults['work_amount'] = (string)((int)($resv['final_amount'] ?: ((int)$resv['unit_price'] * (int)$resv['qty'])));
        $defaults['customer_name'] = (string)$resv['visitor_name'];
        $defaults['car_info'] = (string)($resv['car_info'] ?? '');
        $defaults['product_id'] = (int)$resv['product_id'];
        $defaults['product_name_snap'] = (string)$resv['product_name_snap'];
        $defaults['qty'] = (int)$resv['qty'];
        $defaults['cost_factory'] = (string)(int)($resv['factory_price'] ?? 0);
        $defaults['cost_supply'] = (string)(int)($resv['supply_price'] ?? 0);
    }
}

/* [현황판] 현장 입고 차량에서 작업 시작 — 차량번호 자동 채움, 상태는 작업중
   (예약 연동 중이 아닐 때만. tt_board_walkins 테이블이 아직 없어도 화면이 죽지 않게 격리) */
$walkinId = 0;
$walkinPlate = '';
$walkinInvalid = false;
$walkinReq = (int)($_GET['walkin_id'] ?? 0);
if (!$resv && $walkinReq > 0) {
    try {
        $stmt = $pdo->prepare('SELECT id, plate_no FROM tt_board_walkins WHERE id = :id AND work_log_id IS NULL LIMIT 1');
        $stmt->execute(['id' => $walkinReq]);
        if ($entry = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $walkinId = (int)$entry['id'];
            $walkinPlate = (string)$entry['plate_no'];
            $defaults['car_info'] = $walkinPlate;
            $defaults['status']   = 'working';
        } else {
            $walkinInvalid = true;
        }
    } catch (Throwable $e) {
        error_log('[log-add:walkin] ' . $e->getMessage());
        if (function_exists('sg_maybe_invalidate')) sg_maybe_invalidate($e);
        $walkinInvalid = true;
    }
}

/* 마감 여부 — 마감된 날짜엔 등록 불가 */
$settleCheck = get_settlement($defaults['work_date']);
$dateClosed = $settleCheck && $settleCheck['reopened_at'] === null;

/* 상품 목록 (검색 폼용 — 상위 500개) */
$products = [];
try {
    $products = $pdo->query('SELECT id, name, spec, price_sale, price_original, supply_price FROM tt_products WHERE status = "active" ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$errMsg = flash('tablet_error');
require __DIR__ . '/includes/header.php';
?>

<?php if ($errMsg): ?><div class="notice notice-err">⚠ <?= h($errMsg) ?></div><?php endif; ?>
<?php if ($dateClosed): ?>
<div class="notice notice-warn">🔒 <?= h($defaults['work_date']) ?> 은(는) 이미 일정산 마감이 완료되어 작업일지를 등록할 수 없습니다.</div>
<?php endif; ?>
<?php if ($walkinInvalid): ?>
<div class="notice notice-warn">⚠ 이미 작업이 시작됐거나 취소된 입고 건입니다. 이 화면에서 저장하면 입고 기록과 연결되지 않은 일반 작업일지로 등록됩니다. <a href="<?= BASE_URL ?>/tablet/walkin.php">현장 입고 목록으로</a></div>
<?php endif; ?>

<form method="post" action="<?= BASE_URL ?>/tablet/action.php" class="tablet-card" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
  <input type="hidden" name="action" value="log_save">
  <input type="hidden" name="id" value="0">
  <input type="hidden" name="source" value="<?= h($defaults['source']) ?>">
  <input type="hidden" name="walkin_id" value="<?= (int)$walkinId ?>">
  <?php if ($resv): ?>
  <input type="hidden" name="reservation_id" value="<?= (int)$resv['id'] ?>">
  <input type="hidden" name="customer_name" value="<?= h($defaults['customer_name']) ?>">
  <?php endif; ?>

  <div class="tablet-card-title">
    ➕ 작업일지 등록
    <span class="count-chip"><?= $resv ? '🗓️ 예약 연동 — ' . h($resv['res_no']) : ($walkinId > 0 ? '🚗 현장 입고 — ' . h($walkinPlate) : '🚶 일반 로드') ?></span>
  </div>

  <div class="tablet-form">

    <div class="form-row-3">
      <div class="form-item">
        <label>작업 날짜</label>
        <input type="date" name="work_date" value="<?= h($defaults['work_date']) ?>" required>
      </div>
      <div class="form-item">
        <label>작업 시작 시각</label>
        <input type="time" name="work_time" value="<?= h($defaults['work_time']) ?>" required>
      </div>
      <div class="form-item">
        <label>작업자</label>
        <select name="worker_id" required>
          <?php foreach ($workers as $w): ?>
          <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === (int)$defaults['worker_id'] ? 'selected' : '' ?>><?= h($w['name']) ?><?= $w['role'] ? ' (' . h($w['role']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-item">
      <label>작업구분</label>
      <div class="seg">
        <input type="radio" name="job_type" id="jt-tire" value="tire" <?= $defaults['job_type'] === 'tire' ? 'checked' : '' ?>>
        <label for="jt-tire">🛞 타이어</label>
        <input type="radio" name="job_type" id="jt-repair" value="repair" <?= $defaults['job_type'] === 'repair' ? 'checked' : '' ?>>
        <label for="jt-repair">🔧 정비</label>
        <input type="radio" name="job_type" id="jt-etc" value="etc" <?= $defaults['job_type'] === 'etc' ? 'checked' : '' ?>>
        <label for="jt-etc">📄 기타</label>
      </div>
    </div>

    <div class="form-item pen-field" data-pen-name="work-content" data-pen-fallback="(✍️ 손글씨로 작성 — 첨부 필기 이미지 참고)">
      <div class="pen-field-head">
        <label>작업내용 <span class="hint">무엇을 했는지 간단히 — 예: 앞2본 교체 + 휠밸런싱</span></label>
        <div class="pen-toggle">
          <button type="button" class="pen-mode on" data-mode="text">⌨️ 텍스트</button>
          <button type="button" class="pen-mode" data-mode="pen">✍️ 펜글씨</button>
        </div>
      </div>
      <textarea name="work_content" class="pen-text" required placeholder="작업내용을 입력해 주세요"><?= h($defaults['work_content']) ?></textarea>
      <div class="pen-pad" hidden>
        <div class="pen-canvas-wrap"><canvas></canvas></div>
        <div class="pen-tools">
          <button type="button" class="pen-tool pen-w" data-w="3">얇게</button>
          <button type="button" class="pen-tool pen-w on" data-w="5">보통</button>
          <button type="button" class="pen-tool pen-w" data-w="9">굵게</button>
          <button type="button" class="pen-tool pen-undo">↩ 되돌리기</button>
          <button type="button" class="pen-tool pen-clear">🧽 전체 지우기</button>
        </div>
        <span class="hint">✍️ 터치펜·손가락으로 직접 쓰세요 — 저장하면 '작업내용 필기' 이미지가 작업일지에 첨부됩니다</span>
      </div>
    </div>

    <div class="form-row">
      <div class="form-item">
        <label>작업금액 (원)</label>
        <input type="number" name="work_amount" id="workAmount" min="0" step="1000" value="<?= h((string)$defaults['work_amount']) ?>" placeholder="예: 560000" required>
      </div>
      <div class="form-item">
        <label>작업상태</label>
        <select name="status">
          <option value="done" <?= $defaults['status'] === 'done' ? 'selected' : '' ?>>✅ 완료</option>
          <option value="working" <?= $defaults['status'] === 'working' ? 'selected' : '' ?>>🔧 작업중</option>
        </select>
      </div>
    </div>

    <div class="form-item">
      <label>현장 결제수단 <span class="hint">작업 후 결제를 받았다면 선택 — 나중에 작업일지 카드의 "💰 결제등록"으로도 등록할 수 있습니다</span></label>
      <div class="seg">
        <input type="radio" name="pay_method" id="pm-none" value="none" <?= $defaults['pay_method'] === 'none' ? 'checked' : '' ?>>
        <label for="pm-none">⏳ 외상 (결제 전)</label>
        <input type="radio" name="pay_method" id="pm-card" value="card" <?= $defaults['pay_method'] === 'card' ? 'checked' : '' ?>>
        <label for="pm-card">💳 카드</label>
        <input type="radio" name="pay_method" id="pm-cash" value="cash" <?= $defaults['pay_method'] === 'cash' ? 'checked' : '' ?>>
        <label for="pm-cash">💵 현금</label>
        <input type="radio" name="pay_method" id="pm-transfer" value="transfer" <?= $defaults['pay_method'] === 'transfer' ? 'checked' : '' ?>>
        <label for="pm-transfer">🏦 계좌이체</label>
      </div>
    </div>

    <div class="form-row" id="payAmountRow" style="display:none;">
      <div class="form-item">
        <label>결제금액 (원)</label>
        <input type="number" name="pay_amount" id="payAmount" min="0" step="1000" value="<?= h((string)$defaults['pay_amount']) ?>" placeholder="비워두면 작업금액 그대로">
        <span class="hint">비워두면 작업금액으로 결제등록됩니다</span>
      </div>
    </div>

    <div class="form-row">
      <?php if ($resv): ?>
      <div class="form-item">
        <label>고객 (예약 연동)</label>
        <input type="text" value="<?= h($defaults['customer_name']) ?>" disabled>
        <span class="hint">예약 고객정보가 자동 연동됩니다</span>
      </div>
      <?php else: ?>
      <div class="form-item pen-field" data-pen-name="customer-name" data-pen-fallback="✍️ 손글씨 (첨부 필기 참고)">
        <div class="pen-field-head">
          <label>고객명 (선택)</label>
          <div class="pen-toggle">
            <button type="button" class="pen-mode on" data-mode="text">⌨️ 텍스트</button>
            <button type="button" class="pen-mode" data-mode="pen">✍️ 펜글씨</button>
          </div>
        </div>
        <input type="text" name="customer_name" class="pen-text" value="<?= h($defaults['customer_name']) ?>" placeholder="예: 김철수">
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
      <?php endif; ?>
      <div class="form-item">
        <label>차량정보 (선택)</label>
        <input type="text" name="car_info" value="<?= h($defaults['car_info']) ?>" placeholder="예: 그랜저 IG / 12가3456">
      </div>
    </div>

    <div class="form-item">
      <label>작업 상품 (타이어 — 선택)</label>
      <select name="product_id" id="productSelect">
        <option value="0">— 상품 없음 / 수작업 —</option>
        <?php foreach ($products as $p): ?>
        <option value="<?= (int)$p['id'] ?>" data-name="<?= h($p['name'] . ($p['spec'] ? ' (' . $p['spec'] . ')' : '')) ?>"
                data-factory="<?= (int)$p['price_original'] ?>" data-supply="<?= (int)$p['supply_price'] ?>"
                <?= (int)$p['id'] === (int)$defaults['product_id'] ? 'selected' : '' ?>>
          <?= h($p['name'] . ($p['spec'] ? ' [' . $p['spec'] . ']' : '') . ' — 판매가 ' . number_format((int)$p['price_sale']) . '원') ?>
        </option>
        <?php endforeach; ?>
      </select>
      <span class="hint">선택하면 공장도가·매입단가가 자동으로 채워집니다 (정비·기타 작업은 생략)</span>
    </div>

    <div class="form-item pen-field" data-pen-name="product-name" data-pen-fallback="✍️ 손글씨 (첨부 필기 참고)">
      <div class="pen-field-head">
        <label>작업상품 직접 쓰기 <span class="hint">목록에 없거나 직접 적을 때 — 텍스트 / 펜글씨</span></label>
        <div class="pen-toggle">
          <button type="button" class="pen-mode on" data-mode="text">⌨️ 텍스트</button>
          <button type="button" class="pen-mode" data-mode="pen">✍️ 펜글씨</button>
        </div>
      </div>
      <input type="text" name="product_name_snap" class="pen-text" value="<?= h($defaults['product_name_snap']) ?>" placeholder="예: 한국타이어 225/55R17 컴포트">
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
        <input type="number" name="qty" min="1" max="99" value="<?= (int)$defaults['qty'] ?>">
      </div>
      <div class="form-item">
        <label>공장도가 (원/본)</label>
        <input type="number" name="cost_factory" id="costFactory" min="0" step="1000" value="<?= h((string)$defaults['cost_factory']) ?>" placeholder="제조사 출고가">
      </div>
      <div class="form-item">
        <label>매입단가 (원/본)</label>
        <input type="number" name="cost_supply" id="costSupply" min="0" step="1000" value="<?= h((string)$defaults['cost_supply']) ?>" placeholder="실제 매입가">
      </div>
    </div>

    <div class="form-item">
      <label>작업 사진 첨부 (선택 · 최대 5장)</label>
      <input type="file" name="photos[]" id="photoInput" accept="image/*" capture="environment" multiple class="photo-input">
      <span class="hint">작업 전/후 사진을 찍어 첨부하면 정비 증빙으로 관리자 화면에 함께 표시됩니다 (JPG/PNG, 개당 5MB 이하)</span>
      <div class="photo-preview" id="photoPreview"></div>
    </div>

    <div class="action-bar">
      <a class="tablet-btn tablet-btn-ghost" href="<?= BASE_URL ?>/tablet/index.php?date=<?= h($defaults['work_date']) ?>">취소</a>
      <button type="submit" class="tablet-btn tablet-btn-xl" <?= $dateClosed ? 'disabled' : '' ?>>💾 작업일지 저장</button>
    </div>
  </div>
</form>

<script>
/* 사진 선택 미리보기 */
(function(){
  var input = document.getElementById('photoInput');
  var preview = document.getElementById('photoPreview');
  if (!input || !preview) return;
  input.addEventListener('change', function(){
    preview.innerHTML = '';
    var files = Array.prototype.slice.call(input.files || []).slice(0, 5);
    if (files.length < (input.files || []).length) {
      preview.insertAdjacentHTML('afterbegin', '<div class="photo-over">📷 최대 5장까지 첨부됩니다 (나머지는 제외)</div>');
    }
    files.forEach(function(f){
      if (!/^image\//.test(f.type)) return;
      var url = URL.createObjectURL(f);
      var item = document.createElement('div');
      item.className = 'photo-thumb';
      item.style.backgroundImage = 'url(' + url + ')';
      preview.appendChild(item);
    });
  });
})();
</script>

<script>
/* 상품 선택 시 공장도가/매입단가 자동 채움 */
(function(){
  var sel = document.getElementById('productSelect');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    var empty = opt.getAttribute('value') === '0';
    var cf = document.getElementById('costFactory');
    var cs = document.getElementById('costSupply');
    if (empty) return;
    if (cf && !cf.value) cf.value = opt.getAttribute('data-factory') || '';
    if (cs && !cs.value) cs.value = opt.getAttribute('data-supply') || '';
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
