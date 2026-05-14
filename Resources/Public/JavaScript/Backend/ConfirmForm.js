class ConfirmFormHandler {
  initialize() {
    document.addEventListener('submit', this.onSubmit, true);
  }

  onSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    const message = form.getAttribute('data-confirm-message');
    if (!message) {
      return;
    }
    if (!window.confirm(message)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }
}

new ConfirmFormHandler().initialize();

export default ConfirmFormHandler;
