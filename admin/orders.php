<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('orders');
ensure_order_tracking_columns();
$pdo = Database::connection();

$statusLabels = [
    'pending'   => '주문접수',
    'paid'      => '결제완료',
    'preparing' => '상품준비중',
    'shipped'   => '배송중',
    'done'      => '배송완료',
    'cancelled' => '주문취소',
];

function admin_delete_orders(PDO $pdo, array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (empty($ids)) return 0;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tt_order_status_logs WHERE order_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM tt_order_items WHERE order_id IN ({$placeholders})")->execute($ids);
        $stmt = $pdo->prepare("DELETE FROM tt_orders WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $affected = $stmt->rowCount();
        $pdo->commit();
        return $affected;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[admin/orders delete] ' . $e->getMessage());
        return -1;
    }
}

if (is_post() && ($_POST['form_type'] ?? '') === 'change_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/orders.php?id=' . (int)($_POST['order_id'] ?? 0));
    }

    $orderId   = (int)($_POST['order_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $memo      = trim($_POST['memo'] ?? '');

    if (!array_key_exists($newStatus, $statusLabels)) {
        flash('admin_error', '올바르지 않은 상태값입니다.');
        redirect('/admin/orders.php?id=' . $orderId);
    }

    /*
     * [FIX] 500 에러 근본 원인 수정
     * ------------------------------------------------------------------
     * 기존 코드는 beginTransaction() ~ commit() 사이에서 AdminAuth::log()를
     * 호출했다. AdminAuth::log() 내부는 ensure_admin_logs_table()을 통해
     * "CREATE TABLE IF NOT EXISTS tt_admin_logs (...)" 라는 DDL을 실행하는데,
     * MySQL/MariaDB의 InnoDB는 DDL이 실행되는 순간 (테이블 존재 여부와 무관하게)
     * 현재 진행 중인 트랜잭션을 강제로 암시적 커밋(implicit commit) 시켜버린다.
     *
     * 그 결과:
     *   1) beginTransaction() 이후 UPDATE/INSERT까지는 정상 실행됨
     *   2) AdminAuth::log() 안에서 CREATE TABLE 실행 → 트랜잭션이 여기서 이미 커밋됨
     *   3) 뒤이어 나오는 $pdo->commit() 호출 시 "there is no active transaction"
     *      PDOException 발생
     *   4) catch(Throwable $e) 블록에서 다시 $pdo->rollBack()을 호출하지만,
     *      이미 트랜잭션이 없는 상태이므로 rollBack()도 예외를 던지며 그대로
     *      전역 예외 핸들러 밖으로 튀어나가 500 에러(서버 관리자 root@localhost
     *      안내 문구가 뜨는 Apache 기본 에러 페이지)로 이어졌다.
     *
     * 해결책: 핵심 주문 데이터 변경(UPDATE + 상태 로그 INSERT)만 트랜잭션으로
     * 묶고, commit()을 완전히 마친 뒤 트랜잭션 바깥에서 AdminAuth::log()를
     * 호출한다. catch 블록에서도 rollBack() 호출 전 inTransaction()으로
     * 실제 열려 있는 트랜잭션이 있는지 확인해 이중 예외를 방지한다.
     */
    try {
        $pdo->beginTransaction();

        /* [v48] 변경 전 결제상태를 먼저 읽어 동기화 여부를 결정한다.
           어드민이 "결제완료"(또는 그 이후 상태)로 변경하면 payment_status도 'paid'로,
           "환불"로 변경하면 paid였던 건을 'refunded'로 함께 동기화 —
           v47까지 status만 바꾸고 payment_status는 그대로라 마이페이지 세금계산서의
           "결제완료 주문" 목록이 비어 있던 것이 이 건의 직접 원인. */
        $curStmt = $pdo->prepare('SELECT payment_status FROM tt_orders WHERE id = :id LIMIT 1');
        $curStmt->execute(['id' => $orderId]);
        $prevPayStatus = (string)($curStmt->fetchColumn() ?: 'unpaid');

        $paidLike = in_array($newStatus, order_paid_statuses(), true);
        $syncTo = null;
        if ($paidLike && $prevPayStatus !== 'paid') {
            $syncTo = 'paid';
        } elseif ($newStatus === 'refunded' && $prevPayStatus === 'paid') {
            $syncTo = 'refunded';
        }

        $upd = $pdo->prepare('UPDATE tt_orders SET status = :s' . ($syncTo ? ', payment_status = :ps' : '') . ' WHERE id = :id');
        $updParams = ['s' => $newStatus, 'id' => $orderId];
        if ($syncTo) $updParams['ps'] = $syncTo;
        $upd->execute($updParams);

        $log = $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:oid, :s, :memo)');
        $baseMemo = $memo !== '' ? $memo : ($statusLabels[$newStatus] . '로 변경');
        if ($syncTo === 'paid') $baseMemo .= ' · 결제상태 동기화(미결제→결제완료)';
        if ($syncTo === 'refunded') $baseMemo .= ' · 결제상태 동기화(결제완료→환불)';
        $log->execute(['oid' => $orderId, 's' => $newStatus, 'memo' => $baseMemo]);

        $pdo->commit();

        /* [v51] 관리자 결제확정(무통장입금 등) 시점에도 이카운트 ERP 판매입력 자동 전송 */
        if ($syncTo === 'paid') {
            ecount_on_paid('order', $orderId);
        }

        // [FIX] 관리자 활동 로그는 핵심 트랜잭션 commit 이후, 완전히 분리된 시점에 기록한다.
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_status_change', "주문#{$orderId} 상태를 {$statusLabels[$newStatus]}로 변경");

        /* [v48] 결제확정 시점에 소득공제 현금영수증 자동 발행 —
           주문 시 소득공제(휴대폰 번호)를 요청한 건은 관리자 수동 토글 없이 발행 처리된다. */
        $receiptAutoIssued = auto_issue_income_cash_receipt($pdo, $orderId);

        flash('admin_success', '주문 상태가 변경되었습니다.'
            . ($receiptAutoIssued ? ' 🧾 현금영수증(소득공제) 자동 발행 처리되었습니다 — 정산마감에 반영됩니다.' : ''));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[admin/orders] ' . $e->getMessage());
        flash('admin_error', '상태 변경 중 오류가 발생했습니다.');
    }

    redirect('/admin/orders.php?id=' . $orderId);
}

