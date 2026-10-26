<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
AdminAuth::requirePermission('chatbot'); // 고객응대 권한 (chatbot 모듈)
ensure_chatbot_table();

$pdo = Database::connection();

/* ===== 등록 / 수정 / 삭제 / 활성 토글 처리 ===== */
if (is_post()) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        flash('admin_error', '유효하지 않은 요청입니다.');
        redirect('/admin/chatbot.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $entryId  = (int)($_POST['entry_id'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        $keywords = trim($_POST['keywords'] ?? '');
        $answer   = trim($_POST['answer'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($question === '' || $keywords === '' || $answer === '') {
            flash('admin_error', '질문, 키워드, 응답 내용을 모두 입력해주세요.');
            redirect('/admin/chatbot.php');
        }
        if (mb_strlen($question, 'UTF-8') > 200 || mb_strlen($keywords, 'UTF-8') > 255) {
            flash('admin_error', '질문은 200자, 키워드는 255자 이내로 입력해주세요.');
            redirect('/admin/chatbot.php');
        }

        if ($entryId > 0) {
            $pdo->prepare('UPDATE tt_chatbot_entries SET question = :q, keywords = :k, answer = :a, sort_order = :s WHERE id = :id')
                ->execute(['q' => $question, 'k' => $keywords, 'a' => $answer, 's' => $sortOrder, 'id' => $entryId]);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'chatbot_update', "챗봇 응답 수정 #{$entryId}: {$question}");
            flash('admin_success', '챗봇 응답이 수정되었습니다.');
        } else {
            $pdo->prepare('INSERT INTO tt_chatbot_entries (question, keywords, answer, sort_order, is_active) VALUES (:q, :k, :a, :s, 1)')
                ->execute(['q' => $question, 'k' => $keywords, 'a' => $answer, 's' => $sortOrder]);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'chatbot_create', "챗봇 응답 등록: {$question}");
            flash('admin_success', '챗봇 응답이 등록되었습니다.');
        }
    } elseif ($action === 'delete') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        if ($entryId > 0) {
            $pdo->prepare('DELETE FROM tt_chatbot_entries WHERE id = :id')->execute(['id' => $entryId]);
            AdminAuth::log((int)AdminAuth::currentAdminId(), 'chatbot_delete', "챗봇 응답 삭제 #{$entryId}");
            flash('admin_success', '챗봇 응답이 삭제되었습니다.');
        }
    } elseif ($action === 'toggle') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        if ($entryId > 0) {
            $pdo->prepare('UPDATE tt_chatbot_entries SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $entryId]);
            flash('admin_success', '표시 상태가 변경되었습니다.');
        }
    }
    redirect('/admin/chatbot.php');
}

/* ===== 목록 조회 ===== */
$entries = $pdo->query('SELECT id, question, keywords, answer, sort_order, is_active, updated_at
                        FROM tt_chatbot_entries
                        ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);

$editId = (int)($_GET['edit'] ?? 0);
$editEntry = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tt_chatbot_entries WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $editEntry = $stmt->fetch();
}

