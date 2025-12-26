<?php
// compose.php
declare(strict_types=1);

// ======================================
// BOOTSTRAP CENTRAL (session + auth + PDO)
// ======================================
// compose.php está em /dashboards, então sobe 1 nível para /core
require_once __DIR__ . '/../core/bootstrap.php';

require_login(); // qualquer usuário logado pode compor

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role            = (string)($_SESSION['role'] ?? '');

/**
 * ✅ CSRF token (para enviar junto no send_mail.php)
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

/**
 * 2) Flags de papel
 */
$isClient = in_array($role, ['user', 'client'], true);
$isPro    = ($role === 'pro');
$isAdmin  = ($role === 'admin');

/**
 * 3) Carrega dados do usuário logado
 */
$stmt = $pdo->prepare("
    SELECT id, name, role, avatar_url, specialty
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_user) {
    session_destroy();
    header('Location: /login.php');
    exit;
}

/**
 * 3.1) Detecta specialty do PRO (personal vs nutritionist)
 * - mantém compatível com o seu padrão atual (role = pro)
 */
$specialty = '';
if ($isPro) {
    $specialty = (string)($_SESSION['specialty'] ?? ($current_user['specialty'] ?? ''));

    if ($specialty === '') {
        $stmt = $pdo->prepare("SELECT specialty FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$current_user_id]);
        $specialty = (string)($stmt->fetchColumn() ?: '');
        $_SESSION['specialty'] = $specialty; // opcional
    }
}

$isPersonal     = ($isPro && $specialty === 'personal_trainer');
$isNutritionist = ($isPro && $specialty === 'nutritionist');

/**
 * 4) Busca lista de destinatários, dependendo do papel
 */
$destinatarios = [];

if ($isClient) {

    // Cliente → coach(es) conectados via coach_clients
    $sql = "
        SELECT
            u.id,
            u.name,
            u.avatar_url,
            'coach' AS relation_type
        FROM coach_clients cc
        JOIN users u ON u.id = cc.coach_id
        WHERE cc.client_id = ?
        ORDER BY cc.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id]);
    $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($isPro) {

    // Pro → clientes conectados (coach_clients) OU com plano criado por ele
    $sql = "
        SELECT DISTINCT
            base.id,
            base.name,
            base.avatar_url,
            'client' AS relation_type
        FROM (
            -- clientes conectados via coach_clients
            SELECT
                u.id,
                u.name,
                u.avatar_url
            FROM coach_clients cc
            JOIN users u ON u.id = cc.client_id
            WHERE cc.coach_id = :coach_id

            UNION

            -- clientes que têm plano criado por este coach
            SELECT
                u2.id,
                u2.name,
                u2.avatar_url
            FROM workout_plans wp2
            JOIN users u2 ON u2.id = wp2.user_id
            WHERE wp2.created_by = :coach_id2
        ) AS base
        ORDER BY base.name ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':coach_id'  => $current_user_id,
        ':coach_id2' => $current_user_id,
    ]);
    $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    // Admin / outros → todos usuários, exceto ele
    $sql = "
        SELECT
            id,
            name,
            avatar_url,
            'other' AS relation_type
        FROM users
        WHERE id != ?
        ORDER BY name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id]);
    $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 5) Pré-selecionar destinatário (se vier ?to=ID na URL)
 * - Segurança: só aceita se o ID estiver dentro da lista de destinatários carregada
 */
