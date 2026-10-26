<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('ecount');
ensure_ecount_tables();

$pageTitle = '이카운트 구매관리 (구매입고)';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');
$pdo = Database::connection();

/* ===== [v64] 액션 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/ecount-purchases.php');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $r = ecount_save_purchase_entry(
            (string)($_POST['cust_name'] ?? ''),
            (string)($_POST['prod_input'] ?? ''),
            (int)($_POST['qty'] ?? 0),
            (int)($_POST['unit_price'] ?? 0),
            (string)($_POST['io_date'] ?? ''),
            (string)($_POST['remark'] ?? '')
        );
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-purchases.php');
    }
    if ($action === 'resend') {
        $r = ecount_resend_purchase((int)($_POST['id'] ?? 0));
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-purchases.php');
    }
    if ($action === 'delete') {
        $st = $pdo->prepare('SELECT source, product_id, qty, stock_moved FROM tt_ecount_purchases WHERE id = :id LIMIT 1');
        $st->execute(['id' => (int)($_POST['id'] ?? 0)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['source'] === 'worklog') {
                flash('admin_error', '작업일지 자동 항목은 여기서 지울 수 없어요 — 작업일지에서 "진행중으로" 되돌리면 자동으로 제거돼요.');
                redirect('/admin/ecount-purchases.php');
            }
            /* 직접 입력 행 삭제 — 사이트 재고 되돌리기(증가시킨 것) */
            if ((int)$row['stock_moved'] === 1 && $row['product_id']) {
                try { $pdo->prepare('UPDATE tt_products SET stock = GREATEST(0, stock - :q) WHERE id = :id')->execute(['q' => (int)$row['qty'], 'id' => (int)$row['product_id']]); }
                catch (Throwable $e) { sg_error_log('[ecount_purchase delete stock] ' . $e->getMessage()); }
            }
            $pdo->prepare('DELETE FROM tt_ecount_purchases WHERE id = :id')->execute(['id' => (int)($_POST['id'] ?? 0)]);
            flash('admin_success', '구매입고 행을 삭제했습니다. (ERP에 이미 전송된 구매 전표는 ERP에서 직접 삭제해 주세요)');
        }
        redirect('/admin/ecount-purchases.php');
    }
    redirect('/admin/ecount-purchases.php');
}

/* ===== [v64] 엑셀(CSV) 다운로드 ===== */
if (($_GET['export'] ?? '') === 'csv') {
    $q = trim((string)($_GET['q'] ?? ''));
    $where = $q !== '' ? 'WHERE cust_name LIKE :q1 OR prod_des LIKE :q2 OR cust_cd LIKE :q3' : '';
    $st = $pdo->prepare("SELECT * FROM tt_ecount_purchases {$where} ORDER BY io_date DESC, id DESC");
    $st->execute($q !== '' ? ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%'] : []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecount-purchases-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['번호', '일자', '거래처코드', '거래처명', '품목코드', '품목명', '수량', '단가', '금액', '출처', 'ERP전송', '사이트재고반영', '적요', '등록일시']);
    $n = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $n++;
        fputcsv($out, [$n, (string)$r['io_date'], (string)$r['cust_cd'], (string)$r['cust_name'], (string)$r['prod_cd'], (string)$r['prod_des'],
            (int)$r['qty'], (int)$r['unit_price'], (int)$r['amt'],
            $r['source'] === 'worklog' ? '작업일지 자동' : '직접입력',
            $r['erp_status'] === 'sent' ? '전송완료' : ($r['erp_status'] === 'skip' ? '작업소모(전송안함)' : ($r['erp_status'] === 'fail' ? '전송실패' : '대기')),
            (int)$r['stock_moved'] === 1 ? '반영' : '-', (string)$r['remark'], substr((string)$r['created_at'], 0, 16)]);
    }
    fclose($out);
    exit;
}

