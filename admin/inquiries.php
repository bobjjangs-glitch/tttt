<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('inquiries');
$pdo = Database::connection();

// 테이블이 없으면 생성
try {
    $pdo->query("SELECT id FROM tt_inquiries LIMIT 1");
} catch (Throwable $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_inquiries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        name VARCHAR(50) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        email VARCHAR(191) NULL,
        category VARCHAR(30) NOT NULL,
        subject VARCHAR(150) NOT NULL,
        content TEXT NOT NULL,
        status ENUM('pending','answered','closed') NOT NULL DEFAULT 'pending',
        answer TEXT NULL,
        answered_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
}

// 답변 등록
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/inquiries.php');
    }
    $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
    $answer = trim($_POST['answer'] ?? '');

    if ($inquiryId > 0 && $answer !== '') {
        $stmt = $pdo->prepare('UPDATE tt_inquiries SET answer = :answer, answered_at = NOW(), status = "answered" WHERE id = :id');
        $stmt->execute(['answer' => $answer, 'id' => $inquiryId]);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'inquiry_answer', '1:1문의 답변 #' . $inquiryId);
        flash('admin_success', '답변이 등록되었습니다.');
    }
    redirect('/admin/inquiries.php');
}

// 필터
$filter = $_GET['status'] ?? 'all';
$validFilters = ['all', 'pending', 'answered', 'closed'];
if (!in_array($filter, $validFilters, true)) $filter = 'all';

$whereSql = '';
if ($filter !== 'all') {
    $whereSql = 'WHERE status = ' . $pdo->quote($filter);
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM tt_inquiries {$whereSql}")->fetchColumn();

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->query("SELECT * FROM tt_inquiries {$whereSql} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$inquiries = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'pending'  => '답변대기',
    'answered' => '답변완료',
    'closed'   => '종료',
];
$statusColors = [
    'pending'  => '#f59e0b',
    'answered' => '#16a34a',
    'closed'   => '#64748b',
];
$categoryLabels = [
    'product'  => '상품 문의',
    'order'    => '주문/결제',
    'shipping' => '배송',
    'return'   => '교환/반품',
    'member'   => '회원/계정',
    'business' => '사업자 관련',
    'etc'      => '기타',
];

$pageTitle = '1:1 문의 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">1:1 문의 관리</h2>
      <p class="admin-list-sub">고객센터에서 접수된 문의를 관리합니다.</p>
    </div>
    <div class="admin-filter-tabs">
      <a href="?status=all" class="admin-filter-tab <?= $filter==='all'?'active':'' ?>">전체</a>
      <a href="?status=pending" class="admin-filter-tab <?= $filter==='pending'?'active':'' ?>">답변대기</a>
      <a href="?status=answered" class="admin-filter-tab <?= $filter==='answered'?'active':'' ?>">답변완료</a>
      <a href="?status=closed" class="admin-filter-tab <?= $filter==='closed'?'active':'' ?>">종료</a>
    </div>
  </div>

  <?php if (empty($inquiries)): ?>
    <div class="admin-empty">문의 내역이 없습니다.</div>
  <?php else: ?>
    <div class="admin-inquiry-list">
      <?php foreach ($inquiries as $inq): ?>
      <div class="admin-inquiry-item">
        <div class="admin-inquiry-head">
          <span class="admin-inquiry-cat"><?= h($categoryLabels[$inq['category']] ?? $inq['category']) ?></span>
          <span class="admin-inquiry-status" style="background:<?= $statusColors[$inq['status']] ?>20;color:<?= $statusColors[$inq['status']] ?>">
            <?= h($statusLabels[$inq['status']] ?? $inq['status']) ?>
          </span>
          <span class="admin-inquiry-date"><?= h(date('Y.m.d H:i', strtotime($inq['created_at']))) ?></span>
        </div>
        <h3 class="admin-inquiry-subject"><?= h($inq['subject']) ?></h3>
        <div class="admin-inquiry-meta">
          <span><?= h($inq['name']) ?></span>
          <span><?= h($inq['phone']) ?></span>
          <?php if (!empty($inq['email'])): ?><span><?= h($inq['email']) ?></span><?php endif; ?>
        </div>
        <p class="admin-inquiry-content"><?= nl2br(h($inq['content'])) ?></p>

        <?php if (!empty($inq['answer'])): ?>
          <div class="admin-inquiry-answer">
            <strong>답변</strong>
            <p><?= nl2br(h($inq['answer'])) ?></p>
            <span class="admin-inquiry-answer-date"><?= h(date('Y.m.d H:i', strtotime($inq['answered_at']))) ?></span>
          </div>
        <?php elseif ($inq['status'] === 'pending'): ?>
          <form method="post" class="admin-inquiry-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="inquiry_id" value="<?= (int)$inq['id'] ?>">
            <textarea name="answer" rows="3" placeholder="답변을 입력하세요" required></textarea>
            <button type="submit" class="btn-admin-primary">답변 등록</button>
          </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?status=<?= h($filter) ?>&page=<?= $p ?>" class="<?= $p===$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
