<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('banners'); // [주의] 실제 권한 슬러그가 다르면 여기만 수정

$pdo = Database::connection();

function ensure_popups_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tt_popups (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            title             VARCHAR(100) NOT NULL,
            image_url         VARCHAR(255) NOT NULL,
            link_url          VARCHAR(255) NULL,
            width             INT NOT NULL DEFAULT 420,
            height            INT NOT NULL DEFAULT 560,
            start_at          DATETIME NULL,
            end_at            DATETIME NULL,
            allow_today_close TINYINT(1) NOT NULL DEFAULT 1,
            sort_order        INT NOT NULL DEFAULT 0,
            is_active         TINYINT(1) NOT NULL DEFAULT 1,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
ensure_popups_table($pdo);

const POPUP_MAX_UPLOAD_PIXEL_W = 6000;
const POPUP_MAX_UPLOAD_PIXEL_H = 6000;
const POPUP_MIN_W = 200; const POPUP_MAX_W = 900;
const POPUP_MIN_H = 200; const POPUP_MAX_H = 1000;

function redirect_popups(): never { redirect('/admin/popups.php'); }

function popup_resize_contain(string $srcPath, string $destPath, string $ext, int $targetW, int $targetH): bool
{
    $ext = strtolower($ext);
    switch ($ext) {
        case 'jpg': case 'jpeg': $src = @imagecreatefromjpeg($srcPath); break;
        case 'png': $src = @imagecreatefrompng($srcPath); break;
        case 'gif': $src = @imagecreatefromgif($srcPath); break;
        case 'webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false; break;
        default: $src = false;
    }
    if ($src === false) return false;

    $srcW = imagesx($src); $srcH = imagesy($src);
    $scale   = min($targetW / $srcW, $targetH / $srcH);
    $scaledW = max(1, (int)round($srcW * $scale));
    $scaledH = max(1, (int)round($srcH * $scale));

    $dest = imagecreatetruecolor($scaledW, $scaledH);
    if (in_array($ext, ['png', 'gif', 'webp'], true)) {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);
    }
    imagecopyresampled($dest, $src, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

    $ok = false;
    switch ($ext) {
        case 'jpg': case 'jpeg': $ok = imagejpeg($dest, $destPath, 92); break;
        case 'png': $ok = imagepng($dest, $destPath, 6); break;
        case 'gif': $ok = imagegif($dest, $destPath); break;
        case 'webp': $ok = function_exists('imagewebp') ? imagewebp($dest, $destPath, 92) : false; break;
    }
    imagedestroy($src); imagedestroy($dest);
    return $ok;
}

function popup_handle_upload(array $file, int $targetW, int $targetH): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'url' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => '이미지 업로드 중 오류가 발생했습니다. (code=' . $file['error'] . ')'];

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) return ['ok' => false, 'msg' => '이미지 파일만 업로드할 수 있습니다.'];
    if ($imageInfo[0] > POPUP_MAX_UPLOAD_PIXEL_W || $imageInfo[1] > POPUP_MAX_UPLOAD_PIXEL_H) {
        return ['ok' => false, 'msg' => "이미지 해상도가 너무 큽니다. (최대 " . POPUP_MAX_UPLOAD_PIXEL_W . "×" . POPUP_MAX_UPLOAD_PIXEL_H . "px)"];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'msg' => '지원하지 않는 이미지 형식입니다. (jpg, png, webp, gif만 가능)'];
    if ($file['size'] > 8 * 1024 * 1024) return ['ok' => false, 'msg' => '팝업 이미지는 8MB 이하만 가능합니다.'];
    if (!extension_loaded('gd')) return ['ok' => false, 'msg' => '서버에 GD 확장이 없어 이미지 처리를 할 수 없습니다.'];

    $uploadDir = __DIR__ . '/../uploads/popups';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $filename = 'pp_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = $uploadDir . '/' . $filename;
    $tmpKeep  = $uploadDir . '/_tmp_' . bin2hex(random_bytes(6)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $tmpKeep)) return ['ok' => false, 'msg' => '이미지 저장에 실패했습니다.'];
    $resized = popup_resize_contain($tmpKeep, $target, $ext, $targetW, $targetH);
    @unlink($tmpKeep);
    if (!$resized) return ['ok' => false, 'msg' => '이미지 리사이즈 처리 중 오류가 발생했습니다.'];

    return ['ok' => true, 'url' => BASE_URL . '/uploads/popups/' . $filename];
}

