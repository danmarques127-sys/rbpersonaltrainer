<?php
// messages.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role            = (string)($_SESSION['role'] ?? '');

/**
 * CSRF token for message actions
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

/**
 * 2) Load logged user data
 */
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        role,
        avatar_url
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$isClient = in_array($role, ['user', 'client'], true);
$isPro    = ($role === 'pro');
$isAdmin  = ($role === 'admin');

/**
 * 3) Current folder (inbox/sent/archive/trash)
 */
$allowed_folders = ['inbox', 'sent', 'archive', 'trash'];
$folder = (isset($_GET['folder']) && in_array($_GET['folder'], $allowed_folders, true))
    ? (string)$_GET['folder']
    : 'inbox';

/**
 * 3.1) Feedback (mail/status) - mostra resultado após ações e envio
 */
$mailParam   = isset($_GET['mail']) ? (string)$_GET['mail'] : '';
$statusParam = isset($_GET['status']) ? (string)$_GET['status'] : '';

$flash = null; // ['type' => 'success|warning|error|info', 'title' => '', 'msg' => '']

if ($mailParam !== '') {
    if ($mailParam === '1') {
        $flash = [
            'type'  => 'success',
            'title' => 'Message sent',
            'msg'   => 'Your message was saved and the email notification was delivered.',
        ];
    } elseif ($mailParam === '0') {
        $flash = [
            'type'  => 'warning',
            'title' => 'Message sent (email not delivered)',
            'msg'   => 'Your message was saved, but the email notification could not be delivered (SMTP/config).',
        ];
    }
}

if ($flash === null && $statusParam !== '') {
    // Feedback vindo do message_action.php
    $map = [
        'archived'       => ['success', 'Archived', 'Message moved to archive.'],
        'trashed'        => ['success', 'Moved to trash', 'Message moved to trash.'],
        'restored'       => ['success', 'Restored', 'Message restored successfully.'],
        'deleted'        => ['warning', 'Deleted forever', 'Message was removed for your account.'],
        'trash_emptied'  => ['warning', 'Trash emptied', 'All trash messages were removed for your account.'],

        'invalid_action' => ['error', 'Invalid action', 'The requested action is not allowed.'],
        'invalid_id'     => ['error', 'Invalid message', 'The message id is invalid.'],
        'no_permission'  => ['error', 'No permission', 'You do not have permission to modify this message.'],
        'error'          => ['error', 'Error', 'Something went wrong while processing your request.'],
    ];

    if (isset($map[$statusParam])) {
        [$type, $title, $msg] = $map[$statusParam];
        $flash = [
            'type'  => $type,
            'title' => $title,
            'msg'   => $msg,
        ];
    }
}

/**
 * 4) Unread inbox count (para badges)
 */
$sqlUnread = "
    SELECT COUNT(*)
    FROM mail_recipients r
    JOIN mail_messages m ON m.id = r.message_id
    WHERE r.user_id = :uid
      AND COALESCE(r.folder, 'inbox') = 'inbox'
      AND r.is_read = 0
      AND (m.thread_id IS NULL OR m.id = m.thread_id)
";
$stmtU = $pdo->prepare($sqlUnread);
$stmtU->execute(['uid' => $current_user_id]);
$unread_inbox_count = (int)$stmtU->fetchColumn();

/**
 * 5) Fetch messages for this folder
 *  - inbox / archive / trash : user é destinatário
 *  - sent                    : user é remetente
 *  - mostrar apenas a raiz da thread
 *
 * ✅ FIX: Sent agora respeita o "trash" e o "deleted" do próprio user (shadow) em mail_recipients,
 *        então ao mover pra lixeira ele some do Sent, e ao "delete forever" ele não volta pro Sent.
 */
