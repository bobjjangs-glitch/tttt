<?php
// 분류 트리(대·중·소) 왼쪽 패널 — 이카운트식. inc/functions.php(h) 가 먼저 로드되어 있어야 합니다.
// 사용: cat_tree_open(...) ... 화면 내용 ... cat_tree_close();

/** 선택한 분류 + 모든 하위 분류 id */
function cat_descendant_ids(PDO $pdo, int $rootId): array
{
    $children = [];
    foreach ($pdo->query('SELECT id, parent_id FROM categories')->fetchAll() as $c) {
        if ($c['parent_id'] !== null) {
            $children[(int)$c['parent_id']][] = (int)$c['id'];
        }
    }
    $ids   = [$rootId];
    $seen  = [$rootId => true];
    $queue = [$rootId];
    while ($queue) {
        $cur = array_shift($queue);
        foreach ($children[$cur] ?? [] as $cid) {
            if (!isset($seen[$cid])) {
                $seen[$cid] = true;
                $ids[]      = $cid;
                $queue[]    = $cid;
            }
        }
    }
    return $ids;
}

function cat_tree_open(PDO $pdo, string $page, int $selected = 0, bool $stockOnly = false): void
{
    $byId = [];
    foreach ($pdo->query('SELECT id, parent_id, name, is_active FROM categories ORDER BY sort_order, id')->fetchAll() as $r) {
        $byId[(int)$r['id']] = $r;
    }
    $children = [];
    foreach ($byId as $id => $r) {
        $pid = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
        if ($pid === $id || ($pid !== 0 && !isset($byId[$pid]))) {
            $pid = 0; // 부모를 못 찾는 분류는 최상위로
        }
        $children[$pid][] = $id;
    }

    // 분류별 품목 수 (하위 분류 포함 합계)
    $sql = 'SELECT category_id, COUNT(*) AS c FROM items'
         . ($stockOnly ? ' WHERE is_stock_item = 1' : '') . ' GROUP BY category_id';
    $own = [];
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $own[(int)$r['category_id']] = (int)$r['c'];
    }
    $total = [];
    $calc  = function (int $id, int $d) use (&$calc, &$total, $children, $own): int {
        $s = $own[$id] ?? 0;
        if ($d < 8) {
            foreach ($children[$id] ?? [] as $c) {
                $s += $calc($c, $d + 1);
            }
        }
        return $total[$id] = $s;
    };
    foreach ($children[0] ?? [] as $rid) {
        $calc($rid, 0);
    }

    // 선택한 분류의 윗대 분류는 펼쳐서 보여 줌
    $open = [];
    $cur  = $selected;
    for ($i = 0; $i < 8 && $cur > 0 && isset($byId[$cur]); $i++) {
        $open[$cur] = true;
        $p   = $byId[$cur]['parent_id'];
        $cur = $p === null ? 0 : (int)$p;
    }

    $render = function (int $pid, int $depth) use (&$render, $children, $byId, $total, $open, $selected, $page): void {
        foreach ($children[$pid] ?? [] as $id) {
            $r    = $byId[$id];
            $cls  = 'ct-a' . ($id === $selected ? ' on' : '') . ((int)$r['is_active'] ? '' : ' muted');
            $a    = '<a class="' . $cls . '" href="' . h($page . '?cat=' . $id) . '">' . h($r['name'])
                  . ' <span class="ct-n">' . (int)($total[$id] ?? 0) . '</span></a>';
            if (!empty($children[$id]) && $depth < 5) {
                echo '<details class="ct-node"' . (isset($open[$id]) ? ' open' : '') . '><summary>' . $a . '</summary><div class="ct-sub">';
                $render($id, $depth + 1);
                echo '</div></details>';
            } else {
                echo '<div class="ct-leaf">' . $a . '</div>';
            }
        }
    };
    ?>
<style>
.ct-wrap{display:flex;gap:12px;align-items:flex-start}
.ct-tree{width:210px;flex:none;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:8px;font-size:13px;max-height:calc(100vh - 170px);overflow:auto;position:sticky;top:8px}
.ct-main{flex:1;min-width:0}
.ct-head{font-weight:700;padding:2px 4px 6px;border-bottom:1px solid #e5e7eb;margin-bottom:4px}
.ct-tree a{color:#1f2937;text-decoration:none}
.ct-a{display:block;padding:5px 6px;border-radius:4px}
.ct-a:hover{background:#f3f4f6;text-decoration:none}
.ct-a.on{background:#eaf1fe;color:#2563eb;font-weight:700}
.ct-a.muted{color:#9ca3af}
.ct-n{color:#9ca3af;font-size:11px;font-weight:400}
.ct-node>summary{cursor:pointer;list-style-position:inside}
.ct-node>summary>a{display:inline-block;width:calc(100% - 18px);vertical-align:middle}
.ct-sub{margin-left:14px;border-left:1px solid #e5e7eb;padding-left:4px}
.ct-leaf{margin-left:18px}
@media (max-width:800px){.ct-wrap{display:block}.ct-tree{width:auto;position:static;max-height:240px;margin-bottom:12px}}
@media print{.ct-tree{display:none!important}.ct-wrap{display:block}}
</style>
<div class="ct-wrap">
  <aside class="ct-tree no-print">
    <div class="ct-head">분류</div>
    <a class="ct-a<?= $selected === 0 ? ' on' : '' ?>" href="<?= h($page) ?>">전체 <span class="ct-n"><?= (int)array_sum($own) ?></span></a>
    <?php $render(0, 0); ?>
  </aside>
  <div class="ct-main">
    <?php
}

function cat_tree_close(): void
{
    echo '</div></div>';
}
