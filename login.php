<?php
require_once __DIR__.'/config.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim($_POST['username'] ?? '');
  $p = $_POST['password'] ?? '';
  if ($u === $ADMIN_USER && password_verify($p, $ADMIN_PASS_HASH)) {
    $_SESSION['is_admin'] = true;
    header('Location: admin.php');
    exit;
  } else {
    $err = 'بيانات الدخول غير صحيحة';
  }
}
?>
<!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8">
<title>Admin Login</title>
<style>
  body{font-family:system-ui;background:#071226;color:#e6eef8;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
  .card{background:#0b1220;padding:22px;border-radius:12px;width:360px;box-shadow:0 8px 30px rgba(2,6,23,0.6)}
  h2{color:#6ee7b7;margin:0 0 8px}
  input{width:100%;padding:10px;border-radius:8px;border:0;margin-top:10px;color:#071226}
  button{width:100%;padding:10px;margin-top:14px;background:#6ee7b7;color:#042024;border:0;border-radius:10px;font-weight:700;cursor:pointer}
  .err{color:#ffc4c4;margin-top:8px}
  .note{color:#98a0b3;margin-top:8px;font-size:13px}
</style>
</head>
<body>
  <div class="card">
    <h2>تسجيل دخول الأدمن</h2>
    <form method="post">
      <input name="username" placeholder="Username" required>
      <input name="password" type="password" placeholder="Password" required>
      <button>تسجيل دخول</button>
      <?php if($err){ echo "<div class='err'>$err</div>"; } ?>

    </form>
  </div>
</body>
</html>
