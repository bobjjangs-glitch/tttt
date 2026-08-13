<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$phone = preg_replace('/[^0-9]/', '', trim($_POST['phone'] ?? ''));
$code  = trim($_POST['code'] ?? '');

$pdo = Database::connection();
$stmt = $pdo->prepare(
    'SELECT id FROM tt_phone_verifications
     WHERE phone = :phone AND code = :code AND is_verified = 0 AND expires_at >= NOW()
     ORDER BY id DESC LIMIT 1'
);
$stmt->execute(['phone' => $phone, 'code' => $code]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['ok' => false, 'msg' => '인증번호가 올바르지 않거나 만료되었습니다.']);
    exit;
}

$pdo->prepare('UPDATE tt_phone_verifications SET is_verified = 1 WHERE id = :id')->execute(['id' => $row['id']]);
echo json_encode(['ok' => true, 'msg' => '휴대폰 인증이 완료되었습니다.']);
