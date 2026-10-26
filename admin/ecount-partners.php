<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('ecount');
ensure_ecount_tables();

$pageTitle = '이카운트 거래처 관리';

$okMsg = flash('admin_success');
$errMsg = flash('admin_error');
$pdo = Database::connection();

/* ===== [v63] 엑셀(CSV) 업로드 — ERP 거래처현황에서 내려받은 자료 유연 매칭 ===== */
function epp_parse_csv(string $tmpPath): array
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
        if ($cells === ['']) continue;
        if (!$header) { $header = $cells; continue; }
        $rows[] = $cells;
    }
    fclose($stream);
    return ['header' => $header, 'rows' => $rows];
}

const EPP_FIELD_ALIASES = [
    'cust_cd'   => ['거래처코드', '사업자번호', '거래처코드(BUSINESS_NO)', '거래처 번호'],
    'cust_name' => ['거래처', '거래처명', '상호', '거래처명(상호)'],
    'boss_name' => ['대표자', '대표자명', '대표'],
    'tel'       => ['전화', '전화번호', '연락처', '휴대폰', '전화번호1'],
    'addr'      => ['주소', '소재지', '주소1', '본사주소'],
    'uptae'     => ['업태', '업태명'],
    'jongmok'   => ['종목', '종목명'],
    'email'     => ['이메일', 'E-Mail', 'EMAIL', '메일'],
];

function epp_build_column_map(array $header): array
{
    $map = [];
    foreach (EPP_FIELD_ALIASES as $field => $aliases) {
        $map[$field] = null;
        foreach ($header as $i => $h) {
            $h = trim((string)$h);
            if ($h !== '' && in_array($h, $aliases, true)) { $map[$field] = $i; break; }
        }
        if ($map[$field] === null) { /* 부분일치 백업 */
            foreach ($header as $i => $h) {
                $h = trim((string)$h);
                if ($h === '') continue;
                foreach ($aliases as $a) { if ($a !== '' && mb_strpos($h, $a) !== false) { $map[$field] = $i; break 2; } }
            }
        }
    }
    return ['map' => $map, 'missing' => ($map['cust_name'] === null ? ['cust_name'] : []), 'header' => $header];
}


