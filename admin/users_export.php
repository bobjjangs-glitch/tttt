<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('orders');

$pdo = Database::connection();

$statusLabels = [
    'active'    => '활성',
    'dormant'   => '휴면',
    'withdrawn' => '탈퇴',
];

// 목록 화면과 동일한 검색/필터 조건을 그대로 적용 (지금 화면에 보이는 조건 그대로 다운로드)
$keyword      = trim($_GET['kw'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$where  = '1=1';
$params = [];
if ($keyword !== '') {
    $where .= ' AND (u.email LIKE :kw OR u.name LIKE :kw OR u.phone LIKE :kw)';
    $params['kw'] = '%' . $keyword . '%';
}
if (array_key_exists($statusFilter, $statusLabels)) {
    $where .= ' AND u.status = :status';
    $params['status'] = $statusFilter;
}

$stmt = $pdo->prepare("
    SELECT u.name, u.email, u.phone, u.status, u.marketing_agree,
           u.created_at, u.last_login_at,
           COALESCE(o.order_count, 0) AS order_count,
           COALESCE(o.total_spent, 0) AS total_spent
    FROM tt_users u
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS order_count, SUM(total_amount) AS total_spent
        FROM tt_orders GROUP BY user_id
    ) o ON o.user_id = u.id
    WHERE {$where}
    ORDER BY u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

AdminAuth::log((int)AdminAuth::currentAdminId(), 'user_export', '회원 목록 엑셀 다운로드 (' . count($users) . '건)');

$filename = '회원목록_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// UTF-8 BOM — 이게 없으면 엑셀에서 한글이 깨져 보입니다.
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['이름', '이메일', '휴대폰', '상태', '마케팅수신동의', '가입일', '최근로그인', '주문건수', '총결제금액']);

foreach ($users as $u) {
    fputcsv($out, [
        $u['name'],
        $u['email'],
        $u['phone'] ?? '-',
        $statusLabels[$u['status']] ?? $u['status'],
        $u['marketing_agree'] ? '동의' : '미동의',
        $u['created_at'],
        $u['last_login_at'] ?? '기록 없음',
        (int)$u['order_count'],
        (int)$u['total_spent'],
    ]);
}

fclose($out);
exit;
