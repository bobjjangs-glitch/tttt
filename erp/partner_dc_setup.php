<?php
// erp/partner_dc_setup.php — DC율 저장소 생성 (1회용, 실행 후 삭제)
require __DIR__ . '/inc/bootstrap.php';
require_login();
$u = current_user();
if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 실행할 수 있습니다.');
}
require_once __DIR__ . '/inc/dc_lib.php';
$pdo = db();

// [표시 이름, 종류(col|check|table), 대상, 이름, 실행할 SQL]
$steps = [
    ['erp_partners.dc_rate', 'col', 'erp_partners', 'dc_rate',
        "ALTER TABLE erp_partners ADD COLUMN dc_rate INT NOT NULL DEFAULT 0"],
    ['erp_partners CHECK (dc_rate 0~10000)', 'check', 'erp_partners', 'erp_ck_partner_dc',
        "ALTER TABLE erp_partners ADD CONSTRAINT erp_ck_partner_dc CHECK (dc_rate BETWEEN 0 AND 10000)"],
    ['erp_dc_log 테이블', 'table', 'erp_dc_log', 'erp_dc_log',
        "CREATE TABLE erp_dc_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_id BIGINT UNSIGNED NOT NULL,
            old_rate INT NOT NULL,
            new_rate INT NOT NULL,
            source VARCHAR(10) NOT NULL,
            changed_by BIGINT UNSIGNED NOT NULL,
            changed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_dclog_partner (partner_id, changed_at)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
];

function pds_exists(PDO $pdo, string $kind, string $target, string $name): bool
{
    if ($kind === 'col') {
        return dc_has_col($pdo, $target, $name);
    }
    if ($kind === 'table') {
        return dc_has_table($pdo, $target);
    }
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'"
    );
    $st->execute([$target, $name]);
    return (int)$st->fetchColumn() > 0;
}

$results = [];
$stopped = false;
$error   = '';

if (is_post()) {
    csrf_check();
    if (empty($_POST['backup_ok'])) {
        $error = 'DB 백업을 했는지 체크해야 실행됩니다.';
    } else {
        foreach ($steps as $s) {
            [$label, $kind, $target, $name, $sql] = $s;
            try {
                if (pds_exists($pdo, $kind, $target, $name)) {
                    $results[] = [$label, '이미 있음 (건너뜀)', true];
                    continue;
                }
                $pdo->exec($sql);
                $results[] = [$label, '완료', true];
            } catch (Throwable $e) {
                error_log('[ERP] partner_dc_setup ' . $label . ': ' . $e->getMessage());
                $results[] = [$label, '실패: ' . get_class($e) . ' (서버 로그 확인)', false];
                $stopped = true;
                break;
            }
        }
        try {
            audit('dc_setup', 'setup', null, json_encode($results, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            error_log('[ERP] partner_dc_setup audit: ' . $e->getMessage());
        }
    }
}

$status = [];
$allDone = true;
foreach ($steps as $s) {
    try {
        $ok = pds_exists($pdo, $s[1], $s[2], $s[3]);
    } catch (Throwable $e) {
        $ok = null;
    }
    $status[] = [$s[0], $ok];
    if ($ok !== true) {
        $allDone = false;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>DC율 저장소 생성</title></head>
<body style="font-family:sans-serif;max-width:760px;margin:24px auto">
<h1>DC율 저장소 생성 (1회용)</h1>
<p>브라우저 번역을 끄고 보세요. DDL은 실행 즉시 확정되며 되돌릴 수 없습니다. 실행 전에 phpMyAdmin에서 DB 백업을 받으세요.</p>
<?php if ($error): ?><p style="color:#b91c1c"><?= h($error) ?></p><?php endif; ?>

<h2>현재 상태</h2>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>대상</th><th>상태</th></tr>
<?php foreach ($status as $st): ?>
  <tr><td><?= h($st[0]) ?></td><td><?= $st[1] === null ? '확인 실패' : ($st[1] ? '있음' : '없음') ?></td></tr>
<?php endforeach; ?>
</table>

<?php if ($results): ?>
  <h2>실행 결과</h2>
  <table border="1" cellpadding="6" style="border-collapse:collapse">
  <?php foreach ($results as $r): ?>
    <tr><td><?= h($r[0]) ?></td><td style="color:<?= $r[2] ? '#166534' : '#b91c1c' ?>"><?= h($r[1]) ?></td></tr>
  <?php endforeach; ?>
  </table>
  <?php if ($stopped): ?>
    <p style="color:#b91c1c">실패해서 멈췄습니다. 이 화면을 복사해 보내 주세요. 다시 실행해도 이미 된 항목은 건너뜁니다.</p>
  <?php endif; ?>
<?php endif; ?>

<?php if ($allDone): ?>
  <p style="color:#166534"><strong>모든 항목이 적용된 상태입니다.</strong> 이 파일을 서버에서 삭제하세요.</p>
<?php else: ?>
  <form method="post" onsubmit="return confirm('DC율 저장소를 만듭니다. 되돌릴 수 없습니다. 계속할까요?');">
    <?= csrf_field() ?>
    <p><label><input type="checkbox" name="backup_ok" value="1"> DB 백업을 받았습니다</label></p>
    <button type="submit">실행</button>
  </form>
<?php endif; ?>
</body></html>
