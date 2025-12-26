<?php
// /register_client.php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

$pdo = getPDO();

/**
 * CSRF
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

$token   = (string)($_GET['token'] ?? '');
$error   = '';
$success = '';
$invite  = null;

// Se for POST, também pega o token do hidden
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['token'] ?? $token);
}

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * 1) Carrega o convite, se tiver token
 */
if ($token !== '') {
    $stmt = $pdo->prepare("
        SELECT id, email, role, coach_id, token, expires_at, used_at
        FROM invites
        WHERE token = :token
          AND role = 'client'
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invite) {
        $error = "This invite link is invalid or does not exist.";
    } else {
        $now = date('Y-m-d H:i:s');

        if (!empty($invite['expires_at']) && (string)$invite['expires_at'] < $now) {
            $error = "This invite link has expired. Please ask your coach for a new link.";
        } elseif (!empty($invite['used_at'])) {
            $error = "This invite link has already been used. Please login or ask your coach for a new invite.";
        }
    }
}

/**
 * 2) Processa o cadastro
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validate
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if ($posted_csrf === '' || !hash_equals($csrf_token, $posted_csrf)) {
        $error = "Security validation failed. Please refresh the page and try again.";
    }

    $name         = trim((string)($_POST['name'] ?? ''));
    $username     = trim((string)($_POST['username'] ?? ''));
    $email        = trim((string)($_POST['email'] ?? ''));
    $phone        = trim((string)($_POST['phone'] ?? ''));
    $birthday_raw = trim((string)($_POST['birthday'] ?? ''));
    $password     = (string)($_POST['password'] ?? '');
    $confirm      = (string)($_POST['password_confirm'] ?? '');

    // Se veio de convite, força o email ser o do convite
    if ($invite && !empty($invite['email'])) {
        $email = (string)$invite['email'];
    }

    // normalizar birthday (input type="date" => YYYY-MM-DD)
    $birthday = null;
    if ($error === '' && $birthday_raw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $birthday_raw);
        if ($dt && $dt->format('Y-m-d') === $birthday_raw) {
            $birthday = $dt->format('Y-m-d');
        } else {
            $error = "Please enter a valid birth date.";
        }
    }

    // Se o link de convite é inválido/expirado, bloqueia o cadastro por esse link
    if ($error === '' && $token !== '' && !$invite) {
        $error = "This invite link is not valid.";
    }

    // validações básicas
    if ($error === '') {
        if ($name === '' || $username === '' || $email === '' || $password === '' || $confirm === '') {
            $error = "Please fill in all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (mb_strlen($username) < 3) {
            $error = "Username must have at least 3 characters.";
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match.";
        }
    }

    if ($error === '') {
        try {
            // Verificar se já existe email ou username
            $check = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ? OR username = ?
                LIMIT 1
            ");
            $check->execute([$email, $username]);

            if ($check->fetch()) {
                $error = "An account with this email or username already exists. Please login.";
            } else {
                // Hash da senha
                $hash = password_hash($password, PASSWORD_DEFAULT);

                // Inserir na tabela users como 'user' (cliente)
                $insert = $pdo->prepare("
                    INSERT INTO users
                        (name, username, email, password_hash, role, status, phone, birthday, created_at, email_verified, phone_verified)
                    VALUES
                        (?,    ?,        ?,     ?,            'user', 'active', ?,    ?,        NOW(),       0,             0)
                ");
                $insert->execute([
                    $name,
                    $username,
                    $email,
                    $hash,
                    $phone,
                    $birthday
                ]);

                $userId = (int)$pdo->lastInsertId();

                // Se veio de convite com coach_id, cria o vínculo coach ↔ client e marca convite como usado
                if ($invite && !empty($invite['coach_id'])) {
                    $coachId = (int)$invite['coach_id'];

                    $linkStmt = $pdo->prepare("
                        INSERT IGNORE INTO coach_clients (coach_id, client_id)
                        VALUES (:coach_id, :client_id)
                    ");
                    $linkStmt->execute([
                        ':coach_id'  => $coachId,
                        ':client_id' => $userId,
                    ]);

                    $usedStmt = $pdo->prepare("
                        UPDATE invites
                        SET used_at = NOW()
                        WHERE id = :id
                    ");
                    $usedStmt->execute([':id' => (int)$invite['id']]);
                }

                // Login automático após registro
                session_regenerate_id(true);
                $_SESSION['user_id']   = $userId;
                $_SESSION['user_name'] = $name;
                $_SESSION['role']      = 'user';

                // Rotação CSRF após ação sensível
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header("Location: /dashboards/dashboard_client.php?welcome=1");
                exit();
            }
        } catch (Throwable $e) {
            $error = "Error creating account. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Online Personal Trainer | RB Personal Trainer | RB Coaching</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- SEO BASICS -->
  <meta name="description" content="Online personal training and coaching with RB Team (RB Personal Trainer), a Boston-based personal trainer helping clients all over the United States transform their body, health and lifestyle with customized workout and nutrition guidance." />
  <meta name="author" content="RB Team" />
  <meta name="theme-color" content="#FF7A00" />

  <!-- CANONICAL -->
  <link rel="canonical" href="https://www.rbpersonaltrainer.com/" />

  <!-- FAVICONS -->
  <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
  <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
  <link rel="manifest" href="/assets/images/site.webmanifest">
  <meta name="msapplication-TileColor" content="#FF7A00">
  <meta name="msapplication-TileImage" content="/assets/images/mstile-150x150.png">

  <!-- OPEN GRAPH / FACEBOOK -->
  <meta property="og:type" content="website" />
  <meta property="og:title" content="RB Personal Trainer | Online Coaching with RB Team" />
  <meta property="og:description" content="Transform your body and your lifestyle with RB Personal Trainer. Online coaching, custom workout plans and ongoing support, based in the Boston area and serving clients across the United States." />
  <meta property="og:url" content="https://www.rbpersonaltrainer.com/" />
  <meta property="og:site_name" content="RB Personal Trainer" />
  <meta property="og:locale" content="en_US" />
  <meta property="og:image" content="https://www.rbpersonaltrainer.com/images/og-rb-personal-trainer.jpg" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />

  <!-- TWITTER CARDS -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="RB Personal Trainer | Online Personal Training with RB Team" />
  <meta name="twitter:description" content="Online coaching, customized training plans and ongoing support with RB Personal Trainer. Boston-based, serving high-end clients online across the United States." />
  <meta name="twitter:image" content="https://www.rbpersonaltrainer.com/images/og-rb-personal-trainer.jpg" />
  <meta name="twitter:creator" content="@rbpersonaltrainer" />

  <!-- GEO / LOCAL -->
  <meta name="geo.region" content="US-MA" />
  <meta name="geo.placename" content="Boston" />

  <!-- CSS -->
  <link rel="stylesheet" href="/assets/css/global.css" />
  <link rel="stylesheet" href="/assets/css/register.css" />
  <link rel="stylesheet" href="/assets/css/footer.css" />
  <link rel="stylesheet" href="/assets/css/header.css" />
</head>
<body>
<header id="rb-static-header" class="rbf1-header">
  <div class="rbf1-topbar">
    <a href="/" class="rbf1-logo">
      <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
    </a>

    <nav class="rbf1-nav" id="rbf1-nav">
      <ul>
        <li><a href="/index.php" class="rbf1-link rbf1-link-active">Home</a></li>
        <li><a href="/about.html">About</a></li>
        <li><a href="/services.html">Services</a></li>
        <li><a href="/blog.html">Blog</a></li>
        <li><a href="/testimonials.html">Testimonials</a></li>
        <li><a href="/contact.html">Contact</a></li>
        <li class="mobile-only">
          <a href="login.php" class="rb-mobile-logout">Login</a>
        </li>
      </ul>
    </nav>

    <div class="rbf1-right">
      <a href="login.php" class="rbf1-login">Login</a>
    </div>

    <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
      ☰
    </button>
  </div>
</header>

<main class="register-main">
  <div class="register-wrapper">
    <div class="register-card">
      <p class="register-eyebrow">Client Registration</p>
      <h1 class="register-title">Create your client account</h1>
      <p class="register-subtitle">
        This page is only for clients who received an invite link from their coach.
      </p>

      <?php if ($error !== ''): ?>
        <div class="register-message" style="display:block; color:#ff6b6b; margin-bottom:10px;">
          <?php echo e($error); ?>
        </div>
      <?php endif; ?>

      <form method="post" action="register_client.php<?php echo $token !== '' ? ('?token=' . rawurlencode($token)) : ''; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
        <input type="hidden" name="token" value="<?php echo e($token); ?>">

        <label class="register-label" for="name">Full name</label>
        <input class="register-input" type="text" id="name" name="name" required>

        <label class="register-label" for="username">Username</label>
        <input class="register-input" type="text" id="username" name="username" required>

        <label class="register-label" for="email">Email</label>
        <input
          class="register-input"
          type="email"
          id="email"
          name="email"
          required
          value="<?php echo e((string)($invite['email'] ?? '')); ?>"
          <?php echo ($invite && !empty($invite['email'])) ? 'readonly' : ''; ?>
        >

        <label class="register-label" for="phone">Phone number</label>
        <input class="register-input" type="tel" id="phone" name="phone" placeholder="+1 (555) 123-4567">

        <label class="register-label" for="birthday">Date of birth</label>
        <input class="register-input" type="date" id="birthday" name="birthday">

        <label class="register-label" for="password">Password</label>
        <input class="register-input" type="password" id="password" name="password" required>

        <label class="register-label" for="password_confirm">Confirm password</label>
        <input class="register-input" type="password" id="password_confirm" name="password_confirm" required>

        <button class="register-button" type="submit">
          Create account
        </button>

        <p class="register-login-hint">
          Already have an account?
          <a href="login.php">Login</a>
        </p>
      </form>
    </div>
  </div>
</main>

<footer class="site-footer">
  <div class="footer-cta">
    <div class="footer-cta-inner">
      <p class="footer-cta-eyebrow">Online Personal Training</p>
      <h2 class="footer-cta-title">
        Ready to go full throttle in your transformation?
      </h2>
      <a href="/contact.html" class="footer-cta-button">
        Contact Us
      </a>
    </div>
  </div>

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
        <li><a href="/">Home</a></li>
        <li><a href="/about.html">About</a></li>
        <li><a href="/services.html">Services</a></li>
        <li><a href="/blog.html">Blog</a></li>
        <li><a href="/testimonials.html">Testimonials</a></li>
        <li><a href="/contact.html">Contact</a></li>
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

<!-- JS EXTERNO GERAL DO SITE -->
<script src="script.js"></script>

<!-- JS do menu mobile -->
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
</script>
</body>
</html>
