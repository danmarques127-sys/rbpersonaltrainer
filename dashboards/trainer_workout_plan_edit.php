<?php
// trainer_workout_plan_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

// ================================
// CSRF (POST forms)
// ================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

/*
 * 2) Resolver de onde vem a sessão que vamos editar
 *
 * Ordem de prioridade:
 *   1) POST[session_id]  (ao salvar o formulário)
 *   2) GET[session_id]
 *   3) GET[plan_id]      → pegar/criar sessão do plano
 *   4) GET[client_id] + GET[mode=new] → criar plano + sessão
 */

$session_id = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT);
if (!$session_id) {
    $session_id = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);
}

$plan_id   = filter_input(INPUT_GET, 'plan_id', FILTER_VALIDATE_INT);
$client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
$mode      = isset($_GET['mode']) ? (string)$_GET['mode'] : null;

if ($session_id && $session_id > 0) {
    // Já temos uma sessão específica, não precisamos criar nada.
} elseif ($plan_id && $plan_id > 0) {
    // Veio um plan_id: achar (ou criar) uma sessão para esse plano,
    // garantindo que o plano é desse coach.

    // 2.1) Garante que o plano existe e que foi criado por esse coach
    $stmt = $pdo->prepare("
        SELECT id, user_id, name
        FROM workout_plans
        WHERE id = :pid
          AND created_by = :coach
        LIMIT 1
    ");
    $stmt->execute([
        ':pid'   => $plan_id,
        ':coach' => $current_user_id,
    ]);
    $planRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$planRow) {
        header('Location: trainer_workouts.php');
        exit;
    }

    // Garante que $client_id reflete o dono real do plano (cliente)
    if (empty($client_id) || $client_id <= 0) {
        $client_id = (int)$planRow['user_id'];
    }

    // 2.2) Tentar pegar a primeira sessão do plano
    $stmt = $pdo->prepare("
        SELECT id
        FROM workout_sessions
        WHERE plan_id = :pid
        ORDER BY order_index ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([':pid' => $plan_id]);
    $sessRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sessRow) {
        $session_id = (int)$sessRow['id'];
    } else {
        // Não há sessões ainda: cria uma sessão padrão "Session 1"
        $insert = $pdo->prepare("
            INSERT INTO workout_sessions
                (plan_id, title, day_of_week, session_label, notes, order_index)
            VALUES
                (:pid, :title, NULL, NULL, NULL, 1)
        ");
        $insert->execute([
            ':pid'   => $plan_id,
            ':title' => 'Session 1',
        ]);
        $session_id = (int)$pdo->lastInsertId();
    }
} elseif ($client_id && $client_id > 0 && $mode === 'new') {
    // Criar um NOVO plano para esse cliente, depois criar a primeira sessão

    // 2.3) Verifica se cliente existe e é role 'user' OU 'client'
    $stmt = $pdo->prepare("
        SELECT id, name
        FROM users
        WHERE id = :cid
          AND role IN ('user', 'client')
        LIMIT 1
    ");
    $stmt->execute([':cid' => $client_id]);
    $clientRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$clientRow) {
        header('Location: trainer_workouts.php');
        exit;
    }

    // 2.4) Cria o plano já atrelado a esse cliente + esse coach
    $defaultPlanName = 'New workout plan';
    $insertPlan = $pdo->prepare("
        INSERT INTO workout_plans (user_id, created_by, name)
        VALUES (:uid, :coach, :name)
    ");
    $insertPlan->execute([
        ':uid'   => $client_id,
        ':coach' => $current_user_id,
        ':name'  => $defaultPlanName,
    ]);
    $plan_id = (int)$pdo->lastInsertId();

    // 2.5) Cria a primeira sessão
    $insertSess = $pdo->prepare("
        INSERT INTO workout_sessions
            (plan_id, title, day_of_week, session_label, notes, order_index)
        VALUES
            (:pid, :title, NULL, NULL, NULL, 1)
    ");
    $insertSess->execute([
        ':pid'   => $plan_id,
        ':title' => 'Session 1',
    ]);
    $session_id = (int)$pdo->lastInsertId();
} else {
    header('Location: trainer_workouts.php');
    exit;
}

/*
 * 3) Carrega sessão + plano + cliente
 *    Agora garantindo TAMBÉM que o plano é desse coach.
 */
$sql = "
    SELECT
        ws.id            AS session_id,
        ws.title         AS session_title,
        ws.day_of_week,
        ws.session_label,
        ws.notes,
        ws.order_index,

        wp.id            AS plan_id,
        wp.name          AS plan_name,
        wp.user_id       AS client_id,
        wp.created_by    AS coach_id,

        uclient.name     AS client_name
    FROM workout_sessions ws
    JOIN workout_plans wp   ON wp.id = ws.plan_id
    JOIN users uclient      ON uclient.id = wp.user_id
    WHERE ws.id = :sid
      AND wp.created_by = :coach
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':sid'   => $session_id,
    ':coach' => $current_user_id,
]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    header('Location: trainer_workouts.php');
    exit;
}

// 3.b) Rascunho + helper
$draftKey = 'workout_edit_draft_' . $session_id;
$unknown_exercises = [];

/**
 * Verifica se o nome existe no array de presets
 */
if (!function_exists('exerciseNameExistsInPresets')) {
    function exerciseNameExistsInPresets(string $name, array $presets): bool
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return false;
        }
        foreach ($presets as $p) {
            if (isset($p['name']) && mb_strtolower((string)$p['name']) === $needle) {
                return true;
            }
        }
        return false;
    }
}

/*
 * 4) Lista de exercícios base (presets)
 *    Agora com primary_muscles, description e youtube_url (opcional).
 */
