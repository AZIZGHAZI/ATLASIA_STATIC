<?php
/**
 * ATLASIA — Proxy IA générative (compatible OpenAI /chat/completions).
 *
 * Deux modes d'entrée (POST JSON) :
 *  A) Analyse d'un élément (boutons ✨) :
 *     { key, title, context:{interpretation,tendance,recommandation,source}, question? }
 *  B) Chat / outil libre (AI Research Studio) :
 *     { prompt:"texte libre", tool?:"Résumé automatique" }
 *
 * Renvoie : { ok:true, mode:"ai"|"local", text:"<html>" }
 *
 * Clé : config.php['api_key'] > getenv OPENAI_API_KEY > getenv ABACUS_API_KEY.
 * Sans clé => moteur d'analyse LOCAL (hors-ligne, fondé sur les données réelles).
 *
 * Conforme à la Charte ATLASIA : grounding strict, sources citées, jamais de
 * « vérité absolue », aucun chiffre inventé.
 */
header('Content-Type: application/json; charset=utf-8');

// Polyfills mbstring (au cas où l'extension n'est pas activée sur le serveur)
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string) $s); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($h, $n, $o = 0, $enc = null) { return strpos((string) $h, (string) $n, $o); }
}

$cfgFile = __DIR__ . '/config.php';
$cfg = file_exists($cfgFile) ? (include $cfgFile) : [];

/* --------- Résolution de la clé et de l'endpoint --------- */
$apiKey  = trim($cfg['api_key'] ?? '');
$baseUrl = rtrim($cfg['base_url'] ?? 'https://api.openai.com/v1', '/');
if ($apiKey === '') {
    $envOpenAI = trim((string) getenv('OPENAI_API_KEY'));
    $envAbacus = trim((string) getenv('ABACUS_API_KEY'));
    if ($envOpenAI !== '') {
        $apiKey = $envOpenAI;               // garde le base_url configuré
    } elseif ($envAbacus !== '') {
        $apiKey = $envAbacus;               // clé Abacus => endpoint RouteLLM
        $baseUrl = 'https://routellm.abacus.ai/v1';
    }
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true) ?: [];
$key      = $in['key'] ?? '';
$title    = trim($in['title'] ?? 'Analyse ATLASIA');
$ctx      = $in['context'] ?? [];
$question = trim($in['question'] ?? '');
$prompt   = trim($in['prompt'] ?? '');
$tool     = trim($in['tool'] ?? '');
$isChat   = ($prompt !== '');

/* ============================================================
   Chargement des données réelles de la plateforme (grounding)
   ============================================================ */
$dataFile = __DIR__ . '/../data/regions_data.json';
$data = json_decode(@file_get_contents($dataFile), true) ?: [];

/**
 * Construit un contexte textuel FACTUEL à partir des données disponibles.
 * Sélectionne les blocs pertinents selon le texte de la requête.
 */
