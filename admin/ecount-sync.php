<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();
if (!AdminAuth::isSuper()) {
    flash('admin_error', '이카운트 연동 관리는 최고관리자만 접근할 수 있습니다.');
    redirect('/admin/index.php');
}

$pdo = Database::connection();
ensure_ecount_tables($pdo);

$lastResult = null;
$lastAction = null;

if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/ecount-sync.php');
    }
    $action = $_POST['action'] ?? '';
    $lastAction = $action;

    if ($action === 'test_connection') {
        try {
            $client = new EcountClient($pdo);
            $info = $client->testConnection();
            flash('admin_success', "연결 성공! 도메인={$info['domain_base']}, ZONE={$info['zone']}, 세션={$info['session_id']}");
            $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('test', ?, 1, 0)")
                ->execute(["연결 테스트 성공 (ZONE={$info['zone']})"]);
        } catch (Throwable $e) {
            flash('admin_error', '연결 실패: ' . $e->getMessage());
            try {
                $pdo->prepare("INSERT INTO tt_ecount_sync_log (direction, summary, success_count, fail_count) VALUES ('test', ?, 0, 1)")
                    ->execute(['연결 테스트 실패: ' . $e->getMessage()]);
            } catch (Throwable $e2) {}
        }
        redirect('/admin/ecount-sync.php');
    }

    if ($action === 'push_now') {
        $dryRun = (($_POST['dry_run'] ?? '') === '1');
        $lastResult = ecount_run_push($pdo, $dryRun);
    }

    if ($action === 'pull_now') {
        $lastResult = ecount_run_pull($pdo);
    }
}

$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM tt_ecount_sync_queue WHERE status = 'pending'")->fetchColumn();
$recentLogs = $pdo->query("SELECT * FROM tt_ecount_sync_log ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

$comCode = get_setting('ecount_com_code', '');
$userId  = get_setting('ecount_user_id', '');
$apiKeySet = get_setting('ecount_api_key', '') !== '';
$domain = get_setting('ecount_domain', 'sboapi');

$pageTitle = '이카운트 연동 — 수동 동기화';
require __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
  <h2 class="admin-list-title">📊 이카운트 ERP 연동 — 수동 동기화 (크론 미사용)</h2>
  <p class="admin-list-sub">cron 스케줄러가 막혀 있어도 이 화면에서 버튼으로 바로 테스트할 수 있습니다.</p>

  <?php if ($msg = flash('admin_success')): ?><div class="alert alert-success" style="padding:12px 16px;background:#dcfce7;color:#15803d;border-radius:10px;margin:12px 0;"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('admin_error')): ?><div class="alert alert-danger" style="padding:12px 16px;background:#fee2e2;color:#b91c1c;border-radius:10px;margin:12px 0;"><?= h($msg) ?></div><?php endif; ?>

  <div class="api-svc-card">
    <h3>현재 설정 상태</h3>
    <ul style="font-size:13px;line-height:1.9;">
      <li>회사코드(COM_CODE): <?= $comCode !== '' ? '입력됨' : '<strong style="color:red;">미입력</strong>' ?></li>
      <li>사용자ID(USER_ID): <?= $userId !== '' ? '입력됨' : '<strong style="color:red;">미입력</strong>' ?></li>
      <li>API 인증키: <?= $apiKeySet ? '입력됨' : '<strong style="color:red;">미입력</strong>' ?></li>
      <li>도메인: <strong><?= h($domain) ?></strong> (<?= $domain === 'oapi' ? '실제 운영 계정' : '테스트/샌드박스 계정' ?>)</li>
      <li>대기 중인 재고 전송 요청: <strong><?= $pendingCount ?></strong>건</li>
    </ul>
    <p style="color:#64748b; font-size:13px;">값이 비어있거나 도메인이 이상하면 <a href="/admin/api-settings.php#ecount">연동/API 관리</a>에서 먼저 확인하세요.</p>
  </div>

  <div class="api-svc-card">
    <h3>1단계 — 연결 테스트</h3>
    <p class="api-svc-desc">실제 전표를 만들지 않고, 로그인/세션 발급만 확인합니다. 가장 먼저 눌러보세요.</p>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="test_connection">
      <button type="submit" class="btn-admin-primary">지금 연결 테스트</button>
    </form>
  </div>

  <div class="api-svc-card">
    <h3>2단계 — 재고 전송(Push) — 홈페이지 → 이카운트</h3>
    <p class="api-svc-desc">대기 중인 재고 변경 요청(<?= $pendingCount ?>건)을 이카운트에 실제 전표로 보냅니다.</p>
    <form method="post" style="display:flex; gap:10px; margin-bottom:8px;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="push_now">
      <input type="hidden" name="dry_run" value="1">
      <button type="submit" class="btn-admin-primary" style="background:#64748b;">미리보기만 (DRY-RUN)</button>
    </form>
    <form method="post" onsubmit="return confirm('실제로 이카운트에 전표가 생성됩니다. 진행할까요?');">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="push_now">
      <button type="submit" class="btn-admin-primary" style="background:#dc2626;">실제 전송 실행</button>
    </form>
  </div>

  <div class="api-svc-card">
    <h3>3단계 — 재고 조회 반영(Pull) — 이카운트 → 홈페이지</h3>
    <p class="api-svc-desc">이카운트의 현재 재고현황을 조회해 품목코드가 매핑된 상품의 재고를 갱신합니다.</p>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="pull_now">
      <button type="submit" class="btn-admin-primary">지금 재고 조회 반영</button>
    </form>
  </div>

  <?php if ($lastResult !== null): ?>
  <div class="api-svc-card" style="background:#f8fafc;">
    <h3>방금 실행 결과 (<?= h((string)$lastAction) ?>)</h3>
    <?php if (!empty($lastResult['error'])): ?>
      <p style="color:#dc2626; font-weight:700;">오류: <?= h($lastResult['error']) ?></p>
    <?php endif; ?>
    <p>성공: <?= (int)($lastResult['success'] ?? 0) ?>건 / 실패: <?= (int)($lastResult['fail'] ?? 0) ?>건<?php if (isset($lastResult['skip'])): ?> / 스킵: <?= (int)$lastResult['skip'] ?>건<?php endif; ?></p>
    <?php if (!empty($lastResult['log'])): ?>
      <pre style="white-space:pre-wrap; background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; font-size:12px;"><?= h(implode("\n", $lastResult['log'])) ?></pre>
    <?php endif; ?>
    <?php if (!empty($lastResult['sample'])): ?>
      <p>원본 응답 (구조 인식 실패 시 디버그용):</p>
      <pre style="white-space:pre-wrap; background:#1e293b; color:#fbbf24; padding:12px; border-radius:8px; font-size:12px;"><?= h(json_encode($lastResult['sample'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="api-svc-card">
    <h3>최근 동기화 로그</h3>
    <table class="admin-table" style="width:100%;border-collapse:collapse;">
      <thead><tr><th>시각</th><th>방향</th><th>내용</th><th>성공</th><th>실패</th></tr></thead>
      <tbody>
        <?php foreach ($recentLogs as $l): ?>
        <tr>
          <td><?= h($l['created_at']) ?></td>
          <td><?= h($l['direction']) ?></td>
          <td><?= h($l['summary']) ?></td>
          <td><?= (int)$l['success_count'] ?></td>
          <td><?= (int)$l['fail_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recentLogs): ?><tr><td colspan="5">기록이 없습니다.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
