<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('products');
$pdo = Database::connection();

/**
 * products_import.php에서 이미 tt_products에 추가해 둔 스펙 컬럼(특히 load_speed_rating)이
 * 아직 없는 환경(엑셀 일괄 업로드를 한 번도 돌리지 않은 서버)에서도 안전하게 동작하도록
 * 재고 매칭에 필요한 컬럼만 최소한으로 존재 여부를 확인하고 없으면 추가한다.
 */
function admin_stock_ensure_columns(PDO $pdo): void
{
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tt_products")->fetchAll() as $row) {
        $existing[$row['Field']] = true;
    }
    if (!isset($existing['load_speed_rating'])) {
        $pdo->exec("ALTER TABLE tt_products ADD COLUMN load_speed_rating VARCHAR(30) NULL COMMENT '하중&속도규격'");
    }
}
admin_stock_ensure_columns($pdo);

const STOCK_UPDATE_REQUIRED_COLUMNS = ['브랜드', '사이즈', '재고'];

/** 공백 제거 + 대문자 통일. "235/65R16" vs "235 / 65 R16" 같은 표기 차이를 흡수한다. */
function admin_stock_normalize_key(string $s): string
{
    $s = mb_strtoupper(trim($s));
    return preg_replace('/\s+/u', '', $s) ?? $s;
}

/**
 * 같은 브랜드를 가리키는 영문/한글/표기 변형을 하나의 그룹으로 묶어둔다.
 * DB(tt_brands.name)에 어느 표기로 등록돼 있어도 매칭되게 하기 위함이다.
 * products_import.php의 admin_import_brand_alias_groups()와 동일한 내용이다.
 * 두 파일 중 하나에만 브랜드를 추가하면 다른 화면에서 다시 "찾을 수 없음"이 나므로,
 * 브랜드를 하나 추가할 때는 반드시 두 파일 모두에 같은 줄을 넣어야 한다.
 */
function admin_stock_brand_alias_groups(): array
{
    static $groups = [
        ['MICHELIN', '미쉐린', '미쉐린타이어'],
        ['BFGOODRICH', 'BF-GOODRICH', 'BF GOODRICH', 'BF굿리치', 'BF굿리치타이어'],
        ['CONTINENTAL', '콘티넨탈'],
        ['BRIDGESTONE', '브릿지스톤', '브리지스톤'],
        ['HANKOOK', 'HANKOOK TIRE', '한국타이어'],
        ['KUMHO', 'KUMHO TIRE', '금호타이어', '금호'],
        ['NEXEN', 'NEXEN TIRE', '넥센타이어', '넥센'],
        ['GOODYEAR', '굿이어'],
        ['PIRELLI', '피렐리'],
        ['YOKOHAMA', '요코하마'],
        ['DUNLOP', '던롭'],
        ['TOYO', 'TOYO TIRES', '토요'],
        ['FALKEN', '팔켄'],
    ];
    return $groups;
}

/**
 * CSV 원본 브랜드 표기를 받아 실제 tt_brands에 등록된 브랜드 id를 찾는다.
 * 1) DB 이름과 정규화 후 정확히 일치하면 바로 매칭
 * 2) 별칭 그룹에 속해 있으면, 같은 그룹 안에서 실제 DB에 등록된 표기를 찾아 매칭
 * 3) 둘 다 실패하면 null
 */
function admin_stock_resolve_brand_id(string $rawBrand, array $brandMap): ?int
{
    $normalized = admin_stock_normalize_key($rawBrand);
    if ($normalized === '') return null;

    if (isset($brandMap[$normalized])) {
        return $brandMap[$normalized];
    }

    foreach (admin_stock_brand_alias_groups() as $group) {
        $groupKeys = array_map('admin_stock_normalize_key', $group);
        if (!in_array($normalized, $groupKeys, true)) continue;

        foreach ($groupKeys as $key) {
            if (isset($brandMap[$key])) {
                return $brandMap[$key];
            }
        }
    }

    return null;
}

