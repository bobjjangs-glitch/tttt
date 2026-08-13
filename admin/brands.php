<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('brands');

$pdo = Database::connection();

function admin_generate_brand_slug(PDO $pdo, string $name, int $excludeId = 0): string {
    $base = strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'brand-' . bin2hex(random_bytes(3));
    }
    $slug = $base;
    $i = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM tt_brands WHERE slug = :slug AND id != :ex LIMIT 1');
        $stmt->execute(['slug' => $slug, 'ex' => $excludeId]);
        if (!$stmt->fetch()) break;
        $slug = $base . '-' . (++$i);
    }
    return $slug;
}

function admin_handle_logo_upload(array $file): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'url' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => '이미지 업로드 중 오류가 발생했습니다. (code=' . $file['error'] . ')'];
    }
    if (@getimagesize($file['tmp_name']) === false) return ['ok' => false, 'msg' => '이미지 파일만 업로드할 수 있습니다.'];
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'msg' => '지원하지 않는 이미지 형식입니다. (jpg, png, webp만 가능)'];
    if ($file['size'] > 2 * 1024 * 1024) return ['ok' => false, 'msg' => '로고 이미지는 2MB 이하만 가능합니다.'];

    $uploadDir = __DIR__ . '/../uploads/brands';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    $filename = 'b_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) return ['ok' => false, 'msg' => '이미지 저장에 실패했습니다.'];
    return ['ok' => true, 'url' => BASE_URL . '/uploads/brands/' . $filename];
}

$errors = [];

