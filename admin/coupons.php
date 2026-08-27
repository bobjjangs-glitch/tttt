<?php
// /admin/coupons.php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('coupons');
ensure_coupon_tables();

$pdo = Database::connection();
$adminId = AdminAuth::currentAdminId();

/* ---------- 쿠폰 발송(발급) ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'issue') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/coupons.php');
    }

    $couponId  = (int)($_POST['coupon_id'] ?? 0);
    $issueMode = ($_POST['issue_mode'] ?? 'all') === 'email' ? 'email' : 'all';
    $emailsRaw = trim($_POST['emails'] ?? '');

    $cStmt = $pdo->prepare('SELECT * FROM tt_coupons WHERE id = :id LIMIT 1');
    $cStmt->execute(['id' => $couponId]);
    $coupon = $cStmt->fetch();

    if (!$coupon) {
        flash('admin_error', '쿠폰을 찾을 수 없습니다.');
        redirect('/admin/coupons.php');
    }
    if (($coupon['status'] ?? 'inactive') !== 'active') {
        flash('admin_error', '비활성 쿠폰은 발송할 수 없습니다.');
        redirect('/admin/coupons.php');
    }

    $remain = $coupon['total_limit'] !== null
        ? max(0, (int)$coupon['total_limit'] - (int)$coupon['issued_count'])
        : null;

    if ($remain !== null && $remain <= 0) {
        flash('admin_error', '발급 가능한 수량이 모두 소진되었습니다.');
        redirect('/admin/coupons.php');
    }

    // [FIX] AdminAuth::log()는 내부에서 ensure_admin_logs_table()을 통해
    // "CREATE TABLE IF NOT EXISTS"라는 DDL 문을 실행한다.
    // MySQL/InnoDB는 DDL 실행 시 진행 중인 트랜잭션을 강제로 암묵적 커밋해버리기 때문에,
    // 트랜잭션 도중(beginTransaction ~ commit 사이)에 로그 기록 함수를 호출하면
    // 실제로는 이미 커밋된 상태에서 나중에 $pdo->commit()이 호출되어
    // PDOException("There is no active transaction")이 발생한다.
    // → 로그 테이블을 트랜잭션 시작 "전"에 미리 보장해 두어 트랜잭션 내부에서
    //    DDL이 실행될 가능성을 원천적으로 제거한다.
    ensure_admin_logs_table($pdo);

    $inserted = 0;
    try {
        set_time_limit(120); // 대량 발송 시 실행시간 초과로 인한 500 방지

        $pdo->beginTransaction();

        if ($issueMode === 'all') {
            $sql = "INSERT INTO tt_user_coupons (user_id, coupon_id)
                    SELECT u.id, :cid FROM tt_users u
                    WHERE u.status = 'active'
                      AND NOT EXISTS (
                          SELECT 1 FROM tt_user_coupons uc
                          WHERE uc.user_id = u.id AND uc.coupon_id = :cid2
                      )";
            if ($remain !== null) $sql .= " LIMIT " . (int)$remain;

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue('cid', $couponId, PDO::PARAM_INT);
            $stmt->bindValue('cid2', $couponId, PDO::PARAM_INT);
            $stmt->execute();
            $inserted = $stmt->rowCount();

        } else {
            $emails = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $emailsRaw)));
            foreach ($emails as $email) {
                if ($remain !== null && $inserted >= $remain) break;

                $uStmt = $pdo->prepare("SELECT id FROM tt_users WHERE email = :email AND status = 'active' LIMIT 1");
                $uStmt->execute(['email' => $email]);
                $userId = $uStmt->fetchColumn();
                if (!$userId) continue;

                $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM tt_user_coupons WHERE user_id = :uid AND coupon_id = :cid');
                $dupStmt->execute(['uid' => $userId, 'cid' => $couponId]);
                if ((int)$dupStmt->fetchColumn() > 0) continue;

                $pdo->prepare('INSERT INTO tt_user_coupons (user_id, coupon_id) VALUES (:uid, :cid)')
                    ->execute(['uid' => $userId, 'cid' => $couponId]);
                $inserted++;
            }
        }

        if ($inserted > 0) {
            $pdo->prepare('UPDATE tt_coupons SET issued_count = issued_count + :n WHERE id = :id')
                ->execute(['n' => $inserted, 'id' => $couponId]);
        }

        // [FIX] 순수 DML(INSERT/UPDATE)만 커밋한다. 여기까지는 DDL이 전혀 없으므로
        // 이 commit()은 항상 "활성 트랜잭션이 있는" 정상 상태에서 호출된다.
        $pdo->commit();

        // [FIX] 로그 기록은 트랜잭션이 완전히 끝난 "이후"에 실행한다.
        // 이제는 ensure_admin_logs_table()이 이미 위에서 실행되어 테이블이 보장된 상태이므로
        // 이 안에서 추가 DDL이 발생하지 않는다.
        AdminAuth::log($adminId, 'coupon_issue', "쿠폰#{$couponId} {$inserted}건 발송");

        flash('admin_success', "쿠폰이 {$inserted}명에게 발송되었습니다.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[coupon issue] ' . $e->getMessage());
        flash('admin_error', '쿠폰 발송 중 오류가 발생했습니다. [' . $e->getMessage() . ']');
    }
    redirect('/admin/coupons.php');
}

/* ---------- 활성/비활성 토글 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'toggle_status') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) redirect('/admin/coupons.php');
    $id = (int)($_POST['coupon_id'] ?? 0);
    $pdo->prepare("UPDATE tt_coupons SET status = IF(status='active','inactive','active') WHERE id = :id")
        ->execute(['id' => $id]);
    redirect('/admin/coupons.php');
}

/* ---------- 삭제 ---------- */
if (is_post() && ($_POST['form_type'] ?? '') === 'delete') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) redirect('/admin/coupons.php');
    $id = (int)($_POST['coupon_id'] ?? 0);
    $pdo->prepare('DELETE FROM tt_coupons WHERE id = :id')->execute(['id' => $id]);
    flash('admin_success', '쿠폰이 삭제되었습니다.');
    redirect('/admin/coupons.php');
}

