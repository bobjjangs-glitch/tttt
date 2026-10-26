<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/board-lib.php';

if (!tablet_current_worker() && !AdminAuth::isLoggedIn()) {
    redirect('/tablet/login.php');
}
if (!is_post()) redirect('/tablet/reservation-board.php');

$returnTo = '/tablet/reservation-board.php';
if (!empty($_POST['return_to'])
    && preg_match('#^/tablet/[a-z\-]+\.php(\?[A-Za-z0-9=&_\-]*)?$#', (string)$_POST['return_to'])) {
    $returnTo = (string)$_POST['return_to'];
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    flash('tablet_error', '유효하지 않은 요청입니다. 다시 시도해 주세요.');
    redirect($returnTo);
}

$rid  = (int)($_POST['reservation_id'] ?? 0);
$undo = ($_POST['undo'] ?? '') === '1';
if ($rid <= 0) {
    flash('tablet_error', '예약 정보가 올바르지 않습니다.');
    redirect($returnTo);
}

try {
    ensure_board_columns();
    /* 작업일지가 이미 연동된 예약은 입고 상태를 바꾸지 않는다.
       예약확정(confirmed) 이후 예약만 입고 처리 가능.
       (같은 named parameter 재사용 불가 — emulate prepares OFF — 라서 :id / :id2 분리) */
    $sql = $undo
        ? "UPDATE tt_reservations SET checked_in_at = NULL
            WHERE id = :id AND checked_in_at IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM tt_work_logs w WHERE w.reservation_id = :id2)"
        : "UPDATE tt_reservations SET checked_in_at = NOW()
            WHERE id = :id AND status IN ('confirmed','visited') AND checked_in_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM tt_work_logs w WHERE w.reservation_id = :id2)";
    $stmt = Database::connection()->prepare($sql);
    $stmt->execute(['id' => $rid, 'id2' => $rid]);

    if ($stmt->rowCount() > 0) {
        flash('tablet_ok', $undo ? '입고를 취소했습니다.' : '입고 처리했습니다 — 현황판에 "입고중"으로 표시됩니다.');
    } else {
        flash('tablet_error', '처리할 수 없는 예약입니다 (예약확정 전이거나, 이미 입고/작업 시작되었거나 취소된 예약).');
    }
} catch (Throwable $e) {
    error_log('[checkin] ' . $e->getMessage());
    sg_maybe_invalidate($e);
    flash('tablet_error', '입고 처리 중 오류가 발생했습니다.');
}
redirect($returnTo);
