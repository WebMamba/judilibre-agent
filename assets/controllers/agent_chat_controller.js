import { Controller } from '@hotwired/stimulus';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

/**
 * The conversation: renders what the ag-ui controller (the AG-UI client of
 * webmamba/ag-ui-bundle) dispatches as `ag-ui:*` DOM events. Adapted from
 * WebMamba/demo-ag-ui.
 *
 *  - text        → streamed into the assistant's turn, as sanitized markdown
 *  - tool calls  → a readable line ("🔎 Recherche « … » — 12 décisions"), which is also
 *                  the call's activity slot: ag-ui-activities puts the server-rendered
 *                  component (search plan, decision cards) in its place
 *  - interrupts  → the input is locked until the plan is answered
 */
export default class extends Controller {
    static targets = ['messages', 'input', 'submit', 'placeholder', 'userTemplate', 'assistantTemplate'];
    static values = { waiting: String, interrupted: Boolean };

    connect() {
        this.texts = new Map();     // messageId → { el, markdown, frame }
        this.toolCalls = new Map(); // toolCallId → { line, label, name, args }
        // A reopened dossier may wait on a form of the agent: answer it first.
        this.waiting = this.interruptedValue;
        this.defaultPlaceholder = this.inputTarget.placeholder;
        this.setBusy(false);
        this.scrollToBottom();
    }

    /** Enter without Shift submits; Shift+Enter inserts a newline. */
    maybeSend(event) {
        if (event.shiftKey || event.isComposing) return;
        event.preventDefault();
        event.target.form.requestSubmit();
    }

    /** Before ag-ui#send takes the input: the user's message, and an empty turn. */
    send() {
        const text = this.inputTarget.value.trim();
        if (text === '') return;
        this.hidePlaceholder();
        this.appendUserMessage(text);
        this.turn = this.appendAssistantTurn();
    }

    /** A button answering an interrupt: lock the answering controls, show the choice. */
    chose(event) {
        const component = event.currentTarget.closest('[data-ag-ui-interrupt-id], [data-interrupt]');
        // The fields and the answering buttons; pin and exclude buttons stay usable.
        component?.querySelectorAll('input, textarea, [data-interrupt-control] button, button[data-interrupt-control]').forEach((el) => { el.disabled = true; });
        event.currentTarget.classList.add('ring-2', 'ring-offset-2', 'ring-indigo-400');
    }

    // — Lifecycle —

    runStarted() {
        this.waiting = false;
        this.turn ??= this.appendAssistantTurn();
        this.setBusy(true);
    }

    runFinished({ detail: event }) {
        this.waiting = event.outcome?.type === 'interrupt';
    }

    runError({ detail: event }) {
        this.showError(event.message);
    }

    error({ detail: { message } }) {
        this.showError(message);
    }

    /** ag-ui:idle — the turn is over, or waits for the user's answer to the plan. */
    idle() {
        this.turn?.querySelectorAll('.streaming-cursor').forEach((el) => el.classList.remove('streaming-cursor'));
        this.setBusy(false);
        if (!this.waiting) {
            this.turn = null;
            this.inputTarget.focus();
        }
    }

    // — Text —

    textStart({ detail: { messageId } }) {
        if (this.texts.has(messageId)) return;
        this.texts.set(messageId, { el: this.append('div', 'assistant-text streaming-cursor my-2'), markdown: '', frame: null });
    }

    textContent({ detail: { messageId, delta } }) {
        const text = this.texts.get(messageId);
        if (!text) return;
        text.markdown += delta;
        // Re-render at most once a frame: "**gras" only becomes bold once its "**" arrives.
        text.frame ??= requestAnimationFrame(() => {
            text.frame = null;
            this.renderMarkdown(text);
        });
    }

    textEnd({ detail: { messageId } }) {
        const text = this.texts.get(messageId);
        if (!text) return;
        if (text.frame) cancelAnimationFrame(text.frame);
        text.frame = null;
        this.renderMarkdown(text);
        text.el.classList.remove('streaming-cursor');
    }

    // — Tool calls —

    /** The line is the call's activity slot too: a component the call renders takes its place. */
    toolCallStart({ detail: { toolCallId, toolCallName } }) {
        const slot = this.append('div', '');
        slot.dataset.agUiActivity = `act_${toolCallId}`;

        const line = document.createElement('div');
        line.className = 'tool-call my-1 flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400';
        const dot = document.createElement('span');
        dot.className = 'inline-block h-2 w-2 shrink-0 rounded-full bg-indigo-400 animate-pulse';
        const label = document.createElement('span');
        line.append(dot, label);
        slot.append(line);

        const call = { line, dot, label, name: toolCallName, args: '' };
        label.textContent = this.constructor.describe(call.name, {});
        this.toolCalls.set(toolCallId, call);
        this.scrollToBottom();
    }

