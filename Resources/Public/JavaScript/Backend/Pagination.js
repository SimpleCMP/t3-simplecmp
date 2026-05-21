/**
 * Auto-submit handling for the detection list's filters + pagination
 * size.
 *
 * - `[data-per-page]` — select that sets `perPage` and clears `page`
 *   (= jump to page 1 of the new size).
 * - `<select data-list-filter="<name>">` — fires on change.
 * - `<input data-list-filter="<name>">` — fires on Enter or on a
 *   400 ms debounce after the last keystroke. Relying on `change`
 *   alone (which only fires on blur for text inputs) made search
 *   feel unresponsive — the user had to tab away to trigger a
 *   filter, and the field never reacted to typing.
 *
 * All flows rewrite `location` directly. A `<form method="get">`
 * would also work, but TYPO3's BE-module token sits in the URL and
 * weaving it back into a form action is more code than this.
 */

const INPUT_DEBOUNCE_MS = 400;

class Pagination {
  constructor() {
    /** @type {Map<HTMLInputElement, number>} */
    this._inputTimers = new Map();
  }

  initialize() {
    document.addEventListener('change', this.onChange);
    document.addEventListener('input', this.onInput);
    document.addEventListener('keydown', this.onKeydown);
  }

  onChange = (event) => {
    const target = event.target;
    if (target instanceof HTMLSelectElement && target.matches('[data-per-page]')) {
      Pagination.navigate(target, { perPage: target.value, page: null });
      return;
    }
    if (target instanceof HTMLSelectElement && target.hasAttribute('data-list-filter')) {
      const filterName = target.getAttribute('data-list-filter');
      Pagination.navigate(target, { [filterName]: target.value || null, page: null });
    }
  };

  onInput = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || !target.hasAttribute('data-list-filter')) {
      return;
    }
    const previous = this._inputTimers.get(target);
    if (previous !== undefined) clearTimeout(previous);
    const handle = setTimeout(() => {
      this._inputTimers.delete(target);
      const filterName = target.getAttribute('data-list-filter');
      Pagination.navigate(target, { [filterName]: target.value || null, page: null });
    }, INPUT_DEBOUNCE_MS);
    this._inputTimers.set(target, handle);
  };

  onKeydown = (event) => {
    if (event.key !== 'Enter') return;
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || !target.hasAttribute('data-list-filter')) {
      return;
    }
    event.preventDefault();
    const previous = this._inputTimers.get(target);
    if (previous !== undefined) {
      clearTimeout(previous);
      this._inputTimers.delete(target);
    }
    const filterName = target.getAttribute('data-list-filter');
    Pagination.navigate(target, { [filterName]: target.value || null, page: null });
  };

  static navigate(target, paramUpdates) {
    const url = new URL(target.ownerDocument.location);
    for (const [name, value] of Object.entries(paramUpdates)) {
      if (value === null || value === '') {
        url.searchParams.delete(name);
      } else {
        url.searchParams.set(name, String(value));
      }
    }
    target.ownerDocument.location.assign(url.toString());
  }
}

new Pagination().initialize();

export default Pagination;
