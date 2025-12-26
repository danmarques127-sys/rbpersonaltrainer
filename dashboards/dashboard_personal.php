<?php
// dashboards/dashboard_personal.php
declare(strict_types=1);

// ======================================
// BOOTSTRAP CENTRAL (session + auth + PDO)
// ======================================
require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']); // somente PRO

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

// ===============================
// HELPERS (safe query wrappers)
// ===============================
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Execute a query that returns a single scalar (COUNT, etc).
 * If query/table/column doesn't exist, returns $default.
 */
function safe_scalar(PDO $pdo, string $sql, array $params = [], int $default = 0): int {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $val = $st->fetchColumn();
        if ($val === false || $val === null) return $default;
        return (int)$val;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Execute a query that returns rows (list).
 * If query/table/column doesn't exist, returns [].
 */
function safe_rows(PDO $pdo, string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll($fetchMode);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

// ===============================
// DADOS DO USUÁRIO (com fallback)
// ===============================
$specialty   = null;
$currentUser = [];

try {
    // tenta pegar specialty (se a coluna existir)
    $stmt = $pdo->prepare("SELECT specialty FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$current_user_id]);
    $specialty = $stmt->fetchColumn();
} catch (Throwable $e) {
    $specialty = null;
}

try {
    // tenta pegar name e avatar_url (se avatar_url existir)
    $stmt = $pdo->prepare("SELECT name, avatar_url FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$current_user_id]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // fallback: pega só o nome
    try {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$current_user_id]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        $currentUser = [];
    }
}

// Se specialty existir e não for personal_trainer, bloqueia.
// Se specialty NÃO existir (null), deixa passar por enquanto pra não travar ambiente.
if ($specialty !== null && (string)$specialty !== 'personal_trainer') {
    header('Location: /login.php');
    exit;
}

$coachNameRaw = (string)($currentUser['name'] ?? ($_SESSION['user_name'] ?? 'Coach'));

// padrão de avatar no teu assets
$avatarUrlRaw = (string)($currentUser['avatar_url'] ?? '/assets/images/default-avatar.png');

// ===============================
// VALORES DO DASHBOARD (AGORA BUSCA DO BANCO)
// ===============================

// banner de boas-vindas (por enquanto desativado)
$welcome = false;

// ------------------------------------------------------
// 1) CLIENTES LINKADOS AO COACH
//   Tenta ler de coach_clients (sua tabela existe no print)
// ------------------------------------------------------
$activeClientsCount = safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM coach_clients WHERE coach_id = ?",
    [$current_user_id],
    0
);

// Para usar em outros blocos: lista de client_ids
$clientIds = [];
$clientIdRows = safe_rows(
    $pdo,
    "SELECT client_id FROM coach_clients WHERE coach_id = ?",
    [$current_user_id]
);
foreach ($clientIdRows as $r) {
    if (isset($r['client_id'])) $clientIds[] = (int)$r['client_id'];
}
$clientIds = array_values(array_unique(array_filter($clientIds, fn($x) => $x > 0)));

// Helper: placeholders IN (...)
$inPlaceholders = '';
$inParams = [];
if (!empty($clientIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($clientIds), '?'));
    $inParams = $clientIds;
}

// ------------------------------------------------------
// 2) UNREAD MESSAGES (coach inbox) - usa mail_recipients/mail_messages
// ------------------------------------------------------
$unreadMessagesCount = safe_scalar(
    $pdo,
    "
    SELECT COUNT(*) AS cnt
    FROM mail_recipients r
    INNER JOIN mail_messages m ON m.id = r.message_id
    WHERE r.recipient_id = ?
      AND r.folder = 'inbox'
      AND r.is_read = 0
      AND (m.thread_id IS NULL OR m.id = m.thread_id)
    ",
    [$current_user_id],
    0
);

// ------------------------------------------------------
// 3) PLANS TO UPDATE
//   Como você ainda não implementou regra 100%,
//   eu busco 2 jeitos (com fallback):
//   A) workout_plans com end_date < hoje (para clientes do coach)
//   B) workout_plans com updated_at antigo (>30 dias)
// ------------------------------------------------------
$plansToUpdateCount = 0;

if (!empty($clientIds)) {
    // A) end_date vencido
    $plansToUpdateCount = safe_scalar(
        $pdo,
        "
        SELECT COUNT(DISTINCT p.client_id)
        FROM workout_plans p
        WHERE p.client_id IN ($inPlaceholders)
          AND p.end_date IS NOT NULL
          AND DATE(p.end_date) < CURDATE()
        ",
        $inParams,
        0
    );

    // B) se A deu 0 e existir updated_at (ou created_at), tenta regra de “antigo”
    if ($plansToUpdateCount === 0) {
        $plansToUpdateCount = safe_scalar(
            $pdo,
            "
            SELECT COUNT(DISTINCT p.client_id)
            FROM workout_plans p
            WHERE p.client_id IN ($inPlaceholders)
              AND (
                   (p.updated_at IS NOT NULL AND p.updated_at < (NOW() - INTERVAL 30 DAY))
                OR (p.updated_at IS NULL AND p.created_at IS NOT NULL AND p.created_at < (NOW() - INTERVAL 30 DAY))
              )
            ",
            $inParams,
            0
        );
    }
}

// ------------------------------------------------------
// 4) TODAY / PRIORITIES
//   A) todayWorkoutsCount: tenta workout_sessions (scheduled_at/session_date)
//   B) todayCheckinsToReview / pendingCheckinsCount: tenta trainer_checkins se existir
//   C) todayMessagesToReply: usa unreadMessagesCount como base
// ------------------------------------------------------
$todayWorkoutsCount = 0;

if (!empty($clientIds)) {
    // Tentativa 1: workout_sessions.scheduled_at
    $todayWorkoutsCount = safe_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM workout_sessions s
        WHERE s.client_id IN ($inPlaceholders)
          AND s.scheduled_at IS NOT NULL
          AND DATE(s.scheduled_at) = CURDATE()
        ",
        $inParams,
        0
    );

    // Tentativa 2 (fallback): workout_sessions.session_date
    if ($todayWorkoutsCount === 0) {
        $todayWorkoutsCount = safe_scalar(
            $pdo,
            "
            SELECT COUNT(*)
            FROM workout_sessions s
            WHERE s.client_id IN ($inPlaceholders)
              AND s.session_date IS NOT NULL
              AND DATE(s.session_date) = CURDATE()
            ",
            $inParams,
            0
        );
    }
}

