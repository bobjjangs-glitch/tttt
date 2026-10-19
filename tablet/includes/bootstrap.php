<?php
declare(strict_types=1);
/* 태블릿 공통 부트스트랩 — 세션/DB/보조함수만 (로그인 필요 없음) */
require_once __DIR__ . '/../../core/bootstrap.php';
ensure_work_log_tables();
ensure_worker_pin_lock_columns();
ensure_work_log_photos_table();

/* PIN 연속 실패 잠금 정책 */
if (!defined('MAX_PIN_ATTEMPTS')) define('MAX_PIN_ATTEMPTS', 5);
if (!defined('PIN_LOCK_SECONDS')) define('PIN_LOCK_SECONDS', 300);

function tablet_current_worker(): ?array
{
    $wid = (int)($_SESSION['tt_worker_id'] ?? 0);
    if ($wid <= 0) return null;
    static $cache = null;
    if ($cache === null) {
        try {
            $stmt = Database::connection()->prepare('SELECT * FROM tt_workers WHERE id = :id AND is_active = 1 LIMIT 1');
            $stmt->execute(['id'=>$wid]);
            $cache = $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
        } catch (Throwable $e) { $cache = false; }
    }
    return $cache ?: null;
}

function tablet_require_worker(): array
{
    $worker = tablet_current_worker();
    if ($worker) return $worker;
    /* 관리자 세션도 태블릿 사용 허용 */
    if (AdminAuth::isLoggedIn()) {
        return ['id'=>0, 'name'=>AdminAuth::currentAdminName(), 'role'=>'관리자'];
    }
    redirect('/tablet/login.php');
}
