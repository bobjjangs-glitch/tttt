<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_once __DIR__ . '/inc/events.php';
require_once __DIR__ . '/inc/dashboard.php';
require_once __DIR__ . '/inc/dash_theme.php';

$today = date('Y-m-d');
$canWrite = can_write();
$weekNames = ['일', '월', '화', '수', '목', '금', '토'];
$cats = ev_categories();

// ── 보는 달 ──────────────────────────────────────────
$ym = (isset($_GET['ym']) && is_string($_GET['ym'])) ? $_GET['ym'] : '';
if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $mm) || (int)$mm[1] < 2000 || (int)$mm[1] > 2100 || (int)$mm[2] < 1 || (int)$mm[2] > 12) {
    $ym = date('Y-m');
}
$first = $ym . '-01';
$daysInMonth = (int)date('t', strtotime($first));
$last = $ym . '-' . sprintf('%02d', $daysInMonth);
$startWeekday = (int)date('w', strtotime($first));
$prevYm = date('Y-m', strtotime($first . ' -1 month'));
$nextYm = date('Y-m', strtotime($first . ' +1 month'));
$prevFirst = $prevYm . '-01';
$prevLast = date('Y-m-t', strtotime($prevFirst));

// 선택한 날 (없으면: 이번 달이면 오늘, 아니면 1일)
$sel = (isset($_GET['d']) && is_string($_GET['d']) && valid_date($_GET['d']) && substr($_GET['d'], 0, 7) === $ym)
    ? $_GET['d']
    : ($ym === date('Y-m') ? $today : $first);
$selDay = (int)substr($sel, 8, 2);

// ── 일정 ─────────────────────────────────────────────
$events = [];
$evMsg = null;
$r = dash_try(function () use ($first, $last) {
    ev_ensure_table();
    return ev_fetch_range($first, $last);
});
if ($r['ok']) {
    $events = $r['v'];
} else {
    $evMsg = $r['msg'];
}
$byDay = [];
foreach ($events as $e) {
    $s = $e['start_date'] < $first ? 1 : (int)substr($e['start_date'], 8, 2);
    $f = $e['end_date'] > $last ? $daysInMonth : (int)substr($e['end_date'], 8, 2);
    for ($dd = $s; $dd <= $f; $dd++) {
        $byDay[$dd][] = $e;
    }
}
$dayEvents = $byDay[$selDay] ?? [];
$editEv = null;
$editRaw = (isset($_GET['edit']) && is_string($_GET['edit'])) ? $_GET['edit'] : '';
if ($canWrite && ctype_digit($editRaw)) {
    foreach ($dayEvents as $e) {
        if ((int)$e['id'] === (int)$editRaw) {
            $editEv = $e;
            break;
        }
    }
}
$curCat = $editEv['category'] ?? 'general';
if (!isset($cats[$curCat])) {
    $curCat = 'general';
}

// ── 통계 ─────────────────────────────────────────────
$colInfo = dash_try('dash_amount_col');
$none = ['ok' => false, 'msg' => ''];
$sumCur  = $colInfo['ok'] ? dash_try(fn() => dash_sums($first, $last)) : $none;
$sumPrev = $colInfo['ok'] ? dash_try(fn() => dash_sums($prevFirst, $prevLast)) : $none;
$daily   = $colInfo['ok'] ? dash_try(fn() => dash_daily($first, $last)) : $none;
$months  = $colInfo['ok'] ? dash_try(fn() => dash_months($ym, 6)) : $none;
$top     = $colInfo['ok'] ? dash_try(fn() => dash_top_partners($first, $last)) : $none;
$recent  = $colInfo['ok'] ? dash_try(fn() => dash_recent()) : $none;
$counts  = dash_try('dash_counts');

$dailyMap = $daily['ok'] ? $daily['v'] : [];
$monthNum = (int)substr($ym, 5, 2);