if (is_post() && ($_POST['form_type'] ?? '') === 'delete_one') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/orders.php');
    }
    $orderId = (int)($_POST['order_id'] ?? 0);
    $result = admin_delete_orders($pdo, [$orderId]);
    if ($result > 0) {
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_delete', "주문#{$orderId} 삭제");
        flash('admin_success', '주문이 삭제되었습니다.');
    } else {
        flash('admin_error', '주문 삭제 중 오류가 발생했습니다.');
    }
    redirect('/admin/orders.php');
}

if (is_post() && ($_POST['form_type'] ?? '') === 'bulk_delete') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/orders.php');
    }
    $ids = $_POST['order_ids'] ?? [];
    $result = admin_delete_orders($pdo, is_array($ids) ? $ids : []);
    if ($result > 0) {
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_bulk_delete', "{$result}건 일괄 삭제");
        flash('admin_success', "{$result}건의 주문이 삭제되었습니다.");
    } elseif ($result === 0) {
        flash('admin_error', '선택된 주문이 없습니다.');
    } else {
        flash('admin_error', '주문 삭제 중 오류가 발생했습니다.');
    }
    redirect('/admin/orders.php');
}

/* ===== 운송장(배송정보) 저장 ===== */
if (is_post() && ($_POST['form_type'] ?? '') === 'save_tracking') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/orders.php?id=' . (int)($_POST['order_id'] ?? 0));
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    $carrier = $_POST['carrier'] ?? '';
    $carriers = carrier_labels();
    if (!array_key_exists($carrier, $carriers)) $carrier = '';
    $trackingNo = trim((string)($_POST['tracking_number'] ?? ''));
    if ($trackingNo !== '' && !preg_match('/^[A-Za-z0-9\-]{5,40}$/', $trackingNo)) {
        flash('admin_error', '운송장번호 형식이 올바르지 않습니다. (영문/숫자/하이픈 5~40자)');
        redirect('/admin/orders.php?id=' . $orderId);
    }

    try {
        $stmt = $pdo->prepare('SELECT order_no, status, tracking_number FROM tt_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            flash('admin_error', '존재하지 않는 주문입니다.');
            redirect('/admin/orders.php');
        }

        $hadTracking = (string)($order['tracking_number'] ?? '') !== '';
        $pdo->prepare('UPDATE tt_orders SET carrier = :c, tracking_number = :t,
                        shipped_at = IF(:t2 <> "" AND shipped_at IS NULL, NOW(), shipped_at)
                      WHERE id = :id')
            ->execute(['c' => $carrier ?: null, 't' => $trackingNo ?: null, 't2' => $trackingNo, 'id' => $orderId]);

        $carrierLabel = $carriers[$carrier] ?? '택배사 미지정';
        $logMemo = $trackingNo !== ''
            ? "운송장 등록 — {$carrierLabel} {$trackingNo}" . ($hadTracking ? ' (변경)' : '')
            : '운송장 정보 삭제';
        $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:oid, :s, :memo)')
            ->execute(['oid' => $orderId, 's' => $order['status'], 'memo' => $logMemo]);

        AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_tracking_save', "주문#{$orderId} {$logMemo}");
        flash('admin_success', '배송 정보가 저장되었습니다.' . ($trackingNo !== '' ? ' — 고객 마이페이지에서 배송조회가 가능합니다.' : ''));
    } catch (Throwable $e) {
        error_log('[admin/orders save_tracking] ' . $e->getMessage());
        flash('admin_error', '배송 정보 저장 중 오류가 발생했습니다.');
    }
    redirect('/admin/orders.php?id=' . $orderId);
}

