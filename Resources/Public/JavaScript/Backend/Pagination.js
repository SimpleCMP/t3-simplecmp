/**
 * Per-page select handling for the detection list.
 *
 * When the user picks a new page-size value, navigate to the same
 * URL with `perPage` swapped in and `page` reset to 1. We don't use
 * a <form method="get"> because TYPO3's BE-module token already
 * lives in the URL — building a form action that preserves it
 * cleanly is more code than just rewriting the location.
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
    if (!target.matches('[data-per-page]')) {
      return;
    }
    const url = new URL(target.ownerDocument.location);
    url.searchParams.set('perPage', target.value);
    url.searchParams.delete('page');
    target.ownerDocument.location.assign(url.toString());
  }
}

new Pagination().initialize();

export default Pagination;