// ── 알림 (닫기·접기 대상) ────────────────────────────
$alerts = [];
if ($counts['ok']) {
    if ($counts['v']['minus'] > 0) {
        $alerts[] = [
            'key' => 'minus', 'sig' => (string)(int)$counts['v']['minus'], 'level' => 'danger',
            'pre' => '재고가 마이너스인 품목이 ', 'num' => (int)$counts['v']['minus'] . '개',
            'post' => ' 있습니다. 입고 누락이나 조정 오류일 수 있습니다.',
            'links' => [['재고 현황 보기', 'stock.php']],
        ];
    }
    if ($counts['v']['draft'] > 0) {
        $alerts[] = [
            'key' => 'draft', 'sig' => (string)(int)$counts['v']['draft'], 'level' => 'info',
            'pre' => '임시 상태 전표가 ', 'num' => (int)$counts['v']['draft'] . '건',
            'post' => ' 있습니다(판매·매입 합계). 확정 전에는 재고·매출 집계에 반영되지 않습니다.',
            'links' => [
                ['판매', 'trades.php?type=sale&status=draft'],
                ['매입', 'trades.php?type=purchase&status=draft'],
            ],
        ];
    }
}

page_header('대시보드', 'dashboard');
dash_theme();
?>
<h1>대시보드 <small><?= h($today) ?> (<?= $weekNames[(int)date('w')] ?>)</small></h1>

<?php if (!$colInfo['ok']): ?>
  <div class="dsh-fail dsh-mb"><?= h($colInfo['msg']) ?></div>
<?php endif; ?>

<?php if ($alerts): ?>
<div id="dshNotice" data-day="<?= h($today) ?>">
  <div class="dsh-notice" id="dshNoticeBox">
    <button type="button" class="dsh-notice-head" id="dshNoticeHead" aria-expanded="true" aria-controls="dshNoticeBody">
      <span class="dsh-notice-ico" aria-hidden="true">!</span>
      <span class="dsh-notice-title">확인이 필요한 알림<b id="dshNoticeCnt"><?= count($alerts) ?></b></span>
      <span class="dsh-notice-chev" aria-hidden="true"></span>
    </button>
    <div class="dsh-notice-body" id="dshNoticeBody">
      <?php foreach ($alerts as $a): ?>
        <div class="dsh-notice-item <?= h($a['level']) ?>" data-key="<?= h($a['key']) ?>" data-sig="<?= h($a['sig']) ?>">
          <span class="t"><?= h($a['pre']) ?><b><?= h($a['num']) ?></b><?= h($a['post']) ?></span>
          <span class="lk">
            <?php foreach ($a['links'] as $lk): ?><a href="<?= h($lk[1]) ?>"><?= h($lk[0]) ?></a><?php endforeach; ?>
          </span>
          <button type="button" class="dsh-x" aria-label="이 알림 닫기" title="오늘은 숨기기">&times;</button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <button type="button" class="dsh-link" id="dshNoticeRestore" hidden>닫은 알림 다시 보기</button>
