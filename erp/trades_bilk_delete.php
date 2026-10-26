<?php
// trades_bulk_delete.php — 전표 선택 삭제 (관리자 전용): 확인 화면 → 실행
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/trade_service.php';
$pdo = db();

if ((current_user()['role'] ?? '') !== 'admin') {
    flash('전표 선택 삭제는 관리자만 할 수 있습니다.', 'err');
    redirect('trades.php');
    exit;
}
if (!is_post()) {
    redirect('trades.php');
    exit;
}
csrf_check();

const TB_MAX = 100;

$type = in_str($_POST, 'type', 10);
if (!in_array($type, ['sale', 'purchase'], true)) {
    $type = 'sale';
}
$back = 'trades.php?type=' . $type;

$ids = [];
$raw = $_POST['ids'] ?? [];
if (is_array($raw)) {
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) {
            $ids[$n] = $n;
        }
    }
}
$ids = array_values($ids);
if (!$ids) {
    flash('선택된 전표가 없습니다.', 'err');
    redirect($back);
    exit;
}
if (count($ids) > TB_MAX) {
    flash('한 번에 최대 ' . TB_MAX . '건까지만 삭제할 수 있습니다. 나눠서 진행하세요.', 'err');
    redirect($back);
    exit;
}

/** 재고 이력/라인 테이블에 필요한 컬럼이 있는지 확인 (없으면 해당 삭제를 막는다) */
function tb_caps(PDO $pdo): array
{
    $cols = function (string $table) use ($pdo): array {
        $out = [];
        foreach ($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll() as $c) {
            $out[] = $c['Field'];
        }
        return $out;
    };
    $caps = ['ledger' => false, 'lines' => false];
    try {
        $l = $cols('erp_stock_ledger');
        $caps['ledger'] = in_array('trade_id', $l, true) && in_array('item_id', $l, true) && in_array('qty', $l, true);
        $n = $cols('erp_trade_lines');
        $caps['lines'] = in_array('trade_id', $n, true);
    } catch (Throwable $e) {
        error_log('[ERP] trades bulk caps: ' . $e->getMessage());
    }
    return $caps;
}

/** 삭제 불가 사유 (빈 배열이면 삭제 가능) */
function tb_blockers(PDO $pdo, array $t, array $caps): array
{
    $status = (string)$t['status'];
    $id     = (int)$t['id'];

    if ($status === 'confirmed') {
        return ['확정 전표입니다. 상세 화면에서 먼저 취소하세요.'];
    }
    if ($status !== 'draft' && $status !== 'canceled') {
        return ['알 수 없는 상태입니다(' . $status . ').'];
    }

    if ($status === 'draft') {
        if ($caps['ledger']) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM erp_stock_ledger WHERE trade_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                return ['임시 전표인데 재고 이력이 있습니다. 관리자 점검이 필요합니다.'];
            }
        }
        return [];
    }

    // canceled
    if (!$caps['ledger'] || !$caps['lines']) {
        return ['재고 이력/라인 테이블 구조를 확인할 수 없어 삭제를 막았습니다.'];
    }
    $st = $pdo->prepare(
        'SELECT item_id, SUM(qty) AS s FROM erp_stock_ledger WHERE trade_id = ? GROUP BY item_id HAVING SUM(qty) <> 0'
    );
    $st->execute([$id]);
    $bad = $st->fetchAll();
    if ($bad) {
        return ['재고 이력의 합이 0이 아닙니다(품목 ' . count($bad) . '개). 재고가 틀어질 수 있어 삭제를 막았습니다.'];
    }
    return [];
}

$caps = tb_caps($pdo);
$step = in_str($_POST, 'step', 10);

