<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user','client']);

$pdo = getPDO();

$userId   = (int)($_SESSION['user_id'] ?? 0);
$clientId = $userId;

// ===============================
// GOALS ATIVOS
// ===============================
$sqlGoals = "
    SELECT *
    FROM client_goals
    WHERE client_id = :client_id
      AND status = 'active'
    ORDER BY 
        priority = 'main' DESC,
        end_date IS NULL,
        end_date ASC
";

$stmtGoals = $pdo->prepare($sqlGoals);
$stmtGoals->execute(['client_id' => $clientId]);
$allGoals = $stmtGoals->fetchAll(PDO::FETCH_ASSOC);

$mainGoals      = [];
$secondaryGoals = [];
foreach ($allGoals as $g) {
    if (($g['priority'] ?? '') === 'main') $mainGoals[] = $g;
    else $secondaryGoals[] = $g;
}

$goalTypes = [
    'weight'      => 'Weight / Body composition',
    'body_comp'   => 'Body fat / Lean mass',
    'measure'     => 'Body measurements',
    'performance' => 'Performance / Conditioning',
    'habit'       => 'Habits / Lifestyle',
    'nutrition'   => 'Nutrition',
    'custom'      => 'Custom goals',
];

$goalsByType = [];
foreach ($allGoals as $goal) {
    $typeKey = (string)($goal['type'] ?? 'custom');
    if (!isset($goalTypes[$typeKey])) $typeKey = 'custom';
    $goalsByType[$typeKey][] = $goal;
}

// ===============================
// HISTÓRICO RECENTE
// ===============================
$sqlHistory = "
    SELECT gp.*, g.title
    FROM client_goal_progress gp
    JOIN client_goals g ON g.id = gp.goal_id
    WHERE g.client_id = :client_id
    ORDER BY gp.log_date DESC, gp.created_at DESC
    LIMIT 10
