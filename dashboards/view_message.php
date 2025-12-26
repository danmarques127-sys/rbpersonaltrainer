<?php
// view_message.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client', 'pro', 'admin']);

$pdo = getPDO();

/**
 * Role helpers
 */
$role = (string)($_SESSION['role'] ?? '');
$isClient = in_array($role, ['user', 'client'], true);
$isPro    = ($role === 'pro');
$isAdmin  = ($role === 'admin');

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

/**
 * Asset normalizer (fix relative paths inside /dashboards/)
 * Accepts:
 *  - empty => placeholder
 *  - http(s) => keep
 *  - /assets/... => keep
 *  - assets/...  => /assets/...
 *  - /uploads/... or uploads/... (legacy) => /assets/uploads/...
 *  - ./assets/... , ../assets/... => /assets/...
 *  - ./uploads/... , ../uploads/... => /assets/uploads/...
 * Security:
 *  - blocks any remaining ".."
 *  - blocks "data:" URIs
 */
function asset_src(?string $url, string $placeholder): string
{
    $url = trim((string)$url);

    if ($url === '') {
        return $placeholder;
    }

    // ✅ normaliza path com "\" (Windows) para URL
    $url = str_replace('\\', '/', $url);

    // Block data URIs
    if (preg_match('~^data:~i', $url)) {
        return $placeholder;
    }

    // External absolute URL
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }

    // Normalize known leading relative prefixes safely
    $url = preg_replace('~^(?:\./)+~', '', $url);
    $url = preg_replace('~^(?:\../)+~', '', $url);

    // Harden traversal anywhere
    if (str_contains($url, '..')) {
        return $placeholder;
    }

    $u = ltrim($url, '/');

    if (str_starts_with($url, '/assets/')) {
        return $url;
    }

    if (str_starts_with($u, 'assets/')) {
        return '/' . $u;
    }

    if (str_starts_with($u, 'uploads/')) {
        return '/assets/' . $u; // => /assets/uploads/...
    }

    if (str_starts_with($url, '/')) {
        return $url;
    }

    return '/' . $u;
}

function avatar_src(?string $url): string
{
    return asset_src($url, '/assets/images/client-avatar-placeholder.jpg');
}

function attachment_src(?string $url): string
{
    return asset_src($url, '/assets/images/default-avatar.png');
}

/**
 * CSRF token (reply form uses POST)
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

/**
 * ✅ Flash messages (POST -> Redirect -> GET)
 */
$flash_success = '';
$flash_error   = '';

if (!empty($_SESSION['flash_success'])) {
    $flash_success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flash_error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

/**
 * ✅ Scroll to bottom after sending
 */
$scroll_to_bottom = (isset($_GET['sent']) && (string)$_GET['sent'] === '1');

/**
 * 2) Get message id
 */
$message_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($message_id <= 0) {
    header('Location: messages.php');
    exit;
}

/**
 * 3) Load current user
 */
$stmt = $pdo->prepare("
    SELECT id, name, role, avatar_url
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

/**
 * 4) Load message with security check
 */
$sql = "
    SELECT
        m.id,
        m.subject,
        m.body,
        m.created_at,
        m.thread_id,
        m.sender_id,
        u.name       AS sender_name,
        u.avatar_url AS sender_avatar
    FROM mail_messages m
    JOIN users u ON u.id = m.sender_id
    LEFT JOIN mail_recipients r
           ON r.message_id = m.id AND r.user_id = ?
    WHERE m.id = ?
      AND (m.sender_id = ? OR r.user_id IS NOT NULL)
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $current_user_id,
    $message_id,
    $current_user_id,
]);
$message = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$message) {
    header('Location: messages.php');
    exit;
}

/**
 * 5) Determine thread id (root)
 */
$thread_id = (int)(($message['thread_id'] ?? 0) ?: ($message['id'] ?? 0));

/**
 * ✅ 5.1) Descobrir "outro usuário" pela THREAD inteira (mais robusto)
 */
$other_user_id     = null;
$other_user_name   = null;
$other_user_avatar = null;

$sqlOther = "
    SELECT u.id, u.name, u.avatar_url
    FROM (
        SELECT sender_id AS uid
        FROM mail_messages
        WHERE (thread_id = ? OR id = ?)

        UNION

        SELECT r.user_id AS uid
        FROM mail_recipients r
        JOIN mail_messages m ON m.id = r.message_id
        WHERE (m.thread_id = ? OR m.id = ?)
    ) x
    JOIN users u ON u.id = x.uid
    WHERE x.uid <> ?
    LIMIT 1
