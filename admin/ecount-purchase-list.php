<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('ecount');
ensure_ecount_tables();

$pageTitle = '이카운트 구매현황 (엑셀 업로드)';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');
$pdo = Database::connection();

/* [v62] 구매현황(구매 전표) 업로드 캐시 — 이카운트는 구매 전표를 읽는 API를 제공하지 않아
 * ERP의 구매현황 화면에서 엑셀(CSV)로 내려받은 자료를 여기에 올려 조회·검색한다. */
(function () use ($pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_purchase_list (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            io_date    VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '일자(YYYYMMDD)',
            cust_cd    VARCHAR(30)  NULL COMMENT '거래처코드',
            cust_name  VARCHAR(100) NULL COMMENT '거래처명',
            prod_des   VARCHAR(150) NULL COMMENT '품목명',
            qty        VARCHAR(20)  NULL COMMENT '수량',
            supply_amt VARCHAR(20)  NULL COMMENT '공급가액',
            vat_amt    VARCHAR(20)  NULL COMMENT '부가세',
            remark     VARCHAR(200) NULL COMMENT '적요',
            raw_row    TEXT NULL COMMENT '원본 행(JSON)',
            batch      VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '업로드 묶음',
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pl_cust (cust_name),
            INDEX idx_pl_date (io_date)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { sg_error_log('[ensure_ecount_purchase_list] ' . $e->getMessage()); }
})();

/* ===== CSV 파서(UTF-8 BOM/EUC-KR 자동) — products_import 와 동일 규격 ===== */
function epl_parse_csv(string $tmpPath): array
{
    $raw = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') return ['header' => [], 'rows' => []];
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    $encoding = mb_detect_encoding($raw, ['UTF-8', 'EUC-KR', 'CP949', 'ISO-8859-1'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $converted = @mb_convert_encoding($raw, 'UTF-8', $encoding);
        if ($converted !== false) $raw = $converted;
    }
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $raw);
    rewind($stream);
    $header = [];
    $rows = [];
    while (($cells = fgetcsv($stream)) !== false) {
        $cells = array_map(static fn($c) => trim((string)$c), $cells);
        if ($cells === [''] ) continue;
        if (!$header) { $header = $cells; continue; }
        $rows[] = $cells;
    }
    fclose($stream);
    return ['header' => $header, 'rows' => $rows];
}

/* ===== 컬럼 매칭(ERP 구매현황 엑셀 표기 유연 매칭) ===== */
const EPL_FIELD_ALIASES = [
    'io_date'    => ['일자', '발주일자', '구매일자', '전표일자', '입고일자', '날짜', '매입일자'],
    'cust_cd'    => ['거래처코드', '거래처코드(사업자번호)', '사업자번호', '거래처코드'],
    'cust_name'  => ['거래처', '거래처명', '거래처명(상호)', '상호'],
    'prod_des'   => ['품목', '품목명', '품목명(규격)', '상품명', '품명', '자재명'],
    'qty'        => ['수량', '구매수량', '매입수량'],
    'supply_amt' => ['공급가액', '공급가', '매입금액', '구매금액', '금액', '공급가액($)'],
    'vat_amt'    => ['부가세', '부가세액', '부가가치세', '부가세금액'],
    'remark'     => ['적요', '비고', '내용', '적요/비고'],
];
const EPL_REQUIRED = ['cust_name', 'io_date'];

function epl_build_column_map(array $header): array
{
    $map = [];
    $missing = [];
    foreach (EPL_FIELD_ALIASES as $field => $aliases) {
        $map[$field] = null;
        foreach ($header as $i => $h) {
            $h = trim((string)$h);
            if ($h === '') continue;
            if (in_array($h, $aliases, true)) { $map[$field] = $i; break; }
        }
        foreach ($header as $i => $h) { /* 부분일치 백업 */
            $h = trim((string)$h);
            if ($h !== '' && $map[$field] === null && in_array($field, EPL_REQUIRED, true) === false) {
                foreach ($aliases as $a) { if ($a !== '' && mb_strpos($h, $a) !== false) { $map[$field] = $i; break 2; } }
            }
        }
    }
    foreach (EPL_REQUIRED as $req) { if ($map[$req] === null) $missing[] = $req; }
    return ['map' => $map, 'missing' => $missing, 'header' => $header];
}

/* ===== 업로드 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/ecount-purchase-list.php');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'clear') {
        $pdo->exec('DELETE FROM tt_ecount_purchase_list');
        flash('admin_success', '구매현황 업로드 자료를 비웠습니다.');
        redirect('/admin/ecount-purchase-list.php');
    }
    if ($action === 'upload') {
        @set_time_limit(300);
        if (empty($_FILES['csv_file']['name'])) {
            flash('admin_error', '업로드할 CSV 파일을 선택해 주세요.');
            redirect('/admin/ecount-purchase-list.php');
        }
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash('admin_error', '파일 업로드 중 오류가 발생했습니다. (code=' . $_FILES['csv_file']['error'] . ')');
            redirect('/admin/ecount-purchase-list.php');
        }
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            flash('admin_error', 'CSV 파일(.csv)만 업로드할 수 있습니다 — 이카운트 구매현황 화면에서 엑셀로 내려받은 뒤 "CSV(쉼표로 분리)"로 저장해서 올려주세요.');
            redirect('/admin/ecount-purchase-list.php');
        }
        try {
            $parsed = epl_parse_csv($_FILES['csv_file']['tmp_name']);
            if (empty($parsed['header'])) {
                flash('admin_error', '업로드한 파일을 읽을 수 없습니다.');
                redirect('/admin/ecount-purchase-list.php');
            }
            $report = epl_build_column_map($parsed['header']);
            if (!empty($report['missing'])) {
                flash('admin_error', '필수 컬럼을 찾지 못했습니다: ' . implode(', ', $report['missing']) . ' — ERP 구매현황 엑셀의 머리행(거래처/일자)을 포함해서 저장해 주세요.');
                redirect('/admin/ecount-purchase-list.php');
            }
            if (empty($parsed['rows'])) {
                flash('admin_error', '업로드한 파일에 데이터 행이 없습니다.');
                redirect('/admin/ecount-purchase-list.php');
            }
            $map = $report['map'];
            $batch = date('Ymd-His');
            /* 스냅샷 교체 방식 — 새 업로드가 이전 것을 대체 */
            $pdo->exec('DELETE FROM tt_ecount_purchase_list');
            $ins = $pdo->prepare('INSERT INTO tt_ecount_purchase_list (io_date, cust_cd, cust_name, prod_des, qty, supply_amt, vat_amt, remark, raw_row, batch)
                                  VALUES (:d, :cd, :cn, :pd, :q, :sa, :va, :rk, :raw, :b)');
            $upPartner = $pdo->prepare('INSERT INTO tt_ecount_partners (cust_cd, cust_name, source)
                                       VALUES (:cd, :nm, "excel")
                                       ON DUPLICATE KEY UPDATE cust_name=VALUES(cust_name)');
            $n = 0; $partners = 0; $seenP = [];
            foreach ($parsed['rows'] as $row) {
                $get = static function (string $f) use ($map, $row): string {
                    $i = $map[$f] ?? null;
                    return ($i === null || !isset($row[$i])) ? '' : trim((string)$row[$i]);
                };
                $custName = mb_substr($get('cust_name'), 0, 100);
                if ($custName === '') continue;
                $ioDate = preg_replace('/[^0-9]/', '', $get('io_date'));
                if (strlen($ioDate) === 8) {
                    /* YYYYMMDD 정규화 (엑셀 표기 다양성 흡수) */
                } elseif (strlen($ioDate) >= 6) {
                    $ioDate = mb_substr($ioDate, 0, 8);
                } else {
                    $ioDate = '';
                }
                /* [v63] 거래처 코드 정규화 — 대시/공백 제거 (중복 거래처 방지) */
                $custCd = preg_replace('/[\s\-]/', '', mb_substr($get('cust_cd'), 0, 30));
                $ins->execute([
                    'd' => $ioDate, 'cd' => $custCd, 'cn' => $custName,
                    'pd' => mb_substr($get('prod_des'), 0, 150),
                    'q' => preg_replace('/[^0-9.\-]/', '', $get('qty')),
                    'sa' => preg_replace('/[^0-9.\-]/', '', $get('supply_amt')),
                    'va' => preg_replace('/[^0-9.\-]/', '', $get('vat_amt')),
                    'rk' => mb_substr($get('remark'), 0, 200),
                    'raw' => json_encode($row, JSON_UNESCAPED_UNICODE),
                    'b' => $batch,
                ]);
                $n++;
                if ($custName !== '' && !isset($seenP[$custName])) {
                    $seenP[$custName] = true;
                    $upPartner->execute(['cd' => $custCd !== '' ? $custCd : 'UPLOAD-' . md5($custName), 'nm' => $custName]);
                    $partners++;
                }
            }
            ecount_log('import', 'purchase_list', 'ok', '구매현황 엑셀 업로드 ' . $n . '행 · 거래처 ' . $partners . '건 수집');
            flash('admin_success', '구매현황 ' . number_format($n) . '건을 업로드했습니다 · 거래처 ' . $partners . '건이 거래처 목록에 수집되었어요 (이전 자료는 교체됨)');
        } catch (Throwable $e) {
            flash('admin_error', '업로드 처리 중 오류: ' . $e->getMessage() . ' — 반복되면 이 메시지를 그대로 알려주세요.');
        }
        redirect('/admin/ecount-purchase-list.php');
    }
    redirect('/admin/ecount-purchase-list.php');
}

