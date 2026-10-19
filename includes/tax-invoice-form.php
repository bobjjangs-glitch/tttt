<?php
/* =====================================================================
   [v43] 전자세금계산서 표준 양식 렌더러 (공용)

   국세청 전자세금계산서(공급받는자보관용) 양식 그대로:
     · 상단 중앙 "전자세금계산서" + 우측 승인번호
     · 공급자 / 공급받는자 격자 (등록번호·종사업장·상호·성명·주소·업태·종목)
     · 작성일자(년/월/일 칸) / 공급가액·세액 (자릿수 칸 + 단위 표기)
     · 수정사유
     · 품목 명세 (월/일/품목/규격/수량/단가/공급가액/세액/비고 — 빈 칸 포함 4행)
     · 합계금액 + 수금납 (현금/수표/어음/외상미수금 + "이 금액을 영수(청구)함")
     · 하단 안내 문구 + 출력일시/출력자/IP

   사용처: 회원측 tax-invoice-view.php / admin/tax-invoice-print.php /
           admin/wholesale-sale-print.php [v43]
   ===================================================================== */

/** 금액 → 자릿수 칸 배열 (12칸: 천억~일) — 오른쪽 정렬, 빈 칸은 '' */
function tx_digit_cells(int $amount): array
{
    $units = ['천억','백억','십억','억','천만','백만','십만','만','천','백','십','일'];
    $digits = array_map('intval', str_split((string)abs($amount)));
    $pad = array_fill(0, 12 - count($digits), '');
    return [array_merge($pad, $digits), $units];
}

/** 자릿수 칸 그리드 HTML (값 칸 행 + 단위 라벨 행) */
function tx_digit_grid(int $amount): string
{
    [$cells, $units] = tx_digit_cells($amount);
    $html = '<table class="tx-digits"><tbody><tr>';
    foreach ($cells as $d) $html .= '<td>' . ($d === '' ? '' : $d) . '</td>';
    $html .= '</tr><tr class="tx-units">';
    foreach ($units as $u) $html .= '<td>' . $u . '</td>';
    return $html . '</tr></tbody></table>';
}

/** 날짜(YYYY-MM-DD) → [년4자리, 월2, 일2] */
function tx_date_parts(string $date): array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return [date('Y'), date('m'), date('d')];
    return [$m[1], $m[2], $m[3]];
}

/** 날짜 값 → 한 글자씩 칸으로 끊은 HTML */
function tx_date_boxes_html(string $v): string
{
    $out = '';
    foreach (str_split($v) as $ch) $out .= '<span class="tx-datebox">' . h($ch) . '</span>';
    return $out;
}

/**
 * 전자세금계산서 본문 렌더 (DOCTYPE 포함 완전한 문서를 echo)
 *
 * $opts = [
 *   'supplier'  => ['biz_number','biz_name','ceo','address','uptae','jongmok'],
 *   'buyer'     => 같은 키 (NULL 허용 — 빈 칸 출력),
 *   'approval_no' => 승인번호 (빈 값이면 '-' 표기),
 *   'write_date'  => 'YYYY-MM-DD' 작성일자,
 *   'items'     => [ ['mon'=>'09','day'=>'05','name'=>'','spec'=>'','qty'=>1,
 *                     'unit_price'=>0,'supply'=>0,'vat'=>0,'memo'=>''], ... ],
 *   'supply_total' => int, 'vat_total' => int, 'total_amount' => int,
 *   'pay_kind'  => 'cash'|'check'|'bill'|'credit'|null  (수금납 체크 — null이면 모두 빈 칸),
 *   'meta_printer' => 출력자명, 'meta_ip' => IP (기본 $_SERVER REMOTE_ADDR),
 *   'print_buttons' => true면 인쇄/닫기 버튼 표시,
 *   'back_url'  => 목록으로 대체 링크,
 *   'site_note' => true면 하단 안내에 당사 사이트(BASE_URL) 조회 문구 포함,
 * ]
 */
