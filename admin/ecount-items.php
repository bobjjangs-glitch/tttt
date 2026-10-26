<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');

/* ───────── 이카운트 품목 마스터: 엑셀 일괄 등록/변경 + 이카운트 API 전송 ─────────
   - 엑셀 → 이 사이트 품목 리스트(tt_ecount_item_master) : 품목코드 기준 신규/변경
   - 이 사이트 → 이카운트 : SaveBasicProduct (최고관리자만, 소량 테스트 → 확인 → 전체)
*/

$isSuper = AdminAuth::isSuper();
const ECI_SELF = '/admin/ecount-items.php';

function eci_fields(): array {
    return ['item_name','spec_type','spec','remark','in_price','in_vat','out_price','out_vat',
            'prev_price','prev_vat','prev2_price','prev2_vat','disabled'];
}
function eci_labels(): array {
    return ['item_name'=>'품목명','spec_type'=>'규격구분','spec'=>'규격','remark'=>'적요',
            'in_price'=>'입고단가','in_vat'=>'입고단가 VAT','out_price'=>'출고단가','out_vat'=>'출고단가 VAT',
            'prev_price'=>'인상 전가','prev_vat'=>'인상 전가 VAT','prev2_price'=>'인상 전전가',
            'prev2_vat'=>'인상 전전가 VAT','disabled'=>'사용중단'];
}
function eci_defaults(): array {
    return ['item_name'=>'','spec_type'=>'1','spec'=>'','remark'=>'',
            'in_price'=>'0.00','in_vat'=>'0','out_price'=>'0.00','out_vat'=>'0',
            'prev_price'=>'0.00','prev_vat'=>'N','prev2_price'=>'0.00','prev2_vat'=>'N','disabled'=>'N'];
}
function eci_header_names(): array {
    return ['품목코드','품목명','규격구분','규격','적요','입고단가','입고단가 VAT포함여부',
            '출고단가','출고단가 VAT포함여부','인상 전가','인상 전가 VAT포함여부',
            '인상 전전가','인상 전전가 VAT포함여부','사용중단'];
}

/* ───────── 테이블 ───────── */
function eci_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_item_master (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        item_cd VARCHAR(50) NOT NULL,
        item_name VARCHAR(200) NOT NULL DEFAULT '',
        spec_type VARCHAR(10) NOT NULL DEFAULT '1',
        spec VARCHAR(200) NOT NULL DEFAULT '',
        remark VARCHAR(255) NOT NULL DEFAULT '',
        in_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        in_vat VARCHAR(10) NOT NULL DEFAULT '0',
        out_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        out_vat VARCHAR(10) NOT NULL DEFAULT '0',
        prev_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        prev_vat VARCHAR(10) NOT NULL DEFAULT 'N',
        prev2_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        prev2_vat VARCHAR(10) NOT NULL DEFAULT 'N',
        disabled CHAR(1) NOT NULL DEFAULT 'N',
        sync_status VARCHAR(10) NOT NULL DEFAULT 'pending',
        sync_error VARCHAR(500) NULL,
        synced_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_item_cd (item_cd),
        KEY idx_item_name (item_name),
        KEY idx_disabled (disabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* 이전 버전으로 이미 만들어진 테이블 보강 */
    $cols = $pdo->query('SHOW COLUMNS FROM tt_ecount_item_master')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sync_status', $cols, true)) {
        $pdo->exec("ALTER TABLE tt_ecount_item_master ADD COLUMN sync_status VARCHAR(10) NOT NULL DEFAULT 'pending'");
    }
    if (!in_array('sync_error', $cols, true)) {
        $pdo->exec('ALTER TABLE tt_ecount_item_master ADD COLUMN sync_error VARCHAR(500) NULL');
    }
    if (!in_array('synced_at', $cols, true)) {
        $pdo->exec('ALTER TABLE tt_ecount_item_master ADD COLUMN synced_at DATETIME NULL');
    }
    $idx = $pdo->query("SHOW INDEX FROM tt_ecount_item_master WHERE Key_name = 'idx_sync_status'")->fetchAll();
    if (!$idx) {
        $pdo->exec('ALTER TABLE tt_ecount_item_master ADD INDEX idx_sync_status (sync_status)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_ecount_item_state (
        skey VARCHAR(40) NOT NULL PRIMARY KEY,
        sval VARCHAR(100) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function eci_state_get(PDO $pdo, string $key, string $def = ''): string {
    $st = $pdo->prepare('SELECT sval FROM tt_ecount_item_state WHERE skey = :k');
    $st->execute(['k' => $key]);
    $v = $st->fetchColumn();
    return $v === false ? $def : (string)$v;
}
function eci_state_set(PDO $pdo, string $key, string $val): void {
    $pdo->prepare('INSERT INTO tt_ecount_item_state (skey, sval) VALUES (:k, :v1) ON DUPLICATE KEY UPDATE sval = :v2')
        ->execute(['k' => $key, 'v1' => $val, 'v2' => $val]);
}
/* 인상 전가/전전가가 이카운트 단가A~J 중 몇 번인지 (0 = 전송 안 함) */
function eci_price_map(PDO $pdo): array {
    $m = [];
    foreach (['prev', 'prev2'] as $k) {
        $n = (int)eci_state_get($pdo, 'pm_' . $k, '0');
        $m[$k] = ($n >= 1 && $n <= 10) ? $n : 0;
    }
    return $m;
}
/* EcountClient 와 동일한 규칙으로 도메인 판정 (화면 배너가 실제 호출 대상과 어긋나지 않게) */
function eci_domain_base(): string {
    $d = strtolower(trim((string)get_setting('ecount_domain', 'sboapi')));
    $d = preg_replace('/[^a-z]/', '', $d) ?: 'sboapi';
    return in_array($d, ['sboapi', 'oapi'], true) ? $d : 'sboapi';
}

/* ───────── xlsx 읽기 (ZipArchive + DOM) : 모든 시트 반환 ───────── */
function eci_col_index(string $ref): int {
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) return 0;
    $s = strtoupper($m[1]); $n = 0;
    for ($i = 0, $l = strlen($s); $i < $l; $i++) { $n = $n * 26 + (ord($s[$i]) - 64); }
    return $n - 1;
}

function eci_read_sheet_xml(string $xml, array $shared): array {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sd = new DOMDocument();
    if (!@$sd->loadXML($xml)) return [];
    $sx = new DOMXPath($sd);
    $sx->registerNamespace('m', $ns);

    $rows = []; $seq = 0;
    foreach ($sx->query('//m:sheetData/m:row') as $rowNode) {
        $seq++;
        $line = [];
        foreach ($sx->query('m:c', $rowNode) as $c) {
            $ref = $c->getAttribute('r');
            $idx = $ref !== '' ? eci_col_index($ref) : count($line);
            $t = $c->getAttribute('t');
            $val = '';
            if ($t === 'inlineStr') {
                foreach ($sx->query('.//m:t', $c) as $tn) { $val .= $tn->textContent; }
            } else {
                $vn = $sx->query('m:v', $c)->item(0);
                $raw = $vn ? $vn->textContent : '';
                $val = ($t === 's') ? ($shared[(int)$raw] ?? '') : $raw;
            }
            $line[$idx] = $val;
        }
        if ($line) {
            $rn = (int)$rowNode->getAttribute('r');
            $rows[$rn > 0 ? $rn : $seq] = $line;
        }
    }
    return $rows;
}

/** @return array<int, array{name:string, rows:array}> */
function eci_read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('서버에 ZipArchive 확장이 없어 엑셀을 읽을 수 없습니다.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('엑셀 파일을 열 수 없습니다. .xlsx 형식인지 확인해 주세요. (.xls 는 xlsx 로 다시 저장해야 합니다)');
    }
    try {
        $ns  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $nsR = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $d = new DOMDocument();
            if (@$d->loadXML($ssXml)) {
                $x = new DOMXPath($d); $x->registerNamespace('m', $ns);
                foreach ($x->query('//m:si') as $si) {
                    $t = '';
                    foreach ($x->query('.//m:t[not(ancestor::m:rPh)]', $si) as $tn) { $t .= $tn->textContent; }
                    $shared[] = $t;
                }
            }
        }

        $sheets = [];
        $wbXml  = $zip->getFromName('xl/workbook.xml');
        $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbXml !== false && $relXml !== false) {
            $wd = new DOMDocument(); $rd = new DOMDocument();
            if (@$wd->loadXML($wbXml) && @$rd->loadXML($relXml)) {
                $targets = [];
                foreach ($rd->getElementsByTagName('Relationship') as $rel) {
                    $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
                }
                $wx = new DOMXPath($wd); $wx->registerNamespace('m', $ns);
                foreach ($wx->query('//m:sheets/m:sheet') as $sn) {
                    $tg = $targets[$sn->getAttributeNS($nsR, 'id')] ?? '';
                    if ($tg === '') continue;
                    $tg = ltrim($tg, '/');
                    $sheets[] = [
                        'name' => $sn->getAttribute('name'),
                        'path' => (strpos($tg, 'xl/') === 0) ? $tg : 'xl/' . $tg,
                    ];
                }
            }
        }
        if (!$sheets) $sheets[] = ['name' => 'sheet1', 'path' => 'xl/worksheets/sheet1.xml'];

        $out = [];
        foreach ($sheets as $sh) {
            $xml = $zip->getFromName($sh['path']);
            if ($xml === false) continue;
            $out[] = ['name' => $sh['name'], 'rows' => eci_read_sheet_xml($xml, $shared)];
        }
        if (!$out) throw new RuntimeException('시트 데이터를 찾을 수 없습니다.');
        return $out;
    } finally {
        $zip->close();
    }
}

