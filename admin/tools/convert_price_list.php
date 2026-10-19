<?php
declare(strict_types=1);

use Shuchkin\SimpleXLSX;

// ===== 진단 설정: 반드시 파일 최상단, require보다 먼저 =====
error_reporting(E_ALL);
ini_set('display_errors', '1');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(200);
        }
        echo "<h2>❌ FATAL ERROR 발생 (convert_price_list.php)</h2>";
        echo "메시지: " . htmlspecialchars($err['message']) . "<br>";
        echo "파일: " . htmlspecialchars($err['file']) . "<br>";
        echo "라인: " . $err['line'] . "<br>";
    }
});

set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(200);
    }
    echo "<h2>❌ EXCEPTION 발생 (convert_price_list.php)</h2>";
    echo "메시지: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "파일: " . htmlspecialchars($e->getFile()) . "<br>";
    echo "라인: " . $e->getLine() . "<br>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
});
// ===== 진단 설정 끝 =====

/**
 * admin/tools/convert_price_list.php
 * 원본 타이어 가격표(xlsx) → products_import.php가 요구하는 형식의 CSV로 변환
 * DB에 아무것도 쓰지 않는 순수 변환 도구. 로그인한 관리자만 접근 가능.
 *
 * [2026-08 수정 내역]
 * 1) Runflat 원본 코드(ZP/EMT/SELFSEAL 등)를 Y/N으로 정규화, 원본 코드는 비고에 보존
 * 2) 정상가 0원 행은 CSV에서 제외하고 리포트로 별도 표시 (2단계: 리포트 확인 → 다운로드)
 */

$bootstrapPath = __DIR__ . '/../../core/bootstrap.php';
if (!file_exists($bootstrapPath)) {
    http_response_code(200);
    die('❌ bootstrap.php를 찾을 수 없습니다. 경로 확인 필요: ' . htmlspecialchars($bootstrapPath));
}
require_once $bootstrapPath;

$simpleXlsxPath = __DIR__ . '/../libs/SimpleXLSX.php';
if (!file_exists($simpleXlsxPath)) {
    http_response_code(200);
    die('❌ SimpleXLSX.php를 찾을 수 없습니다. 경로 확인 필요: ' . htmlspecialchars($simpleXlsxPath));
}
require_once $simpleXlsxPath;

if (!class_exists('AdminAuth')) {
    http_response_code(200);
    die('❌ AdminAuth 클래스가 로드되지 않았습니다. bootstrap.php 구성을 확인하세요.');
}
AdminAuth::requireLogin();

$tmpDir = __DIR__ . '/../../storage/tmp_imports';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}

// ---------------------------------------------------------
// 유틸리티 함수
// ---------------------------------------------------------

function safe_str($val): string
{
    if ($val === null) return '';
    return trim((string)$val);
}

function clean_price($val): int
{
    $s = safe_str($val);
    if ($s === '') return 0;
    $s = str_replace([',', '원', ' '], '', $s);
    if (!is_numeric($s)) return 0;
    return (int) round((float)$s);
}

/**
 * 원본 가격표의 영문 브랜드명을 tt_brands 테이블에 등록된 실제 한글명으로 변환.
 * (DB tt_brands 실제 목록: 한국타이어, 금호타이어, 넥센, 피렐리, 미쉐린,
 *  콘티넨탈, 브리지스톤, 굿이어, 요코하마, 라우펜, 쿠퍼, BF굿리치)
 * ※ 'BF굿리치'는 DB에 신규 등록이 필요함. 아래 SQL을 먼저 실행해야 매칭됨:
 *    INSERT INTO tt_brands (name) VALUES ('BF굿리치');
 */
