<?php
declare(strict_types=1);

/**
 * [v51] 이카운트(ECOUNT) ERP Open API 클라이언트
 *
 * 공식 OAPI V2 규격 (https://www.ecount.kr 개발자 매뉴얼 기준):
 *  1. Zone 조회   POST {base}/OAPI/V2/Zone                 {COM_CODE}            → Data.ZONE
 *  2. 로그인      POST {base}/OAPI/V2/OAPILogin            {COM_CODE, USER_ID, API_CERT_KEY, LAN_TYPE, ZONE} → Data.SESSION_ID
 *  3. 각 API      POST {zone-base}{엔드포인트}?SESSION_ID=..  (JSON body)
 *
 *  base URL 규칙:
 *   - 테스트키: https://sboapi{zone}.ecount.com  (zone 없으면 https://sboapi.ecount.com)
 *   - 실서버 : https://oapi{zone}.ecount.com    (zone 없으면 https://oapi.ecount.com)
 *   - ecount_domain 설정값이 있으면 우선 사용 (http 로 시작하면 그대로, 아니면 https://{값})
 *     → 샌드박스/사설 연결 테스트에도 쓰인다.
 *
 * Rate Limit (공식):
 *   Zone/로그인 10분 1회(테스트 10초) · 단건조회 1초 · 저장 10초 · 다건조회 10분
 *   → 다건조회(items/stock/orders)는 functions.php 의 ecount_rate_* 가드로 차단한다.
 */

final class EcountException extends RuntimeException
{
}

final class EcountAPI
{
    private string $comCode;
    private string $userId;
    private string $apiKey;
    private string $domain;
    private bool $testMode;
    private string $zone = '';
    private string $sessionId = '';
    private int $sessionExpiresAt = 0;

    /** [v54] 세션 캐시 15분 — 실측 세션 수명(~15분+)보다 짧게 유지해
     *  만료 세션으로 인한 실서버 일반 오류("An Error occurred...")를 예방 */
    private const SESSION_TTL = 900;
    private const HTTP_TIMEOUT = 40;

    public function __construct(string $comCode, string $userId, string $apiKey, string $domain = '', bool $testMode = false)
    {
        $this->comCode = trim($comCode);
        $this->userId  = trim($userId);
        $this->apiKey  = trim($apiKey);
        $this->testMode = $testMode;
        $this->domain  = $this->normalizeDomainSetting($domain);
    }

    /** tt_site_settings 에 저장된 연동정보로 클라이언트 생성 */
    public static function fromSettings(): self
    {
        return new self(
            get_setting('ecount_com_code'),
            get_setting('ecount_user_id'),
            get_setting('ecount_api_key'),
            get_setting('ecount_domain'),
            get_setting('ecount_test_mode', '0') === '1'
        );
    }

    /** 연동에 필요한 최소 정보(회사코드·ID·키)가 저장되어 있는가 */
    public static function configured(): bool
    {
        return get_setting('ecount_com_code') !== ''
            && get_setting('ecount_user_id') !== ''
            && get_setting('ecount_api_key') !== '';
    }

    public function comCode(): string { return $this->comCode; }

    /**
     * [v52] 도메인 설정값 정규화.
     * 실서버에서 "oapi" 같은 불완전한 값을 넣으면 https://oapi 호스트로 요청해
     * "Could not resolve host: oapi" 오류가 났다. 안전하게 해석한다:
     *  - 빈 값 / "oapi" / "sboapi" / "ecount" / "ecount.com" / "www.ecount.com" → '' (Zone 자동조회)
     *  - "oapi.ecount.com"·"sboapi.ecount.com"·"oapi{ZONE}.ecount.com" 계열 → 정상 https URL 로 변환
     *  - Zone 처럼 보이는 짧은 값(1~6자 알파벳·숫자) → '' 로 취급하고 Zone 값으로 활용 (Zone 캐시에 반영)
     *  - http(s):// 시작 → 그대로 사용 (사내 프록시/목업)
     *  - 그 외(호스트처럼 보이는 값) → https://{값}
     */
    private function normalizeDomainSetting(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = (string)preg_replace('#^https?://#i', '', $d);
        $d = rtrim(trim($d, " \t./"), '.');
        if ($d === '') {
            return '';
        }
        // 불완전하거나 무의미한 값 → Zone 자동조회로 복귀
        if (in_array($d, ['oapi', 'sboapi', 'ecount', 'ecount.com', 'www.ecount.com', 'oapi.ecount.com', 'sboapi.ecount.com', 'erp', 'null', 'none'], true)) {
            return '';
        }
        // oapiBB.ecount.com / sboapi01.ecount.com 등 → 정상 URL 로 변환해 사용
        if (preg_match('/^(s?oapi)([a-z0-9]{1,6})?\.ecount\.com$/', $d, $m)) {
            return 'https://' . $d;
        }
        // Zone 값으로 오해해 넣은 짧은 값 (예: "BB", "bb", "01") → Zone 취급
        if (preg_match('/^[a-z0-9]{1,6}$/', $d)) {
            if ($this->zone === '') {
                $this->zone = strtoupper($d);
            }
            return '';
        }
        // 그 밖의 값은 커스텀 호스트로 존중하되 http 로 시작했던 값은 원문 유지
        $original = strtolower(trim($domain));
        return (preg_match('#^https?://#i', $original) ? rtrim($original, '/') : 'https://' . $d);
    }