function eci_norm_header(string $s): string {
    $s = preg_replace('/[\s\x{00A0}\x{FEFF}*]+/u', '', $s) ?? $s;
    return preg_replace('/[\(\[（]필수[\)\]）]/u', '', $s) ?? $s;
}
function eci_clean_code(string $s): string {
    $s = trim(preg_replace('/[\x{00A0}\x{FEFF}]+/u', '', $s) ?? $s);
    if (preg_match('/^\d+\.0+$/', $s)) $s = preg_replace('/\.0+$/', '', $s) ?? $s;
    return $s;
}

/* 실패 시 화면에 보여줄 진단: 각 시트의 행 수와 첫 2행 내용 */
function eci_diag(array $sheets): string {
    $out = [];
    foreach ($sheets as $s) {
        $first = []; $k = 0;
        foreach ($s['rows'] as $rn => $line) {
            if (++$k > 2) break;
            ksort($line);
            $cells = array_slice(array_map(static fn($v) => mb_substr(trim((string)$v), 0, 12), $line), 0, 6);
            $first[] = $rn . '행[' . implode(' | ', $cells) . ']';
        }
        $out[] = '시트「' . $s['name'] . '」 ' . count($s['rows']) . '행 → ' . ($first ? implode(' ', $first) : '(비어 있음)');
    }
    return implode(' ‖ ', $out);
}

/* ── 행 → 검증된 품목 배열 ── */
function eci_parse_items(array $rows): array {
    $map = [
        '품목코드'=>'item_cd','품목명'=>'item_name','규격구분'=>'spec_type','규격'=>'spec','적요'=>'remark',
        '입고단가'=>'in_price','입고단가VAT포함여부'=>'in_vat','출고단가'=>'out_price','출고단가VAT포함여부'=>'out_vat',
        '인상전가'=>'prev_price','인상전가VAT포함여부'=>'prev_vat','인상전전가'=>'prev2_price',
        '인상전전가VAT포함여부'=>'prev2_vat','사용중단'=>'disabled',
    ];
    $hdrRow = null; $cols = []; $n = 0;
    foreach ($rows as $rn => $line) {
        if (++$n > 30) break;
        $tmp = [];
        foreach ($line as $ci => $v) {
            $k = eci_norm_header((string)$v);
            if (isset($map[$k])) $tmp[$ci] = $map[$k];
        }
        if (in_array('item_cd', $tmp, true)) { $hdrRow = $rn; $cols = $tmp; break; }
    }
    if ($hdrRow === null) {
        throw new RuntimeException("'품목코드' 헤더 행을 찾지 못했습니다");
    }
    if (!in_array('item_name', $cols, true)) {
        throw new RuntimeException("'품목명' 열이 없습니다 (인식된 열: " . implode(',', array_values($cols)) . ')');
    }

    $items = []; $errors = [];
    foreach ($rows as $rn => $line) {
        if ($rn <= $hdrRow) continue;
        $rec = [];
        foreach ($cols as $ci => $f) { $rec[$f] = isset($line[$ci]) ? trim((string)$line[$ci]) : ''; }
        if (implode('', $rec) === '') continue;

        $cd = eci_clean_code($rec['item_cd'] ?? '');
        if ($cd === '') { $errors[] = "{$rn}행: 품목코드가 비어 있어 건너뜀"; continue; }
        if (mb_strlen($cd) > 50) { $errors[] = "{$rn}행: 품목코드가 50자를 넘어 건너뜀 ({$cd})"; continue; }
        $rec['item_cd'] = $cd;

        if (($rec['item_name'] ?? '') === '') { $errors[] = "{$rn}행: 품목명이 비어 있어 건너뜀 ({$cd})"; continue; }
        $rec['item_name'] = mb_substr($rec['item_name'], 0, 200);
        if (isset($rec['spec']))   $rec['spec']   = mb_substr($rec['spec'], 0, 200);
        if (isset($rec['remark'])) $rec['remark'] = mb_substr($rec['remark'], 0, 255);
        if (isset($rec['spec_type'])) $rec['spec_type'] = $rec['spec_type'] === '' ? '1' : mb_substr($rec['spec_type'], 0, 10);
        foreach (['in_vat','out_vat','prev_vat','prev2_vat'] as $vf) {
            if (isset($rec[$vf])) $rec[$vf] = mb_substr($rec[$vf], 0, 10);
        }
        foreach (['in_price','out_price','prev_price','prev2_price'] as $pf) {
            if (!isset($rec[$pf])) continue;
            $raw = str_replace(',', '', $rec[$pf]);
            if ($raw === '') $raw = '0';
            if (!is_numeric($raw) || (float)$raw < 0) {
                $errors[] = "{$rn}행: " . eci_labels()[$pf] . " 값이 올바르지 않아 건너뜀 ({$cd}: {$rec[$pf]})";
                continue 2;
            }
            $rec[$pf] = number_format((float)$raw, 2, '.', '');
        }
        if (isset($rec['disabled'])) {
            $rec['disabled'] = in_array(strtoupper($rec['disabled']), ['Y','1','TRUE'], true) ? 'Y' : 'N';
        }

        $key = mb_strtoupper($cd);
        if (isset($items[$key])) $errors[] = "{$rn}행: 파일 안에서 품목코드 중복 ({$cd}) — 뒤 행 값으로 덮어씀";
        $items[$key] = $rec;
    }
    return ['items' => $items, 'errors' => $errors, 'present' => array_values($cols), 'hdr' => $hdrRow, 'rows' => count($rows)];
}

