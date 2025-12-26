<?php
// dashboards/dashboard_pro.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo = getPDO();

$userId = (int)($_SESSION['user_id'] ?? 0);

// Confere no banco (não confia só na sessão)
$stmt = $pdo->prepare("SELECT role, specialty, name FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    // sessão inválida / usuário não existe
    session_destroy();
    header('Location: /login.php');
    exit;
}

$dbRole      = (string)($row['role'] ?? '');
$specialty   = $row['specialty'] ?? null; // pode ser null
$displayName = (string)($row['name'] ?? ($_SESSION['user_name'] ?? 'Coach'));

if ($dbRole !== 'pro') {
    header('Location: /login.php');
    exit;
}

// Roteia pelo specialty
if ($specialty === 'personal_trainer') {
    header('Location: /dashboards/dashboard_personal.php');
    exit;
}

if ($specialty === 'nutritionist') {
    header('Location: /dashboards/dashboard_nutritionist.php');
    exit;
}

// Fallback: specialty não definido ou valor inesperado
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>PRO Dashboard | RB Personal Trainer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow">
</head>
<body style="margin:0; font-family: Arial, sans-serif; background:#050811; color:#fff;">

  <div style="max-width:720px; margin:60px auto; padding:24px; border:1px solid #2c3345; border-radius:14px; background:#0b0f1e;">
    <p style="margin:0 0 8px 0; color:#ff7a00; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; font-size:12px;">
      PRO Area
    </p>

    <h1 style="margin:0 0 10px 0; font-size:28px;">Setup required</h1>

    <p style="margin:0 0 16px 0; color:#cbd5e1; line-height:1.6;">
      Hi, <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>.<br>
      Your profile does not have a valid specialty configured yet.<br>
      Please contact the system administrator.
    </p>

    <a href="/login.php" style="display:inline-block; padding:10px 14px; border-radius:999px; border:1px solid #ff7a00; color:#ffb27a; text-decoration:none;">
      Back to login
    </a>
  </div>

</body>
</html>
