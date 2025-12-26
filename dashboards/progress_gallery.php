<?php
// messages.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client', 'pro', 'admin']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role            = (string)($_SESSION['role'] ?? '');

/**
 * Role helpers
 */
$isClient = in_array($role, ['user', 'client'], true);
$isPro    = ($role === 'pro');
$isAdmin  = ($role === 'admin');

/**
 * CSRF token for message actions
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

/**
 * Helper: safe HTML output
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 1) Load logged-in user data
 */
$stmt = $pdo->prepare("
    SELECT id, name, role, avatar_url
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$logged_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$logged_user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

/**
 * 2) Determine who is being viewed
 */
$view_client_id = $current_user_id;
$display_user   = $logged_user;

/**
 * Pro can view only assigned clients using client_id.
 * Admin can view any client using client_id (optional but useful).
 */
if (($isPro || $isAdmin) && isset($_GET['client_id'])) {
    $candidate_id = (int)$_GET['client_id'];

    if ($candidate_id > 0) {
        if ($isPro) {
            // Pro: must be assigned
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.avatar_url
                FROM coach_clients cc
                JOIN users u ON u.id = cc.client_id
                WHERE cc.coach_id = ? AND cc.client_id = ?
                LIMIT 1
            ");
            $stmt->execute([$current_user_id, $candidate_id]);
            $clientData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$clientData) {
                http_response_code(403);
                die('This client is not assigned to you.');
            }

            $view_client_id = (int)$clientData['id'];
            $display_user   = $clientData;
        } else {
            // Admin: can view any client/user by id (you can restrict roles if you want)
            $stmt = $pdo->prepare("
                SELECT id, name, avatar_url
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$candidate_id]);
            $clientData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$clientData) {
                http_response_code(404);
                die('User not found.');
            }

            $view_client_id = (int)$clientData['id'];
            $display_user   = $clientData;
        }
    }
}

/**
 * 3) Fetch photos
 */
$sqlPhotos = "
    SELECT
        id,
        file_path,
        taken_at,
        weight_kg,
        pose,
        notes,
        created_at
    FROM progress_photos
    WHERE user_id = ?
    ORDER BY taken_at DESC, created_at DESC
";
$stmt = $pdo->prepare($sqlPhotos);
$stmt->execute([$view_client_id]);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * 4) Fetch videos
 */
$sqlVideos = "
    SELECT
        id,
        file_path,
        taken_at,
        notes,
        created_at,
        expires_at
    FROM progress_videos
    WHERE user_id = ?
      AND expires_at > NOW()
    ORDER BY created_at DESC
";
$stmtV = $pdo->prepare($sqlVideos);
$stmtV->execute([$view_client_id]);
$videos = $stmtV->fetchAll(PDO::FETCH_ASSOC);

$display_name = (string)($display_user['name'] ?? '');
$avatar_alt   = $display_name !== '' ? ('Profile photo of ' . $display_name) : 'Client profile photo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Progress Gallery | RB Personal Trainer | Rafa Breder Coaching</title>
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

  <?php if ($isPro): ?>
    <!-- Coach uses the same premium CSS of personal_profile -->
    <link rel="stylesheet" href="/assets/css/client_profile.css">
  <?php else: ?>
    <!-- Client uses the layout of dashboard_client -->
    <link rel="stylesheet" href="/assets/css/dashboard_client.css">
  <?php endif; ?>

  <link rel="stylesheet" href="/assets/css/client_workouts.css">
  <link rel="stylesheet" href="/assets/css/progress_gallery.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">
</head>
<body>

