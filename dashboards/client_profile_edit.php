<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user','client']);

$pdo    = getPDO();
$userId = (int)($_SESSION['user_id'] ?? 0);

$profileErrors  = [];
$profileSuccess = null;

// ===============================
// MENSAGENS DO UPLOAD (avatar)
// ===============================
$avatarUploadError   = $_SESSION['avatar_upload_error']   ?? null;
$avatarUploadSuccess = $_SESSION['avatar_upload_success'] ?? null;

// limpa para não repetir depois do refresh
unset($_SESSION['avatar_upload_error'], $_SESSION['avatar_upload_success']);

// ===============================
// CSRF
// ===============================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

// ===============================
// HELPERS
// ===============================
function formatHeightImperialOption($cm): string
{
    $cm = (float)$cm;
    if ($cm <= 0) return (string)$cm . ' cm';

    $totalInches = $cm / 2.54;
    $feet        = (int)floor($totalInches / 12);
    $inches      = (int)round($totalInches - ($feet * 12));

    if ($inches === 12) {
        $feet  += 1;
        $inches = 0;
    }

    return $feet . "'" . $inches . "\" (" . (int)round($cm) . " cm)";
}

function kgToLbs($kg): string
{
    if ($kg === '' || $kg === null) return '';
    $kg = (float)$kg;
    if ($kg <= 0) return '';
    return (string)round($kg * 2.20462, 1);
}

function lbsToKgOrNull($lbsInput): ?float
{
    if ($lbsInput === '' || $lbsInput === null) return null;
    $lbs = (float)$lbsInput;
    if ($lbs <= 0) return null;
    $kg = $lbs * 0.45359237;
    return (float)round($kg, 1);
}

// ===============================
// COMPATIBILIDADE PHP < 8
// ===============================
if (!function_exists('rb_starts_with')) {
    function rb_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

// Normaliza caminhos antigos do avatar para o padrão novo
function normalizeAvatarUrl($url): string
{
    $url = (string)($url ?? '');
    $url = trim($url);

    if ($url === '') {
        return '/assets/images/client-avatar-placeholder.jpg';
    }

    if (rb_starts_with($url, '/assets/uploads/avatars/')) {
        return $url;
    }

    if (rb_starts_with($url, 'assets/uploads/avatars/')) {
        return '/' . $url;
    }

    if (rb_starts_with($url, 'uploads/avatars/')) {
        return '/assets/' . $url;
    }

    // se já vier com /assets/images/... etc, deixa
    return $url;
}

// ===============================
// PROCESSA POST (SALVAR PERFIL)
// ===============================
$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['profile_update']));

$nameInput                 = null;
$emailInput                = null;
$phoneInput                = null;
$genderInput               = null;
$dateOfBirthInput          = null;
$heightCmInput             = null;
$weightLbsInput            = null;
$timeZoneInput             = null;
$trainingExperienceInput   = null;
$trainingFrequencyInput    = null;
$trainingAvailabilityInput = null;
$injuriesLimitationsInput  = null;
$medicalConditionsInput    = null;
$foodAllergiesInput        = null;
$dietaryPreferencesInput   = null;
$sleepAverageInput         = null;
$stressLevelInput          = null;
$hydrationLevelInput       = null;

