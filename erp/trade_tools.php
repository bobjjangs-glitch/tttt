<?php
// trade_tools.php - 전표 비고 저장 (수량·금액·재고·상태는 건드리지 않음). 미실행 코드
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_write();
if (!is_post()) {
    http_response_code(405);
    exit('잘못된 요청입니다.');
}
csrf_check();
$pdo = db();

/** 돌아갈 주소: trade_view.php만 허용 (임의 주소로 보내지 않음) */
function ttools_back(string $raw): string
{
    $p = parse_url($raw);
    if (!is_array($p) || basename((string)($p['path'] ?? '')) !== 'trade_view.php') {
        return 'trades.php';
    }
    $q = preg_replace('/[^A-Za-z0-9=&_%.\-]/', '', (string)($p['query'] ?? '')) ?? '';
    return 'trade_view.php' . ($q !== '' ? '?' . $q : '');
}

$id   = in_int($_POST, 'id');
$back = ttools_back((string)($_POST['back'] ?? ''));
$memo = (isset($_POST['memo']) && is_array($_POST['memo'])) ? $_POST['memo'] : [];

$clip = function ($v, int $max): string {
    return mb_substr(trim((string)$v), 0, $max);
};

$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT id, status FROM trades WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) {
        $pdo->rollBack();
        flash('전표를 찾을 수 없습니다.', 'error');
        redirect('trades.php');
    }
    if ((string)$t['status'] === 'canceled') {
        $pdo->rollBack();
        flash('취소된 전표는 비고를 수정할 수 없습니다.', 'error');
        redirect($back);
    }

    $st = $pdo->prepare('SELECT id FROM trade_lines WHERE trade_id = ?');
    $st->execute([$id]);
    $own = array_flip(array_map('intval', array_column($st->fetchAll(), 'id')));

    $up = $pdo->prepare('UPDATE trade_lines SET memo = ? WHERE id = ? AND trade_id = ?');
    foreach ($memo as $lineId => $text) {
        $lineId = (int)$lineId;
        if (isset($own[$lineId])) {          // 이 전표의 줄만 수정
            $up->execute([$clip($text, 200), $lineId, $id]);
        }
    }
    if (array_key_exists('trade_memo', $_POST)) {
        $pdo->prepare('UPDATE trades SET memo = ?, updated_at = ? WHERE id = ?')
            ->execute([$clip($_POST['trade_memo'], 500), date('Y-m-d H:i:s'), $id]);
    }
    audit('trade_memo', 'trades', $id, '비고 수정');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
flash('비고를 저장했습니다.');
redirect($back);