<?php if ($isPro): ?>

  <!-- ===================================================== -->
  <!-- HEADER DO PERSONAL (copiado de personal_profile.php)  -->
  <!-- ===================================================== -->
  <header id="rb-static-header" class="rbf1-header">
    <div class="rbf1-topbar">
      <a href="/" class="rbf1-logo">
        <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
      </a>

      <nav class="rbf1-nav" id="rbf1-nav">
        <ul>
          <li><a href="dashboard_personal.php">Dashboard</a></li>
          <li><a href="personal_profile.php">Profile</a></li>
          <li><a href="trainer_workouts.php">Workouts</a></li>
          <li><a href="trainer_checkins.php">Check-ins</a></li>
          <li><a href="messages.php">Messages</a></li>
          <li><a href="trainer_clients.php" class="rbf1-link rbf1-link-active">Clients</a></li>

          <!-- Logout só no mobile -->
          <li class="mobile-only">
            <a href="../login.php" class="rb-mobile-logout">Logout</a>
          </li>
        </ul>
      </nav>

      <div class="rbf1-right">
        <a href="../login.php" class="rbf1-login">Logout</a>
      </div>

      <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
        ☰
      </button>
    </div>
  </header>

<?php else: ?>

  <!-- ===================================================== -->
  <!-- HEADER DO CLIENTE (copiado de dashboard_client.php)   -->
  <!-- ===================================================== -->
  <header id="rb-static-header" class="rbf1-header">
    <div class="rbf1-topbar">
      <a href="/" class="rbf1-logo">
        <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
      </a>

      <nav class="rbf1-nav" id="rbf1-nav">
        <ul>
          <li><a href="dashboard_client.php">Dashboard</a></li>
          <li><a href="client_profile.php">Profile</a></li>
          <li><a href="client_goals.php">Goals</a></li>
          <li><a href="messages.php">Messages</a></li>
          <li><a href="client_workouts.php">Workout</a></li>
          <li><a href="client_nutrition.php">Nutritionist</a></li>
          <li><a href="progress_gallery.php" class="rbf1-link rbf1-link-active">Photos Gallery</a></li>

          <li class="mobile-only">
            <a href="../login.php" class="rb-mobile-logout">Logout</a>
          </li>
        </ul>
      </nav>

      <div class="rbf1-right">
        <a href="../login.php" class="rbf1-login">Logout</a>
      </div>

      <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
        ☰
      </button>
    </div>
  </header>

<?php endif; ?>

