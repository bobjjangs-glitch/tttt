<?php
// erp/partner_dc.php — 거래처별 DC율 관리 (개별 수정 / 선택 일괄 / 엑셀 일괄)
require __DIR__ . '/inc/bootstrap.php';
require_login();
$me = current_user();
if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 사용할 수 있습니다.');
}
require_once __DIR__ . '/inc/dc_lib.php';
require_once __DIR__ . '/inc/dc_xlsx.php';

const DCP_MAX_ROWS  = 5000;     // 엑셀 업로드 최대 행
const DCP_MAX_BYTES = 5242880;  // 5MB
const DCP_MAX_BULK  = 500;      // 화면 일괄 처리 최대 건수
const DCP_LIST_MAX  = 300;      // 화면 목록 최대 건수

$pdo = db();
$uid = (int)($me['id'] ?? 0);

function dcp_like(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
}

/** [WHERE 문자열, 바인딩] */
function dcp_where(string $q, bool $onlyDc, bool $inclOff): array
{
    $w = ['1=1'];
    $a = [];
    if (!$inclOff) {
        $w[] = 'is_active = 1';
    }
    if ($onlyDc) {
        $w[] = 'dc_rate > 0';
    }
    if ($q !== '') {
        $w[] = '(name LIKE ? OR partner_code LIKE ? OR ceo_name LIKE ? OR phone LIKE ?)';
        for ($i = 0; $i < 4; $i++) {
            $a[] = dcp_like($q);
        }
    }
    return [implode(' AND ', $w), $a];
}

/**
 * 변경 적용 (한 트랜잭션, 실패 시 전체 롤백).
 * $changes: [partner_id => 새 DC율]. 반환 [변경 건수, 동일 건수, 없는 거래처 건수]
 */
