<?php
// trainer_workouts.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_role    = (string)($_SESSION['role'] ?? '');

// 2) Filtros (status, busca, cliente)
$allowedStatus = ['all', 'active', 'archived'];
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, $allowedStatus, true)) {
    $status = 'all';
}

$search = trim((string)($_GET['q'] ?? ''));

// filtro de cliente (igual trainer_checkins.php)
$clientFilter = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
if ($clientFilter !== null && $clientFilter <= 0) {
    $clientFilter = null;
}

// 3) Lista de clientes desse coach (coach_clients + workout_plans)
$sqlClients = "
    SELECT base.id, base.name
    FROM (
        SELECT u.id, u.name
        FROM coach_clients cc
        JOIN users u ON u.id = cc.client_id
        WHERE cc.coach_id = :coach1

        UNION

        SELECT u2.id, u2.name
        FROM workout_plans wp2
        JOIN users u2 ON u2.id = wp2.user_id
        WHERE wp2.created_by = :coach2
    ) AS base
    ORDER BY base.name ASC
";
$stmt = $pdo->prepare($sqlClients);
$stmt->execute([
    ':coach1' => $current_user_id,
    ':coach2' => $current_user_id,
]);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// 3.1) Garante que o client_id filtrado é realmente desse coach
$selectedClient = null;
if ($clientFilter !== null) {
    $sqlClientInfo = "
        SELECT u.*
        FROM users u
        WHERE u.id = :client_id
          AND (
                EXISTS (
                    SELECT 1
                    FROM coach_clients cc
                    WHERE cc.client_id = u.id
                      AND cc.coach_id = :coach1
                )
             OR EXISTS (
                    SELECT 1
                    FROM workout_plans wp
                    WHERE wp.user_id = u.id
                      AND wp.created_by = :coach2
                )
          )
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sqlClientInfo);
    $stmt->execute([
        ':client_id' => $clientFilter,
        ':coach1'    => $current_user_id,
        ':coach2'    => $current_user_id,
    ]);
    $selectedClient = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$selectedClient) {
        // se não pertence a esse coach, reseta o filtro
        $clientFilter = null;
    }
}

// 4) Resumo (cards de contagem) – sempre considerando todos os planos do coach
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_plans,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_plans,
        COUNT(DISTINCT user_id) AS clients_count
    FROM workout_plans
    WHERE created_by = ?
