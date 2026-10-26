<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/cat_tree.php';
$pdo = db();
$cats = category_paths(true);

const ITEMS_LIST_MAX = 300;

// 입력칸 표시용: DB 가 DECIMAL(예: 85000.00)이어도 정수 부분만 보여 줌
$wonText = static function ($v): string {
    return explode('.', (string)$v)[0];
};

$form = null;
if (is_post()) {
    require_write();
    csrf_check();
    $id    = in_int($_POST, 'id');
    $code  = in_str($_POST, 'item_code', 40);
    $name  = in_str($_POST, 'name', 200);
    $cat   = in_int($_POST, 'category_id');
    $brand = in_str($_POST, 'brand', 50);
    $pat   = in_str($_POST, 'pattern_name', 80);
    $spec  = in_str($_POST, 'spec', 40);
    $unit  = in_str($_POST, 'unit', 10) ?: '개';
    $tax   = in_str($_POST, 'tax_type', 10);
    $retail = parse_won($_POST['retail_price'] ?? '');
    $whole  = parse_won($_POST['wholesale_price'] ?? '');
    $stock  = isset($_POST['is_stock_item']) ? 1 : 0;
    $active = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $code)) {
        $errors[] = '품목코드는 영문/숫자/-/_/. 만 사용할 수 있습니다.';
    }
    if ($name === '') {
        $errors[] = '품목명을 입력하세요.';
    }
    if (!isset($cats[$cat])) {
        $errors[] = '분류를 선택하세요.';
    }
    if (!in_array($tax, ['taxable', 'exempt'], true)) {
        $errors[] = '과세구분이 올바르지 않습니다.';
    }
    if ($retail === null || $whole === null) {
        $errors[] = '가격은 0 이상의 정수(원)로 입력하세요.';
    }

    $form = ['id' => $id, 'item_code' => $code, 'name' => $name, 'category_id' => $cat, 'brand' => $brand,
             'pattern_name' => $pat, 'spec' => $spec, 'unit' => $unit, 'tax_type' => $tax,
             'retail_price' => $retail ?? 0, 'wholesale_price' => $whole ?? 0, 'is_stock_item' => $stock, 'is_active' => $active];

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $specCode = normalize_tire_spec($spec !== '' ? $spec : $name);
        try {
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE items SET item_code=?, name=?, category_id=?, brand=?, pattern_name=?, spec=?, spec_code=?, unit=?, tax_type=?,
                            retail_price=?, wholesale_price=?, is_stock_item=?, is_active=?, updated_at=? WHERE id=?'
                )->execute([$code, $name, $cat, $brand, $pat, $spec, $specCode, $unit, $tax, $retail, $whole, $stock, $active, $now, $id]);
                audit('item_update', 'item', $id, $code);
            } else {
                $pdo->prepare(
                    'INSERT INTO items (item_code, name, category_id, brand, pattern_name, spec, spec_code, unit, tax_type, retail_price, wholesale_price, is_stock_item, is_active, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$code, $name, $cat, $brand, $pat, $spec, $specCode, $unit, $tax, $retail, $whole, $stock, $active, $now, $now]);
                audit('item_create', 'item', (int)$pdo->lastInsertId(), $code);
            }
            flash('품목을 저장했습니다.');
            redirect('items.php');
        } catch (PDOException $e) {
            // MySQL 1062 = 유니크 키 중복. 그 외 오류(외래키 등)는 중복으로 오해하지 않도록 그대로 던진다.
            $isDup = isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062;
            if ($isDup) {
                $errors[] = '이미 사용 중인 품목코드입니다.';
            } else {
                throw $e;
            }
        }
    }
    foreach ($errors as $er) {
        flash($er, 'err');
    }
}

if ($form === null) {
    $editId = in_int($_GET, 'edit');
    if ($editId > 0) {
        $st = $pdo->prepare('SELECT * FROM items WHERE id = ?');
        $st->execute([$editId]);
        $form = $st->fetch() ?: null;
    } elseif (isset($_GET['new'])) {
        $form = ['id' => 0, 'item_code' => '', 'name' => '', 'category_id' => 0, 'brand' => '', 'pattern_name' => '',
                 'spec' => '', 'unit' => '개', 'tax_type' => 'taxable', 'retail_price' => 0, 'wholesale_price' => 0,
                 'is_stock_item' => 1, 'is_active' => 1];
    }
}

