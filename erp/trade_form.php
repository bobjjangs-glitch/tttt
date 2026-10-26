<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
require_write();
$pdo = db();

// 외상 거래처에서 현금·이체·카드로 바로 받는 건을 허용할지 여부.
// 거래처 원장(lg_sum)이 결제수단을 반영하도록 바꾸고 검증한 뒤에만 true 로 바꾸세요.
const TF_CREDIT_PARTNER_PAID_NOW = false;
// 단가·정가 상한(원). 수량(1/1000 단위) × 단가 상한(줄 금액 약 9조 원). 서버·브라우저 계산이 어긋나지 않게 하는 안전장치.
const TF_MAX_PRICE = 100000000000;
const TF_MAX_AMOUNT_MILLI = 9000000000000000;

/** "12.5", "12.5%", "" -> 0.01% 단위 정수(1250). 형식 오류·100 초과면 null */
function tf_dc_parse(string $s): ?int
{
    $s = str_replace([' ', '%', ','], '', trim($s));
    if ($s === '') {
        return 0;
    }
    if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    $v = (int)$m[1] * 100 + (isset($m[2]) ? (int)str_pad($m[2], 2, '0', STR_PAD_RIGHT) : 0);
    return $v <= 10000 ? $v : null;
}

/** 1250 -> "12.5", 0 -> "" */
function tf_dc_fmt(int $v): string
{
    if ($v <= 0) {
        return '';
    }
    $i = intdiv($v, 100);
    $f = $v % 100;
    return $f === 0 ? (string)$i : rtrim(sprintf('%d.%02d', $i, $f), '0');
}

$PAY = ['cash' => '현금', 'transfer' => '계좌이체', 'card' => '카드', 'credit' => '외상'];
$PAID_NOW = ['cash', 'transfer', 'card'];
$ACCT_KINDS = ['bank' => '은행', 'cash' => '현금', 'card' => '카드', 'mall' => '쇼핑몰', 'other' => '기타'];

if (is_post()) {
    $id   = in_int($_POST, 'id');
    $type = in_str($_POST, 'type', 10);
} else {
    $id   = in_int($_GET, 'id');
    $type = in_str($_GET, 'type', 10);
}

$trade = null;
$initLines = [];
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM trades WHERE id = ?');
    $st->execute([$id]);
    $trade = $st->fetch();
    if (!$trade) {
        http_response_code(404);
        exit('전표를 찾을 수 없습니다.');
    }
    if ($trade['status'] !== 'draft') {
        flash('임시 전표만 수정할 수 있습니다.', 'err');
        redirect('trade_view.php?id=' . $id);
    }
    $type = $trade['trade_type'];
}
if (!in_array($type, ['sale', 'purchase'], true)) {
    $type = 'sale';
}

// 외상(is_credit) 컬럼 / 전표 계좌(account_id) 컬럼 존재 확인. 실패해도 화면은 계속 동작해야 한다.
$hasCredit = false;
try {
    $hasCredit = (bool)$pdo->query("SHOW COLUMNS FROM erp_partners LIKE 'is_credit'")->fetch();
} catch (Throwable $e) {
    error_log('[ERP] trade_form credit column check: ' . $e->getMessage());
}
$hasAcct = false;
try {
    $hasAcct = (bool)$pdo->query("SHOW COLUMNS FROM erp_trades LIKE 'account_id'")->fetch();
    if ($hasAcct) {
        $pdo->query('SELECT 1 FROM erp_fund_accounts LIMIT 1')->fetch();
    }
} catch (Throwable $e) {
    $hasAcct = false;
    error_log('[ERP] trade_form account column check: ' . $e->getMessage());
}

// 줄 DC율 컬럼(trade_columns_setup.php 로 추가) 4개가 모두 있어야 DC 기능을 켠다.
$hasDc = false;
try {
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM erp_trade_lines')->fetchAll() as $c) {
        $have[(string)$c['Field']] = true;
    }
    $hasDc = isset($have['item_code'], $have['pattern_name'], $have['list_price'], $have['dc_rate']);
} catch (Throwable $e) {
    error_log('[ERP] trade_form dc column check: ' . $e->getMessage());
}
$isAdmin = ((current_user()['role'] ?? '') === 'admin');

