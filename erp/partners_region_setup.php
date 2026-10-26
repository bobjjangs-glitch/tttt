<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/partner_lib.php';
require_login();

$me = current_user();
if (!is_array($me) || ($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 실행할 수 있습니다.');
}
$pdo = db();
$has = in_array('region', pt_cols($pdo), true);
$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    if ($has) {
        $msg = '이미 region 컬럼이 있습니다. 아무것도 변경하지 않았습니다.';
    } else {
        try {
            $pdo->exec("ALTER TABLE erp_partners
                ADD COLUMN region VARCHAR(10) NOT NULL DEFAULT '' AFTER address,
                ADD KEY idx_partner_region (region)");
            $has = true;
            $filled = 0;
            $rows = $pdo->query("SELECT id, address FROM erp_partners WHERE address <> ''")->fetchAll();
            $up = $pdo->prepare('UPDATE erp_partners SET region = ? WHERE id = ?');
            foreach ($rows as $r) {
                $reg = pt_region_from_address((string)$r['address']);
                if ($reg !== '') {
                    $up->execute([$reg, (int)$r['id']]);
                    $filled++;
                }
            }
            $msg = 'region 컬럼을 추가했습니다. 기존 주소에서 지역을 자동 지정한 거래처: ' . $filled . '건. 이 파일을 서버에서 삭제하세요.';
        } catch (Throwable $e) {
            error_log('[ERP] partners_region_setup: ' . $e->getMessage());
            $err = '컬럼 추가 중 오류: ' . $e->getMessage();
        }
    }
}

page_header('거래처 지역 컬럼 설치', 'partners');
?>
<div style="max-width:640px;background:#fff;border:1px solid #dfe3ea;border-radius:12px;padding:18px">
  <h2 style="margin-top:0">거래처 지역 컬럼 설치</h2>
  <?php if ($err !== ''): ?><p style="color:#8a1f17"><?= h($err) ?></p><?php endif; ?>
  <?php if ($msg !== ''): ?><p style="color:#0b6b3a"><?= h($msg) ?></p><?php endif; ?>
  <p>현재 상태: <b><?= $has ? 'region 컬럼 있음 (설치 불필요)' : 'region 컬럼 없음' ?></b></p>
  <?php if (!$has): ?>
  <p>실행하면 <code>erp_partners</code>에 <code>region</code> 컬럼을 추가합니다. DB 백업 후 눌러 주세요.</p>
  <form method="post"><?= csrf_field() ?><button class="btn" type="submit">컬럼 추가 실행</button></form>
  <?php endif; ?>
</div>
<?php page_footer();
