<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('ecount');
ensure_ecount_tables();
ensure_stock_movement_tables();

$pageTitle = '이카운트 창고별 재고';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');
$pdo = Database::connection();

/* ===== 액션 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/ecount-stock.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $adminId = (int)AdminAuth::currentAdminId();

    if ($action === 'pull') {
        $r = ecount_pull_stock();
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-stock.php');
    }
    if ($action === 'apply') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $prodCd = trim((string)($_POST['prod_cd'] ?? ''));
        $qty = (int)($_POST['qty'] ?? 0);
        if ($prodCd !== '') {
            /* 반영 전에 ERP에서 실시간(단건 API) 재고를 다시 조회해 최신값 기준으로 반영 */
            $live = ecount_query_stock_single($prodCd);
            if (($live['ok'] ?? false) && isset($live['total'])) {
                $qty = (int)$live['total'];
            } else {
                /* 실시간 조회 실패 시 캐시 합계로 폴백 */
                $sum = $pdo->prepare('SELECT COALESCE(SUM(CAST(bal_qty AS DECIMAL(18,4))), 0) FROM tt_ecount_stock WHERE prod_cd = :cd');
                $sum->execute(['cd' => $prodCd]);
                $qty = (int)round((float)$sum->fetchColumn());
            }
        }
        $r = ecount_apply_stock_to_local($productId, $qty);
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-stock.php');
    }
    /* [v64] 미매핑 상품 ↔ ERP 품목 자동 매핑 (이름 1:1 일치) */
    if ($action === 'auto_map') {
        $r = ecount_auto_map_products();
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-stock.php' . ($whFilter !== '' ? '?wh=' . urlencode($whFilter) : ''));
    }
    if ($action === 'receipt') {
        /* 관리자 수동 입고 → ERP 생산입고 전송 (ERP 재고 증가) */
        $r = ecount_push_goods_receipt((int)($_POST['product_id'] ?? 0), (int)($_POST['qty'] ?? 0), (string)($_POST['remark'] ?? ''));
        if ($r['ok']) {
            $n = (int)($_POST['qty'] ?? 0);
            $p = ecount_get_product((int)($_POST['product_id'] ?? 0));
            if ($p) {
                $newStock = (int)$p['stock'] + $n;
                $pdo->prepare('UPDATE tt_products SET stock = :st WHERE id = :id')->execute(['st' => $newStock, 'id' => (int)$p['id']]);
                record_stock_movement((int)$p['id'], null, 'in', $n, $newStock, 'ERP 입고 전송 동반 로컬 입고', 'ecount-receipt');
            }
            AdminAuth::log($adminId, 'ecount_receipt', 'ERP 입고 전송 ' . $n . '개');
        }
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-stock.php');
    }
    redirect('/admin/ecount-stock.php');
}

