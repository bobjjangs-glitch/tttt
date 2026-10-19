<?php
declare(strict_types=1);

final class Sms
{
    /**
     * 범용 SMS 발송 (알리고 API) — 리마인더/공지 등 인증번호 외 용도.
     * SMS_API_KEY 등이 정의되어 있지 않으면 무료 테스트 모드로 동작하며
     * 실제 발송 없이 로그만 남긴다 (ok=true 반환: 운영 알림 흐름을 막지 않기 위함).
     *
     * @return array{ok: bool, msg: string}
     */
    public static function send(string $phone, string $message): array
    {
        if (!preg_match('/^01[0-9]{8,9}$/', preg_replace('/[^0-9]/', '', $phone))) {
            return ['ok' => false, 'msg' => '휴대폰 번호 형식이 올바르지 않습니다.'];
        }

        if (!defined('SMS_API_KEY') || !defined('SMS_USER_ID') || !defined('SMS_SENDER')) {
            self::log("[SMS 무료모드] to={$phone} msg=" . mb_substr($message, 0, 60));
            return ['ok' => true, 'msg' => '무료 테스트 모드로 발송 처리했습니다. (실발송은 SMS API 설정 후)'];
        }

        $ch = curl_init('https://apis.aligo.in/send/');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => [
                'key'      => SMS_API_KEY,
                'user_id'  => SMS_USER_ID,
                'sender'   => SMS_SENDER,
                'receiver' => $phone,
                'msg'      => $message,
                'msg_type' => 'SMS',
            ],
        ]);
        $res = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($res === false || $curlErrno !== 0) {
            self::log("범용 발송 cURL 실패: errno={$curlErrno} error={$curlError}");
            return ['ok' => false, 'msg' => '문자 발송 서버에 연결할 수 없습니다.'];
        }

        $data = json_decode($res, true);
        if (!is_array($data) || !isset($data['result_code'])) {
            self::log("범용 발송 응답 파싱 실패: raw={$res}");
            return ['ok' => false, 'msg' => '문자 발송 서버 응답을 해석할 수 없습니다.'];
        }

        if ((int)$data['result_code'] < 0) {
            self::log("알리고 범용 발송 실패: code=" . $data['result_code'] . ' msg=' . ($data['message'] ?? ''));
            return ['ok' => false, 'msg' => '문자 발송에 실패했습니다.'];
        }

        self::log("범용 발송 성공: phone={$phone}");
        return ['ok' => true, 'msg' => '문자를 발송했습니다.'];
    }

    /**
     * SMS 발송 (알리고 API).
     * SMS_API_KEY, SMS_USER_ID, SMS_SENDER 상수가 config/constants.php에
     * 정의되어 있어야 실제 발송이 이루어진다.
     * 정의되어 있지 않으면 무료 테스트 모드로 동작하며,
     * 인증번호를 화면에 표시하여 회원가입이 가능하도록 한다.
     *
     * @return array{ok: bool, msg: string} 발송 성공 여부와 상세 메시지
     */
    public static function sendVerificationCode(string $phone, string $code): array
    {
        $message = "[타이어탑] 인증번호는 [{$code}] 입니다. 3분 내로 입력해주세요.";

        // SMS API 키가 없으면 무료 테스트 모드: 화면에 인증번호 표시
        if (!defined('SMS_API_KEY') || !defined('SMS_USER_ID') || !defined('SMS_SENDER')) {
            error_log("[SMS 무료모드] to={$phone} code={$code} msg={$message}");
            // ok=true 로 반환하되, 클라이언트에서 dev_code를 화면에 표시
            return ['ok' => true, 'msg' => '인증번호가 발송되었습니다. (무료 테스트 모드)', 'dev_code' => $code];
        }

        $ch = curl_init('https://apis.aligo.in/send/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => [
                'key'      => SMS_API_KEY,
                'user_id'  => SMS_USER_ID,
                'sender'   => SMS_SENDER,
                'receiver' => $phone,
                'msg'      => $message,
                'msg_type' => 'SMS',
            ],
        ]);
        $res = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 1) 네트워크 자체가 실패한 경우
        if ($res === false || $curlErrno !== 0) {
            self::log("cURL 실패: errno={$curlErrno} error={$curlError}");
            return ['ok' => false, 'msg' => '문자 발송 서버에 연결할 수 없습니다. 잠시 후 다시 시도해주세요.'];
        }

        // 2) 응답은 왔지만 JSON 파싱이 안 되는 경우
        $data = json_decode($res, true);
        if (!is_array($data) || !isset($data['result_code'])) {
            self::log("응답 파싱 실패: raw={$res}");
            return ['ok' => false, 'msg' => '문자 발송 서버 응답을 해석할 수 없습니다.'];
        }

        // 3) 알리고 응답 코드 확인
        $resultCode = (int)$data['result_code'];
        if ($resultCode < 0) {
            self::log("알리고 발송 실패: code={$resultCode} msg=" . ($data['message'] ?? ''));
            return ['ok' => false, 'msg' => '문자 발송에 실패했습니다. 잠시 후 다시 시도해주세요.'];
        }

        self::log("발송 성공: phone={$phone} msg_id=" . ($data['info']['mid'] ?? ''));
        return ['ok' => true, 'msg' => '인증번호가 발송되었습니다.'];
    }

    private static function log(string $line): void
    {
        $dir = defined('LOG_DIR') ? LOG_DIR : __DIR__ . '/../logs/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . 'sms.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }
}
