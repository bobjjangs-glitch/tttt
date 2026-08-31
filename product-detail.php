<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_review_extra_columns();

if (!defined('REVIEW_WRITE_WINDOW_DAYS')) {
    define('REVIEW_WRITE_WINDOW_DAYS', 7);
}

$pdo = Database::connection();

function pd_sanitize_style_attr(string $style): string
{
    $allowedProps = ['color', 'background-color', 'font-size', 'font-family', 'font-weight', 'font-style', 'text-decoration', 'text-align'];
    $out = [];
    foreach (explode(';', $style) as $decl) {
        $decl = trim($decl);
        if ($decl === '') continue;
        $parts = explode(':', $decl, 2);
        if (count($parts) !== 2) continue;
        $prop = strtolower(trim($parts[0]));
        $val  = trim($parts[1]);
        if (!in_array($prop, $allowedProps, true)) continue;
        if (preg_match('/(javascript:|expression\(|url\(|@import)/i', $val)) continue;
        $out[] = $prop . ':' . $val;
    }
    return implode(';', $out);
}

function pd_sanitize_description_html(string $html): string
{
    if ($html === '') return '';
    $allowedTags = '<b><strong><i><em><u><s><span><div><p><br><ul><ol><li><a><font><h3><h4><blockquote>';
    $clean = strip_tags($html, $allowedTags);

    $prevErr = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . '<div id="__root__">' . $clean . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($prevErr);

    $xpath = new DOMXPath($dom);
    $rootList = $xpath->query('//div[@id="__root__"]');
    if ($rootList === false || $rootList->length === 0) return '';
    $root = $rootList->item(0);

    $walk = function (DOMNode $node) use (&$walk): void {
        if ($node->nodeType === XML_ELEMENT_NODE && $node instanceof DOMElement) {
            $tag = strtolower($node->nodeName);
            $toRemove = [];
            foreach ($node->attributes as $attr) {
                $attrName = strtolower($attr->name);
                if ($attrName === 'style') {
                    $node->setAttribute('style', pd_sanitize_style_attr($attr->value));
                } elseif ($tag === 'a' && $attrName === 'href') {
                    if (preg_match('/^\s*(javascript:|data:)/i', $attr->value)) $toRemove[] = 'href';
                } elseif ($tag === 'a' && $attrName === 'target') {
                    if ($attr->value !== '_blank') $toRemove[] = 'target';
                } elseif ($tag === 'font' && in_array($attrName, ['color', 'face', 'size'], true)) {
                    /* 허용 */
                } else {
                    $toRemove[] = $attr->name;
                }
            }
            foreach ($toRemove as $a) $node->removeAttribute($a);
        }
        foreach (iterator_to_array($node->childNodes) as $child) {
            $walk($child);
        }
    };
    $walk($root);

    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $dom->saveHTML($child);
    }
    return trim($result);
}

function pd_render_description(string $desc): string
{
    if ($desc === '') return '';
    if (strip_tags($desc) === $desc) {
        return nl2br(h($desc), false);
    }
    return pd_sanitize_description_html($desc);
}

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($productId <= 0) {
    http_response_code(404);
    echo '잘못된 상품 요청입니다.';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.model, p.spec, p.origin, p.thumbnail_url,
            p.price_original, p.price_sale, p.stock, p.status,
            p.rating_avg, p.review_count, p.description, p.dot_code,
            p.pattern_code, p.load_speed_rating, p.width_mm, p.aspect_ratio,
            p.rim_diameter, p.pattern_name, p.oem, p.tech, p.runflat,
            p.car_type, p.grade, p.feature_tags,
            b.name AS brand_name
     FROM tt_products p
     LEFT JOIN tt_brands b ON b.id = p.brand_id
     WHERE p.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product || $product['status'] !== 'active') {
    http_response_code(404);
    echo '판매중지되었거나 존재하지 않는 상품입니다.';
    exit;
}

$options = [];
try {
    $optStmt = $pdo->prepare(
        "SELECT id, dot_code, price_sale, stock_qty
         FROM tt_product_options
         WHERE product_id = :pid AND is_active = 1
         ORDER BY dot_code"
    );
    $optStmt->execute(['pid' => $productId]);
    $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[product-detail options] ' . $e->getMessage());
    $options = [];
}

$mainImages = [];
try {
    $miStmt = $pdo->prepare(
        'SELECT image_url FROM tt_product_main_images
         WHERE product_id = :pid ORDER BY sort_order ASC, id ASC'
    );
    $miStmt->execute(['pid' => $productId]);
    $mainImages = $miStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('[product-detail main images] ' . $e->getMessage());
    $mainImages = [];
}
if (empty($mainImages) && !empty($product['thumbnail_url'])) {
    $mainImages = [$product['thumbnail_url']];
}

$detailImages = [];
try {
    $diStmt = $pdo->prepare(
        'SELECT image_url FROM tt_product_detail_images
         WHERE product_id = :pid ORDER BY sort_order ASC, id ASC'
    );
    $diStmt->execute(['pid' => $productId]);
    $detailImages = $diStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('[product-detail images] ' . $e->getMessage());
    $detailImages = [];
}

$isWished = false;
if (Auth::isLoggedIn()) {
    $wStmt = $pdo->prepare("SELECT id FROM tt_wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1");
    $wStmt->execute([':uid' => Auth::currentUserId(), ':pid' => $productId]);
    $isWished = (bool)$wStmt->fetch();
}

$csrfToken = Csrf::token();
$autoOpenReview = (($_GET['write_review'] ?? '') === '1');

