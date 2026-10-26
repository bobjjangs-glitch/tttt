<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
ensure_review_extra_columns();

if (!defined('REVIEW_WRITE_WINDOW_DAYS')) {
    define('REVIEW_WRITE_WINDOW_DAYS', 7);
}

$pdo = Database::connection();
ensure_price_tier_columns();

$userType = Auth::isLoggedIn() ? Auth::currentUserType() : 'personal';

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
    echo '잘못된 상품 접근입니다.';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.model, p.spec, p.origin, p.thumbnail_url,
            p.price_original, p.price_sale, p.business_price, p.stock, p.status,
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
$stmt->execute(['id' => $productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product || $product['status'] !== 'active') {
    http_response_code(404);
    echo '판매종료되었거나 존재하지 않는 상품입니다.';
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

/* [FIX] flash() 헬퍼는 $_SESSION['_flash'][키] 구조로 저장하는데
   예전 코드가 $_SESSION['flash']를 직접 읽어 바로구매·주문 실패 안내가
   상세 페이지에 절대 표시되지 않았다. flash() 헬퍼로 정확히 꺼낸다. */
$flashMsg = null;
$flashError = flash('error');
$flashSuccess = flash('success');
if ($flashError !== null) {
    $flashMsg = ['type' => 'error', 'message' => $flashError];
} elseif ($flashSuccess !== null) {
    $flashMsg = ['type' => 'success', 'message' => $flashSuccess];
}

$discountPct = 0;
$displayPrice = (int)$product['price_sale'];
$isBizPrice = false;
if (Auth::isLoggedIn() && Auth::isBusiness()) {
    $businessPrice = (int)($product['business_price'] ?? 0);
    $displayPrice = get_display_price((int)$product['price_sale'], $businessPrice, 'business');
    $isBizPrice = $displayPrice < (int)$product['price_sale'];
}
if ((int)$product['price_original'] > 0) {
    $discountPct = (int)round((1 - ($displayPrice / (int)$product['price_original'])) * 100);
}

/* ===== 재고 상태 (소비자·사업자 공통 — 재고 없으면 구매/결제 진행 불가) =====
   사업자+DOT옵션형: 기본상품 재고와 모든 옵션 재고가 전부 0일 때만 품절 취급
   (옵션 선택 시엔 해당 옵션 재고 기준 — 기존 JS 옵션 처리가 담당)          */
$productStock = (int)$product['stock'];
$hasOptionChoices = !empty($options) && $userType === 'business';
$totalOptionStock = 0;
foreach ((array)$options as $opt) $totalOptionStock += max(0, (int)$opt['stock_qty']);
$outOfStock = $hasOptionChoices
    ? ($productStock <= 0 && $totalOptionStock <= 0)
    : ($productStock <= 0);

/* ===== 혜택 안내 (사진 참고) — 쿠폰 다운로드 / 보증 배너 / 가입 쿠폰팩 / 카드할인 =====
   [v46] 사업자 회원은 쿠폰(받기·가입팩·쿠폰함) 대상에서 제외 — 소비자 전용 혜택 */
$isBizUser = ($userType === 'business');
$downloadableCoupons = $isBizUser ? [] : get_downloadable_coupons(Auth::isLoggedIn() ? Auth::currentUserId() : null);
$warrantyBannerText = trim(get_setting('benefit_warranty_text', ''));
if ($warrantyBannerText === '') $warrantyBannerText = '정품 보증 · 무상 밸런스/장착 안내';
[$packCount, $packWon] = $isBizUser ? [0, 0] : signup_coupon_pack_summary();
$signupPackText = trim(get_setting('benefit_signup_pack_text', ''));
if ($signupPackText === '') $signupPackText = '첫 가입 & 구매 시 쿠폰팩 증정';
$cardBenefitRows = [];
for ($bi = 1; $bi <= 3; $bi++) {
    $bLabel = trim(get_setting("benefit_row_{$bi}_label", $bi === 1 ? '카드할인' : ''));
    $bText  = trim(get_setting("benefit_row_{$bi}_text", $bi === 1 ? '카드사 무이자 할부 혜택 (결제 시 적용)' : ''));
    if ($bLabel !== '' || $bText !== '') {
        $cardBenefitRows[] = ['label' => $bLabel !== '' ? $bLabel : '카드할인', 'text' => $bText];
    }
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
if (!empty($product['brand_name']))       $specRows[] = ['브랜드', $product['brand_name']];
if ($sizeLabel !== '')                     $specRows[] = ['규격', $sizeLabel];
if (!empty($product['pattern_name']))     $specRows[] = ['패턴명', $product['pattern_name']];
if (!empty($product['pattern_code']))     $specRows[] = ['패턴코드', $product['pattern_code']];
if (!empty($product['load_speed_rating']))$specRows[] = ['하중/속도지수', $product['load_speed_rating']];
if (!empty($product['origin']))           $specRows[] = ['원산지', $product['origin']];
if (!empty($product['oem']))              $specRows[] = ['OEM 여부', $product['oem']];
if (!empty($product['tech']))             $specRows[] = ['Tech.', $product['tech']];
$specRows[]                                = ['런플랫(Runflat)', ($product['runflat'] ?? 'N') === 'Y' ? 'Y (런플랫 타이어)' : 'N (일반 타이어)'];
if (!empty($product['dot_code']) && $isBizUser) $specRows[] = ['대표 DOT / 제조년주', $product['dot_code']]; /* [v46] 사업자 전용 */

$reviewOptionTags     = review_option_tag_options();
$reviewVisitTypes     = review_visit_type_options();
$reviewExtraServices  = review_extra_service_options();
$activeStores         = store_active_options();

$pageTitle = $product['name'];
require __DIR__ . '/includes/header.php';
?>

<style>
.pdh-wrap{max-width:1200px;margin:0 auto;padding:24px 20px 0;}
/* ===== 상세 2단 레이아웃 — 좌: 이미지+상세정보/리뷰 (긴 컬럼) / 우: 구매패널 (스티키) ===== */
.pd-layout{display:grid;grid-template-columns:1.08fr 1fr;gap:32px;align-items:start;grid-template-areas:"gallery panel" "content panel";}
.pdh-gallery{grid-area:gallery;}
.pd-content{grid-area:content;min-width:0;}
.pdh-info{grid-area:panel;}
@media (max-width:1000px){.pd-layout{grid-template-columns:1fr;grid-template-areas:"gallery" "panel" "content";}}
/* ===== 우측 구매 패널 — 카드형 + 스크롤 따라오는 스티키 (데스크톱) ===== */
.pdh-info{position:sticky;top:86px;align-self:start;background:#fff;border:1px solid #e6eaf0;border-radius:18px;padding:22px 22px 18px;box-shadow:0 14px 34px rgba(15,23,42,.07);max-height:calc(100vh - 104px);overflow-y:auto;scrollbar-width:thin;line-height:1.5;}
@media (max-width:1000px){.pdh-info{position:static;max-height:none;overflow:visible;background:transparent;border:none;box-shadow:none;padding:0;border-radius:0;}}
.pdh-brand{color:#475569;font-size:13.5px;font-weight:800;margin:0 0 6px;letter-spacing:.2px;}
.pdh-chip-row{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;}
.pdh-cartype-chip,.pdh-size-chip{background:#f1f5f9;color:#334155;border-radius:999px;padding:4px 11px;font-size:12.5px;font-weight:700;}
.pdh-title{font-size:23px;font-weight:800;color:#0f172a;margin:4px 0;line-height:1.45;word-break:keep-all;}
.pdh-subtitle{color:#475569;font-size:13px;margin:0 0 10px;line-height:1.6;word-break:keep-all;}
.pdh-rating-row{display:flex;align-items:center;gap:8px;font-size:13.5px;color:#475569;font-weight:600;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #f1f5f9;}
.pdh-rating-row .star{color:#fbbf24;}
.pdh-rating-row .sep{color:#e2e8f0;}
.pdh-price-box{margin-bottom:16px;background:#f8fafc;border:1px solid #eef1f6;border-radius:14px;padding:14px 16px;}
.pdh-price-top-row{display:flex;align-items:center;gap:8px;margin-bottom:2px;}
.pdh-discount{color:#ef4444;font-weight:900;font-size:21px;}
.pdh-orig-price{color:#94a3b8;text-decoration:line-through;font-size:14px;}
.pdh-final-row{display:flex;align-items:center;gap:6px;}
.pdh-won-badge{background:#0f172a;color:#fff;font-size:11px;font-weight:800;border-radius:6px;padding:2px 6px;}
.pdh-final-price{font-size:29px;font-weight:900;color:#0f172a;letter-spacing:-.2px;}
/* ===== 혜택 안내 (사진 참고) — 쿠폰 티켓 / 보증 배너 / 가입 쿠폰팩 / 카드할인 [v41 재정리] ===== */
.pdh-coupon-ticket-wrap{position:relative;margin-bottom:12px;}
.pdh-coupon-ticket{width:100%;display:flex;align-items:center;gap:10px;border:1.5px solid #c7d2fe;background:linear-gradient(90deg,#eef2ff,#f8faff);border-radius:12px;padding:13px 16px;cursor:pointer;font-size:14px;font-weight:800;color:#4338ca;transition:.15s;position:relative;}
.pdh-coupon-ticket:hover{border-color:#818cf8;background:linear-gradient(90deg,#e0e7ff,#eef2ff);}
.pdh-ticket-ic{font-size:16px;line-height:1;}
.pdh-ticket-label{flex:1;text-align:left;letter-spacing:-.2px;}
.pdh-ticket-count{background:#4f46e5;color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:2px 9px;flex:none;}
.pdh-ticket-arrow{width:20px;text-align:center;color:#818cf8;font-size:12px;flex:none;}
.pdh-coupon-ticket[aria-expanded="true"] .pdh-ticket-arrow{transform:rotate(180deg);}
.pdh-coupon-drop{position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 14px 34px rgba(15,23,42,.16);z-index:60;padding:8px;max-height:300px;overflow-y:auto;}
.pdh-coupon-item{display:flex;align-items:center;gap:12px;padding:12px 12px;border-bottom:1px dashed #f1f5f9;}
.pdh-coupon-item:last-of-type{border-bottom:none;}
.pdh-coupon-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;}
.pdh-coupon-info b{font-size:13.5px;color:#1e293b;font-weight:800;line-height:1.4;word-break:keep-all;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;}
.pdh-coupon-meta{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;}
.pdh-coupon-val{font-size:12.5px;font-weight:900;color:#4338ca;background:#eef2ff;border-radius:99px;padding:2px 9px;}
.pdh-coupon-min,.pdh-coupon-until{font-size:11.5px;color:#94a3b8;font-weight:600;}
.pdh-coupon-btn{border:none;background:#4f46e5;color:#fff;border-radius:9px;padding:8px 14px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;transition:.15s;flex:none;}
.pdh-coupon-btn:hover{background:#4338ca;}
.pdh-coupon-btn.held{background:#f1f5f9;color:#64748b;cursor:default;}
.pdh-coupon-more{display:block;text-align:center;font-size:12px;font-weight:700;color:#64748b;padding:9px 0 5px;text-decoration:none;}
.pdh-coupon-more:hover{color:#4338ca;}
.pdh-warranty-banner{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:12px;padding:13px 16px;margin-bottom:8px;text-decoration:none;transition:.15s;}
.pdh-warranty-banner:hover{filter:brightness(1.2);}
.pdh-warranty-ic{font-size:16px;}
.pdh-warranty-text{flex:1;font-size:13.5px;font-weight:800;color:#fff;letter-spacing:.1px;word-break:keep-all;}
.pdh-warranty-arrow{color:#34d399;font-weight:900;font-size:16px;}
.pdh-signup-pack{display:flex;align-items:center;gap:8px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:9px 12px;margin-bottom:12px;text-decoration:none;transition:.15s;}
.pdh-signup-pack:hover{background:#ede9fe;}
.pdh-pack-ic{font-size:14px;}
.pdh-pack-text{flex:1;font-size:12.5px;color:#5b21b6;font-weight:600;line-height:1.6;word-break:keep-all;}
.pdh-pack-text b{font-weight:800;}
.pdh-pack-arrow{color:#8b5cf6;font-weight:900;}
.pdh-card-benefits{border:1px solid #eef1f6;border-radius:12px;margin-bottom:16px;overflow:hidden;}
.pdh-card-row{display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:12.5px;}
.pdh-card-row + .pdh-card-row{border-top:1px solid #f1f5f9;}
.pdh-card-label{flex-shrink:0;font-weight:800;color:#334155;}
.pdh-card-text{color:#475569;font-weight:600;line-height:1.6;word-break:keep-all;}
/* ===== 품절 배너 — 재고 없으면 구매·결제 진행 불가 + 재고 요청 안내 ===== */
.pdh-oos-banner{background:#fef2f2;border:1.5px solid #fecaca;border-radius:14px;padding:14px 16px;margin-bottom:16px;}
.pdh-oos-banner .pdh-oos-title{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:800;color:#b91c1c;margin:0 0 4px;}
.pdh-oos-banner .pdh-oos-sub{font-size:13px;color:#7f1d1d;margin:0 0 10px;line-height:1.7;word-break:keep-all;}
.pdh-restock-row{display:flex;gap:8px;margin-top:8px;}
.pdh-restock-row input{width:80px;padding:9px 10px;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;}
.btn-restock{border:none;background:linear-gradient(135deg,#2563eb,#4f46e5);border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;color:#fff;cursor:pointer;flex:1;transition:.15s;}
.btn-restock:hover{filter:brightness(1.07);}
.pdh-option-row{margin-bottom:16px;}
.pdh-option-row label{display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;}
.pdh-option-row select{width:100%;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;background:#fff;}
.pdh-option-hint{font-size:12.5px;color:#64748b;margin:6px 0 0;line-height:1.6;word-break:keep-all;}
.pdh-stock-ok{color:#0d9488;font-size:13px;font-weight:700;margin:6px 0 0;}
.pdh-stock-warn{color:#ef4444;font-size:13px;font-weight:700;margin:6px 0 0;display:none;}
.pdh-dot-info{font-size:13px;color:#475569;margin:0 0 6px;}
.pdh-qty-row{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
.pdh-qty-row > label{font-size:13px;color:#64748b;font-weight:600;}
.pdh-qty-stepper{display:flex;align-items:center;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fff;}
.pdh-qty-stepper button{width:34px;height:36px;border:none;background:#f8fafc;font-size:16px;cursor:pointer;color:#475569;font-weight:700;}
.pdh-qty-stepper button:hover{background:#eef2f7;}
.pdh-qty-stepper input{width:46px;height:36px;border:none;text-align:center;font-size:14px;font-weight:700;-moz-appearance:textfield;}
.pdh-total-row{display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid #eef1f6;border-radius:12px;padding:12px 14px;font-size:13px;color:#64748b;margin-bottom:14px;}
.pdh-total-row strong{font-size:19px;font-weight:900;color:#0f172a;}
.pdh-action-row{display:flex;align-items:center;gap:8px;}
.pdh-icon-btn{width:46px;height:46px;border-radius:12px;border:1px solid #e2e8f0;background:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;color:#64748b;cursor:pointer;transition:.15s;flex-shrink:0;}
.pdh-icon-btn:hover{border-color:#cbd5e1;background:#f8fafc;}
.pdh-icon-btn.active{color:#ef4444;border-color:#fca5a5;background:#fef2f2;}
.pdh-buy-btn{flex:1;height:46px;border:none;border-radius:12px;background:linear-gradient(135deg,#2563eb,#4f46e5);color:#fff;font-weight:800;font-size:15px;cursor:pointer;transition:.15s;box-shadow:0 8px 18px rgba(37,99,235,.28);letter-spacing:.1px;word-break:keep-all;}
.pdh-buy-btn:hover{filter:brightness(1.06);box-shadow:0 10px 22px rgba(37,99,235,.35);}
.pdh-buy-btn:disabled{opacity:.55;cursor:not-allowed;box-shadow:none;filter:grayscale(.3);}
/* ===== 혜택 안내 박스 (스카이톤) ===== */
.pdh-perks{display:flex;flex-direction:column;gap:7px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:13px 15px;margin-top:14px;font-size:13px;color:#075985;font-weight:700;line-height:1.7;word-break:keep-all;}
.pdh-perks .ic{margin-right:4px;}
/* ===== 방문 예약 버튼 & 모달 ===== */
.pdh-reserve-btn{flex:1;height:46px;border:none;border-radius:12px;background:#fff;color:#2563eb;font-weight:700;font-size:15px;cursor:pointer;transition:.15s;border:1.5px solid #2563eb;white-space:nowrap;}
.pdh-reserve-btn:hover{background:#dbeafe;}
@media(max-width:768px){.pdh-reserve-btn{font-size:13px;padding:0 10px;}}
.resv-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9500;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(2px);}
.resv-modal-overlay.open{display:flex;}
.resv-modal-box{width:100%;max-width:480px;max-height:90vh;background:#fff;border-radius:20px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:resvIn .22s ease;}
@keyframes resvIn{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
.resv-modal-head{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 12px;}
.resv-modal-title{font-size:19px;font-weight:800;color:#0f172a;margin:0;}
.resv-modal-close{background:none;border:none;font-size:26px;color:#94a3b8;cursor:pointer;line-height:1;padding:0 4px;}
.resv-modal-product{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 24px;padding:10px 14px;background:#f1f5f9;border-radius:10px;}
.resv-modal-product-name{font-size:13px;font-weight:700;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.resv-modal-product-price{font-size:14px;font-weight:800;color:#2563eb;white-space:nowrap;}
.resv-modal-scroll{padding:16px 24px 8px;overflow-y:auto;}
.resv-guide{font-size:12px;color:#64748b;line-height:1.7;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:10px 14px;margin-bottom:14px;}
.resv-guide b{color:#0369a1;}
.resv-field-row{display:flex;gap:10px;margin-bottom:12px;}
.resv-field-2col{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.resv-field label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;}
.resv-field .req{color:#ef4444;}
.resv-field input,.resv-field select,.resv-field textarea{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;transition:border-color .15s,box-shadow .15s;background:#fff;}
.resv-field input:focus,.resv-field select:focus,.resv-field textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.resv-field textarea{resize:vertical;}
.resv-qty-stepper{display:flex;align-items:center;gap:0;border:1.5px solid #e2e8f0;border-radius:10px;overflow:hidden;}
.resv-qty-stepper button{width:40px;height:40px;background:#f8fafc;border:none;font-size:17px;font-weight:800;color:#1e293b;cursor:pointer;}
.resv-qty-stepper button:hover{background:#e2e8f0;}
.resv-qty-stepper input{flex:1;text-align:center;border:none;border-left:1.5px solid #e2e8f0;border-right:1.5px solid #e2e8f0;font-size:14px;font-weight:700;padding:0;border-radius:0;box-shadow:none!important;}
.resv-total-row{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#f8fafc;border-radius:10px;margin:6px 0 10px;}
.resv-total-row span{font-size:12px;color:#64748b;font-weight:600;}
.resv-total-row strong{font-size:17px;font-weight:900;color:#0f172a;}
.resv-msg{font-size:12px;min-height:16px;margin:0 0 6px;font-weight:600;}
.resv-msg.error{color:#ef4444;}
.resv-msg.success{color:#16a34a;}
.resv-modal-actions{display:flex;gap:10px;justify-content:flex-end;padding:12px 24px 20px;border-top:1px solid #f1f5f9;}
.btn-resv-submit{background:#2563eb;color:#fff;border:none;padding:11px 24px;border-radius:10px;font-weight:800;font-size:14px;cursor:pointer;transition:background .15s;}
.btn-resv-submit:hover{background:#1d4ed8;}
.btn-resv-submit:disabled{opacity:.6;cursor:not-allowed;}
.resv-done-box{width:100%;max-width:360px;background:#fff;border-radius:20px;padding:32px 28px;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:resvIn .22s ease;}
.resv-done-icon{font-size:44px;margin-bottom:10px;}
.resv-done-title{font-size:20px;font-weight:900;color:#0f172a;margin:0 0 14px;}
.resv-done-desc{font-size:12px;color:#64748b;margin:0;}
.resv-done-no{font-size:22px;font-weight:900;color:#2563eb;letter-spacing:1px;margin:4px 0 14px;}
.resv-done-sub{font-size:12px;color:#64748b;line-height:1.7;margin:0 0 20px;}
.resv-done-actions{display:flex;flex-direction:column;gap:8px;}
.btn-resv-primary{display:block;background:#0f172a;color:#fff;padding:12px;border-radius:10px;font-weight:800;font-size:14px;text-decoration:none;}
.btn-resv-primary:hover{background:#1e293b;}
.btn-resv-secondary{background:#f1f5f9;color:#334155;border:none;padding:12px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;}
@media(max-width:480px){
  .resv-modal-overlay{padding:0;align-items:flex-end;}
  .resv-modal-box{max-width:100%;border-radius:20px 20px 0 0;max-height:92vh;}
  .resv-field-2col{grid-template-columns:1fr;}
  .resv-modal-scroll{padding:12px 20px 8px;}
  .resv-modal-actions{padding:10px 20px calc(16px + env(safe-area-inset-bottom));}
}
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

/* [FIX] body가 스크롤 잠기는 동안 배경 스크롤 방지 */
body.tt-modal-lock{overflow:hidden;}

/* [FIX-핵심 1] z-index를 헤더(9000)보다 훨씬 높게. 기존 999는 헤더 밑으로 깔려서 모달 상단이 잘려 보였다.
   [FIX-핵심 2] 모달 박스를 head/scroll/actions 3단 flex 구조로 재설계.
   어떤 화면 높이에서도 취소/등록 버튼과 사진 미리보기가 화면 밖으로 밀려날 수 없게 만든다. */
.review-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:opacity .2s ease;z-index:20000;padding:24px 0;}
.review-modal-overlay.active{opacity:1;visibility:visible;}
.review-modal-box{background:#fff;border-radius:20px;width:92%;max-width:460px;position:relative;transform:translateY(16px) scale(.97);transition:transform .2s ease;box-shadow:0 24px 60px rgba(0,0,0,.25);max-height:min(88vh,88dvh);display:flex;flex-direction:column;overflow:hidden;}
.review-modal-overlay.active .review-modal-box{transform:translateY(0) scale(1);}
.review-modal-close{position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;z-index:2;}
.review-modal-head{flex-shrink:0;padding:32px 32px 0;}
.review-modal-title{font-size:19px;font-weight:800;margin-bottom:4px;}
.review-modal-sub{font-size:13px;color:#64748b;margin-bottom:18px;}
.review-modal-scroll{flex:1;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:0 32px;}
.star-rating{display:flex;flex-direction:row-reverse;gap:4px;margin-bottom:16px;}
.star-rating input{display:none;}
.star-rating label{font-size:30px;color:#e2e8f0;cursor:pointer;transition:color .12s,transform .12s;}
.star-rating input:checked ~ label,.star-rating label:hover,.star-rating label:hover ~ label{color:#fbbf24;}
.rv-modal-field-label{font-size:13px;font-weight:700;color:#334155;margin-bottom:10px;}
.rv-chip-group{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;}
.rv-chip-input{position:absolute;opacity:0;width:0;height:0;pointer-events:none;}
.rv-chip-label{display:inline-flex;align-items:center;gap:4px;padding:8px 14px;border:1.5px solid #e2e8f0;border-radius:999px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;user-select:none;transition:.15s;background:#fff;}
.rv-chip-label:hover{border-color:#c7d2fe;background:#f5f5ff;}
.rv-chip-input:checked + .rv-chip-label{background:linear-gradient(135deg,#6366f1,#8b5cf6);border-color:transparent;color:#fff;box-shadow:0 4px 10px rgba(99,102,241,.35);}
.review-modal-box textarea{width:100%;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;font-size:14px;resize:vertical;margin-bottom:18px;box-sizing:border-box;}

/* [FIX v49] 리뷰 모달 하단 짤림 — 모달 box(display:flex)의 자식은 <form>인데 form이
   block 요소라 flex 체인이 여기서 끊겼다. .review-modal-scroll이 높이 제한을 받지 못해
   내용(별점·칩·사진첨부·버튼)만큼 무한 확장되고, box의 overflow:hidden이 하단을 잘라버려
   취소/등록 버튼·사진 첨부칸이 화면에 안 나오던 것. form을 flex:1+min-height:0 열 컬럼으로
   만들어 head 고정 → 스크롤 영역 → 하단 버튼 footer 구조를 완성한다. */
.pd-review-form{display:flex;flex-direction:column;flex:1 1 auto;min-height:0;overflow:hidden;}

/* [FIX] 취소/등록 버튼 영역을 스크롤 밖 하단 고정 footer로 분리 */
.review-modal-actions{flex-shrink:0;display:flex;gap:10px;justify-content:flex-end;padding:16px 32px 24px;border-top:1px solid #f1f5f9;background:#fff;box-shadow:0 -8px 12px -10px rgba(15,23,42,.14);}
.btn-modal-cancel{background:#f1f5f9;color:#475569;border:none;padding:10px 20px;border-radius:999px;font-weight:600;cursor:pointer;}
.btn-modal-submit{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;padding:10px 24px;border-radius:999px;font-weight:700;cursor:pointer;box-shadow:0 6px 16px rgba(99,102,241,.35);transition:transform .12s ease;}
.btn-modal-submit:hover{transform:translateY(-1px);}
/* [FIX v49] 모바일 — 리뷰 모달 여백 축소 + 하단 safe-area(노치 홈바) 확보 */
@media(max-width:480px){
  .review-modal-overlay{padding:12px 0;}
  .review-modal-box{width:100%;max-width:100%;max-height:min(92vh,92dvh);border-radius:18px;}
  .review-modal-head{padding:24px 20px 0;}
  .review-modal-scroll{padding:0 20px;}
  .review-modal-actions{padding:12px 20px calc(16px + env(safe-area-inset-bottom));}
  .review-modal-close{top:10px;right:12px;}
}
.pd-review-item{display:flex;flex-direction:column;gap:8px;padding:20px 0;border-bottom:1px solid #f1f5f9;}
.pd-review-item-top{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.pd-review-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pd-review-user{font-size:14px;font-weight:700;color:#1e293b;}
.pd-review-date{font-size:12px;color:#94a3b8;}
.pd-review-stars{font-size:15px;color:#fbbf24;letter-spacing:1px;}
.pd-review-content{font-size:14px;line-height:1.7;color:#334155;margin:4px 0;word-break:break-word;}
.pd-review-tag-row{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 4px;}
.pd-review-tag-chip{background:#f0f0ff;color:#6366f1;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;}
.pd-review-photo-row{display:flex;gap:10px;margin:10px 0;flex-wrap:wrap;}
.pd-review-photo-row img{width:96px;height:96px;object-fit:cover;border-radius:12px;cursor:pointer;border:1px solid #e2e8f0;transition:transform .15s,box-shadow .15s;}
.pd-review-photo-row img:hover{transform:scale(1.05);box-shadow:0 4px 12px rgba(0,0,0,.12);}
.pd-review-badge-row{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0;}
.pd-review-badge{border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;}
.pd-review-badge.visit{background:#eff6ff;color:#2563eb;}
.pd-review-badge.extra{background:#f0fdf4;color:#16a34a;}
.pd-review-badge.hot{background:#fef2f2;color:#ef4444;}
.pd-review-vehicle{font-size:12px;color:#64748b;font-weight:600;}
.pd-review-store-box{font-size:12px;color:#475569;background:#f8fafc;border:1px solid #eef1f6;border-radius:10px;padding:8px 12px;margin:6px 0;}
.pd-flash-msg{padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:14px;}
.pd-flash-msg.success{background:#ecfdf5;color:#047857;}
.pd-flash-msg.error{background:#fef2f2;color:#b91c1c;}
/* [FIX] 리뷰 작성 완료 후 돌아왔을 때 시선을 끌기 위한 짧은 펄스 강조 효과 */
@keyframes pdFlashPulse{0%{box-shadow:0 0 0 0 rgba(99,102,241,.45);}100%{box-shadow:0 0 0 12px rgba(99,102,241,0);}}
.pd-flash-msg.pd-flash-pulse{animation:pdFlashPulse 1s ease-out;}
.pd-desc-html{font-size:15px;line-height:1.75;color:#334155;word-break:break-word;margin-bottom:20px;}
.pd-desc-html a{color:#4338ca;text-decoration:underline;}
.pd-desc-html ul,.pd-desc-html ol{padding-left:20px;margin:10px 0;}
.pd-desc-html blockquote{margin:12px 0;padding:10px 16px;border-left:3px solid #cbd5e1;background:#f8fafc;color:#475569;}
.pd-desc-html h3,.pd-desc-html h4{margin:16px 0 8px;color:#0f172a;}
.pd-detail-images{margin-top:24px;display:flex;flex-direction:column;}
.pd-detail-images img{display:block;width:100%;height:auto;}
.pd-tabs{max-width:none;margin:28px 0 0;padding:0;display:flex;gap:8px;border-bottom:1px solid #e2e8f0;overflow-x:auto;}
.pd-tab-btn{background:none;border:none;padding:12px 18px;font-size:15px;font-weight:700;color:#94a3b8;cursor:pointer;border-bottom:2px solid transparent;}
.pd-tab-btn.active{color:#0f172a;border-bottom-color:#0f172a;}
.pd-tab-panel{max-width:none;margin:0;padding:24px 0 60px;display:none;}
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
.pd-review-vehicle{font-size:12px;color:#64748b;font-weight:600;}
.pd-review-badge-row{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 4px;}
.pd-review-badge{border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;}
.pd-review-badge.visit{background:#eff6ff;color:#2563eb;}
.pd-review-badge.extra{background:#f0fdf4;color:#16a34a;}
.pd-review-badge.hot{background:#fef2f2;color:#ef4444;}
.pd-review-store-box{font-size:12px;color:#475569;background:#f8fafc;border:1px solid #eef1f6;border-radius:10px;padding:8px 12px;margin:4px 0;}
.pd-review-photo-row{display:flex;gap:8px;margin:8px 0;flex-wrap:wrap;}
.pd-review-photo-row img{width:72px;height:72px;object-fit:cover;border-radius:10px;cursor:pointer;}
.pd-review-helpful-row{display:flex;align-items:center;gap:8px;margin-top:6px;}
.btn-review-helpful{display:inline-flex;align-items:center;gap:6px;border:1px solid #e2e8f0;background:#fff;border-radius:999px;padding:6px 14px;font-size:13px;font-weight:700;color:#64748b;cursor:pointer;transition:.15s;}
.btn-review-helpful:hover{border-color:#c7d2fe;background:#f5f5ff;}
.btn-review-helpful.active{background:#eef2ff;border-color:#6366f1;color:#4338ca;}
/* [FIX] JS에서 사용 중인데 정의가 빠져 있던 리뷰 하이라이트 애니메이션을 추가 */
@keyframes pdReviewHighlight{0%{background:#eef2ff;}100%{background:transparent;}}
.pd-review-highlight{animation:pdReviewHighlight 2s ease;border-radius:12px;}
.rv-visit-toggle-row{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.rv-store-select-wrap{margin-bottom:16px;}
.rv-store-select-wrap select{width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;}
.rv-vehicle-input-wrap{margin-bottom:16px;}
.rv-vehicle-input-wrap input{width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;}
.rv-photo-upload-wrap{margin-bottom:18px;}
.rv-photo-upload-wrap input[type=file]{font-size:13px;}
.rv-photo-count-hint{font-size:12px;color:#94a3b8;margin-top:6px;}
.rv-photo-preview{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;}
.rv-photo-preview .rv-photo-thumb{width:60px;height:60px;}
.rv-photo-preview img{width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;display:block;}
</style>

<main class="tt-main">
<div class="pdh-wrap">
  <div class="pd-breadcrumb" style="margin-bottom:16px;">
    <a href="<?= BASE_URL ?>/product-list.php">타이어 목록</a> &gt; <?= h($product['name']) ?>
  </div>

  <div class="pd-layout">

    <!-- 좌측: 상품 이미지 갤러리 -->
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
            <div class="pdh-service-item"><span class="ic">🚚</span> 빠른출고</div>
            <div class="pdh-service-item"><span class="ic">🔧</span> 무료장착</div>
          </div>
          <a class="pdh-badge-consult" href="tel:1877-9778">📞 문의전화 걸기</a>
        </div>
      </div>

      <?php if (count($mainImages) > 1): ?>
      <div class="pdh-thumb-strip" id="pdThumbStrip">
        <?php foreach ($mainImages as $i => $imgUrl): ?>
          <img src="<?= h($imgUrl) ?>" class="<?= $i === 0 ? 'active' : '' ?>" data-src="<?= h($imgUrl) ?>" alt="이미지 <?= $i + 1 ?>">
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- 우측: 구매 패널 (카드형 · 스크롤을 따라 내려오는 스티키) -->
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

      <div class="pdh-price-box" data-price-original="<?= (int)$product['price_original'] ?>" data-display-price="<?= $displayPrice ?>">
        <?php if ($isBizPrice): ?>
        <div class="pdh-biz-price-notice">
          <span class="pdh-biz-badge">🏢 사업자 우대가 적용됨</span>
        </div>
        <?php endif; ?>
        <div class="pdh-price-top-row">
          <?php if ($discountPct > 0): ?>
            <span class="pdh-discount" id="pdDiscountPct"><?= $discountPct ?>%</span>
            <span class="pdh-orig-price"><?= number_format($isBizPrice && !empty($product['biz_price_original']) ? (int)$product['biz_price_original'] : (int)$product['price_original']) ?>원</span>
          <?php endif; ?>
        </div>
        <div class="pdh-final-row">
          <span class="pdh-won-badge">W</span>
          <span class="pdh-final-price" id="pdPriceNow"><?= number_format($displayPrice) ?>원</span>
        </div>
        <?php if ($isBizPrice && !empty($product['biz_supply_price'])): ?>
        <div class="pdh-biz-supply-info">
          <span>사업자 공급가: <?= number_format((int)$product['biz_supply_price']) ?>원</span>
        </div>
        <?php endif; ?>
      </div>

      <?php /* ===== 혜택 안내 — 쿠폰 받기 / 보증 / 가입 쿠폰팩 / 카드할인 (사진 참고) ===== */ ?>
      <?php if (!empty($downloadableCoupons)): ?>
      <div class="pdh-coupon-ticket-wrap">
        <button type="button" class="pdh-coupon-ticket" id="pdCouponTicket" aria-expanded="false">
          <span class="pdh-ticket-ic">🎫</span>
          <span class="pdh-ticket-label">할인 쿠폰 받기</span>
          <span class="pdh-ticket-count"><?= count($downloadableCoupons) ?>장</span>
          <span class="pdh-ticket-arrow">▾</span>
        </button>
        <div class="pdh-coupon-drop" id="pdCouponDrop" hidden>
          <?php foreach ($downloadableCoupons as $dc): ?>
          <div class="pdh-coupon-item" data-coupon-id="<?= (int)$dc['id'] ?>">
            <div class="pdh-coupon-info">
              <b><?= h($dc['name']) ?></b>
              <div class="pdh-coupon-meta">
                <span class="pdh-coupon-val"><?= h(coupon_discount_label($dc)) ?></span>
                <?php if ((int)$dc['min_order_amount'] > 0): ?><span class="pdh-coupon-min"><?= number_format((int)$dc['min_order_amount']) ?>원 이상 구매 시</span><?php endif; ?>
                <?php if (!empty($dc['valid_until'])): ?><span class="pdh-coupon-until"><?= h(date('m/d', strtotime($dc['valid_until']))) ?>까지</span><?php endif; ?>
              </div>
            </div>
            <?php if (!empty($dc['already_held'])): ?>
            <button type="button" class="pdh-coupon-btn held" disabled>✓ 보유중</button>
            <?php else: ?>
            <button type="button" class="pdh-coupon-btn" data-claim="<?= (int)$dc['id'] ?>">받기 ↓</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <a href="<?= BASE_URL ?>/mypage.php<?= Auth::isLoggedIn() ? '#coupons' : '' ?>" class="pdh-coupon-more">내 쿠폰함 보기 ›</a>
        </div>
      </div>
      <?php endif; ?>

      <a class="pdh-warranty-banner" href="<?= BASE_URL ?>/guide.php#tire">
        <span class="pdh-warranty-ic">💎</span>
        <span class="pdh-warranty-text"><?= h($warrantyBannerText) ?></span>
        <span class="pdh-warranty-arrow">›</span>
      </a>

      <?php if ($packCount > 0): ?>
      <a class="pdh-signup-pack" href="<?= Auth::isLoggedIn() ? BASE_URL . '/mypage.php#coupons' : BASE_URL . '/login.php' ?>">
        <span class="pdh-pack-ic">🎁</span>
        <span class="pdh-pack-text"><b><?= h($signupPackText) ?></b> — 쿠폰 <?= (int)$packCount ?>장<?= $packWon > 0 ? ' (최대 ' . number_format($packWon) . '원 할인)' : '' ?></span>
        <span class="pdh-pack-arrow">›</span>
      </a>
      <?php endif; ?>

      <?php if (!empty($cardBenefitRows)): ?>
      <div class="pdh-card-benefits">
        <?php foreach ($cardBenefitRows as $br): ?>
        <div class="pdh-card-row">
          <span class="pdh-card-label"><?= h($br['label']) ?></span>
          <span class="pdh-card-text"><?= h($br['text']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php /* ===== 품절 배너 — 재고 없으면 구매·결제 진행 불가 (소비자·사업자 공통) ===== */ ?>
      <?php if ($outOfStock): ?>
      <div class="pdh-oos-banner">
        <p class="pdh-oos-title">📦 재고가 없습니다.</p>
        <p class="pdh-oos-sub">재고 요청을 하시기 바랍니다 — 입고되면 안내드립니다. 재고가 확보되면 바로구매·결제가 가능합니다.</p>
        <div class="pdh-restock-row">
          <input type="number" id="pdRestockQty" placeholder="수량" min="1" value="1">
          <button type="button" class="btn-restock" id="pdRestockBtn" data-product-id="<?= (int)$productId ?>">📥 재고 요청하기</button>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($options) && $userType === 'business'): ?>
      <div class="pdh-option-row">
        <label for="pdOptionSelect">DOT 옵션 선택 (사업자 전용 · 선택하지 않아도 구매 가능합니다)</label>
        <select id="pdOptionSelect">
          <option value="">기본옵션 (선택 안 함 · <?= number_format($displayPrice) ?>원)</option>
          <?php foreach ($options as $opt):
              $dotYear = '';
              if (preg_match('/(\d{4})$/', $opt['dot_code'] ?? '', $m)) {
                  $dotYear = $m[1];
              } elseif (preg_match('/(\d{2})$/', $opt['dot_code'] ?? '', $m)) {
                  $dotYear = '20' . $m[1];
              }
              $optDisplayPrice = get_display_price((int)$opt['price_sale'], 0, $userType);
          ?>
            <option value="<?= (int)$opt['id'] ?>"
                    data-price="<?= $optDisplayPrice ?>"
                    data-stock="<?= (int)$opt['stock_qty'] ?>">
              DOT <?= h($opt['dot_code']) ?><?= $dotYear ? ' (' . h($dotYear) . ')' : '' ?> (재고 <?= (int)$opt['stock_qty'] ?>개) - <?= number_format($optDisplayPrice) ?>원
            </option>
          <?php endforeach; ?>
        </select>
        <p class="pdh-option-hint">옵션을 선택하지 않으면 기본 상품가로 결제가 진행됩니다.</p>
        <p class="pdh-stock-warn" id="pdStockWarn">품절된 옵션입니다.</p>
      </div>
      <?php elseif (!empty($options) && $userType === 'personal'): ?>
      <!-- 일반회원에게는 DOT 옵션 선택을 숨기고 기본 가격으로만 결제 -->
      <div class="pdh-option-row">
        <input type="hidden" id="pdOptionSelect" value="">
        <p class="pdh-option-hint">기본 상품가로 결제됩니다.</p>
      </div>
      <?php else: ?>
      <div class="pdh-option-row">
        <?php /* [v46] 소비자(비사업자)에게는 DOT 코드·재고 수량을 노출하지 않음 — 가격 중심 화면 */ ?>
        <p class="pdh-option-hint">기본 상품가로 결제가 진행됩니다.</p>
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
        <button type="button" class="pdh-icon-btn" id="pdAddCartBtn" aria-label="장바구니" <?= $outOfStock ? 'disabled' : '' ?>>🛒</button>
        <?php /* [v45] 예약·배송 명칭 정리 — 일반회원: 🗓️ 매장예약 + 🚚 일반배송 / 사업자: 📦 택배배송 */ ?>
        <?php if ($userType !== 'business'): ?>
        <button type="button" class="pdh-reserve-btn" id="pdReserveBtn">🗓️ 매장예약</button>
        <?php endif; ?>
        <button type="button" class="pdh-buy-btn" id="pdBuyNowBtn" <?= $outOfStock ? 'disabled' : '' ?>><?= $outOfStock ? '재고 없음' : ($userType === 'business' ? '📦 택배배송' : '🚚 일반배송') ?></button>
      </div>

      <div class="pdh-perks">
        <?php if ($userType === 'business'): ?>
        <span><span class="ic">🎁</span>쿠폰 할인가 반영 · DOT 옵션 재고 기준 실시간 가격 갱신</span>
        <?php else: ?>
        <span><span class="ic">🎁</span>쿠폰 할인가 반영 · 매장 방문 시 무료 장착</span>
        <?php endif; ?>
        <span><span class="ic">🔧</span>무료 장착 · 휠밸런스 포함 (매장 방문 시)</span>
        <span><span class="ic">🚚</span>오늘 주문 — 제휴 매장 빠른 출고</span>
      </div>
    </div>

  <div class="pd-content">

  <div class="pd-tabs">
    <button type="button" class="pd-tab-btn active" data-tab="info">상품 상세정보</button>
    <button type="button" class="pd-tab-btn" data-tab="shipping">배송/설치</button>
    <button type="button" class="pd-tab-btn" data-tab="review">리뷰 (<?= (int)$product['review_count'] ?>)</button>
  </div>

  <div class="pd-tab-panel active" data-panel="info">
    <?php if (!empty($specRows)): ?>
      <div class="pd-spec-box">
        <h3>상품 상세정보</h3>
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
      <p style="color:var(--gray4);">등록된 상품 설명이 없습니다.</p>
    <?php endif; ?>

    <?php if (!empty($detailImages)): ?>
      <div class="pd-detail-images">
        <?php foreach ($detailImages as $imgUrl): ?>
          <img src="<?= h($imgUrl) ?>" alt="상품 상세이미지" loading="lazy">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="pd-tab-panel" data-panel="shipping">
    <div class="pd-shipping-box">
      <h4>배송 / 설치 안내</h4>
      <ul>
        <li>제휴 매장 배송 (직접, 정품 등 대부분 지역은 물류 5,500원 추가 발생)</li>
        <li>출고 후 배송 완료까지 약 1~3일 소요</li>
        <li>출고일 후 최대 3일 이내에 배송 (택배 발송 준비되는 대로 매장 안내 문자)</li>
        <li>배송완료 이후 확인 후 교환/환불 처리</li>
        <li>배송완료 이후 이틀 2일 이내 설치 안내 (직접, 매장측 협의하여 이용 부탁드립니다(정확된 감소))</li>
      </ul>
      <h4>무료 / 유료 설치 안내</h4>
      <p class="pd-shipping-sub">무료 / 유료 설치 관련 유의</p>
      <ul>
        <li>제휴 매장 협의, 배송 일정 등 사정에 따라 배송 후 매장에서 개별 연락</li>
        <li>설치예약이 완료된 이후 상품 안내 후 14일 이내에 실제 방문 완료</li>
        <li>재고소진된 옵션 및 규격 부장하고 취소되어 안내드릴 배송/설치 이용 불가 사유(직접 물류센터쪽에 문의 부탁드립니다 11,000원 추가 발생)</li>
        <li>제외사항에 문의부탁드립니다 (자세한 문의는 고객센터로 연결 부탁드립니다)</li>
      </ul>
      <p class="pd-shipping-sub">무료 / 유료 추가되는 사항</p>
      <ul>
        <li>휠 정렬이 필요한 상황 이용시 별도 추가 요금</li>
        <li>공기압센서 교체나 이상시 상품 부담 관련되어 형태 별도 요금</li>
        <li>공기압센서와 관련 추가 부착사항에 상품 관리되어 편의를 위해 안내 요금</li>
        <li>배송 완료(포함 총 14일 이내)이 방문업체 요금</li>
      </ul>
      <h4>취소/환불 규정</h4>
      <ul>
        <li>결제완료 후 아직 배송 시작 전이면 전체 취소가 가능합니다. (제휴점 자체 배송관련 6일 이내, 폐 갱신금액 20% 이상 차감 발생)</li>
        <li>규격변경으로인해 교환(제휴 배송 등 확인)에 이용하시면 부담해드립니다.</li>
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
        SELECT r.id, r.user_id, r.rating, r.content, r.option_tags, r.created_at,
               r.vehicle_model, r.visit_type, r.extra_service, r.store_id, r.helpful_count,
               u.name AS user_name,
               s.name AS store_name, s.address AS store_address
        FROM tt_reviews r
        JOIN tt_users u ON u.id = r.user_id
        LEFT JOIN tt_stores s ON s.id = r.store_id
        WHERE r.product_id = :pid
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $rvStmt->execute(['pid' => $productId]);
    $productReviews = $rvStmt->fetchAll(PDO::FETCH_ASSOC);
    $currentUid = Auth::isLoggedIn() ? (int)Auth::currentUserId() : 0;

    $myHelpfulSet = [];
    if ($currentUid && !empty($productReviews)) {
        $rIds = array_column($productReviews, 'id');
        $ph = implode(',', array_fill(0, count($rIds), '?'));
        $hStmt = $pdo->prepare("SELECT review_id FROM tt_review_helpful WHERE user_id = ? AND review_id IN ($ph)");
        $hStmt->execute(array_merge([$currentUid], $rIds));
        $myHelpfulSet = array_flip($hStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $reviewPhotosByReview = [];
    if (!empty($productReviews)) {
        $rIds2 = array_column($productReviews, 'id');
        $ph2 = implode(',', array_fill(0, count($rIds2), '?'));
        $pStmt = $pdo->prepare("SELECT review_id, image_url FROM tt_review_photos WHERE review_id IN ($ph2) ORDER BY sort_order ASC");
        $pStmt->execute($rIds2);
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $prow) {
            $reviewPhotosByReview[$prow['review_id']][] = $prow['image_url'];
        }
    }

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
        <p style="color:var(--gray4);">구매확정일로부터 7일 이내에만 후기를 작성하실 수 있습니다. (이미 후기를 작성했거나 후기작성기한이 지난 경우 리뷰 작성이 불가능합니다)</p>
    <?php endif; ?>

    <?php if (empty($productReviews)): ?>
        <p style="color:var(--gray4);">등록된 후기가 없습니다.</p>
    <?php else: ?>
        <div class="pd-review-list">
            <?php foreach ($productReviews as $rv):
                $rvTags   = review_parse_option_tags($rv['option_tags'] ?? null);
                $rvExtras = review_parse_extra_service($rv['extra_service'] ?? null);
                $isHot    = is_review_hot((int)$rv['helpful_count']);
            ?>
              <div class="pd-review-item" id="review-<?= (int)$rv['id'] ?>">

                    <div class="pd-review-item-top">
                        <div class="pd-review-meta">
                            <span class="pd-review-stars"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></span>
                            <span class="pd-review-user"><?= h(mb_substr($rv['user_name'], 0, 1) . str_repeat('*', max(0, mb_strlen($rv['user_name']) - 1))) ?></span>
                            <?php if (!empty($rv['vehicle_model'])): ?>
                                <span class="pd-review-vehicle"><?= h($rv['vehicle_model']) ?></span>
                            <?php endif; ?>
                            <span class="pd-review-date"><?= h(date('y.m.d', strtotime($rv['created_at']))) ?></span>
                        </div>
                        <?php if ($currentUid && $currentUid === (int)$rv['user_id']): ?>
                            <form method="post" action="<?= BASE_URL ?>/review-delete.php" onsubmit="return confirm('정말 이 후기를 삭제하시겠습니까? 삭제하면 되돌릴 수 없습니다.');" style="margin:0;">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                                <input type="hidden" name="return_to" value="product">
                                <button type="submit" class="btn-review-delete">삭제</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($isHot || !empty($rv['visit_type']) || !empty($rvExtras)): ?>
                        <div class="pd-review-badge-row">
                            <?php if ($isHot): ?><span class="pd-review-badge hot">🔥 HOT</span><?php endif; ?>
                            <?php if (!empty($rv['visit_type']) && isset($reviewVisitTypes[$rv['visit_type']])): ?>
                                <span class="pd-review-badge visit"><?= h($reviewVisitTypes[$rv['visit_type']]) ?></span>
                            <?php endif; ?>
                            <?php foreach ($rvExtras as $ex): ?>
                                <span class="pd-review-badge extra"><?= h($ex) ?> 추가</span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($rvTags)): ?>
                        <div class="pd-review-tag-row">
                            <?php foreach ($rvTags as $t): ?>
                                <span class="pd-review-tag-chip"><?= h($t) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <p class="pd-review-content"><?= nl2br(h($rv['content'])) ?></p>

                    <?php if (!empty($reviewPhotosByReview[$rv['id']])): ?>
                        <div class="pd-review-photo-row">
                            <?php foreach ($reviewPhotosByReview[$rv['id']] as $purl): ?>
                                <img src="<?= h(normalize_photo_url($purl)) ?>" alt="후기 사진" loading="lazy" onclick="window.open(this.src)">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($rv['visit_type'] === 'store' && !empty($rv['store_name'])): ?>
                        <div class="pd-review-store-box">
                            🏬 <?= h($rv['store_name']) ?><?= !empty($rv['store_address']) ? ' · ' . h($rv['store_address']) : '' ?>
                        </div>
                    <?php endif; ?>

                    <div class="pd-review-helpful-row">
                        <button type="button"
                                class="btn-review-helpful <?= isset($myHelpfulSet[$rv['id']]) ? 'active' : '' ?>"
                                data-review-id="<?= (int)$rv['id'] ?>">
                            👍 도움돼요 <span class="hp-count"><?= (int)$rv['helpful_count'] ?></span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canWriteReview): ?>
<div class="review-modal-overlay" id="reviewModalOverlay">
  <div class="review-modal-box">
    <button type="button" class="review-modal-close" id="reviewModalClose" aria-label="닫기">&times;</button>

    <div class="review-modal-head">
      <h3 class="review-modal-title">후기 작성하기</h3>
      <p class="review-modal-sub">솔직한 상품 후기를 남겨주시면 다른 고객님들께 큰 도움이 됩니다.</p>
    </div>

    <form method="post" action="<?= BASE_URL ?>/review-submit.php" class="pd-review-form" enctype="multipart/form-data" id="pdReviewForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
        <input type="hidden" name="return_to" value="product">

        <div class="review-modal-scroll">
            <div class="star-rating">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
                    <label for="star<?= $i ?>">★</label>
                <?php endfor; ?>
            </div>

            <?php if (!empty($reviewOptionTags)): ?>
            <div class="rv-modal-field-label">어떤 점이 좋았나요? (선택, 여러개 선택 가능)</div>
            <div class="rv-chip-group">
                <?php foreach ($reviewOptionTags as $idx => $tagLabel): ?>
                    <input type="checkbox" class="rv-chip-input" id="rvTag<?= $idx ?>" name="option_tags[]" value="<?= h($tagLabel) ?>">
                    <label class="rv-chip-label" for="rvTag<?= $idx ?>"><?= h($tagLabel) ?></label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="rv-modal-field-label">방문 형태</div>
            <div class="rv-visit-toggle-row">
                <?php foreach ($reviewVisitTypes as $vKey => $vLabel): ?>
                    <input type="radio" class="rv-chip-input" id="rvVisit_<?= h($vKey) ?>" name="visit_type" value="<?= h($vKey) ?>" <?= $vKey === 'store' ? 'checked' : '' ?>>
                    <label class="rv-chip-label" for="rvVisit_<?= h($vKey) ?>"><?= h($vLabel) ?></label>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($activeStores)): ?>
            <div class="rv-store-select-wrap" id="rvStoreSelectWrap">
                <div class="rv-modal-field-label">방문 매장</div>
                <select name="store_id" id="rvStoreSelect">
                    <?php foreach ($activeStores as $st): ?>
                        <option value="<?= (int)$st['id'] ?>"><?= h($st['name']) ?> (<?= h($st['address']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="rv-vehicle-input-wrap">
                <div class="rv-modal-field-label">탑승 차량 (선택)</div>
                <input type="text" name="vehicle_model" maxlength="60" placeholder="예) 소나타 ES">
            </div>

            <?php if (!empty($reviewExtraServices)): ?>
            <div class="rv-modal-field-label">추가로 진행한 서비스 (선택, 여러개 선택 가능)</div>
            <div class="rv-chip-group">
                <?php foreach ($reviewExtraServices as $eIdx => $eLabel): ?>
                    <input type="checkbox" class="rv-chip-input" id="rvExtra<?= $eIdx ?>" name="extra_service[]" value="<?= h($eLabel) ?>">
                    <label class="rv-chip-label" for="rvExtra<?= $eIdx ?>"><?= h($eLabel) ?></label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <textarea name="content" rows="4" maxlength="1000" placeholder="상품에 대한 솔직한 후기를 남겨주세요." required></textarea>

            <div class="rv-photo-upload-wrap">
                <div class="rv-modal-field-label">사진 첨부 (선택, 최대 3장 / 각각 5MB 이하)</div>
                <input type="file" name="photos[]" id="rvPhotoInput" accept="image/jpeg,image/png,image/webp" multiple>
                <p class="rv-photo-count-hint" id="rvPhotoCountHint"></p>
                <div class="rv-photo-preview" id="rvPhotoPreview"></div>
            </div>
        </div>

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

<!-- ===== 매장 예약 모달 ===== -->
<div class="resv-modal-overlay" id="resvModalOverlay">
  <div class="resv-modal-box">
    <div class="resv-modal-head">
      <h3 class="resv-modal-title">🗓️ 매장 예약</h3>
      <button type="button" class="resv-modal-close" id="resvModalClose" aria-label="닫기">&times;</button>
    </div>
    <div class="resv-modal-product">
      <span class="resv-modal-product-name"><?= h($product['name']) ?></span>
      <span class="resv-modal-product-price"><?= number_format($displayPrice) ?>원</span>
    </div>
    <div class="resv-modal-scroll">
      <p class="resv-guide">
        매장에 방문하셔서 상품을 확인하신 후 결제·장착하시는 예약입니다.<br>
        예약 접수 후 <b>결제창(쿠폰 적용 가능)</b>으로 이어집니다. 무통장입금/가상계좌/카드로 결제할 수 있습니다.
      </p>

      <div class="resv-field-row resv-field-2col">
        <div class="resv-field">
          <label>예약자 이름 <span class="req">*</span></label>
          <input type="text" id="resvName" value="<?= Auth::isLoggedIn() ? h(Auth::currentUserName()) : '' ?>" placeholder="이름" maxlength="30">
        </div>
        <div class="resv-field">
          <label>휴대폰 번호 <span class="req">*</span></label>
          <input type="tel" id="resvPhone" inputmode="numeric" placeholder="010-1234-5678" maxlength="13">
        </div>
      </div>

      <div class="resv-field-row">
        <div class="resv-field">
          <label>방문 목적 <span class="req">*</span></label>
          <select id="resvType">
            <?php $resvTypes = ['tire' => '타이어 장착·교체', 'repair' => '정비·수리', 'etc' => '기타 문의·상담']; $resvTypeDefault = 'tire'; ?>
            <?php foreach ($resvTypes as $tk => $tl): ?>
            <option value="<?= $tk ?>" <?= $tk === $resvTypeDefault ? 'selected' : '' ?>><?= $tl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="resv-field-row resv-field-2col">
        <div class="resv-field">
          <label>방문 예정일 <span class="req">*</span></label>
          <input type="date" id="resvDate" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+30 days')) ?>">
        </div>
        <div class="resv-field">
          <label>방문 시간 <span class="req">*</span> <small id="resvSlotHint" style="color:#64748b;font-weight:600;"></small></label>
          <select id="resvTime">
            <option value="">날짜를 선택하면 예약 가능한 시간이 표시됩니다</option>
          </select>
        </div>
      </div>

      <div class="resv-field-row resv-field-2col">
        <div class="resv-field">
          <label>수량 (타이어 개수)</label>
          <div class="resv-qty-stepper">
            <button type="button" id="resvQtyMinus">−</button>
            <input type="number" id="resvQty" value="4" min="1" max="20">
            <button type="button" id="resvQtyPlus">+</button>
          </div>
        </div>
        <div class="resv-field">
          <label>차량 정보 (선택)</label>
          <input type="text" id="resvCar" placeholder="예) 그랜저 IG / 12가3456" maxlength="50">
        </div>
      </div>

      <div class="resv-field-row">
        <div class="resv-field">
          <label>요청사항 (선택)</label>
          <?php /* [v45] 요청사항 칸 확대 — 모달 폭에 맞게 충분히 넓고 크게 */ ?>
          <textarea id="resvMemo" rows="4" maxlength="300" placeholder="예) 장착 희망, 알림 받고 싶은 시간 등" style="width:100%;min-height:104px;"></textarea>
        </div>
      </div>

      <div class="resv-total-row">
        <span>예상 금액 (현장 결제)</span>
        <strong id="resvTotalPrice"><?= number_format($displayPrice * 4) ?>원</strong>
      </div>

      <p class="resv-msg" id="resvMsg"></p>
    </div>
    <div class="resv-modal-actions">
      <button type="button" class="btn-modal-cancel" id="resvCancelBtn">취소</button>
      <button type="button" class="btn-resv-submit" id="resvSubmitBtn">예약하기</button>
    </div>
  </div>
</div>

<!-- 예약 완료 모달 -->
<div class="resv-modal-overlay" id="resvDoneOverlay">
  <div class="resv-done-box">
    <div class="resv-done-icon">✅</div>
    <h3 class="resv-done-title">예약이 접수되었습니다</h3>
    <p class="resv-done-desc">예약번호</p>
    <p class="resv-done-no" id="resvDoneNo"></p>
    <p class="resv-done-sub">영업일 1일 내 확인 연락을 드립니다.<br>담당자가 방문 일정을 확정해 드립니다.</p>
    <div class="resv-done-actions">
      <?php if (Auth::isLoggedIn()): ?>
      <a href="<?= BASE_URL ?>/mypage.php?tab=reservation#myreservations" class="btn-resv-primary">내 예약 확인하기</a>
      <?php endif; ?>
      <button type="button" class="btn-resv-secondary" id="resvDoneClose">닫기</button>
    </div>
  </div>
</div>

  </div><!-- /.pd-content -->

  </div>
</div>
</main>

<input type="hidden" id="csrfToken" value="<?= h($csrfToken) ?>">
<script>
const BASE_URL       = "<?= BASE_URL ?>";
const csrfToken      = document.getElementById('csrfToken').value;
const productId      = <?= (int)$productId ?>;
const hasOptions      = <?= !empty($options) ? 'true' : 'false' ?>;
const isLoggedIn      = <?= Auth::isLoggedIn() ? 'true' : 'false' ?>;
const autoOpenReview = <?= $autoOpenReview ? 'true' : 'false' ?>;
const isBusinessUser = <?= ($userType === 'business') ? 'true' : 'false' ?>;
const bizDiscountPct = <?= ($userType === 'business') ? get_business_discount_pct() : 0 ?>;
const bizDiscountFixed = <?= ($userType === 'business') ? get_business_discount()['amount'] : 0 ?>;
const bizDiscountType = "<?= ($userType === 'business') ? get_business_discount()['type'] : 'percent' ?>";
const OUT_OF_STOCK   = <?= $outOfStock ? 'true' : 'false' ?>; // 재고 없음 — 구매·결제 진행 불가

const qtyInput     = document.getElementById('pdQtyInput');
const totalEl      = document.getElementById('pdTotalPrice');
const priceNowEl   = document.getElementById('pdPriceNow');
const discountEl   = document.getElementById('pdDiscountPct');
const priceBoxEl   = document.querySelector('.pdh-price-box');
const optionSelect = document.getElementById('pdOptionSelect');
const stockWarn    = document.getElementById('pdStockWarn');
const buyBtn       = document.getElementById('pdBuyNowBtn');
const cartBtn      = document.getElementById('pdAddCartBtn');
const wishBtn      = document.getElementById('pdWishBtn');

/* ============================================================
   [NEW] 방문 예약 모달
   ============================================================ */
(function(){
  const openBtn    = document.getElementById('pdReserveBtn');
  const overlay    = document.getElementById('resvModalOverlay');
  const closeBtn   = document.getElementById('resvModalClose');
  const cancelBtn  = document.getElementById('resvCancelBtn');
  const nameIn     = document.getElementById('resvName');
  const phoneIn    = document.getElementById('resvPhone');
  const dateIn     = document.getElementById('resvDate');
  const timeIn     = document.getElementById('resvTime');
  const typeIn     = document.getElementById('resvType');
  const slotHintEl = document.getElementById('resvSlotHint');
  const carIn      = document.getElementById('resvCar');
  const memoIn     = document.getElementById('resvMemo');
  const qtyIn      = document.getElementById('resvQty');
  const qtyMinus   = document.getElementById('resvQtyMinus');
  const qtyPlus    = document.getElementById('resvQtyPlus');
  const totalEl2   = document.getElementById('resvTotalPrice');
  const msgEl      = document.getElementById('resvMsg');
  const submitBtn  = document.getElementById('resvSubmitBtn');
  const doneOverlay= document.getElementById('resvDoneOverlay');
  const doneNoEl   = document.getElementById('resvDoneNo');
  const doneClose  = document.getElementById('resvDoneClose');

  if (!openBtn || !overlay) return;

  const unitPrice = <?= (int)$displayPrice ?>;

  function open(){ overlay.classList.add('open'); document.body.style.overflow='hidden'; msgEl.textContent=''; msgEl.className='resv-msg'; loadSlots(); }
  function close(){ overlay.classList.remove('open'); document.body.style.overflow=''; }

  openBtn.addEventListener('click', open);
  closeBtn?.addEventListener('click', close);
  cancelBtn?.addEventListener('click', close);
  overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
  doneClose?.addEventListener('click', function(){ doneOverlay.classList.remove('open'); document.body.style.overflow=''; });
  doneOverlay?.addEventListener('click', function(e){ if (e.target === doneOverlay) { doneOverlay.classList.remove('open'); document.body.style.overflow=''; } });

  // 전화번호 자동 하이픈
  phoneIn?.addEventListener('input', function(){
    let v = this.value.replace(/[^0-9]/g, '');
    if (v.length < 4) this.value = v;
    else if (v.length < 8) this.value = v.slice(0,3) + '-' + v.slice(3);
    else if (v.length < 11) this.value = v.slice(0,3) + '-' + v.slice(3,7) + '-' + v.slice(7);
    else this.value = v.slice(0,3) + '-' + v.slice(3,7) + '-' + v.slice(7,11);
  });

  // 수량 스테퍼
  function clampQty(){ let q = parseInt(qtyIn.value,10)||1; q = Math.max(1, Math.min(20, q)); qtyIn.value = q; return q; }
  function updateTotal(){ const q = clampQty(); totalEl2.textContent = (unitPrice * q).toLocaleString('ko-KR') + '원'; }
  qtyMinus?.addEventListener('click', function(){ qtyIn.value = clampQty() - 1; updateTotal(); });
  qtyPlus?.addEventListener('click', function(){ qtyIn.value = clampQty() + 1; updateTotal(); });
  qtyIn?.addEventListener('input', updateTotal);
  updateTotal();

  // 방문 날짜·방문 목적 선택 → 예약 가능한(마감되지 않은) 시간대만 불러오기
  let slotSeq = 0;
  function resetTimeSelect(msg){
    timeIn.innerHTML = '';
    const opt = document.createElement('option');
    opt.value = ''; opt.textContent = msg;
    timeIn.appendChild(opt);
  }
  function loadSlots(){
    const d = dateIn.value;
    const t = typeIn ? typeIn.value : 'tire';
    if (!d) { resetTimeSelect('날짜를 선택하면 예약 가능한 시간이 표시됩니다'); if (slotHintEl) slotHintEl.textContent = ''; return; }
    const mySeq = ++slotSeq;
    resetTimeSelect('시간 조회 중...');
    if (slotHintEl) slotHintEl.textContent = '';
    fetch(BASE_URL + '/ajax-reservation-slots.php?date=' + encodeURIComponent(d) + '&type=' + encodeURIComponent(t), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json()).then(data => {
      if (mySeq !== slotSeq) return; // 그 사이 날짜/목적이 바뀌면 무시
      if (!data.ok) { resetTimeSelect('시간을 불러올 수 없습니다 — 날짜를 다시 선택해주세요'); return; }
      if (!data.slots.length) {
        resetTimeSelect('이 날짜는 예약이 마감되었습니다 — 다른 날짜를 선택해주세요');
        if (slotHintEl) slotHintEl.textContent = '';
        return;
      }
      timeIn.innerHTML = '';
      const ph = document.createElement('option');
      ph.value = ''; ph.textContent = '시간 선택 (잔여 있는 시간만 표시)';
      timeIn.appendChild(ph);
      data.slots.forEach(function(s){
        const o = document.createElement('option');
        o.value = s.time;
        o.textContent = s.time + ' · 잔여 ' + s.remaining + '명';
        timeIn.appendChild(o);
      });
      if (slotHintEl) slotHintEl.textContent = '시간대별 최대 ' + data.max + '명';
    }).catch(function(){
      if (mySeq !== slotSeq) return;
      resetTimeSelect('시간을 불러올 수 없습니다 — 날짜를 다시 선택해주세요');
    });
  }
  dateIn?.addEventListener('change', loadSlots);
  typeIn?.addEventListener('change', loadSlots);

  // 제출
  submitBtn?.addEventListener('click', function(){
    const name = nameIn.value.trim();
    const phoneRaw = phoneIn.value.replace(/[^0-9]/g,'');
    const date = dateIn.value;
    const time = timeIn.value;
    const qty = clampQty();

    msgEl.className = 'resv-msg';
    if (!name) { msgEl.textContent = '예약자 이름을 입력해주세요.'; msgEl.classList.add('error'); nameIn.focus(); return; }
    if (!/^01[0-9]\d{7,8}$/.test(phoneRaw)) { msgEl.textContent = '휴대폰 번호를 정확히 입력해주세요.'; msgEl.classList.add('error'); phoneIn.focus(); return; }
    if (!date) { msgEl.textContent = '방문 예정일을 선택해주세요.'; msgEl.classList.add('error'); dateIn.focus(); return; }
    if (!time) { msgEl.textContent = '방문 시간을 선택해주세요.'; msgEl.classList.add('error'); timeIn.focus(); return; }

    submitBtn.disabled = true;
    submitBtn.textContent = '예약 접수 중...';

    const body = new URLSearchParams();
    body.set('csrf_token', csrfToken);
    body.set('product_id', String(productId));
    body.set('qty', String(qty));
    body.set('visitor_name', name);
    body.set('visitor_phone', phoneRaw);
    body.set('visit_date', date);
    body.set('visit_time', time);
    body.set('visit_type', typeIn ? typeIn.value : 'tire');
    body.set('car_info', carIn.value.trim());
    body.set('memo', memoIn.value.trim());

    fetch(BASE_URL + '/reservation-submit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString()
    }).then(r => r.json()).then(data => {
      submitBtn.disabled = false;
      submitBtn.textContent = '예약하기';
      if (data.success) {
        close();
        // 예약 접수 성공 → 결제창으로 이동
        if (data.res_no) {
          window.location.href = BASE_URL + '/reservation-pay.php?no=' + encodeURIComponent(data.res_no);
        } else {
          doneNoEl.textContent = data.res_no || '';
          doneOverlay.classList.add('open');
        }
      } else {
        msgEl.textContent = data.message || '예약 접수에 실패했습니다.';
        msgEl.classList.add('error');
      }
    }).catch(() => {
      submitBtn.disabled = false;
      submitBtn.textContent = '예약하기';
      msgEl.textContent = '네트워크 오류가 발생했습니다. 다시 시도해주세요.';
      msgEl.classList.add('error');
    });
  });
})();

/* ===================== 썸네일 이미지 전환 ===================== */
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

/* ===================== 가격/수량 계산 ===================== */
function applyBizDiscount(price) {
  if (!isBusinessUser) return price;
  if (bizDiscountType === 'fixed' && bizDiscountFixed > 0) {
    return Math.max(0, price - bizDiscountFixed);
  }
  if (bizDiscountPct > 0) {
    return Math.round(price * (1 - bizDiscountPct / 100));
  }
  return price;
}

function currentUnitPrice() {
  if (optionSelect && optionSelect.value !== '') {
    const opt = optionSelect.options[optionSelect.selectedIndex];
    let price = parseInt(opt.dataset.price, 10) || 0;
    return applyBizDiscount(price);
  }
  return parseInt(priceNowEl.textContent.replace(/[^0-9]/g, ''), 10) || 0;
}

function recalcTotal() {
  const qty = Math.max(1, Math.min(99, parseInt(qtyInput.value, 10) || 1));
  qtyInput.value = qty;
  totalEl.textContent = (currentUnitPrice() * qty).toLocaleString('ko-KR') + '원';
}

document.getElementById('pdQtyMinus').addEventListener('click', () => {
  qtyInput.value = Math.max(1, (parseInt(qtyInput.value, 10) || 1) - 1);
  recalcTotal();
});
document.getElementById('pdQtyPlus').addEventListener('click', () => {
  qtyInput.value = Math.min(99, (parseInt(qtyInput.value, 10) || 1) + 1);
  recalcTotal();
});
qtyInput.addEventListener('input', recalcTotal);

/* ===================== DOT 옵션 선택 시 가격 갱신 (선택 안 해도 무방) ===================== */
if (optionSelect) {
  const originalPrice   = parseInt(priceBoxEl?.dataset.priceOriginal || '0', 10) || 0;
  const baseSalePrice   = parseInt(priceNowEl.textContent.replace(/[^0-9]/g, ''), 10) || 0;
  const baseDiscountPct = discountEl ? parseInt(discountEl.textContent.replace(/[^0-9]/g, ''), 10) || 0 : 0;

  optionSelect.addEventListener('change', () => {
    if (optionSelect.value === '') {
      priceNowEl.textContent = baseSalePrice.toLocaleString('ko-KR') + '원';
      if (discountEl) {
        discountEl.textContent = baseDiscountPct + '%';
        discountEl.style.display = baseDiscountPct > 0 ? '' : 'none';
      }
      if (stockWarn) stockWarn.style.display = 'none';
      buyBtn.disabled = false;
      recalcTotal();
      return;
    }

    const opt   = optionSelect.options[optionSelect.selectedIndex];
    const price = parseInt(opt.dataset.price, 10) || 0;
    const stock = parseInt(opt.dataset.stock, 10) || 0;

    priceNowEl.textContent = price.toLocaleString('ko-KR') + '원';

    if (discountEl && originalPrice > 0) {
      const pct = Math.round((1 - (price / originalPrice)) * 100);
      discountEl.textContent = pct + '%';
      discountEl.style.display = pct > 0 ? '' : 'none';
    }

    if (stockWarn) {
      if (stock <= 0) {
        stockWarn.textContent = '재고가 없습니다. 재고 요청을 하시기 바랍니다. (다른 DOT 옵션을 선택해주세요)';
        stockWarn.style.display = 'block';
        buyBtn.disabled = true;
      } else {
        stockWarn.style.display = 'none';
        buyBtn.disabled = false;
      }
    }

    recalcTotal();
  });
}

/* ===================== 바로구매 버튼 — 재고 없으면 차단 ===================== */
buyBtn.addEventListener('click', () => {
  if (!isLoggedIn) {
    alert('로그인이 필요합니다.');
    location.href = BASE_URL + '/login.php';
    return;
  }
  if (OUT_OF_STOCK) {
    alert('재고가 없습니다. 재고 요청을 하시기 바랍니다.');
    return;
  }
  document.getElementById('buyNowOptionId').value = (optionSelect && optionSelect.value !== '') ? optionSelect.value : '';
  document.getElementById('buyNowQty').value = qtyInput.value;
  document.getElementById('buyNowForm').submit();
});

/* ===================== 장바구니 담기 버튼 — 재고 없으면 차단 ===================== */
cartBtn.addEventListener('click', async () => {
  if (!isLoggedIn) {
    alert('로그인이 필요합니다.');
    location.href = BASE_URL + '/login.php';
    return;
  }
  if (OUT_OF_STOCK) {
    alert('재고가 없습니다. 재고 요청을 하시기 바랍니다.');
    return;
  }

  cartBtn.disabled = true;
  try {
    const res = await fetch(BASE_URL + '/cart-add.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        product_id: productId,
        option_id: (optionSelect && optionSelect.value !== '') ? optionSelect.value : null,
        qty: parseInt(qtyInput.value, 10) || 1,
        csrf_token: csrfToken
      })
    });
    const data = await res.json();
    if (data.success) {
      if (window.ttSetCartCount) window.ttSetCartCount(data.data.cart_count);
      alert(data.data.message || '장바구니에 담았습니다.');
    } else {
      alert(data.message || '장바구니 담기에 실패했습니다.');
    }
  } catch (e) {
    alert('네트워크 오류로 요청에 실패했습니다.');
  } finally {
    cartBtn.disabled = false;
  }
});

/* ===================== 찜하기 버튼 ===================== */
wishBtn.addEventListener('click', async () => {
  if (!isLoggedIn) {
    alert('로그인이 필요합니다.');
    location.href = BASE_URL + '/login.php';
    return;
  }
  wishBtn.disabled = true;
  try {
    const res = await fetch(BASE_URL + '/wish-toggle.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ product_id: productId, csrf_token: csrfToken })
    });
    const data = await res.json();
    if (data.success) {
      wishBtn.classList.toggle('active', data.data.wished);
      wishBtn.textContent = data.data.wished ? '♥' : '♡';
    } else {
      alert(data.message || '찜하기 처리에 실패했습니다.');
    }
  } catch (e) {
    alert('네트워크 오류로 요청에 실패했습니다.');
  } finally {
    wishBtn.disabled = false;
  }
});

/* ===================== 상단 탭 전환 (정보/배송/리뷰) ===================== */
const tabBtns   = document.querySelectorAll('.pd-tab-btn');
const tabPanels = document.querySelectorAll('.pd-tab-panel');
function activateTab(tabName) {
  tabBtns.forEach(b => b.classList.toggle('active', b.dataset.tab === tabName));
  tabPanels.forEach(p => p.classList.toggle('active', p.dataset.panel === tabName));
}
tabBtns.forEach(btn => {
  btn.addEventListener('click', () => activateTab(btn.dataset.tab));
});

/* [FIX] activateTab() 함수 내부에 끼어 있던 코드를 최상위로 분리.
   기존에는 탭을 클릭할 때마다 이 코드가 매번 재실행되며 activateTab을 재귀 호출하는
   구조적 오류가 있었다. 홈 화면에서 특정 리뷰 카드 클릭 → open_review 파라미터로
   진입했을 때만 한 번 실행되도록 페이지 로드 시점에 독립적으로 둔다. */
(function () {
  const params = new URLSearchParams(window.location.search);
  const openReviewId = params.get('open_review');
  if (!openReviewId) return;

  activateTab('review');
  setTimeout(() => {
    const target = document.getElementById('review-' + openReviewId);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.classList.add('pd-review-highlight');
    setTimeout(() => target.classList.remove('pd-review-highlight'), 2200);
  }, 120);
})();

/* [FIX-핵심] 리뷰 작성 완료 후 review-submit.php가 '#review'로 리다이렉트하면
   여기서 리뷰 탭을 강제로 활성화하고, 서버가 심어둔 성공/실패 플래시 메시지로
   스크롤 이동 + 펄스 강조를 준다. write_review 파라미터 유무와 무관하게
   해시(#review)만으로 동작하므로 write_review=1(자동 오픈)과 완전히 독립적이다. */
(function () {
  if (window.location.hash !== '#review') return;
  activateTab('review');
  setTimeout(() => {
    const flashEl = document.querySelector('.pd-flash-msg');
    if (!flashEl) return;
    flashEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    flashEl.classList.add('pd-flash-pulse');
    setTimeout(() => flashEl.classList.remove('pd-flash-pulse'), 1200);
  }, 100);
})();

/* ===================== 리뷰 작성 모달 ===================== */
const reviewWriteBtn     = document.getElementById('pdReviewWriteBtn');
const reviewModalOverlay = document.getElementById('reviewModalOverlay');
const reviewModalClose   = document.getElementById('reviewModalClose');
const reviewModalCancel  = document.getElementById('reviewModalCancel');

function openReviewModal() {
  if (!reviewModalOverlay) return;
  activateTab('review');
  reviewModalOverlay.classList.add('active');
  document.body.classList.add('tt-modal-lock'); /* [FIX] 배경 스크롤 잠금 */
}
function closeReviewModal() {
  reviewModalOverlay?.classList.remove('active');
  document.body.classList.remove('tt-modal-lock');
}
reviewWriteBtn?.addEventListener('click', openReviewModal);
reviewModalClose?.addEventListener('click', closeReviewModal);
reviewModalCancel?.addEventListener('click', closeReviewModal);
reviewModalOverlay?.addEventListener('click', (e) => {
  if (e.target === reviewModalOverlay) closeReviewModal();
});

if (autoOpenReview) {
  activateTab('review');
  if (reviewModalOverlay) openReviewModal();
}

/* ===================== 방문형태에 따라 매장선택 노출 ===================== */
const rvStoreSelectWrap = document.getElementById('rvStoreSelectWrap');
document.querySelectorAll('input[name="visit_type"]').forEach(radio => {
  radio.addEventListener('change', () => {
    if (!rvStoreSelectWrap) return;
    rvStoreSelectWrap.style.display = radio.value === 'store' && radio.checked ? '' : 'none';
  });
});
if (rvStoreSelectWrap) {
  const checkedVisit = document.querySelector('input[name="visit_type"]:checked');
  rvStoreSelectWrap.style.display = (checkedVisit && checkedVisit.value === 'store') ? '' : 'none';
}

/* [FIX] 리뷰 사진 미리보기 — 서버 검증 규칙(최대 3장, 5MB)을 클라이언트에서도 동일하게 적용하고
   개수/용량 초과 시 사용자에게 즉시 피드백을 준다. 기존 코드는 3장 초과분을 조용히 잘라냈을 뿐
   사용자에게 아무런 안내가 없었다. */
const MAX_REVIEW_PHOTOS = 3;
const MAX_REVIEW_PHOTO_BYTES = 5 * 1024 * 1024;
const rvPhotoInput      = document.getElementById('rvPhotoInput');
const rvPhotoPreview    = document.getElementById('rvPhotoPreview');
const rvPhotoCountHint  = document.getElementById('rvPhotoCountHint');

rvPhotoInput?.addEventListener('change', () => {
  if (!rvPhotoPreview) return;
  rvPhotoPreview.innerHTML = '';

  const allFiles   = Array.from(rvPhotoInput.files || []);
  const validFiles = allFiles.filter(f => f.size <= MAX_REVIEW_PHOTO_BYTES).slice(0, MAX_REVIEW_PHOTOS);

  if (rvPhotoCountHint) {
    if (allFiles.length > MAX_REVIEW_PHOTOS) {
      rvPhotoCountHint.textContent = `최대 ${MAX_REVIEW_PHOTOS}장까지만 첨부됩니다. (선택 ${allFiles.length}장 → 적용 ${validFiles.length}장)`;
    } else if (allFiles.some(f => f.size > MAX_REVIEW_PHOTO_BYTES)) {
      rvPhotoCountHint.textContent = '5MB를 초과하는 파일은 제외되었습니다.';
    } else if (validFiles.length > 0) {
      rvPhotoCountHint.textContent = `선택된 사진 ${validFiles.length}장`;
    } else {
      rvPhotoCountHint.textContent = '';
    }
  }

  validFiles.forEach(file => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const wrap = document.createElement('div');
      wrap.className = 'rv-photo-thumb';
      const img = document.createElement('img');
      img.src = e.target.result;
      img.alt = file.name;
      wrap.appendChild(img);
      rvPhotoPreview.appendChild(wrap);
    };
    reader.readAsDataURL(file);
  });
});

/* ===================== 도움돼요(리뷰 helpful) 버튼 ===================== */
document.querySelectorAll('.btn-review-helpful').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!isLoggedIn) {
      alert('로그인이 필요합니다.');
      location.href = BASE_URL + '/login.php';
      return;
    }
    btn.disabled = true;
    try {
      const res = await fetch(BASE_URL + '/review-helpful.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ review_id: btn.dataset.reviewId, csrf_token: csrfToken })
      });
      const data = await res.json();
      if (data.success) {
        btn.classList.toggle('active', data.data.helpful);
        btn.querySelector('.hp-count').textContent = data.data.helpful_count;
      } else {
        alert(data.message || '처리 중 오류가 발생했습니다.');
      }
    } catch (e) {
      alert('네트워크 오류로 요청에 실패했습니다.');
    } finally {
      btn.disabled = false;
    }
  });
});

/* ===================== 할인 쿠폰 받기 (티켓 드롭다운) ===================== */
(function(){
  const ticket = document.getElementById('pdCouponTicket');
  const drop = document.getElementById('pdCouponDrop');
  if (!ticket || !drop) return;
  ticket.addEventListener('click', function(e){
    e.stopPropagation();
    const open = drop.hasAttribute('hidden');
    if (open) drop.removeAttribute('hidden'); else drop.setAttribute('hidden', '');
    ticket.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', function(e){
    if (!drop.hasAttribute('hidden') && !drop.contains(e.target) && e.target !== ticket) {
      drop.setAttribute('hidden', '');
      ticket.setAttribute('aria-expanded', 'false');
    }
  });
  drop.addEventListener('click', async function(e){
    const btn = e.target.closest('.pdh-coupon-btn[data-claim]');
    if (!btn) return;
    e.stopPropagation();
    if (!isLoggedIn) {
      alert('로그인이 필요합니다. 로그인 후 쿠폰을 받아주세요.');
      location.href = BASE_URL + '/login.php';
      return;
    }
    const couponId = btn.getAttribute('data-claim');
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '받는 중...';
    try {
      const body = new URLSearchParams();
      body.set('csrf_token', csrfToken);
      body.set('coupon_id', couponId);
      const res = await fetch(BASE_URL + '/ajax-coupon-download.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
      });
      if (res.status === 401) {
        alert('로그인이 필요합니다. 로그인 후 쿠폰을 받아주세요.');
        location.href = BASE_URL + '/login.php';
        return;
      }
      const data = await res.json();
      alert(data.message || (data.success ? '쿠폰이 지급되었습니다.' : '쿠폰 받기에 실패했습니다.'));
      if (data.success) {
        btn.textContent = '✓ 받기 완료';
        btn.classList.add('held');
        btn.removeAttribute('data-claim');
      } else {
        btn.disabled = false;
        btn.textContent = orig;
      }
    } catch (err) {
      btn.disabled = false;
      btn.textContent = orig;
      alert('네트워크 오류로 쿠폰 받기에 실패했습니다.');
    }
  });
})();

/* ===================== 재고 입고 알림 버튼 (옵션 없는 단일상품 전용) ===================== */
const restockBtn = document.getElementById('pdRestockBtn');
restockBtn?.addEventListener('click', async () => {
  if (!isLoggedIn) {
    alert('로그인이 필요합니다.');
    location.href = BASE_URL + '/login.php';
    return;
  }
  const qty = Math.max(1, parseInt(document.getElementById('pdRestockQty').value, 10) || 1);
  restockBtn.disabled = true;
  restockBtn.textContent = '재고 확인 중...';
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
      restockBtn.textContent = '✓ 재고 알림 신청';
      restockBtn.style.background = '#22c55e';
      restockBtn.style.color = '#fff';
      alert(data.message || '재고 입고 알림이 등록되었습니다.');
    } else {
      restockBtn.disabled = false;
      restockBtn.textContent = '재고 입고 알림';
      alert(data.message || '알림 등록 중 오류가 발생했습니다.');
    }
  } catch (e) {
    restockBtn.disabled = false;
    restockBtn.textContent = '재고 입고 알림';
    alert('네트워크 오류로 요청에 실패했습니다.');
  }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
