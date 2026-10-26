<?php
// partner_delete.php — 거래처 삭제 (관리자 전용)
// 거래 이력이 있으면 삭제 불가 → "사용 중지"로 대체
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

$ledgerLib = __DIR__ . '/inc/ledger_lib.php';
if (is_file($ledgerLib)) {
    require_once $ledgerLib;
}

$me = current_user();
if (($me['role'] ?? '') !== 'admin') {
    flash('거래처 삭제는 관리자만 할 수 있습니다.', 'err');
    redirect('partners.php');
    exit;
}

/** 테이블/컬럼 참조 건수. 식별자는 영문·숫자·_ 만 허용 */
function pd_count(PDO $pdo, string $table, string $col, int $id): int
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) {
        throw new RuntimeException('내부 식별자 오류로 참조 검사를 할 수 없습니다.');
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = ?");
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') { // 테이블 자체가 없으면 참조도 없음
            return 0;
        }
        throw $e;
    }
}

/** 이 거래처를 참조하는 데이터: [['label'=>..., 'count'=>n], ...] (0건은 제외) */
function pd_refs(PDO $pdo, int $id): array
{
    $targets = [
        'erp_trades'   => ['partner_id', '거래 전표(임시·확정·취소 포함)'],
        'erp_payments' => ['partner_id', '입금 내역'],
    ];

    // FK 로 이 테이블을 참조하는 다른 테이블 자동 탐지
    $st = $pdo->prepare(
        "SELECT TABLE_NAME AS tname, COLUMN_NAME AS cname
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME = 'erp_partners'
            AND REFERENCED_COLUMN_NAME = 'id'"
    );
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        $t = (string)$r['tname'];
        if (isset($targets[$t]) || $t === 'erp_partner_openings') {
            continue;
        }
        $targets[$t] = [(string)$r['cname'], $t];
    }

    $out = [];
    foreach ($targets as $table => $def) {
        $n = pd_count($pdo, $table, $def[0], $id);
        if ($n > 0) {
            $out[] = ['label' => $def[1], 'count' => $n];
        }
    }
    return $out;
}

/** 기초잔액 행 (없으면 null) */
function pd_opening(PDO $pdo, int $id): ?array
{
    try {
        $st = $pdo->prepare('SELECT ar_amount, ap_amount FROM erp_partner_openings WHERE partner_id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return null;
        }
        throw $e;
    }
}

/** 외상 원장 잔액. 확인 불가면 null */
function pd_balance(int $id): ?int
{
    if (!function_exists('lg_balance') || !function_exists('lg_ready_error')) {
        return null;
    }
    try {
        if (lg_ready_error() !== null) {
            return null;
        }
        return (int)lg_balance($id);
    } catch (Throwable $e) {
        return null;
    }
}

/** 삭제 불가 사유 목록 (비어 있으면 삭제 가능) */
function pd_delete_blockers(PDO $pdo, array $p): array
{
    $id  = (int)$p['id'];
    $out = [];
    foreach (pd_refs($pdo, $id) as $r) {
        $out[] = $r['label'] . ' ' . number_format($r['count']) . '건이 있습니다.';
    }
    $op = pd_opening($pdo, $id);
    if ($op && ((int)$op['ar_amount'] !== 0 || (int)$op['ap_amount'] !== 0)) {
        $out[] = '기초잔액(미수/미지급)이 0원이 아닙니다.';
    }
    if ((int)($p['is_credit'] ?? 0) === 1) {
        $bal = pd_balance($id);
        if ($bal === null) {
            $out[] = '외상 원장 잔액을 확인할 수 없습니다.';
        } elseif ($bal !== 0) {
            $out[] = '외상 미수 잔액이 ' . number_format($bal) . '원 남아 있습니다.';
        }
    }
    return $out;
}

/** 사용 중지 불가 사유 */
function pd_deactivate_blockers(array $p): array
{
    if ((int)($p['is_credit'] ?? 0) !== 1) {
        return [];
    }
    $bal = pd_balance((int)$p['id']);
    if ($bal === null) {
        return ['외상 원장 잔액을 확인할 수 없어 사용 중지를 막았습니다.'];
    }
    if ($bal !== 0) {
        return ['외상 미수 잔액이 ' . number_format($bal) . '원 남아 있어 사용 중지할 수 없습니다. 입금 등록으로 정리하세요.'];
    }
    return [];
}

