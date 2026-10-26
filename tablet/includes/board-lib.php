<?php
declare(strict_types=1);
/* 현황판 공통 라이브러리 — 태블릿 부트스트랩(세션/DB/ensure_*) 재사용 */
require_once __DIR__ . '/bootstrap.php';

/** 현황판용 스키마 보장: tt_reservations.checked_in_at + tt_board_walkins 테이블
 *  주의: SchemaGuard 키를 _v2로 바꿨다. 이전 키는 15분간 "점검 완료"로 캐시돼 있어
 *  그대로 쓰면 새 테이블이 최대 15분 동안 생성되지 않는다. */
function ensure_board_columns(): void
{
    static $checked = false;
    if (sg_skip('ensure_board_columns_v2', $checked)) return;
    try {
        ensure_reservations_table();
        $pdo  = Database::connection();
        $cols = $pdo->query('SHOW COLUMNS FROM tt_reservations')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('checked_in_at', $cols, true)) {
            $pdo->exec('ALTER TABLE tt_reservations ADD COLUMN checked_in_at DATETIME NULL DEFAULT NULL');
        }
        /* work_log_id에는 FK를 걸지 않는다 (tt_work_logs.id 타입을 확인하지 못했고,
           작업일지 삭제가 입고 기록 때문에 막히면 안 되므로) */
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS tt_board_walkins (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                visit_date  DATE         NOT NULL,
                plate_no    VARCHAR(30)  NOT NULL,
                work_log_id BIGINT UNSIGNED NULL DEFAULT NULL,
                created_by  VARCHAR(50)  NULL,
                created_at  DATETIME     NOT NULL,
                KEY idx_visit_date (visit_date),
                KEY idx_work_log (work_log_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        sg_error_log('[ensure_board_columns] ' . $e->getMessage());
    }
    if (!SchemaGuard::hadErrors()) SchemaGuard::mark('ensure_board_columns_v2');
}

function board_token(): string
{
    return trim(get_setting('board_token', ''));
}

function board_has_session(): bool
{
    return tablet_current_worker() !== null || AdminAuth::isLoggedIn();
}

/** 세션(작업자/관리자) 또는 TV 토큰. API는 헤더만, 페이지는 ?key= 도 허용 */
function board_authorized(bool $allowQueryKey = false): bool
{
    if (board_has_session()) return true;
    $stored = board_token();
    if ($stored === '') return false;
    $given = (string)($_SERVER['HTTP_X_BOARD_KEY'] ?? '');
    if ($given === '' && $allowQueryKey) $given = (string)($_GET['key'] ?? '');
    return $given !== '' && hash_equals($stored, $given);
}

/** 예약 건의 현황판 상태 */
function board_state(string $resvStatus, ?string $workStatus, $checkedInAt): string
{
    if ($workStatus === 'done') return 'done';
    if ($workStatus !== null && $workStatus !== '') return 'working';
    if ($resvStatus === 'visited' || !empty($checkedInAt)) return 'checkin';
    return 'reserved';
}

function board_plate(?string $carInfo): string
{
    $carInfo = trim((string)$carInfo);
    if ($carInfo === '') return '번호미입력';
    $plate = function_exists('extract_plate_no') ? trim((string)extract_plate_no($carInfo)) : '';
    return $plate !== '' ? $plate : mb_substr($carInfo, 0, 12);
}

/** 현장 입고 기록 → 작업일지 연결 (log_save 저장 직후 호출).
 *  [FIX] 연결 가능 조건을 화면 목록(walkin.php / board_fetch)과 같은 기준으로 맞춘다.
 *  - work_log_id가 NULL이거나
 *  - 연결돼 있던 작업일지가 삭제돼 사라진 경우(목록에는 다시 "입고중"으로 보이는 건)
 *  예전에는 IS NULL만 허용해서, 작업일지를 지운 뒤 다시 나타난 입고 건은 새 작업일지와
 *  연결되지 않았다. 살아 있는 작업일지에 연결된 건은 여전히 건드리지 않는다. */
function board_link_walkin(int $walkinId, int $workLogId): void
{
    if ($walkinId <= 0 || $workLogId <= 0) return;
    ensure_board_columns();
    Database::connection()
        ->prepare(
            'UPDATE tt_board_walkins SET work_log_id = :l
              WHERE id = :id
                AND (work_log_id IS NULL
                     OR NOT EXISTS (SELECT 1 FROM tt_work_logs w WHERE w.id = tt_board_walkins.work_log_id))'
        )
        ->execute(['l' => $workLogId, 'id' => $walkinId]);
}

/** 현황판 데이터의 단일 출처 — 나중에 SSE로 바꿔도 이 함수는 그대로 쓴다.
 *  항목 key는 출처 접두어(r=예약, i=현장입고, w=현장작업) + id.
 *  서로 다른 테이블의 id가 겹치므로 key 없이 id만 쓰면 화면에서 충돌한다. */
function board_fetch(string $date): array
{
    ensure_board_columns();
    $pdo   = Database::connection();
    $items = [];

    /* 1) 예약 건 */
    $stmt = $pdo->prepare(
        "SELECT r.id, r.visit_time, r.car_info, r.status AS resv_status, r.checked_in_at,
                (SELECT w.status FROM tt_work_logs w
                  WHERE w.reservation_id = r.id ORDER BY w.id DESC LIMIT 1) AS work_status
           FROM tt_reservations r
          WHERE r.visit_date = :d AND r.status IN ('pending','confirmed','visited')"
    );
    $stmt->execute(['d' => $date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'key'   => 'r' . (int)$r['id'],
            'src'   => 'r',
            'id'    => (int)$r['id'],
            'time'  => substr((string)$r['visit_time'], 0, 5),
            'plate' => board_plate($r['car_info'] ?? null),
            'state' => board_state(
                (string)$r['resv_status'],
                $r['work_status'] !== null ? (string)$r['work_status'] : null,
                $r['checked_in_at'] ?? null
            ),
        ];
    }

    /* 2) 현장 입고 — 아직 작업일지가 연결되지 않은 건만 "입고중".
          연결된 작업일지가 삭제됐다면(w.id IS NULL) 다시 입고중으로 돌아온다. */
    $stmt = $pdo->prepare(
        "SELECT i.id, i.plate_no, i.created_at
           FROM tt_board_walkins i
           LEFT JOIN tt_work_logs w ON w.id = i.work_log_id
          WHERE i.visit_date = :d AND w.id IS NULL"
    );
    $stmt->execute(['d' => $date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'key'   => 'i' . (int)$r['id'],
            'src'   => 'i',
            'id'    => (int)$r['id'],
            'time'  => substr((string)$r['created_at'], 11, 5),
            'plate' => board_plate((string)$r['plate_no']),
            'state' => 'checkin',
        ];
    }

    /* 3) 현장 작업 — 예약과 연동되지 않은 오늘 작업일지 (status는 working/done만 존재) */
    $stmt = $pdo->prepare(
        "SELECT w.id, w.work_time, w.car_info, w.status
           FROM tt_work_logs w
          WHERE w.work_date = :d AND w.reservation_id IS NULL"
    );
    $stmt->execute(['d' => $date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'key'   => 'w' . (int)$r['id'],
            'src'   => 'w',
            'id'    => (int)$r['id'],
            'time'  => substr((string)$r['work_time'], 0, 5),
            'plate' => board_plate($r['car_info'] ?? null),
            'state' => ((string)$r['status'] === 'done') ? 'done' : 'working',
        ];
    }

    usort($items, static fn(array $a, array $b): int =>
        [$a['time'], $a['src'], $a['id']] <=> [$b['time'], $b['src'], $b['id']]);

    return [
        'date'        => $date,
        'server_time' => date('H:i:s'),
        'hash'        => md5(json_encode($items, JSON_UNESCAPED_UNICODE)),
        'items'       => $items,
    ];
}