if ($folder === 'sent') {
    $sql = "
        SELECT
            m.id,
            m.subject,
            m.body,
            m.created_at,
            m.thread_id,
            1 AS is_read,
            COALESCE(
                GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', '),
                '(no recipient)'
            ) AS other_party_name,
            NULL AS other_party_avatar
        FROM mail_messages m
        LEFT JOIN mail_recipients r
               ON r.message_id = m.id
        LEFT JOIN users u
               ON u.id = r.user_id
        WHERE m.sender_id = :uid_sender
          AND (m.thread_id IS NULL OR m.id = m.thread_id)
          AND NOT EXISTS (
              SELECT 1
              FROM mail_recipients r2
              WHERE r2.message_id = m.id
                AND r2.user_id = :uid_shadow
                AND COALESCE(r2.folder, 'inbox') = 'trash'
          )
          AND NOT EXISTS (
              SELECT 1
              FROM mail_recipients r3
              WHERE r3.message_id = m.id
                AND r3.user_id = :uid_shadow2
                AND COALESCE(r3.folder, 'inbox') = 'deleted'
          )
        GROUP BY m.id, m.subject, m.body, m.created_at, m.thread_id
        ORDER BY m.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'uid_sender'  => $current_user_id,
        'uid_shadow'  => $current_user_id,
        'uid_shadow2' => $current_user_id,
    ]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $name_field   = 'other_party_name';
    $avatar_field = 'other_party_avatar';
} else {
    $sql = "
        SELECT
            m.id,
            m.subject,
            m.body,
            m.created_at,
            m.thread_id,
            r.is_read,
            u.name       AS other_party_name,
            u.avatar_url AS other_party_avatar
        FROM mail_recipients r
        JOIN mail_messages m ON m.id = r.message_id
        JOIN users u         ON u.id = m.sender_id
        WHERE r.user_id = :uid
          AND COALESCE(r.folder, 'inbox') = :folder
          AND (m.thread_id IS NULL OR m.id = m.thread_id)
        ORDER BY m.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'uid'    => $current_user_id,
        'folder' => $folder,
    ]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $name_field   = 'other_party_name';
    $avatar_field = 'other_party_avatar';
}

/**
 * 6) Snippet generator
 */
function make_snippet(string $text, int $len = 80): string
{
    $plain = strip_tags($text);
    if (mb_strlen($plain) <= $len) return $plain;
    return mb_substr($plain, 0, $len) . '...';
}