<div class="wk-container">

  <!-- Cabeçalho com usuário -->
  <div class="wk-user-header">
    <?php if (!empty($display_user['avatar_url'])): ?>
      <img src="<?php echo e((string)$display_user['avatar_url']); ?>" alt="<?php echo e($avatar_alt); ?>">
    <?php else: ?>
      <img src="/assets/img/default-avatar.png" alt="<?php echo e($avatar_alt); ?>">
    <?php endif; ?>
    <div class="wk-user-info">
      <div class="wk-user-name"><?php echo e($display_name); ?></div>
      <div class="wk-user-subtitle">Progress gallery</div>
    </div>
  </div>

  <!-- Header da galeria -->
  <div class="wk-session-header">
    <div>
      <div class="wk-plan-detail-title">Full progress history</div>
      <div class="wk-plan-detail-meta">
        <span>
          <?php if ($isPro): ?>
            Viewing client progress history
          <?php else: ?>
            Here you can see all your progress photos and form videos.
          <?php endif; ?>
        </span>
      </div>
    </div>

    <div class="wk-plan-detail-actions">
      <?php if ($isPro): ?>
        <a href="trainer_clients.php" class="wk-btn-secondary">&larr; Back to clients</a>
      <?php else: ?>
        <a href="client_workouts.php?tab=photos" class="wk-btn-secondary">&larr; Back to workout area</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($photos) && empty($videos)): ?>
    <p class="wk-empty">
      <?php echo $isPro
        ? "This client has no progress media yet."
        : "No progress media yet. Upload your first photos or videos."; ?>
    </p>
  <?php else: ?>

    <?php if (!empty($photos)): ?>
      <div class="wk-media-section-title">
        <span>Progress photos</span>
        <span class="wk-media-counter"><?php echo (int)count($photos); ?> total</span>
      </div>

      <div class="wk-photo-grid">
        <?php foreach ($photos as $ph): ?>
          <div class="wk-photo-card">

            <!-- DELETE BUTTON (secure POST + CSRF) -->
            <?php if ($isAdmin || ($isPro) || (!$isPro && (int)$view_client_id === $current_user_id)): ?>
              <form
                method="post"
                action="delete_progress_photo.php"
                class="wk-photo-delete-form"
                onsubmit="return confirm('Are you sure you want to delete this photo?');"
              >
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <input type="hidden" name="photo_id" value="<?php echo (int)$ph['id']; ?>">
                <button type="submit" class="wk-photo-delete-btn" aria-label="Delete photo">✕</button>
              </form>
            <?php endif; ?>

            <div class="wk-photo-thumb-wrapper">
              <img
                src="<?php echo e((string)$ph['file_path']); ?>"
                alt="Progress photo"
                class="js-open-photo"
                data-full="<?php echo e((string)$ph['file_path']); ?>"
              >
            </div>
            <div class="wk-photo-info">
              <div class="wk-photo-date">
                <?php
                  $dateRef = !empty($ph['taken_at']) ? (string)$ph['taken_at'] : (string)$ph['created_at'];
                  echo e(date('d/m/Y', strtotime($dateRef)));
                ?>
              </div>
              <div class="wk-photo-meta">
                <span><?php echo e(ucfirst((string)($ph['pose'] ?? ''))); ?></span>
                <?php if (!empty($ph['weight_kg'])): ?>
                  <span><?php echo e(number_format((float)$ph['weight_kg'], 1)); ?> kg</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($ph['notes'])): ?>
                <div class="wk-photo-notes">
                  <?php echo nl2br(e((string)$ph['notes'])); ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($videos)): ?>
      <div class="wk-media-section-title" style="margin-top: 18px;">
        <span>Form videos</span>
        <span class="wk-media-counter">
          <?php echo (int)count($videos); ?> available · auto-deleted after 30 days
        </span>
      </div>

      <div class="wk-video-grid">
        <?php foreach ($videos as $vd): ?>
          <div class="wk-video-card">
            <div class="wk-video-thumb">
              <video src="<?php echo e((string)$vd['file_path']); ?>" controls preload="metadata"></video>
            </div>
            <div class="wk-photo-info">
              <div class="wk-photo-date">
                <?php
                  $dateRef = !empty($vd['taken_at']) ? (string)$vd['taken_at'] : (string)$vd['created_at'];
                  echo e(date('d/m/Y', strtotime($dateRef)));
                ?>
              </div>
              <div class="wk-photo-meta">
                <?php if (!empty($vd['expires_at'])): ?>
                  <span>Expires: <?php echo e(date('d/m/Y', strtotime((string)$vd['expires_at']))); ?></span>
                <?php endif; ?>
              </div>
              <?php if (!empty($vd['notes'])): ?>
                <div class="wk-photo-notes">
                  <?php echo nl2br(e((string)$vd['notes'])); ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<!-- ===================================================== -->
<!-- FOOTERS                                               -->
<!-- ===================================================== -->
<?php if ($isPro): ?>

  <!-- FOOTER DO PERSONAL (de personal_profile.php) -->
  <footer class="site-footer">
    <!-- BLOCO PRINCIPAL -->
    <div class="footer-main">
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

      <div class="footer-col footer-nav">
        <h3 class="footer-heading">Navigate</h3>
        <ul class="footer-links">
          <li><a href="dashboard_personal.php">Dashboard</a></li>
          <li><a href="personal_profile.php">Profile</a></li>
          <li><a href="trainer_workouts.php">Workouts</a></li>
          <li><a href="trainer_checkins.php">Check-ins</a></li>
          <li><a href="messages.php">Messages</a></li>
          <li><a href="trainer_clients.php">Clients</a></li>
        </ul>
      </div>

      <div class="footer-col footer-legal">
        <h3 class="footer-heading">Legal</h3>
        <ul class="footer-legal-list">
          <li><a href="/privacy.html">Privacy Policy</a></li>
          <li><a href="/terms.html">Terms of Use</a></li>
          <li><a href="/cookies.html">Cookie Policy</a></li>
        </ul>
      </div>

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

    <div class="footer-bottom">
      <p class="footer-bottom-text">
        © 2025 RB Personal Trainer. All rights reserved.
      </p>
    </div>
  </footer>

