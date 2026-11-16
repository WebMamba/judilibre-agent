import { Controller } from '@hotwired/stimulus';

/**
 * The "Modifier" choice of a search plan: reveals the instruction field, and copies what
 * the user writes into the feedback param that ag-ui#resume sends (ADR 0003).
 * Stimulus reads action params when the action fires, so the attribute is enough.
 */
export default class extends Controller {
    static targets = ['choices', 'instruction', 'feedback', 'send'];

    edit() {
        this.choicesTarget.classList.add('hidden');
        this.instructionTarget.classList.remove('hidden');
        this.feedbackTarget.focus();
    }

    write() {
        const feedback = this.feedbackTarget.value.trim();
        this.sendTarget.dataset.agUiFeedbackParam = feedback;
        this.sendTarget.disabled = feedback === '';
    }
}