";
$stmtHistory = $pdo->prepare($sqlHistory);
$stmtHistory->execute(['client_id' => $clientId]);
$recentHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// CONTAGENS
// ===============================
$statusCounts = ['active'=>0,'completed'=>0,'paused'=>0];
foreach ($allGoals as $g) {
    $st = (string)($g['status'] ?? '');
    if (isset($statusCounts[$st])) $statusCounts[$st]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Client Goals | RB Personal Trainer | Rafa Breder Coaching</title>
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

  <!-- CSS (ABSOLUTO /assets/) -->
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/client_goals.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">
</head>

<body class="client-dashboard client-profile-page client-goals-page">

<!-- ===========================
     HEADER — FORA DO SHELL (CORRETO)
=========================== -->
<header id="rb-static-header" class="rbf1-header">
  <div class="rbf1-topbar">
    <a href="/" class="rbf1-logo">
      <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
    </a>

    <nav class="rbf1-nav" id="rbf1-nav">
      <ul>
        <li><a href="/dashboards/dashboard_client.php">Dashboard</a></li>
        <li><a href="/dashboards/client_profile.php">Profile</a></li>
        <li><a href="/dashboards/client_goals.php" class="rbf1-link rbf1-link-active">Goals</a></li>
        <li><a href="/dashboards/messages.php">Messages</a></li>
        <li><a href="/dashboards/client_workouts.php">Workout</a></li>
        <li><a href="/dashboards/client_nutrition.php">Nutritionist</a></li>
        <li><a href="/dashboards/progress_gallery.php">Photos Gallery</a></li>

        <!-- Logout só no mobile (mantido) -->
        <li class="mobile-only">
          <a href="/login.php" class="rb-mobile-logout">Logout</a>
        </li>
      </ul>
    </nav>

    <div class="rbf1-right">
      <a href="/login.php" class="rbf1-login">Logout</a>
    </div>

    <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
      ☰
    </button>
  </div>
</header>

<!-- ===========================
     MAIN + CLIENT SHELL
=========================== -->
<main class="client-main client-main--goals">
  <div class="client-shell">

    <section class="client-section client-section--goals">

      <div class="section-header">
        <div>
          <h1 class="section-title">My Goals</h1>
          <p class="section-subtitle">
            Track your goals by category, priority, and progress history.
          </p>
        </div>
      </div>

      <div class="goals-layout">

        <!-- LEFT -->
        <div class="goals-column goals-column--list">

          <?php foreach ($goalTypes as $typeKey => $typeLabel):
            $goalsThisType = $goalsByType[$typeKey] ?? [];
          ?>
          <section class="goals-block goals-block--type-<?php echo htmlspecialchars((string)$typeKey, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="goals-block-header">
              <h2 class="goals-block-title"><?php echo htmlspecialchars((string)$typeLabel, ENT_QUOTES, 'UTF-8'); ?></h2>
              <span class="goals-block-count"><?php echo (int)count($goalsThisType); ?> active</span>
            </div>

            <div class="goals-grid goals-grid--main">

              <?php if (empty($goalsThisType)): ?>
                <p class="goals-empty">You don't have goals in this category yet.</p>

              <?php else: foreach ($goalsThisType as $goal): ?>
                <?php
                  $statusRaw = (string)($goal['status'] ?? 'active');
                  $statusKey = htmlspecialchars($statusRaw, ENT_QUOTES, 'UTF-8');
                ?>
                <article class="goal-card goal-status-<?php echo $statusKey; ?>">

                  <header class="goal-card-header">
                    <div>
                      <h3 class="goal-card-title"><?php echo htmlspecialchars((string)($goal['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                      <p class="goal-card-type">
                        <?php echo (($goal['priority'] ?? '') === 'main') ? 'Main goal' : 'Secondary goal'; ?>
                      </p>
                    </div>
                    <div class="goal-card-status">
                      <span class="goal-status-badge goal-status-badge--<?php echo $statusKey; ?>">
                        <?php echo htmlspecialchars(ucfirst($statusRaw), ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                    </div>
                  </header>

                  <div class="goal-card-body">
                    <?php if (!empty($goal['description'])): ?>
                      <p class="goal-card-description">
                        <?php echo nl2br(htmlspecialchars((string)$goal['description'], ENT_QUOTES, 'UTF-8')); ?>
                      </p>
                    <?php endif; ?>

                    <div class="goal-card-meta">
                      <?php if (!empty($goal['start_value']) || !empty($goal['target_value'])): ?>
                        <div class="goal-meta-item">
                          <span class="goal-meta-label">Target</span>
                          <span class="goal-meta-value">
                            <?php if (!empty($goal['start_value'])): ?>
                              <?php echo htmlspecialchars((string)$goal['start_value'], ENT_QUOTES, 'UTF-8'); ?>
                              <?php echo htmlspecialchars((string)($goal['unit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> →
                            <?php endif; ?>
                            <?php echo htmlspecialchars((string)($goal['target_value'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                          </span>
                        </div>
                      <?php endif; ?>

                      <div class="goal-meta-item">
                        <span class="goal-meta-label">Period</span>
                        <span class="goal-meta-value">
                          <?php
                            $sd = (string)($goal['start_date'] ?? '');
                            $ed = (string)($goal['end_date'] ?? '');

                            if ($sd !== '') echo htmlspecialchars(date('M d, Y', strtotime($sd)), ENT_QUOTES, 'UTF-8');
                            if ($ed !== '') echo ' → ' . htmlspecialchars(date('M d, Y', strtotime($ed)), ENT_QUOTES, 'UTF-8');
                            if ($sd === '' && $ed === '') echo 'Not defined';
                          ?>
                        </span>
                      </div>
                    </div>

                    <div class="goal-progress">
                      <div class="goal-progress-bar">
                        <div class="goal-progress-fill" style="width:0%"></div>
                      </div>
                      <span class="goal-progress-label">Progress: <strong>0%</strong></span>
                    </div>
                  </div>

                  <footer class="goal-card-footer">
                    <a href="/dashboards/client_goal_progress.php?goal_id=<?php echo (int)($goal['id'] ?? 0); ?>"
                       class="goal-details-cta">
                      View Details
                    </a>
                  </footer>

                </article>
              <?php endforeach; endif; ?>

            </div>
          </section>
          <?php endforeach; ?>

        </div>

        <!-- RIGHT -->
        <aside class="goals-column goals-column--aside">

          <section class="goals-summary-card">
            <h2 class="goals-summary-title">Overview</h2>
            <ul class="goals-summary-list">
              <li><span>Total goals</span><strong><?php echo (int)count($allGoals); ?></strong></li>
              <li><span>Main goals</span><strong><?php echo (int)count($mainGoals); ?></strong></li>
              <li><span>Secondary goals</span><strong><?php echo (int)count($secondaryGoals); ?></strong></li>
              <li><span>Active</span><strong><?php echo (int)$statusCounts['active']; ?></strong></li>
              <li><span>Completed</span><strong><?php echo (int)$statusCounts['completed']; ?></strong></li>
              <li><span>Paused</span><strong><?php echo (int)$statusCounts['paused']; ?></strong></li>
            </ul>
          </section>

          <section class="goals-history-card">
            <h2 class="goals-history-title">Recent History</h2>

            <?php if (empty($recentHistory)): ?>
              <p class="goals-empty">No history yet. Add progress to your goals.</p>
            <?php else: ?>
              <?php $recentHistory = array_slice($recentHistory, 0, 2); ?>
              <ul class="goals-history-list">
                <?php foreach ($recentHistory as $entry): ?>
                  <li class="goals-history-item">
                    <div class="goals-history-main">
                      <span class="goals-history-goal">
                        <?php echo htmlspecialchars((string)($entry['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                      <span class="goals-history-date">
                        <?php
                          $ld = (string)($entry['log_date'] ?? '');
                          echo $ld !== '' ? htmlspecialchars(date('M d, Y', strtotime($ld)), ENT_QUOTES, 'UTF-8') : '';
                        ?>
                      </span>
                    </div>

                    <div class="goals-history-extra">
                      <?php if (($entry['value'] ?? null) !== null): ?>
                        <span class="goals-history-value">
                          <?php
                            $val = (float)$entry['value'];
                            echo htmlspecialchars(rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8');
                          ?>
                        </span>
                      <?php endif; ?>

                      <?php if (!empty($entry['note'])): ?>
                        <p class="goals-history-note">
                          <?php echo nl2br(htmlspecialchars((string)$entry['note'], ENT_QUOTES, 'UTF-8')); ?>
                        </p>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>

          <div class="goals-bottom-cta-right">
            <a href="/dashboards/client_goal_update.php" class="btn-flat">New Goal</a>
          </div>

        </aside>

      </div>

    </section>

  </div>
</main>

<!-- FOOTER IGUAL AO SITE -->
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

<!-- JS EXTERNO (ABSOLUTO /assets/) -->
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
