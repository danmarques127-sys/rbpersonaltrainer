<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user','client']);

$pdo = getPDO();

$userId = (int)($_SESSION['user_id'] ?? 0);
$welcome = isset($_GET['welcome']);

$welcome = isset($_GET['welcome']);

//
// ===============================
// DADOS DO USUÁRIO
// ===============================
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        avatar_url,
        phone,
        gender,
        birthday,
        height_cm,
        weight_kg,
        time_zone,
        main_goal
    FROM users
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$userNameRaw = $userRow['name'] ?? ($_SESSION['user_name'] ?? 'Client');
$userName    = htmlspecialchars((string)$userNameRaw, ENT_QUOTES, 'UTF-8');

$avatarUrl = !empty($userRow['avatar_url'])
    ? htmlspecialchars((string)$userRow['avatar_url'], ENT_QUOTES, 'UTF-8')
    : '/assets/images/client-avatar-placeholder.jpg';

$email       = (string)($userRow['email'] ?? '');
$phone       = (string)($userRow['phone'] ?? '');
$gender      = (string)($userRow['gender'] ?? '');
$dateOfBirth = (string)($userRow['birthday'] ?? '');
$heightCm    = $userRow['height_cm'] ?? '';
$weightKg    = $userRow['weight_kg'] ?? '';
$timeZone    = (string)($userRow['time_zone'] ?? '');
$mainGoal    = (string)($userRow['main_goal'] ?? '');


