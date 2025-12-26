<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_login();
require_role(['user','client']);

$userId = (int)$_SESSION['user_id'];
$pdo    = getPDO();

// ===============================
// CARREGA DADOS DO USUÁRIO
// (evita SELECT *)
// ===============================
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        phone,
        gender,
        birthday,
        height_cm,
        weight_kg,
        time_zone,
        training_experience,
        training_frequency,
        training_availability,
        injuries_limitations,
        medical_conditions,
        food_allergies,
        dietary_preferences,
        sleep_average,
        stress_level,
        hydration_level,
        main_goal,
        avatar_url
    FROM users
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Dados básicos
$name      = $userRow['name']  ?? ($_SESSION['user_name'] ?? 'Client');
$email     = $userRow['email'] ?? '';
$avatarUrl = !empty($userRow['avatar_url'])
    ? $userRow['avatar_url']
    : '/assets/images/client-avatar-placeholder.jpg';

// Demais campos
$phone                = $userRow['phone']                 ?? '';
$gender               = $userRow['gender']                ?? '';
$dateOfBirth          = $userRow['birthday']              ?? '';
$heightCm             = $userRow['height_cm']             ?? '';
$weightKg             = $userRow['weight_kg']             ?? '';
$timeZone             = $userRow['time_zone']             ?? '';
$trainingExperience   = $userRow['training_experience']   ?? '';
$trainingFrequency    = $userRow['training_frequency']    ?? '';
$trainingAvailability = $userRow['training_availability'] ?? '';
$injuriesLimitations  = $userRow['injuries_limitations']  ?? '';
$medicalConditions    = $userRow['medical_conditions']    ?? '';
$foodAllergies        = $userRow['food_allergies']        ?? '';
$dietaryPreferences   = $userRow['dietary_preferences']   ?? '';
$sleepAverage         = $userRow['sleep_average']         ?? '';
$stressLevel          = $userRow['stress_level']          ?? '';
$hydrationLevel       = $userRow['hydration_level']       ?? '';
$mainGoal             = $userRow['main_goal']             ?? '';

// ===============================
// FORMATADORES
// ===============================
function formatHeightImperial($heightCm): string
{
    if ($heightCm === '' || $heightCm === null) return 'Not provided';

    $cm = (float)$heightCm;
    if ($cm <= 0) return 'Not provided';

    $totalInches = $cm / 2.54;
    $feet        = (int)floor($totalInches / 12);
    $inches      = (int)round($totalInches - ($feet * 12));

    if ($inches === 12) {
        $feet += 1;
        $inches = 0;
    }

    return $feet . "'" . $inches . "\" (" . round($cm) . " cm)";
}

function formatWeightImperial($weightKg): string
{
    if ($weightKg === '' || $weightKg === null) return 'Not provided';

    $kg = (float)$weightKg;
    if ($kg <= 0) return 'Not provided';

    $lbs = round($kg * 2.20462);
    return $lbs . " lbs (" . round($kg, 1) . " kg)";
}

function formatSleepLabel($code): string
{
    $labels = [
        'less_than_5' => 'Less than 5h',
        '5-6'         => '5–6 hours',
        '7-8'         => '7–8 hours',
        'more_than_8' => 'More than 8h',
    ];

    if ($code === '' || $code === null) return 'Not provided';
    return $labels[$code] ?? (string)$code;
}

function formatStressLabel($code): string
{
    $labels = [
        'very_low'  => 'Very low',
        'low'       => 'Low',
        'medium'    => 'Medium',
        'high'      => 'High',
        'very_high' => 'Very high',
    ];

    if ($code === '' || $code === null) return 'Not provided';
    return $labels[$code] ?? (string)$code;
}

function formatHydrationLabel($code): string
{
    $labels = [
        'very_low'  => 'Very low',
        'low'       => 'Low',
        'medium'    => 'Medium',
        'high'      => 'High',
        'very_high' => 'Very high',
    ];

    if ($code === '' || $code === null) return 'Not provided';
    return $labels[$code] ?? (string)$code;
}

