<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/board-lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$hasSession = board_has_session();

/* 최초 1회: 로그인한 작업자/관리자가 열면 TV 토큰 자동 생성 */
if ($hasSession && board_token() === '') {
    set_setting('board_token', bin2hex(random_bytes(32)));
}

if (!board_authorized(true)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="robots" content="noindex"><title>접근 불가</title></head>'
       . '<body style="font-family:sans-serif;text-align:center;padding:80px 20px;"><h2>접근 권한이 없습니다</h2><p>현황판 전용 링크로 접속해 주세요.</p></body></html>';
    exit;
}

/* 작업자/관리자가 ?link=1 로 열면 TV에 등록할 링크를 보여준다 */
if ($hasSession && ($_GET['link'] ?? '') === '1') {
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="robots" content="noindex"><title>TV 링크</title></head>'
       . '<body style="font-family:sans-serif;padding:40px;"><h3>현황판 TV 전용 링크</h3>'
       . '<p style="color:#b91c1c;">이 링크를 아는 사람은 로그인 없이 현황판을 볼 수 있습니다. 외부에 공유하지 마세요.</p>'
       . '<code style="word-break:break-all;">' . h(BASE_URL . '/tablet/board.php?key=' . board_token()) . '</code>'
       . '<p>주소가 /로 시작하면 앞에 도메인을 붙여 TV 브라우저에 등록하세요.</p></body></html>';
    exit;
}

$key = $hasSession ? '' : (string)($_GET['key'] ?? '');
$jf  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>입출고 현황판</title>
<style>
:root{--bg:#0b1220;--panel:#111a2e;--card:#1a2744;--text:#e8eefc;--muted:#8fa0c4}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;background:var(--bg);color:var(--text);overflow:hidden;
  font-family:'Pretendard Variable',Pretendard,-apple-system,'Malgun Gothic',sans-serif}
