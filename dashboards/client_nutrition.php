<?php
// /dashboards/client_nutrition.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client', 'pro', 'admin']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role            = (string)($_SESSION['role'] ?? '');

$isPro   = ($role === 'pro');
$isAdmin = ($role === 'admin');

/**
 * CSRF token (mantém padrão do projeto)
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
 * Load logged-in user data (name/avatar)
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

$display_name = (string)($logged_user['name'] ?? '');
$avatar_alt   = $display_name !== '' ? ('Profile photo of ' . $display_name) : 'Client profile photo';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Nutrition | RB Personal Trainer | Rafa Breder Coaching</title>
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
  <link rel="stylesheet" href="/assets/css/dashboard_client.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">

  <style>
    /* =========================================================
       RB - Under Construction Page (Tetris-ish build animation)
       Uses RB colors vibe: black/dark + orange accent
    ========================================================== */

    :root{
      --rb-bg: #0b0b0d;
      --rb-card: rgba(255,255,255,.06);
      --rb-card-2: rgba(255,255,255,.10);
      --rb-border: rgba(255,255,255,.12);
      --rb-text: rgba(255,255,255,.92);
      --rb-muted: rgba(255,255,255,.70);
      --rb-orange: #FF7A00;
      --rb-orange-2: #ff9a3d;
      --rb-shadow: 0 24px 80px rgba(0,0,0,.55);
    }

    .rb-uc-wrap{
      width: min(1100px, 94vw);
      margin: 0 auto;
      padding: 20px 0 28px 0;
    }

    .rb-uc-hero{
      border: 1px solid var(--rb-border);
      background: linear-gradient(180deg, rgba(255,122,0,.10), rgba(255,255,255,.04));
      border-radius: 18px;
      box-shadow: var(--rb-shadow);
      padding: 18px;
      overflow: hidden;
      position: relative;
    }

    .rb-uc-top{
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 14px;
    }

    .rb-uc-user{
      display:flex;
      align-items:center;
      gap: 12px;
      min-width: 0;
    }

    .rb-uc-avatar{
      width: 44px;
      height: 44px;
      border-radius: 999px;
      object-fit: cover;
      border: 1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.06);
      flex: 0 0 auto;
    }

    .rb-uc-user-meta{
      min-width:0;
    }

    .rb-uc-name{
      color: var(--rb-text);
      font-weight: 800;
      font-size: 16px;
      line-height: 1.1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-bottom: 2px;
    }

    .rb-uc-sub{
      color: var(--rb-muted);
      font-size: 13px;
      line-height: 1.25;
    }

    .rb-uc-actions{
      display:flex;
      align-items:center;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .rb-uc-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap: 8px;
      height: 40px;
      padding: 0 14px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.06);
      color: var(--rb-text);
      text-decoration: none;
      font-weight: 700;
      font-size: 14px;
      transition: transform .12s ease, background .12s ease, border-color .12s ease;
      user-select: none;
      cursor: pointer;
      white-space: nowrap;
    }
    .rb-uc-btn:hover{
      transform: translateY(-1px);
      background: rgba(255,255,255,.10);
      border-color: rgba(255,255,255,.22);
    }

    .rb-uc-btn-primary{
      background: linear-gradient(180deg, rgba(255,122,0,.95), rgba(255,122,0,.78));
      border-color: rgba(255,122,0,.55);
      color: #111;
    }
    .rb-uc-btn-primary:hover{
      background: linear-gradient(180deg, rgba(255,154,61,.98), rgba(255,122,0,.85));
      border-color: rgba(255,154,61,.65);
    }

    .rb-uc-grid{
      display:grid;
      grid-template-columns: 1.2fr .8fr;
      gap: 16px;
    }

    @media (max-width: 980px){
      .rb-uc-grid{ grid-template-columns: 1fr; }
      .rb-uc-actions{ width:100%; justify-content:flex-start; }
      .rb-uc-top{ flex-wrap: wrap; }
    }

    .rb-uc-card{
      border: 1px solid var(--rb-border);
      background: rgba(0,0,0,.25);
      border-radius: 16px;
      padding: 16px;
      overflow: hidden;
      position: relative;
    }

    .rb-uc-title{
      display:flex;
      align-items:center;
      gap: 10px;
      margin-bottom: 10px;
    }

    .rb-uc-badge{
      display:inline-flex;
      align-items:center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid rgba(255,122,0,.35);
      background: rgba(255,122,0,.12);
      color: rgba(255,255,255,.92);
      font-weight: 800;
      font-size: 12px;
      letter-spacing: .4px;
      text-transform: uppercase;
    }

    .rb-uc-h1{
      margin: 0;
      color: var(--rb-text);
      font-weight: 900;
      font-size: 22px;
      line-height: 1.2;
    }

    .rb-uc-p{
      margin: 10px 0 0 0;
      color: var(--rb-muted);
      font-size: 14px;
      line-height: 1.55;
      max-width: 62ch;
    }

    .rb-uc-list{
      margin: 12px 0 0 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: 10px;
    }

    .rb-uc-li{
      display:flex;
      align-items:flex-start;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.04);
      color: rgba(255,255,255,.84);
      font-size: 14px;
      line-height: 1.35;
    }

    .rb-uc-dot{
      width: 10px;
      height: 10px;
      border-radius: 999px;
      margin-top: 4px;
      background: var(--rb-orange);
      box-shadow: 0 0 0 4px rgba(255,122,0,.14);
      flex: 0 0 auto;
    }

    /* ======== Tetris-ish animation panel ======== */

    .rb-tetris{
      border: 1px solid rgba(255,255,255,.12);
      background: radial-gradient(1200px 600px at 20% 10%, rgba(255,122,0,.12), rgba(0,0,0,.35));
      border-radius: 16px;
      overflow: hidden;
      position: relative;
      min-height: 330px;
      box-shadow: inset 0 0 0 1px rgba(0,0,0,.22);
    }

    .rb-tetris-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 10px;
      padding: 12px 12px 0 12px;
    }

    .rb-tetris-title{
      color: rgba(255,255,255,.88);
      font-weight: 900;
      font-size: 14px;
      letter-spacing: .4px;
      text-transform: uppercase;
    }

    .rb-tetris-status{
      display:inline-flex;
      align-items:center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.86);
      font-weight: 800;
      font-size: 12px;
      white-space: nowrap;
    }

    .rb-pulse{
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: var(--rb-orange);
      box-shadow: 0 0 0 0 rgba(255,122,0,.35);
      animation: rbPulse 1.25s ease-in-out infinite;
    }

    @keyframes rbPulse{
      0%{ box-shadow: 0 0 0 0 rgba(255,122,0,.30); }
      50%{ box-shadow: 0 0 0 10px rgba(255,122,0,.08); }
      100%{ box-shadow: 0 0 0 0 rgba(255,122,0,.00); }
    }

    .rb-tetris-board{
      position: relative;
      width: 100%;
      height: 285px;
      margin-top: 10px;
      padding: 10px 12px 12px 12px;
    }

    .rb-tetris-grid{
      position:absolute;
      inset: 10px 12px 12px 12px;
      border-radius: 14px;
      background:
        linear-gradient(rgba(255,255,255,.06) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.06) 1px, transparent 1px);
      background-size: 22px 22px;
      background-position: 0 0;
      opacity: .65;
      pointer-events:none;
    }

    .rb-piece{
      position:absolute;
      width: 22px;
      height: 22px;
      border-radius: 6px;
      border: 1px solid rgba(255,255,255,.16);
      box-shadow:
        inset 0 0 0 1px rgba(0,0,0,.20),
        0 10px 24px rgba(0,0,0,.35);
      background: rgba(255,255,255,.12);
      transform: translate3d(0,0,0);
      opacity: 0;
      animation-duration: 5.8s;
      animation-timing-function: linear;
      animation-iteration-count: infinite;
    }

    .rb-piece::after{
      content:"";
      position:absolute;
      inset: 3px;
      border-radius: 5px;
      background: rgba(255,255,255,.08);
    }

    /* Orange variants to match brand */
    .rb-piece.o1{ background: rgba(255,122,0,.85); border-color: rgba(255,154,61,.45); }
    .rb-piece.o2{ background: rgba(255,154,61,.78); border-color: rgba(255,154,61,.35); }
    .rb-piece.o3{ background: rgba(255,122,0,.62); border-color: rgba(255,122,0,.35); }
    .rb-piece.g1{ background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.18); }
    .rb-piece.g2{ background: rgba(255,255,255,.16); border-color: rgba(255,255,255,.14); }

    /* Path: fall + lock into place (fake tetris) */
    @keyframes rbFall1{
      0%   { transform: translate(40px, -40px); opacity: 0; }
      8%   { opacity: 1; }
      55%  { transform: translate(40px, 140px); opacity: 1; }
      62%  { transform: translate(40px, 140px); opacity: 1; }
      76%  { opacity: 1; }
      88%  { opacity: 0; }
      100% { opacity: 0; }
    }
    @keyframes rbFall2{
      0%   { transform: translate(120px, -60px); opacity: 0; }
      10%  { opacity: 1; }
      52%  { transform: translate(120px, 165px); opacity: 1; }
      60%  { transform: translate(120px, 165px); opacity: 1; }
      78%  { opacity: 1; }
      90%  { opacity: 0; }
      100% { opacity: 0; }
    }
    @keyframes rbFall3{
      0%   { transform: translate(210px, -50px); opacity: 0; }
      10%  { opacity: 1; }
      50%  { transform: translate(210px, 118px); opacity: 1; }
      58%  { transform: translate(210px, 118px); opacity: 1; }
      78%  { opacity: 1; }
      90%  { opacity: 0; }
      100% { opacity: 0; }
    }
    @keyframes rbFall4{
      0%   { transform: translate(300px, -60px); opacity: 0; }
      12%  { opacity: 1; }
      48%  { transform: translate(300px, 188px); opacity: 1; }
      58%  { transform: translate(300px, 188px); opacity: 1; }
      78%  { opacity: 1; }
      90%  { opacity: 0; }
      100% { opacity: 0; }
    }
    @keyframes rbFall5{
      0%   { transform: translate(70px, -70px); opacity: 0; }
      12%  { opacity: 1; }
      44%  { transform: translate(70px, 205px); opacity: 1; }
      56%  { transform: translate(70px, 205px); opacity: 1; }
      78%  { opacity: 1; }
      90%  { opacity: 0; }
      100% { opacity: 0; }
    }
    @keyframes rbFall6{
      0%   { transform: translate(260px, -80px); opacity: 0; }
      10%  { opacity: 1; }
      46%  { transform: translate(260px, 230px); opacity: 1; }
      56%  { transform: translate(260px, 230px); opacity: 1; }
      78%  { opacity: 1; }
      90%  { opacity: 0; }
      100% { opacity: 0; }
    }

    .rb-piece.p1{ animation-name: rbFall1; }
    .rb-piece.p2{ animation-name: rbFall2; animation-delay: .15s; }
    .rb-piece.p3{ animation-name: rbFall3; animation-delay: .30s; }
    .rb-piece.p4{ animation-name: rbFall4; animation-delay: .45s; }
    .rb-piece.p5{ animation-name: rbFall5; animation-delay: .60s; }
    .rb-piece.p6{ animation-name: rbFall6; animation-delay: .75s; }

    /* Build text shimmer */
    .rb-shimmer{
      position: relative;
      display: inline-block;
      font-weight: 900;
      background: linear-gradient(90deg, rgba(255,255,255,.55), rgba(255,122,0,.95), rgba(255,255,255,.55));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      background-size: 240% 100%;
      animation: rbShimmer 2.2s linear infinite;
    }
    @keyframes rbShimmer{
      0%{ background-position: 0% 50%; }
      100%{ background-position: 240% 50%; }
    }

    /* Small note footer inside hero */
    .rb-uc-note{
      margin-top: 12px;
      color: rgba(255,255,255,.72);
      font-size: 13px;
      line-height: 1.45;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.04);
    }

    /* Respect reduced motion */
    @media (prefers-reduced-motion: reduce){
      .rb-piece, .rb-pulse, .rb-shimmer{ animation: none !important; }
    }
  </style>
