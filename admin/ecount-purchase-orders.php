<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('ecount');
ensure_ecount_tables();

$pageTitle = '이카운트 발주서 (입력·조회)';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');
$pdo = Database::connection();

/* [v58] 엑셀(CSV) 다운로드 — 현재 조회·필터된 구매현황 */
if (($_GET['export'] ?? '') === 'csv') {
    $rows = ecount_filter_po_rows($_SESSION['ecount_po_rows'] ?? [], trim((string)($_SESSION['ecount_po_kw'] ?? '')));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecount-orders-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM — 엑셀에서 한글 깨짐 방지
    fputcsv($out, ['발주번호', '발주일자', '거래처코드', '거래처명', '품목', '수량', '공급가액', '부가세', '창고', '상태', '납기일']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string)($r['ORD_NO'] ?? ''),
            substr((string)($r['ORD_DATE'] ?? ''), 0, 8),
            (string)($r['CUST'] ?? ''),
            (string)($r['CUST_DES'] ?? ''),
            (string)($r['PROD_DES'] ?? ''),
            (string)($r['QTY'] ?? ''),
            (string)($r['BUY_AMT'] ?? ''),
            (string)($r['VAT_AMT'] ?? ''),
            (string)($r['WH_DES'] ?? ($r['WH_CD'] ?? '')),
            ((string)($r['P_FLAG'] ?? '') === '9') ? '종결' : '진행중',
            substr((string)($r['TIME_DATE'] ?? ''), 0, 8),
        ]);
    }
    fclose($out);
    exit;
}

/* 조회 결과는 세션에 잠깐 보관(POST→리다이렉트 패턴) */
$poRows = $_SESSION['ecount_po_rows'] ?? [];
$poTotal = (int)($_SESSION['ecount_po_total'] ?? 0);
$poRange = $_SESSION['ecount_po_range'] ?? ['from' => date('Y-m-d', strtotime('-14 days')), 'to' => date('Y-m-d')];
$poKw = trim((string)($_SESSION['ecount_po_kw'] ?? ''));