function eci_fmt(string $f, string $v): string {
    if (substr($f, -6) === '_price') return number_format((float)$v);
    $v = trim($v);
    if (mb_strlen($v) > 40) $v = mb_substr($v, 0, 40) . '…';
    return $v === '' ? '(빈값)' : $v;
}

/* ── 신규/변경 판정 + 사이트 DB 반영 (dryRun=true 면 DB 변경 없음) ── */
function eci_apply(PDO $pdo, array $parsed, bool $dryRun): array {
    $fields  = eci_fields();
    $labels  = eci_labels();
    $present = array_values(array_intersect($fields, $parsed['present']));

    $existing = [];
    foreach ($pdo->query('SELECT * FROM tt_ecount_item_master')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[mb_strtoupper((string)$r['item_cd'])] = $r;
    }

    $res = ['new'=>0,'changed'=>0,'same'=>0,'log'=>[],'errors'=>$parsed['errors']];
    $toInsert = []; $toUpdate = [];

    foreach ($parsed['items'] as $key => $rec) {
        if (!isset($existing[$key])) {
            $toInsert[] = $rec; $res['new']++;
            $res['log'][] = '[신규] ' . $rec['item_cd'] . ' | ' . $rec['item_name']
                . (isset($rec['out_price']) ? ' | 출고단가 ' . number_format((float)$rec['out_price']) : '');
            continue;
        }
        $old = $existing[$key]; $diff = [];
        foreach ($present as $f) {
            $new = (string)($rec[$f] ?? '');
            $cur = (string)($old[$f] ?? '');
            if ($new !== $cur) $diff[$f] = [$cur, $new];
        }
        if (!$diff) { $res['same']++; continue; }
        $toUpdate[] = ['id' => (int)$old['id'], 'diff' => $diff];
        $res['changed']++;
        $parts = [];
        foreach ($diff as $f => $pair) {
            $parts[] = $labels[$f] . ': ' . eci_fmt($f, $pair[0]) . ' → ' . eci_fmt($f, $pair[1]);
        }
        $res['log'][] = '[변경] ' . $rec['item_cd'] . ' | ' . (string)$old['item_name'] . ' | ' . implode(', ', $parts);
    }

    if (!$dryRun && ($toInsert || $toUpdate)) {
        $cols = array_merge(['item_cd'], $fields);
        $ins = $pdo->prepare('INSERT INTO tt_ecount_item_master (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')');
        $pdo->beginTransaction();
        try {
            foreach ($toInsert as $rec) {
                $rec = array_merge(eci_defaults(), $rec);
                $p = ['item_cd' => $rec['item_cd']];
                foreach ($fields as $f) { $p[$f] = $rec[$f]; }
                $ins->execute($p);
            }
            foreach ($toUpdate as $u) {
                $sets = []; $p = ['id' => $u['id']];
                foreach ($u['diff'] as $f => $pair) { $sets[] = "$f = :$f"; $p[$f] = $pair[1]; }
                /* 사용중단만 바뀐 경우는 API 전송 대상이 아니므로 대기 처리하지 않음 */
                if (array_diff(array_keys($u['diff']), ['disabled'])) {
                    $sets[] = "sync_status = 'pending'";
                    $sets[] = 'sync_error = NULL';
                }
                $pdo->prepare('UPDATE tt_ecount_item_master SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($p);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    return $res;
}

/* ───────── 이카운트 API 전송용 변환 ───────── */
function eci_num(string $v): string {
    $s = rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}
function eci_vat01(string $v): string { return in_array(strtoupper(trim($v)), ['1', 'Y'], true) ? '1' : '0'; }
function eci_vatyn(string $v): string { return in_array(strtoupper(trim($v)), ['1', 'Y'], true) ? 'Y' : 'N'; }

/** @return array{0:?array,1:string} [payload|null, 오류메시지] */
function eci_api_item(array $r, array $pm): array {
    $cd = (string)$r['item_cd'];
    if (mb_strlen($cd) > 20) return [null, '품목코드가 20자를 넘어 이카운트에 등록할 수 없습니다.'];
    $sf = (string)$r['spec_type'];
    if (!in_array($sf, ['1','2','3','4'], true)) $sf = '1';

    $d = [
        'PROD_CD'       => $cd,
        'PROD_DES'      => mb_substr((string)$r['item_name'], 0, 100),
        'SIZE_FLAG'     => $sf,
        'SIZE_DES'      => mb_substr((string)$r['spec'], 0, 100),
        'REMARKS'       => mb_substr((string)$r['remark'], 0, 100),
        'IN_PRICE'      => eci_num((string)$r['in_price']),
        'IN_PRICE_VAT'  => eci_vat01((string)$r['in_vat']),
        'OUT_PRICE'     => eci_num((string)$r['out_price']),
        'OUT_PRICE_VAT' => eci_vat01((string)$r['out_vat']),
    ];
    foreach (['prev', 'prev2'] as $k) {
        $n = (int)($pm[$k] ?? 0);
        if ($n >= 1 && $n <= 10) {
            $d["OUT_PRICE{$n}"]        = eci_num((string)$r[$k . '_price']);
            $d["OUT_PRICE{$n}_VAT_YN"] = eci_vatyn((string)$r[$k . '_vat']);
        }
    }
    return [$d, ''];
}

/** SaveBasicProduct 응답 해석. 처리 자체가 안 된 경우 ['fatal'=>메시지] */
function eci_parse_save_response(array $res, int $n): array {
    $data = $res['Data'] ?? null;
    if ((string)($res['Status'] ?? '') !== '200' || !empty($res['Error']) || !is_array($data)) {
        $msg = '';
        if (is_array($res['Error'] ?? null)) {
            $msg = trim((string)($res['Error']['Message'] ?? '') . ' ' . (string)($res['Error']['MessageDetail'] ?? ''));
        }
        return ['fatal' => $msg !== '' ? $msg : '응답 오류: ' . mb_substr((string)json_encode($res, JSON_UNESCAPED_UNICODE), 0, 300)];
    }
    $succ = (int)($data['SuccessCnt'] ?? 0);
    $fail = (int)($data['FailCnt'] ?? 0);
    $rawDetails = $data['ResultDetails'] ?? '[]';
    $details = is_array($rawDetails) ? $rawDetails : json_decode((string)$rawDetails, true);
    if (!is_array($details)) $details = [];

    $errOf = static function (array $d): string {
        $parts = [];
        foreach (($d['Errors'] ?? []) as $e) {
            if (is_array($e)) $parts[] = trim((string)($e['ColCd'] ?? '') . ': ' . (string)($e['Message'] ?? ''));
        }
        return $parts ? implode(' / ', $parts) : (string)($d['TotalError'] ?? '실패');
    };

    $per = [];
    if (count($details) === $n) {
        foreach ($details as $d) {
            $ok = is_array($d) && !empty($d['IsSuccess']);
            $per[] = [$ok, $ok ? '' : (is_array($d) ? $errOf($d) : '실패')];
        }
    } elseif ($fail === 0) {
        for ($i = 0; $i < $n; $i++) $per[] = [true, ''];
    } else {
        $first = (isset($details[0]) && is_array($details[0])) ? $errOf($details[0]) : '';
        $msg = ($succ === 0)
            ? ($first !== '' ? $first : '전체 실패')
            : '일부 실패 — 건별 구분 불가, 이카운트에서 확인 필요' . ($first !== '' ? ' (' . $first . ')' : '');
        for ($i = 0; $i < $n; $i++) $per[] = [false, $msg];
    }
    return ['per' => $per, 'succ' => $succ, 'fail' => $fail,
            'info' => (string)($data['QUANTITY_INFO'] ?? ''), 'trace' => (string)($data['TRACE_ID'] ?? '')];
}

/* ───────── 요청 처리 ───────── */
$pdo = Database::connection();
try { ensure_ecount_tables($pdo); } catch (Throwable $e) { error_log('[ecount-items] ensure_ecount_tables: ' . $e->getMessage()); }
eci_ensure_table($pdo);

$uploadResult = null;
$pageError = '';

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect(ECI_SELF);
    }
    $action = (string)($_POST['action'] ?? '');
    $superOnly = ['push', 'requeue', 'verify', 'save_pm'];
    if (in_array($action, $superOnly, true) && !$isSuper) {
        flash('admin_error', '이카운트 전송 관련 기능은 최고관리자만 사용할 수 있습니다.');
        redirect(ECI_SELF);
    }

    if ($action === 'upload') {
        $mode = (($_POST['mode'] ?? '') === 'apply') ? 'apply' : 'preview';
        try {
            $f = $_FILES['xlsx'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('파일 업로드에 실패했습니다. (서버 업로드 용량 제한일 수 있습니다)');
            }
            if (!is_uploaded_file((string)$f['tmp_name'])) throw new RuntimeException('잘못된 업로드입니다.');
            if ((int)$f['size'] > 10 * 1024 * 1024) throw new RuntimeException('파일 크기는 10MB 이하여야 합니다.');
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') throw new RuntimeException('.xlsx 파일만 지원합니다. (.xls 는 엑셀에서 xlsx 로 다시 저장해 주세요)');

            $sheets = eci_read_xlsx((string)$f['tmp_name']);
            $parsed = null; $notes = [];
            foreach ($sheets as $s) {
                try {
                    $pr = eci_parse_items($s['rows']);
                } catch (RuntimeException $e) {
                    $notes[] = '시트「' . $s['name'] . '」: ' . $e->getMessage();
                    continue;
                }
                if ($pr['items']) { $parsed = $pr; $parsed['sheet'] = $s['name']; break; }
                $notes[] = '시트「' . $s['name'] . '」: 헤더는 행 ' . $pr['hdr'] . '에서 찾았지만 유효한 품목이 0건 (인식된 열: ' . implode(',', $pr['present']) . ')'
                         . ($pr['errors'] ? ' — ' . implode(' | ', array_slice($pr['errors'], 0, 3)) : ' (헤더 아래 데이터 행 없음)');
            }
            if ($parsed === null) {
                throw new RuntimeException('등록할 품목 행을 찾지 못했습니다. ' . implode(' / ', $notes) . ' ▶ 읽힌 내용: ' . eci_diag($sheets));
            }

            $uploadResult = eci_apply($pdo, $parsed, $mode !== 'apply');
            $uploadResult['mode']  = $mode;
            $uploadResult['file']  = (string)$f['name'];
            $uploadResult['sheet'] = $parsed['sheet'];
            $uploadResult['total'] = count($parsed['items']);
        } catch (RuntimeException $e) {
            $pageError = $e->getMessage();
        } catch (Throwable $e) {
            sg_maybe_invalidate($e);
            error_log('[ecount-items:upload] ' . $e->getMessage());
            $pageError = '처리 중 오류가 발생했습니다. 로그를 확인해 주세요.';
        }

    } elseif ($action === 'save_one') {
        try {
            $vals = [
                trim((string)($_POST['item_cd'] ?? '')),
                (string)($_POST['item_name'] ?? ''), (string)($_POST['spec_type'] ?? '1'),
                (string)($_POST['spec'] ?? ''), (string)($_POST['remark'] ?? ''),
                (string)($_POST['in_price'] ?? '0'), (string)($_POST['in_vat'] ?? '0'),
                (string)($_POST['out_price'] ?? '0'), (string)($_POST['out_vat'] ?? '0'),
                (string)($_POST['prev_price'] ?? '0'), (string)($_POST['prev_vat'] ?? 'N'),
                (string)($_POST['prev2_price'] ?? '0'), (string)($_POST['prev2_vat'] ?? 'N'),
                isset($_POST['disabled']) ? 'Y' : 'N',
            ];
            $parsed = eci_parse_items([1 => eci_header_names(), 2 => $vals]);
            if ($parsed['errors'] || !$parsed['items']) {
                flash('admin_error', implode(' / ', $parsed['errors']) ?: '입력값을 확인해 주세요.');
            } else {
                $r = eci_apply($pdo, $parsed, false);
                flash('admin_success', $r['new'] ? '품목을 등록했습니다.' : ($r['changed'] ? '품목을 수정했습니다.' : '변경된 내용이 없습니다.'));
            }
        } catch (Throwable $e) {
            sg_maybe_invalidate($e);
            error_log('[ecount-items:save_one] ' . $e->getMessage());
            flash('admin_error', '저장 중 오류가 발생했습니다.');
        }
        redirect(ECI_SELF);

    } elseif ($action === 'delete') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('DELETE FROM tt_ecount_item_master WHERE id = :id')->execute(['id' => $id]);
                flash('admin_success', '이 사이트의 품목 리스트에서만 삭제했습니다. (이카운트에 이미 등록된 품목은 그대로 남습니다)');
            }
        } catch (Throwable $e) {
            sg_maybe_invalidate($e);
            error_log('[ecount-items:delete] ' . $e->getMessage());
            flash('admin_error', '삭제 중 오류가 발생했습니다.');
        }
        redirect(ECI_SELF);

    } elseif ($action === 'save_pm') {
        $a = max(0, min(10, (int)($_POST['pm_prev'] ?? 0)));
        $b = max(0, min(10, (int)($_POST['pm_prev2'] ?? 0)));
        if ($a > 0 && $a === $b) {
            flash('admin_error', '인상 전가와 인상 전전가를 같은 단가에 지정할 수 없습니다.');
        } else {
            eci_state_set($pdo, 'pm_prev', (string)$a);
            eci_state_set($pdo, 'pm_prev2', (string)$b);
            flash('admin_success', '단가 매핑을 저장했습니다. 이후 전송부터 적용됩니다. (이미 전송된 품목은 다시 대기 처리해야 반영됩니다)');
        }
        redirect(ECI_SELF);

    } elseif ($action === 'requeue') {
        $pdo->exec("UPDATE tt_ecount_item_master SET sync_status = 'pending' WHERE sync_status = 'failed'");
        flash('admin_success', '실패 건을 전송 대기로 되돌렸습니다.');
        redirect(ECI_SELF);

    } elseif ($action === 'verify') {
        try {
            $vs = $pdo->prepare('SELECT * FROM tt_ecount_item_master WHERE id = :id');
            $vs->execute(['id' => (int)($_POST['id'] ?? 0)]);
            $row = $vs->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('품목을 찾을 수 없습니다.');
            $client = new EcountClient($pdo);
            $r = $client->viewBasicProduct((string)$row['item_cd']);
            $remote = $r['Data']['Result'][0] ?? null;
            $_SESSION['eci_notice'] = [
                'type' => 'verify', 'row' => $row, 'remote' => is_array($remote) ? $remote : null,
                'err' => is_array($r['Error'] ?? null) ? (string)($r['Error']['Message'] ?? '') : '',
                'pm' => eci_price_map($pdo),
            ];
        } catch (Throwable $e) {
            error_log('[ecount-items:verify] ' . $e->getMessage());
            flash('admin_error', '이카운트 조회 실패: ' . $e->getMessage());
        }
        redirect(ECI_SELF);

    } elseif ($action === 'push') {
        $limit = (int)($_POST['limit'] ?? 5);
        if (!in_array($limit, [5, 50, 300], true)) $limit = 5;
        $dry = (($_POST['dry_run'] ?? '') === '1');
        $pm  = eci_price_map($pdo);

        $wait = max(0, 11 - (time() - (int)eci_state_get($pdo, 'last_push_at', '0')));
        if (!$dry && $wait > 0) {
            flash('admin_error', "이카운트 저장 API는 10초에 1회만 호출할 수 있습니다. {$wait}초 뒤에 다시 눌러 주세요.");
            redirect(ECI_SELF);
        }

        $stmt = $pdo->prepare("SELECT * FROM tt_ecount_item_master WHERE sync_status = 'pending' ORDER BY id ASC LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            flash('admin_success', '전송 대기 중인 품목이 없습니다.');
            redirect(ECI_SELF);
        }

        $payloads = []; $sendRows = []; $localFail = [];
        foreach ($rows as $r) {
            [$d, $err] = eci_api_item($r, $pm);
            if ($d === null) { $localFail[(int)$r['id']] = $err; continue; }
            $payloads[] = $d; $sendRows[] = $r;
        }

        if ($dry) {
            $_SESSION['eci_notice'] = ['type' => 'dry', 'count' => count($payloads), 'local_fail' => count($localFail),
                                       'sample' => array_slice($payloads, 0, 3), 'pm' => $pm];
            redirect(ECI_SELF);
        }

        if ($localFail) {
            $up = $pdo->prepare("UPDATE tt_ecount_item_master SET sync_status = 'failed', sync_error = :e WHERE id = :id");
            foreach ($localFail as $id => $err) { $up->execute(['e' => $err, 'id' => $id]); }
        }
        if (!$payloads) {
            flash('admin_error', '전송 가능한 품목이 없습니다. (검증 실패 ' . count($localFail) . '건은 목록에서 확인)');
            redirect(ECI_SELF);
        }

        eci_state_set($pdo, 'last_push_at', (string)time());   // 실패해도 10초 간격 규칙은 적용
        try {
            $client = new EcountClient($pdo);
            $res = $client->saveBasicProducts($payloads);
        } catch (Throwable $e) {
            error_log('[ecount-items:push] ' . $e->getMessage());
            flash('admin_error', '이카운트 전송 실패 (이 사이트에서는 대기 상태 유지): ' . $e->getMessage()
                . ' ▶ 통신 오류(타임아웃 등)라면 이카운트가 이미 처리했을 수 있습니다. 재전송 전에 목록의 [이카운트 확인]으로 먼저 대조하세요.');
            redirect(ECI_SELF);
        }

        $pr = eci_parse_save_response($res, count($payloads));
        if (isset($pr['fatal'])) {
            flash('admin_error', '이카운트가 요청을 처리하지 않았습니다 (대기 상태 유지): ' . $pr['fatal']);
            redirect(ECI_SELF);
        }

        $okCnt = 0; $ngCnt = 0; $errList = [];
        $upOk = $pdo->prepare("UPDATE tt_ecount_item_master SET sync_status = 'sent', sync_error = NULL, synced_at = NOW() WHERE id = :id");
        $upNg = $pdo->prepare("UPDATE tt_ecount_item_master SET sync_status = 'failed', sync_error = :e WHERE id = :id");
        foreach ($sendRows as $i => $r) {
            [$ok, $msg] = $pr['per'][$i] ?? [false, '결과 해석 실패'];
            if ($ok) { $upOk->execute(['id' => (int)$r['id']]); $okCnt++; }
            else {
                $upNg->execute(['e' => mb_substr($msg, 0, 500), 'id' => (int)$r['id']]); $ngCnt++;
                if (count($errList) < 20) $errList[] = $r['item_cd'] . ' — ' . $msg;
            }
        }
        try {
            $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('push', :s, :ok, :ng)")
                ->execute(['s' => mb_substr('품목등록 ' . count($payloads) . '건 전송 (TRACE ' . $pr['trace'] . ')', 0, 250), 'ok' => $okCnt, 'ng' => $ngCnt]);
        } catch (Throwable $e) { error_log('[ecount-items:log] ' . $e->getMessage()); }

        $_SESSION['eci_notice'] = [
            'type' => 'push', 'ok' => $okCnt, 'ng' => $ngCnt, 'local_fail' => count($localFail),
            'info' => $pr['info'], 'trace' => $pr['trace'], 'errors' => $errList,
            'remaining' => (int)$pdo->query("SELECT COUNT(*) FROM tt_ecount_item_master WHERE sync_status = 'pending'")->fetchColumn(),
        ];
        redirect(ECI_SELF);
    }
}