if (is_post() && ($_POST['form_type'] ?? '') === 'save_brand') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/brands.php');
    }

    $brandId = (int)($_POST['brand_id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');

    if ($name === '') {
        flash('admin_error', '브랜드명을 입력해 주세요.');
        redirect('/admin/brands.php');
    }

    $dup = $pdo->prepare('SELECT id FROM tt_brands WHERE name = :name AND id != :ex LIMIT 1');
    $dup->execute(['name' => $name, 'ex' => $brandId]);
    if ($dup->fetch()) {
        flash('admin_error', '이미 등록된 브랜드명입니다. (' . $name . ')');
        redirect('/admin/brands.php');
    }

    $uploadResult = admin_handle_logo_upload($_FILES['logo'] ?? []);
    if (!$uploadResult['ok']) {
        flash('admin_error', $uploadResult['msg']);
        redirect('/admin/brands.php');
    }

    try {
        if ($brandId > 0) {
            $existing = $pdo->prepare('SELECT logo_url FROM tt_brands WHERE id = :id');
            $existing->execute(['id' => $brandId]);
            $row = $existing->fetch();
            if (!$row) {
                flash('admin_error', '존재하지 않는 브랜드입니다.');
                redirect('/admin/brands.php');
            }

            $logoUrl = $row['logo_url'];
            if (!empty($uploadResult['url'])) $logoUrl = $uploadResult['url'];
            elseif (isset($_POST['remove_logo'])) $logoUrl = null;

            $slug = admin_generate_brand_slug($pdo, $name, $brandId);

            $pdo->prepare('UPDATE tt_brands SET name = :name, slug = :slug, logo_url = :logo WHERE id = :id')
                ->execute(['name' => $name, 'slug' => $slug, 'logo' => $logoUrl, 'id' => $brandId]);

            AdminAuth::log((int)AdminAuth::currentAdminId(), 'brand_update', "브랜드#{$brandId} 수정 ({$name})");
            flash('admin_success', '브랜드 정보가 수정되었습니다.');
        } else {
            $slug = admin_generate_brand_slug($pdo, $name);

            $pdo->prepare('INSERT INTO tt_brands (name, slug, logo_url, is_active) VALUES (:name, :slug, :logo, 1)')
                ->execute(['name' => $name, 'slug' => $slug, 'logo' => $uploadResult['url']]);

            $newId = (int)$pdo->lastInsertId();
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'brand_create', "브랜드#{$newId} 등록 ({$name})");
            flash('admin_success', "'{$name}' 브랜드가 등록되었습니다.");
        }
    } catch (Throwable $e) {
        error_log('[admin/brands save] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }

    redirect('/admin/brands.php');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/brands.php');
    }
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $pdo->prepare('UPDATE tt_brands SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
        ->execute(['id' => $brandId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'brand_toggle_status', "브랜드#{$brandId} 상태 전환");
    flash('admin_success', '브랜드 노출 상태가 변경되었습니다.');
    redirect('/admin/brands.php');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_brand') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/brands.php');
    }
    $brandId = (int)($_POST['brand_id'] ?? 0);

    $chk = $pdo->prepare('SELECT COUNT(*) FROM tt_products WHERE brand_id = :id');
    $chk->execute(['id' => $brandId]);
    $productCount = (int)$chk->fetchColumn();

    if ($productCount > 0) {
        flash('admin_error', "이 브랜드로 등록된 상품이 {$productCount}건 있어 삭제할 수 없습니다. 노출을 끄려면 '숨김' 처리를 이용해 주세요.");
        redirect('/admin/brands.php');
    }

    $pdo->prepare('DELETE FROM tt_brands WHERE id = :id')->execute(['id' => $brandId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'brand_delete', "브랜드#{$brandId} 삭제");
    flash('admin_success', '브랜드가 삭제되었습니다.');
    redirect('/admin/brands.php');
}

$brands = $pdo->query('
    SELECT b.id, b.name, b.slug, b.logo_url, b.is_active,
           (SELECT COUNT(*) FROM tt_products p WHERE p.brand_id = b.id) AS product_count
    FROM tt_brands b
    ORDER BY b.name ASC
')->fetchAll();

$pageTitle = '제조사 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-page-title">제조사 등록</h2>
  <form method="post" enctype="multipart/form-data" class="admin-product-form" id="brandForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="save_brand">
    <input type="hidden" name="brand_id" value="0" id="brandIdInput">

    <div class="admin-form-row">
      <label>브랜드명 *</label>
      <input type="text" name="name" id="brandNameInput" placeholder="예: 콘티넨탈" required>
    </div>
    <div class="admin-form-row">
      <label>로고 이미지</label>
      <div id="brandLogoPreview" style="margin-bottom:8px;display:none;">
        <img id="brandLogoPreviewImg" src="" alt="로고 미리보기" style="max-height:50px;max-width:180px;border:1px solid #ddd;border-radius:8px;padding:4px;">
        <button type="button" class="admin-link-btn" id="brandLogoRemoveBtn" style="margin-left:8px;">로고 삭제</button>
      </div>
      <input type="file" name="logo" id="brandLogoInput" accept=".jpg,.jpeg,.png,.webp,.svg">
      <p class="admin-form-hint">메인 페이지 상품 카드 상단에 표시될 브랜드 로고입니다. (jpg, png, webp, svg / 2MB 이하)</p>
      <input type="hidden" name="remove_logo" id="brandLogoRemoveFlag" value="">
    </div>
    <div class="admin-form-actions">
      <button type="button" class="btn-admin-secondary" id="brandFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="brandFormSubmitBtn">브랜드 등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>제조사 목록 <span class="admin-count-pill"><?= count($brands) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead>
      <tr>
        <th style="width:64px">로고</th>
        <th>브랜드명</th>
        <th>슬러그</th>
        <th>등록 상품수</th>
        <th>노출 상태</th>
        <th style="width:160px"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($brands)): ?>
      <tr><td colspan="6" class="admin-empty-row">🏷 등록된 제조사가 없습니다.</td></tr>
    <?php else: foreach ($brands as $b): ?>
      <tr>
        <td>
          <?php if ($b['logo_url']): ?>
            <img src="<?= h($b['logo_url']) ?>" alt="" class="admin-thumb-img">
          <?php else: ?>
            <div class="admin-thumb-placeholder">🏷</div>
          <?php endif; ?>
        </td>
        <td><strong><?= h($b['name']) ?></strong></td>
        <td class="admin-text-sub mono"><?= h($b['slug']) ?></td>
        <td class="mono"><?= number_format((int)$b['product_count']) ?>개</td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="toggle_status">
            <input type="hidden" name="brand_id" value="<?= (int)$b['id'] ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $b['is_active'] ? 'done' : 'cancelled' ?>">
              <?= $b['is_active'] ? '노출중' : '숨김' ?>
            </button>
          </form>
        </td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-brand"
                  data-id="<?= (int)$b['id'] ?>"
                  data-name="<?= h($b['name']) ?>"
                  data-logo="<?= h($b['logo_url'] ?? '') ?>">수정</button>
          <form method="post" style="display:inline"
                onsubmit="return confirm('&quot;<?= h($b['name']) ?>&quot; 브랜드를 삭제하시겠습니까?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="delete_brand">
            <input type="hidden" name="brand_id" value="<?= (int)$b['id'] ?>">
            <button type="submit" class="btn-admin-danger" style="padding:4px 10px;font-size:12px;">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('.btn-edit-brand').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('brandIdInput').value = this.dataset.id;
    document.getElementById('brandNameInput').value = this.dataset.name;
    document.getElementById('brandFormSubmitBtn').textContent = '브랜드 수정 저장';
    document.getElementById('brandFormCancelBtn').style.display = 'inline-block';

    var logoUrl = this.dataset.logo || '';
    var previewWrap = document.getElementById('brandLogoPreview');
    var previewImg  = document.getElementById('brandLogoPreviewImg');
    if (logoUrl) {
      previewImg.src = logoUrl;
      previewWrap.style.display = 'block';
    } else {
      previewWrap.style.display = 'none';
    }
    document.getElementById('brandLogoRemoveFlag').value = '';

    document.getElementById('brandForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

document.getElementById('brandFormCancelBtn').addEventListener('click', function () {
  document.getElementById('brandIdInput').value = '0';
  document.getElementById('brandNameInput').value = '';
  document.getElementById('brandLogoInput').value = '';
  document.getElementById('brandFormSubmitBtn').textContent = '브랜드 등록';
  document.getElementById('brandLogoPreview').style.display = 'none';
  document.getElementById('brandLogoRemoveFlag').value = '';
  this.style.display = 'none';
});

document.getElementById('brandLogoRemoveBtn').addEventListener('click', function () {
  document.getElementById('brandLogoPreview').style.display = 'none';
  document.getElementById('brandLogoRemoveFlag').value = '1';
  document.getElementById('brandLogoInput').value = '';
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