    toolCallArgs({ detail: { toolCallId, delta } }) {
        const call = this.toolCalls.get(toolCallId);
        if (call) call.args += delta;
    }

    toolCallEnd({ detail: { toolCallId } }) {
        const call = this.toolCalls.get(toolCallId);
        if (!call) return;
        call.label.textContent = this.constructor.describe(call.name, this.constructor.parse(call.args));
        // A component is on its way: the line keeps pulsing until it takes its place.
        if (!this.constructor.COMPONENT_TOOLS.includes(call.name)) call.dot.classList.remove('animate-pulse');
    }

    toolCallResult({ detail: { toolCallId, content } }) {
        const call = this.toolCalls.get(toolCallId);
        if (!call) return;
        const outcome = this.constructor.outcome(call.name, content);
        if (outcome) call.label.textContent += ` — ${outcome}`;
    }

    activityChanged() {
        this.scrollToBottom();
    }

    /** The tools whose result is a component: their line pulses until it arrives. */
    static COMPONENT_TOOLS = ['ask_questions', 'propose_research_plan', 'present_findings'];

    /** What a call does, in words: its line in the conversation. */
    static describe(name, args) {
        switch (name) {
            case 'ask_questions': return '❓ Préparation des questions…';
            case 'propose_research_plan': return '📝 Préparation de la fiche dossier et du plan…';
            case 'search_axis': {
                const queries = (args.queries ?? []).map((q) => `« ${q.query} »`).join(', ');
                return `🔎 Recherche ${args.axisId ? `de l'axe ${args.axisId}` : '…'}${queries ? ` : ${queries}` : ''} — tri par Jev`;
            }
            case 'read_decision': return '📄 Lecture d\'une décision';
            case 'present_findings': return '⚖️ Préparation des principes et des décisions…';
            case 'write_section': return `✍️ Rédaction${args.section ? ` de la section « ${args.section} »` : '…'}`;
            case 'list_taxonomy': return '📚 Consultation des listes Judilibre';
            case 'set_filters': return '🎚️ Mise à jour des filtres';
            default: return `⚙️ ${name}`;
        }
    }

    /** What came of it, from the tool result the model read. */
    static outcome(name, content) {
        const result = this.parse(content);
        if (name === 'search_axis') {
            if (Array.isArray(result.retained)) return `${result.retained.length} décision${result.retained.length > 1 ? 's' : ''} retenue${result.retained.length > 1 ? 's' : ''} sur ${result.candidates ?? 0}`;
            return String(content).slice(0, 160);
        }
        if (name === 'read_decision') return result.citation ?? String(content).slice(0, 140);
        if (name === 'write_section') return String(content).split(/(?<=\.)\s/)[0];
        if (name === 'set_filters') return String(content).split('\n')[0];
        return null;
    }

    static parse(json) {
        try {
            const value = JSON.parse(json);
            return value && typeof value === 'object' ? value : {};
        } catch {
            return {};
        }
    }

    // — Rendering —

    /** The model writes markdown: render it, and never trust its HTML. */
    renderMarkdown(text) {
        text.el.innerHTML = DOMPurify.sanitize(marked.parse(text.markdown, { gfm: true, breaks: true }));
        text.el.querySelectorAll('a').forEach((a) => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
        this.scrollToBottom();
    }

    append(tag, className) {
        this.turn ??= this.appendAssistantTurn();
        const el = document.createElement(tag);
        el.className = className;
        this.turn.appendChild(el);
        return el;
    }

    appendUserMessage(text) {
        const node = this.userTemplateTarget.content.firstElementChild.cloneNode(true);
        node.querySelector('[data-role="text"]').textContent = text;
        this.messagesTarget.appendChild(node);
        this.scrollToBottom();
    }

    /** @returns {HTMLElement} where everything of the assistant's turn is appended */
    appendAssistantTurn() {
        this.hidePlaceholder();
        const node = this.assistantTemplateTarget.content.firstElementChild.cloneNode(true);
        this.messagesTarget.appendChild(node);
        this.scrollToBottom();
        return node.querySelector('[data-role="body"]');
    }

    showError(message) {
        const el = this.append('div', 'my-2 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-950/50 dark:text-rose-300');
        el.textContent = `⚠ ${message}`;
    }

    hidePlaceholder() {
        if (this.hasPlaceholderTarget) this.placeholderTarget.remove();
    }

    setBusy(busy) {
        const locked = busy || this.waiting;
        this.submitTarget.disabled = locked;
        this.inputTarget.disabled = locked;
        this.inputTarget.placeholder = this.waiting ? this.waitingValue : this.defaultPlaceholder;
    }

    scrollToBottom() {
        requestAnimationFrame(() => {
            this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
        });
    }
}
