<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

if (is_post()) {
    require_write();
    csrf_check();
    $act = in_str($_POST, 'act', 10);
    if ($act === 'add') {
        $name = in_str($_POST, 'name', 50);
        $pid  = in_int($_POST, 'parent_id');
        $paths = category_paths(false);
        if ($name === '') {
            flash('분류명을 입력하세요.', 'err');
        } elseif (strpos($name, '>') !== false) {
            flash('분류명에는 > 문자를 쓸 수 없습니다.', 'err');
        } elseif ($pid > 0 && (!isset($paths[$pid]) || substr_count($paths[$pid], ' > ') >= 3)) {
            flash('상위 분류가 올바르지 않거나 너무 깊습니다.', 'err');
        } else {
            $parentVal = $pid > 0 ? $pid : null;
            $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id <=> ? AND name = ?');
            $st->execute([$parentVal, $name]);
            if ((int)$st->fetchColumn() > 0) {
                flash('같은 위치에 같은 이름의 분류가 이미 있습니다.', 'err');
            } else {
                $pdo->prepare('INSERT INTO categories (parent_id, name, sort_order) VALUES (?,?,?)')
                    ->execute([$parentVal, $name, 100]);
                audit('category_create', 'category', (int)$pdo->lastInsertId(), $name);
                flash('분류를 추가했습니다.');
            }
        }
    } elseif ($act === 'update') {
        $id     = in_int($_POST, 'id');
        $name   = in_str($_POST, 'name', 50);
        $sort   = in_int($_POST, 'sort_order');
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id <= 0 || $name === '') {
            flash('분류명을 입력하세요.', 'err');
        } elseif (strpos($name, '>') !== false) {
            flash('분류명에는 > 문자를 쓸 수 없습니다.', 'err');
        } else {
            $st = $pdo->prepare('SELECT parent_id FROM categories WHERE id = ?');
            $st->execute([$id]);
            $cur = $st->fetch();
            if (!$cur) {
                flash('분류를 찾을 수 없습니다.', 'err');
            } else {
                $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id <=> ? AND name = ? AND id <> ?');
                $st->execute([$cur['parent_id'], $name, $id]);
                if ((int)$st->fetchColumn() > 0) {
                    flash('같은 위치에 같은 이름의 분류가 이미 있습니다.', 'err');
                } else {
                    $pdo->prepare('UPDATE categories SET name=?, sort_order=?, is_active=? WHERE id=?')
                        ->execute([$name, $sort, $active, $id]);
                    audit('category_update', 'category', $id, $name);
                    flash('저장했습니다.');
                }
            }
        }
    }
    redirect('categories.php');
}

$paths = category_paths(false);
$rows = [];
foreach ($pdo->query('SELECT id, name, sort_order, is_active FROM categories') as $r) {
    $rows[(int)$r['id']] = $r;
}

page_header('분류', 'categories');
?>
<h1>분류</h1>
<p class="hint">전표 입력 화면의 "클릭 → 클릭" 품목 선택이 이 분류 트리를 그대로 사용합니다. 분류를 삭제하는 기능은 없으며, 사용 체크만 해제합니다.</p>

<?php if (can_write()): ?>
<form method="post" class="card toolbar" onsubmit="var b=this.querySelector('button[type=submit]'); if (b) { b.disabled = true; }">
  <?= csrf_field() ?><input type="hidden" name="act" value="add">
  <select name="parent_id">
    <option value="0">(최상위)</option>
    <?php foreach ($paths as $id => $p): ?><option value="<?= (int)$id ?>"><?= h($p) ?></option><?php endforeach; ?>
  </select>
  <input id="catName" name="name" placeholder="새 분류명" maxlength="50" required>
  <button class="btn btn-primary" type="submit">추가</button>
</form>
<?php endif; ?>

<table class="table">
  <thead><tr><th>경로</th><th>이름</th><th>정렬</th><th>사용</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($paths as $id => $p):
      if (!isset($rows[$id])) {
          continue;
      }
      $r   = $rows[$id];
      $fid = 'cf' . (int)$id;
      $ro  = can_write() ? '' : 'readonly'; ?>
    <tr class="<?= (int)$r['is_active'] ? '' : 'muted' ?>">
      <td>
        <form id="<?= h($fid) ?>" method="post">
          <?= csrf_field() ?><input type="hidden" name="act" value="update"><input type="hidden" name="id" value="<?= (int)$id ?>">
        </form>
        <?= h($p) ?>
      </td>
      <td><input form="<?= h($fid) ?>" name="name" value="<?= h($r['name']) ?>" maxlength="50" <?= $ro ?>></td>
      <td><input form="<?= h($fid) ?>" name="sort_order" value="<?= (int)$r['sort_order'] ?>" size="4" <?= $ro ?>></td>
      <td><input form="<?= h($fid) ?>" type="checkbox" name="is_active" value="1" <?= (int)$r['is_active'] ? 'checked' : '' ?> <?= can_write() ? '' : 'disabled' ?>></td>
      <td><?php if (can_write()): ?><button class="btn btn-sm" type="submit" form="<?= h($fid) ?>">저장</button><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$paths): ?><tr><td colspan="5" class="empty">분류가 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>

<script>
// 하단 바의 [신규(F2)] → "새 분류명" 칸으로 이동하고 커서를 둔다
(function () {
  function focusNew() {
    if (location.hash !== '#catName') { return; }
    var el = document.getElementById('catName');
    if (el) { el.scrollIntoView({ block: 'center' }); el.focus(); }
    // 해시를 지워 두면 같은 버튼을 다시 눌러도 동작한다
    history.replaceState(null, '', location.pathname + location.search);
  }
  window.addEventListener('hashchange', focusNew);
  focusNew();
})();
</script>
<?php
require_once __DIR__ . '/inc/list_bar.php';
listbar_render(['title' => '분류 목록', 'new_url' => '#catName']);
page_footer();
