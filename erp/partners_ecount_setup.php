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

$ddl = [
    'ecount_code'    => "ALTER TABLE erp_partners ADD COLUMN ecount_code VARCHAR(20) NULL, ADD UNIQUE KEY uq_partner_ecount (ecount_code)",
    'mobile'         => "ALTER TABLE erp_partners ADD COLUMN mobile VARCHAR(30) NOT NULL DEFAULT ''",
    'sms_phone'      => "ALTER TABLE erp_partners ADD COLUMN sms_phone VARCHAR(30) NOT NULL DEFAULT ''",
    'depositor'      => "ALTER TABLE erp_partners ADD COLUMN depositor VARCHAR(100) NOT NULL DEFAULT ''",
    'search_keyword' => "ALTER TABLE erp_partners ADD COLUMN search_keyword VARCHAR(100) NOT NULL DEFAULT ''",
];

$current = function () use ($pdo): array {
    $c = [];
    foreach ($pdo->query('SHOW COLUMNS FROM erp_partners')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c[] = $r['Field'];
    }
    return $c;
};

$log = [];
if (is_post()) {
    csrf_check();
    $have = $current();
    foreach ($ddl as $col => $sql) {
        if (in_array($col, $have, true)) {
            $log[] = [$col, '이미 있음 (변경 없음)'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $log[] = [$col, '추가 완료'];
        } catch (Throwable $e) {
            error_log('[ERP] partners_ecount_setup ' . $col . ': ' . $e->getMessage());
            $log[] = [$col, '실패: ' . $e->getMessage()];
        }
    }
}
$have = $current();

page_header('거래처 E카운트 컬럼 설치', 'partners');
?>
<div style="max-width:680px;background:#fff;border:1px solid #dfe3ea;border-radius:12px;padding:18px">
  <h2 style="margin-top:0">거래처 E카운트 컬럼 설치</h2>
  <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:12px">
    <?php foreach ($ddl as $col => $_): ?>
      <tr>
        <td style="padding:6px;border-bottom:1px solid #e5e9f0"><code><?= h($col) ?></code></td>
        <td style="padding:6px;border-bottom:1px solid #e5e9f0"><?= in_array($col, $have, true) ? '있음' : '없음' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php foreach ($log as $l): ?>
    <p style="margin:4px 0;font-size:13px"><b><?= h($l[0]) ?></b> : <?= h($l[1]) ?></p>
  <?php endforeach; ?>
  <?php if (count(array_diff(array_keys($ddl), $have)) > 0): ?>
    <p>DB 백업 후 눌러 주세요. 없는 컬럼만 추가합니다.</p>
    <form method="post"><?= csrf_field() ?><button class="btn" type="submit">없는 컬럼 추가 실행</button></form>
  <?php else: ?>
    <p style="color:#0b6b3a">모두 준비되었습니다. 이 파일을 서버에서 삭제하세요.</p>
  <?php endif; ?>
</div>
<?php page_footer();
