<?php declare(strict_types=1); ?>
</main>
<footer class="tablet-footer">
  <span><?= h(defined('SITE_NAME') ? SITE_NAME : 'TIRETOP') ?> 태블릿 작업일지 · 매장 실시간 기준</span>
  <span class="tablet-footer-time" data-clock><?= date('Y-m-d H:i') ?></span>
</footer>
<script>
/* 시계 */
(function(){
  var el = document.querySelector('[data-clock]');
  function tick(){
    if(!el) return;
    var d = new Date();
    function p(n){ return (n<10?'0':'')+n; }
    el.textContent = d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());
  }
  tick(); setInterval(tick, 15000);
})();

/* 실시간 폴링 — 30초마다 서버 상태 확인, 변경 감지 시 자동 새로고침 */
(function(){
  var last = document.body.getAttribute('data-state-hash');
  if(!last) return;
  function poll(){
    fetch('<?= BASE_URL ?>/tablet/api-state.php?date=' + encodeURIComponent(document.body.getAttribute('data-date') || ''), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(j && j.state_hash && j.state_hash !== last){ location.reload(); }
      })
      .catch(function(){});
  }
  setInterval(poll, 30000);
})();
</script>
</body>
</html>