/* ───────── 화면용 데이터 ───────── */
$notice = $_SESSION['eci_notice'] ?? null;
unset($_SESSION['eci_notice']);

$q    = trim((string)($_GET['q'] ?? ''));
$st   = (string)($_GET['st'] ?? '');
if (!in_array($st, ['', 'N', 'Y'], true)) $st = '';
$ss   = (string)($_GET['ss'] ?? '');
if (!in_array($ss, ['', 'pending', 'sent', 'failed'], true)) $ss = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$where = '1=1'; $params = [];
if ($q !== '') {
    $where .= ' AND (item_cd LIKE :q1 OR item_name LIKE :q2 OR spec LIKE :q3 OR remark LIKE :q4)';
    foreach (['q1','q2','q3','q4'] as $k) { $params[$k] = '%' . $q . '%'; }
}
if ($st !== '') { $where .= ' AND disabled = :st'; $params['st'] = $st; }
if ($ss !== '') { $where .= ' AND sync_status = :ss'; $params['ss'] = $ss; }

$cnt = $pdo->prepare("SELECT COUNT(*) FROM tt_ecount_item_master WHERE {$where}");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare("SELECT * FROM tt_ecount_item_master WHERE {$where} ORDER BY item_cd ASC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stat = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(disabled = 'Y'), 0) AS d FROM tt_ecount_item_master")->fetch(PDO::FETCH_ASSOC);
$allCount = (int)($stat['c'] ?? 0); $disCount = (int)($stat['d'] ?? 0);