function normalize_brand_name(string $rawBrand): string
{
    static $map = [
        'MICHELIN'    => '미쉐린',
        'BFG'         => 'BF굿리치',
        'BFGOODRICH'  => 'BF굿리치',
        'BF GOODRICH' => 'BF굿리치',
        'CONTINENTAL' => '콘티넨탈',
        'BRIDGESTONE' => '브리지스톤',
        'GOODYEAR'    => '굿이어',
        'GOOD YEAR'   => '굿이어',
        'YOKOHAMA'    => '요코하마',
        'PIRELLI'     => '피렐리',
        'LAUFENN'     => '라우펜',
        'COOPER'      => '쿠퍼',
        'HANKOOK'     => '한국타이어',
        'KUMHO'       => '금호타이어',
        'NEXEN'       => '넥센',
    ];
    $key = strtoupper(trim($rawBrand));
    return $map[$key] ?? $rawBrand;
}

/**
 * Runflat 원본 값을 products_import.php가 요구하는 Y/N으로 정규화.
 * 원본이 비어있거나 'N'이면 N. 그 외 ZP/EMT/SELFSEAL/TPC 등 모든 런플랫·실런트
 * 계열 마킹은 "런플랫 관련 기술 적용"으로 간주해 Y로 통일하고,
 * 원본 마킹 텍스트는 비고 컬럼에 보존한다(데이터 손실 방지).
 * @return array [정규화된 Y/N, 원본마킹텍스트(없으면 빈 문자열)]
 */
function normalize_runflat(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '' || strtoupper($raw) === 'N') {
        return ['N', ''];
    }
    if (strtoupper($raw) === 'Y') {
        return ['Y', ''];
    }
    return ['Y', $raw]; // ZP, EMT, SELFSEAL, TPC, FRV 등
}

/**
 * 아이템 상세 + 규격 문자열에서 사이즈/하중속도규격/단면폭/편평비/림직경 추출
 */
function parse_size_detail(string $detail, string $spec): array
{
    $sizeDisplay = '';
    $loadSpeed   = '';

    if (preg_match('/(\d{2,3}\s*\/\s*\d{2}\s*Z?R\s*\d{2})/', $detail, $m, PREG_OFFSET_CAPTURE)) {
        $sizeDisplay = preg_replace('/\s+/', '', $m[1][0]);
        $restStart   = $m[1][1] + strlen($m[1][0]);
        $rest        = trim(substr($detail, $restStart));
        if (preg_match('/^\(?(\d{2,3}[A-Z]{1,2})\)?/', $rest, $m2)) {
            $loadSpeed = $m2[1];
        }
    }

    if ($sizeDisplay === '') {
        if (preg_match('/(\d{2}X\d{1,2}\.\d{2}R\d{2}(?:LT)?)/i', $detail, $m3)) {
            $sizeDisplay = strtoupper($m3[1]);
            if (preg_match('/LT?\s*(\d{2,3}\/?\d{0,3}[A-Z])/', $detail, $m4)) {
                $loadSpeed = $m4[1];
            }
        }
    }

    $specParts = preg_split('/\s+/', trim($spec)) ?: [];
    $specParts = array_values(array_filter($specParts, fn($v) => $v !== ''));

    if ($sizeDisplay === '' && count($specParts) === 3) {
        $sizeDisplay = $specParts[0] . '/' . $specParts[1] . 'R' . $specParts[2];
    }

    $width = $aspect = $diameter = '';
    if (count($specParts) === 3) {
        [$width, $aspect, $diameter] = $specParts;
    }

    return [$sizeDisplay, $loadSpeed, $width, $aspect, $diameter];
}

function pick_data_sheet(SimpleXLSX $xlsx): int
{
    $sheetNames = $xlsx->sheetNames();
    $bestIdx    = 0;
    $bestCount  = -1;
    foreach ($sheetNames as $idx => $name) {
        $rows = $xlsx->rows($idx);
        $cnt  = is_array($rows) ? count($rows) : 0;
        if ($cnt > $bestCount) {
            $bestCount = $cnt;
            $bestIdx   = $idx;
        }
    }
    return $bestIdx;
}

