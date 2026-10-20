<?php
require_once __DIR__ . '/core/bootstrap.php';
$pdo = Database::connection();

// 문의 등록 처리
if (is_post() && ($_POST['form_type'] ?? '') === 'inquiry') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('error', '유효하지 않은 요청입니다.');
        redirect(BASE_URL . '/cs-center.php');
    }

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $content = trim($_POST['content'] ?? '');

    if ($name === '' || $phone === '' || $subject === '' || $content === '') {
        flash('error', '필수 항목을 모두 입력해주세요.');
        redirect(BASE_URL . '/cs-center.php#inquiry');
    }

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM tt_inquiries")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tt_inquiries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            name VARCHAR(50) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(191) NULL,
            category VARCHAR(30) NOT NULL,
            subject VARCHAR(150) NOT NULL,
            content TEXT NOT NULL,
            status ENUM('pending','answered','closed') NOT NULL DEFAULT 'pending',
            answer TEXT NULL,
            answered_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    }

    $stmt = $pdo->prepare('INSERT INTO tt_inquiries (user_id, name, phone, email, category, subject, content, status, created_at)
                          VALUES (:uid, :name, :phone, :email, :category, :subject, :content, "pending", NOW())');
    $stmt->execute([
        'uid' => Auth::isLoggedIn() ? Auth::currentUserId() : null,
        'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
        'category' => $category, 'subject' => $subject, 'content' => $content,
    ]);
    flash('success', '문의가 접수되었습니다. 영업일 기준 1~2일 내 회신드립니다.');
    redirect(BASE_URL . '/cs-center.php#inquiry');
}

