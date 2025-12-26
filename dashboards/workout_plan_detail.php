<?php
// workout_plan_detail.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'pro', 'admin']); // ajuste se quiser restringir só "user"
$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role            = (string)($_SESSION['role'] ?? '');

// 1) Carrega usuário atual
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

$current_user_name = (string)($current_user['name'] ?? '');

// 2) Pega o plano pela URL
$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
if ($plan_id <= 0) {
    header('Location: client_workouts.php?tab=programs');
    exit;
}

// 3) Carrega o plano, garantindo que pertence ao cliente logado
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
    WHERE wp.id = ?
      AND wp.user_id = ?
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$plan_id, $current_user_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    http_response_code(404);
    echo 'Training plan not found or you don\'t have access to it.';
    exit;
}

// 4) Carrega as sessões do plano
$sql = "
    SELECT
        id,
        title,
        day_of_week,
        session_label,
        notes,
        order_index
    FROM workout_sessions
    WHERE plan_id = ?
    ORDER BY order_index ASC, id ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$plan_id]);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// 4.1) Carrega os exercícios de todas as sessões de uma vez
$sessionExercises = [];

if (!empty($sessions)) {
    $sessionIds = array_map(static fn($s) => (int)$s['id'], $sessions);
    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));

    $sqlEx = "
        SELECT
            id,
            session_id,
            exercise_name,
            sets,
            reps,
            target_rpe,
            rest_seconds,
            order_index
        FROM workout_exercises
        WHERE session_id IN ($placeholders)
        ORDER BY session_id ASC, order_index ASC, id ASC
    ";

    $stmtEx = $pdo->prepare($sqlEx);
    $stmtEx->execute($sessionIds);
    $rowsEx = $stmtEx->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rowsEx as $ex) {
        $sid = (int)$ex['session_id'];
        if (!isset($sessionExercises[$sid])) {
            $sessionExercises[$sid] = [];
        }
        $sessionExercises[$sid][] = $ex;
    }
}

