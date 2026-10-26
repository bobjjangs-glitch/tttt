<?php
// inc/menu.php - 3단 메뉴: 대분류(상단) / 중분류(사이드바 그룹) / 소분류(사이드바 항목). 미실행 코드
// 전제: functions.php가 먼저 로드되어 있어야 함 (h, cfg, current_user 사용)

/**
 * 메뉴 정의.
 * 대분류: label, roles(선택), groups[]
 * 중분류(group): label, items[]
 * 소분류(item): label, file, query(선택), also(선택), soon(선택), roles(선택)
 *   file  : 이동할 PHP 파일
 *   query : 링크에 붙는 쿼리 + 현재 화면 판정에 사용
 *   also  : 같은 소분류로 취급할 부속 페이지 (등록/상세 화면)
 *   soon  : true면 아직 없는 화면 (클릭 불가, 회색)
 *   roles : 지정 시 해당 역할만 노출. 화면에서 숨기기만 하므로 각 페이지에서 권한 검사를 따로 해야 함
 */
function erp_menu(): array
{
    $w = ['admin', 'office'];   // 쓰기 가능 역할 (functions.php의 can_write()와 같아야 함)
    return [
        'me' => ['label' => '내정보', 'groups' => [
            ['label' => '계정', 'items' => [
                ['label' => '내 정보',       'file' => 'account.php'],
                ['label' => '비밀번호 변경', 'file' => 'account.php', 'query' => ['tab' => 'password']],
            ]],
        ]],
        'config' => ['label' => '환경설정', 'roles' => ['admin'], 'groups' => [
            ['label' => '기초 정보', 'items' => [
                ['label' => '엑셀 입출력', 'file' => 'excel.php'],
                ['label' => '회사 정보',   'file' => 'company.php', 'soon' => true],
            ]],
            ['label' => '사용자·보안', 'items' => [
                ['label' => '사용자 관리', 'file' => 'users.php'],
                ['label' => '감사 로그',   'file' => 'audit_log.php'],
            ]],
        ]],
        'acc' => ['label' => '회계', 'groups' => [
            ['label' => '판매', 'items' => [
                ['label' => '판매전표', 'file' => 'trades.php', 'query' => ['type' => 'sale'],
                 'also' => ['trade_view.php', 'trade_form.php']],
                ['label' => '소매 매출', 'file' => 'trades.php', 'query' => ['type' => 'sale', 'channel' => 'retail']],
                ['label' => '도매 매출', 'file' => 'trades.php', 'query' => ['type' => 'sale', 'channel' => 'wholesale']],
                ['label' => '판매 입력', 'file' => 'trade_form.php', 'query' => ['type' => 'sale'], 'roles' => $w],
            ]],
            ['label' => '구매', 'items' => [
                ['label' => '매입전표', 'file' => 'trades.php', 'query' => ['type' => 'purchase']],
                ['label' => '매입 입력', 'file' => 'trade_form.php', 'query' => ['type' => 'purchase'], 'roles' => $w],
            ]],
            ['label' => '거래처·원장', 'items' => [
              ['label' => '거래처 원장·미수금', 'file' => 'ledger.php'],
['label' => '입금 등록',          'file' => 'payments.php'],
['label' => '거래처 등록/조회', 'file' => 'partners.php', 'also' => ['partners_excel.php']],

['label' => '자금 계좌', 'file' => 'fund_accounts.php', 'roles' => ['admin']],

            ]],
        ]],
        'tax' => ['label' => '세무', 'groups' => [
            ['label' => '세금계산서', 'items' => [
                ['label' => '발행',        'file' => 'tax_issue.php',    'soon' => true],
                ['label' => '발행 요청건', 'file' => 'tax_requests.php', 'soon' => true],
            ]],
            ['label' => '현금영수증', 'items' => [
                ['label' => '현금영수증 발행', 'file' => 'cashbill.php', 'soon' => true],
            ]],
            ['label' => '신고 자료', 'items' => [
                ['label' => '부가세 자료', 'file' => 'vat_report.php', 'soon' => true],
            ]],
        ]],
        'stock' => ['label' => '재고', 'groups' => [
            ['label' => '재고 현황', 'items' => [
                ['label' => '재고 현황', 'file' => 'stock.php'],
                ['label' => '재고 이력', 'file' => 'stock_ledger.php', 'soon' => true],
            ]],
            ['label' => '기초 등록', 'items' => [
                ['label' => '품목', 'file' => 'items.php'],
                ['label' => '분류', 'file' => 'categories.php'],
            ]],
            ['label' => '입출고', 'items' => [
                ['label' => '입고 대기', 'file' => 'inbox.php',        'soon' => true],
                ['label' => '재고 조정', 'file' => 'stock_adjust.php', 'soon' => true],
            ]],
        ]],
    ];
}

function erp_menu_allowed(array $node, ?string $role): bool
{
    return empty($node['roles']) || ($role !== null && in_array($role, $node['roles'], true));
}

function erp_menu_url(array $it): string
{
    $q = $it['query'] ?? [];
    return $it['file'] . ($q ? '?' . http_build_query($q) : '');
}

