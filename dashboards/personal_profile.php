<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '1');

/**
 * RB Personal Trainer (Dashboards) — Standard bootstrap include
 * Files inside /dashboards/ must include only bootstrap.php
 */
require_once __DIR__ . '/../core/bootstrap.php';

/**
 * Security (minimum): require login + role gate before any output
 */
require_login();
require_role(['pro']);

$pdo    = getPDO();
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ../login.php');
    exit();
}

// Carrega usuário
$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Confere specialty para garantir que é personal trainer
$specialty = $userRow['specialty'] ?? null;
if ($specialty !== 'personal_trainer') {
    header('Location: dashboard_pro.php');
    exit();
}

// ===============================
// DADOS BÁSICOS DO COACH
// ===============================
$name      = $userRow['name']  ?? ($_SESSION['user_name'] ?? 'Coach');
$email     = $userRow['email'] ?? '';
$avatarUrl = !empty($userRow['avatar_url'])
    ? (string)$userRow['avatar_url']
    : 'images/default-avatar.png';

$phone       = $userRow['phone']      ?? '';
$timeZone    = $userRow['time_zone']  ?? '';   // aqui estamos usando como "cidade / região"
$gender      = $userRow['gender']     ?? '';
$dateOfBirth = $userRow['birthday']   ?? '';
$heightCm    = $userRow['height_cm']  ?? '';
$weightKg    = $userRow['weight_kg']  ?? '';

$trainingExperience   = $userRow['training_experience']   ?? '';
$trainingAvailability = $userRow['training_availability'] ?? '';
$trainingFrequency    = $userRow['training_frequency']    ?? '';

$injuriesLimitations = $userRow['injuries_limitations'] ?? '';
$medicalConditions   = $userRow['medical_conditions']   ?? '';
$foodAllergies       = $userRow['food_allergies']       ?? '';
$dietaryPreferences  = $userRow['dietary_preferences']  ?? '';
$sleepHours          = $userRow['sleep_hours']          ?? '';

// Campos específicos do coach / vitrine profissional (já existentes)
$professionalTitle = $userRow['professional_title'] ?? '';
$shortBio          = $userRow['short_bio']          ?? '';
$fullBio           = $userRow['full_bio']           ?? '';

$certifications  = $userRow['certifications']    ?? '';
$experienceYears = $userRow['experience_years']  ?? '';
$expertiseAreas  = $userRow['expertise_areas']   ?? ($userRow['coach_focus_areas'] ?? '');
$courses         = $userRow['courses']           ?? '';

$trainingPhilosophy  = $userRow['training_philosophy']  ?? '';
$customizationMethod = $userRow['customization_method'] ?? '';
$progressMethod      = $userRow['progress_method']      ?? '';
$availability        = $userRow['availability']         ?? $trainingAvailability;
$languages           = $userRow['coach_languages']      ?? ($userRow['languages'] ?? '');

// ========= NOVOS CAMPOS FORTES PRA CONVERSÃO =========
$marketingHeadline   = $userRow['marketing_headline']     ?? ''; // frase de venda
$preferredGoals      = $userRow['preferred_goals']        ?? ''; // objetivos que mais trabalha
$uniqueSellingPoint  = $userRow['unique_selling_point']   ?? ''; // por que escolher você
$messageToNewClients = $userRow['message_to_new_clients'] ?? ''; // recado pro lead
$instagramHandle     = $userRow['instagram_handle']       ?? ''; // @insta para social proof

