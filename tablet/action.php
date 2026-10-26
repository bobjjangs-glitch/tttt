<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/* 태블릿 액션 라우터 — 모든 POST 처리 (로그인 상태 필요) */
$worker = tablet_current_worker();
if (!$worker && !AdminAuth::isLoggedIn()) {
    redirect('/tablet/login.php');
}
$pdo = Database::connection();

if (!is_post()) redirect('/tablet/index.php');
if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    flash('tablet_error', '유효하지 않은 요청입니다. 다시 시도해 주세요.');
    redirect('/tablet/index.php');
}

$action = $_POST['action'] ?? '';
$returnTo = '/tablet/index.php';
if (!empty($_POST['return_to']) && preg_match('#^/tablet/[a-z\-]+\.php#', (string)$_POST['return_to'])) {
    $returnTo = (string)$_POST['return_to'];
}

$back = function (string $msgKey, string $msg, ?string $to = null): never {
    flash($msgKey, $msg);
    redirect($to ?? '/tablet/index.php');
};

switch ($action) {

    /* ---- 작업 완료/진행 토글 ---- */
    case 'log_status': {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM tt_work_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id'=>$id]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$log) $back('tablet_error', '작업일지를 찾을 수 없습니다.');
        if (!empty($log['settlement_id'])) $back('tablet_error', '일정산 마감이 완료된 작업일지는 변경할 수 없습니다.');
        $newStatus = $log['status'] === 'done' ? 'working' : 'done';
        $pdo->prepare('UPDATE tt_work_logs SET status = :s WHERE id = :id')
            ->execute(['s'=>$newStatus, 'id'=>$id]);
        if ($newStatus === 'done' && $log['reservation_id']) {
            try {
                $pdo->prepare("UPDATE tt_reservations SET status = 'visited' WHERE id = :id AND status IN ('pending','confirmed')")
                    ->execute(['id'=>(int)$log['reservation_id']]);
            } catch (Throwable $e) {}
        }
        /* [v64] 작업 완료 → 구매관리 자동 적용(단가=매입단가) + 사이트 재고 차감 / 되돌림 시 복원 */
        $epMsg = '';
        try {
            $ep = ecount_apply_worklog_purchase($id, $newStatus === 'done');
            $epMsg = $ep['ok'] && $ep['message'] !== '' ? ' ' . $ep['message'] : '';
        } catch (Throwable $e) { sg_error_log('[tablet log_status ecount] ' . $e->getMessage()); }
        $back('tablet_ok', ($newStatus === 'done' ? '작업 완료로 표시했습니다.' : '작업 중으로 되돌렸습니다.') . $epMsg, $returnTo);
    }

    /* ---- 작업일지 삭제 ---- */
    case 'log_delete': {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM tt_work_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id'=>$id]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$log) $back('tablet_error', '작업일지를 찾을 수 없습니다.');
        if (!empty($log['settlement_id'])) $back('tablet_error', '일정산 마감이 완료된 작업일지는 삭제할 수 없습니다.');
        /* 첨부 사진도 함께 정리 (행 + 파일) */
        try {
            $photoIds = $pdo->prepare('SELECT id FROM tt_work_log_photos WHERE work_log_id = :id');
            $photoIds->execute(['id'=>$id]);
            delete_work_log_photos(array_map(fn($r) => (int)$r['id'], $photoIds->fetchAll()));
        } catch (Throwable $e) {}
        $pdo->prepare('DELETE FROM tt_work_logs WHERE id = :id')->execute(['id'=>$id]);
        $back('tablet_ok', '작업일지를 삭제했습니다.', $returnTo);
    }

    /* ---- 예약 → 작업 시작 (연동) ---- */
    case 'start_reservation': {
        $rid = (int)($_POST['reservation_id'] ?? 0);
        $wid = (int)($_POST['worker_id'] ?? ($worker['id'] ?? 0));
        $result = create_work_log_from_reservation($rid, $wid);
        if ($result['ok']) {
            $back('tablet_ok', '예약 작업을 시작했습니다 — 작업일지가 연동되었습니다.', $returnTo);
        }
        $back('tablet_error', $result['msg'] ?? '작업 시작에 실패했습니다.', $returnTo);
    }

    /* ---- 현장 결제 등록/수정/취소 (카드/현금/계좌) ---- */
    case 'pay_register': {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM tt_work_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id'=>$id]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$log) $back('tablet_error', '작업일지를 찾을 수 없습니다.', $returnTo);
        if (!empty($log['settlement_id'])) $back('tablet_error', '일정산 마감이 완료된 작업일지는 결제등록을 변경할 수 없습니다.', $returnTo);

        $payMethod = (string)($_POST['pay_method'] ?? '');
        $payLabels = work_pay_method_labels();
        if ($payMethod === 'none') {
            /* 결제 취소 — 외상으로 되돌림 */
            $pdo->prepare('UPDATE tt_work_logs SET pay_method = NULL, pay_amount = 0, paid_at = NULL, cash_receipt_issued = 0 WHERE id = :id')
                ->execute(['id'=>$id]);
            $back('tablet_ok', '결제등록을 취소하고 외상으로 되돌렸습니다.', $returnTo);
        }
        if (!array_key_exists($payMethod, $payLabels)) $back('tablet_error', '결제수단을 선택해 주세요 (카드/현금/계좌이체).', $returnTo);

        $payAmount = max(0, (int)($_POST['pay_amount'] ?? 0));
        if ($payAmount <= 0) $payAmount = (int)$log['work_amount']; /* 미입력 시 작업금액 그대로 */
        /* [v42] 현금영수증 — 현금결제에서만 발행 처리 가능 */
        $cashReceipt = ($payMethod === 'cash' && ($_POST['cash_receipt'] ?? '') === '1') ? 1 : 0;
        $pdo->prepare('UPDATE tt_work_logs SET pay_method = :pm, pay_amount = :pa, paid_at = NOW(), cash_receipt_issued = :cr WHERE id = :id')
            ->execute(['pm'=>$payMethod, 'pa'=>$payAmount, 'cr'=>$cashReceipt, 'id'=>$id]);
        $back('tablet_ok', '결제등록 완료 — ' . $payLabels[$payMethod] . ' ' . number_format($payAmount) . '원' . ($cashReceipt ? ' · 🧾 현금영수증 발행' : ''), $returnTo);
    }

    /* ---- 작업일지 등록/수정 (log-add / log-edit 공용) ---- */
    case 'log_save': {
        $id = (int)($_POST['id'] ?? 0);
        $workDate = $_POST['work_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) $workDate = date('Y-m-d');
        $workTime = $_POST['work_time'] ?? date('H:i');
        if (!preg_match('/^\d{1,2}:\d{2}$/', $workTime)) $workTime = date('H:i');

        $jobType = $_POST['job_type'] ?? 'tire';
        if (!array_key_exists($jobType, work_job_type_labels())) $jobType = 'tire';
        $workerId = (int)($_POST['worker_id'] ?? ($worker['id'] ?? 0));
        $content = trim((string)($_POST['work_content'] ?? ''));
        $amount = max(0, (int)($_POST['work_amount'] ?? 0));
        $source = ($_POST['source'] ?? 'walkin') === 'reservation' ? 'reservation' : 'walkin';
        $reservationId = (int)($_POST['reservation_id'] ?? 0) ?: null;
        $customerName = trim((string)($_POST['customer_name'] ?? ''));
        $carInfo = trim((string)($_POST['car_info'] ?? ''));
        $productId = (int)($_POST['product_id'] ?? 0) ?: null;
        $productName = trim((string)($_POST['product_name_snap'] ?? '')) ?: null;
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        $costFactory = max(0, (int)($_POST['cost_factory'] ?? 0));
        $costSupply = max(0, (int)($_POST['cost_supply'] ?? 0));
        $status = ($_POST['status'] ?? 'done') === 'working' ? 'working' : 'done';

        /* 현장 결제등록 (선택) — 카드/현금/계좌 / 'none'=미결제 */
        $payLabels = work_pay_method_labels();
        $payMethodRaw = (string)($_POST['pay_method'] ?? '');
        if ($payMethodRaw === '') {
            /* 폼에서 결제필드를 안 넘긴 경우: 수정이면 기존값 유지, 등록이면 미결제 */
            $payMethod = $id > 0 ? null : 'none';
        } elseif ($payMethodRaw === 'none' || !array_key_exists($payMethodRaw, $payLabels)) {
            $payMethod = 'none';
        } else {
            $payMethod = $payMethodRaw;
        }
        $payAmount = max(0, (int)($_POST['pay_amount'] ?? 0));
        if ($payMethod !== null && $payMethod !== 'none' && $payAmount <= 0) $payAmount = $amount;
        if ($payMethod === null) {
            /* 기존 결제 유지 (수정 폼에서 결제필드 미전송 시) */
            try {
                $stmtOld = $pdo->prepare('SELECT pay_method, pay_amount FROM tt_work_logs WHERE id = :id LIMIT 1');
                $stmtOld->execute(['id'=>$id]);
                if ($oldPay = $stmtOld->fetch(PDO::FETCH_ASSOC)) {
                    $payMethod = $oldPay['pay_method'] ?: 'none';
                    if ($payMethod !== 'none') $payAmount = (int)$oldPay['pay_amount'] ?: $amount;
                } else { $payMethod = 'none'; $payAmount = 0; }
            } catch (Throwable $e) { $payMethod = 'none'; $payAmount = 0; }
        }
        $paySqlSet = $payMethod !== 'none'
            ? ['pm'=>$payMethod, 'pa'=>$payAmount, 'at'=>date('Y-m-d H:i:s')]
            : ['pm'=>null, 'pa'=>0, 'at'=>null];

        if ($content === '') $back('tablet_error', '작업내용을 입력해 주세요.', $returnTo);

        /* 마감 여부 확인 */
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT settlement_id, work_date FROM tt_work_logs WHERE id = :id LIMIT 1');
            $stmt->execute(['id'=>$id]);
            $prev = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$prev) $back('tablet_error', '작업일지를 찾을 수 없습니다.', $returnTo);
            if (!empty($prev['settlement_id'])) {
                $back('tablet_error', '일정산 마감이 완료된 작업일지는 수정할 수 없습니다.', $returnTo);
            }
            /* 마감된 날짜로의 이동도 금지 */
            $settleCheck = get_settlement($workDate);
            if ($settleCheck && $settleCheck['reopened_at'] === null) {
                $back('tablet_error', '해당 날짜는 이미 일정산 마감이 되어 있어 작업일지를 넣을 수 없습니다.', $returnTo);
            }
        } else {
            $settleCheck = get_settlement($workDate);
            if ($settleCheck && $settleCheck['reopened_at'] === null) {
                $back('tablet_error', '해당 날짜는 이미 일정산 마감이 되어 있어 새 작업일지를 넣을 수 없습니다.', $returnTo);
            }
        }

        /* 상품 선택 시 원가 최신값 반영 (수동 입력이 없을 때) */
        if ($productId) {
            try {
                $stmt = $pdo->prepare('SELECT name, spec, price_original AS factory_price, supply_price FROM tt_products WHERE id = :id LIMIT 1');
                $stmt->execute(['id'=>$productId]);
                if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $productName = $productName ?: ($p['name'] . ($p['spec'] ? ' (' . $p['spec'] . ')' : ''));
                    if ($costFactory === 0) $costFactory = (int)$p['factory_price'];
                    if ($costSupply === 0) $costSupply = (int)$p['supply_price'];
                }
            } catch (Throwable $e) {}
        }

        /* 타이어 작업이면 차량 마스터에 교체 이력 자동 반영 (교체주기 관리) */
        $plateNo = extract_plate_no($carInfo);
        if ($plateNo !== '' && $jobType === 'tire') {
            capture_vehicle([
                'plate_no'    => $plateNo,
                'owner_name'  => $customerName,
                'car_model'   => car_model_from_info($carInfo),
                'tire_date'   => $workDate,
                'tire_product'=> $productName ?? '',
            ]);
        } elseif ($plateNo !== '') {
            /* 정비/기타 작업은 차량만 등록 (교체일 갱신 없음) */
            capture_vehicle([
                'plate_no'   => $plateNo,
                'owner_name' => $customerName,
                'car_model'  => car_model_from_info($carInfo),
            ]);
        }

        if ($id > 0) {
            $pdo->prepare(
                'UPDATE tt_work_logs SET
                    work_date=:wd, work_time=:wt, worker_id=:worker, job_type=:jt, work_content=:content,
                    work_amount=:amount, source=:source, reservation_id=:rid, customer_name=:cname,
                    car_info=:car, product_id=:pid, product_name_snap=:pname, qty=:qty,
                    cost_factory=:cf, cost_supply=:cs, status=:status,
                    pay_method=:pm, pay_amount=:pa, paid_at=:at,
                    cash_receipt_issued = IF(:pm2 = \'cash\', cash_receipt_issued, 0)
                 WHERE id=:id')
                ->execute([
                    'wd'=>$workDate, 'wt'=>$workTime, 'worker'=>$workerId, 'jt'=>$jobType, 'content'=>$content,
                    'amount'=>$amount, 'source'=>$source, 'rid'=>$reservationId, 'cname'=>$customerName ?: null,
                    'car'=>$carInfo ?: null, 'pid'=>$productId, 'pname'=>$productName, 'qty'=>$qty,
                    'cf'=>$costFactory, 'cs'=>$costSupply, 'status'=>$status,
                    'pm'=>$paySqlSet['pm'], 'pa'=>$paySqlSet['pa'], 'at'=>$paySqlSet['at'], 'id'=>$id,
                    'pm2'=>$paySqlSet['pm'],
                ]);
            $savedId = $id;
            $doneMsg = '작업일지를 수정했습니다.';
        } else {
            $resNoSnap = null;
            if ($source === 'reservation' && $reservationId) {
                try {
                    $stmt = $pdo->prepare('SELECT res_no FROM tt_reservations WHERE id = :id LIMIT 1');
                    $stmt->execute(['id'=>$reservationId]);
                    $resNoSnap = $stmt->fetchColumn() ?: null;
                } catch (Throwable $e) {}
            }
            $pdo->prepare(
                'INSERT INTO tt_work_logs
                    (work_date, work_time, worker_id, job_type, work_content, work_amount, source,
                     reservation_id, res_no_snap, customer_name, car_info, product_id, product_name_snap,
                     qty, cost_factory, cost_supply, status, pay_method, pay_amount, paid_at, created_by)
                 VALUES
                    (:wd, :wt, :worker, :jt, :content, :amount, :source,
                     :rid, :resno, :cname, :car, :pid, :pname,
                     :qty, :cf, :cs, :status, :pm, :pa, :at, :by)')
                ->execute([
                    'wd'=>$workDate, 'wt'=>$workTime, 'worker'=>$workerId, 'jt'=>$jobType, 'content'=>$content,
                    'amount'=>$amount, 'source'=>$source, 'rid'=>$reservationId, 'resno'=>$resNoSnap,
                    'cname'=>$customerName ?: null, 'car'=>$carInfo ?: null, 'pid'=>$productId, 'pname'=>$productName,
                    'qty'=>$qty, 'cf'=>$costFactory, 'cs'=>$costSupply, 'status'=>$status,
                    'pm'=>$paySqlSet['pm'], 'pa'=>$paySqlSet['pa'], 'at'=>$paySqlSet['at'],
                    'by'=>($worker['name'] ?? AdminAuth::currentAdminName()),
                ]);
            $savedId = (int)$pdo->lastInsertId();
            $doneMsg = '작업일지를 등록했습니다.';
        }

        /* 사진 삭제(수정 화면 체크박스) + 신규 업로드 (최대 5장) */
        $photoMsg = '';
        if ($savedId > 0 && !empty($_POST['photo_delete_ids']) && is_array($_POST['photo_delete_ids'])) {
            $deleted = delete_work_log_photos($_POST['photo_delete_ids']);
            if ($deleted > 0) $photoMsg .= " 사진 {$deleted}장 삭제.";
        }
        if ($savedId > 0 && !empty($_FILES['photos']) && is_array($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
            $photos = save_work_log_photos($savedId, $_FILES['photos']);
            if ($photos['ok'] > 0) $photoMsg .= " 사진 {$photos['ok']}장 첨부.";
            if (!empty($photos['error'])) $photoMsg .= ' ' . implode(' ', $photos['error']);
        }

        /* [v64] 완료 상태로 저장 → 구매관리 자동 적용(단가=매입단가) + 사이트 재고 차감 / 진행중 저장이면 자동 항목 되돌리기 */
        if ($savedId > 0) {
            try { $ep = ecount_apply_worklog_purchase($savedId, $status === 'done'); if (!empty($ep['message'])) $photoMsg .= ' ' . $ep['message']; }
            catch (Throwable $e) { sg_error_log('[tablet log_save ecount] ' . $e->getMessage()); }
        }

        $back('tablet_ok', $doneMsg . $photoMsg, '/tablet/index.php?date=' . $workDate);
    }

    default:
        $back('tablet_error', '알 수 없는 요청입니다.');
}
