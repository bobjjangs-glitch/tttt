<?php
// inc/dash_theme.php — 대시보드 전용 스타일. assets/style.css v3 의 :root 변수를 사용합니다.
// 주의: 아래 CSS 안에서 주석을 중첩하지 마세요. 중첩되면 이후 규칙이 모두 깨집니다.

function dash_theme(): void
{
    echo <<<'CSS'
<style>
/* 공통 */
.dsh-fail{background:var(--danger-soft);border:1px solid #efb3b3;color:#991b1b;padding:10px 14px;border-radius:10px;font-size:12.5px;font-weight:600}
.dsh-mb{margin-bottom:12px}
.dsh-foot{font-size:11.5px;color:var(--sub);margin-top:10px}
.dsh-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;box-shadow:var(--shadow);min-width:0}
.dsh-card h2{font-size:15px;font-weight:800;letter-spacing:-.01em;margin:0 0 12px;color:var(--text)}
.dsh-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px}
.dsh-head h2{margin:0}
.dsh-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:14px;margin-bottom:14px}
.dsh-grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;margin-bottom:14px}
@media(max-width:960px){.dsh-grid,.dsh-grid2{grid-template-columns:1fr}}

/* 알림 배너 (닫기 / 접기) */
.dsh-notice{border:1px solid #f0cf83;background:#fff7e0;border-radius:14px;margin-bottom:14px;overflow:hidden}
.dsh-notice[hidden]{display:none}
.dsh-notice-head{display:flex;align-items:center;gap:10px;width:100%;padding:11px 16px;border:0;background:transparent;cursor:pointer;font:inherit;text-align:left;color:#7c3a0a;-webkit-appearance:none;appearance:none}
.dsh-notice-head:focus-visible{outline:none;box-shadow:inset var(--ring)}
.dsh-notice-ico{display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;width:22px;height:22px;border-radius:50%;background:#b45309;color:#fff;font-weight:800;font-size:13px}
.dsh-notice-title{flex:1;font-weight:700;font-size:14px}
.dsh-notice-title b{display:inline-block;min-width:20px;padding:0 6px;margin:0 2px;border-radius:10px;background:#b45309;color:#fff;text-align:center;font-size:12px}
.dsh-notice-chev{width:8px;height:8px;margin:-4px 4px 0 0;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);transition:transform .15s}
.dsh-notice.is-collapsed .dsh-notice-chev{transform:rotate(-45deg);margin-top:0}
.dsh-notice-body{border-top:1px solid #f0cf83}
.dsh-notice.is-collapsed .dsh-notice-body{display:none}
.dsh-notice-item{display:flex;align-items:center;gap:10px;padding:10px 12px 10px 16px;font-size:13px;color:#6b3410}
.dsh-notice-item[hidden]{display:none}
.dsh-notice-item+.dsh-notice-item{border-top:1px dashed #f0cf83}
.dsh-notice-item::before{content:"";flex:0 0 8px;width:8px;height:8px;border-radius:50%;background:#b45309}
.dsh-notice-item.danger::before{background:#c62828}
.dsh-notice-item.info::before{background:#1d4ed8}
.dsh-notice-item .t{flex:1;min-width:0}
.dsh-notice-item .t b{font-weight:800}
.dsh-notice-item .lk a{font-weight:700;color:#7c3a0a;text-decoration:underline;white-space:nowrap;margin-left:10px}
.dsh-x{flex:0 0 28px;width:28px;height:28px;border:0;border-radius:8px;background:transparent;color:#7c3a0a;font-size:18px;line-height:1;cursor:pointer}
.dsh-x:hover{background:rgba(124,58,10,.14)}
.dsh-link{border:0;background:none;color:var(--sub);font:inherit;font-size:12px;cursor:pointer;padding:0 0 12px;text-decoration:underline}
.dsh-link[hidden]{display:none}
@media(max-width:640px){.dsh-notice-item{flex-wrap:wrap}.dsh-notice-item .t{flex-basis:calc(100% - 40px)}}

/* KPI 카드 */
.dsh-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px}
.dsh-kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;box-shadow:var(--shadow)}
.dsh-kpi small{display:block;color:var(--sub);font-size:12px}
.dsh-kpi b{display:block;font-size:22px;font-weight:800;letter-spacing:-.02em;margin:6px 0 2px;color:var(--text);font-variant-numeric:tabular-nums}
.dsh-kpi.main{background:linear-gradient(135deg,#1b64da,#1450b8);border-color:transparent}
.dsh-kpi.main b{color:#fff}
.dsh-kpi.main small{color:rgba(255,255,255,.92)}

/* 달력 */
.dsh-nav{display:flex;gap:6px}
.dsh-nav a{display:inline-block;padding:5px 12px;border:1px solid #bcc5d3;border-radius:8px;color:var(--text);font-size:12px;font-weight:600;background:#fff}
.dsh-nav a:hover{background:#f1f4f9;text-decoration:none}
.dsh-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
.dsh-dow{text-align:center;font-size:12px;font-weight:700;color:var(--sub);padding:4px 0}
.dsh-cal>.dsh-dow:nth-child(1){color:#c62828!important}
.dsh-cal>.dsh-dow:nth-child(7){color:#1d4ed8!important}
.dsh-cell{display:block;min-height:94px;padding:6px;border:1px solid #dbe2ee;border-radius:10px;background:#f4f7fb;color:var(--text);overflow:hidden;transition:background .12s,border-color .12s}
.dsh-cell:hover{background:#e8f0ff;border-color:#a9c4f5;text-decoration:none}
.dsh-cell.dsh-empty{background:transparent;border:0;pointer-events:none}
.dsh-cell.sel{background:var(--primary-soft);border-color:var(--primary);box-shadow:inset 0 0 0 1px var(--primary)}
.dsh-dn{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 5px;border-radius:11px;font-size:12px;font-weight:700}
.dsh-cell.sun .dsh-dn{color:#c62828}
.dsh-cell.sat .dsh-dn{color:#1d4ed8}
.dsh-cell.today .dsh-dn{background:var(--primary);color:#fff}
.dsh-sales{float:right;margin-top:2px;padding:1px 7px;border-radius:8px;background:#dbe8ff;color:var(--primary-d);font-size:10px;font-weight:700}
.dsh-chip{display:block;margin-top:3px;padding:2px 6px;border-left:3px solid var(--c);border-radius:4px 6px 6px 4px;font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#1f2937;background:var(--bg)}
.dsh-chip.done{text-decoration:line-through;opacity:.65}
.dsh-more{display:block;margin-top:2px;font-size:10px;font-weight:600;color:var(--sub)}
@media(max-width:960px){.dsh-cell{min-height:66px;padding:4px}.dsh-chip{font-size:10px}}

/* 선택한 날 · 일정 카드 */
.dsh-sum{margin:0 0 12px;padding:8px 12px;border-radius:10px;background:#e3edff;color:#12306f;font-size:13px;font-weight:600}
.dsh-none{margin:0 0 6px;font-size:13px;color:var(--sub)}
.dsh-ev{margin-bottom:8px;padding:10px 12px;border:1px solid var(--line);border-left:4px solid var(--c);border-radius:10px;background:#fff;font-size:13px;color:var(--text)}
.dsh-ev.done .dsh-et{text-decoration:line-through;opacity:.65}
.dsh-ev small{display:block;color:var(--sub);margin-top:2px}
.dsh-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.dsh-acts form{display:inline;margin:0}

/* 일정 추가/수정 폼 */
.dsh-formbox{margin-top:14px;padding:14px;border:1px solid #cfd8e6;border-radius:12px;background:#eef2f8}
.dsh-formtitle{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;font-size:14px;font-weight:800;color:var(--text)}
.dsh-field{display:block;margin-bottom:12px}
.dsh-field>span{display:block;margin-bottom:5px;font-size:12px;font-weight:700;color:#374151}
.dsh-field input,.dsh-field textarea{width:100%}
.dsh-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.dsh-cats{display:flex;flex-wrap:wrap;gap:6px}
.dsh-catopt{position:relative;display:inline-block;margin:0;cursor:pointer}
.dsh-catopt input{position:absolute;top:0;left:0;width:100%;height:100%;margin:0;opacity:0;cursor:pointer}
.dsh-catopt span{display:inline-block;padding:6px 13px;border:1px solid #bcc5d3;border-radius:999px;background:#fff;color:#374151;font-size:12.5px;font-weight:600;transition:all .12s}
.dsh-catopt:hover span{border-color:var(--c);color:var(--text);box-shadow:inset 0 0 0 1px var(--c)}
.dsh-catopt input:checked+span{background:#1f2937;border-color:#1f2937;color:#fff}
@supports (background:color-mix(in srgb,red 50%,black)){
  .dsh-catopt input:checked+span{background:color-mix(in srgb,var(--c) 78%,#000);border-color:color-mix(in srgb,var(--c) 78%,#000)}
}
.dsh-catopt input:focus-visible+span{box-shadow:var(--ring)}
.dsh-formact{display:flex;gap:8px;margin-top:4px}
.dsh-formact .btn-primary{flex:1}

/* 그래프 */
.dsh-bars{display:flex;align-items:stretch;gap:3px;height:160px}
.dsh-bar{flex:1;display:flex;flex-direction:column;min-width:0}
.dsh-bi{flex:1;display:flex;align-items:flex-end;background:#e8edf5;border-radius:4px 4px 0 0}
.dsh-bi i{display:block;width:100%;border-radius:4px 4px 0 0;background:linear-gradient(180deg,#4c8ff0,#1b64da)}
.dsh-bar:hover .dsh-bi i{background:#1450b8}
.dsh-bar span{margin-top:3px;font-size:10px;color:var(--sub);text-align:center}
.dsh-mrow{display:grid;grid-template-columns:58px 1fr;gap:8px;align-items:center;margin:10px 0;font-size:12px;color:var(--sub)}
.dsh-hb{height:8px;min-width:2px;margin:3px 0;border-radius:999px}

/* 표 */
.dsh-tbl{width:100%;border-collapse:collapse;font-size:13px;color:var(--text)}
.dsh-tbl th{padding:8px 8px;background:#eef2f7;border-bottom:1px solid var(--line);text-align:left;font-size:12px;font-weight:700;color:#374151;white-space:nowrap}
.dsh-tbl td{padding:9px 8px;border-bottom:1px solid var(--line-soft);text-align:left}
.dsh-tbl tr:last-child td{border-bottom:0}
.dsh-tbl tbody tr:hover td{background:#f5f8fc}
.dsh-tbl .num{text-align:right;font-variant-numeric:tabular-nums}
</style>
CSS;
}
