<?php
// DB에 접속하지 않고 SQL 변환 규칙만 검사한다. 확인 후 삭제.
require __DIR__ . '/inc/functions.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$cases = [
    ['SELECT id FROM users WHERE login_id = ?',
     'SELECT id FROM erp_users WHERE login_id = ?'],
    ['SELECT t.*, p.name AS partner_name FROM trades t LEFT JOIN partners p ON p.id = t.partner_id',
     'SELECT t.*, p.name AS partner_name FROM erp_trades t LEFT JOIN erp_partners p ON p.id = t.partner_id'],
    ['DELETE FROM trade_lines WHERE trade_id = ?',
     'DELETE FROM erp_trade_lines WHERE trade_id = ?'],
    ['SELECT l.*, i.is_stock_item FROM trade_lines l JOIN items i ON i.id = l.item_id',
     'SELECT l.*, i.is_stock_item FROM erp_trade_lines l JOIN erp_items i ON i.id = l.item_id'],
    ['CONSTRAINT fk_items_cat FOREIGN KEY (category_id) REFERENCES categories (id)',
     'CONSTRAINT erp_fk_items_cat FOREIGN KEY (category_id) REFERENCES erp_categories (id)'],
    ['INSERT INTO doc_sequences (prefix, seq_date, last_no) VALUES (?, ?, 1)',
     'INSERT INTO erp_doc_sequences (prefix, seq_date, last_no) VALUES (?, ?, 1)'],
    ['SELECT item_id FROM stock_ledger GROUP BY item_id',
     'SELECT item_id FROM erp_stock_ledger GROUP BY item_id'],
    ['INSERT INTO audit_logs (user_id) VALUES (?)',
     'INSERT INTO erp_audit_logs (user_id) VALUES (?)'],
    // 이미 접두사가 있거나 컬럼/인덱스 이름인 경우는 그대로여야 한다
    ['SELECT * FROM erp_users',
     'SELECT * FROM erp_users'],
    ['UNIQUE KEY uq_items_code (item_code), KEY idx_trade_date (trade_date)',
     'UNIQUE KEY uq_items_code (item_code), KEY idx_trade_date (trade_date)'],
];

$bad = 0;
foreach ($cases as $i => [$in, $want]) {
    $got = ErpPdo::t($in);
    $ok = $got === $want;
    $bad += $ok ? 0 : 1;
    echo ($ok ? 'OK   ' : 'FAIL ') . ($i + 1) . "\n";
    if (!$ok) {
        echo "  기대: $want\n  결과: $got\n";
    }
}
echo "\n" . ($bad === 0 ? '모두 통과' : $bad . '건 실패 - 설치하지 마세요') . "\n";