/* ===== 액션 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/ecount-purchase-orders.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $adminId = (int)AdminAuth::currentAdminId();

    if ($action === 'search') {
        $from = (string)($_POST['date_from'] ?? date('Y-m-d', strtotime('-14 days')));
        $to = (string)($_POST['date_to'] ?? date('Y-m-d'));
        $fromYmd = preg_replace('/[^0-9]/', '', $from) ?: date('Ymd', strtotime('-14 days'));
        $toYmd = preg_replace('/[^0-9]/', '', $to) ?: date('Ymd');
        /* API 정책: 최대 30일 범위 */
        if (strtotime($fromYmd) > strtotime($toYmd)) {
            flash('admin_error', '시작일이 종료일보다 늦습니다.');
            redirect('/admin/ecount-purchase-orders.php');
        }
        if ((strtotime($toYmd) - strtotime($fromYmd)) / 86400 > 30) {
            flash('admin_error', '발주서 조회는 최대 30일 범위까지 가능합니다.');
            redirect('/admin/ecount-purchase-orders.php');
        }
        $kw = trim((string)($_POST['cust_kw'] ?? ''));
        $_SESSION['ecount_po_kw'] = $kw;
        $r = ecount_fetch_purchase_orders($fromYmd, $toYmd, false);
        if ($r['ok']) {
            $_SESSION['ecount_po_rows'] = $r['rows'];
            $_SESSION['ecount_po_total'] = $r['total'];
            $_SESSION['ecount_po_range'] = ['from' => $from, 'to' => $to];
            $matched = count(ecount_filter_po_rows($r['rows'], $kw));
            flash('admin_success', $r['message'] . ' — 기간: ' . $from . ' ~ ' . $to . ' (ERP 총 ' . $r['total'] . '건)'
                . ($kw !== '' ? ' · 거래처 "' . $kw . '" 일치 ' . $matched . '건' : ''));
        } else {
            unset($_SESSION['ecount_po_rows'], $_SESSION['ecount_po_total']);
            flash('admin_error', $r['message']);
        }
        redirect('/admin/ecount-purchase-orders.php');
    }

    /* [v54] 거래처 필터만 적용 (ERP 호출 없음 — 마지막 조회 결과에서 필터) */
    if ($action === 'filter') {
        $kw = trim((string)($_POST['cust_kw'] ?? ''));
        $_SESSION['ecount_po_kw'] = $kw;
        if ($kw === '') {
            flash('admin_success', '거래처 필터를 해제했습니다.');
        } elseif (empty($_SESSION['ecount_po_rows'])) {
            flash('admin_error', '먼저 기간을 정해 "ERP에서 조회"로 목록을 가져온 뒤 거래처 검색을 사용해 주세요.');
        } else {
            $matched = count(ecount_filter_po_rows($_SESSION['ecount_po_rows'], $kw));
            flash('admin_success', '거래처 "' . $kw . '" 일치 ' . $matched . '건 / 조회 ' . count($_SESSION['ecount_po_rows']) . '건' . ($matched === 0 ? ' — 다른 검색어로 시도하거나 기간을 넓혀보세요.' : ''));
        }
        redirect('/admin/ecount-purchase-orders.php');
    }

    if ($action === 'create') {
        $custCd = (string)($_POST['cust_cd'] ?? '');
        $custName = (string)($_POST['cust_name'] ?? '');
        $ioDate = (string)($_POST['io_date'] ?? date('Y-m-d'));
        $ioDateYmd = preg_replace('/[^0-9]/', '', $ioDate) ?: date('Ymd');
        $lines = [];
        foreach (($_POST['line_prod'] ?? []) as $i => $prodCd) {
            $prodCd = trim((string)$prodCd);
            $qty = (int)($_POST['line_qty'][$i] ?? 0);
            $price = (int)str_replace([',', ''], '', (string)($_POST['line_price'][$i] ?? 0));
            $prodDes = trim((string)($_POST['line_prod_des'][$i] ?? ''));
            if ($prodCd === '' || $qty <= 0) continue;
            $lines[] = ['prod_cd' => $prodCd, 'qty' => $qty, 'price' => $price, 'prod_des' => $prodDes];
        }
        $r = ecount_save_purchase_order($lines, $custCd, $custName, $ioDateYmd);
        if ($r['ok']) AdminAuth::log($adminId, 'ecount_purchase', '발주 입력 ' . count($lines) . '라인 → ' . $custName);
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-purchase-orders.php');
    }
    redirect('/admin/ecount-purchase-orders.php');
}

