<?php
// trainer_exercise_new.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

// ================================
// CSRF (POST forms)
// ================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

$errors  = [];
$success = false;

// Valores padrão do formulário
$name            = '';
$body_part       = '';
$category        = '';
$primary_muscles = '';
$description     = '';
$youtube_url     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf_token, $postedToken)) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    } else {
        // Lê e valida campos
        $name            = trim((string)($_POST['name'] ?? ''));
        $body_part       = trim((string)($_POST['body_part'] ?? ''));
        $category        = trim((string)($_POST['category'] ?? ''));
        $primary_muscles = trim((string)($_POST['primary_muscles'] ?? ''));
        $description     = trim((string)($_POST['description'] ?? ''));
        $youtube_url     = trim((string)($_POST['youtube_url'] ?? ''));

        if ($name === '') {
            $errors[] = 'Exercise name is required.';
        }

        // Validação simples de URL (opcional)
        if ($youtube_url !== '' && !filter_var($youtube_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'YouTube URL must be a valid URL (or leave it empty).';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO exercise_library
                        (name, body_part, category, primary_muscles, description, youtube_url, created_by, created_at)
                    VALUES
                        (:name, :body_part, :category, :primary_muscles, :description, :youtube_url, :created_by, NOW())
                ");
                $stmt->execute([
                    ':name'            => $name,
                    ':body_part'       => $body_part !== '' ? $body_part : null,
                    ':category'        => $category !== '' ? $category : null,
                    ':primary_muscles' => $primary_muscles !== '' ? $primary_muscles : null,
                    ':description'     => $description !== '' ? $description : null,
                    ':youtube_url'     => $youtube_url !== '' ? $youtube_url : null,
                    ':created_by'      => $current_user_id,
                ]);

                $success = true;

                // Limpa campos após salvar com sucesso
                $name            = '';
                $body_part       = '';
                $category        = '';
                $primary_muscles = '';
                $description     = '';
                $youtube_url     = '';
            } catch (Throwable $e) {
                $errors[] = 'Error while saving exercise. Please try again.';
            }
        }
    }
}

// Listas fixas
$bodyParts = [
    'Legs','Glutes','Shoulders','Chest','Back',
    'Biceps','Triceps','Core','Calves','Full body','Other'
];