function build_grounding($data, $needle) {
    $needle = mb_strtolower($needle);
    $g = [];
    $has = fn($w) => $needle === '' || mb_strpos($needle, $w) !== false;

    // --- Chômage / activité / emploi (séries HCP) ---
    $ns = $data['national_series'] ?? [];
    if ($ns && ($has('chôm') || $has('chom') || $has('emploi') || $has('activ') || $has('travail') || $needle === '')) {
        $an = $ns['annees'] ?? [];
        foreach (['taux_chomage','taux_activite','pib'] as $kk) {
            if (!empty($ns[$kk]['valeurs'])) {
                $vals = $ns[$kk]['valeurs'];
                $lib  = $ns[$kk]['libelle'] ?? $kk;
                $last = end($vals); $first = reset($vals);
                $g[] = "$lib : de $first (".($an[0]??'').") à $last (".(end($an)).").";
            }
        }
    }
    // --- National (dernier point) ---
    $nat = $data['national'] ?? [];
    if ($nat && ($has('chôm')||$has('chom')||$has('national')||$has('maroc')||$has('populat')||$has('santé')||$has('sante')||$has('hôpi')||$has('hopi')||$needle==='')) {
        if (isset($nat['taux_chomage_national_2023'])) $g[] = "Chômage national 2023 : {$nat['taux_chomage_national_2023']}% (urbain {$nat['taux_chomage_urbain_2023']}%, rural {$nat['taux_chomage_rural_2023']}%).";
        if (isset($nat['taux_activite_national_2023'])) $g[] = "Taux d'activité national 2023 : {$nat['taux_activite_national_2023']}%.";
        if (isset($nat['population_2024'])) $g[] = "Population 2024 : ".number_format($nat['population_2024'],0,',',' ')." hab. (urbaine ".number_format($nat['population_urbaine']??0,0,',',' ').", rurale ".number_format($nat['population_rurale']??0,0,',',' ').").";
        if (isset($nat['hopitaux_publics_2022'])) $g[] = "Santé 2022 : {$nat['hopitaux_publics_2022']} hôpitaux publics, ".number_format($nat['lits_hopitaux_2022']??0,0,',',' ')." lits.";
    }
    // --- HCP T2 2026 (données conjoncturelles les plus récentes) ---
    if ($nat && ($has('chôm')||$has('chom')||$has('emploi')||$has('pib')||$has('croissance')||$has('inflation')||$has('prix')||$has('ipc')||$has('activ')||$has('2026')||$has('conjonct')||$needle==='')) {
        if (isset($nat['taux_chomage_2026_t2'])) $g[] = "Chômage T2 2026 : {$nat['taux_chomage_2026_t2']}% au national (urbain {$nat['taux_chomage_urbain_2026_t2']}%, rural {$nat['taux_chomage_rural_2026_t2']}%) — source HCP, Enquête sur l'emploi T2 2026.";
        if (isset($nat['taux_activite_2026_t2'])) $g[] = "Taux d'activité T2 2026 : {$nat['taux_activite_2026_t2']}% ; taux d'emploi {$nat['taux_emploi_2026_t2']}% (HCP T2 2026).";
        if (isset($nat['pib_croissance_2026_t2'])) $g[] = "Croissance du PIB T2 2026 : +{$nat['pib_croissance_2026_t2']}% (T1 2026 : +{$nat['pib_croissance_2026_t1']}%) ; PIB 2025 : ".number_format($nat['pib_2025_mdh']??0,0,',',' ')." MDH (HCP).";
        if (isset($nat['ipc_general_mai_2026'])) $g[] = "Indice des prix à la consommation (IPC), mai 2026 : indice {$nat['ipc_general_mai_2026']}, variation annuelle +{$nat['ipc_var_annuelle_mai_2026']}% (HCP).";
    }
    // --- Régions (emploi / population) ---
    $regs = $data['regions'] ?? [];
    if ($regs && ($has('région')||$has('region')||$has('territoire')||$has('chôm')||$has('chom')||$needle==='')) {
        $rows = [];
        foreach ($regs as $r) {
            $ch = $r['emploi']['taux_chomage_2023'] ?? null;
            $po = $r['population']['total_2024'] ?? null;
            if ($ch !== null) $rows[] = ($r['nom']??'?').": chômage {$ch}%".($po?(", pop. ".number_format($po,0,',',' ')):"");
        }
        if ($rows) $g[] = "Par région (2023) — ".implode(" ; ", $rows).".";
    }
    // --- Psychosocial (presse / mots / sujets) ---
    $psy = $data['psychosocial'] ?? [];
    if ($psy && ($has('social')||$has('psycho')||$has('presse')||$has('mot')||$has('sujet')||$has('cohésion')||$has('cohesion')||$has('climat')||$has('gap')||$has('lacune')||$needle==='')) {
        $m = $psy['_meta'] ?? [];
        $g[] = "Observatoire psychosocial (".($m['derniere_mise_a_jour']??'')." · ".($m['nb_articles']??0)." articles de presse). Sources : ".implode(', ', $m['sources'] ?? []).".";
        $suj = array_map(fn($s)=>$s['nom']." (".$s['freq']." occ.)", array_slice($psy['sujets'] ?? [], 0, 10));
        if ($suj) $g[] = "Sujets les plus discutés : ".implode(' ; ', $suj).".";
        $mots = array_map(fn($w)=>$w['mot']."(".$w['freq'].")", array_slice($psy['mots_cles'] ?? [], 0, 15));
        if ($mots) $g[] = "Mots les plus fréquents dans la presse : ".implode(', ', $mots).".";
        $rr = [];
        foreach (($psy['regions'] ?? []) as $rid=>$rv) { $rr[] = ($rv['nom']??$rid).": ".($rv['volume_total']??0)." articles"; }
        if ($rr) $g[] = "Volume presse par région : ".implode(' ; ', $rr).".";
    }
    // --- Articles récents citables (corpus presse, avec URL source) ---
    $corpus = json_decode(@file_get_contents(__DIR__.'/../data/psychosocial_corpus.json'), true) ?: [];
    $recentArts = array_slice($corpus['articles'] ?? [], 0, 8);
    if ($recentArts && ($has('presse')||$has('article')||$has('actualité')||$has('actualite')||$has('actu')||$has('journal')||$has('média')||$has('media')||$needle==='')) {
        $lines = [];
        foreach ($recentArts as $a) {
            $url = !empty($a['url']) ? " [{$a['url']}]" : '';
            $lines[] = "[{$a['source']}] {$a['title']}{$url}";
        }
        $g[] = "Articles récents de la presse marocaine :\n- ".implode("\n- ", $lines);
    }
    return $g;
}

