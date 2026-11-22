Judilibre Agent
===============

Your legal documents, drafted with AI: formal notices, writs of summons, pleadings, legal
opinions, petitions.

For young and independent lawyers
----------------------------------

Every document means hours of work: understanding the case, finding the right rulings
among thousands, sorting them, then writing it all up properly. Big firms have teams for
that. A lawyer on their own doesn't. Judilibre Agent does this work with them, at a lower
cost and much faster.

Not a chatbot: an intelligent application
-----------------------------------------

Judilibre Agent is not a chat window with an AI behind it. It is an application that the
lawyer and the agent use **together**, on the same screen and the same dossier.

 * **The agent drives the interface.** It doesn't only answer with text: it builds what the
   lawyer needs at each step, a form to fill in, a research plan to edit, rulings to sort,
   a document to review, and it updates the dossier live as it works.
 * **The lawyer acts on it directly.** They answer the form, add or remove research axes,
   pin or discard rulings, edit the filters. Every click changes the shared dossier, and
   the agent picks up from there.
 * **Several AIs, one workflow.** Claude Opus reasons and writes, Jev sorts the case law;
   together they take the lawyer from the client's problem to the finished document.

For example, for a legal opinion ahead of a hearing:

 1. **Qualify** — the lawyer describes the client's problem; the agent asks its questions
    in a form (context, problem, party represented). It then proposes an anonymised case
    brief and a research plan split into axes: the lawyer edits, adds or removes axes,
    then approves.
 2. **Search & sort** — for each axis, the agent queries
    [Judilibre](https://www.courdecassation.fr/acces-rapide-judilibre), the open data of
    the French Court of Cassation; **Jev** (TypeSafe AI) sorts the hundreds of decisions
    found in a few seconds and keeps only those that serve the case.
 3. **Select** — case law comes back as principles, each backed by its rulings; the lawyer
    pins the ones that matter, discards the others, then searches further or moves on to
    writing.
 4. **Write** — a few framing questions, then the document is written before their eyes,
    section by section, every citation checked by the server; it exports to Word.

Built with Symfony
------------------

 * **[Symfony AI](https://symfony.com/doc/current/ai/index.html)** — several models work
   together behind a single abstraction: Claude Opus 5.5 reasons and writes, Jev 1.14
   sorts the decisions. Every tool of the agent is a plain PHP class (`#[AsTool]`).
 * **[AG-UI](https://docs.ag-ui.com), in PHP** — we implemented the protocol
   ([AG-UI for PHP](https://github.com/WebMamba/ag-ui-php)) and plugged it into
   Symfony AI: messages, tool calls, dossier state, everything streams live as events.
 * **[Symfony UX](https://ux.symfony.com)** — those events become components: Twig
   Components render what the agent proposes, Live Components keep the dossier state up
   to date, Stimulus handles the lawyer's interactions, Turbo keeps navigation smooth. A
   dynamic UI with no JavaScript framework and no Node.js: just PHP and Twig.

Modelled on [demo-ag-ui](https://github.com/WebMamba/demo-ag-ui).

Symfony, essential
------------------

Case law, sorted, understood, ready to plead, and the long-standing components for
reliability: **Doctrine** to save every dossier, **Security** so each lawyer only sees
their own, **Lock** so a dossier is only processed by one run at a time, **Validator** and
**Serializer** so the dossier state always stays consistent.

Requirements
------------

 * PHP 8.4 or later, [Composer](https://getcomposer.org) and the [Symfony CLI](https://symfony.com/download)
 * An [Anthropic API key](https://console.anthropic.com/settings/keys)
 * A [TypeSafe API key](https://console.typesafe.ai/keys), for Jev
 * A Judilibre API key: create an application on [PISTE](https://piste.gouv.fr),
   subscribe it to the Judilibre API, and get its key (`KeyId` header)

No database server (SQLite), no Node.js, no Docker.

Installation
------------

```bash
composer install
php bin/console importmap:install
php bin/console tailwind:build
php bin/console doctrine:migrations:migrate
```

Keys go in `.env.local`, never committed:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
TYPESAFE_API_KEY=...
APP_USERS='{"you@example.com":"<hash>"}'   # php bin/console security:hash-password
JUDILIBRE_KEY_ID=...   # the PISTE API key, not an OAuth client ID
APP_LOCALE=en          # optional: interface and agent in English (French by default)
# Production by default; a sandbox key goes with:
# JUDILIBRE_BASE_URL=https://sandbox-api.piste.gouv.fr/cassation/judilibre/v1.0/
```

Running
-------

```bash
symfony server:start -d
php bin/console tailwind:build --watch   # while you edit templates or CSS
```

> [!NOTE]
> There is no usage limit: every signed-in account spends your Anthropic credits and your
> PISTE quota without a cap.

How it works
------------

 * The agent and its prompt: `config/packages/ai.yaml`. Its tools: `src/Ai/Tool`, guarded
   by phase (`PhaseGuard`). The components it renders: `src/Twig/Components/Agent`.
 * Sorting by Jev: `src/Triage/JevTriage.php`, called by `search_axis`.
 * The dossier state: `src/Research/ResearchState.php`, saved with its conversation in the
   `Dossier` entity (`src/Dossier/DossierRuns.php`), rendered by the Live Components
   (stepper, case brief, consultation, dossier, filters).
 * The legal opinion: `src/Dossier/ConsultationRenderer.php` (Document view) and
   `ConsultationExporter.php` (Word).
 * The vocabulary: [CONTEXT.md](CONTEXT.md); the decisions: [docs/adr](docs/adr).

Deployment
----------

On a VPS with Docker: FrankenPHP serves the application and obtains the HTTPS certificate.
Access is restricted to the accounts in `APP_USERS` (email => password hash).

Once, on the VPS:

```bash
git clone git@github.com:WebMamba/judilibre-agent.git /srv/judilibre-agent   # with a read-only deploy key
cd /srv/judilibre-agent
cp deploy/env.prod.local.dist .env.prod.local && chmod 600 .env.prod.local     # then fill it in
./deploy/deploy.sh
```

After that, every push to `main` deploys once the tests pass (`.github/workflows/ci.yml`).
The server (`deploy@72.61.193.89`, `/srv/judilibre-agent`) is set in the workflow. In the
GitHub repository: the `DEPLOY_ENABLED=true` variable, and the `DEPLOY_SSH_KEY` (a private
key authorised on the VPS) and `DEPLOY_KNOW_HOSTS` (`ssh-keyscan 72.61.193.89`) secrets.

Tests
-----

```bash
php bin/phpunit
```

No network, no key: Judilibre answers from `tests/Fixtures/judilibre`, the model is
replaced by a scripted platform, and Jev by a fake sorter (`tests/Support`).

Demo video
----------

One minute, no voice, in English, following the presentation above: animated cards, then
a real dossier captioned step by step, with what the lawyer does circled on screen and the
agent's waits sped up (their real duration is shown). The application is recorded in
English: `APP_LOCALE=en` in `.env.local`.

`demo/` records the dossier in the browser (`record.js`, via Playwright), then assembles
the video (`assemble.py` cuts and speeds up, `assemble.swift` adds captions
(`captions.json`) and animations), without a system ffmpeg. `marks.json` is what
`record.js` outputs:

```bash
python3 demo/assemble.py demo/output/raw/<video>.webm demo/output/marks.json
xcrun swiftc -O demo/assemble.swift -o demo/output/assemble && demo/output/assemble demo/output/spec.json
```
