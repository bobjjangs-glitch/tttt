<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();
if (!AdminAuth::isSuper()) {
    flash('admin_error', 'SNS 관리는 최고관리자만 접근할 수 있습니다.');
    redirect('/admin/index.php');
}

/* ===== SNS 채널 정의 ===== */
$snsChannels = [
    'kakao_channel' => [
        'title' => '💬 카카오톡 채널',
        'desc' => '카카오톡 채널(옐로아이디) 연결 — 고객 1:1 상담 채널로 연결됩니다.',
        'fields' => [
            ['kakao_channel_url', '채널 URL (예: http://pf.kakao.com/_xxxxxx)'],
            ['kakao_channel_id', '채널 ID (검색용)'],
            ['kakao_channel_key', '채널 API 키 (필요 시)', 'password'],
        ],
    ],
    'naver_talk' => [
        'title' => '🟢 네이버 톡톡',
        'desc' => '네이버 톡톡 상담 연결 — 스마트스토어 연동 시 함께 사용합니다.',
        'fields' => [
            ['naver_talk_url', '톡톡 URL'],
            ['naver_talk_id', '톡톡 상담 ID'],
        ],
    ],
    'instagram' => [
        'title' => '📷 인스타그램',
        'desc' => '매장 인스타그램 계정 — 사이트 하단·고객센터에 아이콘으로 노출됩니다.',
        'fields' => [
            ['instagram_url', '프로필 URL (https://instagram.com/...)'],
            ['instagram_id', '계정 ID (@ 없이)'],
        ],
    ],
    'youtube' => [
        'title' => '▶️ 유튜브',
        'desc' => '매장 유튜브 채널 — 시공 영상 등을 연결합니다.',
        'fields' => [
            ['youtube_url', '채널 URL'],
            ['youtube_id', '채널명'],
        ],
    ],
    'facebook' => [
        'title' => '📘 페이스북',
        'desc' => '매장 페이스북 페이지 — 이벤트/공지 채널로 사용합니다.',
        'fields' => [
            ['facebook_url', '페이지 URL'],
            ['facebook_id', '페이지명'],
        ],
    ],
];

