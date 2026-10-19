<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();
ensure_reservations_table();

$pdo = Database::connection();

/* ===== 안전 쿼리 헬퍼 (테이블 없어도 대시보드 유지) ===== */
function dash_q(string $sql): array {
    try { return Database::connection()->query($sql)->fetchAll(); }
    catch (Throwable $e) { return []; }
}
function dash_one(string $sql) {
    try { return Database::connection()->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/* ===== 1. 단계별 주문 수 / 매출 ===== */
$stageCounts = array_fill_keys(['pending','paid','preparing','shipped','done','cancelled'], 0);
foreach (dash_q("SELECT status, COUNT(*) AS c FROM tt_orders GROUP BY status") as $r) {
    $k = $r['status'];
    if ($k === 'canceled') $k = 'cancelled';
    if (isset($stageCounts[$k])) $stageCounts[$k] += (int)$r['c'];
}
$stageSales = array_fill_keys(['pending','paid','preparing','shipped','done'], 0);
foreach (dash_q("SELECT status, COALESCE(SUM(total_amount),0) AS s FROM tt_orders WHERE status NOT IN ('cancelled','canceled','refunded') GROUP BY status") as $r) {
    if (isset($stageSales[$r['status']])) $stageSales[$r['status']] = (int)$r['s'];
}
// 정산예정: 결제완료~배송중 (아직 확정 전 매출)
$settlePending = $stageSales['paid'] + $stageSales['preparing'] + $stageSales['shipped'];
$settleDone    = $stageSales['done'];
$settleTotal   = $settlePending + $settleDone;

/* ===== 2. KPI — 오늘/어제 매출·주문 ===== */
$todaySales  = (int)dash_one("SELECT COALESCE(SUM(total_amount),0) FROM tt_orders WHERE DATE(created_at)=CURDATE() AND status NOT IN ('cancelled','canceled','refunded')");
$todayOrders = (int)dash_one("SELECT COUNT(*) FROM tt_orders WHERE DATE(created_at)=CURDATE() AND status NOT IN ('cancelled','canceled','refunded')");
$yestSales   = (int)dash_one("SELECT COALESCE(SUM(total_amount),0) FROM tt_orders WHERE DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status NOT IN ('cancelled','canceled','refunded')");
$yestOrders  = (int)dash_one("SELECT COUNT(*) FROM tt_orders WHERE DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status NOT IN ('cancelled','canceled','refunded')");

/* ===== 3. KPI — 예약 (오늘/어제 방문 예정) ===== */
$todayResv = (int)dash_one("SELECT COUNT(*) FROM tt_reservations WHERE visit_date=CURDATE() AND status NOT IN ('canceled','noshow')");
$yestResv  = (int)dash_one("SELECT COUNT(*) FROM tt_reservations WHERE visit_date=DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status NOT IN ('canceled','noshow')");
$resvPending = (int)dash_one("SELECT COUNT(*) FROM tt_reservations WHERE status='pending'");

/* ===== 4. KPI — 미답변 문의 ===== */
$pendingInquiries = (int)dash_one("SELECT COUNT(*) FROM tt_inquiries WHERE status='pending'");
$newInquiries     = (int)dash_one("SELECT COUNT(*) FROM tt_inquiries WHERE created_at > (NOW() - INTERVAL 1 DAY)");

/* ===== 5. 판매 성과 (일간/주간/월간) ===== */
$perf = [];
$perf['day'] = [
    'orders' => $todayOrders,
    'sales'  => $todaySales,
];
$perf['week'] = [
    'orders' => (int)dash_one("SELECT COUNT(*) FROM tt_orders WHERE created_at > (NOW() - INTERVAL 7 DAY) AND status NOT IN ('cancelled','canceled','refunded')"),
    'sales'  => (int)dash_one("SELECT COALESCE(SUM(total_amount),0) FROM tt_orders WHERE created_at > (NOW() - INTERVAL 7 DAY) AND status NOT IN ('cancelled','canceled','refunded')"),
];
$perf['month'] = [
    'orders' => (int)dash_one("SELECT COUNT(*) FROM tt_orders WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE()) AND status NOT IN ('cancelled','canceled','refunded')"),
    'sales'  => (int)dash_one("SELECT COALESCE(SUM(total_amount),0) FROM tt_orders WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE()) AND status NOT IN ('cancelled','canceled','refunded')"),
];
foreach ($perf as &$p) {
    $p['avg'] = $p['orders'] > 0 ? (int)round($p['sales'] / $p['orders']) : 0;
}
unset($p);

/* ===== 6. 최근 7일 일별 매출·주문·예약 (라인차트/스파크용) ===== */
$dailySales  = []; $dailyOrders = []; $dailyResv = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} day"));
    $dailySales[$day] = 0; $dailyOrders[$day] = 0; $dailyResv[$day] = 0;
}
foreach (dash_q("SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS s, COUNT(*) AS c
                 FROM tt_orders WHERE created_at > (NOW() - INTERVAL 7 DAY) AND status NOT IN ('cancelled','canceled','refunded')
                 GROUP BY DATE(created_at)") as $r) {
    if (isset($dailySales[$r['d']])) { $dailySales[$r['d']] = (int)$r['s']; $dailyOrders[$r['d']] = (int)$r['c']; }
}
foreach (dash_q("SELECT visit_date AS d, COUNT(*) AS c FROM tt_reservations
                 WHERE visit_date > (CURDATE() - INTERVAL 7 DAY) AND status NOT IN ('canceled','noshow')
                 GROUP BY visit_date") as $r) {
    if (isset($dailyResv[$r['d']])) $dailyResv[$r['d']] = (int)$r['c'];
}
$maxDaily = max(1, max($dailySales));
// 차트 y축 (만원 단위, 상위를 여유 있게)
$niceMax = (int)ceil($maxDaily / 400000) * 400000;
if ($niceMax < 400000) $niceMax = 400000;

// 이번주(7일) / 지난주(8~14일 전) 합계
$thisWeek = $perf['week']['sales'];
$lastWeek = (int)dash_one("SELECT COALESCE(SUM(total_amount),0) FROM tt_orders WHERE created_at BETWEEN (NOW() - INTERVAL 14 DAY) AND (NOW() - INTERVAL 7 DAY) AND status NOT IN ('cancelled','canceled','refunded')");

/* ===== 7. 리뷰 ===== */
$recentReviews = array_slice(dash_q("SELECT r.id, r.rating, r.content, r.created_at, p.name AS product_name, u.name AS user_name
                                    FROM tt_reviews r
                                    LEFT JOIN tt_products p ON p.id = r.product_id
                                    LEFT JOIN tt_users u ON u.id = r.user_id
                                    ORDER BY r.created_at DESC LIMIT 3"), 0, 3);
$newReviews = (int)dash_one("SELECT COUNT(*) FROM tt_reviews WHERE created_at > (NOW() - INTERVAL 7 DAY)");
$avgRating  = (float)dash_one("SELECT COALESCE(AVG(rating),0) FROM tt_reviews WHERE created_at > (NOW() - INTERVAL 90 DAY)");
$reviewCount = (int)dash_one("SELECT COUNT(*) FROM tt_reviews");

/* ===== 8. 인기 상품 TOP 5 (최근 7일 판매량) ===== */
$topProducts = array_slice(dash_q("SELECT oi.product_name_snap AS name, MAX(oi.product_id) AS pid,
                                         SUM(oi.qty) AS qty, SUM(oi.subtotal) AS amt,
                                         (SELECT COUNT(*) FROM tt_reviews r2 WHERE r2.product_id = MAX(oi.product_id)) AS reviews
                                  FROM tt_order_items oi
                                  JOIN tt_orders o ON o.id = oi.order_id
                                  WHERE o.created_at > (NOW() - INTERVAL 7 DAY) AND o.status NOT IN ('cancelled','canceled','refunded')
                                  GROUP BY oi.product_name_snap
                                  ORDER BY amt DESC LIMIT 5"), 0, 5);
if (empty($topProducts)) {
    $topProducts = array_slice(dash_q("SELECT oi.product_name_snap AS name, MAX(oi.product_id) AS pid,
                                             SUM(oi.qty) AS qty, SUM(oi.subtotal) AS amt,
                                             (SELECT COUNT(*) FROM tt_reviews r2 WHERE r2.product_id = MAX(oi.product_id)) AS reviews
                                      FROM tt_order_items oi
                                      JOIN tt_orders o ON o.id = oi.order_id
                                      WHERE o.status NOT IN ('cancelled','canceled','refunded')
                                      GROUP BY oi.product_name_snap
                                      ORDER BY amt DESC LIMIT 5"), 0, 5);
}

/* ===== 9. 회원 유형별 매출 (최근 7일) ===== */
$typeRows = dash_q("SELECT COALESCE(u.user_type,'personal') AS t, COUNT(*) AS c, COALESCE(SUM(o.total_amount),0) AS s
                   FROM tt_orders o LEFT JOIN tt_users u ON u.id = o.user_id
                   WHERE o.created_at > (NOW() - INTERVAL 7 DAY) AND o.status NOT IN ('cancelled','canceled','refunded')
                   GROUP BY t");
$typeMap = ['personal' => 0, 'business' => 0];
$typeCnt = ['personal' => 0, 'business' => 0];
foreach ($typeRows as $r) {
    $t = $r['t'] === 'business' ? 'business' : 'personal';
    $typeMap[$t] += (int)$r['s'];
    $typeCnt[$t] += (int)$r['c'];
}
$typeTotal = array_sum($typeMap);
$activeProducts = (int)dash_one("SELECT COUNT(*) FROM tt_products WHERE status='active'");
$weekAvg = $perf['week']['orders'] > 0 ? (int)round($thisWeek / $perf['week']['orders']) : 0;

/* ===== 10. 최근 주문 7건 (대표 상품 스냅샷 포함) ===== */
$recentOrders = array_slice(dash_q("SELECT o.id, o.order_no, o.status, o.total_amount, o.created_at, o.recipient_name, o.recipient_phone,
                                          u.name AS user_name, u.phone AS user_phone
                                   FROM tt_orders o LEFT JOIN tt_users u ON u.id = o.user_id
                                   ORDER BY o.created_at DESC LIMIT 7"), 0, 7);
$orderItems = [];
if ($recentOrders) {
    $ids = implode(',', array_map(fn($o) => (int)$o['id'], $recentOrders));
    foreach (dash_q("SELECT order_id, product_name_snap, size_snap, qty
                     FROM tt_order_items WHERE order_id IN ($ids) ORDER BY id ASC") as $it) {
        if (!isset($orderItems[(int)$it['order_id']])) $orderItems[(int)$it['order_id']] = $it;
    }
}

/* ===== 11. 기타 지표 ===== */
$couponIssued = (int)dash_one("SELECT COUNT(*) FROM tt_user_coupons");
$couponUsed   = (int)dash_one("SELECT COUNT(*) FROM tt_user_coupons WHERE status='used'");
try { $chatbotEntries = (int)dash_one("SELECT COUNT(*) FROM tt_chatbot_entries WHERE is_active=1"); }
catch (Throwable $e) { $chatbotEntries = 0; }

/* ===== SVG 라인차트 포인트 계산 ===== */
// viewBox 620×240, 플롯 영역 x:60~540 (7포인트, 80 간격), y:20~220
$chartPts = []; $lastIdx = 0; $i = 0;
foreach ($dailySales as $day => $amount) {
    $x = 60 + $i * 80;
    $y = 220 - ($amount / $niceMax) * 200;
    $chartPts[] = ['x' => $x, 'y' => round($y, 1), 'day' => $day, 'amt' => $amount];
    $i++;
}
$lastIdx = count($chartPts) - 1;
$lineD  = 'M' . implode(' L', array_map(fn($p) => $p['x'] . ' ' . $p['y'], $chartPts));
$areaD  = $lineD . " L540 220 L60 220 Z";

// 스파크라인 (88×34, x 2~86)
function dash_spark(array $series): string {
    $max = max(1, max($series));
    $pts = []; $i = 0; $n = max(1, count($series) - 1);
    foreach ($series as $v) {
        $x = 2 + ($i * 84 / $n);
        $y = 28 - ($v / $max) * 22;
        $pts[] = round($x, 1) . ' ' . round($y, 1);
        $i++;
    }
    $d = 'M' . implode(' L', $pts);
    $last = $pts[count($pts) - 1];
    [$lx, $ly] = explode(' ', $last);
    return '<svg class="spark" width="88" height="34" viewBox="0 0 88 34" aria-hidden="true">'
         . '<path d="' . $d . '" fill="none" stroke="var(--accent)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
         . '<circle cx="' . $lx . '" cy="' . $ly . '" r="2.4" fill="var(--accent)"/></svg>';
}
function dash_delta(int $now, int $before, string $unit = '', bool $invert = false): array {
    $diff = $now - $before;
    $cls  = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat');
    if ($invert) $cls = $cls === 'up' ? 'down' : ($cls === 'down' ? 'up' : 'flat');
    $sign = $diff > 0 ? '▲' : ($diff < 0 ? '▼' : '－');
    $txt  = $sign . ' ' . number_format(abs($diff)) . $unit;
    return [$cls, $txt];
}

$statusLabels = [
    'pending'   => '주문접수',
    'paid'      => '결제완료',
    'preparing' => '상품준비중',
    'shipped'   => '배송중',
    'done'      => '배송완료',
    'cancelled' => '취소·환불',
];
$pillMap = [
    'pending'   => 'new',
    'paid'      => 'pay',
    'preparing' => 'prep',
    'shipped'   => 'ship',
    'done'      => 'done',
    'cancelled' => 'cancel',
];

$pageTitle = '대시보드';
require __DIR__ . '/includes/header.php';
?>

<div class="sdash">

  <!-- ===== 헤더 행 ===== -->
  <div class="header-row">
    <div>
      <h1><span class="hl">현황</span></h1>
      <p class="sub"><?= h(date('Y년 n월 j일 (D)', strtotime('now'))) ?> · <?= h(date('H:i', strtotime('now'))) ?> 기준 · 실시간 집계</p>
    </div>
    <div class="hstack">
      <span class="date-pick">🗓 <b>최근 7일</b>&nbsp;<?= h(date('m/d', strtotime('-6 day'))) ?> – <?= h(date('m/d')) ?></span>
    </div>
  </div>

  <!-- ===== KPI 4칸 ===== -->
  <div class="kpis">
    <a class="kpi" href="<?= BASE_URL ?>/admin/orders.php">
      <div class="label"><span class="kdot"></span>오늘 매출</div>
      <div class="val tnum"><?= number_format($todaySales) ?><span class="unit">원</span></div>
      <div>
        <?php [$dc, $dt] = dash_delta($todaySales, $yestSales, '원'); ?>
        <span class="delta <?= $dc ?>"><?= $dt ?></span>
        <span class="compare">어제 대비</span>
      </div>
      <?= dash_spark(array_values($dailySales)) ?>
    </a>
    <a class="kpi" href="<?= BASE_URL ?>/admin/orders.php">
      <div class="label"><span class="kdot"></span>신규 주문</div>
      <div class="val tnum"><?= number_format($todayOrders) ?><span class="unit">건</span></div>
      <div>
        <?php [$dc, $dt] = dash_delta($todayOrders, $yestOrders, '건'); ?>
        <span class="delta <?= $dc ?>"><?= $dt ?></span>
        <span class="compare">어제 대비</span>
      </div>
      <?= dash_spark(array_values($dailyOrders)) ?>
    </a>
    <a class="kpi" href="<?= BASE_URL ?>/admin/reservations.php?date=<?= date('Y-m-d') ?>">
      <div class="label"><span class="kdot"></span>오늘 예약</div>
      <div class="val tnum"><?= number_format($todayResv) ?><span class="unit">건</span></div>
      <div>
        <?php [$dc, $dt] = dash_delta($todayResv, $yestResv, '건'); ?>
        <span class="delta <?= $dc ?>"><?= $dt ?></span>
        <span class="compare">어제 대비 · 접수대기 <?= number_format($resvPending) ?>건</span>
      </div>
      <?= dash_spark(array_values($dailyResv)) ?>
    </a>
    <a class="kpi" href="<?= BASE_URL ?>/admin/inquiries.php">
      <div class="label"><span class="kdot"></span>미답변 문의</div>
      <div class="val tnum"><?= number_format($pendingInquiries) ?><span class="unit">건</span></div>
      <div>
        <?php [$dc, $dt] = dash_delta($newInquiries, 0, '건', true); ?>
        <span class="delta <?= $dc ?>"><?= $dt ?></span>
        <span class="compare">신규 문의 (24시간)</span>
      </div>
      <span class="kpi-sub">쿠폰 <?= number_format($couponUsed) ?>/<?= number_format($couponIssued) ?> 사용 · 챗봇 <?= number_format($chatbotEntries) ?>건</span>
    </a>
  </div>

  <!-- ===== 주간 매출 추이 + 리뷰 알림 ===== -->
  <div class="row-2">
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">주간 매출 추이</div>
          <div class="card-sub">최근 7일 · 일별 판매금액</div>
        </div>
        <div class="seg">
          <button type="button" class="on" data-period="day">일간</button>
          <button type="button" data-period="week">주간</button>
          <button type="button" data-period="month">월간</button>
        </div>
      </div>
      <div class="chart-legend">
        <span class="lgnd"><span class="dot" style="background:var(--accent)"></span>이번 주 <b class="tnum"><?= number_format($thisWeek) ?>원</b></span>
        <span class="lgnd"><span class="dot" style="background:#D6D6D0"></span>지난 주 <b class="tnum"><?= number_format($lastWeek) ?>원</b></span>
      </div>

      <?php foreach (['day' => '일간', 'week' => '주간', 'month' => '월간'] as $pk => $pl): ?>
      <div class="perf-row<?= $pk === 'day' ? ' on' : '' ?>" data-period="<?= $pk ?>">
        <div class="perf-metric"><span>판매 건수</span><strong class="tnum"><?= number_format($perf[$pk]['orders']) ?>건</strong></div>
        <div class="perf-metric"><span>판매 금액</span><strong class="tnum"><?= number_format($perf[$pk]['sales']) ?>원</strong></div>
        <div class="perf-metric"><span>평균 결제 단가</span><strong class="tnum"><?= number_format($perf[$pk]['avg']) ?>원</strong></div>
      </div>
      <?php endforeach; ?>

      <div class="chart-wrap">
        <svg viewBox="0 0 620 240" width="100%" height="240" preserveAspectRatio="none" class="tnum">
          <defs>
            <linearGradient id="sdashGrad" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stop-color="var(--accent)" stop-opacity="0.20"/>
              <stop offset="100%" stop-color="var(--accent)" stop-opacity="0"/>
            </linearGradient>
          </defs>
          <g stroke="#ECECE8" stroke-width="1">
            <?php foreach ([20, 70, 120, 170, 220] as $gy): ?>
            <line x1="40" y1="<?= $gy ?>" x2="620" y2="<?= $gy ?>"/>
            <?php endforeach; ?>
          </g>
          <g fill="#9A9CA2" font-size="10" text-anchor="end">
            <?php $step = (int)($niceMax / 4); foreach ([4, 3, 2, 1, 0] as $gi): ?>
            <text x="34" y="<?= 24 + $gi * 50 ?>"><?= $step * $gi >= 10000 ? number_format((int)round($step * $gi / 10000)) . '만' : number_format($step * $gi) ?></text>
            <?php endforeach; ?>
          </g>
          <?php if ($lastWeek > 0): ?>
          <?php
            // 지난 주 평균 기준 참고선 (점선)
            $lwAvg = $lastWeek / 7;
            $lwY = round(220 - ($lwAvg / $niceMax) * 200, 1);
          ?>
          <path d="M60 <?= $lwY ?> L540 <?= $lwY ?>" fill="none" stroke="#D6D6D0" stroke-width="2" stroke-dasharray="4 4"/>
          <?php endif; ?>
          <path d="<?= $areaD ?>" fill="url(#sdashGrad)"/>
          <path d="<?= $lineD ?>" fill="none" stroke="var(--accent)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
          <?php foreach ($chartPts as $ci => $cp): ?>
          <circle cx="<?= $cp['x'] ?>" cy="<?= $cp['y'] ?>" r="<?= $ci === $lastIdx ? 5.5 : 3.5 ?>"<?= $ci === $lastIdx ? ' fill="var(--accent)"' : ' fill="#fff" stroke="var(--accent)" stroke-width="1.6"' ?>>
            <title><?= h(date('n월 j일 (D)', strtotime($cp['day']))) ?> · <?= number_format($cp['amt']) ?>원</title>
          </circle>
          <?php endforeach; ?>
          <g fill="#9A9CA2" font-size="10" text-anchor="middle">
            <?php foreach ($chartPts as $ci => $cp): ?>
            <text x="<?= $cp['x'] ?>" y="238"<?= $ci === $lastIdx ? ' font-weight="700" fill="#111214"' : '' ?>><?= h(date('D n/j', strtotime($cp['day']))) ?></text>
            <?php endforeach; ?>
          </g>
        </svg>
      </div>
    </div>

    <div class="card">
      <div class="card-head review-head">
        <div>
          <div class="card-title">리뷰 알림</div>
          <div class="card-sub">빠른 답변이 재구매율을 높여요</div>
        </div>
        <?php if ($newReviews > 0): ?>
        <span class="badge">● 신규 <?= number_format($newReviews) ?>건</span>
        <?php endif; ?>
      </div>
      <?php if (empty($recentReviews)): ?>
      <p class="review-empty">최근 리뷰가 없습니다.</p>
      <?php else: ?>
      <div class="review-list">
        <?php foreach ($recentReviews as $rv): ?>
        <div class="review">
          <div class="thumb"><span><?= h(mb_substr(trim((string)$rv['product_name']) ?: '타', 0, 1, 'UTF-8')) ?></span></div>
          <div class="review-body">
            <div class="top">
              <span class="stars"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', max(0, 5 - (int)$rv['rating'])) ?></span>
              <span class="who"><?= h($rv['user_name'] ?? '익명') ?> 님</span>
              <span class="when"><?= h(date('m/d H:i', strtotime((string)$rv['created_at']))) ?></span>
            </div>
            <div class="txt"><?= h(mb_strimwidth((string)$rv['content'], 0, 120, '…', 'UTF-8')) ?></div>
            <div class="prod">🛞 <b><?= h((string)$rv['product_name'] ?: '상품') ?></b></div>
          </div>
          <a class="reply" href="<?= BASE_URL ?>/admin/reviews.php">답변</a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="review-foot">
        <span>평균 평점 <b class="tnum"><?= number_format($avgRating, 1) ?></b> · 총 <?= number_format($reviewCount) ?>건</span>
        <a href="<?= BASE_URL ?>/admin/reviews.php">전체 리뷰 보기 →</a>
      </div>
    </div>
  </div>

  <!-- ===== 인기 상품 + 회원 유형 + 정산 예정 ===== -->
  <div class="row-3">
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">인기 상품 TOP 5</div>
          <div class="card-sub">최근 7일 판매량 기준</div>
        </div>
        <a class="card-more" href="<?= BASE_URL ?>/admin/products.php">전체 →</a>
      </div>
      <?php if (empty($topProducts)): ?>
      <p class="review-empty">최근 7일 판매 데이터가 없습니다.</p>
      <?php else: ?>
      <div class="tp-list">
        <?php foreach ($topProducts as $ti => $tp): ?>
        <div class="tp">
          <span class="tp-rank"><?= $ti + 1 ?></span>
          <div class="tp-body">
            <div class="p-name"><?= h(mb_strimwidth((string)$tp['name'], 0, 30, '…', 'UTF-8')) ?></div>
            <div class="tp-meta tnum"><?= number_format((int)$tp['qty']) ?>개 판매 · 리뷰 <?= number_format((int)$tp['reviews']) ?>건</div>
          </div>
          <span class="tp-amt tnum"><?= number_format((int)$tp['amt']) ?><span class="krw">원</span></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">회원 유형별 매출</div>
          <div class="card-sub">최근 7일 · 일반/사업자</div>
        </div>
        <a class="card-more" href="<?= BASE_URL ?>/admin/business-orders.php">사업자 주문 →</a>
      </div>
      <div class="tr-list">
        <?php
        $typePct = $typeTotal > 0 ? (int)round($typeMap['personal'] / $typeTotal * 100) : 50;
        if ($typeTotal === 0) $typePct = 50;
        ?>
        <div class="tr">
          <span class="tr-dot" style="background:var(--accent)"></span>일반 회원
          <span class="tr-bar"><i style="width:<?= $typePct ?>%"></i></span>
          <b class="tnum"><?= number_format($typeMap['personal']) ?>원</b>
        </div>
        <div class="tr">
          <span class="tr-dot" style="background:#111214"></span>사업자 회원
          <span class="tr-bar"><i style="width:<?= 100 - $typePct ?>%"></i></span>
          <b class="tnum"><?= number_format($typeMap['business']) ?>원</b>
        </div>
      </div>
      <div class="tr-foot">
        <div><div class="tr-foot-l">총 주문 (7일)</div><div class="tr-foot-v tnum"><?= number_format($perf['week']['orders']) ?>건</div></div>
        <div><div class="tr-foot-l">평균 결제 단가</div><div class="tr-foot-v tnum"><?= number_format($weekAvg) ?>원</div></div>
        <div><div class="tr-foot-l">판매중 상품</div><div class="tr-foot-v tnum"><?= number_format($activeProducts) ?>개</div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">정산 예정 금액</div>
          <div class="card-sub">결제완료 ~ 배송중 매출 기준</div>
        </div>
        <a class="card-more" href="<?= BASE_URL ?>/admin/orders.php">주문 관리 →</a>
      </div>
      <div class="settle-amt tnum"><?= number_format($settlePending) ?><span class="unit">원</span></div>
      <div class="settle-note">반품 · 취소 가산 후 정산 예정</div>
      <div class="settle-break">
        <div class="sb-row"><span>판매 확정 (배송완료)</span><b class="tnum"><?= number_format($settleDone) ?>원</b></div>
        <div class="sb-row"><span>정산 대기 (결제완료 · 준비중)</span><b class="tnum"><?= number_format($stageSales['paid'] + $stageSales['preparing']) ?>원</b></div>
        <div class="sb-row"><span>배송중</span><b class="tnum"><?= number_format($stageSales['shipped']) ?>원</b></div>
        <div class="sb-row"><span>취소 · 환불</span><b class="tnum"><?= number_format($stageCounts['cancelled']) ?>건</b></div>
      </div>
      <div class="settle-bar"><div class="settle-bar-fill" style="width:<?= $settleTotal > 0 ? (int)round($settleDone / $settleTotal * 100) : 0 ?>%"></div></div>
      <div class="settle-hint">
        <span>확정 비중 <?= $settleTotal > 0 ? (int)round($settleDone / $settleTotal * 100) : 0 ?>%</span>
        <a href="<?= BASE_URL ?>/admin/tax-invoices.php">세금계산서 내역 →</a>
      </div>
    </div>
  </div>

  <!-- ===== 최근 주문 목록 ===== -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">최근 주문 목록</div>
        <div class="card-sub">최근 접수 순 · 최대 7건</div>
      </div>
      <a class="btn-mini" href="<?= BASE_URL ?>/admin/orders.php">주문 관리 →</a>
    </div>
    <div class="filters">
      <?php
      $chips = [
          ''          => ['전체', array_sum($stageCounts)],
          'pending'   => ['주문접수', $stageCounts['pending']],
          'paid'      => ['결제완료', $stageCounts['paid']],
          'preparing' => ['상품준비중', $stageCounts['preparing']],
          'shipped'   => ['배송중', $stageCounts['shipped']],
          'done'      => ['배송완료', $stageCounts['done']],
          'cancelled' => ['취소·환불', $stageCounts['cancelled']],
      ];
      foreach ($chips as $ck => [$cl, $cn]): ?>
      <a class="chip<?= $ck === '' ? ' on' : '' ?>" href="<?= BASE_URL ?>/admin/orders.php<?= $ck !== '' ? '?status=' . $ck : '' ?>"><?= h($cl) ?><span class="n tnum"><?= number_format($cn) ?></span></a>
      <?php endforeach; ?>
    </div>
    <div class="table-scroll">
      <table class="orders">
        <thead>
          <tr>
            <th>주문번호</th>
            <th>상품</th>
            <th>주문자</th>
            <th>주문일시</th>
            <th>결제금액</th>
            <th>상태</th>
            <th style="text-align:right">관리</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentOrders)): ?>
          <tr><td colspan="7" class="empty-cell">주문 내역이 없습니다.</td></tr>
          <?php else: foreach ($recentOrders as $o):
            $oid = (int)$o['id'];
            $it  = $orderItems[$oid] ?? null;
            $st  = $o['status'] === 'canceled' ? 'cancelled' : $o['status'];
            $pill = $pillMap[$st] ?? 'new';
            $stLabel = $statusLabels[$st] ?? $st;
          ?>
          <tr>
            <td class="num"><?= h((string)$o['order_no']) ?></td>
            <td>
              <div class="prod-cell">
                <span class="p-thumb"><span><?= h(mb_substr(trim((string)($it['product_name_snap'] ?? '')) ?: '🛞', 0, 1, 'UTF-8')) ?></span></span>
                <div>
                  <div class="p-name"><?= h(mb_strimwidth((string)($it['product_name_snap'] ?? '상품'), 0, 26, '…', 'UTF-8')) ?></div>
                  <div class="p-opt tnum"><?= h((string)($it['size_snap'] ?? '')) ?><?= $it && $it['size_snap'] ? ' · ' : '' ?><?= $it ? number_format((int)$it['qty']) . '개' : '' ?></div>
                </div>
              </div>
            </td>
            <td>
              <div class="customer"><?= h($o['user_name'] ?? $o['recipient_name'] ?? '-') ?></div>
              <div class="tel tnum"><?= h((string)($o['user_phone'] ?? $o['recipient_phone'] ?? '')) ?></div>
            </td>
            <td class="num"><?= h(date('m/d H:i', strtotime((string)$o['created_at']))) ?></td>
            <td class="right"><span class="amt tnum"><?= number_format((int)$o['total_amount']) ?><span class="krw">원</span></span></td>
            <td><span class="pill <?= $pill ?>"><?= h($stLabel) ?></span></td>
            <td class="right">
              <span class="row-act">
                <a class="pri" href="<?= BASE_URL ?>/admin/orders.php">상세</a>
              </span>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="table-foot">
      <span>전체 <b class="tnum"><?= number_format(array_sum($stageCounts)) ?></b>건 중 최근 <?= count($recentOrders) ?>건 표시</span>
      <a href="<?= BASE_URL ?>/admin/orders.php" class="foot-link">전체 주문 보기 →</a>
    </div>
  </div>
</div>

<style>
@import url('https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.min.css');

/* ===== 셀러온 스타일 대시보드 (사이드바·상단바 제외, 본문만) ===== */
.sdash{
  --sd-bg:#F7F7F5; --sd-surface:#FFFFFF; --sd-line:#ECECE8; --sd-line2:#E4E4DE;
  --sd-ink1:#111214; --sd-ink2:#3A3B3F; --sd-ink3:#6B6D73; --sd-ink4:#9A9CA2;
  --sd-accent:#2456E0; --sd-accent-soft:#EAF0FD; --sd-accent-ink:#1739A8;
  --sd-pos:#0B9A5E; --sd-pos-soft:#E6F6EE;
  --sd-warn:#D08700; --sd-warn-soft:#FBF1DE;
  --sd-danger:#D03A2E; --sd-danger-soft:#FBEAE8;
  --sd-radius:12px; --sd-radius-sm:8px;
  --sd-shadow:0 1px 0 rgba(17,18,20,0.03), 0 1px 2px rgba(17,18,20,0.04);
  font-family:'Pretendard Variable','Pretendard',-apple-system,BlinkMacSystemFont,system-ui,sans-serif;
  color:var(--sd-ink1); font-size:14px; line-height:1.5;
  -webkit-font-smoothing:antialiased;
}
@supports (color: oklch(0.55 0.17 258)){
  .sdash{--sd-accent:oklch(0.55 0.17 258);--sd-accent-soft:oklch(0.955 0.03 258);--sd-accent-ink:oklch(0.42 0.17 258);
    --sd-pos:oklch(0.62 0.14 155);--sd-pos-soft:oklch(0.955 0.045 155);
    --sd-warn:oklch(0.68 0.14 60);--sd-warn-soft:oklch(0.965 0.05 75);
    --sd-danger:oklch(0.6 0.18 25);--sd-danger-soft:oklch(0.965 0.04 25);}
}
.sdash .tnum{font-variant-numeric:tabular-nums}

/* 헤더 행 */
.sdash .header-row{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.sdash h1{font-size:24px;font-weight:800;letter-spacing:-0.03em;margin:0;color:var(--sd-ink1)}
.sdash h1 .hl{background:linear-gradient(180deg,transparent 55%,#FBE38E 55%);padding:0 6px;border-radius:2px}
.sdash .sub{color:var(--sd-ink3);font-size:13px;margin:4px 0 0}
.sdash .date-pick{display:inline-flex;align-items:center;gap:6px;background:var(--sd-surface);border:1px solid var(--sd-line);padding:8px 12px;border-radius:8px;font-size:13px;color:var(--sd-ink2);font-weight:500}
.sdash .date-pick b{color:var(--sd-ink1);font-weight:700}
.sdash .hstack{display:flex;align-items:center;gap:10px}

/* KPI */
.sdash .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.sdash .kpi{background:var(--sd-surface);border:1px solid var(--sd-line);border-radius:var(--sd-radius);padding:18px 18px 16px;box-shadow:var(--sd-shadow);position:relative;overflow:hidden;text-decoration:none;color:inherit;display:block;transition:box-shadow .15s}
.sdash .kpi:hover{box-shadow:0 6px 18px rgba(17,18,20,.07)}
.sdash .kpi .label{display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--sd-ink3);font-weight:600;letter-spacing:-0.01em}
.sdash .kpi .label .kdot{width:6px;height:6px;border-radius:50%;background:var(--sd-accent)}
.sdash .kpi .val{display:flex;align-items:baseline;gap:4px;margin-top:10px;font-size:26px;font-weight:800;letter-spacing:-0.03em;color:var(--sd-ink1)}
.sdash .kpi .val .unit{font-size:14px;font-weight:700;color:var(--sd-ink2);margin-left:2px}
.sdash .kpi .delta{display:inline-flex;align-items:center;gap:3px;margin-top:10px;font-size:12px;font-weight:700;padding:3px 8px;border-radius:999px}
.sdash .delta.up{background:var(--sd-pos-soft);color:var(--sd-pos)}
.sdash .delta.down{background:var(--sd-danger-soft);color:var(--sd-danger)}
.sdash .delta.flat{background:#F1F1EE;color:var(--sd-ink3)}
.sdash .kpi .compare{font-size:12px;color:var(--sd-ink3);margin-left:6px;font-weight:500}
.sdash .kpi .spark{position:absolute;right:14px;bottom:14px;opacity:.9}
.sdash .kpi-sub{position:absolute;right:14px;bottom:14px;font-size:11px;color:var(--sd-ink4);font-weight:600}

/* 카드 그리드 */
.sdash .row-2{display:grid;grid-template-columns:1.72fr 1fr;gap:16px;margin-bottom:20px}
.sdash .row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px}
.sdash .card{background:var(--sd-surface);border:1px solid var(--sd-line);border-radius:var(--sd-radius);padding:20px 22px;box-shadow:var(--sd-shadow)}
.sdash .card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
.sdash .card-title{font-size:15px;font-weight:700;letter-spacing:-0.02em;color:var(--sd-ink1)}
.sdash .card-sub{font-size:12px;color:var(--sd-ink3);margin-top:2px}
.sdash .card-more{font-size:12.5px;color:var(--sd-accent-ink);font-weight:600;text-decoration:none;white-space:nowrap}
.sdash .card-more:hover{text-decoration:underline}
.sdash .btn-mini{font-size:12.5px;font-weight:600;padding:6px 10px;border-radius:8px;border:1px solid var(--sd-accent);color:var(--sd-accent-ink);background:#fff;text-decoration:none;white-space:nowrap}
.sdash .btn-mini:hover{background:var(--sd-accent-soft)}

/* 세그먼트 */
.sdash .seg{display:inline-flex;background:#F1F1EE;border-radius:8px;padding:3px}
.sdash .seg button{border:0;background:transparent;font:inherit;font-size:12.5px;color:var(--sd-ink3);font-weight:600;padding:5px 12px;border-radius:6px;cursor:pointer;letter-spacing:-0.01em}
.sdash .seg button.on{background:#fff;color:var(--sd-ink1);box-shadow:0 1px 2px rgba(0,0,0,.06)}

/* 성과 지표 행 (일/주/월 전환) */
.sdash .perf-row{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin:2px 0 10px}
.sdash .perf-row.on{display:grid}
.sdash .perf-metric{background:#FBFBF9;border:1px solid var(--sd-line);border-radius:10px;padding:10px 12px;display:flex;flex-direction:column;gap:3px}
.sdash .perf-metric span{font-size:11.5px;font-weight:600;color:var(--sd-ink3)}
.sdash .perf-metric strong{font-size:17px;font-weight:800;color:var(--sd-ink1);letter-spacing:-0.02em}

/* 차트 */
.sdash .chart-legend{display:flex;gap:18px;font-size:12px;color:var(--sd-ink3);margin:2px 0 6px;flex-wrap:wrap}
.sdash .chart-legend .dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px;vertical-align:middle}
.sdash .chart-legend .lgnd{display:flex;align-items:center;font-weight:500}
.sdash .chart-legend b{color:var(--sd-ink1);font-weight:700;margin-left:4px}
.sdash .chart-wrap{position:relative}
.sdash .chart-wrap svg{display:block;max-width:100%;height:auto}

/* 리뷰 위젯 */
.sdash .review-head .badge{display:inline-flex;align-items:center;gap:4px;background:var(--sd-danger-soft);color:var(--sd-danger);font-size:11.5px;font-weight:700;padding:3px 8px;border-radius:999px;letter-spacing:-0.01em;white-space:nowrap}
.sdash .review-list{display:flex;flex-direction:column;gap:10px}
.sdash .review{padding:12px;border:1px solid var(--sd-line);border-radius:10px;display:flex;gap:12px;align-items:flex-start}
.sdash .review .thumb{width:40px;height:40px;border-radius:50%;flex:0 0 40px;background:#EDEDE8;border:1px solid var(--sd-line);display:grid;place-items:center;font-weight:700;color:var(--sd-ink2);font-size:14px;overflow:hidden}
.sdash .review .review-body{flex:1;min-width:0}
.sdash .review .top{display:flex;align-items:center;gap:6px;font-size:12.5px;flex-wrap:wrap}
.sdash .stars{color:var(--sd-warn);letter-spacing:1px;font-size:12px}
.sdash .review .who{color:var(--sd-ink2);font-weight:600}
.sdash .review .when{color:var(--sd-ink4);margin-left:auto;font-size:11.5px}
.sdash .review .txt{font-size:13px;color:var(--sd-ink2);margin-top:4px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.sdash .review .prod{font-size:11.5px;color:var(--sd-ink3);margin-top:6px}
.sdash .review .prod b{color:var(--sd-ink2);font-weight:600}
.sdash .reply{align-self:center;font-size:12px;font-weight:600;padding:5px 10px;border-radius:6px;border:1px solid var(--sd-accent);color:var(--sd-accent-ink);background:#fff;cursor:pointer;text-decoration:none;white-space:nowrap}
.sdash .reply:hover{background:var(--sd-accent-soft)}
.sdash .review-foot{margin-top:12px;display:flex;justify-content:space-between;align-items:center;font-size:12.5px;color:var(--sd-ink3);gap:8px;flex-wrap:wrap}
.sdash .review-foot b{color:var(--sd-ink1)}
.sdash .review-foot a{color:var(--sd-accent-ink);font-weight:600;text-decoration:none}
.sdash .review-empty{font-size:13px;color:var(--sd-ink4);text-align:center;padding:26px 0;margin:0}

/* 인기 상품 */
.sdash .tp-list{display:flex;flex-direction:column;gap:2px}
.sdash .tp{display:flex;align-items:center;gap:12px;padding:10px 4px;border-bottom:1px dashed var(--sd-line)}
.sdash .tp:last-child{border-bottom:0}
.sdash .tp-rank{width:22px;height:22px;border-radius:6px;flex:0 0 22px;display:grid;place-items:center;font-size:11.5px;font-weight:700;background:#F1F1EE;color:var(--sd-ink3);font-variant-numeric:tabular-nums}
.sdash .tp:nth-child(1) .tp-rank{background:var(--sd-accent);color:#fff}
.sdash .tp:nth-child(2) .tp-rank{background:#111214;color:#fff}
.sdash .tp:nth-child(3) .tp-rank{background:#26272B;color:#fff}
.sdash .tp-body{flex:1;min-width:0}
.sdash .tp-body .p-name{font-size:13px;font-weight:600;letter-spacing:-0.01em;color:var(--sd-ink1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sdash .tp-meta{font-size:11.5px;color:var(--sd-ink3);margin-top:2px}
.sdash .tp-amt{font-weight:700;font-size:13px;color:var(--sd-ink1);white-space:nowrap;letter-spacing:-0.02em}
.sdash .tp-amt .krw{font-weight:600;color:var(--sd-ink2);margin-left:1px;font-size:11.5px}

/* 회원 유형 (트래픽 리스트 스타일) */
.sdash .tr-list{display:flex;flex-direction:column;gap:12px}
.sdash .tr{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--sd-ink2);flex-wrap:nowrap}
.sdash .tr-dot{width:9px;height:9px;border-radius:2px;flex:0 0 9px}
.sdash .tr-bar{flex:1;height:6px;background:#F1F1EE;border-radius:3px;overflow:hidden;min-width:40px}
.sdash .tr-bar i{display:block;height:100%;border-radius:3px}
.sdash .tr > .tr-bar + b, .sdash .tr b{margin-left:auto;color:var(--sd-ink1);font-weight:700;white-space:nowrap}
.sdash .tr:nth-child(1) .tr-bar i{background:var(--sd-accent)}
.sdash .tr:nth-child(2) .tr-bar i{background:#111214}
.sdash .tr-foot{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:18px;padding-top:14px;border-top:1px solid var(--sd-line)}
.sdash .tr-foot-l{font-size:11.5px;color:var(--sd-ink3);margin-bottom:2px}
.sdash .tr-foot-v{font-size:14px;font-weight:700;color:var(--sd-ink1);letter-spacing:-0.02em}

/* 정산 */
.sdash .settle-amt{font-size:26px;font-weight:800;letter-spacing:-0.03em;color:var(--sd-ink1);margin-top:8px}
.sdash .settle-amt .unit{font-size:14px;font-weight:700;color:var(--sd-ink2);margin-left:2px}
.sdash .settle-note{font-size:12px;color:var(--sd-ink3);margin-top:4px}
.sdash .settle-break{display:flex;flex-direction:column;gap:8px;margin-top:16px;padding:12px 14px;background:#FBFBF9;border-radius:8px;border:1px solid var(--sd-line)}
.sdash .sb-row{display:flex;justify-content:space-between;gap:8px;font-size:12.5px;color:var(--sd-ink3)}
.sdash .sb-row b{color:var(--sd-ink1);font-weight:700;letter-spacing:-0.02em;white-space:nowrap}
.sdash .settle-bar{height:6px;background:#F1F1EE;border-radius:3px;margin-top:16px;overflow:hidden}
.sdash .settle-bar-fill{height:100%;background:linear-gradient(90deg,var(--sd-accent),#5B8DEF);border-radius:3px}
.sdash .settle-hint{display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:12px;color:var(--sd-ink3);gap:8px;flex-wrap:wrap}
.sdash .settle-hint a{color:var(--sd-accent-ink);font-weight:600;text-decoration:none}

/* 주문 테이블 */
.sdash .filters{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.sdash .chip{font-size:12.5px;padding:5px 10px;border-radius:999px;border:1px solid var(--sd-line);color:var(--sd-ink2);background:#fff;cursor:pointer;font-weight:500;letter-spacing:-0.01em;text-decoration:none;display:inline-flex;align-items:center}
.sdash .chip:hover{border-color:var(--sd-line2);background:#FBFBF9}
.sdash .chip.on{background:var(--sd-ink1);color:#fff;border-color:var(--sd-ink1)}
.sdash .chip .n{color:var(--sd-ink4);margin-left:4px;font-weight:500}
.sdash .chip.on .n{color:#B9B9B9}
.sdash .table-scroll{overflow-x:auto}
.sdash table.orders{width:100%;border-collapse:separate;border-spacing:0;margin-top:14px;min-width:760px}
.sdash .orders th{text-align:left;font-size:12px;font-weight:600;color:var(--sd-ink3);padding:10px 12px;border-bottom:1px solid var(--sd-line);letter-spacing:-0.01em;background:#FBFBF9}
.sdash .orders th:first-child{border-top-left-radius:8px}
.sdash .orders th:last-child{border-top-right-radius:8px;text-align:right}
.sdash .orders td{padding:14px 12px;border-bottom:1px solid var(--sd-line);font-size:13.5px;color:var(--sd-ink1);vertical-align:middle}
.sdash .orders tr:last-child td{border-bottom:0}
.sdash .orders td.num{color:var(--sd-ink3);font-size:12.5px}
.sdash .orders td.right{text-align:right}
.sdash .orders td.empty-cell{text-align:center;color:var(--sd-ink4);padding:30px 0}
.sdash .prod-cell{display:flex;align-items:center;gap:10px}
.sdash .p-thumb{width:44px;height:44px;border-radius:8px;flex:0 0 44px;background:#FBFBF9;border:1px solid var(--sd-line);display:grid;place-items:center;font-weight:700;color:var(--sd-ink3);overflow:hidden}
.sdash .p-name{font-weight:600;letter-spacing:-0.01em;color:var(--sd-ink1);font-size:13.5px}
.sdash .p-opt{font-size:11.5px;color:var(--sd-ink3);margin-top:2px}
.sdash .amt{font-weight:700;letter-spacing:-0.02em}
.sdash .amt .krw{font-weight:600;color:var(--sd-ink2);margin-left:1px}
.sdash .customer{font-size:13px;font-weight:600}
.sdash .customer .tel{font-size:11.5px;color:var(--sd-ink3);margin-top:1px;font-weight:500}
.sdash .pill{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:4px 9px;border-radius:999px;letter-spacing:-0.01em;white-space:nowrap}
.sdash .pill::before{content:"";width:5px;height:5px;border-radius:50%;background:currentColor}
.sdash .pill.new{background:#F1F1EE;color:var(--sd-ink3)}
.sdash .pill.pay{background:var(--sd-accent-soft);color:var(--sd-accent-ink)}
.sdash .pill.prep{background:var(--sd-warn-soft);color:var(--sd-warn)}
.sdash .pill.ship{background:#EEF3FF;color:#3557B0}
.sdash .pill.done{background:var(--sd-pos-soft);color:var(--sd-pos)}
.sdash .pill.cancel{background:#F1F1EE;color:var(--sd-ink3)}
.sdash .row-act{display:inline-flex;gap:4px;justify-content:flex-end}
.sdash .row-act a{font-size:12px;padding:5px 9px;border-radius:6px;border:1px solid var(--sd-line);background:#fff;color:var(--sd-ink2);cursor:pointer;font-weight:500;white-space:nowrap;text-decoration:none}
.sdash .row-act a:hover{border-color:var(--sd-line2);background:#FBFBF9}
.sdash .row-act a.pri{border-color:var(--sd-accent);color:var(--sd-accent-ink);font-weight:600}
.sdash .row-act a.pri:hover{background:var(--sd-accent-soft)}
.sdash .table-foot{display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:12.5px;color:var(--sd-ink3);gap:8px;flex-wrap:wrap}
.sdash .table-foot b{color:var(--sd-ink1)}
.sdash .foot-link{color:var(--sd-accent-ink);font-weight:600;text-decoration:none}

/* 반응형 */
@media(max-width:1200px){
  .sdash .row-3{grid-template-columns:1fr 1fr}
}
@media(max-width:1024px){
  .sdash .kpis{grid-template-columns:repeat(2,1fr)}
  .sdash .row-2{grid-template-columns:1fr}
}
@media(max-width:768px){
  .sdash .kpis{grid-template-columns:1fr 1fr}
  .sdash .row-3{grid-template-columns:1fr}
  .sdash .perf-row{grid-template-columns:1fr}
  .sdash .header-row{align-items:flex-start;flex-direction:column}
}
</style>

<script>
(function(){
  var segBtns = document.querySelectorAll('.sdash .seg button');
  segBtns.forEach(function(btn){
    btn.addEventListener('click', function(){
      segBtns.forEach(function(b){ b.classList.remove('on'); });
      this.classList.add('on');
      var period = this.dataset.period;
      document.querySelectorAll('.sdash .perf-row').forEach(function(row){
        row.classList.toggle('on', row.dataset.period === period);
      });
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
