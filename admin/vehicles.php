<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('vehicles');
ensure_vehicle_tables();

$pdo = Database::connection();
$pageTitle = '차량 교체주기 관리';

$okMsg  = flash('admin_success');
$errMsg = flash('admin_error');

/* ===== POST 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/vehicles.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $months = (int)($_POST['vehicle_cycle_months'] ?? 24);
        if ($months >= 6 && $months <= 96) {
            set_setting('vehicle_cycle_months', (string)$months);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'vehicle_settings', "차량 기본 교체주기 {$months}개월로 설정");
            flash('admin_success', "기본 교체주기를 {$months}개월로 저장했습니다.");
        } else {
            flash('admin_error', '교체주기는 6~96개월 사이로 입력해 주세요.');
        }
        redirect('/admin/vehicles.php');
    }

    if ($action === 'save_vehicle') {
        $id    = (int)($_POST['vehicle_id'] ?? 0);
        $plate = normalize_plate_no((string)($_POST['plate_no'] ?? ''));
        if (!is_valid_plate_no($plate)) {
            flash('admin_error', '차량번호 형식을 확인해 주세요. (예: 12가3456)');
            redirect('/admin/vehicles.php' . ($id ? "?edit={$id}" : ''));
        }
        $fields = [
            'owner_name'  => trim((string)($_POST['owner_name'] ?? '')) ?: null,
            'owner_phone' => trim((string)($_POST['owner_phone'] ?? '')) ?: null,
            'car_model'   => trim((string)($_POST['car_model'] ?? '')) ?: null,
            'cycle_months'=> max(6, min(96, (int)($_POST['cycle_months'] ?? vehicle_cycle_months()))),
            'memo'        => trim((string)($_POST['memo'] ?? '')) ?: null,
        ];
        $lastTire = trim((string)($_POST['last_tire_date'] ?? ''));
        $lastTire = ($lastTire && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastTire)) ? $lastTire : null;

        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE tt_vehicles SET
                        plate_no=:p, owner_name=:oname, owner_phone=:ophone, car_model=:cmodel,
                        cycle_months=:cyc, last_tire_date=COALESCE(:tdate, last_tire_date), memo=:memo
                     WHERE id=:id')
                    ->execute(['p'=>$plate, 'oname'=>$fields['owner_name'], 'ophone'=>$fields['owner_phone'],
                               'cmodel'=>$fields['car_model'], 'cyc'=>$fields['cycle_months'], 'tdate'=>$lastTire,
                               'memo'=>$fields['memo'], 'id'=>$id]);
                AdminAuth::log((int)AdminAuth::currentAdminId(), 'vehicle_update', "차량 {$plate} 정보 수정");
                flash('admin_success', '차량 정보를 수정했습니다.');
            } else {
                $stmt = $pdo->prepare('SELECT id FROM tt_vehicles WHERE plate_no = :p LIMIT 1');
                $stmt->execute(['p' => $plate]);
                if ($stmt->fetch()) {
                    flash('admin_error', "이미 등록된 차량입니다 ({$plate}). 목록에서 수정해 주세요.");
                    redirect('/admin/vehicles.php');
                }
                $pdo->prepare('INSERT INTO tt_vehicles
                        (plate_no, owner_name, owner_phone, car_model, cycle_months, last_tire_date, memo)
                     VALUES (:p, :oname, :ophone, :cmodel, :cyc, :tdate, :memo)')
                    ->execute(['p'=>$plate, 'oname'=>$fields['owner_name'], 'ophone'=>$fields['owner_phone'],
                               'cmodel'=>$fields['car_model'], 'cyc'=>$fields['cycle_months'], 'tdate'=>$lastTire,
                               'memo'=>$fields['memo']]);
                AdminAuth::log((int)AdminAuth::currentAdminId(), 'vehicle_add', "차량 {$plate} 등록");
                flash('admin_success', '차량을 등록했습니다.');
            }
        } catch (Throwable $e) {
            error_log('[admin/vehicles save] ' . $e->getMessage());
            flash('admin_error', '저장 중 오류가 발생했습니다.');
        }
        redirect('/admin/vehicles.php');
    }

    if ($action === 'delete_vehicle') {
        $id = (int)($_POST['vehicle_id'] ?? 0);
        try {
            $pdo->prepare('DELETE FROM tt_vehicle_reminders WHERE vehicle_id = :id')->execute(['id'=>$id]);
            $pdo->prepare('DELETE FROM tt_vehicles WHERE id = :id')->execute(['id'=>$id]);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'vehicle_delete', "차량 #{$id} 삭제");
            flash('admin_success', '차량을 삭제했습니다.');
        } catch (Throwable $e) {
            flash('admin_error', '삭제 중 오류가 발생했습니다.');
        }
        redirect('/admin/vehicles.php');
    }

    if ($action === 'send_reminder') {
        $id = (int)($_POST['vehicle_id'] ?? 0);
        $result = send_vehicle_reminder($id, (string)AdminAuth::currentAdminName());
        if ($result['ok']) flash('admin_success', '리마인더 문자를 발송했습니다. ' . $result['msg']);
        else flash('admin_error', '발송 실패: ' . $result['msg']);
        redirect('/admin/vehicles.php');
    }

    if ($action === 'send_reminder_bulk') {
        $ids = array_map('intval', (array)($_POST['vehicle_ids'] ?? []));
        $ids = array_values(array_filter($ids, fn($v) => $v > 0));
        $sent = 0; $fail = 0;
        foreach ($ids as $vid) {
            $r = send_vehicle_reminder($vid, (string)AdminAuth::currentAdminName());
            $r['ok'] ? $sent++ : $fail++;
        }
        if ($sent > 0) flash('admin_success', "리마인더 {$sent}건 발송" . ($fail ? " / 실패 {$fail}건" : '') . '.');
        else flash('admin_error', '발송에 실패했습니다. 연락처가 등록된 차량인지 확인해 주세요.');
        redirect('/admin/vehicles.php');
    }
    redirect('/admin/vehicles.php');
}

/* ===== 수정 대상 ===== */
$editVehicle = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM tt_vehicles WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* ===== 필터 ===== */
$filterView = $_GET['view'] ?? 'all';
if (!in_array($filterView, ['all', 'due', 'over', 'none'], true)) $filterView = 'all';
$search = trim((string)($_GET['q'] ?? ''));