// ---------------------------------------------------------------- POST
if (is_post()) {
    csrf_check();
    $id  = in_int($_POST, 'id');
    $act = in_str($_POST, 'act', 12);
    $back = 'partner_delete.php?id=' . $id;

    try {
        if ($id <= 0 || !in_array($act, ['delete', 'deactivate'], true)) {
            throw new RuntimeException('요청이 올바르지 않습니다.');
        }

        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM erp_partners WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            throw new RuntimeException('거래처를 찾을 수 없습니다. 이미 삭제됐을 수 있습니다.');
        }

        $snap = '상호=' . $p['name']
            . ' / 사업자번호=' . ($p['business_no'] ?? '')
            . ' / 코드=' . ($p['ecount_code'] ?? '');

        if ($act === 'delete') {
            $blockers = pd_delete_blockers($pdo, $p);
            if ($blockers) {
                throw new RuntimeException('삭제할 수 없습니다. ' . implode(' / ', $blockers));
            }
            // 위 검사로 0원 기초잔액 행만 남은 상태 → FK 때문에 먼저 지움
            try {
                $pdo->prepare('DELETE FROM erp_partner_openings WHERE partner_id = ?')->execute([$id]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '42S02') {
                    throw $e;
                }
            }
            $del = $pdo->prepare('DELETE FROM erp_partners WHERE id = ?');
            $del->execute([$id]);
            if ($del->rowCount() !== 1) {
                throw new RuntimeException('삭제되지 않았습니다. 다시 시도하세요.');
            }
            audit('partner_delete', 'partners', $id, $snap);
            $pdo->commit();
            flash('거래처를 삭제했습니다.');
            redirect('partners.php');
            exit;
        }

        // deactivate
        $blockers = pd_deactivate_blockers($p);
        if ($blockers) {
            throw new RuntimeException(implode(' / ', $blockers));
        }
        $up = $pdo->prepare('UPDATE erp_partners SET is_active = 0, updated_at = ? WHERE id = ?');
        $up->execute([date('Y-m-d H:i:s'), $id]);
        audit('partner_deactivate', 'partners', $id, $snap);
        $pdo->commit();
        flash('거래처를 사용 중지로 변경했습니다. 기존 전표와 원장은 그대로 유지됩니다.');
        redirect('partners.php');
        exit;
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($e->getMessage(), 'err');
        redirect($back);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ERP] partner delete: ' . get_class($e) . ': ' . $e->getMessage());
        $msg = ($e instanceof PDOException && $e->getCode() === '23000')
            ? '다른 데이터가 이 거래처를 사용 중이라 삭제할 수 없습니다. 사용 중지를 이용하세요.'
            : '처리 중 오류가 발생했습니다. 아무것도 변경되지 않았습니다.';
        flash($msg, 'err');
        redirect($back);
        exit;
    }
}

// ---------------------------------------------------------------- GET
$id = in_int($_GET, 'id');
$st = $pdo->prepare('SELECT * FROM erp_partners WHERE id = ?');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) {
    flash('거래처를 찾을 수 없습니다.', 'err');
    redirect('partners.php');
    exit;
}

$refs      = pd_refs($pdo, $id);
$delBlock  = pd_delete_blockers($pdo, $p);
$deaBlock  = pd_deactivate_blockers($p);
$typeLabel = ['customer' => '매출처', 'supplier' => '매입처', 'both' => '매출·매입'][$p['partner_type'] ?? ''] ?? '-';
$isActive  = (int)($p['is_active'] ?? 0) === 1;

page_header('거래처 삭제', 'partners');
?>
<h2 style="margin:0 0 14px">거래처 삭제</h2>

<div class="card" style="max-width:640px;padding:18px;margin-bottom:16px">
  <table style="width:100%">
    <tr><th style="width:120px;text-align:left">상호</th><td><?= h($p['name']) ?></td></tr>
    <tr><th style="text-align:left">사업자번호</th><td><?= ($p['business_no'] ?? '') !== '' ? h(fmt_biz_no($p['business_no'])) : '-' ?></td></tr>
    <tr><th style="text-align:left">대표자</th><td><?= h($p['ceo_name'] ?? '') ?: '-' ?></td></tr>
    <tr><th style="text-align:left">유형</th><td><?= h($typeLabel) ?></td></tr>
    <tr><th style="text-align:left">외상</th><td><?= (int)($p['is_credit'] ?? 0) === 1 ? '외상 거래처' : '-' ?></td></tr>
    <tr><th style="text-align:left">상태</th><td><?= $isActive ? '사용 중' : '사용 중지' ?></td></tr>
  </table>
</div>

<?php if ($delBlock): ?>
  <div class="card" style="max-width:640px;padding:18px;margin-bottom:16px;border-left:4px solid #dc2626">
    <strong>삭제할 수 없습니다.</strong>
    <ul style="margin:8px 0 0 18px">
      <?php foreach ($delBlock as $b): ?>
        <li><?= h($b) ?></li>
      <?php endforeach; ?>
    </ul>
    <p style="margin:10px 0 0;color:#6b7280">
      회계 이력이 있는 거래처는 지우면 전표·원장 추적이 끊깁니다. 대신 <b>사용 중지</b>하면 목록·입력 화면에서 빠지고 기존 이력은 그대로 남습니다.
    </p>
  </div>

  <?php if ($isActive): ?>
    <?php if ($deaBlock): ?>
      <p style="color:#dc2626;max-width:640px"><?= h(implode(' / ', $deaBlock)) ?></p>
    <?php else: ?>
      <form method="post" action="partner_delete.php" style="display:inline"
            onsubmit="this.querySelector('button').disabled=true;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="act" value="deactivate">
        <button class="btn" type="submit">사용 중지로 변경</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p style="color:#6b7280">이미 사용 중지된 거래처입니다.</p>
  <?php endif; ?>
<?php else: ?>
  <div class="card" style="max-width:640px;padding:18px;margin-bottom:16px;border-left:4px solid #f59e0b">
    <strong>이 거래처는 연결된 전표·입금·잔액이 없어 삭제할 수 있습니다.</strong>
    <p style="margin:8px 0 0;color:#6b7280">삭제하면 복구할 수 없습니다. (감사 로그에 상호·사업자번호는 남습니다.)</p>
  </div>
  <form method="post" action="partner_delete.php" style="display:inline"
        onsubmit="if(!confirm('정말 삭제할까요? 복구할 수 없습니다.')){return false;} this.querySelector('button').disabled=true;">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="act" value="delete">
    <button class="btn" type="submit" style="background:#dc2626;color:#fff;border-color:#dc2626">삭제</button>
  </form>
<?php endif; ?>

<a class="btn" href="partners.php" style="margin-left:8px">목록으로</a>
<?php page_footer();
