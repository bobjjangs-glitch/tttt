<?php
// /admin/_seed_admin.php
// ⚠️ 이 파일은 관리자 계정을 1회 생성하기 위한 임시 스크립트입니다.
// 실행 후 반드시 서버에서 삭제하세요.
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

$username = 'admin';
$password = 'ChangeThisPassword123!'; // 원하시면 여기서 직접 바꾸셔도 됩니다.
$name     = '최고관리자';
$role     = 'super';

try {
    $pdo = Database::connection();

    $check = $pdo->prepare('SELECT id FROM tt_admins WHERE username = :u LIMIT 1');
    $check->execute(['u' => $username]);
    if ($check->fetch()) {
        echo "이미 '{$username}' 계정이 존재합니다. 이 파일을 삭제하세요.";
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('INSERT INTO tt_admins (username, password_hash, name, role) VALUES (:u, :p, :n, :r)');
    $stmt->execute([
        'u' => $username,
        'p' => $hash,
        'n' => $name,
        'r' => $role,
    ]);

    echo "관리자 계정 생성 완료: {$username} / {$password} (role: {$role})<br>";
    echo "지금 즉시 로그인 후 비밀번호를 변경하고, 이 파일을 서버에서 삭제하세요.";
} catch (Throwable $e) {
    echo "오류 발생: " . htmlspecialchars($e->getMessage());
    error_log('[seed_admin] ' . $e->getMessage());
}
