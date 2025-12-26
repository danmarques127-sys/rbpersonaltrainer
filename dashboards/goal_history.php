<?php
require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client']);

$userId = (int)$_SESSION['user_id'];
$pdo    = getPDO();

/*
======================================================
   1. CARREGA GOALS (exceto canceled) - LIMIT 30
======================================================
*/
$sqlGoals = "
    SELECT *
    FROM client_goals
    WHERE client_id = :client_id
      AND status != 'canceled'
    ORDER BY updated_at DESC, created_at DESC
    LIMIT 30
";
$stmt = $pdo->prepare($sqlGoals);
$stmt->execute(['client_id' => $userId]);
$goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
======================================================
   2. CARREGA PROGRESSO DE TODOS OS GOALS
======================================================
*/
$progressByGoal = [];

if (!empty($goals)) {
    $goalIds = array_map(static fn($g) => (int)$g['id'], $goals);
    $placeholders = implode(',', array_fill(0, count($goalIds), '?'));

    $sqlProgress = "
        SELECT *
        FROM client_goal_progress
        WHERE goal_id IN ($placeholders)
        ORDER BY goal_id ASC, created_at DESC
    ";

    $stmtProgress = $pdo->prepare($sqlProgress);
    $stmtProgress->execute($goalIds);
    $allProgress = $stmtProgress->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allProgress as $p) {
        $gid = (int)$p['goal_id'];
        $progressByGoal[$gid][] = $p;
    }
}

/*
======================================================
   HELPERS
======================================================
*/
function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function renderStatus($s) {
    return match ($s) {
        'active'    => 'Active',
        'completed' => 'Completed',
        'paused'    => 'Paused',
        default     => ucfirst((string)$s),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
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
  <link rel="stylesheet" href="/assets/css/goal_history.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">
  <meta charset="UTF-8" />
  <title>Client Profile | RB Personal Trainer | Rafa Breder Coaching</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow">
</head>
<body>

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

<main class="client-dashboard">
  <div class="goal-history-main">

<div class="page-header">
    <h1>Goal History</h1>
    <p>View all goals and every progress entry ever created.</p>
    <a href="client_goals.php" class="back-btn">← Back to Goals</a>
</div>

<div class="history-container">

<?php if (empty($goals)): ?>
    <p class="empty-history">No goals found yet.</p>
<?php else: ?>

<?php foreach ($goals as $goal): ?>
<div class="goal-card-full">

    <div class="goal-header">
        <h2><?= h($goal['title']) ?></h2>
        <span class="status-pill status-<?= h($goal['status']) ?>">
            <?= renderStatus($goal['status']) ?>
        </span>
    </div>

    <div class="goal-meta">
        <p><strong>Period:</strong>
            <?= $goal['start_date'] ? date('M d, Y', strtotime($goal['start_date'])) : '—' ?>
            →
            <?= $goal['end_date'] ? date('M d, Y', strtotime($goal['end_date'])) : '—' ?>
        </p>

        <p><strong>Values:</strong>
            <?= h($goal['start_value']) ?> <?= h($goal['unit']) ?>
            →
            <?= h($goal['target_value']) ?> <?= h($goal['unit']) ?>
        </p>
    </div>

    <div class="progress-list">
        <h3>Progress Log</h3>

        <?php
            $gid = (int)$goal['id'];
            $progressRows = $progressByGoal[$gid] ?? [];
        ?>

        <?php if (empty($progressRows)): ?>
            <p class="no-progress">No progress recorded.</p>
        <?php else: ?>
            <?php foreach ($progressRows as $p): ?>
                <div class="progress-item">
                    <div>
                        <strong>+<?= number_format((float)$p['progress_value'], 2) ?></strong>
                        <?= h($goal['unit']) ?>
                    </div>
                    <div class="progress-date">
                        <?= date('M d, Y H:i', strtotime($p['created_at'])) ?>
                    </div>
                    <?php if (!empty($p['note'])): ?>
                        <div class="progress-note">
                            <?= nl2br(h($p['note'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
<?php endforeach; ?>

<?php endif; ?>

</div>
</main>
</div>

<!-- FOOTER (corrigido: sem footer duplicado) -->
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
        <li><a href="/dashboards/dashboard_client.php">Dashboard</a></li>
        <li><a href="/dashboards/client_profile.php">Profile</a></li>
        <li><a href="/dashboards/client_goals.php">Goals</a></li>
        <li><a href="/dashboards/client_workouts.php">Workouts</a></li>
        <li><a href="/dashboards/client_nutrition.php">Nutrition</a></li>
        <li><a href="/dashboards/messages.php">Messages</a></li>
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
    <p class="footer-bottom-text">© 2025 RB Personal Trainer. All rights reserved.</p>
  </div>
</footer>

<script src="/assets/js/script.js"></script>
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
