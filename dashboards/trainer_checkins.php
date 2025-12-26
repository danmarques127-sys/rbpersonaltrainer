<?php
// trainer_checkins.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

// 2) Filtro de cliente (opcional)
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
$clients = $stmt->fetchAll() ?: [];

// 3.1) Infos de perfil do cliente selecionado (filtrado por vínculo com o coach)
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
    $selectedClient = $stmt->fetch() ?: null;
}

/**
 * ===== Units: CM/KG -> IN/LB =====
 */
$cm_to_in = function (float $cm): float {
    return $cm / 2.54;
};
$kg_to_lb = function (float $kg): float {
    return $kg * 2.2046226218;
};

// helper p/ formatar valores (igual ao profile) - agora em IN/LB
$formatClientField = function ($value, string $type = 'text') use ($cm_to_in, $kg_to_lb): string {
    if ($value === null || $value === '') {
        return 'Not provided';
    }

    switch ($type) {
        case 'height': {
            $cm = (float)$value;
            $in = $cm_to_in($cm);

            // formato: 5' 8" (opcional) + inches
            $feet = (int)floor($in / 12);
            $inch = (int)round($in - ($feet * 12));

            // ajuste de arredondamento tipo 5' 12" -> 6' 0"
            if ($inch === 12) {
                $feet++;
                $inch = 0;
            }

            return $feet . "'" . ' ' . $inch . '"' . ' (' . number_format($in, 1) . ' in)';
        }

        case 'weight': {
            $kg = (float)$value;
            $lb = $kg_to_lb($kg);
            return number_format($lb, 1) . ' lb';
        }

        case 'date':
            return date('Y-m-d', strtotime((string)$value));

        default:
            return (string)$value;
    }
};

// 4) Inicializa arrays vazios e contadores (estado padrão – sem cliente selecionado)
$workoutLogs      = [];
$goalUpdates      = [];
$photos           = [];
$clientGoals      = [];
$totalCheckins    = 0;
$totalGoalUpdates = 0;
$totalPhotos      = 0;

