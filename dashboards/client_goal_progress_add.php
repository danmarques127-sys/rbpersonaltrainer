<?php
session_start();
require_once "config.php";

// Verifica login e role permitido
if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['user', 'client'], true)
) {
    header("Location: login.php");
    exit();
}

$pdo = getPDO();
$clientId = (int) $_SESSION['user_id'];

// Só aceita POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: client_goals.php");
    exit();
}

/**
 * (Recomendado) CSRF
 * Se você já tem CSRF no projeto, mantenha o mesmo nome do campo.
 * Se ainda não tem, deixe esse bloco e comece a enviar o hidden input 'csrf_token' nos forms.
 */
if (!empty($_SESSION['csrf_token'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        header("Location: client_goals.php?error=csrf");
        exit();
    }
}

// Dados recebidos
$goalIdRaw  = $_POST["goal_id"] ?? null;
$logDateRaw = $_POST["log_date"] ?? null;
$valueRaw   = $_POST["value"] ?? null;
$noteRaw    = $_POST["note"] ?? "";

// goal_id: obrigatório e inteiro > 0
$goalId = filter_var($goalIdRaw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
if (!$goalId) {
    header("Location: client_goals.php?error=invalid_goal");
    exit();
}

// log_date: obrigatório no formato YYYY-MM-DD
$logDate = trim((string)$logDateRaw);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) {
    header("Location: client_goals.php?error=invalid_date");
    exit();
}
$dt = DateTime::createFromFormat('Y-m-d', $logDate);
$dtErrors = DateTime::getLastErrors();
if (!$dt || (!empty($dtErrors['warning_count']) || !empty($dtErrors['error_count']))) {
    header("Location: client_goals.php?error=invalid_date");
    exit();
}

// value: opcional, mas se vier deve ser numérico (aceita decimal)
$value = null;
if ($valueRaw !== null && $valueRaw !== '') {
    // normaliza vírgula -> ponto (pt-br)
    $normalized = str_replace(',', '.', (string)$valueRaw);

    if (!is_numeric($normalized)) {
        header("Location: client_goals.php?error=invalid_value");
        exit();
    }
    $value = (float)$normalized;

    // (Opcional) bloqueia negativos se não fizer sentido no seu app
    if ($value < 0) {
        header("Location: client_goals.php?error=invalid_value");
        exit();
    }
}

// note: opcional, limita tamanho
$note = trim((string)$noteRaw);
if (mb_strlen($note) > 500) {
    $note = mb_substr($note, 0, 500);
}

// Confere se a meta é do cliente (impede mexer em meta alheia)
$stmt = $pdo->prepare("
    SELECT id
    FROM client_goals
    WHERE id = :id AND client_id = :client
    LIMIT 1
");
$stmt->execute([
    ":id" => $goalId,
    ":client" => $clientId
]);

if (!$stmt->fetch()) {
    header("Location: client_goals.php?error=invalid_goal");
    exit();
}

// Insere o progresso
$stmt = $pdo->prepare("
    INSERT INTO client_goal_progress (goal_id, log_date, value, note, created_at)
    VALUES (:goal, :log_date, :value, :note, NOW())
");
$stmt->execute([
    ":goal"     => $goalId,
    ":log_date" => $logDate,
    ":value"    => $value,
    ":note"     => $note
]);

header("Location: client_goals.php?success=progress_added");
exit();
