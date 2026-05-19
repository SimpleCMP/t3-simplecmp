import type { Page, FrameLocator, Locator } from '@playwright/test';

/**
 * Page object for the SimpleCMP Detections BE module.
 *
 * Module URL: `/typo3/module/simplecmp/detections`. The route id is
 * `simplecmp_detections`; sub-routes follow the pattern
 * `simplecmp_detections.Backend\<Controller>_<action>`.
 *
 * Important: TYPO3 v14 renders Extbase BE modules inside an iframe
 * (`<iframe id="typo3-contentIframe">`) hung off
 * `<typo3-backend-module-router>`. Every locator in this object scopes
 * into that frame via `frameLocator(...)` — querying the outer
 * document for `h1`, `tbody tr` etc. silently returns nothing because
 * the module markup lives one document down.
 */
export class DetectionsModule {
  private readonly frame: FrameLocator;

  constructor(private readonly page: Page) {
    this.frame = page.frameLocator('#typo3-contentIframe');
  }

  /**
   * Wait for the inner Fluid template to finish rendering.
   *
   * If you need to apply URL filters (e.g. `?status=kuratiert`), pass
   * them via `query` rather than calling `page.goto()` then `open()`
   * — `open()` does its own navigation and would otherwise clobber
   * any query string.
   */
  async open(query: string = ''): Promise<void> {
    const suffix = query ? (query.startsWith('?') ? query : `?${query}`) : '';
    await this.page.goto(`/typo3/module/simplecmp/detections${suffix}`);
    // The iframe streams in after the BE chrome paints. Wait on the
    // page-specific h1 so the spec assertion that follows runs against
    // a fully-rendered template. Generic "SimpleCMP" matches the
    // module-router header too — we want the inner one.
    await this.frame
      .locator('h1', { hasText: /tracker detections|Tracker-Erkennungen/i })
      .first()
      .waitFor({ state: 'visible', timeout: 20_000 });
  }

  async openLibraryTab(): Promise<void> {
    // The tab is rendered as `<a class="nav-link" role="tab">`.
    // German label "Bibliothek", English "Library" — match either.
    await this.frame.getByRole('tab', { name: /Bibliothek|Library/i }).first().click();
    await this.page.waitForLoadState('networkidle');
    // The library list has its own h1 — wait for it.
    await this.frame
      .locator('h1', { hasText: /service library|Dienste-Bibliothek/i })
      .first()
      .waitFor({ state: 'visible', timeout: 15_000 });
  }

  async openDetectionsTab(): Promise<void> {
    await this.frame.getByRole('tab', { name: /Erkennungen|Detections/i }).first().click();
    await this.page.waitForLoadState('networkidle');
  }

  /** Row locator that matches by the visible identifier text in the table. */
  detectionRow(identifier: string): Locator {
    return this.frame.locator('tbody tr', { hasText: identifier }).first();
  }

  /** Row locator for the library browser. Matches by service id. */
  libraryRow(serviceId: string): Locator {
    return this.frame.locator('tbody tr', { hasText: serviceId }).first();
  }

  /** Direct access for specs that need to query inside the module iframe. */
  get content(): FrameLocator {
    return this.frame;
  }
}
