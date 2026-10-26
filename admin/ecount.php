<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('ecount');
ensure_ecount_tables();

$pageTitle = '이카운트 ERP 연동';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');
$pdo = Database::connection();

/* ===== 액션 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/ecount.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $adminId = (int)AdminAuth::currentAdminId();

    if ($action === 'save_settings') {
        $whCd = trim((string)($_POST['wh_cd'] ?? ''));
        $custCd = trim((string)($_POST['cust_cd'] ?? ''));
        $interval = max(30, min(600, (int)($_POST['pull_interval'] ?? 600)));
        set_setting('ecount_wh_cd', $whCd);
        set_setting('ecount_cust_cd', $custCd);
        set_setting('ecount_pull_interval', (string)$interval);
        set_setting('ecount_sync_enabled', isset($_POST['sync_enabled']) ? '1' : '0');
        set_setting('ecount_auto_push', isset($_POST['auto_push']) ? '1' : '0');
        set_setting('ecount_test_mode', isset($_POST['test_mode']) ? '1' : '0');
        AdminAuth::log($adminId, 'ecount_settings', '이카운트 동기화 설정 저장');
        flash('admin_success', '이카운트 동기화 설정을 저장했습니다.');
        redirect('/admin/ecount.php');
    }

    if ($action === 'test') {
        if (!EcountAPI::configured()) {
            flash('admin_error', '먼저 연동/API 관리에서 회사코드·사용자 ID·API 인증키를 등록해 주세요.');
        } else {
            $r = EcountAPI::fromSettings()->testConnection();
            ecount_log('push', 'test', $r['ok'] ? 'ok' : 'fail', $r['message'] . ' → ' . ($r['base_url'] ?? ''), null, $adminId);
            if ($r['ok']) {
                flash('admin_success', '✅ 이카운트 연결 성공 — Zone: ' . $r['zone'] . ' · 연결서버: ' . ($r['base_url'] ?? ''));
            } else {
                flash('admin_error', '❌ 연결 실패 (' . ($r['base_url'] ?? '') . '): ' . $r['message']);
            }
        }
        redirect('/admin/ecount.php');
    }

    if ($action === 'pull_items') {
        $r = ecount_pull_items();
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount.php');
    }
    if ($action === 'pull_stock') {
        $r = ecount_pull_stock();
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount.php');
    }
    if ($action === 'queue_process') {
        $r = ecount_queue_process(20);
        flash('admin_success', '큐 처리 완료 — 전송성공 ' . $r['done'] . '건 · 재시도대기 ' . $r['pending'] . '건 · 실패 ' . $r['failed'] . '건');
        redirect('/admin/ecount.php');
    }
    if ($action === 'regen_token') {
        set_setting('ecount_cron_token', bin2hex(random_bytes(12)));
        AdminAuth::log($adminId, 'ecount_token', '이카운트 자동동기화 토큰 재발급');
        flash('admin_success', '자동동기화 토큰을 재발급했습니다.');
        redirect('/admin/ecount.php');
    }
    redirect('/admin/ecount.php');
}

/* ===== 상태 수집 ===== */
$st = ecount_status_summary();
if (get_setting('ecount_cron_token', '') === '') {
    set_setting('ecount_cron_token', bin2hex(random_bytes(12)));
    $st = ecount_status_summary();
}
$cronToken = get_setting('ecount_cron_token');
$cronUrl = BASE_URL . '/admin/ecount-cron.php?token=' . urlencode($cronToken);
$basePreview = EcountAPI::baseUrlPreview($st['test_mode']);
$lastStockSync = $st['last_stock_sync'] > 0
    ? date('Y-m-d H:i:s', $st['last_stock_sync']) . ' (' . max(0, (int)floor((time() - $st['last_stock_sync']) / 60)) . '분 전)'
    : '아직 없음';

