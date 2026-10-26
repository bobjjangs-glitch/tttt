<?php
// partner_bulk_delete.php — 거래처 선택 삭제 (관리자 전용, 확인 화면 → 실행)
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

$ledgerLib = __DIR__ . '/inc/ledger_lib.php';
if (is_file($ledgerLib)) {
    require_once $ledgerLib;
}

if ((current_user()['role'] ?? '') !== 'admin') {
    flash('거래처 삭제는 관리자만 할 수 있습니다.', 'err');
    redirect('partners.php');
    exit;
}

const PB_MAX = 200;

function pb_count(PDO $pdo, string $table, string $col, int $id): int
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) {
        throw new RuntimeException('내부 식별자 오류');
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = ?");
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return 0;
        }
        throw $e;
    }
}

/** 거래처를 참조하는 테이블 목록 (FK 자동 탐지 포함) */
function pb_targets(PDO $pdo): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = [
        'erp_trades'   => ['partner_id', '거래 전표'],
        'erp_payments' => ['partner_id', '입금 내역'],
    ];
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
        $name = (string)$r['tname'];
        if (isset($t[$name]) || $name === 'erp_partner_openings') {
            continue;
        }
        $t[$name] = [(string)$r['cname'], $name];
    }
    return $t;
}

/** 삭제 불가 사유 (빈 배열이면 삭제 가능) */
function pb_blockers(PDO $pdo, array $p): array
{
    $id  = (int)$p['id'];
    $out = [];

    foreach (pb_targets($pdo) as $table => $def) {
        $n = pb_count($pdo, $table, $def[0], $id);
        if ($n > 0) {
            $out[] = $def[1] . ' ' . number_format($n) . '건';
        }
    }

    try {
        $st = $pdo->prepare('SELECT ar_amount, ap_amount FROM erp_partner_openings WHERE partner_id = ?');
        $st->execute([$id]);
        $op = $st->fetch();
        if ($op && ((int)$op['ar_amount'] !== 0 || (int)$op['ap_amount'] !== 0)) {
            $out[] = '기초잔액 0원 아님';
        }
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') {
            throw $e;
        }
    }

    if ((int)($p['is_credit'] ?? 0) === 1) {
        $bal = null;
        if (function_exists('lg_balance') && function_exists('lg_ready_error')) {
            try {
                if (lg_ready_error() === null) {
                    $bal = (int)lg_balance($id);
                }
            } catch (Throwable $e) {
                $bal = null;
            }
        }
        if ($bal === null) {
            $out[] = '외상 잔액 확인 불가';
        } elseif ($bal !== 0) {
            $out[] = '외상 미수 ' . number_format($bal) . '원';
        }
    }
    return $out;
}

if (!is_post()) {
    redirect('partners.php');
    exit;
}
csrf_check();

// 선택된 id 정리
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
    flash('선택된 거래처가 없습니다.', 'err');
    redirect('partners.php');
    exit;
}
if (count($ids) > PB_MAX) {
    flash('한 번에 최대 ' . PB_MAX . '건까지만 삭제할 수 있습니다. 나눠서 진행하세요.', 'err');
    redirect('partners.php');
    exit;
}

$step = in_str($_POST, 'step', 10);

// ------------------------------------------------------------ 실행
if ($step === 'delete') {
    $deleted = 0;
    $skipped = 0;
    $failed  = 0;
    $now = date('Y-m-d H:i:s');

    foreach ($ids as $id) {
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM erp_partners WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p) {
                $pdo->rollBack();
                continue; // 이미 없음
            }
            if (pb_blockers($pdo, $p)) {
                $pdo->rollBack();
                $skipped++;
                continue;
            }
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
                throw new RuntimeException('삭제 행 수 불일치');
            }
            audit(
                'partner_delete',
                'partners',
                $id,
                '일괄삭제 상호=' . $p['name'] . ' / 사업자번호=' . ($p['business_no'] ?? '') . ' / 코드=' . ($p['ecount_code'] ?? '')
            );
            $pdo->commit();
            $deleted++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ERP] partner bulk delete id=' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
            $failed++;
        }
    }

    $msg = $deleted . '건 삭제';
    if ($skipped) {
        $msg .= ', ' . $skipped . '건 제외(이력 있음)';
    }
    if ($failed) {
        $msg .= ', ' . $failed . '건 실패(서버 로그 확인)';
    }
    flash($msg . '했습니다.', ($deleted > 0 && !$failed) ? 'ok' : 'err');
    redirect('partners.php');
    exit;
}

// ------------------------------------------------------------ 확인 화면
$in   = implode(',', array_fill(0, count($ids), '?'));
$st   = $pdo->prepare("SELECT * FROM erp_partners WHERE id IN ($in) ORDER BY name, id");
$st->execute($ids);
$rows = $st->fetchAll();

$okCount = 0;
$list    = [];
foreach ($rows as $p) {
    $b = pb_blockers($pdo, $p);
    if (!$b) {
        $okCount++;
    }
    $list[] = ['p' => $p, 'blockers' => $b];
}

page_header('거래처 선택 삭제', 'partners');
?>
<h2 style="margin:0 0 14px">거래처 선택 삭제 확인</h2>

<p style="max-width:760px">
  선택 <b><?= count($list) ?></b>건 중 삭제 가능 <b style="color:#16a34a"><?= $okCount ?></b>건,
  삭제 불가 <b style="color:#dc2626"><?= count($list) - $okCount ?></b>건입니다.
  삭제 불가 거래처는 건드리지 않습니다. 삭제는 복구할 수 없습니다.
</p>

<div class="card" style="max-width:760px;padding:0;margin-bottom:16px;overflow:auto">
  <table style="width:100%">
    <thead>
      <tr><th style="text-align:left">상호</th><th style="text-align:left">사업자번호</th><th style="text-align:left">결과</th></tr>
    </thead>
    <tbody>
    <?php foreach ($list as $r): $p = $r['p']; ?>
      <tr>
        <td><?= h($p['name']) ?></td>
        <td><?= ($p['business_no'] ?? '') !== '' ? h(fmt_biz_no($p['business_no'])) : '-' ?></td>
        <td>
          <?php if ($r['blockers']): ?>
            <span style="color:#dc2626">삭제 불가 — <?= h(implode(' / ', $r['blockers'])) ?></span>
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
  <form method="post" action="partner_bulk_delete.php" style="display:inline"
        onsubmit="if(!confirm('삭제 가능한 <?= (int)$okCount ?>건을 삭제합니다. 복구할 수 없습니다. 진행할까요?')){return false;} this.querySelector('button').disabled=true;">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="delete">
    <?php foreach ($ids as $id): ?>
      <input type="hidden" name="ids[]" value="<?= (int)$id ?>">
    <?php endforeach; ?>
    <button class="btn" type="submit" style="background:#dc2626;color:#fff;border-color:#dc2626">
      삭제 가능 <?= (int)$okCount ?>건 삭제
    </button>
  </form>
<?php endif; ?>
<a class="btn" href="partners.php" style="margin-left:8px">취소</a>
<?php page_footer();