// 5) Para cada sessão, pega o último log (se existir)
$session_logs = [];
if (!empty($sessions)) {
    $stmtLog = $pdo->prepare("
        SELECT performed_at, status
        FROM workout_logs
        WHERE user_id = ?
          AND session_id = ?
        ORDER BY performed_at DESC
        LIMIT 1
    ");

    foreach ($sessions as $s) {
        $sid = (int)$s['id'];
        $stmtLog->execute([$current_user_id, $sid]);
        $log = $stmtLog->fetch(PDO::FETCH_ASSOC);
        $session_logs[$sid] = $log ?: null;
    }
}

// 6) Presets de exercícios (para descrição + vídeo no modal)
// include seguro: se não existir, continua com array vazio
$exercisePresets = [];
$presetsFile = __DIR__ . '/exercise_presets.php';

if (file_exists($presetsFile)) {
    $loaded = require $presetsFile; // CAPTURA o retorno do arquivo
    if (is_array($loaded)) {
        $exercisePresets = $loaded;
    }
}

/**
 * Helper para dia da semana
 */
function day_of_week_label(?int $d): string
{
    if ($d === null) return '';

    $map = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
    return $map[$d] ?? '';
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$plan_name        = (string)($plan['name'] ?? '');
$plan_desc        = (string)($plan['description'] ?? '');
$plan_status      = (string)($plan['status'] ?? '');
$plan_weeks_total = (int)($plan['weeks_total'] ?? 0);
$coach_name       = (string)($plan['coach_name'] ?? '');
$avatar_url       = (string)($current_user['avatar_url'] ?? '');

// Avatar fallback (padronizado para /assets/)
$avatar_src = $avatar_url !== '' ? $avatar_url : '/assets/img/default-avatar.png';
$avatar_alt = $current_user_name !== '' ? ('Profile photo of ' . $current_user_name) : 'Client profile photo';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Workout Plan - <?php echo e($plan_name); ?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

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
    <link rel="stylesheet" href="/assets/css/workout_plan_detail.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">
</head>
<body>

<!-- HEADER (fora do shell) -->
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

<div class="wk-container">

    <!-- Cabeçalho do usuário -->
    <div class="wk-user-header">
        <img src="<?php echo e($avatar_src); ?>" alt="<?php echo e($avatar_alt); ?>">
        <div class="wk-user-info">
            <div class="wk-user-name"><?php echo e($current_user_name); ?></div>
            <div class="wk-user-subtitle">Workout plan</div>
        </div>
    </div>

    <!-- Cabeçalho do plano -->
    <div class="wk-plan-detail-header">
        <div>
            <div class="wk-plan-detail-title">
                <?php echo e($plan_name); ?>
            </div>
            <div class="wk-plan-detail-meta">
                <?php if ($coach_name !== ''): ?>
                    <span>Coach: <?php echo e($coach_name); ?></span>
                <?php endif; ?>
                <?php if ($plan_weeks_total > 0): ?>
                    <span><?php echo (int)$plan_weeks_total; ?> weeks</span>
                <?php endif; ?>
                <?php if ($plan_status !== ''): ?>
                    <span>Status: <?php echo e(ucfirst($plan_status)); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="wk-plan-detail-actions">
            <a href="client_workouts.php?tab=programs" class="wk-btn-secondary">
                ← Back to programs
            </a>
        </div>
    </div>

    <?php if ($plan_desc !== ''): ?>
        <div class="wk-plan-detail-desc">
            <?php echo nl2br(e($plan_desc)); ?>
        </div>
    <?php endif; ?>

    <!-- Lista de sessões + exercícios -->
    <div class="wk-session-list">
        <?php if (empty($sessions)): ?>
            <p class="wk-empty">Your coach hasn’t added sessions to this plan yet.</p>
        <?php else: ?>
            <?php foreach ($sessions as $s): ?>
                <?php
                    $sid = (int)($s['id'] ?? 0);
                    $log = $session_logs[$sid] ?? null;

                    $last_label   = 'No logs yet.';
                    $status_class = 'wk-session-status-none';

                    if ($log) {
                        $performed_at = (string)($log['performed_at'] ?? '');
                        $status       = (string)($log['status'] ?? '');

                        $date = '';
                        if ($performed_at !== '') {
                            $ts = strtotime($performed_at);
                            if ($ts !== false) {
                                $date = date('d/m/Y', $ts);
                            }
                        }

                        if ($status === 'completed') {
                            $status_class = 'wk-session-status-completed';
                            $last_label   = ($date !== '' ? "Last: {$date} · Completed" : "Last: Completed");
                        } elseif ($status === 'missed') {
                            $status_class = 'wk-session-status-missed';
                            $last_label   = ($date !== '' ? "Last: {$date} · Missed" : "Last: Missed");
                        } elseif ($status === 'partial') {
                            $status_class = 'wk-session-status-partial';
                            $last_label   = ($date !== '' ? "Last: {$date} · Partial" : "Last: Partial");
                        }
                    }

                    $dow = ($s['day_of_week'] !== null)
                        ? day_of_week_label((int)$s['day_of_week'])
                        : '';

                    $exList = $sessionExercises[$sid] ?? [];
                    $session_title = (string)($s['title'] ?? '');
                    $session_label = (string)($s['session_label'] ?? '');
                    $session_notes = (string)($s['notes'] ?? '');
                ?>
                <div class="wk-session-card">
                    <div class="wk-session-main">
                        <div class="wk-session-title-row">
                            <div class="wk-session-title">
                                <?php echo e($session_title); ?>
                            </div>
                            <?php if ($session_label !== ''): ?>
                                <span class="wk-session-label">
                                    <?php echo e($session_label); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="wk-session-meta">
                            <?php if ($dow !== ''): ?>
                                <span><?php echo e($dow); ?></span>
                            <?php endif; ?>
                            <span class="wk-session-status <?php echo e($status_class); ?>">
                                <?php echo e($last_label); ?>
                            </span>
                        </div>

                        <?php if ($session_notes !== ''): ?>
                            <div class="wk-session-notes">
                                <?php echo nl2br(e($session_notes)); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Lista de exercícios da sessão -->
                        <?php if (!empty($exList)): ?>
                            <ul class="wk-exercise-list">
                                <?php foreach ($exList as $ex): ?>
                                    <?php
                                        $exercise_name = (string)($ex['exercise_name'] ?? '');
                                        $parts = [];

                                        if (!empty($ex['sets'])) {
                                            $sets = (int)$ex['sets'];
                                            $reps = trim((string)($ex['reps'] ?? ''));
                                            if ($reps !== '') {
                                                $parts[] = $sets . ' x ' . $reps;
                                            } else {
                                                $parts[] = $sets . ' sets';
                                            }
                                        } elseif (!empty($ex['reps'])) {
                                            $parts[] = (string)$ex['reps'];
                                        }

                                        if (!empty($ex['target_rpe'])) {
                                            $parts[] = 'RPE ' . (int)$ex['target_rpe'];
                                        }

                                        if (!empty($ex['rest_seconds'])) {
                                            $parts[] = 'Rest ' . (int)$ex['rest_seconds'] . 's';
                                        }

                                        $metaText = implode(' · ', $parts);
                                    ?>
                                    <li class="wk-exercise-item">
                                        <button type="button"
                                                class="wk-exercise-name js-ex-info"
                                                data-ex-name="<?php echo e($exercise_name); ?>">
                                            <?php echo e($exercise_name); ?>
                                        </button>
                                        <?php if ($metaText !== ''): ?>
                                            <span class="wk-exercise-meta">
                                                <?php echo e($metaText); ?>
                                            </span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="wk-exercise-empty">
                                No exercises added to this session yet.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="wk-session-actions">
                        <a href="workout_session.php?session_id=<?php echo (int)$sid; ?>"
                           class="wk-btn-primary">
                            Start workout
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- MODAL DE DETALHES DO EXERCÍCIO -->
<div class="wk-ex-modal-backdrop" id="ex-modal-backdrop">
    <div class="wk-ex-modal" role="dialog" aria-modal="true" aria-labelledby="ex-modal-title">
        <div class="wk-ex-modal-header">
            <div class="wk-ex-modal-title" id="ex-modal-title">Exercise details</div>
            <button type="button" class="wk-ex-modal-close" id="ex-modal-close" aria-label="Close">✕</button>
        </div>
        <div class="wk-ex-modal-body">
            <div class="wk-ex-tagline" id="ex-modal-tagline"></div>
            <div id="ex-modal-description"></div>
            <div class="wk-ex-modal-video" id="ex-modal-video" style="display: none;">
                <iframe id="ex-modal-iframe" src="" allowfullscreen></iframe>
            </div>
        </div>
    </div>
</div>

<script>
  // Mobile menu toggle (mantém IDs exigidos: rbf1-toggle, rbf1-nav)
  (function () {
    const btn = document.getElementById('rbf1-toggle');
    const nav = document.getElementById('rbf1-nav');
    if (!btn || !nav) return;

    btn.addEventListener('click', function () {
      const expanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', String(!expanded));
      nav.classList.toggle('is-open', !expanded);
    });
  })();
</script>

<script>
  // Presets vindos do PHP
  const EXERCISE_PRESETS = <?php
      echo json_encode($exercisePresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  ?>;

  function findExercisePresetByName(name) {
    if (!name) return null;
    const q = name.trim().toLowerCase();
    if (!q) return null;
    return EXERCISE_PRESETS.find(e => (e.name || '').toLowerCase() === q) || null;
  }

  document.addEventListener('DOMContentLoaded', function () {
    const backdrop = document.getElementById('ex-modal-backdrop');
    const closeBtn = document.getElementById('ex-modal-close');
    const titleEl  = document.getElementById('ex-modal-title');
    const tagline  = document.getElementById('ex-modal-tagline');
    const descEl   = document.getElementById('ex-modal-description');
    const videoBox = document.getElementById('ex-modal-video');
    const iframe   = document.getElementById('ex-modal-iframe');

    function openExerciseModal(exerciseName) {
      const name = (exerciseName || '').trim();
      if (!name) {
        titleEl.textContent  = 'Exercise details';
        tagline.textContent  = 'No exercise selected.';
        descEl.textContent   = 'Tap on an exercise from your workout to see more information.';
        videoBox.style.display = 'none';
        if (iframe) iframe.src = '';
        backdrop.style.display = 'flex';
        return;
      }

      const preset = findExercisePresetByName(name);
      titleEl.textContent = name;

      if (!preset) {
        tagline.textContent = 'No extra information saved yet for this exercise.';
        descEl.textContent  = 'Your coach can later connect this exercise to the library with description and demo videos.';
        videoBox.style.display = 'none';
        if (iframe) iframe.src = '';
      } else {
        const bodyPart = preset.body_part || 'Body part: N/A';
        const category = preset.category ? ' • ' + preset.category : '';
        const muscles  = preset.primary_muscles ? ' • Muscles: ' + preset.primary_muscles : '';
        tagline.textContent = bodyPart + category + muscles;

        descEl.textContent  = preset.description || 'Basic preset entry with default description.';

        if (preset.youtube_url) {
          let videoUrl = preset.youtube_url.trim();

          // Se vier link normal do YouTube, converte para /embed/
          if (videoUrl.includes("watch?v=")) {
            const vid = videoUrl.split("watch?v=")[1].split("&")[0];
            videoUrl = "https://www.youtube.com/embed/" + vid;
          }

          videoBox.style.display = 'block';
          iframe.src = videoUrl;
        } else {
          videoBox.style.display = 'none';
          if (iframe) iframe.src = '';
        }
      }

      backdrop.style.display = 'flex';
    }

    function closeModal() {
      backdrop.style.display = 'none';
      if (iframe) iframe.src = '';
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) {
      backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) closeModal();
      });
    }

    // Delegação: qualquer elemento com .js-ex-info abre o modal
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.js-ex-info');
      if (!btn) return;
      const exName = btn.getAttribute('data-ex-name') || btn.textContent;
      openExerciseModal(exName);
    });

    window.openExerciseModal = openExerciseModal;
  });
</script>
<!-- FOOTER (corrigido: sem footer duplicado) -->
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
