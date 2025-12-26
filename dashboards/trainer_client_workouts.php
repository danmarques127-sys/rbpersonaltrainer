<?php
// trainer_client_workouts.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

/**
 * Security headers (best-effort; adjust CSP if you later add external CDNs)
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
// If you already send CSP elsewhere, remove this to avoid conflicts.
// header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");

/**
 * CSRF token (para deletar plano)
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

/**
 * 2) Pega o client_id da URL
 */
$client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
if (!$client_id || $client_id <= 0) {
    http_response_code(400);
    echo 'Client not specified.';
    exit;
}

/**
 * 3) Carrega dados do cliente
 *  - REMOVIDO EMAIL do SELECT para evitar exposição por acidente (logs, dumps, var_dump, etc.)
 */
$sql = "
    SELECT id, name, avatar_url
    FROM users
    WHERE id = ?
      AND role IN ('user', 'client')
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    http_response_code(404);
    echo 'Client not found.';
    exit;
}

/**
 * (Opcional / Best-effort) Tentativa de validar vínculo coach <-> client.
 * - Se existir tabela coach_clients, valida por ela.
 * - Se não existir, tenta validar por workout_plans (created_by = coach).
 * - Se não conseguir validar por nada, NÃO BLOQUEIA (para não quebrar seu fluxo atual),
 *   mas você pode trocar $enforceAssociation para true quando tiver a tabela pronta.
 */
$enforceAssociation = false; // <- mude para true quando sua regra de vínculo estiver definida

$isAssociated = null;

// 3A) tenta coach_clients
try {
    $sqlAssoc = "SELECT 1 FROM coach_clients WHERE coach_id = ? AND client_id = ? LIMIT 1";
    $stAssoc = $pdo->prepare($sqlAssoc);
    $stAssoc->execute([$current_user_id, $client_id]);
    $isAssociated = (bool)$stAssoc->fetchColumn();
} catch (Throwable $e) {
    // tabela pode não existir; ignora
}

// 3B) tenta workout_plans
if ($isAssociated === null || $isAssociated === false) {
    try {
        $sqlAssoc2 = "SELECT 1 FROM workout_plans WHERE user_id = ? AND created_by = ? LIMIT 1";
        $stAssoc2 = $pdo->prepare($sqlAssoc2);
        $stAssoc2->execute([$client_id, $current_user_id]);
        $isAssociated2 = (bool)$stAssoc2->fetchColumn();

        if ($isAssociated === null) {
            $isAssociated = $isAssociated2;
        } else {
            $isAssociated = ($isAssociated || $isAssociated2);
        }
    } catch (Throwable $e) {
        // ignora
    }
}

if ($enforceAssociation && !$isAssociated) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

/**
 * (NOVO) Deletar plano (apenas se for do coach atual e do client atual)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_plan') {

    // CSRF check
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $plan_id = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
    if ($plan_id > 0) {
        // Deleta somente se o plano pertence a este client e foi criado por este coach
        $del = $pdo->prepare("
            DELETE FROM workout_plans
            WHERE id = ?
              AND user_id = ?
              AND created_by = ?
            LIMIT 1
        ");
        $del->execute([$plan_id, $client_id, $current_user_id]);
    }

    // volta para a mesma tela
    header('Location: trainer_client_workouts.php?client_id=' . (int)$client_id);
    exit;
}

/**
 * 4) Fotos de progresso recentes do cliente
 */
$sqlPhotos = "
    SELECT
        file_path,
        taken_at,
        weight_kg,
        pose,
        notes,
        created_at
    FROM progress_photos
    WHERE user_id = ?
    ORDER BY taken_at DESC, created_at DESC
    LIMIT 4
";
$stmt = $pdo->prepare($sqlPhotos);
$stmt->execute([$client_id]);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/**
 * 5) Planos de treino do cliente criados por ESTE coach
 */
$sqlPlans = "
    SELECT
        wp.id,
        wp.name,
        wp.description,
        wp.status,
        wp.weeks_total,
        wp.created_at
    FROM workout_plans wp
    WHERE wp.user_id   = :client_id
      AND wp.created_by = :coach_id
    ORDER BY (wp.status = 'active') DESC,
             wp.created_at DESC
";
$stmt = $pdo->prepare($sqlPlans);
$stmt->execute([
    ':client_id' => $client_id,
    ':coach_id'  => $current_user_id,
]);
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/**
 * 6) Preview de exercícios por plano (best-effort)
 * Tenta ler workout_plan_exercises e montar até 3 itens por plano.
 * Se a tabela não existir, segue normal.
 */