$pre_to = isset($_GET['to']) ? (int)$_GET['to'] : null;
if ($pre_to) {
    $allowedIds = array_map(static fn($d) => (int)$d['id'], $destinatarios);
    if (!in_array($pre_to, $allowedIds, true)) {
        $pre_to = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Compose Message | RB Personal Trainer | Rafa Breder Coaching</title>
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
  <link rel="stylesheet" href="/assets/css/compose.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">

  <!-- FIX: header.css zera padding do body com !important; aqui garantimos o offset do header fixo -->
  <style>
    body { padding-top: 96px !important; }
  </style>

  <!-- CSS extra só para a barra de busca do destinatário -->
  <style>
    .msg-recipient-search {
      margin-bottom: 4px;
    }
    .msg-recipient-search input {
      width: 100%;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid #2c3345;
      background: #050811;
      color: #ffffff;
      font-size: 0.95rem;
      outline: none;
    }
    .msg-recipient-search input::placeholder {
      color: #6c748e;
    }

    .msg-recipient-suggestions {
      margin-bottom: 8px;
      background: #050811;
      border: 1px solid #2c3345;
      border-radius: 8px;
      max-height: 220px;
      overflow-y: auto;
      display: none; /* começa escondido */
    }
    .msg-recipient-suggestions-item {
      padding: 8px 12px;
      cursor: pointer;
      font-size: 0.9rem;
      color: #e2e6f5;
    }
    .msg-recipient-suggestions-item:hover {
      background: #141b2d;
    }

    /* ✅ Upload field styling (simples, alinhado ao tema) */
    .msg-file-input {
      width: 100%;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid #2c3345;
      background: #050811;
      color: #d7defc;
      font-size: 0.95rem;
      outline: none;
    }
    .msg-help-text-small {
      margin-top: 6px;
      font-size: 0.85rem;
      color: #8d94b8;
    }
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
          <li><a href="/dashboards/dashboard_client.php">Dashboard</a></li>
          <li><a href="/dashboards/client_profile.php">Profile</a></li>
          <li><a href="/dashboards/client_goals.php">Goals</a></li>
          <li><a href="/dashboards/messages.php" class="rbf1-link rbf1-link-active">Messages</a></li>
          <li><a href="/dashboards/client_workouts.php">Workout</a></li>
          <li><a href="/dashboards/client_nutrition.php">Nutritionist</a></li>
          <li><a href="/dashboards/progress_gallery.php">Photos Gallery</a></li>

        <?php elseif ($isPro): ?>
          <li>
            <a href="/dashboards/<?php echo $isNutritionist ? 'dashboard_nutritionist.php' : 'dashboard_personal.php'; ?>">
              Dashboard
            </a>
          </li>

          <li>
            <a href="/dashboards/<?php echo $isNutritionist ? 'nutritionist_profile.php' : 'personal_profile.php'; ?>">
              Profile
            </a>
          </li>

          <li>
            <a href="/dashboards/<?php echo $isNutritionist ? 'nutritionist_profile_edit.php' : 'personal_profile_edit.php'; ?>">
              Edit Profile
            </a>
          </li>

          <li><a href="/dashboards/trainer_workouts.php">Workouts</a></li>
          <li><a href="/dashboards/trainer_checkins.php">Check-ins</a></li>
          <li><a href="/dashboards/messages.php" class="rbf1-link rbf1-link-active">Messages</a></li>
          <li><a href="/dashboards/trainer_clients.php">Clients</a></li>

        <?php elseif ($isAdmin): ?>
          <li><a href="/dashboards/dashboard_admin.php">Dashboard</a></li>
          <li><a href="/dashboards/messages.php" class="rbf1-link rbf1-link-active">Messages</a></li>
        <?php endif; ?>

        <li class="mobile-only">
            <a href="/login.php" class="rb-mobile-logout">Logout</a>
        </li>
      </ul>
    </nav>

    <div class="rbf1-right">
      <a href="/login.php" class="rbf1-login">Logout</a>
    </div>

    <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
      ☰
    </button>
  </div>
</header>

<div class="msg-container">

    <!-- Cabeçalho do usuário -->
    <div class="msg-user-header">
        <?php if (!empty($current_user['avatar_url'])): ?>
            <img src="<?php echo htmlspecialchars((string)$current_user['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Client profile photo">
        <?php else: ?>
            <img src="/assets/images/default-avatar.png" alt="Client profile photo">
        <?php endif; ?>
        <div class="msg-user-info">
            <div class="msg-user-name"><?php echo htmlspecialchars((string)$current_user['name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="msg-user-subtitle">
              <?php if ($isClient): ?>
                New message to your coach
              <?php elseif ($isPro): ?>
                New message to a client
              <?php elseif ($isAdmin): ?>
                New internal message
              <?php else: ?>
                New message
              <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="msg-compose-wrapper">

        <div class="msg-compose-header">
            <div class="msg-section-title">
                <span>Communication</span>
                <h2>Write a new message</h2>
                <p>
                  <?php if ($isClient): ?>
                    Send a message to your coach and keep all communication in one place.
                  <?php elseif ($isPro): ?>
                    Send a message to one of your clients and keep all check-ins and updates organized.
                  <?php else: ?>
                    Send an internal message using the secure inbox.
                  <?php endif; ?>
                </p>
            </div>

            <a href="/dashboards/messages.php" class="msg-btn-secondary">Back to inbox</a>
        </div>

        <!-- ✅ enctype + csrf + attachment -->
        <form action="/dashboards/send_mail.php" method="post" class="msg-form" enctype="multipart/form-data">

            <!-- CSRF -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

            <!-- Destinatário -->
            <div class="msg-field">
                <label for="receiver_id" class="msg-label">To</label>

                <?php if (!empty($destinatarios)): ?>
                    <!-- Barra de busca por nome (autocomplete) -->
                    <div class="msg-recipient-search">
                        <input
                          type="text"
                          id="recipient_search"
                          autocomplete="off"
                          placeholder="<?php echo $isPro ? 'Search clients by name...' : 'Search recipients by name...'; ?>"
                        >
                    </div>
                    <!-- Sugestões -->
                    <div id="recipient_suggestions" class="msg-recipient-suggestions"></div>
                <?php endif; ?>

                <!-- Select REAL que guarda o ID (enviado para o backend) -->
                <select name="receiver_id" id="receiver_id" class="msg-select" required>
                    <option value="">Select a recipient...</option>

                    <?php foreach ($destinatarios as $dst): ?>
                        <?php
                            $label = (string)$dst['name'];
                            $relationType = (string)($dst['relation_type'] ?? '');

                            if ($relationType === 'coach') {
                                $label .= ' · Coach';
                            } elseif ($relationType === 'client') {
                                $label .= ' · Client';
                            } elseif ($relationType !== '' && $relationType !== 'other') {
                                $label .= ' · ' . ucfirst($relationType);
                            }
                        ?>
                        <option
                          value="<?php echo (int)$dst['id']; ?>"
                          data-label="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>"
                          <?php echo $pre_to === (int)$dst['id'] ? 'selected' : ''; ?>
                        >
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if (empty($destinatarios)): ?>
                    <p class="msg-help-text">
                        <?php if ($isClient): ?>
                            No connected coach found yet. Ask your coach to connect your account.
                        <?php elseif ($isPro): ?>
                            No connected clients found yet. Create a plan or connect clients.
                        <?php else: ?>
                            No recipients available.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Assunto -->
            <div class="msg-field">
                <label for="subject" class="msg-label">Subject</label>
                <input
                    type="text"
                    id="subject"
                    name="subject"
                    class="msg-input"
                    maxlength="255"
                    placeholder="e.g. Questions about my workout plan"
                    required>
            </div>

            <!-- Corpo -->
            <div class="msg-field">
                <label for="body" class="msg-label">Message</label>
                <textarea
                    id="body"
                    name="body"
                    class="msg-textarea"
                    rows="6"
                    placeholder="Type your message here..."
                    required></textarea>
            </div>

            <!-- ✅ Attachment (send_mail.php já suporta JPG/PNG até 5MB) -->
            <div class="msg-field">
                <label for="attachment" class="msg-label">Attachment (optional)</label>
                <input
                  type="file"
                  id="attachment"
                  name="attachment"
                  class="msg-file-input"
                  accept="image/png,image/jpeg"
                >
                <div class="msg-help-text-small">Allowed: JPG/PNG • Max: 5MB</div>
            </div>

            <!-- Nova thread (sem thread_id) -->
            <input type="hidden" name="thread_id" value="">

            <div class="msg-form-actions">
                <a href="/dashboards/messages.php" class="msg-btn-secondary">Cancel</a>
                <button type="submit" class="msg-btn-primary" id="sendBtn">Send message</button>
            </div>

        </form>
    </div>

</div>

<!-- FOOTER -->
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
          <li><a href="/dashboards/dashboard_client.php">Dashboard</a></li>
          <li><a href="/dashboards/client_profile.php">Profile</a></li>
          <li><a href="/dashboards/client_goals.php">Goals</a></li>
          <li><a href="/dashboards/client_workouts.php">Workouts</a></li>
          <li><a href="/dashboards/client_nutrition.php">Nutrition</a></li>
          <li><a href="/dashboards/messages.php">Messages</a></li>

        <?php elseif ($isPro): ?>
          <li>
            <a href="/dashboards/<?php echo $isNutritionist ? 'dashboard_nutritionist.php' : 'dashboard_personal.php'; ?>">
              Dashboard
            </a>
          </li>

          <li>
            <a href="/dashboards/<?php echo $isNutritionist ? 'nutritionist_profile.php' : 'personal_profile.php'; ?>">
              Profile
            </a>
          </li>

          <li><a href="/dashboards/trainer_workouts.php">Workouts</a></li>
          <li><a href="/dashboards/trainer_checkins.php">Check-ins</a></li>
          <li><a href="/dashboards/trainer_clients.php">Clients</a></li>
          <li><a href="/dashboards/messages.php">Messages</a></li>

        <?php elseif ($isAdmin): ?>
          <li><a href="/dashboards/dashboard_admin.php">Dashboard</a></li>
          <li><a href="/dashboards/messages.php">Messages</a></li>
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

    // --- Autocomplete de destinatários ---
    const searchInput    = document.getElementById('recipient_search');
    const suggestionsBox = document.getElementById('recipient_suggestions');
    const selectEl       = document.getElementById('receiver_id');
    const sendBtn        = document.getElementById('sendBtn');

    if (searchInput && suggestionsBox && selectEl) {
      const contacts = [];
      for (let i = 0; i < selectEl.options.length; i++) {
        const opt = selectEl.options[i];
        if (!opt.value) continue;
        contacts.push({
          value: opt.value,
          label: opt.getAttribute('data-label') || opt.text
        });
      }

      function clearSuggestions() {
        suggestionsBox.innerHTML = '';
        suggestionsBox.style.display = 'none';
      }

      function showSuggestions(query) {
        const q = query.toLowerCase().trim();
        clearSuggestions();
        if (!q) return;

        const matches = contacts.filter(c => c.label.toLowerCase().includes(q));
        if (!matches.length) return;

        matches.forEach(function (c) {
          const div = document.createElement('div');
          div.className = 'msg-recipient-suggestions-item';
          div.textContent = c.label;
          div.dataset.value = c.value;

          div.addEventListener('click', function () {
            searchInput.value = c.label;
            selectEl.value = c.value;
            clearSuggestions();
          });

          suggestionsBox.appendChild(div);
        });

        suggestionsBox.style.display = 'block';
      }

      searchInput.addEventListener('input', function () {
        selectEl.value = '';
        showSuggestions(this.value);
      });

      searchInput.addEventListener('blur', function () {
        setTimeout(clearSuggestions, 200);
      });

      // se veio com ?to=ID, já preenche o input com o label desse contato
      if (selectEl.value) {
        const opt = selectEl.selectedOptions[0];
        if (opt) {
          const lbl = opt.getAttribute('data-label') || opt.text;
          searchInput.value = lbl;
        }
      }

      // ✅ Proteção: se digitou e não selecionou, bloqueia submit
      const form = document.querySelector('form.msg-form');
      if (form && sendBtn) {
        form.addEventListener('submit', function (e) {
          if (!selectEl.value) {
            e.preventDefault();
            alert('Please select a recipient from the list.');
            selectEl.focus();
            return false;
          }
        });
      }
    }

  })();
</script>

</body>
</html>
