<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$u = current_user();
if (!$u || ($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    page_header('감사 로그', 'config');
    echo '<div class="card"><p>관리자만 볼 수 있는 화면입니다.</p></div>';
    page_footer();
    exit;
}

const AUDIT_LIST_MAX = 300;

$pdo  = db();
$from = in_str($_GET, 'from', 10);
$to   = in_str($_GET, 'to', 10);
if (!valid_date($from)) {
    $from = date('Y-m-d', strtotime('-7 days'));
}
if (!valid_date($to)) {
    $to = date('Y-m-d');
}
$actionF = in_str($_GET, 'action', 50);
$userRaw = in_str($_GET, 'user', 20);
$userF   = ctype_digit($userRaw) ? (int)$userRaw : 0;
$q       = in_str($_GET, 'q', 50);

$where = ['a.created_at >= ?', 'a.created_at < ?'];
$args  = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
if ($actionF !== '') {
    $where[] = 'a.action = ?';
    $args[]  = $actionF;
}
if ($userF > 0) {
    $where[] = 'a.user_id = ?';
    $args[]  = $userF;
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(a.detail LIKE ? OR a.target LIKE ? OR a.action LIKE ?)';
    array_push($args, $like, $like, $like);
}

$st = $pdo->prepare(
    "SELECT a.id, a.created_at, a.action, a.target, a.target_id, a.detail, a.ip,
            u.name AS user_name, u.login_id
       FROM erp_audit_logs a
       LEFT JOIN erp_users u ON u.id = a.user_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY a.id DESC
      LIMIT " . (AUDIT_LIST_MAX + 1)
);
$st->execute($args);
$rows = $st->fetchAll();
$truncated = count($rows) > AUDIT_LIST_MAX;
if ($truncated) {
    $rows = array_slice($rows, 0, AUDIT_LIST_MAX);
}

$actions = $pdo->query("SELECT DISTINCT action FROM erp_audit_logs ORDER BY action LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
$users   = $pdo->query("SELECT id, name, login_id FROM erp_users ORDER BY name LIMIT 200")->fetchAll();

page_header('감사 로그', 'config');
?>
<style>
.al-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff}
.al-tbl th,.al-tbl td{padding:5px 8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
.al-tbl th{background:#f9fafb;white-space:nowrap}
.al-tbl td.det{max-width:420px;word-break:break-all}
.al-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:10px}
</style>
<h1>감사 로그</h1>
<form method="get" class="al-bar">
  <input type="date" name="from" value="<?= h($from) ?>">
  <span>~</span>
  <input type="date" name="to" value="<?= h($to) ?>">
  <select name="action">
    <option value="">전체 작업</option>
    <?php foreach ($actions as $a): ?>
      <option value="<?= h($a) ?>" <?= $a === $actionF ? 'selected' : '' ?>><?= h($a) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="user">
    <option value="0">전체 사용자</option>
    <?php foreach ($users as $x): ?>
      <option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $userF ? 'selected' : '' ?>><?= h($x['name'] . ' (' . $x['login_id'] . ')') ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="대상 / 상세 검색" maxlength="50">
  <button class="btn" type="submit">조회</button>
</form>

<table class="al-tbl">
  <thead>
    <tr><th>일시</th><th>사용자</th><th>작업</th><th>대상</th><th>상세</th><th>IP</th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="6">조건에 맞는 기록이 없습니다.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['created_at']) ?></td>
      <td><?= h(($r['user_name'] ?? '') !== '' ? $r['user_name'] : '(알 수 없음)') ?></td>
      <td><?= h($r['action']) ?></td>
      <td><?= h(trim($r['target'] . ($r['target_id'] !== null ? ' #' . $r['target_id'] : ''))) ?></td>
      <td class="det"><?= h($r['detail']) ?></td>
      <td><?= h($r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($truncated): ?>
  <p class="dsh-foot" style="font-size:12px;color:#6b7280">최신 <?= AUDIT_LIST_MAX ?>건만 표시합니다. 기간·작업·사용자 조건으로 좁혀 주세요.</p>
<?php endif; ?>
<?php
require_once __DIR__ . '/inc/list_bar.php';
listbar_render(['title' => '감사 로그']);
page_footer();
