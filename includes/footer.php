<?php
// 모바일 하단 탭바 활성 페이지 판별
$tabbarCurrentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
</main>

<!-- ===== [모바일 앱] 하단 탭바 (앱 전용 화면 네비게이션) ===== -->
<nav class="tt-app-tabbar" id="ttAppTabbar">
  <a href="<?= BASE_URL ?>/index.php" class="tt-tab-item <?= $tabbarCurrentScript === 'index.php' ? 'active' : '' ?>">
    <span class="tt-tab-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
    </span>
    <span class="tt-tab-label">홈</span>
  </a>
  <a href="<?= BASE_URL ?>/product-list.php?cat=1" class="tt-tab-item <?= $tabbarCurrentScript === 'product-list.php' ? 'active' : '' ?>">
    <span class="tt-tab-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.5" cy="10.5" r="7"/><line x1="21" y1="21" x2="15.8" y2="15.8"/></svg>
    </span>
    <span class="tt-tab-label">검색</span>
  </a>
  <a href="<?= BASE_URL ?>/mypage.php" class="tt-tab-item <?= $tabbarCurrentScript === 'mypage.php' ? 'active' : '' ?>">
    <span class="tt-tab-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4.5 21c1.5-4 4-6 7.5-6s6 2 7.5 6"/></svg>
    </span>
    <span class="tt-tab-label">마이</span>
    <?php
      // 예약 알림 배지 (로그인 사용자의 pending/confirmed 예약 수)
      try {
          if (Auth::isLoggedIn()) {
              $tbStmt = Database::connection()->prepare("SELECT COUNT(*) FROM tt_reservations WHERE user_id = :uid AND status IN ('pending','confirmed')");
              $tbStmt->execute(['uid' => Auth::currentUserId()]);
              $resvBadge = (int)$tbStmt->fetchColumn();
              if ($resvBadge > 0) echo '<span class="tt-tab-badge">' . $resvBadge . '</span>';
          }
      } catch (Throwable $e) {}
    ?>
  </a>
  <a href="<?= BASE_URL ?>/cart.php" class="tt-tab-item <?= $tabbarCurrentScript === 'cart.php' ? 'active' : '' ?>">
    <span class="tt-tab-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3h2.6l2.4 12.2a1.6 1.6 0 0 0 1.6 1.3h7.9a1.6 1.6 0 0 0 1.6-1.3L20.8 7H6.1"/></svg>
    </span>
    <span class="tt-tab-label">장바구니</span>
    <span class="tt-tab-badge cart" id="ttTabCartBadge" style="<?= ($cartCount ?? 0) > 0 ? '' : 'display:none' ?>"><?= $cartCount ?? 0 ?></span>
  </a>
  <a href="<?= BASE_URL ?>/cs-center.php" class="tt-tab-item <?= $tabbarCurrentScript === 'cs-center.php' ? 'active' : '' ?>">
    <span class="tt-tab-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/></svg>
    </span>
    <span class="tt-tab-label">고객센터</span>
  </a>
</nav>

<!-- ===== [AI 챗봇] 플로팅 버튼 + 채팅 패널 (독립 fixed 배치) ===== -->
<button type="button" class="cbt-fab" id="cbtFab" aria-label="AI 챗봇 열기">
  <span class="cbt-fab-icon" id="cbtFabIcon">💬</span>
  <span class="cbt-fab-badge" id="cbtFabBadge">AI</span>
</button>

<div class="cbt-panel" id="cbtPanel" aria-hidden="true">
  <div class="cbt-head">
    <div class="cbt-head-profile">
      <span class="cbt-head-avatar">🤖</span>
      <div class="cbt-head-info">
        <strong>타이어몰 AI 상담봇</strong>
        <span class="cbt-head-status"><i></i>지금 문의 가능</span>
      </div>
    </div>
    <button type="button" class="cbt-head-close" id="cbtClose" aria-label="닫기">&times;</button>
  </div>

  <div class="cbt-body" id="cbtBody">
    <div class="cbt-msg bot">
      <div class="cbt-bubble">안녕하세요! 타이어몰 AI 상담봇입니다 🛞<br>궁금한 점을 입력해주세요. 아래 추천 질문을 눌러도 됩니다.</div>
    </div>
    <div class="cbt-suggest-row" id="cbtSuggestRow"></div>
  </div>

  <div class="cbt-input-row">
    <input type="text" id="cbtInput" placeholder="궁금한 점을 입력해주세요" maxlength="500" autocomplete="off">
    <button type="button" id="cbtSend" aria-label="보내기">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
    </button>
  </div>
  <p class="cbt-foot-note">키워드 기반 자동응답 · 고객센터(1588-0000)</p>
</div>

<footer class="tt-footer">
  <div class="tt-footer-inner">
    <nav class="tt-footer-links">
      <a href="<?= BASE_URL ?>/terms.php">이용약관</a>
      <span class="tt-footer-divider">|</span>
      <a href="<?= BASE_URL ?>/privacy.php" class="tt-footer-privacy">개인정보처리방침</a>
      <span class="tt-footer-divider">|</span>
      <a href="<?= BASE_URL ?>/guide.php">이용안내</a>
    </nav>
    <p><strong><?= h(SITE_NAME) ?></strong> | 사업자등록번호: 000-00-00000 | 통신판매업신고: 0000-서울-0000</p>
    <p>고객센터: 1588-0000 (평일 09:00~18:00)</p>
  </div>
