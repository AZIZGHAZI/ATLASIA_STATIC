<?php
/**
 * API simple pour sauvegarder les données régionales HCP depuis admin.php.
 * Méthode : POST (JSON) — champ 'password' requis + 'data' (structure regions_data.json).
 * Sécurité : mot de passe partagé simple (démo). Ne pas exposer publiquement en production.
 */
header('Content-Type: application/json; charset=utf-8');

define('ADMIN_PASSWORD', 'atlasia2024');
$dataFile   = __DIR__ . '/../data/regions_data.json';
$backupDir  = __DIR__ . '/../data/backups';

function respond($ok, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Méthode non autorisée. Utilisez POST.');
}

// Lire le corps JSON (ou fallback form-data)
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
    if (isset($input['data']) && is_string($input['data'])) {
        $input['data'] = json_decode($input['data'], true);
    }
}

// Authentification
$password = $input['password'] ?? '';
if (!hash_equals(ADMIN_PASSWORD, (string)$password)) {
    http_response_code(401);
    respond(false, 'Mot de passe incorrect.');
}

$data = $input['data'] ?? null;
if (!is_array($data) || !isset($data['regions']) || !is_array($data['regions'])) {
    http_response_code(400);
    respond(false, 'Données invalides : la structure attendue { national, regions[] } est absente.');
}

// Sauvegarde de sécurité de l'ancien fichier + préservation des clés non transmises
if (file_exists($dataFile)) {
    if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }
    @copy($dataFile, $backupDir . '/regions_data_' . date('Ymd_His') . '.json');

    // Préserver les sections non modifiées par l'admin (ex. psychosocial,
    // population_recensements) pour éviter toute perte de données.
    $existing = json_decode(@file_get_contents($dataFile), true);
    if (is_array($existing)) {
        foreach ($existing as $key => $val) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $val;
            }
        }
    }
}

// Mettre à jour les métadonnées
if (!isset($data['_meta']) || !is_array($data['_meta'])) {
    $data['_meta'] = [];
}
$data['_meta']['source'] = $data['_meta']['source'] ?? 'Haut Commissariat au Plan (HCP) — Annuaire Statistique du Maroc 2024';
$data['_meta']['derniere_mise_a_jour'] = date('Y-m-d H:i:s');

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    http_response_code(500);
    respond(false, 'Erreur d\'encodage JSON : ' . json_last_error_msg());
}

// Écriture atomique
$tmp = $dataFile . '.tmp';
if (file_put_contents($tmp, $json) === false || !rename($tmp, $dataFile)) {
    http_response_code(500);
    respond(false, 'Impossible d\'écrire le fichier de données (vérifiez les permissions).');
}

respond(true, 'Données enregistrées avec succès.', [
    'annee_reference' => $data['_meta']['annee_reference'] ?? null,
    'nb_regions'      => count($data['regions']),
    'horodatage'      => $data['_meta']['derniere_mise_a_jour'],
]);