// Só carrega check-ins, GOALS e fotos se um cliente foi selecionado
// e se ele realmente pertence a esse coach ($selectedClient !== null)
if ($clientFilter !== null && $selectedClient) {

    // 4) Recent workout check-ins (workout_logs) – APENAS do cliente
    $logsLimit = 50;

    $sqlLogs = "
        SELECT
            wl.id,
            wl.user_id,
            wl.session_id,
            wl.performed_at,
            wl.status,
            wl.difficulty,
            wl.mood,
            wl.notes,
            u.name          AS client_name,
            u.avatar_url    AS client_avatar,
            ws.session_label,
            wp.name         AS plan_name
        FROM workout_logs wl
        JOIN workout_sessions ws ON ws.id = wl.session_id
        JOIN workout_plans    wp ON wp.id = ws.plan_id
        JOIN users            u  ON u.id = wl.user_id
        WHERE wp.created_by = :coach_id
          AND wl.user_id    = :client_id
        ORDER BY wl.performed_at DESC
        LIMIT {$logsLimit}
    ";

    $stmt = $pdo->prepare($sqlLogs);
    $stmt->execute([
        ':coach_id'  => $current_user_id,
        ':client_id' => $clientFilter,
    ]);
    $workoutLogs = $stmt->fetchAll() ?: [];

    // 5) GOALS do cliente (primário, secundário etc – tabela client_goals)
    $sqlClientGoals = "
        SELECT
            g.*
        FROM client_goals g
        WHERE g.client_id = :client_id
        ORDER BY g.id ASC
    ";
    $stmt = $pdo->prepare($sqlClientGoals);
    $stmt->execute([
        ':client_id' => $clientFilter,
    ]);
    $clientGoals = $stmt->fetchAll() ?: [];

    // 6) Goal progress (client_goal_progress) – apenas desse cliente
    $goalsLimit = 50;

    $sqlGoals = "
        SELECT
            gp.id,
            gp.goal_id,
            gp.log_date,
            gp.value,
            gp.note,
            gp.created_at,
            g.title        AS goal_title,
            u.id           AS client_id,
            u.name         AS client_name,
            u.avatar_url   AS client_avatar
        FROM client_goal_progress gp
        JOIN client_goals g  ON g.id = gp.goal_id
        JOIN users       u   ON u.id = g.client_id
        WHERE g.client_id = :client_id
          AND g.client_id IN (
              SELECT base.id
              FROM (
                  SELECT u.id
                  FROM coach_clients cc
                  JOIN users u ON u.id = cc.client_id
                  WHERE cc.coach_id = :coach1

                  UNION

                  SELECT u2.id
                  FROM workout_plans wp2
                  JOIN users u2 ON u2.id = wp2.user_id
                  WHERE wp2.created_by = :coach2
              ) AS base
          )
        ORDER BY gp.log_date DESC, gp.created_at DESC
        LIMIT {$goalsLimit}
    ";

    $stmt = $pdo->prepare($sqlGoals);
    $stmt->execute([
        ':client_id' => $clientFilter,
        ':coach1'    => $current_user_id,
        ':coach2'    => $current_user_id,
    ]);
    $goalUpdates = $stmt->fetchAll() ?: [];

    // 7) Progress photos – somente desse cliente
    $photosLimit = 24;

    $sqlPhotos = "
        SELECT
            p.id,
            p.user_id,
            p.file_path,
            p.taken_at,
            p.weight_kg,
            p.pose,
            p.notes,
            p.created_at,
            u.name        AS client_name,
            u.avatar_url  AS client_avatar
        FROM progress_photos p
        JOIN users u ON u.id = p.user_id
        WHERE p.user_id = :client_id
          AND p.user_id IN (
              SELECT base.id
              FROM (
                  SELECT u.id
                  FROM coach_clients cc
                  JOIN users u ON u.id = cc.client_id
                  WHERE cc.coach_id = :coach1

                  UNION

                  SELECT u2.id
                  FROM workout_plans wp2
                  JOIN users u2 ON u2.id = wp2.user_id
                  WHERE wp2.created_by = :coach2
              ) AS base
          )
        ORDER BY p.taken_at DESC, p.created_at DESC
        LIMIT {$photosLimit}
    ";

    $stmt = $pdo->prepare($sqlPhotos);
    $stmt->execute([
        ':client_id' => $clientFilter,
        ':coach1'    => $current_user_id,
        ':coach2'    => $current_user_id,
    ]);
    $photos = $stmt->fetchAll() ?: [];

    // 8) Contadores simples para o topo (apenas do cliente selecionado)
    $totalCheckins    = count($workoutLogs);
    $totalGoalUpdates = count($goalUpdates);
    $totalPhotos      = count($photos);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Coach Check-ins | RB Personal Trainer | Rafa Breder Coaching</title>
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
    <link rel="stylesheet" href="/assets/css/trainer_client_workouts.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">

    <style>
      .coach-checkins .tw-title {
          font-size: 20px;
          font-weight: 600;
          color: var(--rb-text-main);
          margin: 0 0 4px;
      }

      .coach-checkins .tw-subtitle {
          font-size: 13px;
          color: var(--rb-text-muted);
          margin: 0;
      }

      .tw-header-row {
          display: flex;
          justify-content: space-between;
          gap: 16px;
          align-items: flex-start;
          margin-bottom: 18px;
      }

      .tw-summary-mini {
          display: flex;
          flex-wrap: wrap;
          gap: 8px;
          justify-content: flex-end;
      }

      .tw-summary-pill {
          min-width: 90px;
          padding: 6px 10px;
          border-radius: 999px;
          border: 1px solid var(--rb-border-soft);
          background: rgba(15, 23, 42, 0.95);
          display: flex;
          flex-direction: column;
          gap: 2px;
      }

      .tw-pill-label {
          font-size: 10px;
          text-transform: uppercase;
          letter-spacing: 0.08em;
          color: var(--rb-text-label);
      }

      .tw-pill-value {
          font-size: 14px;
          font-weight: 600;
          color: var(--rb-text-main);
      }

      /* filtros */

      .tw-filters {
          display: flex;
          justify-content: space-between;
          gap: 12px;
          align-items: flex-end;
          margin-bottom: 16px;
          flex-wrap: wrap;
      }

      .tw-filter-left,
      .tw-filter-search {
          min-width: 220px;
          flex: 1 1 220px;
      }

      .tw-field-label {
          font-size: 11px;
          color: var(--rb-text-label);
          text-transform: uppercase;
          letter-spacing: 0.06em;
          margin-bottom: 4px;
          display: block;
      }

      .tw-select {
          width: 100%;
          padding: 6px 12px;
          border-radius: 999px;
          border: 1px solid var(--rb-border-soft);
          background: var(--rb-bg-card-soft);
          color: var(--rb-text-main);
          font-size: 13px;
          outline: none;
          appearance: none;
          -webkit-appearance: none;
          -moz-appearance: none;
          background-image: linear-gradient(45deg, transparent 50%, #9ca3af 50%),
                            linear-gradient(135deg, #9ca3af 50%, transparent 50%);
          background-position: calc(100% - 12px) 9px, calc(100% - 7px) 9px;
          background-size: 5px 5px, 5px 5px;
          background-repeat: no-repeat;
      }

      .tw-filter-right {
          display: flex;
          align-items: flex-end;
          gap: 10px;
          flex-wrap: wrap;
      }

      .tw-link-reset {
          font-size: 11px;
          color: var(--rb-text-muted);
          text-decoration: underline;
          cursor: pointer;
      }

      .tw-link-reset:hover {
          color: #e5e7eb;
      }

      .msg-recipient-search {
          margin-bottom: 4px;
      }
      .msg-recipient-search input {
          width: 100%;
          padding: 8px 12px;
          border-radius: 999px;
          border: 1px solid #2c3345;
          background: #050811;
          color: #ffffff;
          font-size: 0.9rem;
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
          display: none;
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

      .coach-section {
          margin-top: 18px;
      }

      .coach-section-title {
          font-size: 13px;
          font-weight: 600;
          color: var(--rb-text-main);
          margin-bottom: 4px;
      }

      .coach-section-sub {
          font-size: 11px;
          color: var(--rb-text-muted);
          margin-bottom: 8px;
      }

      .coach-checkins-list .wk-checkin-row {
          padding-top: 8px;
          padding-bottom: 8px;
      }

      .coach-goal-row {
          padding: 8px 4px;
          border-bottom: 1px solid rgba(31, 41, 55, 0.9);
      }

      .coach-goal-row:last-child {
          border-bottom: none;
      }

      .coach-goal-header {
          font-size: 12px;
          color: var(--rb-text-main);
          margin-bottom: 2px;
      }

      .coach-goal-meta {
          font-size: 11px;
          color: var(--rb-text-muted);
          display: flex;
          flex-wrap: wrap;
          gap: 8px;
      }

      .coach-goal-notes {
          margin-top: 4px;
          font-size: 12px;
          color: #e5e7eb;
      }

      .coach-photo-client {
          font-size: 11px;
          color: var(--rb-text-muted);
          margin-top: 2px;
      }

      /* Coach goals clicáveis */
      .coach-goal-row[data-goal-id] {
          cursor: pointer;
          transition: background var(--rb-transition-fast), transform var(--rb-transition-fast);
      }
      .coach-goal-row[data-goal-id]:hover {
          background: rgba(15, 23, 42, 0.9);
          transform: translateY(-1px);
      }

      /* CLIENT CARD */

      .coach-client-card {
          display: flex;
          flex-wrap: wrap;
          gap: 16px;
          padding: 12px 14px;
          border-radius: 16px;
          background: rgba(15, 23, 42, 0.95);
          border: 1px solid var(--rb-border-soft);
          cursor: pointer;
      }

      .coach-client-main {
          display: flex;
          gap: 10px;
          align-items: center;
          flex: 0 0 230px;
      }

      .coach-client-avatar {
          width: 52px;
          height: 52px;
          border-radius: 999px;
          overflow: hidden;
          background: #020617;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 22px;
          font-weight: 600;
          color: #e5e7eb;
      }

      .coach-client-avatar img {
          width: 100%;
          height: 100%;
          object-fit: cover;
      }

      .coach-client-name {
          font-size: 15px;
          font-weight: 600;
          color: var(--rb-text-main);
      }

      .coach-client-meta {
          font-size: 11px;
          color: var(--rb-text-muted);
          margin-top: 2px;
      }

      /* resumo (compacto) */

      .coach-client-summary-grid {
          flex: 1 1 300px;
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
          gap: 8px 16px;
          align-items: flex-start;
      }

      .coach-client-stat-label {
          text-transform: uppercase;
          letter-spacing: 0.06em;
          color: var(--rb-text-label);
          margin-bottom: 2px;
          font-size: 10px;
      }

      .coach-client-stat-value {
          font-size: 13px;
          color: var(--rb-text-main);
      }

      .coach-client-details-toggle {
          width: 100%;
          margin-top: 6px;
          display: flex;
          justify-content: flex-end;
      }

      .coach-toggle-btn {
          background: transparent;
          border: none;
          color: var(--rb-text-muted);
          font-size: 11px;
          display: inline-flex;
          align-items: center;
          gap: 4px;
          cursor: pointer;
          text-transform: uppercase;
          letter-spacing: 0.06em;
      }

      .coach-toggle-btn:hover {
          color: #e5e7eb;
      }

      .coach-toggle-icon {
          font-size: 10px;
      }

      /* painel expandido */

      .coach-client-panel-full {
          display: none;
          width: 100%;
          margin-top: 10px;
          padding-top: 10px;
          border-top: 1px dashed rgba(55,65,81,0.85);
      }

      .coach-client-panel-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
          gap: 14px 24px;
      }

      .coach-client-group-title {
          font-size: 11px;
          color: var(--rb-text-muted);
          text-transform: uppercase;
          letter-spacing: 0.08em;
          margin-bottom: 6px;
      }

      .coach-client-group-fields {
          display: grid;
          grid-template-columns: 1fr;
          gap: 5px;
      }

      /* PHOTO CARDS (clicáveis) */
      .wk-photo-card {
          cursor: pointer;
          transition: transform var(--rb-transition-fast), box-shadow var(--rb-transition-fast), border-color var(--rb-transition-fast);
      }
      .wk-photo-card:hover {
          transform: translateY(-2px);
          box-shadow: 0 18px 38px rgba(0,0,0,0.75);
          border-color: rgba(148,163,184,0.8);
      }

      /* MODAIS (goal + photo) */

      .rb-modal-backdrop {
          position: fixed;
          inset: 0;
          background: rgba(15, 23, 42, 0.78);
          display: none;
          align-items: center;
          justify-content: center;
          z-index: 999;
      }

      .rb-modal {
          background: #020617;
          border-radius: 18px;
          border: 1px solid rgba(55,65,81,0.9);
          box-shadow: 0 28px 60px rgba(0,0,0,0.85);
          padding: 16px 18px 18px;
          max-width: 520px;
          width: calc(100% - 32px);
          max-height: 80vh;
          overflow-y: auto;
      }

      .rb-modal.rb-photo-modal {
          max-width: 760px;
      }

      .rb-modal-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 10px;
          margin-bottom: 10px;
      }

      .rb-modal-title {
          font-size: 15px;
          font-weight: 600;
          color: var(--rb-text-main);
          margin: 0;
      }

      .rb-modal-close {
          border: none;
          background: transparent;
          color: var(--rb-text-muted);
          font-size: 18px;
          cursor: pointer;
          padding: 2px 4px;
      }

      .rb-modal-close:hover {
          color: #e5e7eb;
      }

      .goal-progress-list {
          display: flex;
          flex-direction: column;
          gap: 8px;
      }

      .goal-progress-item {
          border-radius: 10px;
          border: 1px solid rgba(31,41,55,0.9);
          background: rgba(15,23,42,0.96);
          padding: 6px 8px;
      }

      .goal-progress-main {
          display: flex;
          justify-content: space-between;
          gap: 8px;
          font-size: 12px;
          color: var(--rb-text-main);
      }

      .goal-progress-date {
          font-weight: 500;
      }

      .goal-progress-value {
          color: var(--rb-accent-strong);
      }

      .goal-progress-note {
          margin-top: 4px;
          font-size: 12px;
          color: #e5e7eb;
      }

      /* Detalhes do goal no modal */
      .goal-detail-info {
          border-radius: 10px;
          border: 1px solid rgba(31,41,55,0.9);
          background: rgba(15,23,42,0.96);
          padding: 8px 10px;
          margin-bottom: 10px;
          font-size: 12px;
      }

      .goal-detail-title {
          font-size: 11px;
          text-transform: uppercase;
          letter-spacing: .08em;
          color: var(--rb-text-label);
          margin-bottom: 4px;
      }

      .goal-detail-line {
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
          margin-bottom: 2px;
      }

      .goal-detail-label {
          font-weight: 500;
          color: var(--rb-text-label);
      }

      .goal-detail-value {
          color: var(--rb-text-main);
      }

      .photo-modal-img {
          width: 100%;
          max-height: 60vh;
          object-fit: contain;
          border-radius: 12px;
          margin-bottom: 10px;
          background: #020617;
      }

      .photo-modal-meta {
          font-size: 12px;
          color: var(--rb-text-main);
          display: flex;
          flex-direction: column;
          gap: 4px;
      }

      .photo-modal-line strong {
          color: var(--rb-text-label);
          font-weight: 500;
          margin-right: 4px;
          text-transform: uppercase;
          letter-spacing: 0.06em;
          font-size: 11px;
      }

      @media (max-width: 900px) {
          .tw-header-row {
              flex-direction: column;
              align-items: flex-start;
          }

          .tw-summary-mini {
              justify-content: flex-start;
          }

          .tw-filters {
              align-items: stretch;
          }

          .tw-filter-right {
              width: 100%;
              justify-content: flex-start;
          }

          .coach-client-card {
              flex-direction: column;
          }

          .coach-client-main {
              flex: 1 1 auto;
          }
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
                <li><a href="dashboard_personal.php" class="rbf1-link">Dashboard</a></li>
                <li><a href="personal_profile.php" class="rbf1-link">Profile</a></li>
                <li><a href="trainer_workouts.php" class="rbf1-link">Workouts</a></li>
                <li><a href="trainer_checkins.php" class="rbf1-link rbf1-link-active">Check-ins</a></li>
                <li><a href="messages.php" class="rbf1-link">Messages</a></li>
                <li><a href="trainer_clients.php" class="rbf1-link">Clients</a></li>

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

<main class="coach-dashboard">
    <div class="coach-shell">

        <section class="wk-container coach-checkins">

            <!-- Título + resumo -->
            <div class="tw-header-row">
                <div>
                    <h1 class="tw-title">Check-ins & progress</h1>
                    <p class="tw-subtitle">
                        See how your clients are doing: workout check-ins, goal progress and photo updates.
                    </p>
                </div>

                <div class="tw-summary-mini">
                    <div class="tw-summary-pill">
                        <span class="tw-pill-label">Workout check-ins</span>
                        <span class="tw-pill-value"><?php echo (int)$totalCheckins; ?></span>
                    </div>
                    <div class="tw-summary-pill">
                        <span class="tw-pill-label">Goal updates</span>
                        <span class="tw-pill-value"><?php echo (int)$totalGoalUpdates; ?></span>
                    </div>
                    <div class="tw-summary-pill">
                        <span class="tw-pill-label">Photos</span>
                        <span class="tw-pill-value"><?php echo (int)$totalPhotos; ?></span>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <form method="get" class="tw-filters">
                <div class="tw-filter-left">
                    <label for="client_id" class="tw-field-label">Client</label>
                    <select name="client_id" id="client_id" class="tw-select">
                        <option value="">All clients</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"
                                <?php if ($clientFilter && (int)$c['id'] === (int)$clientFilter) echo 'selected'; ?>>
                                <?php echo htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tw-filter-search">
                    <label class="tw-field-label">Search by name</label>
                    <div class="msg-recipient-search">
                        <input
                            type="text"
                            id="client_search"
                            autocomplete="off"
                            placeholder="Type a client name..."
                        >
                    </div>
                    <div id="client_suggestions" class="msg-recipient-suggestions"></div>
                </div>

                <div class="tw-filter-right">
                    <button type="submit" class="wk-btn-primary tw-filter-button">
                        Apply
                    </button>
                    <?php if ($clientFilter): ?>
                        <a href="trainer_checkins.php" class="tw-link-reset">Clear filter</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- CLIENT INFO + tudo só se tiver cliente selecionado -->
            <?php if ($clientFilter && $selectedClient): ?>
                <?php
                  $clientNameRaw = (string)($selectedClient['name'] ?? '');
                  $clientNameEsc = htmlspecialchars($clientNameRaw, ENT_QUOTES, 'UTF-8');

                  // valores pré-formatados
                  $valLocation   = $formatClientField($selectedClient['time_zone'] ?? null);
                  $valGender     = ($selectedClient['gender'] ?? '') ? ucfirst((string)$selectedClient['gender']) : 'Not provided';
                  $valHeight     = $formatClientField($selectedClient['height_cm'] ?? null, 'height');
                  $valWeight     = $formatClientField($selectedClient['weight_kg'] ?? null, 'weight');
                  $valExperience = $formatClientField($selectedClient['training_experience'] ?? null);
                  $valInjuries   = $formatClientField($selectedClient['injuries_limitations'] ?? null);

                  $birthdayText  = $formatClientField($selectedClient['birthday'] ?? null, 'date');
                ?>
                <div class="coach-section">
                    <h2 class="coach-section-title">Client info</h2>
                    <p class="coach-section-sub">
                        Snapshot of this client's profile (email and phone hidden).
                    </p>

                    <div class="coach-client-card" id="coach-client-card">
                        <!-- coluna avatar + nome -->
                        <div class="coach-client-main">
                            <div class="coach-client-avatar">
                                <?php if (!empty($selectedClient['avatar_url'])): ?>
                                    <img
                                        src="<?php echo htmlspecialchars((string)$selectedClient['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="<?php echo 'Profile photo of ' . $clientNameEsc; ?>"
                                    >
                                <?php else: ?>
                                    <?php echo htmlspecialchars(strtoupper(substr($clientNameRaw, 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="coach-client-name">
                                    <?php echo $clientNameEsc; ?>
                                </div>
                                <div class="coach-client-meta">
                                    <?php
                                      $birthdayOut = ($birthdayText !== 'Not provided') ? $birthdayText : '';
                                      echo htmlspecialchars($birthdayOut, ENT_QUOTES, 'UTF-8');
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- resumo compacto -->
                        <div class="coach-client-summary-grid">
                            <div>
                                <div class="coach-client-stat-label">Location / time zone</div>
                                <div class="coach-client-stat-value">
                                    <?php echo htmlspecialchars($valLocation, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div>
                                <div class="coach-client-stat-label">Gender</div>
                                <div class="coach-client-stat-value">
                                    <?php echo htmlspecialchars($valGender, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div>
                                <div class="coach-client-stat-label">Height</div>
                                <div class="coach-client-stat-value">
                                    <?php echo htmlspecialchars($valHeight, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div>
                                <div class="coach-client-stat-label">Weight</div>
                                <div class="coach-client-stat-value">
                                    <?php echo htmlspecialchars($valWeight, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div>
                                <div class="coach-client-stat-label">Training experience</div>
                                <div class="coach-client-stat-value">
                                    <?php echo htmlspecialchars($valExperience, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div>
                                <div class="coach-client-stat-label">Injuries / limitations</div>
                                <div class="coach-client-stat-value">
                                    <?php echo htmlspecialchars($valInjuries, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                        </div>

                        <div class="coach-client-details-toggle">
                            <button type="button" id="client_details_toggle" class="coach-toggle-btn">
                                <span class="coach-toggle-text">View full profile</span>
                                <span class="coach-toggle-icon">▼</span>
                            </button>
                        </div>

                        <!-- painel completo (abre/fecha) -->
                        <div class="coach-client-panel-full" id="client_details_extra">
                            <div class="coach-client-panel-grid">

                                <!-- Personal & contact info -->
                                <div>
                                    <div class="coach-client-group-title">Personal & contact info</div>
                                    <div class="coach-client-group-fields">
                                        <div>
                                            <div class="coach-client-stat-label">Location / time zone</div>
                                            <div class="coach-client-stat-value">
                                                <?php echo htmlspecialchars($valLocation, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Body measurements -->
                                <div>
                                    <div class="coach-client-group-title">Body measurements</div>
                                    <div class="coach-client-group-fields">
                                        <div>
                                            <div class="coach-client-stat-label">Gender</div>
                                            <div class="coach-client-stat-value">
                                                <?php echo htmlspecialchars($valGender, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Date of birth</div>
                                            <div class="coach-client-stat-value">
                                                <?php echo htmlspecialchars($birthdayText, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Height</div>
                                            <div class="coach-client-stat-value">
                                                <?php echo htmlspecialchars($valHeight, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Average sleep</div>
                                            <div class="coach-client-stat-value">
                                                <?php
                                                  echo htmlspecialchars(
                                                      $formatClientField($selectedClient['sleep_average'] ?? null),
                                                      ENT_QUOTES,
                                                      'UTF-8'
                                                  );
                                                ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Stress level</div>
                                            <div class="coach-client-stat-value">
                                                <?php
                                                  echo htmlspecialchars(
                                                      $formatClientField($selectedClient['stress_level'] ?? null),
                                                      ENT_QUOTES,
                                                      'UTF-8'
                                                  );
                                                ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Weight</div>
                                            <div class="coach-client-stat-value">
                                                <?php echo htmlspecialchars($valWeight, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Training profile -->
                                <div>
                                    <div class="coach-client-group-title">Training profile</div>
                                    <div class="coach-client-group-fields">
                                        <div>
                                            <div class="coach-client-stat-label">Training experience</div>
                                            <div class="coach-client-stat-value">
                                                <?php echo htmlspecialchars($valExperience, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Training frequency</div>
                                            <div class="coach-client-stat-value">
                                                <?php
                                                  echo htmlspecialchars(
                                                      $formatClientField($selectedClient['training_frequency'] ?? null),
                                                      ENT_QUOTES,
                                                      'UTF-8'
                                                  );
                                                ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Training availability</div>
                                            <div class="coach-client-stat-value">
                                                <?php
                                                  echo htmlspecialchars(
                                                      $formatClientField($selectedClient['training_availability'] ?? null),
                                                      ENT_QUOTES,
                                                      'UTF-8'
                                                  );
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Health & nutrition -->
                                <div>
                                    <div class="coach-client-group-title">Health & nutrition</div>
                                    <div class="coach-client-group-fields">
                                        <div>
                                            <div class="coach-client-stat-label">Injuries / limitations</div>
                                            <div class="coach-client-stat-value">
                                                <?php echo htmlspecialchars($valInjuries, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Medical conditions / medications</div>
                                            <div class="coach-client-stat-value">
                                                <?php
                                                  $medCombined = trim(
                                                      (string)($selectedClient['medical_conditions'] ?? '') . ' ' .
                                                      (string)($selectedClient['medications'] ?? '')
                                                  );
                                                  echo htmlspecialchars(
                                                      $formatClientField($medCombined),
                                                      ENT_QUOTES,
                                                      'UTF-8'
                                                  );
                                                ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Hydration level</div>
                                            <div class="coach-client-stat-value">
                                                <?php
                                                  echo htmlspecialchars(
                                                      $formatClientField($selectedClient['hydration_level'] ?? null),
                                                      ENT_QUOTES,
                                                      'UTF-8'
                                                  );
                                                ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Food allergies / intolerances</div>
                                            <div class="coach-client-stat-value">
                                                <?php
                                                  echo htmlspecialchars(
                                                      $formatClientField($selectedClient['food_allergies'] ?? null),
                                                      ENT_QUOTES,
                                                      'UTF-8'
                                                  );
                                                ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="coach-client-stat-label">Dietary preferences</div>
                                            <div class="coach-client-stat-value">
                                                <?php
                                                  echo htmlspecialchars(
                                                      $formatClientField($selectedClient['dietary_preferences'] ?? null),
                                                      ENT_QUOTES,
                                                      'UTF-8'
                                                  );
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div> <!-- .coach-client-panel-grid -->
                        </div> <!-- .coach-client-panel-full -->
                    </div> <!-- .coach-client-card -->
                </div>

                <!-- WORKOUT CHECK-INS -->
                <div class="coach-section">
                    <h2 class="coach-section-title">Workout check-ins</h2>
                    <p class="coach-section-sub">
                        Recent workout logs from your clients. Based on training plans you created.
                    </p>

                    <?php if (empty($workoutLogs)): ?>
                        <p class="wk-empty">
                            No workout check-ins found for this client.
                        </p>
                    <?php else: ?>
                        <div class="coach-checkins-list">
                            <?php foreach ($workoutLogs as $log): ?>
                                <?php
                                    $performed = $log['performed_at']
                                        ? date('M d, Y · H:i', strtotime((string)$log['performed_at']))
                                        : '—';

                                    $statusRaw   = (string)($log['status'] ?? 'unknown');
                                    $statusClass = preg_replace('/[^a-z0-9_-]/i', '', strtolower($statusRaw)) ?: 'unknown';
                                    $statusLabel = ucfirst($statusClass);

                                    $difficulty = $log['difficulty'];
                                    $mood       = $log['mood'];

                                    $logClientName = htmlspecialchars((string)($log['client_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="wk-checkin-row">
                                    <div class="wk-checkin-date">
                                        <?php echo htmlspecialchars($performed, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>

                                    <div class="coach-checkin-client">
                                        <span>Client:</span>
                                        <?php echo $logClientName; ?>
                                    </div>

                                    <div class="wk-checkin-status wk-status-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>

                                    <div class="wk-checkin-meta">
                                        <?php if ($difficulty !== null): ?>
                                            <span>Difficulty: <?php echo (int)$difficulty; ?>/10</span>
                                        <?php endif; ?>
                                        <?php if ($mood !== null): ?>
                                            <span>Mood: <?php echo (int)$mood; ?>/5</span>
                                        <?php endif; ?>
                                        <?php if (!empty($log['session_label'])): ?>
                                            <span>Session: <?php echo htmlspecialchars((string)$log['session_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($log['plan_name'])): ?>
                                            <span>Plan: <?php echo htmlspecialchars((string)$log['plan_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($log['notes'])): ?>
                                        <div class="wk-checkin-notes">
                                            <?php echo nl2br(htmlspecialchars((string)$log['notes'], ENT_QUOTES, 'UTF-8')); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- GOAL PROGRESS -->
                <div class="coach-section">
                    <h2 class="coach-section-title">Goal progress</h2>
                    <p class="coach-section-sub">
                        Recent updates on client goals (weight, measurements, performance targets).
                    </p>

                    <?php if (!empty($clientGoals)): ?>
                        <div class="coach-goals-list" style="margin-bottom:8px;">
                            <?php foreach ($clientGoals as $g): ?>
                                <?php
                                  $goalId    = (int)($g['id'] ?? 0);
                                  $goalTitle = (string)($g['title'] ?? 'Goal');
                                  $goalTitleEsc = htmlspecialchars($goalTitle, ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="coach-goal-row"
                                     data-goal-id="<?php echo $goalId; ?>"
                                     data-goal-title="<?php echo $goalTitleEsc; ?>">
                                    <div class="coach-goal-header">
                                        <?php echo $goalTitleEsc; ?>
                                    </div>
                                    <div class="coach-goal-meta">
                                        <span>Client: <?php echo $clientNameEsc; ?></span>
                                        <span>Click to see full details & history</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($goalUpdates)): ?>
                        <p class="wk-empty">
                            <?php if (!empty($clientGoals)): ?>
                                No progress logs found for this client's goals yet.
                            <?php else: ?>
                                No goals or progress entries found for this client.
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <div class="coach-goals-list">
                            <?php foreach ($goalUpdates as $gp): ?>
                                <?php
                                    $logDate = $gp['log_date']
                                        ? date('M d, Y', strtotime((string)$gp['log_date']))
                                        : '—';
                                ?>
                                <div class="coach-goal-row">
                                    <div class="coach-goal-header">
                                        <?php echo htmlspecialchars((string)($gp['goal_title'] ?? 'Goal'), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="coach-goal-meta">
                                        <span>Client: <?php echo htmlspecialchars((string)($gp['client_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span>Date: <?php echo htmlspecialchars($logDate, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ($gp['value'] !== null && $gp['value'] !== ''): ?>
                                            <span>Value: <?php echo htmlspecialchars((string)$gp['value'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($gp['note'])): ?>
                                        <div class="coach-goal-notes">
                                            <?php echo nl2br(htmlspecialchars((string)$gp['note'], ENT_QUOTES, 'UTF-8')); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- PROGRESS PHOTOS -->
                <div class="coach-section">
                    <h2 class="coach-section-title">Progress photos</h2>
                    <p class="coach-section-sub">
                        Latest check-in photos from your clients.
                    </p>

                    <?php if (empty($photos)): ?>
                        <p class="wk-empty">
                            No progress photos found for this client.
                        </p>
                    <?php else: ?>
                        <div class="wk-photo-grid">
                            <?php foreach ($photos as $ph): ?>
                                <?php
                                    if (!empty($ph['taken_at'])) {
                                        $photoDate = date('d/m/Y', strtotime((string)$ph['taken_at']));
                                    } else {
                                        $photoDate = date('d/m/Y', strtotime((string)$ph['created_at']));
                                    }

                                    $poseLabel   = ucfirst((string)($ph['pose'] ?? ''));
                                    $weightLabel = !empty($ph['weight_kg'])
                                        ? number_format((float)$ph['weight_kg'], 1) . ' kg'
                                        : '';
                                    $notesText   = (string)($ph['notes'] ?? '');

                                    $photoClientNameRaw = (string)($ph['client_name'] ?? '');
                                    $photoClientNameEsc = htmlspecialchars($photoClientNameRaw, ENT_QUOTES, 'UTF-8');

                                    $photoSrcRaw = (string)($ph['file_path'] ?? '');
                                    $photoSrcEsc = htmlspecialchars($photoSrcRaw, ENT_QUOTES, 'UTF-8');

                                    $photoAlt = $photoClientNameRaw !== ''
                                        ? ('Progress photo of ' . $photoClientNameEsc)
                                        : 'Progress photo';
                                ?>
                                <div class="wk-photo-card"
                                     data-photo-src="<?php echo $photoSrcEsc; ?>"
                                     data-photo-date="<?php echo htmlspecialchars($photoDate, ENT_QUOTES, 'UTF-8'); ?>"
                                     data-photo-client="<?php echo $photoClientNameEsc; ?>"
                                     data-photo-pose="<?php echo htmlspecialchars($poseLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                     data-photo-weight="<?php echo htmlspecialchars($weightLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                     data-photo-notes="<?php echo htmlspecialchars($notesText, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="wk-photo-thumb-wrapper">
                                        <img src="<?php echo $photoSrcEsc; ?>" alt="<?php echo $photoAlt; ?>">
                                    </div>
                                    <div class="wk-photo-info">
                                        <div class="wk-photo-date">
                                            <?php echo htmlspecialchars($photoDate, ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="coach-photo-client">
                                            Client: <?php echo $photoClientNameEsc; ?>
                                        </div>
                                        <div class="wk-photo-meta">
                                            <?php if ($poseLabel !== ''): ?>
                                                <span><?php echo htmlspecialchars($poseLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                            <?php if ($weightLabel !== ''): ?>
                                                <span><?php echo htmlspecialchars($weightLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($notesText !== ''): ?>
                                            <div class="wk-photo-notes">
                                                <?php echo nl2br(htmlspecialchars($notesText, ENT_QUOTES, 'UTF-8')); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Exporta goalUpdates e clientGoals para JS (para modal de histórico/detalhes) -->
                <script>
                  window.goalProgressData = <?php
                      echo json_encode(
                          $goalUpdates,
                          JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                      );
                  ?>;
                  window.clientGoalsData = <?php
                      echo json_encode(
                          $clientGoals,
                          JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                      );
                  ?>;
                </script>

            <?php else: ?>
                <!-- Estado sem cliente selecionado -->
                <div class="coach-section">
                    <p class="wk-empty">
                        Select a client above to see workout check-ins, goals and progress photos.
                    </p>
                </div>
            <?php endif; ?>

        </section>

        <!-- MODAL: GOAL DETAILS -->
        <div id="goal-modal" class="rb-modal-backdrop">
            <div class="rb-modal">
                <div class="rb-modal-header">
                    <h3 class="rb-modal-title" id="goal-modal-title">Goal details</h3>
                    <button type="button" class="rb-modal-close goal-modal-close" aria-label="Close">
                        ×
                    </button>
                </div>
                <div id="goal-modal-body">
                    <!-- preenchido via JS -->
                </div>
            </div>
        </div>

        <!-- MODAL: PHOTO VIEWER -->
        <div id="photo-modal" class="rb-modal-backdrop">
            <div class="rb-modal rb-photo-modal">
                <div class="rb-modal-header">
                    <h3 class="rb-modal-title" id="photo-modal-title">Progress photo</h3>
                    <button type="button" class="rb-modal-close photo-modal-close" aria-label="Close">
                        ×
                    </button>
                </div>
                <img id="photo-modal-img" class="photo-modal-img" src="" alt="Progress photo large">
                <div id="photo-modal-meta" class="photo-modal-meta"></div>
            </div>
        </div>

    </div>
</main>

<script>
  // helper simples para escapar HTML no JS
  function rbEscapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      })[m];
    });
  }

  // Menu mobile (IDs mantidos: rbf1-toggle, rbf1-nav)
  (function () {
    const toggle = document.getElementById('rbf1-toggle');
    const nav    = document.getElementById('rbf1-nav');
    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        nav.classList.toggle('rbf1-open');
      });
    }
  })();

  // Autocomplete de clientes (igual mensagens)
  (function () {
    const searchInput     = document.getElementById('client_search');
    const suggestionsBox  = document.getElementById('client_suggestions');
    const selectEl        = document.getElementById('client_id');

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

    function showSuggestions(query) {
      const q = query.trim().toLowerCase();
      if (!q) {
        clearSuggestions();
        return;
      }
      const filtered = clients.filter(c => c.label.toLowerCase().includes(q));
      if (!filtered.length) {
        clearSuggestions();
        return;
      }
      suggestionsBox.innerHTML = '';
      filtered.slice(0, 12).forEach(function (c) {
        const div = document.createElement('div');
        div.className = 'msg-recipient-suggestions-item';
        div.textContent = c.label;
        div.addEventListener('mousedown', function (e) {
          e.preventDefault();
          selectEl.value = c.value;
          searchInput.value = c.label;
          clearSuggestions();
        });
        suggestionsBox.appendChild(div);
      });
      suggestionsBox.style.display = 'block';
    }

    searchInput.addEventListener('input', function () {
      showSuggestions(this.value);
    });

    searchInput.addEventListener('blur', function () {
      setTimeout(clearSuggestions, 200);
    });
  })();

  // Toggle full profile
  (function () {
    const card   = document.getElementById('coach-client-card');
    const extra  = document.getElementById('client_details_extra');
    const toggle = document.getElementById('client_details_toggle');
    if (!card || !extra || !toggle) return;

    const textSpan = toggle.querySelector('.coach-toggle-text');
    const iconSpan = toggle.querySelector('.coach-toggle-icon');
    let isOpen = false;

    function setState(open) {
      isOpen = open;
      extra.style.display = open ? 'block' : 'none';
      if (textSpan && iconSpan) {
        textSpan.textContent = open ? 'Hide full profile' : 'View full profile';
        iconSpan.textContent = open ? '▲' : '▼';
      }
    }

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      setState(!isOpen);
    });

    card.addEventListener('click', function (e) {
      if (e.target.closest('#client_details_toggle')) return;
      setState(!isOpen);
    });
  })();

  // MODAL: Goal details (mostra dados do goal + histórico de progressos)
  (function () {
    const goalData    = (window.goalProgressData || []);
    const goalDetails = (window.clientGoalsData || []);
    const modal       = document.getElementById('goal-modal');
    const titleEl     = document.getElementById('goal-modal-title');
    const bodyEl      = document.getElementById('goal-modal-body');
    if (!modal || !titleEl || !bodyEl) return;

    const closeButtons = modal.querySelectorAll('.goal-modal-close');

    function closeModal() {
      modal.style.display = 'none';
    }

    closeButtons.forEach(btn => btn.addEventListener('click', closeModal));
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });

    const rows = document.querySelectorAll('.coach-goal-row[data-goal-id]');
    rows.forEach(function (row) {
      row.addEventListener('click', function () {
        const goalId    = parseInt(this.getAttribute('data-goal-id') || '0', 10);
        const goalTitle = this.getAttribute('data-goal-title') || 'Goal';

        titleEl.textContent = goalTitle;

        const goalInfo = goalDetails.find(function (g) {
          return parseInt(g.id, 10) === goalId;
        }) || null;

        const entries = goalData.filter(function (gp) {
          return parseInt(gp.goal_id, 10) === goalId;
        });

        let html = '';

        // bloco de detalhes do goal
        if (goalInfo) {
          html += '<div class="goal-detail-info">';
          html += '<div class="goal-detail-title">Goal details</div>';

          // Mostra todas as colunas, exceto algumas técnicas
          const omit = { id: true, client_id: true };
          Object.keys(goalInfo).forEach(function (key) {
            if (omit[key]) return;
            const value = goalInfo[key];
            if (value === null || value === '') return;
            html += '<div class="goal-detail-line">';
            html += '<span class="goal-detail-label">' +
                    rbEscapeHtml(key.replace(/_/g, ' ')) +
                    ':</span>';
            html += '<span class="goal-detail-value">' +
                    rbEscapeHtml(String(value)) +
                    '</span>';
            html += '</div>';
          });

          html += '</div>';
        }

        // bloco de histórico
        if (!entries.length) {
          html += '<p class="wk-empty" style="margin-top:4px;">No progress entries for this goal yet.</p>';
        } else {
          html += '<div class="goal-progress-list">';
          entries.forEach(function (gp) {
            const dateStr  = gp.log_date || '';
            const valueStr = (gp.value !== null && gp.value !== '') ? gp.value : '';
            const noteStr  = gp.note || '';

            html += '<div class="goal-progress-item">';
            html += '<div class="goal-progress-main">';
            html += '<span class="goal-progress-date">' + rbEscapeHtml(dateStr) + '</span>';
            if (valueStr !== '') {
              html += '<span class="goal-progress-value">' + rbEscapeHtml(String(valueStr)) + '</span>';
            }
            html += '</div>';
            if (noteStr !== '') {
              html += '<div class="goal-progress-note">' +
                      rbEscapeHtml(noteStr).replace(/\n/g, '<br>') +
                      '</div>';
            }
            html += '</div>';
          });
          html += '</div>';
        }

        bodyEl.innerHTML = html;
        modal.style.display = 'flex';
      });
    });
  })();

  // MODAL: Photo viewer (abre foto grande)
  (function () {
    const modal    = document.getElementById('photo-modal');
    const imgEl    = document.getElementById('photo-modal-img');
    const metaEl   = document.getElementById('photo-modal-meta');
    const titleEl  = document.getElementById('photo-modal-title');
    if (!modal || !imgEl || !metaEl || !titleEl) return;

    function closeModal() {
      modal.style.display = 'none';
      imgEl.src = '';
      metaEl.innerHTML = '';
    }

    const closeButtons = modal.querySelectorAll('.photo-modal-close');
    closeButtons.forEach(btn => btn.addEventListener('click', closeModal));
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });

    const cards = document.querySelectorAll('.wk-photo-card');
    cards.forEach(function (card) {
      card.addEventListener('click', function () {
        const src    = this.getAttribute('data-photo-src')    || '';
        const date   = this.getAttribute('data-photo-date')   || '';
        const client = this.getAttribute('data-photo-client') || '';
        const pose   = this.getAttribute('data-photo-pose')   || '';
        const weight = this.getAttribute('data-photo-weight') || '';
        const notes  = this.getAttribute('data-photo-notes')  || '';

        if (!src) return;

        imgEl.src = src;
        titleEl.textContent = client ? ('Progress photo – ' + client) : 'Progress photo';

        let html = '';
        if (client) {
          html += '<div class="photo-modal-line"><strong>Client:</strong> ' +
                  rbEscapeHtml(client) + '</div>';
        }
        if (date) {
          html += '<div class="photo-modal-line"><strong>Date:</strong> ' +
                  rbEscapeHtml(date) + '</div>';
        }

        const poseWeight = [pose, weight].filter(Boolean).join(' · ');
        if (poseWeight) {
          html += '<div class="photo-modal-line"><strong>Pose / weight:</strong> ' +
                  rbEscapeHtml(poseWeight) + '</div>';
        }

        if (notes) {
          html += '<div class="photo-modal-line"><strong>Notes:</strong><br>' +
                  rbEscapeHtml(notes).replace(/\n/g, '<br>') +
                  '</div>';
        }

        metaEl.innerHTML = html;
        modal.style.display = 'flex';
      });
    });
  })();
</script>

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

</body>
</html>