";

$stmtO = $pdo->prepare($sqlOther);
$stmtO->execute([
    $thread_id,
    $thread_id,
    $thread_id,
    $thread_id,
    $current_user_id,
]);

$other = $stmtO->fetch(PDO::FETCH_ASSOC);

if ($other) {
    $other_user_id     = (int)$other['id'];
    $other_user_name   = (string)$other['name'];
    $other_user_avatar = (string)($other['avatar_url'] ?? '');
} else {
    // Fallback
    $other_user_id     = (int)($message['sender_id'] ?? 0);
    $other_user_name   = (string)($message['sender_name'] ?? 'User');
    $other_user_avatar = (string)($message['sender_avatar'] ?? '');
}

/**
 * 7) Mark messages as read
 */
$upd = $pdo->prepare("
    UPDATE mail_recipients r
    JOIN mail_messages m ON m.id = r.message_id
       SET r.is_read = 1
     WHERE r.user_id = ?
       AND (m.thread_id = ? OR m.id = ?)
");
$upd->execute([
    $current_user_id,
    $thread_id,
    $thread_id,
]);

/**
 * 8) Load full thread
 */
$sqlThread = "
    SELECT
        m.id,
        m.body,
        m.created_at,
        m.sender_id,
        u.name AS sender_name
    FROM mail_messages m
    JOIN users u ON u.id = m.sender_id
    WHERE m.thread_id = ? OR m.id = ?
    ORDER BY m.created_at ASC
";
$stmt = $pdo->prepare($sqlThread);
$stmt->execute([$thread_id, $thread_id]);
$threadMessages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/**
 * 9) Load attachments
 */
$attachmentsByMsg = [];
if (!empty($threadMessages)) {
    $ids = array_values(array_map('intval', array_column($threadMessages, 'id')));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sqlAtt = "
        SELECT id, message_id, file_path
        FROM mail_attachments
        WHERE message_id IN ($placeholders)
    ";
    $stmtAtt = $pdo->prepare($sqlAtt);
    $stmtAtt->execute($ids);

    while ($row = $stmtAtt->fetch(PDO::FETCH_ASSOC)) {
        $attachmentsByMsg[(int)$row['message_id']][] = $row;
    }
}

/**
 * 10) Unread counter
 */
$sqlUnread = "
    SELECT COUNT(*)
    FROM mail_recipients r
    WHERE r.user_id = ?
      AND COALESCE(r.folder, 'inbox') = 'inbox'
      AND r.is_read = 0
";
$stmtU = $pdo->prepare($sqlUnread);
$stmtU->execute([$current_user_id]);
$unread_inbox_count = (int)$stmtU->fetchColumn();

/**
 * 11) Reply subject
 */
$reply_subject = (string)($message['subject'] ?? '');
if (stripos($reply_subject, 're:') !== 0) {
    $reply_subject = 'Re: ' . $reply_subject;
}

// cache bust (imagens/JS)
$cachebust = (string)time();

// ✅ Avatar FINAL (fallback + normalização)
$avatarFallback = '/assets/images/client-avatar-placeholder.jpg';
$rawAvatar      = trim((string)($other_user_avatar ?? ''));
$avatarFinalSrc = ($rawAvatar !== '') ? avatar_src($rawAvatar) : $avatarFallback;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Conversation - <?php echo htmlspecialchars((string)$other_user_name, ENT_QUOTES, 'UTF-8'); ?> | RB Personal Trainer</title>
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
  <link rel="stylesheet" href="/assets/css/view_message.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">

  <!-- ✅ garante modal acima do header (mesmo se header tiver z-index alto) -->
  <style>
    #imgModal { z-index: 999999 !important; }
    #imgModalClose { z-index: 1000000 !important; }
    .msg-attachments img { cursor: zoom-in; }
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
            <li><a href="dashboard_client.php" class="rbf1-link">Dashboard</a></li>
            <li><a href="client_profile.php" class="rbf1-link">Profile</a></li>
            <li><a href="client_goals.php" class="rbf1-link">Goals</a></li>
            <li>
              <a href="messages.php" class="rbf1-link rbf1-link-active">
                Messages
                <?php if ($unread_inbox_count > 0): ?>
                  <span class="msg-nav-badge"><?php echo (int)$unread_inbox_count; ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li><a href="client_workouts.php" class="rbf1-link">Workout</a></li>
            <li><a href="client_nutrition.php" class="rbf1-link">Nutritionist</a></li>
            <li><a href="progress_gallery.php" class="rbf1-link">Photos Gallery</a></li>

        <?php elseif ($isPro): ?>
            <li><a href="dashboard_personal.php" class="rbf1-link">Dashboard</a></li>
            <li><a href="personal_profile.php" class="rbf1-link">Profile</a></li>
            <li><a href="personal_profile_edit.php" class="rbf1-link">Edit Profile</a></li>
            <li><a href="trainer_workouts.php" class="rbf1-link">Workouts</a></li>
            <li><a href="trainer_checkins.php" class="rbf1-link">Check-ins</a></li>
            <li>
              <a href="messages.php" class="rbf1-link rbf1-link-active">
                Messages
                <?php if ($unread_inbox_count > 0): ?>
                  <span class="msg-nav-badge"><?php echo (int)$unread_inbox_count; ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li><a href="trainer_clients.php" class="rbf1-link">Clients</a></li>

        <?php elseif ($isAdmin): ?>
            <li><a href="dashboard_admin.php" class="rbf1-link">Dashboard</a></li>
            <li>
              <a href="messages.php" class="rbf1-link rbf1-link-active">
                Messages
                <?php if ($unread_inbox_count > 0): ?>
                  <span class="msg-nav-badge"><?php echo (int)$unread_inbox_count; ?></span>
                <?php endif; ?>
              </a>
            </li>
        <?php endif; ?>

        <li class="mobile-only">
          <a href="../login.php" class="rb-mobile-logout">Logout</a>
        </li>

      </ul>
    </nav>

    <div class="rbf1-right">
      <a href="../login.php" class="rbf1-login">Logout</a>
    </div>

    <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">☰</button>
  </div>
</header>

<div class="msg-container">
<?php if (!empty($flash_success)): ?>
  <div class="msg-flash msg-flash-success">
    <?php echo htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8'); ?>
  </div>
<?php endif; ?>

<?php if (!empty($flash_error)): ?>
  <div class="msg-flash msg-flash-error">
    <?php echo htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8'); ?>
  </div>
<?php endif; ?>

    <div class="msg-user-header">
        <?php
          $safeOtherName = htmlspecialchars((string)$other_user_name, ENT_QUOTES, 'UTF-8');
          $avatarAlt = ($other_user_name !== null && $other_user_name !== '')
              ? 'Profile photo of ' . $safeOtherName
              : 'Client profile photo';
        ?>
        <img
          src="<?php echo htmlspecialchars((string)$avatarFinalSrc, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($cachebust, ENT_QUOTES, 'UTF-8'); ?>"
          alt="<?php echo htmlspecialchars($avatarAlt, ENT_QUOTES, 'UTF-8'); ?>"
          onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($avatarFallback, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($cachebust, ENT_QUOTES, 'UTF-8'); ?>';"
        >

        <div class="msg-user-info">
            <div class="msg-user-name">
                Conversation with <?php echo $safeOtherName; ?>
            </div>
            <div class="msg-user-subtitle">
                Subject: <?php echo htmlspecialchars((string)($message['subject'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    </div>

    <div class="msg-conversation-wrapper">

        <div class="msg-conversation-header-bar">
            <div class="msg-section-title">
                <span>Conversation</span>
                <h2>Full message history</h2>
            </div>

            <a href="messages.php" class="msg-btn-secondary">Back to messages</a>
        </div>

        <div class="msg-thread">

            <?php foreach ($threadMessages as $tm): ?>
                <?php
                    $isMine  = ((int)($tm['sender_id'] ?? 0) === $current_user_id);
                    $bubbleClass = $isMine ? 'msg-bubble-me' : 'msg-bubble-other';
                    $label   = $isMine ? 'You' : (string)($tm['sender_name'] ?? '');
                    $created = (string)($tm['created_at'] ?? '');
                    $dateTs  = $created !== '' ? strtotime($created) : false;
                    $dateStr = $dateTs ? date('d/m/Y H:i', $dateTs) : '';
                    $msgId   = (int)($tm['id'] ?? 0);

                    $attFallback = '/assets/images/default-avatar.png';
                ?>
                <div class="msg-bubble-row <?php echo htmlspecialchars($bubbleClass, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="msg-bubble-meta">
                        <span class="msg-bubble-author"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="msg-bubble-time"><?php echo htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <div class="msg-bubble-body">
                        <?php echo nl2br(htmlspecialchars((string)($tm['body'] ?? ''), ENT_QUOTES, 'UTF-8')); ?>
                    </div>

                    <?php if (!empty($attachmentsByMsg[$msgId] ?? [])): ?>
                        <div class="msg-attachments">
                            <?php foreach (($attachmentsByMsg[$msgId] ?? []) as $att): ?>
                                <?php
                                  $rawPath = (string)($att['file_path'] ?? '');
                                  $attSrc  = attachment_src($rawPath);
                                ?>
                                <img
                                  src="<?php echo htmlspecialchars((string)$attSrc, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($cachebust, ENT_QUOTES, 'UTF-8'); ?>"
                                  alt="Attachment"
                                  onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($attFallback, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($cachebust, ENT_QUOTES, 'UTF-8'); ?>';"
                                >
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>

            <!-- ✅ anchor pro scroll -->
            <div id="bottom"></div>

        </div>

        <div class="msg-reply-wrapper">
            <h3 class="msg-reply-title">Reply</h3>

            <form action="send_mail.php" method="post" enctype="multipart/form-data" class="msg-reply-form">

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="receiver_id" value="<?php echo (int)$other_user_id; ?>">
                <input type="hidden" name="subject"     value="<?php echo htmlspecialchars((string)$reply_subject, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="thread_id"   value="<?php echo (int)$thread_id; ?>">

                <div class="msg-field msg-attachment-field">
                    <label class="msg-label">Add Image (optional)</label>
                    <input type="file" name="attachment" accept="image/*" class="msg-file-input">
                </div>

                <div class="msg-field">
                    <label for="body" class="msg-label">Message</label>
                    <textarea id="body" name="body" rows="5" class="msg-textarea" required></textarea>
                </div>

                <div class="msg-form-actions">
                    <a href="messages.php" class="msg-btn-secondary">Back</a>
                    <button type="submit" class="msg-btn-primary">Send Reply</button>
                </div>
            </form>
        </div>

    </div>
</div>

<div id="imgModal">
    <span id="imgModalClose">&times;</span>
    <img id="imgModalContent" src="" alt="Attachment">
</div>

<script src="/assets/js/script.js?v=<?php echo htmlspecialchars($cachebust, ENT_QUOTES, 'UTF-8'); ?>"></script>

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

  document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('imgModal');
    const modalImg = document.getElementById('imgModalContent');
    const closeBtn = document.getElementById('imgModalClose');

    // ✅ Event delegation: mais robusto que querySelectorAll + addEventListener
    document.addEventListener('click', function (e) {
      const img = e.target.closest('.msg-attachments img');
      if (!img) return;

      modalImg.src = img.src;
      modal.style.display = 'block';
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        modal.style.display = 'none';
        modalImg.src = '';
      });
    }

    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) {
          modal.style.display = 'none';
          modalImg.src = '';
        }
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && modal.style.display === 'block') {
        modal.style.display = 'none';
        modalImg.src = '';
      }
    });

    // ✅ Scroll to bottom after redirect (?sent=1)
    <?php if (!empty($scroll_to_bottom)): ?>
      const bottom = document.getElementById('bottom');
      if (bottom) bottom.scrollIntoView({ behavior: 'smooth', block: 'end' });
    <?php endif; ?>
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
        <?php if ($isClient): ?>
          <li><a href="dashboard_client.php">Dashboard</a></li>
          <li><a href="client_profile.php">Profile</a></li>
          <li><a href="client_goals.php">Goals</a></li>
          <li><a href="messages.php">Messages</a></li>
          <li><a href="client_workouts.php">Workout</a></li>
          <li><a href="client_nutrition.php">Nutritionist</a></li>
          <li><a href="progress_gallery.php">Photos Gallery</a></li>
        <?php elseif ($isPro): ?>
          <li><a href="dashboard_personal.php">Dashboard</a></li>
          <li><a href="personal_profile.php">Profile</a></li>
          <li><a href="personal_profile_edit.php">Edit Profile</a></li>
          <li><a href="trainer_workouts.php">Workouts</a></li>
          <li><a href="trainer_checkins.php">Check-ins</a></li>
          <li><a href="messages.php">Messages</a></li>
          <li><a href="trainer_clients.php">Clients</a></li>
        <?php else: ?>
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
    <p class="footer-bottom-text">© 2025 RB Personal Trainer. All rights reserved.</p>
  </div>
</footer>

</body>
</html>
