<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = Database::connection();

/* =========================================================
 * 0. 권한 체크
 *    - 'products' 아니라 'stock-requests' 권한이어야 정상 동작
 * ========================================================= */
AdminAuth::requirePermission('stock-requests');
$currentAdmin   = AdminAuth::user(); // ['id'=>.., 'name'=>.., 'role'=>..] 형태 가정
$currentAdminId = isset($currentAdmin['id']) ? (int)$currentAdmin['id'] : null;

/* =========================================================
 * 1. 스키마 안전장치 - 실제 운영 테이블 구조 기준
 *    (이미 있는 컬럼은 절대 건드리지 않고, 없는 것만 추가)
 * ========================================================= */
function sr_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function sr_ensure_stock_requests_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_stock_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        dot_code VARCHAR(20) NULL,
        qty INT NOT NULL DEFAULT 1,
        phone VARCHAR(20) NULL,
        status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [
        'requested_qty'  => "ALTER TABLE tt_stock_requests ADD COLUMN requested_qty INT NOT NULL DEFAULT 1 COMMENT '요청 수량'",
        'customer_name'  => "ALTER TABLE tt_stock_requests ADD COLUMN customer_name VARCHAR(50) NULL COMMENT '주문자명'",
        'customer_phone' => "ALTER TABLE tt_stock_requests ADD COLUMN customer_phone VARCHAR(20) NULL COMMENT '주문자 연락처'",
        'customer_email' => "ALTER TABLE tt_stock_requests ADD COLUMN customer_email VARCHAR(120) NULL COMMENT '주문자 이메일'",
        'brand_text'     => "ALTER TABLE tt_stock_requests ADD COLUMN brand_text VARCHAR(100) NULL COMMENT '요청 브랜드'",
        'size_text'      => "ALTER TABLE tt_stock_requests ADD COLUMN size_text VARCHAR(60) NOT NULL DEFAULT '' COMMENT '요청 사이즈'",
        'memo'           => "ALTER TABLE tt_stock_requests ADD COLUMN memo TEXT NULL COMMENT '고객 메모'",
        'admin_memo'     => "ALTER TABLE tt_stock_requests ADD COLUMN admin_memo TEXT NULL COMMENT '관리자 메모'",
        'processed_by'   => "ALTER TABLE tt_stock_requests ADD COLUMN processed_by INT NULL COMMENT '처리 관리자 ID'",
        'processed_at'   => "ALTER TABLE tt_stock_requests ADD COLUMN processed_at DATETIME NULL COMMENT '처리 완료 시각'",
        'ip_address'     => "ALTER TABLE tt_stock_requests ADD COLUMN ip_address VARCHAR(45) NULL COMMENT '요청자 IP'",
        'deleted_at'     => "ALTER TABLE tt_stock_requests ADD COLUMN deleted_at DATETIME NULL COMMENT '소프트 삭제 시각(현재 미사용, 확장용)'",
    ];

    foreach ($columns as $name => $sql) {
        if (!sr_column_exists($pdo, 'tt_stock_requests', $name)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                error_log('[sr_ensure_stock_requests_schema] ' . $e->getMessage());
            }
        }
    }

    try { $pdo->exec("ALTER TABLE tt_stock_requests ADD INDEX idx_status (status)"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tt_stock_requests ADD INDEX idx_product (product_id)"); } catch (Throwable $e) {}
}

function sr_ensure_admin_logs_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_admin_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        action VARCHAR(50) NOT NULL,
        memo TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

sr_ensure_stock_requests_schema($pdo);
sr_ensure_admin_logs_table($pdo);

function sr_safe_log(?int $adminId, string $action, string $memo = ''): void
{
    if ($adminId === null) {
        return;
    }
    try {
        if (class_exists('AdminAuth') && method_exists('AdminAuth', 'log')) {
            AdminAuth::log($adminId, $action, $memo);
        }
    } catch (Throwable $e) {
        error_log('[sr_safe_log] ' . $e->getMessage());
    }
}

/* =========================================================
 * 2. 이 페이지 전용 CSRF 토큰 (다른 파일 함수와 절대 충돌 안 나게 분리)
 * ========================================================= */
if (empty($_SESSION['sr_csrf'])) {
    $_SESSION['sr_csrf'] = bin2hex(random_bytes(32));
}
$SR_CSRF = $_SESSION['sr_csrf'];

function sr_csrf_ok(): bool
{
    return isset($_POST['sr_csrf'], $_SESSION['sr_csrf'])
        && hash_equals($_SESSION['sr_csrf'], (string)$_POST['sr_csrf']);
}

/* =========================================================
 * 3. 상태 메타 정보 (라벨/색상)
 * ========================================================= */
const SR_STATUS_META = [
    'pending'    => ['label' => '대기',   'color' => '#f59e0b', 'bg' => '#fff7ed'],
    'processing' => ['label' => '처리중', 'color' => '#3b82f6', 'bg' => '#eff6ff'],
    'done'       => ['label' => '완료',   'color' => '#22c55e', 'bg' => '#f0fdf4'],
    'cancelled'  => ['label' => '취소',   'color' => '#ef4444', 'bg' => '#fef2f2'],
];

/* =========================================================
 * 4. POST 처리: 상태 변경 / 삭제(신규)
 * ========================================================= */
$flashSuccess = null;
$flashError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!sr_csrf_ok()) {
        $flashError = '보안 토큰이 유효하지 않습니다. 새로고침 후 다시 시도해 주세요.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id     = (int)($_POST['id'] ?? 0);

        /* ---------- 4-1. 상태 변경 ---------- */
        if ($action === 'update_status' && $id > 0) {
            $newStatus = (string)($_POST['status'] ?? '');
            $adminMemo = trim((string)($_POST['admin_memo'] ?? ''));

            if (!array_key_exists($newStatus, SR_STATUS_META)) {
                $flashError = '알 수 없는 상태 값입니다.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE tt_stock_requests
                         SET status = :status,
                             admin_memo = :memo,
                             processed_by = :admin_id,
                             processed_at = NOW()
                         WHERE id = :id"
                    );
                    $stmt->execute([
                        ':status'   => $newStatus,
                        ':memo'     => $adminMemo,
                        ':admin_id' => $currentAdminId,
                        ':id'       => $id,
                    ]);
                    sr_safe_log($currentAdminId, 'stock_request_status', "요청 #{$id} 상태를 '{$newStatus}'로 변경");
                    $flashSuccess = "요청 #{$id} 상태가 저장되었습니다.";
                } catch (Throwable $e) {
                    error_log('[admin/stock-requests update_status] ' . $e->getMessage());
                    $flashError = '상태 저장 중 오류: ' . $e->getMessage();
                }
            }
        }

        /* ---------- 4-2. 삭제 처리 (신규 기능) ---------- */
        if ($action === 'delete' && $id > 0) {
            try {
                $pdo->beginTransaction();

                $info = $pdo->prepare(
                    "SELECT customer_name, customer_phone, phone, brand_text, size_text
                     FROM tt_stock_requests WHERE id = :id"
                );
                $info->execute([':id' => $id]);
                $row = $info->fetch(PDO::FETCH_ASSOC);

                $del = $pdo->prepare("DELETE FROM tt_stock_requests WHERE id = :id");
                $del->execute([':id' => $id]);

                $pdo->commit();

                if ($row) {
                    $phone   = $row['customer_phone'] ?: $row['phone'];
                    $summary = trim(($row['customer_name'] ?? '') . ' / ' . $phone . ' / ' . ($row['brand_text'] ?? '') . ' ' . ($row['size_text'] ?? ''));
                    sr_safe_log($currentAdminId, 'stock_request_delete', "요청 #{$id} 삭제 ({$summary})");
                }
                $flashSuccess = "요청 #{$id}이(가) 삭제되었습니다.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[admin/stock-requests delete] ' . $e->getMessage());
                $flashError = '삭제 중 오류: ' . $e->getMessage();
            }
        }
    }

    // 중복 제출 방지를 위해 같은 화면으로 리다이렉트 (플래시 메시지는 세션에 잠깐 보관)
    $_SESSION['sr_flash_success'] = $flashSuccess;
    $_SESSION['sr_flash_error']   = $flashError;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . $qs : ''));
    exit;
}

