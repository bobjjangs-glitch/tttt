<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('banners'); // [주의] 실제 권한 슬러그가 다르면 여기만 수정

$pdo = Database::connection();

/* =====================================================================
   테이블이 없으면 즉시 생성 (배포 시 마이그레이션을 빠뜻려도 최소 동작 보장)
   ===================================================================== */
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

function redirect_popups(): never {
    redirect('/admin/popups.php');
}

/* =====================================================================
   이미지 리사이즈 — 배너와 달리 잘라내지 않고(contain) 비율 유지한 채
   지정 크기 안에 맞춘다. 텍스트/버튼이 포함된 팝업 이미지가 잘리는 사고 방지.
   ===================================================================== */
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

/* =====================================================================
   POST 핸들러 — 등록/수정
   ===================================================================== */
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

/* =====================================================================
   조회
   ===================================================================== */
$popups = $pdo->query('
    SELECT id, title, image_url, link_url, width, height, start_at, end_at, allow_today_close, sort_order, is_active
    FROM tt_popups ORDER BY sort_order ASC, id DESC
')->fetchAll();

$pageTitle = '팝업 광고 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card" style="margin-bottom:20px">
  <h2 id="popupFormTitle">팝업 광고 등록</h2>
  <form method="post" enctype="multipart/form-data" id="popupForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="save_popup">
    <input type="hidden" name="popup_id" id="popupIdInput" value="0">

    <div class="admin-form-grid">
      <div class="admin-form-row">
        <label>팝업 제목 (관리용) <span class="req">*</span></label>
        <input type="text" name="title" id="popupTitleInput" required maxlength="80">
      </div>
      <div class="admin-form-row">
        <label>클릭 시 이동할 링크 URL</label>
        <input type="text" name="link_url" id="popupLinkInput" placeholder="https://... (비워두면 이미지만 표시)">
      </div>
      <div class="admin-form-row">
        <label>노출 순서 (숫자가 작을수록 먼저 뜸)</label>
        <input type="number" name="sort_order" id="popupSortInput" value="0">
      </div>

      <div class="admin-form-row">
        <label>가로(px)</label>
        <input type="number" name="width" id="popupWidthInput" value="420" min="<?= POPUP_MIN_W ?>" max="<?= POPUP_MAX_W ?>">
      </div>
      <div class="admin-form-row">
        <label>세로(px)</label>
        <input type="number" name="height" id="popupHeightInput" value="560" min="<?= POPUP_MIN_H ?>" max="<?= POPUP_MAX_H ?>">
      </div>

      <div class="admin-form-row">
        <label>노출 시작일시</label>
        <input type="datetime-local" name="start_at" id="popupStartInput">
      </div>
      <div class="admin-form-row">
        <label>노출 종료일시</label>
        <input type="datetime-local" name="end_at" id="popupEndInput">
        <p class="admin-form-hint">둘 다 비워두면 노출 여부는 오직 아래 '노출/숨김' 토글로만 제어됩니다.</p>
      </div>

      <div class="admin-form-row">
        <label>
          <input type="checkbox" name="allow_today_close" id="popupAllowTodayClose" value="1" checked>
          "오늘 하루 보지 않기" 버튼 노출
        </label>
      </div>

      <div class="admin-form-row admin-form-row-full">
        <p class="admin-form-hint">지정한 가로×세로 안에 이미지 비율을 유지한 채(잘리지 않고) 맞춰서 저장됩니다.</p>
      </div>

      <div class="admin-form-row admin-form-row-full" id="popupCurrentPreview"></div>

      <div class="admin-form-row admin-form-row-full">
        <label id="popupImageLabel">팝업 이미지 <span class="req">*</span></label>
        <input type="file" name="image" accept="image/*">
      </div>
    </div>

    <div class="admin-form-actions">
      <button type="button" class="btn-admin-secondary" id="popupFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="popupFormSubmitBtn">팝업 등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>등록된 팝업 광고 <span class="admin-count-pill"><?= count($popups) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead>
      <tr>
        <th style="width:110px">미리보기</th><th>제목</th><th>링크</th><th style="width:90px">사이즈</th>
        <th style="width:170px">노출 기간</th><th style="width:70px">순서</th><th style="width:90px">노출</th><th style="width:150px"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($popups)): ?>
      <tr><td colspan="8" class="admin-empty-row">🪧 등록된 팝업 광고가 없습니다.</td></tr>
    <?php else: foreach ($popups as $pp): ?>
      <tr>
        <td><img src="<?= h($pp['image_url']) ?>" style="width:90px;height:36px;object-fit:contain;border-radius:6px;background:#f1f5f9;"></td>
        <td><span class="admin-prod-name"><?= h($pp['title']) ?></span></td>
        <td class="admin-text-sub"><?= $pp['link_url'] ? h($pp['link_url']) : '-' ?></td>
        <td class="mono"><?= (int)$pp['width'] ?>×<?= (int)$pp['height'] ?></td>
        <td class="admin-text-sub">
          <?= $pp['start_at'] ? h(date('Y-m-d H:i', strtotime($pp['start_at']))) : '제한없음' ?>
          ~
          <?= $pp['end_at'] ? h(date('Y-m-d H:i', strtotime($pp['end_at']))) : '제한없음' ?>
        </td>
        <td class="mono"><?= (int)$pp['sort_order'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?><input type="hidden" name="form_type" value="toggle_popup_status">
            <input type="hidden" name="popup_id" value="<?= (int)$pp['id'] ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $pp['is_active']?'done':'cancelled' ?>"><?= $pp['is_active']?'노출중':'숨김' ?></button>
          </form>
        </td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-popup"
            data-id="<?= (int)$pp['id'] ?>"
            data-title="<?= h($pp['title']) ?>"
            data-link="<?= h((string)$pp['link_url']) ?>"
            data-width="<?= (int)$pp['width'] ?>"
            data-height="<?= (int)$pp['height'] ?>"
            data-sort="<?= (int)$pp['sort_order'] ?>"
            data-start="<?= $pp['start_at'] ? h(date('Y-m-d\TH:i', strtotime($pp['start_at']))) : '' ?>"
            data-end="<?= $pp['end_at'] ? h(date('Y-m-d\TH:i', strtotime($pp['end_at']))) : '' ?>"
            data-today-close="<?= (int)$pp['allow_today_close'] ?>"
            data-image="<?= h($pp['image_url']) ?>">수정</button>
          <form method="post" style="display:inline" onsubmit="return confirm('이 팝업 광고를 삭제하시겠습니까?');">
            <?= Csrf::field() ?><input type="hidden" name="form_type" value="delete_popup">
            <input type="hidden" name="popup_id" value="<?= (int)$pp['id'] ?>">
            <button type="submit" class="admin-link-btn admin-link-btn-danger">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
const popupForm         = document.getElementById('popupForm');
const popupFormTitle     = document.getElementById('popupFormTitle');
const popupIdInput       = document.getElementById('popupIdInput');
const popupTitleInput    = document.getElementById('popupTitleInput');
const popupLinkInput     = document.getElementById('popupLinkInput');
const popupSortInput     = document.getElementById('popupSortInput');
const popupWidthInput    = document.getElementById('popupWidthInput');
const popupHeightInput   = document.getElementById('popupHeightInput');
const popupStartInput    = document.getElementById('popupStartInput');
const popupEndInput      = document.getElementById('popupEndInput');
const popupAllowToday    = document.getElementById('popupAllowTodayClose');
const popupImageLabel    = document.getElementById('popupImageLabel');
const popupCurrentPreview = document.getElementById('popupCurrentPreview');
const popupFormSubmitBtn = document.getElementById('popupFormSubmitBtn');
const popupFormCancelBtn = document.getElementById('popupFormCancelBtn');

document.querySelectorAll('.btn-edit-popup').forEach(btn => {
  btn.addEventListener('click', () => {
    popupFormTitle.textContent = '팝업 광고 수정';
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
    popupCurrentPreview.innerHTML = '<img src="' + btn.dataset.image + '" style="max-width:200px;border-radius:8px;border:1px solid #e2e8f0;">';
    popupFormSubmitBtn.textContent = '수정 완료';
    popupFormCancelBtn.style.display = '';
    popupForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

popupFormCancelBtn.addEventListener('click', () => {
  popupForm.reset();
  popupFormTitle.textContent = '팝업 광고 등록';
  popupIdInput.value = '0';
  popupImageLabel.innerHTML = '팝업 이미지 <span class="req">*</span>';
  popupCurrentPreview.innerHTML = '';
  popupFormSubmitBtn.textContent = '팝업 등록';
  popupFormCancelBtn.style.display = 'none';
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