$curAcctId = (int)($trade['account_id'] ?? 0);
$accounts = [];
if ($hasAcct) {
    try {
        $st = $pdo->prepare(
            'SELECT id, name, kind, is_active FROM erp_fund_accounts WHERE is_active = 1 OR id = ? ORDER BY is_active DESC, sort_no, id'
        );
        $st->execute([$curAcctId]);
        $accounts = $st->fetchAll();
    } catch (Throwable $e) {
        $hasAcct = false;
        error_log('[ERP] trade_form accounts: ' . $e->getMessage());
    }
}
$activeAcctCount = 0;
foreach ($accounts as $a) {
    if ((int)$a['is_active'] === 1) {
        $activeAcctCount++;
    }
}

$hdr = [
    'trade_date'    => $trade['trade_date'] ?? date('Y-m-d'),
    'channel'       => $trade['channel'] ?? 'retail',
    'partner_id'    => (int)($trade['partner_id'] ?? 0),
    'customer_name' => $trade['customer_name'] ?? '',
    'vehicle_no'    => $trade['vehicle_no'] ?? '',
    'memo'          => $trade['memo'] ?? '',
    'pay_method'    => (string)($trade['pay_method'] ?? ''),
    'account_id'    => $curAcctId,
];
if ($trade) {
    $st = $pdo->prepare(
        'SELECT item_id, qty, unit_price, memo' . ($hasDc ? ', list_price, dc_rate' : '')
        . ' FROM trade_lines WHERE trade_id = ? ORDER BY line_no'
    );
    $st->execute([$id]);
    foreach ($st as $l) {
        $lp = $hasDc ? (int)($l['list_price'] ?? 0) : 0;
        $initLines[] = [
            'item_id'    => (int)$l['item_id'],
            'qty'        => fmt_qty($l['qty']),
            'unit_price' => (string)$l['unit_price'],
            'list_price' => $lp > 0 ? (string)$lp : '',
            'dc_rate'    => $hasDc ? tf_dc_fmt((int)($l['dc_rate'] ?? 0)) : '',
            'memo'       => $l['memo'],
        ];
    }
}

