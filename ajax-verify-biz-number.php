<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$bNo = preg_replace('/[^0-9]/', '', trim($_POST['biz_number'] ?? ''));

if (strlen($bNo) !== 10) {
    echo json_encode(['ok' => false, 'msg' => '사업자등록번호는 숫자 10자리여야 합니다.']);
    exit;
}

/* 1) 체크섬(구조) 검증 — Validator::bizNumber()와 동일한 국세청 공식 검증 알고리즘 */
$weights = [1, 3, 7, 1, 3, 7, 1, 3, 5];
$sum = 0;
for ($i = 0; $i < 9; $i++) {
    $sum += (int)$bNo[$i] * $weights[$i];
}
$sum += intdiv(((int)$bNo[8] * 5), 10);
$checkDigit = (10 - ($sum % 10)) % 10;

if ($checkDigit !== (int)$bNo[9]) {
    echo json_encode(['ok' => false, 'msg' => '유효하지 않은 사업자등록번호입니다. 다시 확인해주세요.']);
    exit;
}

/* 2) 국세청 실시간 상태조회 (공공데이터포털 - data.go.kr)
   서비스키가 설정되지 않았다면 형식 검증까지만 통과시키고 안내 문구를 띄운다. */
if (!defined('NTS_API_SERVICE_KEY') || NTS_API_SERVICE_KEY === '') {
    echo json_encode([
        'ok' => true,
        'verified' => false,
        'msg' => '사업자등록번호 형식은 유효합니다. (국세청 실시간 확인은 관리자가 아직 설정하지 않았습니다)',
    ]);
    exit;
}

$ch = curl_init('https://api.odcloud.kr/api/nts-businessman/v1/status?serviceKey=' . NTS_API_SERVICE_KEY);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json;charset=UTF-8'],
    CURLOPT_POSTFIELDS     => json_encode(['b_no' => [$bNo]], JSON_UNESCAPED_UNICODE),
]);
$res     = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($res === false) {
    error_log('[ajax-verify-biz-number] curl 오류: ' . $curlErr);
    echo json_encode(['ok' => false, 'msg' => '국세청 조회 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.']);
    exit;
}

$data = json_decode($res, true);
$row  = $data['data'][0] ?? null;

if (!$row || empty($row['b_stt_cd'])) {
    echo json_encode(['ok' => false, 'verified' => false, 'msg' => '국세청에 등록되지 않은 사업자등록번호입니다.']);
    exit;
}

/* b_stt_cd: 01=계속사업자, 02=휴업자, 03=폐업자 */
if ($row['b_stt_cd'] !== '01') {
    echo json_encode([
        'ok' => false, 'verified' => false,
        'msg' => '현재 상태: ' . ($row['b_stt'] ?? '확인불가') . ' — 계속사업자만 가입 가능합니다.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true, 'verified' => true,
    'msg' => '국세청에 등록된 사업자입니다. (' . $row['b_stt'] . ')',
]);