//
// ===============================
// ÚLTIMO GOAL DO CLIENTE
// ===============================
$lastGoal = null;
try {
    $stmtLastGoal = $pdo->prepare("
        SELECT title
        FROM client_goals
        WHERE client_id = :client_id
          AND status <> 'canceled'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtLastGoal->execute(['client_id' => $userId]);
    $lastGoalRow = $stmtLastGoal->fetch(PDO::FETCH_ASSOC);
    $lastGoal    = $lastGoalRow['title'] ?? null;
} catch (Throwable $e) {
    $lastGoal = null;
}


//
// ===============================
// ÚLTIMA FOTO DE PROGRESSO
// ===============================
$lastPhoto = null;
try {
    $stmtLastPhoto = $pdo->prepare("
        SELECT file_path, taken_at, created_at
        FROM progress_photos
        WHERE user_id = :uid
        ORDER BY taken_at DESC, created_at DESC
        LIMIT 1
    ");
    $stmtLastPhoto->execute(['uid' => $userId]);
    $lastPhoto = $stmtLastPhoto->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $lastPhoto = null;
}


//
// ===============================
// COACH CONECTADO (se houver)
// ===============================
$coachName   = null;
$coachAvatar = null;

try {
    $stmtCoach = $pdo->prepare("
        SELECT u.id, u.name, u.avatar_url
        FROM coach_clients cc
        JOIN users u ON u.id = cc.coach_id
        WHERE cc.client_id = :client_id
        ORDER BY cc.created_at DESC
        LIMIT 1
    ");
    $stmtCoach->execute(['client_id' => $userId]);
    $coachRow = $stmtCoach->fetch(PDO::FETCH_ASSOC);

    if ($coachRow) {
        $coachName   = $coachRow['name'] ?? null;
        $coachAvatar = $coachRow['avatar_url'] ?? null;
    }
} catch (Throwable $e) {
    $coachName   = null;
    $coachAvatar = null;
}


//
// ===============================
// ÚLTIMA MENSAGEM RECEBIDA
// ===============================
// ⚠️ Ajuste aqui se sua tabela/colunas forem diferentes.
// Padrão comum: messages(to_user_id, from_user_id, subject, body, created_at, is_read)
$lastMessage = null;

try {
    $stmtMsg = $pdo->prepare("
        SELECT id, subject, body, created_at, from_user_id
        FROM messages
        WHERE to_user_id = :uid
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $stmtMsg->execute(['uid' => $userId]);
    $lastMessage = $stmtMsg->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $lastMessage = null;
}


//
// ===============================
// ÚLTIMO CHECK-IN DO CLIENTE
// ===============================
// ⚠️ Ajuste aqui conforme seu schema.
// Padrão comum: client_checkins(user_id, weight_kg, notes, created_at)
$lastCheckin = null;

try {
    $stmtCheckin = $pdo->prepare("
        SELECT id, weight_kg, notes, created_at
        FROM client_checkins
        WHERE user_id = :uid
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $stmtCheckin->execute(['uid' => $userId]);
    $lastCheckin = $stmtCheckin->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $lastCheckin = null;
}


//
// ===============================
// ÚLTIMA ATUALIZAÇÃO DE TREINO / PLANO
// ===============================
// ⚠️ Ajuste conforme suas tabelas.
// Padrão comum: workout_plans(client_id, title, updated_at)
// ou workout_sessions(client_id, started_at/created_at)
$lastWorkoutUpdate = null;

try {
    $stmtWU = $pdo->prepare("
        SELECT id, title, updated_at
        FROM workout_plans
        WHERE client_id = :uid
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $stmtWU->execute(['uid' => $userId]);
    $lastWorkoutUpdate = $stmtWU->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $lastWorkoutUpdate = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/dashboard_client.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">
  <meta charset="UTF-8" />
  <title>Client Dashboard | RB Personal Trainer | Rafa Breder Coaching</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- IMPORTANT FOR PRIVATE PAGES -->
  <meta name="robots" content="noindex, nofollow">

  <!-- SEO BASICS -->
  <meta name="description" content="Client dashboard for RB Personal Trainer online coaching." />
  <meta name="author" content="Rafaella Breder" />
  <meta name="theme-color" content="#FF7A00" />

  <!-- CANONICAL -->
  <link rel="canonical" href="https://www.rbpersonaltrainer.com/" />

  <!-- FAVICONS -->
  <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
  <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
  <link rel="manifest" href="/assets/images/site.webmanifest">
  <meta name="msapplication-TileColor" content="#FF7A00">
  <meta name="msapplication-TileImage" content="/assets/images/mstile-150x150.png">

  <!-- OPEN GRAPH / FACEBOOK -->
  <meta property="og:type" content="website" />
  <meta property="og:title" content="RB Personal Trainer | Online Coaching with Rafa Breder" />
  <meta property="og:description" content="Transform your body and your lifestyle with RB Personal Trainer. Online coaching, custom workout plans and ongoing support, based in the Boston area and serving clients across the United States." />
  <meta property="og:url" content="https://www.rbpersonaltrainer.com/" />
  <meta property="og:site_name" content="RB Personal Trainer" />
  <meta property="og:locale" content="en_US" />
  <meta property="og:image" content="https://www.rbpersonaltrainer.com/assets/images/og-rb-personal-trainer.jpg" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />

  <!-- TWITTER CARDS -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="RB Personal Trainer | Online Personal Training with Rafa Breder" />
  <meta name="twitter:description" content="Online coaching, customized training plans and ongoing support with RB Personal Trainer. Boston-based, serving high-end clients online across the United States." />
  <meta name="twitter:image" content="https://www.rbpersonaltrainer.com/assets/images/og-rb-personal-trainer.jpg" />
  <meta name="twitter:creator" content="@rbpersonaltrainer" />

  <!-- GEO / LOCAL -->
  <meta name="geo.region" content="US-MA" />
  <meta name="geo.placename" content="Boston" />

  <!-- JSON-LD: WEBSITE -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "RB Personal Trainer",
    "url": "https://www.rbpersonaltrainer.com/",
    "description": "Online personal training and coaching with RB Personal Trainer (Rafa Breder), Boston-based trainer working with clients across the United States.",
    "inLanguage": "en-US",
    "publisher": {
      "@type": "Person",
      "name": "Rafaella Breder",
      "url": "https://www.rbpersonaltrainer.com/"
    }
  }
  </script>

  <!-- JSON-LD: PERSON / PERSONAL TRAINER -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Person",
    "name": "Rafaella Breder",
    "alternateName": ["Rafa Breder", "RB Personal Trainer"],
    "jobTitle": "Online Personal Trainer",
    "description": "Online personal trainer based in the Boston area, helping clients across the United States with customized coaching for fat loss, muscle gain, recovery and performance.",
    "image": "https://www.rbpersonaltrainer.com/assets/images/rafa-breder-profile.jpg",
    "url": "https://www.rbpersonaltrainer.com/",
    "sameAs": [
      "https://www.instagram.com/rbpersonaltrainer",
      "https://www.facebook.com/rbpersonaltrainer"
    ],
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Boston",
      "addressRegion": "MA",
      "addressCountry": "US"
    },
    "areaServed": [
      { "@type": "Country", "name": "United States" }
    ],
    "knowsAbout": [
      "online personal training",
      "hypertrophy training",
      "weight loss and fat loss",
      "functional training",
      "injury recovery exercises",
      "pre and postnatal training",
      "cardio and running preparation",
      "lifestyle and mindset coaching"
    ]
  }
  </script>

  <!-- JSON-LD: LOCAL BUSINESS -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    "name": "RB Personal Trainer",
    "image": "https://www.rbpersonaltrainer.com/assets/images/rafa-breder-profile.jpg",
    "url": "https://www.rbpersonaltrainer.com/",
    "description": "Premium online personal training and coaching for professionals and high-income clients in the Boston area and across Massachusetts, with customized programs for fat loss, muscle gain and performance.",
    "priceRange": "$$$",
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Boston",
      "addressRegion": "MA",
      "addressCountry": "US"
    },
    "areaServed": [
      { "@type": "City", "name": "Boston" },
      { "@type": "City", "name": "Cambridge" },
      { "@type": "City", "name": "Brookline" },
      { "@type": "City", "name": "Newton" },
      { "@type": "City", "name": "Wellesley" },
      { "@type": "City", "name": "Weston" },
      { "@type": "City", "name": "Dover" },
      { "@type": "City", "name": "Lexington" },
      { "@type": "City", "name": "Concord" },
      { "@type": "City", "name": "Needham" },
      { "@type": "City", "name": "Winchester" },
      { "@type": "City", "name": "Belmont" },
      { "@type": "City", "name": "Wayland" },
      { "@type": "City", "name": "Sudbury" },
      { "@type": "City", "name": "Lincoln" },
      { "@type": "City", "name": "Carlisle" },
      { "@type": "City", "name": "Sherborn" },
      { "@type": "City", "name": "Medfield" },
      { "@type": "City", "name": "Westwood" },
      { "@type": "City", "name": "Hingham" },
      { "@type": "City", "name": "Cohasset" },
      { "@type": "City", "name": "Duxbury" },
      { "@type": "City", "name": "Marblehead" },
      { "@type": "City", "name": "Manchester-by-the-Sea" },
      { "@type": "City", "name": "Andover" },
      { "@type": "City", "name": "North Andover" },
      { "@type": "City", "name": "Arlington" },
      { "@type": "City", "name": "Milton" },
      { "@type": "City", "name": "Somerville" },
      { "@type": "City", "name": "Beverly" },
      { "@type": "City", "name": "Winthrop" },
      { "@type": "City", "name": "Watertown" },
      { "@type": "City", "name": "Woburn" },
      { "@type": "City", "name": "Burlington" },
      { "@type": "City", "name": "Reading" },
      { "@type": "City", "name": "North Reading" },
      { "@type": "City", "name": "Stoneham" },
      { "@type": "City", "name": "Melrose" },
      { "@type": "City", "name": "Sharon" },
      { "@type": "City", "name": "Needham Heights" },
      { "@type": "City", "name": "Chestnut Hill" }
    ],
    "servesCuisine": [],
    "openingHoursSpecification": []
  }
  </script>
</head>
<body>

  <?php if ($welcome): ?>
    <div class="welcome-banner">
      Welcome, <?= $userName ?>!
      Your client account has been created successfully.
    </div>
  <?php endif; ?>

  <header id="rb-static-header" class="rbf1-header">
    <div class="rbf1-topbar">
      <a href="/" class="rbf1-logo">
        <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
      </a>

      <nav class="rbf1-nav" id="rbf1-nav">
        <ul>
          <li><a href="/dashboards/dashboard_client.php" class="rbf1-link rbf1-link-active">Dashboard</a></li>
          <li><a href="/dashboards/client_profile.php">Profile</a></li>
          <li><a href="/dashboards/client_goals.php">Goals</a></li>
          <li><a href="/dashboards/messages.php">Messages</a></li>
          <li><a href="/dashboards/client_workouts.php">Workout</a></li>
          <li><a href="/dashboards/client_nutrition.php">Nutritionist</a></li>
          <li><a href="/dashboards/progress_gallery.php">Photos Gallery</a></li>

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

 <main class="client-dashboard">
  <div class="client-shell">

    <!-- HERO: AVATAR + NOME + STATUS -->
    <section class="client-hero">
      <div class="client-hero-left">

        <div class="client-avatar-wrapper">
          <div class="client-avatar">
            <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= $userName; ?>">
          </div>
        </div>

        <div class="client-hero-text">
          <p class="client-eyebrow">Client Dashboard</p>
          <h1 class="client-title">Hi, <?= $userName; ?>.</h1>
          <p class="client-subtitle">
            This is your private space to follow your training, nutrition and progress with your coach.
          </p>
        </div>

      </div>

      <div class="client-hero-right">
        <div class="client-pill client-pill--muted">
          Program <span>Not set yet</span>
        </div>

        <div class="client-pill client-pill--status">
          Status
          <span>
            <?php if ($coachName): ?>
              Connected to coach
            <?php else: ?>
              Waiting for coach
            <?php endif; ?>
          </span>
        </div>

        <div class="client-pill client-pill--muted">
          Coach
          <span>
            <?php if ($coachName): ?>
              <?= htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8'); ?>
            <?php else: ?>
              Not connected yet
            <?php endif; ?>
          </span>
        </div>
      </div>
    </section>

    <!-- PAINÉIS PRINCIPAIS -->
    <section class="client-main-grid">

      <!-- PAINEL 1: HOJE E TREINOS (somente texto) -->
      <article class="client-panel client-panel--today">
        <header class="client-panel-header">
          <div>
            <p class="client-panel-eyebrow">Today & Training</p>
            <h2 class="client-panel-title">Your plan</h2>
          </div>
          <a href="/dashboards/client_workouts.php" class="client-panel-link">Go to workouts →</a>
        </header>

        <div class="client-panel-body">
          <p class="client-panel-label">Today</p>
          <p class="client-panel-main">
            No workouts scheduled yet.
          </p>
          <p class="client-panel-text">
            As soon as your personal trainer assigns your sessions, your workout for today and for the full week will appear here for you to follow and mark as done.
          </p>
        </div>
      </article>

      <!-- PAINEL 2: VISÃO GERAL / MÉTRICAS DA SEMANA -->
      <article class="client-panel client-panel--overview">
        <header class="client-panel-header">
          <div>
            <p class="client-panel-eyebrow">Overview</p>
            <h2 class="client-panel-title">This week at a glance</h2>
          </div>
        </header>

        <div class="client-panel-body">
          <div class="client-mini-grid">
            <div class="client-mini-box">
              <p class="client-mini-label">Workouts this week</p>
              <p class="client-mini-value">—</p>
            </div>
            <div class="client-mini-box">
              <p class="client-mini-label">Last check-in</p>
              <p class="client-mini-value">—</p>
            </div>
            <div class="client-mini-box">
              <p class="client-mini-label">Next check-in</p>
              <p class="client-mini-value">—</p>
            </div>
            <div class="client-mini-box">
              <p class="client-mini-label">Last photo upload</p>
              <p class="client-mini-value">—</p>
            </div>
          </div>
        </div>
      </article>

      <!-- PAINEL 3: PROGRESSO & METAS -->
      <article class="client-panel client-panel--progress">
        <header class="client-panel-header">
          <div>
            <p class="client-panel-eyebrow">Progress</p>
            <h2 class="client-panel-title">Goals & tracking</h2>
          </div>
          <a href="/dashboards/client_goals.php" class="client-panel-link">Create or view goals →</a>
        </header>

        <div class="client-panel-body">

          <?php if (!$lastGoal): ?>

            <p class="client-panel-text">
              You don’t have any goals registered yet.
            </p>

          <?php else: ?>

            <p class="client-panel-label">Latest goal</p>

            <h3 class="dashboard-last-goal">
              <?= htmlspecialchars($lastGoal, ENT_QUOTES, 'UTF-8'); ?>
            </h3>

            <a href="/dashboards/client_goals.php" class="dashboard-view-more">
              View more →
            </a>

            <br><br>

            <p class="client-panel-label">Last photo upload</p>

            <?php if (!$lastPhoto): ?>

              <p class="client-panel-text" style="color:#888">
                Not uploaded yet
              </p>

            <?php else: ?>

              <p class="client-panel-text">
                File:
                <a href="<?= htmlspecialchars($lastPhoto['file_path'], ENT_QUOTES, 'UTF-8'); ?>"
                   class="last-photo-link"
                   target="_blank"
                   rel="noopener noreferrer">
                  <?= htmlspecialchars(basename($lastPhoto['file_path']), ENT_QUOTES, 'UTF-8'); ?>

                  <span class="last-photo-preview">
                    <img src="<?= htmlspecialchars($lastPhoto['file_path'], ENT_QUOTES, 'UTF-8'); ?>"
                         alt="Last progress photo">
                  </span>
                </a>
              </p>

            <?php endif; ?>

          <?php endif; ?>

        </div>

        <footer class="client-panel-footer">
          <a href="/dashboards/progress_gallery.php" class="client-panel-link subtle">Open progress photos →</a>
        </footer>
      </article>

      <!-- PAINEL 4: MENSAGENS & CHECK-INS -->
      <article class="client-panel client-panel--communication">
        <header class="client-panel-header">
          <div>
            <p class="client-panel-eyebrow">Communication</p>
            <h2 class="client-panel-title">Messages & check-ins</h2>
          </div>
          <a href="/dashboards/messages.php" class="client-panel-link">Open messages →</a>
        </header>

        <div class="client-panel-body">
          <p class="client-panel-text">
            You don’t have any messages yet.
          </p>
          <p class="client-panel-text">
            When your coach or nutritionist sends you a message, it will appear here so you can keep all your communication in one place.
          </p>

          <div class="client-inline-box">
            <div>
              <p class="client-mini-label">Weekly check-in</p>
              <p class="client-mini-value">Not submitted yet</p>
            </div>
            <a href="/dashboards/client_checkin.php" class="client-panel-link subtle">
              Do this week’s check-in →
            </a>
          </div>
        </div>
      </article>

    </section>

    <div class="section-divider" role="separator" aria-hidden="true"></div>

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
      <p class="footer-bottom-text">
        © 2025 RB Personal Trainer. All rights reserved.
      </p>
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