$exercisePresets = [
    // 1. Legs
    [
        'name'           => 'Leg Press Machine',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Quadriceps, glutes, hamstrings',
        'description'    => 'Sentado na máquina, pés na plataforma à largura dos ombros. Empurre estendendo os joelhos sem travar completamente e retorne controlando o peso.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Hack Squat Machine',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Quadriceps, glutes',
        'description'    => 'Posicione costas e ombros no apoio, pés na plataforma. Agache flexionando joelhos e quadris e suba estendendo as pernas.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Seated Leg Curl Machine',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Hamstrings',
        'description'    => 'Sentado, pernas estendidas com calcanhares sob o rolo. Flexione os joelhos puxando o rolo para baixo e retorne devagar.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Lying Leg Curl Machine',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Hamstrings',
        'description'    => 'Deitado de barriga para baixo, rolo atrás dos calcanhares. Flexione os joelhos trazendo os calcanhares em direção aos glúteos.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Leg Extension Machine',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Quadriceps',
        'description'    => 'Sentado, rolo na frente dos tornozelos. Estenda os joelhos elevando o peso e desça controlando o movimento.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Smith Machine Squats',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Quadriceps, glutes',
        'description'    => 'Barra guiada apoiada nas costas. Agache flexionando quadril e joelhos mantendo peito aberto e suba empurrando o chão.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Standing Calf Raise Machine',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Calves (gastrocnêmio)',
        'description'    => 'Em pé na máquina com ombros sob as almofadas, eleve os calcanhares ao máximo e abaixe alongando a panturrilha.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Seated Calf Raise Machine',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Calves (sólio)',
        'description'    => 'Sentado com joelhos flexionados, apoio sobre as coxas. Eleve os calcanhares e desça controlando o alongamento.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Glute Kickback Machine',
        'body_part'      => 'Legs',
        'category'       => 'Machine',
        'primary_muscles'=> 'Glutes',
        'description'    => 'Apoie o peito na máquina, um pé no apoio. Empurre o pé para trás e para cima contra a resistência, contraindo o glúteo.',
        'youtube_url'    => null,
    ],

    [
        'name'           => 'Squat (Barbell)',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, glutes, core',
        'description'    => 'Barra nas costas, pés afastados na largura dos ombros. Agache até as coxas ficarem paralelas ao chão e suba empurrando o chão.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Squat (Dumbbell)',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, glutes, core',
        'description'    => 'Segure halteres ao lado do corpo ou em posição de rack. Agache flexionando quadris e joelhos e levante controlando.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Front Squat',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, core',
        'description'    => 'Barra apoiada na frente dos ombros. Mantenha o tronco ereto enquanto agacha e retorna à posição inicial.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Goblet Squat',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, glutes',
        'description'    => 'Segure um halter ou kettlebell na frente do peito. Agache mantendo cotovelos entre os joelhos e suba mantendo o tronco ereto.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Bulgarian Split Squat',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, glutes',
        'description'    => 'Um pé apoiado atrás em banco, outro à frente. Flexione o joelho da frente descendo o corpo e suba empurrando com a perna da frente.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Walking Lunge',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, glutes, hamstrings',
        'description'    => 'Dê um passo à frente, flexione ambos os joelhos e desça o quadril. Empurre com a perna da frente e avance para o próximo passo.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Reverse Lunge',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Glutes, hamstrings, quadriceps',
        'description'    => 'Partindo em pé, dê um passo para trás e flexione ambos os joelhos. Retorne trazendo a perna de trás para a posição inicial.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Step-ups (Barbell)',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, glutes',
        'description'    => 'Barra nas costas, suba em um banco com um pé, estenda o joelho e quadril, depois desça controlado.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Step-ups (Dumbbell)',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, glutes',
        'description'    => 'Com halteres nas mãos, suba no banco com um pé, estenda o corpo e desça mantendo o controle.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Romanian Deadlift',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Hamstrings, glutes, lombar',
        'description'    => 'De pé com barra, desça o tronco empurrando o quadril para trás com leve flexão de joelhos e volte contraindo glúteos.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Conventional Deadlift',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Posterior de coxa, glutes, costas',
        'description'    => 'Pés sob a barra, segure com as mãos fora dos joelhos. Eleve a barra estendendo joelhos e quadris, mantendo costas neutras.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Single-Leg Romanian Deadlift',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Hamstrings, glutes, estabilidade de core',
        'description'    => 'Em apoio unipodal, incline o tronco à frente levando a perna oposta para trás, mantendo a coluna neutra, e retorne.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Sumo Deadlift',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Glutes, adutores, quadriceps',
        'description'    => 'Pés bem afastados e ponta dos pés para fora. Segure a barra entre as pernas e estenda joelhos e quadris para subir.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Glute-Ham Raise',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Hamstrings, glutes',
        'description'    => 'No aparelho GHR, desça o tronco controlando com posteriores e retorne contraindo glúteos e posteriores de coxa.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Pistol Squat',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Quadriceps, glutes, core',
        'description'    => 'Agachamento unilateral com a outra perna estendida à frente. Desça o máximo que conseguir com controle e suba.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Curtsy Lunge',
        'body_part'      => 'Legs',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Glutes (glúteo médio), quadriceps',
        'description'    => 'Cruze uma perna atrás da outra em diagonal, flexionando os joelhos, e volte à posição inicial.',
        'youtube_url'    => null,
    ],

    // 2. Glutes
    [
        'name'           => 'Hip Abduction Machine',
        'body_part'      => 'Glutes',
        'category'       => 'Machine',
        'primary_muscles'=> 'Glúteo médio e mínimo',
        'description'    => 'Sentado na máquina, abra os joelhos contra a resistência e retorne controlando a volta.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Hip Adduction Machine',
        'body_part'      => 'Glutes',
        'category'       => 'Machine',
        'primary_muscles'=> 'Adutores de quadril',
        'description'    => 'Na máquina de adução, aproxime as pernas contra a resistência e volte devagar.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Smith Machine Hip Thrust',
        'body_part'      => 'Glutes',
        'category'       => 'Machine',
        'primary_muscles'=> 'Glutes, hamstrings',
        'description'    => 'Costas apoiadas em banco, barra na linha do quadril. Eleve o quadril até alinhar tronco e coxas, contraindo glúteos.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Cable Glute Kickback',
        'body_part'      => 'Glutes',
        'category'       => 'Cable',
        'primary_muscles'=> 'Gluteus maximus',
        'description'    => 'Com tornozeleira no cabo, estenda a perna para trás e ligeiramente para cima contra a resistência.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Glute Kickback Machine',
        'body_part'      => 'Glutes',
        'category'       => 'Machine',
        'primary_muscles'=> 'Glutes',
        'description'    => 'Variante em máquina focada em extensão de quadril empurrando o apoio com o pé.',
        'youtube_url'    => null,
    ],

    [
        'name'           => 'Barbell Hip Thrust',
        'body_part'      => 'Glutes',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Glutes, hamstrings',
        'description'    => 'Costas em banco, barra sobre o quadril. Eleve o quadril, faça pausa em cima e desça controlando.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Glute Bridge',
        'body_part'      => 'Glutes',
        'category'       => 'Bodyweight / Barbell',
        'primary_muscles'=> 'Glutes',
        'description'    => 'Deitado, pés no chão, eleve o quadril contraindo glúteos e retorne sem encostar completamente.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Single-Leg Glute Bridge',
        'body_part'      => 'Glutes',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Glutes, core',
        'description'    => 'Mesma posição do glute bridge, mas com uma perna elevada, trabalhando unilateralmente.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Step-ups',
        'body_part'      => 'Glutes',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Glutes, quadriceps',
        'description'    => 'Subida em banco focando em empurrar pelo calcanhar da perna que sobe.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Cable Pull-Through',
        'body_part'      => 'Glutes',
        'category'       => 'Cable',
        'primary_muscles'=> 'Glutes, hamstrings',
        'description'    => 'De costas para a polia baixa, puxe o cabo entre as pernas estendendo o quadril para frente.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Kettlebell Swing',
        'body_part'      => 'Glutes',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Glutes, hamstrings, core',
        'description'    => 'Movimento balístico usando extensão de quadril para projetar o kettlebell à frente.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Donkey Kick',
        'body_part'      => 'Glutes',
        'category'       => 'Bodyweight / Cable',
        'primary_muscles'=> 'Glutes',
        'description'    => 'Em quatro apoios, eleve uma perna para cima com joelho flexionado, contraindo o glúteo.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Fire Hydrant',
        'body_part'      => 'Glutes',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Glúteo médio',
        'description'    => 'Em quatro apoios, abra o joelho para o lado mantendo o quadril alinhado.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Frog Pumps',
        'body_part'      => 'Glutes',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Glutes',
        'description'    => 'Deitado, solas dos pés juntas e joelhos abertos. Eleve o quadril em movimentos curtos e rápidos.',
        'youtube_url'    => null,
    ],

    // 3. Shoulders
    [
        'name'           => 'Shoulder Press Machine',
        'body_part'      => 'Shoulders',
        'category'       => 'Machine',
        'primary_muscles'=> 'Deltoide anterior e medial, tríceps',
        'description'    => 'Sentado na máquina, empurre as alças acima da cabeça mantendo o tronco estável.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Lateral Raise Machine',
        'body_part'      => 'Shoulders',
        'category'       => 'Machine',
        'primary_muscles'=> 'Deltoide medial',
        'description'    => 'Sentado, levante os braços para o lado até a altura dos ombros e desça controlado.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Rear Delt Machine',
        'body_part'      => 'Shoulders',
        'category'       => 'Machine',
        'primary_muscles'=> 'Deltoide posterior, parte alta das costas',
        'description'    => 'Sentado de frente para o encosto, abra os braços para trás focando em contrair parte de trás dos ombros.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Smith Machine Overhead Press',
        'body_part'      => 'Shoulders',
        'category'       => 'Machine',
        'primary_muscles'=> 'Deltoides, tríceps',
        'description'    => 'Em pé ou sentado sob a barra guiada, pressione a barra acima da cabeça e retorne.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Overhead Press (Barbell)',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Deltoides, trapézio, tríceps',
        'description'    => 'Em pé com barra na altura dos ombros, pressione acima da cabeça mantendo o core firme.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Overhead Press (Dumbbell)',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Deltoides, tríceps',
        'description'    => 'Sentado ou em pé, pressione halteres acima da cabeça e desça até próxima da orelha.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Arnold Press',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Deltoides completo',
        'description'    => 'Comece com halteres à frente do peito e palmas voltadas para você, gire enquanto pressiona acima da cabeça.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Dumbbell Lateral Raise',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Deltoide medial',
        'description'    => 'Com halteres ao lado do corpo, eleve os braços até altura dos ombros com cotovelos levemente flexionados.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Dumbbell Front Raise',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Deltoide anterior',
        'description'    => 'Eleve os halteres à frente do corpo até a altura dos ombros e retorne.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Dumbbell Reverse Fly',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Deltoide posterior, parte alta das costas',
        'description'    => 'Incline o tronco à frente e abra os braços para os lados focando em contrair parte de trás dos ombros.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Upright Row (Barbell)',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Deltoides, trapézio',
        'description'    => 'Puxe a barra na frente do corpo até altura do peito com cotovelos apontando para cima.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Upright Row (Dumbbell)',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Deltoides, trapézio',
        'description'    => 'Mesma ideia do upright row com barra, usando halteres.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Upright Row (Cable)',
        'body_part'      => 'Shoulders',
        'category'       => 'Cable',
        'primary_muscles'=> 'Deltoides, trapézio',
        'description'    => 'Com cabo baixo, puxe a barra ou corda na frente do corpo até a linha do peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Face Pull (Cable)',
        'body_part'      => 'Shoulders',
        'category'       => 'Cable',
        'primary_muscles'=> 'Deltoide posterior, trapézio médio',
        'description'    => 'Com corda na polia alta, puxe em direção ao rosto abrindo os cotovelos para fora.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Dumbbell Shrugs',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Trapézio superior',
        'description'    => 'Com halteres ao lado, eleve os ombros em direção às orelhas e desça.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Barbell Shrugs',
        'body_part'      => 'Shoulders',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Trapézio superior',
        'description'    => 'Com barra nas mãos à frente do corpo, faça elevação de ombros controlada.',
        'youtube_url'    => null,
    ],

    // 4. Chest
    [
        'name'           => 'Chest Press Machine',
        'body_part'      => 'Chest',
        'category'       => 'Machine',
        'primary_muscles'=> 'Peitoral maior, tríceps, ombro anterior',
        'description'    => 'Sentado na máquina, empurre as alças à frente estendendo os braços e retorne.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Pec Deck Machine',
        'body_part'      => 'Chest',
        'category'       => 'Machine',
        'primary_muscles'=> 'Peitoral maior',
        'description'    => 'Com braços apoiados nas almofadas, feche-os à frente do peito e abra de volta.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Incline Chest Press Machine',
        'body_part'      => 'Chest',
        'category'       => 'Machine',
        'primary_muscles'=> 'Peitoral superior, ombros',
        'description'    => 'Versão inclinada da chest press focando porção superior do peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Cable Chest Fly',
        'body_part'      => 'Chest',
        'category'       => 'Cable',
        'primary_muscles'=> 'Peitoral maior',
        'description'    => 'Com cabos nas mãos, faça movimento de abraço trazendo as mãos à frente do peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Smith Machine Bench Press',
        'body_part'      => 'Chest',
        'category'       => 'Machine',
        'primary_muscles'=> 'Peitoral, tríceps, ombro',
        'description'    => 'Deitado no banco sob a barra guiada, desça até a linha do peito e empurre de volta.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Bench Press (Barbell)',
        'body_part'      => 'Chest',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Peitoral, tríceps, ombro anterior',
        'description'    => 'Deitado, barra nas mãos, desça até perto do peito e empurre até extensão dos braços.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Bench Press (Dumbbell)',
        'body_part'      => 'Chest',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Peitoral, tríceps',
        'description'    => 'Mesma ideia do supino com barra, usando halteres para maior amplitude.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Incline Bench Press',
        'body_part'      => 'Chest',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Peitoral superior',
        'description'    => 'Supino em banco inclinado, focando parte superior do peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Decline Bench Press',
        'body_part'      => 'Chest',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Peitoral inferior',
        'description'    => 'Supino em banco declinado, destacando porção inferior do peitoral.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Dumbbell Fly',
        'body_part'      => 'Chest',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Peitoral',
        'description'    => 'Deitado, braços abertos com leve flexão de cotovelos, feche em arco acima do peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Incline Dumbbell Fly',
        'body_part'      => 'Chest',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Peitoral superior',
        'description'    => 'Variante inclinada do fly com foco na parte alta do peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Push-ups',
        'body_part'      => 'Chest',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Peitoral, tríceps, ombros',
        'description'    => 'Flexão de braços no chão, mantendo corpo alinhado dos calcanhares à cabeça.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Chest Dips',
        'body_part'      => 'Chest',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Peitoral inferior, tríceps',
        'description'    => 'Em barras paralelas, desça o corpo inclinando levemente o tronco à frente e empurre de volta.',
        'youtube_url'    => null,
    ],

    // 5. Back
    [
        'name'           => 'Lat Pulldown Machine',
        'body_part'      => 'Back',
        'category'       => 'Machine',
        'primary_muscles'=> 'Latíssimos, bíceps',
        'description'    => 'Sentado, puxe a barra da máquina em direção ao peito mantendo peito aberto.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Seated Row Machine',
        'body_part'      => 'Back',
        'category'       => 'Machine',
        'primary_muscles'=> 'Dorsal, rombóides, bíceps',
        'description'    => 'Puxe as alças em direção ao abdômen mantendo ombros para trás e peito aberto.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'T-Bar Row Machine',
        'body_part'      => 'Back',
        'category'       => 'Machine',
        'primary_muscles'=> 'Costas médias, bíceps',
        'description'    => 'Inclinado sobre o aparelho, puxe a alça em direção ao peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Pull-over Machine',
        'body_part'      => 'Back',
        'category'       => 'Machine',
        'primary_muscles'=> 'Latíssimos',
        'description'    => 'Com braços estendidos, traga a alça em arco da posição acima da cabeça até perto do corpo.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Assisted Pull-Up Machine',
        'body_part'      => 'Back',
        'category'       => 'Machine',
        'primary_muscles'=> 'Costas e bíceps',
        'description'    => 'Barra fixa assistida, suba até que o queixo ultrapasse a barra e desça controlado.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Pull-up',
        'body_part'      => 'Back',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Latíssimos, bíceps',
        'description'    => 'Na barra fixa, puxe o corpo até o queixo passar da barra e desça totalmente.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Chin-up',
        'body_part'      => 'Back',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Bíceps, costas',
        'description'    => 'Variação de pegada supinada focando mais bíceps.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Barbell Row',
        'body_part'      => 'Back',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Costas médias, bíceps',
        'description'    => 'Incline o tronco com barra nas mãos e puxe em direção ao abdômen.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Dumbbell Row',
        'body_part'      => 'Back',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Costas, bíceps',
        'description'    => 'Apoie uma mão e joelho no banco e puxe o halter ao lado do tronco.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'One-Arm Dumbbell Row',
        'body_part'      => 'Back',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Costas unilaterais, bíceps',
        'description'    => 'Mesma ideia do dumbbell row focando um lado de cada vez.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Deadlift',
        'body_part'      => 'Back',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Posterior de coxa, glutes, costas',
        'description'    => 'Levantamento terra clássico, tirando a barra do chão com extensão de quadris e joelhos.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Rack Pull',
        'body_part'      => 'Back',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Costas, glutes',
        'description'    => 'Variação do deadlift com a barra apoiada em rack, focando metade final do movimento.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Cable Row',
        'body_part'      => 'Back',
        'category'       => 'Cable',
        'primary_muscles'=> 'Costas médias, bíceps',
        'description'    => 'Remada na polia baixa puxando a alça em direção ao abdômen.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Face Pull (Rear Delt Focus)',
        'body_part'      => 'Back',
        'category'       => 'Cable',
        'primary_muscles'=> 'Deltoide posterior, trapézio',
        'description'    => 'Mesma base do face pull, enfatizando contração da parte de trás dos ombros.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Inverted Row',
        'body_part'      => 'Back',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Costas, bíceps',
        'description'    => 'Deitado sob uma barra baixa, puxe o peito em direção à barra mantendo corpo em linha reta.',
        'youtube_url'    => null,
    ],

    // 6. Biceps
    [
        'name'           => 'Bicep Curl Machine',
        'body_part'      => 'Biceps',
        'category'       => 'Machine',
        'primary_muscles'=> 'Bíceps braquial',
        'description'    => 'Sentado, flexione os cotovelos trazendo as alças em direção aos ombros.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Preacher Curl Machine',
        'body_part'      => 'Biceps',
        'category'       => 'Machine',
        'primary_muscles'=> 'Bíceps braquial',
        'description'    => 'Apoie os braços no banco Scott e flexione os cotovelos levantando a alça.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Cable Bicep Curl',
        'body_part'      => 'Biceps',
        'category'       => 'Cable',
        'primary_muscles'=> 'Bíceps, braquial',
        'description'    => 'Com barra na polia baixa, flexione os cotovelos sem mover os ombros.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Barbell Curl',
        'body_part'      => 'Biceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Bíceps',
        'description'    => 'Em pé com barra, flexione os cotovelos trazendo a barra ao peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Dumbbell Curl',
        'body_part'      => 'Biceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Bíceps',
        'description'    => 'Com halteres, flexione os cotovelos alternadamente ou simultâneo.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Hammer Curl',
        'body_part'      => 'Biceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Braquial, braquiorradial, bíceps',
        'description'    => 'Pegada neutra como se segurasse um martelo, flexionando os cotovelos.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Incline Dumbbell Curl',
        'body_part'      => 'Biceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Bíceps alongado',
        'description'    => 'Em banco inclinado, deixe os braços para trás e faça o curl.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Concentration Curl',
        'body_part'      => 'Biceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Bíceps',
        'description'    => 'Sentado, cotovelo apoiado na coxa, flexione apenas o antebraço.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Zottman Curl',
        'body_part'      => 'Biceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Bíceps, antebraço',
        'description'    => 'Curl em supinação subindo, rotação para pronação no topo e descida controlada.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Cable Curl',
        'body_part'      => 'Biceps',
        'category'       => 'Cable',
        'primary_muscles'=> 'Bíceps',
        'description'    => 'Variação de curl na polia com tensão constante.',
        'youtube_url'    => null,
    ],

    // 7. Triceps
    [
        'name'           => 'Triceps Pushdown Machine',
        'body_part'      => 'Triceps',
        'category'       => 'Cable',
        'primary_muscles'=> 'Tríceps',
        'description'    => 'Na polia alta, estenda os cotovelos empurrando a barra ou corda para baixo.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Seated Triceps Extension Machine',
        'body_part'      => 'Triceps',
        'category'       => 'Machine',
        'primary_muscles'=> 'Tríceps',
        'description'    => 'Sentado na máquina, estenda os cotovelos atrás da cabeça ou à frente conforme modelo.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Overhead Cable Triceps Extension',
        'body_part'      => 'Triceps',
        'category'       => 'Cable',
        'primary_muscles'=> 'Tríceps cabeça longa',
        'description'    => 'De costas para a polia, leve a corda acima da cabeça e estenda os cotovelos.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Triceps Dips',
        'body_part'      => 'Triceps',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Tríceps, peito',
        'description'    => 'Em barras paralelas, desça até 90° de cotovelo e suba.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Close-Grip Bench Press',
        'body_part'      => 'Triceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Tríceps, peito',
        'description'    => 'Supino com pegada fechada focando mais tríceps.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Skull Crushers (Barbell)',
        'body_part'      => 'Triceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Tríceps',
        'description'    => 'Deitado, desça a barra em direção à testa flexionando cotovelos e estenda novamente.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Skull Crushers (Dumbbell)',
        'body_part'      => 'Triceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Tríceps',
        'description'    => 'Mesma ideia usando halteres.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Overhead Dumbbell Extension',
        'body_part'      => 'Triceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Tríceps cabeça longa',
        'description'    => 'Segure halter acima da cabeça com ambas as mãos, flexione e estenda os cotovelos.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Kickbacks (Dumbbell)',
        'body_part'      => 'Triceps',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Tríceps',
        'description'    => 'Com tronco inclinado, estenda o cotovelo levando o halter para trás.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Kickbacks (Cable)',
        'body_part'      => 'Triceps',
        'category'       => 'Cable',
        'primary_muscles'=> 'Tríceps',
        'description'    => 'Mesma ideia usando cabo para tensão constante.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Rope Pushdown (Cable)',
        'body_part'      => 'Triceps',
        'category'       => 'Cable',
        'primary_muscles'=> 'Tríceps',
        'description'    => 'Com corda na polia alta, empurre para baixo abrindo a corda no final do movimento.',
        'youtube_url'    => null,
    ],

    // 8. Core
    [
        'name'           => 'Ab Crunch Machine',
        'body_part'      => 'Core',
        'category'       => 'Machine',
        'primary_muscles'=> 'Reto abdominal',
        'description'    => 'Sentado, flexione a coluna para frente contra a resistência da máquina.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Cable Woodchopper',
        'body_part'      => 'Core',
        'category'       => 'Cable',
        'primary_muscles'=> 'Oblíquos, core rotacional',
        'description'    => 'Com cabo alto ou baixo, faça movimento de rotação trazendo a alça em diagonal.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Roman Chair',
        'body_part'      => 'Core',
        'category'       => 'Machine',
        'primary_muscles'=> 'Lombar, glutes, isquiotibiais',
        'description'    => 'Na cadeira romana, flexione o tronco para frente e estenda até alinhá-lo com as pernas.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Hyperextension Bench',
        'body_part'      => 'Core',
        'category'       => 'Machine',
        'primary_muscles'=> 'Lombar, glutes',
        'description'    => 'Similar ao roman chair, focando extensão lombar.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Captain’s Chair',
        'body_part'      => 'Core',
        'category'       => 'Machine',
        'primary_muscles'=> 'Abdominais inferiores, flexores de quadril',
        'description'    => 'Suspenso na cadeira, eleve os joelhos ou pernas para trabalhar o abdômen.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Plank',
        'body_part'      => 'Core',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Core global, ombros, glutes',
        'description'    => 'Apoio em antebraços e pontas dos pés, mantenha corpo em linha reta estática.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Side Plank',
        'body_part'      => 'Core',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Oblíquos, glutes',
        'description'    => 'Apoiado de lado em antebraço e pés, mantenha corpo alinhado.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Hanging Leg Raise',
        'body_part'      => 'Core',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Abdominais, flexores de quadril',
        'description'    => 'Suspenso na barra, eleve as pernas ou joelhos em direção ao peito.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Weighted Sit-up',
        'body_part'      => 'Core',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Reto abdominal',
        'description'    => 'Abdominal tradicional segurando carga no peito ou acima da cabeça.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Russian Twist',
        'body_part'      => 'Core',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Oblíquos',
        'description'    => 'Sentado, tronco levemente inclinado, gire o tronco de um lado para o outro com peso nas mãos.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Bicycle Crunch',
        'body_part'      => 'Core',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Reto abdominal, oblíquos',
        'description'    => 'Em decúbito dorsal, alterne cotovelo com joelho oposto simulando pedalar.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'V-Up',
        'body_part'      => 'Core',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Abdominais, flexores de quadril',
        'description'    => 'Deitado, eleve simultaneamente tronco e pernas tentando tocar as mãos nos pés.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Mountain Climbers',
        'body_part'      => 'Core',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Core, ombros, cardio',
        'description'    => 'Em posição de prancha, traga joelhos alternadamente em direção ao peito em ritmo acelerado.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Ab Rollout (Wheel)',
        'body_part'      => 'Core',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Reto abdominal, core profundo',
        'description'    => 'De joelhos, role a roda para frente alongando o corpo e retorne puxando com o core.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Ab Rollout (Barbell)',
        'body_part'      => 'Core',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Core, ombros',
        'description'    => 'Mesma ideia do rollout com roda, usando barra com anilhas.',
        'youtube_url'    => null,
    ],

    // 9. Calves
    [
        'name'           => 'Standing Calf Raise Machine',
        'body_part'      => 'Calves',
        'category'       => 'Machine',
        'primary_muscles'=> 'Panturrilha gastrocnêmio',
        'description'    => 'Em pé na máquina, faça flexão plantar elevando os calcanhares.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Seated Calf Raise Machine',
        'body_part'      => 'Calves',
        'category'       => 'Machine',
        'primary_muscles'=> 'Panturrilha sóleo',
        'description'    => 'Sentado, realize elevação de calcanhares com joelhos flexionados.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Leg Press Calf Raise',
        'body_part'      => 'Calves',
        'category'       => 'Machine',
        'primary_muscles'=> 'Panturrilhas',
        'description'    => 'Na leg press, use apenas a articulação do tornozelo para empurrar a plataforma.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Standing Dumbbell Calf Raise',
        'body_part'      => 'Calves',
        'category'       => 'Free weight',
        'primary_muscles'=> 'Panturrilhas',
        'description'    => 'Em pé segurando halteres, eleve e abaixe os calcanhares.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Single-Leg Calf Raise',
        'body_part'      => 'Calves',
        'category'       => 'Bodyweight',
        'primary_muscles'=> 'Panturrilha unilateral',
        'description'    => 'Em apoio unipodal, faça elevação de calcanhar de uma perna por vez.',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Donkey Calf Raise',
        'body_part'      => 'Calves',
        'category'       => 'Free weight / Bodyweight',
        'primary_muscles'=> 'Panturrilhas',
        'description'    => 'Flexione o tronco à frente e faça elevação de calcanhar com peso apoiado na região lombar (ou máquina específica).',
        'youtube_url'    => null,
    ],
    [
        'name'           => 'Jump Rope',
        'body_part'      => 'Calves',
        'category'       => 'Bodyweight / Conditioning',
        'primary_muscles'=> 'Panturrilhas, condicionamento cardiorrespiratório',
        'description'    => 'Pular corda com pequenos saltos, mantendo contato leve dos pés com o chão.',
        'youtube_url'    => null,
    ],
];