/* 최근 로그 */
$logs = $pdo->query('SELECT * FROM tt_ecount_sync_log ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);

/* 큐 목록 */
$queue = $pdo->query("SELECT * FROM tt_ecount_queue ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/includes/header.php';
?>

<style>
.ec-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:18px 0}
.ec-stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px}
.ec-stat .k{font-size:12.5px;color:#64748b;font-weight:600}
.ec-stat .v{font-size:21px;font-weight:800;margin-top:4px;color:#0f172a}
.ec-stat .v small{font-size:12px;color:#94a3b8;font-weight:600}
.ec-flag{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11.5px;font-weight:700}
.ec-on{background:#dcfce7;color:#15803d}.ec-off{background:#f1f5f9;color:#64748b}
.ec-actions{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0}
.ec-cronurl{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ec-cronurl input{flex:1;min-width:240px;font-size:12.5px;font-family:monospace}
.ec-table th{white-space:nowrap}
.ec-dir-push{color:#2563eb;font-weight:700}.ec-dir-pull{color:#059669;font-weight:700}
</style>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🔗 이카운트 ERP 연동</h2>
      <p class="admin-list-sub">쇼핑몰 ↔ 이카운트 ERP를 실시간으로 연동합니다 — 주문 결제 시 ERP 판매입력 자동 전송(재고 차감), ERP 창고별 재고를 주기적으로 가져와 쇼핑몰 재고에 자동 반영합니다.</p>
    </div>
    <a class="btn" href="<?= BASE_URL ?>/admin/api-settings.php">⚙️ 연동/API 키 관리</a>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <?php if (!$st['configured']): ?>
  <div class="alert alert-error">이카운트 연동정보(회사코드·사용자 ID·API 인증키)가 아직 등록되지 않았습니다. <a href="<?= BASE_URL ?>/admin/api-settings.php" style="text-decoration:underline">연동/API 관리</a>에서 먼저 등록해 주세요.</div>
  <?php endif; ?>

  <?php $domainSetting = get_setting('ecount_domain', '');
        if ($domainSetting !== '' && !preg_match('#^https?://#i', $domainSetting)): ?>
  <div class="alert alert-error">⚠️ 연결 도메인 설정에 "<?= h($domainSetting) ?>" 값이 저장되어 있어 실제 연결은 Zone 자동조회(<?= h($basePreview) ?>)로 이루어집니다. "oapi" 같은 값은 서버 주소가 아닙니다 — <a href="<?= BASE_URL ?>/admin/api-settings.php" style="text-decoration:underline">연동/API 관리</a>에서 도메인 칸을 비우고 저장하면 이 안내가 사라집니다.</div>
  <?php endif; ?>

  <!-- ===== 상태 카드 ===== -->
  <div class="ec-grid">
    <div class="ec-stat"><div class="k">연결 상태</div>
      <div class="v"><?= $st['configured'] ? '<span class="ec-flag ec-on">설정됨</span>' : '<span class="ec-flag ec-off">미설정</span>' ?>
      <?= $st['test_mode'] ? '<span class="ec-flag ec-off" style="background:#fef3c7;color:#b45309">테스트키</span>' : '<span class="ec-flag ec-on" style="background:#dbeafe;color:#1d4ed8">실서버</span>' ?></div>
      <div class="k" style="margin-top:6px">Zone: <?= $st['zone'] !== '' ? h($st['zone']) : '자동조회' ?></div>
      <div class="k" style="margin-top:2px;word-break:break-all">연결서버: <b><?= h($basePreview) ?></b></div>
    </div>
    <div class="ec-stat"><div class="k">ERP 재고 동기화(몰 반영)</div>
      <div class="v"><?= $st['sync_enabled'] ? '<span class="ec-flag ec-on">켜짐</span>' : '<span class="ec-flag ec-off">꺼짐</span>' ?></div>
      <div class="k" style="margin-top:6px">마지막 동기화: <?= h($lastStockSync) ?></div>
    </div>
    <div class="ec-stat"><div class="k">결제 시 ERP 자동전송</div>
      <div class="v"><?= $st['auto_push'] ? '<span class="ec-flag ec-on">켜짐</span>' : '<span class="ec-flag ec-off">꺼짐</span>' ?></div>
      <div class="k" style="margin-top:6px">대기 큐 <?= $st['queue_pending'] ?>건 · 완료 <?= $st['queue_done'] ?>건</div>
    </div>
    <div class="ec-stat"><div class="k">캐시 현황</div>
      <div class="v"><?= number_format($st['items_cached']) ?><small> 품목</small></div>
      <div class="k" style="margin-top:6px"><?= number_format($st['partners_cached']) ?> 거래처 · <?= number_format($st['stock_rows']) ?> 창고별재고 행</div>
    </div>
  </div>

  <!-- ===== [v63] 구매현황(엑셀 업로드) 현황 라인 + 바로 검색 ===== -->
  <?php
    try {
        $plStats = $pdo->query('SELECT COUNT(*) AS c, MAX(imported_at) AS last_at, COUNT(DISTINCT cust_name) AS custs FROM tt_ecount_purchase_list')->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $plStats = []; }
    $plRows = (int)($plStats['c'] ?? 0);
    $plCusts = (int)($plStats['custs'] ?? 0);
    $plLast = !empty($plStats['last_at']) ? date('m월 d일 H시 i분', (int)strtotime((string)$plStats['last_at'])) : '';
  ?>
  <div class="ec-stat" style="margin:0 0 14px">
    <div class="k">📈 구매현황 (엑셀 업로드 자료)</div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:6px">
      <div class="v"><?= number_format($plRows) ?><small> 행 · 거래처 <?= number_format($plCusts) ?>곳</small></div>
      <?php if ($plLast !== ''): ?><div class="k">마지막 업로드: <b><?= h($plLast) ?></b></div><?php endif; ?>
      <form method="get" action="<?= BASE_URL ?>/admin/ecount-purchase-list.php" style="display:flex;gap:6px;flex:1;min-width:220px;max-width:420px">
        <input type="text" name="q" placeholder="구매현황에서 바로 검색 — 거래처명·품목 (예: 윈터)" style="flex:1">
        <button type="submit" class="btn">🔎 검색</button>
      </form>
    </div>
    <div class="k" style="margin-top:6px">ERP 구매현황 화면에서 엑셀로 내려받아 <b>CSV(쉼표로 분리)</b>로 저장 → <a href="<?= BASE_URL ?>/admin/ecount-purchase-list.php" style="text-decoration:underline">구매현황(엑셀 업로드) 화면</a>에 올리면 위 검색으로 바로 볼 수 있어요.</div>
  </div>

  <!-- ===== [v53] 서버 IP 확인 (이카운트 IP등록용) ===== -->
  <div class="ec-stat" style="margin:0 0 14px">
    <div class="k">이카운트 [IP등록]에 넣을 값 — 이 쇼핑몰 서버의 아웃바운드 IP</div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px">
      <b id="ecServerIp" style="font-size:19px;font-family:monospace;color:#0f172a">확인 중...</b>
      <button type="button" class="btn" id="ecIpBtn" style="display:none">다시 확인</button>
    </div>
    <div class="k" style="margin-top:6px">이카운트 ERP에서 <b>환경설정(Self-Customizing) > 정보관리 > API인증키발급 > 하단 [IP등록]</b>에 이 IP를 등록해야 이 서버의 API 호출이 허용됩니다 (최대 20개). 등록 후 연결 테스트를 다시 실행해 주세요.</div>
  </div>

  <!-- ===== 수동 액션 ===== -->
  <div class="ec-actions">
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn btn-primary">🔌 연결 테스트</button>
    </form>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="pull_items">
      <button type="submit" class="btn" data-wait="<?= (int)$st['items_pull_wait'] ?>">📥 ERP 품목 가져오기<?= ecount_wait_label((int)$st['items_pull_wait']) ?></button>
    </form>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="pull_stock">
      <button type="submit" class="btn" data-wait="<?= (int)$st['stock_pull_wait'] ?>">📦 창고별 재고 가져오기<?= ecount_wait_label((int)$st['stock_pull_wait']) ?></button>
    </form>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="queue_process">
      <button type="submit" class="btn">🔁 전송 큐 처리</button>
    </form>
    <a class="btn" href="<?= BASE_URL ?>/admin/ecount-items.php">품목 관리 →</a>
    <a class="btn" href="<?= BASE_URL ?>/admin/ecount-partners.php">거래처 관리 →</a>
    <a class="btn" href="<?= BASE_URL ?>/admin/ecount-stock.php">창고별 재고 →</a>
    <a class="btn" href="<?= BASE_URL ?>/admin/ecount-purchases.php">🛒 구매관리 →</a>
    <a class="btn" href="<?= BASE_URL ?>/admin/ecount-purchase-orders.php">발주서 →</a>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/admin/ecount-purchase-list.php">📈 구매현황 조회하기 →</a>
  </div>

  <!-- ===== 동기화 설정 ===== -->
  <h3 class="section-title" style="margin-top:8px">동기화 설정</h3>
  <form method="post" style="max-width:720px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="save_settings">
    <table class="form-table">
      <tr>
        <th>기본 창고코드 (WH_CD)</th>
        <td><input type="text" name="wh_cd" value="<?= h($st['wh_cd']) ?>" placeholder="예: 00001" style="width:160px">
          <div class="field-sub">판매전표·입고전표·발주에 기록될 ERP 창고코드 (이카운트 환경설정의 창고코드)</div></td>
      </tr>
      <tr>
        <th>전표용 기본 거래처코드</th>
        <td><input type="text" name="cust_cd" value="<?= h($st['cust_cd']) ?>" placeholder="예: 00001" style="width:160px">
          <div class="field-sub">쇼핑몰 주문 판매전표의 거래처(CUST)로 기록될 코드 — "쇼핑몰" 거래처를 하나 만들어 두면 관리가 쉽습니다</div></td>
      </tr>
      <tr>
        <th>ERP 전체조회 간격 (초)</th>
        <td><input type="number" name="pull_interval" min="30" max="600" step="10" value="<?= (int)(get_setting('ecount_pull_interval', '600') ?: 600) ?>" style="width:120px">
          <div class="field-sub">품목 전체 · 창고별 재고 전체 · 발주서(구매현황) 조회의 최소 재시도 간격. <b>이카운트 실서버 공식 규칙이 다건조회 10분(600초) 1회</b>라 600초가 안전해요 — 더 짧게 잡으면 이카운트 Rate Limit(412)에 걸려 조회가 실패할 수 있고, 그때는 자동으로 10분 뒤로 물러납니다. (특정 상품 1개의 실시간 조회는 항상 1초 단위 / 이미 조회한 목록의 검색·필터는 즉시 가능)</div></td>
      </tr>
      <tr>
        <th>동작 스위치</th>
        <td style="padding-top:10px">
          <label style="display:block;margin-bottom:8px"><input type="checkbox" name="sync_enabled" <?= $st['sync_enabled'] ? 'checked' : '' ?>> ERP 창고별 재고를 쇼핑몰 재고에 <b>자동 반영</b> (10분 주기 + 관리자 접속 시)</label>
          <label style="display:block;margin-bottom:8px"><input type="checkbox" name="auto_push" <?= $st['auto_push'] ? 'checked' : '' ?>> 주문·예약 <b>결제완료 시 ERP 판매입력 자동 전송</b> (실패 시 자동 재시도)</label>
          <label style="display:block"><input type="checkbox" name="test_mode" <?= $st['test_mode'] ? 'checked' : '' ?>> <b>테스트키 모드</b> (sboapi 테스트 서버 사용 — 테스트키로 1회 이상 호출 성공 후에는 실서버 전환)</label>
        </td>
      </tr>
    </table>
    <div style="margin-top:14px"><button type="submit" class="btn btn-primary">설정 저장</button></div>
  </form>

  <!-- ===== 자동동기화 URL ===== -->
  <h3 class="section-title" style="margin-top:26px">자동 동기화 (주기 실행)</h3>
  <p class="admin-list-sub" style="margin-bottom:8px">이 URL을 호스팅 cron(dothome 예약작업)에 5분 간격으로 등록하면 관리자가 접속해 있지 않아도 ①결제완료 전송 재시도 ②ERP 재고 10분 주기 자동 반영이 계속됩니다. 관리자 화면이 열려 있을 때도 자동으로 60초마다 실행됩니다.</p>
  <div class="ec-cronurl">
    <input type="text" readonly value="<?= h($cronUrl) ?>" id="ecCronUrl" onclick="this.select()">
    <button type="button" class="btn" onclick="var i=document.getElementById('ecCronUrl');i.select();try{document.execCommand('copy');this.textContent='복사됨';}catch(e){}">복사</button>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="action" value="regen_token">
      <button type="submit" class="btn">토큰 재발급</button>
    </form>
  </div>
</div>

<!-- ===== 전송 큐 ===== -->
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title">📤 전송 큐 (몰 → ERP)</h2>
      <p class="admin-list-sub">결제완료 건의 ERP 판매입력 전송 대기/결과입니다. 실패 건은 원인 확인 후 "전송 큐 처리"로 재시도할 수 있습니다.</p></div>
  </div>
  <div style="overflow:auto">
  <table class="admin-table ec-table" style="min-width:760px">
    <thead><tr><th>#</th><th>구분</th><th>대상</th><th>상태</th><th>시도</th><th>마지막 오류</th><th>등록일시</th><th>처리일시</th></tr></thead>
    <tbody>
    <?php if (!$queue): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">전송 대기 건이 없습니다 — 결제가 완료되면 자동으로 쌓입니다.</td></tr><?php endif; ?>
    <?php foreach ($queue as $q):
        $pl = json_decode((string)$q['payload'], true) ?: [];
        $kindLabel = ($q['action'] ?? '') === 'order_sale' ? '주문 결제' : '예약 결제';
    ?>
    <tr>
      <td><?= (int)$q['id'] ?></td>
      <td><?= h($kindLabel) ?></td>
      <td>#<?= (int)($pl['id'] ?? 0) ?></td>
      <td><?php if ($q['status'] === 'done'): ?><span class="ec-flag ec-on">전송완료</span>
          <?php elseif ($q['status'] === 'fail'): ?><span class="ec-flag ec-off" style="background:#fee2e2;color:#b91c1c">실패</span>
          <?php else: ?><span class="ec-flag ec-off" style="background:#fef3c7;color:#b45309">대기</span><?php endif; ?></td>
      <td><?= (int)$q['attempts'] ?>/8</td>
      <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h((string)$q['last_error']) ?></td>
      <td><?= h((string)$q['created_at']) ?></td>
      <td><?= h((string)($q['processed_at'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ===== 동기화 로그 ===== -->
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title">📋 동기화 로그 (최근 30건)</h2>
      <p class="admin-list-sub">push(몰→ERP) / pull(ERP→몰) 방향별 처리 결과입니다.</p></div>
  </div>
  <div style="overflow:auto">
  <table class="admin-table ec-table" style="min-width:720px">
    <thead><tr><th>일시</th><th>방향</th><th>유형</th><th>결과</th><th>내용</th></tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">아직 동기화 기록이 없습니다.</td></tr><?php endif; ?>
    <?php foreach ($logs as $lg): ?>
    <tr>
      <td><?= h((string)$lg['created_at']) ?></td>
      <td class="<?= $lg['direction'] === 'push' ? 'ec-dir-push' : 'ec-dir-pull' ?>"><?= $lg['direction'] === 'push' ? '몰→ERP' : 'ERP→몰' ?></td>
      <td><?= h((string)$lg['type']) ?></td>
      <td><?= $lg['status'] === 'ok' ? '<span class="ec-flag ec-on">성공</span>' : '<span class="ec-flag ec-off" style="background:#fee2e2;color:#b91c1c">실패</span>' ?></td>
      <td style="max-width:420px"><?= h((string)$lg['message']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
/* [v53] 서버 아웃바운드 IP 자동 확인 (이카운트 IP등록에 넣을 값) */
(function(){
  var el = document.getElementById('ecServerIp');
  var btn = document.getElementById('ecIpBtn');
  if (!el) return;
  var check = function(){
    fetch('<?= BASE_URL ?>/admin/ajax-ecount.php?action=server_ip', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        el.textContent = (j && j.ok && j.ip) ? j.ip : '확인 실패 (잠시 후 다시 시도)';
        if (btn) btn.style.display = '';
      })
      .catch(function(){ el.textContent = '확인 실패 (잠시 후 다시 시도)'; if (btn) btn.style.display = ''; });
  };
  if (btn) btn.addEventListener('click', check);
  check();
})();

/* 관리자가 이 페이지를 열고 있으면 60초마다 자동동기화 핑 */
(function(){
  if (!<?= $st['configured'] ? 'true' : 'false' ?>) return;
  var run = function(){
    fetch('<?= BASE_URL ?>/admin/ajax-ecount.php?action=cron_ping', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.stock && j.stock.applied > 0) location.reload();
      })
      .catch(function(){});
  };
  setInterval(run, 60000);
})();
</script>