if (array_key_exists('sr_flash_success', $_SESSION) || array_key_exists('sr_flash_error', $_SESSION)) {
    $flashSuccess = $_SESSION['sr_flash_success'] ?? null;
    $flashError   = $_SESSION['sr_flash_error'] ?? null;
    unset($_SESSION['sr_flash_success'], $_SESSION['sr_flash_error']);
}

/* =========================================================
 * 5. 목록 조회 (필터 + 검색 + 페이지네이션)
 * ========================================================= */
$statusFilter = (string)($_GET['status'] ?? 'all');
$keyword      = trim((string)($_GET['q'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($statusFilter !== 'all' && array_key_exists($statusFilter, SR_STATUS_META)) {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
if ($keyword !== '') {
    $where[] = '(customer_name LIKE :kw OR customer_phone LIKE :kw OR phone LIKE :kw OR brand_text LIKE :kw)';
    $params[':kw'] = '%' . $keyword . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_stock_requests {$whereSql}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$listSql = "SELECT id, user_id, product_id, dot_code,
                   COALESCE(NULLIF(requested_qty,0), qty, 1) AS display_qty,
                   COALESCE(customer_phone, phone) AS display_phone,
                   customer_name, customer_email, brand_text, size_text, memo,
                   status, admin_memo, processed_by, processed_at, ip_address, created_at
            FROM tt_stock_requests
            {$whereSql}
            ORDER BY
               CASE status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END,
               created_at DESC
            LIMIT :limit OFFSET :offset";

$listStmt = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$requests = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statCounts = ['pending' => 0, 'processing' => 0, 'done' => 0, 'cancelled' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) cnt FROM tt_stock_requests GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $statCounts[$r['status']] = (int)$r['cnt'];
}

$pageTitle  = '재고요청 관리';
$activeMenu = 'stock-requests';
require_once __DIR__ . '/includes/admin_layout_top.php';
?>
<style>
:root {
    --sr-radius: 14px;
}
.sr-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 60px; }
.sr-hero {
    background: linear-gradient(135deg, #4f46e5, #6366f1 60%, #818cf8);
    color: #fff; border-radius: var(--sr-radius);
    padding: 28px 32px; margin-bottom: 24px;
    box-shadow: 0 10px 30px rgba(79,70,229,.25);
}
.sr-hero h1 { margin: 0 0 6px; font-size: 22px; font-weight: 700; }
.sr-hero p { margin: 0; opacity: .9; font-size: 14px; }

.sr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px,1fr)); gap: 12px; margin-bottom: 24px; }
.sr-stat-card {
    background: #fff; border-radius: var(--sr-radius); padding: 16px;
    box-shadow: 0 2px 10px rgba(0,0,0,.06); border-left: 5px solid var(--c);
}
.sr-stat-card .num { font-size: 24px; font-weight: 800; }
.sr-stat-card .lbl { font-size: 13px; color: #6b7280; margin-top: 2px; }

.sr-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 18px; }
.sr-chip {
    padding: 7px 16px; border-radius: 999px; font-size: 13px; font-weight: 600;
    text-decoration: none; color: #374151; background: #f3f4f6; transition: all .15s;
}
.sr-chip.active { background: #4f46e5; color: #fff; box-shadow: 0 4px 10px rgba(79,70,229,.3); }
.sr-chip:hover { transform: translateY(-1px); }

.sr-search { margin-left: auto; display: flex; gap: 6px; }
.sr-search input {
    border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 12px; font-size: 13px; min-width: 220px;
}
.sr-search button {
    border: none; border-radius: 10px; padding: 8px 14px; background: #111827; color: #fff; font-size: 13px; cursor: pointer;
}

.sr-flash { border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; font-weight: 600; }
.sr-flash.ok { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.sr-flash.err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

.sr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px,1fr)); gap: 16px; }
.sr-card {
    background: #fff; border-radius: var(--sr-radius); padding: 18px 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,.06); transition: box-shadow .15s;
}
.sr-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.1); }
.sr-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.sr-badge { padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.sr-id { color: #9ca3af; font-size: 12px; }

.sr-field { font-size: 13px; color: #374151; margin: 4px 0; }
.sr-field b { color: #111827; }
.sr-memo { background: #f9fafb; border-radius: 8px; padding: 8px 10px; font-size: 12px; color: #4b5563; margin-top: 8px; }

.sr-form-row { display: flex; gap: 8px; margin-top: 14px; }
.sr-form-row select {
    flex: 1; border: 1px solid #e5e7eb; border-radius: 8px; padding: 7px 8px; font-size: 13px;
}
.sr-form-row textarea {
    width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; padding: 7px 8px; font-size: 12px; margin-top: 8px; resize: vertical; min-height: 40px;
}
.sr-btn {
    border: none; border-radius: 8px; padding: 7px 14px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.sr-btn-save { background: #4f46e5; color: #fff; }
.sr-btn-del { background: #fef2f2; color: #b91c1c; margin-left: auto; }
.sr-btn-del:hover { background: #fee2e2; }

.sr-pagination { display: flex; justify-content: center; gap: 6px; margin-top: 28px; }
.sr-pagination a, .sr-pagination span {
    padding: 7px 12px; border-radius: 8px; font-size: 13px; text-decoration: none; color: #374151; background: #f3f4f6;
}
.sr-pagination .current { background: #111827; color: #fff; }
</style>

<div class="sr-wrap">

    <div class="sr-hero">
        <h1>재고요청 관리</h1>
        <p>고객이 남긴 재고 문의를 확인하고 상태를 변경하거나 필요 시 삭제할 수 있습니다.</p>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="sr-flash ok">✅ <?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="sr-flash err">⚠️ <?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <div class="sr-stats">
        <?php foreach (SR_STATUS_META as $key => $meta): ?>
            <div class="sr-stat-card" style="--c: <?= $meta['color'] ?>">
                <div class="num" style="color: <?= $meta['color'] ?>"><?= $statCounts[$key] ?? 0 ?></div>
                <div class="lbl"><?= $meta['label'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="sr-toolbar">
        <a class="sr-chip <?= $statusFilter === 'all' ? 'active' : '' ?>" href="?status=all<?= $keyword !== '' ? '&q=' . urlencode($keyword) : '' ?>">전체 (<?= $totalCount ?>)</a>
        <?php foreach (SR_STATUS_META as $key => $meta): ?>
            <a class="sr-chip <?= $statusFilter === $key ? 'active' : '' ?>" href="?status=<?= $key ?><?= $keyword !== '' ? '&q=' . urlencode($keyword) : '' ?>">
                <?= $meta['label'] ?> (<?= $statCounts[$key] ?? 0 ?>)
            </a>
        <?php endforeach; ?>

        <form class="sr-search" method="get">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <input type="text" name="q" placeholder="고객명·연락처·브랜드 검색" value="<?= htmlspecialchars($keyword) ?>">
            <button type="submit">검색</button>
        </form>
    </div>

    <?php if (empty($requests)): ?>
        <div class="sr-card" style="text-align:center; color:#9ca3af;">조건에 맞는 재고요청이 없습니다.</div>
    <?php else: ?>
        <div class="sr-grid">
            <?php foreach ($requests as $r):
                $meta = SR_STATUS_META[$r['status']] ?? SR_STATUS_META['pending'];
            ?>
                <div class="sr-card">
                    <div class="sr-card-top">
                        <span class="sr-badge" style="background: <?= $meta['bg'] ?>; color: <?= $meta['color'] ?>">
                            <?= $meta['label'] ?>
                        </span>
                        <span class="sr-id">#<?= (int)$r['id'] ?> · <?= htmlspecialchars($r['created_at']) ?></span>
                    </div>

                    <div class="sr-field"><b>고객명</b> : <?= htmlspecialchars($r['customer_name'] ?: '-') ?></div>
                    <div class="sr-field"><b>연락처</b> : <?= htmlspecialchars($r['display_phone'] ?: '-') ?></div>
                    <?php if (!empty($r['customer_email'])): ?>
                        <div class="sr-field"><b>이메일</b> : <?= htmlspecialchars($r['customer_email']) ?></div>
                    <?php endif; ?>
                    <div class="sr-field"><b>상품</b> : <?= htmlspecialchars($r['brand_text'] ?: '-') ?> / <?= htmlspecialchars($r['size_text'] ?: '-') ?>
                        <?php if (!empty($r['dot_code'])): ?> (DOT <?= htmlspecialchars($r['dot_code']) ?>)<?php endif; ?>
                    </div>
                    <div class="sr-field"><b>요청수량</b> : <?= (int)$r['display_qty'] ?>개 · <b>상품ID</b> : <?= (int)$r['product_id'] ?></div>

                    <?php if (!empty($r['memo'])): ?>
                        <div class="sr-memo">📝 고객 메모: <?= nl2br(htmlspecialchars($r['memo'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['admin_memo'])): ?>
                        <div class="sr-memo">🗂️ 관리자 메모: <?= nl2br(htmlspecialchars($r['admin_memo'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['processed_at'])): ?>
                        <div class="sr-field" style="color:#9ca3af; font-size:12px;">처리일시: <?= htmlspecialchars($r['processed_at']) ?></div>
                    <?php endif; ?>

                    <!-- 상태 변경 폼 -->
                    <form method="post">
                        <input type="hidden" name="sr_csrf" value="<?= htmlspecialchars($SR_CSRF) ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <div class="sr-form-row">
                            <select name="status">
                                <?php foreach (SR_STATUS_META as $key => $m): ?>
                                    <option value="<?= $key ?>" <?= $r['status'] === $key ? 'selected' : '' ?>><?= $m['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="sr-btn sr-btn-save">저장</button>
                        </div>
                        <textarea name="admin_memo" placeholder="관리자 메모 (선택)"><?= htmlspecialchars($r['admin_memo'] ?? '') ?></textarea>
                    </form>

                    <!-- 삭제 폼 (신규 기능) -->
                    <form method="post" onsubmit="return confirm('요청 #<?= (int)$r['id'] ?>을(를) 완전히 삭제합니다. 삭제된 데이터는 복구할 수 없습니다. 계속하시겠습니까?');" style="margin-top:8px; display:flex;">
                        <input type="hidden" name="sr_csrf" value="<?= htmlspecialchars($SR_CSRF) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="sr-btn sr-btn-del">🗑 삭제</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="sr-pagination">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="?status=<?= htmlspecialchars($statusFilter) ?>&q=<?= urlencode($keyword) ?>&page=<?= $p ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
setTimeout(function () {
    document.querySelectorAll('.sr-flash').forEach(function (el) { el.style.display = 'none'; });
}, 4000);
</script>

<?php
require_once __DIR__ . '/includes/admin_layout_bottom.php';
