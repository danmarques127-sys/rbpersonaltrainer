<?php
// goal_client_complete.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('/dashboards/client_goals.php');
}

// ======================================================
// CSRF VALIDATION (obrigatório)
// ======================================================
$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    redirect('/dashboards/client_goals.php?error=csrf');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = (string)($_SESSION['role'] ?? '');

$goalId = (int)($_POST['goal_id'] ?? 0);
if ($goalId <= 0 || $userId <= 0) {
    redirect('/dashboards/client_goals.php?error=invalid_goal');
}

$pdo = getPDO();

try {
    $pdo->beginTransaction();

    $sql = "
        SELECT id, client_id, target_value, current_value, status
        FROM client_goals
        WHERE id = :goal_id
        LIMIT 1
        FOR UPDATE
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':goal_id' => $goalId]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        $pdo->rollBack();
        redirect('/dashboards/client_goals.php?error=goal_not_found');
    }

    // ownership: user/client só pode mexer no próprio goal
    if (in_array($role, ['user', 'client'], true) && (int)$goal['client_id'] !== $userId) {
        $pdo->rollBack();
        redirect('/dashboards/client_goals.php?error=unauthorized');
    }

    $targetValue  = $goal['target_value'] !== null ? (float)$goal['target_value'] : null;
    $currentValue = $goal['current_value'] !== null ? (float)$goal['current_value'] : 0.0;

    // Se tem target e current está abaixo, completa batendo no target
    if ($targetValue !== null && $targetValue > 0 && $currentValue < $targetValue) {
        $currentValue = $targetValue;
    }

    $sqlUpdate = "
        UPDATE client_goals
        SET
            status = 'completed',
            current_value = :current_value,
            updated_at = NOW()
        WHERE id = :goal_id
          AND client_id = :client_id
        LIMIT 1
    ";
    $stmtUpdate = $pdo->prepare($sqlUpdate);
    $stmtUpdate->execute([
        ':current_value' => $currentValue,
        ':goal_id'       => $goalId,
        ':client_id'     => $userId
    ]);

    $pdo->commit();

    // volta pra página real de detalhes
    redirect('/dashboards/client_goal_progress.php?goal_id=' . $goalId . '&success=completed');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect('/dashboards/client_goal_progress.php?goal_id=' . $goalId . '&error=complete_failed');
}