// 내 문의 내역 (로그인 시)
$myInquiries = [];
if (Auth::isLoggedIn()) {
    try {
        $stmt = $pdo->prepare('SELECT id, category, subject, content, status, answer, answered_at, created_at
                               FROM tt_inquiries WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10');
        $stmt->execute(['uid' => Auth::currentUserId()]);
        $myInquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$pageTitle = '고객센터';
require __DIR__ . '/includes/header.php';
?>

<div class="cs-page">
  <!-- 히어로 -->
  <div class="cs-hero">
    <div class="cs-hero-inner">
      <h1>고객센터</h1>
      <p>궁금한 점이 있으시면 언제든 문의해주세요</p>
    </div>
  </div>

  <div class="cs-container">
    <!-- 빠른 문의 카드 -->
    <div class="cs-quick-grid">
      <div class="cs-quick-card">
        <div class="cs-quick-icon">📞</div>
        <h3>전화문의</h3>
        <p class="cs-quick-value">1588-0000</p>
        <p class="cs-quick-sub">평일 09:00 ~ 18:00</p>
      </div>
      <div class="cs-quick-card">
        <div class="cs-quick-icon">✉️</div>
        <h3>이메일</h3>
        <p class="cs-quick-value">help@tiretop.kr</p>
        <p class="cs-quick-sub">24시간 접수 / 영업일 회신</p>
      </div>
      <div class="cs-quick-card">
        <div class="cs-quick-icon">💬</div>
        <h3>카카오톡</h3>
        <p class="cs-quick-value">@넘버원타이어</p>
        <p class="cs-quick-sub">평일 08:30 ~ 18:00</p>
      </div>
      <div class="cs-quick-card">
        <div class="cs-quick-icon">📍</div>
        <h3>매장찾기</h3>
        <p class="cs-quick-value">전국 설치점</p>
        <p class="cs-quick-sub">가까운 장착점 검색</p>
      </div>
    </div>

    <!-- FAQ -->
    <section class="cs-faq-sec">
      <h2 class="cs-sec-title">자주 묻는 질문</h2>
      <div class="cs-faq-list">
        <details class="cs-faq-item" open>
          <summary>타이어 규격은 어떻게 확인하나요?</summary>
          <div class="cs-faq-answer">
            <p>운전석 도어 프레임 또는 차량 사용설명서에 타이어 규격이 표기되어 있습니다. 기존 타이어 측면에 적힌 숫자(예: 225/45R18)를 확인하셔도 됩니다.</p>
          </div>
        </details>
        <details class="cs-faq-item">
          <summary>배송은 얼마나 걸리나요?</summary>
          <div class="cs-faq-answer">
            <p>결제 완료 후 2~5영업일 내 배송됩니다. 도서산간 지역은 3~7영업일 소요될 수 있습니다. 배송조회는 마이페이지 > 주문내역에서 가능합니다.</p>
          </div>
        </details>
        <details class="cs-faq-item">
          <summary>교환/반품은 어떻게 하나요?</summary>
          <div class="cs-faq-answer">
            <p>상품 수령 후 7일 이내 교환/반품 가능합니다. 단, 타이어 장착 후(사용 흔적이 있는 경우)는 교환/반품이 불가합니다. 고객센터로 연락주시면 안내해드립니다.</p>
          </div>
        </details>
        <details class="cs-faq-item">
          <summary>사업자 회원 가입은 어떻게 하나요?</summary>
          <div class="cs-faq-answer">
            <p>로그인 페이지에서 '사업자 회원가입' 탭을 선택하고 사업자등록번호, 상호명, 대표자명, 주소를 입력하시면 됩니다. 가입 후 관리자 승인(1~2영업일)이 완료되면 로그인 가능합니다.</p>
          </div>
        </details>
        <details class="cs-faq-item">
          <summary>사업자 회원의 혜택은 무엇인가요?</summary>
          <div class="cs-faq-answer">
            <p>사업자 회원에게는 판매가에서 추가 할인이 적용되며, DOT 옵션별 재고/가격을 선택하여 주문할 수 있습니다. 사업자 전용 주문 관리 페이지도 제공됩니다.</p>
          </div>
        </details>
        <details class="cs-faq-item">
          <summary>설치 서비스도 제공하나요?</summary>
          <div class="cs-faq-answer">
            <p>전국 제휴 설치점에서 타이어 장착 서비스를 이용하실 수 있습니다. 설치 비용은 별도이며, 주문 시 또는 배송 후 설치점에 직접 문의하시면 됩니다.</p>
          </div>
        </details>
        <details class="cs-faq-item">
          <summary>DOT 번호는 무엇인가요?</summary>
          <div class="cs-faq-answer">
            <p>DOT 번호는 타이어의 생산 주차와 연도를 나타냅니다. 예: 2625 → 2025년 26주차 생산. 신선도 확인에 참고하시면 됩니다.</p>
          </div>
        </details>
      </div>
    </section>

    <!-- 1:1 문의 -->
    <section class="cs-inquiry-sec" id="inquiry">
      <h2 class="cs-sec-title">1:1 문의하기</h2>
      <?php if ($ok = flash('success')): ?>
        <div class="cs-flash success"><?= h($ok) ?></div>
      <?php endif; ?>
      <?php if ($err = flash('error')): ?>
        <div class="cs-flash error"><?= h($err) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= BASE_URL ?>/cs-center.php#inquiry" class="cs-inquiry-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_type" value="inquiry">

        <div class="cs-form-row">
          <label>문의 유형 <span class="req">*</span></label>
          <select name="category" required>
            <option value="">선택해주세요</option>
            <option value="product">상품 문의</option>
            <option value="order">주문/결제</option>
            <option value="shipping">배송</option>
            <option value="return">교환/반품</option>
            <option value="member">회원/계정</option>
            <option value="business">사업자 관련</option>
            <option value="etc">기타</option>
          </select>
        </div>

        <div class="cs-form-row cs-form-2col">
          <div>
            <label>이름 <span class="req">*</span></label>
            <input type="text" name="name" value="<?= Auth::isLoggedIn() ? h(Auth::currentUserName()) : '' ?>" required>
          </div>
          <div>
            <label>연락처 <span class="req">*</span></label>
            <input type="text" name="phone" placeholder="010-1234-5678" required>
          </div>
        </div>

        <div class="cs-form-row">
          <label>이메일</label>
          <input type="email" name="email" value="<?= Auth::isLoggedIn() ? h(Auth::currentUserId() ? '' : '') : '' ?>" placeholder="example@email.com">
        </div>

        <div class="cs-form-row">
          <label>제목 <span class="req">*</span></label>
          <input type="text" name="subject" required>
        </div>

        <div class="cs-form-row">
          <label>내용 <span class="req">*</span></label>
          <textarea name="content" rows="6" placeholder="문의 내용을 상세히 입력해주세요." required></textarea>
        </div>

        <button type="submit" class="cs-submit-btn">문의 접수하기</button>
      </form>
    </section>

    <!-- 내 문의 내역 -->
    <?php if (Auth::isLoggedIn() && !empty($myInquiries)): ?>
    <section class="cs-my-inquiries">
      <h2 class="cs-sec-title">내 문의 내역</h2>
      <div class="cs-inquiry-list">
        <?php foreach ($myInquiries as $inq): ?>
        <div class="cs-inquiry-item">
          <div class="cs-inquiry-head">
            <span class="cs-inquiry-cat"><?= h($inq['category']) ?></span>
            <span class="cs-inquiry-status status-<?= h($inq['status']) ?>">
              <?= $inq['status'] === 'pending' ? '답변대기' : ($inq['status'] === 'answered' ? '답변완료' : '종료') ?>
            </span>
            <span class="cs-inquiry-date"><?= h(date('Y.m.d', strtotime($inq['created_at']))) ?></span>
          </div>
          <h3 class="cs-inquiry-subject"><?= h($inq['subject']) ?></h3>
          <p class="cs-inquiry-content"><?= nl2br(h($inq['content'])) ?></p>
          <?php if (!empty($inq['answer'])): ?>
          <div class="cs-inquiry-answer">
            <strong>답변</strong>
            <p><?= nl2br(h($inq['answer'])) ?></p>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<style>
.cs-page{background:#f8fafc;min-height:100vh;}
.cs-hero{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:48px 20px 36px;text-align:center;}
.cs-hero h1{font-size:28px;font-weight:900;margin:0 0 6px;}
.cs-hero p{font-size:14px;color:#cbd5e1;margin:0;}
.cs-container{max-width:800px;margin:0 auto;padding:32px 20px 80px;}

/* 빠른 문의 카드 */
.cs-quick-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:40px;}
.cs-quick-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px 16px;text-align:center;transition:box-shadow .15s;}
.cs-quick-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.06);}
.cs-quick-icon{font-size:28px;margin-bottom:8px;}
.cs-quick-card h3{font-size:13px;font-weight:700;color:#64748b;margin:0 0 6px;}
.cs-quick-value{font-size:16px;font-weight:800;color:#0f172a;margin:0 0 2px;}
.cs-quick-sub{font-size:11px;color:#94a3b8;margin:0;}

/* 섹션 타이틀 */
.cs-sec-title{font-size:20px;font-weight:800;color:#0f172a;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #0f172a;}

/* FAQ */
.cs-faq-list{margin-bottom:48px;}
.cs-faq-item{background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:8px;overflow:hidden;}
.cs-faq-item summary{padding:16px 20px;font-size:14px;font-weight:700;color:#1e293b;cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;}
.cs-faq-item summary::after{content:'+';font-size:18px;color:#94a3b8;transition:transform .15s;}
.cs-faq-item[open] summary::after{transform:rotate(45deg);}
.cs-faq-item summary::-webkit-details-marker{display:none;}
.cs-faq-answer{padding:0 20px 16px;}
.cs-faq-answer p{font-size:13px;color:#475569;line-height:1.7;margin:0;}

/* 문의 폼 */
.cs-inquiry-sec{margin-bottom:48px;}
.cs-inquiry-form{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;}
.cs-form-row{margin-bottom:16px;}
.cs-form-row label{display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;}
.cs-form-row .req{color:#ef4444;}
.cs-form-row input,.cs-form-row select,.cs-form-row textarea{
  width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:10px;
  font-size:14px;font-family:inherit;background:#fff;
  transition:border-color .15s,box-shadow .15s;
}
.cs-form-row input:focus,.cs-form-row select:focus,.cs-form-row textarea:focus{
  outline:none;border-color:#0f172a;box-shadow:0 0 0 3px rgba(15,23,42,.08);
}
.cs-form-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.cs-submit-btn{
  width:100%;height:48px;border:none;border-radius:12px;
  background:linear-gradient(135deg,#0f172a,#1e293b);
  color:#fff;font-size:15px;font-weight:800;cursor:pointer;
  box-shadow:0 4px 12px rgba(15,23,42,.15);transition:transform .12s;
}
.cs-submit-btn:hover{transform:translateY(-1px);}

/* 플래시 */
.cs-flash{padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;}
.cs-flash.success{background:#f0fdf4;color:#15803d;}
.cs-flash.error{background:#fef2f2;color:#b91c1c;}

/* 내 문의 내역 */
.cs-inquiry-list{display:flex;flex-direction:column;gap:12px;}
.cs-inquiry-item{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;}
.cs-inquiry-head{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.cs-inquiry-cat{font-size:12px;font-weight:700;color:#64748b;background:#f1f5f9;padding:3px 10px;border-radius:999px;}
.cs-inquiry-status{font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;}
.cs-inquiry-status.status-pending{background:#fef3c7;color:#92400e;}
.cs-inquiry-status.status-answered{background:#d1fae5;color:#065f46;}
.cs-inquiry-status.status-closed{background:#f1f5f9;color:#64748b;}
.cs-inquiry-date{font-size:12px;color:#94a3b8;margin-left:auto;}
.cs-inquiry-subject{font-size:15px;font-weight:700;color:#1e293b;margin:0 0 6px;}
.cs-inquiry-content{font-size:13px;color:#475569;line-height:1.6;margin:0 0 10px;}
.cs-inquiry-answer{background:#f8fafc;border-radius:10px;padding:12px 14px;}
.cs-inquiry-answer strong{font-size:13px;color:#0f172a;display:block;margin-bottom:4px;}
.cs-inquiry-answer p{font-size:13px;color:#334155;line-height:1.6;margin:0;}

@media(max-width:768px){
  .cs-hero{padding:36px 16px 24px;}
  .cs-hero h1{font-size:22px;}
  .cs-container{padding:20px 12px 60px;}
  .cs-quick-grid{grid-template-columns:1fr 1fr;gap:10px;}
  .cs-quick-card{padding:14px 10px;}
  .cs-quick-icon{font-size:24px;}
  .cs-quick-value{font-size:14px;}
  .cs-form-2col{grid-template-columns:1fr;}
  .cs-sec-title{font-size:18px;}
  .cs-inquiry-form{padding:16px;}
}
@media(max-width:480px){
  .cs-quick-grid{grid-template-columns:1fr;}
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
