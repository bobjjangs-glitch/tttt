<?php
declare(strict_types=1);

final class Sms
{
    /**
     * 알리고(https://smartsms.aligo.in) SMS 발송.
     * SMS_API_KEY, SMS_USER_ID, SMS_SENDER 상수가 config/constants.php에
     * 정의되어 있어야 실제 발송이 이루어진다.
     * 정의되어 있지 않으면 테스트 모드로 동작하며, 실제로는 발송하지 않는다.
     *
     * @return array{ok: bool, msg: string} 발송 성공 여부와 상세 메시지
     */
    public static function sendVerificationCode(string $phone, string $code): array
    {
        $message = "[타이어탑] 인증번호는 [{$code}] 입니다. 3분 내로 입력해주세요.";

        if (!defined('SMS_API_KEY') || !defined('SMS_USER_ID') || !defined('SMS_SENDER')) {
            error_log("[SMS 테스트모드] to={$phone} msg={$message}");
            return ['ok' => false, 'msg' => 'SMS_API_KEY 등 발송 설정이 되어 있지 않아 테스트 모드로 동작 중입니다. 실제 문자는 발송되지 않았습니다.'];
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

        // 1) 네트워크 자체가 실패한 경우 (서버 응답조차 못 받음)
        if ($res === false || $curlErrno !== 0) {
            self::log("cURL 실패: errno={$curlErrno} error={$curlError}");
            return ['ok' => false, 'msg' => '문자 발송 서버에 연결할 수 없습니다. 잠시 후 다시 시도해주세요.'];
        }

        // 2) 응답은 왔지만 JSON 파싱이 안 되는 경우 (API 주소나 파라미터 형식 문제)
        $data = json_decode($res, true);
        if (!is_array($data) || !isset($data['result_code'])) {
            self::log("응답 파싱 실패: raw={$res}");
            return ['ok' => false, 'msg' => '문자 발송 서버 응답을 해석할 수 없습니다.'];
        }

        // 3) 알리고 응답 코드 확인 (result_code가 1 이상이면 성공, 음수면 실패)
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
