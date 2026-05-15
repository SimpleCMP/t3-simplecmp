class ConfirmFormHandler {
  initialize() {
    document.addEventListener('submit', this.onSubmit, true);
    document.addEventListener('click', this.onClick, true);
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

  onClick(event) {
    const target = event.target instanceof Element
      ? event.target.closest('a[data-confirm-message]')
      : null;
    if (!target) {
      return;
    }
    const message = target.getAttribute('data-confirm-message');
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
