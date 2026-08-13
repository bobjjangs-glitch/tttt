<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();

$pdo = Database::connection();

// ★ 배너 표준 규격 (모든 배너는 이 크기로 자동 리사이즈됨)
const BANNER_TARGET_W = 1900;
const BANNER_TARGET_H = 600;

/**
 * 업로드된 원본 이미지를 1900x600 비율로 자동 크롭+리사이즈해서 저장한다.
 * (object-fit: cover 방식 — 비율 유지, 넘치는 부분은 중앙 기준으로 잘라냄)
 */
function admin_resize_banner_to_fixed(string $srcPath, string $destPath, string $ext): bool
{
    $ext = strtolower($ext);

    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $src = @imagecreatefromjpeg($srcPath);
            break;
        case 'png':
            $src = @imagecreatefrompng($srcPath);
            break;
        case 'gif':
            $src = @imagecreatefromgif($srcPath);
            break;
        case 'webp':
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false;
            break;
        default:
            $src = false;
    }
    if ($src === false) return false;

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $targetRatio = BANNER_TARGET_W / BANNER_TARGET_H; // 1900/600 ≈ 3.1667
    $srcRatio    = $srcW / $srcH;

    if ($srcRatio > $targetRatio) {
        // 원본이 목표보다 가로로 더 넓음 → 좌우를 잘라냄
        $cropH = $srcH;
        $cropW = (int)round($srcH * $targetRatio);
        $cropX = (int)round(($srcW - $cropW) / 2);
        $cropY = 0;
    } else {
        // 원본이 목표보다 세로로 더 김(또는 정사각형) → 위아래를 잘라냄
        $cropW = $srcW;
        $cropH = (int)round($srcW / $targetRatio);
        $cropX = 0;
        $cropY = (int)round(($srcH - $cropH) / 2);
    }

    $dest = imagecreatetruecolor(BANNER_TARGET_W, BANNER_TARGET_H);

    if ($ext === 'png') {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
    }

    imagecopyresampled(
        $dest, $src,
        0, 0,
        $cropX, $cropY,
        BANNER_TARGET_W, BANNER_TARGET_H,
        $cropW, $cropH
    );

    $ok = false;
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $ok = imagejpeg($dest, $destPath, 85);
            break;
        case 'png':
            $ok = imagepng($dest, $destPath, 6);
            break;
        case 'gif':
            $ok = imagegif($dest, $destPath);
            break;
        case 'webp':
            $ok = function_exists('imagewebp') ? imagewebp($dest, $destPath, 85) : false;
            break;
    }

    imagedestroy($src);
    imagedestroy($dest);

    return $ok;
}

function admin_handle_banner_upload(array $file): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'url' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => '이미지 업로드 중 오류가 발생했습니다. (code=' . $file['error'] . ')'];
    }
    if (@getimagesize($file['tmp_name']) === false) return ['ok' => false, 'msg' => '이미지 파일만 업로드할 수 있습니다.'];
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'msg' => '지원하지 않는 이미지 형식입니다. (jpg, png, webp, gif만 가능)'];
    if ($file['size'] > 5 * 1024 * 1024) return ['ok' => false, 'msg' => '배너 이미지는 5MB 이하만 가능합니다.'];

    if (!extension_loaded('gd')) {
        return ['ok' => false, 'msg' => '서버에 GD 확장이 설치되어 있지 않아 이미지 자동 리사이즈를 할 수 없습니다. 호스팅 관리자에게 문의해주세요.'];
    }

    $uploadDir = __DIR__ . '/../uploads/banners';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $filename = 'bn_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = $uploadDir . '/' . $filename;

    // 원본을 임시 파일로 저장 → 그걸 소스로 읽어 1900x600으로 리사이즈 후 최종 파일로 저장
    $tmpKeep = $uploadDir . '/_tmp_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $tmpKeep)) {
        return ['ok' => false, 'msg' => '이미지 저장에 실패했습니다.'];
    }

    $resized = admin_resize_banner_to_fixed($tmpKeep, $target, $ext);
    @unlink($tmpKeep);

    if (!$resized) {
        return ['ok' => false, 'msg' => '이미지 리사이즈 처리 중 오류가 발생했습니다. 다른 이미지로 시도해주세요.'];
    }

    return ['ok' => true, 'url' => BASE_URL . '/uploads/banners/' . $filename];
}