$needle = $isChat ? ($tool.' '.$prompt) : ($title.' '.$question.' '.json_encode($ctx, JSON_UNESCAPED_UNICODE));
$grounding = build_grounding($data, $needle);

/* Contexte spécifique fourni par un bouton d'analyse */
$ctxLines = [];
foreach (['interpretation'=>'Interprétation','tendance'=>'Tendance','recommandation'=>'Recommandation','source'=>'Source'] as $k=>$lbl) {
    if (!empty($ctx[$k])) $ctxLines[] = "$lbl : ".$ctx[$k];
}

/* ============================================================
   MOTEUR LOCAL (sans clé) — analyse fondée sur les données réelles
   ============================================================ */
function local_structured($title, $ctx) {
    $h = '';
    if (!empty($ctx['interpretation'])) $h .= '<h4>🔎 Interprétation potentielle</h4><p>'.htmlspecialchars($ctx['interpretation']).'</p>';
    if (!empty($ctx['tendance']))       $h .= '<h4>📈 Tendance prospective</h4><p>'.htmlspecialchars($ctx['tendance']).'</p>';
    if (!empty($ctx['recommandation'])) $h .= '<h4>🎯 Recommandation opérationnelle</h4><p>'.htmlspecialchars($ctx['recommandation']).'</p>';
    $src = !empty($ctx['source']) ? ('Source : '.htmlspecialchars($ctx['source']).'. ') : '';
    $h .= '<p class="ai-source">'.$src.'Analyse locale (IA générative non configurée) fondée sur les données de la plateforme — non une vérité absolue.</p>';
    return $h ?: '<p>Aucune donnée documentée pour cet élément.</p>';
}

/* Réponse "chat" locale : reformule les données factuelles pertinentes. */
function local_chat($prompt, $tool, $grounding) {
    $intro = $tool !== ''
        ? "<h4>🛠️ ".htmlspecialchars($tool)."</h4><p>Voici une analyse fondée sur les données réelles de la plateforme :</p>"
        : "<h4>🔎 Analyse fondée sur les données ATLASIA</h4>";
    if (!$grounding) {
        return $intro."<p>Aucune donnée directement liée à cette demande n'est disponible dans la plateforme pour le moment. "
             ."Reformulez avec un thème couvert : chômage, activité, population, santé, presse/mots sociaux, régions.</p>"
             ."<p class=\"ai-source\">Moteur d'analyse local ATLASIA — fondé uniquement sur les données disponibles.</p>";
    }
    $items = array_map(fn($x)=>'<li>'.htmlspecialchars($x).'</li>', $grounding);
    return $intro
        ."<p>« ".htmlspecialchars($prompt)." »</p>"
        ."<h4>📊 Données mobilisées</h4><ul>".implode('', $items)."</ul>"
        ."<h4>🎯 Lecture</h4><p>Ces chiffres proviennent des Annuaires Statistiques du Maroc (HCP) et du corpus de presse de l'Observatoire. "
        ."Ils décrivent des ordres de grandeur ; toute conclusion doit rester prudente et croiser plusieurs sources.</p>"
        ."<p class=\"ai-source\">Moteur d'analyse local ATLASIA — fondé uniquement sur les données de la plateforme, non une vérité absolue. "
        ."Ajoutez une clé API (voir api/config.php) pour activer la rédaction générative complète.</p>";
}

