<?php
// admin/promo-banners.php — 프로모 배너 관리는 배너 통합 관리 화면으로 이전되었습니다.
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();
redirect('/admin/banners.php?tab=promo');


$pdo = Database::connection();

// ★ 카테고리 아이콘 저장 규격 (정사각형 → 직사각형, 4:5 비율)
const CATICON_TARGET_W = 220;
const CATICON_TARGET_H = 280;

function caticon_resize_rect(string $srcPath, string $destPath, string $ext, int $targetW, int $targetH): bool
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

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // ★ cover 방식: 직사각형 틀을 꽉 채우고 넘치는 부분은 중앙 기준으로 잘라냄
    $scale   = max($targetW / $srcW, $targetH / $srcH);
    $scaledW = (int)round($srcW * $scale);
    $scaledH = (int)round($srcH * $scale);
    $offsetX = (int)round(($targetW - $scaledW) / 2);
    $offsetY = (int)round(($targetH - $scaledH) / 2);

    $dest = imagecreatetruecolor($targetW, $targetH);
    if ($ext === 'png' || $ext === 'gif' || $ext === 'webp') {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);
    }

    imagecopyresampled($dest, $src, $offsetX, $offsetY, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

    $ok = false;
    switch ($ext) {
        case 'jpg': case 'jpeg': $ok = imagejpeg($dest, $destPath, 90); break;
        case 'png': $ok = imagepng($dest, $destPath, 6); break;
        case 'gif': $ok = imagegif($dest, $destPath); break;
        case 'webp': $ok = function_exists('imagewebp') ? imagewebp($dest, $destPath, 90) : false; break;
    }

    imagedestroy($src);
    imagedestroy($dest);
    return $ok;
}

function caticon_handle_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'url' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => '업로드 중 오류가 발생했습니다. (code=' . $file['error'] . ')'];
    if (@getimagesize($file['tmp_name']) === false) return ['ok' => false, 'msg' => '이미지 파일만 업로드할 수 있습니다.'];

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'msg' => '지원하지 않는 이미지 형식입니다.'];
    if ($file['size'] > 5 * 1024 * 1024) return ['ok' => false, 'msg' => '이미지는 5MB 이하만 가능합니다.'];
    if (!extension_loaded('gd')) return ['ok' => false, 'msg' => '서버에 GD 확장이 없어 이미지 처리를 할 수 없습니다.'];

    $uploadDir = __DIR__ . '/../uploads/category-icons';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $filename = 'ci_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = $uploadDir . '/' . $filename;
    $tmpKeep  = $uploadDir . '/_tmp_' . bin2hex(random_bytes(6)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $tmpKeep)) return ['ok' => false, 'msg' => '이미지 저장에 실패했습니다.'];

    $resized = caticon_resize_rect($tmpKeep, $target, $ext, CATICON_TARGET_W, CATICON_TARGET_H);
    @unlink($tmpKeep);
    if (!$resized) return ['ok' => false, 'msg' => '이미지 리사이즈 처리 중 오류가 발생했습니다.'];

    return ['ok' => true, 'url' => BASE_URL . '/uploads/category-icons/' . $filename];
}