function admin_stock_parse_int(string $v): int
{
    $v = preg_replace('/[^\d\-]/', '', $v);
    return $v === '' ? 0 : (int)$v;
}

/** products_import.php의 admin_parse_csv_flexible()와 동일한 BOM/인코딩 처리 로직. */
function admin_stock_parse_csv(string $tmpPath): array
{
    $raw = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') return ['header' => [], 'rows' => []];

    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);

    $encoding = mb_detect_encoding($raw, ['UTF-8', 'EUC-KR', 'CP949', 'ISO-8859-1'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $converted = @mb_convert_encoding($raw, 'UTF-8', $encoding);
        if ($converted !== false) $raw = $converted;
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $raw);
    rewind($stream);

    $header = fgetcsv($stream);
    if ($header === false) { fclose($stream); return ['header' => [], 'rows' => []]; }
    $header = array_map(fn($h) => trim((string)$h), $header);

    $rows = [];
    while (($line = fgetcsv($stream)) !== false) {
        if (count(array_filter($line, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = trim((string)($line[$i] ?? ''));
        }
        $rows[] = $assoc;
    }
    fclose($stream);
    return ['header' => $header, 'rows' => $rows];
}

function admin_stock_build_column_check(array $csvHeader): array
{
    $missing = array_values(array_diff(STOCK_UPDATE_REQUIRED_COLUMNS, $csvHeader));
    return $missing;
}

/**
 * 브랜드+사이즈(정규화된 키)로 상품을 찾아 재고만 갱신한다.
 * - 동일 브랜드+사이즈에 상품이 정확히 1건이면 바로 갱신.
 * - 2건 이상이면(하중속도규격이 다른 변형) CSV의 '하중속도규격' 컬럼으로 추가 매칭 시도.
 * - 그래도 특정할 수 없으면 '모호'로 분류하고 갱신하지 않는다(잘못된 상품 재고를 건드리지 않기 위함).
 * - 가격, 상태, 스펙 등 다른 필드는 절대 변경하지 않는다.
 */
function admin_stock_bulk_update(PDO $pdo, array $rows): array
{
    $brandStmt = $pdo->query('SELECT id, name FROM tt_brands');
    $brandMap = [];
    foreach ($brandStmt->fetchAll() as $b) {
        $brandMap[admin_stock_normalize_key($b['name'])] = (int)$b['id'];
    }

    $prodStmt = $pdo->query('SELECT id, name, brand_id, spec, load_speed_rating, stock FROM tt_products WHERE brand_id IS NOT NULL');
    $index = [];
    foreach ($prodStmt->fetchAll() as $p) {
        if ($p['spec'] === null || trim((string)$p['spec']) === '') continue;
        $key = (int)$p['brand_id'] . '|' . admin_stock_normalize_key((string)$p['spec']);
        $index[$key][] = $p;
    }

    $successCount = 0;
    $updated = [];
    $ambiguous = [];
    $notFound = [];
    $errors = [];

    $updateStmt = $pdo->prepare('UPDATE tt_products SET stock = :stock WHERE id = :id');

    foreach ($rows as $i => $r) {
        $rowNo = $i + 2;

        $brandRaw     = trim($r['브랜드'] ?? '');
        $sizeRaw      = trim($r['사이즈'] ?? '');
        $loadSpeedRaw = trim($r['하중속도규격'] ?? '');
        $stockRaw     = trim($r['재고'] ?? '');

        if ($brandRaw === '' || $sizeRaw === '') {
            $errors[] = "{$rowNo}행: 브랜드 또는 사이즈가 비어있어 건너뛰었습니다.";
            continue;
        }

        $stockVal = admin_stock_parse_int($stockRaw);
        if ($stockRaw === '' || $stockVal < 0) {
            $errors[] = "{$rowNo}행 [{$brandRaw} {$sizeRaw}]: 재고 값이 올바르지 않습니다. (입력값: '{$stockRaw}')";
            continue;
        }

        $brandId = admin_stock_resolve_brand_id($brandRaw, $brandMap);
        if ($brandId === null) {
            $notFound[] = "{$rowNo}행: 브랜드 '{$brandRaw}'를 찾을 수 없습니다.";
            continue;
        }

        $sizeKey = admin_stock_normalize_key($sizeRaw);
        $indexKey = $brandId . '|' . $sizeKey;
        $candidates = $index[$indexKey] ?? [];

        if (empty($candidates)) {
            $notFound[] = "{$rowNo}행: '{$brandRaw} {$sizeRaw}' 조건에 매칭되는 상품이 없습니다.";
            continue;
        }

        $target = null;
        if (count($candidates) === 1) {
            $target = $candidates[0];
        } elseif ($loadSpeedRaw !== '') {
            $loadKey = admin_stock_normalize_key($loadSpeedRaw);
            $filtered = array_values(array_filter($candidates, function ($c) use ($loadKey) {
                return admin_stock_normalize_key((string)($c['load_speed_rating'] ?? '')) === $loadKey;
            }));
            if (count($filtered) === 1) {
                $target = $filtered[0];
            }
        }

        if ($target === null) {
            $names = implode(', ', array_map(fn($c) => "#{$c['id']} {$c['name']}", $candidates));
            $ambiguous[] = "{$rowNo}행: '{$brandRaw} {$sizeRaw}' 조건에 상품이 " . count($candidates) . "건 있어 특정할 수 없습니다. (하중속도규격 값을 추가하면 구분됩니다) [{$names}]";
            continue;
        }

        try {
            $updateStmt->execute(['stock' => $stockVal, 'id' => $target['id']]);
            $successCount++;
            $updated[] = "{$rowNo}행: #{$target['id']} {$target['name']} 재고 {$target['stock']} → {$stockVal}";
        } catch (Throwable $e) {
            error_log('[admin/stock_update] ' . $e->getMessage());
            $errors[] = "{$rowNo}행: 저장 중 오류가 발생했습니다.";
        }
    }

    return [
        'success'   => $successCount,
        'updated'   => $updated,
        'ambiguous' => $ambiguous,
        'notfound'  => $notFound,
        'errors'    => $errors,
    ];
}

$result = null;
$missingColumns = null;

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/stock_update.php');
    }

    if (empty($_FILES['csv_file']['name'])) {
        flash('admin_error', '업로드할 CSV 파일을 선택해 주세요.');
        redirect('/admin/stock_update.php');
    }
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        flash('admin_error', '파일 업로드 중 오류가 발생했습니다. (code=' . $_FILES['csv_file']['error'] . ')');
        redirect('/admin/stock_update.php');
    }
    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        flash('admin_error', 'CSV 파일(.csv)만 업로드할 수 있습니다. 엑셀에서 "CSV(쉼표로 분리)"로 저장해 주세요.');
        redirect('/admin/stock_update.php');
    }

    $parsed = admin_stock_parse_csv($_FILES['csv_file']['tmp_name']);
    if (empty($parsed['header'])) {
        flash('admin_error', '업로드한 파일을 읽을 수 없습니다.');
        redirect('/admin/stock_update.php');
    }

    $missingColumns = admin_stock_build_column_check($parsed['header']);
    if (!empty($missingColumns)) {
        flash('admin_error', '필수 컬럼이 없습니다: ' . implode(', ', $missingColumns));
        redirect('/admin/stock_update.php');
    }

    if (empty($parsed['rows'])) {
        flash('admin_error', '업로드한 파일에 데이터 행이 없습니다.');
        redirect('/admin/stock_update.php');
    }

    $result = admin_stock_bulk_update($pdo, $parsed['rows']);

    AdminAuth::log(
        (int)AdminAuth::currentAdminId(),
        'stock_bulk_update',
        "재고 일괄 업데이트: 성공 {$result['success']}건, 모호 " . count($result['ambiguous']) . "건, 미매칭 " . count($result['notfound']) . "건, 오류 " . count($result['errors']) . '건'
    );
}

