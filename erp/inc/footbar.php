<?php
// inc/footbar.php — 하단 고정 바 공용 (신규 F2 / 조회 / 인쇄 / Excel ▲ / 선택 삭제)
function footbar_render(array $o): void
{
    $newUrl    = (string)($o['new_url'] ?? '');
    $excelUrl  = (string)($o['excel_url'] ?? '');
    $delAction = (string)($o['delete_action'] ?? '');
    $delHidden = is_array($o['delete_hidden'] ?? null) ? $o['delete_hidden'] : [];
    ?>
<style>
.fb-spacer{height:60px}
.fb{position:fixed;left:0;right:0;bottom:0;z-index:60;display:flex;align-items:center;gap:6px;
    padding:8px 16px;background:#1a2233;border-top:1px solid #2b364b}
.fb [hidden]{display:none!important}
.fb-btn{display:inline-flex;align-items:center;gap:4px;height:30px;padding:0 12px;
    border:1px solid #3a4660;border-radius:6px;background:#222c40;color:#e5e7eb;
    font:500 13px/1 inherit;cursor:pointer;text-decoration:none;white-space:nowrap}
.fb-btn:hover{background:#2c3852;color:#fff}
.fb-btn u{font-size:11px;opacity:.85}
.fb-danger{color:#fca5a5;border-color:#7f3a3a}
.fb-dd{position:relative}
.fb-menu{display:none;position:absolute;left:0;bottom:calc(100% + 6px);min-width:200px;padding:6px;
    background:#222c40;border:1px solid #3a4660;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.35)}
.fb-dd.open .fb-menu{display:block}
.fb-menu a,.fb-menu .fb-dis{display:block;padding:8px 10px;border-radius:6px;color:#e5e7eb;
    font-size:13px;text-decoration:none;white-space:nowrap}
.fb-menu a:hover{background:#2c3852}
.fb-menu .fb-dis{color:#7b8499;cursor:not-allowed}
.fb-print-title{display:none}
@media print{
  .fb,.fb-spacer,.no-print,.gnb,.snb,.fb-chk{display:none!important}
  .fb-print-title{display:block!important}
}
</style>

<div class="fb-spacer"></div>
<div class="fb" id="fb">
  <?php if ($newUrl !== ''): ?>
    <a class="fb-btn" href="<?= h($newUrl) ?>" id="fbNew">신규 <u>F2</u></a>
  <?php endif; ?>
  <button type="button" class="fb-btn" id="fbSearch">조회</button>
  <button type="button" class="fb-btn" id="fbPrint">인쇄</button>
  <?php if ($excelUrl !== ''): ?>
    <div class="fb-dd" id="fbExcelDd">
      <button type="button" class="fb-btn" id="fbExcelBtn" aria-haspopup="true" aria-expanded="false">Excel ▲</button>
      <div class="fb-menu" role="menu">
        <a href="<?= h($excelUrl) ?>" role="menuitem">현재 조건 다운로드 (.xlsx)</a>
        <span class="fb-dis">일괄 업로드 (준비중)</span>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($delAction !== ''): ?>
    <button type="button" class="fb-btn fb-danger" id="fbDelete" hidden>선택 삭제 (0)</button>
    <form id="fbDelForm" method="post" action="<?= h($delAction) ?>" hidden>
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="confirm">
      <?php foreach ($delHidden as $k => $v): ?>
        <input type="hidden" name="<?= h((string)$k) ?>" value="<?= h((string)$v) ?>">
      <?php endforeach; ?>
    </form>
  <?php endif; ?>
</div>

<script>
(function () {
  var dd = document.getElementById('fbExcelDd');
  var ddBtn = document.getElementById('fbExcelBtn');
  function closeDd() {
    if (dd) { dd.classList.remove('open'); ddBtn.setAttribute('aria-expanded', 'false'); }
  }
  if (ddBtn) {
    ddBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var o = dd.classList.toggle('open');
      ddBtn.setAttribute('aria-expanded', o ? 'true' : 'false');
    });
  }
  document.addEventListener('click', function (e) { if (dd && !dd.contains(e.target)) closeDd(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDd();
    if (e.key === 'F2') {
      var n = document.getElementById('fbNew');
      if (n) { e.preventDefault(); location.href = n.href; }
    }
  });

  document.getElementById('fbPrint').addEventListener('click', function () { closeDd(); window.print(); });
  document.getElementById('fbSearch').addEventListener('click', function () {
    var f = document.querySelector('form[data-fb-search]');
    if (!f) { window.scrollTo(0, 0); return; }
    if (f.requestSubmit) f.requestSubmit(); else f.submit();
  });

  // 선택 삭제
  var delBtn = document.getElementById('fbDelete');
  var delForm = document.getElementById('fbDelForm');
  function rows() { return Array.prototype.slice.call(document.querySelectorAll('.fb-row-chk')); }
  function refresh() {
    if (!delBtn) return;
    var all = rows();
    var n = all.filter(function (c) { return c.checked; }).length;
    delBtn.textContent = '선택 삭제 (' + n + ')';
    delBtn.hidden = n === 0;
    var head = document.getElementById('fbAll');
    if (head) {
      head.checked = n > 0 && n === all.length;
      head.indeterminate = n > 0 && n < all.length;
    }
  }
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'fbAll') {
      rows().forEach(function (c) { c.checked = t.checked; });
      refresh();
    } else if (t.classList && t.classList.contains('fb-row-chk')) {
      refresh();
    }
  });
  if (delBtn) {
    delBtn.addEventListener('click', function () {
      var picked = rows().filter(function (c) { return c.checked; });
      if (!picked.length) return;
      delForm.querySelectorAll('input[name="ids[]"]').forEach(function (x) { x.remove(); });
      picked.forEach(function (c) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'ids[]'; i.value = c.value;
        delForm.appendChild(i);
      });
      delForm.submit();
    });
  }
  window.addEventListener('pageshow', refresh); // 뒤로가기 시 체크 상태 복원 대응
  refresh();
})();
</script>
<?php
}
