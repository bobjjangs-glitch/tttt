<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('banners');

$pdo = Database::connection();
ensure_settings_table();
ensure_promo_placement_column();
ensure_banner_size_columns();

function redirect_tab(string $tab): never {
    redirect('/admin/banners.php?tab=' . $tab);
}

/* =====================================================================
   [NEW] 업로드 이미지 최대 픽셀 해상도 — 세 업로드 함수(배너/아이콘/프로모모)
   가 공통으로 참조. 용량(byte) 제한과 별개로 초고해상도 이미지로 인한
   GD 메모리 고갈(DoS)을 막기 위한 방어선.
   ===================================================================== */
const MAX_UPLOAD_PIXEL_W = 6000;
const MAX_UPLOAD_PIXEL_H = 6000;

/* =====================================================================
   ① 메인 배너 — 카드 슬라이드형. 어드민이 가로/세로 픽셀을 직접 지정 (cover 크롭)
   ===================================================================== */
const BANNER_DEFAULT_W = 1200;
const BANNER_DEFAULT_H = 400;
const BANNER_MIN_W = 400;  const BANNER_MAX_W = 1600;
const BANNER_MIN_H = 200;  const BANNER_MAX_H = 700;

function admin_resize_banner_cover(string $srcPath, string $destPath, string $ext, int $targetW, int $targetH): bool
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
    $scale   = max($targetW / $srcW, $targetH / $srcH);
    $scaledW = (int)round($srcW * $scale);
    $scaledH = (int)round($srcH * $scale);
    $offsetX = (int)round(($targetW - $scaledW) / 2);
    $offsetY = (int)round(($targetH - $scaledH) / 2);

    $dest = imagecreatetruecolor($targetW, $targetH);

    /* [FIX-1] PNG/GIF/WebP는 알파 채널을 보존해야 투명 배경이 검은색으로
       깨지지 않는다. caticon_resize_rect() / promo_resize_cover()와 동일한 처리. */
    if (in_array($ext, ['png', 'gif', 'webp'], true)) {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);
    }

    imagecopyresampled($dest, $src, $offsetX, $offsetY, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

    /* [FIX-1] 무조건 imagejpeg()로 저장하던 것을 확장자별로 분기.
       파일명 확장자(.png 등)와 실제 저장 포맷이 항상 일치하도록 보장한다. */
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

function admin_handle_banner_upload(array $file, int $targetW, int $targetH): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'url' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => '이미지 업로드 중 오류가 발생했습니다. (code=' . $file['error'] . ')'];

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) return ['ok' => false, 'msg' => '이미지 파일만 업로드할 수 있습니다.'];

    /* [FIX-2] 초고해상도 이미지로 인한 GD 메모리 고갈(DoS) 방지 */
    if ($imageInfo[0] > MAX_UPLOAD_PIXEL_W || $imageInfo[1] > MAX_UPLOAD_PIXEL_H) {
        return ['ok' => false, 'msg' => "이미지 해상도가 너무 큽니다. (최대 " . MAX_UPLOAD_PIXEL_W . "×" . MAX_UPLOAD_PIXEL_H . "px, 업로드한 이미지는 {$imageInfo[0]}×{$imageInfo[1]}px)"];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'msg' => '지원하지 않는 이미지 형식입니다. (jpg, png, webp, gif만 가능)'];
    if ($file['size'] > 8 * 1024 * 1024) return ['ok' => false, 'msg' => '배너 이미지는 8MB 이하만 가능합니다.'];
    if (!extension_loaded('gd')) return ['ok' => false, 'msg' => '서버에 GD 확장이 없어 이미지 처리를 할 수 없습니다.'];

    $uploadDir = __DIR__ . '/../uploads/banners';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $filename = 'bn_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = $uploadDir . '/' . $filename;
    $tmpKeep  = $uploadDir . '/_tmp_' . bin2hex(random_bytes(6)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $tmpKeep)) return ['ok' => false, 'msg' => '이미지 저장에 실패했습니다.'];
    $resized = admin_resize_banner_cover($tmpKeep, $target, $ext, $targetW, $targetH);
    @unlink($tmpKeep);
    if (!$resized) return ['ok' => false, 'msg' => '이미지 리사이즈 처리 중 오류가 발생했습니다.'];

    return ['ok' => true, 'url' => BASE_URL . '/uploads/banners/' . $filename];
}

/* =====================================================================
   ② 카테고리 아이콘 — 220x280 (4:5) cover 크롭
   ===================================================================== */
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

    $srcW = imagesx($src); $srcH = imagesy($src);
    $scale   = max($targetW / $srcW, $targetH / $srcH);
    $scaledW = (int)round($srcW * $scale);
    $scaledH = (int)round($srcH * $scale);
    $offsetX = (int)round(($targetW - $scaledW) / 2);
    $offsetY = (int)round(($targetH - $scaledH) / 2);

    $dest = imagecreatetruecolor($targetW, $targetH);
    if (in_array($ext, ['png','gif','webp'], true)) {
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
    imagedestroy($src); imagedestroy($dest);
    return $ok;
}

function caticon_handle_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'url' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => '업로드 중 오류가 발생했습니다. (code=' . $file['error'] . ')'];

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) return ['ok' => false, 'msg' => '이미지 파일만 업로드할 수 있습니다.'];

    /* [FIX-2] 초고해상도 이미지 방어 (아이콘도 동일하게 적용) */
    if ($imageInfo[0] > MAX_UPLOAD_PIXEL_W || $imageInfo[1] > MAX_UPLOAD_PIXEL_H) {
        return ['ok' => false, 'msg' => "이미지 해상도가 너무 큽니다. (최대 " . MAX_UPLOAD_PIXEL_W . "×" . MAX_UPLOAD_PIXEL_H . "px)"];
    }

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