</div>
<script>
(function () {
  var wrap = document.getElementById('dshNotice');
  if (!wrap) { return; }
  var box = document.getElementById('dshNoticeBox');
  var head = document.getElementById('dshNoticeHead');
  var cnt = document.getElementById('dshNoticeCnt');
  var restore = document.getElementById('dshNoticeRestore');
  var day = wrap.getAttribute('data-day');
  var items = Array.prototype.slice.call(box.querySelectorAll('.dsh-notice-item'));
  var COLL = 'erp.notice.collapsed';
  var mem = {}; // localStorage 가 막힌 브라우저 대비(새로고침 전까지만 유지)

  function read(k) {
    if (Object.prototype.hasOwnProperty.call(mem, k)) { return mem[k]; }
    try { return window.localStorage.getItem(k); } catch (e) { return null; }
  }
  function write(k, v) {
    mem[k] = v;
    try { window.localStorage.setItem(k, v); } catch (e) {}
  }
  function drop(k) {
    delete mem[k];
    try { window.localStorage.removeItem(k); } catch (e) {}
  }
  function keyOf(it) { return 'erp.notice.' + it.getAttribute('data-key'); }
  // 날짜 + 건수가 같을 때만 "닫힘" 유지 → 내일이 되거나 건수가 바뀌면 다시 표시
  function sigOf(it) { return day + '|' + it.getAttribute('data-sig'); }

  function render() {
    var shown = 0, closed = 0;
    items.forEach(function (it) {
      var off = read(keyOf(it)) === sigOf(it);
      it.hidden = off;
      if (off) { closed++; } else { shown++; }
    });
    cnt.textContent = shown;
    box.hidden = (shown === 0);
    restore.hidden = (closed === 0);
  }
  function setCollapsed(c) {
    if (c) { box.classList.add('is-collapsed'); } else { box.classList.remove('is-collapsed'); }
    head.setAttribute('aria-expanded', c ? 'false' : 'true');
  }

  setCollapsed(read(COLL) === '1');
  head.addEventListener('click', function () {
    var c = !box.classList.contains('is-collapsed');
    setCollapsed(c);
    write(COLL, c ? '1' : '0');
  });
  items.forEach(function (it) {
    var x = it.querySelector('.dsh-x');
    if (x) {
      x.addEventListener('click', function () {
        write(keyOf(it), sigOf(it));
        render();
      });
    }
  });
  restore.addEventListener('click', function () {
    items.forEach(function (it) { drop(keyOf(it)); });
    render();
  });
  render();
})();
</script>
<?php endif; ?>

<?php if ($sumCur['ok'] && $sumPrev['ok']):
    $c = $sumCur['v'];
    $p = $sumPrev['v'];
    $etc = $c['sale'] - $c['retail'] - $c['wholesale'];
    ?>
<div class="dsh-kpis">
  <div class="dsh-kpi main"><small><?= $monthNum ?>월 매출</small><b><?= dash_won($c['sale']) ?>원</b><small><?= h(dash_delta($c['sale'], $p['sale'])) ?></small></div>
  <div class="dsh-kpi"><small>소매 매출</small><b><?= dash_won($c['retail']) ?>원</b><small><?= h(dash_delta($c['retail'], $p['retail'])) ?></small></div>
  <div class="dsh-kpi"><small>도매 매출</small><b><?= dash_won($c['wholesale']) ?>원</b><small><?= h(dash_delta($c['wholesale'], $p['wholesale'])) ?><?= $etc !== 0 ? ' · 구분 없음 ' . dash_won($etc) . '원' : '' ?></small></div>
  <div class="dsh-kpi"><small><?= $monthNum ?>월 매입</small><b><?= dash_won($c['purchase']) ?>원</b><small><?= h(dash_delta($c['purchase'], $p['purchase'])) ?></small></div>
  <div class="dsh-kpi"><small>확정 전표</small><b><?= (int)($c['sale_cnt'] + $c['purchase_cnt']) ?>건</b><small>판매 <?= (int)$c['sale_cnt'] ?> · 매입 <?= (int)$c['purchase_cnt'] ?></small></div>
</div>
<?php elseif (($sumCur['msg'] ?? '') !== ''): ?>
  <div class="dsh-fail dsh-mb">통계 카드: <?= h($sumCur['msg']) ?></div>
<?php endif; ?>