$sc = ['pending' => 0, 'sent' => 0, 'failed' => 0];
foreach ($pdo->query('SELECT sync_status, COUNT(*) AS c FROM tt_ecount_item_master GROUP BY sync_status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($sc[$r['sync_status']])) $sc[$r['sync_status']] = (int)$r['c'];
}

$prodMap = [];
if ($rows) {
    try {
        $codes = array_values(array_unique(array_map(static fn($r) => (string)$r['item_cd'], $rows)));
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $ps = $pdo->prepare("SELECT id, name, ecount_item_cd FROM tt_products WHERE ecount_item_cd IN ($ph)");
        $ps->execute($codes);
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $prd) { $prodMap[mb_strtoupper((string)$prd['ecount_item_cd'])] = $prd; }
    } catch (Throwable $e) { error_log('[ecount-items] product link: ' . $e->getMessage()); }
}

$editRow = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $es = $pdo->prepare('SELECT * FROM tt_ecount_item_master WHERE id = :id');
    $es->execute(['id' => $editId]);
    $editRow = $es->fetch(PDO::FETCH_ASSOC) ?: null;
}
$ev = $editRow ?: array_merge(['item_cd' => ''], eci_defaults());

$domain = eci_domain_base();
$isProd = ($domain === 'oapi');
$pmNow  = eci_price_map($pdo);
$waitNow = max(0, 11 - (time() - (int)eci_state_get($pdo, 'last_push_at', '0')));