$todayCheckinsToReview = 0;
$pendingCheckinsCount  = 0;

// Tentativa: trainer_checkins (status pending)
if (!empty($clientIds)) {
    $todayCheckinsToReview = safe_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM trainer_checkins c
        WHERE c.client_id IN ($inPlaceholders)
          AND c.status = 'pending'
          AND c.created_at IS NOT NULL
          AND DATE(c.created_at) = CURDATE()
        ",
        $inParams,
        0
    );

    $pendingCheckinsCount = safe_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM trainer_checkins c
        WHERE c.client_id IN ($inPlaceholders)
          AND c.status = 'pending'
        ",
        $inParams,
        0
    );
}

$todayMessagesToReply = $unreadMessagesCount;

// ------------------------------------------------------
// 5) LISTAS
//   A) clientsDuePlans (usa workout_plans end_date vencido)
//   B) recentClients (últimos clientes linkados)
//   C) recentMessages (últimas conversas / inbox)
// ------------------------------------------------------
$clientsDuePlans = [];
if (!empty($clientIds)) {
    $clientsDuePlans = safe_rows(
        $pdo,
        "
        SELECT
            u.id,
            u.name,
            COALESCE(p.plan_type, 'standard') AS plan_type,
            COALESCE(DATEDIFF(CURDATE(), DATE(p.end_date)), 0) AS days_overdue
        FROM coach_clients cc
        INNER JOIN users u ON u.id = cc.client_id
        LEFT JOIN (
            SELECT p1.*
            FROM workout_plans p1
            INNER JOIN (
                SELECT client_id, MAX(COALESCE(updated_at, created_at, end_date)) AS mx
                FROM workout_plans
                GROUP BY client_id
            ) lastp ON lastp.client_id = p1.client_id
            AND COALESCE(p1.updated_at, p1.created_at, p1.end_date) = lastp.mx
        ) p ON p.client_id = cc.client_id
        WHERE cc.coach_id = ?
          AND p.end_date IS NOT NULL
          AND DATE(p.end_date) < CURDATE()
        ORDER BY p.end_date ASC
        LIMIT 6
        ",
        [$current_user_id]
    );
}