/* 저장 */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/sns-settings.php');
    }
    $svc = $_POST['channel'] ?? '';
    if (!isset($snsChannels[$svc])) {
        flash('admin_error', '알 수 없는 SNS 채널입니다.');
        redirect('/admin/sns-settings.php');
    }
    try {
        $saved = 0;
        foreach ($snsChannels[$svc]['fields'] as $field) {
            $key = $field[0];
            $val = trim($_POST[$key] ?? '');
            if ($val !== '') { set_setting($key, $val); $saved++; }
        }
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'sns_settings_save', "SNS 채널 설정 저장: {$svc} ({$saved}건)");
        flash('admin_success', $snsChannels[$svc]['title'] . ' 설정이 저장되었습니다. (' . $saved . '개 항목)');
    } catch (Throwable $e) {
        error_log('[admin/sns-settings save] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect('/admin/sns-settings.php#' . $svc);
}

/* 상태 */
foreach ($snsChannels as $svc => &$cfg) {
    $filled = 0;
    foreach ($cfg['fields'] as $f) {
        if (get_setting($f[0], '') !== '') $filled++;
    }
    $cfg['filled'] = $filled;
    $cfg['total'] = count($cfg['fields']);
    $cfg['ready'] = ($filled > 0);
}
unset($cfg);

$pageTitle = 'SNS 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">💬 SNS 관리</h2>
      <p class="admin-list-sub">카카오톡 채널 · 네이버 톡톡 · 인스타그램 · 유튜브 · 페이스북 등 매장 SNS 채널을 등록·관리합니다.</p>
    </div>
  </div>

  <!-- 채널 현황 -->
  <div class="api-status-grid" style="grid-template-columns:repeat(5,1fr);">
    <?php foreach ($snsChannels as $svc => $cfg): ?>
    <a href="#<?= $svc ?>" class="api-status-card <?= $cfg['ready'] ? 'on' : '' ?>">
      <span class="api-status-title"><?= $cfg['title'] ?></span>
      <span class="api-status-badge"><?= $cfg['ready'] ? '✅ 연결됨' : '⚙️ 미설정' ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($snsChannels as $svc => $cfg): ?>
  <div class="api-svc-card" id="<?= $svc ?>">
    <div class="api-svc-head">
      <h3><?= $cfg['title'] ?></h3>
      <span class="api-svc-badge <?= $cfg['ready'] ? 'ready' : 'notready' ?>">
        <?= $cfg['ready'] ? '연결됨' : '미설정 · ' . $cfg['filled'] . '/' . $cfg['total'] . ' 항목' ?>
      </span>
    </div>
    <p class="api-svc-desc"><?= h($cfg['desc']) ?></p>

    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="channel" value="<?= h($svc) ?>">
      <div class="api-field-grid">
        <?php foreach ($cfg['fields'] as $field):
            [$key, $label] = $field;
            $isPw = ($field[2] ?? '') === 'password';
            $current = (string)get_setting($key, '');
            $masked = $current !== '' ? mb_substr($current, 0, 3) . str_repeat('•', 8) : '';
        ?>
        <div class="api-field">
          <label><?= h($label) ?></label>
          <?php if ($isPw): ?>
          <input type="password" name="<?= h($key) ?>" value="" placeholder="<?= $masked !== '' ? '저장됨 (' . $masked . ') — 변경 시 입력' : '미설정' ?>" autocomplete="new-password">
          <?php else: ?>
          <input type="text" name="<?= h($key) ?>" value="<?= h($current) ?>" placeholder="미설정" autocomplete="off">
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="api-svc-actions">
        <button type="submit" class="btn-admin-primary">이 채널 저장</button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>

  <div class="api-note-box">
    <h4>ℹ️ SNS 채널 사용 안내</h4>
    <ul>
      <li>채널 URL/ID를 등록하면 사이트 <b>고객센터·푸터의 SNS 아이콘</b>에 자동 연결됩니다.</li>
      <li>카카오톡 채널은 챗봇의 "상담원 연결" 대응 채널로도 사용할 수 있습니다.</li>
      <li>API 키가 필요한 항목은 마스킹되어 표시되며, 빈 칸으로 두면 기존 값이 유지됩니다.</li>
    </ul>
  </div>
</div>

<link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/admin.css">
<style>
.api-status-grid{display:grid;gap:10px;margin-bottom:22px;}
.api-status-card{display:flex;flex-direction:column;gap:6px;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px;text-decoration:none;transition:border-color .15s,background .15s;}
.api-status-card:hover{border-color:#cbd5e1;}
.api-status-card.on{border-color:#86efac;background:#f0fdf4;}
.api-status-title{font-size:12px;font-weight:800;color:#0f172a;}
.api-status-badge{font-size:10px;font-weight:800;color:#64748b;}
.api-svc-card{border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin-bottom:16px;scroll-margin-top:80px;}
.api-svc-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px;}
.api-svc-head h3{font-size:15px;font-weight:800;color:#0f172a;margin:0;}
.api-svc-badge{font-size:10px;font-weight:800;padding:4px 10px;border-radius:999px;}
.api-svc-badge.ready{background:#dcfce7;color:#15803d;}
.api-svc-badge.notready{background:#fef3c7;color:#92400e;}
.api-svc-desc{font-size:12px;color:#64748b;line-height:1.7;margin:0 0 14px;}
.api-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.api-field label{display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:5px;}
.api-field input{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;transition:border-color .15s,box-shadow .15s;background:#fff;}
.api-field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.api-svc-actions{margin-top:14px;}
.api-note-box{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:16px 18px;margin-top:8px;}
.api-note-box h4{font-size:13px;font-weight:800;color:#0c4a6e;margin:0 0 8px;}
.api-note-box ul{padding-left:18px;margin:0;}
.api-note-box li{font-size:12px;color:#075985;line-height:1.8;}
@media(max-width:640px){.api-field-grid{grid-template-columns:1fr;}}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
