<?php
/**
 * 예약 리마인더 자동 발송 (호스팅 크론용 CLI 스크립트)
 *
 * 사용법 (호스팅 제어판의 크론에 하루 1회 등록 — 예: 매일 21:00):
 *   php /home/계정/tiretop/scripts/reservation-reminders.php
 *
 * 동작: 내일 방문 예정인 예약(예약접수/확정) 고객에게 SMS 리마인더를 발송하고
 *       tt_reservation_reminders 에 이력을 남긴다 (같은 날 중복 발송 방지).
 *       SMS API 키가 없으면 무료 테스트 모드로 이력만 기록한다.
 *
 * 참고: core/bootstrap.php 는 DB 연결 실패 시 exit 하며 웹 응답(500)을 출력하므로,
 *       CLI 실행 시에는 부트스트랩 직전에서 CLI 전용 처리를 한다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI 전용 스크립트입니다.');
}

date_default_timezone_set(date_default_timezone_get() ?: 'Asia/Seoul');

require_once __DIR__ . '/../core/bootstrap.php';

fwrite(STDOUT, "[예약 리마인더] " . date('Y-m-d H:i:s') . " 실행\n");

$result = send_reservation_reminders(date('Y-m-d', strtotime('+1 day')), 'cron');

fwrite(STDOUT, sprintf(
    "내일(%s) 방문 예약 대상 %d건 — 발송 %d건 / 실패 %d건 / 이미 발송 생략 %d건\n",
    $result['date'], $result['total'], $result['sent'], $result['failed'], $result['skipped']
));
foreach ($result['detail'] as $line) {
    fwrite(STDOUT, "  - {$line}\n");
}

/* 자동 발송 대상은 내일 예약뿐이지만, 오늘 미발송 건도 뒤늦게 보완 발송 (당일 안내) */
$todayResult = send_reservation_reminders(date('Y-m-d'), 'cron');
if ($todayResult['sent'] > 0) {
    fwrite(STDOUT, sprintf(
        "오늘(%s) 미발송 보완 — 발송 %d건 / 실패 %d건\n",
        $todayResult['date'], $todayResult['sent'], $todayResult['failed']
    ));
    foreach ($todayResult['detail'] as $line) {
        fwrite(STDOUT, "  - {$line}\n");
    }
}

fwrite(STDOUT, "[예약 리마인더] 완료\n");
exit(0);