/*
 * 5) Se POST: salvar exercícios da sessão / criar novo exercício
 */
$save_success = false;
$error_msg    = '';
$unknown_exercises = $unknown_exercises ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf_token, $postedToken)) {
        $error_msg = 'Invalid request. Please refresh the page and try again.';
    } else {
        $modePost = (string)($_POST['mode'] ?? '');

        // Nome da lista / apelido da sessão
        $session_label = trim((string)($_POST['session_label'] ?? ''));

        // Arrays de exercícios vindos do formulário
        $names    = $_POST['exercise_name'] ?? [];
        $setsArr  = $_POST['sets']          ?? [];
        $repsArr  = $_POST['reps']          ?? [];
        $rpeArr   = $_POST['target_rpe']    ?? [];
        $restArr  = $_POST['rest_seconds']  ?? [];

        if (!is_array($names)) {
            $names   = [];
            $setsArr = $repsArr = $rpeArr = $restArr = [];
        }

        // Descobrir quais nomes não existem na "library" (presets)
        $unknown_exercises = [];
        foreach ($names as $rawName) {
            $n = trim((string)$rawName);
            if ($n === '') {
                continue;
            }
            if (!exerciseNameExistsInPresets($n, $exercisePresets)) {
                if (!in_array($n, $unknown_exercises, true)) {
                    $unknown_exercises[] = $n;
                }
            }
        }

        // Salvar tudo como rascunho na sessão
        $_SESSION[$draftKey] = [
            'session_label' => $session_label,
            'names'         => $names,
            'sets'          => $setsArr,
            'reps'          => $repsArr,
            'rpe'           => $rpeArr,
            'rest'          => $restArr,
        ];

        // 5.a) Botão: "Create new exercise"
        if ($modePost === 'create_new_exercise') {
            $suggested = $unknown_exercises[0] ?? '';

            $query = [
                'session_id' => $session_id,
                'return'     => 'trainer_workout_plan_edit.php',
            ];
            if ($suggested !== '') {
                $query['exercise_name'] = $suggested;
            }

            header('Location: trainer_exercise_new.php?' . http_build_query($query));
            exit;
        }

        // 5.b) Botão: "Save session"
        if ($modePost === 'save_exercises') {
            if (!empty($unknown_exercises)) {
                $error_msg = 'There are exercises that are not in your library. Please create them first or adjust the names.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Atualiza o apelido / nome da sessão (opcional)
                    $updSess = $pdo->prepare("
                        UPDATE workout_sessions
                        SET session_label = :label
                        WHERE id = :sid
                    ");
                    $updSess->execute([
                        ':label' => $session_label !== '' ? $session_label : null,
                        ':sid'   => $session_id,
                    ]);

                    // Apaga todos os exercícios da sessão e recria
                    $del = $pdo->prepare("DELETE FROM workout_exercises WHERE session_id = ?");
                    $del->execute([$session_id]);

                    $ins = $pdo->prepare("
                        INSERT INTO workout_exercises
                            (session_id, exercise_name, sets, reps, target_rpe, rest_seconds, order_index)
                        VALUES
                            (:sid, :name, :sets, :reps, :rpe, :rest, :ord)
                    ");

                    $order = 1;

                    $total = max(
                        count($names),
                        count($setsArr),
                        count($repsArr),
                        count($rpeArr),
                        count($restArr)
                    );

                    for ($i = 0; $i < $total; $i++) {
                        $name = trim((string)($names[$i] ?? ''));
                        if ($name === '') {
                            continue;
                        }

                        $sets = (int)($setsArr[$i] ?? 0);
                        $reps = trim((string)($repsArr[$i] ?? ''));
                        $rpe  = (int)($rpeArr[$i] ?? 0);
                        $rest = (int)($restArr[$i] ?? 0);

                        $ins->execute([
                            ':sid'  => $session_id,
                            ':name' => $name,
                            ':sets' => $sets > 0 ? $sets : null,
                            ':reps' => $reps !== '' ? $reps : null,
                            ':rpe'  => $rpe > 0 ? $rpe : null,
                            ':rest' => $rest > 0 ? $rest : null,
                            ':ord'  => $order++,
                        ]);
                    }

                    $pdo->commit();
                    $save_success = true;

                    // Se salvou com sucesso, podemos limpar o rascunho
                    unset($_SESSION[$draftKey]);
                    $unknown_exercises = [];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_msg = 'Error while saving exercises. Please try again.';
                }
            }
        }
    }
}