$logs = [];
try {
    $logs = $pdo->query("SELECT created_at, summary, success_count, fail_count FROM tt_ecount_sync_log WHERE summary LIKE '품목등록%' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* 로그 표시는 부가 기능 */ }

$pageTitle = '이카운트 품목 등록 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-list-title">📦 이카운트 품목 등록 관리</h2>
  <p class="admin-list-sub">① 엑셀을 올려 이 사이트의 품목 리스트에 반영 → ② 최고관리자가 이카운트로 전송합니다. 두 단계는 분리되어 있어, ①만으로는 이카운트가 바뀌지 않습니다.</p>

  <?php if ($pageError !== ''): ?>
    <div style="padding:12px 16px;background:#fee2e2;color:#b91c1c;border-radius:10px;margin:12px 0;word-break:break-all;"><?= h($pageError) ?></div>
  <?php endif; ?>

  <?php if ($notice): ?>
    <?php if ($notice['type'] === 'dry'): ?>
      <div class="api-svc-card" style="background:#f8fafc;">
        <h3>👀 전송 미리보기 (이카운트로 보내지 않았습니다)</h3>
        <p>전송 예정 <strong><?= (int)$notice['count'] ?></strong>건 / 사전 검증 실패 <strong><?= (int)$notice['local_fail'] ?></strong>건 (실제 전송 시 실패 처리됩니다)</p>
        <p style="font-size:12px;color:#64748b;">아래는 앞 3건의 실제 전송 내용입니다. 인상 전가/전전가 항목(OUT_PRICEn)이 의도한 단가 번호에 들어가는지 확인하세요.</p>
        <pre style="white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:12px;"><?= h((string)json_encode($notice['sample'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
      </div>
    <?php elseif ($notice['type'] === 'push'): ?>
      <div class="api-svc-card" style="background:#f8fafc;">
        <h3>📤 전송 결과</h3>
        <p>성공 <strong style="color:#15803d;"><?= (int)$notice['ok'] ?></strong>건 / 실패 <strong style="color:#b91c1c;"><?= (int)$notice['ng'] ?></strong>건 / 사전 검증 실패 <?= (int)$notice['local_fail'] ?>건 / 남은 대기 <strong><?= (int)$notice['remaining'] ?></strong>건</p>
        <?php if ($notice['info'] !== ''): ?><p style="font-size:12px;color:#64748b;">이카운트 허용량: <?= h($notice['info']) ?> (TRACE <?= h($notice['trace']) ?>)</p><?php endif; ?>
        <?php if ($notice['errors']): ?>
          <pre style="white-space:pre-wrap;background:#fff7ed;color:#9a3412;padding:10px;border-radius:8px;font-size:12px;max-height:220px;overflow:auto;"><?= h(implode("\n", $notice['errors'])) ?></pre>
        <?php endif; ?>
        <p style="font-size:12px;color:#b45309;">⚠ "성공"은 이카운트가 오류 없이 응답했다는 뜻입니다. 값이 실제로 들어갔는지는 목록의 [이카운트 확인] 버튼으로 반드시 대조하세요.</p>
      </div>
    <?php elseif ($notice['type'] === 'verify'): ?>
      <?php
        $row = $notice['row']; $rm = $notice['remote']; $pmv = $notice['pm'];
        $cmp = [
            ['품목명', 'PROD_DES', (string)$row['item_name'], false],
            ['규격', 'SIZE_DES', (string)$row['spec'], false],
            ['적요', 'REMARKS', mb_substr((string)$row['remark'], 0, 100), false],
            ['입고단가', 'IN_PRICE', (string)$row['in_price'], true],
            ['출고단가', 'OUT_PRICE', (string)$row['out_price'], true],
        ];
        foreach (['prev' => '인상 전가', 'prev2' => '인상 전전가'] as $k => $lb) {
            if (($pmv[$k] ?? 0) > 0) $cmp[] = [$lb . ' (OUT_PRICE' . $pmv[$k] . ')', 'OUT_PRICE' . $pmv[$k], (string)$row[$k . '_price'], true];
        }
      ?>
      <div class="api-svc-card" style="background:#f8fafc;">
        <h3>🔎 이카운트 대조: <?= h((string)$row['item_cd']) ?></h3>
        <?php if ($rm === null): ?>
          <p style="color:#b91c1c;">이카운트에서 이 품목코드를 찾지 못했습니다.<?= $notice['err'] !== '' ? ' (' . h($notice['err']) . ')' : '' ?></p>
        <?php else: ?>
          <table class="admin-table" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead><tr><th>항목</th><th>이 사이트</th><th>이카운트</th><th>일치</th></tr></thead>
            <tbody>
            <?php foreach ($cmp as [$label, $key, $local, $isNum]): $remoteVal = (string)($rm[$key] ?? '');
                $same = $isNum ? abs((float)$local - (float)$remoteVal) < 0.005 : trim($local) === trim($remoteVal); ?>
              <tr><td><?= h($label) ?></td><td><?= h($isNum ? number_format((float)$local) : $local) ?></td><td><?= h($isNum ? number_format((float)$remoteVal) : $remoteVal) ?></td>
                  <td><?= $same ? '✅' : '<span style="color:#b91c1c;">❌</span>' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p style="font-size:12px;color:#64748b;">이카운트 값이 비어 있거나 다르면, 그 항목이 이카운트 품목등록 화면의 "기본탭"에 없어서 등록되지 않았을 가능성이 가장 높습니다.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isSuper): ?>
  <div class="api-svc-card" style="border:2px solid <?= $isProd ? '#dc2626' : '#f59e0b' ?>;">
    <h3>이카운트 전송 <span style="font-size:13px;color:<?= $isProd ? '#dc2626' : '#b45309' ?>;">현재 대상: <?= $isProd ? '【운영 서버(oapi)】 실제 품목 마스터에 등록됩니다' : '【테스트 서버(sboapi)】 테스트 회사 데이터입니다' ?></span></h3>
    <p style="font-size:13px;">전송 상태: 대기 <strong><?= $sc['pending'] ?></strong> / 완료 <strong><?= $sc['sent'] ?></strong> / 실패 <strong style="color:#b91c1c;"><?= $sc['failed'] ?></strong></p>

    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="push">
      <label>1회 전송 건수
        <select name="limit">
          <option value="5">5건 (처음엔 반드시 이것으로 테스트)</option>
          <option value="50">50건</option>
          <option value="300">300건</option>
        </select>
      </label>
      <button type="submit" name="dry_run" value="1" class="btn-admin-primary" style="background:#64748b;">① 미리보기 (전송 안 함)</button>
      <button type="submit" id="pushBtn" data-wait="<?= (int)$waitNow ?>" class="btn-admin-primary" style="background:#dc2626;"
              onclick="return confirm('<?= $isProd ? '【운영】' : '【테스트】' ?> 이카운트에 품목을 실제로 등록합니다. 진행할까요?');">② 이카운트로 전송</button>
    </form>
    <form method="post" style="display:inline;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="requeue">
      <button type="submit" class="btn-admin-primary" style="background:#64748b;">실패 건을 다시 대기로</button>
    </form>
    <p style="font-size:12px;color:#64748b;margin-top:8px;">저장 API는 10초에 1회, 시간당 연속 오류 30건이면 차단됩니다. 처음 5건 전송 → 목록에서 [이카운트 확인] 대조 → 이상 없을 때만 건수를 늘리세요. 매번 10초 이상 간격이 필요합니다.</p>

    <hr style="margin:14px 0;">
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save_pm">
      <strong style="font-size:13px;">이카운트 단가 매핑</strong>
      <?php foreach (['pm_prev' => ['인상 전가', 'prev'], 'pm_prev2' => ['인상 전전가', 'prev2']] as $nm => [$lb, $kk]): ?>
        <label><?= h($lb) ?> →
          <select name="<?= $nm ?>">
            <option value="0">전송 안 함</option>
            <?php for ($n = 1; $n <= 10; $n++): ?>
              <option value="<?= $n ?>" <?= $pmNow[$kk] === $n ? 'selected' : '' ?>>단가<?= chr(64 + $n) ?> (OUT_PRICE<?= $n ?>)</option>
            <?php endfor; ?>
          </select>
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn-admin-primary">매핑 저장</button>
    </form>
    <p style="font-size:12px;color:#b45309;">이카운트에는 "인상 전가"라는 기본 필드가 없습니다. 이카운트 품목등록 화면에서 이 이름을 단가 A~J 중 무엇으로 쓰고 계신지 확인한 뒤 지정하세요. 모르면 "전송 안 함"으로 두세요. 잘못 지정하면 다른 단가가 덮어써집니다.</p>
  </div>
  <?php else: ?>
  <div class="api-svc-card"><p style="font-size:13px;color:#64748b;">이카운트로 전송하는 기능은 최고관리자 계정에만 표시됩니다.</p></div>
  <?php endif; ?>

  <div class="api-svc-card">
    <h3>엑셀 일괄 등록 / 변경 (이 사이트 리스트에만 반영)</h3>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="file" name="xlsx" accept=".xlsx" required>
      <button type="submit" name="mode" value="preview" class="btn-admin-primary" style="background:#64748b;">① 미리보기 (DB 반영 안 함)</button>
      <button type="submit" name="mode" value="apply" class="btn-admin-primary"
              onclick="return confirm('미리보기로 확인하셨나요? 신규 등록 및 변경 내용이 이 사이트 DB에 반영되고, 이카운트 전송 대기 상태가 됩니다.');">② 실제 반영</button>
    </form>
    <p style="color:#64748b;font-size:12px;margin-top:8px;">
      헤더 이름으로 열을 찾으므로 열 순서가 달라도 됩니다. 파일에 있는 열만 비교·수정하며, 값이 바뀐 품목은 이카운트 전송 대기로 돌아갑니다.
    </p>
  </div>

  <?php if ($uploadResult !== null): ?>
  <div class="api-svc-card" style="background:#f8fafc;">
    <h3><?= $uploadResult['mode'] === 'apply' ? '✅ 반영 완료' : '👀 미리보기 결과 (아직 DB에 반영되지 않았습니다)' ?> — <?= h($uploadResult['file']) ?> / 시트「<?= h($uploadResult['sheet']) ?>」</h3>
    <p>
      파일 내 유효 품목 <strong><?= (int)$uploadResult['total'] ?></strong>건 →
      신규 <strong style="color:#15803d;"><?= (int)$uploadResult['new'] ?></strong>건 /
      변경 <strong style="color:#b45309;"><?= (int)$uploadResult['changed'] ?></strong>건 /
      동일 <strong><?= (int)$uploadResult['same'] ?></strong>건 /
      제외(오류·중복) <strong style="color:#b91c1c;"><?= count($uploadResult['errors']) ?></strong>건
    </p>
    <?php if ($uploadResult['errors']): ?>
      <pre style="white-space:pre-wrap;background:#fff7ed;color:#9a3412;padding:10px;border-radius:8px;font-size:12px;max-height:220px;overflow:auto;"><?= h(implode("\n", array_slice($uploadResult['errors'], 0, 100))) ?><?= count($uploadResult['errors']) > 100 ? "\n… 외 " . (count($uploadResult['errors']) - 100) . '건' : '' ?></pre>
    <?php endif; ?>
    <?php if ($uploadResult['log']): ?>
      <pre style="white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:12px;max-height:360px;overflow:auto;"><?= h(implode("\n", array_slice($uploadResult['log'], 0, 300))) ?><?= count($uploadResult['log']) > 300 ? "\n… 외 " . (count($uploadResult['log']) - 300) . '건 (화면 생략)' : '' ?></pre>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="api-svc-card">
    <details <?= $editRow ? 'open' : '' ?>>
      <summary style="cursor:pointer;font-weight:700;"><?= $editRow ? '품목 수정: ' . h((string)$editRow['item_cd']) : '＋ 품목 단건 등록' ?></summary>
      <form method="post" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-top:12px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_one">
        <label>품목코드 * (이카운트 전송은 20자 이내)<input type="text" name="item_cd" maxlength="50" required value="<?= h((string)$ev['item_cd']) ?>" <?= $editRow ? 'readonly' : '' ?>></label>
        <label>품목명 *<input type="text" name="item_name" maxlength="200" required value="<?= h((string)$ev['item_name']) ?>"></label>
        <label>규격구분<input type="text" name="spec_type" maxlength="10" value="<?= h((string)$ev['spec_type']) ?>"></label>
        <label>규격(모델명)<input type="text" name="spec" maxlength="200" value="<?= h((string)$ev['spec']) ?>"></label>
        <label>적요<input type="text" name="remark" maxlength="255" value="<?= h((string)$ev['remark']) ?>"></label>
        <label>입고단가<input type="text" name="in_price" value="<?= h((string)(float)$ev['in_price']) ?>"></label>
        <label>입고단가 VAT포함<input type="text" name="in_vat" maxlength="10" value="<?= h((string)$ev['in_vat']) ?>"></label>
        <label>출고단가<input type="text" name="out_price" value="<?= h((string)(float)$ev['out_price']) ?>"></label>
        <label>출고단가 VAT포함<input type="text" name="out_vat" maxlength="10" value="<?= h((string)$ev['out_vat']) ?>"></label>
        <label>인상 전가<input type="text" name="prev_price" value="<?= h((string)(float)$ev['prev_price']) ?>"></label>
        <label>인상 전가 VAT포함<input type="text" name="prev_vat" maxlength="10" value="<?= h((string)$ev['prev_vat']) ?>"></label>
        <label>인상 전전가<input type="text" name="prev2_price" value="<?= h((string)(float)$ev['prev2_price']) ?>"></label>
        <label>인상 전전가 VAT포함<input type="text" name="prev2_vat" maxlength="10" value="<?= h((string)$ev['prev2_vat']) ?>"></label>
        <label style="align-self:end;"><input type="checkbox" name="disabled" value="Y" <?= ((string)$ev['disabled'] === 'Y') ? 'checked' : '' ?>> 사용중단</label>
        <div style="align-self:end;display:flex;gap:8px;">
          <button type="submit" class="btn-admin-primary">저장</button>
          <?php if ($editRow): ?><a href="<?= BASE_URL ?>/admin/ecount-items.php" class="btn-admin-primary" style="background:#64748b;text-decoration:none;">취소</a><?php endif; ?>
        </div>
      </form>
    </details>
  </div>

  <div class="api-svc-card">
    <h3>품목 리스트 <span style="font-weight:400;font-size:13px;color:#64748b;">전체 <?= $allCount ?>건 · 사용중단 <?= $disCount ?>건 · 현재 조건 <?= $total ?>건</span></h3>
    <form method="get" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="품목코드 / 품목명 / 규격 / 적요 검색" style="min-width:260px;">
      <select name="st">
        <option value="">사용중단: 전체</option>
        <option value="N" <?= $st === 'N' ? 'selected' : '' ?>>사용중</option>
        <option value="Y" <?= $st === 'Y' ? 'selected' : '' ?>>사용중단</option>
      </select>
      <select name="ss">
        <option value="">이카운트 전송: 전체</option>
        <option value="pending" <?= $ss === 'pending' ? 'selected' : '' ?>>대기</option>
        <option value="sent" <?= $ss === 'sent' ? 'selected' : '' ?>>완료</option>
        <option value="failed" <?= $ss === 'failed' ? 'selected' : '' ?>>실패</option>
      </select>
      <button type="submit" class="btn-admin-primary">검색</button>
    </form>

    <div style="overflow-x:auto;">
    <table class="admin-table" style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead><tr>
        <th>품목코드</th><th>품목명</th><th>규격(모델)</th><th>적요</th>
        <th style="text-align:right;">입고단가</th><th style="text-align:right;">출고단가</th><th style="text-align:right;">인상 전가</th>
        <th>사용</th><th>이카운트 전송</th><th>쇼핑몰 연결</th><th>관리</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $pm2 = $prodMap[mb_strtoupper((string)$r['item_cd'])] ?? null; ?>
        <tr>
          <td><?= h((string)$r['item_cd']) ?></td>
          <td><?= h((string)$r['item_name']) ?></td>
          <td><?= h((string)$r['spec']) ?></td>
          <td><?= h((string)$r['remark']) ?></td>
          <td style="text-align:right;"><?= number_format((float)$r['in_price']) ?></td>
          <td style="text-align:right;"><?= number_format((float)$r['out_price']) ?></td>
          <td style="text-align:right;"><?= number_format((float)$r['prev_price']) ?></td>
          <td><?= (string)$r['disabled'] === 'Y' ? '<span style="color:#b91c1c;">중단</span>' : '사용' ?></td>
          <td>
            <?php if ($r['sync_status'] === 'sent'): ?>
              <span style="color:#15803d;" title="<?= h((string)$r['synced_at']) ?>">완료</span>
            <?php elseif ($r['sync_status'] === 'failed'): ?>
              <span style="color:#b91c1c;" title="<?= h((string)$r['sync_error']) ?>">실패 ⓘ</span>
            <?php else: ?>
              <span style="color:#b45309;">대기</span>
            <?php endif; ?>
          </td>
          <td><?= $pm2 ? h('#' . (string)$pm2['id'] . ' ' . (string)$pm2['name']) : '<span style="color:#94a3b8;">미연결</span>' ?></td>
          <td style="white-space:nowrap;">
            <a href="?<?= h(http_build_query(['q' => $q, 'st' => $st, 'ss' => $ss, 'page' => $page, 'edit' => (int)$r['id']])) ?>">수정</a>
            <?php if ($isSuper): ?>
            <form method="post" style="display:inline;">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="verify">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" style="background:none;border:0;color:#2563eb;cursor:pointer;">이카운트 확인</button>
            </form>
            <?php endif; ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('이 사이트 리스트에서만 삭제합니다. 이카운트에는 영향이 없습니다. 삭제할까요?');">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;">삭제</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="11" style="text-align:center;padding:24px;">등록된 품목이 없습니다. 위에서 엑셀을 올려 주세요.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>

    <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">
      <?php for ($pg = max(1, $page - 5); $pg <= min($totalPages, $page + 5); $pg++): ?>
        <a href="?<?= h(http_build_query(['q' => $q, 'st' => $st, 'ss' => $ss, 'page' => $pg])) ?>"
           style="padding:4px 10px;border:1px solid #cbd5e1;border-radius:6px;<?= $pg === $page ? 'background:#1e293b;color:#fff;' : '' ?>"><?= $pg ?></a>
      <?php endfor; ?>
    </div>
  </div>

  <?php if ($logs): ?>
  <div class="api-svc-card">
    <h3>최근 품목 전송 로그</h3>
    <table class="admin-table" style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead><tr><th>시각</th><th>내용</th><th>성공</th><th>실패</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
        <tr><td><?= h((string)$l['created_at']) ?></td><td><?= h((string)$l['summary']) ?></td><td><?= (int)$l['success_count'] ?></td><td><?= (int)$l['fail_count'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var b = document.getElementById('pushBtn');
  if (!b) return;
  var w = parseInt(b.getAttribute('data-wait') || '0', 10);
  if (w <= 0) return;
  var label = b.textContent;
  b.disabled = true;
  b.textContent = label + ' (' + w + '초 후 가능)';
  var iv = setInterval(function () {
    w--;
    if (w <= 0) { clearInterval(iv); b.disabled = false; b.textContent = label; }
    else { b.textContent = label + ' (' + w + '초 후 가능)'; }
  }, 1000);
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
