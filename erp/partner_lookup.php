<?php
// partner_lookup.php — 거래처 검색(JSON, 읽기 전용)
//   목록: ?type=sale|purchase&q=검색어     상세: ?id=번호
require __DIR__ . '/inc/bootstrap.php';
require_login();
$ledgerLib = __DIR__ . '/inc/ledger_lib.php';
if (is_file($ledgerLib)) {
    require_once $ledgerLib;
}
$pdo = db();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pl_out(array $d): void
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // 선택 컬럼(E카운트 확장 컬럼)이 있는지 확인
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM erp_partners')->fetchAll() as $c) {
        $have[(string)$c['Field']] = true;
    }

    // ---------------------------------------------------------- 상세
    $id = in_int($_GET, 'id');
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM erp_partners WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            pl_out(['ok' => false, 'error' => '거래처를 찾을 수 없습니다.']);
        }

        $credit  = (int)($p['is_credit'] ?? 0) === 1;
        $bal     = null;
        $balNote = '';
        if ($credit) {
            if (function_exists('lg_balance') && function_exists('lg_ready_error') && lg_ready_error() === null) {
                $bal = (int)lg_balance($id);
            } else {
                $balNote = '원장을 확인할 수 없어 전잔을 표시하지 못했습니다.';
            }
        }
        $biz = (string)($p['business_no'] ?? '');

        pl_out([
            'ok' => true,
            'partner' => [
                'id'              => (int)$p['id'],
                'name'            => (string)$p['name'],
                'business_no'     => $biz !== '' ? fmt_biz_no($biz) : '',
                'ceo_name'        => (string)($p['ceo_name'] ?? ''),
                'phone'           => (string)($p['phone'] ?? ''),
                'address'         => (string)($p['address'] ?? ''),
                'tax_email'       => (string)($p['tax_email'] ?? ''),
                'partner_type'    => (string)($p['partner_type'] ?? ''),
                'default_channel' => (string)($p['default_channel'] ?? ''),
                'memo'            => (string)($p['memo'] ?? ''),
                'is_credit'       => $credit,
                'balance'         => $bal,       // 전잔: 확정 외상 잔액 (외상 거래처가 아니면 null)
                'balance_note'    => $balNote,
            ],
        ]);
    }

    // ---------------------------------------------------------- 목록
    $type = in_str($_GET, 'type', 10);
    $q    = in_str($_GET, 'q', 50);

    $w = ['is_active = 1'];
    $a = [];
    if ($type === 'sale') {
        $w[] = "partner_type IN ('customer','both')";
    } elseif ($type === 'purchase') {
        $w[] = "partner_type IN ('supplier','both')";
    }

    $order = 'name, id';
    $orderArgs = [];
    if ($q !== '') {
        $esc  = addcslashes($q, '%_\\');
        $like = '%' . $esc . '%';
        $or   = ['name LIKE ?', 'ceo_name LIKE ?', 'phone LIKE ?'];
        $a    = [$like, $like, $like];

        $digits = preg_replace('/\D/', '', $q);
        if ($digits !== '') {
            $or[] = 'business_no LIKE ?';
            $a[]  = '%' . $digits . '%';
        }
        foreach (['ecount_code', 'search_keyword'] as $col) {
            if (isset($have[$col])) {
                $or[] = $col . ' LIKE ?';
                $a[]  = $like;
            }
        }
        $w[] = '(' . implode(' OR ', $or) . ')';

        // 상호가 검색어로 시작하는 거래처를 먼저
        $order = 'CASE WHEN name LIKE ? THEN 0 ELSE 1 END, name, id';
        $orderArgs = [$esc . '%'];
    }

    $sql = 'SELECT id, name, business_no, ceo_name, phone, address, default_channel, is_credit
              FROM erp_partners
             WHERE ' . implode(' AND ', $w) . '
             ORDER BY ' . $order . '
             LIMIT 21';
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($a, $orderArgs));
    $rows = $st->fetchAll();

    $more = count($rows) > 20;
    $out  = [];
    foreach (array_slice($rows, 0, 20) as $r) {
        $biz = (string)($r['business_no'] ?? '');
        $out[] = [
            'id'              => (int)$r['id'],
            'name'            => (string)$r['name'],
            'business_no'     => $biz !== '' ? fmt_biz_no($biz) : '',
            'ceo_name'        => (string)($r['ceo_name'] ?? ''),
            'phone'           => (string)($r['phone'] ?? ''),
            'address'         => (string)($r['address'] ?? ''),
            'default_channel' => (string)($r['default_channel'] ?? ''),
            'is_credit'       => (int)($r['is_credit'] ?? 0) === 1,
        ];
    }
    pl_out(['ok' => true, 'items' => $out, 'more' => $more]);
} catch (Throwable $e) {
    error_log('[ERP] partner_lookup: ' . get_class($e) . ': ' . $e->getMessage());
    pl_out(['ok' => false, 'error' => '거래처 조회 중 오류가 발생했습니다.']);
}
