<?php
require_once __DIR__.'/config.php';
if (!session_admin_ok()) {
  header('Location: login.php'); exit;
}
?>
<!doctype html><meta charset="utf-8">
<title>Admin Panel — ShrekAimAssist</title>
<style>
/* نفس CSS الموجود سابقًا ببساطة */
body{font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:#0b1220;color:#e6eef8;margin:0}
.wrap{max-width:1200px;margin:0 auto;padding:20px}
.card{background:#071126;padding:18px;border-radius:12px}
h1{color:#6ee7b7;font-size:20px;margin:0 0 10px}
label{font-size:13px;color:#98a0b3}
input,select{width:100%;padding:9px 12px;margin-top:6px;border-radius:8px;border:0;box-sizing:border-box;color:#0b1220}
.row{display:flex;gap:14px;flex-wrap:wrap}
.col{flex:1;min-width:260px}
.btn{background:#6ee7b7;color:#042024;padding:9px 12px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
.btn.alt{background:transparent;color:#6ee7b7;border:1px solid rgba(110,231,183,.15)}
.badge{padding:3px 8px;border-radius:6px;font-weight:700;font-size:12px}
.badge.active{background:#153e2b;color:#8ef6c6}
.badge.expired{background:#3a1a1a;color:#ffc4c4}
.badge.revoked{background:#3a1320;color:#ffb3c3}
.small{font-size:12px;color:#98a0b3}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px}
th,td{padding:8px;border-bottom:1px solid rgba(255,255,255,.06)}
.actions button{margin-right:6px;margin-bottom:4px}
.kpis{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}
.kpi{background:#0b1932;padding:10px 12px;border-radius:10px}
.kpi strong{display:block;font-size:18px;color:#fff}
.logout{float:right;background:transparent;color:#fff;border:1px solid rgba(255,255,255,0.06);padding:6px 10px;border-radius:8px}
</style>

<div class="wrap">
  <a class="logout" href="logout.php">Logout</a>
  <h1>License Admin — ShrekAimAssist</h1>

  <div class="card">
    <div class="row">
      <div class="col">
        <label>Product</label>
        <input id="product" value="<?php echo htmlspecialchars($PRODUCT_DEFAULT); ?>">
      </div>
      <div class="col">
        <label>Version</label>
        <input id="version" value="<?php echo htmlspecialchars($VERSION_DEFAULT); ?>">
      </div>
      <div class="col">
        <label>Days</label>
        <input id="days" type="number" value="1" min="1">
      </div>
      <div class="col" style="display:flex;align-items:flex-end;gap:8px">
        <button class="btn" id="btnGenerate">Create new key</button>
        <button class="btn alt" id="btnRefresh">Refresh list</button>
      </div>
    </div>

    <div class="kpis" id="kpis">
      <div class="kpi"><div class="small">Total</div><strong id="k_total">0</strong></div>
      <div class="kpi"><div class="small">Active</div><strong id="k_active">0</strong></div>
      <div class="kpi"><div class="small">Expired</div><strong id="k_expired">0</strong></div>
      <div class="kpi"><div class="small">Revoked</div><strong id="k_revoked">0</strong></div>
      <div class="kpi"><div class="small">Not activated</div><strong id="k_not">0</strong></div>
    </div>

    <div class="row">
      <div class="col">
        <label>Key (للعمليات)</label>
        <input id="key">
      </div>
      <div class="col">
        <label>HWID (اختياري للتفعيل)</label>
        <input id="hwid" placeholder="PC-12345">
      </div>
      <div class="col" style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap">
        <button class="btn" id="btnActivate">Activate</button>
        <button class="btn" id="btnValidate">Validate</button>
        <button class="btn alt" id="btnRevoke">Revoke</button>
        <input id="extendDays" type="number" value="3" style="width:90px">
        <button class="btn alt" id="btnExtend">Extend</button>
        <button class="btn alt" id="btnDelete">Delete</button>
      </div>
    </div>

    <hr style="opacity:.08;margin:14px 0">

    <div class="row">
      <div class="col">
        <label>بحث (Key/HWID)</label>
        <input id="q" placeholder="ابحث عن مفتاح أو HWID">
      </div>
      <div class="col">
        <label>حالة</label>
        <select id="filterStatus">
          <option value="any">Any</option>
          <option value="active">Active</option>
          <option value="expired">Expired</option>
          <option value="revoked">Revoked</option>
          <option value="not_activated">Not activated</option>
        </select>
      </div>
      <div class="col">
        <label>Version (فلترة)</label>
        <input id="filterVersion" placeholder="مثال: 1.1">
      </div>
      <div class="col" style="display:flex;align-items:flex-end">
        <button class="btn alt" id="btnFilter">Apply filters</button>
      </div>
    </div>

    <table id="tbl">
      <thead><tr>
        <th>Key</th><th>Product / Ver</th><th>Issued</th><th>Activated</th><th>Expires</th><th>HWID</th><th>Duration</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody></tbody>
    </table>

    <pre id="out" class="small" style="background:#0b1932;padding:10px;border-radius:8px;margin-top:10px"></pre>
  </div>
</div>

<script>
const API = 'api.php';
const el = id => document.getElementById(id);
const out = el('out');
function showOut(v){ out.textContent = typeof v==='string' ? v : JSON.stringify(v,null,2) }

async function call(action, body={}, admin=false){
  const r = await fetch(API+'?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const t = await r.text(); try{return JSON.parse(t)}catch{ return {raw:t,status:r.status}}
}

function badge(s){
  if(s==='active') return '<span class="badge active">Active</span>';
  if(s==='expired') return '<span class="badge expired">Expired</span>';
  if(s==='revoked') return '<span class="badge revoked">Revoked</span>';
  return '<span class="small">Not activated</span>';
}

async function refreshList(){
  const res = await call('list', {
    q: el('q').value.trim() || undefined,
    status: el('filterStatus').value,
    version: el('filterVersion').value.trim() || undefined,
    limit: 500
  }, true);
  showOut(res);
  if(!res.ok) return;

  el('k_total').textContent = res.counts.total;
  el('k_active').textContent = res.counts.active;
  el('k_expired').textContent = res.counts.expired;
  el('k_revoked').textContent = res.counts.revoked;
  el('k_not').textContent = res.counts.not_activated;

  const tbody = document.querySelector('#tbl tbody');
  tbody.innerHTML = '';
  res.rows.forEach(r=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="cursor:pointer;color:#a9ffdd">${r.license_key}</td>
      <td>${r.product} <span class="small">/${r.version||''}</span></td>
      <td class="small">${r.issued_at}</td>
      <td class="small">${r.activated_at||'-'}</td>
      <td class="small">${r.expires_at||'-'}</td>
      <td class="small">${r.activated_hwid||'-'}</td>
      <td class="small">${r.duration_days}d</td>
      <td>${badge(r.status)}</td>
      <td class="actions">
        <button class="btn alt" data-act="copy">Copy</button>
        <button class="btn" data-act="activate">Activate</button>
        <button class="btn alt" data-act="validate">Validate</button>
        <button class="btn alt" data-act="revoke">Revoke</button>
        <button class="btn alt" data-act="extend">Extend</button>
        <button class="btn alt" data-act="delete">Delete</button>
      </td>
    `;
    tbody.appendChild(tr);

    tr.querySelector('td:first-child').onclick = ()=>{ el('key').value = r.license_key; showOut(r); };
    tr.querySelector('[data-act="copy"]').onclick = ()=>{ navigator.clipboard.writeText(r.license_key); alert('Copied'); };
    tr.querySelector('[data-act="activate"]').onclick = async ()=>{
      const hw = prompt('Enter HWID (optional)', r.activated_hwid||'');
      const a = await call('activate', {key:r.license_key, hwid: hw, version:r.version}, false);
      showOut(a); refreshList();
    };
    tr.querySelector('[data-act="validate"]').onclick = async ()=>{
      const a = await call('validate', {key:r.license_key}, false);
      showOut(a);
    };
    tr.querySelector('[data-act="revoke"]').onclick = async ()=>{
      if(!confirm('Revoke this key?')) return;
      const a = await call('revoke', {key:r.license_key}, true);
      showOut(a); refreshList();
    };
    tr.querySelector('[data-act="extend"]').onclick = async ()=>{
      const d = prompt('Extra days to add?', '3'); if(!d) return;
      const a = await call('extend', {key:r.license_key, extra_days: parseInt(d)}, true);
      showOut(a); refreshList();
    };
    tr.querySelector('[data-act="delete"]').onclick = async ()=>{
      if(!confirm('Delete this key forever?')) return;
      const a = await call('delete', {key:r.license_key}, true);
      showOut(a); refreshList();
    };
  });
}

/* عمليات العلوية */
el('btnGenerate').onclick = async ()=>{
  const res = await call('generate', {product:el('product').value, version:el('version').value, days:+el('days').value}, true);
  showOut(res); if(res.ok && res.key){ el('key').value = res.key; refreshList(); }
};
el('btnRefresh').onclick = refreshList;
el('btnFilter').onclick = refreshList;

el('btnActivate').onclick = async ()=>{
  const k = el('key').value.trim(); if(!k) return alert('ادخل المفتاح');
  const r = await call('activate', {key:k, hwid:el('hwid').value||null, version:el('version').value}, false);
  showOut(r); refreshList();
};
el('btnValidate').onclick = async ()=>{
  const k = el('key').value.trim(); if(!k) return alert('ادخل المفتاح');
  const r = await call('validate', {key:k}, false); showOut(r);
};
el('btnRevoke').onclick = async ()=>{
  const k = el('key').value.trim(); if(!k) return alert('ادخل المفتاح');
  if(!confirm('Revoke this key?')) return;
  const r = await call('revoke', {key:k}, true); showOut(r); refreshList();
};
el('btnExtend').onclick = async ()=>{
  const k = el('key').value.trim(); if(!k) return alert('ادخل المفتاح');
  const d = parseInt(el('extendDays').value||3);
  const r = await call('extend', {key:k, extra_days:d}, true); showOut(r); refreshList();
};
el('btnDelete').onclick = async ()=>{
  const k = el('key').value.trim(); if(!k) return alert('ادخل المفتاح');
  if(!confirm('Delete this key forever?')) return;
  const r = await call('delete', {key:k}, true); showOut(r); refreshList();
};

/* تحميل أولي */
refreshList();
</script>
