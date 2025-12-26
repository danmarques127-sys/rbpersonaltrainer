<?php
declare(strict_types=1);

// Página PÚBLICA (reset via email) -> NÃO exige login
require_once __DIR__ . '/core/bootstrap.php';

$pdo = getPDO();

$errors   = [];
$showForm = false;

// token pela URL
$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';

// ===============================
// 1) GET — usuário clicou no email
// ===============================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {

    if ($token === '' || strlen($token) < 10) {
        $errors[] = 'Invalid reset link. Please request a new one.';
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                pr.id      AS reset_id,
                pr.user_id AS user_id,
                pr.expires_at,
                pr.used_at
            FROM password_resets pr
            WHERE pr.token = ?
              AND pr.used_at IS NULL
              AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($resetRow) {
            $showForm = true;
        } else {
            $errors[] = 'This reset link has expired. Please request a new one.';
        }
    }
}

// ===============================
// 2) POST — envio da nova senha
// ===============================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $token = trim((string)($_POST['token'] ?? ($_GET['token'] ?? '')));

    $newPassword     = trim((string)($_POST['new_password'] ?? ''));
    $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));

    // validações
    if ($newPassword === '' || $confirmPassword === '') {
        $errors[] = 'Please fill in all password fields.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if ($token === '' || strlen($token) < 10) {
        $errors[] = 'Invalid reset link. Please request a new one.';
    }

    if (empty($errors)) {

        // revalida token
        $stmt = $pdo->prepare("
            SELECT 
                pr.id      AS reset_id,
                pr.user_id AS user_id
            FROM password_resets pr
            WHERE pr.token = ?
              AND pr.used_at IS NULL
              AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resetRow) {
            $errors[] = 'This reset link has expired. Please request a new one.';
            $showForm = false;
        } else {
            $resetId = (int)$resetRow['reset_id'];
            $userId  = (int)$resetRow['user_id'];

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            try {
                $pdo->beginTransaction();

                // Atualiza senha do user
                $updUser = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $updUser->execute([$newHash, $userId]);

                // Marca token como usado
                $updReset = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
                $updReset->execute([$resetId]);

                $pdo->commit();

                // encerra sessão atual (se existir)
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_unset();
                    session_destroy();
                }

                // volta pro login (no seu padrão)
                redirect('/dashboards/login.php?password_reset=1');

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'An error occurred while updating your password. Please try again.';
                $showForm = true;
            }
        }
    } else {
        $showForm = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Create New Password | RB Personal Trainer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- FAVICONS -->
  <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
  <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
  <link rel="manifest" href="/assets/images/site.webmanifest">
  <meta name="msapplication-TileColor" content="#FF7A00">
  <meta name="msapplication-TileImage" content="/assets/images/mstile-150x150.png">
  
<style>
  :root {
    --rb-orange: #f9a826;
    --rb-orange-dark: #d87c00;
    --rb-bg-dark: #050506;
    --rb-card-bg: rgba(0,0,0,0.9);
    --rb-text: #f5f5f5;
    --rb-muted: #9ca3af;
    --rb-error: #f87171;
  }
  body {
    background: #000;
    display:flex;
    justify-content:center;
    align-items:center;
    padding:40px 16px;
    min-height:100vh;
    font-family: system-ui, sans-serif;
    color: var(--rb-text);
  }
  .cp-wrapper {
    width:100%;
    max-width:440px;
    background: var(--rb-card-bg);
    padding:32px 28px;
    border-radius:20px;
    border:1px solid rgba(255,255,255,0.06);
  }
  .cp-title {
    text-align:center;
    font-size:20px;
    margin-bottom:8px;
    color: var(--rb-orange-dark);
    font-weight:700;
  }
  .cp-subtitle {
    text-align:center;
    font-size:14px;
    color: var(--rb-muted);
    margin-bottom:20px;
  }
  .cp-errors {
    border:1px solid var(--rb-error);
    padding:10px 12px;
    margin-bottom:16px;
    border-radius:8px;
    color:var(--rb-error);
    background:rgba(255,0,0,0.06);
    font-size:13px;
  }
  .cp-label {
    display:block;
    margin-bottom:6px;
    font-size:13px;
    color: var(--rb-orange-dark);
  }
  .cp-input {
    width:100%;
    border-radius:8px;
    background:#000;
    padding:10px 14px;
    color:var(--rb-text);
    border:1px solid var(--rb-orange-dark);
    outline:none;
  }
  .cp-input:focus { box-shadow:0 0 0 1px var(--rb-orange-dark); }
  .cp-button {
    margin-top:14px;
    width:100%;
    padding:11px 18px;
    background:none;
    border:2px solid var(--rb-orange-dark);
    border-radius:8px;
    color: var(--rb-orange-dark);
    font-weight:700;
    font-size:15px;
    cursor:pointer;
    transition:0.2s;
    text-transform:uppercase;
  }
  .cp-button:hover { background: rgba(216,124,0,0.08); }
  .cp-link {
    color: var(--rb-orange-dark);
    text-decoration:none;
    border-bottom:1px solid var(--rb-orange-dark);
    padding-bottom:1px;
  }
  .cp-link:hover { opacity:0.8; }
  .cp-help {
    font-size:12px;
    text-align:center;
    margin-top:14px;
    color: var(--rb-muted);
  }
</style>
</head>
<body>

<div class="cp-wrapper">
  <h1 class="cp-title">Create your new password</h1>
  <p class="cp-subtitle">Choose a strong password to secure your account.</p>

  <?php if (!empty($errors)): ?>
    <div class="cp-errors">
      <ul>
        <?php foreach ($errors as $err): ?>
          <li><?php echo htmlspecialchars((string)$err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($showForm): ?>
    <form method="post" action="/client_change_password.php?token=<?php echo urlencode($token); ?>">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

      <label class="cp-label" for="new_password">New password</label>
      <input type="password" name="new_password" id="new_password" class="cp-input" required>

      <label class="cp-label" for="confirm_password">Confirm password</label>
      <input type="password" name="confirm_password" id="confirm_password" class="cp-input" required>

      <button class="cp-button" type="submit">Set new password</button>

      <p class="cp-help">
        Remembered your password?
        <a href="/dashboards/login.php" class="cp-link">Back to login</a>
      </p>
    </form>
  <?php else: ?>
    <p class="cp-help">
      This reset link is invalid or expired.
      <br><a href="/forgot_password.php" class="cp-link">Request a new reset link</a>
    </p>
  <?php endif; ?>
</div>

</body>
</html>