if (is_post()) {
    csrf_check();
    $act = in_str($_POST, 'act', 10);
    $errors = [];

    $hdr['trade_date']    = in_str($_POST, 'trade_date', 10);
    $hdr['channel']       = $type === 'sale' ? in_str($_POST, 'channel', 10) : '';
    $hdr['partner_id']    = in_int($_POST, 'partner_id');
    $hdr['customer_name'] = in_str($_POST, 'customer_name', 100);
    $hdr['vehicle_no']    = strtoupper(preg_replace('/[\s\-]+/', '', in_str($_POST, 'vehicle_no', 20)));
    $hdr['memo']          = in_str($_POST, 'memo', 500);
    $hdr['pay_method']    = in_str($_POST, 'pay_method', 10);
    $hdr['account_id']    = in_int($_POST, 'account_id');

    if (!valid_date($hdr['trade_date'])) {
        $errors[] = '일자가 올바르지 않습니다.';
    }
    if ($type === 'sale' && !in_array($hdr['channel'], ['retail', 'wholesale'], true)) {
        $errors[] = '매출구분(소매/도매)을 선택하세요.';
    }
    $needPartner = $type === 'purchase' || $hdr['channel'] === 'wholesale';
    if ($needPartner && $hdr['partner_id'] <= 0) {
        $errors[] = '매입·도매 전표는 거래처를 선택해야 합니다.';
    }

    // 거래처: 존재·사용 여부 + 판매/매입 유형 일치 + 외상 여부
    $pInfo = null;
    if ($hdr['partner_id'] > 0) {
        $st = $pdo->prepare('SELECT partner_type' . ($hasCredit ? ', is_credit' : '') . ' FROM partners WHERE id = ? AND is_active = 1');
        $st->execute([$hdr['partner_id']]);
        $pInfo = $st->fetch();
        if (!$pInfo) {
            $errors[] = '거래처가 존재하지 않거나 사용 중지 상태입니다.';
        } else {
            $okTypes = $type === 'purchase' ? ['supplier', 'both'] : ['customer', 'both'];
            if (!in_array($pInfo['partner_type'], $okTypes, true)) {
                $errors[] = $type === 'purchase'
                    ? '선택한 거래처는 매입처가 아닙니다.'
                    : '선택한 거래처는 매출처가 아닙니다.';
            }
        }
    }
    $partnerCredit = $pInfo && $hasCredit && (int)($pInfo['is_credit'] ?? 0) === 1;

    // 결제수단
    $pm = $hdr['pay_method'];
    if ($pm !== '' && !isset($PAY[$pm])) {
        $errors[] = '결제수단이 올바르지 않습니다.';
        $pm = '';
    } elseif ($type === 'sale') {
        if ($pm === 'credit' && !$partnerCredit) {
            $errors[] = '외상은 외상 거래처로 지정된 거래처에서만 선택할 수 있습니다.';
        }
        if ($partnerCredit && in_array($pm, $PAID_NOW, true) && !TF_CREDIT_PARTNER_PAID_NOW) {
            $errors[] = '외상 거래처의 판매는 결제수단을 "외상"으로 저장하세요. 현금으로 받은 돈은 입금등록에서 처리합니다.';
        }
    }
    if ($act === 'confirm' && $pm === '') {
        $errors[] = '확정하려면 결제수단을 선택하세요.';
    }

    // 계좌: 현금·이체·카드일 때만 의미가 있다
    $acct = $hdr['account_id'];
    $needAcct = in_array($pm, $PAID_NOW, true);
    if (!$needAcct || !$hasAcct) {
        $acct = 0;
    }
    if ($acct > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM erp_fund_accounts WHERE id = ? AND (is_active = 1 OR id = ?)');
        $st->execute([$acct, $curAcctId]);
        if (!(int)$st->fetchColumn()) {
            $errors[] = '선택한 계좌를 사용할 수 없습니다.';
            $acct = 0;
        }
    }
    if ($act === 'confirm' && $hasAcct && $needAcct && $acct <= 0 && $activeAcctCount > 0) {
        $errors[] = '확정하려면 ' . ($type === 'sale' ? '입금' : '출금') . ' 계좌를 선택하세요.';
    }
    $hdr['account_id'] = $acct;

    // 라인 파싱: 화면 값은 신뢰하지 않고 품목명·과세구분·금액을 서버가 다시 구한다.
    $arr = function (string $k): array {
        return is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
    };
    $sv = function ($v): string {
        return is_scalar($v) ? (string)$v : '';
    };
    $pIds = $arr('item_id'); $pQty = $arr('qty'); $pPrice = $arr('unit_price'); $pMemo = $arr('memo');
    $pList = $arr('list_price'); $pDc = $arr('dc_rate');
    $initLines = [];
    $want = [];
    foreach ($pIds as $i => $iid) {
        $iid = is_scalar($iid) ? (int)$iid : 0;
        if ($iid <= 0) {
            continue;
        }
        $initLines[] = [
            'item_id'    => $iid,
            'qty'        => $sv($pQty[$i] ?? ''),
            'unit_price' => $sv($pPrice[$i] ?? ''),
            'list_price' => $sv($pList[$i] ?? ''),
            'dc_rate'    => $sv($pDc[$i] ?? ''),
            'memo'       => $sv($pMemo[$i] ?? ''),
        ];
        $want[$iid] = true;
    }
    if (!$initLines) {
        $errors[] = '품목을 한 개 이상 추가하세요.';
    }
    $itemMap = [];
    if ($want) {
        $ids = array_keys($want);
        $st = $pdo->prepare(
            'SELECT id, item_code, name, spec, pattern_name, tax_type, is_active FROM items WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $st->execute($ids);
        foreach ($st as $r) {
            $itemMap[(int)$r['id']] = $r;
        }
    }

    $lines = [];
    $supply = 0;
    $taxable = 0;
    foreach ($initLines as $n => $l) {
        $no = $n + 1;
        $it = $itemMap[$l['item_id']] ?? null;
        $qm = qty_to_milli($l['qty']);
        $price = parse_won($l['unit_price']);
        if (!$it || !(int)$it['is_active']) {
            $errors[] = $no . '번 줄: 사용할 수 없는 품목입니다.';
            continue;
        }
        if ($qm === null || $qm <= 0) {
            $errors[] = $no . '번 줄: 수량은 0보다 큰 숫자(소수 3자리까지)여야 합니다.';
            continue;
        }
        if ($price === null || $price > TF_MAX_PRICE) {
            $errors[] = $no . '번 줄: 단가는 0 이상 1,000억 이하의 정수(원)여야 합니다.';
            continue;
        }
        if ($price > 0 && $qm > intdiv(TF_MAX_AMOUNT_MILLI, $price)) {
            $errors[] = $no . '번 줄: 금액이 너무 큽니다. 수량 또는 단가를 확인하세요.';
            continue;
        }

        // 정가 · DC율 (DC 컬럼이 있을 때만)
        $listStore = 0;
        $dcStore = 0;
        if ($hasDc) {
            $listIn = parse_won($l['list_price']);
            $dcIn = tf_dc_parse($l['dc_rate']);
            if ($listIn === null || $listIn > TF_MAX_PRICE) {
                $errors[] = $no . '번 줄: 정가는 0 이상 1,000억 이하의 정수(원)여야 합니다.';
                continue;
            }
            if ($dcIn === null) {
                $errors[] = $no . '번 줄: DC율은 0~100 사이 숫자(소수 둘째 자리까지)여야 합니다.';
                continue;
            }
            $listStore = $listIn;
            if ($listIn > 0 && $price < $listIn) {
                // DC율로 계산한 단가가 입력한 단가와 같으면 입력한 DC율을, 다르면(단가를 직접 고친 경우) 실효 DC율을 기록한다.
                $calc = intdiv($listIn * (10000 - $dcIn) + 5000, 10000);
                $dcStore = ($calc === $price)
                    ? $dcIn
                    : intdiv(($listIn - $price) * 10000 + intdiv($listIn, 2), $listIn);
            }
        }

        $amount = line_amount($qm, $price);
        $supply += $amount;
        if ($it['tax_type'] === 'taxable') {
            $taxable += $amount;
        }
        $lines[] = [
            'line_no' => $no, 'item_id' => $l['item_id'], 'item_name' => $it['name'], 'spec' => $it['spec'],
            'tax_type' => $it['tax_type'], 'qty' => milli_to_str($qm), 'unit_price' => $price, 'amount' => $amount,
            'memo' => mb_substr($l['memo'], 0, 200),
            'item_code' => mb_substr((string)$it['item_code'], 0, 40),
            'pattern_name' => mb_substr((string)$it['pattern_name'], 0, 80),
            'list_price' => $listStore, 'dc_rate' => $dcStore,
        ];
    }
    $vat = vat_of($taxable);
    $total = $supply + $vat;

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $uid = (int)current_user()['id'];
            $pidVal = $hdr['partner_id'] > 0 ? $hdr['partner_id'] : null;
            $chVal = $type === 'sale' ? $hdr['channel'] : null;
            $pmVal = $pm !== '' ? $pm : null;
            $acctVal = $acct > 0 ? $acct : null;

            if ($id > 0) {
                $st = $pdo->prepare('SELECT * FROM trades WHERE id = ? FOR UPDATE');
                $st->execute([$id]);
                $t = $st->fetch();
                if (!$t || $t['status'] !== 'draft') {
                    throw new RuntimeException('임시 전표만 수정할 수 있습니다.');
                }
                $set = [
                    'channel' => $chVal, 'trade_date' => $hdr['trade_date'], 'partner_id' => $pidVal,
                    'customer_name' => $hdr['customer_name'], 'vehicle_no' => $hdr['vehicle_no'],
                    'supply_amount' => $supply, 'vat_amount' => $vat, 'total_amount' => $total,
                    'memo' => $hdr['memo'], 'pay_method' => $pmVal,
                ];
                if ($hasAcct) {
                    $set['account_id'] = $acctVal;
                }
                $set['updated_at'] = $now;
                $sql = 'UPDATE trades SET ' . implode(', ', array_map(function ($c) { return $c . ' = ?'; }, array_keys($set))) . ' WHERE id = ?';
                $pdo->prepare($sql)->execute(array_merge(array_values($set), [$id]));
                $pdo->prepare('DELETE FROM trade_lines WHERE trade_id = ?')->execute([$id]);
            } else {
                $prefix = $type === 'sale' ? 'SL' : 'PU';
                $seq = next_no($prefix, $hdr['trade_date']);
                $tradeNo = sprintf('%s-%s-%04d', $prefix, str_replace('-', '', $hdr['trade_date']), $seq);
                $row = [
                    'trade_no' => $tradeNo, 'trade_type' => $type, 'channel' => $chVal, 'pay_method' => $pmVal,
                ];
                if ($hasAcct) {
                    $row['account_id'] = $acctVal;
                }
                $row += [
                    'trade_date' => $hdr['trade_date'], 'partner_id' => $pidVal, 'customer_name' => $hdr['customer_name'],
                    'vehicle_no' => $hdr['vehicle_no'], 'supply_amount' => $supply, 'vat_amount' => $vat,
                    'total_amount' => $total, 'status' => 'draft', 'memo' => $hdr['memo'],
                    'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now,
                ];
                $sql = 'INSERT INTO trades (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($row));
                $id = (int)$pdo->lastInsertId();
            }

            $cols = ['trade_id', 'line_no', 'item_id', 'item_name', 'spec', 'tax_type', 'qty', 'unit_price', 'amount', 'memo'];
            if ($hasDc) {
                $cols = array_merge($cols, ['item_code', 'pattern_name', 'list_price', 'dc_rate']);
            }
            $ins = $pdo->prepare(
                'INSERT INTO trade_lines (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            );
            foreach ($lines as $l) {
                $vals = [$id, $l['line_no'], $l['item_id'], $l['item_name'], $l['spec'], $l['tax_type'], $l['qty'], $l['unit_price'], $l['amount'], $l['memo']];
                if ($hasDc) {
                    $vals = array_merge($vals, [$l['item_code'], $l['pattern_name'], $l['list_price'], $l['dc_rate']]);
                }
                $ins->execute($vals);
            }
            audit('trade_save', 'trade', $id, 'total=' . $total . ' pay=' . ($pm !== '' ? $pm : '-') . ' acct=' . ($acct > 0 ? $acct : '-'));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!($e instanceof RuntimeException)) {
                throw $e;
            }
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        if ($act === 'confirm') {
            try {
                confirm_trade($id, (int)current_user()['id']);
                flash('전표를 확정했습니다. 재고가 반영되었습니다.');
            } catch (RuntimeException $e) {
                flash('임시 저장은 되었지만 확정하지 못했습니다: ' . $e->getMessage(), 'err');
            }
        } else {
            flash('임시 저장했습니다. 확정 전에는 재고가 움직이지 않습니다.');
        }
        redirect('trade_view.php?id=' . $id);
    }
    foreach ($errors as $er) {
        flash($er, 'err');
    }
}

