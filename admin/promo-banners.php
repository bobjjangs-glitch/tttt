<?php
// admin/promo-banners.php — 프로모 배너 관리는 배너 통합 관리 화면으로 이전되었습니다.
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();
redirect('/admin/banners.php?tab=promo');


$pdo = Database::connection();

const PROMO_TARGET_W = 640;
const PROMO_TARGET_H = 420;

function promo_resize_cover(string $srcPath, string $destPath, string $ext, int $targetW, int $targetH): bool
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

    // ★ cover 방식: 카드 전체를 이미지로 꽉 채우고, 넘치는 부분은 중앙 기준으로 잘라냄
    $scale   = max($targetW / $srcW, $targetH / $srcH);
    $scaledW = (int)round($srcW * $scale);
    $scaledH = (int)round($srcH * $scale);
    $offsetX = (int)round(($targetW - $scaledW) / 2);
    $offsetY = (int)round(($targetH - $scaledH) / 2);

    $dest = imagecreatetruecolor($targetW, $targetH);
    imagecopyresampled($dest, $src, $offsetX, $offsetY, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

    $ok = false;
    switch ($ext) {
        case 'jpg': case 'jpeg': $ok = imagejpeg($dest, $destPath, 88); break;
        case 'png': $ok = imagepng($dest, $destPath, 6); break;
        case 'gif': $ok = imagegif($dest, $destPath); break;
        case 'webp': $ok = function_exists('imagewebp') ? imagewebp($dest, $destPath, 88) : false; break;
    }

    imagedestroy($src);
    imagedestroy($dest);
    return $ok;
}

function promo_handle_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'url' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => '업로드 중 오류가 발생했습니다. (code=' . $file['error'] . ')'];
    if (@getimagesize($file['tmp_name']) === false) return ['ok' => false, 'msg' => '이미지 파일만 업로드할 수 있습니다.'];

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'msg' => '지원하지 않는 이미지 형식입니다.'];
    if ($file['size'] > 5 * 1024 * 1024) return ['ok' => false, 'msg' => '이미지는 5MB 이하만 가능합니다.'];
    if (!extension_loaded('gd')) return ['ok' => false, 'msg' => '서버에 GD 확장이 없어 이미지 처리를 할 수 없습니다.'];

    $uploadDir = __DIR__ . '/../uploads/promo-banners';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $filename = 'pb_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = $uploadDir . '/' . $filename;
    $tmpKeep  = $uploadDir . '/_tmp_' . bin2hex(random_bytes(6)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $tmpKeep)) return ['ok' => false, 'msg' => '이미지 저장에 실패했습니다.'];

    $resized = promo_resize_cover($tmpKeep, $target, $ext, PROMO_TARGET_W, PROMO_TARGET_H);
    @unlink($tmpKeep);
    if (!$resized) return ['ok' => false, 'msg' => '이미지 리사이즈 처리 중 오류가 발생했습니다.'];

    return ['ok' => true, 'url' => BASE_URL . '/uploads/promo-banners/' . $filename];
}