</head>
<body>

<!-- ===================================================== -->
<!-- HEADER DO CLIENTE (layout padrão dashboard_client.php) -->
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
        <li><a href="client_nutrition.php" class="rbf1-link rbf1-link-active">Nutritionist</a></li>
        <li><a href="progress_gallery.php">Photos Gallery</a></li>

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

<div class="rb-uc-wrap">
  <div class="rb-uc-hero">

    <div class="rb-uc-top">
      <div class="rb-uc-user">
        <?php if (!empty($logged_user['avatar_url'])): ?>
          <img class="rb-uc-avatar" src="<?php echo e((string)$logged_user['avatar_url']); ?>" alt="<?php echo e($avatar_alt); ?>">
        <?php else: ?>
          <img class="rb-uc-avatar" src="/assets/img/default-avatar.png" alt="<?php echo e($avatar_alt); ?>">
        <?php endif; ?>

        <div class="rb-uc-user-meta">
          <div class="rb-uc-name"><?php echo e($display_name); ?></div>
          <div class="rb-uc-sub">Nutrition module</div>
        </div>
      </div>

      <div class="rb-uc-actions">
        <a class="rb-uc-btn" href="dashboard_client.php">← Back to Dashboard</a>
        <a class="rb-uc-btn rb-uc-btn-primary" href="client_workouts.php">Go to Workouts</a>
      </div>
    </div>

    <div class="rb-uc-grid">
      <div class="rb-uc-card">
        <div class="rb-uc-title">
          <span class="rb-uc-badge">Under construction</span>
          <h1 class="rb-uc-h1">
            Nutrition area is <span class="rb-shimmer">still in development</span>
          </h1>
        </div>

        <p class="rb-uc-p">
          We’re building this section right now. Soon you’ll be able to track meals, macros,
          nutrition plans and coach feedback — all inside your RB dashboard.
        </p>

        <ul class="rb-uc-list">
          <li class="rb-uc-li"><span class="rb-uc-dot"></span>Meal plan templates & custom nutrition plans</li>
          <li class="rb-uc-li"><span class="rb-uc-dot"></span>Macro tracking & daily targets</li>
          <li class="rb-uc-li"><span class="rb-uc-dot"></span>Coach review, comments and adjustments</li>
          <li class="rb-uc-li"><span class="rb-uc-dot"></span>Progress charts integrated with photos & check-ins</li>
        </ul>

        <div class="rb-uc-note">
          Tip: while this area is being built, you can keep uploading photos/videos and using check-ins normally.
        </div>
      </div>

      <div class="rb-tetris" aria-label="Page building animation">
        <div class="rb-tetris-header">
          <div class="rb-tetris-title">Building page</div>
          <div class="rb-tetris-status"><span class="rb-pulse"></span>In progress</div>
        </div>

        <div class="rb-tetris-board">
          <div class="rb-tetris-grid"></div>

          <!-- “Pieces” falling (fake tetris vibe) -->
          <div class="rb-piece p1 o1" style="left: 30px; top: 0;"></div>
          <div class="rb-piece p2 g1" style="left: 110px; top: 0;"></div>
          <div class="rb-piece p3 o2" style="left: 200px; top: 0;"></div>
          <div class="rb-piece p4 g2" style="left: 290px; top: 0;"></div>
          <div class="rb-piece p5 o3" style="left: 60px; top: 0;"></div>
          <div class="rb-piece p6 o1" style="left: 250px; top: 0;"></div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ===================================================== -->
<!-- FOOTER DO CLIENTE (padrão dashboard_client.php)       -->
<!-- ===================================================== -->
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

</body>
</html>
