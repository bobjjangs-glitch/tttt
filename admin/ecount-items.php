<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('ecount');
ensure_ecount_tables();

$pageTitle = '이카운트 품목 관리';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');
$pdo = Database::connection();

/* ===== 액션 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/ecount-items.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $adminId = (int)AdminAuth::currentAdminId();

    if ($action === 'pull') {
        $r = ecount_pull_items();
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-items.php');
    }
    /* [v64] 미매핑 상품 ↔ ERP 품목 자동 매핑 (이름 1:1 일치) */
    if ($action === 'auto_map') {
        $r = ecount_auto_map_products();
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-items.php');
    }
    if ($action === 'register') {
        $r = ecount_save_item([
            'prod_cd'   => (string)($_POST['prod_cd'] ?? ''),
            'prod_des'  => (string)($_POST['prod_des'] ?? ''),
            'size_des'  => (string)($_POST['size_des'] ?? ''),
            'unit'      => (string)($_POST['unit'] ?? ''),
            'in_price'  => (string)($_POST['in_price'] ?? ''),
            'out_price' => (string)($_POST['out_price'] ?? ''),
            'class_cd'  => (string)($_POST['class_cd'] ?? ''),
            'remarks'   => (string)($_POST['remarks'] ?? ''),
        ]);
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-items.php');
    }
    if ($action === 'push_product') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $p = ecount_get_product($productId);
        if (!$p) {
            flash('admin_error', '상품을 찾을 수 없습니다.');
        } else {
            $prodCd = ecount_product_code($p);
            $r = ecount_save_item([
                'prod_cd'   => $prodCd,
                'prod_des'  => (string)$p['name'],
                'size_des'  => trim(($p['spec'] ?? '') !== '' ? (string)$p['spec'] : ((string)$p['model'] ?? '')),
                'unit'      => 'EA',
                'in_price'  => (string)(int)$p['supply_price'],
                'out_price' => (string)(int)$p['price_sale'],
                'remarks'   => '쇼핑몰 상품 연동 등록',
            ]);
            if ($r['ok']) {
                /* 성공 시 상품에 매핑 저장 */
                $pdo->prepare('UPDATE tt_products SET ecount_prod_cd = :cd WHERE id = :id')
                    ->execute(['cd' => $prodCd, 'id' => $productId]);
                AdminAuth::log($adminId, 'ecount_item_push', '상품 ERP 등록: ' . $p['name'] . '(' . $prodCd . ')');
            }
            $r['ok'] ? flash('admin_success', $r['message'] . ' — 상품 매핑도 저장되었습니다.') : flash('admin_error', $r['message']);
        }
        redirect('/admin/ecount-items.php' . ((int)($_POST['page'] ?? 1) > 1 ? '?page=' . (int)$_POST['page'] : ''));
    }
    redirect('/admin/ecount-items.php');
}

/* ===== 목록 (캐시 + 매핑) ===== */
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE i.prod_cd LIKE :q1 OR i.prod_des LIKE :q2 OR i.size_des LIKE :q3';
    $params['q1'] = $params['q2'] = $params['q3'] = '%' . $q . '%';
}

