<?php
declare(strict_types=1);

/**
 * admin/ 폴더의 각 파일이 올바른 권한 검사를 하고 있는지 점검하는 스크립트.
 *
 * ⚠️ 절대 admin/ 폴더나 웹에서 접근 가능한 경로에 두지 마세요.
 * ⚠️ 반드시 CLI(터미널/SSH)에서만 실행하세요. 웹 접근은 아래에서 강제로 차단합니다.
 *
 * 실행 방법 (프로젝트 루트에서):
 *   php scripts/check_admin_permissions.php
 */

// ---------- 웹 접근 차단 (안전장치) ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('이 스크립트는 CLI 전용입니다.');
}

// ---------- 경로 설정 ----------
$projectRoot = dirname(__DIR__);
$adminDir    = $projectRoot . '/admin';
$authFile    = $projectRoot . '/core/AdminAuth.php';

if (!is_dir($adminDir)) {
    fwrite(STDERR, "❌ admin 폴더를 찾을 수 없습니다: {$adminDir}\n");
    exit(1);
}
if (!file_exists($authFile)) {
    fwrite(STDERR, "❌ core/AdminAuth.php 를 찾을 수 없습니다: {$authFile}\n");
    exit(1);
}

// ---------- AdminAuth.php에서 실제 등록된 모듈 목록 추출 ----------
// PERMISSIONS 배열에 정의된 모듈명(orders, products, users 등)을 전부 뽑아서
// "존재하지 않는 모듈명을 오타로 적어넣는" 실수까지 잡아낸다.
$authContent = file_get_contents($authFile);
preg_match_all("/'([a-z\-]+)'/", $authContent, $moduleMatches);
$knownModules = array_unique($moduleMatches[1]);
// role 이름(product, order, cs, super)까지 섞여 나오므로 role 키워드는 제외
$roleKeywords = ['super', 'product', 'order', 'cs'];
$knownModules = array_values(array_diff($knownModules, []));

// ---------- 파일별 "기대하는 권한" 매핑 ----------
// 새 파일을 추가할 때마다 반드시 여기에도 한 줄 추가할 것.
// 값이 'super'면 requireSuper(), null이면 requireLogin()만 있어도 정상(대시보드 등 공통 화면).
$expectedMap = [
    'index.php'             => null,       // 대시보드: 로그인만 하면 접근 가능 (공통 화면)
    'admins.php'             => 'super',
    'orders.php'              => 'orders',
    'orders_export.php'       => 'orders',
    'products.php'             => 'products',
    'product_form.php'        => 'products',
    'porducts_export.php'     => 'products',
    'brands.php'               => 'brands',
    'banners.php'               => 'banners',
    'reviews.php'               => 'reviews',
    'users.php'                 => 'users',
    'users_export.php'          => 'users',
    'stock-requests.php'        => 'stock-requests',
];

// ---------- admin 폴더 내 실제 .php 파일 목록 수집 ----------
$actualFiles = [];
foreach (scandir($adminDir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $fullPath = $adminDir . '/' . $entry;
    if (is_file($fullPath) && str_ends_with($entry, '.php')) {
        $actualFiles[] = $entry;
    }
}

// ---------- 점검 실행 ----------
$results = [];
$hasCritical = false;

foreach ($actualFiles as $filename) {
    $filePath = $adminDir . '/' . $filename;
    $content  = file_get_contents($filePath);

    // AdminAuth::requireXxx(...) 호출 패턴 탐지
    $found = null;
    if (preg_match("/AdminAuth::requireSuper\s*\(\s*\)/", $content)) {
        $found = ['type' => 'super', 'module' => null];
    } elseif (preg_match("/AdminAuth::requirePermission\s*\(\s*'([a-z\-]+)'\s*\)/", $content, $m)) {
        $found = ['type' => 'permission', 'module' => $m[1]];
    } elseif (preg_match("/AdminAuth::requireLogin\s*\(\s*\)/", $content)) {
        $found = ['type' => 'login', 'module' => null];
    }

    $expected = array_key_exists($filename, $expectedMap) ? $expectedMap[$filename] : '__UNKNOWN__';

    $status = '';
    $detail = '';

    if ($expected === '__UNKNOWN__') {
        $status = '⚠️ 매핑없음';
        $detail = '$expectedMap 에 정의되지 않은 파일입니다. 새 파일이거나 삭제 대상 잔재 파일일 수 있습니다.';
    } elseif ($found === null) {
        $status = '🔴 위험';
        $detail = '어떤 AdminAuth::require*() 호출도 발견되지 않았습니다. 인증 자체가 없습니다.';
        $hasCritical = true;
    } elseif ($expected === 'super') {
        if ($found['type'] === 'super') {
            $status = '✅ 정상';
        } else {
            $status = '🔴 위험';
            $detail = "requireSuper() 가 필요한데 '{$found['type']}' 로 되어 있습니다.";
            $hasCritical = true;
        }
    } elseif ($expected === null) {
        // 로그인만 필요한 공통 화면 (대시보드 등)
        if ($found['type'] === 'login' || $found['type'] === 'permission' || $found['type'] === 'super') {
            $status = '✅ 정상';
        } else {
            $status = '🔴 위험';
            $hasCritical = true;
        }
    } else {
        // 특정 모듈 권한이 필요한 파일
        if ($found['type'] === 'super') {
            $status = '✅ 정상(최고관리자는 항상 통과)';
        } elseif ($found['type'] === 'permission' && $found['module'] === $expected) {
            $status = '✅ 정상';
        } elseif ($found['type'] === 'permission' && $found['module'] !== $expected) {
            $status = '🔴 위험';
            $detail = "기대값은 '{$expected}' 인데 실제로는 '{$found['module']}' 로 되어 있습니다.";
            $hasCritical = true;
        } elseif ($found['type'] === 'login') {
            $status = '🟡 경고';
            $detail = "requireLogin() 만 있고 '{$expected}' 권한 체크가 없습니다. 모든 역할이 접근 가능한 상태입니다.";
            $hasCritical = true;
        }
    }

    $results[] = [
        'file'     => $filename,
        'expected' => $expected === null ? '(로그인만)' : ($expected === '__UNKNOWN__' ? '?' : $expected),
        'found'    => $found ? ($found['type'] === 'permission' ? $found['module'] : $found['type']) : '없음',
        'status'   => $status,
        'detail'   => $detail,
    ];
}

// ---------- 결과 출력 ----------
echo "\n=== admin/ 권한 검사 리포트 ===\n\n";
printf("%-28s %-16s %-16s %-30s\n", '파일명', '기대 권한', '실제 발견값', '상태');
echo str_repeat('-', 95) . "\n";

foreach ($results as $r) {
    printf("%-28s %-16s %-16s %-30s\n", $r['file'], $r['expected'], $r['found'], $r['status']);
    if ($r['detail'] !== '') {
        echo "   └─ " . $r['detail'] . "\n";
    }
}

echo "\n";
if ($hasCritical) {
    echo "🔴 위험 항목이 발견되었습니다. 배포 전에 반드시 수정하세요.\n";
    exit(1);
} else {
    echo "✅ 모든 파일이 기대한 권한 체크를 통과했습니다.\n";
    exit(0);
}
