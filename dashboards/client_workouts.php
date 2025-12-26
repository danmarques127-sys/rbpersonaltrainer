<?php
// client_workouts.php
declare(strict_types=1);

// ======================================
// BOOTSTRAP CENTRAL (session + auth + PDO)
// ======================================
require_once __DIR__ . '/../core/bootstrap.php';

/**
 * Segurança: somente logado + roles permitidos
 * (liberado para user/client e para pro (PT/Nutri))
 */
require_login();
require_role(['user', 'client', 'pro']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_role    = (string)($_SESSION['role'] ?? '');

/**
 * ======================================
 * AUTO-CLEANUP (sem CRON): apaga logs com 15+ dias
 * - roda no máximo 1x a cada 12h por sessão
 * - não mexe em mais nada do arquivo
 * ======================================
 */
$now = time();
$last_cleanup = (int)($_SESSION['checkin_cleanup_last'] ?? 0);

if ($last_cleanup === 0 || ($now - $last_cleanup) > (12 * 60 * 60)) {
    $stmt = $pdo->prepare("
        DELETE FROM workout_logs
        WHERE performed_at IS NOT NULL
          AND performed_at < (NOW() - INTERVAL 15 DAY)
    ");
    $stmt->execute();

    $_SESSION['checkin_cleanup_last'] = $now;
}

/**
 * Se for PRO, restringe para personal_trainer / nutritionist
 * specialty pode estar na sessão ou no banco
 */
if ($current_role === 'pro') {
    $specialty = $_SESSION['specialty'] ?? null;

    if ($specialty === null) {
        $stmt = $pdo->prepare("SELECT specialty FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$current_user_id]);
        $specialty = (string)($stmt->fetchColumn() ?: '');
        $_SESSION['specialty'] = $specialty; // opcional: cache na sessão
    }

    if (!in_array($specialty, ['personal_trainer', 'nutritionist'], true)) {
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Asset normalizer (fix relative paths inside /dashboards/)
 * Accepts:
 *  - empty => placeholder
 *  - /assets/...           => keep
 *  - assets/...            => /assets/...
 *  - /dashboards/uploads/... => keep
 *  - dashboards/uploads/...  => /dashboards/uploads/...
 *  - /uploads/... or uploads/... => /dashboards/uploads/...
 * Security:
 *  - blocks http(s)
 *  - blocks any ".."
 */
function asset_src(?string $url, string $placeholder): string
{
    $url = trim((string)$url);
    if ($url === '') return $placeholder;

    // remove invisíveis e normaliza barras
    $url = str_replace(["\0", "\r", "\n"], '', $url);
    $url = str_replace('\\', '/', $url);

    // bloqueia URL externa
    if (preg_match('#^https?://#i', $url)) return $placeholder;

    // bloqueia path traversal
    if (strpos($url, '..') !== false) return $placeholder;

    // normaliza caso venha sem barra inicial
    // 1) /assets/...
    if (preg_match('#^/assets/#', $url)) {
        return $url;
    }
    // 2) assets/...
    if (preg_match('#^assets/#', $url)) {
        return '/' . $url;
    }

    // 3) /dashboards/uploads/...
    if (preg_match('#^/dashboards/uploads/#', $url)) {
        return $url;
    }
    // 4) dashboards/uploads/...
    if (preg_match('#^dashboards/uploads/#', $url)) {
        return '/' . $url;
    }

    // 5) /uploads/...  => /dashboards/uploads/...
    if (preg_match('#^/uploads/#', $url)) {
        return '/dashboards' . $url;
    }
    // 6) uploads/...   => /dashboards/uploads/...
    if (preg_match('#^uploads/#', $url)) {
        return '/dashboards/' . $url;
    }

    // se chegar aqui, não reconhece => placeholder
    return $placeholder;
}

function formatDateBR(?string $date): string
{
    if (!$date) return '--';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '--';
}

// 1) Carrega dados do usuário logado
$stmt = $pdo->prepare("
    SELECT id, name, role, avatar_url
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

// Avatar seguro (padroniza fallback)
$avatarFallback = '/assets/images/client-avatar-placeholder.jpg';
$photoFallback  = '/assets/images/client-avatar-placeholder.jpg';

$avatarSrc = asset_src(
    $current_user['avatar_url'] ?? '',
    $avatarFallback
);

// 2) Aba selecionada
$allowed_tabs = ['programs', 'checkins', 'photos'];
$tab = (isset($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs, true))
    ? (string)$_GET['tab']
    : 'programs';

// 3) Resumo da semana

// 3.1 Quantos planos ativos
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total_plans
    FROM workout_plans
    WHERE user_id = ? AND status = 'active'
");
$stmt->execute([$current_user_id]);
$rowPlans = $stmt->fetch(PDO::FETCH_ASSOC);
$total_plans = $rowPlans ? (int)$rowPlans['total_plans'] : 0;

// 3.2 Último log de treino
$stmt = $pdo->prepare("
    SELECT performed_at, status
    FROM workout_logs
    WHERE user_id = ?
    ORDER BY performed_at DESC
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$last_log = $stmt->fetch(PDO::FETCH_ASSOC);

$last_workout_date   = $last_log ? formatDateBR($last_log['performed_at'] ?? null) : null;
$last_workout_status = $last_log ? ($last_log['status'] ?? null) : null;

// 3.3 Workouts completados na semana atual
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS completed_this_week
    FROM workout_logs
    WHERE user_id = ?
      AND status = 'completed'
      AND YEARWEEK(performed_at, 1) = YEARWEEK(CURDATE(), 1)
");
$stmt->execute([$current_user_id]);
$weekRow = $stmt->fetch(PDO::FETCH_ASSOC);
$completed_this_week = $weekRow ? (int)$weekRow['completed_this_week'] : 0;

// 3.4 Última foto de progresso
$stmt = $pdo->prepare("
    SELECT file_path, taken_at, created_at
    FROM progress_photos
    WHERE user_id = ?
    ORDER BY taken_at DESC, created_at DESC
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$last_photo = $stmt->fetch(PDO::FETCH_ASSOC);

$lastPhotoSrc = asset_src(
    $last_photo['file_path'] ?? '',
    $photoFallback
);

// 4) Conteúdo das abas

// 4.1 Programs
$plans = [];
if ($tab === 'programs') {
    $sql = "
        SELECT 
            wp.id,
            wp.name,
            wp.description,
            wp.status,
            wp.weeks_total,
            wp.created_at,
            u.name AS coach_name
        FROM workout_plans wp
        LEFT JOIN users u ON u.id = wp.created_by
        WHERE wp.user_id = ?
        ORDER BY (wp.status = 'active') DESC, wp.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id]);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4.2 Check-ins (últimos 60)
$logs_by_week = [];
if ($tab === 'checkins') {
    $sql = "
        SELECT
            id,
            session_id,
            performed_at,
            status,
            difficulty,
            mood,
            notes
        FROM workout_logs
        WHERE user_id = ?
        ORDER BY performed_at DESC
        LIMIT 60
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as $log) {
        $performed = $log['performed_at'] ?? '';
        $weekNumber = (int)date('W', strtotime($performed));
        $year       = (int)date('o', strtotime($performed));
        $weekKey    = $year . '-W' . str_pad((string)$weekNumber, 2, '0', STR_PAD_LEFT);

        if (!isset($logs_by_week[$weekKey])) {
            $logs_by_week[$weekKey] = [
                'label' => "Week {$weekNumber} · {$year}",
                'items' => [],
            ];
        }

        $logs_by_week[$weekKey]['items'][] = $log;
    }
}

// 4.3 Photos (últimas 4)
$photos = [];
$photos_lightbox = [];
if ($tab === 'photos') {
    $sql = "
        SELECT
            id,
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
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($photos as $ph) {
        $src = asset_src($ph['file_path'] ?? '', $photoFallback);
        $date = !empty($ph['taken_at']) ? $ph['taken_at'] : ($ph['created_at'] ?? null);
        $pose = trim((string)($ph['pose'] ?? ''));
        $poseLabel = $pose !== '' ? ucfirst($pose) : '—';
        $w = (!empty($ph['weight_kg'])) ? (number_format((float)$ph['weight_kg'], 1) . ' kg') : '';
        $captionParts = array_filter([
            formatDateBR((string)$date),
            $poseLabel,
            $w,
        ], fn($x) => (string)$x !== '');
        $caption = implode(' · ', $captionParts);

        $photos_lightbox[] = [
            'src' => $src,
            'caption' => $caption,
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Client Workouts | RB Personal Trainer | Rafa Breder Coaching</title>
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
  <link rel="stylesheet" href="/assets/css/client_workouts.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">

  <style>
    /* LIGHTBOX */
    .rb-lightbox {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      z-index: 9999;
    }
    .rb-lightbox.rb-open { display: flex; }

    .rb-lightbox-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(0,0,0,0.78);
      backdrop-filter: blur(2px);
    }

    .rb-lightbox-panel {
      position: relative;
      width: min(1000px, 96vw);
      max-height: 92vh;
      border-radius: 14px;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,0.10);
      background: rgba(10,10,10,0.92);
      box-shadow: 0 20px 60px rgba(0,0,0,0.65);
      z-index: 2;
      display: flex;
      flex-direction: column;
    }

    .rb-lightbox-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 10px 12px;
      border-bottom: 1px solid rgba(255,255,255,0.10);
    }

    .rb-lightbox-caption {
      font-size: 12px;
      color: rgba(255,255,255,0.75);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 75%;
    }

    .rb-lightbox-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .rb-lb-btn {
      appearance: none;
      border: 1px solid rgba(255,255,255,0.18);
      background: rgba(255,255,255,0.06);
      color: rgba(255,255,255,0.90);
      border-radius: 10px;
      padding: 8px 10px;
      cursor: pointer;
      font-size: 13px;
      line-height: 1;
    }
    .rb-lb-btn:hover { background: rgba(255,255,255,0.10); }
    .rb-lb-btn:disabled { opacity: 0.45; cursor: not-allowed; }

    .rb-lightbox-body {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 14px;
      min-height: 240px;
    }

    .rb-lightbox-img {
      max-width: 100%;
      max-height: 76vh;
      width: auto;
      height: auto;
      object-fit: contain;
      border-radius: 12px;
      user-select: none;
      -webkit-user-drag: none;
      background: rgba(255,255,255,0.03);
    }

    .rb-lightbox-nav {
      position: absolute;
      inset: 0;
      pointer-events: none;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 8px;
    }
    .rb-lightbox-nav .rb-lb-btn {
      pointer-events: auto;
      border-radius: 999px;
      padding: 10px 12px;
      font-size: 16px;
    }

    .wk-photo-thumb-wrapper img {
      cursor: zoom-in;
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
        <li><a href="dashboard_client.php">Dashboard</a></li>
        <li><a href="client_profile.php">Profile</a></li>
        <li><a href="client_goals.php">Goals</a></li>
        <li><a href="messages.php">Messages</a></li>
        <li><a href="client_workouts.php" class="rbf1-link rbf1-link-active">Workout</a></li>
        <li><a href="client_nutrition.php">Nutritionist</a></li>
        <li><a href="progress_gallery.php">Photos Gallery</a></li>

        <!-- Logout mantém login.php como você pediu -->
        <li class="mobile-only">
          <a href="../login.php" class="rb-mobile-logout">Logout</a>
        </li>
      </ul>
    </nav>

    <div class="rbf1-right">
      <a href="../login.php" class="rbf1-login">Logout</a>
    </div>

    <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">☰</button>
  </div>
</header>

<div class="wk-container">

  <!-- Cabeçalho com usuário -->
  <div class="wk-user-header">
    <img
      src="<?php echo htmlspecialchars($avatarSrc, ENT_QUOTES, 'UTF-8'); ?>"
      alt="Client profile photo"
      onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($avatarFallback, ENT_QUOTES, 'UTF-8'); ?>';"
    >
    <div class="wk-user-info">
      <div class="wk-user-name"><?php echo htmlspecialchars((string)($current_user['name'] ?? 'Client'), ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="wk-user-subtitle">Workout area</div>
    </div>
  </div>

  <!-- Card resumo da semana -->
  <div class="wk-summary-grid">
    <div class="wk-summary-card">
      <div class="wk-summary-title">Active plans</div>
      <div class="wk-summary-value"><?php echo (int)$total_plans; ?></div>
      <div class="wk-summary-footer">Training programs assigned by your coach.</div>
    </div>

    <div class="wk-summary-card">
      <div class="wk-summary-title">Workouts this week</div>
      <div class="wk-summary-value"><?php echo (int)$completed_this_week; ?></div>
      <div class="wk-summary-footer">Completed sessions (this week).</div>
    </div>

    <div class="wk-summary-card">
      <div class="wk-summary-title">Last workout</div>
      <div class="wk-summary-value"><?php echo htmlspecialchars(($last_workout_date ?: '--'), ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="wk-summary-footer">
        <?php
          if ($last_workout_status === 'completed') {
              echo 'Status: Completed';
          } elseif ($last_workout_status === 'missed') {
              echo 'Status: Missed';
          } elseif ($last_workout_status === 'partial') {
              echo 'Status: Partial';
          } else {
              echo 'No workout logs yet.';
          }
        ?>
      </div>
    </div>

    <div class="wk-summary-card wk-summary-photo">
      <div class="wk-summary-title">Last progress photo</div>

      <?php if ($last_photo && !empty($last_photo['file_path'])): ?>
        <div class="wk-photo-thumb-wrapper">
          <img
            src="<?php echo htmlspecialchars($lastPhotoSrc, ENT_QUOTES, 'UTF-8'); ?>"
            alt="Last progress photo"
            class="js-lb-open"
            data-lb-index="0"
            onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($photoFallback, ENT_QUOTES, 'UTF-8'); ?>';"
          >
        </div>
        <div class="wk-summary-footer">
          <?php
            $date = $last_photo['taken_at'] ?? $last_photo['created_at'] ?? null;
            echo $date ? ('Taken at: ' . htmlspecialchars(formatDateBR((string)$date), ENT_QUOTES, 'UTF-8')) : 'Recently uploaded.';
          ?>
        </div>
      <?php else: ?>
        <div class="wk-summary-value wk-summary-value-small">No photos</div>
        <div class="wk-summary-footer">Upload your first progress photo.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tabs -->
  <div class="wk-tabs">
    <a href="client_workouts.php?tab=programs" class="wk-tab-link <?php echo $tab === 'programs' ? 'active' : ''; ?>">Programs</a>
    <a href="client_workouts.php?tab=checkins" class="wk-tab-link <?php echo $tab === 'checkins' ? 'active' : ''; ?>">Check-ins</a>
    <a href="client_workouts.php?tab=photos" class="wk-tab-link <?php echo $tab === 'photos' ? 'active' : ''; ?>">Photos</a>
  </div>

  <!-- Conteúdo -->
  <div class="wk-tab-content">

    <?php if ($tab === 'programs'): ?>

      <?php if (empty($plans)): ?>
        <p class="wk-empty">No training programs assigned yet. Your coach will set them up for you.</p>
      <?php else: ?>
        <div class="wk-plan-grid">
          <?php foreach ($plans as $plan): ?>
            <?php
              $status = (string)($plan['status'] ?? '');
              $statusClass = preg_replace('/[^a-z_]/i', '', $status);
            ?>
            <div class="wk-plan-card">
              <div class="wk-plan-header">
                <div class="wk-plan-name"><?php echo htmlspecialchars((string)($plan['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                <span class="wk-plan-status wk-plan-status-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                </span>
              </div>

              <?php if (!empty($plan['description'])): ?>
                <div class="wk-plan-desc"><?php echo nl2br(htmlspecialchars((string)$plan['description'], ENT_QUOTES, 'UTF-8')); ?></div>
              <?php endif; ?>

              <div class="wk-plan-meta">
                <?php if (!empty($plan['coach_name'])): ?>
                  <span>Coach: <?php echo htmlspecialchars((string)$plan['coach_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if (!empty($plan['weeks_total'])): ?>
                  <span><?php echo (int)$plan['weeks_total']; ?> weeks</span>
                <?php endif; ?>
              </div>

              <div class="wk-plan-actions">
                <a href="workout_plan_detail.php?plan_id=<?php echo (int)$plan['id']; ?>" class="wk-btn-primary">
                  View sessions
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'checkins'): ?>

      <?php if (empty($logs_by_week)): ?>
        <p class="wk-empty">No workout check-ins yet. Complete a workout to see your history here.</p>
      <?php else: ?>
        <?php foreach ($logs_by_week as $week_data): ?>
          <div class="wk-week-block">
            <div class="wk-week-header">
              <div class="wk-week-title"><?php echo htmlspecialchars((string)$week_data['label'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <div class="wk-week-body">
              <?php foreach ($week_data['items'] as $log): ?>
                <?php
                  $performed = (string)($log['performed_at'] ?? '');
                  $d = $performed ? date('d/m', strtotime($performed)) : '--';
                  $status = (string)($log['status'] ?? '');
                  $statusClass = preg_replace('/[^a-z_]/i', '', $status);

                  $difficulty = $log['difficulty'] ?? null;
                  $mood       = $log['mood'] ?? null;
                ?>

                <div class="wk-checkin-row">
                  <div class="wk-checkin-date"><?php echo htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></div>

                  <div class="wk-checkin-status wk-status-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                  </div>

                  <div class="wk-checkin-meta">
                    <?php if ($difficulty !== null && $difficulty !== ''): ?>
                      <span>Difficulty: <?php echo (int)$difficulty; ?>/10</span>
                    <?php endif; ?>
                    <?php if ($mood !== null && $mood !== ''): ?>
                      <span>Mood: <?php echo (int)$mood; ?>/5</span>
                    <?php endif; ?>
                  </div>

                  <?php if (!empty($log['notes'])): ?>
                    <div class="wk-checkin-notes"><?php echo nl2br(htmlspecialchars((string)$log['notes'], ENT_QUOTES, 'UTF-8')); ?></div>
                  <?php endif; ?>
                </div>

              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($tab === 'photos'): ?>

      <div class="wk-photos-header">
        <p>View a snapshot of your recent progress. For full history, open the gallery.</p>
        <div style="display:flex; gap:8px;">
          <a href="progress_gallery.php" class="wk-btn-secondary">Open gallery</a>
          <a href="workout_photo_upload.php" class="wk-btn-primary">Upload progress</a>
        </div>
      </div>

      <?php if (empty($photos)): ?>
        <p class="wk-empty">No progress photos yet. Upload your first check-in.</p>
      <?php else: ?>
        <div class="wk-photo-grid">
          <?php foreach ($photos as $idx => $ph): ?>
            <?php
              $src = asset_src($ph['file_path'] ?? '', $photoFallback);
              $date = !empty($ph['taken_at']) ? $ph['taken_at'] : ($ph['created_at'] ?? null);

              $pose = trim((string)($ph['pose'] ?? ''));
              $poseLabel = $pose !== '' ? ucfirst($pose) : '—';
            ?>
            <div class="wk-photo-card">
              <div class="wk-photo-thumb-wrapper">
                <img
                  src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>"
                  alt="Client progress photo"
                  class="js-lb-open"
                  data-lb-index="<?php echo (int)$idx; ?>"
                  onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($photoFallback, ENT_QUOTES, 'UTF-8'); ?>';"
                >
              </div>

              <div class="wk-photo-info">
                <div class="wk-photo-date"><?php echo htmlspecialchars(formatDateBR((string)$date), ENT_QUOTES, 'UTF-8'); ?></div>

                <div class="wk-photo-meta">
                  <span><?php echo htmlspecialchars($poseLabel, ENT_QUOTES, 'UTF-8'); ?></span>

                  <?php if (!empty($ph['weight_kg'])): ?>
                    <span><?php echo htmlspecialchars(number_format((float)$ph['weight_kg'], 1), ENT_QUOTES, 'UTF-8'); ?> kg</span>
                  <?php endif; ?>
                </div>

                <?php if (!empty($ph['notes'])): ?>
                  <div class="wk-photo-notes"><?php echo nl2br(htmlspecialchars((string)$ph['notes'], ENT_QUOTES, 'UTF-8')); ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div><!-- .wk-tab-content -->

</div><!-- .wk-container -->

<!-- LIGHTBOX (modal) -->
<div class="rb-lightbox" id="rbLightbox" aria-hidden="true">
  <div class="rb-lightbox-backdrop" id="rbLbBackdrop"></div>

  <div class="rb-lightbox-panel" role="dialog" aria-modal="true" aria-label="Photo viewer">
    <div class="rb-lightbox-topbar">
      <div class="rb-lightbox-caption" id="rbLbCaption">Photo</div>
      <div class="rb-lightbox-actions">
        <button type="button" class="rb-lb-btn" id="rbLbPrev" aria-label="Previous photo">←</button>
        <button type="button" class="rb-lb-btn" id="rbLbNext" aria-label="Next photo">→</button>
        <button type="button" class="rb-lb-btn" id="rbLbClose" aria-label="Close">✕</button>
      </div>
    </div>

    <div class="rb-lightbox-body">
      <div class="rb-lightbox-nav">
        <button type="button" class="rb-lb-btn" id="rbLbPrev2" aria-label="Previous photo">‹</button>
        <button type="button" class="rb-lb-btn" id="rbLbNext2" aria-label="Next photo">›</button>
      </div>

      <img src="" alt="Selected progress photo" class="rb-lightbox-img" id="rbLbImg">
    </div>
  </div>
</div>

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

<script>
  (function () {
    const LB_DATA = <?php echo json_encode($photos_lightbox, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    const lb = document.getElementById('rbLightbox');
    const backdrop = document.getElementById('rbLbBackdrop');
    const img = document.getElementById('rbLbImg');
    const caption = document.getElementById('rbLbCaption');

    const btnClose = document.getElementById('rbLbClose');
    const btnPrev = document.getElementById('rbLbPrev');
    const btnNext = document.getElementById('rbLbNext');
    const btnPrev2 = document.getElementById('rbLbPrev2');
    const btnNext2 = document.getElementById('rbLbNext2');

    let currentIndex = 0;

    function clampIndex(i) {
      if (!LB_DATA || LB_DATA.length === 0) return 0;
      if (i < 0) return 0;
      if (i >= LB_DATA.length) return LB_DATA.length - 1;
      return i;
    }

    function renderButtons() {
      const hasMany = LB_DATA && LB_DATA.length > 1;
      const atStart = currentIndex <= 0;
      const atEnd = currentIndex >= (LB_DATA.length - 1);

      [btnPrev, btnPrev2].forEach(b => b.disabled = !hasMany || atStart);
      [btnNext, btnNext2].forEach(b => b.disabled = !hasMany || atEnd);
    }

    function openLightbox(index) {
      if (!LB_DATA || LB_DATA.length === 0) return;

      currentIndex = clampIndex(index);
      const item = LB_DATA[currentIndex];

      img.src = item.src || '';
      caption.textContent = item.caption || 'Photo';

      lb.classList.add('rb-open');
      lb.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';

      renderButtons();
    }

    function closeLightbox() {
      lb.classList.remove('rb-open');
      lb.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      img.src = '';
      caption.textContent = 'Photo';
    }

    function prev() { openLightbox(currentIndex - 1); }
    function next() { openLightbox(currentIndex + 1); }

    document.addEventListener('click', function (e) {
      const el = e.target;
      if (!el) return;

      if (el.classList && el.classList.contains('js-lb-open')) {
        const idx = parseInt(el.getAttribute('data-lb-index') || '0', 10);
        openLightbox(isNaN(idx) ? 0 : idx);
      }
    });

    backdrop.addEventListener('click', closeLightbox);
    btnClose.addEventListener('click', closeLightbox);

    btnPrev.addEventListener('click', prev);
    btnPrev2.addEventListener('click', prev);
    btnNext.addEventListener('click', next);
    btnNext2.addEventListener('click', next);

    document.addEventListener('keydown', function (e) {
      if (!lb.classList.contains('rb-open')) return;

      if (e.key === 'Escape') {
        e.preventDefault();
        closeLightbox();
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        prev();
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        next();
      }
    });
  })();
</script>

</body>
</html>
