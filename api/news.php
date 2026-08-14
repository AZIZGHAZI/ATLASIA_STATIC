<?php
/**
 * ATLASIA — API Veille Presse.
 *
 * Sert le corpus d'articles de la presse marocaine (data/psychosocial_corpus.json),
 * avec filtrage par mot-clé et par source, tri par date décroissante.
 *
 * Paramètres (GET ou POST) :
 *   q      : mot-clé (filtre insensible à la casse sur titre + chapô)
 *   source : nom exact de la source à filtrer
 *   limit  : nombre max d'articles (défaut 20, max 50)
 *
 * Réponse JSON :
 *   { ok:true, total:N, articles:[...], sources:[...], generated:"..." }
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Polyfills mbstring
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string) $s); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($h, $n, $o = 0, $enc = null) { return strpos((string) $h, (string) $n, $o); }
}

$corpusFile = __DIR__ . '/../data/psychosocial_corpus.json';
$corpus = json_decode(@file_get_contents($corpusFile), true) ?: [];
$all = $corpus['articles'] ?? [];
$generated = $corpus['generated'] ?? null;

/* --------- Paramètres d'entrée (GET, POST form ou POST JSON) --------- */
$in = $_GET + $_POST;
$rawBody = file_get_contents('php://input');
if ($rawBody !== '' && ($json = json_decode($rawBody, true)) && is_array($json)) {
    $in = $json + $in;
}
$q      = trim((string) ($in['q'] ?? ''));
$source = trim((string) ($in['source'] ?? ''));
$limit  = (int) ($in['limit'] ?? 20);
if ($limit <= 0)  $limit = 20;
if ($limit > 50)  $limit = 50;

/* --------- Liste des sources disponibles (avant filtrage) --------- */
$sources = [];
foreach ($all as $a) {
    $s = $a['source'] ?? '';
    if ($s !== '' && !in_array($s, $sources, true)) $sources[] = $s;
}
sort($sources, SORT_NATURAL | SORT_FLAG_CASE);

/* --------- Filtrage --------- */
$qLower = mb_strtolower($q);
$filtered = [];
foreach ($all as $a) {
    if ($source !== '' && ($a['source'] ?? '') !== $source) continue;
    if ($qLower !== '') {
        $hay = mb_strtolower(($a['title'] ?? '') . ' ' . ($a['summary'] ?? ''));
        if (mb_strpos($hay, $qLower) === false) continue;
    }
    $filtered[] = $a;
}

/* --------- Tri par date décroissante (les articles sans date en dernier) --------- */
usort($filtered, function ($x, $y) {
    $dx = $x['date'] ?? '';
    $dy = $y['date'] ?? '';
    if ($dx === $dy) return 0;
    if ($dx === '') return 1;
    if ($dy === '') return -1;
    return strcmp($dy, $dx);
});

$total = count($filtered);
$articles = array_slice($filtered, 0, $limit);

echo json_encode([
    'ok'        => true,
    'total'     => $total,
    'articles'  => array_values($articles),
    'sources'   => $sources,
    'generated' => $generated,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