/* [v58] 엑셀(CSV) 다운로드 — 현재 검색 조건의 품목 전체 */
if (($_GET['export'] ?? '') === 'csv') {
    $exp = $pdo->prepare("SELECT i.*, p.name AS local_name FROM tt_ecount_items i
                          LEFT JOIN tt_products p ON p.ecount_prod_cd = i.prod_cd
                          {$where} ORDER BY LENGTH(i.prod_cd), i.prod_cd");
    $exp->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecount-items-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['번호', '품목코드', '품목명', '규격', '단위', '입고단가', '출고단가', '쇼핑몰 매핑 상품', '최근동기화']);
    $n = 0;
    while ($r = $exp->fetch(PDO::FETCH_ASSOC)) {
        $n++;
        fputcsv($out, [
            $n, (string)$r['prod_cd'], (string)$r['prod_des'], (string)$r['size_des'], (string)$r['unit'],
            (string)$r['in_price'], (string)$r['out_price'],
            (string)($r['local_name'] ?? ''), substr((string)$r['synced_at'], 0, 16),
        ]);
    }
    fclose($out);
    exit;
}

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_ecount_items i {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

/* [v58] 품목코드 자연 정렬 — 숫자 코드(1,2,...,10)가 문자열 정렬(1,10,100,2,...)로 밀리는 것 수정 */
$listStmt = $pdo->prepare("SELECT i.*, p.id AS local_id, p.name AS local_name, p.stock AS local_stock
                          FROM tt_ecount_items i
                          LEFT JOIN tt_products p ON p.ecount_prod_cd = i.prod_cd
                          {$where}
                          ORDER BY LENGTH(i.prod_cd), i.prod_cd LIMIT {$perPage} OFFSET {$offset}");
$listStmt->execute($params);
$items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* 미매핑 쇼핑몰 상품 (ERP 등록 대상) */
$unmapped = $pdo->query("SELECT id, name, model, spec, price_sale, supply_price, ecount_prod_cd, sku
                         FROM tt_products WHERE ecount_prod_cd IS NULL OR ecount_prod_cd = ''
                         ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

$st = ecount_status_summary();
require __DIR__ . '/includes/header.php';
?>

<style>
.ec-frm{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end}
.ec-frm label{font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px}
.ec-frm input,.ec-frm select{width:100%}
.mapd{color:#15803d;font-weight:700}
</style>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">📦 이카운트 품목 (품목 등록·조회)</h2>
      <p class="admin-list-sub">ERP 품목을 가져와 조회·검색하고, 신규 품목을 ERP에 등록하며, 쇼핑몰 상품과 ERP 품목코드를 매핑합니다. (ERP 품목 다건조회는 API 정책상 10분에 1회)</p>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="pull">
      <button type="submit" class="btn btn-primary" data-wait="<?= (int)$st['items_pull_wait'] ?>">📥 ERP에서 가져오기<?= ecount_wait_label((int)$st['items_pull_wait']) ?></button>
    </form>
    <!-- [v64] 미매핑 쇼핑몰 상품 자동 매핑 -->
    <?php $unmappedCnt2 = (int)$pdo->query("SELECT COUNT(*) FROM tt_products WHERE ecount_prod_cd IS NULL OR ecount_prod_cd = ''")->fetchColumn(); ?>
    <form method="post" onsubmit="return confirm('ERP 품목과 이름이 정확히 일치하는 미매핑 상품을 자동 매핑할까요?')">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="auto_map">
      <button type="submit" class="btn" title="쇼핑몰 상품과 ERP 품목의 이름이 정확히 1:1로 같은 것을 자동으로 연결해요">🔄 상품 자동 매핑 (미매핑 <?= number_format($unmappedCnt2) ?>건)</button>
    </form>
    <a class="btn" href="<?= BASE_URL ?>/admin/ecount-items.php?export=csv<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">⬇ 엑셀(CSV) 다운로드</a>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <form method="get" style="display:flex;gap:8px;max-width:460px;margin:12px 0">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="품목코드·품목명·규격 검색" style="flex:1">
    <button type="submit" class="btn">검색</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= BASE_URL ?>/admin/ecount-items.php">초기화</a><?php endif; ?>
  </form>

  <!-- [v62] 현재 위치 표시 — 표 위에도 총건수/현재페이지 표시 (34페이지 클릭 시 어디 있는지 바로 보임) -->
  <div id="itemsTableTop" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 6px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:13px">
    <b>📋 <?= number_format($total) ?>건</b>
    <span style="color:#64748b">·</span>
    <span>현재 <b><?= $page ?></b> / <?= $pages ?>페이지</span>
    <span style="color:#64748b">·</span>
    <span><?= $total > 0 ? number_format($offset + 1) . ' ~ ' . number_format($offset + count($items)) . '번 표시 중' : '표시할 항목 없음' ?></span>
    <?php if ($q !== ''): ?><span style="color:#2563eb">· 검색: <?= h($q) ?></span><?php endif; ?>
  </div>

  <div style="overflow:auto">
  <table class="admin-table" style="min-width:900px">
    <thead><tr><th>번호</th><th>품목코드</th><th>품목명</th><th>규격</th><th>단위</th><th>입고단가</th><th>출고단가</th><th>쇼핑몰 상품 매핑</th><th>최근동기화</th></tr></thead>
    <tbody>
    <?php if (!$items): ?><tr><td colspan="9" style="text-align:center;color:#94a3b8">ERP 품목 캐시가 비어 있습니다 — "ERP에서 가져오기"를 눌러 주세요.</td></tr><?php endif; ?>
    <?php $n = $offset; foreach ($items as $it): $n++; ?>
    <tr>
      <td><?= $n ?></td>
      <td><b><?= h($it['prod_cd']) ?></b></td>
      <td><?= h($it['prod_des']) ?></td>
      <td><?= h($it['size_des']) ?></td>
      <td><?= h($it['unit']) ?></td>
      <td style="text-align:right"><?= $it['in_price'] !== null ? number_format((float)$it['in_price']) : '-' ?></td>
      <td style="text-align:right"><?= $it['out_price'] !== null ? number_format((float)$it['out_price']) : '-' ?></td>
      <td><?php if ($it['local_id']): ?>
          <a href="<?= BASE_URL ?>/admin/product_form.php?id=<?= (int)$it['local_id'] ?>" class="mapd">✔ <?= h(mb_substr((string)$it['local_name'], 0, 24)) ?> (재고 <?= (int)$it['local_stock'] ?>)</a>
          <?php else: ?><span style="color:#94a3b8">미매핑</span><?php endif; ?></td>
      <td><?= h(date('m-d H:i', strtotime((string)$it['synced_at']))) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination" style="margin-bottom:4px">
    <?php /* [v58] 창문식 페이지네이션 — 전체 페이지를 전부 나열하지 않고 현재 중심으로 표시 */ ?>
    <?php
      $window = [];
      $from = max(1, $page - 4);
      $to = min($pages, $from + 8);
      $from = max(1, $to - 8);
      for ($i = $from; $i <= $to; $i++) $window[] = $i;
      $qs = $q !== '' ? ('&q=' . urlencode($q)) : '';
      $anchor = '#itemsTableTop'; /* [v62] 페이지 클릭 시 표 상단 위치 표시로 이동 */
    ?>
    <?php if ($page > 1): ?><a class="page-link" href="?page=<?= $page - 1 ?><?= $qs ?><?= $anchor ?>">‹ 이전</a><?php endif; ?>
    <?php if ($from > 1): ?><a class="page-link" href="?page=1<?= $qs ?><?= $anchor ?>">1</a><?php if ($from > 2): ?><span style="color:#94a3b8">…</span><?php endif; ?><?php endif; ?>
    <?php foreach ($window as $i): ?>
      <?php if ($i === $page): ?><span class="page-current"><?= $i ?></span>
      <?php else: ?><a class="page-link" href="?page=<?= $i ?><?= $qs ?><?= $anchor ?>"><?= $i ?></a><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($to < $pages): ?><?php if ($to < $pages - 1): ?><span style="color:#94a3b8">…</span><?php endif; ?><a class="page-link" href="?page=<?= $pages ?><?= $qs ?><?= $anchor ?>"><?= $pages ?></a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="page-link" href="?page=<?= $page + 1 ?><?= $qs ?><?= $anchor ?>">다음 ›</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <!-- [v62] 총건수 표시는 페이지네이션 줄과 분리해 한 줄로 (밀려 보이던 것 수정) -->
  <p class="admin-list-sub" style="margin-top:6px">총 <?= number_format($total) ?>건<?= $pages > 1 ? ' · ' . $page . '/' . $pages . '페이지 · 페이지당 ' . $perPage . '건' : '' ?><?= $q !== '' ? ' · 검색 "' . h($q) . '"' : '' ?></p>
</div>

<!-- ===== ERP 품목 등록 ===== -->
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title">➕ ERP 품목 등록</h2>
      <p class="admin-list-sub">새 품목을 이카운트 ERP와 관리자 캐시에 동시 등록합니다.</p></div>
  </div>
  <form method="post" class="ec-frm" style="max-width:900px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="register">
    <div><label>품목코드 (PROD_CD) *</label><input type="text" name="prod_cd" required maxlength="20" placeholder="예: T00001"></div>
    <div><label>품목명 (PROD_DES) *</label><input type="text" name="prod_des" required maxlength="100"></div>
    <div><label>규격 (SIZE_DES)</label><input type="text" name="size_des" maxlength="100" placeholder="예: 225/65R16"></div>
    <div><label>단위</label><input type="text" name="unit" maxlength="6" value="EA"></div>
    <div><label>입고단가</label><input type="number" name="in_price" min="0" step="1" placeholder="0"></div>
    <div><label>출고단가</label><input type="number" name="out_price" min="0" step="1" placeholder="0"></div>
    <div><label>품목그룹 (CLASS_CD)</label><input type="text" name="class_cd" maxlength="5" placeholder="예: 00001"></div>
    <div><label>적요</label><input type="text" name="remarks" maxlength="100"></div>
    <div><button type="submit" class="btn btn-primary">ERP + 관리자에 등록</button></div>
  </form>
  <p class="admin-list-sub" style="margin-top:10px">※ 창고(WH_CD)는 품목 등록 시 입력하지 않습니다 — 이카운트에서 품목 등록의 WH_CD는 생산공정 코드로 취급되어 미등록 창고코드를 넣으면 "생산공정(미등록코드)" 오류로 거부됩니다. 창고는 재고·전표(발주/판매) 입력 시에만 사용됩니다.</p>
</div>

<!-- ===== 쇼핑몰 상품 → ERP 등록 ===== -->
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title">🛒 쇼핑몰 상품 → ERP 등록·매핑</h2>
      <p class="admin-list-sub">아직 ERP 품목코드에 매핑되지 않은 쇼핑몰 상품입니다. "ERP 등록"을 누르면 품목코드(품번 우선, 없으면 T+상품ID)로 ERP에 등록되고 자동 매핑됩니다.</p></div>
  </div>
  <div style="overflow:auto">
  <table class="admin-table" style="min-width:760px">
    <thead><tr><th>상품ID</th><th>상품명</th><th>규격/모델</th><th>소비자가</th><th>공급가</th><th>예상 품목코드</th><th></th></tr></thead>
    <tbody>
    <?php if (!$unmapped): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">매핑되지 않은 상품이 없습니다 — 전 상품이 ERP와 연결되어 있습니다.</td></tr><?php endif; ?>
    <?php foreach ($unmapped as $p): ?>
    <tr>
      <td>#<?= (int)$p['id'] ?></td>
      <td><?= h(mb_substr((string)$p['name'], 0, 40)) ?></td>
      <td><?= h(trim((string)$p['spec'] ?: (string)$p['model'])) ?></td>
      <td style="text-align:right"><?= number_format((int)$p['price_sale']) ?>원</td>
      <td style="text-align:right"><?= number_format((int)$p['supply_price']) ?>원</td>
      <td><b><?= h(ecount_product_code($p)) ?></b><?= $p['sku'] ? ' <small style="color:#94a3b8">(품번)</small>' : '' ?></td>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="action" value="push_product">
          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="page" value="<?= $page ?>">
          <button type="submit" class="btn btn-primary">ERP 등록</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
/* [v60] 페이지 진입 시 자동 갱신 — 마지막 동기화가 간격을 넘었으면(버튼 대기 없음) 조용히 자동으로 가져오고 새로고침 */
(function(){
  <?php if ((int)$st['items_pull_wait'] === 0): ?>
  var csrf = '<?= Csrf::token() ?>';
  var autoNote = document.createElement('div');
  autoNote.style.cssText = 'margin:8px 0;font-size:13px;color:#1d4ed8';
  autoNote.textContent = '🔄 ERP에서 최신 품목을 자동으로 가져오는 중… (잠시 후 자동으로 새로고침돼요)';
  var head = document.querySelector('.admin-card .admin-list-sub');
  if (head) head.parentNode.insertBefore(autoNote, head.nextSibling);
  fetch('<?= BASE_URL ?>/admin/ajax-ecount.php?action=items_pull', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'csrf_token=' + encodeURIComponent(csrf)
  }).then(function(r){ return r.json(); }).then(function(j){
    if (j.ok) { setTimeout(function(){ location.reload(); }, 600); }
    else if (autoNote.parentNode) { autoNote.remove(); }
  }).catch(function(){ if (autoNote.parentNode) autoNote.remove(); });
  <?php endif; ?>
})();
</script>
