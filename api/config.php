<?php
/**
 * ATLASIA — Configuration de l'IA générative embarquée.
 *
 * 1. Copiez ce fichier en « config.php » dans le même dossier (api/).
 * 2. Collez votre clé API et choisissez le fournisseur.
 * 3. Sans clé valide, la plateforme retombe automatiquement sur l'analyse
 *    locale (aucune page cassée).
 *
 * Fournisseurs compatibles (API type OpenAI /chat/completions) :
 *   - OpenAI        : https://api.openai.com/v1   | modèle ex. gpt-4o-mini
 *   - Abacus RouteLLM : https://routellm.abacus.ai/v1 | modèle ex. route-llm
 *   - Groq, Together, Mistral, OpenRouter, Ollama local, etc.
 */
return [
    // Laissez vide ('') pour forcer le repli local (analyse hors-ligne).
    'api_key'  => '',

    // URL de base de l'API (sans /chat/completions à la fin).
    'base_url' => 'https://api.openai.com/v1',

    // Nom du modèle.
    'model'    => 'gpt-4o-mini',

    // Température (0 = factuel, 1 = créatif). 0.3 recommandé.
    'temperature' => 0.3,

    // Délai max d'appel réseau (secondes).
    'timeout'  => 30,
];