// ========= FOTOS DE PORTFÓLIO =========
$stmtPhotos = $pdo->prepare("
    SELECT photo_url, caption
    FROM coach_photos
    WHERE coach_id = :id
    ORDER BY created_at DESC
    LIMIT 4
");
$stmtPhotos->execute(['id' => $userId]);
$coachPhotos = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ===============================
// FORMATADORES (reaproveitados do client profile)
// ===============================
function formatHeightImperial($heightCm): string
{
    if ($heightCm === '' || $heightCm === null) {
        return 'Not provided';
    }
    $cm = (float)$heightCm;
    if ($cm <= 0) {
        return 'Not provided';
    }
    $totalInches = $cm / 2.54;
    $feet        = (int)floor($totalInches / 12);
    $inches      = (int)round($totalInches - ($feet * 12));

    if ($inches === 12) {
        $feet  += 1;
        $inches = 0;
    }

    $feetPart   = $feet . "'" . $inches . "\"";
    $metricPart = (string)round($cm) . " cm";

    return $feetPart . " (" . $metricPart . ")";
}

function formatWeightImperial($weightKg): string
{
    if ($weightKg === '' || $weightKg === null) {
        return 'Not provided';
    }
    $kg = (float)$weightKg;
    if ($kg <= 0) {
        return 'Not provided';
    }
    $lbs = (int)round($kg * 2.20462);
    return $lbs . " lbs (" . (string)round($kg, 1) . " kg)";
}

function formatSleepLabel($code): string
{
    $labels = [
        'less_than_5' => 'Less than 5h',
        '5-6'         => '5–6 hours',
        '7-8'         => '7–8 hours',
        'more_than_8' => 'More than 8h',
    ];

    if ($code === '' || $code === null) {
        return 'Not provided';
    }

    if (isset($labels[$code])) {
        return $labels[$code];
    }

    return (string)$code;
}

function showValueOrNotProvided($value): void
{
    if ($value === '' || $value === null) {
        echo 'Not provided';
    } else {
        echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$profilePhotoAlt = 'Profile photo of ' . $name;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Coach Profile | RB Personal Trainer | Rafa Breder Coaching</title>
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
  <link rel="stylesheet" href="/assets/css/client_profile.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">

  <style>
    /* thumbnails menores (quadrado menor) */
    .rb-portfolio-grid{
      display:grid;
      grid-template-columns:repeat(auto-fill,minmax(72px,1fr));
      gap:10px;
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
      border-radius:12px;
      overflow:hidden;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(255,255,255,.03);
    }
    .rb-portfolio-thumb img{
      width:100%;
      height:100%;
      display:block;
      object-fit:cover;
      object-position:center;
      transform:translateZ(0);
      transition:transform .18s ease, filter .18s ease;
    }
    .rb-portfolio-btn:hover .rb-portfolio-thumb img{
      transform:scale(1.03);
      filter:brightness(1.05);
    }
    .rb-portfolio-caption{
      margin-top:6px;
      font-size:.82rem;
      opacity:.85;
      line-height:1.25;
    }

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

<header id="rb-static-header" class="rbf1-header">
  <div class="rbf1-topbar">
    <a href="/" class="rbf1-logo">
      <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
    </a>

    <nav class="rbf1-nav" id="rbf1-nav">
      <ul>
        <li><a href="/dashboards/dashboard_personal.php">Dashboard</a></li>
        <li><a href="/dashboards/personal_profile.php" class="rbf1-link rbf1-link-active">Profile</a></li>
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

<main class="client-dashboard">
  <div class="client-shell">

    <section class="client-hero">
      <div class="client-hero-left">
        <div class="client-avatar-wrapper">
          <div class="client-avatar">
            <!-- COMO ANTES: usa o valor cru -->
            <img src="<?= esc($avatarUrl); ?>" alt="<?= esc($profilePhotoAlt); ?>">
          </div>
        </div>

        <div class="client-hero-text">
          <p class="client-eyebrow">Coach professional profile</p>
          <h1 class="client-title"><?= esc($name); ?></h1>
          <p class="client-subtitle">
            This is your professional profile inside RB Personal Trainer.
          </p>
        </div>
      </div>

      <div class="client-hero-right">
        <div class="client-pill client-pill--status">
          Profile <span>Active</span>
        </div>
      </div>
    </section>

    <section class="client-main-grid">

      <article class="client-panel client-panel--personal">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Professional identity</p>
          <h2 class="client-panel-title">How clients will see you</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row form-row--avatar">
              <p class="form-label">Profile photo</p>
              <div class="profile-value">
                <div class="client-avatar-wrapper">
                  <div class="client-avatar">
                    <!-- COMO ANTES -->
                    <img src="<?= esc($avatarUrl); ?>" alt="<?= esc($profilePhotoAlt); ?>">
                  </div>
                </div>
              </div>
            </div>

            <div class="form-row">
              <p class="form-label">Full name</p>
              <p class="profile-value"><?php showValueOrNotProvided($name); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Professional title</p>
              <p class="profile-value"><?php showValueOrNotProvided($professionalTitle); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Marketing headline</p>
              <p class="profile-value"><?php showValueOrNotProvided($marketingHeadline); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Location / Time zone</p>
              <p class="profile-value"><?php showValueOrNotProvided($timeZone); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Languages</p>
              <p class="profile-value"><?php showValueOrNotProvided($languages); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Contact email</p>
              <p class="profile-value"><?php showValueOrNotProvided($email); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Phone (optional)</p>
              <p class="profile-value"><?php showValueOrNotProvided($phone); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Instagram</p>
              <p class="profile-value">
                <?php
                if ($instagramHandle === '' || $instagramHandle === null) {
                    echo 'Not provided';
                } else {
                    $handle = esc($instagramHandle);
                    $rawUrl = (str_starts_with((string)$instagramHandle, 'http'))
                        ? (string)$instagramHandle
                        : 'https://www.instagram.com/' . ltrim((string)$instagramHandle, '@/');
                    $url = esc($rawUrl);
                    echo '<a href="' . $url . '" target="_blank" rel="noopener">' . $handle . '</a>';
                }
                ?>
              </p>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer">
          <a href="personal_profile_edit.php" class="client-panel-link subtle">
            Edit professional identity
          </a>
        </footer>
      </article>

      <article class="client-panel client-panel--training">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Experience & specialties</p>
          <h2 class="client-panel-title">Who you help and how</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <p class="form-label">Years of experience</p>
              <p class="profile-value"><?php showValueOrNotProvided($experienceYears); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Coaching experience (self-rating)</p>
              <p class="profile-value">
                <?php
                if ($trainingExperience === '' || $trainingExperience === null) {
                    echo 'Not provided';
                } else {
                    switch ((string)$trainingExperience) {
                        case 'beginner': echo 'Up to 1 year'; break;
                        case 'intermediate': echo '2–4 years'; break;
                        case 'advanced': echo '5+ years'; break;
                        default: echo esc($trainingExperience);
                    }
                }
                ?>
              </p>
            </div>

            <div class="form-row">
              <p class="form-label">Main goals you work with</p>
              <p class="profile-value"><?php showValueOrNotProvided($preferredGoals); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Typical weekly coaching load</p>
              <p class="profile-value">
                <?php
                if ($trainingFrequency === '' || $trainingFrequency === null) {
                    echo 'Not provided';
                } else {
                    switch ((string)$trainingFrequency) {
                        case '1-2': echo '1–2 client sessions/day'; break;
                        case '3': echo '3 client sessions/day'; break;
                        case '4-5': echo '4–5 client sessions/day'; break;
                        case '6+': echo '6+ client sessions/day'; break;
                        default: echo esc($trainingFrequency);
                    }
                }
                ?>
              </p>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer">
          <a href="personal_profile_edit.php" class="client-panel-link subtle">
            Edit experience & specialties
          </a>
        </footer>
      </article>

      <article class="client-panel client-panel--body">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Credentials & portfolio</p>
          <h2 class="client-panel-title">Certifications & work samples</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <p class="form-label">Main certifications</p>
              <p class="profile-value"><?php showValueOrNotProvided($certifications); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Courses & special trainings</p>
              <p class="profile-value">
                <?php
                if ($courses === '' || $courses === null) {
                    echo 'Not provided';
                } else {
                    echo nl2br(esc($courses));
                }
                ?>
              </p>
            </div>

            <div class="form-row">
              <p class="form-label">Portfolio photos</p>
              <div class="profile-value">

                <?php if (!empty($coachPhotos)): ?>
                  <div class="rb-portfolio-grid">
                    <?php foreach ($coachPhotos as $photo): ?>
                      <?php
                        // ✅ COMO ANTES: usa o que está no banco, sem mexer
                        $photoUrl = (string)($photo['photo_url'] ?? '');
                        $caption  = (string)($photo['caption'] ?? '');

                        if ($photoUrl === '') {
                            $photoUrl = '/assets/images/portfolio-placeholder.png';
                        }

                        $photoAlt = $caption !== '' ? ('Coach photo: ' . $caption) : 'Coach photo';
                      ?>
                      <button
                        type="button"
                        class="rb-portfolio-btn"
                        data-rb-lightbox
                        data-full="<?= esc($photoUrl); ?>"
                        aria-label="Open photo"
                      >
                        <div class="rb-portfolio-thumb">
                          <img src="<?= esc($photoUrl); ?>" alt="<?= esc($photoAlt); ?>" loading="lazy">
                        </div>

                        <?php if ($caption !== ''): ?>
                          <div class="rb-portfolio-caption"><?= esc($caption); ?></div>
                        <?php endif; ?>
                      </button>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <p class="profile-value">You haven’t added any portfolio photos yet.</p>
                <?php endif; ?>

              </div>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer">
          <a href="personal_profile_edit.php" class="client-panel-link subtle">
            Edit certifications & portfolio
          </a>
        </footer>
      </article>

      <article class="client-panel client-panel--health">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Approach & logistics</p>
          <h2 class="client-panel-title">How you work with clients</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <p class="form-label">Training philosophy</p>
              <p class="profile-value">
                <?php
                if ($trainingPhilosophy === '' || $trainingPhilosophy === null) {
                    echo 'Not provided';
                } else {
                    echo nl2br(esc($trainingPhilosophy));
                }
                ?>
              </p>
            </div>

            <div class="form-row">
              <p class="form-label">How you track progress</p>
              <p class="profile-value">
                <?php
                if ($progressMethod === '' || $progressMethod === null) {
                    echo 'Not provided';
                } else {
                    echo nl2br(esc($progressMethod));
                }
                ?>
              </p>
            </div>

            <div class="form-row">
              <p class="form-label">Availability / schedule</p>
              <p class="profile-value"><?php showValueOrNotProvided($availability); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Why work with you</p>
              <p class="profile-value">
                <?php
                if ($uniqueSellingPoint === '' || $uniqueSellingPoint === null) {
                    echo 'Not provided';
                } else {
                    echo nl2br(esc($uniqueSellingPoint));
                }
                ?>
              </p>
            </div>

            <div class="form-row">
              <p class="form-label">Message to new clients</p>
              <p class="profile-value">
                <?php
                if ($messageToNewClients === '' || $messageToNewClients === null) {
                    echo 'Not provided';
                } else {
                    echo nl2br(esc($messageToNewClients));
                }
                ?>
              </p>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer">
          <a href="personal_profile_edit.php" class="client-panel-link subtle">
            Edit approach & message
          </a>
        </footer>
      </article>

    </section>

  </div>
</main>

<footer class="site-footer">
  <div class="footer-cta">
    <div class="footer-cta-inner">
      <p class="footer-cta-eyebrow">Online Personal Training</p>
      <h2 class="footer-cta-title">
        Progress is built in small, consistent moments—show up for yourself today.
      </h2>
      <a href="/dashboards/reset_password.php" class="footer-cta-button">
        Reset Password
      </a>
    </div>
  </div>

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
                <img src="/assets/images/facebook.png" alt="Instagram Logo">
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

<div id="rbLightbox" class="rb-lightbox" aria-hidden="true">
  <div class="rb-lightbox-inner" role="dialog" aria-modal="true">
    <button type="button" class="rb-lightbox-close" id="rbLightboxClose" aria-label="Close">✕</button>
    <img id="rbLightboxImg" class="rb-lightbox-img" src="" alt="Photo preview">
  </div>
</div>

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
