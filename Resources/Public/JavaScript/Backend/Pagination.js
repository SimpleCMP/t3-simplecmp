/**
 * Auto-submit handling for the detection list's select-based filters
 * and pagination size:
 *
 * - `[data-per-page]` — sets `perPage` and clears `page` (= jump to
 *   page 1 of the new size).
 * - `[data-list-filter="<name>"]` — sets `<name>` in the URL (or
 *   removes it when the value is empty), and clears `page` so the
 *   user lands on page 1 of the filtered view.
 *
 * Both flows rewrite `location` directly. A <form method="get"> would
 * also work, but TYPO3's BE-module token sits in the URL and weaving
 * it back into a form action is more code than this.
 */
class Pagination {
  initialize() {
    document.addEventListener('change', this.onChange);
  }

  onChange(event) {
    const target = event.target;
    if (!(target instanceof HTMLSelectElement)) {
      return;
    }
    if (target.matches('[data-per-page]')) {
      Pagination.navigate(target, { perPage: target.value, page: null });
      return;
    }
    const filterName = target.getAttribute('data-list-filter');
    if (filterName) {
      Pagination.navigate(target, { [filterName]: target.value || null, page: null });
    }
  }

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
