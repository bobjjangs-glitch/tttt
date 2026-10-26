<?php
// erp/trade_diag2.php — 전표 구조 · 결제수단 누락 진단 (읽기 전용, 사용 후 삭제)
require __DIR__ . '/inc/bootstrap.php';
require_login();
$u = current_user();
if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('관리자만 볼 수 있습니다.');
}
$pdo = db();

function td_run(PDO $pdo, string $sql): array
{
    try {
        return ['ok' => true, 'rows' => $pdo->query($sql)->fetchAll()];
    } catch (Throwable $e) {
        error_log('[ERP] trade_diag2: ' . $e->getMessage());
        return ['ok' => false, 'rows' => [], 'msg' => get_class($e)];
    }
}

function td_table(string $title, array $res): void
{
    echo '<h2>' . h($title) . '</h2>';
    if (!$res['ok']) {
        echo '<p style="color:#b91c1c">조회 실패: ' . h($res['msg'] ?? '') . '</p>';
        return;
    }
    if (!$res['rows']) {
        echo '<p>(결과 없음)</p>';
        return;
    }
    echo '<table border="1" cellpadding="6" style="border-collapse:collapse"><tr>';
    foreach (array_keys($res['rows'][0]) as $k) {
        echo '<th>' . h((string)$k) . '</th>';
    }
    echo '</tr>';
    foreach ($res['rows'] as $r) {
        echo '<tr>';
        foreach ($r as $v) {
            echo '<td>' . h($v === null ? 'NULL' : (string)$v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>전표 진단 2</title></head>
<body style="font-family:sans-serif;max-width:900px;margin:24px auto">
<h1>전표 구조 · 결제수단 진단 (읽기 전용)</h1>
<p>브라우저 번역을 끄고 보세요. 이 화면 전체를 복사해 보낸 뒤 서버에서 이 파일을 삭제하세요.</p>
<?php
td_table('1. erp_trade_lines 컬럼 (이름 / 타입 / NULL / 기본값)', td_run($pdo,
    "SELECT COLUMN_NAME AS col, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_trade_lines'
      ORDER BY ORDINAL_POSITION"));

td_table('2. erp_trades 컬럼 (이름 / 타입 / NULL)', td_run($pdo,
    "SELECT COLUMN_NAME AS col, COLUMN_TYPE AS type, IS_NULLABLE AS nullable
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_trades'
      ORDER BY ORDINAL_POSITION"));

td_table('3. 전표 건수 (유형 / 상태)', td_run($pdo,
    "SELECT trade_type, status, COUNT(*) AS cnt FROM erp_trades GROUP BY trade_type, status ORDER BY trade_type, status"));

td_table('4. 결제수단 분포 (유형 / 상태 / 결제수단)', td_run($pdo,
    "SELECT trade_type, status, COALESCE(NULLIF(pay_method, ''), '(미지정)') AS pay_method, COUNT(*) AS cnt
       FROM erp_trades GROUP BY trade_type, status, 3 ORDER BY trade_type, status, 3"));

td_table('5. 확정됐는데 결제수단이 비어 있는 전표 (자금일보 누락 위험)', td_run($pdo,
    "SELECT trade_type, COUNT(*) AS cnt FROM erp_trades
      WHERE status = 'confirmed' AND (pay_method IS NULL OR pay_method = '') GROUP BY trade_type"));

td_table('6. 확정 + 현금/이체/카드인데 계좌 미지정인 전표', td_run($pdo,
    "SELECT trade_type, COUNT(*) AS cnt FROM erp_trades
      WHERE status = 'confirmed' AND pay_method IN ('cash','transfer','card') AND account_id IS NULL GROUP BY trade_type"));

td_table('7. 자금 계좌 등록 수', td_run($pdo,
    "SELECT COUNT(*) AS total FROM erp_fund_accounts"));
?>
</body></html>