/** 대분류를 눌렀을 때 갈 주소: 첫 번째로 실제 있는 화면. 없으면 index.php?menu=키 */
function erp_menu_first_url(string $mk, array $m, ?string $role): string
{
    foreach ($m['groups'] as $g) {
        foreach ($g['items'] as $it) {
            if (empty($it['soon']) && erp_menu_allowed($it, $role)) {
                return erp_menu_url($it);
            }
        }
    }
    return 'index.php?menu=' . urlencode($mk);
}

/**
 * 현재 요청이 어느 대분류/소분류인지 판정.
 * 반환: [대분류키|null, [대분류키, 그룹번호, 항목번호]|null]
 */
function erp_menu_resolve(array $menu): array
{
    $path = parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
    $file = basename((string)$path);
    $main = null;
    $sub  = null;
    $best = 0;

    foreach ($menu as $mk => $m) {
        foreach ($m['groups'] as $gi => $g) {
            foreach ($g['items'] as $ii => $it) {
                if (!empty($it['soon'])) {
                    continue;
                }
                $files = array_merge([$it['file']], $it['also'] ?? []);
                if (!in_array($file, $files, true)) {
                    continue;
                }
                if ($main === null) {
                    $main = $mk;                          // 파일만 맞아도 대분류는 확정
                }
                if ($it['file'] !== $file) {
                    continue;                             // 부속 페이지는 소분류를 강조하지 않음
                }
                $score = 1;
                $ok    = true;
                foreach (($it['query'] ?? []) as $k => $v) {   // 쿼리가 더 많이 맞는 항목이 우선
                    if ((string)($_GET[$k] ?? '') !== (string)$v) {
                        $ok = false;
                        break;
                    }
                    $score++;
                }
                if ($ok && $score > $best) {
                    $best = $score;
                    $main = $mk;
                    $sub  = [$mk, $gi, $ii];
                }
            }
        }
    }
    // 준비중뿐인 대분류는 index.php?menu=키 로 들어온다
    if ($main === null && isset($_GET['menu']) && is_string($_GET['menu']) && isset($menu[$_GET['menu']])) {
        $main = $_GET['menu'];
    }
    return [$main, $sub];
}

/** 상단 대분류 줄 */
function erp_render_nav(): string
{
    $menu = erp_menu();
    $u    = function_exists('current_user') ? current_user() : null;
    $role = $u['role'] ?? null;
    [$mainKey] = erp_menu_resolve($menu);

    $o  = '<nav class="gnb"><div class="gnb-in">';
    $o .= '<a class="gnb-brand" href="index.php">' . h((string)cfg('app.name', 'TireERP')) . '</a>';
    $o .= '<ul class="gnb-main">';
    foreach ($menu as $mk => $m) {
        if (!erp_menu_allowed($m, $role)) {
            continue;
        }
        $on = ($mk === $mainKey) ? ' class="on"' : '';
        $o .= '<li><a' . $on . ' href="' . h(erp_menu_first_url($mk, $m, $role)) . '">' . h($m['label']) . '</a></li>';
    }
    $o .= '</ul>';
    if ($u) {
        $o .= '<span class="gnb-user">' . h((string)($u['name'] ?? '')) . '님</span>';
    }
    $o .= '</div></nav>';
    return $o;
}

/** 왼쪽 사이드바: 현재 대분류의 중분류(그룹) + 소분류(항목) */
function erp_render_side(): string
{
    $menu = erp_menu();
    $u    = function_exists('current_user') ? current_user() : null;
    $role = $u['role'] ?? null;
    $showSoon = (bool)cfg('app.show_soon', true);
    [$mainKey, $sub] = erp_menu_resolve($menu);

    if ($mainKey === null || !isset($menu[$mainKey]) || !erp_menu_allowed($menu[$mainKey], $role)) {
        return '';
    }
    $m = $menu[$mainKey];
    $o = '<aside class="sidebar no-print"><div class="side-title">' . h($m['label']) . '</div>';

    foreach ($m['groups'] as $gi => $g) {
        $rows = '';
        foreach ($g['items'] as $ii => $it) {
            if (!erp_menu_allowed($it, $role)) {
                continue;
            }
            if (!empty($it['soon'])) {
                if ($showSoon) {
                    $rows .= '<span class="side-soon">' . h($it['label']) . '<em>준비중</em></span>';
                }
                continue;
            }
            $on = ($sub && $sub[0] === $mainKey && $sub[1] === $gi && $sub[2] === $ii) ? ' class="on"' : '';
            $rows .= '<a' . $on . ' href="' . h(erp_menu_url($it)) . '">' . h($it['label']) . '</a>';
        }
        if ($rows === '') {
            continue;   // 보여 줄 항목이 없는 그룹은 숨김
        }
        $o .= '<details class="side-group" data-key="' . h($mainKey . '.' . (string)$g['label']) . '" open>'
            . '<summary>' . h((string)$g['label']) . '</summary>' . $rows . '</details>';
    }
    $o .= '</aside>';
    $o .= '<script>(function(){try{var ds=document.querySelectorAll(".side-group");'
        . 'for(var i=0;i<ds.length;i++){(function(d){var k="erpside:"+d.getAttribute("data-key");'
        . 'if(localStorage.getItem(k)==="0"&&!d.querySelector("a.on")){d.removeAttribute("open");}'
        . 'd.addEventListener("toggle",function(){localStorage.setItem(k,d.open?"1":"0");});})(ds[i]);}}catch(e){}})();</script>';
    return $o;
}
