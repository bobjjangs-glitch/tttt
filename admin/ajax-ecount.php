<?php
declare(strict_types=1);
/**
 * [v51] 이카운트 ajax 엔드포인트 (JSON)
 *  - status      : 연결 상태 요약
 *  - test        : 연결 테스트
 *  - server_ip   : [v53] 이 서버의 아웃바운드 IP (이카운트 IP등록에 등록할 값)
 *  - stock_single: 품목 1개 창고별 재고 실시간 조회 (단건 API — 1초 제한)
 *  - cron_ping   : 자동동기화 1사이클 (큐 재시도 + 10분 경과 시 재고 풀)
 */
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('ecount');
ensure_ecount_tables();

header('Content-Type: application/json; charset=utf-8');
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

try {
    if ($action === 'status') {
        echo json_encode(['ok' => true, 'status' => ecount_status_summary()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'test') {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => '유효하지 않은 요청입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(EcountAPI::fromSettings()->testConnection(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'server_ip') {
        // [v53] 이 쇼핑몰 서버가 외부 API를 호출할 때 사용하는 아웃바운드 IP —
        // 이카운트 ERP > 환경설정(Self-Customizing) > 정보관리 > API인증키발급 > 하단 [IP등록]에
        // 등록해야 이 서버의 API 호출이 허용된다.
        $ip = '';
        foreach (['https://checkip.amazonaws.com/', 'https://api.ipify.org/'] as $u) {
            $raw = false;
            if (function_exists('curl_init')) {
                $ch = curl_init($u);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);
                $raw = curl_exec($ch);
                curl_close($ch);
            } else {
                $raw = @file_get_contents($u, false, stream_context_create(['http' => ['timeout' => 8]]));
            }
            $raw = trim((string)$raw);
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $raw)) {
                $ip = $raw;
                break;
            }
        }
        echo json_encode(['ok' => $ip !== '', 'ip' => $ip], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'items_pull') {
        // [v60] ERP 품목 자동가져오기(페이지 로드 시 자동 갱신용) — 간격 가드는 그대로 적용
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => '유효하지 않은 요청입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        session_write_close(); // [v61] ERP 호출 중 세션 잠금 방지
        $r = ecount_pull_items();
        echo json_encode(['ok' => $r['ok'], 'message' => $r['message'], 'count' => (int)($r['count'] ?? 0), 'blocked' => !$r['ok'] && strpos((string)$r['message'], '간격') !== false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'stock_pull') {
        // [v60] 창고별 재고 자동가져오기(페이지 로드 시 자동 갱신용)
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => '유효하지 않은 요청입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        session_write_close(); // [v61] ERP 호출 중 세션 잠금 방지
        $r = ecount_pull_stock();
        echo json_encode(['ok' => $r['ok'], 'message' => $r['message'], 'blocked' => !$r['ok'] && strpos((string)$r['message'], '간격') !== false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'stock_total_pull') {
        // [v60] 총재고(재고현황) 가져오기 — GetListInventoryBalanceStatus
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => '유효하지 않은 요청입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        session_write_close(); // [v61] ERP 호출 중 세션 잠금 방지
        $r = ecount_pull_stock_total();
        echo json_encode(['ok' => $r['ok'], 'message' => $r['message'], 'count' => (int)($r['count'] ?? 0), 'blocked' => !$r['ok'] && strpos((string)$r['message'], '간격') !== false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'year_fetch') {
        // [v59] 년도 전체 구매현황 조회 — 30일 단위 자동 분할 + 이어받기 + 자체 페이싱(2초)
        // 간격 제한(Rate Limit)에 걸리면 wait 초를 반환 → JS 가 실시간 카운트다운 후 같은 기간 재시도
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => '유효하지 않은 요청입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $year = (int)($_POST['year'] ?? 0);
        $idx  = (int)($_POST['idx'] ?? 0);
        $reset = trim((string)($_POST['reset'] ?? '')) === '1';
        if ($year < 2000 || $year > (int)date('Y') + 1) {
            echo json_encode(['ok' => false, 'message' => '년도가 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $windows = [];
        $start = strtotime($year . '-01-01');
        $end   = strtotime($year . '-12-31');
        for ($t = $start; $t <= $end; $t = strtotime('+30 days', $t)) {
            $to = min(strtotime('+29 days', $t), $end);
            $windows[] = [date('Ymd', $t), date('Ymd', $to)];
            if ($to >= $end) break;
        }
        // [v59] 처음부터 다시
        if ($reset) {
            unset($_SESSION['ecount_po_rows'], $_SESSION['ecount_po_total'], $_SESSION['ecount_po_year_year'], $_SESSION['ecount_po_year_next']);
        }
        // [v59] 이어받기 — 같은 년도, idx=0 으로 시작 요청이 들어오면 저장된 다음 기간부터 계속
        $resume = false;
        if ($idx === 0 && (int)($_SESSION['ecount_po_year_year'] ?? 0) === $year
            && (int)($_SESSION['ecount_po_year_next'] ?? 0) > 0
            && (int)($_SESSION['ecount_po_year_next'] ?? 0) < count($windows)) {
            $idx = (int)$_SESSION['ecount_po_year_next'];
            $resume = true;
        }
        if ($idx < 0 || $idx >= count($windows)) {
            echo json_encode(['ok' => false, 'message' => '기간 번호가 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // [v59] 자체 페이싱 — 최소 2초 간격 (연속 클릭/재시도 폭주 방지)
        $lastAt = (int)($_SESSION['ecount_po_year_last'] ?? 0);
        if ($lastAt > 0 && (time() - $lastAt) < 2) {
            echo json_encode(['ok' => false, 'blocked' => true, 'wait' => 2, 'message' => '너무 빠른 재시도 — 2초 간격으로 진행합니다.', 'idx' => $idx, 'year' => $year, 'total_windows' => count($windows), 'resume' => $resume], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['ecount_po_year_last'] = time();
        [$fromYmd, $toYmd] = $windows[$idx];
        // [v59] bypassGuard — 거친 간격 가드 대신 자체 페이싱, Rate Limit 실패 시 페널티는 그대로
        $r = ecount_fetch_purchase_orders($fromYmd, $toYmd, true, 3, true);
        if (!$r['ok']) {
            $blocked = (bool)preg_match('/간격|Rate Limit|2초 간격/', (string)$r['message']);
            echo json_encode([
                'ok' => false,
                'blocked' => $blocked,
                'wait' => max(3, ecount_rate_wait('ecount_last_orders_pull')),
                'message' => $r['message'],
                'idx' => $idx,
                'year' => $year,
                'total_windows' => count($windows),
                'resume' => $resume,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 누적 (reset 또는 다른 년도면 새로 시작)
        if ((int)($_SESSION['ecount_po_year_year'] ?? 0) !== $year || $idx === 0) {
            $_SESSION['ecount_po_rows'] = [];
            $_SESSION['ecount_po_total'] = 0;
            $_SESSION['ecount_po_year_year'] = $year;
        }
        $_SESSION['ecount_po_rows'] = array_merge($_SESSION['ecount_po_rows'], $r['rows']);
        $_SESSION['ecount_po_total'] += (int)$r['total'];
        $_SESSION['ecount_po_range'] = ['from' => $year . '-01-01', 'to' => $year . '-12-31'];
        $done = $idx >= count($windows) - 1;
        $_SESSION['ecount_po_year_next'] = $done ? 0 : $idx + 1;
        echo json_encode([
            'ok' => true,
            'done' => $done,
            'idx' => $idx,
            'year' => $year,
            'total_windows' => count($windows),
            'window' => $fromYmd . '~' . $toYmd,
            'fetched' => (int)$r['total'],
            'message' => $r['message'],
            'accumulated' => count($_SESSION['ecount_po_rows']),
            'resume' => $resume,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'stock_single') {
        $prodCd = trim((string)($_GET['prod_cd'] ?? ($_POST['prod_cd'] ?? '')));
        if ($prodCd === '') {
            echo json_encode(['ok' => false, 'message' => '품목코드가 필요합니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        session_write_close(); // [v61] ERP 호출 중 세션 잠금 방지
    $r = ecount_query_stock_single($prodCd);
        $rows = [];
        foreach ($r['rows'] ?? [] as $row) {
            $rows[] = [
                'wh_cd' => (string)($row['WH_CD'] ?? ''),
                'wh_des' => (string)($row['WH_DES'] ?? ''),
                'bal_qty' => (string)($row['BAL_QTY'] ?? '0'),
            ];
        }
        echo json_encode(['ok' => $r['ok'], 'message' => $r['message'] ?? '', 'rows' => $rows, 'total' => $r['total'] ?? 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'cron_ping') {
        // [v61] 세션 잠금 해제 후 처리 — 60초 핑이 ERP 호출(수 초) 동안 다른 관리자 페이지 로드를
        // 붙잡지 않게 한다(PHP 세션 파일 잠금 = 관리자 전체가 느려지던 원인)
        session_write_close();
        $r = ecount_cron_run();
        echo json_encode(['ok' => true, 'result' => $r], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false, 'message' => '알 수 없는 동작입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('[admin/ajax-ecount] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => '처리 중 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
