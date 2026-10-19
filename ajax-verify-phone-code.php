<?php
// ajax-verify-phone-code.php — 인증번호 검증
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$phone = preg_replace('/[^0-9]/', '', trim($_POST['phone'] ?? ''));
$code  = trim($_POST['code'] ?? '');

if ($phone === '' || $code === '') {
    echo json_encode(['ok' => false, 'msg' => '휴대폰 번호와 인증번호를 모두 입력해주세요.']);
    exit;
}

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
        error_log('[phone-verify] table create: ' . $e2->getMessage());
    }
}

// DB에서 인증번호 검증
try {
    $stmt = $pdo->prepare(
        'SELECT id FROM tt_phone_verifications
         WHERE phone = :phone AND code = :code AND is_verified = 0 AND expires_at >= NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['phone' => $phone, 'code' => $code]);
    $row = $stmt->fetch();

    if ($row) {
        $pdo->prepare('UPDATE tt_phone_verifications SET is_verified = 1 WHERE id = :id')->execute(['id' => $row['id']]);
        echo json_encode(['ok' => true, 'msg' => '휴대폰 인증이 완료되었습니다.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('[phone-verify] query: ' . $e->getMessage());
}

// DB 검증 실패 — 무료 모드 fallback: 최근 10분 내 발송된 코드 중 아무거나 매칭
try {
    $stmt2 = $pdo->prepare(
        'SELECT id, code FROM tt_phone_verifications
         WHERE phone = :phone AND is_verified = 0 AND created_at > (NOW() - INTERVAL 10 MINUTE)
         ORDER BY id DESC LIMIT 1'
    );
    $stmt2->execute(['phone' => $phone]);
    $row2 = $stmt2->fetch();
    if ($row2 && (string)$row2['code'] === (string)$code) {
        $pdo->prepare('UPDATE tt_phone_verifications SET is_verified = 1 WHERE id = :id')->execute(['id' => $row2['id']]);
        echo json_encode(['ok' => true, 'msg' => '휴대폰 인증이 완료되었습니다.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('[phone-verify] fallback: ' . $e->getMessage());
}

echo json_encode(['ok' => false, 'msg' => '인증번호가 올바르지 않거나 만료되었습니다.']);