/* ===== 액션 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/ecount-partners.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $adminId = (int)AdminAuth::currentAdminId();

    if ($action === 'register') {
        $r = ecount_save_partner([
            'cust_cd'   => (string)($_POST['cust_cd'] ?? ''),
            'cust_name' => (string)($_POST['cust_name'] ?? ''),
            'boss_name' => (string)($_POST['boss_name'] ?? ''),
            'tel'       => (string)($_POST['tel'] ?? ''),
            'addr'      => (string)($_POST['addr'] ?? ''),
            'uptae'     => (string)($_POST['uptae'] ?? ''),
            'jongmok'   => (string)($_POST['jongmok'] ?? ''),
            'email'     => (string)($_POST['email'] ?? ''),
            'remarks'   => (string)($_POST['remarks'] ?? ''),
        ]);
        $isEdit = trim((string)($_POST['is_edit'] ?? '')) === '1';
        if ($r['ok']) AdminAuth::log($adminId, 'ecount_partner', '거래처 ' . ($isEdit ? '수정' : '등록') . ': ' . $_POST['cust_name']);
        if ($r['ok'] && $isEdit) $r['message'] .= ' — 쇼핑몰과 ERP 양쪽 모두 갱신되었습니다.';
        $r['ok'] ? flash('admin_success', $r['message']) : flash('admin_error', $r['message']);
        redirect('/admin/ecount-partners.php');
    }

    if ($action === 'collect') {
        /* 발주서(최근 30일) 조회에서 거래처 자동 수집 — 다건조회 API는 10분에 1회 */
        $r = ecount_fetch_purchase_orders(date('Ymd', strtotime('-30 days')), date('Ymd'), true);
        $r['ok'] ? flash('admin_success', $r['message'] . ' — 거래처도 함께 수집되었습니다.') : flash('admin_error', $r['message']);
        redirect('/admin/ecount-partners.php');
    }

    /* [v63] 엑셀(CSV) 업로드 — ERP 거래처현황 등에서 내려받은 거래처 일괄 반영 (관리자에만 저장, ERP 재전송 없음) */
    if ($action === 'upload_excel') {
        @set_time_limit(300);
        if (($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($_FILES['csv_file']['name'])) {
            flash('admin_error', '업로드할 CSV 파일을 선택해 주세요.');
            redirect('/admin/ecount-partners.php');
        }
        if (strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION)) !== 'csv') {
            flash('admin_error', 'CSV 파일(.csv)만 업로드할 수 있습니다 — 엑셀에서 "CSV(쉼표로 분리)"로 저장해서 올려주세요.');
            redirect('/admin/ecount-partners.php');
        }
        try {
            $parsed = epp_parse_csv($_FILES['csv_file']['tmp_name']);
            if (empty($parsed['header']) || empty($parsed['rows'])) {
                flash('admin_error', '업로드한 파일에서 머리행 또는 데이터 행을 찾지 못했습니다.');
                redirect('/admin/ecount-partners.php');
            }
            $report = epp_build_column_map($parsed['header']);
            if (!empty($report['missing'])) {
                flash('admin_error', '필수 컬럼(거래처명)을 찾지 못했습니다 — 머리행(거래처/거래처명 포함)과 함께 저장해 주세요.');
                redirect('/admin/ecount-partners.php');
            }
            $map = $report['map'];
            $up = $pdo->prepare('INSERT INTO tt_ecount_partners (cust_cd, cust_name, boss_name, tel, addr, uptae, jongmok, email, source)
                                 VALUES (:cd, :nm, :boss, :tel, :addr, :ut, :jm, :em, "excel")
                                 ON DUPLICATE KEY UPDATE
                                   cust_name=IF(VALUES(cust_name)<>"", VALUES(cust_name), cust_name),
                                   boss_name=IF(VALUES(boss_name)<>"", VALUES(boss_name), boss_name),
                                   tel=IF(VALUES(tel)<>"", VALUES(tel), tel),
                                   addr=IF(VALUES(addr)<>"", VALUES(addr), addr),
                                   uptae=IF(VALUES(uptae)<>"", VALUES(uptae), uptae),
                                   jongmok=IF(VALUES(jongmok)<>"", VALUES(jongmok), jongmok),
                                   email=IF(VALUES(email)<>"", VALUES(email), email),
                                   source=IF(source="manual", source, "excel")');
            $findByName = $pdo->prepare('SELECT cust_cd FROM tt_ecount_partners WHERE cust_name = :nm LIMIT 1');
            $n = 0; $new = 0; $upd = 0;
            foreach ($parsed['rows'] as $row) {
                $get = static function (string $f) use ($map, $row): string {
                    $i = $map[$f] ?? null;
                    return ($i === null || !isset($row[$i])) ? '' : trim((string)$row[$i]);
                };
                $custName = mb_substr($get('cust_name'), 0, 100);
                if ($custName === '') continue;
                /* 코드 정규화 — 사업자번호는 대시/공백 제거한 10자리로 통일 (중복 거래처 방지) */
                $custCd = preg_replace('/[\s\-]/', '', mb_substr($get('cust_cd'), 0, 30));
                /* 대상 해결: 코드로 찾고 → 없으면 이름으로 찾고 → 그래도 없으면 신규 */
                $findByCd = $pdo->prepare('SELECT cust_cd FROM tt_ecount_partners WHERE cust_cd = :cd LIMIT 1');
                $findByCd->execute(['cd' => $custCd]);
                $foundCd = $findByCd->fetchColumn();
                if ($custCd === '' || $foundCd === false) {
                    $findByName->execute(['nm' => $custName]);
                    $foundNm = $findByName->fetchColumn();
                    if ($foundNm !== false) { $custCd = (string)$foundNm; $foundCd = $custCd; }
                }
                if ($custCd === '') $custCd = 'EXCEL-' . substr(md5($custName), 0, 16);
                $isNew = $foundCd === false;
                $up->execute([
                    'cd' => $custCd, 'nm' => $custName,
                    'boss' => mb_substr($get('boss_name'), 0, 50),
                    'tel' => mb_substr($get('tel'), 0, 50),
                    'addr' => mb_substr($get('addr'), 0, 200),
                    'ut' => mb_substr($get('uptae'), 0, 50),
                    'jm' => mb_substr($get('jongmok'), 0, 50),
                    'em' => mb_substr($get('email'), 0, 100),
                ]);
                $n++; $isNew ? $new++ : $upd++;
            }
            ecount_log('import', 'partners', 'ok', '거래처 엑셀 업로드 ' . $n . '건 (신규 ' . $new . ' · 갱신 ' . $upd . ')');
            flash('admin_success', '거래처 ' . number_format($n) . '건을 반영했습니다 — 신규 ' . number_format($new) . '건 · 갱신 ' . number_format($upd) . '건. (엑셀로 올린 자료는 관리자에만 저장되고 ERP로 다시 전송하지 않아요)');
        } catch (Throwable $e) {
            flash('admin_error', '업로드 처리 중 오류: ' . $e->getMessage() . ' — 반복되면 이 메시지를 그대로 알려주세요.');
        }
        redirect('/admin/ecount-partners.php');
    }
    redirect('/admin/ecount-partners.php');
}

