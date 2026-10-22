<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requireLogin();
if (!AdminAuth::isSuper()) {
    flash('admin_error', '연동/API 관리는 최고관리자만 접근할 수 있습니다.');
    redirect('/admin/index.php');
}

$pdo = Database::connection();

/* ===== 연동 서비스 정의 =====
   각 연동항목: 키(tt_site_settings 저장 키), 라벨, 설명, 필드 목록 */
$integrations = [
    'ecount' => [
        'title' => '📊 이카운트 (ECOUNT)',
        'desc' => '이카운트 ERP와 연동하여 주문·세금계산서 데이터를 자동으로 전송합니다.',
        'doc' => 'https://www.ecount.kr',
        'fields' => [
            ['ecount_com_code', '회사코드 (COM_CODE)'],
            ['ecount_user_id', '사용자 ID'],
            ['ecount_api_key', 'API 인증키', 'password'],
            /* [FIX] Open API 전용 도메인 — 반드시 sboapi 또는 oapi 둘 중 하나.
               쇼핑몰 자사 도메인이나 ERP 웹 로그인 주소(xxx.ecounterp.com)가 아님. */
            ['ecount_domain', '도메인 — Open API용 (테스트계정: sboapi / 실운영계정: oapi)'],
        ],
    ],
    'kakaotalk' => [
        'title' => '💬 카카오톡 알림톡',
        'desc' => '주문확인·배송시작·예약확정 등의 알림톡을 자동 발송합니다. (알리고/카카오 비즈메시지)',
        'doc' => 'https://smartsms.aligo.in',
        'fields' => [
            ['kakao_aligo_key', '알리고 API KEY', 'password'],
            ['kakao_aligo_user', '알리고 사용자 ID'],
            ['kakao_sender', '발신번호 (예: 02-000-0000)'],
            ['kakao_sender_key', '알림톡 발신프로필 키 (Sender Key)', 'password'],
        ],
    ],
    'naver_smartstore' => [
        'title' => '🟢 네이버 스마트스토어',
        'desc' => '스마트스토어 상품·주문을 이 쇼핑몰과 연동합니다. (네이버 커머스 API)',
        'doc' => 'https://developers.naver.com',
        'fields' => [
            ['naver_client_id', 'Client ID (애플리케이션 등록 시 발급)'],
            ['naver_client_secret', 'Client Secret', 'password'],
            ['naver_merchant_no', '상점번호 (Merchant No)'],
            ['naver_account', '판매자 계정 (로그인 ID)'],
        ],
    ],
    'sms' => [
        'title' => '📱 SMS 문자발송 (알리고)',
        'desc' => '휴대폰 인증번호와 각종 안내 문자를 발송하는 기본 SMS 게이트웨이 설정입니다.',
        'doc' => 'https://smartsms.aligo.in',
        'fields' => [
            ['sms_api_key', '알리고 API KEY', 'password'],
            ['sms_user_id', '알리고 사용자 ID'],
            ['sms_sender', '발신번호'],
        ],
    ],
    'pg' => [
        'title' => '💳 PG 결제 연동',
        'desc' => 'PG사(이니시스/토스페이먼츠 등) 연동 정보입니다. 실결제 연동 시 필요합니다.',
        'doc' => '',
        'fields' => [
            ['pg_mid', '상점 ID (MID)'],
            ['pg_api_key', 'PG API 키', 'password'],
            ['pg_provider', 'PG사명 (예: 이니시스, 토스페이먼츠)'],
        ],
    ],
];

/* ===== 저장 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        flash('admin_error', '잘못된 요청입니다.');
        redirect('/admin/api-settings.php');
    }
    $svc = $_POST['service'] ?? '';
    if (!isset($integrations[$svc])) {
        flash('admin_error', '알 수 없는 연동 서비스입니다.');
        redirect('/admin/api-settings.php');
    }
    try {
        $saved = 0;
        foreach ($integrations[$svc]['fields'] as $field) {
            $key = $field[0];
            $val = trim($_POST[$key] ?? '');
            if ($val !== '') { set_setting($key, $val); $saved++; }
            else { /* 빈 값 저장 안 함 — 기존 값 유지 */ }
        }
        AdminAuth::log((int)AdminAuth::currentAdminId(), 'api_settings_save', "외부 연동 설정 저장: {$svc} ({$saved}건)");
        flash('admin_success', $integrations[$svc]['title'] . ' 설정이 저장되었습니다. (' . $saved . '개 항목)');
    } catch (Throwable $e) {
        error_log('[admin/api-settings save] ' . $e->getMessage());
        flash('admin_error', '저장 중 오류가 발생했습니다.');
    }
    redirect('/admin/api-settings.php#' . $svc);
}

/* 상태 요약 (설정된 필드 수 / 전체 필드 수) */
foreach ($integrations as $svc => &$cfg) {
    $filled = 0;
    foreach ($cfg['fields'] as $f) {
        $v = get_setting($f[0], '');
        if ($v !== '') $filled++;
    }
    $cfg['filled'] = $filled;
    $cfg['total'] = count($cfg['fields']);
    $cfg['ready'] = ($filled === count($cfg['fields']));
}
unset($cfg);

