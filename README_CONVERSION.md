# ATLASIA — Version statique (HTML / CSS / JS)

Ce dossier est la conversion de ton site PHP (ATLASIA-main) en site 100%
statique, compatible avec GitHub Pages (ou n'importe quel hébergement
statique : Netlify, Vercel, Cloudflare Pages...).

## Ce qui a été fait
- Les 15 pages `.php` ont été rendues en `.html` (contenu identique à ce que
  tu vois en local sur XAMPP — cartes, graphiques, textes, mise en page).
- Tous les liens internes de navigation (`dashboard.php` → `dashboard.html`,
  etc.) ont été corrigés automatiquement.
- `index.php` (qui faisait une redirection serveur) a été remplacé par un
  `index.html` qui redirige vers `dashboard.html`.
- Les données (`data/*.json`, `*.geojson`), le CSS et le dossier `uploads/`
  ont été copiés tels quels — les cartes et graphiques Leaflet/Chart.js
  continuent de fonctionner car ils chargent déjà ces fichiers via
  JavaScript (`fetch`).

## Ce qui NE peut PAS fonctionner en statique (limitation technique, pas un bug)
Certaines fonctionnalités appellent un serveur PHP en arrière-plan
(dossier `api/`) : GitHub Pages ne peut exécuter aucun code serveur, quel
que soit le langage.
- `api/ai.php` : l'assistant IA générative → bascule automatiquement sur
  une **analyse locale** déjà intégrée (aucune page cassée, juste moins
  "vivant").
- `api/update_data.php` et `api/refresh_psychosocial.php` (page
  Administration) : écriture de fichiers côté serveur → affichera un
  message d'erreur clair invitant à utiliser un serveur PHP.
- `api/news.php` (actualités dynamiques) : idem.

Ces fichiers `api/*.php` sont inclus dans le dossier pour référence, mais
ne s'exécuteront pas sur un hébergement statique.

## Pour héberger ce site
1. Mets tout le contenu de ce dossier à la racine de ton repo GitHub
   (ou dans `/docs` si tu préfères).
2. Active GitHub Pages sur ce dossier.
3. Le site s'ouvrira directement sur `index.html` → `dashboard.html`.

Si tu veux un jour retrouver les fonctionnalités IA/admin en ligne, il
faudra un hébergeur qui supporte PHP (voir la discussion précédente).
