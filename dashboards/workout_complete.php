<?php
// workout_complete.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

/**
 * CSRF (POST obrigatório)
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_session_token = (string)$_SESSION['csrf_token'];

/**
 * 1) Valida método
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: client_workouts.php?tab=checkins');
    exit;
}

/**
 * 2) Valida CSRF
 */
$csrf_post_token = (string)($_POST['csrf_token'] ?? '');
if ($csrf_post_token === '' || !hash_equals($csrf_session_token, $csrf_post_token)) {
    header('Location: client_workouts.php?tab=checkins');
    exit;
}

/**
 * 3) Sanitiza/valida payload
 */
$session_id  = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$status      = (string)($_POST['status'] ?? 'completed');

$difficulty  = (isset($_POST['difficulty']) && $_POST['difficulty'] !== '')
    ? (int)$_POST['difficulty']
    : null;

$mood        = (isset($_POST['mood']) && $_POST['mood'] !== '')
    ? (int)$_POST['mood']
    : null;

$notes       = trim((string)($_POST['notes'] ?? ''));

$allowed_status = ['completed', 'partial', 'missed'];
if (!in_array($status, $allowed_status, true)) {
    $status = 'completed';
}

if ($session_id <= 0) {
    header('Location: client_workouts.php?tab=checkins');
    exit;
}

/**
 * 4) Garante que essa sessão pertence a um plano do cliente logado
 */
$sql = "
    SELECT ws.id, ws.plan_id, wp.user_id
    FROM workout_sessions ws
    JOIN workout_plans wp ON wp.id = ws.plan_id
    WHERE ws.id = ?
      AND wp.user_id = ?
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$session_id, $current_user_id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    header('Location: client_workouts.php?tab=checkins');
    exit;
}

/**
 * 5) Insere log
 */
$sql = "
    INSERT INTO workout_logs
        (session_id, user_id, performed_at, status, difficulty, mood, notes)
    VALUES
        (:session_id, :user_id, NOW(), :status, :difficulty, :mood, :notes)
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':session_id' => $session_id,
    ':user_id'    => $current_user_id,
    ':status'     => $status,
    ':difficulty' => $difficulty,
    ':mood'       => $mood,
    ':notes'      => ($notes !== '' ? $notes : null),
]);

/**
 * 6) Redireciona para aba de check-ins
 */
header('Location: client_workouts.php?tab=checkins');
exit;
