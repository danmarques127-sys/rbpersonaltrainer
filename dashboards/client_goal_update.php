<?php
declare(strict_types=1);

// ======================================
// BOOTSTRAP CENTRAL (session + auth + PDO)
// ======================================
require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user','client']);

$pdo      = getPDO();
$userId   = (int)($_SESSION['user_id'] ?? 0);
$clientId = $userId;

// ======================================
// UNREAD MESSAGES COUNT (HEADER BADGE)
// ======================================
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

// ======================================
// CSRF token (gerar 1 vez por sessão)
// ======================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ======================================
// Helpers: detectar e resolver colunas
// ======================================
function db_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c
            LIMIT 1
        ");
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function resolve_column(PDO $pdo, string $table, array $candidates): string
{
    foreach ($candidates as $cand) {
        if (is_string($cand) && $cand !== '' && db_has_column($pdo, $table, $cand)) {
            return $cand;
        }
    }
    return '';
}

/**
 * Resolve (client_goals) columns
 * -> devolvemos sempre nomes padronizados via alias nos SELECTs
 */
$tblGoals = 'client_goals';

$colGoalId              = resolve_column($pdo, $tblGoals, ['id', 'goal_id']);
$colClientId            = resolve_column($pdo, $tblGoals, ['client_id', 'user_id', 'member_id']);
$colType                = resolve_column($pdo, $tblGoals, ['type', 'goal_type', 'category']);
$colPriority            = resolve_column($pdo, $tblGoals, ['priority', 'goal_priority']);
$colTitle               = resolve_column($pdo, $tblGoals, ['title', 'goal_title', 'name']);
$colDesc                = resolve_column($pdo, $tblGoals, ['description', 'details', 'notes', 'goal_description', 'goal_details', 'desc']);

$colStartValue          = resolve_column($pdo, $tblGoals, ['start_value', 'start_val', 'initial_value', 'from_value', 'baseline_value']);
$colTargetValue         = resolve_column($pdo, $tblGoals, ['target_value', 'target_val', 'goal_value', 'to_value', 'desired_value']);
$colUnit                = resolve_column($pdo, $tblGoals, ['unit', 'units', 'measure_unit', 'measurement_unit']);

$colStartDate           = resolve_column($pdo, $tblGoals, ['start_date', 'date_start', 'begin_date']);
$colEndDate             = resolve_column($pdo, $tblGoals, ['end_date', 'date_end', 'deadline', 'due_date']);

$colVisibleTrainer      = resolve_column($pdo, $tblGoals, ['visible_trainer', 'trainer_visible', 'show_trainer']);
$colVisibleNutritionist = resolve_column($pdo, $tblGoals, ['visible_nutritionist', 'nutritionist_visible', 'show_nutritionist']);

$colStatus              = resolve_column($pdo, $tblGoals, ['status', 'state']);

// se faltar coluna essencial, melhor parar com erro claro
if ($colGoalId === '' || $colClientId === '' || $colTitle === '') {
    http_response_code(500);
    die('Database schema mismatch: missing required columns in client_goals (id/client_id/title).');
}