// ----- 화면 데이터 (품목·거래처 목록은 싣지 않고 trade_api.php 로 검색한다) -----
$cats = [];
foreach ($pdo->query('SELECT id, parent_id, name FROM categories WHERE is_active = 1 ORDER BY sort_order, id') as $r) {
    $cats[] = ['id' => (int)$r['id'], 'p' => (int)$r['parent_id'], 'n' => $r['name']];
}

// 이미 담긴 줄(수정 화면, 저장 실패 후 재표시)의 품목 정보
$lineInfo = [];
if ($initLines) {
    $ids = [];
    foreach ($initLines as $l) {
        $ids[(int)$l['item_id']] = true;
    }
    $ids = array_keys($ids);
    $st = $pdo->prepare(
        'SELECT id, item_code, name, spec, pattern_name, tax_type, is_active FROM erp_items WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $st->execute($ids);
    foreach ($st as $r) {
        $lineInfo[(int)$r['id']] = $r;
    }
}
$jsLines = [];
foreach ($initLines as $l) {
    $r = $lineInfo[(int)$l['item_id']] ?? null;
    $jsLines[] = [
        'item_id'    => (int)$l['item_id'],
        'code'       => $r['item_code'] ?? '',
        'name'       => $r['name'] ?? ('품목 #' . (int)$l['item_id']),
        'spec'       => $r['spec'] ?? '',
        'pattern'    => $r['pattern_name'] ?? '',
        'tax'        => $r['tax_type'] ?? 'taxable',
        'active'     => $r ? ((int)$r['is_active'] === 1) : false,
        'qty'        => $l['qty'],
        'unit_price' => $l['unit_price'],
        'list_price' => $l['list_price'],
        'dc_rate'    => $l['dc_rate'],
        'memo'       => $l['memo'],
    ];
}

// 이미 선택된 거래처(수정 화면, 저장 실패 후 재표시)
$partnerSel = null;
if ((int)$hdr['partner_id'] > 0) {
    try {
        $st = $pdo->prepare(
            'SELECT id, name, business_no, default_channel, partner_type, is_active' . ($hasCredit ? ', is_credit' : '') . ' FROM erp_partners WHERE id = ?'
        );
        $st->execute([(int)$hdr['partner_id']]);
        $r = $st->fetch();
        if ($r) {
            $b = (string)($r['business_no'] ?? '');
            $partnerSel = [
                'id'      => (int)$r['id'],
                'name'    => $r['name'],
                'biz'     => (strlen($b) === 10 && ctype_digit($b)) ? fmt_biz_no($b) : $b,
                'channel' => $r['default_channel'],
                'credit'  => $hasCredit && (int)($r['is_credit'] ?? 0) === 1,
                'active'  => (int)$r['is_active'] === 1,
            ];
        }
    } catch (Throwable $e) {
        error_log('[ERP] trade_form partner: ' . $e->getMessage());
    }
}

$data = json_encode(
    [
        'type' => $type, 'cats' => $cats, 'lines' => $jsLines, 'partner' => $partnerSel,
        'api' => 'trade_api.php', 'hasCredit' => $hasCredit, 'paidNow' => TF_CREDIT_PARTNER_PAID_NOW,
        'hasDc' => $hasDc,
    ],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);

$title = ($id > 0 ? '임시 전표 수정' : '새 ') . ($type === 'sale' ? '판매전표' : '매입전표');
page_header($title, $type);
?>
<style>
.pk-wrap{display:flex;flex-direction:column;gap:4px}
.pk-box{position:relative}
.pk-box input{width:100%}
.pk-list{position:absolute;top:100%;left:0;right:0;z-index:30;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:280px;overflow:auto}
.pk-list button{display:block;width:100%;text-align:left;padding:9px 12px;border:0;background:#fff;cursor:pointer;font:inherit;color:inherit}
.pk-list button:hover,.pk-list button.on{background:#eff6ff}
.pk-empty{padding:10px 12px;color:#6b7280}
.pk-sel{font-weight:600;min-height:22px}
#lines td{vertical-align:middle}
#lines td.cd{white-space:nowrap;color:#6b7280;font-size:12px}
#lines input.qty{width:76px}
#lines input.list,#lines input.price{width:104px}
#lines input.dc{width:68px}
.dcbox{color:#b45309}
</style>
<h1><?= h($title) ?><?= $trade ? ' · ' . h($trade['trade_no']) : '' ?></h1>
<?php if (!$hasDc && $isAdmin): ?>
<p style="margin:0 0 10px;color:#92400e;font-weight:600">DC율 컬럼이 아직 없어 DC 입력란을 숨겼습니다. DB 백업 후 <a href="trade_columns_setup.php">trade_columns_setup.php</a> 를 실행하면 나타납니다.</p>
<?php endif; ?>
<form method="post" id="tf" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="type" value="<?= h($type) ?>">

  <div class="card grid-form">
    <label>일자<input type="date" name="trade_date" value="<?= h($hdr['trade_date']) ?>" required></label>
    <?php if ($type === 'sale'): ?>
    <label>매출구분
      <select name="channel" id="channel">
        <option value="retail" <?= $hdr['channel'] === 'retail' ? 'selected' : '' ?>>소매</option>
        <option value="wholesale" <?= $hdr['channel'] === 'wholesale' ? 'selected' : '' ?>>도매</option>
      </select>
    </label>
    <?php endif; ?>

    <div class="pk-wrap">
      <span><?= $type === 'purchase' ? '거래처 (필수)' : '거래처 (도매 필수)' ?></span>
      <input type="hidden" name="partner_id" id="partnerId" value="<?= (int)$hdr['partner_id'] ?>">
      <div class="pk-box">
        <input type="text" id="partnerQ" placeholder="코드 · 상호 · 대표 · 전화 · 사업자번호 검색" autocomplete="off">
        <div id="partnerList" class="pk-list" hidden></div>
      </div>
      <div id="partnerSel" class="pk-sel"></div>
    </div>

    <?php if ($type === 'sale'): ?>
    <label>고객명 (소매)<input name="customer_name" value="<?= h($hdr['customer_name']) ?>" maxlength="100"></label>
    <label>차량번호<input name="vehicle_no" value="<?= h($hdr['vehicle_no']) ?>" maxlength="20"></label>
    <?php endif; ?>

    <label>결제수단 (확정 시 필수)
      <select name="pay_method" id="payMethod">
        <option value="">선택 안 함</option>
        <?php foreach ($PAY as $k => $lbl): ?>
          <option value="<?= h($k) ?>" <?= $hdr['pay_method'] === $k ? 'selected' : '' ?>><?= h($lbl . ($type === 'purchase' && $k === 'credit' ? ' (미지급)' : '')) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($hasAcct): ?>
    <label id="acctWrap"><?= $type === 'sale' ? '입금 계좌' : '출금 계좌' ?>
      <select name="account_id" id="account">
        <option value="0">선택 안 함</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= (int)$hdr['account_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['name'] . ' [' . ($ACCT_KINDS[$a['kind']] ?? $a['kind']) . ']' . ((int)$a['is_active'] === 1 ? '' : ' (사용 안 함)')) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <label class="wide">메모<input name="memo" value="<?= h($hdr['memo']) ?>" maxlength="500"></label>
    <p id="payHint" style="grid-column:1/-1;margin:0;display:none;font-weight:600;color:#92400e"></p>
    <?php if ($hasAcct && $activeAcctCount === 0): ?>
    <p style="grid-column:1/-1;margin:0;color:#92400e">등록된 자금 계좌가 없습니다. 지금 확정하면 계좌 없이 저장됩니다. 관리자가 자금 계좌 메뉴에서 계좌를 먼저 등록하세요.</p>
    <?php endif; ?>
  </div>

  <div class="card picker">
    <div class="chips" id="catTop"></div>
    <div class="chips" id="catSub"></div>
    <input id="q" placeholder="품목 검색 (이름 · 코드 · 규격 205/55R16 · 2055516) — Enter: 첫 결과 추가">
    <div class="itemlist" id="itemList"></div>
  </div>

  <table class="table lines" id="lines">
    <thead>
      <tr>
        <th>코드</th><th>품목</th><th>규격</th><th>패턴</th><th class="num">수량</th>
        <?php if ($hasDc): ?><th class="num">정가</th><th class="num">DC율(%)</th><?php endif; ?>
        <th class="num">단가</th><th class="num">공급가액</th><th>비고</th><th></th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>

  <div class="totals">
    <div>공급가액 <strong id="tSupply">0</strong>원</div>
    <div>부가세 <strong id="tVat">0</strong>원</div>
    <div>합계 <strong id="tTotal">0</strong>원</div>
    <?php if ($hasDc): ?>
    <div class="dcbox">DC 합계 <strong id="tDc">0</strong>원 <small id="tDcPct"></small></div>
    <?php endif; ?>
    <small>화면 표시용 계산입니다. 저장할 때 서버가 다시 계산합니다.</small>
  </div>

  <div class="actions">
    <button class="btn" type="submit" name="act" value="save">임시 저장</button>
    <button class="btn btn-primary" type="submit" name="act" value="confirm">저장 후 확정 (재고 반영)</button>
    <a class="btn" href="trades.php?type=<?= h($type) ?>">목록</a>
  </div>
</form>
<script id="tf-data" type="application/json"><?= $data ?></script>
<script src="assets/trade_form.js?v=<?= (int)@filemtime(__DIR__ . '/assets/trade_form.js') ?>"></script>
<?php page_footer();