// ---------------------------------------------------------
// STEP 3: 리포트 확인 후 실제 CSV 다운로드 (GET)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download') {
    if (empty($_SESSION['convert_tmp_file']) || empty($_SESSION['convert_tmp_token'])
        || ($_GET['token'] ?? '') !== $_SESSION['convert_tmp_token']) {
        http_response_code(200);
        die('❌ 다운로드 토큰이 유효하지 않거나 만료되었습니다. 변환을 다시 시도해주세요.');
    }
    $path = $_SESSION['convert_tmp_file'];
    if (!file_exists($path)) {
        http_response_code(200);
        die('❌ 임시 파일을 찾을 수 없습니다. 변환을 다시 시도해주세요.');
    }
    $filename = 'products_import_ready_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    unset($_SESSION['convert_tmp_file'], $_SESSION['convert_tmp_token']);
    exit;
}

// ---------------------------------------------------------
// STEP 2: 업로드 처리 → 리포트 화면 표시 (CSV는 임시파일로 저장, 아직 다운로드하지 않음)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['price_file'])) {

    if (!class_exists('Csrf') || !Csrf::verify($_POST['csrf_token'] ?? '')) {
        http_response_code(200);
        die('❌ CSRF 토큰이 유효하지 않습니다. 폼을 새로고침 후 다시 시도하세요.');
    }

    if ($_FILES['price_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(200);
        die('❌ 파일 업로드 실패 (에러코드: ' . $_FILES['price_file']['error'] . ')');
    }

    $tmpPath = $_FILES['price_file']['tmp_name'];

    $xlsx = SimpleXLSX::parse($tmpPath);
    if ($xlsx === false) {
        http_response_code(200);
        die('❌ 엑셀 파싱 실패: ' . htmlspecialchars(SimpleXLSX::parseError()));
    }

    $sheetIdx = pick_data_sheet($xlsx);
    $rows     = $xlsx->rows($sheetIdx);

    $out      = [];
    $skipped  = []; // 정상가 0원 등, CSV에서 제외된 행 (리포트용)
    $lastIdx  = -1;

    foreach ($rows as $row) {
        $brand = $row[0] ?? null;

        if (!is_string($brand) || trim($brand) === '') {
            $remarkTail = safe_str($row[12] ?? '');
            if ($lastIdx >= 0 && $remarkTail !== '') {
                $out[$lastIdx]['비고'] = trim($out[$lastIdx]['비고'] . ' ' . $remarkTail);
            }
            continue;
        }

        $brandClean = trim($brand);

        if (str_starts_with($brandClean, '※') || $brandClean === '브랜드') {
            continue;
        }

        $brandClean = normalize_brand_name($brandClean);

        $itemDetail = safe_str($row[3] ?? '');
        if ($itemDetail === '') {
            continue;
        }

        $cai          = safe_str($row[2] ?? '');
        $spec         = safe_str($row[4] ?? '');
        $patternName  = safe_str($row[5] ?? '');
        $oem          = safe_str($row[6] ?? '');
        $tech         = safe_str($row[7] ?? '');
        $runflatRaw   = safe_str($row[8] ?? '');
        $season       = safe_str($row[9] ?? '');
        $listPrice    = clean_price($row[10] ?? null);
        $factoryPrice = clean_price($row[11] ?? null);
        $remark       = safe_str($row[12] ?? '');

        [$sizeDisplay, $loadSpeed, $width, $aspect, $diameter] =
            parse_size_detail($itemDetail, $spec);

        [$runflatFlag, $runflatNote] = normalize_runflat($runflatRaw);
        if ($runflatNote !== '') {
            $remark = trim($remark . " [RUNFLAT:{$runflatNote}]");
        }

        // 정상가가 0원 이하이면 products_import.php에서 무조건 거부되므로,
        // CSV에 넣지 않고 별도 리포트로 분리해 사람이 원본 데이터를 확인하게 한다.
        if ($listPrice <= 0) {
            $skipped[] = [
                'brand'  => $brandClean,
                'name'   => $itemDetail,
                'reason' => '원본 가격표에서 정상가(기표가격)를 읽을 수 없거나 0원입니다. 원본 엑셀에서 직접 확인 필요.',
            ];
            continue;
        }

        $out[] = [
            '카테고리'     => '타이어',
            '브랜드'       => $brandClean,
            '상품명'       => $itemDetail,
            '패턴코드'     => $cai,
            '사이즈'       => $sizeDisplay,
            '하중속도규격' => $loadSpeed,
            '원산지'       => '',
            '단면폭'       => $width,
            '편평비'       => $aspect,
            '림직경'       => $diameter,
            '패턴명'       => $patternName,
            'OEM'          => $oem,
            'Tech'         => $tech,
            'Runflat'      => $runflatFlag,
            '정상가'       => $listPrice,
            '판매가'       => $listPrice,
            '공급가'       => $factoryPrice,
            '재고'         => 0,
            '상태'         => '노출',
            '계절성'       => $season,
            '비고'         => $remark,
        ];
        $lastIdx = count($out) - 1;
    }

    if (empty($out)) {
        http_response_code(200);
        die('❌ 변환된 데이터가 0건입니다. 시트 구조가 예상과 다를 수 있습니다.');
    }

    // CSV를 즉시 다운로드하지 않고 임시 파일로 저장 → 리포트 화면에서 확인 후 다운로드
    $token = bin2hex(random_bytes(16));
    $tmpFile = $tmpDir . '/convert_ready_' . $token . '.csv';

    $fp = fopen($tmpFile, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, array_keys($out[0]));
    foreach ($out as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);

    $_SESSION['convert_tmp_file']  = $tmpFile;
    $_SESSION['convert_tmp_token'] = $token;

    $pageTitle = '가격표 변환 리포트';
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
    <meta charset="UTF-8">
    <title>변환 리포트</title>
    <style>
    body{font-family:sans-serif;max-width:800px;margin:40px auto;}
    .box{border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:16px;}
    .ok{color:#15803d;} .warn{color:#b91c1c;}
    table{border-collapse:collapse;width:100%;font-size:13px;margin-top:12px;}
    th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;}
    th{background:#fef2f2;}
    .btn-dl{background:#16a34a;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;text-decoration:none;display:inline-block;}
    </style>
    </head>
    <body>
    <div class="box">
      <h2>변환 결과</h2>
      <p class="ok">✅ 정상 변환: <?= count($out) ?>건</p>
      <p class="warn">⚠ 제외됨(정상가 0원 등): <?= count($skipped) ?>건</p>
      <a href="?action=download&token=<?= htmlspecialchars($token) ?>" class="btn-dl">✅ 정상 변환된 <?= count($out) ?>건 CSV 다운로드</a>
    </div>

    <?php if (!empty($skipped)): ?>
    <div class="box">
      <h3 class="warn">제외된 항목 목록 (CSV에 포함되지 않음 — 원본 확인 후 수동 처리 필요)</h3>
      <table>
        <tr><th>브랜드</th><th>상품명</th><th>제외 사유</th></tr>
        <?php foreach ($skipped as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['brand']) ?></td>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['reason']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>가격표 → 임포트용 CSV 변환</title>
<style>
body{font-family:sans-serif;max-width:640px;margin:60px auto;}
.box{border:1px solid #ddd;border-radius:8px;padding:24px;}
input[type=file]{margin:16px 0;}
button{background:#2563eb;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;}
.note{color:#666;font-size:13px;line-height:1.6;margin-top:16px;}
</style>
</head>
<body>
<div class="box">
  <h2>공급사 가격표(xlsx) → 임포트용 CSV 변환</h2>
  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="file" name="price_file" accept=".xlsx" required>
    <br>
    <button type="submit">변환하기</button>
  </form>
  <div class="note">
    업로드 후 바로 다운로드되지 않고, 정상 변환/제외 건수를 먼저 보여주는 리포트 화면이 뜹니다.<br>
    리포트에서 "CSV 다운로드" 버튼을 눌러야 실제 파일이 받아집니다.
  </div>
</div>
</body>
</html>