$pageTitle = '재고 일괄 업데이트';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card" style="background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #e0e7ff;">
  <h2 class="admin-page-title">📦 브랜드+사이즈 매칭 재고 일괄 업데이트</h2>
  <p class="admin-form-hint" style="line-height:1.7;">
    이 기능은 <b>재고 수량만 변경</b>합니다. 가격, 상태, 스펙 등 다른 항목은 절대 건드리지 않습니다.<br>
    CSV는 <b>브랜드, 사이즈, 재고</b> 3개 컬럼이 필수입니다. 동일 브랜드+사이즈로 상품이 여러 건(하중속도규격이 다른 경우)
    존재할 경우 <b>하중속도규격</b> 컬럼을 추가로 넣으면 정확히 구분해서 매칭됩니다. 구분이 안 되면 해당 행은
    "모호"로 분류되어 <b>업데이트되지 않고</b> 결과 화면에 표시됩니다.
  </p>
  <p class="admin-form-hint" style="margin-top:8px;">예시:</p>
  <pre style="background:#fff;border:1px solid #e0e7ff;border-radius:8px;padding:10px 14px;font-size:13px;overflow-x:auto;">브랜드,사이즈,하중속도규격,재고
미쉐린,235/65R16,115/113T,20
BF굿리치,32X11.50R15LT,113R,15</pre>
</div>

