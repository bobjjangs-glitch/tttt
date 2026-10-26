<?php
// ajax-reservation-slots.php — 방문 예약: 날짜·방문구분별 시간대 가용 인원 조회
// 해당 날짜의 시간대별 잔여 인원을 반환한다 (취소/노쇼 제외 집계).
require_once __DIR__ . '/core/bootstrap.php';
ensure_reservations_table();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['ok' => false, 'msg' => '잘못된 요청입니다.']);
    exit;
}

$date = trim($_GET['date'] ?? '');
$type = trim($_GET['type'] ?? '') ?: 'tire';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['ok' => false, 'msg' => '날짜 형식이 올바르지 않습니다.']);
    exit;
}
$today = date('Y-m-d');
if ($date < $today || $date > date('Y-m-d', strtotime('+30 days'))) {
    echo json_encode(['ok' => false, 'msg' => '예약은 오늘부터 30일 이내만 가능합니다.']);
    exit;
}
$labels = resv_visit_type_labels();
if (!array_key_exists($type, $labels)) $type = 'tire';

$max = get_resv_slot_max($type);
$now = date('H:i');

$slots = [];
foreach (resv_time_slots() as $t) {
    /* 오늘 날짜면 이미 지난(현재 시각 이전) 시간대는 제외 */
    if ($date === $today && $t <= $now) continue;
    $remaining = resv_slot_remaining($date, $t, $type);
    if ($remaining <= 0) continue; // 마감 시간대는 아예 보여주지 않음
    $slots[] = ['time' => $t, 'remaining' => $remaining];
}

echo json_encode([
    'ok'    => true,
    'type'  => $type,
    'label' => $labels[$type],
    'max'   => $max,
    'slots' => $slots,
    'date'  => $date,
], JSON_UNESCAPED_UNICODE);
