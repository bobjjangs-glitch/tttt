/* ============================================================
   ✍️ 펜글씨 이중 입력 필드 — 텍스트 / 터치(펜·손가락) 필기 전환
   사용: .pen-field 컨테이너에
     data-pen-name="파일명 접두어"  data-pen-fallback="빈 텍스트 시 대체 문구"
   구조: .pen-toggle > .pen-mode[data-mode=text|pen]
         .pen-text (input/textarea), .pen-pad > canvas + .pen-tools
   저장 시 그려진 내용은 PNG로 변환해 기존 사진 첨부(photos[]) 파이프라인으로
   함께 업로드됩니다 (최대 5장 제한 안 준수).
   ============================================================ */
(function () {
  'use strict';

  var PAD_H = 220;           // 필기판 높이 (CSS px)
  var INK = '#111827';       // 잉크 색
  var GUIDE = '#e2e8f0';     // 줄 색

  function setupField(root) {
    var filePrefix = root.getAttribute('data-pen-name') || 'pen';
    var fallback = root.getAttribute('data-pen-fallback') || '';
    var textEl = root.querySelector('.pen-text');
    var pad = root.querySelector('.pen-pad');
    if (!textEl || !pad) return;
    var canvas = pad.querySelector('canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var modeBtns = root.querySelectorAll('.pen-mode');
    var undoBtn = pad.querySelector('.pen-undo');
    var clearBtn = pad.querySelector('.pen-clear');
    var widthBtns = pad.querySelectorAll('.pen-w');
    var strokes = [];        // [{pts:[{x,y}], w}]
    var current = null;
    var baseWidth = 5;
    var dpr = Math.max(1, window.devicePixelRatio || 1);
    var sized = false;

    function cssW() { return canvas.width / dpr; }
    function cssH() { return canvas.height / dpr; }

    function redraw() {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, cssW(), cssH());
      /* 필기 보조선 (60px 간격) */
      ctx.strokeStyle = GUIDE;
      ctx.lineWidth = 1;
      for (var gy = 60; gy < PAD_H - 8; gy += 60) {
        ctx.beginPath();
        ctx.moveTo(0, gy + 0.5);
        ctx.lineTo(cssW(), gy + 0.5);
        ctx.stroke();
      }
      for (var i = 0; i < strokes.length; i++) drawStroke(strokes[i]);
    }

    function drawStroke(s) {
      if (!s.pts.length) return;
      ctx.strokeStyle = INK;
      ctx.fillStyle = INK;
      ctx.lineWidth = s.w;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      if (s.pts.length === 1) {
        ctx.beginPath();
        ctx.arc(s.pts[0].x, s.pts[0].y, s.w / 2, 0, Math.PI * 2);
        ctx.fill();
        return;
      }
      ctx.beginPath();
      var m0 = mid(s.pts[0], s.pts[1]);
      ctx.moveTo(s.pts[0].x, s.pts[0].y);
      ctx.quadraticCurveTo(s.pts[0].x, s.pts[0].y, m0.x, m0.y);
      for (var i = 1; i < s.pts.length - 1; i++) {
        var m = mid(s.pts[i], s.pts[i + 1]);
        ctx.quadraticCurveTo(s.pts[i].x, s.pts[i].y, m.x, m.y);
      }
      var last = s.pts[s.pts.length - 1];
      ctx.lineTo(last.x, last.y);
      ctx.stroke();
    }

    function mid(a, b) { return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }; }

    function resize() {
      var w = Math.max(240, Math.floor((pad.clientWidth || 320)));
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(PAD_H * dpr);
      canvas.style.width = '100%';
      canvas.style.height = PAD_H + 'px';
      sized = true;
      redraw();
    }

    function pos(e) {
      var r = canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    /* ---- 그리기 (Pointer Events: 터치펜·손가락·마우스 공용, 펜 압력 반영) ---- */
    canvas.style.touchAction = 'none';
    canvas.addEventListener('pointerdown', function (e) {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      e.preventDefault();
      try { canvas.setPointerCapture(e.pointerId); } catch (err) {}
      var w = baseWidth;
      if (e.pointerType === 'pen' && e.pressure > 0) {
        w = Math.max(1.5, baseWidth * (0.45 + e.pressure * 1.1));
      }
      current = { pts: [pos(e)], w: w };
      strokes.push(current);
      redraw();
    });
    canvas.addEventListener('pointermove', function (e) {
      if (!current) return;
      e.preventDefault();
      var p = pos(e);
      var pts = current.pts;
      var prev = pts[pts.length - 1];
      var m = mid(prev, p);
      ctx.strokeStyle = INK;
      ctx.lineWidth = current.w;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      if (pts.length === 1) {
        ctx.fillStyle = INK;
        ctx.beginPath();
        ctx.arc(prev.x, prev.y, current.w / 2, 0, Math.PI * 2);
        ctx.fill();
      } else {
        ctx.beginPath();
        ctx.moveTo(mid(pts[pts.length - 2], prev).x, mid(pts[pts.length - 2], prev).y);
        ctx.quadraticCurveTo(prev.x, prev.y, m.x, m.y);
        ctx.stroke();
      }
      pts.push(p);
    });
    function endStroke() { current = null; }
    canvas.addEventListener('pointerup', endStroke);
    canvas.addEventListener('pointercancel', endStroke);

    /* ---- 모드 전환 ---- */
    var origRequired = textEl.required;
    Array.prototype.forEach.call(modeBtns, function (btn) {
      btn.addEventListener('click', function () {
        var pen = btn.getAttribute('data-mode') === 'pen';
        Array.prototype.forEach.call(modeBtns, function (b) {
          b.classList.toggle('on', b === btn);
        });
        textEl.hidden = pen;
        /* 펜 모드에서는 텍스트 필수검사 비활성 — 필기 이미지가 내용을 대신함 */
        textEl.required = pen ? false : origRequired;
        pad.hidden = !pen;
        if (pen && !sized) requestAnimationFrame(resize);
      });
    });

    /* ---- 도구 ---- */
    if (undoBtn) undoBtn.addEventListener('click', function () {
      strokes.pop();
      current = null;
      redraw();
    });
    if (clearBtn) clearBtn.addEventListener('click', function () {
      strokes = [];
      current = null;
      redraw();
    });
    Array.prototype.forEach.call(widthBtns, function (btn) {
      btn.addEventListener('click', function () {
        baseWidth = Math.max(1, parseInt(btn.getAttribute('data-w'), 10) || 5);
        Array.prototype.forEach.call(widthBtns, function (b) {
          b.classList.toggle('on', b === btn);
        });
      });
    });

    /* 창 크기 변화에 맞춰 필기판 재구성 (그려진 내용 유지) */
    var rt;
    window.addEventListener('resize', function () {
      if (pad.hidden) return;
      clearTimeout(rt);
      rt = setTimeout(resize, 150);
    });

    root.__penField = {
      hasStrokes: function () { return strokes.length > 0; },
      applyFallback: function () {
        if (strokes.length && fallback && !textEl.value.trim()) textEl.value = fallback;
      },
      toFile: function () {
        return new Promise(function (resolve) {
          if (!strokes.length) { resolve(null); return; }
          canvas.toBlob(function (blob) {
            if (!blob) { resolve(null); return; }
            try {
              resolve(new File([blob], filePrefix + '-' + Date.now() + '.png', { type: 'image/png' }));
            } catch (err) {
              /* 구형 브라우저 File 생성자 폴백 */
              resolve(new Blob([blob], { type: 'image/png' }));
            }
          }, 'image/png');
        });
      }
    };
  }

  /* ---- 폼 제출 처리: 필기 → PNG 파일로 변환해 사진 첨부에 합류 ---- */
  function bindForms() {
    Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
      var fields = Array.prototype.slice.call(form.querySelectorAll('.pen-field'));
      if (!fields.length) return;
      var photoInput = form.querySelector('input[type="file"][name="photos[]"]');
      var MAX = 5;

      form.addEventListener('submit', function (e) {
        var active = fields.filter(function (f) { return f.__penField && f.__penField.hasStrokes(); });
        if (!active.length) return;            /* 텍스트로만 작성 시 기존 흐름 그대로 */

        if (form.__penSubmitted) return;       /* 재제출 가드 */
        e.preventDefault();
        form.__penSubmitted = true;

        active.forEach(function (f) { f.__penField.applyFallback(); });

        var dt = new DataTransfer();
        var picked = photoInput ? Array.prototype.slice.call(photoInput.files) : [];
        picked.forEach(function (f) { dt.items.add(f); });

        var penFiles = [];
        var chain = Promise.resolve();
        active.forEach(function (f) {
          chain = chain.then(function () {
            return f.__penField.toFile().then(function (file) {
              if (file) penFiles.push(file);
            });
          });
        });

        chain.then(function () {
          var room = Math.max(0, MAX - picked.length);
          var skipped = Math.max(0, penFiles.length - room);
          if (room > 0) {
            penFiles.slice(0, room).forEach(function (f) { dt.items.add(f); });
          }
          if (photoInput) {
            try { photoInput.files = dt.files; } catch (err) {}
          }
          if (skipped > 0 && !form.__penNoticeShown) {
            form.__penNoticeShown = true;
            alert('사진·필기 첨부는 최대 ' + MAX + '장까지 가능해 필기 ' + skipped + '장을 제외했습니다. (카메라 사진이 우선입니다)');
          }
          form.submit();
        }).catch(function () {
          form.submit();
        });
      });
    });
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('.pen-field'), setupField);
    bindForms();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