if ($isPost) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $sess = (string)($_SESSION['csrf_token'] ?? '');

    if ($sess === '' || !hash_equals($sess, $csrf)) {
        $profileErrors[] = 'Invalid request (CSRF).';
    } else {
        $nameInput                 = trim((string)($_POST['name']                  ?? ''));
        $emailInput                = trim((string)($_POST['email']                 ?? ''));
        $phoneInput                = trim((string)($_POST['phone']                 ?? ''));
        $genderInput               = trim((string)($_POST['gender']                ?? ''));
        $dateOfBirthInput          = trim((string)($_POST['date_of_birth']         ?? ''));
        $heightCmInput             = trim((string)($_POST['height_cm']             ?? ''));
        $weightLbsInput            = trim((string)($_POST['weight_lbs']            ?? ''));
        $timeZoneInput             = trim((string)($_POST['time_zone']             ?? ''));
        $trainingExperienceInput   = trim((string)($_POST['training_experience']   ?? ''));
        $trainingFrequencyInput    = trim((string)($_POST['training_frequency']    ?? ''));
        $trainingAvailabilityInput = trim((string)($_POST['training_availability'] ?? ''));
        $injuriesLimitationsInput  = trim((string)($_POST['injuries_limitations']  ?? ''));
        $medicalConditionsInput    = trim((string)($_POST['medical_conditions']    ?? ''));
        $foodAllergiesInput        = trim((string)($_POST['food_allergies']        ?? ''));
        $dietaryPreferencesInput   = trim((string)($_POST['dietary_preferences']   ?? ''));
        $sleepAverageInput         = trim((string)($_POST['sleep_average']         ?? ''));
        $stressLevelInput          = trim((string)($_POST['stress_level']          ?? ''));
        $hydrationLevelInput       = trim((string)($_POST['hydration_level']       ?? ''));

        if ($nameInput === '') {
            $profileErrors[] = 'Name is required.';
        }

        if ($emailInput === '') {
            $profileErrors[] = 'Email is required.';
        } elseif (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $profileErrors[] = 'Invalid email format.';
        }

        if (empty($profileErrors)) {
            $dateOfBirthDb = $dateOfBirthInput !== '' ? $dateOfBirthInput : null;

            $heightCmDb = null;
            if ($heightCmInput !== '') {
                $h = (int)$heightCmInput;
                if ($h >= 140 && $h <= 210) {
                    $heightCmDb = (float)$h;
                }
            }

            $weightKgDb             = lbsToKgOrNull($weightLbsInput);
            $timeZoneDb             = $timeZoneInput !== '' ? $timeZoneInput : null;
            $genderDb               = $genderInput !== '' ? $genderInput : null;
            $phoneDb                = $phoneInput !== '' ? $phoneInput : null;
            $trainingExperienceDb   = $trainingExperienceInput !== '' ? $trainingExperienceInput : null;
            $trainingFrequencyDb    = $trainingFrequencyInput !== '' ? $trainingFrequencyInput : null;
            $trainingAvailabilityDb = $trainingAvailabilityInput !== '' ? $trainingAvailabilityInput : null;
            $injuriesLimitationsDb  = $injuriesLimitationsInput !== '' ? $injuriesLimitationsInput : null;
            $medicalConditionsDb    = $medicalConditionsInput !== '' ? $medicalConditionsInput : null;
            $foodAllergiesDb        = $foodAllergiesInput !== '' ? $foodAllergiesInput : null;
            $dietaryPreferencesDb   = $dietaryPreferencesInput !== '' ? $dietaryPreferencesInput : null;
            $sleepAverageDb         = $sleepAverageInput !== '' ? $sleepAverageInput : null;
            $stressLevelDb          = $stressLevelInput !== '' ? $stressLevelInput : null;
            $hydrationLevelDb       = $hydrationLevelInput !== '' ? $hydrationLevelInput : null;

            try {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET
                        name                  = :name,
                        email                 = :email,
                        phone                 = :phone,
                        gender                = :gender,
                        birthday              = :birthday,
                        height_cm             = :height_cm,
                        weight_kg             = :weight_kg,
                        time_zone             = :time_zone,
                        training_experience   = :training_experience,
                        training_frequency    = :training_frequency,
                        training_availability = :training_availability,
                        injuries_limitations  = :injuries_limitations,
                        medical_conditions    = :medical_conditions,
                        food_allergies        = :food_allergies,
                        dietary_preferences   = :dietary_preferences,
                        sleep_average         = :sleep_average,
                        stress_level          = :stress_level,
                        hydration_level       = :hydration_level
                    WHERE id = :id
                    LIMIT 1
                ");

                $stmt->execute([
                    'name'                  => $nameInput,
                    'email'                 => $emailInput,
                    'phone'                 => $phoneDb,
                    'gender'                => $genderDb,
                    'birthday'              => $dateOfBirthDb,
                    'height_cm'             => $heightCmDb,
                    'weight_kg'             => $weightKgDb,
                    'time_zone'             => $timeZoneDb,
                    'training_experience'   => $trainingExperienceDb,
                    'training_frequency'    => $trainingFrequencyDb,
                    'training_availability' => $trainingAvailabilityDb,
                    'injuries_limitations'  => $injuriesLimitationsDb,
                    'medical_conditions'    => $medicalConditionsDb,
                    'food_allergies'        => $foodAllergiesDb,
                    'dietary_preferences'   => $dietaryPreferencesDb,
                    'sleep_average'         => $sleepAverageDb,
                    'stress_level'          => $stressLevelDb,
                    'hydration_level'       => $hydrationLevelDb,
                    'id'                    => $userId,
                ]);

                $_SESSION['user_name'] = $nameInput;
                $profileSuccess = 'Profile updated successfully.';
            } catch (Throwable $e) {
                error_log("Profile update error user {$userId}: " . $e->getMessage());
                $profileErrors[] = 'Error while saving your profile. Please try again.';
            }
        }
    }
}

