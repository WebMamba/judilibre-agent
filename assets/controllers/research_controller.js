import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/**
 * The browser's side of the shared research state (ADR 0002): filters, approved plan
 * and dossier.
 *
 *  - it restores filters and dossier from localStorage, and hands them to the ag-ui
 *    client once it connects (the plan belongs to a conversation: it is not restored)
 *  - every state change, whoever made it, is saved and shown in the two Live Components
 *  - the user's edits (filters panel, pin and unpin buttons) become the new state with
 *    research:state, which ag-ui#setState sends with the next run
 */
export default class extends Controller {
    static values = { storageKey: { type: String, default: 'judilibre-agent.research' } };

    connect() {
        this.state = this.restore();
    }

    /** ag-ui:connect — the client is ready: give it the restored state. */
    connected() {
        this.publish();
    }

    /** ag-ui:state-changed — from the agent (STATE_DELTA) or from publish(). */
    stateChanged({ detail: { state } }) {
        this.state = this.constructor.normalize(state);
        this.save();
        this.render();
    }

    /** research:filters-changed — the user edited the filters panel. */
    filtersChanged({ detail: { filters } }) {
        if (JSON.stringify(filters) === JSON.stringify(this.state.filters)) return;
        this.state = { ...this.state, filters };
        this.publish();
    }

    /** A card's pin button. */
    pin({ params: { id, title, note }, currentTarget }) {
        const key = String(id);
        if (!this.state.dossier.some((decision) => decision.id === key)) {
            this.state = { ...this.state, dossier: [...this.state.dossier, { id: key, title: String(title), note: String(note ?? '') }] };
            this.publish();
        }
        currentTarget.disabled = true;
        currentTarget.textContent = '✓ Épinglée';
    }

    /** The dossier's unpin buttons. */
    unpin({ params: { id } }) {
        this.state = { ...this.state, dossier: this.state.dossier.filter((decision) => decision.id !== String(id)) };
        this.publish();
    }

    /** "Nouvelle recherche": a new conversation, same filters and dossier. */
    restart() {
        this.state = { ...this.state, plan: null };
        this.save();
        window.location.reload();
    }

    publish() {
        this.dispatch('state', { detail: this.state });
    }

    async render() {
        await Promise.all([
            this.update('filters-panel', 'filters', this.state.filters),
            this.update('dossier-panel', 'dossier', this.state.dossier),
        ]);
    }

    async update(elementId, prop, value) {
        const element = document.getElementById(elementId);
        if (!element) return;
        const component = await getComponent(element);
        if (JSON.stringify(component.valueStore.get(prop)) === JSON.stringify(value)) return;
        component.set(prop, value, true);
    }

    restore() {
        try {
            const saved = JSON.parse(window.localStorage.getItem(this.storageKeyValue) ?? 'null');
            return this.constructor.normalize({ ...saved, plan: null });
        } catch {
            return this.constructor.normalize(null);
        }
    }

    save() {
        try {
            window.localStorage.setItem(this.storageKeyValue, JSON.stringify(this.state));
        } catch {
            // Storage full or disabled: the state still lives for this page.
        }
    }

    /** The shape ResearchState expects, whatever was stored. */
    static normalize(state) {
        const filters = state?.filters ?? {};
        const list = (value) => (Array.isArray(value) ? value.map(String) : []);
        const jurisdictions = list(filters.jurisdictions);

        return {
            filters: {
                jurisdictions: jurisdictions.length > 0 ? jurisdictions : ['cc'],
                chambers: list(filters.chambers),
                locations: list(filters.locations),
                publications: list(filters.publications),
                dateStart: filters.dateStart || null,
                dateEnd: filters.dateEnd || null,
            },
            plan: state?.plan ?? null,
            dossier: Array.isArray(state?.dossier)
                ? state.dossier.filter((d) => d?.id && d?.title).map((d) => ({ id: String(d.id), title: String(d.title), note: String(d.note ?? '') }))
                : [],
        };
    }
}