$exercisePreviewByPlan = []; // [plan_id => [items...]]
if (!empty($plans)) {
    $planIds = array_values(array_unique(array_map(static fn($p) => (int)($p['id'] ?? 0), $plans)));
    $planIds = array_filter($planIds, static fn($id) => $id > 0);

    if (!empty($planIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($planIds), '?'));

            // Ajuste os nomes de colunas conforme seu schema real.
            $sqlEx = "
                SELECT
                    plan_id,
                    exercise_name,
                    sets,
                    reps
                FROM workout_plan_exercises
                WHERE plan_id IN ($placeholders)
                ORDER BY plan_id ASC, id ASC
            ";
            $stEx = $pdo->prepare($sqlEx);
            $stEx->execute($planIds);
            $rows = $stEx->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $r) {
                $pid = (int)($r['plan_id'] ?? 0);
                if ($pid <= 0) continue;

                if (!isset($exercisePreviewByPlan[$pid])) {
                    $exercisePreviewByPlan[$pid] = [];
                }

                // Limita a 3 por plano
                if (count($exercisePreviewByPlan[$pid]) >= 3) {
                    continue;
                }

                $exercisePreviewByPlan[$pid][] = [
                    'name' => (string)($r['exercise_name'] ?? ''),
                    'sets' => $r['sets'] ?? null,
                    'reps' => $r['reps'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            // tabela/colunas podem não existir; segue sem preview
            $exercisePreviewByPlan = [];
        }
    }
}