/* ---------- 등록/수정 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_icon') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect('/admin/category-icons.php'); }

    $iconId    = (int)($_POST['icon_id'] ?? 0);
    $label     = trim($_POST['label'] ?? '');
    $linkUrl   = trim($_POST['link_url'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($label === '') { flash('admin_error', '카테고리명을 입력해 주세요.'); redirect('/admin/category-icons.php'); }

    $uploadResult = caticon_handle_upload($_FILES['image'] ?? []);
    if (!$uploadResult['ok']) { flash('admin_error', $uploadResult['msg']); redirect('/admin/category-icons.php'); }

    try {
        if ($iconId > 0) {
            $existing = $pdo->prepare('SELECT icon_image_url FROM tt_category_icons WHERE id = :id');
            $existing->execute(['id' => $iconId]);
            $row = $existing->fetch();
            if (!$row) { flash('admin_error', '존재하지 않는 항목입니다.'); redirect('/admin/category-icons.php'); }

            $imgUrl = $row['icon_image_url'];
            if (!empty($uploadResult['url'])) $imgUrl = $uploadResult['url'];

            $pdo->prepare('UPDATE tt_category_icons SET label = :label, icon_image_url = :img, link_url = :link, sort_order = :sort WHERE id = :id')
                ->execute(['label' => $label, 'img' => $imgUrl, 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder, 'id' => $iconId]);

            flash('admin_success', '카테고리 아이콘이 수정되었습니다.');
        } else {
            if (empty($uploadResult['url'])) { flash('admin_error', '아이콘 이미지를 선택해 주세요.'); redirect('/admin/category-icons.php'); }

            $pdo->prepare('INSERT INTO tt_category_icons (label, icon_image_url, link_url, sort_order, is_active) VALUES (:label, :img, :link, :sort, 1)')
                ->execute(['label' => $label, 'img' => $uploadResult['url'], 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder]);

            flash('admin_success', "'{$label}' 카테고리가 등록되었습니다.");
        }
    } catch (Throwable $e) {
        error_log('[admin/category-icons save] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect('/admin/category-icons.php');
}

/* ---------- 노출/숨김 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect('/admin/category-icons.php'); }
    $iconId = (int)($_POST['icon_id'] ?? 0);
    $pdo->prepare('UPDATE tt_category_icons SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $iconId]);
    flash('admin_success', '노출 상태가 변경되었습니다.');
    redirect('/admin/category-icons.php');
}

/* ---------- 삭제 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'delete_icon') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect('/admin/category-icons.php'); }
    $iconId = (int)($_POST['icon_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_category_icons WHERE id = :id')->execute(['id' => $iconId]);
    flash('admin_success', '카테고리 아이콘이 삭제되었습니다.');
    redirect('/admin/category-icons.php');
}

$icons = $pdo->query('SELECT id, label, icon_image_url, link_url, sort_order, is_active FROM tt_category_icons ORDER BY sort_order ASC, id ASC')->fetchAll();

$pageTitle = '카테고리 아이콘 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-page-title" id="iconFormTitle">카테고리 아이콘 등록</h2>
  <p class="admin-mini-hint">메인 화면 배너 아래 노출되는 카테고리 아이콘 바입니다. 어떤 크기의 이미지를 올려도 직사각형(220x280, 모서리 둥근 형태)으로 자동 잘려서(cover) 통일된 규격으로 저장됩니다.</p>

  <form method="post" enctype="multipart/form-data" class="admin-form-grid" id="iconForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="save_icon">
    <input type="hidden" name="icon_id" value="0" id="iconIdInput">

    <div class="admin-form-row">
      <label>카테고리명 *</label>
      <input type="text" name="label" id="iconLabelInput" placeholder="예: 승용차 타이어">
    </div>
    <div class="admin-form-row">
      <label>클릭 시 이동할 링크</label>
      <input type="text" name="link_url" id="iconLinkInput" placeholder="예: /product-list.php?cat=passenger">
    </div>
    <div class="admin-form-row">
      <label>노출 순서</label>
      <input type="number" name="sort_order" id="iconSortInput" value="0" min="0">
    </div>
    <div class="admin-form-row admin-form-row-full">
      <label id="iconImageLabel">아이콘 이미지 *</label>
      <div id="iconCurrentPreview"></div>
      <input type="file" name="image" id="iconImageInput" accept=".jpg,.jpeg,.png,.webp,.gif">
      <p class="admin-form-hint">가로 220 x 세로 280 (세로가 조금 더 긴 직사각형)으로 자동 크롭됩니다. 세로형 이미지를 올리면 잘림이 최소화됩니다.</p>
    </div>

    <div class="admin-form-actions admin-form-row-full">
      <button type="button" class="btn-admin-secondary" id="iconFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="iconFormSubmitBtn">등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>카테고리 목록 <span class="admin-count-pill"><?= count($icons) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead>
      <tr><th style="width:90px">이미지</th><th>카테고리명</th><th>링크</th><th style="width:70px">순서</th><th style="width:100px">노출</th><th style="width:160px"></th></tr>
    </thead>
    <tbody>
    <?php if (empty($icons)): ?>
      <tr><td colspan="6" class="admin-empty-row">등록된 카테고리가 없습니다.</td></tr>
    <?php else: foreach ($icons as $ic): ?>
      <tr>
        <td><img src="<?= h($ic['icon_image_url']) ?>" style="width:44px;height:56px;border-radius:8px;object-fit:cover;"></td>
        <td><strong><?= h($ic['label']) ?></strong></td>
        <td class="admin-text-sub"><?= $ic['link_url'] ? h($ic['link_url']) : '-' ?></td>
        <td class="mono"><?= (int)$ic['sort_order'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="toggle_status">
            <input type="hidden" name="icon_id" value="<?= (int)$ic['id'] ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $ic['is_active'] ? 'done' : 'cancelled' ?>"><?= $ic['is_active'] ? '노출중' : '숨김' ?></button>
          </form>
        </td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-icon"
                  data-id="<?= (int)$ic['id'] ?>" data-label="<?= h($ic['label']) ?>"
                  data-link="<?= h($ic['link_url'] ?? '') ?>" data-sort="<?= (int)$ic['sort_order'] ?>"
                  data-image="<?= h($ic['icon_image_url']) ?>">수정</button>
          <form method="post" style="display:inline" onsubmit="return confirm('삭제하시겠습니까?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="delete_icon">
            <input type="hidden" name="icon_id" value="<?= (int)$ic['id'] ?>">
            <button type="submit" class="btn-admin-danger" style="padding:4px 10px;font-size:12px;">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('.btn-edit-icon').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('iconIdInput').value = this.dataset.id;
    document.getElementById('iconLabelInput').value = this.dataset.label;
    document.getElementById('iconLinkInput').value = this.dataset.link;
    document.getElementById('iconSortInput').value = this.dataset.sort;
    document.getElementById('iconImageLabel').textContent = '아이콘 이미지 (선택 — 비워두면 기존 이미지 유지)';
    document.getElementById('iconCurrentPreview').innerHTML = '<img src="' + this.dataset.image + '" style="width:88px;height:112px;border-radius:12px;object-fit:cover;display:block;margin-bottom:8px;">';
    document.getElementById('iconFormTitle').textContent = '카테고리 아이콘 수정';
    document.getElementById('iconFormSubmitBtn').textContent = '수정 저장';
    document.getElementById('iconFormCancelBtn').style.display = 'inline-block';
    document.getElementById('iconForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
document.getElementById('iconFormCancelBtn').addEventListener('click', function () {
  document.getElementById('iconIdInput').value = '0';
  document.getElementById('iconLabelInput').value = '';
  document.getElementById('iconLinkInput').value = '';
  document.getElementById('iconSortInput').value = '0';
  document.getElementById('iconImageInput').value = '';
  document.getElementById('iconImageLabel').textContent = '아이콘 이미지 *';
  document.getElementById('iconCurrentPreview').innerHTML = '';
  document.getElementById('iconFormTitle').textContent = '카테고리 아이콘 등록';
  document.getElementById('iconFormSubmitBtn').textContent = '등록';
  this.style.display = 'none';
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