/* =====================================================================
   ③ 프로모 배너 — 그리드형(640x420) / BEST 상단 와이드형(1200x300) cover 크롭
   ===================================================================== */
const PROMO_TARGET_W = 640;
const PROMO_TARGET_H = 420;
const PROMO_BESTTOP_W = 1200;
const PROMO_BESTTOP_H = 300;

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

    $srcW = imagesx($src); $srcH = imagesy($src);
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
    imagedestroy($src); imagedestroy($dest);
    return $ok;
}

function promo_handle_upload(array $file, string $placement = 'grid'): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'url' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => '업로드 중 오류가 발생했습니다. (code=' . $file['error'] . ')'];

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) return ['ok' => false, 'msg' => '이미지 파일만 업로드할 수 있습니다.'];

    /* [FIX-2] 초고해상도 이미지 방어 (프로모 배너도 동일하게 적용) */
    if ($imageInfo[0] > MAX_UPLOAD_PIXEL_W || $imageInfo[1] > MAX_UPLOAD_PIXEL_H) {
        return ['ok' => false, 'msg' => "이미지 해상도가 너무 큽니다. (최대 " . MAX_UPLOAD_PIXEL_W . "×" . MAX_UPLOAD_PIXEL_H . "px)"];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'msg' => '지원하지 않는 이미지 형식입니다.'];
    if ($file['size'] > 5 * 1024 * 1024) return ['ok' => false, 'msg' => '이미지는 5MB 이하만 가능합니다.'];
    if (!extension_loaded('gd')) return ['ok' => false, 'msg' => '서버에 GD 확장이 없어 이미지 처리를 할 수 없습니다.'];

    $targetW = $placement === 'best_top' ? PROMO_BESTTOP_W : PROMO_TARGET_W;
    $targetH = $placement === 'best_top' ? PROMO_BESTTOP_H : PROMO_TARGET_H;

    $uploadDir = __DIR__ . '/../uploads/promo-banners';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $filename = 'pb_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = $uploadDir . '/' . $filename;
    $tmpKeep  = $uploadDir . '/_tmp_' . bin2hex(random_bytes(6)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $tmpKeep)) return ['ok' => false, 'msg' => '이미지 저장에 실패했습니다.'];
    $resized = promo_resize_cover($tmpKeep, $target, $ext, $targetW, $targetH);
    @unlink($tmpKeep);
    if (!$resized) return ['ok' => false, 'msg' => '이미지 리사이즈 처리 중 오류가 발생했습니다.'];

    return ['ok' => true, 'url' => BASE_URL . '/uploads/promo-banners/' . $filename];
}

