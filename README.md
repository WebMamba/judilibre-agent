Judilibre Agent
===============

Un agent qui prépare, avec l'avocat, une consultation juridique en vue d'une plaidoirie,
appuyée sur [Judilibre](https://www.courdecassation.fr/acces-rapide-judilibre), l'open
data de la Cour de cassation :

 1. **Qualification** — l'avocat expose le problème ; l'agent pose ses questions dans
    un formulaire (contexte, problème, partie défendue).
 2. **Plan** — l'agent propose une fiche dossier anonymisée et des axes de recherche ;
    l'avocat les corrige, ajoute ou supprime des axes, et valide.
 3. **Recherche** — pour chaque axe, l'agent interroge Judilibre ; **Jev** (TypeSafe AI)
    trie les centaines de décisions trouvées et ne garde que les utiles.
 4. **Sélection** — l'agent présente les principes et leurs arrêts ; l'avocat épingle,
    écarte, puis poursuit la recherche ou passe à la rédaction.
 5. **Cadrage et rédaction** — quelques questions, puis la consultation s'écrit section
    par section, chaque citation vérifiée par le serveur ; export Word.

Construit avec [Symfony AI](https://symfony.com/doc/current/ai/index.html),
[Symfony UX](https://ux.symfony.com) et le protocole [AG-UI](https://docs.ag-ui.com)
([AG-UI for PHP](https://github.com/WebMamba/ag-ui-php)), sur le modèle de
[demo-ag-ui](https://github.com/WebMamba/demo-ag-ui). Modèles : Claude Opus 5.5 (l'agent) et Jev 1.14 (le tri).

> [!WARNING]
> Une aide à la décision générée par IA : vérifiez toujours les décisions citées.

Prérequis
---------

 * PHP 8.4 ou plus, [Composer](https://getcomposer.org) et la [Symfony CLI](https://symfony.com/download)
 * Une [clé d'API Anthropic](https://console.anthropic.com/settings/keys)
 * Une [clé d'API TypeSafe](https://console.typesafe.ai/keys), pour Jev
 * Une clé d'API Judilibre : créez une application sur [PISTE](https://piste.gouv.fr),
   abonnez-la à l'API Judilibre, et récupérez sa clé (en-tête `KeyId`)

Ni serveur de base de données (SQLite), ni Node.js, ni Docker.

Installation
------------

```bash
composer install
php bin/console importmap:install
php bin/console tailwind:build
php bin/console doctrine:migrations:migrate
```

Les clés vont dans `.env.local`, jamais commité :

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
TYPESAFE_API_KEY=...
APP_USERS='{"vous@example.fr":"<hachage>"}'   # php bin/console security:hash-password
JUDILIBRE_KEY_ID=...   # la clé d'API PISTE, pas un identifiant OAuth
# Production par défaut ; une clé du bac à sable va avec :
# JUDILIBRE_BASE_URL=https://sandbox-api.piste.gouv.fr/cassation/judilibre/v1.0/
```

Lancement
---------

```bash
symfony server:start -d
php bin/console tailwind:build --watch   # pendant que vous modifiez templates ou CSS
```

> [!NOTE]
> Il n'y a pas de limite d'utilisation : chaque compte connecté consomme vos crédits
> Anthropic et votre quota PISTE sans plafond.

Comment ça marche
-----------------

 * L'agent et son prompt : `config/packages/ai.yaml`. Ses tools : `src/Ai/Tool`, gardés
   par phase (`PhaseGuard`). Les composants qu'il affiche : `src/Twig/Components/Agent`.
 * Le tri par Jev : `src/Triage/JevTriage.php`, appelé par `search_axis`.
 * L'état du dossier : `src/Research/ResearchState.php`, enregistré avec sa conversation
   dans l'entité `Dossier` (`src/Dossier/DossierRuns.php`), affiché par les Live
   Components (frise, fiche, consultation, dossier, filtres).
 * La consultation : `src/Dossier/ConsultationRenderer.php` (vue Document) et
   `ConsultationExporter.php` (Word).
 * Le vocabulaire : [CONTEXT.md](CONTEXT.md) ; les décisions : [docs/adr](docs/adr).

Déploiement
-----------

Sur un VPS avec Docker : FrankenPHP sert l'application et obtient le certificat HTTPS.
L'accès est réservé aux comptes de `APP_USERS` (e-mail => hachage du mot de passe).

Une fois, sur le VPS :

```bash
git clone git@github.com:WebMamba/judilibre-agent.git /srv/judilibre-agent   # avec une deploy key en lecture
cd /srv/judilibre-agent
cp deploy/env.prod.local.dist .env.prod.local && chmod 600 .env.prod.local     # puis le remplir
./deploy/deploy.sh
```

Ensuite, chaque push sur `main` déploie, une fois les tests verts (`.github/workflows/ci.yml`).
Le serveur (`deploy@72.61.193.89`, `/srv/judilibre-agent`) est écrit dans le workflow.
Dans le dépôt GitHub : la variable `DEPLOY_ENABLED=true`, et les secrets `DEPLOY_SSH_KEY`
(une clé privée autorisée sur le VPS) et `DEPLOY_KNOW_HOSTS` (`ssh-keyscan 72.61.193.89`).

Tests
-----

```bash
php bin/phpunit
```

Ni réseau, ni clé : Judilibre répond depuis `tests/Fixtures/judilibre`, et le modèle est
remplacé par une plateforme scriptée, et Jev par un tri factice (`tests/Support`).

Vidéo de présentation
---------------------

`demo/` enregistre un vrai dossier dans le navigateur (`record.js`, via Playwright), puis
monte la vidéo avec la voix off (`say`) et les sous-titres, sans ffmpeg système :

```bash
python3 demo/assemble.py demo/output/raw/<video>.webm demo/output/marks.json
xcrun swiftc -O demo/assemble.swift -o demo/output/assemble && demo/output/assemble demo/output/spec.json
```
