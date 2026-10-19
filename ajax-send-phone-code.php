<?php
// ajax-send-phone-code.php — 무료 인증 모드 (SMS 발송 없이 화면에 인증번호 표시)
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$phone = trim($_POST['phone'] ?? '');
if ($phone === '') {
    echo json_encode(['ok' => false, 'msg' => '휴대폰 번호를 입력해주세요.']);
    exit;
}
$phone = preg_replace('/[^0-9]/', '', $phone);

$pdo = Database::connection();

// 테이블이 없으면 자동 생성
try {
    $pdo->query("SELECT id FROM tt_phone_verifications LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_phone_verifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(20) NOT NULL,
            code VARCHAR(10) NOT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phone (phone)
        ) ENGINE=InnoDB");
    } catch (Throwable $e2) {
        error_log('[phone-send] table create: ' . $e2->getMessage());
    }
}

// 1분 내 재요청 방지
try {
    $recent = $pdo->prepare('SELECT id FROM tt_phone_verifications WHERE phone = :phone AND created_at > (NOW() - INTERVAL 1 MINUTE) ORDER BY id DESC LIMIT 1');
    $recent->execute(['phone' => $phone]);
    if ($recent->fetch()) {
        echo json_encode(['ok' => false, 'msg' => '잠시 후 다시 시도해주세요. (1분 대기)']);
        exit;
    }
} catch (Throwable $e) {
    // 무시하고 진행
}

// 인증번호 생성
$code = (string)random_int(100000, 999999);

// DB에 저장
try {
    $stmt = $pdo->prepare('INSERT INTO tt_phone_verifications (phone, code, expires_at) VALUES (:phone, :code, NOW() + INTERVAL 5 MINUTE)');
    $stmt->execute(['phone' => $phone, 'code' => $code]);
} catch (Throwable $e) {
    error_log('[phone-send] insert: ' . $e->getMessage());
    // DB 저장 실패해도 화면에는 인증번호 표시
}

// 무료 모드: SMS 발송 안 함, 화면에 인증번호 직접 표시
echo json_encode([
    'ok'  => true,
    'msg' => '인증번호가 발송되었습니다. (무료 모드)',
    'dev_code' => $code,
]);
