/**
 * Wires the bulk-select UI on the Bibliothek tab. Tracks the checked
 * state of `[data-bulk-row]` checkboxes scattered across the table,
 * syncs the `[data-bulk-select-all]` header checkbox, and on submit
 * appends hidden inputs to whichever of the two hidden forms matches
 * the action — `[data-bulk-form-adopt]` for unadopted rows,
 * `[data-bulk-form-unadopt]` for adopted rows.
 *
 * Two separate buttons (Adopt N / Unadopt M) each enabled by their
 * own count keep the model simple: each toolbar button is responsible
 * for one half of the selection regardless of what else is checked.
 * The corresponding form is the SINGLE source of truth for that
 * action — no client-side mixing across forms in one submit.
 *
 * The forms have to live outside the table because the per-row
 * unadopt forms inside action cells would otherwise be nested, which
 * HTML disallows.
 */
class LibraryBulkSelectHandler {
  initialize() {
    document.addEventListener('change', this.onChange);
    document.addEventListener('click', this.onClick);
    // Snapshot the empty-state labels rendered by Fluid on page load.
    // sync() resets the visible label to these whenever the matching
    // count drops to 0; without snapshots the button keeps showing "N
    // …" forever once a count was ever displayed.
    const adoptLabel = document.querySelector('[data-bulk-submit-adopt-label]');
    const unadoptLabel = document.querySelector('[data-bulk-submit-unadopt-label]');
    this.emptyAdoptLabel = adoptLabel?.textContent?.trim() ?? '';
    this.emptyUnadoptLabel = unadoptLabel?.textContent?.trim() ?? '';
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
      ? event.target.closest('[data-bulk-clear], [data-bulk-submit-adopt], [data-bulk-submit-unadopt]')
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
    if (target.hasAttribute('data-bulk-submit-adopt')) {
      event.preventDefault();
      this.submit('adopt');
      return;
    }
    if (target.hasAttribute('data-bulk-submit-unadopt')) {
      event.preventDefault();
      this.submit('unadopt');
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

  /**
   * @returns {{ adopt: HTMLInputElement[], unadopt: HTMLInputElement[] }}
   */
  splitChecked() {
    const rows = [...document.querySelectorAll('[data-bulk-row]')]
      .filter((el) => el instanceof HTMLInputElement && el.checked);
    const adopt = [];
    const unadopt = [];
    rows.forEach((row) => {
      if (row.getAttribute('data-adopted') === '1') {
        unadopt.push(row);
      } else {
        adopt.push(row);
      }
    });
    return { adopt, unadopt };
  }

  sync() {
    const rows = [...document.querySelectorAll('[data-bulk-row]')]
      .filter((el) => el instanceof HTMLInputElement);
    const totalRows = rows.length;
    const checkedCount = rows.filter((el) => el.checked).length;
    const { adopt, unadopt } = this.splitChecked();

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

    this.updateButton('adopt', adopt.length, this.emptyAdoptLabel);
    this.updateButton('unadopt', unadopt.length, this.emptyUnadoptLabel);

    const clear = document.querySelector('[data-bulk-clear]');
    if (clear instanceof HTMLButtonElement) {
      clear.disabled = checkedCount === 0;
    }
  }

  /**
   * @param {'adopt'|'unadopt'} action
   * @param {number} count
   * @param {string} emptyLabel
   */
  updateButton(action, count, emptyLabel) {
    const submit = document.querySelector(`[data-bulk-submit-${action}]`);
    const submitLabel = document.querySelector(`[data-bulk-submit-${action}-label]`);
    if (!(submit instanceof HTMLButtonElement) || !(submitLabel instanceof HTMLElement)) {
      return;
    }
    submit.disabled = count === 0;
    const template = submit.getAttribute('data-label-template') || '';
    if (count > 0 && template) {
      submitLabel.textContent = template.replace('%d', String(count));
    } else {
      submitLabel.textContent = emptyLabel;
    }
  }

  /**
   * @param {'adopt'|'unadopt'} action
   */
  submit(action) {
    const formSelector = action === 'adopt'
      ? '[data-bulk-form-adopt]'
      : '[data-bulk-form-unadopt]';
    const form = document.querySelector(formSelector);
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    // Wipe any leftover hidden inputs from a previous failed submit.
    form.querySelectorAll('input[type="hidden"][name="serviceIds[]"]').forEach((el) => el.remove());

    const { adopt, unadopt } = this.splitChecked();
    const rows = action === 'adopt' ? adopt : unadopt;
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
