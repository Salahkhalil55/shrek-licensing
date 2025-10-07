<?php
require_once __DIR__.'/config.php';

$action = $_GET['action'] ?? '';
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));

function compute_expires($activated_at, $duration_days){
  if(!$activated_at) return null;
  $d = new DateTime($activated_at.' UTC');
  $d->add(new DateInterval('P'.intval($duration_days).'D'));
  return $d->format('c');
}

function row_with_status($lic){
  $expires = $lic['activated_at'] ? compute_expires($lic['activated_at'], $lic['duration_days']) : null;
  $status = "not_activated";
  if(intval($lic['revoked'])===1) $status = "revoked";
  elseif($lic['activated_at'] && $expires && (new DateTime('now', new DateTimeZone('UTC')) > new DateTime($expires))) $status = "expired";
  elseif($lic['activated_at']) $status = "active";
  return array_merge($lic, ["expires_at"=>$expires, "status"=>$status]);
}

/* -------- generate (admin) -------- */
if ($action === 'generate') {
  if (!admin_ok()) respond(["ok"=>false,"error"=>"unauthorized"], 401);
  $in = json_input();
  $product = $in['product'] ?? $GLOBALS['PRODUCT_DEFAULT'];
  $version = $in['version'] ?? $GLOBALS['VERSION_DEFAULT'];
  $days    = intval($in['days'] ?? 1);
  $note    = $in['note'] ?? '';

  $key = strtoupper(bin2hex(random_bytes(12)));
  $stmt = db()->prepare("INSERT INTO licenses(license_key, product, version, duration_days, note) VALUES (?,?,?,?,?)");
  $stmt->execute([$key, $product, $version, $days, $note]);
  respond(["ok"=>true,"key"=>$key,"days"=>$days,"product"=>$product,"version"=>$version]);
}

/* -------- list (admin) + filters -------- */
if ($action === 'list') {
  if (!admin_ok()) respond(["ok"=>false,"error"=>"unauthorized"], 401);
  $in = json_input();
  $q = trim($in['q'] ?? '');
  $status = $in['status'] ?? 'any';
  $version = trim($in['version'] ?? '');
  $limit = max(1, min(intval($in['limit'] ?? 500), 1000));

  $sql = "SELECT * FROM licenses";
  $where = [];
  $args = [];

  if($version !== ''){
    $where[] = "version = ?";
    $args[] = $version;
  }
  if($q !== ''){
    $where[] = "(license_key LIKE ? OR activated_hwid LIKE ?)";
    $args[] = "%$q%";
    $args[] = "%$q%";
  }
  if($where){
    $sql .= " WHERE ".implode(" AND ", $where);
  }
  $sql .= " ORDER BY issued_at DESC LIMIT $limit";
  $stmt = db()->prepare($sql);
  $stmt->execute($args);

  $rows = []; $c_all=0; $c_active=0; $c_expired=0; $c_revoked=0; $c_not=0;
  while($lic = $stmt->fetch(PDO::FETCH_ASSOC)){
    $c_all++;
    $r = row_with_status($lic);
    if($status==='any' || $status===$r['status']) $rows[] = $r;
    if($r['status']==='active') $c_active++;
    elseif($r['status']==='expired') $c_expired++;
    elseif($r['status']==='revoked') $c_revoked++;
    else $c_not++;
  }
  respond(["ok"=>true,"rows"=>$rows,"counts"=>[
    "total"=>$c_all,"active"=>$c_active,"expired"=>$c_expired,"revoked"=>$c_revoked,"not_activated"=>$c_not
  ]]);
}

/* -------- activate -------- */
if ($action === 'activate') {
  $in = json_input();
  $key = trim($in['key'] ?? '');
  $hwid = $in['hwid'] ?? null;
  $version = $in['version'] ?? null;

  $stmt = db()->prepare("SELECT * FROM licenses WHERE license_key=?");
  $stmt->execute([$key]);
  $lic = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$lic) respond(["ok"=>false,"error"=>"invalid_key"],400);
  if (intval($lic['revoked'])===1) respond(["ok"=>false,"error"=>"revoked"],400);
  if ($version && $lic['version'] && $lic['version'] !== $version) respond(["ok"=>false,"error"=>"version_mismatch"],400);

  if (!empty($lic['activated_at']) && !empty($lic['activated_hwid']) && $lic['activated_hwid'] !== $hwid) {
    respond(["ok"=>false,"error"=>"hwid_mismatch"],400);
  }

  if (empty($lic['activated_at'])) {
    $stmt = db()->prepare("UPDATE licenses SET activated_at=?, activated_hwid=? WHERE id=?");
    $stmt->execute([$nowUtc->format('Y-m-d H:i:s'), $hwid, $lic['id']]);
    $lic['activated_at'] = $nowUtc->format('Y-m-d H:i:s');
    $lic['activated_hwid'] = $hwid;
  }

  $expires = compute_expires($lic['activated_at'], $lic['duration_days']);
  respond(["ok"=>true,"key"=>$lic['license_key'],
    "activated_at"=> (new DateTime($lic['activated_at'].' UTC'))->format('c'),
    "expires_at"=> $expires]);
}

/* -------- validate -------- */
if ($action === 'validate') {
  $in = json_input();
  $key = trim($in['key'] ?? '');

  $stmt = db()->prepare("SELECT * FROM licenses WHERE license_key=?");
  $stmt->execute([$key]);
  $lic = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$lic) respond(["ok"=>false,"error"=>"invalid_key"],400);
  if (intval($lic['revoked'])===1) respond(["ok"=>false,"error"=>"revoked"],400);
  if (empty($lic['activated_at'])) respond(["ok"=>false,"error"=>"not_activated"],400);

  $expires = compute_expires($lic['activated_at'], $lic['duration_days']);
  $now = new DateTime('now', new DateTimeZone('UTC'));
  if ($expires && $now > new DateTime($expires)) respond(["ok"=>false,"error"=>"expired"],400);

  respond(["ok"=>true,"expires_at"=>$expires,"product"=>$lic['product'],"version"=>$lic['version']]);
}

/* -------- revoke (admin) -------- */
if ($action === 'revoke') {
  if (!admin_ok()) respond(["ok"=>false,"error"=>"unauthorized"], 401);
  $in = json_input();
  $key = trim($in['key'] ?? '');
  $stmt = db()->prepare("UPDATE licenses SET revoked=1 WHERE license_key=?");
  $stmt->execute([$key]);
  respond(["ok"=>true]);
}

/* -------- extend (admin) -------- */
if ($action === 'extend') {
  if (!admin_ok()) respond(["ok"=>false,"error"=>"unauthorized"], 401);
  $in = json_input();
  $key = trim($in['key'] ?? '');
  $extra = intval($in['extra_days'] ?? 0);
  $stmt = db()->prepare("UPDATE licenses SET duration_days = duration_days + ? WHERE license_key=?");
  $stmt->execute([$extra, $key]);
  respond(["ok"=>true]);
}

/* -------- delete (admin) -------- */
if ($action === 'delete') {
  if (!admin_ok()) respond(["ok"=>false,"error"=>"unauthorized"], 401);
  $in = json_input();
  $key = trim($in['key'] ?? '');
  $stmt = db()->prepare("DELETE FROM licenses WHERE license_key=?");
  $stmt->execute([$key]);
  respond(["ok"=>true]);
}

respond(["ok"=>false,"error"=>"unknown_action","hint"=>"use ?action=generate|list|activate|validate|revoke|extend|delete"],404);