function render_tax_invoice_form(array $opts): void
{
    /* ---------- 값 준비 (heredoc 안에서는 함수 호출이 불가하므로 전부 미리 계산) ---------- */
    $s = array_merge([
        'biz_number' => '', 'biz_name' => '', 'ceo' => '', 'address' => '',
        'uptae' => '', 'jongmok' => '',
    ], $opts['supplier'] ?? []);
    $b = array_merge([
        'biz_number' => '', 'biz_name' => '', 'ceo' => '', 'address' => '',
        'uptae' => '', 'jongmok' => '',
    ], $opts['buyer'] ?? []);
    foreach (['biz_number','biz_name','ceo','address','uptae','jongmok'] as $k) {
        $s[$k] = h((string)($s[$k] ?? ''));
        $b[$k] = h((string)($b[$k] ?? ''));
    }

    $items = array_values($opts['items'] ?? []);
    $supplyTotal = (int)($opts['supply_total'] ?? 0);
    $vatTotal = (int)($opts['vat_total'] ?? 0);
    $totalAmount = (int)($opts['total_amount'] ?? ($supplyTotal + $vatTotal));
    $payKind = $opts['pay_kind'] ?? null;
    [$yy, $mm, $dd] = tx_date_parts($opts['write_date'] ?? date('Y-m-d'));
    $yyBox = tx_date_boxes_html($yy);
    $mmBox = tx_date_boxes_html($mm);
    $ddBox = tx_date_boxes_html($dd);
    $approvalNo = h(trim((string)($opts['approval_no'] ?? '')) ?: '-');
    $printer = h((string)($opts['meta_printer'] ?? ''));
    $ip = h((string)($opts['meta_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
    $printedAt = h(date('Y-m-d H:i:s'));
    $showBtns = !empty($opts['print_buttons']);
    $backUrl = (string)($opts['back_url'] ?? '');
    $siteNote = $opts['site_note'] ?? true;

    /* 품목 행은 최소 4행 — 실제 품목 뒤 빈 행으로 채움 */
    $rowsOut = '';
    for ($i = 0; $i < max(4, count($items)); $i++) {
        $it = $items[$i] ?? null;
        if ($it) {
            $rowsOut .= '<tr>'
                . '<td class="tx-c">' . h((string)($it['mon'] ?? '')) . '</td>'
                . '<td class="tx-c">' . h((string)($it['day'] ?? '')) . '</td>'
                . '<td class="tx-l">' . h((string)($it['name'] ?? '')) . '</td>'
                . '<td class="tx-c">' . h((string)($it['spec'] ?? '')) . '</td>'
                . '<td class="tx-c">' . h((string)($it['qty'] ?? '')) . '</td>'
                . '<td class="tx-r">' . number_format((int)($it['unit_price'] ?? 0)) . '</td>'
                . '<td class="tx-r">' . number_format((int)($it['supply'] ?? 0)) . '</td>'
                . '<td class="tx-r">' . number_format((int)($it['vat'] ?? 0)) . '</td>'
                . '<td class="tx-c">' . h((string)($it['memo'] ?? '')) . '</td>'
                . '</tr>';
        } else {
            $rowsOut .= '<tr>' . str_repeat('<td></td>', 9) . '</tr>';
        }
    }

    $amountGrid = tx_digit_grid($supplyTotal);
    $vatGrid = tx_digit_grid($vatTotal);
    $totalGrid = tx_digit_grid($totalAmount);

    $cb = fn(?string $k): string => ($payKind !== null && $payKind === $k) ? '✔' : '';
    $cbCash = $cb('cash'); $cbCheck = $cb('check'); $cbBill = $cb('bill'); $cbCredit = $cb('credit');

    $noteText = $siteNote
        ? '(*) 본 세금계산서는 부가가치세법 제32조에 의거 발행된 전자세금계산서이며, 당사 사이트(' . h(BASE_URL) . ')에서 조회 및 출력이 가능합니다.'
        : '(*) 본 세금계산서는 부가가치세법 제32조에 의거 발행된 전자세금계산서입니다.';
    $metaLine = '출력일시 : ' . $printedAt
        . ($printer !== '' ? ' · 출력자 : ' . $printer : '')
        . ($ip !== '' ? ' · IP : ' . $ip : '');

    $supplierRows = <<<HTML
        <tr>
          <th rowspan="4" class="tx-side">공급자</th>
          <th class="tx-k">등록번호</th><td colspan="2">{$s['biz_number']}</td>
          <th class="tx-k">종사업장</th><td></td>
        </tr>
        <tr><th class="tx-k">상호 (법인명)</th><td colspan="2">{$s['biz_name']}</td><th class="tx-k">성명</th><td colspan="2">{$s['ceo']}</td></tr>
        <tr><th class="tx-k">사업장 주소</th><td colspan="5" class="tx-addr">{$s['address']}</td></tr>
        <tr><th class="tx-k">업태</th><td>{$s['uptae']}</td><th class="tx-k">종목</th><td colspan="3">{$s['jongmok']}</td></tr>
HTML;

    $buyerRows = <<<HTML
        <tr>
          <th rowspan="4" class="tx-side">공급<br>받는자</th>
          <th class="tx-k">등록번호</th><td colspan="2">{$b['biz_number']}</td>
          <th class="tx-k">종사업장</th><td></td>
        </tr>
        <tr><th class="tx-k">상호 (법인명)</th><td colspan="2">{$b['biz_name']}</td><th class="tx-k">성명</th><td colspan="2">{$b['ceo']}</td></tr>
        <tr><th class="tx-k">사업장 주소</th><td colspan="5" class="tx-addr">{$b['address']}</td></tr>
        <tr><th class="tx-k">업태</th><td>{$b['uptae']}</td><th class="tx-k">종목</th><td colspan="3">{$b['jongmok']}</td></tr>
HTML;

    $standalone = !isset($opts['standalone']) ? true : (bool)$opts['standalone'];

    $css = <<<CSS
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Pretendard','Noto Sans KR','Apple SD Gothic Neo',sans-serif;background:#eef2f7;padding:20px;color:#111;}
  .tx-wrap{width:860px;max-width:100%;margin:0 auto;background:#fff;padding:26px 28px 22px;border:1px solid #cbd5e1;}
  .tx-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;}
  .tx-title{text-align:center;line-height:1.3;}
  .tx-title .t1{font-size:24px;font-weight:900;letter-spacing:10px;text-indent:10px;color:#0b3a8f;}
  .tx-title .t2{font-size:12px;font-weight:700;letter-spacing:2px;color:#3b5b9c;margin-top:2px;}
  .tx-approval{border:1.6px solid #2f5aa8;padding:5px 10px 7px;min-width:300px;}
  .tx-approval .lbl{font-size:11px;font-weight:800;letter-spacing:2px;color:#2f5aa8;border-bottom:1px solid #c9d8ee;padding-bottom:3px;}
  .tx-approval .no{font-size:15px;font-weight:800;letter-spacing:1px;padding-top:5px;font-family:'Menlo',monospace;}
  table.tx-main, table.tx-items, table.tx-digits{border-collapse:collapse;width:100%;}
  .tx-main th, .tx-main td, .tx-items th, .tx-items td{border:1px solid #2f5aa8;font-size:12px;padding:5px 7px;}
  .tx-main th.tx-side{background:#e8effb;font-weight:900;font-size:13px;width:64px;}
  .tx-main th.tx-k{background:#f3f7fd;font-weight:800;width:96px;font-size:11.5px;}
  .tx-main td{height:31px;white-space:nowrap;}
  .tx-main td.tx-addr{white-space:normal;}
  .tx-main tr.tx-date-row th, .tx-main tr.tx-amt-row th, .tx-main tr.tx-modify th{background:#f3f7fd;font-weight:800;font-size:11.5px;}
  .tx-main tr.tx-date-row th{width:64px;}
  .tx-main tr.tx-amt-row th{width:64px;}
  .tx-main tr.tx-amt-row th.tx-vat{width:46px;}
  .tx-datebox{display:inline-flex;width:20px;height:24px;border:1px solid #7f9cd0;margin:0 1.5px;align-items:center;justify-content:center;font-weight:800;font-size:13px;}
  .tx-date-sep{font-size:11px;font-weight:800;margin:0 5px;}
  table.tx-digits{table-layout:fixed;margin:0;}
  .tx-digits td{border:1px solid #7f9cd0;width:23px;height:22px;text-align:center;font-size:12.5px;font-weight:700;padding:0;}
  .tx-digits tr.tx-units td{border:none;height:13px;font-size:8.5px;color:#6b7c99;font-weight:600;padding-top:1px;}
  .tx-items{margin-top:12px;}
  .tx-items th{background:#e8effb;font-weight:800;font-size:11.5px;padding:6px 4px;}
  .tx-items td{height:27px;padding:4px 8px;}
  .tx-items .tx-c{text-align:center;}
  .tx-items .tx-l{text-align:left;}
  .tx-items .tx-r{text-align:right;}
  .tx-total{margin-top:12px;border:1px solid #2f5aa8;display:flex;}
  .tx-total .tx-total-amt{flex:1.1;padding:10px 14px;border-right:1px solid #2f5aa8;}
  .tx-total .lbl{font-size:11px;font-weight:800;letter-spacing:1px;color:#2f5aa8;}
  .tx-total .big{margin-top:4px;}
  .tx-total .tx-pay{flex:1;padding:8px 14px;display:flex;flex-direction:column;justify-content:center;gap:5px;}
  .tx-pay .pay-row{display:flex;gap:14px;flex-wrap:wrap;}
  .tx-pay .pay-k{font-size:12px;font-weight:700;display:flex;align-items:center;gap:3px;}
  .tx-pay .pay-k .tx-cb{display:inline-flex;width:14px;height:14px;border:1px solid #2f5aa8;align-items:center;justify-content:center;font-size:10px;}
  .tx-pay .pay-receipt{font-size:12px;font-weight:900;letter-spacing:1px;text-align:right;border-top:1px dashed #9db6e0;padding-top:5px;}
  .tx-note{margin-top:12px;border:1px solid #b9c9e8;padding:8px 12px;font-size:11px;color:#33507e;line-height:1.6;background:#f7faff;}
  .tx-meta{margin-top:8px;font-size:10.5px;color:#8395ad;letter-spacing:0.3px;}
  .tx-actions{width:860px;max-width:100%;margin:14px auto 0;display:flex;gap:8px;}
  .tx-btn{border:none;border-radius:8px;padding:11px 22px;font-size:14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}
  .tx-btn-print{background:#0b3a8f;color:#fff;}
  .tx-btn-back{background:#e2e8f0;color:#334155;}
  @media print{
    body{background:#fff;padding:0;}
    .tx-wrap{border:none;padding:6px;width:100%;}
    .tx-actions{display:none;}
  }
</style>
CSS;

    $bodyHtml = <<<HTML
<div class="tx-wrap">
  <div class="tx-head">
    <div style="width:300px;"></div>
    <div class="tx-title">
      <div class="t1">전자세금계산서</div>
      <div class="t2">(공급받는자보관용)</div>
    </div>
    <div class="tx-approval">
      <div class="lbl">승 인 번 호</div>
      <div class="no">{$approvalNo}</div>
    </div>
  </div>

  <table class="tx-main">
    <tbody>
      {$supplierRows}
      {$buyerRows}
      <tr class="tx-date-row">
        <th>작성일자</th>
        <td colspan="5">년 {$yyBox} <span class="tx-date-sep">월</span> {$mmBox} <span class="tx-date-sep">일</span> {$ddBox}</td>
      </tr>
      <tr class="tx-amt-row">
        <th>공급가액</th>
        <td>{$amountGrid}</td>
        <th class="tx-vat">세액</th>
        <td colspan="3">{$vatGrid}</td>
      </tr>
      <tr class="tx-modify">
        <th>수정사유</th>
        <td colspan="5"></td>
      </tr>
    </tbody>
  </table>

  <table class="tx-items">
    <thead>
      <tr>
        <th style="width:34px;">월</th><th style="width:34px;">일</th>
        <th>품목</th><th style="width:110px;">규격</th>
        <th style="width:48px;">수량</th><th style="width:90px;">단가</th>
        <th style="width:100px;">공급가액</th><th style="width:86px;">세액</th>
        <th style="width:60px;">비고</th>
      </tr>
    </thead>
    <tbody>
      {$rowsOut}
    </tbody>
  </table>

  <div class="tx-total">
    <div class="tx-total-amt">
      <div class="lbl">합계금액 (공급가액과 세액의 합계)</div>
      <div class="big">{$totalGrid}</div>
    </div>
    <div class="tx-pay">
      <div class="pay-row">
        <span class="pay-k"><span class="tx-cb">{$cbCash}</span>현금</span>
        <span class="pay-k"><span class="tx-cb">{$cbCheck}</span>수표</span>
        <span class="pay-k"><span class="tx-cb">{$cbBill}</span>어음</span>
        <span class="pay-k"><span class="tx-cb">{$cbCredit}</span>외상미수금</span>
      </div>
      <div class="pay-receipt">이 금액을 영수 (청구) 함</div>
    </div>
  </div>

  <div class="tx-note">{$noteText}</div>
  <div class="tx-meta">{$metaLine}</div>
</div>
HTML;

    /* 본문 뒤 버튼 */
    $btnHtml = '';
    if ($showBtns) {
        $btnHtml .= '<div class="tx-actions">'
            . '<button type="button" class="tx-btn tx-btn-print" onclick="window.print()">🖨️ 인쇄</button>';
        if ($backUrl !== '') {
            $btnHtml .= '<a class="tx-btn tx-btn-back" href="' . h($backUrl) . '" style="text-decoration:none;">목록으로</a>';
        } else {
            $btnHtml .= '<button type="button" class="tx-btn tx-btn-back" onclick="(function(){try{window.close();}catch(e){}setTimeout(function(){if(!window.closed)history.back();},200);})()">닫기</button>';
        }
        $btnHtml .= '</div>';
    }

    if ($standalone) {
        echo "<!DOCTYPE html>\n<html lang=\"ko\">\n<head>\n<meta charset=\"UTF-8\">\n<title>전자세금계산서</title>\n";
        echo $css;
        echo "\n</head>\n<body>\n";
        echo $bodyHtml;
        echo $btnHtml;
        echo "\n</body>\n</html>\n";
    } else {
        echo $css;
        echo "\n";
        echo $bodyHtml;
        echo $btnHtml;
    }
}