body{display:flex;flex-direction:column}
.top{display:flex;align-items:center;justify-content:space-between;padding:1.4vh 2vw;flex:none}
.top h1{font-size:3.4vh;font-weight:800}
.top .meta{font-size:2.6vh;color:var(--muted);font-variant-numeric:tabular-nums}
.board{flex:1;min-height:0;display:grid;grid-template-columns:repeat(4,1fr);gap:1.2vw;padding:0 2vw 2vh}
.col{background:var(--panel);border-radius:1.2vh;display:flex;flex-direction:column;min-height:0;border-top:.8vh solid var(--c)}
.c-checkin{--c:#f59e0b}.c-working{--c:#3b82f6}.c-reserved{--c:#94a3b8}.c-done{--c:#22c55e}
.col-h{display:flex;justify-content:space-between;align-items:baseline;padding:1.6vh 1.2vw}
.col-h b{font-size:3.6vh;color:var(--c)}
.col-h span{font-size:4.4vh;font-weight:800}
.col-b{flex:1;min-height:0;overflow:hidden;padding:0 1vw 1vh;display:flex;flex-direction:column;gap:1vh}
.card{flex:none;background:var(--card);border-radius:1vh;padding:1.4vh 1vw;display:flex;justify-content:space-between;align-items:center}
.plate{font-size:4.4vh;font-weight:800;letter-spacing:.04em}
.time{font-size:2.4vh;color:var(--muted);font-variant-numeric:tabular-nums}
.more{flex:none;text-align:center;color:var(--muted);font-size:2.6vh;padding-top:.6vh}
@keyframes flash{from{background:#facc15;color:#000}to{background:var(--card)}}
.card.flash{animation:flash 2.5s ease-out}
#alert{display:none;position:fixed;left:0;right:0;bottom:0;background:#b91c1c;color:#fff;
  text-align:center;font-size:3vh;font-weight:800;padding:1.2vh}
</style>
</head>
<body>
<div class="top">
  <h1>🔧 <?= h(defined('SITE_NAME') ? SITE_NAME : 'TIRETOP') ?> 입출고 현황</h1>
  <div class="meta" id="clock"></div>
</div>
<div class="board" id="board"></div>
<div id="alert"></div>

<script>
(function () {
  'use strict';
  var KEY = <?= json_encode($key, $jf) ?>;
  var API = <?= json_encode(BASE_URL . '/tablet/board-api.php', $jf) ?>;
  var COLS = [
    {id: 'checkin',  label: '입고중'},
    {id: 'working',  label: '작업중'},
    {id: 'reserved', label: '예약차'},
    {id: 'done',     label: '작업완료'}
  ];
  var POLL_MS = 3000, STALE_MS = 15000;

  var board = document.getElementById('board');
  var alertEl = document.getElementById('alert');
  var clockEl = document.getElementById('clock');
  var bodies = {}, counts = {};

  COLS.forEach(function (c) {
    var col = document.createElement('section');
    col.className = 'col c-' + c.id;
    var h = document.createElement('div'); h.className = 'col-h';
    var t = document.createElement('b'); t.textContent = c.label;
    var n = document.createElement('span'); n.textContent = '0';
    h.appendChild(t); h.appendChild(n);
    var b = document.createElement('div'); b.className = 'col-b';
    col.appendChild(h); col.appendChild(b);
    board.appendChild(col);
    bodies[c.id] = b; counts[c.id] = n;
  });

  var prev = {}, lastHash = null, lastItems = null, firstLoad = true, pageDate = null;
  var lastOk = Date.now(), failCount = 0, authFailed = false;

  /* 컬럼 높이에 들어가는 만큼만 남기고 나머지는 "외 N대"로 — 잘려서 조용히 사라지는 것 방지 */
  function fitColumn(body, total) {
    if (!body.clientHeight) return;
    var cards = body.querySelectorAll('.card');
    var shown = cards.length;
    function over() { return body.scrollHeight > body.clientHeight + 1; }
    while (shown > 0 && over()) { body.removeChild(cards[--shown]); }
    if (shown < total) {
      var more = document.createElement('div');
      more.className = 'more';
      more.textContent = '외 ' + (total - shown) + '대';
      body.appendChild(more);
      while (shown > 1 && over()) {
        body.removeChild(cards[--shown]);
        more.textContent = '외 ' + (total - shown) + '대';
      }
    }
  }

  function render(items) {
    lastItems = items;
    var groups = {checkin: [], working: [], reserved: [], done: []};
    items.forEach(function (it) { (groups[it.state] || groups.reserved).push(it); });
    groups.done.reverse(); // 최근 완료가 위로

    COLS.forEach(function (c) {
      var list = groups[c.id], body = bodies[c.id];
      body.textContent = '';
      counts[c.id].textContent = String(list.length);
      list.forEach(function (it) {
        var card = document.createElement('div');
        card.className = 'card' + ((!firstLoad && prev[it.key] !== it.state) ? ' flash' : '');
        var p = document.createElement('span'); p.className = 'plate'; p.textContent = it.plate;
        var t = document.createElement('span'); t.className = 'time'; t.textContent = it.time;
        card.appendChild(p); card.appendChild(t);
        body.appendChild(card);
      });
      fitColumn(body, list.length);
    });
    prev = {};
    items.forEach(function (it) { prev[it.key] = it.state; });
    firstLoad = false;
  }

  window.addEventListener('resize', function () { if (lastItems) render(lastItems); });

  function poll() {
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 8000);
    var opts = {cache: 'no-store', credentials: 'same-origin', headers: {}};
    if (KEY) opts.headers['X-Board-Key'] = KEY;
    if (ctrl) opts.signal = ctrl.signal;

    fetch(API, opts)
      .then(function (r) {
        if (r.status === 401) { authFailed = true; throw new Error('auth'); }
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      })
      .then(function (j) {
        if (!j || !j.ok) throw new Error('bad payload');
        if (pageDate === null) pageDate = j.date;
        else if (j.date !== pageDate) { location.reload(); return; } // 자정 넘으면 새로고침
        if (j.hash !== lastHash) { render(j.items); lastHash = j.hash; }
        lastOk = Date.now(); failCount = 0; authFailed = false;
      })
      .catch(function () { failCount++; })
      .then(function () {
        clearTimeout(timer);
        setTimeout(poll, Math.min(POLL_MS * (1 + failCount), 15000));
      });
  }

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function hms(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }

  setInterval(function () {
    var now = new Date();
    clockEl.textContent = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + ' ' + hms(now);
    if (authFailed) {
      alertEl.textContent = '⚠ 인증 오류 — 현황판 링크를 확인하세요';
      alertEl.style.display = 'block';
    } else if (Date.now() - lastOk > STALE_MS) {
      alertEl.textContent = '⚠ 서버 연결 끊김 — 마지막 갱신 ' + hms(new Date(lastOk)) + ' (화면이 최신이 아닐 수 있음)';
      alertEl.style.display = 'block';
    } else {
      alertEl.style.display = 'none';
    }
  }, 1000);

  poll();
})();
</script>
</body>
</html>
