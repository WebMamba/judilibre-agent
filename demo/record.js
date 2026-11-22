// Records the demo: title cards, then a real dossier from the problem to the Word
// export, in a 1600x900 browser with a visible cursor. Run it with Playwright MCP's
// browser_run_code (filename: demo/record.js), logged in on http://127.0.0.1:8000.
// Returns the milestones (seconds since the video started) that demo/assemble.py cuts on,
// and the spotlights (an element and its label) that demo/assemble.swift draws over the video.
async (page) => {
    const BASE = 'http://127.0.0.1:8000';
    const W = 1600, H = 900;

    // The cards animate in: each block slides up after the previous one.
    const card = (inner) => `<!doctype html><html lang="en"><head><meta charset="utf-8"><style>
        html,body{margin:0;height:100%;font-family:-apple-system,"SF Pro Display","Helvetica Neue",sans-serif;color:#fff}
        body{display:flex;align-items:center;justify-content:center;background:radial-gradient(1200px 700px at 20% 10%,#4338ca 0%,#1e1b4b 55%,#0b0a1f 100%)}
        .wrap{max-width:1180px;padding:0 80px}
        .wrap>*,.grid>*{opacity:0;animation:in .55s cubic-bezier(.2,.7,.2,1) forwards}
        .wrap>:nth-child(2){animation-delay:.12s}.wrap>:nth-child(3){animation-delay:.24s}.wrap>:nth-child(4){animation-delay:.36s}
        .grid>:nth-child(1){animation-delay:.5s}.grid>:nth-child(2){animation-delay:.65s}.grid>:nth-child(3){animation-delay:.8s}.grid>:nth-child(4){animation-delay:.95s}
        @keyframes in{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}
        .kicker{font-size:22px;letter-spacing:.28em;text-transform:uppercase;color:#a5b4fc}
        h1{font-size:80px;line-height:1.02;margin:.25em 0 .2em;font-weight:750;letter-spacing:-.02em}
        p{font-size:30px;line-height:1.45;color:#c7d2fe;margin:0}
        .grid{display:grid;grid-template-columns:repeat(var(--n,3),1fr);gap:18px;margin-top:44px}
        .grid>div{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:18px;padding:22px 24px}
        .grid b{display:block;font-size:25px;margin-bottom:8px}.grid span{font-size:18px;color:#c7d2fe;line-height:1.4}
        .scale{font-size:96px}
    </style></head><body><div class="wrap">${inner}</div></body></html>`;

    // Each card is held as long as demo/assemble.py shows it.
    const CARDS = {
        title: [3000, card(`<div class="scale">⚖️</div><div class="kicker">Cassius</div>
            <h1>Your legal documents,<br>drafted with AI.</h1>
            <p>Formal notices · writs of summons · pleadings · legal opinions · petitions</p>`)],
        problem: [3500, card(`<div class="kicker">For young and independent lawyers</div>
            <h1>Hours of work<br>for every document.</h1>
            <p>Big firms have teams for that. A lawyer on their own doesn't.<br>Cassius: lower cost, much faster.</p>`)],
        workflow: [4500, card(`<div class="kicker">Not a chatbot</div>
            <h1>A complete workflow,<br>guided by several AIs.</h1>
            <div class="grid" style="--n:4">
                <div><b>1. Qualify</b><span>Questions in a form</span></div>
                <div><b>2. Search & sort</b><span>Judilibre queried, Jev sorts</span></div>
                <div><b>3. Select</b><span>Principles and rulings, pinned</span></div>
                <div><b>4. Write</b><span>A checked document, in Word</span></div>
            </div>`)],
        symfony: [6000, card(`<div class="kicker">Under the hood</div><h1>Built with Symfony.</h1>
            <div class="grid">
                <div><b>Symfony AI</b><span>Several models together: Claude Opus reasons and writes, Jev sorts. Every tool, a PHP class.</span></div>
                <div><b>AG-UI, in PHP</b><span>The protocol, implemented and plugged into Symfony AI: messages, tools, state, live.</span></div>
                <div><b>Symfony UX</b><span>Twig and Live Components, Stimulus, Turbo: a dynamic UI, no JS framework.</span></div>
            </div>`)],
        end: [4000, card(`<div class="scale">⚖️</div><div class="kicker">Symfony, essential</div>
            <h1>Case law, sorted,<br>understood, ready to plead.</h1>
            <p>Symfony AI · AG-UI · Symfony UX · Doctrine · Security · Lock · Validator</p>`)],
    };

    const context = await page.context().browser().newContext({
        viewport: { width: W, height: H },
        recordVideo: { dir: 'demo/output/raw', size: { width: W, height: H } },
        storageState: await page.context().storageState(),
        acceptDownloads: true,
        locale: 'en-US',
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
    const t0 = Date.now();
    const marks = [];
    const mark = (id) => marks.push({ id, t: (Date.now() - t0) / 1000 });
    // A spotlight: the element's box and a label, drawn by demo/assemble.swift.
    const spots = [];
    let failure = null;
    try {
        const pause = (ms) => p.waitForTimeout(ms);
        let mouse = { x: W / 2, y: H / 2 };
        // Scrolling an element into view may scroll the window too: it is put back.
        const unscroll = () => p.evaluate(() => { document.scrollingElement.scrollTop = 0; });
        const moveTo = async (locator) => {
            await locator.scrollIntoViewIfNeeded();
            await unscroll();
            const box = await locator.boundingBox();
            const x = box.x + box.width / 2, y = box.y + box.height / 2;
            await p.mouse.move(x, y, { steps: 18 });
            mouse = { x, y };
        };
        // Where the element is now, without scrolling: the window itself never scrolls.
        const spot = async (locator, label) => {
            await unscroll();
            const box = await locator.boundingBox();
            if (box) spots.push({ t: (Date.now() - t0) / 1000, label, box: { x: box.x, y: box.y, w: box.width, h: box.height } });
        };
        const showCard = async (id) => { await p.setContent(CARDS[id][1]); mark(id); await pause(CARDS[id][0] + 300); };
        const click = async (locator) => { await moveTo(locator); await pause(250); await locator.click(); await pause(350); };
        const scrollEl = async (selector, dy, steps = 30) => {
            for (let i = 0; i < steps; i++) {
                await p.evaluate(([s, d]) => { const e = document.querySelector(s); if (e) e.scrollTop += d; }, [selector, dy / steps]);
                await pause(40);
            }
        };
        const MESSAGES = '[data-agent-chat-target="messages"]';

        // — Title cards —
        for (const id of ['title', 'problem', 'workflow']) await showCard(id);

        // — A new dossier —
        await p.goto(BASE + '/'); mark('start'); await pause(1500);
        await click(p.getByText('New case', { exact: false }).first());
        await p.waitForSelector('textarea[data-agent-chat-target="input"]');
        await pause(800);
        const input = p.locator('textarea[data-agent-chat-target="input"]');
        await click(input);
        await input.pressSequentially("My client, a team leader at a logistics SME for 12 years, was dismissed after 8 months of sick leave. The employer cites the disruption of the company, but he was only replaced by temps. Can he challenge it?", { delay: 12 });
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
                    await question.locator('textarea').pressSequentially("Road transport collective agreement, 45 employees, supervisor. A temp in the job from the 2nd month of leave; no permanent hire 3 months later.", { delay: 6 });
                }
            }
            await pause(500);
            await click(form.locator('[data-questions-target="submit"]'));
        };
        const forms = () => p.locator('[data-controller="questions"]').count();
        await p.waitForSelector('[data-controller="questions"] [data-questions-target="submit"]', { timeout: 240000 });
        await pause(1200);
        await spot(p.locator('[data-controller="questions"]').last(), 'A form, generated by the agent');
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
        await spot(plan, 'Editable brief and axes');
        await click(plan.getByText('Add an axis'));
        const added = plan.locator('[data-research-plan-target="axis"]').last();
        await added.locator('[data-role="title"]').pressSequentially('Notice pay for an employee on sick leave', { delay: 18 });
        await pause(800);
        await spot(added, 'An axis added by the lawyer');
        await click(plan.locator('[data-research-plan-target="approve"]'));
        mark('validated');

        // — Search, triage, principles —
        await p.waitForSelector('[data-controller="findings"]', { timeout: 900000 });
        await pause(1500);
        mark('findings');
        const findings = p.locator('[data-controller="findings"]').last();
        await findings.evaluate((e) => e.scrollIntoView({ block: 'start' }));
        await pause(1500);
        await spot(findings.locator('[data-pin-principle]').first(), 'Pin a principle');
        await click(findings.locator('[data-pin-principle]').first());
        await click(findings.locator('[data-pin-decision]').nth(0));
        await scrollEl(MESSAGES, 260, 25);
        await click(findings.locator('[data-pin-decision]').nth(1));
        await scrollEl(MESSAGES, 700, 50);
        const excludes = findings.locator('[data-exclude-decision]');
        await spot(excludes.nth(Math.min(5, (await excludes.count()) - 1)), 'Discard a ruling');
        await click(excludes.nth(Math.min(5, (await excludes.count()) - 1)));
        await pause(800);
        await findings.locator('[data-findings-target="instruction"]').scrollIntoViewIfNeeded();
        await click(findings.locator('[data-findings-target="instruction"]'));
        await findings.locator('[data-findings-target="instruction"]').pressSequentially("Stress the lack of a permanent replacement.", { delay: 15 });
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
        await spot(p.locator('#section-faits'), 'The opinion being written');
        mark('redaction');
        await p.waitForFunction(() => document.querySelector('[aria-current=step]')?.innerText.includes('Delivered'), null, { timeout: 900000 });
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
        await spot(p.getByText('Export to Word'), 'Word export');
        const download = p.waitForEvent('download');
        await click(p.getByText('Export to Word'));
        await (await download).saveAs('demo/output/consultation.docx');
        await pause(2000);
        mark('exported');

        // — Closing cards —
        for (const id of ['symfony', 'end']) await showCard(id);
        mark('stop');

    } catch (e) {
        failure = String(e).slice(0, 500);
    }

    const video = p.video();
    const dossierUrl = p.url();
    await context.close();

    return { video: await video.path(), marks, spots, dossierUrl, failure };
}