if ($apiKey === '') {
    $text = $isChat ? local_chat($prompt, $tool, $grounding) : local_structured($title, $ctx);
    echo json_encode(['ok'=>true,'mode'=>'local','text'=>$text], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   APPEL IA GÉNÉRATIVE (OpenAI-compatible)
   ============================================================ */
$system = "Tu es l'assistant analytique de la plateforme ATLASIA (intelligence territoriale du Maroc). "
    . "Tu réponds en français, de façon concise, structurée et professionnelle. "
    . "RÈGLE ABSOLUE (Charte ATLASIA) : tu ne t'appuies QUE sur les données fournies ci-dessous. "
    . "Tu n'inventes aucun chiffre, aucune région, aucune source. Si une information manque, dis-le explicitement. "
    . "Tu présentes toujours tes lectures comme des interprétations plausibles, jamais comme des vérités absolues. "
    . "Réponds en HTML simple (balises <h4>, <p>, <ul><li>). "
    . "Quand tu cites un article de presse, inclus son URL si disponible sous forme de lien HTML <a href='...' target='_blank'>. "
    . "Termine par un court <p class='ai-source'> citant la source (HCP / Observatoire ATLASIA) et rappelant la nature interprétative.";

if ($isChat) {
    $user  = ($tool !== '' ? "Outil demandé : $tool.\n" : "");
    $user .= "Demande de l'utilisateur : $prompt\n\n";
    if ($grounding) $user .= "Données disponibles dans la plateforme :\n- ".implode("\n- ", $grounding)."\n\n";
    $user .= "Réponds à la demande en t'appuyant uniquement sur ces données.";
} else {
    $user  = "Élément analysé : $title.\n\n";
    if ($ctxLines)  $user .= "Éléments de contexte de la plateforme :\n- ".implode("\n- ", $ctxLines)."\n\n";
    if ($grounding) $user .= "Données agrégées disponibles :\n- ".implode("\n- ", $grounding)."\n\n";
    $user .= $question !== ''
        ? "Question de l'utilisateur : $question"
        : "Produis une analyse (interprétation, tendance, recommandation) de cet élément à partir des données ci-dessus.";
}

$payload = json_encode([
    'model'       => $cfg['model'] ?? 'gpt-4o-mini',
    'temperature' => $cfg['temperature'] ?? 0.3,
    'messages'    => [
        ['role'=>'system','content'=>$system],
        ['role'=>'user',  'content'=>$user],
    ],
], JSON_UNESCAPED_UNICODE);

$url = $baseUrl . '/chat/completions';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.$apiKey],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => $cfg['timeout'] ?? 45,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $code >= 400) {
    $text = $isChat ? local_chat($prompt, $tool, $grounding) : local_structured($title, $ctx);
    $text = '<p class="ai-source" style="color:#b45309">⚠️ IA distante indisponible ('.($code ?: 'réseau').') — repli sur l\'analyse locale.</p>'.$text;
    echo json_encode(['ok'=>true,'mode'=>'local','text'=>$text], JSON_UNESCAPED_UNICODE);
    exit;
}

$j = json_decode($resp, true);
$content = $j['choices'][0]['message']['content'] ?? '';
if ($content === '') {
    $text = $isChat ? local_chat($prompt, $tool, $grounding) : local_structured($title, $ctx);
    echo json_encode(['ok'=>true,'mode'=>'local','text'=>$text], JSON_UNESCAPED_UNICODE);
    exit;
}
$content = preg_replace('/^```(html)?|```$/m', '', $content);
echo json_encode(['ok'=>true,'mode'=>'ai','text'=>$content], JSON_UNESCAPED_UNICODE);