<?php else: ?>

  <!-- FOOTER DO CLIENTE (de dashboard_client.php) -->
  <footer class="site-footer">
    <div class="footer-main">
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

      <div class="footer-col footer-legal">
        <h3 class="footer-heading">Legal</h3>
        <ul class="footer-legal-list">
          <li><a href="/privacy.html">Privacy Policy</a></li>
          <li><a href="/terms.html">Terms of Use</a></li>
          <li><a href="/cookies.html">Cookie Policy</a></li>
        </ul>
      </div>

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

    <div class="footer-bottom">
      <p class="footer-bottom-text">
        © 2025 RB Personal Trainer. All rights reserved.
      </p>
    </div>
  </footer>

<?php endif; ?>

<!-- PHOTO MODAL (lightbox) -->
<div id="rb-photo-modal" class="rb-photo-modal" aria-hidden="true">
  <div class="rb-photo-backdrop" data-close="1"></div>

  <div class="rb-photo-dialog" role="dialog" aria-modal="true" aria-label="Photo preview">
    <button type="button" class="rb-photo-close" aria-label="Close photo" data-close="1">×</button>
    <img id="rb-photo-modal-img" src="" alt="Progress photo preview" />
  </div>
</div>

<style>
  .rb-photo-modal { display:none; position:fixed; inset:0; z-index:9999; }
  .rb-photo-modal.rb-open { display:block; }
  .rb-photo-backdrop { position:absolute; inset:0; background:rgba(0,0,0,.72); }
  .rb-photo-dialog {
    position:relative;
    max-width:min(1100px, 92vw);
    max-height:88vh;
    margin: 6vh auto 0 auto;
    display:flex;
    align-items:center;
    justify-content:center;
    padding: 10px;
  }
  .rb-photo-dialog img {
    max-width:100%;
    max-height:88vh;
    border-radius:14px;
    background:#111;
  }
  .rb-photo-close {
    position:absolute;
    top: 6px;
    right: 10px;
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 999px;
    cursor: pointer;
    font-size: 28px;
    line-height: 42px;
    background: rgba(255,255,255,.9);
  }
  .js-open-photo { cursor: zoom-in; }

  /* =============================== */
  /* DELETE BUTTON - PHOTO CARD      */
  /* =============================== */
  .wk-photo-card { position: relative; }
  .wk-photo-delete-form { position: absolute; top: 8px; right: 8px; z-index: 2; }
  .wk-photo-delete-btn {
    width: 28px;
    height: 28px;
    border-radius: 999px;
    border: none;
    background: rgba(0,0,0,0.75);
    color: #fff;
    font-size: 16px;
    cursor: pointer;
    line-height: 28px;
  }
  .wk-photo-delete-btn:hover { background: #c0392b; }
</style>

<!-- JS EXTERNO GERAL DO SITE -->
<script src="script.js"></script>

<!-- JS do menu mobile -->
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

<!-- JS do modal de fotos -->
<script>
  (function () {
    const modal = document.getElementById('rb-photo-modal');
    const modalImg = document.getElementById('rb-photo-modal-img');

    function openModal(src) {
      if (!modal || !modalImg) return;
      modalImg.src = src || '';
      modal.classList.add('rb-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      if (!modal || !modalImg) return;
      modal.classList.remove('rb-open');
      modal.setAttribute('aria-hidden', 'true');
      modalImg.src = '';
      document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
      const target = e.target;

      // open
      if (target && target.classList && target.classList.contains('js-open-photo')) {
        const full = target.getAttribute('data-full') || target.getAttribute('src');
        openModal(full);
        return;
      }

      // close (backdrop or button)
      if (target && target.getAttribute && target.getAttribute('data-close') === '1') {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeModal();
    });
  })();
</script>

</body>
</html>
