<?php
require_once __DIR__ . '/core/bootstrap.php';
$pageTitle = '이용안내';
require __DIR__ . '/includes/header.php';
?>

<div class="guide-page">
  <div class="guide-hero">
    <h1>이용안내</h1>
    <p>타이어탑 이용 방법을 한눈에 알려드립니다</p>
  </div>

  <div class="guide-container">
    <!-- 목차 -->
    <nav class="guide-toc">
      <a href="#order">주문/결제</a>
      <a href="#shipping">배송안내</a>
      <a href="#return">교환/반품</a>
      <a href="#tire">타이어 정보</a>
      <a href="#member">회원 혜택</a>
      <a href="#faq">자주 묻는 질문</a>
    </nav>

    <!-- 주문/결제 -->
    <section class="guide-sec" id="order">
      <h2><span class="guide-icon">🛒</span> 주문/결제</h2>
      <div class="guide-card">
        <h3>주문 방법</h3>
        <ol>
          <li>원하는 타이어를 검색 또는 카테고리에서 선택합니다.</li>
          <li>상품 상세페이지에서 수량을 선택하고 장바구니에 담거나 바로 구매합니다.</li>
          <li>배송지 정보 입력 후 결제 방법을 선택합니다.</li>
          <li>무통장입금, 가상계좌, 신용카드 결제가 가능합니다.</li>
        </ol>
        <h3>결제 방법</h3>
        <table class="guide-table">
          <tr><th>무통장입금</th><td>주문 완료 후 24시간 이내 입금 시 자동 주문 확정</td></tr>
          <tr><th>가상계좌</th><td>전용 가상계좌로 입금 시 자동 결제 완료 처리</td></tr>
          <tr><th>신용카드</th><td>실시간 카드 승인으로 즉시 결제 완료</td></tr>
        </table>
      </div>
    </section>

    <!-- 배송안내 -->
    <section class="guide-sec" id="shipping">
      <h2><span class="guide-icon">🚚</span> 배송안내</h2>
      <div class="guide-card">
        <h3>배송 기간</h3>
        <p>결제 완료 후 <strong>2~5영업일</strong> 내 배송됩니다. (도서산간 지역은 3~7영업일)</p>
        <h3>배송비</h3>
        <table class="guide-table">
          <tr><th>기본 배송비</th><td>전 상품 무료배송</td></tr>
          <tr><th>도서산간</th><td>추가 배송비 3,000~5,000원 (지역별 상이)</td></tr>
          <tr><th>설치 서비스</th><td>전국 설치점에서 타이어 장착 가능 (별도 요금)</td></tr>
        </table>
      </div>
    </section>

    <!-- 교환/반품 -->
    <section class="guide-sec" id="return">
      <h2><span class="guide-icon">↩️</span> 교환/반품</h2>
      <div class="guide-card">
        <h3>교환/반품 기간</h3>
        <p>상품 수령 후 <strong>7일 이내</strong> 교환/반품 가능합니다.</p>
        <h3>교환/반품 불가 사유</h3>
        <ul>
          <li>타이어 장착 후 (사용 흔적이 있는 경우)</li>
          <li>고객 부주의로 인한 상품 훼손</li>
          <li>DOT 번호가 지워진 경우</li>
          <li>특별 주문 제작 상품</li>
        </ul>
      </div>
    </section>

    <!-- 타이어 정보 -->
    <section class="guide-sec" id="tire">
      <h2><span class="guide-icon">🛞</span> 타이어 정보</h2>
      <div class="guide-card">
        <h3>타이어 규격 읽는 법</h3>
        <div class="tire-spec-visual">
          <span class="spec-part">225</span>/<span class="spec-part">45</span>R<span class="spec-part">18</span>
        </div>
        <table class="guide-table">
          <tr><th>225</th><td>타이어 폭 (mm) — 타이어 접지면의 너비</td></tr>
          <tr><th>45</th><td>편평비 (%) — 타이어 높이 / 폭 비율 (낮을수록 스포티)</td></tr>
          <tr><th>R</th><td>타이어 구조 (Radial — 방사형 구조)</td></tr>
          <tr><th>18</th><td>림 직경 (inch) — 휠 사이즈</td></tr>
        </table>
        <h3>DOT 번호란?</h3>
        <p>DOT 번호는 타이어 제조 주차와 연도를 나타냅니다. 예: <strong>2625</strong> → 2025년 26주차 생산</p>
      </div>
    </section>

    <!-- 회원 혜택 -->
    <section class="guide-sec" id="member">
      <h2><span class="guide-icon">🎁</span> 회원 혜택</h2>
      <div class="guide-card">
        <div class="member-benefit-grid">
          <div class="member-benefit-card">
            <div class="mb-icon">👤</div>
            <h3>일반 회원</h3>
            <ul>
              <li>기본 가격으로 결제</li>
              <li>리뷰 작성 시 포인트 적립</li>
              <li>쿠폰 발급 및 사용</li>
              <li>찜 목록 관리</li>
            </ul>
          </div>
          <div class="member-benefit-card biz">
            <div class="mb-icon">🏢</div>
            <h3>사업자 회원</h3>
            <ul>
              <li>사업자 우대가 적용 (추가 할인)</li>
              <li>DOT 옵션별 가격 선택 가능</li>
              <li>사업자 전용 주문 관리</li>
              <li>대량 구매 문의 가능</li>
            </ul>
            <p class="mb-note">※ 사업자 회원가입 후 관리자 승인(1~2영업일) 필요</p>
          </div>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section class="guide-sec" id="faq">
      <h2><span class="guide-icon">💬</span> 자주 묻는 질문</h2>
      <div class="guide-card">
        <details class="faq-item" open>
          <summary>타이어 규격은 어떻게 확인하나요?</summary>
          <p>운전석 도어 프레임 또는 차량 사용설명서에 타이어 규격이 표기되어 있습니다. 기존 타이어 측면에 적힌 숫자를 확인하셔도 됩니다.</p>
        </details>
        <details class="faq-item">
          <summary>DOT 옵션은 무엇인가요?</summary>
          <p>DOT 번호는 타이어의 생산 주차를 의미합니다. 사업자 회원은 특정 DOT의 재고를 선택하여 주문할 수 있으며, 일반 회원은 기본 상품가로 결제됩니다.</p>
        </details>
        <details class="faq-item">
          <summary>사업자 회원 가입은 어떻게 하나요?</summary>
          <p>로그인 페이지에서 '사업자 회원가입' 탭을 선택하고 사업자등록번호, 상호명, 대표자명, 주소를 입력하시면 됩니다. 가입 후 관리자 승인(1~2영업일)이 완료되면 로그인 가능합니다.</p>
        </details>
        <details class="faq-item">
          <summary>배송은 얼마나 걸리나요?</summary>
          <p>결제 완료 후 2~5영업일 내 배송됩니다. 도서산간 지역은 3~7영업일 소요될 수 있습니다.</p>
        </details>
        <details class="faq-item">
          <summary>설치 서비스도 제공하나요?</summary>
          <p>전국 제휴 설치점에서 타이어 장착 서비스를 이용하실 수 있습니다. 설치 비용은 별도이며, 주문 시 설치점을 선택할 수 있습니다.</p>
        </details>
      </div>
    </section>

    <!-- 고객센터 -->
    <section class="guide-sec guide-cs" id="cs">
      <h2><span class="guide-icon">📞</span> 고객센터</h2>
      <div class="guide-cs-grid">
        <div class="cs-card">
          <h3>전화 문의</h3>
          <p class="cs-phone">1588-0000</p>
          <p class="cs-hours">평일 09:00 ~ 18:00<br>주말/공휴일 휴무</p>
        </div>
        <div class="cs-card">
          <h3>이메일 문의</h3>
          <p class="cs-email">help@tiretop.kr</p>
          <p class="cs-hours">24시간 접수<br>영업일 내 회신</p>
        </div>
        <div class="cs-card">
          <h3>카카오톡 상담</h3>
          <p class="cs-kakao">@타이어탑</p>
          <p class="cs-hours">평일 09:00 ~ 17:00</p>
        </div>
      </div>
    </section>
  </div>
