<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('reviews');

$pdo = Database::connection();

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_review') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/reviews.php');
    }
    $reviewId = (int)($_POST['review_id'] ?? 0);

    try {
        $pdo->beginTransaction();
        $findStmt = $pdo->prepare('SELECT product_id FROM tt_reviews WHERE id = :id');
        $findStmt->execute(['id' => $reviewId]);
        $target = $findStmt->fetch();

        if ($target) {
            $pdo->prepare('DELETE FROM tt_reviews WHERE id = :id')->execute(['id' => $reviewId]);
            $pdo->prepare('
                UPDATE tt_products p
                SET review_count = (SELECT COUNT(*) FROM tt_reviews WHERE product_id = p.id),
                    rating_avg   = (SELECT COALESCE(AVG(rating),0) FROM tt_reviews WHERE product_id = p.id)
                WHERE p.id = :pid
            ')->execute(['pid' => $target['product_id']]);

            AdminAuth::log((int)AdminAuth::currentAdminId(), 'review_delete', "리뷰#{$reviewId} 삭제");
            flash('admin_success', '리뷰를 삭제했습니다.');
        } else {
            flash('admin_error', '이미 삭제된 리뷰입니다.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[admin/reviews] ' . $e->getMessage());
        flash('admin_error', '삭제 중 오류가 발생했습니다.');
    }
    redirect('/admin/reviews.php');
}

$reviews = $pdo->query("
    SELECT r.id, r.rating, r.content, r.created_at,
           p.id AS product_id, p.name AS product_name,
           u.name AS user_name, u.email AS user_email
    FROM tt_reviews r
    JOIN tt_products p ON p.id = r.product_id
    JOIN tt_users u ON u.id = r.user_id
    ORDER BY r.created_at DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = '리뷰 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-page-title">리뷰 관리 <span class="admin-count-pill"><?= count($reviews) ?>건</span></h2>

  <table class="admin-table-trendy">
    <thead>
      <tr><th style="width:60px">별점</th><th>상품</th><th>내용</th><th style="width:140px">작성자</th><th style="width:100px">작성일</th><th style="width:80px"></th></tr>
    </thead>
    <tbody>
    <?php if (empty($reviews)): ?>
      <tr><td colspan="6" class="admin-empty-row">등록된 리뷰가 없습니다.</td></tr>
    <?php else: foreach ($reviews as $rv): ?>
      <tr>
        <td class="mono"><?= str_repeat('★', (int)$rv['rating']) ?></td>
        <td><a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$rv['product_id'] ?>" target="_blank"><?= h($rv['product_name']) ?></a></td>
        <td><?= h(mb_strimwidth($rv['content'], 0, 60, '...')) ?></td>
        <td><?= h($rv['user_name']) ?> (<?= h($rv['user_email']) ?>)</td>
        <td class="admin-text-sub"><?= h(date('Y-m-d', strtotime($rv['created_at']))) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('이 리뷰를 삭제하시겠습니까?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="delete_review">
            <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
            <button type="submit" class="btn-admin-danger" style="padding:4px 10px;font-size:12px;">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