/* ===== 목록 ===== */
$loadError = '';
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
$rows = [];
$total = 0;
$pages = 1;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = 0;
$perPage = 30;
try {
    if ($q !== '') {
        $where = 'WHERE cust_name LIKE :q1 OR prod_des LIKE :q2 OR cust_cd LIKE :q3 OR prod_cd LIKE :q4';
        $params = ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%', 'q4' => '%' . $q . '%'];
    }
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_ecount_purchases {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $listStmt = $pdo->prepare("SELECT * FROM tt_ecount_purchases {$where} ORDER BY io_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = '구매관리 자료를 읽지 못했습니다: ' . $e->getMessage() . ' — 이 메시지가 계속 보이면 그대로 알려주세요.';
    sg_error_log('[ecount-purchases] ' . $e->getMessage());
}

/* 폼용 자동완성 데이터 (E카운트처럼 입력하면 목록이 떠요) */
$partnerNames = $pdo->query('SELECT cust_name FROM tt_ecount_partners WHERE cust_name <> "" ORDER BY updated_at DESC LIMIT 300')->fetchAll(PDO::FETCH_COLUMN);
$itemOptions = $pdo->query('SELECT prod_cd, prod_des, IN_PRICE FROM tt_ecount_items ORDER BY prod_des LIMIT 2000')->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🛒 구매관리 (구매입고 · 작업소모)</h2>
      <p class="admin-list-sub">이카운트 구매관리처럼 여기서 바로 구매입고를 입력하면 <b>ERP에 구매 전표로 저장되고 ERP 재고가 증가</b>해요. 태블릿 작업일지를 <b>작업 완료</b>하면 사용한 품목이 매입단가로 자동으로 들어오고 <b>사이트 재고가 차감</b>돼요. (거래처·품목은 입력하면 자동으로 목록이 떠요)</p>
    </div>
    <div style="display:flex;gap:8px">
      <a class="btn" href="<?= BASE_URL ?>/admin/ecount-purchases.php?export=csv<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">⬇ 엑셀(CSV)</a>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>
  <?php if ($loadError !== ''): ?><div class="alert alert-error"><?= h($loadError) ?></div><?php endif; ?>

  <!-- ===== 구매입고 입력 (E카운트식 — 자동완성) ===== -->
  <form method="post" class="ec-frm" style="max-width:1000px;margin:12px 0;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="save">
    <div><label>일자 *</label><input type="date" name="io_date" value="<?= h(date('Y-m-d')) ?>" required></div>
    <div style="min-width:200px"><label>거래처 * (입력하면 자동완성)</label>
      <input type="text" name="cust_name" list="epCustList" required maxlength="100" placeholder="예: 윈터예약-소매" autocomplete="off"></div>
    <div style="min-width:260px;flex:2"><label>품목 * (입력하면 자동완성)</label>
      <input type="text" name="prod_input" id="epProdInput" list="epProdList" required maxlength="200" placeholder="예: KOREA W2735 35 — 목록에서 선택하세요" autocomplete="off">
      <small style="color:#64748b" id="epProdHint"></small></div>
    <div><label>수량 *</label><input type="number" name="qty" min="1" value="1" required style="width:90px"></div>
    <div><label>단가(원)</label><input type="number" name="unit_price" id="epUnitPrice" min="0" step="1000" value="0" style="width:130px"></div>
    <div><label>적요</label><input type="text" name="remark" maxlength="200" placeholder="참고 메모(선택)"></div>
    <div><button type="submit" class="btn btn-primary">💾 구매입고 저장 (ERP 전송)</button></div>
  </form>
  <datalist id="epCustList">
    <?php foreach ($partnerNames as $nm): ?><option value="<?= h((string)$nm) ?>"></option><?php endforeach; ?>
  </datalist>
  <datalist id="epProdList">
    <?php foreach ($itemOptions as $it): ?><option value="<?= h((string)$it['prod_des']) ?> [<?= h((string)$it['prod_cd']) ?>]"></option><?php endforeach; ?>
  </datalist>
  <script>
  /* [v64] 품목 선택 시 단가 자동 채움(ERP 매입단가) — E카운트처럼 바로바로 */
  (function () {
    var items = <?= json_encode(array_map(static fn($r): array => ['des' => (string)$r['prod_des'], 'cd' => (string)$r['prod_cd'], 'price' => (string)$r['IN_PRICE']], $itemOptions), JSON_UNESCAPED_UNICODE) ?>;
    var inp = document.getElementById('epProdInput');
    var price = document.getElementById('epUnitPrice');
    var hint = document.getElementById('epProdHint');
    if (!inp) return;
    inp.addEventListener('change', function () {
      var v = inp.value || '';
      hint.textContent = '';
      var m = v.match(/\s\[([A-Za-z0-9_-]{2,30})\]$/);
      var cd = m ? m[1] : '';
      for (var i = 0; i < items.length; i++) {
        if ((cd && items[i].cd === cd) || (!cd && items[i].des === v.trim())) {
          var p = parseInt(items[i].price, 10) || 0;
          if (p > 0 && (parseInt(price.value, 10) || 0) === 0) price.value = p;
          hint.textContent = '선택됨: ' + items[i].des + ' [' + items[i].cd + ']' + (p > 0 ? ' · ERP 매입단가 ' + p.toLocaleString() + '원' : '');
          return;
        }
      }
    });
  })();
  </script>

  <!-- ===== 검색 ===== -->
  <form method="get" style="display:flex;gap:8px;max-width:520px;margin:12px 0">
    <input type="text" name="q" list="epCustList" value="<?= h($q) ?>" placeholder="거래처명·품목 검색 — 예: 윈터 (자동완성)" style="flex:1">
    <button type="submit" class="btn">🔎 검색</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= BASE_URL ?>/admin/ecount-purchases.php">초기화</a><?php endif; ?>
  </form>

  <?php if ($total > 0): ?>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 6px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px">
    <b>📋 <?= number_format($total) ?>건</b><span style="color:#64748b">·</span>
    <span>현재 <b><?= $page ?></b> / <?= $pages ?>페이지</span><span style="color:#64748b">·</span>
    <span><?= number_format($offset + 1) ?> ~ <?= number_format($offset + count($rows)) ?>번 표시 중</span>
  </div>
  <?php endif; ?>

  <div style="overflow:auto">
  <table class="admin-table" style="min-width:1000px">
    <thead><tr><th>번호</th><th>일자</th><th>거래처</th><th>품목 [ERP코드]</th><th>수량</th><th>단가</th><th>금액</th><th>출처</th><th>ERP 전송</th><th>동작</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="10" style="text-align:center;color:#94a3b8">구매입고가 없습니다 — 위 폼에서 입력하거나, 태블릿 작업일지를 "작업 완료"하면 자동으로 들어와요.</td></tr><?php endif; ?>
    <?php $n = $offset; foreach ($rows as $r): $n++; ?>
    <tr>
      <td><?= $n ?></td>
      <td style="white-space:nowrap"><?= h(mb_substr(preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', (string)$r['io_date']), 0, 10)) ?></td>
      <td><b><?= h(mb_substr((string)$r['cust_name'], 0, 20)) ?></b></td>
      <td><?= h(mb_substr((string)$r['prod_des'], 0, 30)) ?> <small style="color:#94a3b8">[<?= h((string)$r['prod_cd']) ?>]</small></td>
      <td style="text-align:right"><?= (int)$r['qty'] ?></td>
      <td style="text-align:right"><?= number_format((int)$r['unit_price']) ?></td>
      <td style="text-align:right;font-weight:700"><?= number_format((int)$r['amt']) ?></td>
      <td><?= $r['source'] === 'worklog' ? '<span class="ec-flag" style="background:#e0f2fe;color:#0369a1">작업일지 자동</span>' : '<span class="ec-flag" style="background:#fef9c3;color:#a16207">직접입력</span>' ?></td>
      <td><?php if ($r['erp_status'] === 'sent'): ?><span style="color:#15803d;font-weight:700">✔ 전송완료</span><br><small style="color:#64748b"><?= h(substr((string)$r['erp_sent_at'], 5, 11)) ?></small>
          <?php elseif ($r['erp_status'] === 'skip'): ?><span style="color:#64748b">작업소모<br>(ERP 전송 안 함)</span>
          <?php elseif ($r['erp_status'] === 'fail'): ?><span style="color:#dc2626;font-weight:700">전송실패</span><br><small style="color:#dc2626"><?= h(mb_substr((string)$r['erp_error'], 0, 24)) ?></small>
          <?php else: ?><span style="color:#d97706">대기</span><?php endif; ?>
          <?php if ((int)$r['stock_moved'] === 1): ?><br><small style="color:#0369a1">사이트 재고 <?= $r['source'] === 'worklog' ? '차감' : '증가' ?>됨</small><?php endif; ?></td>
      <td style="white-space:nowrap">
        <?php if ($r['erp_status'] !== 'sent'): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="action" value="resend">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button type="submit" class="btn" title="ERP 구매입력으로 전송 (ERP 재고가 증가합니다)">ERP 전송</button>
        </form>
        <?php endif; ?>
        <?php if ($r['source'] !== 'worklog'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('이 구매입고 행을 삭제할까요? (사이트 재고 증가분은 되돌려요)')">
          <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button type="submit" class="btn">삭제</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination" style="margin-bottom:4px">
    <?php
      $window = [];
      $from = max(1, $page - 4);
      $to = min($pages, $from + 8);
      $from = max(1, $to - 8);
      for ($i = $from; $i <= $to; $i++) $window[] = $i;
      $qs = $q !== '' ? ('&q=' . urlencode($q)) : '';
    ?>
    <?php if ($page > 1): ?><a class="page-link" href="?page=<?= $page - 1 ?><?= $qs ?>">‹ 이전</a><?php endif; ?>
    <?php if ($from > 1): ?><a class="page-link" href="?page=1<?= $qs ?>">1</a><?php if ($from > 2): ?><span style="color:#94a3b8">…</span><?php endif; ?><?php endif; ?>
    <?php foreach ($window as $i): ?>
      <?php if ($i === $page): ?><span class="page-current"><?= $i ?></span>
      <?php else: ?><a class="page-link" href="?page=<?= $i ?><?= $qs ?>"><?= $i ?></a><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($to < $pages): ?><?php if ($to < $pages - 1): ?><span style="color:#94a3b8">…</span><?php endif; ?><a class="page-link" href="?page=<?= $pages ?><?= $qs ?>"><?= $pages ?></a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="page-link" href="?page=<?= $page + 1 ?><?= $qs ?>">다음 ›</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <p class="admin-list-sub" style="margin-top:6px">총 <?= number_format($total) ?>건<?= $pages > 1 ? ' · ' . $page . '/' . $pages . '페이지 · 페이지당 ' . $perPage . '건' : '' ?><?= $q !== '' ? ' · 검색 "' . h($q) . '"' : '' ?></p>

  <div class="alert" style="background:#f8fafc;border:1px solid #e2e8f0;color:#475569;margin-top:12px">
    💡 <b>직접입력</b>은 ERP에 구매 전표로 바로 전송돼요 (ERP 재고 증가 + ERP 구매현황 화면에 기록). <b>작업일지 자동</b> 행은 작업으로 소모된 품목이라 ERP 전송 없이 사이트 재고만 차감해요 — ERP 구매현황에도 남기고 싶으면 그 행의 "ERP 전송" 버튼을 눌러 주세요.
  </div>
</div>
