<?php
$dbFieldOptions = [
    '__skip__' => '-- 사용안함 --',
    'category' => '카테고리',
    'brand' => '브랜드',
    'name' => '상품명',
    'model' => '모델명',
    'pattern_code' => '패턴코드',
    'size' => '사이즈',
    'load_speed' => '하중속도규격',
    'origin' => '원산지',
    'width' => '단면폭',
    'aspect' => '편평비',
    'diameter' => '림직경',
    'pattern_name' => '패턴명',
    'oem' => 'OEM',
    'tech' => 'Tech',
    'runflat' => 'Runflat',
    'dot_code' => 'DOT코드',
    'price' => '정상가',
    'sale_price' => '판매가',
    'supply_price' => '공급가',
    'stock' => '재고',
    'status' => '상태',
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>매칭 확인</title>
<style>
body{font-family:sans-serif;margin:30px;}
table{border-collapse:collapse;width:100%;font-size:13px;}
th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;}
th{background:#f3f4f6;}
select{padding:4px;}
.warn{color:#dc2626;font-weight:bold;}
.btn-confirm{background:#16a34a;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;margin-top:16px;}
</style>
</head>
<body>
<h2>2단계: 컬럼 자동매칭 확인 (필요시 직접 수정)</h2>
<p>아래는 업로드된 엑셀의 <b>상위 5개 데이터 행</b> 미리보기입니다. 자동 매칭이 잘못됐으면 드롭다운에서 직접 바꾸세요.</p>

<form method="post" action="products_import.php?step=3">
  <input type="hidden" name="step" value="3">
  <?= Csrf::field() ?>

  <table>
    <tr>
      <?php foreach ($headerRow as $idx => $h): ?>
        <th>
          엑셀헤더: "<?= htmlspecialchars((string)$h) ?>"<br>
          <select name="col_map[<?= $idx ?>]">
            <?php foreach ($dbFieldOptions as $val => $label): ?>
              <option value="<?= $val ?>" <?= (($autoMatch[$idx] ?? '__skip__') === $val) ? 'selected' : '' ?>>
                <?= $label ?>
              </option>
            <?php endforeach; ?>
          </select>
        </th>
      <?php endforeach; ?>
    </tr>
    <?php foreach (array_slice($dataRows, 0, 5) as $row): ?>
      <tr>
        <?php foreach ($headerRow as $idx => $h): ?>
          <td><?= htmlspecialchars((string)($row[$idx] ?? '')) ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </table>

  <p class="warn">
    총 <?= count($dataRows) ?>개 행이 감지되었습니다. "확정하고 등록"을 누르면 위 매칭 기준으로 실제 DB에 반영됩니다.
  </p>
  <button type="submit" class="btn-confirm">확정하고 등록하기</button>
</form>
</body>
</html>
