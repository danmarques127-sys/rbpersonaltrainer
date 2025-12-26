<?php
// workout_photo_upload.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

/**
 * CSRF token (POST upload)
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

/**
 * Carrega usuário logado (para cabeçalho)
 */
$stmt = $pdo->prepare("
    SELECT id, name, role, avatar_url
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$errors = [];
$maxPhotoSize = 10 * 1024 * 1024;   // 10 MB
$maxVideoSize = 200 * 1024 * 1024;  // 200 MB

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    /**
     * CSRF validação
     */
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($posted_token === '' || !hash_equals($csrf_token, $posted_token)) {
        $errors[] = 'Invalid request (CSRF). Please try again.';
    }

    // Campos comuns a fotos e vídeos
    $taken_at  = !empty($_POST['taken_at']) ? (string)$_POST['taken_at'] : null;
    $weight_kg = (isset($_POST['weight_kg']) && $_POST['weight_kg'] !== '') ? (float)$_POST['weight_kg'] : null;
    $pose      = !empty($_POST['pose']) ? (string)$_POST['pose'] : 'other';
    $notes     = isset($_POST['notes']) ? trim((string)$_POST['notes']) : null;

    // Diretórios de upload (dentro de /dashboards/uploads/...)
    $photoDir = __DIR__ . '/uploads/progress_photos/';
    $videoDir = __DIR__ . '/uploads/progress_videos/';

    if (empty($errors)) {
        if (!is_dir($photoDir) && !mkdir($photoDir, 0775, true) && !is_dir($photoDir)) {
            $errors[] = 'Unable to create photos upload directory.';
        }
        if (!is_dir($videoDir) && !mkdir($videoDir, 0775, true) && !is_dir($videoDir)) {
            $errors[] = 'Unable to create videos upload directory.';
        }
    }

    $hasAnyFile = false;

    // Upload de FOTOS (múltiplas)
    if (empty($errors) && isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $photoNames = $_FILES['photos']['name'];
        $photoTmp   = $_FILES['photos']['tmp_name'];
        $photoErr   = $_FILES['photos']['error'];
        $photoSize  = $_FILES['photos']['size'];

        $allowedPhotoExt = ['jpg', 'jpeg', 'png', 'webp'];

        for ($i = 0, $n = count($photoNames); $i < $n; $i++) {
            if (($photoErr[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $hasAnyFile = true;

            if (($photoErr[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = 'Error uploading one of the photos.';
                continue;
            }

            if ((int)($photoSize[$i] ?? 0) > $maxPhotoSize) {
                $errors[] = 'One of the photos is too large (max 10MB).';
                continue;
            }

            $originalName = (string)($photoNames[$i] ?? '');
            $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

            if ($ext === '' || !in_array($ext, $allowedPhotoExt, true)) {
                $errors[] = 'Invalid photo format. Allowed: JPG, PNG, WEBP.';
                continue;
            }

            $newName = sprintf(
                'u%d_%s_%d.%s',
                $current_user_id,
                bin2hex(random_bytes(4)),
                time(),
                $ext
            );

            $destPath     = $photoDir . $newName;
            $relativePath = 'uploads/progress_photos/' . $newName;

            if (!move_uploaded_file((string)($photoTmp[$i] ?? ''), $destPath)) {
                $errors[] = 'Failed to save one of the photos.';
                continue;
            }

            // Salva no banco: progress_photos
            // (REMOVIDO uploaded_by para evitar erro "Unknown column 'uploaded_by'")
            $sql = "
                INSERT INTO progress_photos (
                    user_id,
                    file_path,
                    taken_at,
                    weight_kg,
                    pose,
                    notes,
                    created_at
                ) VALUES (
                    :user_id,
                    :file_path,
                    :taken_at,
                    :weight_kg,
                    :pose,
                    :notes,
                    NOW()
                )
            ";
            $stmtInsert = $pdo->prepare($sql);
            $stmtInsert->execute([
                ':user_id'     => $current_user_id,
                ':file_path'   => $relativePath,
                ':taken_at'    => $taken_at,
                ':weight_kg'   => $weight_kg,
                ':pose'        => $pose,
                ':notes'       => ($notes !== '' ? $notes : null),
            ]);
        }
    }

    // Upload de VÍDEOS (múltiplos)
    if (empty($errors) && isset($_FILES['videos']) && is_array($_FILES['videos']['name'])) {
        $videoNames = $_FILES['videos']['name'];
        $videoTmp   = $_FILES['videos']['tmp_name'];
        $videoErr   = $_FILES['videos']['error'];
        $videoSize  = $_FILES['videos']['size'];

        $allowedVideoExt = ['mp4', 'mov', 'webm', 'm4v'];

        for ($i = 0, $n = count($videoNames); $i < $n; $i++) {
            if (($videoErr[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $hasAnyFile = true;

            if (($videoErr[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = 'Error uploading one of the videos.';
                continue;
            }

            if ((int)($videoSize[$i] ?? 0) > $maxVideoSize) {
                $errors[] = 'One of the videos is too large (max 200MB).';
                continue;
            }

            $originalName = (string)($videoNames[$i] ?? '');
            $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

            if ($ext === '' || !in_array($ext, $allowedVideoExt, true)) {
                $errors[] = 'Invalid video format. Allowed: MP4, MOV, WEBM, M4V.';
                continue;
            }

            $newName = sprintf(
                'u%d_%s_%d.%s',
                $current_user_id,
                bin2hex(random_bytes(4)),
                time(),
                $ext
            );

            $destPath     = $videoDir . $newName;
            $relativePath = 'uploads/progress_videos/' . $newName;

            if (!move_uploaded_file((string)($videoTmp[$i] ?? ''), $destPath)) {
                $errors[] = 'Failed to save one of the videos.';
                continue;
            }

            // Tabela progress_videos (precisa existir)
            // (REMOVIDO uploaded_by para evitar erro "Unknown column 'uploaded_by'")
            $sqlVideo = "
                INSERT INTO progress_videos (
                    user_id,
                    file_path,
                    taken_at,
                    notes,
                    created_at,
                    expires_at
                ) VALUES (
                    :user_id,
                    :file_path,
                    :taken_at,
                    :notes,
                    NOW(),
                    DATE_ADD(NOW(), INTERVAL 1 MONTH)
                )
            ";
            $stmtVideo = $pdo->prepare($sqlVideo);
            $stmtVideo->execute([
                ':user_id'   => $current_user_id,
                ':file_path' => $relativePath,
                ':taken_at'  => $taken_at,
                ':notes'     => ($notes !== '' ? $notes : null),
            ]);
        }
    }

    if (empty($errors) && !$hasAnyFile) {
        $errors[] = 'Please select at least one photo or video.';
    }

    if (empty($errors)) {
        header('Location: client_workouts.php?tab=photos');
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Upload Progress | RB Personal Trainer | Rafa Breder Coaching</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- FAVICONS -->
  <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
  <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
  <link rel="manifest" href="/assets/images/site.webmanifest">
  <meta name="msapplication-TileColor" content="#FF7A00">
  <meta name="msapplication-TileImage" content="/assets/images/mstile-150x150.png">

  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/workout_photo_upload.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">
</head>
<body>

<!-- HEADER (fora do shell) -->
<header id="rb-static-header" class="rbf1-header">
  <div class="rbf1-topbar">
    <a href="/" class="rbf1-logo">
      <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
    </a>

    <nav class="rbf1-nav" id="rbf1-nav">
      <ul>
        <li><a href="/dashboards/dashboard_client.php">Dashboard</a></li>
        <li><a href="/dashboards/client_profile.php" class="rbf1-link rbf1-link-active">Profile</a></li>
        <li><a href="/dashboards/client_goals.php">Goals</a></li>
        <li><a href="/dashboards/messages.php">Messages</a></li>
        <li><a href="/dashboards/client_workouts.php">Workout</a></li>
        <li><a href="/dashboards/client_nutrition.php">Nutritionist</a></li>
        <li><a href="/dashboards/progress_gallery.php">Photos Gallery</a></li>

        <!-- Logout mobile (mantido) -->
        <li class="mobile-only">
          <a href="/login.php" class="rb-mobile-logout">Logout</a>
        </li>
      </ul>
    </nav>

    <div class="rbf1-right">
      <a href="/login.php" class="rbf1-login">Logout</a>
    </div>

    <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">☰</button>
  </div>
</header>

<div class="wk-container">

    <!-- Cabeçalho usuário -->
    <div class="wk-user-header">
        <?php
          $nameSafe = htmlspecialchars((string)($current_user['name'] ?? ''), ENT_QUOTES, 'UTF-8');
          $avatar = (string)($current_user['avatar_url'] ?? '');
          $avatarAlt = ($nameSafe !== '') ? ('Profile photo of ' . $nameSafe) : 'Client profile photo';
        ?>
        <?php if ($avatar !== ''): ?>
            <img src="<?php echo htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo $avatarAlt; ?>">
        <?php else: ?>
            <img src="/assets/images/default-avatar.png" alt="<?php echo $avatarAlt; ?>">
        <?php endif; ?>

        <div class="wk-user-info">
            <div class="wk-user-name"><?php echo $nameSafe; ?></div>
            <div class="wk-user-subtitle">Upload progress</div>
        </div>
    </div>

    <!-- Cabeçalho da página -->
    <div class="wk-session-header">
        <div>
            <div class="wk-plan-detail-title">Progress photos &amp; videos</div>
            <div class="wk-plan-detail-meta">
                <span>Upload your latest check-in photos and form videos for your coach.</span>
            </div>
        </div>
        <div class="wk-plan-detail-actions">
            <a href="client_workouts.php?tab=photos" class="wk-btn-secondary">
                &larr; Back to progress gallery
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="wk-session-notes-box" style="border-color: rgba(239,68,68,0.7); color:#fecaca;">
            <?php foreach ($errors as $e): ?>
                <div><?php echo htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="wk-complete-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="wk-field-row">
            <div class="wk-field">
                <label for="taken_at" class="wk-label">Date of photos / videos</label>
                <input type="date" id="taken_at" name="taken_at" class="wk-input">
            </div>

            <div class="wk-field">
                <label for="weight_kg" class="wk-label">Weight (kg)</label>
                <input type="number" step="0.1" id="weight_kg" name="weight_kg"
                       class="wk-input" placeholder="Ex: 72.5">
            </div>

            <div class="wk-field">
                <label for="pose" class="wk-label">Default pose for photos</label>
                <select id="pose" name="pose" class="wk-select">
                    <option value="front">Front</option>
                    <option value="side">Side</option>
                    <option value="back">Back</option>
                    <option value="other" selected>Other / mixed</option>
                </select>
            </div>
        </div>

        <div class="wk-field-row">
            <div class="wk-field">
                <label class="wk-label" for="photos">Progress photos</label>
                <input type="file" id="photos" name="photos[]" class="wk-input" multiple accept="image/*">
                <small style="font-size:11px;color:#9ca3af;">
                    You can select multiple images (JPG, PNG, WEBP).
                </small>
            </div>

            <div class="wk-field">
                <label class="wk-label" for="videos">Form videos</label>
                <input type="file" id="videos" name="videos[]" class="wk-input" multiple accept="video/*">
                <small style="font-size:11px;color:#9ca3af;">
                    Videos are kept for 30 days for coach review.
                </small>
            </div>
        </div>

        <div class="wk-field">
            <label for="notes" class="wk-label">Notes / focus for this check-in</label>
            <textarea id="notes" name="notes" class="wk-textarea"
                      placeholder="Ex: cutting phase, focus on glutes and posture."></textarea>
        </div>

        <div class="wk-form-actions">
            <a href="client_workouts.php?tab=photos" class="wk-btn-secondary">Cancel</a>
            <button type="submit" class="wk-btn-primary">Upload</button>
        </div>
    </form>

</div>

<footer class="site-footer">

  <!-- BLOCO PRINCIPAL -->
  <div class="footer-main">
    <!-- COLUNA BRAND -->
    <div class="footer-col footer-brand">
      <a href="/" class="footer-logo">
        <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
      </a>
      <p class="footer-text">
        RB Personal Trainer offers complete online coaching with customized
        workout plans, fat-loss programs, muscle-building strategies and
        habit coaching. Train with a certified personal trainer and get
        real results at home, in the gym or wherever you are.
      </p>
    </div>

    <!-- COLUNA NAV -->
    <div class="footer-col footer-nav">
      <h3 class="footer-heading">Navigate</h3>
      <ul class="footer-links">
        <li><a href="dashboard_client.php">Dashboard</a></li>
        <li><a href="client_profile.php">Profile</a></li>
        <li><a href="client_goals.php">Goals</a></li>
        <li><a href="client_workouts.php">Workouts</a></li>
        <li><a href="client_nutrition.php">Nutrition</a></li>
        <li><a href="messages.php">Messages</a></li>
      </ul>
    </div>

    <!-- COLUNA LEGAL -->
    <div class="footer-col footer-legal">
      <h3 class="footer-heading">Legal</h3>
      <ul class="footer-legal-list">
        <li><a href="/privacy.html">Privacy Policy</a></li>
        <li><a href="/terms.html">Terms of Use</a></li>
        <li><a href="/cookies.html">Cookie Policy</a></li>
      </ul>
    </div>

    <!-- COLUNA CONTACT -->
    <div class="footer-col footer-contact">
      <h3 class="footer-heading">Contact</h3>

      <div class="footer-contact-block">
        <p class="footer-text footer-contact-text">
          Prefer a direct line to your coach? Reach out and let’s design your
          training strategy together.
        </p>

        <ul class="footer-contact-list">
          <li>
            <span class="footer-contact-label">Email:</span>
            <a href="mailto:rbpersonaltrainer@gmail.com" class="footer-email-link">
              rbpersonaltrainer@gmail.com
            </a>
          </li>
          <li>
            <span class="footer-contact-label">Location:</span>
            Boston, MA · Online clients across the US
          </li>
          <li class="footer-social-row">
            <span class="footer-contact-label">Social:</span>
            <div class="footer-social-icons">
              <a class="social-icon" href="https://www.instagram.com/rbpersonaltrainer" target="_blank" rel="noopener">
                <img src="/assets/images/instagram.png" alt="Instagram Logo">
              </a>
              <a class="social-icon" href="https://www.facebook.com/rbpersonaltrainer" target="_blank" rel="noopener">
                <img src="/assets/images/facebook.png" alt="Facebook Logo">
              </a>
              <a class="social-icon" href="https://www.linkedin.com" target="_blank" rel="noopener">
                <img src="/assets/images/linkedin.png" alt="LinkedIn Logo">
              </a>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- BARRA INFERIOR -->
  <div class="footer-bottom">
    <p class="footer-bottom-text">
      © 2025 RB Personal Trainer. All rights reserved.
    </p>
  </div>
</footer>

<script src="script.js"></script>

<script>
  (function () {
    const toggle = document.getElementById('rbf1-toggle');
    const nav = document.getElementById('rbf1-nav');

    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        nav.classList.toggle('rbf1-open');
      });
    }
  })();
</script>

</body>
</html>