$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ===== [v42] 현금영수증(지출증빙) 발행 처리/해제 ===== */
if (is_post() && ($_POST['form_type'] ?? '') === 'receipt_toggle') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/orders.php');
    }
    $orderId = (int)($_POST['order_id'] ?? 0);
    try {
        $stmt = $pdo->prepare('SELECT order_no, status, cash_receipt_type, cash_receipt_no, cash_receipt_issued FROM tt_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            flash('admin_error', '존재하지 않는 주문입니다.');
            redirect('/admin/orders.php');
        }
        $crType = (string)($order['cash_receipt_type'] ?? 'none');
        $crTypeLabel = $crType === 'income' ? '소득공제' : ($crType === 'expense' ? '지출증빙' : '현금영수증');
        $newVal = ((int)($order['cash_receipt_issued'] ?? 0) === 1) ? 0 : 1;
        $pdo->prepare('UPDATE tt_orders SET cash_receipt_issued = :v WHERE id = :id')
            ->execute(['v' => $newVal, 'id' => $orderId]);
        $pdo->prepare('INSERT INTO tt_order_status_logs (order_id, status, memo) VALUES (:oid, :s, :memo)')
            ->execute(['oid' => $orderId, 's' => $order['status'], 'memo' => $newVal ? "현금영수증({$crTypeLabel}) 발행 처리" : '현금영수증 발행 해제']);
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'order_receipt_toggle',
            "주문#{$orderId} " . ($newVal ? "현금영수증({$crTypeLabel}) 발행 처리" : '현금영수증 발행 해제'));
        flash('admin_success', $newVal ? '현금영수증 발행 처리했습니다 — 정산마감에 반영됩니다.' : '현금영수증 발행을 해제했습니다.');
    } catch (Throwable $e) {
        error_log('[admin/orders receipt_toggle] ' . $e->getMessage());
        flash('admin_error', '현금영수증 처리 중 오류가 발생했습니다.');
    }
    redirect('/admin/orders.php?id=' . $orderId);
}