/* ===== 목록 ===== */
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE cust_cd LIKE :q1 OR cust_name LIKE :q2 OR tel LIKE :q3 OR addr LIKE :q4';
    $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $q . '%';
}

/* [v58] 엑셀(CSV) 다운로드 */
if (($_GET['export'] ?? '') === 'csv') {
    $exp = $pdo->prepare("SELECT * FROM tt_ecount_partners {$where} ORDER BY updated_at DESC");
    $exp->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecount-partners-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['거래처코드', '거래처명', '대표자', '업태', '종목', '전화', '이메일', '주소', '등록경로', '갱신일시']);
    while ($r = $exp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            (string)$r['cust_cd'], (string)$r['cust_name'], (string)($r['boss_name'] ?? ''),
            (string)($r['uptae'] ?? ''), (string)($r['jongmok'] ?? ''), (string)($r['tel'] ?? ''),
            (string)($r['email'] ?? ''), (string)($r['addr'] ?? ''),
            $r['source'] === 'manual' ? '직접등록' : ($r['source'] === 'excel' ? '엑셀업로드' : '발주수집'), substr((string)$r['updated_at'], 0, 16),
        ]);
    }
    fclose($out);
    exit;
}

/* [v58] 수정 대상 거래처 (?edit=거래처코드) — 폼에 불러와 수정 저장 */
$editPartner = null;
$editCd = trim((string)($_GET['edit'] ?? ''));
if ($editCd !== '') {
    $ep = $pdo->prepare('SELECT * FROM tt_ecount_partners WHERE cust_cd = :cd LIMIT 1');
    $ep->execute(['cd' => $editCd]);
    $editPartner = $ep->fetch(PDO::FETCH_ASSOC) ?: null;
}

$list = $pdo->prepare("SELECT * FROM tt_ecount_partners {$where} ORDER BY updated_at DESC LIMIT 200");
$list->execute($params);
$partners = $list->fetchAll(PDO::FETCH_ASSOC);

$st = ecount_status_summary();
require __DIR__ . '/includes/header.php';
?>