/* ===== 엑셀(CSV) 다운로드 ===== */
if (($_GET['export'] ?? '') === 'csv') {
    $q = trim((string)($_GET['q'] ?? ''));
    $where = $q !== '' ? 'WHERE cust_name LIKE :q1 OR prod_des LIKE :q2 OR cust_cd LIKE :q3' : '';
    $st = $pdo->prepare("SELECT * FROM tt_ecount_purchase_list {$where} ORDER BY io_date DESC, id DESC");
    $st->execute($q !== '' ? ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%'] : []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecount-purchase-list-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['번호', '일자', '거래처코드', '거래처명', '품목', '수량', '공급가액', '부가세', '적요', '업로드일시']);
    $n = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $n++;
        fputcsv($out, [$n, (string)$r['io_date'], (string)$r['cust_cd'], (string)$r['cust_name'], (string)$r['prod_des'],
            (string)$r['qty'], (string)$r['supply_amt'], (string)$r['vat_amt'], (string)$r['remark'],
            substr((string)$r['imported_at'], 0, 16)]);
    }
    fclose($out);
    exit;
}

/* ===== 목록 ===== (v63: 전체를 try/catch로 — 서버 오류 대신 읽을 수 있는 안내 표시) ===== */
$loadError = '';
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
$rows = [];
$total = 0;
$pages = 1;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = 0;
$lastImport = null;
$partnerNames = [];
$perPage = 30;
try {
    if ($q !== '') {
        $where = 'WHERE cust_name LIKE :q1 OR prod_des LIKE :q2 OR cust_cd LIKE :q3';
        $params = ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%'];
    }
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_ecount_purchase_list {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $listStmt = $pdo->prepare("SELECT * FROM tt_ecount_purchase_list {$where} ORDER BY io_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    /* [v63] MAX 집계 — 빈 테이블이면 imported_at가 NULL로 와서 표시 가드가 안전하게 건너뜀 */
    $lastImport = $pdo->query('SELECT MAX(imported_at) AS imported_at, COUNT(*) AS c FROM tt_ecount_purchase_list')->fetch(PDO::FETCH_ASSOC) ?: null;
    $partnerNames = $pdo->query('SELECT DISTINCT cust_name FROM tt_ecount_purchase_list WHERE cust_name <> "" ORDER BY cust_name LIMIT 500')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $loadError = '구매현황 자료를 읽지 못했습니다: ' . $e->getMessage() . ' — 이 메시지가 계속 보이면 그대로 알려주세요.';
    sg_error_log('[ecount-purchase-list] ' . $e->getMessage());
}

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">📈 이카운트 구매현황 (엑셀 업로드)</h2>
      <p class="admin-list-sub">이카운트는 구매(입고) 전표를 읽는 API를 제공하지 않아서, <b>ERP 구매현황 화면에서 엑셀로 내려받은 자료를 여기에 올리면</b> 쇼핑몰에서 거래처 검색·기간 조회·엑셀 다운로드가 됩니다. 올린 거래처명은 거래처 목록에 자동 수집돼요. (발주 단계는 <a href="<?= BASE_URL ?>/admin/ecount-purchase-orders.php" style="text-decoration:underline">발주서</a>에서 API로 바로 조회돼요)</p>
    </div>
    <div style="display:flex;gap:8px">
      <a class="btn" href="<?= BASE_URL ?>/admin/ecount-purchase-list.php?export=csv<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">⬇ 엑셀(CSV) 다운로드</a>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>
  <?php if ($loadError !== ''): ?><div class="alert alert-error"><?= h($loadError) ?></div><?php endif; ?>

  <!-- ===== 업로드 ===== -->
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:12px 0;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="upload">
    <div><label style="font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px">구매현황 엑셀(CSV) 업로드</label>
      <input type="file" name="csv_file" accept=".csv,text/csv" required></div>
    <button type="submit" class="btn btn-primary">📤 업로드 (이전 자료 교체)</button>
    <span style="font-size:12px;color:#64748b">※ ERP 구매현황 화면에서 엑셀 저장 → "CSV(쉼표로 분리)(*.csv)"로 저장해서 올려주세요. 머리행(거래처·일자 등)이 포함되어야 해요.</span>
  </form>
  <?php if (!empty($lastImport['imported_at'])): $eplTs = strtotime((string)$lastImport['imported_at']); ?><p class="admin-list-sub">마지막 업로드: <b><?= $eplTs ? h(date('Y-m-d H:i', $eplTs)) : h((string)$lastImport['imported_at']) ?></b> · <?= number_format((int)$lastImport['c']) ?>행<?= $q !== '' ? ' · 현재 검색 "' . h($q) . '"' : '' ?></p><?php endif; ?>

  <!-- ===== 검색 ===== -->
  <form method="get" style="display:flex;gap:8px;max-width:520px;margin:12px 0">
    <input type="text" name="q" list="eplCustList" value="<?= h($q) ?>" placeholder="거래처명·품목 검색 — 예: 윈터 (거래처명이 자동완성돼요)" style="flex:1">
    <button type="submit" class="btn">🔎 검색</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= BASE_URL ?>/admin/ecount-purchase-list.php">초기화</a><?php endif; ?>
  </form>
  <datalist id="eplCustList">
    <?php foreach ($partnerNames as $nm): ?><option value="<?= h((string)$nm) ?>"></option><?php endforeach; ?>
  </datalist>

  <?php if ($total > 0): ?>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 6px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px">
    <b>📋 <?= number_format($total) ?>건</b><span style="color:#64748b">·</span>
    <span>현재 <b><?= $page ?></b> / <?= $pages ?>페이지</span><span style="color:#64748b">·</span>
    <span><?= number_format($offset + 1) ?> ~ <?= number_format($offset + count($rows)) ?>번 표시 중</span>
  </div>
  <?php endif; ?>

  <div style="overflow:auto">
  <table class="admin-table" style="min-width:860px">
    <thead><tr><th>번호</th><th>일자</th><th>거래처</th><th>품목</th><th>수량</th><th>공급가액</th><th>부가세</th><th>적요</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">업로드된 구매현황이 없습니다 — ERP 구매현황 화면에서 엑셀로 내려받아 CSV로 저장한 뒤 위에서 업로드해 주세요.</td></tr><?php endif; ?>
    <?php $n = $offset; foreach ($rows as $r): $n++; ?>
    <tr>
      <td><?= $n ?></td>
      <td><?= h(mb_substr(preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', (string)$r['io_date']), 0, 10)) ?></td>
      <td><b><?= h(mb_substr((string)$r['cust_name'], 0, 22)) ?></b><?= $r['cust_cd'] !== '' ? ' <small style="color:#94a3b8">' . h(mb_substr((string)$r['cust_cd'], 0, 14)) . '</small>' : '' ?></td>
      <td><?= h(mb_substr((string)$r['prod_des'], 0, 34)) ?></td>
      <td style="text-align:right"><?= h((string)$r['qty']) ?: '-' ?></td>
      <td style="text-align:right"><?= $r['supply_amt'] !== '' ? number_format((float)$r['supply_amt']) : '-' ?></td>
      <td style="text-align:right"><?= $r['vat_amt'] !== '' ? number_format((float)$r['vat_amt']) : '-' ?></td>
      <td><?= h(mb_substr((string)$r['remark'], 0, 20)) ?></td>
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
  <p class="admin-list-sub" style="margin-top:6px">총 <?= number_format($total) ?>건<?= $pages > 1 ? ' · ' . $page . '/' . $pages . '페이지 · 페이지당 ' . $perPage . '건' : '' ?></p>

  <?php if ($total > 0): ?>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="clear">
    <button type="submit" class="btn" onclick="return confirm('업로드한 구매현황 자료를 전부 비울까요?')">🗑 업로드 자료 전부 비우기</button>
  </form>
  <?php endif; ?>
</div>