<div class="admin-card">
  <h3 class="admin-form-section-title">CSV 파일 업로드</h3>
  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="admin-form-row">
      <input type="file" name="csv_file" accept=".csv" required>
    </div>
    <div class="admin-form-actions">
      <a href="<?= BASE_URL ?>/admin/products.php" class="btn-admin-secondary">취소</a>
      <button type="submit" class="btn-admin-primary">재고 업데이트 실행</button>
    </div>
  </form>
</div>

<?php if ($result !== null): ?>
<div class="admin-card">
  <h3 class="admin-form-section-title">처리 결과</h3>
  <p>
    ✅ 성공 <b><?= (int)$result['success'] ?></b>건
    &nbsp;·&nbsp; ❓ 모호(미확정) <b><?= count($result['ambiguous']) ?></b>건
    &nbsp;·&nbsp; 🚫 미매칭 <b><?= count($result['notfound']) ?></b>건
    &nbsp;·&nbsp; ❌ 오류 <b><?= count($result['errors']) ?></b>건
  </p>

  <?php if (!empty($result['updated'])): ?>
    <details style="margin-bottom:10px;">
      <summary style="cursor:pointer;font-weight:600;">✅ 성공한 항목 보기 (<?= count($result['updated']) ?>건)</summary>
      <ul style="margin-top:8px;">
        <?php foreach ($result['updated'] as $u): ?>
          <li><?= h($u) ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>

  <?php if (!empty($result['ambiguous'])): ?>
    <div class="admin-alert" style="background:#fff7ed;border:1px solid #fdba74;color:#92400e;">
      <b>❓ 모호(미확정) — 하중속도규격을 채워서 다시 업로드하세요:</b>
      <ul>
        <?php foreach ($result['ambiguous'] as $a): ?>
          <li><?= h($a) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($result['notfound'])): ?>
    <div class="admin-alert admin-alert-error">
      <b>🚫 미매칭 — 브랜드명 또는 사이즈 표기를 확인하세요:</b>
      <ul>
        <?php foreach ($result['notfound'] as $n): ?>
          <li><?= h($n) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($result['errors'])): ?>
    <div class="admin-alert admin-alert-error">
      <b>❌ 처리 오류:</b>
      <ul>
        <?php foreach ($result['errors'] as $err): ?>
          <li><?= h($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="admin-form-actions">
    <a href="<?= BASE_URL ?>/admin/products.php" class="btn-admin-primary">상품 목록으로 이동</a>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
