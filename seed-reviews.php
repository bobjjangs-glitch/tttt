<?php
// ⚠️ 1회용 더미 데이터 스크립트입니다. 실행 후 반드시 이 파일을 서버에서 삭제하세요.
require_once __DIR__ . '/core/bootstrap.php';
$pdo = Database::connection();

// 1. 리뷰를 연결할 실제 상품 목록 확보 (판매중 상품 중 최대 10개)
$products = $pdo->query("
    SELECT id, name FROM tt_products
    WHERE status = 'active'
    ORDER BY sales_count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if (count($products) === 0) {
    die('❌ tt_products 테이블에 판매중(active) 상품이 없습니다. 먼저 상품을 등록해주세요.');
}

// 2. 리뷰 작성자로 쓸 실제 회원 목록 확보 (없으면 더미 회원 1명 생성)
$users = $pdo->query("SELECT id, name FROM tt_users LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

if (count($users) === 0) {
    $pdo->exec("
        INSERT INTO tt_users (name, email, password, created_at)
        VALUES ('김리뷰', 'seed_dummy_user@example.com', '" . password_hash('temp1234', PASSWORD_DEFAULT) . "', NOW())
    ");
    $users = $pdo->query("SELECT id, name FROM tt_users LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
}

// 3. 더미 리뷰 본문 10개 (타이어 쇼핑몰 실제 후기 톤으로 작성)
$reviewTexts = [
    ['rating' => 5, 'content' => '작년에 교체한 타이어인데 승차감이 정말 부드러워졌어요. 소음도 확실히 줄었고 배송도 빨랐습니다. 재구매 의사 100%입니다.'],
    ['rating' => 5, 'content' => '가격 대비 품질이 진짜 좋습니다. 매장 예약까지 한 번에 되니까 시간도 절약되고 편했어요. 강력 추천합니다!'],
    ['rating' => 4, 'content' => '빗길 주행할 때 그립감이 좋아진 게 바로 느껴졌어요. 다만 배송이 하루 정도 늦어져서 4점 드립니다.'],
    ['rating' => 5, 'content' => '견적 비교 없이 그냥 여기서 바로 결제했는데 최저가 맞더라구요. 장착 예약 시스템도 편하고 만족스럽습니다.'],
    ['rating' => 5, 'content' => '타이어 교체 처음 해봐서 걱정했는데 상담부터 예약까지 안내가 명확해서 어렵지 않았어요. 결과물도 좋습니다.'],
    ['rating' => 4, 'content' => '가격도 합리적이고 승차감도 만족스러운데, 매장 위치가 조금 애매해서 찾아가는 데 시간이 걸렸어요.'],
    ['rating' => 5, 'content' => '고속도로 주행 시 노면 소음이 확실히 줄었습니다. 연비도 살짝 좋아진 느낌이에요. 다음에도 여기서 구매할게요.'],
    ['rating' => 5, 'content' => '주말에 예약해서 바로 교체받았어요. 대기 시간도 짧고 직원분들도 친절하게 설명해주셔서 좋았습니다.'],
    ['rating' => 4, 'content' => '타이어 자체는 만족스러운데 포장 상태가 살짝 찌그러져 있어서 아쉬웠어요. 성능에는 문제없어 보입니다.'],
    ['rating' => 5, 'content' => '여러 사이트 가격 비교해봤는데 여기가 제일 저렴했고 후기도 많아서 믿고 구매했습니다. 결과도 만족스럽네요.'],
];

// 4. 실제 INSERT 실행 — 상품/회원을 랜덤 매칭, 작성일은 최근 30일 내로 분산
$stmt = $pdo->prepare("
    INSERT INTO tt_reviews (product_id, user_id, rating, content, created_at)
    VALUES (:product_id, :user_id, :rating, :content, :created_at)
");

$insertedCount = 0;
foreach ($reviewTexts as $i => $rv) {
    $product = $products[array_rand($products)];
    $user = $users[array_rand($users)];
    $daysAgo = random_int(0, 29);
    $createdAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));

    $stmt->execute([
        ':product_id' => $product['id'],
        ':user_id'    => $user['id'],
        ':rating'     => $rv['rating'],
        ':content'    => $rv['content'],
        ':created_at' => $createdAt,
    ]);
    $insertedCount++;
    echo "✅ [{$insertedCount}] 상품 '{$product['name']}' 에 리뷰 등록 완료 (평점 {$rv['rating']}점)<br>";
}

echo "<hr><strong>총 {$insertedCount}개의 더미 리뷰가 등록되었습니다.</strong><br>";
echo "<span style='color:red;'>⚠️ 이 파일(seed-reviews.php)은 반드시 지금 서버에서 삭제해주세요.</span>";
