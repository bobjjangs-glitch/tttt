<?php
// inc/dc_lib.php — DC율 공용 함수 (정수 연산만 사용, float 금지)

const DC_MAX = 10000; // 100.00%

/** "12.5", "12.5%", "10" -> 1250, 1250, 1000. 형식 오류/범위 초과는 null. */
function dc_parse(string $s): ?int
{
    $s = str_replace([',', ' ', '%'], '', trim($s));
    if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    $v = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    return $v <= DC_MAX ? $v : null;
}

/** 1250 -> "12.5", 1000 -> "10", 0 -> "0", 505 -> "5.05" */
function dc_fmt(int $v): string
{
    $i = intdiv($v, 100);
    $f = $v % 100;
    return $f === 0 ? (string)$i : rtrim(sprintf('%d.%02d', $i, $f), '0');
}

/** 정가에서 DC율을 적용한 실제 단가 (원 단위, 0.5 올림). */
function dc_unit_price(int $listPrice, int $rate): int
{
    if ($listPrice < 0 || $rate < 0 || $rate > DC_MAX) {
        throw new InvalidArgumentException('정가 또는 DC율이 범위를 벗어났습니다.');
    }
    return intdiv($listPrice * (DC_MAX - $rate) + 5000, DC_MAX);
}

function dc_has_col(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

function dc_has_table(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

/** DC율 기능에 필요한 컬럼/테이블이 모두 있는지. 없으면 $why 에 이유. */
function dc_ready(PDO $pdo, ?string &$why = null): bool
{
    try {
        if (!dc_has_col($pdo, 'erp_partners', 'partner_code')) {
            $why = '거래처 코드 컬럼(partner_code)이 없습니다. partner_code_migrate.php 를 먼저 실행하세요.';
            return false;
        }
        if (!dc_has_col($pdo, 'erp_partners', 'dc_rate') || !dc_has_table($pdo, 'erp_dc_log')) {
            $why = 'DC율 저장소가 아직 없습니다. partner_dc_setup.php 를 먼저 실행하세요.';
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('[ERP] dc_ready: ' . $e->getMessage());
        $why = 'DB 구조를 확인하지 못했습니다.';
        return false;
    }
}
