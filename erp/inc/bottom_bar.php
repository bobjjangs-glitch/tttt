<?php
// inc/bottom_bar.php - 하단 고정 버튼 바 (스타일 + 메뉴 열기/닫기). 미실행 코드
// 사용: ebar_assets(); 를 한 번 호출한 뒤 .ebar / .ebar-dd 마크업을 출력

function ebar_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<style>
.ebar{position:fixed;left:0;right:0;bottom:0;z-index:50;background:#fff;border-top:1px solid #cfd4da;box-shadow:0 -2px 6px rgba(0,0,0,.06);padding:5px 10px;display:flex;flex-wrap:wrap;align-items:center;gap:4px;font-size:12px}
.ebar-btn{display:inline-flex;align-items:center;height:26px;padding:0 10px;border:1px solid #b8bec6;background:#f7f8fa;color:#222;border-radius:2px;font-size:12px;line-height:1;cursor:pointer;text-decoration:none;white-space:nowrap;box-sizing:border-box}
.ebar-btn:hover{background:#eef1f5}
.ebar-btn.pri{background:#3a3f47;border-color:#3a3f47;color:#fff}
.ebar-btn[disabled]{color:#9aa0a8;background:#f3f4f6;cursor:not-allowed}
.ebar-dd{position:relative;display:inline-block}
.ebar-menu{display:none;position:absolute;left:0;bottom:30px;min-width:170px;background:#fff;border:1px solid #b8bec6;box-shadow:0 -3px 8px rgba(0,0,0,.15);padding:4px 0}
.ebar-dd.open .ebar-menu{display:block}
.ebar-menu form{margin:0;display:block}
.ebar-item{display:block;width:100%;text-align:left;padding:7px 12px;border:0;background:none;font-size:12px;color:#222;cursor:pointer;text-decoration:none;box-sizing:border-box}
.ebar-item:hover{background:#eef3fb}
.ebar-item[disabled]{color:#9aa0a8;cursor:not-allowed;background:none}
.ebar-note{padding:4px 12px;color:#6b7280;font-size:11px;max-width:230px;white-space:normal}
.ebar-reason{display:block;margin:4px 12px;width:210px;box-sizing:border-box}
.ebar-spacer{height:44px}
@media print{.ebar,.ebar-spacer{display:none!important}}
</style>
<script>
document.addEventListener('click', function (e) {
  var t  = e.target;
  var dd = t.closest ? t.closest('.ebar-dd') : null;
  var opened = document.querySelectorAll('.ebar-dd.open');
  for (var i = 0; i < opened.length; i++) {
    if (opened[i] !== dd) { opened[i].classList.remove('open'); }
  }
  if (dd && t.closest('[data-dd]')) { dd.classList.toggle('open'); }
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    var o = document.querySelectorAll('.ebar-dd.open');
    for (var i = 0; i < o.length; i++) { o[i].classList.remove('open'); }
  }
});
</script>
<?php
}
