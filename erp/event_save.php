<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/events.php';

if (!is_post()) {
    redirect('index.php');
    exit;
}
require_write();
csrf_check();

$act = in_str($_POST, 'act', 10);
$ym  = in_str($_POST, 'ym', 7);
$d   = in_str($_POST, 'd', 10);
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}
if ($d !== '' && !valid_date($d)) {
    $d = '';
}
$back = 'index.php?ym=' . $ym . ($d !== '' ? '&d=' . $d : '');

$idRaw = in_str($_POST, 'id', 20);
$id = ctype_digit($idRaw) ? (int)$idRaw : 0;

try {
    ev_ensure_table();
    $pdo = db();
    $u = current_user();
    $uid = $u ? (int)$u['id'] : null;

    if ($act === 'add' || $act === 'update') {
        $title = in_str($_POST, 'title', 100);
        $cat   = in_str($_POST, 'category', 20);
        $s     = in_str($_POST, 'start_date', 10);
        $e     = in_str($_POST, 'end_date', 10);
        $memo  = in_str($_POST, 'memo', 500);
        if ($e === '') {
            $e = $s;
        }

        $err = null;
        if ($title === '') {
            $err = '제목을 입력하세요.';
        } elseif (!isset(ev_categories()[$cat])) {
            $err = '구분이 올바르지 않습니다.';
        } elseif (!valid_date($s) || !valid_date($e)) {
            $err = '날짜가 올바르지 않습니다.';
        } elseif ($e < $s) {
            $err = '종료일이 시작일보다 빠릅니다.';
        } elseif (strtotime($e) - strtotime($s) > 366 * 86400) {
            $err = '일정 기간은 최대 1년까지입니다.';
        } elseif ($act === 'update' && $id <= 0) {
            $err = '수정할 일정을 찾을 수 없습니다.';
        }
        if ($err !== null) {
            flash($err, 'err');
            redirect($back);
            exit;
        }

        if ($act === 'add') {
            $st = $pdo->prepare(
                "INSERT INTO erp_events (title, category, start_date, end_date, memo, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $st->execute([$title, $cat, $s, $e, $memo, $uid]);
            audit('event_create', 'event', (int)$pdo->lastInsertId(), $title);
            flash('일정을 등록했습니다.');
        } else {
            $st = $pdo->prepare(
                "UPDATE erp_events SET title = ?, category = ?, start_date = ?, end_date = ?, memo = ?, updated_at = NOW()
                  WHERE id = ?"
            );
            $st->execute([$title, $cat, $s, $e, $memo, $id]);
            audit('event_update', 'event', $id, $title);
            flash('일정을 수정했습니다.');
        }
    } elseif ($act === 'toggle' && $id > 0) {
        $pdo->prepare("UPDATE erp_events SET is_done = 1 - is_done, updated_at = NOW() WHERE id = ?")->execute([$id]);
        audit('event_toggle', 'event', $id);
    } elseif ($act === 'delete' && $id > 0) {
        $st = $pdo->prepare("SELECT title FROM erp_events WHERE id = ?");
        $st->execute([$id]);
        $title = (string)($st->fetchColumn() ?: '');
        $pdo->prepare("DELETE FROM erp_events WHERE id = ?")->execute([$id]);
        audit('event_delete', 'event', $id, $title);
        flash('일정을 삭제했습니다.');
    } else {
        flash('요청을 처리할 수 없습니다.', 'err');
    }
} catch (Throwable $e) {
    error_log('[ERP] event_save: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    flash('처리 중 오류가 발생했습니다.', 'err');
}

redirect($back);