/* 폼용 데이터 */
$partners = $pdo->query('SELECT cust_cd, cust_name FROM tt_ecount_partners ORDER BY cust_name LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query('SELECT prod_cd, prod_des, size_des, in_price FROM tt_ecount_items ORDER BY prod_cd LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
$st = ecount_status_summary();

/* [v54] 거래처 검색 필터 적용(표시용) — 원본 목록은 세션에 그대로 */
$poAll = $poRows;
$poRows = ecount_filter_po_rows($poRows, $poKw);
require __DIR__ . '/includes/header.php';
?>

<style>
.po-form{display:grid;gap:10px}
.po-form label{font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px}
.po-lines{display:flex;flex-direction:column;gap:8px}
.po-line{display:grid;grid-template-columns:2.2fr 0.7fr 1fr auto;gap:8px;align-items:center}
.po-line .des{font-size:12px;color:#94a3b8;grid-column:span 4}
.po-add{align-self:start}
@media(max-width:720px){.po-line{grid-template-columns:1fr 0.6fr 1fr auto}}
</style>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🧾 이카운트 구매현황(발주서) — 조회 · 입력</h2>
      <p class="admin-list-sub">ERP 구매현황을 기간·거래처별로 조회합니다. 거래처명(또는 거래처코드)을 입력하면 그 거래처의 구매내역만 나옵니다. (조회 API는 10분에 1회 · 최대 30일 범위)</p>
    </div>
    <a class="btn" href="<?= BASE_URL ?>/admin/ecount.php">🔗 연동 대시보드</a>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- ===== 년도 전체 조회 (30일 자동 분할) ===== -->
  <?php
    /* [v59] 진행 중인 년도 이어받기 상태 */
    $yearInProg = (int)($_SESSION['ecount_po_year_year'] ?? 0);
    $yearNext = (int)($_SESSION['ecount_po_year_next'] ?? 0);
    $yearResume = ($yearInProg > 0 && $yearNext > 0);
  ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:12px 0;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">
    <div><label style="font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px">년도 전체 조회</label>
      <select id="poYearSel">
        <?php for ($y = (int)date('Y'); $y >= 2022; $y--): ?>
        <option value="<?= $y ?>" <?= $y === ($yearResume ? $yearInProg : (int)date('Y')) ? 'selected' : '' ?>><?= $y ?>년</option>
        <?php endfor; ?>
      </select></div>
    <button type="button" class="btn btn-primary" id="poYearBtn"><?= $yearResume ? '▶ ' . $yearInProg . '년 이어받기 (기간 ' . $yearNext . '/13부터)' : '📅 년도 전체 조회 (30일 단위 자동 분할)' ?></button>
    <?php if ($yearResume): ?>
    <button type="button" class="btn" id="poYearReset">↺ 처음부터 다시</button>
    <?php endif; ?>
    <a class="btn" id="poExportBtn" href="<?= BASE_URL ?>/admin/ecount-purchase-orders.php?export=csv">⬇ 엑셀(CSV) 다운로드</a>
    <span style="font-size:12px;color:#64748b">30일 단위로 나눠 자동 진행(기간당 약 2초) — 진행 중 새로고침/이탈해도 같은 년도면 자동으로 이어받혀요.</span>
  </div>
  <div id="poYearProgress" style="display:none;margin:10px 0;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:13px"></div>

  <!-- ===== 조회 ===== -->
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:12px 0">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <div><label style="font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px">시작일</label>
      <input type="date" name="date_from" value="<?= h((string)$poRange['from']) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div><label style="font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px">종료일</label>
      <input type="date" name="date_to" value="<?= h((string)$poRange['to']) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div style="flex:1;min-width:220px"><label style="font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px">거래처 검색 (이름·코드)</label>
      <input type="text" name="cust_kw" id="poKw" list="poPartnerList" value="<?= h($poKw) ?>" placeholder="예: 윈터 — 거래처명 일부만 쳐도 자동완성 목록이 떠요" style="width:100%"></div>
    <button type="submit" name="action" value="search" class="btn btn-primary" data-wait="<?= (int)$st['orders_pull_wait'] ?>">🔎 ERP에서 조회<?= ecount_wait_label((int)$st['orders_pull_wait']) ?></button>
    <button type="submit" name="action" value="filter" class="btn" title="ERP 호출 없이 마지막 조회 결과에서 거래처만 필터">🎯 거래처 필터만</button>
  </form>
  <?php
  /* [v58] 거래처 자동완성 목록 — 등록·수집된 거래처 + 조회된 구매현황의 거래처 */
  $acSource = [];
  foreach ((array)$partners as $p) {
      $acSource[trim((string)$p['cust_name'])] = true;
  }
  foreach ((array)$poAll as $r) {
      $nm = trim((string)($r['CUST_DES'] ?? ''));
      if ($nm !== '') $acSource[$nm] = true;
  }
  ?>
  <datalist id="poPartnerList">
    <?php foreach (array_keys($acSource) as $nm): ?>
    <option value="<?= h(mb_substr($nm, 0, 100)) ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <?php if ($poKw !== '' && $poAll): ?><div class="alert alert-success" style="margin-bottom:10px">거래처 필터 "<b><?= h($poKw) ?></b>" — 일치 <?= count($poRows) ?>건 / 조회 <?= count($poAll) ?>건 · <span id="poFilterCap" style="color:#64748b"></span>필터를 해제하려면 검색칸을 비우고 "거래처 필터만"을 누르세요.</div><?php endif; ?>

  <div style="overflow:auto">
  <table class="admin-table" id="poTable" style="min-width:900px">
    <thead><tr><th>발주번호</th><th>발주일자</th><th>거래처</th><th>품목</th><th>수량</th><th>공급가액</th><th>부가세</th><th>창고</th><th>상태</th><th>납기</th></tr></thead>
    <tbody>
    <?php if (!$poAll): ?><tr><td colspan="10" style="text-align:center;color:#94a3b8">조회된 발주서가 없습니다 — 기간을 정하고 "ERP에서 조회"를 눌러 주세요.</td></tr>
    <?php elseif (!$poRows): ?><tr><td colspan="10" style="text-align:center;color:#94a3b8">거래처 "<?= h($poKw) ?>" 와 일치하는 구매내역이 이 기간 조회분에는 없습니다 — 검색어를 줄여보거나(예: "윈터") 기간을 넓혀 다시 조회해 보세요.</td></tr><?php endif; ?>
    <?php foreach ($poRows as $r): ?>
    <tr data-cust="<?= h(mb_strtolower(trim((string)($r['CUST_DES'] ?? '')) . ' ' . trim((string)($r['CUST'] ?? '')))) ?>">
      <td><b><?= h((string)($r['ORD_NO'] ?? '')) ?></b></td>
      <td><?= h(substr((string)($r['ORD_DATE'] ?? ''), 0, 8)) ?></td>
      <td><?= h(mb_substr((string)($r['CUST_DES'] ?? ''), 0, 18)) ?><?= !empty($r['CUST']) ? ' <small style="color:#94a3b8">(' . h((string)$r['CUST']) . ')</small>' : '' ?></td>
      <td><?= h(mb_substr((string)($r['PROD_DES'] ?? ''), 0, 30)) ?></td>
      <td style="text-align:right"><?= number_format((float)($r['QTY'] ?? 0)) ?></td>
      <td style="text-align:right"><?= number_format((float)($r['BUY_AMT'] ?? 0)) ?></td>
      <td style="text-align:right"><?= number_format((float)($r['VAT_AMT'] ?? 0)) ?></td>
      <td><?= h(mb_substr((string)($r['WH_DES'] ?? ($r['WH_CD'] ?? '')), 0, 12)) ?></td>
      <td><?php if ((string)($r['P_FLAG'] ?? '') === '9'): ?><span class="ec-flag ec-off">종결</span><?php else: ?><span class="ec-flag ec-on">진행중</span><?php endif; ?></td>
      <td><?= h(substr((string)($r['TIME_DATE'] ?? ''), 0, 8)) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($poAll): ?><p class="admin-list-sub" style="margin-top:8px"><?php if ($poKw !== ''): ?>필터 "<?= h($poKw) ?>" 일치 <?= count($poRows) ?>건 / 조회 <?= count($poAll) ?>건<?php else: ?>표시 <?= count($poRows) ?>건<?php endif; ?> · ERP 전체 <?= number_format($poTotal) ?>건 (조회 시 최대 300건까지 표시)</p><?php endif; ?>
</div>

<!-- ===== 발주서 입력 ===== -->
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title">➕ 발주서 입력 (ERP 발주서)</h2>
      <p class="admin-list-sub">입력한 발주는 ERP에 <b>발주서</b>로 저장돼서 바로 위 조회(구매현황)에 나타나요 — 이카운트에서 발주서입력/발주서조회로 확인할 수도 있어요. (발주서는 발주 단계라 ERP 재고는 바뀌지 않고, 입고 때 구매(입고) 전표로 처리돼요)</p></div>
  </div>
  <form method="post" class="po-form" style="max-width:860px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="create">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <div style="flex:2;min-width:240px"><label>거래처 *</label>
        <select name="cust_sel" id="poCust" required>
          <option value="">거래처 선택</option>
          <?php foreach ($partners as $p): ?>
          <option value="<?= h($p['cust_cd']) ?>|<?= h($p['cust_name']) ?>"><?= h($p['cust_name']) ?> (<?= h($p['cust_cd']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="cust_cd" id="poCustCd">
        <input type="hidden" name="cust_name" id="poCustName">
        <div class="field-sub"><a href="<?= BASE_URL ?>/admin/ecount-partners.php" style="text-decoration:underline">거래처가 없다면 먼저 등록</a></div>
      </div>
      <div style="flex:1;min-width:150px"><label>발주일자</label>
        <input type="date" name="io_date" value="<?= date('Y-m-d') ?>"></div>
    </div>

    <div><label>품목 라인</label>
      <div class="po-lines" id="poLines"></div>
      <button type="button" class="btn po-add" style="margin-top:8px" onclick="poAdd()">＋ 품목 추가</button>
    </div>

    <div><button type="submit" class="btn btn-primary" onclick="return poSubmit()">ERP에 발주 입력</button>
      <span style="color:#94a3b8;font-size:12.5px;margin-left:8px">※ 입력 API는 10초에 1회 — 연속 입력은 잠시 쉬어가세요</span></div>
  </form>
</div>

<script>
/* [v54] 거래처 검색 — 입력하는 즉시 표에서 필터링 (저장은 "거래처 필터만"/"ERP에서 조회" 버튼) */
(function(){
  var input = document.getElementById('poKw');
  var table = document.getElementById('poTable');
  var cap = document.getElementById('poFilterCap');
  if (!input || !table) return;
  input.addEventListener('keyup', function(){
    var kw = input.value.trim().toLowerCase();
    var shown = 0, total = 0;
    table.querySelectorAll('tbody tr[data-cust]').forEach(function(tr){
      total++;
      var hit = kw === '' || tr.getAttribute('data-cust').indexOf(kw) !== -1;
      tr.style.display = hit ? '' : 'none';
      if (hit) shown++;
    });
    if (cap) cap.textContent = total > 0 ? ('(실시간 일치 ' + shown + '건) ') : '';
  });
})();

/* [v59] 년도 전체 구매현황 조회 — 30일 자동 분할, 실시간 카운트다운, 자동 이어받기 */
(function(){
  var btn = document.getElementById('poYearBtn');
  var box = document.getElementById('poYearProgress');
  if (!btn || !box) return;
  var csrf = '<?= Csrf::token() ?>';
  var running = false;
  var tick = null;
  function say(html){ box.style.display = ''; box.innerHTML = html; }
  function stopTick(){ if (tick) { clearInterval(tick); tick = null; } }
  function countdown(total, label, then){
    stopTick();
    var left = total;
    var el = document.createElement('span');
    el.style.fontWeight = '700';
    say(label + ' ' + (left) + '초 뒤 자동 진행… ');
    box.appendChild(el);
    el.textContent = '';
    function render(){ el.textContent = left + '초'; }
    render();
    tick = setInterval(function(){
      left--;
      if (left <= 0) { stopTick(); then(); return; }
      render();
    }, 1000);
  }
  function chunk(year, idx){
    var body = 'csrf_token=' + encodeURIComponent(csrf) + '&year=' + year + '&idx=' + idx;
    fetch('<?= BASE_URL ?>/admin/ajax-ecount.php?action=year_fetch', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function(r){ return r.json(); }).then(function(j){
      if (!j.ok) {
        if (j.blocked) {
          var wait = Math.max(2, Math.min(600, j.wait || 3));
          countdown(wait, '⏳ 이카운트 조회 간격 제한 —', function(){ chunk(year, j.idx); });
          return;
        }
        stopTick();
        say('❌ ' + (j.message || '오류가 발생했습니다.'));
        running = false; btn.disabled = false;
        return;
      }
      say('📥 ' + j.year + '년 구매현황 — 기간 <b>' + (j.idx + 1) + '/' + j.total_windows + '</b> (' + j.window + ') · 이번 기간 ' + j.fetched + '건 · 누적 <b>' + j.accumulated + '</b>건');
      if (j.done) {
        say('✅ ' + j.year + '년 전체 조회 완료 — 총 <b>' + j.accumulated + '</b>건 · 거래처 수집 완료. 목록을 새로고침합니다…');
        setTimeout(function(){ location.reload(); }, 900);
        return;
      }
      setTimeout(function(){ chunk(year, j.idx + 1); }, 2200);
    }).catch(function(){
      stopTick();
      say('❌ 통신 오류 — 잠시 후 다시 시도해 주세요.');
      running = false; btn.disabled = false;
    });
  }
  btn.addEventListener('click', function(){
    if (running) return;
    running = true; btn.disabled = true;
    var year = document.getElementById('poYearSel').value;
    say('📤 ' + year + '년 구매현황을 30일 단위로 순서대로 가져옵니다… (진행 중 새로고침해도 이어받혀요)');
    chunk(year, 0);
  });
  var resetBtn = document.getElementById('poYearReset');
  if (resetBtn) resetBtn.addEventListener('click', function(){
    fetch('<?= BASE_URL ?>/admin/ajax-ecount.php?action=year_fetch', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'csrf_token=' + encodeURIComponent(csrf) + '&year=' + document.getElementById('poYearSel').value + '&idx=0&reset=1'
    }).then(function(r){ return r.json(); }).then(function(j){
      if (j.ok) { say('↺ 초기화 완료 — 목록을 새로고침합니다…'); setTimeout(function(){ location.reload(); }, 600); }
      else say('❌ ' + (j.message || '초기화 실패'));
    });
  });
})();

var poItems = <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]' ?>;
var poN = 0;
function poAdd(prodCd, prodDes, qty, price){
  poN++;
  var wrap = document.createElement('div');
  wrap.className = 'po-line';
  wrap.innerHTML =
    '<select name="line_prod[]">' +
      '<option value="">품목 선택</option>' +
      poItems.map(function(it){
        var des = it.prod_des + (it.size_des ? ' [' + it.size_des + ']' : '');
        return '<option value="' + it.prod_cd + '" data-des="' + des.replace(/"/g, '&quot;') + '">' + it.prod_cd + ' · ' + des.replace(/</g, '&lt;') + '</option>';
      }).join('') +
    '</select>' +
    '<input type="number" name="line_qty[]" min="1" placeholder="수량" value="' + (qty || '') + '">' +
    '<input type="text" name="line_price[]" placeholder="단가" inputmode="numeric" value="' + (price || '') + '">' +
    '<button type="button" class="btn" onclick="this.closest(\'.po-line\').remove()">삭제</button>' +
    '<input type="hidden" name="line_prod_des[]" class="po-des">';
  document.getElementById('poLines').appendChild(wrap);
  var sel = wrap.querySelector('select');
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    wrap.querySelector('.po-des').value = opt.getAttribute('data-des') || '';
    if (opt.value && !wrap.querySelector('[name="line_price[]"]').value) {
      var found = poItems.find(function(x){ return x.prod_cd === opt.value; });
      if (found && found.in_price) wrap.querySelector('[name="line_price[]"]').value = Math.round(parseFloat(found.in_price));
    }
  });
  if (prodCd) {
    sel.value = prodCd;
    var opt = sel.options[sel.selectedIndex];
    wrap.querySelector('.po-des').value = opt.getAttribute('data-des') || '';
  }
  return wrap;
}
function poSubmit(){
  var cd = document.getElementById('poCust').value.split('|');
  document.getElementById('poCustCd').value = cd[0] || '';
  document.getElementById('poCustName').value = cd.length > 1 ? cd.slice(1).join('|') : '';
  if (!cd[0]) { alert('거래처를 선택하세요.'); return false; }
  var has = false;
  document.querySelectorAll('#poLines select').forEach(function(s){ if (s.value) has = true; });
  if (!has) { alert('품목을 1개 이상 선택하세요.'); return false; }
  return true;
}
document.getElementById('poCust').addEventListener('change', function(){});
poAdd();
</script>