");
$stmt->execute([$current_user_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_plans'   => 0,
    'active_plans'  => 0,
    'clients_count' => 0,
];

$total_plans   = (int)($summary['total_plans'] ?? 0);
$active_plans  = (int)($summary['active_plans'] ?? 0);
$clients_count = (int)($summary['clients_count'] ?? 0);

// 5) Lista de planos do coach (com filtro por client_id se houver)
$sql = "
    SELECT
        wp.id,
        wp.name,
        wp.description,
        wp.status,
        wp.weeks_total,
        wp.created_at,
        u.id   AS client_id,
        u.name AS client_name
    FROM workout_plans wp
    INNER JOIN users u ON u.id = wp.user_id
    WHERE wp.created_by = :coach_id
";

$params = [
    ':coach_id' => $current_user_id,
];

if ($clientFilter !== null) {
    $sql .= " AND wp.user_id = :client_id";
    $params[':client_id'] = $clientFilter;
}

if ($status !== 'all') {
    $sql .= " AND wp.status = :status";
    $params[':status'] = $status;
}

if ($search !== '') {
    $sql .= " AND (wp.name LIKE :term OR u.name LIKE :term2)";
    $term = '%' . $search . '%';
    $params[':term']  = $term;
    $params[':term2'] = $term;
}

$sql .= "
    ORDER BY (wp.status = 'active') DESC,
             u.name ASC,
             wp.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Coach Workouts | RB Personal Trainer | Rafa Breder Coaching</title>
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

    <!-- CSS base do projeto -->
    <link rel="stylesheet" href="/assets/css/global.css">
    <link rel="stylesheet" href="/assets/css/dashboard_personal.css">
    <link rel="stylesheet" href="/assets/css/client_workouts.css"><!-- reaproveita tema wk-* -->
    <link rel="stylesheet" href="/assets/css/trainer_workouts.css"><!-- css específico desta página -->
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">
</head>
<body>

<header id="rb-static-header" class="rbf1-header">
    <!-- TOP BAR ESTILO FERRARI -->
    <div class="rbf1-topbar">
        <a href="/" class="rbf1-logo">
            <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
        </a>

        <nav class="rbf1-nav" id="rbf1-nav">
            <ul>
                <li><a href="dashboard_personal.php" class="rbf1-link">Dashboard</a></li>
                <li><a href="personal_profile.php" class="rbf1-link">Profile</a></li>
                <li><a href="trainer_workouts.php" class="rbf1-link rbf1-link-active">Workouts</a></li>
                <li><a href="trainer_checkins.php" class="rbf1-link">Check-ins</a></li>
                <li><a href="messages.php" class="rbf1-link">Messages</a></li>
                <li><a href="trainer_clients.php" class="rbf1-link">Clients</a></li>

                <!-- Logout (mobile) -->
                <li class="mobile-only"><a href="../login.php" class="rbf1-link">Logout</a></li>
            </ul>
        </nav>

        <div class="rbf1-right">
            <!-- Logout (desktop) -->
            <a href="../login.php" class="rbf1-login">Logout</a>
        </div>

        <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
            ☰
        </button>
    </div>
</header>

<!-- MESMA ESTRUTURA DO CLIENT PROFILE -->
<div class="client-dashboard">
  <div class="client-shell">

    <!-- Card grande no centro (wk-container + coach-workouts) -->
    <div class="wk-container coach-workouts">

        <!-- Cabeçalho / resumo -->
        <div class="wk-header-row">
            <div>
                <h1 class="wk-title">Workout programs</h1>
                <p class="wk-subtitle">
                    Manage training plans for your clients. Use the client search to quickly jump to a specific student.
                </p>

                <?php if ($selectedClient): ?>
                    <p class="wk-subtitle">
                        Showing plans for
                        <strong><?php echo htmlspecialchars((string)$selectedClient['name'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                    </p>
                <?php elseif ($clientFilter !== null): ?>
                    <p class="wk-subtitle">
                        Client not found or not linked to this account.
                    </p>
                <?php endif; ?>
            </div>

            <div class="wk-summary-row">
                <div class="wk-summary-card">
                    <div class="wk-summary-label">Total plans</div>
                    <div class="wk-summary-value"><?php echo (int)$total_plans; ?></div>
                </div>
                <div class="wk-summary-card">
                    <div class="wk-summary-label">Active plans</div>
                    <div class="wk-summary-value"><?php echo (int)$active_plans; ?></div>
                </div>
                <div class="wk-summary-card">
                    <div class="wk-summary-label">Clients</div>
                    <div class="wk-summary-value"><?php echo (int)$clients_count; ?></div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <form method="get" class="tw-filters">
            <!-- Cliente (select + busca, igual check-in/messages) -->
            <div class="tw-filter-block" style="min-width: 220px;">
                <label class="tw-field-label" for="client_id">Client</label>

                <select
                    name="client_id"
                    id="client_id"
                    class="tw-select"
                >
                    <option value="">All clients</option>
                    <?php foreach ($clients as $c): ?>
                        <option
                            value="<?php echo (int)($c['id'] ?? 0); ?>"
                            <?php if ($clientFilter && (int)($c['id'] ?? 0) === (int)$clientFilter) echo 'selected'; ?>
                        >
                            <?php echo htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="msg-recipient-search">
                    <input
                        type="text"
                        id="client_search"
                        autocomplete="off"
                        placeholder="Type a client name..."
                        value="<?php echo $selectedClient ? htmlspecialchars((string)$selectedClient['name'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                    >
                </div>
                <div id="client_suggestions" class="msg-recipient-suggestions"></div>
            </div>

            <!-- Busca por plano / nome -->
            <div class="tw-filter-block">
                <label class="tw-field-label" for="q">Search</label>
                <input
                    type="text"
                    id="q"
                    name="q"
                    class="tw-input"
                    placeholder="Search by client or plan name..."
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                >
            </div>

            <!-- Status -->
            <div class="tw-filter-block" style="max-width: 150px;">
                <label class="tw-field-label" for="status">Status</label>
                <select name="status" id="status" class="tw-select">
                    <option value="all"      <?php if ($status === 'all') echo 'selected'; ?>>All</option>
                    <option value="active"   <?php if ($status === 'active') echo 'selected'; ?>>Active</option>
                    <option value="archived" <?php if ($status === 'archived') echo 'selected'; ?>>Archived</option>
                </select>
            </div>

            <!-- Botões -->
            <div class="tw-filter-actions">
                <button type="submit" class="wk-btn-primary tw-filter-button">
                    Apply
                </button>

                <?php if ($status !== 'all' || $search !== '' || $clientFilter !== null): ?>
                    <a href="trainer_workouts.php" class="tw-link-reset">Clear filters</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Lista de planos + ficha do cliente -->
        <div class="tw-plans-section">

            <?php
            $hasPlans = !empty($plans);
            ?>

            <?php if ($selectedClient): ?>
                <!-- CARD DA FICHA DO CLIENTE -->
                <div class="wk-plan-card tw-plan-card-coach tw-client-summary-card">
                    <div class="wk-plan-header">
                        <div class="wk-plan-name">
                            <?php echo htmlspecialchars((string)$selectedClient['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>

                    <div class="wk-plan-client">
                        Workout programs for this client.
                    </div>

                    <div class="wk-plan-meta">
                        <?php if ($hasPlans): ?>
                            <span><?php echo (int)count($plans); ?> plan(s) found.</span>
                        <?php else: ?>
                            <span>No workout plans yet for this client.</span>
                        <?php endif; ?>
                    </div>

                    <div class="wk-plan-actions tw-plan-actions-coach">
                        <?php if ($hasPlans): ?>
                            <!-- Já tem treinos: editar/gerenciar -->
                            <a href="trainer_client_workouts.php?client_id=<?php echo (int)($selectedClient['id'] ?? 0); ?>"
                               class="wk-btn-primary">
                                Edit workouts
                            </a>
                        <?php else: ?>
                            <!-- Não tem treinos: criar -->
                            <a href="trainer_client_workouts.php?client_id=<?php echo (int)($selectedClient['id'] ?? 0); ?>&mode=new"
                               class="wk-btn-primary">
                                Create workout
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$hasPlans): ?>
                <?php if (!$selectedClient): ?>
                    <!-- Estado vazio genérico (sem filtro de cliente) -->
                    <p class="wk-empty">
                        No workout plans found for this filter.
                        You can create new plans from client profiles or from the clients area.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <!-- Lista de planos existentes -->
                <div class="wk-plan-grid">
                    <?php foreach ($plans as $plan): ?>
                        <div class="wk-plan-card tw-plan-card-coach">
                            <div class="wk-plan-header">
                                <div class="wk-plan-name">
                                    <?php
                                    $planName = (string)($plan['name'] ?? '');
                                    echo htmlspecialchars(($planName !== '' ? $planName : 'Untitled plan'), ENT_QUOTES, 'UTF-8');
                                    ?>
                                </div>
                                <?php
                                $planStatus = (string)($plan['status'] ?? '');
                                $safeStatus = htmlspecialchars($planStatus, ENT_QUOTES, 'UTF-8');
                                ?>
                                <span class="wk-plan-status wk-plan-status-<?php echo $safeStatus; ?>">
                                    <?php echo htmlspecialchars(ucfirst($planStatus), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>

                            <div class="wk-plan-client">
                                For:
                                <strong><?php echo htmlspecialchars((string)($plan['client_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>

                            <?php if (!empty($plan['description'])): ?>
                                <div class="wk-plan-desc">
                                    <?php echo nl2br(htmlspecialchars((string)$plan['description'], ENT_QUOTES, 'UTF-8')); ?>
                                </div>
                            <?php endif; ?>

                            <div class="wk-plan-meta">
                                <?php if (!empty($plan['weeks_total'])): ?>
                                    <span><?php echo (int)$plan['weeks_total']; ?> weeks</span>
                                <?php endif; ?>

                                <?php if (!empty($plan['created_at'])): ?>
                                    <?php
                                    $createdTs = strtotime((string)$plan['created_at']);
                                    ?>
                                    <span>
                                        Created:
                                        <?php echo htmlspecialchars(($createdTs ? date('M d, Y', $createdTs) : ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="wk-plan-actions tw-plan-actions-coach">
                                <?php if (!empty($plan['client_id'])): ?>
                                    <a href="trainer_client_workouts.php?client_id=<?php echo (int)$plan['client_id']; ?>"
                                       class="wk-btn-secondary">
                                        View client workouts
                                    </a>
                                <?php endif; ?>

                                <a href="workout_plan_detail.php?plan_id=<?php echo (int)($plan['id'] ?? 0); ?>"
                                   class="wk-btn-primary">
                                    View sessions
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.wk-container.coach-workouts -->

  </div><!-- /.client-shell -->
</div><!-- /.client-dashboard -->

<footer class="site-footer">

    <!-- BLOCO PRINCIPAL -->
    <div class="footer-main">
      <!-- COLUNA BRAND -->
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

      <!-- COLUNA NAV (VERSÃO COACH) -->
      <div class="footer-col footer-nav">
        <h3 class="footer-heading">Navigate</h3>
        <ul class="footer-links">
          <li><a href="dashboard_personal.php">Dashboard</a></li>
          <li><a href="personal_profile.php">Profile</a></li>
          <li><a href="trainer_workouts.php">Workouts</a></li>
          <li><a href="trainer_checkins.php">Check-ins</a></li>
          <li><a href="messages.php">Messages</a></li>
          <li><a href="trainer_clients.php">Clients</a></li>
        </ul>
      </div>

      <!-- COLUNA LEGAL -->
      <div class="footer-col footer-legal">
        <h3 class="footer-heading">Legal</h3>
        <ul class="footer-legal-list">
          <li><a href="/privacy.html">Privacy Policy</a></li>
          <li><a href="/terms.html">Terms of Use</a></li>
          <li><a href="/cookies.html">Cookie Policy</a></li>
        </ul>
      </div>

      <!-- COLUNA CONTACT -->
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

    <!-- BARRA INFERIOR -->
    <div class="footer-bottom">
      <p class="footer-bottom-text">
        © 2025 RB Personal Trainer. All rights reserved.
      </p>
    </div>
</footer>

<!-- JS EXTERNO GERAL DO SITE -->
<script src="script.js"></script>

<script>
  // Toggle header mobile nav
  (function () {
    const toggle = document.getElementById('rbf1-toggle');
    const nav = document.getElementById('rbf1-nav');

    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        nav.classList.toggle('rbf1-open');
      });
    }
  })();

  // Autocomplete de clientes (igual check-ins / messages)
  (function () {
    const searchInput     = document.getElementById('client_search');
    const suggestionsBox  = document.getElementById('client_suggestions');
    const selectEl        = document.getElementById('client_id');
    const formEl          = document.querySelector('.tw-filters');

    if (!searchInput || !suggestionsBox || !selectEl) return;

    const clients = [];
    for (let i = 0; i < selectEl.options.length; i++) {
      const opt = selectEl.options[i];
      if (!opt.value) continue;
      clients.push({ value: opt.value, label: opt.text });
    }

    function clearSuggestions() {
      suggestionsBox.innerHTML = '';
      suggestionsBox.style.display = 'none';
    }

    function showSuggestions(term) {
      const q = term.trim().toLowerCase();
      if (!q) {
        clearSuggestions();
        return;
      }

      const filtered = clients.filter(function (c) {
        return c.label.toLowerCase().includes(q);
      }).slice(0, 15);

      if (!filtered.length) {
        clearSuggestions();
        return;
      }

      suggestionsBox.innerHTML = '';
      filtered.forEach(function (c) {
        const div = document.createElement('div');
        div.className = 'msg-recipient-suggestions-item';
        div.dataset.value = c.value;
        div.dataset.label = c.label;
        div.textContent = c.label;
        suggestionsBox.appendChild(div);
      });

      suggestionsBox.style.display = 'block';
    }

    suggestionsBox.addEventListener('click', function (e) {
      const item = e.target.closest('.msg-recipient-suggestions-item');
      if (!item) return;

      const value = item.dataset.value;
      const label = item.dataset.label || item.textContent;

      selectEl.value = value;
      searchInput.value = label;
      clearSuggestions();

      if (formEl) {
        formEl.submit();
      }
    });

    searchInput.addEventListener('input', function () {
      showSuggestions(this.value);
    });

    searchInput.addEventListener('focus', function () {
      if (this.value.trim() !== '') {
        showSuggestions(this.value);
      }
    });

    searchInput.addEventListener('blur', function () {
      setTimeout(clearSuggestions, 200);
    });
  })();
</script>

</body>
</html>