$recentClients = [];
if (!empty($clientIds)) {
    $recentClients = safe_rows(
        $pdo,
        "
        SELECT
            u.id,
            u.name,
            lw.last_workout,
            lc.last_checkin
        FROM coach_clients cc
        INNER JOIN users u ON u.id = cc.client_id
        LEFT JOIN (
            SELECT client_id, MAX(created_at) AS last_workout
            FROM workout_logs
            GROUP BY client_id
        ) lw ON lw.client_id = cc.client_id
        LEFT JOIN (
            SELECT client_id, MAX(created_at) AS last_checkin
            FROM trainer_checkins
            GROUP BY client_id
        ) lc ON lc.client_id = cc.client_id
        WHERE cc.coach_id = ?
        ORDER BY cc.id DESC
        LIMIT 6
        ",
        [$current_user_id]
    );

    // Normaliza para string curta no layout (o HTML usa "—")
    foreach ($recentClients as &$c) {
        $c['last_workout'] = !empty($c['last_workout']) ? date('m/d/Y', strtotime((string)$c['last_workout'])) : '—';
        $c['last_checkin'] = !empty($c['last_checkin']) ? date('m/d/Y', strtotime((string)$c['last_checkin'])) : '—';
    }
    unset($c);
}

$recentMessages = safe_rows(
    $pdo,
    "
    SELECT
        m.id AS message_id,
        m.sender_id,
        u.name AS client_name,
        SUBSTRING(m.body, 1, 70) AS preview,
        m.created_at
    FROM mail_recipients r
    INNER JOIN mail_messages m ON m.id = r.message_id
    LEFT JOIN users u ON u.id = m.sender_id
    WHERE r.recipient_id = ?
      AND r.folder = 'inbox'
      AND (m.thread_id IS NULL OR m.id = m.thread_id)
    ORDER BY m.created_at DESC
    LIMIT 5
    ",
    [$current_user_id]
);

// Ajusta "time_ago" simples
foreach ($recentMessages as &$msg) {
    $created = !empty($msg['created_at']) ? strtotime((string)$msg['created_at']) : 0;
    $diff = $created > 0 ? (time() - $created) : 0;
    if ($diff < 60) $msg['time_ago'] = 'now';
    elseif ($diff < 3600) $msg['time_ago'] = (int)floor($diff / 60) . 'm';
    elseif ($diff < 86400) $msg['time_ago'] = (int)floor($diff / 3600) . 'h';
    else $msg['time_ago'] = (int)floor($diff / 86400) . 'd';
}
unset($msg);

// ------------------------------------------------------
// 6) GOALS SUMMARY (client_goals + client_goal_progress)
// ------------------------------------------------------
$totalActiveGoals      = 0;
$goalsExpiringSoon     = 0;
$clientsWithoutUpdates = 0;

