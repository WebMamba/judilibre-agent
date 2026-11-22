import { Controller } from '@hotwired/stimulus';

/**
 * The choice under the principles of a round: what the user wants to dig into goes with
 * either choice, as the `instruction` param ag-ui#resume sends.
 */
export default class extends Controller {
    static targets = ['instruction', 'choice'];

    write() {
        const instruction = this.instructionTarget.value.trim();
        this.choiceTargets.forEach((button) => { button.dataset.agUiInstructionParam = instruction; });
    }
}
