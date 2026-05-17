/**
 * "Detect fonts from active site" button in the Banner-Design module.
 *
 * Loads the active site's FE in a hidden iframe, reads `<body>` and
 * `<h1>` (falls back to `<h2>` if no h1) computed `font-family` and
 * `font-size`, fills the corresponding form fields. Same-origin only;
 * cross-origin reads throw on `contentDocument` access and we surface
 * a friendly fallback message.
 *
 * The form's input events fire after we set values, so the live
 * preview iframe updates without an extra manual step.
 */

const TARGETS = {
  body: { selector: 'body', familyField: 'font-family', sizeField: 'font-size' },
  heading: { selector: 'h1, h2, h3', familyField: 'font-family-heading', sizeField: 'font-size-heading' },
};

class DetectFonts {
  initialize() {
    document.addEventListener('click', this.onClick, true);
  }

  onClick = (event) => {
    const target = event.target instanceof Element
      ? event.target.closest('[data-detect-fonts]')
      : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    const url = target.getAttribute('data-detect-fonts-url');
    if (!url) {
      DetectFonts.setStatus(target, 'No site base URL configured for this site.');
      return;
    }
    DetectFonts.setStatus(target, 'Loading site…');
    DetectFonts.detect(url)
      .then((detected) => DetectFonts.applyDetected(target, detected))
      .catch((err) => DetectFonts.setStatus(target, `Couldn't read fonts (${err.message}). Type them in manually.`));
  };

  static detect(url) {
    return new Promise((resolve, reject) => {
      const iframe = document.createElement('iframe');
      iframe.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;border:0';
      iframe.src = url;
      iframe.addEventListener('load', () => {
        try {
          const doc = iframe.contentDocument;
          if (!doc) throw new Error('cross-origin');
          const body = doc.body;
          const headingEl = doc.querySelector('h1') || doc.querySelector('h2') || doc.querySelector('h3');
          const result = {
            'font-family': getComputedStyle(body).fontFamily,
            'font-size': getComputedStyle(body).fontSize,
          };
          if (headingEl) {
            result['font-family-heading'] = getComputedStyle(headingEl).fontFamily;
            result['font-size-heading'] = getComputedStyle(headingEl).fontSize;
          }
          iframe.remove();
          resolve(result);
        } catch (err) {
          iframe.remove();
          reject(err instanceof Error ? err : new Error(String(err)));
        }
      });
      iframe.addEventListener('error', () => {
        iframe.remove();
        reject(new Error('failed to load'));
      });
      document.body.appendChild(iframe);
    });
  }

  static applyDetected(button, detected) {
    let applied = 0;
    for (const [tokenKey, value] of Object.entries(detected)) {
      if (typeof value !== 'string' || value === '') continue;
      const input = document.querySelector(`input[name="tokens[${tokenKey}]"]`);
      if (!input) continue;
      input.value = value;
      // Fire input so the live-preview postMessage flushes.
      input.dispatchEvent(new Event('input', { bubbles: true }));
      applied++;
    }
    DetectFonts.setStatus(
      button,
      applied > 0
        ? `Filled ${applied} field${applied === 1 ? '' : 's'} from the site.`
        : 'Nothing to fill in — site didn\'t expose readable fonts.',
    );
  }

  static setStatus(button, text) {
    const statusEl = button.parentElement?.querySelector('[data-detect-fonts-status]');
    if (statusEl) statusEl.textContent = text;
  }
}

new DetectFonts().initialize();

export default DetectFonts;
