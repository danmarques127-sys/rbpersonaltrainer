<?php
// message_action.php
declare(strict_types=1);

// ======================================
// BOOTSTRAP CENTRAL (session + auth + PDO)
// ======================================
require_once __DIR__ . '/../core/bootstrap.php';

require_login();

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

/**
 * 1) CSRF check
 */
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
) {
    http_response_code(403);
    echo 'Invalid CSRF token.';
    exit;
}

/**
 * 2) Inputs
 */
$allowed_folders = ['inbox', 'sent', 'archive', 'trash'];
$allowed_actions = ['archive', 'trash', 'restore', 'delete', 'empty_trash'];

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Folder atual (de onde clicou) — a gente sempre volta pra ele
$returnFolder = (isset($_POST['folder']) && in_array((string)$_POST['folder'], $allowed_folders, true))
    ? (string)$_POST['folder']
    : 'inbox';

/**
 * ✅ Status para feedback no messages.php
 */
$status = '';

$redirectBack = function () use ($returnFolder, $status): void {
    $qs = 'folder=' . urlencode($returnFolder);
    if ($status !== '') {
        $qs .= '&status=' . urlencode($status);
    }
    header('Location: /dashboards/messages.php?' . $qs);
    exit;
};

if (!in_array($action, $allowed_actions, true)) {
    $status = 'invalid_action';
    $redirectBack();
}

/**
 * 3) Action: empty_trash (não precisa de id)
 */
if ($action === 'empty_trash') {
    try {
        // Remove apenas os "vínculos" do user com mensagens na lixeira
        $stmt = $pdo->prepare("
            DELETE FROM mail_recipients
            WHERE user_id = ?
              AND COALESCE(folder, 'inbox') = 'trash'
        ");
        $stmt->execute([$current_user_id]);

        $status = 'trash_emptied';
    } catch (Throwable $e) {
        $status = 'error';
    }

    $redirectBack();
}

/**
 * 4) Para as outras ações, precisa ter id válido
 */
if ($id <= 0) {
    $status = 'invalid_id';
    $redirectBack();
}

/**
 * 5) Permission check: user precisa ser sender ou recipient
 */
$canStmt = $pdo->prepare("
    SELECT
        m.id,
        (m.sender_id = ?) AS is_sender,
        EXISTS(
            SELECT 1
            FROM mail_recipients r
            WHERE r.message_id = m.id
              AND r.user_id = ?
        ) AS is_recipient
    FROM mail_messages m
    WHERE m.id = ?
    LIMIT 1
");

$canStmt->execute([
    $current_user_id, // (m.sender_id = ?)
    $current_user_id, // r.user_id = ?
    $id,              // m.id = ?
]);

$can = $canStmt->fetch(PDO::FETCH_ASSOC);

$isSender    = (bool)($can && (int)$can['is_sender'] === 1);
$isRecipient = (bool)($can && (int)$can['is_recipient'] === 1);

if (!$can || (!$isSender && !$isRecipient)) {
    $status = 'no_permission';
    $redirectBack();
}

/**
 * 6) Execute action
 */
try {

    if ($action === 'archive') {

        // Inbox -> Archive (recipient)
        if ($isRecipient) {
            $stmt = $pdo->prepare("
                UPDATE mail_recipients
                   SET folder = 'archive'
                 WHERE user_id = ?
                   AND message_id = ?
            ");
            $stmt->execute([$current_user_id, $id]);
        }

        $status = 'archived';

    } elseif ($action === 'trash') {

        /**
         * Move -> Trash
         * - Recipient: atualiza o vínculo normal
         * - Sender (sent folder): cria/atualiza um vínculo “shadow” pro sender também conseguir “trashing” no UI
         */
        if ($isRecipient) {
            $stmt = $pdo->prepare("
                UPDATE mail_recipients
                   SET folder = 'trash'
                 WHERE user_id = ?
                   AND message_id = ?
            ");
            $stmt->execute([$current_user_id, $id]);
        } elseif ($isSender) {
            $stmt = $pdo->prepare("
                INSERT INTO mail_recipients (message_id, user_id, folder, is_read)
                VALUES (?, ?, 'trash', 1)
                ON DUPLICATE KEY UPDATE folder = 'trash'
            ");
            $stmt->execute([$id, $current_user_id]);
        }

        $status = 'trashed';

    } elseif ($action === 'restore') {

        // Recipient: volta inbox | Sender-shadow: volta sent
        $targetFolder = ($isSender && !$isRecipient) ? 'sent' : 'inbox';

        $stmt = $pdo->prepare("
            UPDATE mail_recipients
               SET folder = ?
             WHERE user_id = ?
               AND message_id = ?
        ");
        $stmt->execute([$targetFolder, $current_user_id, $id]);

        $status = 'restored';

    } elseif ($action === 'delete') {

        // Delete forever (marca deleted pro user)
        $stmt = $pdo->prepare("
            UPDATE mail_recipients
               SET folder = 'deleted'
             WHERE user_id = ?
               AND message_id = ?
        ");
        $stmt->execute([$current_user_id, $id]);

        $status = 'deleted';

    }

} catch (Throwable $e) {
    $status = 'error';
}

/**
 * ✅ volta para a pasta onde você estava + status
 */
$redirectBack();
