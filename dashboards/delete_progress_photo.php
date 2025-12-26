<?php
// /dashboards/delete_progress_photo.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client', 'pro', 'admin']);

$pdo  = getPDO();
$role = (string)($_SESSION['role'] ?? '');
$me   = (int)($_SESSION['user_id'] ?? 0);

/**
 * Redirect helper
 */
function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Only POST
 * (Se você abrir no browser direto, vai cair aqui e redirecionar)
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_to('/dashboards/progress_gallery.php');
}

/**
 * CSRF required
 */
if (
    empty($_SESSION['csrf_token']) ||
    empty($_POST['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

/**
 * Inputs
 */
$photo_id  = (int)($_POST['photo_id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0); // opcional: pra voltar pro mesmo client quando pro/admin

if ($photo_id <= 0) {
    http_response_code(400);
    exit('Invalid photo id');
}

/**
 * Load photo
 */
$stmt = $pdo->prepare("
    SELECT id, user_id, file_path
    FROM progress_photos
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$photo_id]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$photo) {
    http_response_code(404);
    exit('Photo not found');
}

$photo_owner_id = (int)$photo['user_id'];

/**
 * Permission rules
 * - user/client: only delete own photo
 * - pro: only delete photo of assigned client
 * - admin: can delete any
 */
if (in_array($role, ['user', 'client'], true)) {
    if ($photo_owner_id !== $me) {
        http_response_code(403);
        exit('Unauthorized');
    }
}

if ($role === 'pro') {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM coach_clients
        WHERE coach_id = ? AND client_id = ?
        LIMIT 1
    ");
    $stmt->execute([$me, $photo_owner_id]);
    if (!$stmt->fetchColumn()) {
        http_response_code(403);
        exit('Unauthorized');
    }
}

/**
 * Delete DB row first
 */
$pdo->prepare("DELETE FROM progress_photos WHERE id = ?")->execute([$photo_id]);

/**
 * Delete physical file safely
 * file_path costuma vir como /uploads/... ou /assets/...
 * Aqui a gente resolve relativo ao projeto: /dashboards/../ + ltrim(/...)
 */
$filePath = (string)($photo['file_path'] ?? '');
$fullPath = realpath(__DIR__ . '/../' . ltrim($filePath, '/'));

if ($fullPath && is_file($fullPath)) {
    @unlink($fullPath);
}

/**
 * Redirect back (preserva client_id se tiver)
 */
$_SESSION['flash_success'] = 'Photo deleted successfully.';

if ($client_id > 0 && in_array($role, ['pro', 'admin'], true)) {
    redirect_to('/dashboards/progress_gallery.php?client_id=' . $client_id);
}

redirect_to('/dashboards/progress_gallery.php');
