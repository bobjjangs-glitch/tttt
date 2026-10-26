<?php
// erp/partner_code_fill.php — 코드 없는 거래처에 코드 채우기 (관리자 전용)
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/partner_code.php';
require_login();
if ((current_user()['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자 전용입니다.');
}
$pdo = db();

if (!pc_ready($pdo)) {
    exit('partner_code 컬럼이 없습니다. 먼저 partner_code_migrate.php 를 실행하세요.');
}

if (is_post()) {
    csrf_check();
    [$done, $fail] = pc_fill_missing($pdo, 500);
    try {
        audit('partner_code', 'partner', null, '채우기 성공 ' . $done . '건, 실패 ' . $fail . '건');
    } catch (Throwable $e) {
        error_log('[ERP] partner_code_fill audit: ' . $e->getMessage());
    }
    flash('코드 부여 ' . $done . '건' . ($fail ? ', 실패 ' . $fail . '건 (서버 로그의 [ERP] 확인)' : ''), $fail ? 'err' : 'ok');
    redirect('partner_code_fill.php');
}

$missing = (int)$pdo->query("SELECT COUNT(*) FROM erp_partners WHERE partner_code IS NULL OR partner_code = ''")->fetchColumn();
$total   = (int)$pdo->query('SELECT COUNT(*) FROM erp_partners')->fetchColumn();
$last    = (string)$pdo->query("SELECT MAX(partner_code) FROM erp_partners WHERE partner_code REGEXP '^" . PC_PREFIX . "[0-9]+$'")->fetchColumn();
?>
<!doctype html>
<html lang="ko" translate="no">
<head>
<meta charset="utf-8">
<meta name="google" content="notranslate">
<meta name="robots" content="noindex,nofollow">
<title>거래처 코드 채우기</title>
<style>
body{font:14px/1.6 sans-serif;margin:24px;max-width:700px}
.box{background:#f5f7fa;border:1px solid #d5dbe3;border-radius:8px;padding:12px 16px;margin:12px 0}
</style>
</head>
<body>
<h2>거래처 코드 채우기</h2>
<?= flash_render() ?>
<p>전체 <?= $total ?>건 / 코드 없음 <b><?= $missing ?></b>건 / 현재 마지막 코드 <?= h($last !== '' ? $last : '-') ?></p>
<?php if ($missing === 0): ?>
<p>모든 거래처에 코드가 있습니다.</p>
<?php else: ?>
<form method="post" class="box" onsubmit="return confirm('코드 없는 거래처에 순번 코드를 부여합니다. 계속할까요?');">
  <?= csrf_field() ?>
  <button type="submit">코드 없는 거래처 채우기 (한 번에 최대 500건)</button>
</form>
<?php endif; ?>
<p><a href="partners.php">거래처로 돌아가기</a></p>
</body>
</html>