<div class="dsh-grid">
  <!-- 달력 -->
  <section class="dsh-card">
    <div class="dsh-head">
      <h2><?= (int)substr($ym, 0, 4) ?>년 <?= $monthNum ?>월</h2>
      <div class="dsh-nav">
        <a href="index.php?ym=<?= h($prevYm) ?>">◀ 이전</a>
        <a href="index.php">오늘</a>
        <a href="index.php?ym=<?= h($nextYm) ?>">다음 ▶</a>
      </div>
    </div>
    <?php if ($evMsg !== null): ?><div class="dsh-fail dsh-mb">일정: <?= h($evMsg) ?></div><?php endif; ?>
    <div class="dsh-cal">
      <?php foreach ($weekNames as $i => $wn): ?>
        <div class="dsh-dow" style="<?= $i === 0 ? 'color:#ef4444' : ($i === 6 ? 'color:#3b82f6' : '') ?>"><?= $wn ?></div>
      <?php endforeach; ?>
      <?php
      $cells = [];
      for ($i = 0; $i < $startWeekday; $i++) {
          $cells[] = 0;
      }
      for ($dd = 1; $dd <= $daysInMonth; $dd++) {
          $cells[] = $dd;
      }
      while (count($cells) % 7 !== 0) {
          $cells[] = 0;
      }
      foreach ($cells as $idx => $dd):
          if ($dd === 0): ?>
            <div class="dsh-cell dsh-empty"></div>
          <?php continue; endif;
          $ds = sprintf('%s-%02d', $ym, $dd);
          $w = $idx % 7;
          $cls = 'dsh-cell' . ($w === 0 ? ' sun' : '') . ($w === 6 ? ' sat' : '') . ($ds === $today ? ' today' : '') . ($dd === $selDay ? ' sel' : '');
          $list = $byDay[$dd] ?? [];
          ?>
        <a class="<?= $cls ?>" href="index.php?ym=<?= h($ym) ?>&amp;d=<?= h($ds) ?>">
          <span class="dsh-dn"><?= $dd ?></span>
          <?php if (isset($dailyMap[$dd])): ?><span class="dsh-sales">판매 <?= (int)$dailyMap[$dd][0] ?></span><?php endif; ?>
          <?php foreach (array_slice($list, 0, 3) as $e):
              $color = $cats[$e['category']][1] ?? '#6b7280'; ?>
            <span class="dsh-chip<?= (int)$e['is_done'] === 1 ? ' done' : '' ?>" style="--c:<?= h($color) ?>;--bg:<?= h($color) ?>22"><?= h($e['title']) ?></span>
          <?php endforeach; ?>
          <?php if (count($list) > 3): ?><span class="dsh-more">+<?= count($list) - 3 ?>건 더</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- 선택한 날 -->
  <section class="dsh-card">
    <h2><?= (int)substr($sel, 5, 2) ?>월 <?= $selDay ?>일 (<?= $weekNames[(int)date('w', strtotime($sel))] ?>)</h2>
    <?php if (isset($dailyMap[$selDay])): ?>
      <p class="dsh-sum">확정 판매 <b><?= (int)$dailyMap[$selDay][0] ?>건</b> · <?= dash_won($dailyMap[$selDay][1]) ?>원</p>
    <?php endif; ?>

    <?php if (!$dayEvents): ?><p class="dsh-none">등록된 일정이 없습니다.</p><?php endif; ?>
    <?php foreach ($dayEvents as $e):
        $color = $cats[$e['category']][1] ?? '#6b7280';
        $cname = $cats[$e['category']][0] ?? '일반'; ?>
      <div class="dsh-ev<?= (int)$e['is_done'] === 1 ? ' done' : '' ?>" style="--c:<?= h($color) ?>">
        <span class="dsh-et"><b><?= h($e['title']) ?></b></span>
        <small><?= h($cname) ?> · <?= h($e['start_date']) ?><?= $e['end_date'] !== $e['start_date'] ? ' ~ ' . h($e['end_date']) : '' ?><?= (int)$e['is_done'] === 1 ? ' · 완료' : '' ?></small>
        <?php if ($e['memo'] !== ''): ?><small><?= nl2br(h($e['memo'])) ?></small><?php endif; ?>
        <?php if ($canWrite): ?>
          <div class="dsh-acts">
            <form method="post" action="event_save.php">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <input type="hidden" name="ym" value="<?= h($ym) ?>"><input type="hidden" name="d" value="<?= h($sel) ?>">
              <button class="btn btn-sm" type="submit"><?= (int)$e['is_done'] === 1 ? '완료 해제' : '완료' ?></button>
            </form>
            <a class="btn btn-sm" href="index.php?ym=<?= h($ym) ?>&amp;d=<?= h($sel) ?>&amp;edit=<?= (int)$e['id'] ?>">수정</a>
            <form method="post" action="event_save.php" onsubmit="return confirm('이 일정을 삭제할까요?')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <input type="hidden" name="ym" value="<?= h($ym) ?>"><input type="hidden" name="d" value="<?= h($sel) ?>">
              <button class="btn btn-sm btn-danger" type="submit">삭제</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($canWrite && $evMsg === null): ?>
      <form method="post" action="event_save.php" class="dsh-formbox">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="<?= $editEv ? 'update' : 'add' ?>">
        <?php if ($editEv): ?><input type="hidden" name="id" value="<?= (int)$editEv['id'] ?>"><?php endif; ?>
        <input type="hidden" name="ym" value="<?= h($ym) ?>"><input type="hidden" name="d" value="<?= h($sel) ?>">
        <div class="dsh-formtitle">
          <span><?= $editEv ? '일정 수정' : '일정 추가' ?></span>
          <?php if ($editEv): ?><a class="btn btn-sm" href="index.php?ym=<?= h($ym) ?>&amp;d=<?= h($sel) ?>">취소</a><?php endif; ?>
        </div>
        <label class="dsh-field"><span>제목</span>
          <input name="title" required maxlength="100" placeholder="예) 대전카센터 납품" value="<?= h($editEv['title'] ?? '') ?>">
        </label>
        <div class="dsh-field"><span>구분</span>
          <div class="dsh-cats">
            <?php foreach ($cats as $k => $cv): ?>
              <label class="dsh-catopt">
                <input type="radio" name="category" value="<?= h($k) ?>" <?= $curCat === $k ? 'checked' : '' ?>>
                <span style="--c:<?= h($cv[1]) ?>"><?= h($cv[0]) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="dsh-row2">
          <label class="dsh-field"><span>시작</span><input type="date" name="start_date" required value="<?= h($editEv['start_date'] ?? $sel) ?>"></label>
          <label class="dsh-field"><span>종료</span><input type="date" name="end_date" required value="<?= h($editEv['end_date'] ?? $sel) ?>"></label>
        </div>
        <label class="dsh-field"><span>메모</span>
          <textarea name="memo" rows="2" maxlength="500" placeholder="필요하면 적어 두세요"><?= h($editEv['memo'] ?? '') ?></textarea>
        </label>
        <div class="dsh-formact">
          <button class="btn btn-primary" type="submit"><?= $editEv ? '수정 저장' : '일정 등록' ?></button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>

