<?php
// erp/fund_setup.php — 자금 테이블 추가 (1회용, 관리자 전용, 사용 후 서버에서 삭제)
require __DIR__ . '/inc/bootstrap.php';
require_login();
header('Content-Type: text/html; charset=utf-8');

if ((current_user()['role'] ?? '') !== 'admin') {
    exit('관리자만 실행할 수 있습니다.');
}

$pdo = db();

function fs_table_exists(PDO $pdo, string $t): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}

function fs_col_exists(PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$t, $c]);
    return (int)$st->fetchColumn() > 0;
}

$steps = [
    [
        '1. erp_fund_accounts 생성 (자금 계좌 마스터)',
        fn() => fs_table_exists($pdo, 'erp_fund_accounts'),
        'CREATE TABLE erp_fund_accounts ('
        . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' name VARCHAR(60) NOT NULL,'
        . " kind VARCHAR(10) NOT NULL DEFAULT 'bank',"
        . ' opening_amount BIGINT NOT NULL DEFAULT 0,'
        . ' opening_date DATE NOT NULL,'
        . ' sort_no INT NOT NULL DEFAULT 0,'
        . ' is_active TINYINT(1) NOT NULL DEFAULT 1,'
        . " memo VARCHAR(200) NOT NULL DEFAULT '',"
        . ' created_at DATETIME NOT NULL,'
        . ' updated_at DATETIME NOT NULL,'
        . ' PRIMARY KEY (id),'
        . ' UNIQUE KEY uq_fund_account_name (name)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ],
    [
        '2. erp_fund_entries 생성 (기타입출금, 계좌간이동)',
        fn() => fs_table_exists($pdo, 'erp_fund_entries'),
        'CREATE TABLE erp_fund_entries ('
        . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' entry_date DATE NOT NULL,'
        . ' account_id BIGINT UNSIGNED NOT NULL,'
        . ' direction VARCHAR(3) NOT NULL,'
        . " kind VARCHAR(12) NOT NULL DEFAULT 'other',"
        . ' amount BIGINT NOT NULL,'
        . ' transfer_key VARCHAR(40) DEFAULT NULL,'
        . " memo VARCHAR(200) NOT NULL DEFAULT '',"
        . " status VARCHAR(10) NOT NULL DEFAULT 'active',"
        . ' canceled_at DATETIME DEFAULT NULL,'
        . " cancel_reason VARCHAR(200) NOT NULL DEFAULT '',"
        . ' created_by BIGINT UNSIGNED NOT NULL,'
        . ' created_at DATETIME NOT NULL,'
        . ' PRIMARY KEY (id),'
        . ' KEY idx_fund_acct_date (account_id, entry_date),'
        . ' KEY idx_fund_transfer (transfer_key),'
        . ' CONSTRAINT erp_ck_fund_amount CHECK (amount > 0),'
        . ' CONSTRAINT erp_fk_fund_account FOREIGN KEY (account_id) REFERENCES erp_fund_accounts (id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ],
    [
        '3. erp_trades.account_id 추가 (전표 입금/출금 계좌)',
        fn() => fs_col_exists($pdo, 'erp_trades', 'account_id'),
        'ALTER TABLE erp_trades'
        . ' ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER pay_method,'
        . ' ADD KEY idx_trade_account (account_id),'
        . ' ADD CONSTRAINT erp_fk_trade_account FOREIGN KEY (account_id) REFERENCES erp_fund_accounts (id)',
    ],
    [
        '4. erp_payments.account_id 추가 (입금등록 계좌)',
        fn() => fs_col_exists($pdo, 'erp_payments', 'account_id'),
        'ALTER TABLE erp_payments'
        . ' ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER partner_id,'
        . ' ADD KEY idx_pay_account (account_id),'
        . ' ADD CONSTRAINT erp_fk_pay_account FOREIGN KEY (account_id) REFERENCES erp_fund_accounts (id)',
    ],
];

$err = '';
$results = [];

if (is_post()) {
    csrf_check();
    if (empty($_POST['backup_done'])) {
        $err = 'DB 백업을 했다는 체크가 필요합니다.';
    } else {
        foreach ($steps as [$label, $exists, $sql]) {
            if ($exists()) {
                $results[] = [$label, '이미 있음 (건너뜀)', false];
                continue;
            }
            try {
                $pdo->exec($sql);
               try {
    audit('fund_setup', 'step' . mb_substr($label, 0, 1), null, $label);
} catch (Throwable $e) {
    error_log('[ERP] fund_setup audit: ' . $e->getMessage());
}
                $results[] = [$label, '완료', false];
            } catch (Throwable $e) {
                error_log('[ERP] fund_setup: ' . $e->getMessage());
                $results[] = [$label, '실패: ' . get_class($e) . ' / ' . $e->getMessage(), true];
                break;
            }
        }
    }
}
?>
<!doctype html>
<html lang="ko" translate="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>자금 테이블 설정</title>
</head>
<body style="font-family:sans-serif;max-width:760px;margin:24px auto;padding:0 16px">
<h2>자금 테이블 설정 (1회용)</h2>
<?php if ($err): ?><p style="color:#dc2626"><b><?= h($err) ?></b></p><?php endif; ?>

<h3>현재 상태</h3>
<table border="1" cellpadding="6" style="border-collapse:collapse;width:100%">
<?php foreach ($steps as [$label, $exists, $sql]): ?>
  <tr>
    <td><?= h($label) ?></td>
    <td><?= $exists() ? 'OK 있음' : '없음 (실행 시 추가)' ?></td>
  </tr>
<?php endforeach; ?>
</table>

<?php if ($results): ?>
<h3>실행 결과</h3>
<table border="1" cellpadding="6" style="border-collapse:collapse;width:100%">
<?php foreach ($results as [$label, $msg, $bad]): ?>
  <tr>
    <td><?= h($label) ?></td>
    <td style="color:<?= $bad ? '#dc2626' : '#15803d' ?>"><?= h($msg) ?></td>
  </tr>
<?php endforeach; ?>
</table>
<p>이 화면 전체를 복사해서 보내 주세요. 모두 완료됐다면 이 파일을 서버에서 삭제하세요.</p>
<?php endif; ?>

<form method="post" style="margin-top:20px">
  <?= csrf_field() ?>
  <p><label><input type="checkbox" name="backup_done" value="1"> phpMyAdmin으로 DB 백업(내보내기)을 마쳤습니다.</label></p>
  <p><button type="submit">실행</button></p>
</form>
</body>
</html>