// ======================================
// PROCESSA FORM DE CREATE / UPDATE
// ======================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    // CSRF check
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        redirect('/dashboards/client_goals.php?error=csrf');
    }

    // -------------------------------
    // Validações (allowlists + tipos)
    // -------------------------------
    $goalIdRaw = (string)($_POST['goal_id'] ?? '');
    $goalId = null;

    if ($goalIdRaw !== '') {
        $goalId = filter_var($goalIdRaw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        if (!$goalId) {
            redirect('/dashboards/client_goals.php?error=invalid_goal');
        }
    }

    $allowedTypes      = ['weight','body_comp','measure','performance','habit','nutrition','custom'];
    $allowedPriorities = ['main','secondary'];

    $type     = trim((string)($_POST['type'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? 'secondary'));

    if (!in_array($type, $allowedTypes, true)) {
        redirect('/dashboards/client_goals.php?error=invalid_type');
    }

    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'secondary';
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $desc  = trim((string)($_POST['description'] ?? ''));

    if ($title === '' || mb_strlen($title) > 120) {
        redirect('/dashboards/client_goals.php?error=invalid_title');
    }

    if (mb_strlen($desc) > 2000) {
        $desc = mb_substr($desc, 0, 2000);
    }

    // números (normaliza vírgula -> ponto)
    $startVal = null;
    if (isset($_POST['start_value']) && $_POST['start_value'] !== '') {
        $sv = str_replace(',', '.', (string)$_POST['start_value']);
        if (!is_numeric($sv)) {
            redirect('/dashboards/client_goals.php?error=invalid_start');
        }
        $startVal = (float)$sv;
    }

    $targetVal = null;
    if (isset($_POST['target_value']) && $_POST['target_value'] !== '') {
        $tv = str_replace(',', '.', (string)$_POST['target_value']);
        if (!is_numeric($tv)) {
            redirect('/dashboards/client_goals.php?error=invalid_target');
        }
        $targetVal = (float)$tv;
    }

    $unit = trim((string)($_POST['unit'] ?? ''));
    if (mb_strlen($unit) > 20) {
        $unit = mb_substr($unit, 0, 20);
    }

    // datas
    $startDate = (($_POST['start_date'] ?? '') !== '') ? trim((string)$_POST['start_date']) : null;
    $endDate   = (($_POST['end_date'] ?? '') !== '') ? trim((string)$_POST['end_date']) : null;

    $validDate = function (?string $d): bool {
        if ($d === null) return true;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        $e  = DateTime::getLastErrors();
        return (bool)$dt && empty($e['warning_count']) && empty($e['error_count']);
    };

    if (!$validDate($startDate) || !$validDate($endDate)) {
        redirect('/dashboards/client_goals.php?error=invalid_date');
    }

    if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
        redirect('/dashboards/client_goals.php?error=end_before_start');
    }

    $visibleTrainer      = isset($_POST['visible_trainer']) ? 1 : 0;
    $visibleNutritionist = isset($_POST['visible_nutritionist']) ? 1 : 0;

    // -------------------------------
    // UPDATE ou INSERT (somente colunas que existem)
    // -------------------------------
    if ($goalId !== null) {

        $setParts = [];
        $params = [
            ':goal_id'   => $goalId,
            ':client_id' => $clientId
        ];

        // essenciais
        if ($colType !== '') {
            $setParts[] = "{$colType} = :type";
            $params[':type'] = $type;
        }
        if ($colPriority !== '') {
            $setParts[] = "{$colPriority} = :priority";
            $params[':priority'] = $priority;
        }

        $setParts[] = "{$colTitle} = :title";
        $params[':title'] = $title;

        // opcionais
        if ($colDesc !== '') {
            $setParts[] = "{$colDesc} = :goal_desc";
            $params[':goal_desc'] = $desc;
        }
        if ($colStartValue !== '') {
            $setParts[] = "{$colStartValue} = :start_value";
            $params[':start_value'] = $startVal;
        }
        if ($colTargetValue !== '') {
            $setParts[] = "{$colTargetValue} = :target_value";
            $params[':target_value'] = $targetVal;
        }
        if ($colUnit !== '') {
            $setParts[] = "{$colUnit} = :unit";
            $params[':unit'] = $unit;
        }
        if ($colStartDate !== '') {
            $setParts[] = "{$colStartDate} = :start_date";
            $params[':start_date'] = $startDate;
        }
        if ($colEndDate !== '') {
            $setParts[] = "{$colEndDate} = :end_date";
            $params[':end_date'] = $endDate;
        }
        if ($colVisibleTrainer !== '') {
            $setParts[] = "{$colVisibleTrainer} = :visible_trainer";
            $params[':visible_trainer'] = $visibleTrainer;
        }
        if ($colVisibleNutritionist !== '') {
            $setParts[] = "{$colVisibleNutritionist} = :visible_nutritionist";
            $params[':visible_nutritionist'] = $visibleNutritionist;
        }

        if (empty($setParts)) {
            redirect('/dashboards/client_goals.php?error=nothing_to_update');
        }

        $sql = "
            UPDATE {$tblGoals}
            SET " . implode(",\n                ", $setParts) . "
            WHERE {$colGoalId} = :goal_id
              AND {$colClientId} = :client_id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

    } else {

        $cols = [];
        $vals = [];
        $params = [];

        // obrigatório: client_id + title
        $cols[] = $colClientId;
        $vals[] = ":client_id";
        $params[':client_id'] = $clientId;

        $cols[] = $colTitle;
        $vals[] = ":title";
        $params[':title'] = $title;

        // essenciais se existirem
        if ($colType !== '') {
            $cols[] = $colType;
            $vals[] = ":type";
            $params[':type'] = $type;
        }
        if ($colPriority !== '') {
            $cols[] = $colPriority;
            $vals[] = ":priority";
            $params[':priority'] = $priority;
        }

        // opcionais
        if ($colDesc !== '') {
            $cols[] = $colDesc;
            $vals[] = ":goal_desc";
            $params[':goal_desc'] = $desc;
        }
        if ($colStartValue !== '') {
            $cols[] = $colStartValue;
            $vals[] = ":start_value";
            $params[':start_value'] = $startVal;
        }
        if ($colTargetValue !== '') {
            $cols[] = $colTargetValue;
            $vals[] = ":target_value";
            $params[':target_value'] = $targetVal;
        }
        if ($colUnit !== '') {
            $cols[] = $colUnit;
            $vals[] = ":unit";
            $params[':unit'] = $unit;
        }
        if ($colStartDate !== '') {
            $cols[] = $colStartDate;
            $vals[] = ":start_date";
            $params[':start_date'] = $startDate;
        }
        if ($colEndDate !== '') {
            $cols[] = $colEndDate;
            $vals[] = ":end_date";
            $params[':end_date'] = $endDate;
        }
        if ($colVisibleTrainer !== '') {
            $cols[] = $colVisibleTrainer;
            $vals[] = ":visible_trainer";
            $params[':visible_trainer'] = $visibleTrainer;
        }
        if ($colVisibleNutritionist !== '') {
            $cols[] = $colVisibleNutritionist;
            $vals[] = ":visible_nutritionist";
            $params[':visible_nutritionist'] = $visibleNutritionist;
        }
        if ($colStatus !== '') {
            $cols[] = $colStatus;
            $vals[] = ":status";
            $params[':status'] = 'active';
        }

        $sql = "
            INSERT INTO {$tblGoals} (" . implode(", ", $cols) . ")
            VALUES (" . implode(", ", $vals) . ")
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    redirect('/dashboards/client_goals.php');
}

// ===============================
// CARREGA GOALS DO CLIENTE (com alias padrão)
// ===============================
$selectParts = [];
$selectParts[] = "{$colGoalId} AS id";
$selectParts[] = "{$colTitle} AS title";

if ($colType !== '')                $selectParts[] = "{$colType} AS type";
if ($colPriority !== '')            $selectParts[] = "{$colPriority} AS priority";
if ($colDesc !== '')                $selectParts[] = "{$colDesc} AS description";
if ($colStartValue !== '')          $selectParts[] = "{$colStartValue} AS start_value";
if ($colTargetValue !== '')         $selectParts[] = "{$colTargetValue} AS target_value";
if ($colUnit !== '')                $selectParts[] = "{$colUnit} AS unit";
if ($colStartDate !== '')           $selectParts[] = "{$colStartDate} AS start_date";
if ($colEndDate !== '')             $selectParts[] = "{$colEndDate} AS end_date";
if ($colVisibleTrainer !== '')      $selectParts[] = "{$colVisibleTrainer} AS visible_trainer";
if ($colVisibleNutritionist !== '') $selectParts[] = "{$colVisibleNutritionist} AS visible_nutritionist";
if ($colStatus !== '')              $selectParts[] = "{$colStatus} AS status";

$sqlGoals = "
    SELECT " . implode(", ", $selectParts) . "
    FROM {$tblGoals}
    WHERE {$colClientId} = :client_id
" . ($colStatus !== '' ? " AND {$colStatus} != 'canceled' " : "") . "
    ORDER BY {$colGoalId} DESC
";

$stmtGoals = $pdo->prepare($sqlGoals);
$stmtGoals->execute([':client_id' => $clientId]);
$allGoals = $stmtGoals->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// HISTÓRICO RECENTE
// ===============================
$sqlHistory = "
    SELECT gp.*, g.title
    FROM client_goal_progress gp
    JOIN {$tblGoals} g ON g.{$colGoalId} = gp.goal_id
    WHERE g.{$colClientId} = :client_id
    ORDER BY gp.log_date DESC, gp.created_at DESC
    LIMIT 10
";
$stmtHistory = $pdo->prepare($sqlHistory);
$stmtHistory->execute([':client_id' => $clientId]);
$recentHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// HISTÓRICO COMPLETO
// ===============================
$sqlHistoryFull = "
    SELECT gp.*, g.title
    FROM client_goal_progress gp
    JOIN {$tblGoals} g ON g.{$colGoalId} = gp.goal_id
    WHERE g.{$colClientId} = :client_id
    ORDER BY gp.log_date DESC, gp.created_at DESC
";
$stmtHistoryFull = $pdo->prepare($sqlHistoryFull);
$stmtHistoryFull->execute([':client_id' => $clientId]);
$fullHistory = $stmtHistoryFull->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// RESUMO (ACTIVE + LATEST)
// ===============================
$activeGoals = array_filter($allGoals, fn($g) => (($g['status'] ?? 'active') === 'active'));
$activeCount = count($activeGoals);

$latestGoal = null;
if ($activeCount > 0) {
    $tmp = array_values($activeGoals);
    usort($tmp, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
    $latestGoal = $tmp[0] ?? null;
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

  <!-- CSS específico -->
  <link rel="stylesheet" href="/assets/css/client_goal_update.css">
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

    <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">☰</button>
  </div>
</header>

<div class="client-shell">

<main class="client-main client-main--goals">

<section class="client-section client-section--goals">

  <div class="goals-top">

    <!-- ================= COLUNA ESQUERDA ================= -->
    <div class="goals-top-column goals-top-column--left">

      <div class="goals-card goals-card--metrics">
        <div class="goals-mini-cards">

          <div class="mini-card">
            <span class="mini-card-title">Completed</span>
            <span class="mini-card-value">
              <?php
                $completed = count(array_filter($allGoals, fn($g) => !empty($g['end_date']) && $g['end_date'] < date('Y-m-d')));
                $percent = count($allGoals) > 0 ? round(($completed / count($allGoals)) * 100) : 0;
                echo $percent . '%';
              ?>
            </span>
          </div>

          <div class="mini-card">
            <span class="mini-card-title">Last update</span>
            <span class="mini-card-value">
              <?php
                if (!empty($recentHistory)) {
                  $last = $recentHistory[0]['log_date'] ?? null;
                  if ($last) {
                    $daysAgo = (new DateTime($last))->diff(new DateTime())->days;
                    echo $daysAgo . " day" . ($daysAgo==1?'':'s') . " ago";
                  } else {
                    echo "No progress";
                  }
                } else {
                  echo "No progress";
                }
              ?>
            </span>
          </div>

          <div class="mini-card">
            <span class="mini-card-title">Next deadline</span>
            <span class="mini-card-value">
              <?php
                $deadlines = array_filter($allGoals, fn($g) => !empty($g['end_date']));
                if (!empty($deadlines)) {
                  $tmpDead = array_values($deadlines);
                  usort($tmpDead, fn($a,$b) => strtotime((string)$a['end_date']) - strtotime((string)$b['end_date']));
                  $next = strtotime((string)$tmpDead[0]['end_date']);
                  $daysLeft = (int)floor(($next - time())/86400);
                  echo $daysLeft . " days";
                } else {
                  echo "None";
                }
              ?>
            </span>
          </div>

        </div>
      </div>

      <div class="goals-card goals-card--status">
        <h1 class="section-title">My Goals</h1>
        <p class="section-subtitle">
          Track your main goals, secondary goals, progress, and history.
        </p>

        <div class="goal-status-header goal-status-header--compact">

          <div class="goal-status-main">
            <h2 class="goal-status-title">
              <?php echo $activeCount; ?> goal<?php echo ($activeCount == 1 ? '' : 's'); ?> in progress
            </h2>

            <?php if ($latestGoal): ?>
              <p class="goal-status-latest">
                Most recent: <strong><?php echo htmlspecialchars((string)$latestGoal['title']); ?></strong>
              </p>
            <?php else: ?>
              <p class="goal-status-latest">No active goals.</p>
            <?php endif; ?>
          </div>

          <div class="goal-status-extra">
            <div class="goal-status-row">
              <span>Total goals</span>
              <strong><?php echo count($allGoals); ?></strong>
            </div>
            <div class="goal-status-row">
              <span>Main goals</span>
              <strong><?php echo count(array_filter($allGoals, fn($g) => (($g['priority'] ?? '') === 'main'))); ?></strong>
            </div>
            <div class="goal-status-row">
              <span>Secondary goals</span>
              <strong><?php echo count(array_filter($allGoals, fn($g) => (($g['priority'] ?? '') === 'secondary'))); ?></strong>
            </div>
          </div>

        </div>
      </div>

    </div>

    <!-- ================= COLUNA DIREITA ================= -->
    <div class="goals-top-column goals-top-column--right">

      <div class="goals-card goals-card--overview">
        <h2 class="goals-summary-title">Overview</h2>
        <ul class="goals-summary-list">
          <li><span>Total goals</span><strong><?php echo count($allGoals); ?></strong></li>
          <li><span>Main goals</span><strong><?php echo count(array_filter($allGoals, fn($g) => (($g['priority'] ?? '') === 'main'))); ?></strong></li>
          <li><span>Secondary goals</span><strong><?php echo count(array_filter($allGoals, fn($g) => (($g['priority'] ?? '') === 'secondary'))); ?></strong></li>
        </ul>
      </div>

      <div class="goals-card goals-card--history">
        <h2 class="goals-history-title">Recent History</h2>

        <?php if (empty($recentHistory)): ?>
          <p class="goals-empty">No history yet. Add progress to your goals.</p>
        <?php else: ?>
          <?php $recentShort = array_slice($recentHistory, 0, 2); ?>

          <ul class="goals-history-list">
            <?php foreach ($recentShort as $entry): ?>
              <li class="goals-history-item">
                <div class="goals-history-main">
                  <span class="goals-history-goal"><?php echo htmlspecialchars((string)($entry['title'] ?? '')); ?></span>
                  <span class="goals-history-date"><?php echo !empty($entry['log_date']) ? date('M d, Y', strtotime((string)$entry['log_date'])) : ''; ?></span>
                </div>

                <div class="goals-history-extra">
                  <?php if (($entry['value'] ?? null) !== null): ?>
                    <span class="goals-history-value">
                      <?php echo rtrim(rtrim(number_format((float)$entry['value'], 2, '.', ''), '0'), '.'); ?>
                    </span>
                  <?php endif; ?>

                  <?php if (!empty($entry['note'])): ?>
                    <p class="goals-history-note"><?php echo nl2br(htmlspecialchars((string)$entry['note'])); ?></p>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>

        <?php endif; ?>
      </div>

    </div>

  </div>

</section>

<!-- ======================================= -->
<!-- MODAL CREATE GOAL -->
<!-- ======================================= -->
<div class="goal-modal" id="goal-modal-create">
  <div class="goal-modal-dialog">

    <div class="goal-modal-header">
      <h2>Create Goal</h2>
      <button type="button" class="goal-modal-close" data-close>&times;</button>
    </div>

    <form class="goal-form" action="/dashboards/client_goal_update.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token']); ?>">
      <input type="hidden" name="goal_id" id="goal-form-goal-id" value="">

      <div class="goal-form-grid">

        <div class="goal-form-group">
          <label class="form-label" for="goal-type">Category</label>
          <select name="type" id="goal-type" required>
            <option value="">Select…</option>
            <option value="weight">Weight / Body composition</option>
            <option value="body_comp">Body fat / Lean mass</option>
            <option value="measure">Body measurements</option>
            <option value="performance">Performance / Conditioning</option>
            <option value="habit">Habits / Lifestyle</option>
            <option value="nutrition">Nutrition</option>
            <option value="custom">Custom</option>
          </select>
        </div>

        <div class="goal-form-group">
          <label class="form-label" for="goal-priority">Priority</label>
          <select name="priority" id="goal-priority">
            <option value="main">Main goal</option>
            <option value="secondary">Secondary goal</option>
          </select>
        </div>

        <div class="goal-form-group goal-form-group--full">
          <label class="form-label" for="goal-title">Title</label>
          <input type="text" name="title" id="goal-title" required maxlength="120">
        </div>

        <div class="goal-form-group goal-form-group--full">
          <label class="form-label" for="goal-description">Description</label>
          <textarea name="description" id="goal-description" rows="4" maxlength="2000"></textarea>
        </div>

        <div class="goal-form-group">
          <label class="form-label" for="goal-start-value">Start value</label>
          <input type="number" step="0.01" name="start_value" id="goal-start-value">
        </div>

        <div class="goal-form-group">
          <label class="form-label" for="goal-target-value">Target value</label>
          <input type="number" step="0.01" name="target_value" id="goal-target-value">
        </div>

        <div class="goal-form-group">
          <label class="form-label" for="goal-unit">Unit</label>
          <input type="text" name="unit" id="goal-unit" maxlength="20">
        </div>

        <div class="goal-form-group">
          <label class="form-label" for="goal-start-date">Start date</label>
          <input type="date" name="start_date" id="goal-start-date">
        </div>

        <div class="goal-form-group">
          <label class="form-label" for="goal-end-date">End date</label>
          <input type="date" name="end_date" id="goal-end-date">
        </div>

        <div class="goal-form-group goal-form-group--full">
          <label class="form-label">Visibility</label>
          <div class="goal-visibility-options">
            <label><input type="checkbox" name="visible_trainer"> Visible to Trainer</label>
            <label><input type="checkbox" name="visible_nutritionist"> Visible to Nutritionist</label>
          </div>
        </div>

      </div>

      <div class="goal-form-actions">
        <button type="button" class="btn btn-secondary" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Goal</button>
      </div>

    </form>
  </div>
</div>

<!-- ======================================= -->
<!-- MODAL ADD PROGRESS -->
<!-- ======================================= -->
<div class="goal-modal" id="goal-modal-progress">
  <div class="goal-modal-dialog">

    <div class="goal-modal-header">
      <h2>Add Progress</h2>
      <button type="button" class="goal-modal-close" data-close>&times;</button>
    </div>

    <form class="goal-form" action="/dashboards/client_goal_progress_add.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token']); ?>">

      <div class="goal-form-group goal-form-group--full">
        <label class="form-label" for="progress-goal-select">Select goal</label>
        <select name="goal_id" id="progress-goal-select" required>
          <option value="">Choose a goal…</option>
          <?php foreach ($activeGoals as $g): ?>
            <option value="<?php echo (int)$g['id']; ?>">
              <?php echo htmlspecialchars((string)$g['title']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="goal-form-grid">

        <div class="goal-form-group">
          <label class="form-label" for="progress-date">Date</label>
          <input type="date" name="log_date" id="progress-date" required>
        </div>

        <div class="goal-form-group">
          <label class="form-label" for="progress-value">Value</label>
          <input type="number" step="0.01" name="value" id="progress-value">
        </div>

        <div class="goal-form-group goal-form-group--full">
          <label class="form-label" for="progress-note">Note</label>
          <textarea name="note" id="progress-note" rows="4" maxlength="500"></textarea>
        </div>

      </div>

      <div class="goal-form-actions">
        <button type="button" class="btn btn-secondary" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Progress</button>
      </div>

    </form>
  </div>
</div>

<section class="goals-history-full-section">
  <h2 class="goals-history-title">GOAL HISTORY</h2>

  <?php $lastGoals = array_slice($allGoals, 0, 5); ?>

  <div class="goal-history-wrapper">
    <ul class="history-list-full">

      <?php if (empty($lastGoals)): ?>
        <li class="goals-history-item">
          <span class="goals-history-goal">No history yet. Add progress to your goals.</span>
        </li>
      <?php else: ?>
        <?php foreach ($lastGoals as $g): ?>
          <li class="goals-history-item">

            <div class="goals-history-main">
              <span class="goals-history-goal"><?php echo htmlspecialchars((string)$g['title']); ?></span>
              <span class="goals-history-date">
                <?php echo !empty($g['start_date']) ? date('M d, Y', strtotime((string)$g['start_date'])) : 'No date'; ?>
              </span>
            </div>

            <div class="goals-history-extra">
              <span class="goals-history-value"><?php echo htmlspecialchars(ucfirst((string)($g['status'] ?? 'active'))); ?></span>
            </div>

          </li>
        <?php endforeach; ?>
      <?php endif; ?>

    </ul>
  </div>

  <div class="goal-form-actions" style="justify-content:flex-end;">
    <a href="/dashboards/goal_history.php" class="btn btn-primary" style="margin-left:auto;">View more</a>
  </div>
</section>

</main>
</div><!-- ./client-shell -->

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

document.querySelectorAll('.open-progress-modal').forEach(btn => {
  btn.addEventListener('click', function () {
    const modal = document.getElementById('goal-modal-progress');
    modal.classList.add('open');

    const select = document.getElementById('progress-goal-select');
    const goalId = btn.dataset.goalid;

    if (goalId && select) select.value = goalId;
  });
});

document.querySelectorAll('[data-close]').forEach(btn => {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.goal-modal').forEach(m => m.classList.remove('open'));
  });
});
</script>

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
          Prefer a direct line to your coach? Reach out and let’s design your training strategy together.
        </p>
        <ul class="footer-contact-list">
          <li>
            <span class="footer-contact-label">Email:</span>
            <a href="mailto:rbpersonaltrainer@gmail.com" class="footer-email-link">rbpersonaltrainer@gmail.com</a>
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

</body>
</html>
