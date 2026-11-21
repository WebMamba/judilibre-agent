import { Controller } from '@hotwired/stimulus';

/**
 * The plan of a propose_research_plan interrupt: the user edits the case brief, adds,
 * removes or renames axes; the edited plan is kept in the validate button's `plan`
 * param. "Demander un autre plan" reveals the instruction field (ADR 0003).
 */
export default class extends Controller {
    static targets = ['brief', 'axes', 'axis', 'template', 'approve', 'choices', 'instruction', 'feedback', 'send'];

    connect() {
        this.collect();
    }

    add() {
        this.axesTarget.append(this.templateTarget.content.firstElementChild.cloneNode(true));
        this.axisTargets.at(-1)?.querySelector('[data-role="title"]')?.focus();
        this.collect();
    }

    remove(event) {
        event.currentTarget.closest('[data-research-plan-target="axis"]')?.remove();
        this.collect();
    }

    collect() {
        const brief = {};
        this.briefTargets.forEach((field) => { brief[field.dataset.field] = field.value.trim(); });
        const axes = this.axisTargets
            .map((axis) => ({
                title: axis.querySelector('[data-role="title"]').value.trim(),
                objective: axis.querySelector('[data-role="objective"]').value.trim(),
            }))
            .filter((axis) => axis.title !== '');

        this.approveTarget.dataset.agUiPlanParam = JSON.stringify({ brief, axes });
        this.approveTarget.disabled = axes.length === 0;
    }

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