// Escapes úteis
$clientNameRaw = (string)($client['name'] ?? '');
$clientNameEsc = htmlspecialchars($clientNameRaw, ENT_QUOTES, 'UTF-8');
$clientIdEsc   = (int)($client['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Client Workouts (Coach View) | RB Personal Trainer</title>
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

    <!-- Reaproveita o mesmo visual -->
    <link rel="stylesheet" href="/assets/css/global.css">
    <link rel="stylesheet" href="/assets/css/trainer_client_workouts.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">

    <style>
      /* Ajustes leves só para essa tela */

      .coach-client-hub-title {
          font-size: 18px;
          font-weight: 600;
          color: var(--rb-text-main);
          margin: 0 0 4px;
      }

      .coach-client-hub-sub {
          font-size: 13px;
          color: var(--rb-text-muted);
          margin: 0;
      }

      .coach-client-header-row {
          display: flex;
          justify-content: space-between;
          gap: 16px;
          align-items: flex-start;
          margin-bottom: 18px;
      }

      .coach-client-info-left {
          display: flex;
          gap: 12px;
          align-items: center;
      }

      .coach-client-avatar {
          width: 56px;
          height: 56px;
          border-radius: 50%;
          object-fit: cover;
          border: 1px solid rgba(148, 163, 184, 0.45);
      }

      .coach-client-name {
          font-size: 16px;
          font-weight: 600;
          color: var(--rb-text-main);
      }

      /* REMOVIDO: .coach-client-email (não exibiremos e-mail) */

      .coach-client-meta {
          display: flex;
          gap: 8px;
          margin-top: 4px;
          font-size: 11px;
          color: var(--rb-text-label);
      }

      .coach-client-actions {
          display: flex;
          flex-direction: column;
          gap: 6px;
          align-items: flex-end;
      }

      .coach-section-title {
          margin-top: 10px;
          margin-bottom: 4px;
          font-size: 13px;
          font-weight: 600;
          color: var(--rb-text-main);
      }

      .coach-section-sub {
          font-size: 11px;
          color: var(--rb-text-muted);
          margin-bottom: 8px;
      }

      .tw-btn-small {
          font-size: 12px;
          padding: 5px 12px;
      }

      .tw-btn-secondary {
          border: 1px solid var(--rb-border-soft);
          background: var(--rb-bg-card-soft);
          color: var(--rb-text-main);
      }

      .tw-btn-secondary:hover {
          background: rgba(31, 41, 55, 0.98);
      }

      .tw-plan-actions-coach {
          display: flex;
          gap: 8px;
          margin-top: 10px;
          flex-wrap: wrap;
      }

      .tw-client-link {
          color: var(--rb-accent-strong);
          text-decoration: none;
          border-bottom: 1px dotted rgba(255, 145, 0, 0.7);
      }

      .tw-client-link:hover {
          color: #ffb454;
      }

      /* Preview de exercícios dentro do card */
      .wk-plan-preview {
          margin-top: 10px;
          padding-top: 10px;
          border-top: 1px solid rgba(148, 163, 184, 0.20);
      }

      .wk-plan-preview-title {
          font-size: 11px;
          font-weight: 600;
          color: var(--rb-text-label);
          margin-bottom: 6px;
      }

      .wk-plan-preview-list {
          margin: 0;
          padding-left: 18px;
          font-size: 12px;
          color: var(--rb-text-main);
      }

      .wk-plan-preview-list li {
          margin: 4px 0;
          line-height: 1.2;
      }

      .wk-plan-preview-meta {
          font-size: 11px;
          color: var(--rb-text-muted);
      }

      /* (NOVO) Botão de deletar plano */
      .wk-btn-danger {
          border: 1px solid rgba(248, 113, 113, 0.55);
          background: rgba(248, 113, 113, 0.10);
          color: rgba(255, 255, 255, 0.92);
          cursor: pointer;
      }
      .wk-btn-danger:hover {
          background: rgba(248, 113, 113, 0.16);
          border-color: rgba(248, 113, 113, 0.75);
      }

      @media (max-width: 900px) {
          .coach-client-header-row {
              flex-direction: column;
              align-items: flex-start;
          }

          .coach-client-actions {
              align-items: flex-start;
          }
      }
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
                <li><a href="dashboard_personal.php" class="rbf1-link">Dashboard</a></li>
                <li><a href="personal_profile.php" class="rbf1-link">Profile</a></li>
                <li><a href="trainer_workouts.php" class="rbf1-link rbf1-link-active">Workouts</a></li>
                <li><a href="trainer_checkins.php" class="rbf1-link">Check-ins</a></li>
                <li><a href="messages.php" class="rbf1-link">Messages</a></li>
                <li><a href="trainer_clients.php" class="rbf1-link">Clients</a></li>

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

<main class="coach-dashboard">
    <div class="coach-shell">

        <section class="wk-container">

            <!-- Cabeçalho: cliente + contexto -->
            <div class="coach-client-header-row">
                <div class="coach-client-info-left">
                    <?php
                      $avatarRaw = (string)($client['avatar_url'] ?? '');
                      $fallbackAvatar = '/assets/images/default-avatar.png';
                      $avatarSrc = $avatarRaw !== '' ? $avatarRaw : $fallbackAvatar;
                    ?>
                    <img
                        src="<?php echo htmlspecialchars($avatarSrc, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo 'Profile photo of ' . $clientNameEsc; ?>"
                        class="coach-client-avatar"
                    >
                    <div>
                        <div class="coach-client-name">
                            <?php echo $clientNameEsc; ?>
                        </div>

                        <div class="coach-client-meta">
                            <span>Client workout area (coach view)</span>
                        </div>
                    </div>
                </div>

                <div class="coach-client-actions">
                    <a href="progress_gallery.php?client_id=<?php echo $clientIdEsc; ?>"
                       class="wk-btn-secondary tw-btn-small">
                        View full progress gallery
                    </a>
                    <a href="trainer_workout_plan_edit.php?client_id=<?php echo $clientIdEsc; ?>&mode=new"
                       class="wk-btn-primary tw-btn-small">
                        + Create workout plan
                    </a>
                </div>
            </div>

            <!-- FOTOS DE PROGRESSO -->
            <div class="coach-section">
                <h2 class="coach-section-title">Recent progress photos</h2>
                <p class="coach-section-sub">
                    Last check-in pictures uploaded by this client.
                </p>

                <?php if (empty($photos)): ?>
                    <p class="wk-empty">This client has no progress photos yet.</p>
                <?php else: ?>
                    <div class="wk-photo-grid">
                        <?php foreach ($photos as $ph): ?>
                            <?php
                              $photoPathRaw = (string)($ph['file_path'] ?? '');
                              $photoPathEsc = htmlspecialchars($photoPathRaw, ENT_QUOTES, 'UTF-8');
                              $photoAlt = $clientNameRaw !== '' ? ('Progress photo of ' . $clientNameEsc) : 'Progress photo';

                              if (!empty($ph['taken_at'])) {
                                  $photoDateOut = date('d/m/Y', strtotime((string)$ph['taken_at']));
                              } else {
                                  $photoDateOut = date('d/m/Y', strtotime((string)$ph['created_at']));
                              }

                              $poseOut = ucfirst((string)($ph['pose'] ?? ''));
                              $weightOut = !empty($ph['weight_kg']) ? number_format((float)$ph['weight_kg'], 1) . ' kg' : '';
                              $notesOut = (string)($ph['notes'] ?? '');
                            ?>
                            <div class="wk-photo-card">
                                <div class="wk-photo-thumb-wrapper">
                                    <img src="<?php echo $photoPathEsc; ?>" alt="<?php echo $photoAlt; ?>">
                                </div>
                                <div class="wk-photo-info">
                                    <div class="wk-photo-date">
                                        <?php echo htmlspecialchars($photoDateOut, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="wk-photo-meta">
                                        <span><?php echo htmlspecialchars($poseOut, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ($weightOut !== ''): ?>
                                            <span><?php echo htmlspecialchars($weightOut, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($notesOut !== ''): ?>
                                        <div class="wk-photo-notes">
                                            <?php echo nl2br(htmlspecialchars($notesOut, ENT_QUOTES, 'UTF-8')); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PLANOS DE TREINO -->
            <div class="coach-section" style="margin-top: 18px;">
                <h2 class="coach-section-title">Workout plans for this client</h2>
                <p class="coach-section-sub">
                    All training programs you’ve created specifically for this client.
                </p>

                <?php if (empty($plans)): ?>
                    <p class="wk-empty">
                        You have not created any workout plans for this client yet.
                        Use the button above to create the first one.
                    </p>
                <?php else: ?>
                    <div class="wk-plan-grid">
                        <?php foreach ($plans as $plan): ?>
                            <?php
                                $pid = (int)($plan['id'] ?? 0);
                                $preview = $pid > 0 && isset($exercisePreviewByPlan[$pid]) ? $exercisePreviewByPlan[$pid] : [];

                                $planNameOut = (string)($plan['name'] ?? '');
                                $planNameOut = $planNameOut !== '' ? $planNameOut : 'Untitled plan';

                                $statusRaw   = (string)($plan['status'] ?? 'inactive');
                                $statusClass = preg_replace('/[^a-z0-9_-]/i', '', strtolower($statusRaw)) ?: 'inactive';
                                $statusLabel = ucfirst($statusClass);

                                $weeksOut = !empty($plan['weeks_total']) ? (int)$plan['weeks_total'] : null;

                                $createdOut = '';
                                if (!empty($plan['created_at'])) {
                                    $createdOut = date('M d, Y', strtotime((string)$plan['created_at']));
                                }
                            ?>
                            <div class="wk-plan-card">
                                <div class="wk-plan-header">
                                    <div class="wk-plan-name">
                                        <?php echo htmlspecialchars($planNameOut, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <span class="wk-plan-status wk-plan-status-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </div>

                                <?php if (!empty($plan['description'])): ?>
                                    <div class="wk-plan-desc">
                                        <?php echo nl2br(htmlspecialchars((string)$plan['description'], ENT_QUOTES, 'UTF-8')); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="wk-plan-meta">
                                    <?php if ($weeksOut !== null): ?>
                                        <span><?php echo (int)$weeksOut; ?> weeks</span>
                                    <?php endif; ?>
                                    <?php if ($createdOut !== ''): ?>
                                        <span>Created: <?php echo htmlspecialchars($createdOut, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>

                                <!-- PREVIEW DE EXERCÍCIOS (até 3) -->
                                <?php if (!empty($preview)): ?>
                                    <div class="wk-plan-preview">
                                        <div class="wk-plan-preview-title">Preview (some exercises)</div>
                                        <ul class="wk-plan-preview-list">
                                            <?php foreach ($preview as $ex): ?>
                                                <?php
                                                  $exName = (string)($ex['name'] ?? '');
                                                  $sets = $ex['sets'];
                                                  $reps = $ex['reps'];

                                                  $metaParts = [];
                                                  if ($sets !== null && $sets !== '') $metaParts[] = (int)$sets . ' sets';
                                                  if ($reps !== null && $reps !== '') $metaParts[] = htmlspecialchars((string)$reps, ENT_QUOTES, 'UTF-8') . ' reps';
                                                ?>
                                                <li>
                                                    <?php echo htmlspecialchars($exName, ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if (!empty($metaParts)): ?>
                                                        <span class="wk-plan-preview-meta">(<?php echo implode(' · ', $metaParts); ?>)</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <div class="tw-plan-actions-coach">
                                    <a href="trainer_workout_plan_edit.php?plan_id=<?php echo (int)($plan['id'] ?? 0); ?>&client_id=<?php echo $clientIdEsc; ?>"
                                       class="wk-btn-secondary tw-btn-small">
                                        Edit plan
                                    </a>

                                    <!-- Deletar plano -->
                                    <form method="post" action="trainer_client_workouts.php?client_id=<?php echo $clientIdEsc; ?>" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="delete_plan">
                                        <input type="hidden" name="plan_id" value="<?php echo (int)($plan['id'] ?? 0); ?>">
                                        <button type="submit"
                                                class="wk-btn-secondary tw-btn-small wk-btn-danger"
                                                onclick="return confirm('Delete this workout plan? This cannot be undone.');">
                                            Delete plan
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </section>

    </div>
</main>

<footer class="site-footer">
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

</body>
</html>
