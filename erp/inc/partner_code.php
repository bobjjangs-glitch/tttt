<?php
// inc/partner_code.php — 거래처 코드 자동 부여 (신규 파일)
// 코드 형식은 partner_code_migrate.php 의 PCM_PREFIX / PCM_DIGITS 와 반드시 같아야 합니다.
if (!defined('PC_PREFIX')) {
    define('PC_PREFIX', 'C');
    define('PC_DIGITS', 5);
}

/** partner_code 컬럼이 있는지 (없으면 모든 함수가 아무것도 하지 않음) */
function pc_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $ok = (bool)$pdo->query("SHOW COLUMNS FROM erp_partners LIKE 'partner_code'")->fetch();
    } catch (Throwable $e) {
        error_log('[ERP] pc_ready: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

function pc_format(int $n): string
{
    return sprintf('%s%0' . PC_DIGITS . 'd', PC_PREFIX, $n);
}

/** 현재 최대 번호 + 1 */
function pc_next_no(PDO $pdo): int
{
    $start = strlen(PC_PREFIX) + 1;
    $sql = 'SELECT COALESCE(MAX(CAST(SUBSTRING(partner_code, ' . $start . ') AS UNSIGNED)), 0) '
         . 'FROM erp_partners WHERE partner_code REGEXP \'^' . PC_PREFIX . '[0-9]+$\'';
    $next = (int)$pdo->query($sql)->fetchColumn() + 1;
    if ($next > (10 ** PC_DIGITS) - 1) {
        throw new RuntimeException('거래처 코드 자릿수를 초과했습니다.');
    }
    return $next;
}

/**
 * 코드가 비어 있는 거래처 1건에 코드를 부여하고 그 코드를 돌려줍니다.
 * 이미 코드가 있으면 그대로 돌려주고, 컬럼이 없으면 '' 를 돌려줍니다.
 */
function pc_assign_new(PDO $pdo, int $id): string
{
    if (!pc_ready($pdo)) {
        return '';
    }
    for ($try = 0; $try < 5; $try++) {
        $chk = $pdo->prepare('SELECT partner_code FROM erp_partners WHERE id = ?');
        $chk->execute([$id]);
        $cur = $chk->fetchColumn();
        if ($cur === false) {
            throw new RuntimeException('거래처를 찾을 수 없습니다. (id ' . $id . ')');
        }
        if (trim((string)$cur) !== '') {
            return (string)$cur;
        }
        $code = pc_format(pc_next_no($pdo));
        try {
            $up = $pdo->prepare(
                "UPDATE erp_partners SET partner_code = ?, updated_at = ? WHERE id = ? AND (partner_code IS NULL OR partner_code = '')"
            );
            $up->execute([$code, date('Y-m-d H:i:s'), $id]);
            if ($up->rowCount() === 1) {
                return $code;
            }
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e; // 중복이 아니면 그대로 올림
            }
            // 중복(동시 저장)이면 다음 번호로 재시도
        }
    }
    throw new RuntimeException('거래처 코드 부여를 5회 시도했으나 실패했습니다. (id ' . $id . ')');
}

/** 코드가 없는 거래처를 id 순으로 채웁니다. [성공 건수, 실패 건수] */
function pc_fill_missing(PDO $pdo, int $limit = 500): array
{
    if (!pc_ready($pdo)) {
        return [0, 0];
    }
    $st = $pdo->prepare(
        "SELECT id FROM erp_partners WHERE partner_code IS NULL OR partner_code = '' ORDER BY id LIMIT " . (int)$limit
    );
    $st->execute();
    $done = 0;
    $fail = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            pc_assign_new($pdo, (int)$id);
            $done++;
        } catch (Throwable $e) {
            error_log('[ERP] pc_fill_missing id ' . $id . ': ' . $e->getMessage());
            $fail++;
        }
    }
    return [$done, $fail];
}