<div class="dsh-grid2">
  <!-- 일별 판매 -->
  <section class="dsh-card">
    <h2><?= $monthNum ?>월 일별 판매 (확정 전표)</h2>
    <?php if ($daily['ok']):
        $mx = 1;
        foreach ($dailyMap as $v) {
            $mx = max($mx, $v[1]);
        } ?>
      <div class="dsh-bars">
        <?php for ($dd = 1; $dd <= $daysInMonth; $dd++):
            $amt = $dailyMap[$dd][1] ?? 0;
            $cnt = $dailyMap[$dd][0] ?? 0;
            $pct = $amt > 0 ? max(2, intdiv($amt * 100, $mx)) : 0; ?>
          <div class="dsh-bar" title="<?= $monthNum ?>/<?= $dd ?> · <?= $cnt ?>건 · <?= dash_won($amt) ?>원">
            <div class="dsh-bi"><i style="height:<?= $pct ?>%"></i></div>
            <span><?= $dd ?></span>
          </div>
        <?php endfor; ?>
      </div>
    <?php elseif ($daily['msg'] !== ''): ?><div class="dsh-fail"><?= h($daily['msg']) ?></div><?php endif; ?>
  </section>

  <!-- 최근 6개월 -->
  <section class="dsh-card">
    <h2>최근 6개월 매출·매입</h2>
    <?php if ($months['ok']):
        $mx = 1;
        foreach ($months['v'] as $m) {
            $mx = max($mx, $m['sale'], $m['purchase']);
        } ?>
      <?php foreach ($months['v'] as $m): ?>
        <div class="dsh-mrow">
          <span><?= h($m['ym']) ?></span>
          <div>
            <div class="dsh-hb" style="width:<?= intdiv($m['sale'] * 100, $mx) ?>%;background:#3182f6"></div>
            <div class="dsh-hb" style="width:<?= intdiv($m['purchase'] * 100, $mx) ?>%;background:#f59e0b"></div>
            <span>매출 <?= dash_won($m['sale']) ?> · 매입 <?= dash_won($m['purchase']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="dsh-foot">파랑 = 매출, 주황 = 매입 (확정 전표)</div>
    <?php elseif ($months['msg'] !== ''): ?><div class="dsh-fail"><?= h($months['msg']) ?></div><?php endif; ?>
  </section>
</div>

<div class="dsh-grid2">
  <section class="dsh-card">
    <h2><?= $monthNum ?>월 거래처 판매 TOP 5</h2>
    <?php if ($top['ok']): ?>
      <table class="dsh-tbl">
        <thead><tr><th>#</th><th>거래처</th><th class="num">건수</th><th class="num">금액</th><th class="num">비중</th></tr></thead>
        <tbody>
        <?php if (!$top['v']): ?><tr><td colspan="5">거래처가 지정된 확정 판매가 없습니다.</td></tr><?php endif; ?>
        <?php foreach ($top['v'] as $i => $t):
            $saleTotal = $sumCur['ok'] ? $sumCur['v']['sale'] : 0; ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= h($t['name']) ?></td>
            <td class="num"><?= (int)$t['cnt'] ?></td>
            <td class="num"><?= dash_won((int)$t['amt']) ?></td>
            <td class="num"><?= $saleTotal > 0 ? intdiv((int)$t['amt'] * 100, $saleTotal) . '%' : '-' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="dsh-foot">소매처럼 거래처가 지정되지 않은 전표는 포함되지 않습니다.</div>
    <?php elseif ($top['msg'] !== ''): ?><div class="dsh-fail"><?= h($top['msg']) ?></div><?php endif; ?>
  </section>

  <section class="dsh-card">
    <h2>최근 전표</h2>
    <?php if ($recent['ok']): ?>
      <table class="dsh-tbl">
        <thead><tr><th>일자</th><th>전표</th><th>거래처/고객</th><th class="num">금액</th><th>상태</th></tr></thead>
        <tbody>
        <?php if (!$recent['v']): ?><tr><td colspan="5">전표가 없습니다.</td></tr><?php endif; ?>
        <?php foreach ($recent['v'] as $t): ?>
          <tr>
            <td><?= h($t['trade_date']) ?></td>
            <td><a href="trade_view.php?id=<?= (int)$t['id'] ?>"><?= h($t['trade_no']) ?></a>
              <small class="muted"><?= $t['trade_type'] === 'sale' ? '판매' : '매입' ?></small></td>
            <td><?= h($t['who']) ?></td>
            <td class="num"><?= dash_won((int)$t['amt']) ?></td>
            <td><span class="badge <?= h((string)$t['status']) ?>"><?= h(status_label((string)$t['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php elseif ($recent['msg'] !== ''): ?><div class="dsh-fail"><?= h($recent['msg']) ?></div><?php endif; ?>
  </section>
</div>

<?php if ($colInfo['ok']): ?>
  <p class="dsh-foot">집계 기준: 확정 전표만 · <?= h($colInfo['v'][1]) ?> (erp_trades.<?= h($colInfo['v'][0]) ?>) · 임시·취소 전표 제외. 이 컬럼이 의도와 다르면 알려 주세요.</p>
<?php endif; ?>
<?php page_footer();
