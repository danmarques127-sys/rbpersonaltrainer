<?php
declare(strict_types=1);

/**
 * CRON — Cleanup expired progress videos
 * 
 * Executado via cronjob
 * NÃO acessado via navegador
 */

require_once __DIR__ . '/../core/bootstrap.php';

$pdo = getPDO();

// 1. Buscar vídeos expirados
$stmt = $pdo->prepare("
    SELECT id, file_path
    FROM progress_videos
    WHERE expires_at < NOW()
");
$stmt->execute();

$expiredVideos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Apagar arquivos físicos
foreach ($expiredVideos as $video) {
    if (empty($video['file_path'])) {
        continue;
    }

    $fullPath = realpath(__DIR__ . '/../' . ltrim($video['file_path'], '/'));

    if ($fullPath && file_exists($fullPath)) {
        @unlink($fullPath);
    }
}

// 3. Apagar registros do banco
$stmt = $pdo->prepare("
    DELETE FROM progress_videos
    WHERE expires_at < NOW()
");
$stmt->execute();
