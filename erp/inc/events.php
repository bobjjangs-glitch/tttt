<?php
// inc/events.php — 일정(달력) 기능. 테이블 erp_events 는 처음 호출 시 자동 생성.

function ev_categories(): array
{
    // 키 => [표시 이름, 색]
    return [
        'general'  => ['일반',       '#6b7280'],
        'delivery' => ['납품·배송',  '#2563eb'],
        'collect'  => ['수금·결제',  '#059669'],
        'tax'      => ['세무·신고',  '#d97706'],
        'service'  => ['정비·예약',  '#7c3aed'],
    ];
}

function ev_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    $exists = $pdo->query("SHOW TABLES LIKE 'erp_events'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(100) NOT NULL,
            category VARCHAR(20) NOT NULL DEFAULT 'general',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            memo VARCHAR(500) NOT NULL DEFAULT '',
            is_done TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_events_range (start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done = true;
}

/** $from~$to(Y-m-d)와 기간이 겹치는 일정 */
function ev_fetch_range(string $from, string $to): array
{
    $st = db()->prepare(
        "SELECT id, title, category, start_date, end_date, memo, is_done, created_by
           FROM erp_events
          WHERE start_date <= ? AND end_date >= ?
          ORDER BY start_date, id"
    );
    $st->execute([$to, $from]);
    return $st->fetchAll();
}