function valueOrNotProvided($value): string
{
    if ($value === '' || $value === null) return 'Not provided';
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nlValueOrNotProvided($value): string
{
    if ($value === '' || $value === null) return 'Not provided';
    return nl2br(htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
}

function genderLabel($gender): string
{
    if ($gender === '' || $gender === null) return 'Not provided';
    if ($gender === 'female') return 'Female';
    if ($gender === 'male') return 'Male';
    return 'Other / Prefer not to say';
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
  <link rel="stylesheet" href="/assets/css/client_profile.css">
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
  <div class="client-shell">

    <section class="client-hero">
      <div class="client-hero-left">
        <div class="client-avatar-wrapper">
          <div class="client-avatar">
            <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="client-hero-text">
          <p class="client-eyebrow">Client profile</p>
          <h1 class="client-title">Hi, <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>.</h1>
          <p class="client-subtitle">
            Here you can see your personal information, body stats and lifestyle.
            Click “Edit profile” if you want to change anything.
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

      <!-- CARD 1 -->
      <article class="client-panel client-panel--personal">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Your profile</p>
          <h2 class="client-panel-title">Personal & contact info</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row form-row--avatar">
              <p class="form-label">Profile photo</p>
              <div class="profile-value">
                <div class="client-avatar-wrapper">
                  <div class="client-avatar">
                    <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
                  </div>
                </div>
              </div>
            </div>

            <div class="form-row">
              <p class="form-label">Full name</p>
              <p class="profile-value"><?= valueOrNotProvided($name); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Email</p>
              <p class="profile-value"><?= valueOrNotProvided($email); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Phone</p>
              <p class="profile-value"><?= valueOrNotProvided($phone); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Location / Time zone</p>
              <p class="profile-value"><?= valueOrNotProvided($timeZone); ?></p>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer">
          <a href="/dashboards/client_profile_edit.php" class="client-panel-link subtle">Edit basic info</a>
        </footer>
      </article>

      <!-- CARD 2 -->
      <article class="client-panel client-panel--body">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Body & stats</p>
          <h2 class="client-panel-title">Body measurements</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <p class="form-label">Gender</p>
              <p class="profile-value"><?= htmlspecialchars(genderLabel($gender), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Date of birth</p>
              <p class="profile-value">
                <?= ($dateOfBirth === '' || $dateOfBirth === null) ? 'Not provided' : htmlspecialchars((string)$dateOfBirth, ENT_QUOTES, 'UTF-8'); ?>
              </p>
            </div>

            <div class="form-row">
              <p class="form-label">Height</p>
              <p class="profile-value"><?= htmlspecialchars(formatHeightImperial($heightCm), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Average sleep</p>
              <p class="profile-value"><?= htmlspecialchars(formatSleepLabel($sleepAverage), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Stress level</p>
              <p class="profile-value"><?= htmlspecialchars(formatStressLabel($stressLevel), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Weight</p>
              <p class="profile-value"><?= htmlspecialchars(formatWeightImperial($weightKg), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer">
          <a href="/dashboards/client_profile_edit.php" class="client-panel-link subtle">Edit body stats</a>
        </footer>
      </article>

      <!-- CARD 3 -->
      <article class="client-panel client-panel--training">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Training</p>
          <h2 class="client-panel-title">Training profile</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <p class="form-label">Training experience</p>
              <p class="profile-value">
                <?php
                  if ($trainingExperience === '' || $trainingExperience === null) echo 'Not provided';
                  else {
                    echo htmlspecialchars(match ($trainingExperience) {
                      'beginner' => 'Beginner',
                      'intermediate' => 'Intermediate',
                      'advanced' => 'Advanced',
                      default => (string)$trainingExperience,
                    }, ENT_QUOTES, 'UTF-8');
                  }
                ?>
              </p>
            </div>

            <div class="form-row">
              <p class="form-label">Training frequency</p>
              <p class="profile-value">
                <?php
                  if ($trainingFrequency === '' || $trainingFrequency === null) echo 'Not provided';
                  else {
                    echo htmlspecialchars(match ($trainingFrequency) {
                      '1-2' => '1–2 times/week',
                      '3'   => '3 times/week',
                      '4-5' => '4–5 times/week',
                      '6+'  => '6+ times/week',
                      default => (string)$trainingFrequency,
                    }, ENT_QUOTES, 'UTF-8');
                  }
                ?>
              </p>
            </div>

            <div class="form-row">
              <p class="form-label">Training availability</p>
              <p class="profile-value"><?= valueOrNotProvided($trainingAvailability); ?></p>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer">
          <a href="/dashboards/client_profile_edit.php" class="client-panel-link subtle">Edit training profile</a>
        </footer>
      </article>

      <!-- CARD 4 -->
      <article class="client-panel client-panel--health">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Health & lifestyle</p>
          <h2 class="client-panel-title">Health & nutrition</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <p class="form-label">Injuries / limitations</p>
              <p class="profile-value"><?= nlValueOrNotProvided($injuriesLimitations); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Medical conditions / medications</p>
              <p class="profile-value"><?= nlValueOrNotProvided($medicalConditions); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Hydration level</p>
              <p class="profile-value"><?= htmlspecialchars(formatHydrationLabel($hydrationLevel), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Food allergies / intolerances</p>
              <p class="profile-value"><?= nlValueOrNotProvided($foodAllergies); ?></p>
            </div>

            <div class="form-row">
              <p class="form-label">Dietary preferences</p>
              <p class="profile-value"><?= nlValueOrNotProvided($dietaryPreferences); ?></p>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer">
          <a href="/dashboards/client_profile_edit.php" class="client-panel-link subtle">Edit health & nutrition</a>
        </footer>
      </article>

    </section>
  </div>
</main>

<!-- FOOTER (corrigido: sem footer duplicado) -->
<footer class="site-footer">
  <div class="footer-cta">
    <div class="footer-cta-inner">
      <p class="footer-cta-eyebrow">Online Personal Training</p>
      <h2 class="footer-cta-title">
        Progress is built in small, consistent moments—show up for yourself today.
      </h2>
      <a href="/dashboards/reset_password.php" class="footer-cta-button">Reset Password</a>
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
