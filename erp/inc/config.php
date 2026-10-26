<?php
// 이 파일은 외부에서 읽히면 안 됩니다. (inc/.htaccess 가 접근을 막습니다)
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'bobjjangs1231',
        'user'    => 'bobjjangs1231',
        'pass'    => 'ssy201029@',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'        => 'TireERP',
        'timezone'    => 'Asia/Seoul',
        'debug'       => false,               // 첫 실행에서 오류 확인 시에만 true
        'install_key' => 'skwldnjs123', // install.php 실행용, 8자 이상
    ],
    'company' => [                              // 거래명세서 인쇄에 사용 (사업자번호는 직접 확인)
        'name'    => '타이어탑',
        'biz_no'  => '394-88-00126',
        'ceo'     => '',
        'address' => '',
        'tel'     => '',
    ],
];
