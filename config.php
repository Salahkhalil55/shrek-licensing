<?php
// config.php — إعدادات قاعدة البيانات وSession وAdmin (عدل القيم)
$DB_HOST = 'sql306.infinityfree.com';      // غيّر حسب استضافتك
$DB_NAME = 'if0_40113210_shrekaimkeys';    // غيّر حسب استضافتك
$DB_USER = 'if0_40113210';                 // غيّر حسب استضافتك
$DB_PASS = 'Salah324008952';    // ضع كلمة السر لقاعدة البيانات هنا


$ADMIN_USER = 'admin';

$ADMIN_PASS_HASH = password_hash('Sovos0v0', PASSWORD_BCRYPT);

// Optional fallback admin token (لو لسه تحب تستعمل ?admin=TOKEN) — اتركه فارغ لو مش محتاج
$ADMIN_TOKEN = 'Sovos0v0';

// Product defaults
$PRODUCT_DEFAULT = 'ShrekAimAssist';
$VERSION_DEFAULT = '1.1';

// Start session for admin login
if (session_status() == PHP_SESSION_NONE) session_start();

// DB connection (PDO)
function db() {
  static $pdo;
  if (!$pdo) {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
  }
  return $pdo;
}

function json_input() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function respond($arr, $code=200) {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($arr);
  exit;
}

// Existing bearer check (kept as fallback)
function bearer_ok() {
  global $ADMIN_TOKEN;
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!$hdr && isset($_GET['admin'])) return $_GET['admin'] === $ADMIN_TOKEN;
  if (stripos($hdr, 'Bearer ') === 0) {
    return substr($hdr, 7) === $ADMIN_TOKEN;
  }
  return false;
}

// Session-based admin check
function session_admin_ok(){
  return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

// Unified admin check: session first, fallback to bearer or ?admin=
function admin_ok(){
  if (session_admin_ok()) return true;
  if (function_exists('bearer_ok') && bearer_ok()) return true;
  if (!empty($GLOBALS['ADMIN_TOKEN']) && isset($_GET['admin']) && $_GET['admin'] === $GLOBALS['ADMIN_TOKEN']) return true;
  return false;
}
