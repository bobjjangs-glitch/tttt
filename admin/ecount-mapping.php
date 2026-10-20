<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');

$pdo = Database::connection();
ensure_ecount_tables($pdo);

/* 단건 저장 */
if (is_post() && ($_POST['action'] ?? '') === 'save_one') {
    Csrf::verify();
    $pid    = (int)($_POST['product_id'] ?? 0);
    $itemCd = trim((string)($_POST['ecount_item_cd'] ?? ''));
    $whCd   = trim((string)($_POST['ecount_wh_cd'] ?? '100'));

    if ($pid <= 0) {
        flash('error', '잘못된 상품입니다.');
        redirect('/admin/ecount-mapping.php');
    }

    try {
        $pdo->prepare('UPDATE tt_products SET ecount_item_cd = :cd, ecount_wh_cd = :wh WHERE id = :id')
            ->execute([
                'cd' => $itemCd !== '' ? $itemCd : null,
                'wh' => $whCd !== '' ? $whCd : '100',
                'id' => $pid,
            ]);
        flash('success', '저장되었습니다.');
    } catch (Throwable $e) {
        sg_maybe_invalidate($e);
        error_log('[ecount-mapping:save_one] ' . $e->getMessage());
        flash('error', '저장 중 오류가 발생했습니다. (품목코드 중복일 수 있습니다)');
    }
    redirect('/admin/ecount-mapping.php' . (isset($_POST['page']) ? '?page=' . (int)$_POST['page'] : ''));
}

/* CSV 일괄 매핑: 상품ID,이카운트품목코드,이카운트창고코드 */
if (is_post() && ($_POST['action'] ?? '') === 'bulk_csv') {
    Csrf::verify();
    $ok = 0; $fail = 0; $errors = [];

    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $raw = file_get_contents($_FILES['csv']['tmp_name']);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw);
        $lines = preg_split('/\r\n|\r|\n/', (string)$raw);

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || $i === 0) continue; // 헤더 스킵
            $cols = str_getcsv($line);
            $pid    = (int)($cols[0] ?? 0);
            $itemCd = trim((string)($cols[1] ?? ''));
            $whCd   = trim((string)($cols[2] ?? '100'));

            if ($pid <= 0 || $itemCd === '') { $fail++; $errors[] = ($i + 1) . '행: 상품ID/품목코드 누락'; continue; }

            try {
                $pdo->prepare('UPDATE tt_products SET ecount_item_cd = :cd, ecount_wh_cd = :wh WHERE id = :id')
                    ->execute(['cd' => $itemCd, 'wh' => $whCd !== '' ? $whCd : '100', 'id' => $pid]);
                $ok++;
            } catch (Throwable $e) {
                $fail++;
                $errors[] = ($i + 1) . "행: 상품ID {$pid} 저장 실패 (" . $e->getMessage() . ')';
            }
        }
    }
    flash('success', "일괄 매핑 완료: 성공 {$ok}건, 실패 {$fail}건");
    if ($errors) flash('bulk_errors', implode("\n", array_slice($errors, 0, 20)));
    redirect('/admin/ecount-mapping.php');
}

/* 목록 조회 (매핑 안 된 상품 우선, 검색 지원) */
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (p.name LIKE :q OR p.size LIKE :q OR p.ecount_item_cd LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM tt_products p WHERE {$where}")->execute($params) ?: 0;
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tt_products p WHERE {$where}");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();

$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.size, p.stock, p.ecount_item_cd, p.ecount_wh_cd
    FROM tt_products p
    WHERE {$where}
    ORDER BY (p.ecount_item_cd IS NULL) DESC, p.id DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $perPage));
include __DIR__ . '/includes/header.php';
?>
<div class="admin-wrap">
  <h2>이카운트 품목코드 매핑</h2>

  <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('bulk_errors')): ?><pre class="alert alert-warning"><?= h($msg) ?></pre><?php endif; ?>

  <form method="post" enctype="multipart/form-data" style="margin-bottom:20px;">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="bulk_csv">
    <label>CSV 일괄 업로드 (헤더: 상품ID,이카운트품목코드,이카운트창고코드)</label>
    <input type="file" name="csv" accept=".csv" required>
    <button type="submit">일괄 매핑 업로드</button>
  </form>

  <form method="get" style="margin-bottom:12px;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="상품명/사이즈/품목코드 검색">
    <button type="submit">검색</button>
  </form>

  <table class="admin-table">
    <thead>
      <tr><th>상품ID</th><th>상품명</th><th>사이즈</th><th>재고</th><th>이카운트 품목코드</th><th>창고코드</th><th>저장</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['name']) ?></td>
        <td><?= h($r['size'] ?? '') ?></td>
        <td><?= (int)$r['stock'] ?></td>
        <td colspan="3">
          <form method="post" style="display:flex; gap:8px; align-items:center;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_one">
            <input type="hidden" name="product_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="page" value="<?= $page ?>">
            <input type="text" name="ecount_item_cd" value="<?= h($r['ecount_item_cd'] ?? '') ?>" placeholder="품목코드">
            <input type="text" name="ecount_wh_cd" value="<?= h($r['ecount_wh_cd'] ?? '100') ?>" placeholder="창고코드" style="width:80px;">
            <button type="submit">저장</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7">상품이 없습니다.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <div class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?page=<?= $p ?>&q=<?= urlencode($q) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
