<?php
// inc/partner_bar.php — 거래처 화면 하단 고정 바 (신규 / 조회 / 인쇄 / Excel ▲)
// 클래스는 ptb- 접두어로 다른 바와 충돌하지 않게 했습니다. partner_lib.php 가 먼저 로드되어 있어야 합니다.

function ptbar_render(bool $canWrite, array $f): void
{
    $exportUrl   = pt_url('partners_excel.php', array_merge($f, ['mode' => 'export']));
    $templateUrl = 'partners_excel.php?mode=template';
    ?>
<style>
.ptb-spacer{height:60px}
.ptb{position:fixed;left:0;right:0;bottom:0;z-index:50;background:#1f2937;border-top:1px solid #374151;padding:8px 14px}
.ptb-in{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ptb-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 14px;border:1px solid #4b5563;border-radius:8px;background:#374151;color:#fff;font-size:14px;text-decoration:none;cursor:pointer}
.ptb-btn:hover{background:#4b5563}
.ptb-btn small{color:#cbd5e1;font-size:11px}
.ptb-dd{position:relative}
.ptb-menu{display:none;position:absolute;left:0;bottom:calc(100% + 6px);min-width:210px;background:#fff;border:1px solid #cfd6e0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.25);padding:6px}
.ptb-dd.open .ptb-menu{display:block}
.ptb-menu a,.ptb-menu button{display:block;width:100%;text-align:left;padding:9px 12px;border:0;background:none;color:#111827;font-size:14px;text-decoration:none;border-radius:6px;cursor:pointer}
.ptb-menu a:hover,.ptb-menu button:hover{background:#eef2f8}
.ptb-ov{position:fixed;inset:0;z-index:60;background:rgba(17,24,39,.55);display:flex;align-items:flex-end;justify-content:center}
.ptb-ov[hidden]{display:none}
.ptb-panel{width:min(640px,100%);background:#fff;border-radius:14px 14px 0 0;padding:20px 22px 24px;box-shadow:0 -8px 30px rgba(0,0,0,.3)}
.ptb-panel h3{margin:0 0 12px;font-size:17px}
.ptb-panel label{display:block;margin:10px 0 4px;font-size:13px;color:#4b5563}
.ptb-panel select,.ptb-panel input[type=file]{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cfd6e0;border-radius:8px;font-size:14px}
.ptb-note{font-size:12px;color:#6b7684;margin:10px 0 0;line-height:1.6}
.ptb-act{display:flex;gap:8px;margin-top:14px}
@media print{.ptb,.ptb-spacer,.ptb-ov,.no-print,.gnb,.snb{display:none!important}}
</style>

<div class="ptb-spacer no-print"></div>
<div class="ptb no-print" id="ptb">
  <div class="ptb-in">
    <?php if ($canWrite): ?>
      <a class="ptb-btn" id="ptbNew" href="partners.php#pform">신규 <small>F2</small></a>
    <?php endif; ?>
    <button type="button" class="ptb-btn" id="ptbFind">조회</button>
    <button type="button" class="ptb-btn" id="ptbPrint">인쇄</button>
    <div class="ptb-dd" id="ptbXl">
      <button type="button" class="ptb-btn" id="ptbXlBtn">Excel ▲</button>
      <div class="ptb-menu">
        <a href="<?= h($exportUrl) ?>">현재 조건 엑셀 다운로드</a>
        <a href="<?= h($templateUrl) ?>">양식 다운로드</a>
        <?php if ($canWrite): ?><button type="button" id="ptbUp">엑셀 일괄 업로드…</button><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($canWrite): ?>
<div class="ptb-ov no-print" id="ptbOv" hidden>
  <div class="ptb-panel">
    <h3>거래처 엑셀 일괄 업로드</h3>
    <form method="post" action="partners_excel.php" enctype="multipart/form-data" id="ptbForm">
      <?= csrf_field() ?>
      <label for="ptbFile">파일 (.xlsx 또는 .csv, 최대 5MB / 5,000행)</label>
      <input type="file" id="ptbFile" name="xfile" accept=".xlsx,.csv" required>
      <label for="ptbReg">이 파일의 지역 (예: 서울.xlsx → 서울)</label>
      <select id="ptbReg" name="force_region">
        <option value="">자동 (지역 열 → 거래처명 괄호 → 주소 순으로 추정)</option>
        <?php foreach (PT_REGIONS as $r): ?><option value="<?= h($r) ?>"><?= h($r) ?></option><?php endforeach; ?>
      </select>
      <label style="display:flex;gap:6px;align-items:center;color:#111827;margin-top:12px">
        <input type="checkbox" name="dry" value="1" checked> 검증만 하기 (저장 안 함)
      </label>
      <p class="ptb-note">E카운트에서 내려받은 파일을 그대로 올릴 수 있습니다. 1행 제목과 마지막 날짜 줄은 자동으로 건너뜁니다.
        빈 칸은 기존 값을 유지합니다. 처음에는 "검증만 하기"로 결과를 확인한 뒤 체크를 풀고 다시 올리세요.</p>
      <div class="ptb-act">
        <button class="btn" type="submit" id="ptbSubmit">업로드</button>
        <button class="btn btn-sm" type="button" id="ptbClose">닫기</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var xl = $('ptbXl'), ov = $('ptbOv');

  function focusName() {
    var f = $('pform');
    if (!f) return;
    var n = f.querySelector('[name=name]');
    if (n) setTimeout(function () { n.focus(); }, 250);
  }
  function openUp() { if (ov) { ov.hidden = false; xl.classList.remove('open'); } }
  function closeUp() { if (ov) ov.hidden = true; }

  if ($('ptbNew')) $('ptbNew').addEventListener('click', focusName);
  $('ptbFind').addEventListener('click', function () {
    var q = $('qbox');
    if (q) { q.scrollIntoView({behavior: 'smooth', block: 'center'}); setTimeout(function () { q.focus(); }, 250); }
  });
  $('ptbPrint').addEventListener('click', function () { window.print(); });
  $('ptbXlBtn').addEventListener('click', function (e) { e.stopPropagation(); xl.classList.toggle('open'); });
  if ($('ptbUp')) $('ptbUp').addEventListener('click', openUp);
  if ($('ptbClose')) $('ptbClose').addEventListener('click', closeUp);
  if (ov) ov.addEventListener('click', function (e) { if (e.target === ov) closeUp(); });
  if ($('ptbForm')) $('ptbForm').addEventListener('submit', function () { $('ptbSubmit').disabled = true; });
  document.addEventListener('click', function (e) { if (!xl.contains(e.target)) xl.classList.remove('open'); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { xl.classList.remove('open'); closeUp(); }
    if (e.key === 'F2' && $('ptbNew')) { e.preventDefault(); $('ptbNew').click(); window.location.hash = '#pform'; focusName(); }
  });
  if (location.hash === '#pform') focusName();
})();
</script>
<?php
}
