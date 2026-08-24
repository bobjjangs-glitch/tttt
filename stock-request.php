<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';
$pdo = Database::connection();

/**
 * tt_stock_requests 테이블이 없으면 생성한다.
 * product_id는 시스템에 등록된 상품과 연결할 때만 채우고,
 * 등록되지 않은 사이즈/브랜드를 요청하는 경우를 위해 brand_text/size_text를 자유 입력으로 남겨둔다.
 */
function ensure_stock_requests_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tt_stock_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT NULL COMMENT '연결된 상품ID (없으면 NULL)',
            brand_text VARCHAR(100) NULL COMMENT '요청 브랜드(자유입력)',
            size_text VARCHAR(60) NOT NULL COMMENT '요청 사이즈',
            requested_qty INT NOT NULL DEFAULT 1 COMMENT '요청 수량',
            customer_name VARCHAR(50) NOT NULL COMMENT '주문자명',
            customer_phone VARCHAR(20) NOT NULL COMMENT '주문자 연락처',
            customer_email VARCHAR(120) NULL COMMENT '주문자 이메일',
            memo TEXT NULL COMMENT '고객 요청 메모',
            status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending' COMMENT '처리 상태',
            admin_memo TEXT NULL COMMENT '관리자 처리 메모',
            processed_by INT NULL COMMENT '처리한 관리자 ID',
            processed_at DATETIME NULL COMMENT '처리 완료 시각',
            ip_address VARCHAR(45) NULL COMMENT '요청자 IP',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
ensure_stock_requests_table($pdo);

$prefillProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
$prefillBrand      = trim((string)($_GET['brand'] ?? ''));
$prefillSize        = trim((string)($_GET['size'] ?? ''));

$errors = [];
$success = false;

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $errors[] = '잘못된 요청입니다. 새로고침 후 다시 시도해 주세요.';
    } else {
        $productId    = (int)($_POST['product_id'] ?? 0);
        $brandText    = trim((string)($_POST['brand_text'] ?? ''));
        $sizeText     = trim((string)($_POST['size_text'] ?? ''));
        $qty          = (int)($_POST['requested_qty'] ?? 1);
        $customerName = trim((string)($_POST['customer_name'] ?? ''));
        $customerPhone = trim((string)($_POST['customer_phone'] ?? ''));
        $customerEmail = trim((string)($_POST['customer_email'] ?? ''));
        $memo         = trim((string)($_POST['memo'] ?? ''));

        if ($sizeText === '') $errors[] = '요청하실 타이어 사이즈를 입력해 주세요.';
        if ($customerName === '') $errors[] = '이름(또는 상호명)을 입력해 주세요.';
        if ($customerPhone === '' || !preg_match('/^[0-9\-]{9,15}$/', $customerPhone)) {
            $errors[] = '연락처를 정확히 입력해 주세요. (예: 010-1234-5678)';
        }
        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '이메일 형식이 올바르지 않습니다.';
        }
        if ($qty < 1) $qty = 1;

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO tt_stock_requests
                    (product_id, brand_text, size_text, requested_qty,
                     customer_name, customer_phone, customer_email, memo,
                     status, ip_address, created_at)
                VALUES
                    (:product_id, :brand_text, :size_text, :qty,
                     :name, :phone, :email, :memo,
                     'pending', :ip, NOW())
            ");
            $stmt->execute([
                'product_id' => $productId > 0 ? $productId : null,
                'brand_text' => $brandText !== '' ? $brandText : null,
                'size_text'  => $sizeText,
                'qty'        => $qty,
                'name'       => $customerName,
                'phone'      => $customerPhone,
                'email'      => $customerEmail !== '' ? $customerEmail : null,
                'memo'       => $memo !== '' ? $memo : null,
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>재고 문의/요청</title>
<style>
  body { font-family:"Malgun Gothic",sans-serif; background:#f6f8fa; margin:0; padding:40px 16px; }
  .card { max-width:480px; margin:0 auto; background:#fff; border:1px solid #d0d7de; border-radius:10px; padding:28px; }
  h2 { margin-top:0; font-size:20px; }
  label { display:block; font-size:13px; color:#57606a; margin:14px 0 4px; }
  input, textarea { width:100%; padding:9px 10px; border:1px solid #d0d7de; border-radius:6px; font-size:14px; box-sizing:border-box; }
  button { margin-top:18px; width:100%; padding:11px; background:#1a73e8; color:#fff; border:none; border-radius:6px; font-size:15px; cursor:pointer; }
  .err { background:#fce8e6; color:#d93025; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:10px; }
  .ok { background:#e6f4ea; color:#188038; padding:14px; border-radius:6px; font-size:14px; }
</style>
</head>
<body>
<div class="card">
  <h2>📦 재고 문의/요청</h2>

  <?php if ($success): ?>
    <div class="ok">요청이 정상적으로 접수되었습니다. 재고 확인 후 입력하신 연락처로 안내드리겠습니다.</div>
  <?php else: ?>
    <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="product_id" value="<?= (int)($prefillProductId ?? 0) ?>">

      <label>브랜드 (선택)</label>
      <input type="text" name="brand_text" value="<?= h($_POST['brand_text'] ?? $prefillBrand) ?>" placeholder="예: 미쉐린">

      <label>사이즈 <span style="color:#d93025;">*</span></label>
      <input type="text" name="size_text" required value="<?= h($_POST['size_text'] ?? $prefillSize) ?>" placeholder="예: 235/65R16">

      <label>요청 수량</label>
      <input type="number" name="requested_qty" min="1" value="<?= h($_POST['requested_qty'] ?? '1') ?>">

      <label>이름 / 상호명 <span style="color:#d93025;">*</span></label>
      <input type="text" name="customer_name" required value="<?= h($_POST['customer_name'] ?? '') ?>">

      <label>연락처 <span style="color:#d93025;">*</span></label>
      <input type="text" name="customer_phone" required placeholder="010-1234-5678" value="<?= h($_POST['customer_phone'] ?? '') ?>">

      <label>이메일 (선택)</label>
      <input type="email" name="customer_email" value="<?= h($_POST['customer_email'] ?? '') ?>">

      <label>요청 메모 (선택)</label>
      <textarea name="memo" rows="3"><?= h($_POST['memo'] ?? '') ?></textarea>

      <button type="submit">재고 요청 접수하기</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