/*
 * 6) Carrega exercícios atuais da sessão
 *    Se houver rascunho na sessão, usa o rascunho no lugar do banco.
 */
$sql = "
    SELECT
        id,
        exercise_name,
        sets,
        reps,
        target_rpe,
        rest_seconds,
        order_index
    FROM workout_exercises
    WHERE session_id = ?
    ORDER BY order_index ASC, id ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$session_id]);
$exercisesFromDb = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Monta a partir do rascunho (se existir)
$exercises = [];
$draftData = $_SESSION[$draftKey] ?? null;

if (is_array($draftData) && !empty($draftData['names']) && is_array($draftData['names'])) {
    $names   = $draftData['names'] ?? [];
    $setsArr = $draftData['sets']  ?? [];
    $repsArr = $draftData['reps']  ?? [];
    $rpeArr  = $draftData['rpe']   ?? [];
    $restArr = $draftData['rest']  ?? [];

    $total = max(
        count($names),
        count($setsArr),
        count($repsArr),
        count($rpeArr),
        count($restArr)
    );

    for ($i = 0; $i < $total; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $sets = $setsArr[$i] ?? null;
        $reps = $repsArr[$i] ?? null;
        $rpe  = $rpeArr[$i]  ?? null;
        $rest = $restArr[$i] ?? null;

        if ($name === '' && $sets === null && $reps === null && $rpe === null && $rest === null) {
            continue;
        }

        $exercises[] = [
            'id'            => null,
            'exercise_name' => $name,
            'sets'          => $sets,
            'reps'          => $reps,
            'target_rpe'    => $rpe,
            'rest_seconds'  => $rest,
            'order_index'   => $i + 1,
        ];
    }
} else {
    $exercises = $exercisesFromDb;
}

