<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
$pdo = Database::connection();

/**
 * [FIX-3] tt_stock_requests 테이블 스키마 자동 보강 (완전 독립 버전).
 *
 * 지금까지의 문제 원인: 예전 버전은 CREATE TABLE IF NOT EXISTS만 사용했다.
 * 이 명령어는 "테이블이 존재하지 않을 때만" 실행되기 때문에, 만약 아주 예전에
 * admin_memo / processed_by / processed_at / status 같은 컬럼이 없는 구조로
 * tt_stock_requests 테이블이 이미 한 번 만들어져 있었다면, 그 뒤로 코드를 몇 번을
 * 고쳐도 실제 DB 컬럼 구조는 절대 바뀌지 않는다. 그 상태에서 상태 변경 UPDATE 문이
 * 존재하지 않는 컬럼을 참조하면 "Unknown column 'xxx' in 'field list'" 오류가
 * 발생하고, 이게 그대로 화면에 "상태 저장 중 오류"로 나타난 것이다.
 *
 * 이번 수정: 테이블이 없으면 새로 만들고, 있으면 SHOW COLUMNS로 실제 컬럼을 확인해서
 * 부족한 컬럼만 정확히 찾아 ALTER TABLE ADD COLUMN으로 추가한다.
 * 기존 데이터는 전혀 손대지 않으므로 완전히 안전하다.
 *
 * [주의] 함수 이름을 다른 파일(core/functions.php 등)과 절대 겹치지 않도록
 * srpage_ 접두사를 붙였다. 혹시 core/functions.php에 과거에 안내했던
 * ensure_stock_requests_table() 같은 이름의 함수를 추가하신 적이 있다면
 * 그 함수와는 이름이 달라서 "함수 중복 선언" 치명적 오류가 나지 않는다.
 */
function srpage_ensure_stock_requests_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tt_stock_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT NULL COMMENT '연결된 상품ID (없으면 NULL)',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $existingCols = $pdo->query("SHOW COLUMNS FROM tt_stock_requests")->fetchAll(PDO::FETCH_COLUMN);

    $columnDefs = [
        'brand_text'     => "VARCHAR(100) NULL COMMENT '요청 브랜드(자유입력)'",
        'size_text'      => "VARCHAR(60) NOT NULL DEFAULT '' COMMENT '요청 사이즈'",
        'requested_qty'  => "INT NOT NULL DEFAULT 1 COMMENT '요청 수량'",
        'customer_name'  => "VARCHAR(50) NOT NULL DEFAULT '' COMMENT '주문자명'",
        'customer_phone' => "VARCHAR(20) NOT NULL DEFAULT '' COMMENT '주문자 연락처'",
        'customer_email' => "VARCHAR(120) NULL COMMENT '주문자 이메일'",
        'memo'           => "TEXT NULL COMMENT '고객 요청 메모'",
        'status'         => "ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending' COMMENT '처리 상태'",
        'admin_memo'     => "TEXT NULL COMMENT '관리자 처리 메모'",
        'processed_by'   => "INT NULL COMMENT '처리한 관리자 ID'",
        'processed_at'   => "DATETIME NULL COMMENT '처리 완료 시각'",
        'ip_address'     => "VARCHAR(45) NULL COMMENT '요청자 IP'",
    ];

    foreach ($columnDefs as $col => $def) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec("ALTER TABLE tt_stock_requests ADD COLUMN {$col} {$def}");
            error_log("[srpage_ensure_stock_requests_schema] 누락된 컬럼 '{$col}' 을 추가했습니다.");
        }
    }

    // status 컬럼이 예전부터 있었는데 ENUM 값 목록이 부족했을 가능성까지 대비해 강제로 최신화한다.
    try {
        $pdo->exec("ALTER TABLE tt_stock_requests MODIFY COLUMN status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending' COMMENT '처리 상태'");
    } catch (Throwable $e) {
        error_log('[srpage_ensure_stock_requests_schema] status MODIFY 실패: ' . $e->getMessage());
    }

    try { $pdo->exec("ALTER TABLE tt_stock_requests ADD INDEX idx_status (status)"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tt_stock_requests ADD INDEX idx_product (product_id)"); } catch (Throwable $e) {}
}
srpage_ensure_stock_requests_schema($pdo);

/**
 * [FIX] tt_admin_logs 테이블도 없을 수 있으므로 여기서 함께 보강한다.
 * AdminAuth::log()가 이 테이블에 INSERT하는데, 테이블이 없으면 로그 기록 단계에서
 * 예외가 발생해 상태 변경 자체가 실패로 이어질 수 있다.
 */
function srpage_ensure_admin_logs_table(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tt_admin_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                memo TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('[srpage_ensure_admin_logs_table] ' . $e->getMessage());
    }
}
srpage_ensure_admin_logs_table($pdo);

