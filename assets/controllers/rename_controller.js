import { Controller } from '@hotwired/stimulus';

/** A dossier of the list: reveals the rename form. */
export default class extends Controller {
    static targets = ['link', 'form', 'button'];

    edit() {
        this.linkTarget.classList.add('hidden');
        this.buttonTarget.classList.add('hidden');
        this.formTarget.classList.replace('hidden', 'flex');
        this.formTarget.querySelector('input[name="title"]').select();
    }
}
