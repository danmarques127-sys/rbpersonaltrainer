<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_login();
require_role(['user', 'client', 'pro']);
$pdo = getPDO();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = (string)($_SESSION['role'] ?? '');

// ===============================
// Redirect pós-upload
// ===============================
$redirectUrl = '/dashboards/client_profile_edit.php';

if ($role === 'pro') {
    $specialty = $_SESSION['specialty'] ?? null;

    if ($specialty === null) {
        $stmt = $pdo->prepare("SELECT specialty FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $specialty = (string)($stmt->fetchColumn() ?: '');
    }

    $redirectUrl = match ($specialty) {
        'personal_trainer' => '/dashboards/personal_profile_edit.php',
        'nutritionist'     => '/dashboards/nutritionist_profile_edit.php',
        default            => '/dashboards/dashboard_pro.php',
    };
}

// Se não for POST, volta
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect($redirectUrl);
    exit;
}

// ===============================
// CSRF (recomendado)
// - Se você já gera token no form, valide aqui.
// - Se ainda não usa, pode comentar o bloco.
// ===============================
if (isset($_POST['csrf_token'])) {
    $sent = (string)$_POST['csrf_token'];
    $sess = (string)($_SESSION['csrf_token'] ?? '');

    if ($sess === '' || !hash_equals($sess, $sent)) {
        $_SESSION['avatar_upload_error'] = 'Invalid session token. Please try again.';
        redirect($redirectUrl);
        exit;
    }
}

// ===============================
// Validação do arquivo
// ===============================
if (!isset($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
    $_SESSION['avatar_upload_error'] = 'No file selected.';
    redirect($redirectUrl);
    exit;
}

$file = $_FILES['avatar'];

if (!isset($file['error'], $file['tmp_name'], $file['size']) || $file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['avatar_upload_error'] = 'Upload failed. Error code: ' . ($file['error'] ?? 'unknown');
    redirect($redirectUrl);
    exit;
}

// Limite (2MB)
$maxBytes = 2 * 1024 * 1024;
if ((int)$file['size'] > $maxBytes) {
    $_SESSION['avatar_upload_error'] = 'File too large. Maximum 2MB.';
    redirect($redirectUrl);
    exit;
}

// Garante que veio via upload HTTP
if (!is_uploaded_file($file['tmp_name'])) {
    $_SESSION['avatar_upload_error'] = 'Invalid upload.';
    redirect($redirectUrl);
    exit;
}

// MIME real (finfo)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']) ?: '';

$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// (GIF removido por segurança, como você já queria)
if (!isset($allowedMime[$mime])) {
    $_SESSION['avatar_upload_error'] = 'Invalid file type. Allowed: JPG, PNG, WEBP.';
    redirect($redirectUrl);
    exit;
}

$ext = $allowedMime[$mime];

// Checa se é realmente uma imagem (dimensões)
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    $_SESSION['avatar_upload_error'] = 'File is not a valid image.';
    redirect($redirectUrl);
    exit;
}

// (Opcional) limitar dimensões absurdas (anti-bomba de memória)
$maxW = 4000;
$maxH = 4000;
$w = (int)($imageInfo[0] ?? 0);
$h = (int)($imageInfo[1] ?? 0);
if ($w <= 0 || $h <= 0 || $w > $maxW || $h > $maxH) {
    $_SESSION['avatar_upload_error'] = 'Image dimensions are not allowed.';
    redirect($redirectUrl);
    exit;
}

// ===============================
// Storage
// ===============================
// Pasta oficial do projeto:
// public_html/assets/uploads/avatars
$uploadDir = realpath(__DIR__ . '/../assets/uploads') ?: (__DIR__ . '/../assets/uploads');
$uploadDir = rtrim($uploadDir, DIRECTORY_SEPARATOR) . '/avatars';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        $_SESSION['avatar_upload_error'] = 'Failed to create upload directory.';
        redirect($redirectUrl);
        exit;
    }
}
if (!is_writable($uploadDir)) {
    $_SESSION['avatar_upload_error'] = 'Upload directory not writable.';
    redirect($redirectUrl);
    exit;
}

