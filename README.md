Recherche de jurisprudence
==========================

Un assistant qui retrouve des décisions de justice françaises dans
[Judilibre](https://www.courdecassation.fr/acces-rapide-judilibre), l'open data de la
Cour de cassation. On lui pose une question de droit ; il propose un plan de recherche,
que l'utilisateur approuve, interroge Judilibre, lit les décisions et répond par une
synthèse et des cartes de décisions à épingler dans un dossier.

Construit avec [Symfony AI](https://symfony.com/doc/current/ai/index.html),
[Symfony UX](https://ux.symfony.com) et le protocole [AG-UI](https://docs.ag-ui.com)
([AG-UI for PHP](https://github.com/WebMamba/ag-ui-php)), sur le modèle de
[demo-ag-ui](https://github.com/WebMamba/demo-ag-ui). Modèle : Claude Opus 5.5.

> [!WARNING]
> Une aide à la recherche, pas une consultation juridique : vérifiez toujours les
> décisions citées.

Prérequis
---------

 * PHP 8.4 ou plus, [Composer](https://getcomposer.org) et la [Symfony CLI](https://symfony.com/download)
 * Une [clé d'API Anthropic](https://console.anthropic.com/settings/keys)
 * Une clé d'API Judilibre : créez une application sur [PISTE](https://piste.gouv.fr),
   abonnez-la à l'API Judilibre, et récupérez sa clé (en-tête `KeyId`)

Ni base de données, ni Node.js, ni Docker.

Installation
------------

```bash
composer install
php bin/console importmap:install
php bin/console tailwind:build
```

Les clés vont dans `.env.local`, jamais commité :

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
JUDILIBRE_KEY_ID=...
# Bac à sable par défaut ; en production :
# JUDILIBRE_BASE_URL=https://api.piste.gouv.fr/cassation/judilibre/v1.0/
```

Lancement
---------

```bash
symfony server:start -d
php bin/console tailwind:build --watch   # pendant que vous modifiez templates ou CSS
```

> [!CAUTION]
> `POST /agent/stream` n'a ni authentification ni limite de débit : quiconque y accède
> consomme vos crédits Anthropic et votre quota PISTE. Ne l'exposez pas tel quel.

Comment ça marche
-----------------

 * L'agent et son prompt : `config/packages/ai.yaml`. Ses tools : `src/Ai/Tool`. Les
   composants qu'il affiche : `src/Twig/Components/Agent`.
 * L'état partagé (filtres, plan approuvé, dossier) : `src/Research/ResearchState.php`,
   affiché par les Live Components `FiltersPanel` et `DossierPanel`, et gardé dans le
   localStorage du navigateur par `assets/controllers/research_controller.js`.
 * Le vocabulaire : [CONTEXT.md](CONTEXT.md) ; les décisions : [docs/adr](docs/adr).

Le bridge AG-UI est patché (`patches/`, appliqué par `cweagans/composer-patches`) pour
envoyer au navigateur les changements d'état faits à la reprise d'un interrupt : sans
lui, un plan approuvé serait oublié au message suivant. Le correctif est proposé en
amont ; retirez le patch une fois publié.

Tests
-----

```bash
php bin/phpunit
```

Ni réseau, ni clé : Judilibre répond depuis `tests/Fixtures/judilibre`, et le modèle est
remplacé par une plateforme scriptée (`tests/Support`).
