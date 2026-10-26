<?php
// 목록 화면 공용 하단 바: 신규(F2) / 조회 / 인쇄 ▲(미리보기·인쇄·PDF) / Excel ▲
// 화면에 표시된 표(table)를 읽어서 인쇄·미리보기를 만듭니다. inc/functions.php(h) 가 먼저 로드되어 있어야 합니다.
//
// 옵션
//   title      : 인쇄 제목
//   new_url    : 신규 버튼 이동 주소 (빈 문자열이면 숨김, 쓰기 권한 없으면 숨김)
//   excel_url  : 서버 다운로드 주소 (있으면 Excel 메뉴가 이것을 사용, 없으면 화면 표를 CSV로 저장)
//   excel_page : 일괄 업로드·다운로드 화면 주소 (빈 문자열이면 링크 숨김)

function listbar_render(array $o = []): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $title     = (string)($o['title'] ?? '목록');
    $newUrl    = (string)($o['new_url'] ?? '');
    $excelUrl  = (string)($o['excel_url'] ?? '');
    $excelPage = (string)($o['excel_page'] ?? '');
    $canNew    = $newUrl !== '' && (!function_exists('can_write') || can_write());
    ?>
<style>
.lb-bar{position:fixed;left:0;right:0;bottom:0;z-index:50;display:flex;gap:4px;align-items:center;padding:5px 6px;background:#fff;border-top:1px solid #d1d5db}
.lb-spacer{height:44px}
.lb-btn{height:26px;padding:0 10px;border:1px solid #9ca3af;background:#fff;color:#111827;font:inherit;font-size:12px;line-height:1;cursor:pointer;border-radius:3px;text-decoration:none;display:inline-flex;align-items:center;white-space:nowrap}
.lb-btn:hover{background:#f3f4f6;text-decoration:none}
.lb-btn-primary{background:#1f2937;border-color:#1f2937;color:#fff}
.lb-btn-primary:hover{background:#374151;color:#fff}
.lb-btn[hidden]{display:none}
.lb-dd{position:relative}
.lb-menu{display:none;position:absolute;left:0;bottom:100%;margin-bottom:4px;min-width:220px;background:#fff;border:1px solid #9ca3af;border-radius:3px;box-shadow:0 -2px 8px rgba(0,0,0,.15)}
.lb-dd.open .lb-menu{display:block}
.lb-menu a,.lb-menu button{display:block;width:100%;box-sizing:border-box;text-align:left;padding:8px 12px;border:0;background:none;color:#111827;font:inherit;font-size:12px;cursor:pointer;text-decoration:none}
.lb-menu a:hover,.lb-menu button:hover{background:#f3f4f6;text-decoration:none}
.lb-menu small{display:block;color:#6b7280;font-size:11px}
#lbPreview[hidden]{display:none!important}
.lb-pv-tools{display:flex;gap:8px;align-items:center;margin:16px 0 8px;flex-wrap:wrap}
.lb-sheet{background:#fff;border:1px solid #cbd5e1;padding:16px;margin-bottom:12px}
.lb-meta{color:#475569;font-size:13px}
@media print{
  .lb-bar,.lb-spacer,.lb-pv-tools{display:none!important}
  body.lb-printing > *:not(#lbPreview){display:none!important}
  .lb-sheet{border:0;padding:0}
}
</style>

<section id="lbPreview" data-title="<?= h($title) ?>" data-printed="<?= h(date('Y-m-d H:i')) ?>" data-new="<?= h($canNew ? $newUrl : '') ?>" hidden>
  <div class="lb-pv-tools">
    <strong>인쇄 미리보기</strong>
    <button type="button" class="btn btn-sm" data-lb-act="print">인쇄</button>
    <button type="button" class="btn btn-sm" data-lb-act="close">닫기</button>
    <span class="lb-meta">PDF로 저장하려면 인쇄창에서 대상(프린터)을 "PDF로 저장"으로 선택하세요.</span>
  </div>
  <div class="lb-sheet" id="lbSheet"></div>
</section>

<div class="lb-spacer"></div>
<div class="lb-bar no-print">
  <?php if ($canNew): ?>
  <a class="lb-btn lb-btn-primary" href="<?= h($newUrl) ?>">신규(F2)</a>
  <?php endif; ?>
  <button type="button" class="lb-btn" id="lbQuery" data-lb-act="query">조회</button>
  <div class="lb-dd">
    <button type="button" class="lb-btn" data-lb-dd>인쇄 ▲</button>
    <div class="lb-menu">
      <button type="button" data-lb-act="preview">미리보기</button>
      <button type="button" data-lb-act="print">인쇄</button>
      <button type="button" data-lb-act="print">PDF로 저장<small>인쇄창에서 "PDF로 저장" 선택</small></button>
    </div>
  </div>
  <div class="lb-dd">
    <button type="button" class="lb-btn" data-lb-dd>Excel ▲</button>
    <div class="lb-menu">
      <?php if ($excelUrl !== ''): ?>
      <a href="<?= h($excelUrl) ?>">현재 조건 다운로드<small>CSV · 조건에 맞는 전체</small></a>
      <?php else: ?>
      <button type="button" data-lb-act="csv">현재 화면 다운로드<small>CSV · 화면에 보이는 행만</small></button>
      <?php endif; ?>
      <?php if ($excelPage !== ''): ?>
      <a href="<?= h($excelPage) ?>">일괄 업로드 · 전체 다운로드<small>Excel 화면으로 이동</small></a>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var pv = document.getElementById('lbPreview');
  if (!pv) { return; }
  var title = pv.getAttribute('data-title') || '목록';
  var printed = pv.getAttribute('data-printed') || '';
  var newUrl = pv.getAttribute('data-new') || '';
  var EXCLUDE = /^(수정|삭제|관리|작업|선택|보기|수불부|상세)$/;
  var printState = null;

  function clean(s) { return String(s).replace(/\s+/g, ' ').trim(); }

  // 칸 안에 입력칸이 있으면 화면에 보이는 "값"을 읽는다 (textContent 는 입력칸이 비어 보임)
  function cellText(td) {
    var f = td.querySelector('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea');
    if (!f) { return clean(td.textContent); }
    if (f.type === 'checkbox' || f.type === 'radio') { return f.checked ? 'Y' : 'N'; }
    if (f.tagName === 'SELECT') {
      var opt = f.options[f.selectedIndex];
      return clean(opt ? opt.text : '');
    }
    return clean(f.value);
  }

  function pickTable() {
    var main = document.querySelector('main') || document.body, best = null, bestN = -1;
    Array.prototype.forEach.call(main.querySelectorAll('table'), function (t) {
      if (pv.contains(t)) { return; }
      var n = t.tBodies.length ? t.tBodies[0].rows.length : t.rows.length;
      if (n > bestN) { best = t; bestN = n; }
    });
    return best;
  }

  function collect() {
    var t = pickTable();
    if (!t || !t.rows.length) { return null; }
    var headRow = (t.tHead && t.tHead.rows.length) ? t.tHead.rows[t.tHead.rows.length - 1] : t.rows[0];
    var keep = [], heads = [], nums = [];
    Array.prototype.forEach.call(headRow.cells, function (th, i) {
      var txt = clean(th.textContent);
      if (th.classList.contains('no-print') || txt === '' || EXCLUDE.test(txt)) { return; }
      keep.push(i); heads.push(txt); nums.push(th.classList.contains('num'));
    });
    if (!keep.length) { return null; }
    var body = t.tBodies.length ? t.tBodies[0] : t, rows = [];
    Array.prototype.forEach.call(body.rows, function (tr) {
      if (tr === headRow) { return; }
      if (tr.cells.length < headRow.cells.length) { return; } // colspan 안내줄 제외
      rows.push(keep.map(function (i) { return cellText(tr.cells[i]); }));
    });
    return { heads: heads, nums: nums, rows: rows };
  }

  function buildPreview(d) {
    var box = document.getElementById('lbSheet');
    box.textContent = '';
    var h2 = document.createElement('h2'); h2.textContent = title; box.appendChild(h2);
    var meta = document.createElement('p'); meta.className = 'lb-meta';
    meta.textContent = '출력일시: ' + printed + ' · ' + d.rows.length + '건';
    box.appendChild(meta);
    var tbl = document.createElement('table'); tbl.className = 'table';
    var thead = tbl.createTHead().insertRow();
    d.heads.forEach(function (h, i) {
      var th = document.createElement('th'); th.textContent = h;
      if (d.nums[i]) { th.className = 'num'; }
      thead.appendChild(th);
    });
    var tb = tbl.createTBody();
    d.rows.forEach(function (r) {
      var tr = tb.insertRow();
      r.forEach(function (c, i) {
        var td = tr.insertCell(); td.textContent = c;
        if (d.nums[i]) { td.className = 'num'; }
      });
    });
    box.appendChild(tbl);
  }

  function noTable() { alert('이 화면에서 표를 찾지 못했습니다.'); }

  function showPreview() {
    var d = collect(); if (!d) { noTable(); return; }
    buildPreview(d);
    pv.hidden = false;
    pv.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function restore() {
    document.body.classList.remove('lb-printing');
    if (!printState) { return; }
    printState.parent.insertBefore(pv, printState.next);
    pv.hidden = printState.hidden;
    printState = null;
  }

  function doPrint() {
    var d = collect(); if (!d) { noTable(); return; }
    buildPreview(d);
    printState = { parent: pv.parentNode, next: pv.nextSibling, hidden: pv.hidden };
    pv.hidden = false;
    document.body.appendChild(pv);            // 레이아웃 구조와 무관하게 이것만 인쇄
    document.body.classList.add('lb-printing');
    setTimeout(function () { window.print(); }, 50);
    setTimeout(restore, 5000);                // afterprint 가 안 오는 브라우저 대비
  }
  window.addEventListener('afterprint', restore);

  function csvCell(s) {
    s = String(s);
    if (s === '-') { s = ''; }
    if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) { s = s.replace(/,/g, ''); }
    else if (!/^-?\d+(\.\d+)?$/.test(s) && /^[=+\-@]/.test(s)) { s = "'" + s; } // 수식 주입 방지
    return '"' + s.replace(/"/g, '""') + '"';
  }

  function downloadCsv() {
    var d = collect(); if (!d) { noTable(); return; }
    var lines = [d.heads.map(csvCell).join(',')];
    d.rows.forEach(function (r) { lines.push(r.map(csvCell).join(',')); });
    var blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var url = URL.createObjectURL(blob), a = document.createElement('a'), n = new Date(), p = function (x) { return (x < 10 ? '0' : '') + x; };
    a.href = url;
    a.download = title.replace(/[\\\/:*?"<>|\s]+/g, '_') + '_' + n.getFullYear() + p(n.getMonth() + 1) + p(n.getDate()) + '_' + p(n.getHours()) + p(n.getMinutes()) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function findGetForm() {
    return Array.prototype.filter.call(document.querySelectorAll('main form'), function (f) {
      return f.method === 'get' && !pv.contains(f);
    })[0] || null;
  }
  if (!findGetForm()) { document.getElementById('lbQuery').hidden = true; }

  function closeAll() {
    document.querySelectorAll('.lb-dd.open').forEach(function (d) { d.classList.remove('open'); });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-lb-dd]');
    if (t) {
      var dd = t.closest('.lb-dd'), was = dd.classList.contains('open');
      closeAll(); if (!was) { dd.classList.add('open'); }
      e.preventDefault(); return;
    }
    var a = e.target.closest('[data-lb-act]');
    if (a) {
      closeAll();
      var k = a.getAttribute('data-lb-act');
      if (k === 'preview') { showPreview(); }
      else if (k === 'print') { doPrint(); }
      else if (k === 'csv') { downloadCsv(); }
      else if (k === 'close') { pv.hidden = true; window.scrollTo({ top: 0, behavior: 'smooth' }); }
      else if (k === 'query') { var f = findGetForm(); if (f) { if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); } } }
      e.preventDefault(); return;
    }
    if (!e.target.closest('.lb-dd')) { closeAll(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeAll(); }
    if (e.key === 'F2' && newUrl) { e.preventDefault(); window.location.href = newUrl; }
  });
})();
</script>
    <?php
}
