<?php
// ledger_export.php - 거래처 원장 / 전체 미수금 현황 CSV 다운로드. 미실행 코드
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ledger_lib.php';
require_login();
$pdo = db();

function lgx_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

/** 엑셀 수식 실행 방지: 위험 문자로 시작하는 문자열 앞에 ' 를 붙인다 */
function lgx_s(string $s): string
{
    return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
}

$notReady = lg_ready_error();
if ($notReady !== null) {
    lgx_fail(503, $notReady);
}

$pid  = in_int($_GET, 'partner');
$from = in_str($_GET, 'from', 10);
$to   = in_str($_GET, 'to', 10);
if (!valid_date($from)) {
    $from = date('Y-m-01', strtotime('-2 months'));
}
if (!valid_date($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$partner = null;
if ($pid > 0) {
    $st = $pdo->prepare('SELECT id, name, business_no, is_credit FROM erp_partners WHERE id = ?');
    $st->execute([$pid]);
    $partner = $st->fetch();
    if (!$partner) {
        lgx_fail(404, '거래처를 찾을 수 없습니다.');
    }
    if ((int)$partner['is_credit'] !== 1) {
        lgx_fail(400, '외상 거래처로 지정된 거래처만 원장을 내려받을 수 있습니다.');
    }
}

// 데이터 준비는 출력 전에 끝낸다 (중간에 예외가 나도 깨진 파일이 내려가지 않게)
$rows = [];
if ($partner) {
    $L = lg_ledger($pid, $from, $to);
    $filename = 'ledger_' . $pid . '_' . $from . '_' . $to . '.csv';
    $rows[] = ['거래처 원장'];
    $rows[] = ['거래처', lgx_s((string)$partner['name'])];
    $rows[] = ['사업자번호', $partner['business_no'] ? lgx_s(fmt_biz_no((string)$partner['business_no'])) : ''];
    $rows[] = ['기간', $from . ' ~ ' . $to];
    $rows[] = [];
    $rows[] = ['일자', '구분', '전표번호', '매출구분', '메모', '매출(청구)', '입금', '잔액'];
    $rows[] = [$from, '이월', '', '', '', '', '', $L['carry']];
    foreach ($L['rows'] as $r) {
        $rows[] = [
            $r['date'],
            $r['kind'],
            lgx_s($r['no']),
            $r['channel'] !== '' ? channel_label($r['channel']) : '',
            lgx_s($r['memo']),
            $r['sale'] ?: '',
            $r['pay'] ?: '',
            $r['bal'],
        ];
    }
    $rows[] = ['합계', '', '', '', '', $L['sale_sum'], $L['pay_sum'], $L['close']];
    $auditDetail = 'partner=' . $pid . ' ' . $from . '~' . $to . ' rows=' . count($L['rows']);
} else {
    $sum = lg_summary();
    $filename = 'receivables_' . date('Ymd') . '.csv';
    $rows[] = ['외상 거래처 미수금 현황'];
    $rows[] = ['출력일', date('Y-m-d')];
    $rows[] = [];
    $rows[] = ['거래처', '사업자번호', '기초잔액', '기준일', '매출(기준일 이후)', '입금', '미수금'];
    $tot = 0;
    foreach ($sum as $r) {
        $tot += $r['bal'];
        $rows[] = [
            lgx_s($r['name']),
            $r['biz'] !== '' ? lgx_s(fmt_biz_no($r['biz'])) : '',
            $r['open'],
            (string)($r['as_of'] ?? ''),
            $r['sale'],
            $r['pay'],
            $r['bal'],
        ];
    }
    $rows[] = ['합계', '', '', '', '', '', $tot];
    $auditDetail = 'summary rows=' . count($sum);
}

audit('ledger_export', 'ledger', $pid > 0 ? $pid : null, $auditDetail);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // 엑셀이 UTF-8 로 인식하게 하는 BOM
foreach ($rows as $row) {
    fputcsv($out, $row, ',', '"', '');   // PHP 8.4: escape 인자 명시 (생략 시 deprecated 경고가 파일에 섞임)
}
fclose($out);