/* ===== 창고 목록 ===== */
$warehouses = [];
foreach ($pdo->query('SELECT wh_cd, wh_des, COUNT(*) c FROM tt_ecount_stock GROUP BY wh_cd, wh_des ORDER BY wh_cd')->fetchAll(PDO::FETCH_ASSOC) as $w) {
    $warehouses[] = $w;
}
$whFilter = trim((string)($_GET['wh'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($whFilter !== '') { $where[] = 's.wh_cd = :wh'; $params['wh'] = $whFilter; }
if ($q !== '') { $where[] = '(s.prod_cd LIKE :q1 OR s.prod_des LIKE :q2)'; $params['q1'] = $params['q2'] = '%' . $q . '%'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* [v58] 엑셀(CSV) 다운로드 — 현재 창고·검색 조건의 창고별 재고 전체 */
if (($_GET['export'] ?? '') === 'csv') {
    $exp = $pdo->prepare("SELECT s.*, p.name AS local_name, p.stock AS local_stock
                          FROM tt_ecount_stock s
                          LEFT JOIN tt_products p ON p.ecount_prod_cd = s.prod_cd
                          {$whereSql} ORDER BY s.prod_cd, s.wh_cd");
    $exp->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecount-stock-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['품목코드', '품목명', '창고코드', '창고명', 'ERP 재고', '쇼핑몰 매핑 상품', '쇼핑몰 재고', '최근동기화']);
    while ($r = $exp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            (string)$r['prod_cd'], (string)$r['prod_des'], (string)$r['wh_cd'], (string)$r['wh_des'],
            (string)$r['bal_qty'], (string)($r['local_name'] ?? ''), (string)($r['local_stock'] ?? ''),
            substr((string)$r['synced_at'], 0, 16),
        ]);
    }
    fclose($out);
    exit;
}

$rows = $pdo->prepare("SELECT s.*, p.id AS local_id, p.name AS local_name, p.stock AS local_stock
                      FROM tt_ecount_stock s
                      LEFT JOIN tt_products p ON p.ecount_prod_cd = s.prod_cd
                      {$whereSql}
                      ORDER BY s.prod_cd, s.wh_cd
                      LIMIT 300");
$rows->execute($params);
$stockRows = $rows->fetchAll(PDO::FETCH_ASSOC);

/* ERP 합계가 로컬 재고와 다른 매핑 상품 목록 (불일치 요약) [v64] ecount_prod_cd 포함 */
$mismatch = $pdo->query("SELECT p.id, p.name, p.ecount_prod_cd, p.stock AS local_stock, COALESCE(SUM(CAST(s.bal_qty AS DECIMAL(18,4))),0) AS erp_total
                         FROM tt_products p JOIN tt_ecount_stock s ON s.prod_cd = p.ecount_prod_cd
                         GROUP BY p.id, p.name, p.ecount_prod_cd, p.stock
                         HAVING p.stock <> CAST(COALESCE(SUM(CAST(s.bal_qty AS DECIMAL(18,4))),0) AS SIGNED)
                         LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

$st = ecount_status_summary();

/* [v60] 총재고(전 창고 합계, 재고현황 API) 캐시 */
$totalRows = $pdo->query("SELECT t.*, p.id AS local_id, p.name AS local_name, p.stock AS local_stock
                           FROM tt_ecount_stock_total t
                           LEFT JOIN tt_products p ON p.ecount_prod_cd = t.prod_cd
                           ORDER BY t.bal_qty + 0 DESC, t.prod_cd LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$lastSync = $st['last_stock_sync'] > 0 ? date('Y-m-d H:i:s', $st['last_stock_sync']) : '아직 없음';
require __DIR__ . '/includes/header.php';
?>

<style>
.ec-wh-chip{display:inline-block;padding:4px 12px;border-radius:99px;border:1px solid #e2e8f0;background:#fff;margin:0 6px 6px 0;font-size:13px;font-weight:600;color:#475569;text-decoration:none}
.ec-wh-chip.on{background:#2563eb;color:#fff;border-color:#2563eb}
.mm-red{color:#b91c1c;font-weight:700}
.mm-green{color:#15803d;font-weight:700}
</style>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🏬 이카운트 창고별 재고</h2>
      <p class="admin-list-sub">ERP의 창고별 재고현황입니다. 마지막 동기화: <b><?= h($lastSync) ?></b> — "가져오기" 버튼 또는 10분 주기 자동동기화로 갱신됩니다. 매핑된 상품은 "반영" 버튼으로 쇼핑몰 재고에 그대로 적용할 수 있습니다.</p>
    </div>
    <div style="display:flex;gap:8px">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
        <input type="hidden" name="action" value="pull">
        <button type="submit" class="btn btn-primary" data-wait="<?= (int)$st['stock_pull_wait'] ?>">📥 전체 가져오기<?= ecount_wait_label((int)$st['stock_pull_wait']) ?></button>
      </form>
      <!-- [v64] 미매핑 상품 자동 매핑 — 이름이 1:1로 일치하면 원클릭 연결 -->
      <?php $unmappedCnt = (int)$pdo->query("SELECT COUNT(*) FROM tt_products WHERE ecount_prod_cd IS NULL OR ecount_prod_cd = ''")->fetchColumn(); ?>
      <form method="post" onsubmit="return confirm('ERP 품목과 이름이 정확히 일치하는 미매핑 상품('<?= number_format($unmappedCnt) ?>'건 중)을 자동 매핑할까요?')">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
        <input type="hidden" name="action" value="auto_map">
        <button type="submit" class="btn" title="쇼핑몰 상품과 ERP 품목의 이름이 정확히 1:1로 같은 것을 자동으로 연결해요">🔄 자동 매핑 (미매핑 <?= number_format($unmappedCnt) ?>건)</button>
      </form>
      <a class="btn" href="<?= BASE_URL ?>/admin/ecount-stock.php?export=csv<?= $whFilter !== '' || $q !== '' ? ('&' . ($whFilter !== '' ? 'wh=' . urlencode($whFilter) . '&' : '') . ($q !== '' ? 'q=' . urlencode($q) : '')) : '' ?>">⬇ 엑셀(CSV) 다운로드</a>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- [v58] 실시간 재고 빠른조회 — 품목 1개, 이카운트 단건 API(1초)로 항상 즉시 조회 -->
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:12px 0;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px">
    <div style="flex:1;min-width:240px"><label style="font-size:12.5px;font-weight:700;color:#166534;display:block;margin-bottom:4px">⚡ 실시간 재고 확인 (품목코드 1개 · 항상 즉시 · 1초 단위)</label>
      <input type="text" id="rtProdCd" placeholder="품목코드 입력 (예: 00001)" style="width:100%">
    </div>
    <button type="button" class="btn btn-primary" id="rtBtn">⚡ 실시간 조회</button>
    <span id="rtResult" style="font-size:13px;color:#166534"></span>
  </div>
  <table class="admin-table" id="rtTable" style="display:none;min-width:560px;margin:8px 0 4px">
    <thead><tr><th>품목코드</th><th>품목명</th><th>창고</th><th>실시간 재고</th></tr></thead>
    <tbody></tbody>
  </table>

  <?php if ($warehouses): ?>
  <div style="margin:10px 0 4px">
    <a class="ec-wh-chip <?= $whFilter === '' ? 'on' : '' ?>" href="<?= BASE_URL ?>/admin/ecount-stock.php?q=<?= urlencode($q) ?>">전체 창고</a>
    <?php foreach ($warehouses as $w): ?>
      <a class="ec-wh-chip <?= $whFilter === $w['wh_cd'] ? 'on' : '' ?>" href="<?= BASE_URL ?>/admin/ecount-stock.php?wh=<?= urlencode((string)$w['wh_cd']) ?>&q=<?= urlencode($q) ?>">
        <?= h($w['wh_cd']) ?> <?= h(mb_substr((string)$w['wh_des'], 0, 14)) ?> <small>(<?= (int)$w['c'] ?>)</small>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="get" style="display:flex;gap:8px;max-width:460px;margin:8px 0">
    <input type="hidden" name="wh" value="<?= h($whFilter) ?>">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="품목코드·품목명 검색" style="flex:1">
    <button type="submit" class="btn">검색</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= BASE_URL ?>/admin/ecount-stock.php?wh=<?= urlencode($whFilter) ?>">초기화</a><?php endif; ?>
  </form>

  <div style="overflow:auto">
  <!-- [v64] 칸 정렬 — 품목별 첫 행에만 쇼핑몰 상품/재고/동작 표시(창고 행마다 반복돼 밀려 보이던 것 수정) + 숫자 칸 nowrap -->
  <table class="admin-table" style="min-width:940px">
    <thead><tr><th>품목코드</th><th>품목명[규격]</th><th>창고</th><th style="min-width:90px">ERP 재고</th><th style="min-width:170px">쇼핑몰 상품</th><th style="min-width:90px">쇼핑몰 재고</th><th>동작</th></tr></thead>
    <tbody>
    <?php if (!$stockRows): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">재고 캐시가 비어 있습니다 — 화면에 들어오면 자동으로 가져오고, "전체 가져오기"로도 가져올 수 있어요.</td></tr><?php endif; ?>
    <?php $lastProdCd = null; foreach ($stockRows as $r):
        $qty = (float)$r['bal_qty']; ?>
    <tr>
      <td><b><?= h($r['prod_cd']) ?></b></td>
      <td><?= h(mb_substr((string)$r['prod_des'], 0, 34)) ?></td>
      <td><?= h($r['wh_cd']) ?> <?= h(mb_substr((string)$r['wh_des'], 0, 12)) ?></td>
      <td style="text-align:right;font-weight:700;white-space:nowrap"><?= number_format($qty, $qty == (int)$qty ? 0 : 1) ?></td>
      <?php if ((string)$r['prod_cd'] !== $lastProdCd): $lastProdCd = (string)$r['prod_cd']; /* 품목 첫 창고 행에만 표시 */ ?>
      <td><?php if ($r['local_id']): ?>
          <a href="<?= BASE_URL ?>/admin/product_form.php?id=<?= (int)$r['local_id'] ?>"><?= h(mb_substr((string)$r['local_name'], 0, 22)) ?></a>
          <?php else: ?><span style="color:#94a3b8">미매핑</span><?php endif; ?></td>
      <td style="text-align:right;white-space:nowrap"><?= $r['local_id'] !== null ? (int)$r['local_stock'] : '-' ?></td>
      <td>
        <?php if ($r['local_id']): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="action" value="apply">
          <input type="hidden" name="product_id" value="<?= (int)$r['local_id'] ?>">
          <input type="hidden" name="prod_cd" value="<?= h($r['prod_cd']) ?>">
          <input type="hidden" name="qty" value="<?= (int)round($qty) ?>">
          <button type="submit" class="btn" title="이 품목의 ERP 전 창고 합계를 쇼핑몰 재고로 반영">ERP 합계로 반영</button>
        </form>
        <?php endif; ?>
      </td>
      <?php else: ?><td style="color:#cbd5e1">〃</td><td style="color:#cbd5e1">〃</td><td style="color:#cbd5e1">〃</td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($whFilter === '' && !$q && $stockRows): ?>
  <div class="alert" style="background:#f8fafc;border:1px solid #e2e8f0;color:#475569;margin-top:12px">
    💡 "ERP 합계로 반영"은 그 품목의 <b>ERP 전 창고 합계</b>를 쇼핑몰 재고로 덮어씁니다. 10분 주기 <b>자동 반영</b>은 <a href="<?= BASE_URL ?>/admin/ecount.php" style="text-decoration:underline">연동 대시보드</a>에서 켜고 끌 수 있습니다.
  </div>
  <?php endif; ?>
</div>

<!-- ===== [v60] 전체 재고현황(총재고) ===== -->
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title">📊 전체 재고현황 (총재고 — 전 창고 합계)</h2>
      <p class="admin-list-sub">ERP 재고현황 API로 받아온 품목별 총재고(모든 창고 합계)예요. 위 창고별 표의 전 창고 합계와 같은 기준이에요 — 매핑된 상품은 쇼핑몰 재고에 반영할 수 있어요.</p></div>
    <div style="display:flex;gap:8px">
      <button type="button" class="btn btn-primary" id="stTotalBtn" data-wait="<?= (int)$st['stock_total_pull_wait'] ?>">📥 총재고 가져오기<?= ecount_wait_label((int)$st['stock_total_pull_wait']) ?></button>
      <a class="btn" href="<?= BASE_URL ?>/admin/ecount-stock.php?export=csv">⬇ 엑셀(CSV)</a>
    </div>
  </div>
  <div id="stTotalNote" style="display:none;margin:8px 0;font-size:13px;color:#1d4ed8"></div>
  <div style="overflow:auto">
  <table class="admin-table" style="min-width:680px">
    <thead><tr><th>품목코드</th><th>품목명</th><th>ERP 총재고</th><th>쇼핑몰 상품</th><th>쇼핑몰 재고</th><th>동작</th></tr></thead>
    <tbody>
    <?php if (!$totalRows): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">총재고 캐시가 비어 있습니다 — "총재고 가져오기"를 눌러 주세요.</td></tr><?php endif; ?>
    <?php foreach ($totalRows as $t):
        $tq = (float)$t['bal_qty']; ?>
    <tr>
      <td><b><?= h($t['prod_cd']) ?></b></td>
      <td><?= h(mb_substr((string)$t['prod_des'], 0, 34)) ?></td>
      <td style="text-align:right;font-weight:700"><?= number_format($tq, $tq == (int)$tq ? 0 : 1) ?></td>
      <td><?php if ($t['local_id']): ?>
          <a href="<?= BASE_URL ?>/admin/product_form.php?id=<?= (int)$t['local_id'] ?>"><?= h(mb_substr((string)$t['local_name'], 0, 22)) ?></a>
          <?php else: ?><span style="color:#94a3b8">미매핑</span><?php endif; ?></td>
      <td style="text-align:right"><?= $t['local_id'] !== null ? (int)$t['local_stock'] : '-' ?></td>
      <td>
        <?php if ($t['local_id']): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="action" value="apply">
          <input type="hidden" name="product_id" value="<?= (int)$t['local_id'] ?>">
          <input type="hidden" name="prod_cd" value="<?= h($t['prod_cd']) ?>">
          <input type="hidden" name="qty" value="<?= (int)round($tq) ?>">
          <button type="submit" class="btn">ERP 총재고로 반영</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ===== 로컬 vs ERP 재고 불일치 ===== -->
<?php if ($mismatch): ?>
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title">⚖️ 쇼핑몰 vs ERP 재고 불일치 (전 창고 합계 기준)</h2>
      <p class="admin-list-sub">자동 동기화가 꺼져 있거나 아직 반영 전인 상품입니다. "전체 가져오기"를 누르면 자동 반영됩니다(동기화 켜짐 시).</p></div>
  </div>
  <div style="overflow:auto">
  <!-- [v64] 칸 정렬 수정 — 품목코드 열 추가 + 상품명 최소폭 + 숫자 칸 nowrap -->
  <table class="admin-table" style="min-width:760px">
    <thead><tr><th>ERP 코드</th><th style="min-width:220px">상품</th><th style="min-width:110px">쇼핑몰 재고</th><th style="min-width:110px">ERP 합계</th><th style="min-width:90px">차이</th></tr></thead>
    <tbody>
    <?php foreach ($mismatch as $m): $diff = (int)round((float)$m['erp_total']) - (int)$m['local_stock']; ?>
    <tr>
      <td style="font-family:monospace"><?= h((string)($m['ecount_prod_cd'] ?: '-')) ?></td>
      <td>#<?= (int)$m['id'] ?> <?= h(mb_substr((string)$m['name'], 0, 32)) ?></td>
      <td style="text-align:right;white-space:nowrap;font-weight:700"><?= (int)$m['local_stock'] ?></td>
      <td style="text-align:right;white-space:nowrap;font-weight:700"><?= (int)round((float)$m['erp_total']) ?></td>
      <td style="text-align:right;white-space:nowrap" class="<?= $diff > 0 ? 'mm-green' : 'mm-red' ?>"><?= $diff > 0 ? '+' : '' ?><?= $diff ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- ===== 수동 입고 → ERP 전송 ===== -->
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title">📤 쇼핑몰 입고 → ERP 반영</h2>
      <p class="admin-list-sub">쇼핑몰에서 재고를 채웠을 때 ERP에도 실시간으로 채우려면 이 폼으로 입고를 전송하세요 — ERP 생산입고로 재고가 증가하고, 쇼핑몰 재고도 함께 증가합니다.</p></div>
  </div>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;max-width:760px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="receipt">
    <select name="product_id" required style="flex:2;min-width:260px">
      <option value="">상품 선택 (ERP 매핑된 상품)</option>
      <?php foreach ($pdo->query("SELECT id, name, ecount_prod_cd, stock FROM tt_products WHERE ecount_prod_cd IS NOT NULL AND ecount_prod_cd <> '' ORDER BY name LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) as $p): ?>
      <option value="<?= (int)$p['id'] ?>">#<?= (int)$p['id'] ?> <?= h(mb_substr((string)$p['name'], 0, 30)) ?> · ERP <?= h($p['ecount_prod_cd']) ?> · 재고 <?= (int)$p['stock'] ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" name="qty" min="1" required placeholder="수량" style="width:100px">
    <input type="text" name="remark" maxlength="100" placeholder="적요(선택)" style="flex:1;min-width:160px">
    <button type="submit" class="btn btn-primary">ERP 입고 전송</button>
  </form>
</div>

<script>
/* [v58] 실시간 재고 빠른조회 — 이카운트 단건 API(1초 1회)로 품목 1개를 항상 즉시 조회 */
(function(){
  var btn = document.getElementById('rtBtn');
  var input = document.getElementById('rtProdCd');
  var result = document.getElementById('rtResult');
  var table = document.getElementById('rtTable');
  if (!btn || !input) return;
  btn.addEventListener('click', function(){
    var cd = input.value.trim();
    if (!cd) { result.textContent = '품목코드를 입력해 주세요.'; return; }
    result.textContent = '조회 중…';
    table.style.display = 'none';
    fetch('<?= BASE_URL ?>/admin/ajax-ecount.php?action=stock_single&prod_cd=' + encodeURIComponent(cd), {credentials: 'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        var tb = table.querySelector('tbody');
        tb.innerHTML = '';
        if (!j.ok) { result.textContent = '❌ ' + (j.message || '조회 실패'); return; }
        var total = 0;
        (j.rows || []).forEach(function(row){
          total += parseFloat(row.bal_qty) || 0;
          var tr = document.createElement('tr');
          tr.innerHTML = '<td><b>' + row.wh_cd + '</b></td><td>' + (row.wh_des || '') + '</td><td>' + (row.prod_des || '') + '</td><td style="text-align:right;font-weight:700">' + row.bal_qty + '</td>';
          tb.appendChild(tr);
        });
        if (!(j.rows || []).length) { result.textContent = '⚠ 해당 품목코드의 재고가 없습니다 (코드 확인 필요 — 목록은 위 표/품목 관리에서 확인)'; return; }
        table.style.display = '';
        result.innerHTML = '✅ <b>' + cd + '</b> 실시간 재고 합계 <b>' + total + '</b> (창고 ' + j.rows.length + '곳)';
      })
      .catch(function(){ result.textContent = '❌ 통신 오류'; });
  });
  input.addEventListener('keyup', function(e){ if (e.key === 'Enter') btn.click(); });
})();
</script>

<script>
/* [v60] 총재고 가져오기 + 들어올 때 자동 갱신 (이카운트처럼 바로바로) */
(function(){
  var csrf = '<?= Csrf::token() ?>';
  function post(action, onDone){
    return fetch('<?= BASE_URL ?>/admin/ajax-ecount.php?action=' + action, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'csrf_token=' + encodeURIComponent(csrf)
    }).then(function(r){ return r.json(); }).then(onDone);
  }
  var totalBtn = document.getElementById('stTotalBtn');
  var totalNote = document.getElementById('stTotalNote');
  if (totalBtn) totalBtn.addEventListener('click', function(){
    totalBtn.disabled = true;
    if (totalNote) { totalNote.style.display = ''; totalNote.textContent = 'ERP에서 총재고를 가져오는 중…'; }
    post('stock_total_pull', function(j){
      if (j.ok) { if (totalNote) totalNote.textContent = '✅ ' + (j.message || '완료') + ' — 목록을 새로고침합니다…'; setTimeout(function(){ location.reload(); }, 700); }
      else { if (totalNote) totalNote.textContent = '⏳ ' + (j.message || '간격 제한'); totalBtn.disabled = false; }
    }).catch(function(){ if (totalNote) totalNote.textContent = '❌ 통신 오류'; totalBtn.disabled = false; });
  });
  /* [v60] 페이지 진입 시 자동 갱신 — 마지막 동기화가 간격을 넘었으면(버튼 대기 없음) 조용히 자동으로 가져오고 새로고침 */
  <?php if ((int)$st['stock_pull_wait'] === 0): ?>
  var autoNote = document.createElement('div');
  autoNote.style.cssText = 'margin:8px 0;font-size:13px;color:#1d4ed8';
  autoNote.textContent = '🔄 ERP에서 최신 재고를 자동으로 가져오는 중… (잠시 후 자동으로 새로고침돼요)';
  var firstCard = document.querySelector('.admin-card .admin-list-sub');
  if (firstCard) firstCard.parentNode.insertBefore(autoNote, firstCard.nextSibling);
  post('stock_pull', function(j){
    if (j.ok) { setTimeout(function(){ location.reload(); }, 600); }
    else if (autoNote.parentNode) { autoNote.remove(); }
  }).catch(function(){ if (autoNote.parentNode) autoNote.remove(); });
  <?php endif; ?>
})();
</script>
