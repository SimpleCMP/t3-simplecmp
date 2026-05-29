/**
 * Wires the bulk-select UI on the Bibliothek tab. Tracks the checked
 * state of `[data-bulk-row]` checkboxes scattered across the table,
 * syncs the `[data-bulk-select-all]` header checkbox, and on submit
 * builds hidden inputs from the checked rows and appends them to the
 * hidden `[data-bulk-form]` form before submitting.
 *
 * The bulk form has to live outside the table because the per-row
 * unadopt forms inside the action cells would otherwise be nested,
 * which HTML disallows. Gathering checked rows at submit time is the
 * common workaround in TYPO3 BE list views with mixed-action rows.
 */
class LibraryBulkSelectHandler {
  initialize() {
    document.addEventListener('change', this.onChange);
    document.addEventListener('click', this.onClick);
    // Snapshot the empty-state label rendered by Fluid on page load.
    // sync() resets the visible label to this whenever count drops to
    // 0; without the snapshot the button would keep showing "N
    // ausgewählte übernehmen" forever once a count was ever displayed.
    const submitLabel = document.querySelector('[data-bulk-submit-label]');
    this.emptyLabel = submitLabel?.textContent?.trim() ?? '';
    // Run once on load so the toolbar state reflects whatever checkbox
    // state the browser restored (e.g. after back-navigation).
    this.sync();
  }

  onChange = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) {
      return;
    }
    if (target.hasAttribute('data-bulk-select-all')) {
      this.toggleAll(target.checked);
      this.sync();
      return;
    }
    if (target.hasAttribute('data-bulk-row')) {
      this.sync();
    }
  };

  onClick = (event) => {
    const target = event.target instanceof Element
      ? event.target.closest('[data-bulk-clear], [data-bulk-submit]')
      : null;
    if (!target) {
      return;
    }
    if (target.hasAttribute('data-bulk-clear')) {
      event.preventDefault();
      this.toggleAll(false);
      this.sync();
      return;
    }
    if (target.hasAttribute('data-bulk-submit')) {
      event.preventDefault();
      this.submit();
    }
  };

  toggleAll(checked) {
    const rows = document.querySelectorAll('[data-bulk-row]');
    rows.forEach((row) => {
      if (row instanceof HTMLInputElement) {
        row.checked = checked;
      }
    });
  }

  sync() {
    const rows = [...document.querySelectorAll('[data-bulk-row]')]
      .filter((el) => el instanceof HTMLInputElement);
    const totalRows = rows.length;
    const checkedCount = rows.filter((el) => el.checked).length;

    // Select-all checkbox tri-state: checked when all checked, unchecked
    // when none, indeterminate in between.
    const selectAll = document.querySelector('[data-bulk-select-all]');
    if (selectAll instanceof HTMLInputElement) {
      if (checkedCount === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
      } else if (checkedCount === totalRows && totalRows > 0) {
        selectAll.checked = true;
        selectAll.indeterminate = false;
      } else {
        selectAll.checked = false;
        selectAll.indeterminate = true;
      }
    }

    const submit = document.querySelector('[data-bulk-submit]');
    const clear = document.querySelector('[data-bulk-clear]');
    const submitLabel = document.querySelector('[data-bulk-submit-label]');
    if (submit instanceof HTMLButtonElement && submitLabel instanceof HTMLElement) {
      const template = submit.getAttribute('data-label-template') || '';
      submit.disabled = checkedCount === 0;
      if (checkedCount > 0 && template) {
        submitLabel.textContent = template.replace('%d', String(checkedCount));
      } else {
        submitLabel.textContent = this.emptyLabel;
      }
    }
    if (clear instanceof HTMLButtonElement) {
      clear.disabled = checkedCount === 0;
    }
  }

  submit() {
    const form = document.querySelector('[data-bulk-form]');
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    // Wipe any leftover hidden inputs from a previous failed submit.
    form.querySelectorAll('input[type="hidden"][name="serviceIds[]"]').forEach((el) => el.remove());

    const rows = [...document.querySelectorAll('[data-bulk-row]')]
      .filter((el) => el instanceof HTMLInputElement && el.checked);
    if (rows.length === 0) {
      return;
    }
    rows.forEach((row) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'serviceIds[]';
      input.value = row.value;
      form.appendChild(input);
    });
    form.submit();
  }
}

const handler = new LibraryBulkSelectHandler();
handler.initialize();
export default handler;
