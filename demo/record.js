// Records the demo: title cards, then a real dossier from the problem to the Word
// export, in a 1600x900 browser with a visible cursor. Run it with Playwright MCP's
// browser_run_code (filename: demo/record.js), logged in on http://127.0.0.1:8000.
// Returns the milestones (seconds since the video started) that demo/assemble.swift cuts on.
async (page) => {
    const BASE = 'http://127.0.0.1:8000';
    const W = 1600, H = 900;

    const card = (inner) => `<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
        html,body{margin:0;height:100%;font-family:-apple-system,"SF Pro Display","Helvetica Neue",sans-serif;color:#fff}
        body{display:flex;align-items:center;justify-content:center;background:radial-gradient(1200px 700px at 20% 10%,#4338ca 0%,#1e1b4b 55%,#0b0a1f 100%)}
        .wrap{max-width:1180px;padding:0 80px}
        .kicker{font-size:22px;letter-spacing:.28em;text-transform:uppercase;color:#a5b4fc}
        h1{font-size:84px;line-height:1.02;margin:.25em 0 .2em;font-weight:750;letter-spacing:-.02em}
        p{font-size:30px;line-height:1.45;color:#c7d2fe;margin:0}
        .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:48px}
        .step{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:18px;padding:22px}
        .step b{display:block;font-size:24px;margin-bottom:8px}.step span{font-size:18px;color:#c7d2fe;line-height:1.4}
        .stack{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:40px}
        .chip{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:18px;padding:22px 24px}
        .chip b{display:block;font-size:26px}.chip span{font-size:18px;color:#c7d2fe}
        .scale{font-size:96px}
    </style></head><body><div class="wrap">${inner}</div></body></html>`;

    const CARDS = {
        title: card(`<div class="scale">⚖️</div><div class="kicker">Judilibre Agent</div>
            <h1>De votre problème juridique<br>à la consultation prête à plaider.</h1>
            <p>Un agent IA qui cherche, trie et rédige avec l'avocat, sur la jurisprudence de la Cour de cassation.</p>`),
        problem: card(`<div class="kicker">Le problème</div>
            <h1>Des heures de recherche<br>pour chaque plaidoirie.</h1>
            <p>Trouver les bons arrêts parmi des milliers, les trier, puis rédiger.</p>
            <div class="steps">
                <div class="step"><b>1. Qualifier</b><span>L'agent pose les bonnes questions.</span></div>
                <div class="step"><b>2. Planifier</b><span>L'avocat valide la fiche et les axes.</span></div>
                <div class="step"><b>3. Chercher & trier</b><span>Judilibre interrogé, Jev trie.</span></div>
                <div class="step"><b>4. Rédiger</b><span>Une consultation vérifiée, en Word.</span></div>
            </div>`),
        stack: card(`<div class="kicker">Sous le capot</div><h1>Une architecture agentique.</h1>
            <div class="stack">
                <div class="chip"><b>Symfony 8 · Symfony AI</b><span>Agent, tools, phases gardées par le serveur</span></div>
                <div class="chip"><b>AG-UI</b><span>Streaming, interrupts, composants Twig</span></div>
                <div class="chip"><b>Claude Opus</b><span>Qualification, raisonnement, rédaction</span></div>
                <div class="chip"><b>Jev · TypeSafe AI</b><span>Tri de centaines de décisions en parallèle</span></div>
                <div class="chip"><b>API Judilibre</b><span>L'open data de la Cour de cassation</span></div>
                <div class="chip"><b>Doctrine · Lock</b><span>Dossiers persistés, un run à la fois</span></div>
            </div>`),
        end: card(`<div class="scale">⚖️</div><div class="kicker">Judilibre Agent</div>
            <h1>La jurisprudence, triée,<br>comprise, et prête à plaider.</h1>
            <p>Symfony AI · AG-UI · Claude Opus · Jev · Judilibre</p>`),
    };

    const context = await page.context().browser().newContext({
        viewport: { width: W, height: H },
        recordVideo: { dir: 'demo/output/raw', size: { width: W, height: H } },
        storageState: await page.context().storageState(),
        acceptDownloads: true,
        locale: 'fr-FR',
    });
    // A visible cursor: the recording does not show the system one.
    await context.addInitScript(() => {
        const install = () => {
            if (document.getElementById('demo-cursor')) return;
            const c = document.createElement('div');
            c.id = 'demo-cursor';
            c.style.cssText = 'position:fixed;z-index:2147483647;left:-40px;top:-40px;width:22px;height:22px;border-radius:50%;background:rgba(79,70,229,.35);border:2px solid #4f46e5;pointer-events:none;transform:translate(-50%,-50%);transition:transform .12s';
            document.documentElement.appendChild(c);
            addEventListener('mousemove', (e) => { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; }, true);
            addEventListener('mousedown', () => { c.style.transform = 'translate(-50%,-50%) scale(.7)'; }, true);
            addEventListener('mouseup', () => { c.style.transform = 'translate(-50%,-50%) scale(1)'; }, true);
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
    });

    const p = await context.newPage();
    let failure = null;
    try {
        const t0 = Date.now();
        const marks = [];
        const mark = (id) => marks.push({ id, t: (Date.now() - t0) / 1000 });
        const pause = (ms) => p.waitForTimeout(ms);
        let mouse = { x: W / 2, y: H / 2 };
        const moveTo = async (locator) => {
            await locator.scrollIntoViewIfNeeded();
            const box = await locator.boundingBox();
            const x = box.x + box.width / 2, y = box.y + box.height / 2;
            await p.mouse.move(x, y, { steps: 18 });
            mouse = { x, y };
        };
        const click = async (locator) => { await moveTo(locator); await pause(250); await locator.click(); await pause(350); };
        const scrollEl = async (selector, dy, steps = 30) => {
            for (let i = 0; i < steps; i++) {
                await p.evaluate(([s, d]) => { const e = document.querySelector(s); if (e) e.scrollTop += d; }, [selector, dy / steps]);
                await pause(40);
            }
        };
        const MESSAGES = '[data-agent-chat-target="messages"]';

        // — Title cards —
        await p.setContent(CARDS.title); mark('title'); await pause(6000);
        await p.setContent(CARDS.problem); mark('problem'); await pause(6000);

        // — A new dossier —
        await p.goto(BASE + '/'); mark('start'); await pause(1500);
        await click(p.getByText('Nouveau dossier', { exact: false }).first());
        await p.waitForSelector('textarea[data-agent-chat-target="input"]');
        await pause(800);
        const input = p.locator('textarea[data-agent-chat-target="input"]');
        await click(input);
        await input.pressSequentially("Mon client, chef d'équipe dans une PME de logistique depuis 12 ans, a été licencié après 8 mois d'arrêt maladie. L'employeur invoque la désorganisation de l'entreprise, mais il n'a été remplacé que par des intérimaires. Peut-il contester ?", { delay: 12 });
        await pause(600);
        await p.keyboard.press('Enter');
        mark('sent');

        // — Qualification: one to three forms, until the plan —
        const answerForm = async (form, pick) => {
            for (const question of await form.locator('[data-questions-target="question"]').all()) {
                const chips = question.locator('label.choice-chip');
                const count = await chips.count();
                if (count > 0) {
                    await click(chips.nth(Math.min(pick, count - 1)));
                    if ((await question.getAttribute('data-type')) === 'multiple' && count > 2) await click(chips.nth(pick + 1));
                } else {
                    await click(question.locator('textarea'));
                    await question.locator('textarea').pressSequentially("Convention collective des transports routiers, 45 salariés, agent de maîtrise. Intérimaire en poste dès le 2e mois d'arrêt ; aucun CDI 3 mois après.", { delay: 6 });
                }
            }
            await pause(500);
            await click(form.locator('[data-questions-target="submit"]'));
        };
        const forms = () => p.locator('[data-controller="questions"]').count();
        await p.waitForSelector('[data-controller="questions"] [data-questions-target="submit"]', { timeout: 240000 });
        await pause(1200);
        mark('questions');
        for (let round = 1; round <= 3; round++) {
            const before = await forms();
            await answerForm(p.locator('[data-controller="questions"]').last(), 0);
            if (round === 1) mark('answered');
            await p.waitForFunction((n) => document.querySelector('[data-controller="research-plan"] [data-research-plan-target="approve"]') || document.querySelectorAll('[data-controller="questions"]').length > n, before, { timeout: 240000 });
            if (await p.locator('[data-controller="research-plan"]').count() > 0) break;
            await pause(1200);
        }

        // — Brief and plan —
        await p.waitForSelector('[data-controller="research-plan"] [data-research-plan-target="approve"]', { timeout: 240000 });
        await pause(1200);
        mark('plan');
        const plan = p.locator('[data-controller="research-plan"]').last();
        await moveTo(plan.locator('textarea[data-field="facts"]'));
        await pause(1500);
        await scrollEl(MESSAGES, 500, 40);
        await click(plan.getByText('Ajouter un axe'));
        const added = plan.locator('[data-research-plan-target="axis"]').last();
        await added.locator('[data-role="title"]').pressSequentially('Indemnité compensatrice de préavis du salarié malade', { delay: 18 });
        await pause(800);
        await click(plan.locator('[data-research-plan-target="approve"]'));
        mark('validated');

        // — Search, triage, principles —
        await p.waitForSelector('[data-controller="findings"]', { timeout: 900000 });
        await pause(1500);
        mark('findings');
        const findings = p.locator('[data-controller="findings"]').last();
        await findings.evaluate((e) => e.scrollIntoView({ block: 'start' }));
        await pause(1500);
        await click(findings.locator('[data-pin-principle]').first());
        await click(findings.locator('[data-pin-decision]').nth(0));
        await scrollEl(MESSAGES, 260, 25);
        await click(findings.locator('[data-pin-decision]').nth(1));
        await scrollEl(MESSAGES, 700, 50);
        const excludes = findings.locator('[data-exclude-decision]');
        await click(excludes.nth(Math.min(5, (await excludes.count()) - 1)));
        await pause(800);
        await findings.locator('[data-findings-target="instruction"]').scrollIntoViewIfNeeded();
        await click(findings.locator('[data-findings-target="instruction"]'));
        await findings.locator('[data-findings-target="instruction"]').pressSequentially("Insiste sur l'absence de remplacement définitif.", { delay: 15 });
        const formsBeforeFraming = await forms();
        await click(findings.locator('[data-ag-ui-action-param="write"]'));
        mark('write');

        // — Framing: one or two forms, until the writing starts —
        await p.waitForFunction((n) => document.querySelectorAll('[data-controller="questions"]').length > n || document.querySelector('#section-faits'), formsBeforeFraming, { timeout: 240000 });
        await pause(1200);
        mark('cadrage');
        for (let round = 1; round <= 2 && await p.locator('#section-faits').count() === 0; round++) {
            const before = await forms();
            const framing = p.locator('[data-controller="questions"]').last();
            if (await framing.locator('[data-questions-target="submit"]').isDisabled()) break;
            await answerForm(framing, 1);
            if (round === 1) mark('framed');
            await p.waitForFunction((n) => document.querySelector('#section-faits') || document.querySelectorAll('[data-controller="questions"]').length > n, before, { timeout: 300000 });
            await pause(800);
        }

        // — Writing —
        await p.waitForSelector('#section-faits', { timeout: 300000 });
        mark('redaction');
        await p.waitForFunction(() => document.querySelector('[aria-current=step]')?.innerText.includes('Livr'), null, { timeout: 900000 });
        await pause(2500);
        mark('delivered');

        // — The document, and the Word export —
        await p.mouse.move(800, 450, { steps: 20 });
        await scrollEl('#consultation-panel .overflow-y-auto', 900, 60);
        await pause(800);
        await scrollEl('#consultation-panel .overflow-y-auto', 1400, 70);
        await pause(800);
        await scrollEl('#consultation-panel .overflow-y-auto', 6000, 60);
        await pause(1500);
        const download = p.waitForEvent('download');
        await click(p.getByText('Exporter en Word'));
        await (await download).saveAs('demo/output/consultation.docx');
        await pause(2000);
        mark('exported');

        // — Closing cards —
        await p.setContent(CARDS.stack); mark('stack'); await pause(6000);
        await p.setContent(CARDS.end); mark('end'); await pause(5000);
        mark('stop');

    } catch (e) {
        failure = String(e).slice(0, 500);
    }

    const video = p.video();
    const dossierUrl = p.url();
    await context.close();

    return { video: await video.path(), marks, dossierUrl, failure };
}
