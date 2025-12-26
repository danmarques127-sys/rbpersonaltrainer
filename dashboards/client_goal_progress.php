<?php
declare(strict_types=1);

// ===============================
// goal_client_view.php  (ou client_goal_progress.php)
// Client goal details + inline edit (AJUSTADO p/ auxiliares funcionarem)
// ===============================

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client']);

$pdo    = getPDO();
$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = (string)($_SESSION['role'] ?? '');

// ===============================
// CSRF (precisa existir p/ forms auxiliares)
// ===============================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

// ===============================
// UNREAD MESSAGES COUNT (HEADER BADGE)
// ===============================
$unread_inbox_count = 0;

try {
    $stmtUnread = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM mail_recipients r
        INNER JOIN mail_messages m ON m.id = r.message_id
        WHERE r.recipient_id = ?
          AND r.folder = 'inbox'
          AND r.is_read = 0
          AND (m.thread_id IS NULL OR m.id = m.thread_id)
    ");
    $stmtUnread->execute([$userId]);
    $unread_inbox_count = (int)($stmtUnread->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $unread_inbox_count = 0;
}

// ===============================
// GET goal_id
// ===============================
$goalId = isset($_GET['goal_id']) ? (int)$_GET['goal_id'] : 0;
if ($goalId <= 0) {
    redirect('/dashboards/client_goals.php?error=' . urlencode('Invalid goal.'));
}

// ===============================
// LOAD GOAL
// ===============================
$sqlGoal = "
    SELECT 
        id,
        client_id,
        title,
        description,
        target_value,
        current_value,
        unit,
        status,
        created_at,
        updated_at
    FROM client_goals
    WHERE id = :goal_id
    LIMIT 1
";
$stmtGoal = $pdo->prepare($sqlGoal);
$stmtGoal->execute([':goal_id' => $goalId]);
$goal = $stmtGoal->fetch(PDO::FETCH_ASSOC);

if (!$goal) {
    redirect('/dashboards/client_goals.php?error=' . urlencode('Goal not found.'));
}

// Ownership (user/client só pode ver o seu)
if (in_array($role, ['user', 'client'], true) && (int)$goal['client_id'] !== $userId) {
    redirect('/dashboards/client_goals.php?error=' . urlencode('You cannot access this goal.'));
}

$targetValue  = $goal['target_value'] !== null ? (float)$goal['target_value'] : null;
$currentValue = $goal['current_value'] !== null ? (float)$goal['current_value'] : 0.0;
$unit         = $goal['unit'] !== null ? (string)$goal['unit'] : '';
$status       = (string)($goal['status'] ?? 'pending');

// Calculate % if target exists
$percent = null;
if ($targetValue !== null && $targetValue > 0) {
    $percent = ($currentValue / $targetValue) * 100.0;
    if ($percent > 100) $percent = 100;
}

// ===============================
// LOAD PROGRESS HISTORY
// ===============================
$sqlHist = "
    SELECT 
        id,
        progress_value,
        note,
        created_at
    FROM client_goal_progress
    WHERE goal_id = :goal_id
    ORDER BY created_at DESC
";
$stmtHist = $pdo->prepare($sqlHist);
$stmtHist->execute([':goal_id' => $goalId]);
$progressList = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// HELPERS
// ===============================
function h($str): string
{
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function renderStatusLabel(string $status): string
{
    switch ($status) {
        case 'completed':
            return '<span class="status-badge status-completed">Completed</span>';
        case 'in_progress':
            return '<span class="status-badge status-in-progress">In progress</span>';
        default:
            return '<span class="status-badge status-pending">Pending</span>';
    }
}

// Format created_at for <input type="date">
$createdDateValue = '';
if (!empty($goal['created_at'])) {
    $ts = strtotime((string)$goal['created_at']);
    if ($ts !== false) $createdDateValue = date('Y-m-d', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Client Goals | RB Personal Trainer | Rafa Breder Coaching</title>
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

    <!-- CSS base -->
    <link rel="stylesheet" href="/assets/css/global.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">

    <!-- Specific CSS -->
    <link rel="stylesheet" href="/assets/css/client_goal_progress.css">
</head>

<body class="client-dashboard">

<header id="rb-static-header" class="rbf1-header">
  <div class="rbf1-topbar">
    <a href="/" class="rbf1-logo">
      <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
    </a>

    <nav class="rbf1-nav" id="rbf1-nav">
      <ul>
        <li><a href="/dashboards/dashboard_client.php">Dashboard</a></li>
        <li><a href="/dashboards/client_profile.php">Profile</a></li>
        <li><a href="/dashboards/client_goals.php" class="rbf1-link rbf1-link-active">Goals</a></li>

        <li>
          <a href="/dashboards/messages.php">
            Messages
            <?php if ($unread_inbox_count > 0): ?>
              <span class="msg-nav-badge"><?= (int)$unread_inbox_count ?></span>
            <?php endif; ?>
          </a>
        </li>

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

<main class="goal-detail-main">
  <div class="client-shell">
    <div class="page-wrapper">

      <div class="page-header">
        <div class="page-title-area">
          <div class="page-title">Goal Details</div>
          <div class="page-subtitle">Track your progress, log updates, and manage this goal.</div>
        </div>

        <a href="/dashboards/client_goals.php" class="back-link">
          <span>←</span>
          <span>Back to goals</span>
        </a>
      </div>

      <div class="layout-grid">

        <!-- LEFT COLUMN -->
        <div class="card">

          <div class="goal-header">
            <div class="goal-title-row">
              <div class="goal-title"><?= h($goal['title']) ?></div>
              <div><?= renderStatusLabel($status) ?></div>
            </div>

            <?php if (!empty($goal['description'])): ?>
              <div class="goal-description">
                <?= nl2br(h($goal['description'])) ?>
              </div>
            <?php endif; ?>

            <div class="goal-meta-row">
              <div class="meta-pill">
                Current: <strong><?= number_format((float)$currentValue, 2, ',', '.') . ' ' . h($unit) ?></strong>
              </div>

              <?php if ($targetValue !== null && $targetValue > 0): ?>
                <div class="meta-pill">
                  Target: <strong><?= number_format((float)$targetValue, 2, ',', '.') . ' ' . h($unit) ?></strong>
                </div>
              <?php endif; ?>

              <div class="meta-pill">
                Created on: <?= !empty($goal['created_at']) ? h(date('m/d/Y', strtotime((string)$goal['created_at']))) : '-' ?>
              </div>
            </div>
          </div>

          <?php if ($percent !== null): ?>
            <div class="progress-section">
              <div class="progress-bar-wrapper">
                <div class="progress-bar-fill" style="width: <?= (float)$percent ?>%;"></div>
              </div>
              <div class="progress-label-row">
                <span><?= number_format((float)$percent, 1, ',', '.') ?>% completed</span>
                <span>
                  <?= number_format((float)$currentValue, 2, ',', '.') . ' / ' . number_format((float)$targetValue, 2, ',', '.') . ' ' . h($unit) ?>
                </span>
              </div>
            </div>
          <?php endif; ?>

          <div class="actions-row">

            <?php if ($status !== 'completed'): ?>
              <form action="/dashboards/goal_client_complete.php" method="post"
                    onsubmit="return confirm('Confirm goal completion?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
                <button type="submit" class="btn btn-accent">
                  <span class="btn-icon">✅</span>
                  <span>Complete goal</span>
                </button>
              </form>
            <?php endif; ?>

            <form action="/dashboards/goal_client_delete.php" method="post"
                  onsubmit="return confirm('Are you sure you want to delete this goal and all progress history?');">
              <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
              <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
              <button type="submit" class="btn btn-danger">
                <span class="btn-icon">🗑️</span>
                <span>Delete goal</span>
              </button>
            </form>

          </div>

          <!-- INLINE EDIT PANEL -->
          <div class="edit-goal-panel" id="edit-goal-panel">
            <div class="edit-goal-header">
              <div class="edit-goal-title">Edit goal details</div>
              <div class="edit-goal-subtitle">Adjust the goal name, description, units, target and dates.</div>
            </div>

            <form action="/dashboards/client_goal_update.php" method="post" class="edit-goal-form">
              <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
              <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">

              <div class="field-group">
                <label for="goal_title" class="field-label">Goal name</label>
                <input
                  type="text"
                  id="goal_title"
                  name="title"
                  class="field-input"
                  value="<?= h($goal['title']) ?>"
                  required
                >
              </div>

              <div class="field-group">
                <label for="goal_description" class="field-label">Description</label>
                <textarea
                  id="goal_description"
                  name="description"
                  class="field-textarea"
                  rows="3"
                ><?= h($goal['description']) ?></textarea>
              </div>

              <div class="field-group field-group-inline">
                <div class="field-inline">
                  <label for="goal_unit" class="field-label">Unit</label>
                  <input
                    type="text"
                    id="goal_unit"
                    name="unit"
                    class="field-input"
                    value="<?= h($unit) ?>"
                    placeholder="kg, lbs, %, reps..."
                  >
                </div>

                <div class="field-inline">
                  <label for="goal_target" class="field-label">Target value</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    id="goal_target"
                    name="target_value"
                    class="field-input"
                    value="<?= $targetValue !== null ? h((string)$targetValue) : '' ?>"
                  >
                </div>
              </div>

              <div class="field-group field-group-inline">
                <div class="field-inline">
                  <label for="goal_status" class="field-label">Status</label>
                  <select id="goal_status" name="status" class="field-input">
                    <option value="pending"     <?= $status === 'pending'     ? 'selected' : '' ?>>Pending</option>
                    <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                    <option value="completed"   <?= $status === 'completed'   ? 'selected' : '' ?>>Completed</option>
                  </select>
                </div>

                <div class="field-inline">
                  <label for="goal_created_at" class="field-label">Created on</label>
                  <input
                    type="date"
                    id="goal_created_at"
                    name="created_at"
                    class="field-input"
                    value="<?= h($createdDateValue) ?>"
                  >
                </div>
              </div>

              <div class="form-footer edit-goal-footer">
                <button type="submit" class="btn btn-accent">
                  <span class="btn-icon">💾</span>
                  <span>Save changes</span>
                </button>

                <button type="button" class="btn btn-ghost btn-cancel-edit">
                  Cancel
                </button>
              </div>
            </form>
          </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="card-plain">

          <div class="section-title">Add progress</div>

          <form action="/dashboards/client_goal_progress_add.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">

            <div class="field-group">
              <label class="field-label" for="progress_value">How much did you progress?</label>
              <input
                id="progress_value"
                name="progress_value"
                type="number"
                step="0.01"
                min="0.01"
                class="field-input"
                required
              >
              <?php if (!empty($unit)): ?>
                <div class="help-text">Goal unit: <?= h($unit) ?></div>
              <?php endif; ?>
            </div>

            <div class="field-group">
              <label class="field-label" for="note">Comment (optional)</label>
              <textarea id="note" name="note" class="field-textarea" placeholder="How was this progress?"></textarea>
            </div>

            <div class="field-group">
              <label class="field-label">
                <input type="checkbox" name="mark_completed" value="1">
                Mark goal as completed with this progress
              </label>
            </div>

            <div class="form-footer">
              <button type="submit" class="btn btn-accent">
                <span class="btn-icon">➕</span>
                <span>Record progress</span>
              </button>
            </div>
          </form>

          <div class="section-divider"></div>

          <div class="section-title">Progress history</div>

          <?php if (empty($progressList)): ?>
            <div class="history-empty">
              No progress recorded yet. Use the form above to register your first update.
            </div>
          <?php else: ?>
            <div class="history-list">
              <?php foreach ($progressList as $item): ?>
                <div class="history-item">
                  <div class="history-header">
                    <div class="history-value">
                      +<?= number_format((float)($item['progress_value'] ?? 0), 2, ',', '.') . ' ' . h($unit) ?>
                    </div>
                    <div class="history-date">
                      <?= !empty($item['created_at']) ? h(date('m/d/Y H:i', strtotime((string)$item['created_at']))) : '-' ?>
                    </div>
                  </div>

                  <?php if (!empty($item['note'])): ?>
                    <div class="history-note">
                      <?= nl2br(h($item['note'])) ?>
                    </div>
                  <?php else: ?>
                    <div class="history-note-empty">(no comment)</div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </div>

      </div><!-- /layout-grid -->
    </div><!-- /page-wrapper -->
  </div><!-- /client-shell -->
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

    const editPanel = document.getElementById('edit-goal-panel');
    const cancelBtn = document.querySelector('.btn-cancel-edit');
    if (cancelBtn && editPanel) {
      cancelBtn.addEventListener('click', function () {
        editPanel.classList.remove('is-open');
      });
    }
  })();
</script>

</body>
</html>