$avatarFallback = '/assets/images/default-avatar.png';
$currentAvatar  = trim((string)($current_user['avatar_url'] ?? ''));
$avatarSrc      = ($currentAvatar !== '') ? $currentAvatar : $avatarFallback;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Messages | RB Personal Trainer | RB Coaching</title>
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

  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/messages.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">

  <!-- Badges + ações -->
  <style>
    .msg-badge,
    .msg-nav-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 18px;
      padding: 0 8px;
      margin-left: 6px;
      border-radius: 999px;
      background: #ff7a00;
      color: #ffffff;
      font-size: 0.7rem;
      line-height: 1.4;
      font-weight: 600;
      vertical-align: middle;
    }
    .msg-folder-link { position: relative; }

    .msg-row-wrapper {
      display: flex;
      align-items: stretch;
      justify-content: space-between;
      gap: 12px;
      padding: 8px 0;
      border-bottom: 1px solid rgba(255,255,255,0.04);
    }
    .msg-row-link { flex: 1; text-decoration: none; }
    .msg-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 14px;
      border-radius: 10px;
      background: #050811;
      transition: background 0.15s ease, transform 0.12s ease;
    }
    .msg-row:hover { background: #0b0f1e; transform: translateY(-1px); }
    .msg-row-unread { box-shadow: 0 0 0 1px rgba(255, 153, 51, 0.5); }

    .msg-row-actions {
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 4px;
      min-width: 120px;
      text-align: right;
      font-size: 0.75rem;
    }

    .msg-action-form { display: inline; }
    .msg-row-action-btn {
      appearance: none;
      border: 0;
      background: transparent;
      padding: 2px 4px;
      cursor: pointer;
      color: #8d94b8;
      text-decoration: none;
      font: inherit;
      text-align: right;
    }
    .msg-row-action-btn:hover { color: #ffffff; text-decoration: underline; }
    .msg-row-action-danger { color: #ff7a00; }

    .msg-bulk-bar {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 8px;
      margin-bottom: 10px;
      margin-top: -4px;
    }
    .msg-bulk-bar form { display: inline; }
    .msg-small-btn {
      border-radius: 999px;
      padding: 4px 10px;
      border: 1px solid #2c3345;
      background: #050811;
      color: #d7defc;
      font-size: 0.75rem;
      cursor: pointer;
      transition: background 0.15s ease, color 0.15s ease, border 0.15s ease;
    }
    .msg-small-btn:hover {
      background: #0f172a;
      color: #ffffff;
      border-color: #ff7a00;
    }
    .msg-small-btn-danger {
      border-color: #ff7a00;
      color: #ffb27a;
    }

    /* ✅ Feedback banner */
    .msg-flash {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.08);
      background: #050811;
      margin: 10px 0 14px 0;
    }
    .msg-flash-title {
      font-weight: 700;
      margin: 0 0 2px 0;
      font-size: 0.95rem;
      color: #ffffff;
    }
    .msg-flash-text {
      margin: 0;
      color: #c9d2f1;
      font-size: 0.9rem;
      line-height: 1.35;
    }
    .msg-flash-success { border-color: rgba(34,197,94,0.35); box-shadow: 0 0 0 1px rgba(34,197,94,0.15) inset; }
    .msg-flash-warning { border-color: rgba(255,122,0,0.45); box-shadow: 0 0 0 1px rgba(255,122,0,0.18) inset; }
    .msg-flash-error   { border-color: rgba(239,68,68,0.45); box-shadow: 0 0 0 1px rgba(239,68,68,0.18) inset; }
    .msg-flash-info    { border-color: rgba(59,130,246,0.45); box-shadow: 0 0 0 1px rgba(59,130,246,0.18) inset; }

    .msg-flash-close {
      appearance: none;
      border: 0;
      background: transparent;
      color: #8d94b8;
      cursor: pointer;
      font-size: 1.1rem;
      line-height: 1;
      padding: 2px 6px;
      border-radius: 8px;
    }
    .msg-flash-close:hover { color: #ffffff; background: rgba(255,255,255,0.06); }
  </style>
</head>
<body>

<header id="rb-static-header" class="rbf1-header">
  <div class="rbf1-topbar">
    <a href="/" class="rbf1-logo">
      <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
    </a>

    <nav class="rbf1-nav" id="rbf1-nav">
      <ul>
        <?php if ($isClient): ?>
          <li><a href="dashboard_client.php">Dashboard</a></li>
          <li><a href="client_profile.php">Profile</a></li>
          <li><a href="client_goals.php">Goals</a></li>
          <li>
            <a href="messages.php" class="rbf1-link rbf1-link-active">
              Messages
              <?php if ($unread_inbox_count > 0): ?>
                <span class="msg-nav-badge"><?php echo (int)$unread_inbox_count; ?></span>
              <?php endif; ?>
            </a>
          </li>
          <li><a href="client_workouts.php">Workout</a></li>
          <li><a href="client_nutrition.php">Nutritionist</a></li>
          <li><a href="progress_gallery.php">Photos Gallery</a></li>

        <?php elseif ($isPro): ?>
          <li><a href="dashboard_personal.php">Dashboard</a></li>
          <li><a href="personal_profile.php">Profile</a></li>
          <li><a href="trainer_workouts.php">Workouts</a></li>
          <li><a href="trainer_checkins.php">Check-ins</a></li>
          <li>
            <a href="messages.php" class="rbf1-link rbf1-link-active">
              Messages
              <?php if ($unread_inbox_count > 0): ?>
                <span class="msg-nav-badge"><?php echo (int)$unread_inbox_count; ?></span>
              <?php endif; ?>
            </a>
          </li>
          <li><a href="trainer_clients.php">Clients</a></li>

        <?php elseif ($isAdmin): ?>
          <li><a href="dashboard_admin.php">Dashboard</a></li>
          <li>
            <a href="messages.php" class="rbf1-link rbf1-link-active">
              Messages
              <?php if ($unread_inbox_count > 0): ?>
                <span class="msg-nav-badge"><?php echo (int)$unread_inbox_count; ?></span>
              <?php endif; ?>
            </a>
          </li>
        <?php endif; ?>

        <!-- Logout mantém login.php como você pediu -->
        <li class="mobile-only">
          <a href="../login.php" class="rb-mobile-logout">Logout</a>
        </li>
      </ul>
    </nav>

    <div class="rbf1-right">
      <a href="../login.php" class="rbf1-login">Logout</a>
    </div>

    <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
      ☰
    </button>
  </div>
</header>

<div class="msg-container">

    <!-- ✅ Feedback depois de enviar/ações -->
    <?php if (!empty($flash)): ?>
      <div class="msg-flash msg-flash-<?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>" id="msgFlash">
        <div>
          <p class="msg-flash-title"><?php echo htmlspecialchars((string)$flash['title'], ENT_QUOTES, 'UTF-8'); ?></p>
          <p class="msg-flash-text"><?php echo htmlspecialchars((string)$flash['msg'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <button type="button" class="msg-flash-close" aria-label="Close" id="msgFlashClose">×</button>
      </div>
    <?php endif; ?>

    <!-- User header -->
    <div class="msg-user-header">
        <img src="<?php echo htmlspecialchars((string)$avatarSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile photo">
        <div class="msg-user-info">
            <div class="msg-user-name"><?php echo htmlspecialchars((string)($current_user['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="msg-user-subtitle">
              <?php if ($isPro): ?>
                RB Trainer Internal Messages
              <?php else: ?>
                RB Client Internal Messages
              <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Folders + new message -->
    <div class="msg-top-bar">
        <div class="msg-folders">
            <a href="messages.php?folder=inbox"
               class="msg-folder-link <?php echo $folder === 'inbox' ? 'active' : ''; ?>">
                Inbox
                <?php if ($unread_inbox_count > 0): ?>
                  <span class="msg-badge"><?php echo (int)$unread_inbox_count; ?></span>
                <?php endif; ?>
            </a>

            <a href="messages.php?folder=sent"
               class="msg-folder-link <?php echo $folder === 'sent' ? 'active' : ''; ?>">
                Sent
            </a>

            <a href="messages.php?folder=archive"
               class="msg-folder-link <?php echo $folder === 'archive' ? 'active' : ''; ?>">
                Archive
            </a>

            <a href="messages.php?folder=trash"
               class="msg-folder-link <?php echo $folder === 'trash' ? 'active' : ''; ?>">
                Trash
            </a>
        </div>

        <a href="compose.php" class="msg-btn-primary">New message</a>
    </div>

    <!-- Barra para esvaziar lixeira -->
    <?php if ($folder === 'trash'): ?>
      <div class="msg-bulk-bar">
        <form action="message_action.php" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="empty_trash">
          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>">
          <button type="submit" class="msg-small-btn msg-small-btn-danger">
            Empty trash
          </button>
        </form>
      </div>
    <?php endif; ?>

    <!-- Message list -->
    <?php if (empty($messages)): ?>
        <p class="msg-empty">No messages in this folder.</p>
    <?php else: ?>
        <?php foreach ($messages as $row): ?>
            <?php
                $snippet   = make_snippet((string)($row['body'] ?? ''));
                $date      = date('d/m/Y H:i', strtotime((string)($row['created_at'] ?? 'now')));
                $is_unread = ((int)($row['is_read'] ?? 1) === 0);
                $msgId     = (int)($row['id'] ?? 0);
            ?>
            <div class="msg-row-wrapper">

                <a href="view_message.php?id=<?php echo $msgId; ?>" class="msg-row-link">
                    <div class="msg-row <?php echo $is_unread ? 'msg-row-unread' : ''; ?>">
                        <div class="msg-row-info">
                            <div class="msg-row-subject">
                                <?php echo htmlspecialchars((string)($row[$name_field] ?? ''), ENT_QUOTES, 'UTF-8'); ?> –
                                <?php echo htmlspecialchars((string)($row['subject'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="msg-row-snippet">
                                <?php echo htmlspecialchars((string)$snippet, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        <div class="msg-row-date"><?php echo htmlspecialchars((string)$date, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </a>

                <div class="msg-row-actions">
                    <?php if ($folder === 'inbox'): ?>

                        <form class="msg-action-form" action="message_action.php" method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="action" value="archive">
                          <input type="hidden" name="id" value="<?php echo $msgId; ?>">
                          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="msg-row-action-btn">Archive</button>
                        </form>

                        <form class="msg-action-form" action="message_action.php" method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="action" value="trash">
                          <input type="hidden" name="id" value="<?php echo $msgId; ?>">
                          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="msg-row-action-btn msg-row-action-danger">Move to trash</button>
                        </form>

                    <?php elseif ($folder === 'archive'): ?>

                        <form class="msg-action-form" action="message_action.php" method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="action" value="restore">
                          <input type="hidden" name="id" value="<?php echo $msgId; ?>">
                          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="msg-row-action-btn">Move to inbox</button>
                        </form>

                        <form class="msg-action-form" action="message_action.php" method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="action" value="trash">
                          <input type="hidden" name="id" value="<?php echo $msgId; ?>">
                          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="msg-row-action-btn msg-row-action-danger">Move to trash</button>
                        </form>

                    <?php elseif ($folder === 'trash'): ?>

                        <form class="msg-action-form" action="message_action.php" method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="action" value="restore">
                          <input type="hidden" name="id" value="<?php echo $msgId; ?>">
                          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="msg-row-action-btn">Restore</button>
                        </form>

                        <form class="msg-action-form" action="message_action.php" method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?php echo $msgId; ?>">
                          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="msg-row-action-btn msg-row-action-danger">Delete forever</button>
                        </form>

                    <?php elseif ($folder === 'sent'): ?>

                        <form class="msg-action-form" action="message_action.php" method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="action" value="trash">
                          <input type="hidden" name="id" value="<?php echo $msgId; ?>">
                          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="msg-row-action-btn msg-row-action-danger">Move to trash</button>
                        </form>

                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

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
        <?php if ($isClient): ?>
          <li><a href="dashboard_client.php">Dashboard</a></li>
          <li><a href="client_profile.php">Profile</a></li>
          <li><a href="client_goals.php">Goals</a></li>
          <li><a href="client_workouts.php">Workouts</a></li>
          <li><a href="client_nutrition.php">Nutrition</a></li>
          <li><a href="messages.php">Messages</a></li>

        <?php elseif ($isPro): ?>
          <li><a href="dashboard_personal.php">Dashboard</a></li>
          <li><a href="personal_profile.php">Profile</a></li>
          <li><a href="trainer_workouts.php">Workouts</a></li>
          <li><a href="trainer_checkins.php">Check-ins</a></li>
          <li><a href="trainer_clients.php">Clients</a></li>
          <li><a href="messages.php">Messages</a></li>

        <?php elseif ($isAdmin): ?>
          <li><a href="dashboard_admin.php">Dashboard</a></li>
          <li><a href="messages.php">Messages</a></li>
        <?php endif; ?>
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
    <p class="footer-bottom-text">
      © 2025 RB Personal Trainer. All rights reserved.
    </p>
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

    // close flash
    const closeBtn = document.getElementById('msgFlashClose');
    const flashEl  = document.getElementById('msgFlash');
    if (closeBtn && flashEl) {
      closeBtn.addEventListener('click', function () {
        flashEl.style.display = 'none';
      });
    }
  })();
</script>

</body>
</html>