<style>
.ec-frm{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end}
.ec-frm label{font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px}
.ec-frm input{width:100%}
.src-manual{background:#dbeafe;color:#1d4ed8}
.src-orders{background:#dcfce7;color:#15803d}
.src-excel{background:#fef9c3;color:#a16207}
</style>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🤝 이카운트 거래처 관리 (등록·조회·수정)</h2>
      <p class="admin-list-sub">ERP에 있는 거래처를 이곳에서 확인·수정합니다. 이카운트는 거래처 '목록조회' API를 제공하지 않아서, 거래처는 <b>구매현황(발주서) 화면의 "년도 전체 조회"</b>를 돌리면 그 해 발주내역에 나온 거래처가 전부 자동 수집됩니다. 여기서 수정 저장하면 <b>쇼핑몰과 ERP 양쪽에 바로 반영</b>되고, ERP에서 바뀐 내용은 다음 수집 때 자동으로 가져와집니다.</p>
    </div>
    <div style="display:flex;gap:8px">
      <a class="btn" href="<?= BASE_URL ?>/admin/ecount-purchase-orders.php">📅 구매현황 년도 전체 조회로 수집 →</a>
      <a class="btn" href="<?= BASE_URL ?>/admin/ecount-partners.php?export=csv<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">⬇ 엑셀(CSV)</a>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <form method="get" style="display:flex;gap:8px;max-width:460px;margin:12px 0">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="거래처코드·상호·전화·주소 검색" style="flex:1">
    <button type="submit" class="btn">검색</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= BASE_URL ?>/admin/ecount-partners.php">초기화</a><?php endif; ?>
  </form>

  <!-- [v63] 거래처 엑셀(CSV) 업로드 -->
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:12px 0;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="upload_excel">
    <div><label style="font-size:12.5px;font-weight:700;color:#475569;display:block;margin-bottom:4px">거래처 엑셀(CSV) 업로드</label>
      <input type="file" name="csv_file" accept=".csv,text/csv" required></div>
    <button type="submit" class="btn btn-primary">📤 일괄 반영</button>
    <span style="font-size:12px;color:#64748b">※ ERP 거래처현황 화면에서 엑셀로 내려받은 뒤 "CSV(쉼표로 분리)"로 저장해서 올리면 <b>거래처명이 같으면 갱신, 새 거래처면 등록</b>됩니다. (관리자에만 저장 — ERP로 다시 전송하지 않아요)</span>
  </form>

  <div style="overflow:auto">
  <table class="admin-table" style="min-width:900px">
    <thead><tr><th>거래처코드</th><th>거래처명</th><th>대표자</th><th>업태/종목</th><th>전화</th><th>주소</th><th>등록경로</th><th>갱신일시</th><th></th></tr></thead>
    <tbody>
    <?php if (!$partners): ?><tr><td colspan="9" style="text-align:center;color:#94a3b8">저장된 거래처가 없습니다 — 구매현황 화면의 "년도 전체 조회"로 수집하거나 아래 등록 폼으로 추가해 주세요.</td></tr><?php endif; ?>
    <?php foreach ($partners as $p): ?>
    <tr>
      <td><b><?= h($p['cust_cd']) ?></b></td>
      <td><?= h($p['cust_name']) ?></td>
      <td><?= h($p['boss_name'] ?? '') ?></td>
      <td><?= h(trim(($p['uptae'] ?? '') . (($p['jongmok'] ?? '') !== '' ? ' / ' . $p['jongmok'] : ''))) ?: '-' ?></td>
      <td><?= h($p['tel'] ?? '') ?></td>
      <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($p['addr'] ?? '') ?></td>
      <td><span class="ec-flag <?= $p['source'] === 'manual' ? 'src-manual' : ($p['source'] === 'excel' ? 'src-excel' : 'src-orders') ?>"><?= $p['source'] === 'manual' ? '직접등록' : ($p['source'] === 'excel' ? '엑셀업로드' : '발주수집') ?></span></td>
      <td><?= h(date('m-d H:i', strtotime((string)$p['updated_at']))) ?></td>
      <td><a class="btn" href="<?= BASE_URL ?>/admin/ecount-partners.php?edit=<?= urlencode((string)$p['cust_cd']) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">✏️ 수정</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ===== 거래처 등록 / 수정 ===== -->
<div class="admin-card">
  <div class="admin-list-header">
    <div><h2 class="admin-list-title"><?= $editPartner ? '✏️ 거래처 수정 — ' . h($editPartner['cust_name']) . ' (ERP + 관리자 동시 갱신)' : '➕ 거래처 등록 (ERP + 관리자 동시 저장)' ?></h2>
      <p class="admin-list-sub"><?= $editPartner
        ? '수정 저장하면 이카운트 ERP와 관리자 목록 양쪽에 바로 반영됩니다. (ERP에서 직접 바꾼 내용은 다음 구매현황 조회·수집 때 여기에 자동 반영돼요)'
        : '입력한 거래처가 이카운트 ERP에 등록되고 관리자에도 저장됩니다. 거래처코드(BUSINESS_NO)는 일반적으로 사업자등록번호 10자리를 사용합니다.' ?></p></div>
    <?php if ($editPartner): ?><a class="btn" href="<?= BASE_URL ?>/admin/ecount-partners.php">＋ 새 거래처 등록으로 전환</a><?php endif; ?>
  </div>
  <form method="post" class="ec-frm" style="max-width:900px">
    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="action" value="register">
    <input type="hidden" name="is_edit" value="<?= $editPartner ? '1' : '' ?>">
    <div><label>거래처코드 (사업자번호) *</label><input type="text" name="cust_cd" required maxlength="30" placeholder="예: 123-45-67890" value="<?= $editPartner ? h($editPartner['cust_cd']) : '' ?>" <?= $editPartner ? 'readonly' : '' ?>></div>
    <div><label>거래처명 (상호) *</label><input type="text" name="cust_name" required maxlength="100" value="<?= $editPartner ? h($editPartner['cust_name']) : '' ?>"></div>
    <div><label>대표자명</label><input type="text" name="boss_name" maxlength="50" value="<?= $editPartner ? h($editPartner['boss_name'] ?? '') : '' ?>"></div>
    <div><label>전화번호</label><input type="text" name="tel" maxlength="50" placeholder="02-000-0000" value="<?= $editPartner ? h($editPartner['tel'] ?? '') : '' ?>"></div>
    <div><label>업태</label><input type="text" name="uptae" maxlength="50" placeholder="예: 타이어 도소매" value="<?= $editPartner ? h($editPartner['uptae'] ?? '') : '' ?>"></div>
    <div><label>종목</label><input type="text" name="jongmok" maxlength="50" value="<?= $editPartner ? h($editPartner['jongmok'] ?? '') : '' ?>"></div>
    <div><label>이메일</label><input type="email" name="email" maxlength="100" value="<?= $editPartner ? h($editPartner['email'] ?? '') : '' ?>"></div>
    <div><label>주소</label><input type="text" name="addr" maxlength="200" style="grid-column:span 2" value="<?= $editPartner ? h($editPartner['addr'] ?? '') : '' ?>"></div>
    <div><label>적요</label><input type="text" name="remarks" maxlength="200" value="<?= $editPartner ? h($editPartner['remarks'] ?? '') : '' ?>"></div>
    <div><button type="submit" class="btn btn-primary"><?= $editPartner ? 'ERP + 관리자에 수정 저장' : 'ERP + 관리자에 등록' ?></button></div>
  </form>
</div>