if (!empty($clientIds)) {
    // Active goals = status != completed
    $totalActiveGoals = safe_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM client_goals g
        WHERE g.client_id IN ($inPlaceholders)
          AND (g.status IS NULL OR g.status <> 'completed')
        ",
        $inParams,
        0
    );

    // Ending soon (end_date em 7 dias) - se existir
    $goalsExpiringSoon = safe_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM client_goals g
        WHERE g.client_id IN ($inPlaceholders)
          AND g.end_date IS NOT NULL
          AND DATE(g.end_date) BETWEEN CURDATE() AND (CURDATE() + INTERVAL 7 DAY)
          AND (g.status IS NULL OR g.status <> 'completed')
        ",
        $inParams,
        0
    );

    // Clients without updates (últimos 7 dias sem progress no client_goal_progress)
    $clientsWithoutUpdates = safe_scalar(
        $pdo,
        "
        SELECT COUNT(DISTINCT g.client_id)
        FROM client_goals g
        LEFT JOIN (
            SELECT gp.goal_id, MAX(gp.created_at) AS last_progress
            FROM client_goal_progress gp
            GROUP BY gp.goal_id
        ) x ON x.goal_id = g.id
        WHERE g.client_id IN ($inPlaceholders)
          AND (g.status IS NULL OR g.status <> 'completed')
          AND (x.last_progress IS NULL OR x.last_progress < (NOW() - INTERVAL 7 DAY))
        ",
        $inParams,
        0
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Coach Dashboard | RB Personal Trainer | Rafa Breder Coaching</title>
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
  <link rel="stylesheet" href="/assets/css/dashboard_personal.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">

  <!-- PRIVATE PAGE -->
  <meta name="robots" content="noindex, nofollow">

  <!-- SEO BASICS -->
  <meta name="description" content="Coach dashboard for RB Personal Trainer online coaching. Manage your clients, training plans, check-ins and messages in one place." />
  <meta name="author" content="Rafaella Breder" />
  <meta name="theme-color" content="#FF7A00" />

  <!-- CANONICAL -->
  <link rel="canonical" href="https://www.rbpersonaltrainer.com/" />

  <!-- OPEN GRAPH / FACEBOOK -->
  <meta property="og:type" content="website" />
  <meta property="og:title" content="RB Personal Trainer | Online Coaching with Rafa Breder" />
  <meta property="og:description" content="Transform your clients' training and lifestyle with RB Personal Trainer. Online coaching, custom workout plans and ongoing support, based in the Boston area and serving clients across the United States." />
  <meta property="og:url" content="https://www.rbpersonaltrainer.com/" />
  <meta property="og:site_name" content="RB Personal Trainer" />
  <meta property="og:locale" content="en_US" />
  <meta property="og:image" content="https://www.rbpersonaltrainer.com/images/og-rb-personal-trainer.jpg" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />

  <!-- TWITTER CARDS -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="RB Personal Trainer | Online Personal Training with Rafa Breder" />
  <meta name="twitter:description" content="Online coaching, customized training plans and ongoing support with RB Personal Trainer. Boston-based, serving high-end clients online across the United States." />
  <meta name="twitter:image" content="https://www.rbpersonaltrainer.com/images/og-rb-personal-trainer.jpg" />
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
    "image": "https://www.rbpersonaltrainer.com/images/rafa-breder-profile.jpg",
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
      {
        "@type": "Country",
        "name": "United States"
      }
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
    "image": "https://www.rbpersonaltrainer.com/images/rafa-breder-profile.jpg",
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

  <style>
  /* ===== Portfolio thumbs (não deforma card) ===== */
  .rb-portfolio-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(90px,1fr));
    gap:12px;
    margin-top:12px;
  }

  .rb-portfolio-btn{
    padding:0;
    border:0;
    background:transparent;
    cursor:pointer;
    width:100%;
    text-align:left;
  }

  .rb-portfolio-thumb{
    width:100%;
    aspect-ratio:1 / 1;
    border-radius:14px;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.10);
    background:rgba(255,255,255,.03);
  }

  .rb-portfolio-thumb img{
    width:100%;
    height:100%;
    display:block;
    object-fit:cover;          /* NÃO estica */
    object-position:center;    /* centraliza */
    transform:translateZ(0);
    transition:transform .18s ease, filter .18s ease;
  }

  .rb-portfolio-btn:hover .rb-portfolio-thumb img{
    transform:scale(1.03);
    filter:brightness(1.05);
  }

  /* ===== Lightbox ===== */
  .rb-lightbox{
    position:fixed;
    inset:0;
    display:none;
    align-items:center;
    justify-content:center;
    padding:24px;
    background:rgba(0,0,0,.78);
    z-index:9999;
  }
  .rb-lightbox.is-open{ display:flex; }

  .rb-lightbox-inner{
    max-width:min(1100px, 95vw);
    max-height:92vh;
    width:auto;
    border-radius:18px;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.12);
    background:rgba(10,10,10,.85);
    box-shadow:0 20px 60px rgba(0,0,0,.55);
    position:relative;
  }

  .rb-lightbox-img{
    display:block;
    max-width:95vw;
    max-height:92vh;
    width:auto;
    height:auto;
    object-fit:contain;
    background:#0b0b0b;
  }

  .rb-lightbox-close{
    position:absolute;
    top:10px;
    right:10px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(0,0,0,.45);
    color:#fff;
    width:40px;
    height:40px;
    border-radius:999px;
    cursor:pointer;
    font-size:18px;
    line-height:40px;
    text-align:center;
  }

  body.rb-lock-scroll{ overflow:hidden; }
  </style>

