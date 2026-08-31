<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('reviews');
ensure_review_extra_columns();

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

/* 리뷰 태그(옵션) 관리 — 추가 / 활성화 토글 / 삭제 */
if (is_post() && ($_POST['form_type'] ?? '') === 'add_review_tag') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/reviews.php');
    }
    $label = trim((string)($_POST['label'] ?? ''));
    if ($label === '' || mb_strlen($label) > 30) {
        flash('admin_error', '태그 문구는 1~30자로 입력해 주세요.');
        redirect('/admin/reviews.php');
    }
    try {
        $maxSort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM tt_review_option_tags')->fetchColumn();
        $pdo->prepare('INSERT INTO tt_review_option_tags (label, is_active, sort_order) VALUES (:label, 1, :sort)')
            ->execute(['label' => $label, 'sort' => $maxSort + 1]);
        flash('admin_success', "태그 '{$label}' 을 추가했습니다.");
    } catch (Throwable $e) {
        flash('admin_error', '이미 존재하는 태그이거나 추가 중 오류가 발생했습니다.');
    }
    redirect('/admin/reviews.php');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_review_tag') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/reviews.php');
    }
    $tagId = (int)($_POST['tag_id'] ?? 0);
    $pdo->prepare('UPDATE tt_review_option_tags SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $tagId]);
    flash('admin_success', '태그 상태를 변경했습니다.');
    redirect('/admin/reviews.php');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_review_tag') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/reviews.php');
    }
    $tagId = (int)($_POST['tag_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_review_option_tags WHERE id = :id')->execute(['id' => $tagId]);
    flash('admin_success', '태그를 삭제했습니다.');
    redirect('/admin/reviews.php');
}

$reviews = $pdo->query("
    SELECT r.id, r.rating, r.content, r.option_tags, r.created_at,
           p.id AS product_id, p.name AS product_name,
           u.name AS user_name, u.email AS user_email
    FROM tt_reviews r
    JOIN tt_products p ON p.id = r.product_id
    JOIN tt_users u ON u.id = r.user_id
    ORDER BY r.created_at DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$reviewTags = review_option_tag_options_admin();

$pageTitle = '리뷰 관리';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card" style="margin-bottom:24px;">
  <h2 class="admin-page-title">리뷰 옵션 태그 관리</h2>
  <p class="admin-text-sub" style="margin:-6px 0 16px;">
    리뷰 작성 모달에서 고객이 선택할 수 있는 "승차감이 편안해요" 같은 문구를 여기서 추가·삭제·비활성화할 수 있습니다.
  </p>

  <form method="post" style="display:flex;gap:8px;margin-bottom:18px;">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_type" value="add_review_tag">
    <input type="text" name="label" maxlength="30" placeholder="예) 승차감이 편안해요" required
           style="flex:1;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;">
    <button type="submit" class="btn-admin-primary" style="padding:9px 18px;">+ 태그 추가</button>
  </form>

  <table class="admin-table-trendy">
    <thead>
      <tr><th style="width:60px">순서</th><th>태그 문구</th><th style="width:100px">상태</th><th style="width:160px"></th></tr>
    </thead>
    <tbody>
    <?php if (empty($reviewTags)): ?>
      <tr><td colspan="4" class="admin-empty-row">등록된 태그가 없습니다.</td></tr>
    <?php else: foreach ($reviewTags as $tag): ?>
      <tr>
        <td class="mono"><?= (int)$tag['sort_order'] ?></td>
        <td><strong><?= h($tag['label']) ?></strong></td>
        <td>
          <?php if ((int)$tag['is_active'] === 1): ?>
            <span class="admin-role-badge" style="background:#f0fdf4;color:#16a34a;">사용중</span>
          <?php else: ?>
            <span class="admin-role-badge" style="background:#f1f5f9;color:#94a3b8;">숨김</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="post" style="display:inline-block;margin-right:6px;">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="toggle_review_tag">
            <input type="hidden" name="tag_id" value="<?= (int)$tag['id'] ?>">
            <button type="submit" class="btn-admin-ghost" style="padding:4px 10px;font-size:12px;">
              <?= (int)$tag['is_active'] === 1 ? '숨기기' : '노출하기' ?>
            </button>
          </form>
          <form method="post" style="display:inline-block;" onsubmit="return confirm('이 태그를 삭제하시겠습니까? 이미 등록된 리뷰의 태그 표시는 유지됩니다.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="delete_review_tag">
            <input type="hidden" name="tag_id" value="<?= (int)$tag['id'] ?>">
            <button type="submit" class="btn-admin-danger" style="padding:4px 10px;font-size:12px;">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="admin-card">
  <h2 class="admin-page-title">리뷰 관리 <span class="admin-count-pill"><?= count($reviews) ?>건</span></h2>

  <table class="admin-table-trendy">
    <thead>
      <tr><th style="width:60px">별점</th><th>상품</th><th>내용 / 태그</th><th style="width:140px">작성자</th><th style="width:100px">작성일</th><th style="width:80px"></th></tr>
    </thead>
    <tbody>
    <?php if (empty($reviews)): ?>
      <tr><td colspan="6" class="admin-empty-row">등록된 리뷰가 없습니다.</td></tr>
    <?php else: foreach ($reviews as $rv): ?>
      <tr>
        <td class="mono"><?= str_repeat('★', (int)$rv['rating']) ?></td>
        <td><a href="<?= BASE_URL ?>/product-detail.php?id=<?= (int)$rv['product_id'] ?>" target="_blank"><?= h($rv['product_name']) ?></a></td>
        <td>
          <?= h(mb_strimwidth($rv['content'], 0, 60, '...')) ?>
          <?php $tags = review_parse_option_tags($rv['option_tags'] ?? null); if (!empty($tags)): ?>
            <div style="margin-top:4px;">
              <?php foreach ($tags as $t): ?>
                <span style="display:inline-block;background:#f0f0ff;color:#6366f1;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;margin-right:4px;"><?= h($t) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </td>
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
