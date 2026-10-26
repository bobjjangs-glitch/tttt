// assets/partner_select.js — 거래처 표에 체크박스 열 + 선택 시 Excel 옆에 "선택 삭제" 버튼 (v4)
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('pbulk');
  if (!form) return;
  if (form.getAttribute('data-ready') === '1') return; // 중복 실행 방지
  form.setAttribute('data-ready', '1');

  // 행마다 붙은 단건 삭제 링크는 제거 (선택 삭제로 대체)
  document.querySelectorAll('a[href*="partner_delete.php"]').forEach(function (a) { a.remove(); });

  // 1) 헤더에 "지역" 열이 있는 표 찾기
  var table = null, idx = -1, headCount = 0;
  document.querySelectorAll('table').forEach(function (t) {
    if (table) return;
    var hr = t.querySelector('thead tr') || t.querySelector('tr');
    if (!hr) return;
    for (var i = 0; i < hr.cells.length; i++) {
      if (hr.cells[i].textContent.trim() === '지역') {
        table = t; idx = i; headCount = hr.cells.length;
        return;
      }
    }
  });
  if (!table) {
    console.warn('[partner_select] "지역" 열이 있는 표를 찾지 못했습니다.');
    return;
  }

  // 2) 헤더: 전체 선택
  var headRow = table.querySelector('thead tr') || table.querySelector('tr');
  var th = document.createElement('th');
  th.style.cssText = 'width:36px;text-align:center';
  var all = document.createElement('input');
  all.type = 'checkbox';
  all.title = '전체 선택';
  th.appendChild(all);
  headRow.insertBefore(th, headRow.cells[idx]);

  // 3) 각 행: 수정 링크의 edit=ID 에서 거래처 번호 추출
  var boxes = [];
  table.querySelectorAll('tbody tr').forEach(function (tr) {
    if (tr.cells.length !== headCount) return; // 빈 목록 안내 행 등
    var a = tr.querySelector('a[href*="edit="]');
    var m = a ? a.getAttribute('href').match(/[?&]edit=(\d+)/) : null;
    var td = document.createElement('td');
    td.style.cssText = 'width:36px;text-align:center';
    if (m) {
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = m[1];
      cb.addEventListener('change', refresh);
      td.appendChild(cb);
      boxes.push(cb);
    }
    tr.insertBefore(td, tr.cells[idx]);
  });
  if (!boxes.length) {
    console.warn('[partner_select] edit=ID 링크를 찾지 못해 체크박스를 만들지 못했습니다.');
  }

  // 4) 선택 삭제 버튼 (처음엔 숨김)
  var btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn';
  btn.style.cssText = 'color:#dc2626';
  btn.style.display = 'none';
  btn.textContent = '선택 삭제 (0)';

  var placedInBar = false;

  // 기존 하단 고정 바 찾기 (main 안쪽 포함)
  function findBar() {
    var cands = document.querySelectorAll('div, nav, footer, section, aside');
    for (var i = 0; i < cands.length; i++) {
      var el = cands[i];
      if (el.contains(table) || table.contains(el)) continue;
      var cs = getComputedStyle(el);
      if (cs.position !== 'fixed') continue;
      if (cs.bottom !== '0px') continue;
      if (el.offsetWidth <= 300) continue;
      if (el.offsetHeight <= 0 || el.offsetHeight >= 120) continue;
      if (!el.querySelector('button, a')) continue;
      return el;
    }
    return null;
  }

  // 바 안에서 "Excel" 로 시작하는 버튼/링크 (DOM 순서상 첫 번째 = 토글 버튼)
  function findExcel(bar) {
    var els = bar.querySelectorAll('button, a');
    for (var i = 0; i < els.length; i++) {
      if (/^\s*(excel|엑셀)/i.test(els[i].textContent)) return els[i];
    }
    return null;
  }

  // Excel 버튼이 드롭다운 묶음 안에 있으면 묶음 전체를 하나로 본다.
  // 신규/조회/인쇄 버튼을 함께 품은 요소(= 버튼 줄)에 닿기 직전까지 올라간다.
  function climbToRowChild(bar, el) {
    var node = el;
    while (node.parentElement && node.parentElement !== bar) {
      var p = node.parentElement;
      var hasOther = false;
      p.querySelectorAll('button, a').forEach(function (x) {
        if (node.contains(x)) return;
        if (/^\s*(신규|조회|인쇄)/.test(x.textContent)) hasOther = true;
      });
      if (hasOther) break;
      node = p;
    }
    return node;
  }

  function placeBtn() {
    var bar = findBar();
    if (!bar) return false;
    var ex = findExcel(bar);
    if (ex) {
      var node = climbToRowChild(bar, ex);
      var ref = ex;
      btn.className = ref.className || 'btn';
      btn.style.color = '#fca5a5';
      btn.style.marginLeft = '8px';
      node.parentElement.insertBefore(btn, node.nextSibling);
      console.info('[partner_select] Excel 오른쪽에 붙였습니다.');
    } else {
      var first = bar.querySelector('button, a');
      btn.className = first ? first.className : 'btn';
      btn.style.color = '#fca5a5';
      btn.style.marginLeft = '8px';
      bar.appendChild(btn);
      console.warn('[partner_select] Excel 버튼을 못 찾아 바의 맨 끝에 붙였습니다.');
    }
    placedInBar = true;
    return true;
  }

  function placeFallback() {
    if (btn.parentNode) return;
    var wrap = document.createElement('div');
    wrap.style.cssText = 'margin:0 0 8px;text-align:right';
    wrap.appendChild(btn);
    table.parentNode.insertBefore(wrap, table);
    console.warn('[partner_select] 하단 고정 바를 찾지 못해 표 위에 버튼을 두었습니다.');
  }

  if (!placeBtn()) {
    // 바가 늦게 그려지는 경우를 위해 페이지 로드 완료 후 한 번 더 시도
    window.addEventListener('load', function () {
      if (!placedInBar && !placeBtn()) placeFallback();
    });
    // load 가 이미 끝난 뒤라면 바로 폴백
    if (document.readyState === 'complete') placeFallback();
  }

  function refresh() {
    var n = boxes.filter(function (b) { return b.checked; }).length;
    btn.textContent = '선택 삭제 (' + n + ')';
    btn.style.display = n > 0 ? '' : 'none';
    all.checked = n > 0 && n === boxes.length;
    all.indeterminate = n > 0 && n < boxes.length;
    // 아직 바에 못 붙였다면 선택 시점에 다시 시도
    if (n > 0 && !placedInBar) {
      if (!placeBtn()) placeFallback();
    }
  }

  all.addEventListener('change', function () {
    boxes.forEach(function (b) { b.checked = all.checked; });
    refresh();
  });

  btn.addEventListener('click', function () {
    var picked = boxes.filter(function (b) { return b.checked; });
    if (!picked.length) return;
    form.querySelectorAll('input[name="ids[]"]').forEach(function (x) { x.remove(); });
    picked.forEach(function (b) {
      var i = document.createElement('input');
      i.type = 'hidden';
      i.name = 'ids[]';
      i.value = b.value;
      form.appendChild(i);
    });
    form.submit();
  });

  refresh();
});