/* ---------- 목록 조회 ---------- */
$coupons = $pdo->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM tt_user_coupons uc WHERE uc.coupon_id = c.id) AS total_issued,
           (SELECT COUNT(*) FROM tt_user_coupons uc WHERE uc.coupon_id = c.id AND uc.status = 'used') AS total_used
    FROM tt_coupons c
    ORDER BY c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = '쿠폰 관리';
require __DIR__ . '/includes/header.php';
?>
<style>
.coupon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px}
.coupon-card{
  background:#fff;border:1px solid var(--adm-border);border-radius:16px;overflow:hidden;
  transition:transform .15s,box-shadow .15s;
}
.coupon-card:hover{transform:translateY(-3px);box-shadow:0 10px 24px rgba(17,24,39,.08)}
.coupon-card-top{
  padding:18px 18px 14px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;position:relative;
}
.coupon-card-top.inactive{background:linear-gradient(135deg,#94a3b8,#cbd5e1)}
.coupon-thumb{width:100%;height:110px;object-fit:cover;border-radius:10px;margin-bottom:10px;background:rgba(255,255,255,.15)}
.coupon-name{font-size:16px;font-weight:800}
.coupon-discount{font-size:22px;font-weight:900;margin-top:4px}
.coupon-status-chip{
  position:absolute;top:14px;right:14px;font-size:11px;font-weight:800;
  padding:3px 10px;border-radius:999px;background:rgba(255,255,255,.25);
}
.coupon-card-body{padding:16px 18px}
.coupon-meta-row{display:flex;justify-content:space-between;font-size:12.5px;color:var(--adm-text-sub);margin-bottom:6px}
.coupon-meta-row strong{color:var(--adm-text)}
.coupon-stats{display:flex;gap:10px;margin:12px 0}
.coupon-stat-pill{flex:1;text-align:center;background:#f8f9fb;border-radius:10px;padding:10px 4px}
.coupon-stat-pill b{display:block;font-size:16px;color:var(--adm-primary)}
.coupon-stat-pill span{font-size:11px;color:var(--adm-text-sub)}
.coupon-card-actions{display:flex;gap:6px;margin-top:12px;flex-wrap:wrap}
.coupon-issue-form{margin-top:14px;padding-top:14px;border-top:1px solid var(--adm-border)}
.coupon-issue-form summary{cursor:pointer;font-size:13px;font-weight:700;color:var(--adm-primary);list-style:none}
.coupon-issue-form summary::before{content:'📨 ';}
.coupon-issue-body{margin-top:10px;display:flex;flex-direction:column;gap:8px}
.coupon-issue-body select,.coupon-issue-body textarea{
  width:100%;border:1px solid var(--adm-border);border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;
}
.coupon-issue-body textarea{resize:vertical;min-height:60px}
</style>

<div class="admin-toolbar">
  <div class="admin-filter-form-wide"><span class="admin-count-pill">총 <?= count($coupons) ?>개 쿠폰</span></div>
  <div class="admin-toolbar-right">
    <a href="<?= BASE_URL ?>/admin/coupon_form.php" class="btn-admin-add">＋ 새 쿠폰 만들기</a>
  </div>
</div>

<?php if ($coupons): ?>
<div class="coupon-grid">
  <?php foreach ($coupons as $c): ?>
    <?php
      $discountLabel = $c['discount_type'] === 'percent'
          ? (int)$c['discount_value'] . '% 할인'
          : number_format((int)$c['discount_value']) . '원 할인';
      $period = ($c['valid_from'] ? date('Y.m.d', strtotime($c['valid_from'])) : '제한없음')
              . ' ~ ' . ($c['valid_until'] ? date('Y.m.d', strtotime($c['valid_until'])) : '제한없음');
    ?>
    <div class="coupon-card">
      <div class="coupon-card-top <?= $c['status'] !== 'active' ? 'inactive' : '' ?>">
        <span class="coupon-status-chip"><?= $c['status'] === 'active' ? '발송중' : '비활성' ?></span>
        <?php if ($c['image_url']): ?>
          <img src="<?= h($c['image_url']) ?>" class="coupon-thumb" alt="">
        <?php endif; ?>
        <div class="coupon-name"><?= h($c['name']) ?></div>
        <div class="coupon-discount"><?= $discountLabel ?></div>
      </div>
      <div class="coupon-card-body">
        <div class="coupon-meta-row"><span>최소 주문금액</span><strong><?= number_format((int)$c['min_order_amount']) ?>원</strong></div>
        <?php if ($c['discount_type'] === 'percent' && $c['max_discount_amount']): ?>
          <div class="coupon-meta-row"><span>최대 할인액</span><strong><?= number_format((int)$c['max_discount_amount']) ?>원</strong></div>
        <?php endif; ?>
        <div class="coupon-meta-row"><span>유효기간</span><strong><?= h($period) ?></strong></div>
        <div class="coupon-meta-row"><span>발급 한도</span><strong><?= $c['total_limit'] ? number_format((int)$c['total_limit']) . '개' : '무제한' ?></strong></div>

        <div class="coupon-stats">
          <div class="coupon-stat-pill"><b><?= (int)$c['total_issued'] ?></b><span>발급됨</span></div>
          <div class="coupon-stat-pill"><b><?= (int)$c['total_used'] ?></b><span>사용됨</span></div>
          <div class="coupon-stat-pill"><b><?= (int)$c['total_issued'] - (int)$c['total_used'] ?></b><span>미사용</span></div>
        </div>

        <div class="coupon-card-actions">
          <a href="<?= BASE_URL ?>/admin/coupon_form.php?id=<?= (int)$c['id'] ?>" class="admin-link-btn">수정</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="form_type" value="toggle_status">
            <input type="hidden" name="coupon_id" value="<?= (int)$c['id'] ?>">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-admin-secondary" style="padding:6px 14px;font-size:12.5px">
              <?= $c['status'] === 'active' ? '비활성화' : '활성화' ?>
            </button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('이 쿠폰을 삭제하시겠습니까? 발급된 회원 쿠폰함에서도 함께 삭제됩니다.');">
            <input type="hidden" name="form_type" value="delete">
            <input type="hidden" name="coupon_id" value="<?= (int)$c['id'] ?>">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-admin-danger">삭제</button>
          </form>
        </div>

        <details class="coupon-issue-form">
          <summary>이 쿠폰 발송하기</summary>
          <form method="post" class="coupon-issue-body">
            <input type="hidden" name="form_type" value="issue">
            <input type="hidden" name="coupon_id" value="<?= (int)$c['id'] ?>">
            <?= Csrf::field() ?>
            <select name="issue_mode" onchange="this.closest('form').querySelector('.email-box').style.display = this.value === 'email' ? 'block' : 'none';">
              <option value="all">전체 회원에게 발송</option>
              <option value="email">이메일 지정 발송</option>
            </select>
            <textarea class="email-box" name="emails" style="display:none" placeholder="이메일을 줄바꿈 또는 쉼표로 구분해서 입력하세요"></textarea>
            <button type="submit" class="btn-admin-primary" style="width:100%">발송하기</button>
          </form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="admin-card"><p class="admin-empty-row">등록된 쿠폰이 없습니다. 새 쿠폰을 만들어보세요.</p></div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
