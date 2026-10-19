<?php
declare(strict_types=1);

final class Sms
{
    /**
     * ★ 중요: 아래는 실제 SMS 발송이 아니라 골격입니다.
     * 알리고(https://smartsms.aligo.in) 등 문자발송 업체에 가입 후
     * SMS_API_KEY, SMS_USER_ID, SMS_SENDER 상수를 core/config.php에 정의해야
     * 실제 문자가 발송됩니다. 값이 없으면 자동으로 테스트 모드로 동작합니다.
     */
    public static function sendVerificationCode(string $phone, string $code): bool
    {
        $message = "[타이어탑] 인증번호는 [{$code}] 입니다. 3분 내로 입력해주세요.";

        if (!defined('SMS_API_KEY') || !defined('SMS_USER_ID') || !defined('SMS_SENDER')) {
            // 테스트 모드: 실제 발송 없이 로그만 남김 (운영 전환 시 반드시 실제 키 설정 필요)
            error_log("[SMS 테스트모드] to={$phone} msg={$message}");
            return true;
        }

        $ch = curl_init('https://apis.aligo.in/send/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => [
                'key'     => SMS_API_KEY,
                'user_id' => SMS_USER_ID,
                'sender'  => SMS_SENDER,
                'receiver'=> $phone,
                'msg'     => $message,
            ],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        return $res !== false;
    }
}