$pageTitle = '외부 연동 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">🔗 외부 연동 관리</h2>
      <p class="admin-list-sub">이카운트 · 카카오톡 알림톡 · 네이버 스마트스토어 · SMS · PG 결제 API를 등록하고 관리합니다.</p>
    </div>
  </div>

  <!-- 연동 현황 요약 카드 -->
  <div class="api-status-grid">
    <?php foreach ($integrations as $svc => $cfg): ?>
    <a href="#<?= $svc ?>" class="api-status-card <?= $cfg['ready'] ? 'on' : '' ?>">
      <span class="api-status-title"><?= $cfg['title'] ?></span>
      <span class="api-status-badge"><?= $cfg['ready'] ? '✅ 연동준비' : '⚙️ ' . $cfg['filled'] . '/' . $cfg['total'] . ' 입력' ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($integrations as $svc => $cfg): ?>
  <div class="api-svc-card" id="<?= $svc ?>">
    <div class="api-svc-head">
      <h3><?= $cfg['title'] ?></h3>
      <span class="api-svc-badge <?= $cfg['ready'] ? 'ready' : 'notready' ?>">
        <?= $cfg['ready'] ? '설정 완료' : '미설정 · ' . $cfg['filled'] . '/' . $cfg['total'] . ' 항목' ?>
      </span>
    </div>
    <p class="api-svc-desc"><?= h($cfg['desc']) ?>
      <?php if (!empty($cfg['doc'])): ?>
      <a href="<?= h($cfg['doc']) ?>" target="_blank" rel="noopener" class="api-svc-doc">📚 API 발급 안내 ↗</a>
      <?php endif; ?>
    </p>

    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="service" value="<?= h($svc) ?>">
      <div class="api-field-grid">
        <?php foreach ($cfg['fields'] as $field):
            [$key, $label] = $field;
            $isPw = ($field[2] ?? '') === 'password';
            $current = (string)get_setting($key, '');
            // 저장된 값 마스킹 표시 (입력값은 새로 넣을 때만 저장)
            $masked = $current !== '' ? mb_substr($current, 0, 3) . str_repeat('•', 8) : '';
        ?>
        <div class="api-field">
          <label><?= h($label) ?></label>
          <?php if ($isPw): ?>
          <input type="password" name="<?= h($key) ?>" value="" placeholder="<?= $masked !== '' ? '저장됨 (' . $masked . ') — 변경 시 입력' : '미설정' ?>" autocomplete="new-password">
          <?php else: ?>
          <input type="text" name="<?= h($key) ?>" value="<?= h($current) ?>" placeholder="미설정" autocomplete="off">
          <?php endif; ?>
          <?php if ($isPw && $current !== ''): ?>
          <p class="api-field-hint">⚠️ 보안을 위해 저장된 값은 마스킹 표시됩니다. 변경할 때만 새 값을 입력하세요. (빈 칸이면 기존 값 유지)</p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="api-svc-actions">
        <button type="submit" class="btn-admin-primary">이 설정 저장</button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>

  <div class="api-note-box">
    <h4>ℹ️ 연동 사용 안내</h4>
    <ul>
      <li>이 페이지는 <b>연동 정보를 안전하게 등록·보관</b>하는 설정 저장소입니다.</li>
      <li>각 API 키는 해당 서비스(이카운트/알리고/네이버/PG사)에서 발급받아 입력해주세요.</li>
      <li>비밀키(API KEY/Secret)는 마스킹되어 표시되며, <b>빈 칸으로 두면 기존 값이 유지</b>됩니다.</li>
      <li>SMS(알리고) 키를 등록하면 휴대폰 인증번호가 실제 문자로 발송됩니다. 등록 전에는 무료 모드(화면 표시)로 동작합니다.</li>
      <li>설정 저장은 관리자 감사 로그에 기록됩니다.</li>
    </ul>
  </div>
</div>

<style>
.api-status-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:22px;}
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
.api-svc-doc{color:#2563eb;font-weight:700;text-decoration:none;margin-left:6px;font-size:11px;}
.api-svc-doc:hover{text-decoration:underline;}
.api-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.api-field label{display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:5px;}
.api-field input{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;transition:border-color .15s,box-shadow .15s;background:#fff;}
.api-field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.api-field-hint{font-size:10.5px;color:#94a3b8;margin-top:4px;line-height:1.5;}
.api-svc-actions{margin-top:14px;}
.api-note-box{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:16px 18px;margin-top:8px;}
.api-note-box h4{font-size:13px;font-weight:800;color:#0c4a6e;margin:0 0 8px;}
.api-note-box ul{padding-left:18px;margin:0;}
.api-note-box li{font-size:12px;color:#075985;line-height:1.8;}
@media(max-width:900px){.api-status-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:640px){.api-status-grid{grid-template-columns:1fr;}.api-field-grid{grid-template-columns:1fr;}}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