const STOCK_REQUEST_STATUS_LABELS = [
    'pending'    => '대기',
    'processing' => '처리중',
    'done'       => '완료',
    'cancelled'  => '취소',
];

// 상태 변경 / 관리자 메모 저장 처리
// [FIX] 반드시 try/catch로 감싸서, 어떤 DB 오류가 나도 500 백지 화면이 아니라
// 화면 상단에 실제 오류 원인이 나타나게 한다. 원인이 확실히 해결됐다고 검증되면
// 이후에는 상세 메시지를 감추고 일반 문구로 되돌려도 된다.
if (is_post() && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다. 새로고침 후 다시 시도해 주세요.');
        redirect('/admin/stock-requests.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $adminMemo = trim((string)($_POST['admin_memo'] ?? ''));

    if ($id <= 0 || !isset(STOCK_REQUEST_STATUS_LABELS[$status])) {
        flash('admin_error', '요청 상태 값이 올바르지 않습니다.');
        redirect('/admin/stock-requests.php');
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE tt_stock_requests
            SET status = :status,
                admin_memo = :memo,
                processed_by = :admin_id,
                processed_at = CASE WHEN :status2 IN ('done','cancelled') THEN NOW() ELSE processed_at END
            WHERE id = :id
        ");
        $stmt->execute([
            'status'   => $status,
            'status2'  => $status,
            'memo'     => $adminMemo !== '' ? $adminMemo : null,
            'admin_id' => (int)AdminAuth::currentAdminId(),
            'id'       => $id,
        ]);

        try {
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'stock_request_update', "재고요청 #{$id} 상태를 '{$status}'로 변경");
        } catch (Throwable $logErr) {
            // 로그 기록 실패는 상태 변경 자체를 무효화하면 안 되므로 조용히 기록만 남긴다.
            error_log('[admin/stock-requests log] ' . $logErr->getMessage());
        }

        flash('admin_success', "요청 #{$id} 처리 상태가 저장되었습니다.");
    } catch (Throwable $e) {
        error_log('[admin/stock-requests update_status] ' . $e->getMessage());
        // [임시 진단 조치] 원인이 확실히 해결됐음이 확인되면 아래 메시지를
        // '상태 저장 중 오류가 발생했습니다. 관리자에게 문의해 주세요.' 로 되돌리는 것을 권장한다.
        flash('admin_error', '상태 저장 중 오류: ' . $e->getMessage());
    }

    redirect('/admin/stock-requests.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

// 목록 조회 (상태 필터 지원)
$filterStatus = $_GET['status'] ?? '';
$where = '';
$params = [];
if ($filterStatus !== '' && isset(STOCK_REQUEST_STATUS_LABELS[$filterStatus])) {
    $where = 'WHERE r.status = :status';
    $params['status'] = $filterStatus;
}

try {
    $sql = "
        SELECT r.*, p.name AS product_name
        FROM tt_stock_requests r
        LEFT JOIN tt_products p ON p.id = r.product_id
        {$where}
        ORDER BY r.created_at DESC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    $countStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM tt_stock_requests GROUP BY status");
    $statusCounts = ['pending'=>0,'processing'=>0,'done'=>0,'cancelled'=>0];
    foreach ($countStmt->fetchAll() as $c) $statusCounts[$c['status']] = (int)$c['cnt'];
} catch (Throwable $e) {
    error_log('[admin/stock-requests list] ' . $e->getMessage());
    $requests = [];
    $statusCounts = ['pending'=>0,'processing'=>0,'done'=>0,'cancelled'=>0];
    flash('admin_error', '목록을 불러오는 중 오류: ' . $e->getMessage());
}

// 상태별 색상/아이콘 (이 파일 안에만 있는 지역 배열 — 트렌디 UI용)
$statusMeta = [
    'pending'    => ['label' => '대기',   'color' => '#f59e0b', 'bg' => '#fff7ed', 'icon' => '⏳'],
    'processing' => ['label' => '처리중', 'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => '🔧'],
    'done'       => ['label' => '완료',   'color' => '#22c55e', 'bg' => '#f0fdf4', 'icon' => '✅'],
    'cancelled'  => ['label' => '취소',   'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => '✕'],
];

$pageTitle = '재고 요청 관리';
require __DIR__ . '/includes/header.php';
?>

<style>
/* ============ 재고 요청 관리 — 트렌디 UI (이 페이지 전용, 다른 화면 영향 없음) ============ */
.sr-hero {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 55%, #a855f7 100%);
    border-radius: 18px;
    padding: 28px 30px;
    color: #fff;
    box-shadow: 0 10px 30px -10px rgba(79,70,229,.5);
    margin-bottom: 20px;
}
.sr-hero h2 { margin: 0 0 6px; font-size: 22px; font-weight: 800; letter-spacing: -0.3px; }
.sr-hero p  { margin: 0; opacity: .9; font-size: 13.5px; }
.sr-filter-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 18px; }
.sr-filter-chip {
    padding: 7px 16px; border-radius: 999px; font-size: 13px; font-weight: 600;
    background: rgba(255,255,255,.16); color: #fff; text-decoration: none;
    border: 1px solid rgba(255,255,255,.3); transition: all .15s ease;
}
.sr-filter-chip:hover { background: rgba(255,255,255,.28); }
.sr-filter-chip.active { background: #fff; color: #4f46e5; border-color: #fff; }

.sr-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
.sr-card {
    background: #fff; border: 1px solid #eef0f4; border-radius: 16px;
    padding: 18px 20px; box-shadow: 0 2px 10px -4px rgba(0,0,0,.06);
    transition: box-shadow .15s ease, transform .15s ease;
}
.sr-card:hover { box-shadow: 0 8px 24px -6px rgba(0,0,0,.12); transform: translateY(-2px); }
.sr-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.sr-badge {
    display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700;
    padding: 4px 10px; border-radius: 999px;
}
.sr-time { font-size: 11.5px; color: #9aa0ac; margin-top: 4px; }
.sr-name { font-size: 16px; font-weight: 800; color: #1f2330; margin: 2px 0; }
.sr-phone a { color: #4f46e5; font-weight: 600; text-decoration: none; font-size: 13.5px; }
.sr-email { color: #6b7280; font-size: 12.5px; }
.sr-product-box {
    background: #f8f9fc; border-radius: 10px; padding: 10px 12px; margin: 12px 0;
    font-size: 13px; color: #374151;
}
.sr-product-box b { color: #111827; }
.sr-unregistered { color: #b45309; font-size: 11.5px; font-weight: 600; margin-top: 4px; }
.sr-qty { display: inline-block; background: #eef2ff; color: #4338ca; font-weight: 700; font-size: 12px; padding: 2px 8px; border-radius: 6px; }
.sr-memo { font-size: 12.5px; color: #6b7280; margin: 8px 0; white-space: pre-wrap; }
.sr-form { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.sr-form select {
    flex: 0 0 110px; border: 1px solid #e0e3e8; border-radius: 8px; padding: 7px 8px; font-size: 12.5px;
}
.sr-form textarea {
    flex: 1 1 140px; border: 1px solid #e0e3e8; border-radius: 8px; padding: 7px 10px; font-size: 12.5px; resize: vertical; min-height: 34px;
}
.sr-form button {
    background: linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff; border:none; border-radius: 8px;
    padding: 8px 16px; font-size: 12.5px; font-weight: 700; cursor: pointer; transition: opacity .15s;
}
.sr-form button:hover { opacity: .88; }
.sr-empty { text-align: center; padding: 60px 20px; color: #9aa0ac; font-size: 14px; }
</style>

<div class="sr-hero">
  <h2>📦 재고 요청 관리</h2>
  <p>회원이 상품 목록/상세페이지에서 접수한 재고 요청입니다. 회원가입 정보(이름·연락처·이메일)와 요청 상품을 확인하고 처리 상태를 관리하세요.</p>
  <div class="sr-filter-row">
    <a href="?status=" class="sr-filter-chip <?= $filterStatus==='' ? 'active' : '' ?>">전체 (<?= array_sum($statusCounts) ?>)</a>
    <?php foreach (STOCK_REQUEST_STATUS_LABELS as $key => $label): ?>
      <a href="?status=<?= $key ?>" class="sr-filter-chip <?= $filterStatus===$key ? 'active' : '' ?>">
        <?= $statusMeta[$key]['icon'] ?> <?= $label ?> (<?= $statusCounts[$key] ?>)
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (empty($requests)): ?>
  <div class="sr-empty">해당 조건의 재고 요청이 없습니다.</div>
<?php else: ?>
<div class="sr-card-grid">
  <?php foreach ($requests as $r):
      $meta = $statusMeta[$r['status']] ?? $statusMeta['pending'];
  ?>
  <div class="sr-card">
    <div class="sr-card-top">
      <div>
        <div class="sr-name"><?= h($r['customer_name']) ?></div>
        <div class="sr-phone"><a href="tel:<?= h($r['customer_phone']) ?>">📞 <?= h($r['customer_phone']) ?></a></div>
        <?php if (!empty($r['customer_email'])): ?>
          <div class="sr-email">✉️ <?= h($r['customer_email']) ?></div>
        <?php endif; ?>
      </div>
      <div style="text-align:right;">
        <span class="sr-badge" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;"><?= $meta['icon'] ?> <?= $meta['label'] ?></span>
        <div class="sr-time"><?= h($r['created_at']) ?></div>
      </div>
    </div>

    <div class="sr-product-box">
      <?php if (!empty($r['product_name'])): ?>
        <b><?= h($r['product_name']) ?></b> <span style="color:#9aa0ac;">#<?= (int)$r['product_id'] ?></span>
      <?php else: ?>
        <?= !empty($r['brand_text']) ? h($r['brand_text']) . ' ' : '' ?><?= h($r['size_text']) ?>
        <div class="sr-unregistered">⚠ 미등록 상품</div>
      <?php endif; ?>
      <div style="margin-top:6px;">요청 수량 <span class="sr-qty"><?= (int)$r['requested_qty'] ?>개</span></div>
    </div>

    <?php if (!empty($r['memo'])): ?>
      <div class="sr-memo">💬 <?= nl2br(h($r['memo'])) ?></div>
    <?php endif; ?>

    <form method="post" class="sr-form">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <select name="status">
        <?php foreach (STOCK_REQUEST_STATUS_LABELS as $key => $label): ?>
          <option value="<?= $key ?>" <?= $r['status']===$key ? 'selected' : '' ?>><?= $statusMeta[$key]['icon'] ?> <?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <textarea name="admin_memo" placeholder="처리 메모"><?= h($r['admin_memo'] ?? '') ?></textarea>
      <button type="submit">저장</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