$pageTitle = 'AI 챗봇 관리';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
  <div class="admin-list-header">
    <div>
      <h2 class="admin-list-title">💬 AI 챗봇 관리</h2>
      <p class="admin-list-sub">사이트 우측 하단 챗봇에 표시되는 질문·응답을 관리합니다. 키워드가 사용자 메시지와 겹치면 해당 응답이 자동으로 답변됩니다.</p>
    </div>
  </div>

  <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:12px;color:#075985;line-height:1.7">
    <b>💡 키워드 작성법</b> — 쉼표(,)로 구분해 여러 개 등록할 수 있습니다. (예: <code>배송,얼마,기간,걸리</code>)<br>
    사용자가 "배송 얼마나 걸려요?"라고 물으면 "배송" 키워드가 매칭되어 등록한 응답이 자동 답변됩니다. 키워드가 많고 길수록 우선 매칭됩니다.
  </div>

  <!-- 등록/수정 폼 -->
  <div class="cb-form" id="cbForm">
    <h3 class="cb-form-title"><?= $editEntry ? '응답 수정' : '새 응답 등록' ?></h3>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="entry_id" value="<?= (int)($editEntry['id'] ?? 0) ?>">

      <div class="cb-form-row">
        <label>대표 질문 <span class="req">*</span></label>
        <input type="text" name="question" value="<?= h($editEntry['question'] ?? '') ?>" maxlength="200" placeholder="예) 배송은 얼마나 걸리나요?" required>
        <p class="cb-hint">챗봇 첫 화면의 추천 질문 버튼에 표시됩니다.</p>
      </div>

      <div class="cb-form-row">
        <label>매칭 키워드 <span class="req">*</span></label>
        <input type="text" name="keywords" value="<?= h($editEntry['keywords'] ?? '') ?>" maxlength="255" placeholder="예) 배송,얼마,기간,걸리,도착" required>
        <p class="cb-hint">쉼표(,)로 구분. 사용자 메시지에 이 단어가 포함되면 이 응답이 자동 답변됩니다.</p>
      </div>

      <div class="cb-form-row">
        <label>응답 내용 <span class="req">*</span></label>
        <textarea name="answer" rows="4" placeholder="사용자에게 표시될 답변 내용을 입력하세요. 줄바꿈도 반영됩니다." required><?= h($editEntry['answer'] ?? '') ?></textarea>
      </div>

      <div class="cb-form-row" style="max-width:200px">
        <label>정렬 순서</label>
        <input type="number" name="sort_order" value="<?= (int)($editEntry['sort_order'] ?? 0) ?>" min="0">
        <p class="cb-hint">숫자가 낮을수록 먼저 표시/우선 매칭됩니다.</p>
      </div>

      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn-admin-primary"><?= $editEntry ? '수정 저장' : '등록하기' ?></button>
        <?php if ($editEntry): ?>
        <a href="/admin/chatbot.php" class="btn-admin-secondary" style="text-decoration:none;padding:10px 20px">새로 쓰기</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- 목록 -->
  <div style="margin-top:28px">
    <h3 class="cb-form-title">등록된 응답 (<?= count($entries) ?>건)</h3>
    <?php if (empty($entries)): ?>
      <div class="admin-empty" style="padding:40px 0">등록된 응답이 없습니다. 위 폼에서 첫 응답을 등록해주세요.</div>
    <?php else: ?>
    <div class="admin-table-wrap" style="overflow-x:auto">
      <table class="admin-table">
        <thead>
          <tr>
            <th>순서</th>
            <th>상태</th>
            <th>대표 질문</th>
            <th>키워드</th>
            <th>응답 내용</th>
            <th>수정일</th>
            <th>관리</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): ?>
          <tr>
            <td><?= (int)$e['sort_order'] ?></td>
            <td>
              <span class="cb-active-badge <?= $e['is_active'] ? 'on' : 'off' ?>">
                <?= $e['is_active'] ? '표시중' : '숨김' ?>
              </span>
            </td>
            <td style="font-size:13px;font-weight:700;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($e['question']) ?></td>
            <td style="font-size:11px;color:#64748b;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($e['keywords']) ?></td>
            <td style="font-size:12px;color:#334155;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h(mb_strimwidth($e['answer'], 0, 60, '…', 'UTF-8')) ?></td>
            <td style="font-size:11px;color:#94a3b8;white-space:nowrap"><?= h(date('m/d H:i', strtotime($e['updated_at']))) ?></td>
            <td style="white-space:nowrap">
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                <a href="?edit=<?= (int)$e['id'] ?>" class="btn-admin-secondary" style="padding:4px 10px;font-size:11px;text-decoration:none">수정</a>
                <form method="post" style="display:inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>">
                  <button type="submit" class="btn-admin-secondary" style="padding:4px 10px;font-size:11px"><?= $e['is_active'] ? '숨김' : '표시' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('정말 삭제하시겠습니까?')">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>">
                  <button type="submit" style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer">삭제</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<style>
.cb-form{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:20px;}
.cb-form-title{font-size:15px;font-weight:800;color:#0f172a;margin:0 0 14px;}
.cb-form-row{margin-bottom:12px;}
.cb-form-row label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;}
.cb-form-row .req{color:#ef4444;}
.cb-form-row input[type=text],.cb-form-row input[type=number],.cb-form-row textarea{
  width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;
  font-family:inherit;transition:border-color .15s;background:#fff;
}
.cb-form-row input:focus,.cb-form-row textarea:focus{outline:none;border-color:#2563eb;}
.cb-form-row textarea{resize:vertical;}
.cb-hint{font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.5;}
.cb-active-badge{display:inline-block;font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;}
.cb-active-badge.on{background:#dcfce7;color:#15803d;}
.cb-active-badge.off{background:#f1f5f9;color:#64748b;}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
