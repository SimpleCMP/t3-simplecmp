/**
 * Selection management for the detection list:
 *
 * - The header `[data-bulk-select-all]` checkbox toggles every
 *   `[data-bulk-select-row]` inside the same table.
 * - The `[data-bulk-select-trigger]` button stays disabled while no
 *   rows are selected; enables and shows the count in
 *   `[data-bulk-select-count]` when at least one is.
 *
 * The trigger button submits the wrapping form (via the HTML5
 * `form` attribute), and ConfirmForm.js intercepts that submit to
 * show the confirm dialog. This file only deals with selection
 * state.
 */
class BulkSelect {
  initialize() {
    document.addEventListener('change', this.onChange, true);
    this.syncTrigger();
  }

  onChange(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) {
      return;
    }
    if (target.matches('[data-bulk-select-all]')) {
      const table = target.closest('table');
      if (!table) {
        return;
      }
      table.querySelectorAll('input[type="checkbox"][data-bulk-select-row]').forEach((cb) => {
        cb.checked = target.checked;
      });
      BulkSelect.syncTrigger();
      return;
    }
    if (target.matches('[data-bulk-select-row]')) {
      BulkSelect.syncTrigger();
    }
  }

  syncTrigger() {
    BulkSelect.syncTrigger();
  }

  static syncTrigger() {
    document.querySelectorAll('[data-bulk-select-trigger]').forEach((trigger) => {
      const formId = trigger.getAttribute('form');
      const form = formId ? document.getElementById(formId) : trigger.closest('form');
      if (!form) {
        return;
      }
      const checked = form.querySelectorAll('[data-bulk-select-row]:checked').length;
      trigger.disabled = checked === 0;
      const counter = trigger.querySelector('[data-bulk-select-count]');
      if (counter) {
        counter.textContent = String(checked);
      }
    });
  }
}

new BulkSelect().initialize();

export default BulkSelect;
