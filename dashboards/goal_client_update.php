<?php
// goal_client_update.php
// Localização: /dashboards/
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('/dashboards/client_goals.php');
}

/* ======================================================
   CSRF VALIDATION
   ====================================================== */
$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    redirect('/dashboards/client_goals.php?error=' . urlencode('Invalid request.'));
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = (string)($_SESSION['role'] ?? '');

/* ======================================================
   INPUT VALIDATION
   ====================================================== */
$goalId        = (int)($_POST['goal_id'] ?? 0);
$progressValue = trim((string)($_POST['progress_value'] ?? ''));
$note          = trim((string)($_POST['note'] ?? ''));
$markCompleted = (int)($_POST['mark_completed'] ?? 0);

if ($goalId <= 0) {
    redirect('/dashboards/client_goals.php?error=' . urlencode('Invalid goal.'));
}

if ($progressValue === '' || !is_numeric(str_replace(',', '.', $progressValue))) {
    redirect('/dashboards/goal_client_view.php?goal_id=' . $goalId . '&error=' . urlencode('Invalid progress value.'));
}

$progressValue = (float)str_replace(',', '.', $progressValue);
if ($progressValue <= 0) {
    redirect('/dashboards/goal_client_view.php?goal_id=' . $goalId . '&error=' . urlencode('Progress must be greater than zero.'));
}

if (mb_strlen($note) > 1000) {
    $note = mb_substr($note, 0, 1000);
}

$pdo = getPDO();

try {
    $pdo->beginTransaction();

    /* ======================================================
       LOCK GOAL
       ====================================================== */
    $stmtGoal = $pdo->prepare("
        SELECT id, client_id, target_value, current_value, status
        FROM client_goals
        WHERE id = :goal_id
        FOR UPDATE
    ");
    $stmtGoal->execute([':goal_id' => $goalId]);
    $goal = $stmtGoal->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        $pdo->rollBack();
        redirect('/dashboards/client_goals.php?error=' . urlencode('Goal not found.'));
    }

    // Ownership check (user/client only update their own goal)
    if (in_array($role, ['user', 'client'], true) && (int)$goal['client_id'] !== $userId) {
        $pdo->rollBack();
        redirect('/dashboards/client_goals.php?error=' . urlencode('Unauthorized action.'));
    }

    $oldCurrent  = (float)($goal['current_value'] ?? 0);
    $targetValue = ($goal['target_value'] !== null) ? (float)$goal['target_value'] : null;
    $oldStatus   = (string)($goal['status'] ?? 'pending');

    $newCurrent = $oldCurrent + $progressValue;
    $newStatus  = $oldStatus;

    if ($markCompleted === 1) {
        $newStatus = 'completed';
        if ($targetValue !== null && $targetValue > 0 && $newCurrent < $targetValue) {
            $newCurrent = $targetValue;
        }
    } else {
        if ($targetValue !== null && $targetValue > 0 && $newCurrent >= $targetValue) {
            $newStatus = 'completed';
        } elseif ($oldStatus === 'pending') {
            $newStatus = 'in_progress';
        }
    }

    /* ======================================================
       INSERT PROGRESS HISTORY
       ====================================================== */
    $stmtHist = $pdo->prepare("
        INSERT INTO client_goal_progress
            (goal_id, progress_value, note, created_at)
        VALUES
            (:goal_id, :progress_value, :note, NOW())
    ");
    $stmtHist->execute([
        ':goal_id'        => $goalId,
        ':progress_value' => $progressValue,
        ':note'           => $note
    ]);

    /* ======================================================
       UPDATE GOAL
       ====================================================== */
    $stmtUpdate = $pdo->prepare("
        UPDATE client_goals
        SET
            current_value = :current_value,
            status        = :status,
            updated_at    = NOW()
        WHERE id = :goal_id
    ");
    $stmtUpdate->execute([
        ':current_value' => $newCurrent,
        ':status'        => $newStatus,
        ':goal_id'       => $goalId
    ]);

    $pdo->commit();

    redirect('/dashboards/goal_client_view.php?goal_id=' . $goalId . '&success=1');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect('/dashboards/goal_client_view.php?goal_id=' . $goalId . '&error=' . urlencode('Failed to record progress.'));
}