$where = 'WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (plate_no LIKE :q1 OR owner_name LIKE :q2 OR owner_phone LIKE :q3 OR car_model LIKE :q4 OR last_tire_product LIKE :q5)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = $params[':q5'] = '%' . $search . '%';
}

$cycleDefault = vehicle_cycle_months();

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/* 상태 필터는 PHP에서 계산 (due/over는 날짜 연산 필요) */
$stmt = $pdo->prepare("SELECT * FROM tt_vehicles {$where} ORDER BY last_tire_date DESC, id DESC");
$stmt->execute($params);
$allVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$vehicles = [];
foreach ($allVehicles as $v) {
    $info = vehicle_due_info($v);
    if ($filterView === 'due' && !in_array($info['status'], ['due'], true)) continue;
    if ($filterView === 'over' && $info['status'] !== 'over') continue;
    if ($filterView === 'none' && $info['status'] !== 'none') continue;
    $v['_due'] = $info;
    $vehicles[] = $v;
}

$total = count($vehicles);
$totalPages = max(1, (int)ceil($total / $perPage));
$vehicles = array_slice($vehicles, $offset, $perPage);

/* 통계 */
$statCounts = ['all' => count($allVehicles), 'due' => 0, 'over' => 0, 'none' => 0];
foreach ($allVehicles as $v) {
    $info = vehicle_due_info($v);
    if ($info['status'] === 'due') $statCounts['due']++;
    elseif ($info['status'] === 'over') $statCounts['over']++;
    elseif ($info['status'] === 'none') $statCounts['none']++;
}