// ===============================
// CARREGA DADOS DO USUÁRIO (evita SELECT *)
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
        avatar_url
    FROM users
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Dados do banco
$name                 = (string)($userRow['name'] ?? ($_SESSION['user_name'] ?? 'Client'));
$email                = (string)($userRow['email'] ?? '');
$avatarUrl            = normalizeAvatarUrl($userRow['avatar_url'] ?? '');
$phone                = (string)($userRow['phone'] ?? '');
$gender               = (string)($userRow['gender'] ?? '');
$dateOfBirth          = (string)($userRow['birthday'] ?? '');
$heightCm             = $userRow['height_cm'] ?? '';
$weightKg             = $userRow['weight_kg'] ?? '';
$timeZone             = (string)($userRow['time_zone'] ?? '');
$trainingExperience   = (string)($userRow['training_experience'] ?? '');
$trainingFrequency    = (string)($userRow['training_frequency'] ?? '');
$trainingAvailability = (string)($userRow['training_availability'] ?? '');
$injuriesLimitations  = (string)($userRow['injuries_limitations'] ?? '');
$medicalConditions    = (string)($userRow['medical_conditions'] ?? '');
$foodAllergies        = (string)($userRow['food_allergies'] ?? '');
$dietaryPreferences   = (string)($userRow['dietary_preferences'] ?? '');
$sleepAverage         = (string)($userRow['sleep_average'] ?? '');
$stressLevel          = (string)($userRow['stress_level'] ?? '');
$hydrationLevel       = (string)($userRow['hydration_level'] ?? '');

$weightLbs = kgToLbs($weightKg);

