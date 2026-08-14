<?php
// /admin/clear_opcache.php — 1회성 진단/조치용, 실행 후 반드시 삭제
declare(strict_types=1);

echo "<pre>";

if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo $result
        ? "✅ OPcache 캐시를 성공적으로 초기화했습니다.\n"
        : "❌ OPcache 초기화에 실패했습니다. (권한 문제일 수 있음)\n";
} else {
    echo "❌ opcache_reset() 함수를 사용할 수 없는 환경입니다.\n";
}

echo "\n초기화 후 admin/login.php를 다시 열어서 정상 작동하는지 확인해주세요.\n";
echo "</pre>";