    // ------------------------------------------------------------------ HTTP

    /** [v52] 현재 설정으로 실제 사용될 연결 URL 미리보기 (관리자 화면 진단용) */
    public static function baseUrlPreview(bool $testMode = false): string
    {
        $api = new self(
            get_setting('ecount_com_code'),
            get_setting('ecount_user_id'),
            get_setting('ecount_api_key'),
            get_setting('ecount_domain'),
            $testMode
        );
        if ($api->domain !== '') {
            return $api->domain;
        }
        $zone = $api->zone !== '' ? $api->zone : get_setting('ecount_zone', '');
        if ($zone === '') {
            $zone = '{ZONE}';
        }
        return ($testMode ? 'https://sboapi' : 'https://oapi') . strtolower($zone) . '.ecount.com';
    }

    private function httpPost(string $url, array $body): array
    {
        // [v56] PHP 의 json_encode([]) 는 '{}' 이 아니라 '[]'(배열) 로 직렬화된다.
        // 이카운트 실서버(.NET)의 모델 바인딩은 빈 배열 본문을
        // "Model validation state error"(HTTP 400) 로 거부한다(테스트서버는 관대).
        // → 빈 본문은 반드시 '{}' 오브젝트로 보낸다.
        $json = json_encode($body === [] ? new stdClass() : $body, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new EcountException('요청 본문을 JSON으로 만들 수 없습니다.');
        }

        $response = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS    => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            ]);
            $raw = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false) {
                throw new EcountException('이카운트 서버에 연결할 수 없습니다. (' . $err . ')');
            }
            if ($code === 302 || $code === 412) {
                throw new EcountException('이카운트 API 호출 제한(Rate Limit)에 걸렸습니다. 잠시 후 다시 시도해주세요.');
            }
            if ($code >= 400) {
                $this->throwForHttpStatus($code, (string)$raw, $url);
            }
            $response = json_decode((string)$raw, true);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => $json,
                    'timeout' => self::HTTP_TIMEOUT,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                throw new EcountException('이카운트 서버에 연결할 수 없습니다.');
            }
            // [v55] curl 이 없는 호스트의 폴백 경로 — ignore_errors 로 4xx 본문도 받으므로
            // 반드시 상태코드를 직접 확인한다(이전엔 4xx·Rate Limit(302/412)이 본문 파싱으로 넘어갔다).
            $status = 0;
            if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])
                && preg_match('#HTTP/\S+\s+(\d{3})#', (string)$http_response_header[0], $m)) {
                $status = (int)$m[1];
            }
            if ($status === 302 || $status === 412) {
                throw new EcountException('이카운트 API 호출 제한(Rate Limit)에 걸렸습니다. 잠시 후 다시 시도해주세요.');
            }
            if ($status >= 400) {
                $this->throwForHttpStatus($status, (string)$raw, $url);
            }
            $response = json_decode($raw, true);
        }

        if (!is_array($response)) {
            throw new EcountException('이카운트 응답을 해석할 수 없습니다.');
        }
        return $response;
    }

    /** [v55] HTTP 4xx 응답 처리 — 본문의 사유(Data.Message / Error.Message)를 그대로 노출해 진단 가능하게
     *  [v56] 엔드포인트 경로도 표시(오류가 어느 API 호출에서 났는지 바로 알 수 있게) */
    private function throwForHttpStatus(int $code, string $raw, string $url = ''): void
    {
        $body = json_decode($raw, true);
        $bmsg = '';
        if (is_array($body)) {
            $bmsg = (string)($body['Data']['Message'] ?? ($body['Error']['Message'] ?? ''));
        } elseif (trim($raw) !== '') {
            $bmsg = mb_substr(trim($raw), 0, 200);
        }
        $endpoint = '';
        if ($url !== '' && ($p = parse_url($url, PHP_URL_PATH)) !== null) {
            $endpoint = ' [' . $p . ']';
        }
        throw new EcountException('이카운트 API가 HTTP ' . $code . ' 를 반환했습니다.' . ($bmsg !== '' ? ' — ' . $bmsg : '') . $endpoint, $code);
    }

    // ------------------------------------------------------------------ 인증

    private function baseUrl(): string
    {
        if ($this->domain !== '') {
            if (preg_match('#^https?://#i', $this->domain)) {
                return rtrim($this->domain, '/');
            }
            return 'https://' . $this->domain;
        }
        $zone = strtolower($this->zone);
        if ($this->testMode) {
            return $zone !== '' ? 'https://sboapi' . $zone . '.ecount.com' : 'https://sboapi.ecount.com';
        }
        return $zone !== '' ? 'https://oapi' . $zone . '.ecount.com' : 'https://oapi.ecount.com';
    }

    private function ensureZone(): void
    {
        if ($this->zone !== '' || $this->domain !== '') {
            return; // 도메인 직접 지정 시 zone 불필요
        }
        // zone 은 tt_site_settings 에 캐시 (Zone API 도 10분 제한)
        $cachedZone    = get_setting('ecount_zone', '');
        $cachedZoneAge = (int)get_setting('ecount_zone_at', '0');
        if ($cachedZone !== '' && $cachedZoneAge > 0 && (time() - $cachedZoneAge) < 86400) {
            $this->zone = $cachedZone;
            return;
        }
        $url = ($this->testMode ? 'https://sboapi.ecount.com' : 'https://oapi.ecount.com') . '/OAPI/V2/Zone';
        $res = $this->httpPost($url, ['COM_CODE' => $this->comCode]);
        $data = is_array($res['Data'] ?? null) ? $res['Data'] : [];
        $zone = (string)($data['ZONE'] ?? '');
        if ($zone === '' && isset($data['Datas']) && is_array($data['Datas'])) {
            $zone = (string)($data['Datas']['ZONE'] ?? '');
        }
        $okOuter = ((string)($res['Status'] ?? '') === '200' || (int)($res['Status'] ?? 0) === 200);
        $topError = is_array($res['Error'] ?? null) ? (string)($res['Error']['Message'] ?? '') : '';
        if (!$okOuter || $topError !== '') {
            $msg = (string)($data['Message'] ?? '');
            if ($msg === '') {
                $msg = $topError !== '' ? $topError : 'Zone 조회 실패';
            }
            throw new EcountException('이카운트 Zone 조회 실패: ' . $msg);
        }
        if ($zone === '') {
            throw new EcountException('이카운트 Zone 정보를 받지 못했습니다.');
        }
        $this->zone = $zone;
        set_setting('ecount_zone', $zone);
        set_setting('ecount_zone_at', (string)time());
    }

    private function login(): string
    {
        $this->ensureZone();
        $url = $this->baseUrl() . '/OAPI/V2/OAPILogin';
        $res = $this->httpPost($url, [
            'COM_CODE'    => $this->comCode,
            'USER_ID'     => $this->userId,
            'API_CERT_KEY' => $this->apiKey,
            'LAN_TYPE'    => 'ko-KR',
            'ZONE'        => $this->zone,
        ]);
        // [v52] 실서버 응답형태: 성공 → Data.Code "00" + Data.Datas.SESSION_ID
        //                        실패 → Data.Code "201" 등 + Data.Message (Status 는 200 으로 옴)
        $data = is_array($res['Data'] ?? null) ? $res['Data'] : [];
        $innerCode = (string)($data['Code'] ?? '');
        $sid = '';
        if (isset($data['Datas']) && is_array($data['Datas'])) {
            $sid = (string)($data['Datas']['SESSION_ID'] ?? '');
        }
        if ($sid === '') {
            $sid = (string)($data['SESSION_ID'] ?? '');
        }
        $okOuter = ((string)($res['Status'] ?? '') === '200' || (int)($res['Status'] ?? 0) === 200);
        $innerOk = ($innerCode === '' || $innerCode === '00' || $innerCode === '0' || $innerCode === '200');
        $topError = $res['Error']['Message'] ?? '';
        if (!$okOuter || $topError !== '' || !$innerOk || $sid === '') {
            $msg = (string)($data['Message'] ?? '');
            if ($msg === '') {
                $msg = (string)($res['Error']['Message'] ?? '');
            }
            if ($msg === '') {
                $msg = '로그인 실패';
            }
            // [v53] 실측 기반 오류코드별 안내 보강
            //  201: 실서버에서 실사용키 거부 — ① IP 미등록(미등록 IP에서는 이 메시지로 표시될 수 있음)
            //       ② 테스트키로 테스트서버에서 1회 이상 성공 호출 전 실사용키 사용 ③ 키 값·유효기간 문제
            //  204: 테스트 인증키를 실서버(oapi)로 보냄 → 테스트키 모드 켜기
            //  205: 키는 유효하지만 호출 IP가 ERP IP등록에 없음
            if ($innerCode === '205') {
                $msg .= ' — 이 인증키는 유효하지만 이 서버의 IP가 이카운트에 등록되어 있지 않습니다. ERP 환경설정(Self-Customizing) > 정보관리 > API인증키발급 > 하단 [IP등록]에 이 서버의 아웃바운드 IP를 등록해 주세요. (관리자 이카운트 화면의 "서버 IP 확인"에서 확인할 수 있습니다)';
            } elseif ($innerCode === '204') {
                $msg .= ' — 테스트용 인증키를 실서버(oapi)로 보내고 있습니다. 동기화 설정에서 [테스트키 모드]를 체크하면 테스트 서버(sboapi)로 연결됩니다.';
            } elseif ($innerCode === '201') {
                $msg .= ' — 실사용 인증키가 거부되었습니다. 확인 순서: ① ERP API인증키발급 > [IP등록]에 이 서버 IP가 등록되어 있는지 확인 ② 테스트 인증키로 테스트 서버([테스트키 모드] 체크)에서 먼저 1회 이상 연결 성공 후 실사용 키를 사용해야 합니다 ③ 키 값·유효기간이 맞는지 확인(맞지 않으면 API인증키발급 화면에서 재발급).';
            }
            throw new EcountException('이카운트 로그인 실패: ' . $msg);
        }
        $this->sessionId = $sid;
        $this->sessionExpiresAt = time() + self::SESSION_TTL;
        // 세션 캐시 저장 (별도 프로세스·다음 요청에서 재사용)
        // [v55] 세션은 발급된 연결(서버·키)에 종속 — 서명을 함께 저장해
        // 모드(테스트/실서버)·키·도메인이 바뀐 뒤 다른 서버 세션을 재사용하는
        // 오염(HTTP 400 · 일반 오류의 원인)을 자동 차단한다.
        set_setting('ecount_session_id', $sid);
        set_setting('ecount_session_at', (string)time());
        set_setting('ecount_session_sig', $this->sessionSig());
        return $sid;
    }

    /** [v55] 현재 연결(Zone·도메인·계정·키)의 서명 — 세션 캐시가 이 연결에서 발급된 것인지 판별 */
    private function sessionSig(): string
    {
        return md5($this->baseUrl() . '|' . $this->comCode . '|' . $this->userId . '|' . $this->apiKey);
    }

    private function ensureSession(): string
    {
        if ($this->sessionId === '') {
            $cachedSid = get_setting('ecount_session_id', '');
            $cachedAt  = (int)get_setting('ecount_session_at', '0');
            // [v55] 서명이 다른 세션(모드 전환·키 교체·도메인 변경 전 발급분)은 무조건 폐기
            if ($cachedSid !== '' && $cachedAt > 0
                && (time() - $cachedAt) < self::SESSION_TTL
                && $this->ensureZoneForSig()
                && get_setting('ecount_session_sig', '') === $this->sessionSig()) {
                $this->sessionId = $cachedSid;
                $this->sessionExpiresAt = $cachedAt + self::SESSION_TTL;
                return $this->sessionId;
            }
            $this->login();
        } elseif ($this->sessionExpiresAt > 0 && time() > $this->sessionExpiresAt) {
            $this->login();
        }
        return $this->sessionId;
    }

    /** [v55] 세션 서명 비교용 zone 확보 (도메인 직접 지정이면 즉시 통과) */
    private function ensureZoneForSig(): bool
    {
        try {
            $this->ensureZone();
            return true;
        } catch (EcountException $e) {
            return false; // Zone 조회 실패 시 캐시 세션 신뢰 불가 → 재로그인
        }
    }

    private function invalidateSession(): void
    {
        $this->sessionId = '';
        $this->sessionExpiresAt = 0;
        set_setting('ecount_session_id', '');
        set_setting('ecount_session_at', '0');
        set_setting('ecount_session_sig', '');
    }

    /**
     * 공통 호출. 세션 만료 에러는 1회 재로그인 후 재시도한다.
     * [v52] 실서버 응답형태 반영: 외부 Status 는 항상 200 이어도 실제 오류는
     * Data.Code(201 등)+Data.Message 나 최상위 Error 로 내려온다. 성공 데이터는
     * Data.Result / Data.Datas 등 Data 내부에 있다.
     * @return array Data 부분 (Result/TotalCnt/SuccessCnt 등 포함)
     */
    private function call(string $endpoint, array $body): array
    {
        $attempt = function () use ($endpoint, $body): array {
            $sid = $this->ensureSession();
            $url = $this->baseUrl() . $endpoint . '?SESSION_ID=' . urlencode($sid);
            $res = $this->httpPost($url, $body);
            $status = $res['Status'] ?? null;
            $okOuter = ((string)$status === '200' || (int)$status === 200);
            $data = is_array($res['Data'] ?? null) ? $res['Data'] : [];
            $innerCode = (string)($data['Code'] ?? '');
            $innerOk = ($innerCode === '' || $innerCode === '00' || $innerCode === '0' || $innerCode === '200' || $innerCode === 'Y');
            $topError = is_array($res['Error'] ?? null) ? (string)($res['Error']['Message'] ?? '') : '';
            if (!$okOuter || $topError !== '' || !$innerOk) {
                $errMsg = (string)($data['Message'] ?? '');
                if ($errMsg === '') {
                    $errMsg = $topError !== '' ? $topError : 'API 호출 실패';
                }
                $errCode = (int)($innerCode !== '' ? $innerCode : ($res['Error']['Code'] ?? 0));
                // 세션 만료 → 1회 재로그인
                if ($errCode === 401 || stripos($errMsg, 'SESSION') !== false || stripos($errMsg, '세션') !== false) {
                    throw new EcountException('__SESSION__', $errCode);
                }
                // [v54] 실서버 일반 오류("An Error occurred during the operation progress")도
                // 만료 세션·일시 오류일 수 있음 → 세션 갱신 후 1회 재시도
                if (stripos($errMsg, 'An Error occurred') !== false) {
                    throw new EcountException('__RETRY__', $errCode);
                }
                throw new EcountException('이카운트 API 오류: ' . $errMsg . ' [' . $endpoint . ']', $errCode);
            }
            // 성공 페이로드: Data.Datas 래핑이 있으면 벗기고, 없으면 Data 자체
            if (isset($data['Datas']) && is_array($data['Datas'])) {
                return $data['Datas'];
            }
            return $data;
        };

        try {
            return $attempt();
        } catch (EcountException $e) {
            $mode = $e->getMessage();
            if ($mode !== '__SESSION__' && $mode !== '__RETRY__') {
                throw $e;
            }
            // [v54] 세션 만료 또는 실서버 일반 오류 → 세션 갱신 후 1회 재시도
            $this->invalidateSession();
            try {
                $this->login();
            } catch (EcountException $le) {
                throw new EcountException('이카운트 API 오류: ' . $le->getMessage() . ' — 재시도를 위해 재로그인했으나 실패했습니다. 잠시 후 다시 시도해 주세요.', $le->getCode());
            }
            try {
                return $attempt();
            } catch (EcountException $e2) {
                $m2 = $e2->getMessage();
                if ($m2 === '__SESSION__' || $m2 === '__RETRY__') {
                    // 재시도에도 동일 오류 → 짧은 간격 반복 호출은 이카운트 오류 제한(시간당 연속 오류 30건)에 걸릴 수 있음
                    throw new EcountException(
                        '이카운트 API 오류: 세션을 갱신해 재시도했지만 같은 오류가 계속됩니다. '
                        . '이카운트 쪽 일시 오류이거나 조회 제한(다건조회 10분 1회 · 로그인 10분 1회)에 걸렸을 수 있으니 '
                        . '몇 분 뒤 다시 시도해 주세요. 반복해서 누르면 오류 제한(시간당 연속 오류 30건)에 걸릴 수 있어요. [' . $endpoint . ']',
                        $e2->getCode()
                    );
                }
                throw $e2;
            }
        }
    }

    /** 연결 테스트 (Zone + 로그인) */
    public function testConnection(): array
    {
        try {
            $this->invalidateSession();
            $sid = $this->login();
            return [
                'ok' => true,
                'message' => '연결 성공 (세션 발급 완료)',
                'session' => mb_substr($sid, 0, 6) . '...',
                'zone' => $this->zone ?: '(도메인 직접 지정)',
                'base_url' => $this->baseUrl(),
            ];
        } catch (EcountException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'session' => '',
                'zone' => $this->zone,
                'base_url' => $this->baseUrl(),
            ];
        }
    }

    // ------------------------------------------------------------- 품목 API

    /** 품목 다건 조회 (10분 1회 제한 — 전체 목록) @return array[] Result */
    /** @return array{rows:array[], total:int} [v62] total 포함 반환 — ERP 삭제 품목 동기화(풀 후 프룬) 판정용 */
    public function getItems(string $prodCd = ''): array
    {
        $body = [];
        if ($prodCd !== '') $body['PROD_CD'] = $prodCd;
        $data = $this->call('/OAPI/V2/InventoryBasic/GetBasicProductsList', $body);
        $rows = is_array($data['Result'] ?? null) ? $data['Result'] : [];
        return ['rows' => $rows, 'total' => (int)($data['TotalCnt'] ?? count($rows))];
    }

    /** 품목 단건 조회 (1초 1회) */
    public function getItem(string $prodCd): ?array
    {
        $data = $this->call('/OAPI/V2/InventoryBasic/ViewBasicProduct', ['PROD_CD' => $prodCd]);
        $result = $data['Result'] ?? [];
        return is_array($result) ? ($result[0] ?? null) : null;
    }

    /** 품목 등록 (SaveBasicProduct, 10초 1회). $items: BulkDatas 필드 배열 목록 */
    public function saveItems(array $items): array
    {
        $data = $this->call('/OAPI/V2/InventoryBasic/SaveBasicProduct', [
            'ProductList' => array_map(static fn(array $it): array => ['BulkDatas' => $it], $items),
        ]);
        return $data;
    }

    // ------------------------------------------------------------ 거래처 API

    /** 거래처 등록 (SaveBasicCust, 10초 1회). $fields: BUSINESS_NO·CUST_NAME 필수 */
    public function savePartner(array $fields): array
    {
        $data = $this->call('/OAPI/V2/AccountBasic/SaveBasicCust', [
            'CustList' => [['BulkDatas' => $fields]],
        ]);
        return $data;
    }

    // -------------------------------------------------------------- 재고 API

    /** 창고별 재고 단건 (품목 1개, 1초 1회 — 실시간 조회용) */
    public function getStockByWarehouse(string $prodCd, string $baseDateYmd = ''): array
    {
        if ($baseDateYmd === '') $baseDateYmd = date('Ymd');
        $data = $this->call('/OAPI/V2/InventoryBalance/ViewInventoryBalanceStatusByLocation', [
            'PROD_CD'   => $prodCd,
            'BASE_DATE' => $baseDateYmd,
        ]);
        return is_array($data['Result'] ?? null) ? $data['Result'] : [];
    }

    /** 창고별 재고 다건 (전체, 10분 1회 — 자동 동기화용) */
    public function getStockByWarehouseList(string $baseDateYmd = '', string $whCd = ''): array
    {
        if ($baseDateYmd === '') $baseDateYmd = date('Ymd');
        $body = ['BASE_DATE' => $baseDateYmd];
        if ($whCd !== '') $body['WH_CD'] = $whCd;
        $data = $this->call('/OAPI/V2/InventoryBalance/GetListInventoryBalanceStatusByLocation', $body);
        return is_array($data['Result'] ?? null) ? $data['Result'] : [];
    }

    /** [v60] 총재고(전 창고 합계) 다건 — 재고현황 (10분 1회). 실측 엔드포인트: TotalCnt 4,495품목 반환 확인 */
    public function getStockTotalList(string $baseDateYmd = ''): array
    {
        if ($baseDateYmd === '') $baseDateYmd = date('Ymd');
        $data = $this->call('/OAPI/V2/InventoryBalance/GetListInventoryBalanceStatus', ['BASE_DATE' => $baseDateYmd]);
        return is_array($data['Result'] ?? null) ? $data['Result'] : [];
    }

    /** [v60] 총재고(전 창고 합계) 단건 — 품목 1개의 ERP 총재고 (1초 1회) */
    public function getStockTotal(string $prodCd, string $baseDateYmd = ''): array
    {
        if ($baseDateYmd === '') $baseDateYmd = date('Ymd');
        $data = $this->call('/OAPI/V2/InventoryBalance/ViewInventoryBalanceStatus', [
            'PROD_CD'   => $prodCd,
            'BASE_DATE' => $baseDateYmd,
        ]);
        return is_array($data['Result'] ?? null) ? $data['Result'] : [];
    }

    // ------------------------------------------------------------ 발주/구매 API

    /**
     * 발주서 조회 (10분 1회, 최대 30일 범위).
     * @return array{total:int, rows:array[]}
     */
    public function getPurchaseOrders(string $fromYmd, string $toYmd, int $page = 1, int $size = 100, string $custCd = '', string $prodCd = ''): array
    {
        $body = [
            'ListParam' => [
                'BASE_DATE_FROM' => $fromYmd,
                'BASE_DATE_TO'   => $toYmd,
                'PAGE_CURRENT'   => max(1, $page),
                'PAGE_SIZE'      => min(100, max(1, $size)),
            ],
        ];
        if ($custCd !== '') $body['CUST_CD'] = $custCd;
        if ($prodCd !== '') $body['PROD_CD'] = $prodCd;
        $data = $this->call('/OAPI/V2/Purchases/GetPurchasesOrderList', $body);
        return [
            'total' => (int)($data['TotalCnt'] ?? 0),
            'rows'  => is_array($data['Result'] ?? null) ? $data['Result'] : [],
        ];
    }

    /** 구매(입고) 입력 (SavePurchases, 10초 1회) — 발주→입고 처리에도 사용
     *  [v61] 주의: 이 API는 구매(입고) 전표를 만들며 발주서조회(GetPurchasesOrderList)에는
     *  나오지 않는다 — 쇼핑몰 구매현황에 바로 보이는 발주는 savePurchaseOrder(발주서)로 입력할 것. */
    public function savePurchases(array $items): array
    {
        $data = $this->call('/OAPI/V2/Purchases/SavePurchases', [
            'PurchasesList' => array_map(static fn(array $it): array => ['BulkDatas' => $it], $items),
        ]);
        return $data;
    }

    /** [v61] 발주서 입력 (SavePurchaseOrder, 10초 1회) — 발주서조회(GetPurchasesOrderList)로
     *  바로 조회되는 전표. API 매뉴얼(구매관리API > 발주서입력) 기준:
     *  PurchaseOrderList[].BulkDatas{ VPM_LINE_SER_NO, IO_DATE, CUST, CUST_DES, WH_CD, PROD_CD*, PROD_DES, QTY*, PRICE, SUPPLY_AMT, VAT_AMT, REMARKS, TIME_DATE } */
    public function savePurchaseOrder(array $items): array
    {
        $data = $this->call('/OAPI/V2/Purchases/SavePurchaseOrder', [
            'PurchaseOrderList' => array_map(static fn(array $it): array => ['BulkDatas' => $it], $items),
        ]);
        return $data;
    }

    // ------------------------------------------------------------ 판매/입고 API

    /** 판매 입력 (SaveSale, 10초 1회) — 쇼핑몰 주문 결제 시 ERP 재고 차감·전표 생성 */
    public function saveSales(array $items): array
    {
        $data = $this->call('/OAPI/V2/Sale/SaveSale', [
            'SaleList' => array_map(static fn(array $it): array => ['BulkDatas' => $it], $items),
        ]);
        return $data;
    }

    /** 생산입고 I 입력 (SaveGoodsReceipt, 10초 1회) — 쇼핑몰 관리자의 수동 재고 채움(입고)을 ERP에 반영 */
    public function saveGoodsReceipts(array $items): array
    {
        $data = $this->call('/OAPI/V2/GoodsReceipt/SaveGoodsReceipt', [
            'GoodsReceiptList' => array_map(static fn(array $it): array => ['BulkDatas' => $it], $items),
        ]);
        return $data;
    }
}