/* ---------- 등록/수정 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_promo') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect('/admin/promo-banners.php'); }

    $promoId   = (int)($_POST['promo_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $ctaText   = trim($_POST['cta_text'] ?? '') ?: '바로가기';
    $linkUrl   = trim($_POST['link_url'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($title === '') { flash('admin_error', '제목을 입력해 주세요.'); redirect('/admin/promo-banners.php'); }

    $uploadResult = promo_handle_upload($_FILES['image'] ?? []);
    if (!$uploadResult['ok']) { flash('admin_error', $uploadResult['msg']); redirect('/admin/promo-banners.php'); }

    try {
        if ($promoId > 0) {
            $existing = $pdo->prepare('SELECT image_url FROM tt_promo_banners WHERE id = :id');
            $existing->execute(['id' => $promoId]);
            $row = $existing->fetch();
            if (!$row) { flash('admin_error', '존재하지 않는 항목입니다.'); redirect('/admin/promo-banners.php'); }

            $imgUrl = $row['image_url'];
            if (!empty($uploadResult['url'])) $imgUrl = $uploadResult['url'];

            $pdo->prepare('UPDATE tt_promo_banners SET title=:title, description=:desc, cta_text=:cta, image_url=:img, link_url=:link, sort_order=:sort WHERE id=:id')
                ->execute(['title' => $title, 'desc' => $desc, 'cta' => $ctaText, 'img' => $imgUrl, 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder, 'id' => $promoId]);

            flash('admin_success', '프로모 배너가 수정되었습니다.');
        } else {
            if (empty($uploadResult['url'])) { flash('admin_error', '배너 이미지를 선택해 주세요.'); redirect('/admin/promo-banners.php'); }

            $pdo->prepare('INSERT INTO tt_promo_banners (title, description, cta_text, image_url, link_url, sort_order, is_active) VALUES (:title, :desc, :cta, :img, :link, :sort, 1)')
                ->execute(['title' => $title, 'desc' => $desc, 'cta' => $ctaText, 'img' => $uploadResult['url'], 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder]);

            flash('admin_success', "'{$title}' 프로모 배너가 등록되었습니다.");
        }
    } catch (Throwable $e) {
        error_log('[admin/promo-banners save] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect('/admin/promo-banners.php');
}

/* ---------- 노출/숨김 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect('/admin/promo-banners.php'); }
    $promoId = (int)($_POST['promo_id'] ?? 0);
    $pdo->prepare('UPDATE tt_promo_banners SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $promoId]);
    flash('admin_success', '노출 상태가 변경되었습니다.');
    redirect('/admin/promo-banners.php');
}

/* ---------- 삭제 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'delete_promo') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect('/admin/promo-banners.php'); }
    $promoId = (int)($_POST['promo_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_promo_banners WHERE id = :id')->execute(['id' => $promoId]);
    flash('admin_success', '프로모 배너가 삭제되었습니다.');
    redirect('/admin/promo-banners.php');
}

$promos = $pdo->query('SELECT id, title, description, cta_text, image_url, link_url, sort_order, is_active FROM tt_promo_banners ORDER BY sort_order ASC, id ASC')->fetchAll();

$pageTitle = '프로모 배너 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-page-title" id="promoFormTitle">프로모 배너 등록</h2>
  <p class="admin-mini-hint">메인 화면 카테고리 아이콘 아래 4단으로 노출되는 컬러/이미지 배너입니다. 이미지 업로드 시 640x420 규격으로 자동 크롭(cover)됩니다.</p>

  <form method="post" enctype="multipart/form-data" class="admin-form-grid" id="promoForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="save_promo">
    <input type="hidden" name="promo_id" value="0" id="promoIdInput">

    <div class="admin-form-row">
      <label>제목 *</label>
      <input type="text" name="title" id="promoTitleInput" placeholder="예: 겨울용 타이어">
    </div>
    <div class="admin-form-row">
      <label>설명 문구</label>
      <input type="text" name="description" id="promoDescInput" placeholder="예: 눈길·빙판 대비 지금 준비하세요">
    </div>
    <div class="admin-form-row">
      <label>버튼 문구</label>
      <input type="text" name="cta_text" id="promoCtaInput" placeholder="바로가기" value="바로가기">
    </div>
    <div class="admin-form-row">
      <label>클릭 시 이동할 링크</label>
      <input type="text" name="link_url" id="promoLinkInput" placeholder="예: /product-list.php?cat=winter">
    </div>
    <div class="admin-form-row">
      <label>노출 순서</label>
      <input type="number" name="sort_order" id="promoSortInput" value="0" min="0">
    </div>
    <div class="admin-form-row admin-form-row-full">
      <label id="promoImageLabel">배너 이미지 *</label>
      <div id="promoCurrentPreview"></div>
      <input type="file" name="image" id="promoImageInput" accept=".jpg,.jpeg,.png,.webp,.gif">
      <p class="admin-form-hint">640x420 비율(가로형)에 가까운 이미지를 올리면 잘림이 최소화됩니다.</p>
    </div>

    <div class="admin-form-actions admin-form-row-full">
      <button type="button" class="btn-admin-secondary" id="promoFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="promoFormSubmitBtn">등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>프로모 배너 목록 <span class="admin-count-pill"><?= count($promos) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead>
      <tr><th style="width:120px">미리보기</th><th>제목</th><th>설명</th><th style="width:70px">순서</th><th style="width:100px">노출</th><th style="width:160px"></th></tr>
    </thead>
    <tbody>
    <?php if (empty($promos)): ?>
      <tr><td colspan="6" class="admin-empty-row">등록된 프로모 배너가 없습니다.</td></tr>
    <?php else: foreach ($promos as $pm): ?>
      <tr>
        <td><img src="<?= h($pm['image_url']) ?>" style="width:100px;height:66px;object-fit:cover;border-radius:8px;"></td>
        <td><strong><?= h($pm['title']) ?></strong></td>
        <td class="admin-text-sub"><?= h($pm['description']) ?></td>
        <td class="mono"><?= (int)$pm['sort_order'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="toggle_status">
            <input type="hidden" name="promo_id" value="<?= (int)$pm['id'] ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $pm['is_active'] ? 'done' : 'cancelled' ?>"><?= $pm['is_active'] ? '노출중' : '숨김' ?></button>
          </form>
        </td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-promo"
                  data-id="<?= (int)$pm['id'] ?>" data-title="<?= h($pm['title']) ?>"
                  data-desc="<?= h($pm['description'] ?? '') ?>" data-cta="<?= h($pm['cta_text']) ?>"
                  data-link="<?= h($pm['link_url'] ?? '') ?>" data-sort="<?= (int)$pm['sort_order'] ?>"
                  data-image="<?= h($pm['image_url']) ?>">수정</button>
          <form method="post" style="display:inline" onsubmit="return confirm('삭제하시겠습니까?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="delete_promo">
            <input type="hidden" name="promo_id" value="<?= (int)$pm['id'] ?>">
            <button type="submit" class="btn-admin-danger" style="padding:4px 10px;font-size:12px;">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('.btn-edit-promo').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('promoIdInput').value = this.dataset.id;
    document.getElementById('promoTitleInput').value = this.dataset.title;
    document.getElementById('promoDescInput').value = this.dataset.desc;
    document.getElementById('promoCtaInput').value = this.dataset.cta;
    document.getElementById('promoLinkInput').value = this.dataset.link;
    document.getElementById('promoSortInput').value = this.dataset.sort;
    document.getElementById('promoImageLabel').textContent = '배너 이미지 (선택 — 비워두면 기존 이미지 유지)';
    document.getElementById('promoCurrentPreview').innerHTML = '<img src="' + this.dataset.image + '" style="max-width:220px;display:block;margin-bottom:8px;border-radius:8px;">';
    document.getElementById('promoFormTitle').textContent = '프로모 배너 수정';
    document.getElementById('promoFormSubmitBtn').textContent = '수정 저장';
    document.getElementById('promoFormCancelBtn').style.display = 'inline-block';
    document.getElementById('promoForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
document.getElementById('promoFormCancelBtn').addEventListener('click', function () {
  document.getElementById('promoIdInput').value = '0';
  document.getElementById('promoTitleInput').value = '';
  document.getElementById('promoDescInput').value = '';
  document.getElementById('promoCtaInput').value = '바로가기';
  document.getElementById('promoLinkInput').value = '';
  document.getElementById('promoSortInput').value = '0';
  document.getElementById('promoImageInput').value = '';
  document.getElementById('promoImageLabel').textContent = '배너 이미지 *';
  document.getElementById('promoCurrentPreview').innerHTML = '';
  document.getElementById('promoFormTitle').textContent = '프로모 배너 등록';
  document.getElementById('promoFormSubmitBtn').textContent = '등록';
  this.style.display = 'none';
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