/* ---------- 배너 등록/수정 처리 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_banner') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/banners.php');
    }

    $bannerId  = (int)($_POST['banner_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $linkUrl   = trim($_POST['link_url'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($title === '') {
        flash('admin_error', '배너 제목을 입력해 주세요.');
        redirect('/admin/banners.php');
    }

    $uploadResult = admin_handle_banner_upload($_FILES['image'] ?? []);
    if (!$uploadResult['ok']) {
        flash('admin_error', $uploadResult['msg']);
        redirect('/admin/banners.php');
    }

    try {
        if ($bannerId > 0) {
            // 수정
            $existing = $pdo->prepare('SELECT image_url FROM tt_banners WHERE id = :id');
            $existing->execute(['id' => $bannerId]);
            $row = $existing->fetch();
            if (!$row) {
                flash('admin_error', '존재하지 않는 배너입니다.');
                redirect('/admin/banners.php');
            }

            $imageUrl = $row['image_url'];
            if (!empty($uploadResult['url'])) $imageUrl = $uploadResult['url'];

            $pdo->prepare('UPDATE tt_banners SET title = :title, image_url = :image, link_url = :link, sort_order = :sort WHERE id = :id')
                ->execute([
                    'title' => $title,
                    'image' => $imageUrl,
                    'link'  => $linkUrl !== '' ? $linkUrl : null,
                    'sort'  => $sortOrder,
                    'id'    => $bannerId,
                ]);

            AdminAuth::log((int)AdminAuth::currentAdminId(), 'banner_update', "배너#{$bannerId} 수정 ({$title})");
            flash('admin_success', '배너가 수정되었습니다.');
        } else {
            // 신규 등록 — 이미지는 필수
            if (empty($uploadResult['url'])) {
                flash('admin_error', '배너 이미지를 선택해 주세요.');
                redirect('/admin/banners.php');
            }

            $pdo->prepare('INSERT INTO tt_banners (title, image_url, link_url, sort_order, is_active) VALUES (:title, :image, :link, :sort, 1)')
                ->execute([
                    'title' => $title,
                    'image' => $uploadResult['url'],
                    'link'  => $linkUrl !== '' ? $linkUrl : null,
                    'sort'  => $sortOrder,
                ]);

            $newId = (int)$pdo->lastInsertId();
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'banner_create', "배너#{$newId} 등록 ({$title})");
            flash('admin_success', "'{$title}' 배너가 등록되었습니다.");
        }
    } catch (Throwable $e) {
        error_log('[admin/banners save] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }

    redirect('/admin/banners.php');
}

/* ---------- 노출/숨김 토글 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/banners.php');
    }
    $bannerId = (int)($_POST['banner_id'] ?? 0);
    $pdo->prepare('UPDATE tt_banners SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
        ->execute(['id' => $bannerId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'banner_toggle_status', "배너#{$bannerId} 상태 전환");
    flash('admin_success', '배너 노출 상태가 변경되었습니다.');
    redirect('/admin/banners.php');
}

/* ---------- 삭제 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'delete_banner') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/banners.php');
    }
    $bannerId = (int)($_POST['banner_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_banners WHERE id = :id')->execute(['id' => $bannerId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'banner_delete', "배너#{$bannerId} 삭제");
    flash('admin_success', '배너가 삭제되었습니다.');
    redirect('/admin/banners.php');
}

/* ---------- 목록 조회 (노출 순서대로) ---------- */
$banners = $pdo->query('
    SELECT id, title, image_url, link_url, sort_order, is_active, created_at
    FROM tt_banners
    ORDER BY sort_order ASC, id ASC
')->fetchAll();

$pageTitle = '메인 배너 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-page-title" id="bannerFormTitle">배너 등록</h2>
  <p class="admin-mini-hint">메인 화면 상단에 노출되는 자동슬라이드 배너입니다. 어떤 크기의 이미지를 올려도 서버에서 자동으로 <strong>1900x600</strong> 규격에 맞춰 중앙 기준으로 잘라 저장됩니다. 중요한 문구나 이미지는 가급적 화면 중앙에 배치해주세요.</p>

  <form method="post" enctype="multipart/form-data" class="admin-form-grid" id="bannerForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="save_banner">
    <input type="hidden" name="banner_id" value="0" id="bannerIdInput">

    <div class="admin-form-row">
      <label>배너 제목 *</label>
      <input type="text" name="title" id="bannerTitleInput" placeholder="예: 여름 타이어 할인 이벤트">
    </div>
    <div class="admin-form-row">
      <label>클릭 시 이동할 링크 (선택)</label>
      <input type="text" name="link_url" id="bannerLinkInput" placeholder="예: /product-list.php?cat=tire">
    </div>
    <div class="admin-form-row">
      <label>노출 순서</label>
      <input type="number" name="sort_order" id="bannerSortInput" value="0" min="0">
      <p class="admin-form-hint">숫자가 작을수록 먼저 노출됩니다.</p>
    </div>
    <div class="admin-form-row admin-form-row-full">
      <label id="bannerImageLabel">배너 이미지 *</label>
      <div id="bannerCurrentPreview"></div>
      <input type="file" name="image" id="bannerImageInput" accept=".jpg,.jpeg,.png,.webp,.gif">
      <p class="admin-form-hint">업로드 즉시 1900x600 크기로 자동 변환됩니다.</p>
    </div>

    <div class="admin-form-actions admin-form-row-full">
      <button type="button" class="btn-admin-secondary" id="bannerFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="bannerFormSubmitBtn">배너 등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>배너 목록 <span class="admin-count-pill"><?= count($banners) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead>
      <tr>
        <th style="width:120px">미리보기</th>
        <th>제목</th>
        <th>링크</th>
        <th style="width:80px">순서</th>
        <th style="width:100px">노출 상태</th>
        <th style="width:160px"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($banners)): ?>
      <tr><td colspan="6" class="admin-empty-row">🖼 등록된 배너가 없습니다.</td></tr>
    <?php else: foreach ($banners as $bn): ?>
      <tr>
        <td>
          <img src="<?= h($bn['image_url']) ?>" alt="" class="admin-thumb-img" style="width:100px;height:34px;object-fit:cover;">
        </td>
        <td><strong><?= h($bn['title']) ?></strong></td>
        <td class="admin-text-sub"><?= $bn['link_url'] ? h($bn['link_url']) : '-' ?></td>
        <td class="mono"><?= (int)$bn['sort_order'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="toggle_status">
            <input type="hidden" name="banner_id" value="<?= (int)$bn['id'] ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $bn['is_active'] ? 'done' : 'cancelled' ?>">
              <?= $bn['is_active'] ? '노출중' : '숨김' ?>
            </button>
          </form>
        </td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-banner"
                  data-id="<?= (int)$bn['id'] ?>"
                  data-title="<?= h($bn['title']) ?>"
                  data-link="<?= h($bn['link_url'] ?? '') ?>"
                  data-sort="<?= (int)$bn['sort_order'] ?>"
                  data-image="<?= h($bn['image_url']) ?>">수정</button>
          <form method="post" style="display:inline"
                onsubmit="return confirm('&quot;<?= h($bn['title']) ?>&quot; 배너를 삭제하시겠습니까?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="delete_banner">
            <input type="hidden" name="banner_id" value="<?= (int)$bn['id'] ?>">
            <button type="submit" class="btn-admin-danger" style="padding:4px 10px;font-size:12px;">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('.btn-edit-banner').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('bannerIdInput').value = this.dataset.id;
    document.getElementById('bannerTitleInput').value = this.dataset.title;
    document.getElementById('bannerLinkInput').value = this.dataset.link;
    document.getElementById('bannerSortInput').value = this.dataset.sort;
    document.getElementById('bannerImageLabel').textContent = '배너 이미지 (선택 — 비워두면 기존 이미지 유지)';
    document.getElementById('bannerCurrentPreview').innerHTML =
      '<img src="' + this.dataset.image + '" style="max-width:220px;display:block;margin-bottom:8px;border-radius:6px;">';
    document.getElementById('bannerFormTitle').textContent = '배너 수정';
    document.getElementById('bannerFormSubmitBtn').textContent = '배너 수정 저장';
    document.getElementById('bannerFormCancelBtn').style.display = 'inline-block';
    document.getElementById('bannerForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

document.getElementById('bannerFormCancelBtn').addEventListener('click', function () {
  document.getElementById('bannerIdInput').value = '0';
  document.getElementById('bannerTitleInput').value = '';
  document.getElementById('bannerLinkInput').value = '';
  document.getElementById('bannerSortInput').value = '0';
  document.getElementById('bannerImageInput').value = '';
  document.getElementById('bannerImageLabel').textContent = '배너 이미지 *';
  document.getElementById('bannerCurrentPreview').innerHTML = '';
  document.getElementById('bannerFormTitle').textContent = '배너 등록';
  document.getElementById('bannerFormSubmitBtn').textContent = '배너 등록';
  this.style.display = 'none';
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