$flashMsg = null;
if (!empty($_SESSION['flash'])) {
    $flashMsg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$discountPct = 0;
if ((int)$product['price_original'] > 0) {
    $discountPct = (int)round((1 - ((int)$product['price_sale'] / (int)$product['price_original'])) * 100);
}

$sizeLabel = '';
if (!empty($product['width_mm']) && !empty($product['aspect_ratio']) && !empty($product['rim_diameter'])) {
    $sizeLabel = $product['width_mm'] . '/' . $product['aspect_ratio'] . 'R' . $product['rim_diameter'];
} elseif (!empty($product['spec'])) {
    $sizeLabel = $product['spec'];
}

$subtitle = '';
if (!empty($product['description'])) {
    $firstLine = trim(strtok((string)$product['description'], "\n"));
    if ($firstLine !== '') {
        $firstLinePlain = trim(strip_tags($firstLine));
        if ($firstLinePlain !== '') {
            $subtitle = mb_strlen($firstLinePlain) > 40 ? mb_substr($firstLinePlain, 0, 40) . '…' : $firstLinePlain;
        }
    }
}

$badgeCarType = trim((string)($product['car_type'] ?? ''));
$badgeGrade   = trim((string)($product['grade'] ?? ''));
$featureTags  = array_values(array_filter(array_map('trim', explode(',', (string)($product['feature_tags'] ?? '')))));

$specRows = [];
if (!empty($product['brand_name']))       $specRows[] = ['제조사', $product['brand_name']];
if ($sizeLabel !== '')                     $specRows[] = ['크기', $sizeLabel];
if (!empty($product['pattern_name']))     $specRows[] = ['패턴명', $product['pattern_name']];
if (!empty($product['pattern_code']))     $specRows[] = ['패턴코드', $product['pattern_code']];
if (!empty($product['load_speed_rating']))$specRows[] = ['하중&속도규격', $product['load_speed_rating']];
if (!empty($product['origin']))           $specRows[] = ['원산지', $product['origin']];
if (!empty($product['oem']))              $specRows[] = ['OEM 인증', $product['oem']];
if (!empty($product['tech']))             $specRows[] = ['Tech.', $product['tech']];
$specRows[]                                = ['런플랫(Runflat)', ($product['runflat'] ?? 'N') === 'Y' ? 'Y (런플랫 타이어)' : 'N (일반 타이어)'];
if (!empty($product['dot_code']))         $specRows[] = ['대표 DOT / 생산연월', $product['dot_code']];

/* [NEW] 리뷰 작성 모달용 선택 가능 태그 목록 (어드민 관리) */
$reviewOptionTags = review_option_tag_options();

$pageTitle = $product['name'];
require __DIR__ . '/includes/header.php';
?>

<style>
.pdh-wrap{max-width:1200px;margin:0 auto;padding:24px 20px 0;}
.pdh-grid{display:grid;grid-template-columns:1fr 1.15fr;gap:36px;align-items:start;}
@media (max-width:1000px){.pdh-grid{grid-template-columns:1fr;}}
.pdh-brand{font-size:13px;color:#94a3b8;font-weight:700;margin:0 0 8px;}
.pdh-chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.pdh-size-chip{display:inline-block;border:1px solid #cbd5e1;border-radius:8px;padding:4px 12px;font-size:14px;font-weight:700;color:#334155;}
.pdh-cartype-chip{display:inline-block;background:#0f172a;color:#fff;border-radius:8px;padding:4px 12px;font-size:13px;font-weight:700;}
.pdh-title{font-size:26px;font-weight:800;color:#0f172a;margin:0 0 6px;line-height:1.32;word-break:break-all;}
.pdh-subtitle{font-size:13px;color:#94a3b8;margin:0 0 14px;}
.pdh-rating-row{display:flex;align-items:center;gap:6px;font-size:13px;color:#64748b;margin-bottom:20px;}
.pdh-rating-row .star{color:#fbbf24;font-size:14px;}
.pdh-rating-row .sep{color:#e2e8f0;}
.pdh-price-box{margin-bottom:20px;padding-bottom:18px;border-bottom:1px dashed #e2e8f0;}
.pdh-price-top-row{display:flex;align-items:baseline;gap:8px;margin-bottom:4px;min-height:18px;}
.pdh-discount{color:#ef4444;font-weight:800;font-size:15px;}
.pdh-orig-price{color:#94a3b8;font-size:13px;text-decoration:line-through;}
.pdh-final-row{display:flex;align-items:baseline;gap:8px;}
.pdh-won-badge{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;background:#0d9488;color:#fff;border-radius:6px;font-size:11px;font-weight:800;flex-shrink:0;}
.pdh-final-price{font-size:27px;font-weight:800;color:#0d9488;}
.pdh-option-row{margin-bottom:14px;}
.pdh-option-row label{display:block;font-size:12px;color:#64748b;margin-bottom:6px;font-weight:600;}
.pdh-option-row select{width:100%;padding:11px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;background:#fff;}
.pdh-stock-warn{color:#ef4444;font-size:12px;margin-top:6px;display:none;}
.pdh-stock-ok{color:#0d9488;font-size:13px;font-weight:700;margin:0 0 6px;}
.pdh-dot-info{font-size:13px;color:#475569;margin:0 0 6px;}
.pdh-restock-row{display:flex;gap:8px;margin-top:8px;}
.pdh-restock-row input{width:70px;padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;}
.btn-restock{border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:700;color:#334155;cursor:pointer;}
.pdh-qty-row{display:flex;align-items:center;gap:12px;margin-bottom:16px;}
.pdh-qty-row > label{font-size:13px;color:#64748b;font-weight:600;}
.pdh-qty-stepper{display:flex;align-items:center;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;}
.pdh-qty-stepper button{width:32px;height:34px;border:none;background:#f8fafc;font-size:16px;cursor:pointer;color:#475569;}
.pdh-qty-stepper button:hover{background:#eef2f7;}
.pdh-qty-stepper input{width:44px;height:34px;border:none;text-align:center;font-size:14px;-moz-appearance:textfield;}
.pdh-total-row{display:flex;align-items:center;justify-content:space-between;font-size:13px;color:#64748b;margin-bottom:18px;}
.pdh-total-row strong{font-size:17px;color:#0f172a;}
.pdh-action-row{display:flex;align-items:center;gap:10px;}
.pdh-icon-btn{width:46px;height:46px;border-radius:12px;border:1px solid #e2e8f0;background:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;color:#64748b;cursor:pointer;transition:.15s;flex-shrink:0;}
.pdh-icon-btn:hover{border-color:#cbd5e1;background:#f8fafc;}
.pdh-icon-btn.active{color:#ef4444;border-color:#fca5a5;background:#fef2f2;}
.pdh-buy-btn{flex:1;height:46px;border:none;border-radius:12px;background:#0f172a;color:#fff;font-weight:700;font-size:15px;cursor:pointer;transition:.15s;}
.pdh-buy-btn:hover{background:#1e293b;}
.pdh-buy-btn:disabled{opacity:.6;cursor:not-allowed;}
.pdh-benefit-box{margin-top:16px;font-size:12px;color:#94a3b8;line-height:1.6;}
.pdh-gallery{display:flex;flex-direction:column;gap:12px;}
.pdh-gallery-row{display:flex;gap:14px;align-items:stretch;}
.pdh-main-img-wrap{position:relative;flex:1;min-width:0;}
.pdh-main-img{width:100%;aspect-ratio:1/1;background:#f1f5f9;border-radius:16px;display:flex;align-items:center;justify-content:center;overflow:hidden;}
.pdh-main-img img{width:100%;height:100%;object-fit:cover;}
.pdh-main-img .ph{font-size:48px;}
.pdh-badge-side{width:150px;flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:14px;padding-top:6px;}
.pdh-badge-grade{width:100%;text-align:center;border:1px solid #cbd5e1;border-radius:14px;padding:12px 6px;font-size:17px;font-weight:800;color:#0f172a;background:#fff;box-shadow:0 4px 10px rgba(15,23,42,.06);}
.pdh-badge-tags{display:flex;flex-wrap:wrap;justify-content:center;gap:6px;width:100%;}
.pdh-badge-tags .pdh-tag-chip{background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:700;color:#334155;white-space:nowrap;}
.pdh-badge-services{display:flex;flex-direction:column;gap:9px;width:100%;padding-top:2px;border-top:1px solid #e2e8f0;padding-top:12px;}
.pdh-badge-services .pdh-service-item{display:flex;align-items:center;gap:6px;font-size:12px;color:#334155;font-weight:700;}
.pdh-badge-services .pdh-service-item .ic{color:#0d9488;font-size:13px;}
.pdh-badge-consult{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:11px;color:#64748b;text-decoration:none;text-align:center;padding-top:10px;border-top:1px solid #e2e8f0;width:100%;}
.pdh-badge-consult:hover{color:#0d9488;}
.pdh-thumb-strip{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
.pdh-thumb-strip img{width:56px;height:56px;object-fit:cover;border-radius:10px;cursor:pointer;border:2px solid transparent;transition:border-color .12s;}
.pdh-thumb-strip img.active,.pdh-thumb-strip img:hover{border-color:#0d9488;}
.pd-review-cta{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
.btn-review-write{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;padding:12px 26px;border-radius:999px;font-weight:700;font-size:15px;cursor:pointer;box-shadow:0 6px 16px rgba(99,102,241,.35);transition:transform .15s ease,box-shadow .15s ease;display:inline-flex;align-items:center;gap:6px;}
.btn-review-write:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(99,102,241,.45);}
.btn-review-write:active{transform:translateY(0);}
.pd-review-ddays{font-size:13px;color:#64748b;}
.review-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:opacity .2s ease;z-index:999;}
.review-modal-overlay.active{opacity:1;visibility:visible;}
.review-modal-box{background:#fff;border-radius:20px;padding:32px;width:92%;max-width:460px;position:relative;transform:translateY(16px) scale(.97);transition:transform .2s ease;box-shadow:0 24px 60px rgba(0,0,0,.25);max-height:88vh;overflow-y:auto;}
.review-modal-overlay.active .review-modal-box{transform:translateY(0) scale(1);}
.review-modal-close{position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;}
.review-modal-title{font-size:19px;font-weight:800;margin-bottom:4px;}
.review-modal-sub{font-size:13px;color:#64748b;margin-bottom:18px;}
.star-rating{display:flex;flex-direction:row-reverse;gap:4px;margin-bottom:16px;}
.star-rating input{display:none;}
.star-rating label{font-size:30px;color:#e2e8f0;cursor:pointer;transition:color .12s,transform .12s;}
.star-rating input:checked ~ label,.star-rating label:hover,.star-rating label:hover ~ label{color:#fbbf24;}
/* [NEW] 리뷰 태그(체크박스 칩) — 반드시 인접 형제 선택자(+)만 사용해서
   "하나 체크하면 뒤의 모든 라벨이 같이 하이라이트되는" 버그를 방지한다. (~ 사용 금지) */
.rv-modal-field-label{font-size:13px;font-weight:700;color:#334155;margin-bottom:10px;}
.rv-chip-group{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;}
.rv-chip-input{position:absolute;opacity:0;width:0;height:0;pointer-events:none;}
.rv-chip-label{display:inline-flex;align-items:center;gap:4px;padding:8px 14px;border:1.5px solid #e2e8f0;border-radius:999px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;user-select:none;transition:.15s;background:#fff;}
.rv-chip-label:hover{border-color:#c7d2fe;background:#f5f5ff;}
.rv-chip-input:checked + .rv-chip-label{background:linear-gradient(135deg,#6366f1,#8b5cf6);border-color:transparent;color:#fff;box-shadow:0 4px 10px rgba(99,102,241,.35);}
.review-modal-box textarea{width:100%;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;font-size:14px;resize:vertical;margin-bottom:18px;box-sizing:border-box;}
.review-modal-actions{display:flex;gap:10px;justify-content:flex-end;}
.btn-modal-cancel{background:#f1f5f9;color:#475569;border:none;padding:10px 20px;border-radius:999px;font-weight:600;cursor:pointer;}
.btn-modal-submit{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;padding:10px 24px;border-radius:999px;font-weight:700;cursor:pointer;box-shadow:0 6px 16px rgba(99,102,241,.35);transition:transform .12s ease;}
.btn-modal-submit:hover{transform:translateY(-1px);}
.pd-review-item{display:flex;flex-direction:column;gap:6px;padding:16px 0;border-bottom:1px solid #f1f5f9;}
.pd-review-item-top{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.pd-review-meta{display:flex;align-items:center;gap:8px;}
.btn-review-delete{background:none;border:1px solid #e2e8f0;color:#94a3b8;font-size:12px;padding:4px 10px;border-radius:999px;cursor:pointer;transition:color .12s,border-color .12s;}
.btn-review-delete:hover{color:#ef4444;border-color:#fca5a5;}
/* [NEW] 리뷰 목록에 표시되는 태그 칩 (읽기 전용) */
.pd-review-tag-row{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 2px;}
.pd-review-tag-chip{background:#f0f0ff;color:#6366f1;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;}
.pd-flash-msg{padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:14px;}
.pd-flash-msg.success{background:#ecfdf5;color:#047857;}
.pd-flash-msg.error{background:#fef2f2;color:#b91c1c;}
.pd-desc-html{font-size:15px;line-height:1.75;color:#334155;word-break:break-word;margin-bottom:20px;}
.pd-desc-html a{color:#4338ca;text-decoration:underline;}
.pd-desc-html ul,.pd-desc-html ol{padding-left:20px;margin:10px 0;}
.pd-desc-html blockquote{margin:12px 0;padding:10px 16px;border-left:3px solid #cbd5e1;background:#f8fafc;color:#475569;}
.pd-desc-html h3,.pd-desc-html h4{margin:16px 0 8px;color:#0f172a;}
.pd-detail-images{margin-top:24px;display:flex;flex-direction:column;}
.pd-detail-images img{display:block;width:100%;height:auto;}
.pd-tabs{max-width:1200px;margin:32px auto 0;padding:0 20px;display:flex;gap:8px;border-bottom:1px solid #e2e8f0;}
.pd-tab-btn{background:none;border:none;padding:12px 18px;font-size:15px;font-weight:700;color:#94a3b8;cursor:pointer;border-bottom:2px solid transparent;}
.pd-tab-btn.active{color:#0f172a;border-bottom-color:#0f172a;}
.pd-tab-panel{max-width:1200px;margin:0 auto;padding:24px 20px 60px;display:none;}
.pd-tab-panel.active{display:block;}
.pd-spec-box{margin-bottom:24px;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;}
.pd-spec-box h3{margin:0;padding:14px 18px;font-size:15px;font-weight:800;background:#f8fafc;border-bottom:1px solid #e2e8f0;}
.pd-spec-table{width:100%;border-collapse:collapse;font-size:14px;}
.pd-spec-table tr:not(:last-child) th,.pd-spec-table tr:not(:last-child) td{border-bottom:1px solid #f1f5f9;}
.pd-spec-table th{width:34%;text-align:left;padding:12px 18px;color:#64748b;font-weight:600;background:#fafbff;white-space:nowrap;}
.pd-spec-table td{padding:12px 18px;color:#1e293b;font-weight:600;word-break:break-all;}
.pd-shipping-box{font-size:14px;color:#334155;line-height:1.7;}
.pd-shipping-box h4{font-size:15px;font-weight:800;margin:22px 0 10px;color:#1e293b;}
.pd-shipping-box h4:first-child{margin-top:0;}
.pd-shipping-box ul{margin:0;padding-left:18px;}
.pd-shipping-box li{margin-bottom:6px;}
.pd-shipping-sub{font-size:13px;font-weight:700;color:#475569;margin:14px 0 6px;}
</style>

<main class="tt-main">
<div class="pdh-wrap">
  <div class="pd-breadcrumb" style="margin-bottom:16px;">
    <a href="<?= BASE_URL ?>/product-list.php">상품 목록</a> &gt; <?= h($product['name']) ?>
  </div>

  <div class="pdh-grid">
    <div class="pdh-info">
      <?php if (!empty($product['brand_name'])): ?>
        <p class="pdh-brand"><?= h($product['brand_name']) ?></p>
      <?php endif; ?>

      <?php if ($sizeLabel !== '' || $badgeCarType !== ''): ?>
      <div class="pdh-chip-row">
        <?php if ($badgeCarType !== ''): ?>
          <span class="pdh-cartype-chip"><?= h($badgeCarType) ?></span>
        <?php endif; ?>
        <?php if ($sizeLabel !== ''): ?>
          <span class="pdh-size-chip"><?= h($sizeLabel) ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <h1 class="pdh-title"><?= h($product['name']) ?></h1>
      <?php if ($subtitle !== ''): ?>
        <p class="pdh-subtitle"><?= h($subtitle) ?></p>
      <?php endif; ?>

      <div class="pdh-rating-row">
        <span class="star">★</span>
        <span><?= number_format((float)($product['rating_avg'] ?? 0), 1) ?>점</span>
        <span class="sep">|</span>
        <span>💬 <?= (int)$product['review_count'] ?></span>
      </div>

      <div class="pdh-price-box" data-price-original="<?= (int)$product['price_original'] ?>">
        <div class="pdh-price-top-row">
          <?php if ($discountPct > 0): ?>
            <span class="pdh-discount" id="pdDiscountPct"><?= $discountPct ?>%</span>
            <span class="pdh-orig-price"><?= number_format((int)$product['price_original']) ?>원</span>
          <?php endif; ?>
        </div>
        <div class="pdh-final-row">
          <span class="pdh-won-badge">W</span>
          <span class="pdh-final-price" id="pdPriceNow"><?= number_format((int)$product['price_sale']) ?>원</span>
        </div>
      </div>

      <?php if (!empty($options)): ?>
      <div class="pdh-option-row">
        <label for="pdOptionSelect">DOT 옵션 선택 (재고·년차별 옵션)</label>
        <select id="pdOptionSelect">
          <option value="">옵션을 선택해주세요</option>
          <?php foreach ($options as $opt):
              $dotYear = '';
              if (preg_match('/(\d{4})$/', $opt['dot_code'] ?? '', $m)) {
                  $dotYear = $m[1];
              } elseif (preg_match('/(\d{2})$/', $opt['dot_code'] ?? '', $m)) {
                  $dotYear = '20' . $m[1];
              }
          ?>
            <option value="<?= (int)$opt['id'] ?>"
                    data-price="<?= (int)$opt['price_sale'] ?>"
                    data-stock="<?= (int)$opt['stock_qty'] ?>">
              DOT <?= h($opt['dot_code']) ?><?= $dotYear ? ' (' . h($dotYear) . ')' : '' ?> (재고 <?= (int)$opt['stock_qty'] ?>개) - <?= number_format((int)$opt['price_sale']) ?>원
            </option>
          <?php endforeach; ?>
        </select>
        <p class="pdh-stock-warn" id="pdStockWarn">품절된 옵션입니다.</p>
      </div>
      <?php else: ?>
        <div class="pdh-option-row">
          <?php if (!empty($product['dot_code'])): ?>
            <p class="pdh-dot-info">DOT: <strong><?= h($product['dot_code']) ?></strong></p>
          <?php endif; ?>
          <?php if ((int)$product['stock'] > 0): ?>
            <p class="pdh-stock-ok">재고 <?= (int)$product['stock'] ?>개</p>
          <?php else: ?>
            <p class="pdh-stock-warn" style="display:block;">판매중인 상품의 재고가 없습니다.</p>
            <div class="pdh-restock-row">
              <input type="number" id="pdRestockQty" placeholder="수량" min="1" value="1">
              <button type="button" class="btn-restock" id="pdRestockBtn" data-product-id="<?= (int)$productId ?>">재고 입고요청</button>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="pdh-qty-row">
        <label>수량</label>
        <div class="pdh-qty-stepper">
          <button type="button" id="pdQtyMinus">-</button>
          <input type="number" id="pdQtyInput" value="1" min="1" max="99">
          <button type="button" id="pdQtyPlus">+</button>
        </div>
      </div>

      <div class="pdh-total-row">
        <span>총 결제금액</span>
        <strong id="pdTotalPrice"><?= number_format((int)$product['price_sale']) ?>원</strong>
      </div>

      <div class="pdh-action-row">
        <button type="button" class="pdh-icon-btn <?= $isWished ? 'active' : '' ?>" id="pdWishBtn" aria-label="찜하기">
          <?= $isWished ? '♥' : '♡' ?>
        </button>
        <button type="button" class="pdh-icon-btn" id="pdAddCartBtn" aria-label="장바구니">🛒</button>
        <button type="button" class="pdh-buy-btn" id="pdBuyNowBtn">바로구매</button>
      </div>

      <p class="pdh-benefit-box">표시된 가격은 부가세 포함가이며, DOT 옵션 선택 시 해당 재고 기준으로 결제가 진행됩니다.</p>
    </div>

    <div class="pdh-gallery">
      <div class="pdh-gallery-row">
        <div class="pdh-main-img-wrap">
          <div class="pdh-main-img" id="pdMainImgBox">
            <?php if (!empty($mainImages)): ?>
              <img id="pdMainImgTag" src="<?= h($mainImages[0]) ?>" alt="<?= h($product['name']) ?>">
            <?php else: ?>
              <span class="ph">🛞</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="pdh-badge-side">
          <?php if ($badgeGrade !== ''): ?>
            <div class="pdh-badge-grade"><?= h($badgeGrade) ?></div>
          <?php endif; ?>
          <?php if (!empty($featureTags)): ?>
            <div class="pdh-badge-tags">
              <?php foreach ($featureTags as $tag): ?>
                <span class="pdh-tag-chip"><?= h($tag) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="pdh-badge-services">
            <div class="pdh-service-item"><span class="ic">🚚</span> 무료배송</div>
            <div class="pdh-service-item"><span class="ic">🔧</span> 무료장착</div>
          </div>
          <a class="pdh-badge-consult" href="tel:1877-9778">📞 브랜드 상담 문의</a>
        </div>
      </div>

      <?php if (count($mainImages) > 1): ?>
      <div class="pdh-thumb-strip" id="pdThumbStrip">
        <?php foreach ($mainImages as $i => $imgUrl): ?>
          <img src="<?= h($imgUrl) ?>" class="<?= $i === 0 ? 'active' : '' ?>" data-src="<?= h($imgUrl) ?>" alt="썸네일 <?= $i + 1 ?>">
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="pd-tabs">
    <button type="button" class="pd-tab-btn active" data-tab="info">상품 기본정보</button>
    <button type="button" class="pd-tab-btn" data-tab="shipping">배송/장착</button>
    <button type="button" class="pd-tab-btn" data-tab="review">후기 (<?= (int)$product['review_count'] ?>)</button>
  </div>

  <div class="pd-tab-panel active" data-panel="info">
    <?php if (!empty($specRows)): ?>
      <div class="pd-spec-box">
        <h3>상품 기본정보</h3>
        <table class="pd-spec-table">
          <?php foreach ($specRows as $row): ?>
            <tr>
              <th><?= h($row[0]) ?></th>
              <td><?= h((string)$row[1]) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>

    <?php if (!empty($product['description'])): ?>
      <div class="pd-desc-html"><?= pd_render_description((string)$product['description']) ?></div>
    <?php else: ?>
      <p style="color:var(--gray4);">등록된 상세 설명이 없습니다.</p>
    <?php endif; ?>

    <?php if (!empty($detailImages)): ?>
      <div class="pd-detail-images">
        <?php foreach ($detailImages as $imgUrl): ?>
          <img src="<?= h($imgUrl) ?>" alt="상세페이지 이미지" loading="lazy">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="pd-tab-panel" data-panel="shipping">
    <div class="pd-shipping-box">
      <h4>배송 / 장착 안내</h4>
      <ul>
        <li>전국 무료 배송 (단, 제주 및 도서지역은 개당 5,500원 추가 발생)</li>
        <li>출고 및 배송 완료 시 알림 안내</li>
        <li>출고일 기준 3일 이내 도착 (해당 지역 물량에 따라 배송 일정 상이)</li>
        <li>배송완료 이후 매장에 예약 일정 문의 요망</li>
        <li>배송완료 이후 2주 이내 장착 요망 (단, 매장과 합의하에 장기 보관 가능(보관비 발생))</li>
      </ul>
      <h4>반품 / 교환 안내</h4>
      <p class="pd-shipping-sub">반품 / 교환 가능한 경우</p>
      <ul>
        <li>제품의 하자, 배송 오류 등의 사유로 반품 시 반품배송비는 무료</li>
        <li>사용하지 않은 경우 상품 수령 후 14일 이내에 신청 가능</li>
        <li>고객님의 오 주문 및 단순한 변심에 의해 반품/교환 요청 시 왕복 배송 비용 발생 (제주 및 도서지역은 개당 11,000원 추가 발생)</li>
        <li>전자상거래 등에서의 소비자보호에 관한 법률에 규정되어 있는 소비자 청약 철회 가능 범위에 해당되는 경우</li>
      </ul>
      <p class="pd-shipping-sub">반품 / 교환 불가능한 경우</p>
      <ul>
        <li>본 제품의 특성상 장착 이후에는 반품 불가</li>
        <li>고객님 과실로 인한 상품 멸실 또는 상품이 훼손된 경우</li>
        <li>고객님의 관리 부주의로 상품 가치가 현저히 감소한 경우</li>
        <li>반품 가능 기간(수령 후 14일 이내)이 경과된 경우</li>
        <li>차량의 문제로 제휴점에서 장착이 불가한 경우 무료 반품 불가 (반품비 발생)</li>
      </ul>
      <h4>품질보증기준</h4>
      <ul>
        <li>제조상의 과실에 의한 하자가 발생 시 보증기간 내에 있는 제품에 한해 A/S 처리됩니다. (제조일로부터 6년 이내, 홈 깊이가 20% 이상 남은 경우)</li>
        <li>공정거래위원회 고시(소비자 분쟁 해결기준)에 의거하여 보상해 드립니다.</li>
      </ul>
    </div>
  </div>

<div class="pd-tab-panel" data-panel="review" id="review">
    <?php if ($flashMsg): ?>
        <div class="pd-flash-msg <?= h($flashMsg['type'] ?? 'success') ?>">
            <?= h($flashMsg['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php
    $rvStmt = $pdo->prepare("
        SELECT r.id, r.user_id, r.rating, r.content, r.option_tags, r.created_at, u.name AS user_name
        FROM tt_reviews r
        JOIN tt_users u ON u.id = r.user_id
        WHERE r.product_id = :pid
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $rvStmt->execute(['pid' => $productId]);
    $productReviews = $rvStmt->fetchAll(PDO::FETCH_ASSOC);
    $currentUid = Auth::isLoggedIn() ? (int)Auth::currentUserId() : 0;

    $canWriteReview = false;
    $reviewDeadline = null;
    $reviewDaysLeft = 0;

    if (Auth::isLoggedIn()) {
        $eligStmt = $pdo->prepare("
            SELECT oi.id, o.confirmed_at
            FROM tt_order_items oi
            JOIN tt_orders o ON o.id = oi.order_id
            WHERE o.user_id = :uid
              AND oi.product_id = :pid
              AND o.confirmed_at IS NOT NULL
            ORDER BY o.confirmed_at DESC
            LIMIT 1
        ");
        $eligStmt->execute(['uid' => Auth::currentUserId(), 'pid' => $productId]);
        $eligibleOrderItem = $eligStmt->fetch(PDO::FETCH_ASSOC);

        $alreadyStmt = $pdo->prepare('SELECT id FROM tt_reviews WHERE user_id = :uid AND product_id = :pid LIMIT 1');
        $alreadyStmt->execute(['uid' => Auth::currentUserId(), 'pid' => $productId]);
        $alreadyReviewed = (bool)$alreadyStmt->fetch();

        if ($eligibleOrderItem && !$alreadyReviewed) {
            $confirmedAt = new DateTime($eligibleOrderItem['confirmed_at']);
            $deadline    = (clone $confirmedAt)->modify('+' . REVIEW_WRITE_WINDOW_DAYS . ' days');
            $now         = new DateTime();
            if ($now <= $deadline) {
                $canWriteReview = true;
                $reviewDeadline = $deadline;
                $diff = $now->diff($deadline);
                $reviewDaysLeft = max(1, (int)$diff->days + (($diff->h > 0 || $diff->i > 0) ? 1 : 0));
            }
        }
    }
    ?>

    <?php if ($canWriteReview): ?>
        <div class="pd-review-cta">
            <button type="button" class="btn-review-write" id="pdReviewWriteBtn">
                <span>✎</span> 후기 작성하기
            </button>
            <span class="pd-review-ddays">
                후기 작성 가능 기간: <strong>D-<?= $reviewDaysLeft ?></strong>
                (<?= h($reviewDeadline->format('Y.m.d')) ?>까지)
            </span>
        </div>
    <?php elseif (Auth::isLoggedIn()): ?>
        <p style="color:var(--gray4);">구매확정일 후 7일 이내에만 후기를 작성하실 수 있습니다. (이미 후기를 작성했거나 후기작성기한이 지났거나 구매한 상품이 아니면 표시되지 않음)</p>
    <?php endif; ?>

    <?php if (empty($productReviews)): ?>
        <p style="color:var(--gray4);">등록된 후기가 없습니다.</p>
    <?php else: ?>
        <div class="pd-review-list">
            <?php foreach ($productReviews as $rv): ?>
                <div class="pd-review-item">
                    <div class="pd-review-item-top">
                        <div class="pd-review-meta">
                            <span class="pd-review-stars"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></span>
                            <span class="pd-review-user"><?= h(mb_substr($rv['user_name'], 0, 1) . str_repeat('*', max(0, mb_strlen($rv['user_name']) - 1))) ?></span>
                            <span class="pd-review-date"><?= h(date('Y.m.d', strtotime($rv['created_at']))) ?></span>
                        </div>
                        <?php if ($currentUid && $currentUid === (int)$rv['user_id']): ?>
                            <form method="post" action="<?= BASE_URL ?>/review-delete.php" onsubmit="return confirm('후기를 삭제하시겠습니까? 삭제된 후기는 되돌릴 수 없습니다.');" style="margin:0;">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                                <input type="hidden" name="return_to" value="product">
                                <button type="submit" class="btn-review-delete">삭제</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php $rvTags = review_parse_option_tags($rv['option_tags'] ?? null); ?>
                    <?php if (!empty($rvTags)): ?>
                        <div class="pd-review-tag-row">
                            <?php foreach ($rvTags as $t): ?>
                                <span class="pd-review-tag-chip"><?= h($t) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <p class="pd-review-content"><?= nl2br(h($rv['content'])) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canWriteReview): ?>
<div class="review-modal-overlay" id="reviewModalOverlay">
  <div class="review-modal-box">
    <button type="button" class="review-modal-close" id="reviewModalClose" aria-label="닫기">&times;</button>
    <h3 class="review-modal-title">후기 작성하기</h3>
    <p class="review-modal-sub">솔직한 사용 후기를 남겨주시면 다른 고객님들께 큰 도움이 됩니다.</p>
    <form method="post" action="<?= BASE_URL ?>/review-submit.php" class="pd-review-form" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
        <input type="hidden" name="return_to" value="product">
        <div class="star-rating">
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
                <label for="star<?= $i ?>">★</label>
            <?php endfor; ?>
        </div>

        <?php if (!empty($reviewOptionTags)): ?>
        <div class="rv-modal-field-label">이런 점이 좋았어요! (선택, 여러 개 선택 가능)</div>
        <div class="rv-chip-group">
            <?php foreach ($reviewOptionTags as $idx => $tagLabel): ?>
                <input type="checkbox" class="rv-chip-input" id="rvTag<?= $idx ?>" name="option_tags[]" value="<?= h($tagLabel) ?>">
                <label class="rv-chip-label" for="rvTag<?= $idx ?>"><?= h($tagLabel) ?></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <textarea name="content" rows="4" maxlength="1000" placeholder="상품에 대한 솔직한 후기를 남겨주세요." required></textarea>
        <div class="review-modal-actions">
            <button type="button" class="btn-modal-cancel" id="reviewModalCancel">취소</button>
            <button type="submit" class="btn-modal-submit">등록하기</button>
        </div>
    </form>
  </div>
</div>
<?php endif; ?>

<form method="post" action="<?= BASE_URL ?>/buy-now.php" id="buyNowForm" style="display:none;">
    <?= Csrf::field() ?>
    <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
    <input type="hidden" name="option_id" id="buyNowOptionId" value="">
    <input type="hidden" name="qty" id="buyNowQty" value="1">
</form>

</main>

<input type="hidden" id="csrfToken" value="<?= h($csrfToken) ?>">
<script>
const BASE_URL       = "<?= BASE_URL ?>";
const csrfToken      = document.getElementById('csrfToken').value;
const productId      = <?= (int)$productId ?>;
const hasOptions     = <?= !empty($options) ? 'true' : 'false' ?>;
const isLoggedIn      = <?= Auth::isLoggedIn() ? 'true' : 'false' ?>;
const autoOpenReview = <?= $autoOpenReview ? 'true' : 'false' ?>;

const qtyInput     = document.getElementById('pdQtyInput');
const totalEl      = document.getElementById('pdTotalPrice');
const priceNowEl   = document.getElementById('pdPriceNow');
const optionSelect = document.getElementById('pdOptionSelect');
const stockWarn    = document.getElementById('pdStockWarn');
const buyBtn       = document.getElementById('pdBuyNowBtn');
const cartBtn      = document.getElementById('pdAddCartBtn');
const wishBtn      = document.getElementById('pdWishBtn');

const pdThumbStrip = document.getElementById('pdThumbStrip');
const pdMainImgTag = document.getElementById('pdMainImgTag');
if (pdThumbStrip && pdMainImgTag) {
  pdThumbStrip.addEventListener('click', (e) => {
    const img = e.target.closest('img');
    if (!img) return;
    pdMainImgTag.src = img.dataset.src;
    pdThumbStrip.querySelectorAll('img').forEach(t => t.classList.remove('active'));
    img.classList.add('active');
  });
}

function currentUnitPrice() {
  if (optionSelect && optionSelect.value !== '') {
    const opt = optionSelect.options[optionSelect.selectedIndex];
    return parseInt(opt.dataset.price, 10) || 0;
  }
  return parseInt(priceNowEl.textContent.replace(/[^0-9]/g, ''), 10) || 0;
}

function recalcTotal() {
  const qty = Math.max(1, Math.min(99, parseInt(qtyInput.value, 10) || 1));
  qtyInput.value = qty;
  totalEl.textContent = (currentUnitPrice() * qty).toLocaleString('ko-KR') + '원';
}

document.getElementById('pdQtyMinus').addEventListener('click', () => {
  qtyInput.value = Math.max(1, parseInt(qtyInput.value, 10) - 1);
  recalcTotal();
});
document.getElementById('pdQtyPlus').addEventListener('click', () => {
  qtyInput.value = Math.min(99, parseInt(qtyInput.value, 10) + 1);
  recalcTotal();
});
qtyInput.addEventListener('input', recalcTotal);

if (optionSelect) {
  const basePriceLabel = priceNowEl.textContent;
  optionSelect.addEventListener('change', () => {
    if (optionSelect.value === '') {
      stockWarn.style.display = 'none';
      priceNowEl.textContent = basePriceLabel;
      recalcTotal();
      return;
    }
    const opt = optionSelect.options[optionSelect.selectedIndex];
    const stock = parseInt(opt.dataset.stock, 10) || 0;
    stockWarn.style.display = (stock <= 0) ? 'block' : 'none';
    priceNowEl.textContent = (parseInt(opt.dataset.price, 10) || 0).toLocaleString('ko-KR') + '원';
    recalcTotal();
  });
}

async function postJson(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  const data = await res.json();
  return { status: res.status, data };
}

wishBtn.addEventListener('click', async function () {
  try {
    const { status, data } = await postJson(BASE_URL + '/wish-toggle.php', {
      product_id: productId,
      csrf_token: csrfToken
    });
    if (status === 401) {
      alert('로그인이 필요합니다.');
      location.href = BASE_URL + '/login.php';
      return;
    }
    if (!data.success) {
      alert(data.message || '찜 목록 처리 중 오류가 발생했습니다.');
      return;
    }
    const wished = data.data.wished;
    wishBtn.classList.toggle('active', wished);
    wishBtn.textContent = wished ? '♥' : '♡';
  } catch (e) {
    alert('네트워크 오류로 찜 처리에 실패했습니다.');
  }
});

cartBtn.addEventListener('click', async function () {
  if (hasOptions && optionSelect && optionSelect.value !== '') {
    const opt = optionSelect.options[optionSelect.selectedIndex];
    if ((parseInt(opt.dataset.stock, 10) || 0) <= 0) {
      alert('품절된 옵션입니다. 다른 옵션을 선택해주세요.');
      return;
    }
  }
  const optionIdPayload = (hasOptions && optionSelect && optionSelect.value !== '')
    ? parseInt(optionSelect.value, 10)
    : null;
  try {
    const { status, data } = await postJson(BASE_URL + '/cart-add.php', {
      product_id: productId,
      option_id: optionIdPayload,
      qty: parseInt(qtyInput.value, 10) || 1,
      csrf_token: csrfToken
    });
    if (status === 401) {
      alert('로그인이 필요합니다.');
      location.href = BASE_URL + '/login.php';
      return;
    }
    if (!data.success) {
      alert(data.message || '장바구니 담기에 실패했습니다.');
      return;
    }
    if (typeof window.ttSetCartCount === 'function') {
      window.ttSetCartCount(data.data.cart_count);
    }
    alert(data.data.message || '장바구니에 담겼습니다.');
  } catch (e) {
    alert('네트워크 오류로 장바구니 처리에 실패했습니다.');
  }
});

buyBtn.addEventListener('click', function () {
  if (!isLoggedIn) {
    alert('로그인이 필요합니다.');
    location.href = BASE_URL + '/login.php';
    return;
  }
  if (hasOptions && optionSelect && optionSelect.value !== '') {
    const opt = optionSelect.options[optionSelect.selectedIndex];
    if ((parseInt(opt.dataset.stock, 10) || 0) <= 0) {
      alert('품절된 옵션입니다.');
      return;
    }
    document.getElementById('buyNowOptionId').value = optionSelect.value;
  } else {
    document.getElementById('buyNowOptionId').value = '';
  }
  document.getElementById('buyNowQty').value = Math.max(1, Math.min(99, parseInt(qtyInput.value, 10) || 1));
  buyBtn.disabled = true;
  document.getElementById('buyNowForm').submit();
});

document.querySelectorAll('.pd-tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.pd-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.pd-tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.querySelector(`.pd-tab-panel[data-panel="${btn.dataset.tab}"]`).classList.add('active');
  });
});

const reviewBtn     = document.getElementById('pdReviewWriteBtn');
const reviewOverlay = document.getElementById('reviewModalOverlay');
const reviewClose   = document.getElementById('reviewModalClose');
const reviewCancel  = document.getElementById('reviewModalCancel');

function openReviewModal() {
  reviewOverlay.classList.add('active');
  document.body.style.overflow = 'hidden';
}
function closeReviewModal() {
  reviewOverlay.classList.remove('active');
  document.body.style.overflow = '';
}

if (reviewBtn && reviewOverlay) {
  reviewBtn.addEventListener('click', openReviewModal);
  reviewClose?.addEventListener('click', closeReviewModal);
  reviewCancel?.addEventListener('click', closeReviewModal);
  reviewOverlay.addEventListener('click', (e) => {
    if (e.target === reviewOverlay) closeReviewModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && reviewOverlay.classList.contains('active')) closeReviewModal();
  });
}

if (autoOpenReview) {
  const reviewTabBtn = document.querySelector('.pd-tab-btn[data-tab="review"]');
  const reviewPanel  = document.querySelector('.pd-tab-panel[data-panel="review"]');

  document.querySelectorAll('.pd-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.pd-tab-panel').forEach(p => p.classList.remove('active'));
  reviewTabBtn?.classList.add('active');
  reviewPanel?.classList.add('active');

  document.getElementById('review')?.scrollIntoView({ behavior: 'smooth', block: 'start' });

  if (reviewOverlay) {
    setTimeout(openReviewModal, 350);
  }
}

const restockBtn = document.getElementById('pdRestockBtn');
if (restockBtn) {
  restockBtn.addEventListener('click', async function(){
    const productId = parseInt(restockBtn.dataset.productId, 10);
    const qtyInput  = document.getElementById('pdRestockQty');
    const qty       = Math.max(1, parseInt(qtyInput?.value, 10) || 1);

    restockBtn.disabled = true;
    restockBtn.textContent = '재고 요청 중...';
    try {
      const formData = new FormData();
      formData.append('product_id', productId);
      formData.append('qty', qty);
      formData.append('csrf_token', csrfToken);

      const res = await fetch(BASE_URL + '/ajax-stock-request.php', {
        method: 'POST',
        body: formData
      });

      if (res.status === 401) {
        alert('로그인이 필요합니다.');
        location.href = BASE_URL + '/login.php';
        return;
      }

      const data = await res.json();
      if (data.success) {
        restockBtn.textContent = '✓ 재고 요청';
        restockBtn.style.background = '#22c55e';
        restockBtn.style.color = '#fff';
        alert(data.message || '재고 입고 요청이 등록되었습니다.');
      } else {
        restockBtn.disabled = false;
        restockBtn.textContent = '재고 입고요청';
        alert(data.message || '요청 처리 중 오류가 발생했습니다.');
      }
    } catch (e) {
      restockBtn.disabled = false;
      restockBtn.textContent = '재고 입고요청';
      alert('네트워크 오류로 요청 처리에 실패했습니다.');
    }
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