</div>

<style>
.guide-page{background:#f8fafc;min-height:100vh;}
.guide-hero{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;text-align:center;padding:60px 20px 40px;}
.guide-hero h1{font-size:32px;font-weight:900;margin:0 0 8px;}
.guide-hero p{font-size:15px;color:#cbd5e1;margin:0;}
.guide-container{max-width:800px;margin:0 auto;padding:32px 20px 80px;}
.guide-toc{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:32px;position:sticky;top:60px;background:#f8fafc;padding:12px 0;z-index:10;border-bottom:1px solid #e2e8f0;}
.guide-toc a{font-size:13px;font-weight:700;color:#475569;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:6px 14px;text-decoration:none;transition:.15s;}
.guide-toc a:hover{background:#0f172a;color:#fff;border-color:#0f172a;}
.guide-sec{margin-bottom:40px;scroll-margin-top:80px;}
.guide-sec h2{font-size:22px;font-weight:800;color:#0f172a;margin:0 0 16px;display:flex;align-items:center;gap:8px;}
.guide-icon{font-size:24px;}
.guide-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.guide-card h3{font-size:16px;font-weight:800;color:#1e293b;margin:0 0 10px;}
.guide-card h3:not(:first-child){margin-top:24px;}
.guide-card p{font-size:14px;color:#334155;line-height:1.7;margin:0 0 8px;}
.guide-card ol,.guide-card ul{padding-left:20px;margin:0 0 12px;}
.guide-card li{font-size:14px;color:#334155;line-height:1.8;margin-bottom:4px;}
.guide-table{width:100%;border-collapse:collapse;margin:8px 0 12px;font-size:14px;}
.guide-table th{text-align:left;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:700;width:140px;}
.guide-table td{padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#1e293b;}
.tire-spec-visual{font-size:36px;font-weight:900;color:#0f172a;text-align:center;padding:20px;background:#f8fafc;border-radius:12px;margin:12px 0 16px;letter-spacing:2px;}
.tire-spec-visual .spec-part{color:#2563eb;}
.member-benefit-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.member-benefit-card{border:1px solid #e2e8f0;border-radius:14px;padding:20px;}
.member-benefit-card.biz{border-color:#f59e0b;background:#fffbeb;}
.mb-icon{font-size:36px;margin-bottom:8px;}
.member-benefit-card h3{font-size:17px;font-weight:800;margin:0 0 10px;}
.member-benefit-card ul{padding-left:18px;margin:0;}
.member-benefit-card li{font-size:13px;color:#334155;line-height:1.7;margin-bottom:4px;}
.mb-note{font-size:12px;color:#92400e;margin-top:10px;font-weight:600;}
.faq-item{border:1px solid #e2e8f0;border-radius:12px;margin-bottom:8px;overflow:hidden;}
.faq-item summary{padding:14px 18px;font-size:14px;font-weight:700;color:#1e293b;cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;}
.faq-item summary::after{content:'+';font-size:18px;color:#94a3b8;transition:transform .15s;}
.faq-item[open] summary::after{transform:rotate(45deg);}
.faq-item summary::-webkit-details-marker{display:none;}
.faq-item p{padding:0 18px 14px;font-size:14px;color:#475569;line-height:1.7;margin:0;}
.guide-cs-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.cs-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;text-align:center;}
.cs-card h3{font-size:14px;font-weight:700;color:#64748b;margin:0 0 8px;}
.cs-phone{font-size:24px;font-weight:900;color:#2563eb;margin:0 0 6px;}
.cs-email{font-size:18px;font-weight:800;color:#1e293b;margin:0 0 6px;}
.cs-kakao{font-size:18px;font-weight:800;color:#f7941d;margin:0 0 6px;}
.cs-hours{font-size:12px;color:#94a3b8;line-height:1.5;margin:0;}
@media(max-width:768px){
  .guide-hero{padding:40px 16px 28px;}
  .guide-hero h1{font-size:24px;}
  .guide-container{padding:20px 12px 60px;}
  .guide-toc{position:relative;top:0;gap:6px;}
  .guide-toc a{font-size:12px;padding:5px 10px;}
  .guide-sec h2{font-size:18px;}
  .guide-card{padding:16px;}
  .member-benefit-grid{grid-template-columns:1fr;}
  .guide-cs-grid{grid-template-columns:1fr;}
  .tire-spec-visual{font-size:28px;}
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