</head>
<body>

  <?php if ($welcome): ?>
    <div class="welcome-banner">
      Welcome, <?= h($coachNameRaw); ?>!
      Your coach account has been created successfully.
    </div>
  <?php endif; ?>

  <header id="rb-static-header" class="rbf1-header">
    <div class="rbf1-topbar">
      <a href="/" class="rbf1-logo">
        <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
      </a>

      <nav class="rbf1-nav" id="rbf1-nav">
        <ul>
          <li><a href="/dashboards/dashboard_personal.php" class="rbf1-link rbf1-link-active">Dashboard</a></li>
          <li><a href="/dashboards/personal_profile.php">Profile</a></li>
          <li><a href="/dashboards/trainer_workouts.php">Workouts</a></li>
          <li><a href="/dashboards/trainer_checkins.php">Check-ins</a></li>
          <li><a href="/dashboards/messages.php">Messages</a></li>
          <li><a href="/dashboards/trainer_clients.php">Clients</a></li>

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

  <main class="coach-dashboard">
    <div class="coach-shell">

      <section class="coach-hero">
        <div class="coach-hero-left">

          <div class="coach-avatar-wrapper">
            <div class="coach-avatar">
              <img src="<?= h($avatarUrlRaw); ?>"
                   alt="<?= h($coachNameRaw); ?>">
            </div>
          </div>

          <div class="coach-hero-text">
            <p class="coach-eyebrow">Coach Dashboard</p>
            <h1 class="coach-title">Hi, <?= h($coachNameRaw); ?>.</h1>
            <p class="coach-subtitle">
              Manage your clients, training plans, check-ins and messages in one place.
            </p>
          </div>

        </div>

        <div class="coach-hero-right">
          <div class="coach-pill">
            Active clients <span><?= (int)$activeClientsCount; ?></span>
          </div>
          <div class="coach-pill coach-pill--alert">
            Plans to update <span><?= (int)$plansToUpdateCount; ?></span>
          </div>
          <div class="coach-pill">
            Unread messages <span><?= (int)$unreadMessagesCount; ?></span>
          </div>
        </div>
      </section>

      <section class="coach-main-grid">

        <article class="coach-panel coach-panel-wide">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Today</p>
              <h2 class="coach-panel-title">Your priorities for today</h2>
            </div>
            <a href="/dashboards/trainer_schedule.php" class="coach-panel-link">Open full schedule →</a>
          </header>

          <div class="coach-panel-body coach-panel-body--split">

            <div>
              <p class="coach-panel-label">Overview</p>
              <p class="coach-panel-main">
                <?= (int)$todayWorkoutsCount; ?> workouts •
                <?= (int)$todayCheckinsToReview; ?> check-ins to review •
                <?= (int)$todayMessagesToReply; ?> messages to reply
              </p>
            </div>

            <div class="coach-mini-grid">
              <div class="coach-mini-box">
                <p class="coach-mini-label">Workouts scheduled today</p>
                <p class="coach-mini-value"><?= (int)$todayWorkoutsCount; ?></p>
              </div>
              <div class="coach-mini-box">
                <p class="coach-mini-label">Check-ins to review</p>
                <p class="coach-mini-value"><?= (int)$todayCheckinsToReview; ?></p>
              </div>
              <div class="coach-mini-box">
                <p class="coach-mini-label">Messages to reply</p>
                <p class="coach-mini-value"><?= (int)$todayMessagesToReply; ?></p>
              </div>
            </div>

          </div>
        </article>

        <article class="coach-panel">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Plans</p>
              <h2 class="coach-panel-title">Clients needing a new plan</h2>
            </div>
            <a href="/dashboards/trainer_clients.php?filter=due-plans" class="coach-panel-link">View all →</a>
          </header>

          <div class="coach-panel-body">
            <?php if (empty($clientsDuePlans)): ?>
              <p class="coach-panel-text">
                No clients need a new plan right now.
              </p>
            <?php else: ?>
              <ul class="coach-list">
                <?php foreach ($clientsDuePlans as $client): ?>
                  <li class="coach-list-item">
                    <div>
                      <p class="coach-list-title"><?= h((string)$client['name']); ?></p>
                      <p class="coach-list-meta">
                        <?= h((string)$client['plan_type']); ?> plan •
                        expired <?= h((string)$client['days_overdue']); ?> days ago
                      </p>
                    </div>
                    <a href="/dashboards/trainer_workout_edit.php?client_id=<?= (int)$client['id']; ?>" class="coach-panel-link subtle">
                      Build / update plan →
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </article>

        <article class="coach-panel">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Clients</p>
              <h2 class="coach-panel-title">Your clients</h2>
            </div>
            <a href="/dashboards/trainer_clients.php" class="coach-panel-link">Open client list →</a>
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
                      <p class="coach-list-title"><?= h((string)$client['name']); ?></p>
                      <p class="coach-list-meta">
                        Last workout:
                        <?= h((string)($client['last_workout'] ?? '—')); ?> •
                        Last check-in:
                        <?= h((string)($client['last_checkin'] ?? '—')); ?>
                      </p>
                    </div>
                    <a href="/dashboards/trainer_client_profile.php?id=<?= (int)$client['id']; ?>" class="coach-panel-link subtle">
                      Open profile →
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </article>

        <article class="coach-panel">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Communication</p>
              <h2 class="coach-panel-title">Messages & check-ins</h2>
            </div>
            <a href="/dashboards/messages.php" class="coach-panel-link">Open inbox →</a>
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
                        <?= h((string)($message['client_name'] ?? 'Client')); ?>
                      </p>
                      <p class="coach-list-meta">
                        “<?= h((string)($message['preview'] ?? '')); ?>”
                        • <?= h((string)($message['time_ago'] ?? '')); ?>
                      </p>
                    </div>
                    <a href="/dashboards/messages.php" class="coach-panel-link subtle">
                      Reply →
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <div class="coach-inline-box">
              <div>
                <p class="coach-mini-label">Weekly check-ins</p>

                <?php if ($pendingCheckinsCount > 0): ?>
                  <p class="coach-mini-value">
                    <?= (int)$pendingCheckinsCount; ?> pending to review
                  </p>
                <?php else: ?>
                  <p class="coach-mini-value coach-mini-value--muted">
                    No check-ins pending this week
                  </p>
                <?php endif; ?>
              </div>

              <a href="/dashboards/trainer_checkins.php" class="coach-panel-link subtle">
                Review check-ins →
              </a>
            </div>
          </div>
        </article>

        <article class="coach-panel">
          <header class="coach-panel-header">
            <div>
              <p class="coach-panel-eyebrow">Client goals</p>
              <h2 class="coach-panel-title">Summary of client goals</h2>
            </div>
            <a href="/dashboards/trainer_goals.php" class="coach-panel-link">Open goals →</a>
          </header>

          <div class="coach-panel-body">
            <p>
              Track how your clients are progressing with their goals and quickly
              see where you need to step in with adjustments or check-ins.
            </p>

            <div class="coach-mini-grid">
              <div class="coach-mini-box">
                <p class="coach-mini-label">Active goals</p>
                <p class="coach-mini-value"><?= (int)$totalActiveGoals; ?></p>
              </div>
              <div class="coach-mini-box">
                <p class="coach-mini-label">Ending soon</p>
                <p class="coach-mini-value"><?= (int)$goalsExpiringSoon; ?></p>
              </div>
              <div class="coach-mini-box">
                <p class="coach-mini-label">Clients w/o updates</p>
                <p class="coach-mini-value"><?= (int)$clientsWithoutUpdates; ?></p>
              </div>
            </div>
          </div>
        </article>

      </section>

      <div class="section-divider" role="separator" aria-hidden="true"></div>

    </div>
  </main>

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
          <li><a href="/dashboards/dashboard_personal.php">Dashboard</a></li>
          <li><a href="/dashboards/personal_profile.php">Profile</a></li>
          <li><a href="/dashboards/trainer_clients.php">Clients</a></li>
          <li><a href="/dashboards/trainer_workouts.php">Workouts</a></li>
          <li><a href="/dashboards/trainer_checkins.php">Check-ins</a></li>
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

  <!-- LIGHTBOX HTML (pronto se você usar thumbs em qualquer card) -->
  <div id="rbLightbox" class="rb-lightbox" aria-hidden="true">
    <div class="rb-lightbox-inner" role="dialog" aria-modal="true">
      <button type="button" class="rb-lightbox-close" id="rbLightboxClose" aria-label="Close">✕</button>
      <img id="rbLightboxImg" class="rb-lightbox-img" src="" alt="Photo preview">
    </div>
  </div>

  <script src="/assets/js/script.js"></script>
  <script>
    (function () {
      // ===== Menu mobile =====
      const toggle = document.getElementById('rbf1-toggle');
      const nav = document.getElementById('rbf1-nav');

      if (toggle && nav) {
        toggle.addEventListener('click', function () {
          nav.classList.toggle('rbf1-open');
        });
      }

      // ===== Lightbox (se você tiver botões com data-rb-lightbox) =====
      const box = document.getElementById('rbLightbox');
      const img = document.getElementById('rbLightboxImg');
      const closeBtn = document.getElementById('rbLightboxClose');

      if (!box || !img || !closeBtn) return;

      function openLightbox(src) {
        if (!src) return;
        img.src = src;
        box.classList.add('is-open');
        document.body.classList.add('rb-lock-scroll');
        box.setAttribute('aria-hidden', 'false');
      }

      function closeLightbox() {
        box.classList.remove('is-open');
        document.body.classList.remove('rb-lock-scroll');
        box.setAttribute('aria-hidden', 'true');
        img.src = '';
      }

      document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-rb-lightbox]');
        if (!btn) return;

        const src = btn.getAttribute('data-full') || btn.querySelector('img')?.getAttribute('src');
        openLightbox(src);
      });

      closeBtn.addEventListener('click', closeLightbox);

      box.addEventListener('click', function (e) {
        if (e.target === box) closeLightbox();
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeLightbox();
      });
    })();
  </script>

</body>
</html>
