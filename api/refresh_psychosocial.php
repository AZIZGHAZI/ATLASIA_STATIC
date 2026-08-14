<?php
/**
 * ATLASIA — Rafraîchissement du corpus psychosocial.
 * Ré-exécute le scraper de presse (data/scrape_psychosocial.py) et renvoie le
 * résultat. Protégé par le mot de passe admin.
 *
 * NB : nécessite Python 3 + feedparser installés sur le serveur.
 *   pip install feedparser
 */
header('Content-Type: application/json; charset=utf-8');

define('ADMIN_PASSWORD', 'atlasia2024');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (($in['password'] ?? '') !== ADMIN_PASSWORD) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Mot de passe incorrect.']);
    exit;
}

$script = realpath(__DIR__ . '/../data/scrape_psychosocial.py');
if (!$script) {
    echo json_encode(['ok' => false, 'message' => 'Script de scraping introuvable.']);
    exit;
}

// Cherche un interpréteur Python disponible.
$python = null;
foreach (['python3', 'python'] as $bin) {
    $p = trim(@shell_exec('command -v ' . $bin . ' 2>/dev/null'));
    if ($p) { $python = $p; break; }
}
if (!$python) {
    echo json_encode(['ok' => false, 'message' => 'Python 3 introuvable sur le serveur.']);
    exit;
}

$cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' 2>&1';
$t0 = microtime(true);
$out = shell_exec($cmd);
$dt = round(microtime(true) - $t0, 1);

$data = json_decode(@file_get_contents(__DIR__ . '/../data/regions_data.json'), true) ?: [];
$meta = $data['psychosocial']['_meta'] ?? [];
$kpis = $data['psychosocial']['kpis'] ?? [];

$success = stripos((string)$out, 'psychosocial mis à jour') !== false
        || stripos((string)$out, 'mis a jour') !== false;

echo json_encode([
    'ok'      => $success,
    'message' => $success
        ? "Corpus rafraîchi en {$dt}s — " . ($meta['nb_articles'] ?? 0) . " articles."
        : "Le script s'est exécuté mais aucune confirmation n'a été détectée.",
    'meta'    => $meta,
    'kpis'    => $kpis,
    'log'     => trim((string)$out),
], JSON_UNESCAPED_UNICODE);