/* ===== POST 핸들러 (기존과 동일) ===== */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_popup') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_popups(); }

    $popupId   = (int)($_POST['popup_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $linkUrl   = trim($_POST['link_url'] ?? '');
    $width     = (int)($_POST['width'] ?? 420);
    $height    = (int)($_POST['height'] ?? 560);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $allowTodayClose = isset($_POST['allow_today_close']) ? 1 : 0;

    $startAtRaw = trim($_POST['start_at'] ?? '');
    $endAtRaw   = trim($_POST['end_at'] ?? '');
    $startAt = $startAtRaw !== '' ? $startAtRaw . ':00' : null;
    $endAt   = $endAtRaw !== '' ? $endAtRaw . ':00' : null;

    if ($title === '') { flash('admin_error', '팝업 제목을 입력해 주세요.'); redirect_popups(); }
    if ($width < POPUP_MIN_W || $width > POPUP_MAX_W || $height < POPUP_MIN_H || $height > POPUP_MAX_H) {
        flash('admin_error', "팝업 크기는 가로 " . POPUP_MIN_W . "~" . POPUP_MAX_W . "px, 세로 " . POPUP_MIN_H . "~" . POPUP_MAX_H . "px 사이로 입력해 주세요.");
        redirect_popups();
    }
    if ($startAt && $endAt && strtotime($startAt) > strtotime($endAt)) {
        flash('admin_error', '노출 종료일은 시작일보다 이후여야 합니다.');
        redirect_popups();
    }

    $uploadResult = popup_handle_upload($_FILES['image'] ?? [], $width, $height);
    if (!$uploadResult['ok']) { flash('admin_error', $uploadResult['msg']); redirect_popups(); }

    try {
        if ($popupId > 0) {
            $existing = $pdo->prepare('SELECT image_url FROM tt_popups WHERE id = :id');
            $existing->execute(['id' => $popupId]);
            $row = $existing->fetch();
            if (!$row) { flash('admin_error', '존재하지 않는 팝업입니다.'); redirect_popups(); }

            $imageUrl = $row['image_url'];
            if (!empty($uploadResult['url'])) $imageUrl = $uploadResult['url'];

            $pdo->prepare('
                UPDATE tt_popups
                SET title=:title, image_url=:image, link_url=:link, width=:w, height=:h,
                    start_at=:sa, end_at=:ea, allow_today_close=:atc, sort_order=:sort
                WHERE id=:id
            ')->execute([
                'title' => $title, 'image' => $imageUrl, 'link' => $linkUrl !== '' ? $linkUrl : null,
                'w' => $width, 'h' => $height, 'sa' => $startAt, 'ea' => $endAt,
                'atc' => $allowTodayClose, 'sort' => $sortOrder, 'id' => $popupId,
            ]);

            AdminAuth::log((int)AdminAuth::currentAdminId(), 'popup_update', "팝업#{$popupId} 수정 ({$title})");
            flash('admin_success', '팝업 광고가 수정되었습니다.');
        } else {
            if (empty($uploadResult['url'])) { flash('admin_error', '팝업 이미지를 선택해 주세요.'); redirect_popups(); }

            $pdo->prepare('
                INSERT INTO tt_popups (title, image_url, link_url, width, height, start_at, end_at, allow_today_close, sort_order, is_active)
                VALUES (:title, :image, :link, :w, :h, :sa, :ea, :atc, :sort, 1)
            ')->execute([
                'title' => $title, 'image' => $uploadResult['url'], 'link' => $linkUrl !== '' ? $linkUrl : null,
                'w' => $width, 'h' => $height, 'sa' => $startAt, 'ea' => $endAt,
                'atc' => $allowTodayClose, 'sort' => $sortOrder,
            ]);

            $newId = (int)$pdo->lastInsertId();
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'popup_create', "팝업#{$newId} 등록 ({$title})");
            flash('admin_success', "'{$title}' 팝업 광고가 등록되었습니다.");
        }
    } catch (Throwable $e) {
        error_log('[admin/popups save_popup] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect_popups();
}

if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_popup_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_popups(); }
    $popupId = (int)($_POST['popup_id'] ?? 0);
    $pdo->prepare('UPDATE tt_popups SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $popupId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'popup_toggle', "팝업#{$popupId} 노출상태 변경");
    redirect_popups();
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_popup') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_popups(); }
    $popupId = (int)($_POST['popup_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_popups WHERE id = :id')->execute(['id' => $popupId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'popup_delete', "팝업#{$popupId} 삭제");
    flash('admin_success', '팝업 광고가 삭제되었습니다.');
    redirect_popups();
}

/* ===== 조회 + 스케줄 상태 계산 ===== */
$popups = $pdo->query('
    SELECT id, title, image_url, link_url, width, height, start_at, end_at, allow_today_close, sort_order, is_active
    FROM tt_popups ORDER BY sort_order ASC, id DESC
')->fetchAll();

/**
 * 노출 상태를 4단계로 계산한다: hidden(숨김) / scheduled(예정) / live(진행중) / ended(종료)
 * is_active 토글과 별개로, 관리자가 지금 실제로 화면에 뜨고 있는지 한눈에 알 수 있게 하기 위함.
 */
function popup_schedule_state(array $pp): array
{
    if ((int)$pp['is_active'] === 0) {
        return ['key' => 'hidden', 'label' => '숨김'];
    }
    $now = time();
    $start = $pp['start_at'] ? strtotime($pp['start_at']) : null;
    $end   = $pp['end_at'] ? strtotime($pp['end_at']) : null;

    if ($start !== null && $now < $start) return ['key' => 'scheduled', 'label' => '예정'];
    if ($end !== null && $now > $end)     return ['key' => 'ended', 'label' => '종료'];
    return ['key' => 'live', 'label' => '진행중'];
}

$pageTitle = '팝업 광고 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card" style="margin-bottom:24px">
  <div class="admin-card-head-row">
    <h2 id="popupFormTitle">✨ 팝업 광고 등록</h2>
  </div>

  <form method="post" enctype="multipart/form-data" id="popupForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="save_popup">
    <input type="hidden" name="popup_id" id="popupIdInput" value="0">

    <div class="popup-form-layout">
      <div>
        <div class="admin-form-grid" style="grid-template-columns:repeat(2,1fr)">
          <div class="admin-form-row admin-form-row-full">
            <label>팝업 제목 (관리용) <span class="req">*</span></label>
            <input type="text" name="title" id="popupTitleInput" required maxlength="80" placeholder="예: 8월 여름 타이어 이벤트">
          </div>
          <div class="admin-form-row admin-form-row-full">
            <label>클릭 시 이동할 링크 URL</label>
            <input type="text" name="link_url" id="popupLinkInput" placeholder="https://... (비워두면 이미지만 표시)">
          </div>

          <div class="admin-form-row">
            <label>가로(px)</label>
            <input type="number" name="width" id="popupWidthInput" value="420" min="<?= POPUP_MIN_W ?>" max="<?= POPUP_MAX_W ?>">
          </div>
          <div class="admin-form-row">
            <label>세로(px)</label>
            <input type="number" name="height" id="popupHeightInput" value="560" min="<?= POPUP_MIN_H ?>" max="<?= POPUP_MAX_H ?>">
          </div>

          <div class="admin-form-row admin-form-row-full">
            <div class="popup-period-card" id="popupPeriodCard">
              <div class="popup-period-card-label">📅 노출 기간 설정</div>

              <div class="popup-period-row">
                <div class="popup-period-field">
                  <span class="popup-period-field-title">시작일시</span>
                  <div class="popup-datetime-wrap">
                    <span class="dt-icon">🟢</span>
                    <input type="datetime-local" name="start_at" id="popupStartInput">
                  </div>
                </div>

                <span class="popup-period-arrow">→</span>

                <div class="popup-period-field">
                  <span class="popup-period-field-title">종료일시</span>
                  <div class="popup-datetime-wrap">
                    <span class="dt-icon">🔴</span>
                    <input type="datetime-local" name="end_at" id="popupEndInput">
                  </div>
                </div>
              </div>

              <div class="popup-period-summary">
                <span class="popup-period-summary-badge" id="popupPeriodSummaryBadge">상시 노출</span>
                <button type="button" class="popup-period-clear-btn" id="popupPeriodClearBtn">기간 초기화</button>
              </div>
            </div>
          </div>

          <div class="admin-form-row">
            <label>노출 순서</label>
            <input type="number" name="sort_order" id="popupSortInput" value="0">
            <p class="admin-form-hint">숫자가 작을수록 먼저 뜹니다.</p>
          </div>
          <div class="admin-form-row" style="justify-content:center">
            <label class="admin-checkbox-inline" style="margin-top:26px">
              <input type="checkbox" name="allow_today_close" id="popupAllowTodayClose" value="1" checked>
              "오늘 하루 보지 않기" 버튼 노출
            </label>
          </div>

          <div class="admin-form-row admin-form-row-full">
            <label id="popupImageLabel">팝업 이미지 <span class="req">*</span></label>
            <div class="popup-dropzone" id="popupDropzone">
              <input type="file" name="image" id="popupImageInput" accept="image/*">
              <div class="popup-dropzone-icon">🖼️</div>
              <div class="popup-dropzone-text">클릭 또는 이미지를 끌어다 놓으세요</div>
              <div class="popup-dropzone-sub">jpg, png, webp, gif · 최대 8MB · 비율 유지 자동 리사이즈</div>
            </div>
          </div>
        </div>
      </div>

      <div class="popup-preview-panel">
        <span class="popup-preview-panel-label">실시간 미리보기</span>
        <div class="popup-preview-box" id="popupPreviewBox" style="width:140px;height:187px;">
          <img id="popupPreviewImg" style="display:none;">
          <span class="popup-preview-box-empty" id="popupPreviewEmpty">🪧</span>
        </div>
        <span class="popup-preview-dim" id="popupPreviewDim">420 × 560</span>
      </div>
    </div>

    <div class="admin-form-actions">
      <button type="button" class="btn-admin-secondary" id="popupFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="popupFormSubmitBtn">팝업 등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <div class="admin-card-head-row">
    <h2>등록된 팝업 광고 <span class="admin-count-pill"><?= count($popups) ?>개</span></h2>
  </div>

  <?php if (empty($popups)): ?>
    <div class="admin-empty-row">🪧 등록된 팝업 광고가 없습니다. 위 폼에서 첫 팝업을 등록해 보세요.</div>
  <?php else: ?>
    <div class="popup-grid">
      <?php foreach ($popups as $pp):
        $state = popup_schedule_state($pp);
      ?>
      <div class="popup-card">
        <div class="popup-card-thumb">
          <span class="popup-schedule-badge popup-schedule-<?= $state['key'] ?>"><?= h($state['label']) ?></span>
          <img src="<?= h($pp['image_url']) ?>" alt="<?= h($pp['title']) ?>">
        </div>
        <div class="popup-card-body">
          <div class="popup-card-title"><?= h($pp['title']) ?></div>
          <div class="popup-card-meta">
            <span class="popup-meta-chip"><?= (int)$pp['width'] ?>×<?= (int)$pp['height'] ?></span>
            <span class="popup-meta-chip">순서 <?= (int)$pp['sort_order'] ?></span>
            <?php if ($pp['link_url']): ?><span class="popup-meta-chip">🔗 링크 있음</span><?php endif; ?>
          </div>
          <div class="popup-card-period">
            <?= $pp['start_at'] ? h(date('Y.m.d H:i', strtotime($pp['start_at']))) : '제한없음' ?>
            &nbsp;→&nbsp;
            <?= $pp['end_at'] ? h(date('Y.m.d H:i', strtotime($pp['end_at']))) : '제한없음' ?>
          </div>
          <div class="popup-card-footer">
            <form method="post" class="popup-toggle-form">
              <?= Csrf::field() ?><input type="hidden" name="form_type" value="toggle_popup_status">
              <input type="hidden" name="popup_id" value="<?= (int)$pp['id'] ?>">
              <button type="submit" class="popup-toggle-switch <?= $pp['is_active'] ? 'on' : '' ?>" aria-label="노출 토글"></button>
              <span class="popup-toggle-label"><?= $pp['is_active'] ? '노출' : '숨김' ?></span>
            </form>
            <div class="popup-card-actions">
              <button type="button" class="popup-icon-btn btn-edit-popup" title="수정"
                data-id="<?= (int)$pp['id'] ?>"
                data-title="<?= h($pp['title']) ?>"
                data-link="<?= h((string)$pp['link_url']) ?>"
                data-width="<?= (int)$pp['width'] ?>"
                data-height="<?= (int)$pp['height'] ?>"
                data-sort="<?= (int)$pp['sort_order'] ?>"
                data-start="<?= $pp['start_at'] ? h(date('Y-m-d\TH:i', strtotime($pp['start_at']))) : '' ?>"
                data-end="<?= $pp['end_at'] ? h(date('Y-m-d\TH:i', strtotime($pp['end_at']))) : '' ?>"
                data-today-close="<?= (int)$pp['allow_today_close'] ?>"
                data-image="<?= h($pp['image_url']) ?>">✏️</button>
              <form method="post" onsubmit="return confirm('이 팝업 광고를 삭제하시겠습니까?');">
                <?= Csrf::field() ?><input type="hidden" name="form_type" value="delete_popup">
                <input type="hidden" name="popup_id" value="<?= (int)$pp['id'] ?>">
                <button type="submit" class="popup-icon-btn danger" title="삭제">🗑️</button>
              </form>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
const popupForm          = document.getElementById('popupForm');
const popupFormTitle      = document.getElementById('popupFormTitle');
const popupIdInput        = document.getElementById('popupIdInput');
const popupTitleInput     = document.getElementById('popupTitleInput');
const popupLinkInput      = document.getElementById('popupLinkInput');
const popupSortInput      = document.getElementById('popupSortInput');
const popupWidthInput     = document.getElementById('popupWidthInput');
const popupHeightInput    = document.getElementById('popupHeightInput');
const popupStartInput     = document.getElementById('popupStartInput');
const popupEndInput       = document.getElementById('popupEndInput');
const popupAllowToday     = document.getElementById('popupAllowTodayClose');
const popupImageLabel     = document.getElementById('popupImageLabel');
const popupImageInput     = document.getElementById('popupImageInput');
const popupDropzone       = document.getElementById('popupDropzone');
const popupFormSubmitBtn  = document.getElementById('popupFormSubmitBtn');
const popupFormCancelBtn  = document.getElementById('popupFormCancelBtn');
const popupPreviewBox     = document.getElementById('popupPreviewBox');
const popupPreviewImg     = document.getElementById('popupPreviewImg');
const popupPreviewEmpty   = document.getElementById('popupPreviewEmpty');
const popupPreviewDim     = document.getElementById('popupPreviewDim');

/* ===== 노출 기간 실시간 계산 및 카드 상태 표시 ===== */
const popupPeriodCard          = document.getElementById('popupPeriodCard');
const popupPeriodSummaryBadge  = document.getElementById('popupPeriodSummaryBadge');
const popupPeriodClearBtn      = document.getElementById('popupPeriodClearBtn');

function updatePeriodSummary() {
  const startVal = popupStartInput.value;
  const endVal   = popupEndInput.value;

  popupPeriodCard.classList.remove('has-error');
  popupPeriodSummaryBadge.classList.remove('always', 'error');

  if (!startVal && !endVal) {
    popupPeriodSummaryBadge.textContent = '상시 노출 (기간 제한 없음)';
    popupPeriodSummaryBadge.classList.add('always');
    return;
  }

  if (startVal && endVal) {
    const start = new Date(startVal);
    const end   = new Date(endVal);
    if (end < start) {
      popupPeriodCard.classList.add('has-error');
      popupPeriodSummaryBadge.classList.add('error');
      popupPeriodSummaryBadge.textContent = '⚠️ 종료일이 시작일보다 빠릅니다';
      return;
    }
    const diffDays = Math.max(1, Math.ceil((end - start) / (1000 * 60 * 60 * 24)));
    popupPeriodSummaryBadge.textContent = `총 ${diffDays}일간 진행`;
    return;
  }

  if (startVal && !endVal) {
    popupPeriodSummaryBadge.textContent = '시작일 이후 계속 노출 (종료일 미지정)';
    return;
  }

  popupPeriodSummaryBadge.textContent = '종료일까지 즉시 노출 시작';
}

popupStartInput.addEventListener('input', updatePeriodSummary);
popupEndInput.addEventListener('input', updatePeriodSummary);
popupPeriodClearBtn.addEventListener('click', () => {
  popupStartInput.value = '';
  popupEndInput.value = '';
  updatePeriodSummary();
});

updatePeriodSummary(); // 최초 진입 시 1회 실행

/* ===== 실시간 미리보기 박스 크기 갱신 (비율 유지, 최대 200x260 안에서 스케일) ===== */
const PREVIEW_MAX_W = 200, PREVIEW_MAX_H = 260;
function updatePreviewBoxSize() {
  const w = Math.max(1, parseInt(popupWidthInput.value, 10) || 1);
  const h = Math.max(1, parseInt(popupHeightInput.value, 10) || 1);
  const scale = Math.min(PREVIEW_MAX_W / w, PREVIEW_MAX_H / h, 1);
  popupPreviewBox.style.width  = Math.round(w * scale) + 'px';
  popupPreviewBox.style.height = Math.round(h * scale) + 'px';
  popupPreviewDim.textContent = w + ' × ' + h;
}
popupWidthInput.addEventListener('input', updatePreviewBoxSize);
popupHeightInput.addEventListener('input', updatePreviewBoxSize);
updatePreviewBoxSize();

/* ===== 이미지 선택 시 실제 파일 미리보기 표시 ===== */
function showPreviewFromFile(file) {
  if (!file) return;
  const reader = new FileReader();
  reader.onload = (e) => {
    popupPreviewImg.src = e.target.result;
    popupPreviewImg.style.display = 'block';
    popupPreviewEmpty.style.display = 'none';
  };
  reader.readAsDataURL(file);
}
popupImageInput.addEventListener('change', () => {
  if (popupImageInput.files && popupImageInput.files[0]) {
    showPreviewFromFile(popupImageInput.files[0]);
  }
});

/* 드래그 앤 드롭 시각 효과 */
['dragenter', 'dragover'].forEach(evt => {
  popupDropzone.addEventListener(evt, (e) => { e.preventDefault(); popupDropzone.classList.add('dragover'); });
});
['dragleave', 'drop'].forEach(evt => {
  popupDropzone.addEventListener(evt, (e) => { e.preventDefault(); popupDropzone.classList.remove('dragover'); });
});
popupDropzone.addEventListener('drop', (e) => {
  const file = e.dataTransfer.files && e.dataTransfer.files[0];
  if (file) {
    popupImageInput.files = e.dataTransfer.files;
    showPreviewFromFile(file);
  }
});

/* ===== 수정 버튼 클릭 시 폼에 값 채우기 ===== */
document.querySelectorAll('.btn-edit-popup').forEach(btn => {
  btn.addEventListener('click', () => {
    popupFormTitle.textContent = '✏️ 팝업 광고 수정';
    popupIdInput.value = btn.dataset.id;
    popupTitleInput.value = btn.dataset.title;
    popupLinkInput.value = btn.dataset.link;
    popupSortInput.value = btn.dataset.sort;
    popupWidthInput.value = btn.dataset.width;
    popupHeightInput.value = btn.dataset.height;
    popupStartInput.value = btn.dataset.start;
    popupEndInput.value = btn.dataset.end;
    popupAllowToday.checked = btn.dataset.todayClose === '1';
    popupImageLabel.innerHTML = '팝업 이미지 <span class="admin-text-sub">(선택 시에만 교체됩니다)</span>';

    popupPreviewImg.src = btn.dataset.image;
    popupPreviewImg.style.display = 'block';
    popupPreviewEmpty.style.display = 'none';
    updatePreviewBoxSize();
    updatePeriodSummary(); // [수정] 기존 저장된 기간 값을 배지에도 즉시 반영

    popupFormSubmitBtn.textContent = '수정 완료';
    popupFormCancelBtn.style.display = '';
    popupForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

popupFormCancelBtn.addEventListener('click', () => {
  popupForm.reset();
  popupFormTitle.textContent = '✨ 팝업 광고 등록';
  popupIdInput.value = '0';
  popupImageLabel.innerHTML = '팝업 이미지 <span class="req">*</span>';
  popupPreviewImg.style.display = 'none';
  popupPreviewImg.src = '';
  popupPreviewEmpty.style.display = 'block';
  popupFormSubmitBtn.textContent = '팝업 등록';
  popupFormCancelBtn.style.display = 'none';
  updatePreviewBoxSize();
  updatePeriodSummary(); // [수정] 폼 초기화 시 배지도 '상시 노출' 상태로 되돌림
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
