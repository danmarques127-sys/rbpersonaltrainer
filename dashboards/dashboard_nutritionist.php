<?php
// dashboard_nutrition.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo = getPDO();

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Reconfere specialty para segurança (ajuste o valor para o que você usa no BD)
$stmt = $pdo->prepare("SELECT specialty FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$currentUserId]);
$specialty = (string)$stmt->fetchColumn();

if ($specialty !== 'nutritionist') { // <-- se no BD for outro valor, troque aqui
    header('Location: dashboard_pro.php');
    exit();
}

$coachName = (string)($_SESSION['user_name'] ?? 'Coach');

// ===============================
// VALORES PADRÃO DO DASHBOARD NUTRIÇÃO (MVP)
// ===============================

$welcome = false;

// Cards do topo
$activeClientsCount        = 0;
$mealPlansToUpdateCount    = 0;
$unreadMessagesCount       = 0;

// Card "Today / priorities" (nutrição)
$todayMealPlansToUpdate    = 0;
$todayFoodLogsToReview     = 0;
$todayMessagesToReply      = 0;

// Listas
$clientsDueMealPlans       = []; // clientes com plano alimentar vencendo/vencido
$recentClients             = []; // últimos clientes
$recentMessages            = []; // últimas mensagens

// Check-ins / revisões de nutrição
$pendingNutritionCheckinsCount = 0;

// Avatar (se existir em sessão; caso contrário, fallback)
$avatarUrl = (string)($_SESSION['avatar_url'] ?? 'images/default-avatar.png');

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Nutrition Dashboard | RB Personal Trainer | Nutrition Coaching</title>
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

  <!-- SEO BASICS -->
  <meta name="description" content="Nutrition dashboard for RB Personal Trainer online nutrition coaching." />
  <meta name="author" content="Rafaella Breder" />
  <meta name="theme-color" content="#FF7A00" />

  <!-- CANONICAL -->
  <link rel="canonical" href="https://www.rbpersonaltrainer.com/" />

  <!-- OPEN GRAPH / FACEBOOK -->
  <meta property="og:type" content="website" />
  <meta property="og:title" content="RB Personal Trainer | Online Nutrition & Training Coaching" />
  <meta property="og:description" content="Online nutrition and training coaching with customized meal plans, macro tracking and ongoing support." />
  <meta property="og:url" content="https://www.rbpersonaltrainer.com/" />
  <meta property="og:site_name" content="RB Personal Trainer" />
  <meta property="og:locale" content="en_US" />
  <meta property="og:image" content="https://www.rbpersonaltrainer.com/images/og-rb-personal-trainer.jpg" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />

  <!-- TWITTER CARDS -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="RB Personal Trainer | Online Nutrition Coaching" />
  <meta name="twitter:description" content="Online nutrition coaching with customized meal plans, macro tracking and food log reviews." />
  <meta name="twitter:image" content="https://www.rbpersonaltrainer.com/images/og-rb-personal-trainer.jpg" />
  <meta name="twitter:creator" content="@rbpersonaltrainer" />

  <!-- GEO / LOCAL -->
  <meta name="geo.region" content="US-MA" />
  <meta name="geo.placename" content="Boston" />

  <link rel="stylesheet" href="dashboard_nutritionist.css">
  <link rel="stylesheet" href="global.css">
  <link rel="stylesheet" href="header.css">
  <link rel="stylesheet" href="footer.css">
</head>
<body>

  <?php if ($welcome): ?>
    <div class="welcome-banner">
      Welcome, <?= htmlspecialchars((string)($_SESSION['user_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>!
      Your account has been created successfully.
    </div>
  <?php endif; ?>

  <!-- HEADER FORA DO SHELL -->
  <header id="rb-static-header" class="rbf1-header">
    <div class="rbf1-topbar">
      <a href="/" class="rbf1-logo">
        <img src="../assets/images/logo.svg" alt="RB Personal Trainer Logo">
      </a>

      <nav class="rbf1-nav" id="rbf1-nav">
        <ul>
          <li><a href="dashboard_nutrition.php" class="rbf1-link rbf1-link-active">Dashboard</a></li>
          <li><a href="nutrition_profile.php">Edit Profile</a></li>
          <li><a href="nutrition_mealplans.php">Meal plans</a></li>
          <li><a href="nutrition_foodlogs.php">Food logs</a></li>
          <li><a href="nutrition_checkins.php">Check-ins</a></li>
          <li><a href="nutrition_clients.php">Clients</a></li>
          <li><a href="nutrition_messages.php">Messages</a></li>

          <!-- Logout só no mobile -->
          <li class="mobile-only">
            <a href="../login.php" class="rb-mobile-logout">Logout</a>
          </li>
        </ul>
      </nav>

      <div class="rbf1-right">
        <!-- Logout desktop (arquivo em /dashboards/ => ../login.php) -->
        <a href="../login.php" class="rbf1-login">Logout</a>
      </div>

      <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
        ☰
      </button>
    </div>
  </header>

  <!-- REUTILIZANDO O MESMO CSS (.coach-*) PARA O DASHBOARD DE NUTRIÇÃO -->
  <main class="coach-dashboard">
    <div class="coach-shell">

      <!-- HERO -->
      <section class="coach-hero">
        <div class="coach-hero-left">

          <div class="coach-avatar-wrapper">
            <div class="coach-avatar">
              <img
                src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>"
                alt="<?= htmlspecialchars('Profile photo of ' . $coachName, ENT_QUOTES, 'UTF-8'); ?>"
              >
            </div>
          </div>

          <div class="coach-hero-text">
            <p class="coach-eyebrow">Nutrition Dashboard</p>
            <h1 class="coach-title">Hi, <?= htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8'); ?>.</h1>
            <p class="coach-subtitle">
              Manage your clients’ meal plans, macros, food logs and nutrition check-ins in one place.
            </p>
          </div>

        </div>

        <div class="coach-hero-right">
          <div class="coach-pill">
            Active clients <span><?= (int)$activeClientsCount; ?></span>
          </div>
          <div class="coach-pill coach-pill--alert">
            Meal plans to update <span><?= (int)$mealPlansToUpdateCount; ?></span>
          </div>
          <div class="coach-pill">
            Unread messages <span><?= (int)$unreadMessagesCount; ?></span>
          </div>
        </div>
      </section>

      <!-- GRID PRINCIPAL -->
      <section class="coach-main-grid">

        <!-- PAINEL 1: HOJE / PRIORIDADES -->
        <article class="coach-panel coach-panel-wide">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Today</p>
              <h2 class="coach-panel-title">Your priorities for today</h2>
            </div>
            <a href="nutrition_schedule.php" class="coach-panel-link">Open full schedule →</a>
          </header>

          <div class="coach-panel-body coach-panel-body--split">

            <div>
              <p class="coach-panel-label">Overview</p>
              <p class="coach-panel-main">
                <?= (int)$todayMealPlansToUpdate; ?> meal plans to update •
                <?= (int)$todayFoodLogsToReview; ?> food logs to review •
                <?= (int)$todayMessagesToReply; ?> messages to reply
              </p>
            </div>

            <div class="coach-mini-grid">
              <div class="coach-mini-box">
                <p class="coach-mini-label">Meal plans to update</p>
                <p class="coach-mini-value"><?= (int)$todayMealPlansToUpdate; ?></p>
              </div>
              <div class="coach-mini-box">
                <p class="coach-mini-label">Food logs to review</p>
                <p class="coach-mini-value"><?= (int)$todayFoodLogsToReview; ?></p>
              </div>
              <div class="coach-mini-box">
                <p class="coach-mini-label">Messages to reply</p>
                <p class="coach-mini-value"><?= (int)$todayMessagesToReply; ?></p>
              </div>
            </div>

          </div>
        </article>

        <!-- PAINEL 2: CLIENTES COM PLANO ALIMENTAR PARA ATUALIZAR -->
        <article class="coach-panel">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Meal plans</p>
              <h2 class="coach-panel-title">Clients needing a new plan</h2>
            </div>
            <a href="nutrition_clients.php?filter=due-mealplans" class="coach-panel-link">View all →</a>
          </header>

          <div class="coach-panel-body">
            <?php if (empty($clientsDueMealPlans)): ?>
              <p class="coach-panel-text">
                No clients need a new meal plan right now.
              </p>
            <?php else: ?>
              <ul class="coach-list">
                <?php foreach ($clientsDueMealPlans as $client): ?>
                  <li class="coach-list-item">
                    <div>
                      <p class="coach-list-title"><?= htmlspecialchars((string)$client['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <p class="coach-list-meta">
                        Plan type: <?= htmlspecialchars((string)$client['plan_type'], ENT_QUOTES, 'UTF-8'); ?> •
                        expired <?= htmlspecialchars((string)$client['days_overdue'], ENT_QUOTES, 'UTF-8'); ?> days ago
                      </p>
                    </div>
                    <a href="nutrition_mealplan_edit.php?client_id=<?= (int)$client['id']; ?>" class="coach-panel-link subtle">
                      Build / update meal plan →
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </article>

        <!-- PAINEL 3: CLIENTES -->
        <article class="coach-panel">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Clients</p>
              <h2 class="coach-panel-title">Your clients</h2>
            </div>
            <a href="nutrition_clients.php" class="coach-panel-link">Open client list →</a>
          </header>

          <div class="coach-panel-body">
            <?php if (empty($recentClients)): ?>
              <p class="coach-panel-text">
                No clients assigned yet.
              </p>
            <?php else: ?>
              <ul class="coach-list">
                <?php foreach ($recentClients as $client): ?>
                  <li class="coach-list-item">
                    <div>
                      <p class="coach-list-title"><?= htmlspecialchars((string)$client['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <p class="coach-list-meta">
                        Last food log:
                        <?= htmlspecialchars((string)($client['last_foodlog'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?> •
                        Last nutrition check-in:
                        <?= htmlspecialchars((string)($client['last_nutrition_checkin'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                      </p>
                    </div>
                    <a href="nutrition_client_profile.php?id=<?= (int)$client['id']; ?>" class="coach-panel-link subtle">
                      Open profile →
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </article>

        <!-- PAINEL 4: MENSAGENS & CHECK-INS DE NUTRIÇÃO -->
        <article class="coach-panel">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Communication</p>
              <h2 class="coach-panel-title">Messages & nutrition check-ins</h2>
            </div>
            <a href="nutrition_messages.php" class="coach-panel-link">Open inbox →</a>
          </header>

          <div class="coach-panel-body">
            <?php if (empty($recentMessages)): ?>
              <p class="coach-panel-text">
                No new messages from clients.
              </p>
            <?php else: ?>
              <ul class="coach-list">
                <?php foreach ($recentMessages as $message): ?>
                  <li class="coach-list-item">
                    <div>
                      <p class="coach-list-title">
                        <?= htmlspecialchars((string)$message['client_name'], ENT_QUOTES, 'UTF-8'); ?>
                      </p>
                      <p class="coach-list-meta">
                        “<?= htmlspecialchars((string)$message['preview'], ENT_QUOTES, 'UTF-8'); ?>”
                        • <?= htmlspecialchars((string)$message['time_ago'], ENT_QUOTES, 'UTF-8'); ?>
                      </p>
                    </div>
                    <a href="nutrition_messages.php?client_id=<?= (int)$message['client_id']; ?>" class="coach-panel-link subtle">
                      Reply →
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <div class="coach-inline-box">
              <div>
                <p class="coach-mini-label">Nutrition check-ins</p>

                <?php if ($pendingNutritionCheckinsCount > 0): ?>
                  <p class="coach-mini-value">
                    <?= (int)$pendingNutritionCheckinsCount; ?> pending to review
                  </p>
                <?php else: ?>
                  <p class="coach-mini-value coach-mini-value--muted">
                    No nutrition check-ins pending this week
                  </p>
                <?php endif; ?>
              </div>

              <a href="nutrition_checkins.php" class="coach-panel-link subtle">
                Review nutrition check-ins →
              </a>
            </div>
          </div>
        </article>

      </section>

      <div class="section-divider" role="separator" aria-hidden="true"></div>

    </div>
  </main>

  <!-- FOOTER FORA DO SHELL -->
  <footer class="site-footer">
    <div class="footer-cta">
      <div class="footer-cta-inner">
        <p class="footer-cta-eyebrow">Online Personal Training</p>
        <h2 class="footer-cta-title">Ready to go full throttle in your transformation?</h2>
        <a href="/contact.html" class="footer-cta-button">Contact Us</a>
      </div>
    </div>

    <div class="footer-main">
      <div class="footer-col footer-brand">
        <a href="/" class="footer-logo">
          <img src="../assets/images/logo.svg" alt="RB Personal Trainer Logo">
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
          <li><a href="dashboard_nutrition.php">Dashboard</a></li>
          <li><a href="nutrition_profile.php">Edit Profile</a></li>
          <li><a href="nutrition_mealplans.php">Meal plans</a></li>
          <li><a href="nutrition_foodlogs.php">Food logs</a></li>
          <li><a href="nutrition_checkins.php">Check-ins</a></li>
          <li><a href="nutrition_clients.php">Clients</a></li>
          <li><a href="nutrition_messages.php">Messages</a></li>

          <!-- Logout só no mobile -->
          <li class="mobile-only">
            <a href="../login.php" class="rb-mobile-logout">Logout</a>
          </li>
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
                  <img src="../assets/images/instagram.png" alt="Instagram Logo">
                </a>
                <a class="social-icon" href="https://www.facebook.com/rbpersonaltrainer" target="_blank" rel="noopener">
                  <img src="../assets/images/facebook.png" alt="Facebook Logo">
                </a>
                <a class="social-icon" href="https://www.linkedin.com" target="_blank" rel="noopener">
                  <img src="../assets/images/linkedin.png" alt="LinkedIn Logo">
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
