<?php
// erp/trade_columns_setup.php — 전표 컬럼 추가 (1회용, 실행 후 삭제)
require __DIR__ . '/inc/bootstrap.php';
require_login();
$u = current_user();
if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 실행할 수 있습니다.');
}
$pdo = db();

// [테이블, 이름, 종류(col|check), 실행할 SQL]
$steps = [
    ['erp_trade_lines', 'item_code', 'col',
        "ALTER TABLE erp_trade_lines ADD COLUMN item_code VARCHAR(40) NOT NULL DEFAULT '' AFTER item_id"],
    ['erp_trade_lines', 'pattern_name', 'col',
        "ALTER TABLE erp_trade_lines ADD COLUMN pattern_name VARCHAR(80) NOT NULL DEFAULT '' AFTER item_name"],
    ['erp_trade_lines', 'list_price', 'col',
        "ALTER TABLE erp_trade_lines ADD COLUMN list_price BIGINT NOT NULL DEFAULT 0 AFTER qty"],
    ['erp_trade_lines', 'dc_rate', 'col',
        "ALTER TABLE erp_trade_lines ADD COLUMN dc_rate INT NOT NULL DEFAULT 0 AFTER list_price"],
    ['erp_trade_lines', 'erp_ck_line_dc', 'check',
        "ALTER TABLE erp_trade_lines ADD CONSTRAINT erp_ck_line_dc CHECK (dc_rate BETWEEN 0 AND 10000)"],
    ['erp_trades', 'delivery_addr', 'col',
        "ALTER TABLE erp_trades ADD COLUMN delivery_addr VARCHAR(200) NOT NULL DEFAULT '' AFTER vehicle_no"],
];

function tcs_exists(PDO $pdo, string $table, string $name, string $kind): bool
{
    if ($kind === 'col') {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
    } else {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
                AND CONSTRAINT_TYPE = 'CHECK'"
        );
    }
    $st->execute([$table, $name]);
    return (int)$st->fetchColumn() > 0;
}

function tcs_rows(PDO $pdo, string $table): string
{
    try {
        return (string)(int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    } catch (Throwable $e) {
        return '조회 실패';
    }
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
            [$table, $name, $kind, $sql] = $s;
            $label = $table . '.' . $name;
            try {
                if (tcs_exists($pdo, $table, $name, $kind)) {
                    $results[] = [$label, '이미 있음 (건너뜀)', true];
                    continue;
                }
                $pdo->exec($sql);
                $results[] = [$label, '추가 완료', true];
            } catch (Throwable $e) {
                error_log('[ERP] trade_columns_setup ' . $label . ': ' . $e->getMessage());
                $results[] = [$label, '실패: ' . get_class($e) . ' (서버 로그 확인)', false];
                $stopped = true;
                break; // 한 단계라도 실패하면 이후 단계는 실행하지 않음
            }
        }
        try {
            audit('trade_cols', 'setup', null, json_encode($results, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            error_log('[ERP] trade_columns_setup audit: ' . $e->getMessage());
        }
    }
}

// 현재 상태 (미리보기 겸 결과 확인)
$status = [];
foreach ($steps as $s) {
    try {
        $status[] = [$s[0] . '.' . $s[1], tcs_exists($pdo, $s[0], $s[1], $s[2])];
    } catch (Throwable $e) {
        $status[] = [$s[0] . '.' . $s[1], null];
    }
}
$allDone = true;
foreach ($status as $st) {
    if ($st[1] !== true) { $allDone = false; }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>전표 컬럼 추가</title></head>
<body style="font-family:sans-serif;max-width:760px;margin:24px auto">
<h1>전표 컬럼 추가 (1회용)</h1>
<p>브라우저 번역을 끄고 보세요. DDL은 실행 즉시 확정되며 되돌릴 수 없습니다. 실행 전에 phpMyAdmin에서 DB 백업을 받으세요.</p>
<p>전표 건수: <?= h(tcs_rows($pdo, 'erp_trades')) ?>건 / 전표 줄: <?= h(tcs_rows($pdo, 'erp_trade_lines')) ?>건</p>

<?php if ($error): ?>
  <p style="color:#b91c1c"><?= h($error) ?></p>
<?php endif; ?>

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
    <p style="color:#b91c1c">실패해서 중간에 멈췄습니다. 이 화면을 그대로 복사해 보내 주세요. 같은 파일을 다시 실행해도 이미 추가된 항목은 건너뜁니다.</p>
  <?php endif; ?>
<?php endif; ?>

<?php if ($allDone): ?>
  <p style="color:#166534"><strong>모든 항목이 적용된 상태입니다.</strong> 이 파일을 서버에서 삭제하세요.</p>
<?php else: ?>
  <form method="post" onsubmit="return confirm('컬럼을 추가합니다. 되돌릴 수 없습니다. 계속할까요?');">
    <?= csrf_field() ?>
    <p><label><input type="checkbox" name="backup_ok" value="1"> DB 백업을 받았습니다</label></p>
    <button type="submit">실행</button>
  </form>
<?php endif; ?>
</body></html>