// Se veio POST com erro, mantém o que o usuário digitou
if ($isPost && !empty($profileErrors)) {
    $name                 = (string)$nameInput;
    $email                = (string)$emailInput;
    $phone                = (string)$phoneInput;
    $gender               = (string)$genderInput;
    $dateOfBirth          = (string)$dateOfBirthInput;
    $heightCm             = $heightCmInput;
    $weightLbs            = (string)$weightLbsInput;
    $timeZone             = (string)$timeZoneInput;
    $trainingExperience   = (string)$trainingExperienceInput;
    $trainingFrequency    = (string)$trainingFrequencyInput;
    $trainingAvailability = (string)$trainingAvailabilityInput;
    $injuriesLimitations  = (string)$injuriesLimitationsInput;
    $medicalConditions    = (string)$medicalConditionsInput;
    $foodAllergies        = (string)$foodAllergiesInput;
    $dietaryPreferences   = (string)$dietaryPreferencesInput;
    $sleepAverage         = (string)$sleepAverageInput;
    $stressLevel          = (string)$stressLevelInput;
    $hydrationLevel       = (string)$hydrationLevelInput;
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

  <!-- CSS (ABSOLUTO) -->
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/client_profile_edit.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">

  <meta charset="UTF-8" />
  <title>Edit Client Profile | RB Personal Trainer | Rafa Breder Coaching</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body>

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

    <!-- HERO -->
    <section class="client-hero">
      <div class="client-hero-left">
        <div class="client-avatar-wrapper">
          <div class="client-avatar">
            <img src="<?= htmlspecialchars((string)$avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="client-hero-text">
          <p class="client-eyebrow">Edit profile</p>
          <h1 class="client-title">Edit your profile, <?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8'); ?>.</h1>
          <p class="client-subtitle">
            Update your personal information, body stats and lifestyle so your coach and nutritionist
            can customize your plan.
          </p>
        </div>
      </div>

      <div class="client-hero-right">
        <div class="client-pill client-pill--status">
          Profile <span>Active</span>
        </div>
      </div>
    </section>

    <!-- MENSAGENS DO AVATAR (UPLOAD) -->
    <?php if ($avatarUploadError || $avatarUploadSuccess): ?>
      <section class="client-main-grid" style="margin-bottom: 1.5rem;">
        <article class="client-panel client-panel-wide">
          <div class="client-panel-body">
            <?php if ($avatarUploadError): ?>
              <p class="form-error"><?= htmlspecialchars((string)$avatarUploadError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if ($avatarUploadSuccess): ?>
              <p class="form-success"><?= htmlspecialchars((string)$avatarUploadSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
          </div>
        </article>
      </section>
    <?php endif; ?>

    <!-- MENSAGENS DE ERRO/SUCESSO DO PERFIL -->
    <?php if (!empty($profileErrors) || $profileSuccess): ?>
      <section class="client-main-grid" style="margin-bottom: 1.5rem;">
        <article class="client-panel client-panel-wide">
          <div class="client-panel-body">
            <?php if (!empty($profileErrors)): ?>
              <ul class="form-error-list">
                <?php foreach ($profileErrors as $err): ?>
                  <li class="form-error"><?= htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if ($profileSuccess): ?>
              <p class="form-success"><?= htmlspecialchars((string)$profileSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
          </div>
        </article>
      </section>
    <?php endif; ?>

    <!-- FORM VAZIO SÓ PARA UPLOAD DO AVATAR -->
    <form id="avatar-form"
          action="/dashboards/client_avatar_upload.php"
          method="post"
          enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <!-- Intencionalmente vazio -->
    </form>

    <!-- FORM PRINCIPAL -->
    <form action="/dashboards/client_profile_edit.php"
          method="post"
          class="client-main-grid profile-form">
      <input type="hidden" name="profile_update" value="1">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

      <!-- CARD 1: PERSONAL & CONTACT INFO -->
      <article class="client-panel client-panel--personal">
        <header class="client-panel-header">
          <div>
            <p class="client-panel-eyebrow">Your profile</p>
            <h2 class="client-panel-title">Photo & basic info</h2>
          </div>
        </header>

        <div class="client-panel-body">

          <!-- BLOCO DE FOTO + UPLOAD -->
          <div class="form-row" style="margin-bottom: 1.2rem;">
            <div>
              <p class="form-label">Profile photo</p>
            </div>
            <div style="display:flex; align-items:center; gap:1.1rem; flex-wrap:wrap;">
              <div class="client-avatar-wrapper">
                <div class="client-avatar">
                  <img src="<?= htmlspecialchars((string)$avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
              </div>

              <div style="flex:1; min-width:220px;">
                <label class="form-label" for="avatar" style="margin-bottom:0.35rem;">Upload new photo</label>
                <input
                  type="file"
                  id="avatar"
                  name="avatar"
                  form="avatar-form"
                  accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                >
                <p class="client-panel-text" style="margin-top:0.35rem; font-size:0.8rem; opacity:0.85;">
                  JPG, PNG or WEBP. Max 2MB.
                  When you click “Update photo”, only the picture is updated.
                </p>

                <button type="submit"
                        class="btn-primary"
                        form="avatar-form"
                        style="margin-top:0.4rem;">
                  Update photo
                </button>
              </div>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label" for="name">Full name</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8'); ?>" required>
          </div>

          <div class="form-row">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars((string)$email, ENT_QUOTES, 'UTF-8'); ?>" required>
          </div>

          <div class="form-row">
            <label class="form-label" for="phone">Phone (optional)</label>
            <input type="text" id="phone" name="phone" value="<?= htmlspecialchars((string)$phone, ENT_QUOTES, 'UTF-8'); ?>">
          </div>

          <div class="form-row">
            <label class="form-label" for="time_zone">Location / Time zone</label>
            <input type="text" id="time_zone" name="time_zone"
                   placeholder="e.g. Boston"
                   value="<?= htmlspecialchars((string)$timeZone, ENT_QUOTES, 'UTF-8'); ?>">
          </div>

        </div>
      </article>

      <!-- CARD 2: BODY STATS -->
      <article class="client-panel client-panel--body">
        <header class="client-panel-header">
          <div>
            <p class="client-panel-eyebrow">Body & stats</p>
            <h2 class="client-panel-title">Body measurements</h2>
          </div>
        </header>

        <div class="client-panel-body">

          <div class="form-row">
            <label class="form-label" for="gender">Gender (optional)</label>
            <select id="gender" name="gender">
              <option value="" <?= $gender === '' ? 'selected' : ''; ?>>Select</option>
              <option value="female" <?= $gender === 'female' ? 'selected' : ''; ?>>Female</option>
              <option value="male"   <?= $gender === 'male'   ? 'selected' : ''; ?>>Male</option>
              <option value="other"  <?= $gender === 'other'  ? 'selected' : ''; ?>>Other / Prefer not to say</option>
            </select>
          </div>

          <div class="form-row">
            <label class="form-label" for="date_of_birth">Date of birth (optional)</label>
            <input type="date" id="date_of_birth" name="date_of_birth"
                   value="<?= htmlspecialchars((string)$dateOfBirth, ENT_QUOTES, 'UTF-8'); ?>">
          </div>

          <div class="form-row">
            <label class="form-label" for="height_cm">Height (ft & cm, optional)</label>
            <select id="height_cm" name="height_cm">
              <option value="" <?= $heightCm === '' ? 'selected' : ''; ?>>Select</option>
              <?php for ($cm = 140; $cm <= 210; $cm++): ?>
                <option value="<?= (int)$cm; ?>" <?= (string)$heightCm === (string)$cm ? 'selected' : ''; ?>>
                  <?= htmlspecialchars((string)formatHeightImperialOption($cm), ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="form-row">
            <label class="form-label" for="sleep_average">Average sleep (hours)</label>
            <select id="sleep_average" name="sleep_average">
              <option value="" <?= $sleepAverage === '' ? 'selected' : ''; ?>>Select</option>
              <option value="less_than_5" <?= $sleepAverage === 'less_than_5' ? 'selected' : ''; ?>>Less than 5h</option>
              <option value="5-6"        <?= $sleepAverage === '5-6'        ? 'selected' : ''; ?>>5–6 hours</option>
              <option value="7-8"        <?= $sleepAverage === '7-8'        ? 'selected' : ''; ?>>7–8 hours</option>
              <option value="more_than_8"<?= $sleepAverage === 'more_than_8'? 'selected' : ''; ?>>More than 8h</option>
            </select>
          </div>

          <div class="form-row">
            <label class="form-label" for="stress_level">Stress level</label>
            <select id="stress_level" name="stress_level">
              <option value="" <?= $stressLevel === '' ? 'selected' : ''; ?>>Select</option>
              <option value="very_low"  <?= $stressLevel === 'very_low'  ? 'selected' : ''; ?>>Very low</option>
              <option value="low"       <?= $stressLevel === 'low'       ? 'selected' : ''; ?>>Low</option>
              <option value="medium"    <?= $stressLevel === 'medium'    ? 'selected' : ''; ?>>Medium</option>
              <option value="high"      <?= $stressLevel === 'high'      ? 'selected' : ''; ?>>High</option>
              <option value="very_high" <?= $stressLevel === 'very_high' ? 'selected' : ''; ?>>Very high</option>
            </select>
          </div>

          <div class="form-row">
            <label class="form-label" for="weight_lbs">Weight (lbs, optional)</label>
            <input type="number" step="0.1" id="weight_lbs" name="weight_lbs"
                   value="<?= htmlspecialchars((string)$weightLbs, ENT_QUOTES, 'UTF-8'); ?>">
          </div>

        </div>
      </article>

      <!-- CARD 3: TRAINING PROFILE -->
      <article class="client-panel client-panel--training">
        <header class="client-panel-header">
          <div>
            <p class="client-panel-eyebrow">Training</p>
            <h2 class="client-panel-title">Training profile</h2>
          </div>
        </header>

        <div class="client-panel-body">

          <div class="form-row">
            <label class="form-label" for="training_experience">Training experience</label>
            <select id="training_experience" name="training_experience">
              <option value="" <?= $trainingExperience === '' ? 'selected' : ''; ?>>Select</option>
              <option value="beginner"     <?= $trainingExperience === 'beginner'     ? 'selected' : ''; ?>>Beginner</option>
              <option value="intermediate" <?= $trainingExperience === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
              <option value="advanced"     <?= $trainingExperience === 'advanced'     ? 'selected' : ''; ?>>Advanced</option>
            </select>
          </div>

          <div class="form-row">
            <label class="form-label" for="training_frequency">Training frequency</label>
            <select id="training_frequency" name="training_frequency">
              <option value="" <?= $trainingFrequency === '' ? 'selected' : ''; ?>>Select</option>
              <option value="1-2" <?= $trainingFrequency === '1-2' ? 'selected' : ''; ?>>1–2 times/week</option>
              <option value="3"   <?= $trainingFrequency === '3'   ? 'selected' : ''; ?>>3 times/week</option>
              <option value="4-5" <?= $trainingFrequency === '4-5' ? 'selected' : ''; ?>>4–5 times/week</option>
              <option value="6+"  <?= $trainingFrequency === '6+'  ? 'selected' : ''; ?>>6+ times/week</option>
            </select>
          </div>

          <div class="form-row">
            <label class="form-label" for="training_availability">Training availability (days / times)</label>
            <input type="text" id="training_availability" name="training_availability"
                   placeholder="Example: Mon, Wed, Fri - evenings"
                   value="<?= htmlspecialchars((string)$trainingAvailability, ENT_QUOTES, 'UTF-8'); ?>">
          </div>

        </div>
      </article>

      <!-- CARD 4: HEALTH & NUTRITION -->
      <article class="client-panel client-panel--health">
        <header class="client-panel-header">
          <div>
            <p class="client-panel-eyebrow">Health & lifestyle</p>
            <h2 class="client-panel-title">Health & nutrition</h2>
          </div>
        </header>

        <div class="client-panel-body">

          <div class="form-row">
            <label class="form-label" for="injuries_limitations">Injuries / limitations</label>
            <textarea id="injuries_limitations" name="injuries_limitations" rows="3"
                      placeholder="Example: knee pain, shoulder injury, back issues, movement limitations..."><?= htmlspecialchars((string)$injuriesLimitations, ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>

          <div class="form-row">
            <label class="form-label" for="medical_conditions">Medical conditions / medications</label>
            <textarea id="medical_conditions" name="medical_conditions" rows="3"
                      placeholder="Example: hypertension, diabetes, medication names and schedules..."><?= htmlspecialchars((string)$medicalConditions, ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>

          <div class="form-row">
            <label class="form-label" for="hydration_level">Hydration level</label>
            <select id="hydration_level" name="hydration_level">
              <option value="" <?= $hydrationLevel === '' ? 'selected' : ''; ?>>Select</option>
              <option value="very_low"  <?= $hydrationLevel === 'very_low'  ? 'selected' : ''; ?>>Very low</option>
              <option value="low"       <?= $hydrationLevel === 'low'       ? 'selected' : ''; ?>>Low</option>
              <option value="medium"    <?= $hydrationLevel === 'medium'    ? 'selected' : ''; ?>>Medium</option>
              <option value="high"      <?= $hydrationLevel === 'high'      ? 'selected' : ''; ?>>High</option>
              <option value="very_high" <?= $hydrationLevel === 'very_high' ? 'selected' : ''; ?>>Very high</option>
            </select>
          </div>

          <div class="form-row">
            <label class="form-label" for="food_allergies">Food allergies / intolerances</label>
            <textarea id="food_allergies" name="food_allergies" rows="2"
                      placeholder="Example: lactose intolerance, gluten, nuts, seafood..."><?= htmlspecialchars((string)$foodAllergies, ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>

          <div class="form-row">
            <label class="form-label" for="dietary_preferences">Dietary preferences</label>
            <textarea id="dietary_preferences" name="dietary_preferences" rows="3"
                      placeholder="Example: vegetarian, vegan, no pork, low-carb, dislikes seafood..."><?= htmlspecialchars((string)$dietaryPreferences, ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>

        </div>
      </article>

      <!-- BOTÕES -->
      <div class="profile-form-actions">
        <a href="/dashboards/client_profile.php" class="btn-secondary">Back to profile</a>
        <button type="submit" class="btn-primary">Save profile</button>
      </div>

    </form>

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