// Valor do apelido da sessão (nome da lista)
$session_label_value = '';
if (is_array($draftData) && array_key_exists('session_label', $draftData)) {
    $session_label_value = (string)$draftData['session_label'];
} else {
    $session_label_value = (string)($session['session_label'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>
        Edit workout session - <?php echo htmlspecialchars((string)$session['session_title'], ENT_QUOTES, 'UTF-8'); ?>
    </title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

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
    <link rel="stylesheet" href="/assets/css/trainer_workout_plan_edit.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">

    <style>
        .wk-container-edit {
            max-width: 960px;
            margin: 32px auto 48px;
            padding: 24px 20px 32px;
        }
        .wk-edit-header {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 20px;
        }
        .wk-edit-subtitle {
            font-size: 0.9rem;
            color: var(--rb-text-muted);
        }
        .wk-edit-meta {
            font-size: 0.85rem;
            color: var(--rb-text-muted);
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .wk-alert-success {
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.4);
            color: #bbf7d0;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }
        .wk-alert-error {
            background: rgba(248,113,113,0.12);
            border: 1px solid rgba(248,113,113,0.4);
            color: #fecaca;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }
        .wk-ex-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .wk-ex-table thead th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            text-align: left;
            padding: 6px 4px;
            color: var(--rb-text-label);
            border-bottom: 1px solid rgba(148,163,184,0.35);
        }
        .wk-ex-table tbody td {
            padding: 6px 4px;
            border-bottom: 1px solid rgba(30,41,59,0.7);
        }
        .wk-ex-row {
            transition: background 0.15s ease;
        }
        .wk-ex-row:hover {
            background: rgba(15,23,42,0.7);
        }
        .wk-ex-name-input {
            width: 100%;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--rb-border-soft);
            background: var(--rb-bg-card-soft);
            color: var(--rb-text-main);
            font-size: 0.88rem;
        }
        .wk-ex-small-input {
            width: 80px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--rb-border-soft);
            background: var(--rb-bg-card-soft);
            color: var(--rb-text-main);
            font-size: 0.85rem;
        }
        .wk-ex-name-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .wk-ex-info-btn {
            border: none;
            background: transparent;
            color: var(--rb-text-muted);
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0 4px;
        }
        .wk-ex-info-btn:hover {
            color: #fbbf24;
        }
        .wk-ex-add-row {
            margin-top: 10px;
            display: flex;
            justify-content: flex-start;
        }
        .wk-ex-add-btn {
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 0.85rem;
        }
        .wk-form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            gap: 10px;
        }
        .tw-ex-actions-col {
            width: 32px;
            text-align: center;
        }
        .wk-ex-delete-btn {
            border: none;
            background: transparent;
            color: var(--rb-text-muted);
            cursor: pointer;
            font-size: 1rem;
        }
        .wk-ex-delete-btn:hover {
            color: #f97373;
        }
        .wk-ex-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .wk-ex-modal {
            width: 100%;
            max-width: 520px;
            background: #020617;
            border-radius: 16px;
            border: 1px solid rgba(148,163,184,0.4);
            padding: 16px 18px 18px;
            box-shadow: 0 20px 40px rgba(15,23,42,0.9);
        }
        .wk-ex-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .wk-ex-modal-title {
            font-size: 1rem;
            font-weight: 600;
        }
        .wk-ex-modal-close {
            border: none;
            background: transparent;
            color: var(--rb-text-muted);
            cursor: pointer;
            font-size: 1rem;
        }
        .wk-ex-modal-body {
            font-size: 0.9rem;
            color: var(--rb-text-main);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .wk-ex-tagline {
            font-size: 0.8rem;
            color: var(--rb-text-muted);
        }
        .wk-ex-modal-video {
            margin-top: 8px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid rgba(30,64,175,0.4);
        }
        .wk-ex-modal-video iframe {
            width: 100%;
            aspect-ratio: 16 / 9;
            border: none;
        }
        .wk-edit-sessionname {
            margin: 10px 0 18px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .wk-edit-sessionname label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--rb-text-label);
        }
        .wk-edit-sessionname-input {
            max-width: 320px;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--rb-border-soft);
            background: var(--rb-bg-card-soft);
            color: var(--rb-text-main);
            font-size: 0.9rem;
        }
        .wk-edit-sessionname-hint {
            font-size: 0.78rem;
            color: var(--rb-text-muted);
        }
        .wk-ex-secondary-actions {
            margin-top: 8px;
            display: flex;
            justify-content: flex-start;
        }
        .wk-ex-create-btn {
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 0.85rem;
            margin-left: 8px;
        }
        @media (max-width: 768px) {
            .wk-container-edit {
                margin: 16px auto 32px;
                padding: 16px 12px 24px;
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
                <li><a href="trainer_workouts.php" class="rbf1-link rbf1-link-active">Workouts</a></li>
                <li><a href="trainer_checkins.php" class="rbf1-link">Check-ins</a></li>
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

<div class="wk-container wk-container-edit">
    <div class="wk-edit-header">
        <h1 class="wk-title">Edit workout session</h1>
        <p class="wk-edit-subtitle">
            Build or adjust the exercises for this session. Type the exercise name and pick from suggestions.
        </p>
        <div class="wk-edit-meta">
            <span>Plan: <strong><?php echo htmlspecialchars((string)$session['plan_name'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
            <span>Client: <strong><?php echo htmlspecialchars((string)$session['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
            <span>Session: <strong><?php echo htmlspecialchars((string)$session['session_title'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
        </div>
    </div>

    <?php if ($save_success): ?>
        <div class="wk-alert-success">
            Workout exercises saved successfully.
        </div>
    <?php elseif ($error_msg !== ''): ?>
        <div class="wk-alert-error">
            <?php echo htmlspecialchars((string)$error_msg, ENT_QUOTES, 'UTF-8'); ?>
            <?php if (!empty($unknown_exercises)): ?>
                <div style="margin-top:6px;font-size:0.82rem;">
                    Unknown exercises:
                    <strong><?php echo htmlspecialchars(implode(', ', $unknown_exercises), ENT_QUOTES, 'UTF-8'); ?></strong>.<br>
                    Your current list has been saved as a draft.
                    Click <em>Create new exercise</em> to add them to your library.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="session_id" value="<?php echo (int)$session_id; ?>">

        <div class="wk-edit-sessionname">
            <label for="session_label">Session nickname (optional)</label>
            <input
                type="text"
                id="session_label"
                name="session_label"
                class="wk-edit-sessionname-input"
                placeholder="e.g. Lower Body A, Upper Push..."
                value="<?php echo htmlspecialchars($session_label_value, ENT_QUOTES, 'UTF-8'); ?>"
            >
            <p class="wk-edit-sessionname-hint">
                This is just a friendly name for this exercise list.
            </p>
        </div>

        <table class="wk-ex-table">
            <thead>
            <tr>
                <th style="width: 45%;">Exercise</th>
                <th style="width: 12%;">Sets</th>
                <th style="width: 18%;">Reps</th>
                <th style="width: 12%;">RPE</th>
                <th style="width: 13%;">Rest (s)</th>
                <th class="tw-ex-actions-col"></th>
            </tr>
            </thead>
            <tbody id="exercise-rows">
            <?php if (empty($exercises)): ?>
                <tr class="wk-ex-row">
                    <td>
                        <div class="wk-ex-name-cell">
                            <input type="text"
                                   name="exercise_name[]"
                                   class="wk-ex-name-input js-ex-name"
                                   list="exercise-presets-list"
                                   placeholder="Start typing exercise name..."
                                   autocomplete="off">
                            <button type="button" class="wk-ex-info-btn js-ex-info" title="Exercise details">
                                ⓘ
                            </button>
                        </div>
                    </td>
                    <td><input type="number" name="sets[]" class="wk-ex-small-input" min="0" step="1" placeholder="3"></td>
                    <td><input type="text"   name="reps[]" class="wk-ex-small-input" placeholder="8-12"></td>
                    <td><input type="number" name="target_rpe[]" class="wk-ex-small-input" min="0" max="10" step="1" placeholder="7"></td>
                    <td><input type="number" name="rest_seconds[]" class="wk-ex-small-input" min="0" step="5" placeholder="60"></td>
                    <td class="tw-ex-actions-col">
                        <button type="button" class="wk-ex-delete-btn js-ex-delete" title="Remove exercise">✕</button>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($exercises as $ex): ?>
                    <tr class="wk-ex-row">
                        <td>
                            <div class="wk-ex-name-cell">
                                <input type="text"
                                       name="exercise_name[]"
                                       class="wk-ex-name-input js-ex-name"
                                       list="exercise-presets-list"
                                       value="<?php echo htmlspecialchars((string)($ex['exercise_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                       autocomplete="off">
                                <button type="button" class="wk-ex-info-btn js-ex-info" title="Exercise details">
                                    ⓘ
                                </button>
                            </div>
                        </td>
                        <td>
                            <input type="number"
                                   name="sets[]"
                                   class="wk-ex-small-input"
                                   min="0"
                                   step="1"
                                   value="<?php echo htmlspecialchars((string)($ex['sets'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td>
                            <input type="text"
                                   name="reps[]"
                                   class="wk-ex-small-input"
                                   value="<?php echo htmlspecialchars((string)($ex['reps'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td>
                            <input type="number"
                                   name="target_rpe[]"
                                   class="wk-ex-small-input"
                                   min="0"
                                   max="10"
                                   step="1"
                                   value="<?php echo htmlspecialchars((string)($ex['target_rpe'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td>
                            <input type="number"
                                   name="rest_seconds[]"
                                   class="wk-ex-small-input"
                                   min="0"
                                   step="5"
                                   value="<?php echo htmlspecialchars((string)($ex['rest_seconds'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="tw-ex-actions-col">
                            <button type="button" class="wk-ex-delete-btn js-ex-delete" title="Remove exercise">✕</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="wk-ex-add-row">
            <button type="button" class="wk-btn-secondary wk-ex-add-btn" id="btn-add-exercise">
                + Add exercise
            </button>
        </div>

        <div class="wk-ex-secondary-actions">
            <button
                type="submit"
                name="mode"
                value="create_new_exercise"
                class="wk-btn-secondary wk-ex-create-btn"
            >
                + Create new exercise
            </button>
        </div>

        <div class="wk-form-actions">
            <a href="trainer_client_workouts.php?client_id=<?php echo (int)$session['client_id']; ?>"
               class="wk-btn-secondary">
                Back to client workouts
            </a>
            <button type="submit" name="mode" value="save_exercises" class="wk-btn-primary">
                Save session
            </button>
        </div>
    </form>
</div>

<!-- LISTA PARA AUTOCOMPLETE DOS EXERCÍCIOS -->
<datalist id="exercise-presets-list">
    <?php foreach ($exercisePresets as $preset): ?>
        <option value="<?php echo htmlspecialchars((string)$preset['name'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php
            $meta = [];
            if (!empty($preset['body_part']))  $meta[] = (string)$preset['body_part'];
            if (!empty($preset['category']))   $meta[] = (string)$preset['category'];
            echo htmlspecialchars(implode(' • ', $meta), ENT_QUOTES, 'UTF-8');
            ?>
        </option>
    <?php endforeach; ?>
</datalist>

<div class="wk-ex-modal-backdrop" id="ex-modal-backdrop">
    <div class="wk-ex-modal">
        <div class="wk-ex-modal-header">
            <div class="wk-ex-modal-title" id="ex-modal-title">Exercise details</div>
            <button type="button" class="wk-ex-modal-close" id="ex-modal-close">✕</button>
        </div>
        <div class="wk-ex-modal-body">
            <div class="wk-ex-tagline" id="ex-modal-tagline"></div>
            <div id="ex-modal-description"></div>
            <div class="wk-ex-modal-video" id="ex-modal-video" style="display: none;">
                <iframe id="ex-modal-iframe" src="" allowfullscreen></iframe>
            </div>
        </div>
    </div>
</div>

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

  const EXERCISE_PRESETS = <?php echo json_encode($exercisePresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  function findExercisePresetByName(name) {
    if (!name) return null;
    const q = name.trim().toLowerCase();
    if (!q) return null;
    return EXERCISE_PRESETS.find(e => (e.name || '').toLowerCase() === q) || null;
  }

  (function () {
    const rowsContainer = document.getElementById('exercise-rows');
    const addBtn = document.getElementById('btn-add-exercise');

    function wireRow(row) {
      const nameInput = row.querySelector('.js-ex-name');
      const infoBtn   = row.querySelector('.js-ex-info');
      const delBtn    = row.querySelector('.js-ex-delete');

      if (nameInput) {
        nameInput.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            const val = nameInput.value.trim().toLowerCase();
            if (!val) return;
            const matches = EXERCISE_PRESETS.filter(ex =>
              (ex.name || '').toLowerCase().startsWith(val)
            );
            if (matches.length === 1) {
              e.preventDefault();
              nameInput.value = matches[0].name;
            }
          }
        });
      }

      if (infoBtn && nameInput) {
        infoBtn.addEventListener('click', function () {
          openExerciseModal(nameInput.value);
        });
      }

      if (delBtn) {
        delBtn.addEventListener('click', function () {
          if (rowsContainer && row.parentNode === rowsContainer) {
            rowsContainer.removeChild(row);
          }
        });
      }
    }

    Array.prototype.forEach.call(rowsContainer.querySelectorAll('.wk-ex-row'), wireRow);

    if (addBtn) {
      addBtn.addEventListener('click', function () {
        const tr = document.createElement('tr');
        tr.className = 'wk-ex-row';
        tr.innerHTML = `
            <td>
              <div class="wk-ex-name-cell">
                <input type="text"
                       name="exercise_name[]"
                       class="wk-ex-name-input js-ex-name"
                       list="exercise-presets-list"
                       placeholder="Start typing exercise name..."
                       autocomplete="off">
                <button type="button" class="wk-ex-info-btn js-ex-info" title="Exercise details">
                  ⓘ
                </button>
              </div>
            </td>
            <td><input type="number" name="sets[]" class="wk-ex-small-input" min="0" step="1" placeholder="3"></td>
            <td><input type="text"   name="reps[]" class="wk-ex-small-input" placeholder="8-12"></td>
            <td><input type="number" name="target_rpe[]" class="wk-ex-small-input" min="0" max="10" step="1" placeholder="7"></td>
            <td><input type="number" name="rest_seconds[]" class="wk-ex-small-input" min="0" step="5" placeholder="60"></td>
            <td class="tw-ex-actions-col">
              <button type="button" class="wk-ex-delete-btn js-ex-delete" title="Remove exercise">✕</button>
            </td>
        `;
        rowsContainer.appendChild(tr);
        wireRow(tr);
      });
    }
  })();

  (function () {
    const backdrop = document.getElementById('ex-modal-backdrop');
    const closeBtn = document.getElementById('ex-modal-close');
    const titleEl  = document.getElementById('ex-modal-title');
    const tagline  = document.getElementById('ex-modal-tagline');
    const descEl   = document.getElementById('ex-modal-description');
    const videoBox = document.getElementById('ex-modal-video');
    const iframe   = document.getElementById('ex-modal-iframe');

    window.openExerciseModal = function (exerciseName) {
      const name = (exerciseName || '').trim();
      if (!name) {
        titleEl.textContent  = 'Exercise details';
        tagline.textContent  = 'No exercise selected.';
        descEl.textContent   = 'Start by typing an exercise name in the table.';
        videoBox.style.display = 'none';
        iframe.src = '';
      } else {
        const preset = findExercisePresetByName(name);
        titleEl.textContent = name;

        if (!preset) {
          tagline.textContent = 'No extra information saved yet for this exercise.';
          descEl.textContent  = 'You can later connect this exercise to a library with muscles, description and demo videos.';
          videoBox.style.display = 'none';
          iframe.src = '';
        } else {
          const bodyPart   = preset.body_part || 'Unknown body part';
          const category   = preset.category ? ' • ' + preset.category : '';
          const muscles    = preset.primary_muscles ? ' • Muscles: ' + preset.primary_muscles : '';
          tagline.textContent = bodyPart + category + muscles;

          descEl.textContent  = preset.description || 'Basic preset entry with default description.';

          if (preset.youtube_url) {
            videoBox.style.display = 'block';
            iframe.src = preset.youtube_url;
          } else {
            videoBox.style.display = 'none';
            iframe.src = '';
          }
        }
      }

      backdrop.style.display = 'flex';
    };

    function closeModal() {
      backdrop.style.display = 'none';
      if (iframe) iframe.src = '';
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) {
      backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) {
          closeModal();
        }
      });
    }
  })();
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
          <li><a href="dashboard_personal.php">Dashboard</a></li>
          <li><a href="personal_profile.php">Profile</a></li>
          <li><a href="trainer_workouts.php">Workouts</a></li>
          <li><a href="trainer_checkins.php">Check-ins</a></li>
          <li><a href="messages.php">Messages</a></li>
          <li><a href="trainer_clients.php">Clients</a></li>
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

<script src="../script.js"></script>

</body>
</html>
