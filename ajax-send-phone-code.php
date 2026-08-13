<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$phone = trim($_POST['phone'] ?? '');
if (!preg_match('/^01[0-9]-?\d{3,4}-?\d{4}$/', $phone)) {
    echo json_encode(['ok' => false, 'msg' => '올바른 휴대폰 번호를 입력해주세요.']);
    exit;
}
$phone = preg_replace('/[^0-9]/', '', $phone);

$pdo = Database::connection();

// 1분 이내 재요청 방지 (스팸성 발송 남용 방지)
$recent = $pdo->prepare('SELECT id FROM tt_phone_verifications WHERE phone = :phone AND created_at > (NOW() - INTERVAL 1 MINUTE) ORDER BY id DESC LIMIT 1');
$recent->execute(['phone' => $phone]);
if ($recent->fetch()) {
    echo json_encode(['ok' => false, 'msg' => '잠시 후 다시 시도해주세요.']);
    exit;
}

$code = (string)random_int(100000, 999999);
$stmt = $pdo->prepare('INSERT INTO tt_phone_verifications (phone, code, expires_at) VALUES (:phone, :code, NOW() + INTERVAL 3 MINUTE)');
$stmt->execute(['phone' => $phone, 'code' => $code]);

$sent = Sms::sendVerificationCode($phone, $code);

$response = ['ok' => (bool)$sent, 'msg' => $sent ? '인증번호가 발송되었습니다.' : '발송에 실패했습니다.'];
if (!defined('SMS_API_KEY')) {
    $response['dev_code'] = $code; // ★ 테스트 모드에서만 화면에 코드 노출. 운영 전환 시 이 줄 반드시 삭제.
}
echo json_encode($response);
