import { Controller } from '@hotwired/stimulus';

/**
 * The form of an ask_questions interrupt: keeps the answers in the button's params, which
 * ag-ui#resume sends as the payload. Stimulus reads action params when the action fires.
 */
export default class extends Controller {
    static targets = ['question', 'comment', 'submit'];

    collect() {
        const answers = {};
        this.questionTargets.forEach((fieldset) => {
            const { id, type } = fieldset.dataset;
            const text = fieldset.querySelector('textarea');
            if (text) {
                answers[id] = text.value.trim();
            } else if (type === 'multiple') {
                answers[id] = Array.from(fieldset.querySelectorAll('input:checked'), (input) => input.value);
            } else {
                answers[id] = fieldset.querySelector('input:checked')?.value ?? '';
            }
        });
        this.submitTarget.dataset.agUiAnswersParam = JSON.stringify(answers);
        this.submitTarget.dataset.agUiCommentParam = this.commentTarget.value.trim();
    }
}