// ---------------------------------------------------------------- 실행
if ($step === 'delete') {
    $deleted = 0;
    $skipped = 0;
    $failed  = 0;

    foreach ($ids as $id) {
        try {
            $st = $pdo->prepare('SELECT * FROM erp_trades WHERE id = ?');
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) {
                continue; // 이미 없음
            }
            $detail = 'trade_no=' . $t['trade_no'] . ' / total=' . $t['total_amount']
                . ' / status=' . $t['status'] . ' / 일괄삭제';

            if ($t['status'] === 'draft') {
                if (tb_blockers($pdo, $t, $caps)) {
                    $skipped++;
                    continue;
                }
                delete_draft((int)$id); // 기존 단건 삭제 로직 그대로 사용
                $chk = $pdo->prepare('SELECT COUNT(*) FROM erp_trades WHERE id = ?');
                $chk->execute([$id]);
                if ((int)$chk->fetchColumn() > 0) {
                    throw new RuntimeException('delete_draft 후에도 전표가 남아 있음');
                }
                audit('trade_delete_draft', 'trades', (int)$id, $detail);
                $deleted++;
                continue;
            }

            // canceled: 전표 단위 트랜잭션
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM erp_trades WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $t = $lock->fetch();
            if (!$t) {
                $pdo->rollBack();
                continue;
            }
            if (tb_blockers($pdo, $t, $caps)) {
                $pdo->rollBack();
                $skipped++;
                continue;
            }
            $pdo->prepare('DELETE FROM erp_stock_ledger WHERE trade_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM erp_trade_lines WHERE trade_id = ?')->execute([$id]);
            $del = $pdo->prepare('DELETE FROM erp_trades WHERE id = ?');
            $del->execute([$id]);
            if ($del->rowCount() !== 1) {
                throw new RuntimeException('삭제 행 수 불일치');
            }
            audit('trade_purge', 'trades', (int)$id, $detail . ' / reason=' . ($t['cancel_reason'] ?? ''));
            $pdo->commit();
            $deleted++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ERP] trades bulk delete id=' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
            $failed++;
        }
    }

    $msg = $deleted . '건 삭제';
    if ($skipped) {
        $msg .= ', ' . $skipped . '건 제외';
    }
    if ($failed) {
        $msg .= ', ' . $failed . '건 실패(서버 로그 확인)';
    }
    flash($msg . '했습니다.', ($deleted > 0 && !$failed) ? 'ok' : 'err');
    redirect($back);
    exit;
}

// ---------------------------------------------------------------- 확인 화면
$in = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare(
    "SELECT t.id, t.trade_no, t.trade_date, t.status, t.total_amount, t.customer_name, t.cancel_reason, p.name AS partner_name
       FROM erp_trades t LEFT JOIN erp_partners p ON p.id = t.partner_id
      WHERE t.id IN ($in) ORDER BY t.trade_date DESC, t.id DESC"
);
$st->execute($ids);
$list = [];
$okCount = 0;
foreach ($st->fetchAll() as $t) {
    $b = tb_blockers($pdo, $t, $caps);
    if (!$b) {
        $okCount++;
    }
    $list[] = ['t' => $t, 'b' => $b];
}

page_header('전표 선택 삭제', 'trades');
?>
<h2 style="margin:0 0 14px">전표 선택 삭제 확인</h2>

<p style="max-width:860px">
  선택 <b><?= count($list) ?></b>건 중 삭제 가능 <b style="color:#16a34a"><?= $okCount ?></b>건,
  삭제 불가 <b style="color:#dc2626"><?= count($list) - $okCount ?></b>건입니다.
  삭제 불가 전표는 건드리지 않습니다. 삭제한 전표번호는 다시 쓰이지 않아 번호에 빈 자리가 생깁니다. 복구할 수 없습니다.
</p>

<div class="card" style="max-width:860px;padding:0;margin-bottom:16px;overflow:auto">
  <table style="width:100%">
    <thead>
      <tr>
        <th style="text-align:left">전표번호</th><th>일자</th><th style="text-align:left">거래처/고객</th>
        <th style="text-align:right">합계</th><th>상태</th><th style="text-align:left">결과</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($list as $r): $t = $r['t']; ?>
      <tr>
        <td><?= h($t['trade_no']) ?></td>
        <td style="text-align:center"><?= h($t['trade_date']) ?></td>
        <td><?= h(($t['partner_name'] ?? '') !== '' ? $t['partner_name'] : ($t['customer_name'] ?? '')) ?></td>
        <td style="text-align:right"><?= h(fmt_won($t['total_amount'])) ?></td>
        <td style="text-align:center"><?= h(status_label((string)$t['status'])) ?></td>
        <td>
          <?php if ($r['b']): ?>
            <span style="color:#dc2626">삭제 불가 — <?= h(implode(' / ', $r['b'])) ?></span>
          <?php else: ?>
            <span style="color:#16a34a">삭제 가능</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($okCount > 0): ?>
  <form method="post" action="trades_bulk_delete.php" style="display:inline"
        onsubmit="if(!confirm('삭제 가능한 <?= (int)$okCount ?>건을 삭제합니다. 복구할 수 없습니다. 진행할까요?')){return false;} this.querySelector('button').disabled=true;">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="delete">
    <input type="hidden" name="type" value="<?= h($type) ?>">
    <?php foreach ($ids as $id): ?>
      <input type="hidden" name="ids[]" value="<?= (int)$id ?>">
    <?php endforeach; ?>
    <button class="btn" type="submit" style="background:#dc2626;color:#fff;border-color:#dc2626">
      삭제 가능 <?= (int)$okCount ?>건 삭제
    </button>
  </form>
<?php endif; ?>
<a class="btn" href="<?= h($back) ?>" style="margin-left:8px">취소</a>
<?php page_footer();