// Nome seguro (não depende do nome original)
$newFileName = sprintf(
    'user-%d-%d-%s.%s',
    $userId,
    time(),
    bin2hex(random_bytes(8)),
    $ext
);

$destPath     = $uploadDir . '/' . $newFileName;

// Caminho público que vai pro banco (funciona em qualquer página)
$relativePath = '/assets/uploads/avatars/' . $newFileName;

// ===============================
// Re-encode (remove payload/metadata)
// ===============================
$ok = false;

try {
    switch ($mime) {
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($file['tmp_name']);
            if ($img) {
                // qualidade 85 (bom equilíbrio)
                $ok = imagejpeg($img, $destPath, 85);
                imagedestroy($img);
            }
            break;

        case 'image/png':
            $img = @imagecreatefrompng($file['tmp_name']);
            if ($img) {
                // preserva transparência
                imagealphablending($img, false);
                imagesavealpha($img, true);
                // compressão 6 (0-9)
                $ok = imagepng($img, $destPath, 6);
                imagedestroy($img);
            }
            break;

        case 'image/webp':
            if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
                $img = @imagecreatefromwebp($file['tmp_name']);
                if ($img) {
                    // qualidade 85
                    $ok = imagewebp($img, $destPath, 85);
                    imagedestroy($img);
                }
            } else {
                // fallback: se servidor não suporta webp no GD, usa move_uploaded_file
                $ok = move_uploaded_file($file['tmp_name'], $destPath);
            }
            break;
    }
} catch (Throwable $e) {
    $ok = false;
}

if (!$ok) {
    // fallback: se reencode falhar, tenta mover (melhor do que quebrar)
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $_SESSION['avatar_upload_error'] = 'Failed to save uploaded file.';
        redirect($redirectUrl);
        exit;
    }
}

// Permissão do arquivo (hardening)
@chmod($destPath, 0644);

// ===============================
// Remove avatar antigo (com regra segura)
// ===============================
try {
    $stmt = $pdo->prepare("SELECT avatar_url FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $userId]);
    $oldAvatar = (string)($stmt->fetchColumn() ?: '');

    // aceita ambos formatos antigos e novo (com /assets/...)
    $oldIsInAvatars = (
        $oldAvatar !== '' &&
        (
            str_starts_with($oldAvatar, '/assets/uploads/avatars/') ||
            str_starts_with($oldAvatar, 'assets/uploads/avatars/') ||
            str_starts_with($oldAvatar, 'uploads/avatars/')
        )
    );

    if ($oldIsInAvatars) {
        // normaliza para caminho físico dentro do public_html
        $oldAvatarNorm = ltrim($oldAvatar, '/');

        // Se vier "uploads/avatars/..." antigo, mapeia para assets/uploads/avatars/...
        if (str_starts_with($oldAvatarNorm, 'uploads/avatars/')) {
            $oldAvatarNorm = 'assets/' . $oldAvatarNorm; // assets/uploads/avatars/...
        }

        $oldFullPath = realpath(__DIR__ . '/../' . $oldAvatarNorm);

        // Evita deletar fora da pasta
        $realDir = realpath($uploadDir);

        if ($oldFullPath && $realDir && str_starts_with($oldFullPath, $realDir) && is_file($oldFullPath)) {
            @unlink($oldFullPath);
        }
    }
} catch (Throwable $e) {
    // não crítico
}

// ===============================
// Salva no banco
// ===============================
$stmt = $pdo->prepare("UPDATE users SET avatar_url = :avatar_url WHERE id = :id");
$stmt->execute([
    ':avatar_url' => $relativePath,
    ':id'         => $userId,
]);

$_SESSION['avatar_upload_success'] = 'Profile photo updated successfully.';
redirect($redirectUrl);
exit;