/* 최근 리마인더 이력 */
$recentReminders = [];
try {
    $recentReminders = $pdo->query(
        'SELECT r.*, v.plate_no, v.owner_name
         FROM tt_vehicle_reminders r JOIN tt_vehicles v ON v.id = r.vehicle_id
         ORDER BY r.id DESC LIMIT 10'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$returnQuery = http_build_query(array_filter(['view'=>$filterView, 'q'=>$search ?: null]));

require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🚗 차량 교체주기 관리</h2>
      <p class="admin-list-sub">작업일지·예약에서 자동 수집된 차량의 타이어 교체주기를 관리하고, 교체 시기가 되면 문자로 안내합니다.</p>
    </div>
  </div>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= h($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-error"><?= h($errMsg) ?></div><?php endif; ?>

  <!-- 통계 + 필터 -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
    <?php
    $viewTabs = [
        'all'  => ['전체', count($allVehicles)],
        'due'  => ['⏰ 교체 임박', $statCounts['due']],
        'over' => ['❗ 교체 경과', $statCounts['over']],
        'none' => ['미교체 이력', $statCounts['none']],
    ];
    foreach ($viewTabs as $vk => [$vl, $vc]): ?>
    <a href="?view=<?= $vk ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
       class="btn-admin-<?= $filterView === $vk ? 'primary' : 'secondary' ?>"
       style="padding:8px 16px;font-size:13px;text-decoration:none;"><?= h($vl) ?> <b><?= number_format($vc) ?></b></a>
    <?php endforeach; ?>
  </div>

  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
    <input type="hidden" name="view" value="<?= h($filterView) ?>">
    <div style="flex:1;min-width:220px">
      <label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">검색 (차량번호/고객명/연락처/차종)</label>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="예: 12가3456, 김철수"
             style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
    </div>
    <button type="submit" class="btn-admin-primary" style="padding:9px 18px;font-size:13px">검색</button>
  </form>

  <!-- 차량 등록/수정 폼 -->
  <div style="background:#f8fafc;border-radius:12px;padding:16px;margin-bottom:18px;">
    <h3 style="font-size:15px;font-weight:800;margin:0 0 12px;"><?= $editVehicle ? '✏️ 차량 수정 — ' . h($editVehicle['plate_no']) : '➕ 차량 등록 (직접 입력)' ?></h3>
    <?php if ($editVehicle): ?><a href="/admin/vehicles.php" class="btn-admin-secondary" style="padding:6px 12px;font-size:12px;text-decoration:none;margin-bottom:10px;display:inline-block;">↩ 신규 등록으로 전환</a><?php endif; ?>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save_vehicle">
      <input type="hidden" name="vehicle_id" value="<?= (int)($editVehicle['id'] ?? 0) ?>">
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">차량번호 *</label>
        <input type="text" name="plate_no" required placeholder="12가3456" value="<?= h((string)($editVehicle['plate_no'] ?? '')) ?>"
               style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">차종</label>
        <input type="text" name="car_model" placeholder="그랜저 IG" value="<?= h((string)($editVehicle['car_model'] ?? '')) ?>"
               style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">고객명</label>
        <input type="text" name="owner_name" placeholder="김철수" value="<?= h((string)($editVehicle['owner_name'] ?? '')) ?>"
               style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">연락처 (리마인더 발송)</label>
        <input type="text" name="owner_phone" placeholder="010-1234-5678" value="<?= h((string)($editVehicle['owner_phone'] ?? '')) ?>"
               style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">최근 교체일</label>
        <input type="date" name="last_tire_date" value="<?= h((string)($editVehicle['last_tire_date'] ?? '')) ?>"
               style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:3px">교체주기 (개월)</label>
        <input type="number" name="cycle_months" min="6" max="96" value="<?= (int)($editVehicle['cycle_months'] ?? $cycleDefault) ?>"
               style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px">
      </div>
      <div style="display:flex;align-items:flex-end;">
        <button type="submit" class="btn-admin-primary" style="padding:9px 18px;font-size:13px;"><?= $editVehicle ? '💾 수정 저장' : '➕ 차량 등록' ?></button>
      </div>
    </form>
  </div>

  <!-- 목록 (일괄 리마인더 발송) -->
  <form method="post" id="bulkForm"
        onsubmit="return confirm('선택한 차량에 리마인더 문자를 발송할까요?\n(연락처가 등록된 차량만 발송됩니다)');">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="send_reminder_bulk">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
      <div style="font-size:14px;font-weight:800;">차량 목록 <span style="font-size:12px;color:#64748b;font-weight:500;"><?= number_format($total) ?>대</span></div>
      <button type="submit" class="btn-admin-primary" id="bulkBtn" disabled style="padding:8px 16px;font-size:13px;">📩 선택 차량 리마인더 발송</button>
    </div>

    <div class="admin-table-wrap" style="overflow-x:auto">
      <table class="admin-table">
        <thead>
          <tr>
            <th style="width:36px"><input type="checkbox" id="chkAll"></th>
            <th>차량번호</th><th>차종</th><th>고객/연락처</th><th>최근 교체</th><th>권장 교체일</th><th>상태</th><th>최근 리마인더</th><th>관리</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($vehicles)): ?>
          <tr><td colspan="9" class="empty">조건에 맞는 차량이 없습니다. 작업일지·예약이 쌓이면 자동 등록되거나, 위 폼에서 직접 등록할 수 있습니다.</td></tr>
          <?php else: foreach ($vehicles as $v): $info = $v['_due']; ?>
          <tr <?= $info['status'] === 'over' ? 'style="background:#fef2f2;"' : ($info['status'] === 'due' ? 'style="background:#fffbeb;"' : '') ?>>
            <td><input type="checkbox" name="vehicle_ids[]" value="<?= (int)$v['id'] ?>" class="chkRow"></td>
            <td><b class="mono"><?= h($v['plate_no']) ?></b></td>
            <td><?= h($v['car_model'] ?: '-') ?></td>
            <td>
              <?= h($v['owner_name'] ?: '-') ?><br>
              <small class="text-muted"><?= h($v['owner_phone'] ?: '연락처 없음') ?></small>
            </td>
            <td style="font-size:12px;">
              <?= $v['last_tire_date'] ? h(date('Y.m.d', strtotime($v['last_tire_date']))) : '-' ?>
              <?php if ($v['last_tire_product']): ?><br><small class="text-muted"><?= h(mb_strimwidth($v['last_tire_product'], 0, 24, '…')) ?></small><?php endif; ?>
            </td>
            <td style="font-size:12px;">
              <?php if ($info['due_date']): ?>
                <b><?= h(date('Y.m.d', strtotime($info['due_date']))) ?></b><br>
                <small class="text-muted"><?= $info['days_left'] >= 0 ? 'D-' . (int)$info['days_left'] : 'D+' . abs((int)$info['days_left']) ?></small>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td>
              <?php if ($info['status'] === 'over'): ?><span style="color:#dc2626;font-weight:800;font-size:12px;">❗ <?= h($info['status_label']) ?></span>
              <?php elseif ($info['status'] === 'due'): ?><span style="color:#f59e0b;font-weight:800;font-size:12px;">⏰ <?= h($info['status_label']) ?></span>
              <?php elseif ($info['status'] === 'none'): ?><span style="color:#94a3b8;font-weight:700;font-size:12px;"><?= h($info['status_label']) ?></span>
              <?php else: ?><span style="color:#16a34a;font-weight:700;font-size:12px;">✓ <?= h($info['status_label']) ?></span><?php endif; ?>
            </td>
            <td style="font-size:11px;color:#64748b;"><?= $v['reminder_sent_at'] ? h(date('m/d H:i', strtotime($v['reminder_sent_at']))) : '-' ?></td>
            <td style="white-space:nowrap;">
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('<?= h($v['owner_name'] ?: $v['plate_no']) ?> 차량에 리마인더 문자를 발송할까요?');">
                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                <input type="hidden" name="action" value="send_reminder">
                <input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
                <button type="submit" class="btn btn-sm" style="padding:4px 10px;font-size:12px;">📩 발송</button>
              </form>
              <a class="btn btn-sm" href="?edit=<?= (int)$v['id'] ?>" style="padding:4px 10px;font-size:12px;">✏️ 수정</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('이 차량을 삭제할까요?');">
                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                <input type="hidden" name="action" value="delete_vehicle">
                <input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" style="padding:4px 10px;font-size:12px;">🗑</button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a class="<?= $p === $page ? 'current' : '' ?>" href="?<?= h($returnQuery) . '&page=' . $p ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </form>

  <!-- 기본 교체주기 설정 -->
  <div style="display:flex;gap:10px;align-items:flex-end;background:#f0f9ff;border-radius:12px;padding:14px 16px;margin-top:18px;flex-wrap:wrap;">
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save_settings">
      <div>
        <label style="font-size:11px;font-weight:700;color:#0369a1;display:block;margin-bottom:3px">기본 교체주기 (개월) — 신규 차량에 적용</label>
        <input type="number" name="vehicle_cycle_months" min="6" max="96" value="<?= $cycleDefault ?>"
               style="padding:8px 10px;border:1.5px solid #bae6fd;border-radius:8px;font-size:13px;width:110px">
      </div>
      <button type="submit" class="btn-admin-primary" style="padding:9px 16px;font-size:13px;">저장</button>
    </form>
    <div style="font-size:12px;color:#0369a1;">타이어는 통상 2년(24개월) 전후 권장 — 차량별로 개별 설정도 가능합니다.</div>
  </div>

  <!-- 리마인더 발송 이력 -->
  <div style="margin-top:22px;">
    <h3 style="font-size:15px;font-weight:800;margin:0 0 10px;">📩 최근 리마인더 발송 이력</h3>
    <?php if (empty($recentReminders)): ?>
    <p style="color:#94a3b8;font-size:13px;padding:16px 0;">발송 이력이 없습니다.</p>
    <?php else: ?>
    <table class="admin-table">
      <thead><tr><th>발송 시각</th><th>차량</th><th>고객</th><th>결과</th><th>발송자</th><th>내용</th></tr></thead>
      <tbody>
        <?php foreach ($recentReminders as $r): ?>
        <tr>
          <td style="font-size:12px;"><?= h(date('Y-m-d H:i', strtotime($r['sent_at']))) ?></td>
          <td class="mono"><b><?= h($r['plate_no']) ?></b></td>
          <td><?= h($r['owner_name'] ?: '-') ?></td>
          <td><?= $r['result_ok'] ? '<span style="color:#16a34a;font-weight:700;">성공</span>' : '<span style="color:#dc2626;font-weight:700;">실패</span>' ?></td>
          <td style="font-size:12px;"><?= h($r['sent_by'] ?: '-') ?></td>
          <td style="font-size:11px;color:#64748b;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h((string)$r['message']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var chkAll = document.getElementById('chkAll');
  var bulkBtn = document.getElementById('bulkBtn');
  function update(){
    var checked = document.querySelectorAll('.chkRow:checked').length;
    bulkBtn.disabled = checked === 0;
    bulkBtn.textContent = checked > 0 ? '📩 선택 ' + checked + '대 리마인더 발송' : '📩 선택 차량 리마인더 발송';
  }
  if (chkAll) {
    chkAll.addEventListener('change', function(){
      document.querySelectorAll('.chkRow').forEach(function(r){ r.checked = chkAll.checked; });
      update();
    });
  }
  document.querySelectorAll('.chkRow').forEach(function(r){ r.addEventListener('change', update); });
  update();
})();
</script>

<style>
@media(max-width:768px){ .admin-table-wrap table{min-width:980px} }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