$categories = [
    'Machine','Free weight','Cable','Bodyweight',
    'Conditioning','Other'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create new exercise</title>
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

    <link rel="stylesheet" href="/assets/css/global.css">
    <link rel="stylesheet" href="/assets/css/dashboard_personal.css">
    <link rel="stylesheet" href="/assets/css/client_workouts.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">

    <style>
       /* =========================================================
   TRAINER – CREATE NEW EXERCISE
   Dark / Orange Glow / RB Standard
   ========================================================= */

:root{
  --bg-0:#06070a;
  --bg-1:#0a0b10;
  --bg-2:#0d0f16;

  --card: rgba(16,18,26,.72);

  --line: rgba(255,255,255,.08);

  --txt: rgba(255,255,255,.92);
  --muted: rgba(255,255,255,.62);

  --accent:#ff8a00;
  --accent-soft: rgba(255,138,0,.18);

  --success:#22c55e;
  --error:#f87171;

  --shadow-1: 0 14px 40px rgba(0,0,0,.55);
  --shadow-2: 0 22px 70px rgba(0,0,0,.65);
  --glow: 0 0 0 1px rgba(255,138,0,.12), 0 0 40px rgba(255,138,0,.12);

  --r-xl:22px;
  --r-lg:18px;
  --r-md:14px;
  --r-sm:10px;

  --font: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
}

/* -----------------------
   Container
------------------------ */
.wk-container-new-ex{
  max-width: 720px;
  margin: 36px auto 48px;
  padding: 26px 24px 32px;
  border-radius: var(--r-xl);
  background:
    radial-gradient(600px 260px at 25% 15%, rgba(255,138,0,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.015));
  border: 1px solid rgba(255,255,255,.08);
  box-shadow: var(--shadow-2);
  color: var(--txt);
}

/* -----------------------
   Header
------------------------ */
.wk-new-header{
  display:flex;
  flex-direction:column;
  gap:6px;
  margin-bottom: 20px;
}

.wk-title{
  margin:0;
  font-size: 24px;
  font-weight: 700;
  color: rgba(255,255,255,.96);
}

.wk-new-subtitle{
  font-size: 13px;
  color: var(--muted);
  line-height: 1.55;
}

/* -----------------------
   Alerts
------------------------ */
.wk-alert-success,
.wk-alert-error{
  border-radius: var(--r-lg);
  padding: 12px 14px;
  font-size: 13px;
  margin-bottom: 16px;
  line-height: 1.5;
}

.wk-alert-success{
  background: rgba(34,197,94,.14);
  border: 1px solid rgba(34,197,94,.45);
  color: #bbf7d0;
  box-shadow: 0 0 0 1px rgba(34,197,94,.18) inset;
}

.wk-alert-error{
  background: rgba(248,113,113,.14);
  border: 1px solid rgba(248,113,113,.45);
  color: #fecaca;
  box-shadow: 0 0 0 1px rgba(248,113,113,.18) inset;
}

/* -----------------------
   Form grid
------------------------ */
.wk-form-grid{
  display:flex;
  flex-direction:column;
  gap: 14px;
}

/* inline row */
.wk-form-row-inline{
  display:flex;
  gap: 14px;
  flex-wrap: wrap;
}

.wk-form-row-inline .wk-col{
  flex: 1 1 160px;
}

/* -----------------------
   Labels & help
------------------------ */
.wk-field-label{
  display:block;
  font-size: 11px;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: rgba(255,255,255,.65);
  margin-bottom: 6px;
}

.wk-field-help{
  font-size: 12px;
  color: rgba(255,255,255,.45);
  margin-top: 4px;
}

/* -----------------------
   Inputs
------------------------ */
.wk-input,
.wk-select,
.wk-textarea{
  width:100%;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(0,0,0,.25);
  color: rgba(255,255,255,.9);
  padding: 11px 14px;
  font-size: 14px;
  outline:none;
  transition: 160ms ease;
  box-shadow: 0 0 0 1px rgba(255,255,255,.02) inset;
}

.wk-textarea{
  border-radius: var(--r-lg);
  min-height: 90px;
  resize: vertical;
  line-height: 1.55;
}

.wk-input::placeholder,
.wk-textarea::placeholder{
  color: rgba(255,255,255,.35);
}

.wk-input:focus,
.wk-select:focus,
.wk-textarea:focus{
  border-color: rgba(255,138,0,.45);
  background: rgba(255,138,0,.06);
  box-shadow: var(--glow);
}

/* select arrow */
.wk-select{
  appearance:none;
  background-image:
    linear-gradient(45deg, transparent 50%, rgba(255,255,255,.7) 50%),
    linear-gradient(135deg, rgba(255,255,255,.7) 50%, transparent 50%);
  background-position:
    calc(100% - 18px) calc(50% - 2px),
    calc(100% - 12px) calc(50% - 2px);
  background-size: 6px 6px;
  background-repeat: no-repeat;
  padding-right: 42px;
}

/* -----------------------
   Form actions
------------------------ */
.wk-form-actions{
  display:flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-top: 22px;
  padding-top: 18px;
  border-top: 1px solid rgba(255,255,255,.08);
}

/* Buttons */
.wk-btn-secondary,
.wk-btn-primary{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding: 11px 16px;
  border-radius: 999px;
  font-size: 12px;
  letter-spacing: .14em;
  text-transform: uppercase;
  cursor:pointer;
  transition: 170ms ease;
  text-decoration:none;
  white-space: nowrap;
}

.wk-btn-secondary{
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(0,0,0,.28);
  color: rgba(255,255,255,.88);
}

.wk-btn-secondary:hover{
  border-color: rgba(255,138,0,.35);
  background: rgba(255,138,0,.06);
  color:#fff;
  box-shadow: var(--glow);
}

.wk-btn-primary{
  border: 1px solid rgba(255,138,0,.45);
  background: rgba(255,138,0,.12);
  color:#fff;
  box-shadow: var(--glow);
}

.wk-btn-primary:hover{
  background: rgba(255,138,0,.18);
}

/* -----------------------
   Responsive
------------------------ */
@media (max-width: 768px){
  .wk-container-new-ex{
    margin: 18px auto 32px;
    padding: 18px 14px 26px;
  }

  .wk-form-actions{
    flex-direction: column-reverse;
    align-items: stretch;
  }
}
    </style>
</head>
<body>

<header id="rb-static-header" class="rbf1-header">
    <div class="rbf1-topbar">
        <a href="/" class="rbf1-logo">
            <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
        </a>

        <nav class="rbf1-nav" id="rbf1-nav">
            <ul>
                <li><a href="dashboard_personal.php" class="rbf1-link">Dashboard</a></li>
                <li><a href="personal_profile.php" class="rbf1-link">Profile</a></li>
                <li><a href="trainer_workouts.php" class="rbf1-link rbf1-link-active">Workouts</a></li>
                <li><a href="trainer_checkins.php" class="rbf1-link">Check-ins</a></li>
                <li><a href="messages.php" class="rbf1-link">Messages</a></li>
                <li><a href="trainer_clients.php" class="rbf1-link">Clients</a></li>

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

<div class="wk-container wk-container-new-ex">
    <div class="wk-new-header">
        <h1 class="wk-title">Create new exercise</h1>
        <p class="wk-new-subtitle">
            Add a new exercise to your library, with body part, muscles, description and an optional YouTube demo link.
        </p>
    </div>

    <?php if ($success): ?>
        <div class="wk-alert-success">
            Exercise created successfully.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="wk-alert-error">
            <?php foreach ($errors as $err): ?>
                <div><?php echo htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="wk-form-grid">

            <div>
                <label class="wk-field-label" for="name">Exercise name *</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    class="wk-input"
                    required
                    maxlength="255"
                    value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="e.g. Barbell Hip Thrust"
                >
                <div class="wk-field-help">
                    This is the name that will appear in suggestions and in client sessions.
                </div>
            </div>

            <div class="wk-form-row-inline">
                <div class="wk-col">
                    <label class="wk-field-label" for="body_part">Body part</label>
                    <select id="body_part" name="body_part" class="wk-select">
                        <option value="">Select body part...</option>
                        <?php foreach ($bodyParts as $bp): ?>
                            <?php $selected = ($body_part === $bp) ? 'selected' : ''; ?>
                            <option value="<?php echo htmlspecialchars($bp, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($bp, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wk-col">
                    <label class="wk-field-label" for="category">Category</label>
                    <select id="category" name="category" class="wk-select">
                        <option value="">Select category...</option>
                        <?php foreach ($categories as $cat): ?>
                            <?php $selected = ($category === $cat) ? 'selected' : ''; ?>
                            <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="wk-field-label" for="primary_muscles">Primary muscles</label>
                <input
                    type="text"
                    id="primary_muscles"
                    name="primary_muscles"
                    class="wk-input"
                    maxlength="255"
                    value="<?php echo htmlspecialchars($primary_muscles, ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="e.g. Quadriceps, glutes, hamstrings"
                >
            </div>

            <div>
                <label class="wk-field-label" for="description">Description / coaching cues</label>
                <textarea
                    id="description"
                    name="description"
                    class="wk-textarea"
                    placeholder="Short description of setup, execution and main cues..."
                ><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div class="wk-field-help">
                    This text can be shown when the coach or client opens exercise details.
                </div>
            </div>

            <div>
                <label class="wk-field-label" for="youtube_url">YouTube demo URL</label>
                <input
                    type="url"
                    id="youtube_url"
                    name="youtube_url"
                    class="wk-input"
                    maxlength="512"
                    value="<?php echo htmlspecialchars($youtube_url, ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="https://www.youtube.com/watch?v=..."
                >
                <div class="wk-field-help">
                    Optional. If provided, the video can be embedded in the exercise details modal.
                </div>
            </div>

        </div>

        <div class="wk-form-actions">
            <a href="trainer_workouts.php" class="wk-btn-secondary">
                Back to workouts
            </a>
            <button type="submit" class="wk-btn-primary">
                Save exercise
            </button>
        </div>
    </form>
</div>

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

<!-- JS EXTERNO GERAL DO SITE -->
<script src="../script.js"></script>

</body>
</html>
