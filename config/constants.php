<?php
declare(strict_types=1);

/* [NEW] 운영/개발 환경 플래그.
   실서버에 올릴 때는 반드시 'production'으로 둔다.
   로컬에서 디버깅할 때만 'development'로 바꿔서 에러를 화면에 띄운다. */
define('APP_ENV', 'production');

define('SITE_NAME', 'TIRETOP');
define('BASE_URL', 'https://bobjjangs1231.dothome.co.kr/tire');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');
define('SHIPPING_FEE_DEFAULT', 3000);
define('FREE_SHIPPING_MIN', 100000);
define('SESSION_LIFETIME', 60 * 60 * 24 * 7);

/* [NEW] 로그 파일 경로 (웹루트 안이지만 .htaccess로 외부 접근 차단) */
define('LOG_DIR', __DIR__ . '/../logs/');
