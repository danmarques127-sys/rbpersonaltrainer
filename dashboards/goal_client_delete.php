<?php
// goal_client_delete.php
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

$goalId = (int)($_POST['goal_id'] ?? 0);
if ($goalId <= 0) {
    redirect('/dashboards/client_goals.php?error=' . urlencode('Invalid goal.'));
}

$pdo = getPDO();

try {
    $pdo->beginTransaction();

    // Lock goal row (prevents race conditions)
    $stmt = $pdo->prepare("
        SELECT id, client_id
        FROM client_goals
        WHERE id = :goal_id
        FOR UPDATE
    ");
    $stmt->execute([':goal_id' => $goalId]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        $pdo->rollBack();
        redirect('/dashboards/client_goals.php?error=' . urlencode('Goal not found.'));
    }

    // Ownership check (user/client only delete their own goal)
    if (in_array($role, ['user', 'client'], true) && (int)$goal['client_id'] !== $userId) {
        $pdo->rollBack();
        redirect('/dashboards/client_goals.php?error=' . urlencode('Unauthorized action.'));
    }

    // Delete progress history first (FK safe)
    $stmtDelH = $pdo->prepare("DELETE FROM client_goal_progress WHERE goal_id = :goal_id");
    $stmtDelH->execute([':goal_id' => $goalId]);

    // Delete the goal
    $stmtDelG = $pdo->prepare("DELETE FROM client_goals WHERE id = :goal_id");
    $stmtDelG->execute([':goal_id' => $goalId]);

    $pdo->commit();

    redirect('/dashboards/client_goals.php?deleted=1');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect('/dashboards/client_goals.php?error=' . urlencode('Failed to delete goal.'));
}
