<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '1');

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo    = getPDO();
$userId = (int)($_SESSION['user_id'] ?? 0);

// Helper
function esc($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * CSRF token (para POST do form principal)
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

// ===============================
// EXCLUIR FOTO (GET) - mantém como estava (com checagem de dono)
// ===============================
if (isset($_GET['delete_photo'])) {
    $photoId = (int)$_GET['delete_photo'];
    if ($photoId > 0) {
        $stmtDel = $pdo->prepare("
            DELETE FROM coach_photos
            WHERE id = :id AND coach_id = :coach_id
        ");
        $stmtDel->execute([
            'id'       => $photoId,
            'coach_id' => $userId
        ]);
    }
    header('Location: personal_profile_edit.php');
    exit();
}

// ===============================
// CARREGAR DADOS DO USUÁRIO
// ===============================
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
// VARIÁVEIS INICIAIS
// ===============================
$name      = $userRow['name']       ?? (string)($_SESSION['user_name'] ?? 'Coach');
$email     = $userRow['email']      ?? '';
$avatarUrl = !empty($userRow['avatar_url'])
    ? (string)$userRow['avatar_url']
    : '/assets/images/default-avatar.png';

$phone      = $userRow['phone']      ?? '';
$timeZone   = $userRow['time_zone']  ?? '';
$languages  = $userRow['coach_languages'] ?? ($userRow['languages'] ?? '');

$professionalTitle   = $userRow['professional_title']   ?? '';
$shortBio            = $userRow['short_bio']            ?? '';
$fullBio             = $userRow['full_bio']             ?? '';

$certifications      = $userRow['certifications']       ?? '';
$experienceYears     = $userRow['experience_years']     ?? '';
$expertiseAreas      = $userRow['expertise_areas']      ?? ($userRow['coach_focus_areas'] ?? '');
$courses             = $userRow['courses']              ?? '';

$trainingExperience   = $userRow['training_experience']   ?? '';
$trainingAvailability = $userRow['training_availability'] ?? '';
$trainingFrequency    = $userRow['training_frequency']    ?? '';

$trainingPhilosophy  = $userRow['training_philosophy']  ?? '';
$customizationMethod = $userRow['customization_method'] ?? '';
$progressMethod      = $userRow['progress_method']      ?? '';
$availability        = $userRow['availability']         ?? $trainingAvailability;

$marketingHeadline   = $userRow['marketing_headline']   ?? '';
$idealClients        = $userRow['ideal_clients']        ?? '';
$preferredGoals      = $userRow['preferred_goals']      ?? '';
$uniqueSellingPoint  = $userRow['unique_selling_point'] ?? '';
$messageToNewClients = $userRow['message_to_new_clients'] ?? '';
$instagramHandle     = $userRow['instagram_handle']     ?? '';

$successMessage = '';
$errorMessage   = '';

// ===============================
// PROCESSAR POST (SALVAR PERFIL) + CSRF
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf_token, $postedToken)) {
        http_response_code(400);
        $errorMessage = 'Invalid CSRF token.';
    } else {
        // Campos texto
        $professionalTitle   = trim((string)($_POST['professional_title'] ?? ''));
        $marketingHeadline   = trim((string)($_POST['marketing_headline'] ?? ''));
        $shortBio            = trim((string)($_POST['short_bio'] ?? ''));
        $fullBio             = trim((string)($_POST['full_bio'] ?? ''));
        $timeZone            = trim((string)($_POST['time_zone'] ?? ''));
        $languages           = trim((string)($_POST['languages'] ?? ''));
        $phone               = trim((string)($_POST['phone'] ?? ''));
        $instagramHandle     = trim((string)($_POST['instagram_handle'] ?? ''));

        $experienceYears     = trim((string)($_POST['experience_years'] ?? ''));
        $trainingExperience  = trim((string)($_POST['training_experience'] ?? ''));
        $trainingFrequency   = trim((string)($_POST['training_frequency'] ?? ''));
        $expertiseAreas      = trim((string)($_POST['expertise_areas'] ?? ''));
        $preferredGoals      = trim((string)($_POST['preferred_goals'] ?? ''));
        $idealClients        = trim((string)($_POST['ideal_clients'] ?? ''));

        $certifications      = trim((string)($_POST['certifications'] ?? ''));
        $courses             = trim((string)($_POST['courses'] ?? ''));

        $trainingPhilosophy  = trim((string)($_POST['training_philosophy'] ?? ''));
        $customizationMethod = trim((string)($_POST['customization_method'] ?? ''));
        $progressMethod      = trim((string)($_POST['progress_method'] ?? ''));
        $availability        = trim((string)($_POST['availability'] ?? ''));
        $uniqueSellingPoint  = trim((string)($_POST['unique_selling_point'] ?? ''));
        $messageToNewClients = trim((string)($_POST['message_to_new_clients'] ?? ''));

        try {
            $pdo->beginTransaction();

            // Atualiza a tabela users
            $stmtUpdate = $pdo->prepare("
                UPDATE users
                SET
                  professional_title      = :professional_title,
                  marketing_headline      = :marketing_headline,
                  short_bio               = :short_bio,
                  full_bio                = :full_bio,
                  time_zone               = :time_zone,
                  coach_languages         = :coach_languages,
                  phone                   = :phone,
                  instagram_handle        = :instagram_handle,

                  experience_years        = :experience_years,
                  training_experience     = :training_experience,
                  training_frequency      = :training_frequency,
                  expertise_areas         = :expertise_areas,
                  preferred_goals         = :preferred_goals,
                  ideal_clients           = :ideal_clients,

                  certifications          = :certifications,
                  courses                 = :courses,

                  training_philosophy     = :training_philosophy,
                  customization_method    = :customization_method,
                  progress_method         = :progress_method,
                  availability            = :availability,
                  unique_selling_point    = :unique_selling_point,
                  message_to_new_clients  = :message_to_new_clients
                WHERE id = :id
            ");

            $stmtUpdate->execute([
                'professional_title'     => $professionalTitle,
                'marketing_headline'     => $marketingHeadline,
                'short_bio'              => $shortBio,
                'full_bio'               => $fullBio,
                'time_zone'              => $timeZone,
                'coach_languages'        => $languages,
                'phone'                  => $phone,
                'instagram_handle'       => $instagramHandle,

                'experience_years'       => $experienceYears,
                'training_experience'    => $trainingExperience,
                'training_frequency'     => $trainingFrequency,
                'expertise_areas'        => $expertiseAreas,
                'preferred_goals'        => $preferredGoals,
                'ideal_clients'          => $idealClients,

                'certifications'         => $certifications,
                'courses'                => $courses,

                'training_philosophy'    => $trainingPhilosophy,
                'customization_method'   => $customizationMethod,
                'progress_method'        => $progressMethod,
                'availability'           => $availability,
                'unique_selling_point'   => $uniqueSellingPoint,
                'message_to_new_clients' => $messageToNewClients,
                'id'                     => $userId
            ]);

            // ===============================
            // UPLOAD DE FOTO DE PORTFÓLIO (OPCIONAL)
            // ===============================
            if (!empty($_FILES['portfolio_photo']['name']) && ($_FILES['portfolio_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/coach_photos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $tmpName  = (string)($_FILES['portfolio_photo']['tmp_name'] ?? '');
                $origName = (string)($_FILES['portfolio_photo']['name'] ?? '');
                $ext      = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));

                // Extensões permitidas
                $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array($ext, $allowedExt, true)) {
                    $newFilename = 'coach_' . $userId . '_' . uniqid('', true) . '.' . $ext;
                    $destination = $uploadDir . $newFilename;

                    if (is_uploaded_file($tmpName) && move_uploaded_file($tmpName, $destination)) {
                        $relativePath = 'uploads/coach_photos/' . $newFilename;
                        $photoCaption = trim((string)($_POST['photo_caption'] ?? ''));

                        $stmtPhoto = $pdo->prepare("
                            INSERT INTO coach_photos (coach_id, photo_url, caption)
                            VALUES (:coach_id, :photo_url, :caption)
                        ");
                        $stmtPhoto->execute([
                            'coach_id'  => $userId,
                            'photo_url' => $relativePath,
                            'caption'   => $photoCaption
                        ]);
                    } else {
                        $errorMessage = 'Could not move uploaded file.';
                    }
                } else {
                    $errorMessage = 'Invalid image format. Please upload JPG, PNG or WEBP.';
                }
            }

            if ($errorMessage === '') {
                $pdo->commit();
                $successMessage = 'Your professional profile has been updated successfully.';
            } else {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'Error saving your profile: ' . $e->getMessage();
        }
    }
}

// ===============================
// CARREGAR FOTOS DO PORTFÓLIO
// ===============================
$stmtPhotos = $pdo->prepare("
    SELECT id, photo_url, caption
    FROM coach_photos
    WHERE coach_id = :id
    ORDER BY created_at DESC
");
$stmtPhotos->execute(['id' => $userId]);
$coachPhotos = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Edit Coach Profile | RB Personal Trainer</title>
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
  <link rel="stylesheet" href="/assets/css/personal_profile_edit.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">
</head>
<body>

<header id="rb-static-header" class="rbf1-header">
  <div class="rbf1-topbar">
    <a href="/" class="rbf1-logo">
      <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
    </a>

    <nav class="rbf1-nav" id="rbf1-nav">
      <ul>
        <li><a href="dashboard_personal.php">Dashboard</a></li>
        <li><a href="personal_profile.php">Profile</a></li>
        <li><a href="trainer_workouts.php">Workouts</a></li>
        <li><a href="trainer_checkins.php">Check-ins</a></li>
        <li><a href="messages.php">Messages</a></li>
        <li><a href="trainer_clients.php">Clients</a></li>

        <!-- Logout só no mobile -->
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

<main class="client-dashboard">
  <div class="client-shell">

    <!-- HERO -->
    <section class="client-hero client-hero--edit">
      <div class="client-hero-left">
        <div class="client-avatar-wrapper">
          <div class="client-avatar">
            <img src="<?= esc($avatarUrl); ?>" alt="Profile photo of <?= esc($name); ?>">
          </div>
        </div>
        <div class="client-hero-text">
          <p class="client-eyebrow">Edit coach profile</p>
          <h1 class="client-title">Shape your professional profile</h1>
          <p class="client-subtitle">
            These details will be used to present you to future clients inside RB Personal Trainer.
          </p>
        </div>
      </div>
    </section>

    <?php if ($successMessage): ?>
      <div class="client-alert client-alert--success">
        <?= esc($successMessage); ?>
      </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
      <div class="client-alert client-alert--error">
        <?= esc($errorMessage); ?>
      </div>
    <?php endif; ?>

    <!-- FORM SÓ PARA UPLOAD DA FOTO DE PERFIL DO COACH -->
    <form id="coach-avatar-form"
          action="client_avatar_upload.php"
          method="post"
          enctype="multipart/form-data">
      <!-- vazio de propósito: os inputs ficam dentro do card usando form="coach-avatar-form" -->
    </form>

    <!-- FORM PRINCIPAL DO PERFIL -->
    <form class="client-main-grid client-form-grid"
          method="post"
          action="personal_profile_edit.php"
          enctype="multipart/form-data">

      <input type="hidden" name="csrf_token" value="<?= esc($csrf_token); ?>">

      <!-- CARD 1: IDENTIDADE PROFISSIONAL -->
      <article class="client-panel client-panel--personal">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Professional identity</p>
          <h2 class="client-panel-title">How clients will see you</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">
          <!-- FOTO DE PERFIL DO COACH + UPLOAD -->
          <div class="form-row" style="margin-bottom: 1.2rem;">
            <div>
              <p class="form-label">Profile photo</p>
            </div>
            <div style="display:flex; align-items:center; gap:1.1rem; flex-wrap:wrap;">
              <div class="client-avatar-wrapper">
                <div class="client-avatar">
                  <img src="<?= esc($avatarUrl); ?>" alt="Profile photo of <?= esc($name); ?>">
                </div>
              </div>

              <div style="flex:1; min-width:220px;">
                <label class="form-label" for="coach_avatar" style="margin-bottom:0.35rem;">Upload new photo</label>
                <input
                  type="file"
                  id="coach_avatar"
                  name="avatar"
                  form="coach-avatar-form"
                  accept=".jpg,.jpeg,.png,.webp,image/*"
                >
                <p class="client-panel-text" style="margin-top:0.35rem; font-size:0.8rem; opacity:0.85;">
                  JPG, JPEG, PNG or WEBP. Max 2MB.
                  When you click “Update photo”, only the picture is updated.
                </p>

                <button type="submit"
                        class="btn-primary"
                        form="coach-avatar-form"
                        style="margin-top:0.4rem;">
                  Update photo
                </button>
              </div>
            </div>
          </div>

            <div class="form-row">
              <label class="form-label" for="professional_title">Professional title</label>
              <input
                type="text"
                id="professional_title"
                name="professional_title"
                class="form-input"
                placeholder="e.g. Certified Personal Trainer – NASM"
                value="<?= esc($professionalTitle); ?>">
            </div>

            <div class="form-row">
              <label class="form-label" for="marketing_headline">Marketing headline</label>
              <input
                type="text"
                id="marketing_headline"
                name="marketing_headline"
                class="form-input"
                placeholder="Helping busy professionals lose fat and gain strength"
                value="<?= esc($marketingHeadline); ?>">
            </div>

            <div class="form-row">
              <label class="form-label" for="short_bio">Short bio (headline)</label>
              <textarea
                id="short_bio"
                name="short_bio"
                class="form-textarea"
                rows="2"
                placeholder="One or two sentences that summarize you as a coach."><?= esc($shortBio); ?></textarea>
            </div>

            <div class="form-row">
              <label class="form-label" for="time_zone">Location / time zone</label>
              <input
                type="text"
                id="time_zone"
                name="time_zone"
                class="form-input"
                placeholder="e.g. Boston, MA (US Eastern)"
                value="<?= esc($timeZone); ?>">
            </div>

            <div class="form-row">
              <label class="form-label" for="languages">Languages</label>
              <input
                type="text"
                id="languages"
                name="languages"
                class="form-input"
                placeholder="e.g. English, Portuguese"
                value="<?= esc($languages); ?>">
            </div>

            <div class="form-row">
              <label class="form-label" for="phone">Phone (optional)</label>
              <input
                type="text"
                id="phone"
                name="phone"
                class="form-input"
                placeholder="Phone for client communication (optional)"
                value="<?= esc($phone); ?>">
            </div>

            <div class="form-row">
              <label class="form-label" for="instagram_handle">Instagram handle</label>
              <input
                type="text"
                id="instagram_handle"
                name="instagram_handle"
                class="form-input"
                placeholder="@rbpersonaltrainer or full URL"
                value="<?= esc($instagramHandle); ?>">
            </div>

          </div>
        </div>
      </article>

      <!-- CARD 2: EXPERIÊNCIA & ESPECIALIDADES -->
      <article class="client-panel client-panel--training">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Experience & specialties</p>
          <h2 class="client-panel-title">Who you help and how</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <label class="form-label" for="experience_years">Coaching experience (self-rating)</label>
              <input
                type="text"
                id="experience_years"
                name="experience_years"
                class="form-input"
                placeholder="e.g. Your experience on coaching"
                value="<?= esc($experienceYears); ?>">
            </div>

            <div class="form-row">
              <label class="form-label" for="training_experience">Years of experience</label>
              <select id="training_experience" name="training_experience" class="form-select">
                <option value="">Select...</option>
                <option value="beginner"     <?= $trainingExperience === 'beginner' ? 'selected' : ''; ?>>Up to 1 year</option>
                <option value="intermediate" <?= $trainingExperience === 'intermediate' ? 'selected' : ''; ?>>2–4 years</option>
                <option value="advanced"     <?= $trainingExperience === 'advanced' ? 'selected' : ''; ?>>5+ years</option>
              </select>
            </div>

            <div class="form-row">
              <label class="form-label" for="training_frequency">Typical weekly coaching load</label>
              <select id="training_frequency" name="training_frequency" class="form-select">
                <option value="">Select...</option>
                <option value="1-2" <?= $trainingFrequency === '1-2' ? 'selected' : ''; ?>>1–2 client sessions/day</option>
                <option value="3"   <?= $trainingFrequency === '3'   ? 'selected' : ''; ?>>3 client sessions/day</option>
                <option value="4-5" <?= $trainingFrequency === '4-5' ? 'selected' : ''; ?>>4–5 client sessions/day</option>
                <option value="6+"  <?= $trainingFrequency === '6+'  ? 'selected' : ''; ?>>6+ client sessions/day</option>
              </select>
            </div>

            <div class="form-row">
              <label class="form-label" for="preferred_goals">Main goals you work with</label>
              <textarea
                id="preferred_goals"
                name="preferred_goals"
                class="form-textarea"
                rows="3"
                placeholder="e.g. Fat loss, muscle gain, performance, posture, pain reduction"><?= esc($preferredGoals); ?></textarea>
            </div>

          </div>
        </div>
      </article>

      <!-- CARD 3: CREDENCIAIS & PORTFÓLIO -->
      <article class="client-panel client-panel--body">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Credentials & portfolio</p>
          <h2 class="client-panel-title">Certifications & work samples</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <label class="form-label" for="certifications">Main certifications</label>
              <textarea
                id="certifications"
                name="certifications"
                class="form-textarea"
                rows="3"
                placeholder="e.g. NASM CPT, ACE, ACSM, ISSA..."><?= esc($certifications); ?></textarea>
            </div>

            <div class="form-row">
              <label class="form-label" for="courses">Courses & special trainings</label>
              <textarea
                id="courses"
                name="courses"
                class="form-textarea"
                rows="4"
                placeholder="List relevant courses, workshops and advanced education."><?= esc($courses); ?></textarea>
            </div>

            <div class="form-row">
              <label class="form-label" for="portfolio_photo">Add a portfolio photo</label>
              <input
                type="file"
                id="portfolio_photo"
                name="portfolio_photo"
                class="form-input-file"
                accept=".jpg,.jpeg,.png,.webp">
              <p class="form-help">
                Upload a training session photo or a client result (only if you have their consent).
              </p>
            </div>

            <div class="form-row">
              <label class="form-label" for="photo_caption">Photo caption</label>
              <input
                type="text"
                id="photo_caption"
                name="photo_caption"
                class="form-input"
                placeholder="e.g. 6-month fat loss result, outdoor workout in Boston">
            </div>

            <?php if (!empty($coachPhotos)): ?>
              <div class="form-row">
                <p class="form-label">Current portfolio photos</p>
                <div class="coach-photo-grid">
                  <?php foreach ($coachPhotos as $photo): ?>
                    <div class="coach-photo-card">
                      <div class="coach-photo-thumb">
                        <img
                          src="<?= esc($photo['photo_url']); ?>"
                          alt="<?= !empty($photo['caption']) ? esc($photo['caption']) : ('Coach portfolio photo of ' . esc($name)); ?>">
                      </div>
                      <?php if (!empty($photo['caption'])): ?>
                        <p class="coach-photo-caption"><?= esc($photo['caption']); ?></p>
                      <?php endif; ?>
                      <a href="personal_profile_edit.php?delete_photo=<?= (int)$photo['id']; ?>"
                         class="coach-photo-delete"
                         onclick="return confirm('Delete this photo?');">
                        Remove
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>
      </article>

      <!-- CARD 4: ABORDAGEM & MENSAGEM -->
      <article class="client-panel client-panel--health">
        <header class="client-panel-header">
          <p class="client-panel-eyebrow">Approach & message</p>
          <h2 class="client-panel-title">How you work with clients</h2>
        </header>

        <div class="client-panel-body">
          <div class="profile-section-grid">

            <div class="form-row">
              <label class="form-label" for="training_philosophy">Training philosophy</label>
              <textarea
                id="training_philosophy"
                name="training_philosophy"
                class="form-textarea"
                rows="4"
                placeholder="Explain how you think about training, progression, and long-term results."><?= esc($trainingPhilosophy); ?></textarea>
            </div>

            <div class="form-row">
              <label class="form-label" for="progress_method">How you track progress</label>
              <textarea
                id="progress_method"
                name="progress_method"
                class="form-textarea"
                rows="3"
                placeholder="Check-ins, progress photos, metrics, app data, etc."><?= esc($progressMethod); ?></textarea>
            </div>

            <div class="form-row">
              <label class="form-label" for="availability">Availability / schedule</label>
              <textarea
                id="availability"
                name="availability"
                class="form-textarea"
                rows="2"
                placeholder="e.g. Mon–Fri mornings & evenings (US Eastern)"><?= esc($availability); ?></textarea>
            </div>

            <div class="form-row">
              <label class="form-label" for="unique_selling_point">Why work with you</label>
              <textarea
                id="unique_selling_point"
                name="unique_selling_point"
                class="form-textarea"
                rows="3"
                placeholder="What makes you different from other coaches?"><?= esc($uniqueSellingPoint); ?></textarea>
            </div>

            <div class="form-row">
              <label class="form-label" for="message_to_new_clients">Message to new clients</label>
              <textarea
                id="message_to_new_clients"
                name="message_to_new_clients"
                class="form-textarea"
                rows="3"
                placeholder="Write a short message to someone who is considering hiring you."><?= esc($messageToNewClients); ?></textarea>
            </div>

          </div>
        </div>

        <footer class="client-panel-footer client-panel-footer--actions">
          <button type="submit" class="client-primary-button">
            Save profile
          </button>
          <a href="personal_profile.php" class="client-secondary-link">
            Return
          </a>
        </footer>
      </article>

    </form>

  </div>
</main>

<footer class="site-footer">
      <!-- CTA SUPERIOR -->
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

  <!-- BLOCO PRINCIPAL -->
  <div class="footer-main">
    <!-- COLUNA BRAND -->
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

    <!-- COLUNA NAV (VERSÃO COACH) -->
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

    <!-- COLUNA LEGAL -->
    <div class="footer-col footer-legal">
      <h3 class="footer-heading">Legal</h3>
      <ul class="footer-legal-list">
        <li><a href="/privacy.html">Privacy Policy</a></li>
        <li><a href="/terms.html">Terms of Use</a></li>
        <li><a href="/cookies.html">Cookie Policy</a></li>
      </ul>
    </div>

    <!-- COLUNA CONTACT -->
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

  <!-- BARRA INFERIOR -->
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