function dcp_apply(PDO $pdo, array $changes, string $source, int $userId): array
{
    $now = date('Y-m-d H:i:s');
    $changed = 0;
    $same = 0;
    $missing = 0;
    $pdo->beginTransaction();
    try {
        $up  = $pdo->prepare('UPDATE erp_partners SET dc_rate = ?, updated_at = ? WHERE id = ?');
        $log = $pdo->prepare(
            'INSERT INTO erp_dc_log (partner_id, old_rate, new_rate, source, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach (array_chunk($changes, 500, true) as $chunk) {
            $ids = array_keys($chunk);
            $st = $pdo->prepare(
                'SELECT id, dc_rate FROM erp_partners WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE'
            );
            $st->execute($ids);
            $cur = [];
            foreach ($st as $r) {
                $cur[(int)$r['id']] = (int)$r['dc_rate'];
            }
            foreach ($chunk as $id => $new) {
                if (!isset($cur[$id])) {
                    $missing++;
                    continue;
                }
                if ($cur[$id] === $new) {
                    $same++;
                    continue;
                }
                $up->execute([$new, $now, $id]);
                $log->execute([$id, $cur[$id], $new, $source, $userId, $now]);
                $changed++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    try {
        audit('dc_change', 'partner', null, $source . ' 변경 ' . $changed . '건');
    } catch (Throwable $e) {
        error_log('[ERP] partner_dc audit: ' . $e->getMessage());
    }
    return [$changed, $same, $missing];
}

/** 업로드 파일을 검증만 합니다 (DB 변경 없음). */
function dcp_parse_upload(PDO $pdo, string $path): array
{
    $out = ['errors' => [], 'err_total' => 0, 'changes' => [], 'rows' => [], 'same' => 0, 'blank' => 0];
    $err = function (string $m) use (&$out) {
        $out['err_total']++;
        if (count($out['errors']) < 50) {
            $out['errors'][] = $m;
        }
    };
    try {
        $rows = dcx_read($path);
    } catch (RuntimeException $e) {
        $err($e->getMessage());
        return $out;
    }
    if (count($rows) > DCP_MAX_ROWS + 10) {
        $err('행이 너무 많습니다. ' . DCP_MAX_ROWS . '행 이하로 나눠서 올려 주세요.');
        return $out;
    }

    // 헤더 행 찾기 (앞 5행 안에서 '거래처코드'와 'DC...' 열)
    $hr = -1;
    $cCode = -1;
    $cDc = -1;
    for ($i = 0; $i < min(5, count($rows)); $i++) {
        $a = -1;
        $b = -1;
        foreach ($rows[$i] as $k => $h) {
            $n = mb_strtoupper(preg_replace('/\s+/u', '', (string)$h) ?? '');
            if ($a < 0 && ($n === '거래처코드' || $n === '코드')) {
                $a = $k;
            }
            if ($b < 0 && mb_strpos($n, 'DC') === 0) {
                $b = $k;
            }
        }
        if ($a >= 0 && $b >= 0) {
            $hr = $i;
            $cCode = $a;
            $cDc = $b;
            break;
        }
    }
    if ($hr < 0) {
        $err('헤더를 찾지 못했습니다. 1행에 "거래처코드"와 "DC율(%)" 열이 있어야 합니다. 화면의 "현재 DC율 다운로드" 파일을 양식으로 쓰세요.');
        return $out;
    }

    $seen = [];
    $want = [];
    for ($i = $hr + 1; $i < count($rows); $i++) {
        $code = trim((string)($rows[$i][$cCode] ?? ''));
        $raw  = trim((string)($rows[$i][$cDc] ?? ''));
        if ($code === '' && $raw === '') {
            continue;
        }
        $n = $i + 1;
        if ($code === '') {
            $err($n . '행: 거래처코드가 비어 있습니다.');
            continue;
        }
        $code = strtoupper($code);
        if (!preg_match('/^[A-Z0-9._\-]{1,20}$/', $code)) {
            $err($n . '행: 거래처코드 형식이 올바르지 않습니다.');
            continue;
        }
        if (isset($seen[$code])) {
            $err($n . '행: 거래처코드 ' . $code . ' 가 ' . $seen[$code] . '행에도 있습니다 (중복).');
            continue;
        }
        $seen[$code] = $n;
        if ($raw === '') {
            $out['blank']++; // 빈칸 = 변경 안 함
            continue;
        }
        $norm = str_replace([',', ' '], '', $raw);
        // 엑셀 % 서식 셀은 10% 가 0.1 로 저장됨 → 조용히 0.1% 로 읽히는 사고 방지
        if (strpos($norm, '%') === false && preg_match('/^0?\.\d*[1-9]\d*$/', $norm)) {
            $err($n . '행: "' . $raw . '" 은 1 미만입니다. 엑셀 % 서식 셀이면 10% 가 0.1 로 읽힙니다. 숫자는 10 처럼 % 단위로, 0.5% 는 "0.5%" 처럼 % 를 붙여 글자로 입력하세요.');
            continue;
        }
        $r = dc_parse($norm);
        if ($r === null) {
            $err($n . '행: DC율 "' . $raw . '" 형식이 올바르지 않습니다 (0~100, 소수 둘째 자리까지).');
            continue;
        }
        $want[$code] = [$r, $n];
    }

    if ($want) {
        $found = [];
        foreach (array_chunk(array_keys($want), 500) as $chunk) {
            $st = $pdo->prepare(
                'SELECT id, partner_code, name, dc_rate FROM erp_partners WHERE partner_code IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $st->execute($chunk);
            foreach ($st as $r) {
                $found[strtoupper((string)$r['partner_code'])] = $r;
            }
        }
        foreach ($want as $code => [$rate, $n]) {
            if (!isset($found[$code])) {
                $err($n . '행: 등록되지 않은 거래처코드 ' . $code);
                continue;
            }
            $p = $found[$code];
            $old = (int)$p['dc_rate'];
            if ($old === $rate) {
                $out['same']++;
                continue;
            }
            $out['changes'][(int)$p['id']] = $rate;
            $out['rows'][] = [(string)$p['partner_code'], (string)$p['name'], $old, $rate];
        }
    }
    if ($out['err_total'] > 0) {
        $out['changes'] = []; // 오류가 하나라도 있으면 전부 적용하지 않음
        $out['rows'] = [];
    }
    return $out;
}

// ---------- 준비 상태 ----------
$why = null;
if (!dc_ready($pdo, $why)) {
    page_header('DC율 관리', 'dc');
    echo '<div class="card" style="padding:16px"><p>' . h($why ?? '준비되지 않았습니다.') . '</p></div>';
    page_footer();
    exit;
}

// ---------- 필터 ----------
$q       = in_str($_GET, 'q', 50);
$onlyDc  = !empty($_GET['dc']);
$inclOff = !empty($_GET['off']);
$qsArr   = array_filter(
    ['q' => $q, 'dc' => $onlyDc ? '1' : '', 'off' => $inclOff ? '1' : ''],
    function ($v) { return $v !== ''; }
);
$qs   = http_build_query($qsArr);
$self = 'partner_dc.php' . ($qs !== '' ? '?' . $qs : '');
[$whereSql, $whereArgs] = dcp_where($q, $onlyDc, $inclOff);

// ---------- 엑셀 다운로드 (현재 DC율 = 업로드 양식) ----------
if (isset($_GET['download'])) {
    try {
        $st = $pdo->prepare(
            'SELECT partner_code, name, dc_rate FROM erp_partners WHERE ' . $whereSql
            . ' ORDER BY (partner_code IS NULL), partner_code, id LIMIT ' . DCP_MAX_ROWS
        );
        $st->execute($whereArgs);
        $data = [['거래처코드', '상호(참고용, 업로드 시 무시)', 'DC율(%)']];
        foreach ($st as $r) {
            $data[] = [(string)$r['partner_code'], (string)$r['name'], dc_fmt((int)$r['dc_rate'])];
        }
        $bin = dcx_write($data, [14, 40, 12]);
        try {
            audit('dc_export', 'partner', null, (count($data) - 1) . '건');
        } catch (Throwable $e) {
            error_log('[ERP] partner_dc export audit: ' . $e->getMessage());
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="partner_dc_' . date('Ymd') . '.xlsx"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
        exit;
    } catch (Throwable $e) {
        error_log('[ERP] partner_dc download: ' . $e->getMessage());
        http_response_code(200);
        exit('엑셀 파일을 만들지 못했습니다. 서버 로그를 확인하세요.');
    }
}

// ---------- POST ----------
$errors  = [];
$posted  = [];
$badSet  = [];
$preview = null;

if (is_post()) {
    csrf_check();
    $act = in_str($_POST, 'act', 20);
    try {
        if ($act === 'save_rows') {
            $raw = $_POST['rate'] ?? [];
            if (!is_array($raw) || !$raw) {
                $errors[] = '저장할 값이 없습니다.';
            } elseif (count($raw) > DCP_MAX_BULK) {
                $errors[] = '한 번에 ' . DCP_MAX_BULK . '건까지만 저장할 수 있습니다.';
            } else {
                $changes = [];
                $bad = [];
                foreach ($raw as $id => $v) {
                    $id = (int)$id;
                    if ($id <= 0) {
                        continue;
                    }
                    $posted[$id] = is_string($v) ? $v : '';
                    $r = dc_parse($posted[$id]);
                    if ($r === null) {
                        $bad[$id] = true;
                        continue;
                    }
                    $changes[$id] = $r;
                }
                if ($bad) {
                    $badSet = $bad;
                    $errors[] = 'DC율 형식이 올바르지 않은 칸이 ' . count($bad) . '건 있습니다 (0~100, 소수 둘째 자리까지, 비우지 말고 없으면 0). 빨간 칸을 고치면 전체를 다시 저장할 수 있습니다. 아무것도 저장되지 않았습니다.';
                } else {
                    [$c, $s] = dcp_apply($pdo, $changes, 'row', $uid);
                    flash($c . '건 변경했습니다. (같은 값 ' . $s . '건은 그대로)');
                    redirect($self);
                }
            }
        } elseif ($act === 'bulk_set') {
            $ids = $_POST['ids'] ?? [];
            $ids = is_array($ids)
                ? array_values(array_unique(array_filter(array_map('intval', $ids), function ($x) { return $x > 0; })))
                : [];
            $rate = dc_parse(in_str($_POST, 'bulk_rate', 10));
            if (!$ids) {
                $errors[] = '체크한 거래처가 없습니다.';
            } elseif (count($ids) > DCP_MAX_BULK) {
                $errors[] = '한 번에 ' . DCP_MAX_BULK . '건까지만 적용할 수 있습니다.';
            } elseif ($rate === null) {
                $errors[] = '일괄 적용할 DC율 형식이 올바르지 않습니다 (0~100, 소수 둘째 자리까지).';
            } else {
                $changes = [];
                foreach ($ids as $id) {
                    $changes[$id] = $rate;
                }
                [$c, $s, $m] = dcp_apply($pdo, $changes, 'bulk', $uid);
                flash($c . '건에 ' . dc_fmt($rate) . '% 를 적용했습니다. (이미 같은 값 ' . $s . '건' . ($m ? ', 없는 거래처 ' . $m . '건' : '') . ')');
                redirect($self);
            }
        } elseif ($act === 'xl_preview') {
            unset($_SESSION['dc_xl']);
            $f = $_FILES['xlsx'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = '파일을 선택하세요. (업로드 오류가 계속되면 파일이 5MB 이하인지 확인하세요.)';
            } elseif ((int)$f['size'] > DCP_MAX_BYTES) {
                $errors[] = '파일이 5MB 를 넘습니다.';
            } elseif (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                $errors[] = '.xlsx 파일만 올릴 수 있습니다.';
            } elseif (!is_uploaded_file((string)$f['tmp_name'])) {
                $errors[] = '업로드 파일이 올바르지 않습니다.';
            } else {
                $fh = fopen((string)$f['tmp_name'], 'rb');
                $sig = $fh ? fread($fh, 2) : '';
                if ($fh) {
                    fclose($fh);
                }
                if ($sig !== 'PK') {
                    $errors[] = '엑셀(.xlsx) 파일이 아닙니다. 이름만 바꾼 다른 형식 파일일 수 있습니다.';
                } else {
                    $preview = dcp_parse_upload($pdo, (string)$f['tmp_name']);
                    if (!$preview['err_total'] && $preview['changes']) {
                        $_SESSION['dc_xl'] = ['t' => time(), 'uid' => $uid, 'changes' => $preview['changes']];
                    }
                }
            }
        } elseif ($act === 'xl_apply') {
            $s = $_SESSION['dc_xl'] ?? null;
            unset($_SESSION['dc_xl']);
            if (!is_array($s) || (int)($s['uid'] ?? 0) !== $uid || (time() - (int)($s['t'] ?? 0)) > 1800 || empty($s['changes'])) {
                $errors[] = '미리보기 정보가 없거나 30분이 지나 만료되었습니다. 파일을 다시 올려 확인하세요.';
            } else {
                [$c, $sm, $m] = dcp_apply($pdo, $s['changes'], 'excel', $uid);
                flash('엑셀로 ' . $c . '건 변경했습니다. (같은 값 ' . $sm . '건' . ($m ? ', 없는 거래처 ' . $m . '건' : '') . ')');
                redirect($self);
            }
        }
    } catch (Throwable $e) {
        error_log('[ERP] partner_dc ' . ($act ?? '') . ': ' . $e->getMessage());
        $errors[] = '처리 중 오류가 나서 전부 취소했습니다. 아무것도 변경되지 않았습니다.'
            . (cfg('app.debug', false) ? ' (' . $e->getMessage() . ')' : '');
    }
}

// ---------- 목록 ----------
$st = $pdo->prepare('SELECT COUNT(*) FROM erp_partners WHERE ' . $whereSql);
$st->execute($whereArgs);
$total = (int)$st->fetchColumn();

$st = $pdo->prepare(
    'SELECT id, partner_code, name, ceo_name, is_active, dc_rate FROM erp_partners WHERE ' . $whereSql
    . ' ORDER BY (partner_code IS NULL), partner_code, id LIMIT ' . DCP_LIST_MAX
);
$st->execute($whereArgs);
$list = $st->fetchAll();

page_header('DC율 관리', 'dc');
?>
<style>
.dcp-note{color:var(--mute,#6b7280);font-size:13px;margin:4px 0 12px}
.dcp-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0}
.dcp-tbl{width:100%;border-collapse:collapse}
.dcp-tbl th,.dcp-tbl td{padding:7px 10px;border-bottom:1px solid var(--line,#e5e7eb);text-align:left}
.dcp-tbl .r{text-align:right}
.dcp-rate{width:84px;text-align:right}
.dcp-rate.chg{background:#fffbeb;border-color:#f59e0b}
.dcp-rate.bad{background:#fef2f2;border-color:#dc2626}
.dcp-tbl tr.off td{color:#9ca3af}
.dcp-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:10px 14px;margin:10px 0}
.dcp-card{border:1px solid var(--line,#e5e7eb);border-radius:10px;padding:14px 16px;margin:16px 0}
</style>

<h1>DC율 관리</h1>
<p class="dcp-note">거래처별 기본 DC율입니다. 판매전표 입력 화면 연동은 다음 단계라서, 지금 저장한 값은 전표 금액에 영향을 주지 않습니다. 단위는 %이고 소수 둘째 자리까지 입력할 수 있습니다 (예: 7.5, 12.25).</p>

<?php foreach ($errors as $e): ?>
  <div class="dcp-err"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if ($preview !== null): ?>
  <div class="dcp-card">
    <h2>엑셀 검증 결과 (아직 저장되지 않았습니다)</h2>
    <?php if ($preview['err_total'] > 0): ?>
      <div class="dcp-err">
        오류 <?= (int)$preview['err_total'] ?>건 — 하나라도 있으면 전부 적용하지 않습니다. 파일을 고쳐 다시 올리세요.
        <ul>
          <?php foreach ($preview['errors'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?>
        </ul>
        <?php if ($preview['err_total'] > count($preview['errors'])): ?>
          <div>… 외 <?= (int)($preview['err_total'] - count($preview['errors'])) ?>건 (앞 50건만 표시)</div>
        <?php endif; ?>
      </div>
    <?php elseif (!$preview['changes']): ?>
      <p>변경할 내용이 없습니다. (같은 값 <?= (int)$preview['same'] ?>건, 빈칸 <?= (int)$preview['blank'] ?>건)</p>
    <?php else: ?>
      <p>변경 <strong><?= count($preview['changes']) ?></strong>건 / 같은 값 <?= (int)$preview['same'] ?>건 / 빈칸(변경 안 함) <?= (int)$preview['blank'] ?>건. 아래 <strong>상호와 변경 전후 값</strong>을 눈으로 확인한 뒤 적용하세요.</p>
      <table class="dcp-tbl">
        <tr><th>코드</th><th>상호</th><th class="r">현재</th><th class="r">변경 후</th></tr>
        <?php foreach (array_slice($preview['rows'], 0, 300) as $r): ?>
          <tr><td><?= h($r[0]) ?></td><td><?= h($r[1]) ?></td>
              <td class="r"><?= h(dc_fmt($r[2])) ?>%</td><td class="r"><strong><?= h(dc_fmt($r[3])) ?>%</strong></td></tr>
        <?php endforeach; ?>
      </table>
      <?php if (count($preview['rows']) > 300): ?>
        <p class="dcp-note">표에는 앞 300건만 보입니다. 적용은 전체 <?= count($preview['rows']) ?>건에 됩니다.</p>
      <?php endif; ?>
      <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('<?= count($preview['changes']) ?>건의 DC율을 변경합니다. 계속할까요?');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="xl_apply">
        <button class="btn" type="submit">이대로 <?= count($preview['changes']) ?>건 적용</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="get" action="partner_dc.php" class="dcp-bar">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="상호 / 코드 / 대표자 / 전화" maxlength="50">
  <label><input type="checkbox" name="dc" value="1" <?= $onlyDc ? 'checked' : '' ?>> DC율 있는 곳만</label>
  <label><input type="checkbox" name="off" value="1" <?= $inclOff ? 'checked' : '' ?>> 사용 안 함 포함</label>
  <button class="btn btn-sm" type="submit">조회</button>
</form>
<p class="dcp-note">총 <?= $total ?>건<?= $total > DCP_LIST_MAX ? ' 중 앞 ' . DCP_LIST_MAX . '건만 표시합니다. 검색으로 좁히거나 엑셀을 쓰세요.' : '' ?></p>

<form id="bulkForm" method="post" action="<?= h($self) ?>" class="dcp-bar">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="bulk_set">
  <strong>선택 일괄 적용</strong>
  <span>체크 <span id="dcCnt">0</span>건에</span>
  <input type="text" id="bulkRate" name="bulk_rate" class="dcp-rate" inputmode="decimal" maxlength="7" placeholder="예: 7.5"> %
  <button class="btn btn-sm" type="submit" onclick="return dcpBulk();">적용</button>
</form>

<form id="rowsForm" method="post" action="<?= h($self) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="save_rows">
  <div class="dcp-bar"><button class="btn btn-sm" type="submit">표에서 수정한 값 저장</button>
    <span class="dcp-note" style="margin:0">값을 바꾼 칸은 노란색으로 표시되고, 저장하면 바뀐 줄만 반영됩니다.</span></div>
  <table class="dcp-tbl">
    <tr>
      <th style="width:36px"><input type="checkbox" id="dcAll" title="전체 선택"></th>
      <th>코드</th><th>상호</th><th>대표자</th><th class="r">DC율(%)</th>
    </tr>
    <?php foreach ($list as $r):
        $id = (int)$r['id'];
        $orig = dc_fmt((int)$r['dc_rate']);
        $val = $posted[$id] ?? $orig;
        $cls = 'dcp-rate' . (isset($badSet[$id]) ? ' bad' : '');
    ?>
      <tr class="<?= (int)$r['is_active'] === 1 ? '' : 'off' ?>">
        <td><input type="checkbox" class="dcp-ck" form="bulkForm" name="ids[]" value="<?= $id ?>"></td>
        <td><?= h((string)($r['partner_code'] ?? '')) ?></td>
        <td><?= h((string)$r['name']) ?><?= (int)$r['is_active'] === 1 ? '' : ' (사용 안 함)' ?></td>
        <td><?= h((string)$r['ceo_name']) ?></td>
        <td class="r"><input type="text" class="<?= h($cls) ?>" name="rate[<?= $id ?>]" value="<?= h($val) ?>"
              data-orig="<?= h($orig) ?>" inputmode="decimal" maxlength="7"></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="5">거래처가 없습니다.</td></tr><?php endif; ?>
  </table>
  <div class="dcp-bar"><button class="btn btn-sm" type="submit">표에서 수정한 값 저장</button></div>
</form>

<div class="dcp-card">
  <h2>Excel 일괄 변경</h2>
  <p class="dcp-note">① 아래 버튼으로 현재 DC율 파일을 받습니다 (조회 조건이 적용됩니다). ② 엑셀에서 <strong>DC율(%) 열만</strong> 고칩니다. ③ 올리면 먼저 <strong>검증만</strong> 하고 변경 전후를 보여 줍니다. ④ 확인 후 적용합니다. 거래처코드로만 찾으며 상호 열은 무시합니다. DC율 칸이 빈칸이면 변경하지 않고, 0 을 쓰면 0%로 바뀝니다. 한 줄이라도 오류면 전부 적용되지 않습니다.</p>
  <div class="dcp-bar">
    <a class="btn btn-sm" href="<?= h('partner_dc.php?' . http_build_query(array_merge($qsArr, ['download' => '1']))) ?>">현재 DC율 다운로드 (.xlsx)</a>
  </div>
  <form method="post" action="<?= h($self) ?>" enctype="multipart/form-data" class="dcp-bar">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="xl_preview">
    <input type="file" name="xlsx" accept=".xlsx" required>
    <button class="btn btn-sm" type="submit">업로드 후 검증만 하기</button>
  </form>
</div>

<script>
(function () {
  var all = document.getElementById('dcAll');
  var boxes = document.querySelectorAll('.dcp-ck');
  var cnt = document.getElementById('dcCnt');
  function upd() {
    var n = 0;
    boxes.forEach(function (b) { if (b.checked) { n++; } });
    cnt.textContent = n;
  }
  all.addEventListener('change', function () {
    boxes.forEach(function (b) { b.checked = all.checked; });
    upd();
  });
  boxes.forEach(function (b) { b.addEventListener('change', upd); });
  document.querySelectorAll('.dcp-rate[data-orig]').forEach(function (i) {
    i.addEventListener('input', function () {
      i.classList.remove('bad');
      i.classList.toggle('chg', i.value.trim() !== i.dataset.orig);
    });
  });
  window.dcpBulk = function () {
    var n = parseInt(cnt.textContent, 10) || 0;
    var r = document.getElementById('bulkRate').value.trim();
    if (!n) { alert('체크한 거래처가 없습니다.'); return false; }
    if (r === '') { alert('적용할 DC율을 입력하세요.'); return false; }
    return confirm(n + '건에 DC율 ' + r + '% 를 적용합니다. 계속할까요?');
  };
})();
</script>
<?php page_footer();