</footer>

<script>
/* 장바구니 뱃지 동기화 (헤더 뱃지와 하단 탭바 뱃지) */
window.ttSetCartCount = function (count) {
  var badge = document.getElementById('ttCartBadge');
  if (badge) {
    if (count > 0) { badge.textContent = count; badge.style.display = ''; }
    else { badge.style.display = 'none'; }
  }
  var tabBadge = document.getElementById('ttTabCartBadge');
  if (tabBadge) {
    if (count > 0) { tabBadge.textContent = count; tabBadge.style.display = ''; }
    else { tabBadge.style.display = 'none'; }
  }
};

/* 모바일에서 하단 탭바가 푸터를 가리지 않도록 여백 추가 */
(function () {
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
  if (isStandalone) { document.body.classList.add('pwa-standalone'); }
})();

/* ============================================================
   [AI 챗봇] 플로팅 위젯 동작
   ============================================================ */
(function(){
  var fab = document.getElementById('cbtFab');
  var panel = document.getElementById('cbtPanel');
  var closeBtn = document.getElementById('cbtClose');
  var body = document.getElementById('cbtBody');
  var input = document.getElementById('cbtInput');
  var sendBtn = document.getElementById('cbtSend');
  var suggestRow = document.getElementById('cbtSuggestRow');
  var loadedSuggestions = false;
  var busy = false;
  if (!fab || !panel) return;

  // 상품 상세 하단 구매바가 있는 페이지 — FAB 위치 올림용 body 클래스
  if (document.querySelector('.pdh-action-row')) {
    document.body.classList.add('cbt-buybar-page');
  }

  function open(){
    panel.classList.add('open');
    fab.classList.add('active');
    fab.classList.add('cbt-hidden'); // 모바일 바텀시트가 FAB을 대체
    fab.querySelector('.cbt-fab-icon').textContent = '✕';
    panel.setAttribute('aria-hidden','false');
    if (!loadedSuggestions) { loadSuggestions(); loadedSuggestions = true; }
    setTimeout(function(){ if (input && window.innerWidth > 768) input.focus(); }, 250);
  }
  function close(){
    panel.classList.remove('open');
    fab.classList.remove('active');
    fab.classList.remove('cbt-hidden');
    fab.querySelector('.cbt-fab-icon').textContent = '💬';
    panel.setAttribute('aria-hidden','true');
  }

  fab.addEventListener('click', function(){
    if (panel.classList.contains('open')) close(); else open();
  });
  if (closeBtn) closeBtn.addEventListener('click', close);

  function addMsg(role, text){
    var wrap = document.createElement('div');
    wrap.className = 'cbt-msg ' + role;
    var bubble = document.createElement('div');
    bubble.className = 'cbt-bubble';
    bubble.innerText = text; // XSS 방지 — innerHTML 금지
    wrap.appendChild(bubble);
    body.appendChild(wrap);
    body.scrollTop = body.scrollHeight;
  }

  function addSuggestions(list){
    if (!suggestRow || !list || !list.length) return;
    suggestRow.innerHTML = '';
    list.forEach(function(q){
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'cbt-suggest-chip';
      chip.textContent = q;
      chip.addEventListener('click', function(){ ask(q); });
      suggestRow.appendChild(chip);
    });
  }

  function loadSuggestions(){
    // 첫 추천 질문 로드 — 어드민에서 등록한 대표 질문
    fetch('<?= BASE_URL ?>/ajax-chatbot-suggestions.php', { method: 'POST' })
      .then(function(r){ return r.json(); })
      .then(function(data){ if (data.ok) addSuggestions(data.questions); })
      .catch(function(){});
  }

  function ask(msg){
    if (busy) return;
    busy = true;
    suggestRow.innerHTML = '';
    addMsg('user', msg);
    // "입력 중" 표시
    var typing = document.createElement('div');
    typing.className = 'cbt-msg bot cbt-typing';
    typing.innerHTML = '<div class="cbt-bubble"><span class="cbt-dot"></span><span class="cbt-dot"></span><span class="cbt-dot"></span></div>';
    body.appendChild(typing);
    body.scrollTop = body.scrollHeight;

    var form = new URLSearchParams();
    form.set('message', msg);

    fetch('<?= BASE_URL ?>/ajax-chatbot.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form.toString()
    }).then(function(r){ return r.json(); }).then(function(data){
      typing.remove();
      if (data.ok) {
        addMsg('bot', data.answer);
        addSuggestions(data.suggestions || []);
      } else {
        addMsg('bot', data.message || '오류가 발생했습니다. 잠시 후 다시 시도해주세요.');
      }
      busy = false;
    }).catch(function(){
      typing.remove();
      addMsg('bot', '네트워크 오류가 발생했습니다. 잠시 후 다시 시도해주세요.');
      busy = false;
    });
  }

  function submit(){
    var msg = (input.value || '').trim();
    if (!msg) return;
    input.value = '';
    ask(msg);
  }
  if (sendBtn) sendBtn.addEventListener('click', submit);
  if (input) input.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
  });
})();
</script>
</body>
</html>
