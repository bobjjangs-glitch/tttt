<?php
require __DIR__ . '/inc/bootstrap.php';

$lock = __DIR__ . '/inc/installed.lock';
if (file_exists($lock)) {
    exit('이미 설치되었습니다. 보안을 위해 install.php를 서버에서 삭제하세요.');
}
$key = (string)cfg('app.install_key', '');
if (strlen($key) < 8 || strpos($key, 'CHANGE_ME') !== false) {
    exit('inc/config.php 의 app.install_key 를 8자 이상 임의 문자열로 바꾼 뒤 다시 접속하세요.');
}

$ddl = [
"CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id VARCHAR(30) NOT NULL,
  name VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'office',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_count INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_login (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(50) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_cat_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_code VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  brand VARCHAR(50) NOT NULL DEFAULT '',
  pattern_name VARCHAR(80) NOT NULL DEFAULT '',
  spec VARCHAR(40) NOT NULL DEFAULT '',
  spec_code VARCHAR(12) NOT NULL DEFAULT '',
  unit VARCHAR(10) NOT NULL DEFAULT '개',
  tax_type VARCHAR(10) NOT NULL DEFAULT 'taxable',
  retail_price BIGINT NOT NULL DEFAULT 0,
  wholesale_price BIGINT NOT NULL DEFAULT 0,
  last_cost BIGINT NOT NULL DEFAULT 0,
  is_stock_item TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_items_code (item_code),
  KEY idx_items_cat (category_id),
  KEY idx_items_spec (spec_code),
  CONSTRAINT fk_items_cat FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS partners (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  business_no CHAR(10) NULL,
  branch_no VARCHAR(4) NOT NULL DEFAULT '',
  ceo_name VARCHAR(50) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  tax_email VARCHAR(100) NOT NULL DEFAULT '',
  address VARCHAR(200) NOT NULL DEFAULT '',
  partner_type VARCHAR(10) NOT NULL DEFAULT 'both',
  default_channel VARCHAR(10) NOT NULL DEFAULT 'wholesale',
  memo VARCHAR(500) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_biz (business_no, branch_no),
  KEY idx_partner_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS doc_sequences (
  prefix VARCHAR(10) NOT NULL,
  seq_date DATE NOT NULL,
  last_no INT UNSIGNED NOT NULL,
  PRIMARY KEY (prefix, seq_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS trades (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trade_no VARCHAR(30) NOT NULL,
  trade_type VARCHAR(10) NOT NULL,
  channel VARCHAR(10) NULL,
  trade_date DATE NOT NULL,
  partner_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(100) NOT NULL DEFAULT '',
  vehicle_no VARCHAR(20) NOT NULL DEFAULT '',
  supply_amount BIGINT NOT NULL DEFAULT 0,
  vat_amount BIGINT NOT NULL DEFAULT 0,
  total_amount BIGINT NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  memo VARCHAR(500) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NOT NULL,
  confirmed_by BIGINT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  canceled_at DATETIME NULL,
  cancel_reason VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trade_no (trade_no),
  KEY idx_trade_date (trade_date),
  KEY idx_trade_partner (partner_id, trade_date),
  KEY idx_trade_type_status (trade_type, status),
  CONSTRAINT fk_trade_partner FOREIGN KEY (partner_id) REFERENCES partners (id),
  CONSTRAINT fk_trade_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS trade_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trade_id BIGINT UNSIGNED NOT NULL,
  line_no INT NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  item_name VARCHAR(200) NOT NULL,
  spec VARCHAR(40) NOT NULL DEFAULT '',
  tax_type VARCHAR(10) NOT NULL DEFAULT 'taxable',
  qty DECIMAL(12,3) NOT NULL,
  unit_price BIGINT NOT NULL DEFAULT 0,
  amount BIGINT NOT NULL DEFAULT 0,
  cost_price BIGINT NULL,
  memo VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_line (trade_id, line_no),
  KEY idx_line_item (item_id),
  CONSTRAINT fk_line_trade FOREIGN KEY (trade_id) REFERENCES trades (id),
  CONSTRAINT fk_line_item FOREIGN KEY (item_id) REFERENCES items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS stock_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id BIGINT UNSIGNED NOT NULL,
  move_date DATE NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  trade_line_id BIGINT UNSIGNED NULL,
  entry_kind VARCHAR(10) NOT NULL,
  memo VARCHAR(200) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ledger_line_kind (trade_line_id, entry_kind),
  KEY idx_ledger_item (item_id, move_date),
  CONSTRAINT fk_ledger_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT fk_ledger_line FOREIGN KEY (trade_line_id) REFERENCES trade_lines (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  target VARCHAR(40) NOT NULL DEFAULT '',
  target_id BIGINT UNSIGNED NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$msg = '';
$done = false;
$dbOk = true;
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    error_log('[ERP install] DB 연결 실패: ' . $e->getMessage());
    $dbOk = false;
}

if ($dbOk && is_post()) {
    csrf_check();
    $inKey  = (string)($_POST['install_key'] ?? '');
    $login  = in_str($_POST, 'login_id', 30);
    $name   = in_str($_POST, 'name', 50);
    $pw     = (string)($_POST['password'] ?? '');
    $sample = isset($_POST['sample']);

    if (!hash_equals($key, $inKey)) {
        $msg = 'install_key 가 일치하지 않습니다.';
    } elseif (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $login)) {
        $msg = '아이디는 영문/숫자/밑줄 3~30자로 입력하세요.';
    } elseif ($name === '') {
        $msg = '이름을 입력하세요.';
    } elseif (strlen($pw) < 10) {
        $msg = '비밀번호는 10자 이상이어야 합니다.';
    } else {
        $pdo = db();
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }
        $now = date('Y-m-d H:i:s');

        $st = $pdo->prepare('SELECT id FROM users WHERE login_id = ?');
        $st->execute([$login]);
        if (!$st->fetch()) {
            $pdo->prepare('INSERT INTO users (login_id, name, password_hash, role, created_at) VALUES (?,?,?,?,?)')
                ->execute([$login, $name, password_hash($pw, PASSWORD_DEFAULT), 'admin', $now]);
        }

        if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
            $seed = [
                '타이어'       => ['승용·SUV', '화물·트럭'],
                '오일'         => ['엔진오일', '미션오일', '기타오일·부동액'],
                '정비용품'     => ['배터리', '와이퍼', '브레이크', '기타용품'],
                '공임·서비스'  => ['타이어 공임', '정비 공임'],
            ];
            $insCat = $pdo->prepare('INSERT INTO categories (parent_id, name, sort_order) VALUES (?,?,?)');
            $o = 10;
            foreach ($seed as $top => $subs) {
                $insCat->execute([null, $top, $o]);
                $pid = (int)$pdo->lastInsertId();
                $so = 10;
                foreach ($subs as $s) {
                    $insCat->execute([$pid, $s, $so]);
                    $so += 10;
                }
                $o += 10;
            }
        }

        if ($sample) {
            $catId = function (string $n) use ($pdo) {
                $s = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
                $s->execute([$n]);
                return (int)$s->fetchColumn();
            };
            $items = [
                ['SAMPLE-T1', '[샘플] 승용 타이어 205/55R16', '승용·SUV', '샘플', 'SP1', '205/55R16', 120000, 98000, 85000, 1],
                ['SAMPLE-T2', '[샘플] 화물 타이어 LT265/70R17', '화물·트럭', '샘플', 'LT1', 'LT265/70R17', 210000, 180000, 160000, 1],
                ['SAMPLE-O1', '[샘플] 엔진오일 5W-30 4L', '엔진오일', '샘플', '', '', 45000, 38000, 30000, 1],
                ['SAMPLE-L1', '[샘플] 타이어 교체 공임', '타이어 공임', '', '', '', 10000, 8000, 0, 0],
            ];
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO items (item_code, name, category_id, brand, pattern_name, spec, spec_code, retail_price, wholesale_price, last_cost, is_stock_item, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($items as $it) {
                $ins->execute([$it[0], $it[1], $catId($it[2]), $it[3], $it[4], $it[5], normalize_tire_spec($it[5]), $it[6], $it[7], $it[8], $it[9], $now, $now]);
            }
            $insP = $pdo->prepare(
                'INSERT INTO partners (name, partner_type, default_channel, created_at, updated_at) VALUES (?,?,?,?,?)'
            );
            if ((int)$pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn() === 0) {
                $insP->execute(['[샘플] 도매거래처', 'both', 'wholesale', $now, $now]);
                $insP->execute(['[샘플] 소매 사업자 손님', 'customer', 'retail', $now, $now]);
            }
        }

        file_put_contents($lock, date('c'));
        $done = true;
    }
}
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>설치</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body><main class="container narrow">
<h1>ERP 설치</h1>
<?php if ($done): ?>
  <div class="flash ok">설치가 끝났습니다. 지금 <strong>install.php 를 서버에서 삭제</strong>하세요.</div>
  <p><a class="btn btn-primary" href="login.php">로그인으로 이동</a></p>
<?php elseif (!$dbOk): ?>
  <div class="flash err">DB에 연결하지 못했습니다. inc/config.php 의 DB 정보를 확인하세요.</div>
<?php else: ?>
  <?php if ($msg): ?><div class="flash err"><?= h($msg) ?></div><?php endif; ?>
  <form method="post" class="card form-stack">
    <?= csrf_field() ?>
    <label>install_key (config.php 에 적은 값)<input type="password" name="install_key" required></label>
    <label>관리자 아이디<input name="login_id" required maxlength="30"></label>
    <label>관리자 이름<input name="name" required maxlength="50"></label>
    <label>관리자 비밀번호 (10자 이상)<input type="password" name="password" required minlength="10"></label>
    <label class="check"><input type="checkbox" name="sample" value="1" checked> 샘플 품목/거래처 넣기 (테스트용)</label>
    <button class="btn btn-primary" type="submit">설치</button>
  </form>
<?php endif; ?>
</main></body></html>