if ($detailId > 0) {
    $stmt = $pdo->prepare('SELECT o.*, (SELECT ti.id FROM tt_tax_invoices ti WHERE ti.target_type = "order" AND ti.target_id = o.id AND ti.status = "issued" LIMIT 1) AS tax_invoice_issued FROM tt_orders o WHERE o.id = :id');
    $stmt->execute(['id' => $detailId]);
    $order = $stmt->fetch();

    if (!$order) {
        flash('admin_error', '존재하지 않는 주문입니다.');
        redirect('/admin/orders.php');
    }

    $itemStmt = $pdo->prepare('SELECT * FROM tt_order_items WHERE order_id = :id');
    $itemStmt->execute(['id' => $detailId]);
    $items = $itemStmt->fetchAll();

    $logStmt = $pdo->prepare('SELECT * FROM tt_order_status_logs WHERE order_id = :id ORDER BY id DESC');
    $logStmt->execute(['id' => $detailId]);
    $logs = $logStmt->fetchAll();

    $pageTitle = '주문 상세 - ' . $order['order_no'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="admin-detail-head">
      <a href="<?= BASE_URL ?>/admin/orders.php" class="admin-back-link">← 목록으로</a>
      <form method="post" onsubmit="return confirm('정말 이 주문을 삭제하시겠습니까?\n삭제된 주문은 복구할 수 없습니다.');" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="delete_one">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <button type="submit" class="btn-admin-danger">🗑 주문 삭제</button>
      </form>
    </div>

    <div class="admin-card">
      <h2>주문 정보</h2>
      <table class="admin-table admin-table-kv">
        <tr><th style="width:160px">주문번호</th><td><?= h($order['order_no']) ?></td></tr>
        <tr><th>주문상태</th><td><span class="status-badge status-<?= h($order['status']) ?>"><?= h($statusLabels[$order['status']] ?? $order['status']) ?></span></td></tr>
        <?php /* [v46] 결제수단 표시 (무통장/가상계좌/카드/네이버페이/카카오페이) */ ?>
        <tr><th>결제수단</th><td><?= h(payment_method_labels()[$order['payment_method'] ?? 'bank_transfer'] ?? ($order['payment_method'] ?? 'bank_transfer')) ?></td></tr>

        <tr><th>수령인</th><td><?= h($order['recipient_name']) ?></td></tr>
        <tr><th>연락처</th><td><?= h($order['recipient_phone']) ?></td></tr>
        <tr><th>배송지</th><td><?= h($order['recipient_addr']) ?></td></tr>
        <tr><th>상품금액</th><td><?= format_price((int)$order['total_amount'] - (int)$order['shipping_fee']) ?></td></tr>
        <tr><th>배송비</th><td><?= format_price((int)$order['shipping_fee']) ?></td></tr>
        <tr><th>총 결제금액</th><td><strong><?= format_price((int)$order['total_amount']) ?></strong></td></tr>
        <tr><th>주문일시</th><td><?= h(date('Y-m-d H:i', strtotime($order['created_at']))) ?></td></tr>
      <tr>
        <th>현금영수증</th>
        <td>
          <?php /* [v46→v48] 주문 시 현금영수증 요청(소득공제/지출증빙) 정보 + 자동 발행 안내 표시 */ ?>
          <?php $crType = (string)($order['cash_receipt_type'] ?? 'none'); ?>
          <?php if (!in_array($crType, ['', 'none'], true)): ?>
            <div style="margin-bottom:6px;font-size:13px;color:#0f766e;font-weight:800;">
              🧾 요청됨 — <?= $crType === 'income' ? '소득공제' : '지출증빙' ?>
              <span class="mono"><?= h($order['cash_receipt_no'] ?? '') ?></span>
              <?php if ($crType === 'income'): ?>
                <span style="font-weight:600;color:#16a34a;">· 결제확정 시 자동 발행</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ((int)($order['cash_receipt_issued'] ?? 0) === 1): ?>
          <span style="color:#0e7490;font-weight:800;">🧾 현금영수증<?= $crType === 'income' ? '(소득공제)' : ($crType === 'expense' ? '(지출증빙)' : '') ?> 발행 완료</span>
          <?php else: ?><span style="color:#94a3b8;font-weight:700;">미발행<?= $crType === 'income' ? ' (결제확정 시 자동 발행됩니다)' : '' ?></span><?php endif; ?>
          <form method="post" style="display:inline;margin-left:10px;" onsubmit="return confirm('<?= (int)($order['cash_receipt_issued'] ?? 0) === 1 ? '현금영수증 발행을 해제할까요?' : '이 주문을 현금영수증(' . ($crType === 'income' ? '소득공제' : '지출증빙') . ') 발행 처리할까요?' ?>');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_type" value="receipt_toggle">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button type="submit" class="btn btn-sm"><?= (int)($order['cash_receipt_issued'] ?? 0) === 1 ? '발행 해제' : '🧾 발행 처리' ?></button>
          </form>
        </td>
      </tr>
      <tr>
        <th>세금계산서</th>
        <td>
          <?php if (!empty($order['tax_invoice_issued'])): ?>
          <span style="color:#b91c1c;font-weight:800;">📄 세금계산서 발행 완료</span> <small class="admin-text-sub">(세금계산서 관리에서 발행 처리된 주문)</small>
          <?php else: ?><span style="color:#94a3b8;font-weight:700;">미발행</span> <small class="admin-text-sub">(회원이 요청하면 세금계산서 관리에서 발행)</small><?php endif; ?>
        </td>
      </tr>
        <?php if (!empty($order['tracking_number'])): ?>
        <?php
            $carriers = carrier_labels();
            $carrierLabel = $carriers[$order['carrier'] ?? ''] ?? '택배사 미지정';
            $trackingUrl = carrier_tracking_url($order['carrier'] ?? null, (string)$order['tracking_number']);
        ?>
        <tr>
          <th>운송장</th>
          <td>
            🚚 <?= h($carrierLabel) ?> · <span class="mono"><?= h($order['tracking_number']) ?></span>
            <?php if ($trackingUrl): ?>
              <a href="<?= h($trackingUrl) ?>" target="_blank" rel="noopener" class="admin-link-btn">배송조회 ↗</a>
            <?php endif; ?>
            <?php if (!empty($order['shipped_at'])): ?>
              <span class="admin-text-sub" style="font-size:12px">(출고 <?= h(date('Y-m-d H:i', strtotime($order['shipped_at']))) ?>)</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endif; ?>
      </table>
    </div>

    <div class="admin-card">
      <h2>배송 정보 (운송장 등록)</h2>
      <p class="admin-list-sub" style="margin-bottom:10px">운송장번호를 등록하면 고객 마이페이지 주문내역에 <b>배송조회</b> 버튼이 표시됩니다.</p>
      <form method="post" class="admin-status-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="save_tracking">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <select name="carrier" style="min-width:150px">
          <option value="">택배사 선택</option>
          <?php foreach (carrier_labels() as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= ($order['carrier'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="tracking_number" placeholder="운송장번호 (예: 123456789012)" style="flex:1" value="<?= h((string)($order['tracking_number'] ?? '')) ?>">
        <button type="submit" class="btn-admin-primary">🚚 저장</button>
      </form>
    </div>

    <div class="admin-card">
      <h2>주문 상품</h2>
      <table class="admin-table">
        <thead><tr><th>상품명</th><th>단가</th><th>수량</th><th>소계</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= h($it['product_name']) ?></td>
            <td><?= format_price((int)$it['price']) ?></td>
            <td><?= (int)$it['qty'] ?></td>
            <td><?= format_price((int)$it['price'] * (int)$it['qty']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="admin-card">
      <h2>상태 변경</h2>
      <form method="post" class="admin-status-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="change_status">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <select name="status">
          <?php foreach ($statusLabels as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $key === $order['status'] ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="memo" placeholder="메모 (선택)" style="flex:1">
        <button type="submit" class="btn-admin-primary">변경</button>
      </form>
    </div>

    <div class="admin-card">
      <h2>상태 변경 이력</h2>
      <table class="admin-table">
        <thead><tr><th>상태</th><th>메모</th><th>일시</th></tr></thead>
        <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="3" style="text-align:center;color:var(--adm-text-sub)">이력이 없습니다.</td></tr>
        <?php else: foreach ($logs as $lg): ?>
          <tr>
            <td><span class="status-badge status-<?= h($lg['status']) ?>"><?= h($statusLabels[$lg['status']] ?? $lg['status']) ?></span></td>
            <td><?= h($lg['memo']) ?></td>
            <td><?= h(date('Y-m-d H:i', strtotime($lg['created_at']))) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($statusFilter !== '' && array_key_exists($statusFilter, $statusLabels)) {
    $where .= ' AND status = :status';
    $params['status'] = $statusFilter;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tt_orders WHERE {$where}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("SELECT o.id, o.order_no, o.recipient_name, o.recipient_phone, o.total_amount, o.status, o.carrier, o.tracking_number, o.cash_receipt_issued, o.created_at,
                            (SELECT ti.id FROM tt_tax_invoices ti WHERE ti.target_type = 'order' AND ti.target_id = o.id AND ti.status = 'issued' LIMIT 1) AS tax_invoice_issued
                            FROM tt_orders o WHERE {$where}
                            ORDER BY o.id DESC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$orders = $listStmt->fetchAll();

$totalPages = max(1, (int)ceil($totalCount / $perPage));

$pageTitle = '주문 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-toolbar">
  <select id="statusFilterSelect" onchange="location.href='<?= BASE_URL ?>/admin/orders.php?status=' + this.value">
    <option value="">전체 상태</option>
    <?php foreach ($statusLabels as $key => $label): ?>
      <option value="<?= h($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="admin-toolbar-right">
    <a href="<?= BASE_URL ?>/admin/orders_export.php?status=<?= h($statusFilter) ?>" class="btn-admin-excel">📊 엑셀 다운로드</a>
    <button type="submit" form="bulkDeleteForm" class="btn-admin-danger btn-bulk-delete" disabled>🗑 선택 삭제</button>
  </div>
</div>

<form method="post" id="bulkDeleteForm" onsubmit="return confirm('선택한 주문을 정말 삭제하시겠습니까?\n삭제된 주문은 복구할 수 없습니다.');">
<?= Csrf::field() ?>
<input type="hidden" name="form_type" value="bulk_delete">

<div class="admin-card">
  <h2>주문 목록 <span class="admin-count-pill"><?= number_format($totalCount) ?>건</span></h2>
  <table class="admin-table admin-table-trendy">
    <thead>
      <tr>
        <th style="width:36px"><input type="checkbox" id="chkAll"></th>
        <th>주문번호</th><th>수령인</th><th>연락처</th><th>금액</th><th>상태</th><th>주문일시</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($orders)): ?>
      <tr><td colspan="8" class="admin-empty-row">📭 주문이 없습니다.</td></tr>
    <?php else: foreach ($orders as $o): ?>
      <tr>
        <td><input type="checkbox" name="order_ids[]" value="<?= (int)$o['id'] ?>" class="chkRow"></td>
        <td class="mono"><?= h($o['order_no']) ?></td>
        <td><?= h($o['recipient_name']) ?></td>
        <td><?= h($o['recipient_phone']) ?></td>
        <td class="mono"><?= format_price((int)$o['total_amount']) ?></td>
        <td>
          <span class="status-badge status-<?= h($o['status']) ?>"><?= h($statusLabels[$o['status']] ?? $o['status']) ?></span>
          <?= !empty($o['tracking_number']) ? '<span title="운송장 등록됨" style="font-size:11px;color:#2563eb;font-weight:700;"> 🚚</span>' : '' ?>
          <?= !empty($o['cash_receipt_issued']) ? '<span title="현금영수증 발행" style="font-size:11px;color:#0e7490;font-weight:800;"> 🧾</span>' : '' ?>
          <?= !empty($o['tax_invoice_issued']) ? '<span title="세금계산서 발행 완료" style="font-size:11px;color:#b91c1c;font-weight:800;"> 📄</span>' : '' ?>
        </td>
        <td class="admin-text-sub"><?= h(date('Y-m-d H:i', strtotime($o['created_at']))) ?></td>
        <td><a href="<?= BASE_URL ?>/admin/orders.php?id=<?= (int)$o['id'] ?>" class="admin-link-btn">상세보기</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="admin-pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?page=<?= $p ?>&status=<?= h($statusFilter) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
</form>

<script>
(function(){
  const chkAll = document.getElementById('chkAll');
  const bulkBtn = document.querySelector('.btn-bulk-delete');
  function rows(){ return document.querySelectorAll('.chkRow'); }
  function updateBtn(){
    const checked = document.querySelectorAll('.chkRow:checked').length;
    bulkBtn.disabled = checked === 0;
    bulkBtn.textContent = checked > 0 ? `🗑 선택 삭제 (${checked}건)` : '🗑 선택 삭제';
  }
  chkAll?.addEventListener('change', function(){
    rows().forEach(r => r.checked = chkAll.checked);
    updateBtn();
  });
  rows().forEach(r => r.addEventListener('change', updateBtn));
  updateBtn();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
