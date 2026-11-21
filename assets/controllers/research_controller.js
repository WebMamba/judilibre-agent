import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/**
 * The browser's side of the dossier (ADR 0002). The server is the source of truth: the
 * page brings the saved state, conversation and open interrupts, which this controller
 * hands to the ag-ui client once it connects. The browser only changes the user's part
 * of the state — filters, pinned decisions and principles, exclusions — which
 * ag-ui#setState sends with the next run.
 *
 * Every state change, whoever made it, is shown in the Live Components (stepper, brief,
 * document, dossier, filters) and on the pin and exclude buttons of the cards.
 */
export default class extends Controller {
    static targets = ['document', 'title'];
    static values = { state: Object, messages: Array, interrupts: Array, url: String, token: String };

    connect() {
        this.state = this.stateValue;
    }

    /** ag-ui:connect — restore the dossier into the client. */
    connected({ detail: { client } }) {
        this.client = client;
        client.messages = structuredClone(this.messagesValue);
        client.interrupts = structuredClone(this.interruptsValue);
        this.publish();
        this.syncButtons();
    }

    /** ag-ui:run-started — the dossier exists from its first run on: show its URL. */
    runStarted() {
        if (window.location.pathname !== this.urlValue) window.history.replaceState({}, '', this.urlValue);
    }

    /** ag-ui:state-changed — from the agent (STATE_DELTA) or from publish(). */
    stateChanged({ detail: { state } }) {
        this.state = state;
        this.render();
    }

    /** research:filters-changed — the user edited the filters panel. */
    filtersChanged({ detail: { filters } }) {
        if (JSON.stringify(filters) === JSON.stringify(this.state.filters)) return;
        this.update({ filters });
    }

    /** A decision card's pin button: pins, or unpins. */
    toggleDecision({ params: { id, title, note } }) {
        const key = String(id);
        const dossier = this.state.dossier ?? [];
        this.update({
            dossier: dossier.some((d) => d.id === key)
                ? dossier.filter((d) => d.id !== key)
                : [...dossier, { id: key, title: String(title), note: String(note ?? '') }],
            excluded: (this.state.excluded ?? []).filter((e) => e !== key),
        });
    }

    /** The dossier panel's unpin buttons. */
    unpin({ params: { id } }) {
        this.update({ dossier: (this.state.dossier ?? []).filter((d) => d.id !== String(id)) });
    }

    /** A principle's pin button: "I want this argument". */
    togglePrinciple({ params: { id } }) {
        const key = String(id);
        const pinned = this.state.pinnedPrinciples ?? [];
        this.update({ pinnedPrinciples: pinned.includes(key) ? pinned.filter((p) => p !== key) : [...pinned, key] });
    }

    /** A decision card's exclude button: "not relevant, never show it again". */
    toggleExclusion({ params: { id } }) {
        const key = String(id);
        const excluded = this.state.excluded ?? [];
        this.update({
            excluded: excluded.includes(key) ? excluded.filter((e) => e !== key) : [...excluded, key],
            dossier: (this.state.dossier ?? []).filter((d) => d.id !== key),
        });
    }

    /** The Document view: undo the last rewrite of a section. */
    async undoSection({ params: { key } }) {
        await this.post(`${this.urlValue}/sections/${encodeURIComponent(key)}/undo`);
    }

    /** The Document view: back to the research; the consultation is kept, to update. */
    async resumeResearch() {
        await this.post(`${this.urlValue}/resume-research`);
    }

    /** Changes made on the server outside a run come back as the new state. */
    async post(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ _token: this.tokenValue }),
        });
        if (!response.ok) {
            window.alert(response.status === 409 ? 'Un run est en cours sur ce dossier : réessayez dans un instant.' : 'Action impossible.');
            return;
        }
        const { state } = await response.json();
        this.state = state;
        this.publish();
    }

    update(changes) {
        this.state = { ...this.state, ...changes };
        this.publish();
    }

    publish() {
        this.dispatch('state', { detail: this.state });
    }

    async render() {
        const state = this.state;
        if (this.hasDocumentTarget) this.documentTarget.classList.toggle('hidden', !state.consultation);
        if (this.hasDocumentTarget) this.documentTarget.classList.toggle('flex', Boolean(state.consultation));
        if (this.hasTitleTarget && state.title) this.titleTarget.textContent = state.title;
        this.syncButtons();

        await Promise.all([
            this.set('phase-stepper', { phase: state.phase ?? 'qualification' }),
            this.set('brief-panel', { brief: state.brief ?? null, axes: state.plan?.axes ?? [] }),
            this.set('dossier-panel', { dossier: state.dossier ?? [] }),
            this.set('filters-panel', { filters: state.filters }),
            this.set('consultation-panel', { consultation: state.consultation ?? null, citations: this.citations(), phase: state.phase ?? 'qualification' }),
        ]);
    }

    /** The pin and exclude buttons of every card say what the state holds. */
    syncButtons() {
        const pinned = new Set((this.state.dossier ?? []).map((d) => d.id));
        const principles = new Set(this.state.pinnedPrinciples ?? []);
        const excluded = new Set(this.state.excluded ?? []);

        this.element.querySelectorAll('[data-pin-decision]').forEach((button) => {
            this.mark(button, pinned.has(button.dataset.pinDecision), '📌 Épingler', '✓ Épinglée');
        });
        this.element.querySelectorAll('[data-pin-principle]').forEach((button) => {
            this.mark(button, principles.has(button.dataset.pinPrinciple), '📌 Retenir', '✓ Retenu');
        });
        this.element.querySelectorAll('[data-exclude-decision]').forEach((button) => {
            const on = excluded.has(button.dataset.excludeDecision);
            this.mark(button, on, '🚫 Écarter', '↺ Rétablir');
            button.closest('[data-decision]')?.classList.toggle('excluded', on);
        });
    }

    mark(button, on, off, active) {
        button.classList.toggle('is-on', on);
        button.textContent = on ? active : off;
    }

    /** id => citation, of every decision the dossier knows. */
    citations() {
        const citations = {};
        (this.state.retained ?? []).forEach((d) => { citations[d.id] = d.citation; });
        (this.state.dossier ?? []).forEach((d) => { citations[d.id] = d.title; });
        return citations;
    }

    async set(elementId, props) {
        const element = document.getElementById(elementId);
        if (!element) return;
        const component = await getComponent(element);
        let changed = false;
        for (const [prop, value] of Object.entries(props)) {
            if (JSON.stringify(component.valueStore.get(prop)) === JSON.stringify(value)) continue;
            component.set(prop, value, false);
            changed = true;
        }
        if (changed) await component.render();
    }
}
