<?php
// /dashboards/send_mail.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();

$pdo = getPDO();
$sender_id = (int)($_SESSION['user_id'] ?? 0);

/**
 * Helper: redirect safe
 */
function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * 1) Somente POST
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_to('/dashboards/messages.php');
}

/**
 * 2) CSRF check (se token existir no session E veio no POST, valida)
 */
if (!empty($_SESSION['csrf_token']) && isset($_POST['csrf_token'])) {
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['flash_error'] = 'Invalid CSRF token.';
        redirect_to('/dashboards/messages.php');
    }
}

/**
 * 3) Campos
 */
$receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$subject     = trim((string)($_POST['subject'] ?? ''));
$body        = trim((string)($_POST['body'] ?? ''));
$thread_raw  = trim((string)($_POST['thread_id'] ?? ''));
$thread_id   = ($thread_raw !== '') ? (int)$thread_raw : null;

$errors = [];

if ($receiver_id <= 0) $errors[] = 'Invalid recipient.';
if ($subject === '')   $errors[] = 'Subject is required.';
if ($body === '')      $errors[] = 'Message body is required.';

if (!empty($errors)) {
    $_SESSION['flash_error'] = implode(' ', $errors);

    // se veio do chat, volta pro chat (thread root)
    if (!empty($thread_id) && $thread_id > 0) {
        redirect_to('/dashboards/view_message.php?id=' . (int)$thread_id);
    }

    redirect_to('/dashboards/messages.php');
}

/**
 * Para anexo (opcional)
 */
$attachmentSavedRelPath = null;

try {
    $pdo->beginTransaction();

    /**
     * 4) Validar destinatário existe
     */
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $check->execute([$receiver_id]);
    $receiverRow = $check->fetch(PDO::FETCH_ASSOC);

    if (!$receiverRow || empty($receiverRow['id'])) {
        throw new RuntimeException('Recipient not found.');
    }

    /**
     * 5) Insert mail_messages
     */
    $stmt = $pdo->prepare("
        INSERT INTO mail_messages (
            sender_id, subject, body, thread_id, created_at
        ) VALUES (
            :sender_id, :subject, :body, :thread_id, NOW()
        )
    ");

    $stmt->execute([
        'sender_id' => $sender_id,
        'subject'   => $subject,
        'body'      => $body,
        'thread_id' => $thread_id
    ]);

    $message_id = (int)$pdo->lastInsertId();

    // Nova thread → thread_id = message_id
    if ($thread_id === null) {
        $thread_id = $message_id;
        $upd = $pdo->prepare("UPDATE mail_messages SET thread_id = :t WHERE id = :id");
        $upd->execute(['t' => $thread_id, 'id' => $message_id]);
    }

    /**
     * 6) Inserir nas caixas (mail_recipients)
     */
    $stmtR = $pdo->prepare("
        INSERT INTO mail_recipients (message_id, user_id, folder, is_read)
        VALUES (:m, :u, :f, :r)
    ");

    // destinatário → inbox não lida
    $stmtR->execute([
        'm' => $message_id,
        'u' => $receiver_id,
        'f' => 'inbox',
        'r' => 0
    ]);

    // remetente → sent lida
    $stmtR->execute([
        'm' => $message_id,
        'u' => $sender_id,
        'f' => 'sent',
        'r' => 1
    ]);

    /**
     * 7) SALVAR ANEXO (opcional)
     */
    if (!empty($_FILES['attachment']['name'])) {

        $allowedMime = ['image/jpeg', 'image/png'];
        $allowedExt  = ['jpg', 'jpeg', 'png'];
        $maxSize     = 5 * 1024 * 1024; // 5 MB

        if (
            !isset($_FILES['attachment']['tmp_name']) ||
            !is_uploaded_file($_FILES['attachment']['tmp_name'])
        ) {
            throw new RuntimeException("Upload failed.");
        }

        if ((int)($_FILES['attachment']['size'] ?? 0) > $maxSize) {
            throw new RuntimeException("File too large (max 5MB).");
        }

        // MIME real do arquivo
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)($finfo->file($_FILES['attachment']['tmp_name']) ?: '');
        } else {
            $mime = (string)(mime_content_type($_FILES['attachment']['tmp_name']) ?: '');
        }

        if (!in_array($mime, $allowedMime, true)) {
            throw new RuntimeException("Invalid file type. JPEG or PNG only.");
        }

        // Extensão (só para nome do arquivo)
        $ext = strtolower((string)pathinfo((string)$_FILES['attachment']['name'], PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';

        // Se a extensão não bater com o MIME, força pela real
        if ($mime === 'image/png') {
            $ext = 'png';
        } elseif ($mime === 'image/jpeg') {
            $ext = 'jpg';
        }

        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException("Invalid file extension.");
        }

        // Nome seguro
        $rand = bin2hex(random_bytes(16));
        $safeName = 'att_' . date('Ymd_His') . '_' . $rand . '.' . $ext;

        // ✅ CORRIGIDO: salvar em pasta pública /assets/uploads/messages
        // (fica fora de /dashboards)
        $uploadDir = __DIR__ . '/../assets/uploads/messages';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException("Failed to create upload directory.");
            }
        }

        $destAbs = $uploadDir . '/' . $safeName;

        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $destAbs)) {
            throw new RuntimeException("Upload failed.");
        }

        // ✅ Caminho no padrão do asset_src(): uploads/... => /assets/uploads/...
        $attachmentSavedRelPath = "uploads/messages/" . $safeName;

        $stmtA = $pdo->prepare("
            INSERT INTO mail_attachments (message_id, file_path)
            VALUES (:mid, :path)
        ");
        $stmtA->execute([
            'mid'  => $message_id,
            'path' => $attachmentSavedRelPath
        ]);
    }

    /**
     * 8) Commit
     */
    $pdo->commit();

    // ✅ Flash + redirect de volta pro CHAT (thread root)
    $_SESSION['flash_success'] = 'Message sent!';

    // IMPORTANTE: voltar pro THREAD, não pra message_id
    redirect_to('/dashboards/view_message.php?id=' . (int)$thread_id . '&sent=1#bottom');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    $_SESSION['flash_error'] = 'Error sending message: ' . $e->getMessage();

    if (!empty($thread_id) && (int)$thread_id > 0) {
        redirect_to('/dashboards/view_message.php?id=' . (int)$thread_id);
    }

    redirect_to('/dashboards/messages.php');
}