// 목록 + 필터 (선택 분류의 하위 분류 포함)
$q = in_str($_GET, 'q', 50);
$catFilter = in_int($_GET, 'cat');
$where = [];
$args = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $digits = preg_replace('/\D/', '', $q);
    $likeDigits = $digits !== '' ? '%' . $digits . '%' : $like;
    $where[] = '(i.name LIKE ? OR i.item_code LIKE ? OR i.spec LIKE ? OR i.spec_code LIKE ?)';
    array_push($args, $like, $like, $like, $likeDigits);
}
if ($catFilter > 0) {
    $ids = cat_descendant_ids($pdo, $catFilter);
    $where[] = 'i.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $args = array_merge($args, $ids);
}
$sql = 'SELECT i.* FROM items i' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY i.is_active DESC, i.name LIMIT ' . (ITEMS_LIST_MAX + 1);
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
$more = count($rows) > ITEMS_LIST_MAX;
if ($more) {
    $rows = array_slice($rows, 0, ITEMS_LIST_MAX);
}
$allPaths = category_paths(false);

page_header('품목', 'items');
?>
<h1>품목</h1>
<?php cat_tree_open($pdo, 'items.php', $catFilter); ?>
<form method="get" class="toolbar no-print">
  <select name="cat">
    <option value="0">전체 분류</option>
    <?php foreach ($allPaths as $cid => $p): ?><option value="<?= (int)$cid ?>" <?= $catFilter === $cid ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?>
  </select>
  <input name="q" value="<?= h($q) ?>" placeholder="품목명 / 코드 / 규격(205/55R16)">
  <button class="btn" type="submit">검색</button>
  <?php if (can_write()): ?><a class="btn btn-primary" href="items.php?new=1">+ 새 품목</a><?php endif; ?>
</form>

<?php if ($form !== null && can_write()): ?>
<form method="post" class="card grid-form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
  <label>품목코드<input name="item_code" value="<?= h($form['item_code']) ?>" required maxlength="40"></label>
  <label class="wide">품목명<input name="name" value="<?= h($form['name']) ?>" required maxlength="200"></label>
  <label>분류
    <select name="category_id" required>
      <option value="">선택</option>
      <?php foreach ($cats as $cid => $p): ?><option value="<?= (int)$cid ?>" <?= (int)$form['category_id'] === $cid ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>브랜드<input name="brand" value="<?= h($form['brand']) ?>" maxlength="50"></label>
  <label>패턴<input name="pattern_name" value="<?= h($form['pattern_name']) ?>" maxlength="80"></label>
  <label>규격<input name="spec" value="<?= h($form['spec']) ?>" maxlength="40" placeholder="205/55R16"></label>
  <label>단위<input name="unit" value="<?= h($form['unit']) ?>" maxlength="10"></label>
  <label>과세구분
    <select name="tax_type">
      <option value="taxable" <?= $form['tax_type'] === 'taxable' ? 'selected' : '' ?>>과세</option>
      <option value="exempt" <?= $form['tax_type'] === 'exempt' ? 'selected' : '' ?>>면세</option>
    </select>
  </label>
  <label>소매가<input name="retail_price" value="<?= h($wonText($form['retail_price'])) ?>" inputmode="numeric"></label>
  <label>도매가<input name="wholesale_price" value="<?= h($wonText($form['wholesale_price'])) ?>" inputmode="numeric"></label>
  <label class="check"><input type="checkbox" name="is_stock_item" value="1" <?= (int)$form['is_stock_item'] ? 'checked' : '' ?>> 재고 관리 (공임은 해제)</label>
  <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int)$form['is_active'] ? 'checked' : '' ?>> 사용</label>
  <div class="wide"><button class="btn btn-primary" type="submit">저장</button> <a class="btn" href="items.php">닫기</a></div>
</form>
<?php endif; ?>

<?php if ($more): ?>
<p class="hint">(최대 <?= (int)ITEMS_LIST_MAX ?>건까지만 표시됩니다. 검색어나 분류로 좁혀 주세요. 인쇄·Excel도 표시된 건만 나옵니다.)</p>
<?php endif; ?>
<table class="table">
  <thead><tr><th>코드</th><th>품목명</th><th>분류</th><th>규격</th><th class="num">소매가</th><th class="num">도매가</th><th class="num">최근매입가</th><th>재고</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= (int)$r['is_active'] ? '' : 'muted' ?>">
      <td><?= h($r['item_code']) ?></td>
      <td><?= h($r['name']) ?></td>
      <td><?= h($allPaths[(int)$r['category_id']] ?? '-') ?></td>
      <td><?= h($r['spec']) ?></td>
      <td class="num"><?= fmt_won($r['retail_price']) ?></td>
      <td class="num"><?= fmt_won($r['wholesale_price']) ?></td>
      <td class="num"><?= fmt_won($r['last_cost']) ?></td>
      <td><?= (int)$r['is_stock_item'] ? '관리' : '-' ?></td>
      <td><?php if (can_write()): ?><a href="items.php?edit=<?= (int)$r['id'] ?>">수정</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">품목이 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
cat_tree_close();
require_once __DIR__ . '/inc/list_bar.php';
listbar_render([
    'title'      => '품목 목록',
    'new_url'    => 'items.php?new=1',
    'excel_page' => 'excel.php',
]);
page_footer();