/* =====================================================================
   POST 핸들러 — 배너
   ===================================================================== */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_banner') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('banner'); }

    $bannerId  = (int)($_POST['banner_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $linkUrl   = trim($_POST['link_url'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $targetW   = (int)($_POST['target_w'] ?? BANNER_DEFAULT_W);
    $targetH   = (int)($_POST['target_h'] ?? BANNER_DEFAULT_H);

    if ($title === '') { flash('admin_error', '배너 제목을 입력해 주세요.'); redirect_tab('banner'); }
    if ($targetW < BANNER_MIN_W || $targetW > BANNER_MAX_W || $targetH < BANNER_MIN_H || $targetH > BANNER_MAX_H) {
        flash('admin_error', "카드 크기는 가로 " . BANNER_MIN_W . "~" . BANNER_MAX_W . "px, 세로 " . BANNER_MIN_H . "~" . BANNER_MAX_H . "px 사이로 입력해 주세요.");
        redirect_tab('banner');
    }

    $uploadResult = admin_handle_banner_upload($_FILES['image'] ?? [], $targetW, $targetH);
    if (!$uploadResult['ok']) { flash('admin_error', $uploadResult['msg']); redirect_tab('banner'); }

    try {
        if ($bannerId > 0) {
            $existing = $pdo->prepare('SELECT image_url FROM tt_banners WHERE id = :id');
            $existing->execute(['id' => $bannerId]);
            $row = $existing->fetch();
            if (!$row) { flash('admin_error', '존재하지 않는 배너입니다.'); redirect_tab('banner'); }

            $imageUrl = $row['image_url'];
            if (!empty($uploadResult['url'])) $imageUrl = $uploadResult['url'];

            $pdo->prepare('UPDATE tt_banners SET title=:title, image_url=:image, link_url=:link, sort_order=:sort, target_w=:tw, target_h=:th WHERE id=:id')
                ->execute(['title' => $title, 'image' => $imageUrl, 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder, 'tw' => $targetW, 'th' => $targetH, 'id' => $bannerId]);

            AdminAuth::log((int)AdminAuth::currentAdminId(), 'banner_update', "배너#{$bannerId} 수정 ({$title}, {$targetW}x{$targetH})");
            flash('admin_success', '배너가 수정되었습니다.');
        } else {
            if (empty($uploadResult['url'])) { flash('admin_error', '배너 이미지를 선택해 주세요.'); redirect_tab('banner'); }

            $pdo->prepare('INSERT INTO tt_banners (title, image_url, link_url, sort_order, target_w, target_h, is_active) VALUES (:title, :image, :link, :sort, :tw, :th, 1)')
                ->execute(['title' => $title, 'image' => $uploadResult['url'], 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder, 'tw' => $targetW, 'th' => $targetH]);

            $newId = (int)$pdo->lastInsertId();
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'banner_create', "배너#{$newId} 등록 ({$title}, {$targetW}x{$targetH})");
            flash('admin_success', "'{$title}' 배너가 등록되었습니다.");
        }
    } catch (Throwable $e) {
        error_log('[admin/banners save_banner] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect_tab('banner');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_banner_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('banner'); }
    $bannerId = (int)($_POST['banner_id'] ?? 0);
    $pdo->prepare('UPDATE tt_banners SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $bannerId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'banner_toggle', "배너#{$bannerId} 노출상태 변경");
    redirect_tab('banner');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_banner') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('banner'); }
    $bannerId = (int)($_POST['banner_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_banners WHERE id = :id')->execute(['id' => $bannerId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'banner_delete', "배너#{$bannerId} 삭제");
    flash('admin_success', '배너가 삭제되었습니다.');
    redirect_tab('banner');
}

/* =====================================================================
   POST 핸들러 — 카테고리 아이콘
   ===================================================================== */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_caticon') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('icon'); }

    $iconId    = (int)($_POST['icon_id'] ?? 0);
    $label     = trim($_POST['label'] ?? '');
    $linkUrl   = trim($_POST['link_url'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($label === '') { flash('admin_error', '아이콘 이름을 입력해 주세요.'); redirect_tab('icon'); }

    $uploadResult = caticon_handle_upload($_FILES['image'] ?? []);
    if (!$uploadResult['ok']) { flash('admin_error', $uploadResult['msg']); redirect_tab('icon'); }

    try {
        if ($iconId > 0) {
            $existing = $pdo->prepare('SELECT icon_image_url FROM tt_category_icons WHERE id = :id');
            $existing->execute(['id' => $iconId]);
            $row = $existing->fetch();
            if (!$row) { flash('admin_error', '존재하지 않는 아이콘입니다.'); redirect_tab('icon'); }

            $imageUrl = $row['icon_image_url'];
            if (!empty($uploadResult['url'])) $imageUrl = $uploadResult['url'];

            $pdo->prepare('UPDATE tt_category_icons SET label=:label, icon_image_url=:image, link_url=:link, sort_order=:sort WHERE id=:id')
                ->execute(['label' => $label, 'image' => $imageUrl, 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder, 'id' => $iconId]);
            flash('admin_success', '카테고리 아이콘이 수정되었습니다.');
        } else {
            if (empty($uploadResult['url'])) { flash('admin_error', '아이콘 이미지를 선택해 주세요.'); redirect_tab('icon'); }
            $pdo->prepare('INSERT INTO tt_category_icons (label, icon_image_url, link_url, sort_order, is_active) VALUES (:label, :image, :link, :sort, 1)')
                ->execute(['label' => $label, 'image' => $uploadResult['url'], 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder]);
            flash('admin_success', "'{$label}' 아이콘이 등록되었습니다.");
        }
    } catch (Throwable $e) {
        error_log('[admin/banners save_caticon] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect_tab('icon');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_caticon_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('icon'); }
    $iconId = (int)($_POST['icon_id'] ?? 0);
    $pdo->prepare('UPDATE tt_category_icons SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $iconId]);
    redirect_tab('icon');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_caticon') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('icon'); }
    $iconId = (int)($_POST['icon_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_category_icons WHERE id = :id')->execute(['id' => $iconId]);
    flash('admin_success', '아이콘이 삭제되었습니다.');
    redirect_tab('icon');
}

/* =====================================================================
   POST 핸들러 — 프로모 배너 (그리드 / BEST 상단)
   ===================================================================== */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_promo') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('promo'); }

    $promoId    = (int)($_POST['promo_id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $ctaText    = trim($_POST['cta_text'] ?? '');
    $linkUrl    = trim($_POST['link_url'] ?? '');
    $sortOrder  = (int)($_POST['sort_order'] ?? 0);
    $placement  = ($_POST['placement'] ?? 'grid') === 'best_top' ? 'best_top' : 'grid';

    $uploadResult = promo_handle_upload($_FILES['image'] ?? [], $placement);
    if (!$uploadResult['ok']) { flash('admin_error', $uploadResult['msg']); redirect_tab('promo'); }

    try {
        if ($promoId > 0) {
            $existing = $pdo->prepare('SELECT image_url FROM tt_promo_banners WHERE id = :id');
            $existing->execute(['id' => $promoId]);
            $row = $existing->fetch();
            if (!$row) { flash('admin_error', '존재하지 않는 배너입니다.'); redirect_tab('promo'); }

            $imageUrl = $row['image_url'];
            if (!empty($uploadResult['url'])) $imageUrl = $uploadResult['url'];

            $pdo->prepare('UPDATE tt_promo_banners SET title=:title, description=:desc, cta_text=:cta, image_url=:image, link_url=:link, sort_order=:sort, placement=:placement WHERE id=:id')
                ->execute(['title' => $title, 'desc' => $desc, 'cta' => $ctaText, 'image' => $imageUrl, 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder, 'placement' => $placement, 'id' => $promoId]);
            flash('admin_success', '프로모션 배너가 수정되었습니다.');
        } else {
            if (empty($uploadResult['url'])) { flash('admin_error', '배너 이미지를 선택해 주세요.'); redirect_tab('promo'); }
            $pdo->prepare('INSERT INTO tt_promo_banners (title, description, cta_text, image_url, link_url, sort_order, placement, is_active) VALUES (:title, :desc, :cta, :image, :link, :sort, :placement, 1)')
                ->execute(['title' => $title, 'desc' => $desc, 'cta' => $ctaText, 'image' => $uploadResult['url'], 'link' => $linkUrl !== '' ? $linkUrl : null, 'sort' => $sortOrder, 'placement' => $placement]);
            flash('admin_success', '프로모션 배너가 등록되었습니다.');
        }
    } catch (Throwable $e) {
        error_log('[admin/banners save_promo] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect_tab('promo');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_promo_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('promo'); }
    $promoId = (int)($_POST['promo_id'] ?? 0);
    $pdo->prepare('UPDATE tt_promo_banners SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $promoId]);
    redirect_tab('promo');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_promo') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('promo'); }
    $promoId = (int)($_POST['promo_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_promo_banners WHERE id = :id')->execute(['id' => $promoId]);
    flash('admin_success', '프로모션 배너가 삭제되었습니다.');
    redirect_tab('promo');
}

/* =====================================================================
   POST 핸들러 — 섹션 제목 (BEST / NEW)
   ===================================================================== */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_section_titles') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('section'); }

    $bestTitle = trim($_POST['best_section_title'] ?? '');
    $bestSub   = trim($_POST['best_section_sub'] ?? '');
    $newTitle  = trim($_POST['new_section_title'] ?? '');
    $newSub    = trim($_POST['new_section_sub'] ?? '');

    if ($bestTitle === '' || $newTitle === '') { flash('admin_error', '섹션 제목은 비워둘 수 없습니다.'); redirect_tab('section'); }

    set_setting('best_section_title', $bestTitle);
    set_setting('best_section_sub', $bestSub);
    set_setting('new_section_title', $newTitle);
    set_setting('new_section_sub', $newSub);

    AdminAuth::log((int)AdminAuth::currentAdminId(), 'section_title_update', "BEST=[{$bestTitle}/{$bestSub}] NEW=[{$newTitle}/{$newSub}]");
    flash('admin_success', '섹션 제목이 저장되었습니다.');
    redirect_tab('section');
}

/* =====================================================================
   POST 핸들러 — BEST/NEW 상품명 빠른 수정
   ===================================================================== */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_product_name') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) { flash('admin_error', '잘못된 요청입니다.'); redirect_tab('product'); }

    $productId = (int)($_POST['product_id'] ?? 0);
    $newName   = trim($_POST['product_name'] ?? '');

    if ($productId <= 0 || $newName === '') { flash('admin_error', '상품명을 입력해 주세요.'); redirect_tab('product'); }

    $pdo->prepare('UPDATE tt_products SET name = :name WHERE id = :id')->execute(['name' => $newName, 'id' => $productId]);
    AdminAuth::log((int)AdminAuth::currentAdminId(), 'product_name_update', "상품#{$productId} 이름 변경 → {$newName}");
    flash('admin_success', '상품명이 수정되었습니다.');
    redirect_tab('product');
}

/* =====================================================================
   조회 (화면 표시용)
   ===================================================================== */
$banners       = $pdo->query('SELECT id, title, image_url, link_url, sort_order, target_w, target_h, is_active FROM tt_banners ORDER BY sort_order ASC, id ASC')->fetchAll();
$categoryIcons = $pdo->query('SELECT id, label, icon_image_url, link_url, sort_order, is_active FROM tt_category_icons ORDER BY sort_order ASC, id ASC')->fetchAll();
$promoBanners  = $pdo->query('SELECT id, title, description, cta_text, image_url, link_url, sort_order, placement, is_active FROM tt_promo_banners ORDER BY placement ASC, sort_order ASC, id ASC')->fetchAll();

$bestSectionTitle = get_setting('best_section_title', '가장 많이 팔린 타이어');
$bestSectionSub   = get_setting('best_section_sub', 'BEST');
$newSectionTitle  = get_setting('new_section_title', '신상품');
$newSectionSub    = get_setting('new_section_sub', 'NEW');

$bestProductsAdmin = $pdo->query("
    SELECT p.id, p.name, b.name AS brand_name
    FROM tt_products p JOIN tt_brands b ON b.id = p.brand_id
    WHERE p.status = 'active' ORDER BY p.sales_count DESC LIMIT 8
")->fetchAll();
$newProductsAdmin = $pdo->query("
    SELECT p.id, p.name, b.name AS brand_name
    FROM tt_products p JOIN tt_brands b ON b.id = p.brand_id
    WHERE p.status = 'active' ORDER BY p.created_at DESC LIMIT 8
")->fetchAll();

$currentTab = $_GET['tab'] ?? 'banner';
$pageTitle  = '홈 화면 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-tabs">
  <a href="?tab=banner" class="admin-tab <?= $currentTab==='banner'?'active':'' ?>">① 메인 배너</a>
  <a href="?tab=icon"   class="admin-tab <?= $currentTab==='icon'?'active':'' ?>">② 카테고리 아이콘</a>
  <a href="?tab=promo"  class="admin-tab <?= $currentTab==='promo'?'active':'' ?>">③ 프로모 배너</a>
  <a href="?tab=section" class="admin-tab <?= $currentTab==='section'?'active':'' ?>">④ 섹션 제목</a>
  <a href="?tab=product" class="admin-tab <?= $currentTab==='product'?'active':'' ?>">⑤ BEST/NEW 상품명</a>
</div>

<?php if ($currentTab === 'banner'): ?>
<!-- ============================== ① 메인 배너 탭 ============================== -->
<div class="admin-card" style="margin-bottom:20px">
  <h2 id="bannerFormTitle">배너 등록</h2>
  <form method="post" enctype="multipart/form-data" id="bannerForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="tab" value="banner">
    <input type="hidden" name="form_type" value="save_banner">
    <input type="hidden" name="banner_id" id="bannerIdInput" value="0">

    <div class="admin-form-grid">
      <div class="admin-form-row">
        <label>배너 제목 <span class="req">*</span></label>
        <input type="text" name="title" id="bannerTitleInput" required maxlength="80">
      </div>
      <div class="admin-form-row">
        <label>링크 URL</label>
        <input type="text" name="link_url" id="bannerLinkInput" placeholder="https://...">
      </div>
      <div class="admin-form-row">
        <label>노출 순서</label>
        <input type="number" name="sort_order" id="bannerSortInput" value="0">
      </div>

      <div class="admin-form-row">
        <label>카드 크기 프리셋</label>
        <select id="bannerSizePreset" onchange="applyBannerPreset(this.value)">
          <option value="1200x400" selected>와이드 카드 (1200 × 400, 추천)</option>
          <option value="1000x460">기본 카드 (1000 × 460)</option>
          <option value="900x600">스퀘어형 카드 (900 × 600)</option>
          <option value="custom">직접 입력</option>
        </select>
      </div>
      <div class="admin-form-row">
        <label>가로(px)</label>
        <input type="number" name="target_w" id="bannerTargetW" value="1200" min="<?= BANNER_MIN_W ?>" max="<?= BANNER_MAX_W ?>">
      </div>
      <div class="admin-form-row">
        <label>세로(px)</label>
        <input type="number" name="target_h" id="bannerTargetH" value="400" min="<?= BANNER_MIN_H ?>" max="<?= BANNER_MAX_H ?>">
      </div>

      <div class="admin-form-row admin-form-row-full">
        <p class="admin-form-hint">지정한 가로×세로 비율로 이미지가 꽉 차게(cover) 잘려서 저장됩니다. 화면 전체 폭이 아니라 콘텐츠 영역(최대 1240px) 안에서 카드형으로 넘어갑니다.</p>
      </div>

      <div class="admin-form-row admin-form-row-full" id="bannerCurrentPreview"></div>

      <div class="admin-form-row admin-form-row-full">
        <label id="bannerImageLabel">배너 이미지 <span class="req">*</span></label>
        <input type="file" name="image" accept="image/*">
      </div>
    </div>

    <div class="admin-form-actions">
      <button type="button" class="btn-admin-secondary" id="bannerFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="bannerFormSubmitBtn">배너 등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>등록된 배너 <span class="admin-count-pill"><?= count($banners) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead><tr><th style="width:120px">미리보기</th><th>제목</th><th>링크</th><th style="width:90px">사이즈</th><th style="width:70px">순서</th><th style="width:90px">노출</th><th style="width:150px"></th></tr></thead>
    <tbody>
    <?php if (empty($banners)): ?>
      <tr><td colspan="7" class="admin-empty-row">🖼 등록된 배너가 없습니다.</td></tr>
    <?php else: foreach ($banners as $bn): ?>
      <tr>
        <td><img src="<?= h($bn['image_url']) ?>" style="width:100px;height:32px;object-fit:cover;border-radius:6px;"></td>
        <td><span class="admin-prod-name"><?= h($bn['title']) ?></span></td>
        <td class="admin-text-sub"><?= $bn['link_url'] ? h($bn['link_url']) : '-' ?></td>
        <td class="mono"><?= (int)$bn['target_w'] ?>×<?= (int)$bn['target_h'] ?></td>
        <td class="mono"><?= (int)$bn['sort_order'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?><input type="hidden" name="tab" value="banner">
            <input type="hidden" name="form_type" value="toggle_banner_status">
            <input type="hidden" name="banner_id" value="<?= (int)$bn['id'] ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $bn['is_active']?'done':'cancelled' ?>"><?= $bn['is_active']?'노출중':'숨김' ?></button>
          </form>
        </td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-banner"
            data-id="<?= (int)$bn['id'] ?>" data-title="<?= h($bn['title']) ?>"
            data-link="<?= h($bn['link_url'] ?? '') ?>" data-sort="<?= (int)$bn['sort_order'] ?>"
            data-image="<?= h($bn['image_url']) ?>" data-tw="<?= (int)$bn['target_w'] ?>" data-th="<?= (int)$bn['target_h'] ?>">수정</button>
          <form method="post" style="display:inline" onsubmit="return confirm('삭제하시겠습니까?');">
            <?= Csrf::field() ?><input type="hidden" name="tab" value="banner">
            <input type="hidden" name="form_type" value="delete_banner">
            <input type="hidden" name="banner_id" value="<?= (int)$bn['id'] ?>">
            <button type="submit" class="btn-admin-danger">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($currentTab === 'icon'): ?>
<!-- ============================== ② 카테고리 아이콘 탭 ============================== -->
<div class="admin-card" style="margin-bottom:20px">
  <h2 id="caticonFormTitle">아이콘 등록</h2>
  <form method="post" enctype="multipart/form-data" id="caticonForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="tab" value="icon">
    <input type="hidden" name="form_type" value="save_caticon">
    <input type="hidden" name="icon_id" id="caticonIdInput" value="0">

    <div class="admin-form-grid">
      <div class="admin-form-row">
        <label>아이콘 이름 <span class="req">*</span></label>
        <input type="text" name="label" id="caticonLabelInput" required maxlength="40" placeholder="예: 당일장착">
      </div>
      <div class="admin-form-row">
        <label>링크 URL</label>
        <input type="text" name="link_url" id="caticonLinkInput" placeholder="https://... 또는 /category.php?type=...">
      </div>
      <div class="admin-form-row">
        <label>노출 순서</label>
        <input type="number" name="sort_order" id="caticonSortInput" value="0">
      </div>

      <div class="admin-form-row admin-form-row-full" id="caticonCurrentPreview"></div>

      <div class="admin-form-row admin-form-row-full">
        <label id="caticonImageLabel">아이콘 이미지 <span class="req">*</span> (220×280 비율로 자동 크롭)</label>
        <input type="file" name="image" accept="image/*">
      </div>
    </div>

    <div class="admin-form-actions">
      <button type="button" class="btn-admin-secondary" id="caticonFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="caticonFormSubmitBtn">아이콘 등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>등록된 카테고리 아이콘 <span class="admin-count-pill"><?= count($categoryIcons) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead><tr><th style="width:80px">이미지</th><th>이름</th><th>링크</th><th style="width:70px">순서</th><th style="width:90px">노출</th><th style="width:150px"></th></tr></thead>
    <tbody>
    <?php if (empty($categoryIcons)): ?>
      <tr><td colspan="6" class="admin-empty-row">등록된 아이콘이 없습니다.</td></tr>
    <?php else: foreach ($categoryIcons as $ci): ?>
      <tr>
        <td><img src="<?= h($ci['icon_image_url']) ?>" style="width:44px;height:56px;object-fit:cover;border-radius:6px;"></td>
        <td><span class="admin-prod-name"><?= h($ci['label']) ?></span></td>
        <td class="admin-text-sub"><?= $ci['link_url'] ? h($ci['link_url']) : '-' ?></td>
        <td class="mono"><?= (int)$ci['sort_order'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?><input type="hidden" name="tab" value="icon">
            <input type="hidden" name="form_type" value="toggle_caticon_status">
            <input type="hidden" name="icon_id" value="<?= (int)$ci['id'] ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $ci['is_active']?'done':'cancelled' ?>"><?= $ci['is_active']?'노출중':'숨김' ?></button>
          </form>
        </td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-caticon"
            data-id="<?= (int)$ci['id'] ?>" data-label="<?= h($ci['label']) ?>"
            data-link="<?= h($ci['link_url'] ?? '') ?>" data-sort="<?= (int)$ci['sort_order'] ?>"
            data-image="<?= h($ci['icon_image_url']) ?>">수정</button>
          <form method="post" style="display:inline" onsubmit="return confirm('삭제하시겠습니까?');">
            <?= Csrf::field() ?><input type="hidden" name="tab" value="icon">
            <input type="hidden" name="form_type" value="delete_caticon">
            <input type="hidden" name="icon_id" value="<?= (int)$ci['id'] ?>">
            <button type="submit" class="btn-admin-danger">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($currentTab === 'promo'): ?>
<!-- ============================== ③ 프로모 배너 탭 ============================== -->
<div class="admin-card" style="margin-bottom:20px">
  <h2 id="promoFormTitle">프로모 배너 등록</h2>
  <form method="post" enctype="multipart/form-data" id="promoForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="tab" value="promo">
    <input type="hidden" name="form_type" value="save_promo">
    <input type="hidden" name="promo_id" id="promoIdInput" value="0">

    <div class="admin-form-row admin-form-row-full">
      <label>배치 위치 <span class="req">*</span></label>
      <label class="admin-radio-inline"><input type="radio" name="placement" value="grid" id="promoPlacementGrid" checked onchange="updatePromoHint()"> 프로모션 그리드 (640×420)</label>
      <label class="admin-radio-inline"><input type="radio" name="placement" value="best_top" id="promoPlacementBestTop" onchange="updatePromoHint()"> BEST 섹션 상단 와이드 (1200×300)</label>
    </div>

    <div class="admin-form-grid">
      <div class="admin-form-row">
        <label>제목 (비워두면 이미지만 표시)</label>
        <input type="text" name="title" id="promoTitleInput" maxlength="60">
      </div>
      <div class="admin-form-row">
        <label>설명</label>
        <input type="text" name="description" id="promoDescInput" maxlength="120">
      </div>
      <div class="admin-form-row">
        <label>버튼 문구</label>
        <input type="text" name="cta_text" id="promoCtaInput" maxlength="20" placeholder="더보기">
      </div>
      <div class="admin-form-row">
        <label>링크 URL</label>
        <input type="text" name="link_url" id="promoLinkInput" placeholder="https://...">
      </div>
      <div class="admin-form-row">
        <label>노출 순서</label>
        <input type="number" name="sort_order" id="promoSortInput" value="0">
      </div>

      <div class="admin-form-row admin-form-row-full" id="promoCurrentPreview"></div>

      <div class="admin-form-row admin-form-row-full">
        <label id="promoImageLabel">배너 이미지 <span class="req">*</span></label>
        <input type="file" name="image" accept="image/*">
        <p class="admin-form-hint" id="promoImageHint">640×420 비율로 자동 크롭됩니다.</p>
      </div>
    </div>

    <div class="admin-form-actions">
      <button type="button" class="btn-admin-secondary" id="promoFormCancelBtn" style="display:none">취소</button>
      <button type="submit" class="btn-admin-primary" id="promoFormSubmitBtn">프로모션 배너 등록</button>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>등록된 프로모션 배너 <span class="admin-count-pill"><?= count($promoBanners) ?>개</span></h2>
  <table class="admin-table-trendy">
    <thead><tr><th style="width:100px">이미지</th><th>제목</th><th style="width:100px">배치</th><th style="width:70px">순서</th><th style="width:90px">노출</th><th style="width:150px"></th></tr></thead>
    <tbody>
    <?php if (empty($promoBanners)): ?>
      <tr><td colspan="6" class="admin-empty-row">등록된 프로모션 배너가 없습니다.</td></tr>
    <?php else: foreach ($promoBanners as $pb): ?>
      <tr>
        <td><img src="<?= h($pb['image_url']) ?>" style="width:90px;height:56px;object-fit:cover;border-radius:6px;"></td>
        <td><span class="admin-prod-name"><?= h($pb['title'] ?: '(제목 없음)') ?></span></td>
        <td><span class="admin-badge"><?= $pb['placement']==='best_top' ? 'BEST 상단' : '그리드' ?></span></td>
        <td class="mono"><?= (int)$pb['sort_order'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?><input type="hidden" name="tab" value="promo">
            <input type="hidden" name="form_type" value="toggle_promo_status">
            <input type="hidden" name="promo_id" value="<?= (int)$pb['id'] ?>">
            <button type="submit" class="status-toggle-btn status-badge status-<?= $pb['is_active']?'done':'cancelled' ?>"><?= $pb['is_active']?'노출중':'숨김' ?></button>
          </form>
        </td>
        <td>
          <button type="button" class="admin-link-btn btn-edit-promo"
            data-id="<?= (int)$pb['id'] ?>" data-title="<?= h($pb['title'] ?? '') ?>"
            data-desc="<?= h($pb['description'] ?? '') ?>" data-cta="<?= h($pb['cta_text'] ?? '') ?>"
            data-link="<?= h($pb['link_url'] ?? '') ?>" data-sort="<?= (int)$pb['sort_order'] ?>"
            data-placement="<?= h($pb['placement']) ?>" data-image="<?= h($pb['image_url']) ?>">수정</button>
          <form method="post" style="display:inline" onsubmit="return confirm('삭제하시겠습니까?');">
            <?= Csrf::field() ?><input type="hidden" name="tab" value="promo">
            <input type="hidden" name="form_type" value="delete_promo">
            <input type="hidden" name="promo_id" value="<?= (int)$pb['id'] ?>">
            <button type="submit" class="btn-admin-danger">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($currentTab === 'section'): ?>
<!-- ============================== ④ 섹션 제목 탭 ============================== -->
<div class="admin-card">
  <h2>홈 화면 섹션 제목 관리</h2>
  <p class="admin-form-hint" style="margin-bottom:16px">index.php의 "가장 많이 팔린 타이어(BEST)", "신상품(NEW)" 문구를 여기서 바꿀 수 있습니다.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="tab" value="section">
    <input type="hidden" name="form_type" value="save_section_titles">

    <div class="admin-form-grid">
      <div class="admin-form-row">
        <label>BEST 섹션 제목 <span class="req">*</span></label>
        <input type="text" name="best_section_title" value="<?= h($bestSectionTitle) ?>" required maxlength="60">
      </div>
      <div class="admin-form-row">
        <label>BEST 섹션 서브타이틀</label>
        <input type="text" name="best_section_sub" value="<?= h($bestSectionSub) ?>" maxlength="30">
      </div>
      <div class="admin-form-row admin-form-row-wide"></div>
      <div class="admin-form-row">
        <label>NEW 섹션 제목 <span class="req">*</span></label>
        <input type="text" name="new_section_title" value="<?= h($newSectionTitle) ?>" required maxlength="60">
      </div>
      <div class="admin-form-row">
        <label>NEW 섹션 서브타이틀</label>
        <input type="text" name="new_section_sub" value="<?= h($newSectionSub) ?>" maxlength="30">
      </div>
    </div>

    <div class="admin-form-actions">
      <button type="submit" class="btn-admin-primary">섹션 제목 저장</button>
    </div>
  </form>
</div>

<?php elseif ($currentTab === 'product'): ?>
<!-- ============================== ⑤ BEST/NEW 상품명 빠른 수정 탭 ============================== -->
<div class="admin-card" style="margin-bottom:20px">
  <h2>BEST 상품 <span class="admin-count-pill">판매량순 상품 8개</span></h2>
  <table class="admin-table-trendy">
    <thead><tr><th style="width:140px">브랜드</th><th>상품명</th><th style="width:100px"></th></tr></thead>
    <tbody>
    <?php foreach ($bestProductsAdmin as $p): ?>
      <tr>
        <td class="admin-text-sub"><?= h($p['brand_name']) ?></td>
        <td colspan="2">
          <form method="post" style="display:flex;gap:8px;align-items:center">
            <?= Csrf::field() ?><input type="hidden" name="tab" value="product">
            <input type="hidden" name="form_type" value="save_product_name">
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input type="text" name="product_name" value="<?= h($p['name']) ?>" style="flex:1;padding:9px 12px;border:1px solid var(--adm-border);border-radius:8px;font-size:14px" maxlength="150">
            <button type="submit" class="btn-admin-primary" style="padding:9px 18px">저장</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="admin-card">
  <h2>NEW 상품 <span class="admin-count-pill">최근 등록 상품 8개</span></h2>
  <table class="admin-table-trendy">
    <thead><tr><th style="width:140px">브랜드</th><th>상품명</th><th style="width:100px"></th></tr></thead>
    <tbody>
    <?php foreach ($newProductsAdmin as $p): ?>
      <tr>
        <td class="admin-text-sub"><?= h($p['brand_name']) ?></td>
        <td colspan="2">
          <form method="post" style="display:flex;gap:8px;align-items:center">
            <?= Csrf::field() ?><input type="hidden" name="tab" value="product">
            <input type="hidden" name="form_type" value="save_product_name">
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input type="text" name="product_name" value="<?= h($p['name']) ?>" style="flex:1;padding:9px 12px;border:1px solid var(--adm-border);border-radius:8px;font-size:14px" maxlength="150">
            <button type="submit" class="btn-admin-primary" style="padding:9px 18px">저장</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
/* ===== 배너 탭 ===== */
function applyBannerPreset(val) {
  if (val === 'custom') return;
  const [w, h] = val.split('x').map(Number);
  document.getElementById('bannerTargetW').value = w;
  document.getElementById('bannerTargetH').value = h;
}
document.querySelectorAll('.btn-edit-banner').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('bannerFormTitle').textContent = '배너 수정';
    document.getElementById('bannerIdInput').value = btn.dataset.id;
    document.getElementById('bannerTitleInput').value = btn.dataset.title;
    document.getElementById('bannerLinkInput').value = btn.dataset.link;
    document.getElementById('bannerSortInput').value = btn.dataset.sort;
    document.getElementById('bannerTargetW').value = btn.dataset.tw;
    document.getElementById('bannerTargetH').value = btn.dataset.th;
    document.getElementById('bannerSizePreset').value = 'custom';
    document.getElementById('bannerCurrentPreview').innerHTML =
      '<div class="admin-thumb-preview"><img src="' + btn.dataset.image + '" alt=""></div>';
    document.getElementById('bannerImageLabel').textContent = '배너 이미지 (변경 시에만 선택)';
    document.getElementById('bannerFormSubmitBtn').textContent = '배너 수정 저장';
    document.getElementById('bannerFormCancelBtn').style.display = 'inline-block';
    document.getElementById('bannerForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
document.getElementById('bannerFormCancelBtn')?.addEventListener('click', () => {
  document.getElementById('bannerFormTitle').textContent = '배너 등록';
  document.getElementById('bannerForm').reset();
  document.getElementById('bannerIdInput').value = '0';
  document.getElementById('bannerTargetW').value = 1200;
  document.getElementById('bannerTargetH').value = 400;
  document.getElementById('bannerSizePreset').value = '1200x400';
  document.getElementById('bannerCurrentPreview').innerHTML = '';
  document.getElementById('bannerImageLabel').textContent = '배너 이미지 *';
  document.getElementById('bannerFormSubmitBtn').textContent = '배너 등록';
  document.getElementById('bannerFormCancelBtn').style.display = 'none';
});

/* ===== 아이콘 탭 ===== */
document.querySelectorAll('.btn-edit-caticon').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('caticonFormTitle').textContent = '아이콘 수정';
    document.getElementById('caticonIdInput').value = btn.dataset.id;
    document.getElementById('caticonLabelInput').value = btn.dataset.label;
    document.getElementById('caticonLinkInput').value = btn.dataset.link;
    document.getElementById('caticonSortInput').value = btn.dataset.sort;
    document.getElementById('caticonCurrentPreview').innerHTML =
      '<div class="admin-thumb-preview"><img src="' + btn.dataset.image + '" alt=""></div>';
    document.getElementById('caticonImageLabel').textContent = '아이콘 이미지 (변경 시에만 선택)';
    document.getElementById('caticonFormSubmitBtn').textContent = '아이콘 수정 저장';
    document.getElementById('caticonFormCancelBtn').style.display = 'inline-block';
    document.getElementById('caticonForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
document.getElementById('caticonFormCancelBtn')?.addEventListener('click', () => {
  document.getElementById('caticonFormTitle').textContent = '아이콘 등록';
  document.getElementById('caticonForm').reset();
  document.getElementById('caticonIdInput').value = '0';
  document.getElementById('caticonCurrentPreview').innerHTML = '';
  document.getElementById('caticonImageLabel').textContent = '아이콘 이미지 * (220×280 비율로 자동 크롭)';
  document.getElementById('caticonFormSubmitBtn').textContent = '아이콘 등록';
  document.getElementById('caticonFormCancelBtn').style.display = 'none';
});

/* ===== 프로모 탭 ===== */
function updatePromoHint() {
  const isBestTop = document.getElementById('promoPlacementBestTop').checked;
  document.getElementById('promoImageHint').textContent = isBestTop
    ? '1200×300 비율로 자동 크롭됩니다. (BEST 섹션 상단 와이드 배너)'
    : '640×420 비율로 자동 크롭됩니다. (프로모션 그리드)';
}
document.querySelectorAll('.btn-edit-promo').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('promoFormTitle').textContent = '프로모션 배너 수정';
    document.getElementById('promoIdInput').value = btn.dataset.id;
    document.getElementById('promoTitleInput').value = btn.dataset.title;
    document.getElementById('promoDescInput').value = btn.dataset.desc;
    document.getElementById('promoCtaInput').value = btn.dataset.cta;
    document.getElementById('promoLinkInput').value = btn.dataset.link;
    document.getElementById('promoSortInput').value = btn.dataset.sort;
    document.getElementById(btn.dataset.placement === 'best_top' ? 'promoPlacementBestTop' : 'promoPlacementGrid').checked = true;
    updatePromoHint();
    document.getElementById('promoCurrentPreview').innerHTML =
      '<div class="admin-thumb-preview"><img src="' + btn.dataset.image + '" alt=""></div>';
    document.getElementById('promoImageLabel').textContent = '배너 이미지 (변경 시에만 선택)';
    document.getElementById('promoFormSubmitBtn').textContent = '프로모션 배너 수정 저장';
    document.getElementById('promoFormCancelBtn').style.display = 'inline-block';
    document.getElementById('promoForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
document.getElementById('promoFormCancelBtn')?.addEventListener('click', () => {
  document.getElementById('promoFormTitle').textContent = '프로모션 배너 등록';
  document.getElementById('promoForm').reset();
  document.getElementById('promoIdInput').value = '0';
  document.getElementById('promoPlacementGrid').checked = true;
  updatePromoHint();
  document.getElementById('promoCurrentPreview').innerHTML = '';
  document.getElementById('promoImageLabel').textContent = '배너 이미지 *';
  document.getElementById('promoFormSubmitBtn').textContent = '프로모션 배너 등록';
  document.getElementById('promoFormCancelBtn').style.display = 'none';
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
