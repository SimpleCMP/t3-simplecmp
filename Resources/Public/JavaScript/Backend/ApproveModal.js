import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

const LIBRARY_BASE = 'https://github.com/SimpleCMP/services-library/blob/main/data/services/';
const PURPOSE_KEYS = ['analytics', 'marketing', 'advertising', 'functional', 'personalization', 'security'];

class ApproveModalHandler {
  initialize() {
    document.addEventListener('click', this.onClick, true);
  }

  onClick(event) {
    const target = event.target instanceof Element
      ? event.target.closest('[data-approve-trigger]')
      : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();

    const url = target.getAttribute('data-approve-url');
    const payloadRaw = target.getAttribute('data-approve-payload');
    const affectedCount = parseInt(target.getAttribute('data-approve-affected-count') || '0', 10);
    let service = {};
    try {
      service = JSON.parse(payloadRaw || '{}');
    } catch (_e) {
      service = {};
    }

    const labels = ApproveModalHandler.readLabels(target);
    const purposeMeta = ApproveModalHandler.readPurposeMeta(target);
    const content = ApproveModalHandler.buildContent(service, labels, purposeMeta, affectedCount);

    Modal.advanced({
      title: labels.title,
      content,
      type: Modal.types.default,
      severity: SeverityEnum.info,
      size: Modal.sizes.large,
      buttons: [
        {
          text: labels.cancel,
          btnClass: 'btn-default',
          name: 'cancel',
          trigger: (_e, modal) => modal.hideModal(),
        },
        {
          text: labels.confirm,
          btnClass: 'btn-primary',
          name: 'confirm',
          active: true,
          trigger: (_e, modal) => {
            modal.hideModal();
            window.location.href = url;
          },
        },
      ],
    });
  }

  static readLabels(button) {
    const get = (key, fallback) => button.getAttribute(`data-label-${key}`) || fallback;
    return {
      title: get('title', 'Approve service'),
      cancel: get('cancel', 'Cancel'),
      confirm: get('confirm', 'Approve'),
      vendor: get('vendor', 'Vendor'),
      purposes: get('purposes', 'Purposes'),
      privacy: get('privacy', 'Privacy policy'),
      cookies: get('cookies', 'Cookies'),
      origins: get('origins', 'Hosts'),
      sectionFrontend: get('section-frontend', 'Frontend data — what visitors will see'),
      sectionRaw: get('section-raw', 'Raw data — what gets written to the registry'),
      sectionImpact: get('section-impact', 'Impact'),
      fieldServiceId: get('field-service-id', 'Service ID'),
      fieldRetention: get('field-retention', 'Retention'),
      fieldI18nNames: get('field-i18n-names', 'Display names (per locale)'),
      fieldLibrarySource: get('field-library-source', 'View library source on GitHub'),
      previewTitle: get('preview-title', 'Banner preview'),
      previewDisclaimer: get('preview-disclaimer', 'Approximate preview only.'),
      previewConsentToggle: get('preview-consent-toggle', 'Consent'),
      impactAffected: get('impact-affected', '%d existing detection(s) match this service.'),
      impactFuture: get('impact-future', 'Future visits will be classified as known.'),
    };
  }

  static readPurposeMeta(button) {
    const meta = {};
    for (const key of PURPOSE_KEYS) {
      const label = button.getAttribute(`data-purpose-${key}-label`);
      const description = button.getAttribute(`data-purpose-${key}-description`);
      if (label) {
        meta[key] = { label, description: description || '' };
      }
    }
    return meta;
  }

  static buildContent(service, labels, purposeMeta, affectedCount) {
    const wrap = document.createElement('div');
    wrap.classList.add('simplecmp-approve-modal');

    // Modal-scoped styles. Inserted once per modal instance — Modal re-creates
    // a fresh DOM subtree per .advanced() call, so this can't accumulate.
    // Banner-preview styles mirror upstream `simplecmp/src/ui/styles/tokens.ts`
    // + `service-toggle.ts` / `banner.ts` static styles so the preview
    // approximates the actual FE rendering. Drift risk: if upstream tokens
    // or service-toggle markup change, this preview falls out of sync.
    const style = document.createElement('style');
    style.textContent = `
      .simplecmp-approve-modal h4.section { margin-top:1.25rem;margin-bottom:.6rem;font-size:.95rem;font-weight:600;letter-spacing:.02em;text-transform:uppercase;color:#6c757d; }
      .simplecmp-approve-modal h4.section:first-child { margin-top:0; }
      .simplecmp-approve-modal dl.row { margin-bottom:.5rem; }
      .simplecmp-approve-modal dt { font-weight:600;color:#495057; }
      .simplecmp-approve-modal code.raw-list { display:block;white-space:pre-wrap;font-size:.85em;background:#f4f4f4;padding:.4em .6em;border-radius:3px; }
      .simplecmp-approve-modal .purpose-card { padding:.5rem .75rem;margin-bottom:.4rem;border-left:3px solid #5e9ed6;background:#f6fafd;border-radius:0 3px 3px 0; }
      .simplecmp-approve-modal .purpose-card .label { font-weight:600;display:flex;align-items:baseline;gap:.5rem; }
      .simplecmp-approve-modal .purpose-card .label code { font-size:.75em;background:#e9ecef;padding:1px 6px;border-radius:2px;color:#495057; }
      .simplecmp-approve-modal .purpose-card .description { font-size:.9em;color:#495057;margin-top:.15rem; }
      .simplecmp-approve-modal .banner-preview {
        background:#ffffff;color:#1a232c;border:1px solid #dde2e7;border-radius:6px;
        padding:1.25rem;margin-top:.5rem;
        box-shadow:0 8px 24px rgba(0,0,0,0.12);
        font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
        font-size:.95rem;line-height:1.5;
      }
      .simplecmp-approve-modal .banner-preview .toggle-row { display:flex;align-items:flex-start;gap:.5rem; }
      .simplecmp-approve-modal .banner-preview .toggle-row input[type='checkbox'] { margin-top:.25rem;flex-shrink:0;accent-color:#1a936f;width:1rem;height:1rem; }
      .simplecmp-approve-modal .banner-preview .toggle-row .meta { flex:1; }
      .simplecmp-approve-modal .banner-preview .toggle-row .title { font-weight:500; }
      .simplecmp-approve-modal .banner-preview .toggle-row .description { margin:.25rem 0 0;font-size:.85rem;color:#5f6b78; }
      .simplecmp-approve-modal .banner-preview .toggle-row .purposes { margin:.25rem 0 0;font-size:.85rem;color:#5f6b78; }
      .simplecmp-approve-modal .banner-preview .toggle-row .badge {
        display:inline-block;margin-left:.5rem;padding:0 .4rem;font-size:.85rem;
        background:#f5f7f9;border-radius:6px;color:#5f6b78;
      }
      .simplecmp-approve-modal .preview-disclaimer { font-size:.8em;color:#6c757d;font-style:italic;margin-top:.4rem; }
      .simplecmp-approve-modal .impact-callout { background:#fff3cd;border:1px solid #ffeeba;color:#664d03;padding:.6rem .8rem;border-radius:3px;margin-bottom:.5rem; }
      .simplecmp-approve-modal a.library-source { display:inline-flex;align-items:center;gap:.3rem;font-size:.9em; }
    `;
    wrap.appendChild(style);

    // Top header (always present).
    const headerH = document.createElement('h3');
    headerH.classList.add('h5', 'mb-1');
    headerH.textContent = service.name || service.id || '—';
    wrap.appendChild(headerH);
    if (service.description) {
      const desc = document.createElement('p');
      desc.classList.add('mb-3', 'text-body-secondary');
      desc.textContent = service.description;
      wrap.appendChild(desc);
    }

    // --- Section 1: Frontend data --------------------------------------
    wrap.appendChild(ApproveModalHandler.sectionHeading(labels.sectionFrontend));

    // Friendly purpose cards
    if (Array.isArray(service.purposes) && service.purposes.length > 0) {
      const purposesContainer = document.createElement('div');
      purposesContainer.classList.add('mb-3');
      for (const key of service.purposes) {
        const meta = purposeMeta[key];
        const card = document.createElement('div');
        card.classList.add('purpose-card');
        const labelLine = document.createElement('div');
        labelLine.classList.add('label');
        const labelText = document.createElement('span');
        labelText.textContent = meta?.label || ApproveModalHandler.titleCase(key);
        const rawTag = document.createElement('code');
        rawTag.textContent = key;
        labelLine.appendChild(labelText);
        labelLine.appendChild(rawTag);
        card.appendChild(labelLine);
        if (meta?.description) {
          const descEl = document.createElement('div');
          descEl.classList.add('description');
          descEl.textContent = meta.description;
          card.appendChild(descEl);
        }
        purposesContainer.appendChild(card);
      }
      wrap.appendChild(purposesContainer);
    }

    // i18n display names if present
    if (service.i18n && typeof service.i18n === 'object') {
      const i18nDl = document.createElement('dl');
      i18nDl.classList.add('row', 'mb-2');
      const dt = document.createElement('dt');
      dt.classList.add('col-sm-4');
      dt.textContent = labels.fieldI18nNames;
      i18nDl.appendChild(dt);
      const dd = document.createElement('dd');
      dd.classList.add('col-sm-8');
      const list = Object.entries(service.i18n)
        .map(([locale, val]) => {
          const name = (val && typeof val === 'object' && typeof val.name === 'string') ? val.name : '';
          return name ? `${locale}: ${name}` : '';
        })
        .filter(Boolean)
        .join(' · ');
      dd.textContent = list || '—';
      i18nDl.appendChild(dd);
      wrap.appendChild(i18nDl);
    }

    // Privacy policy link
    if (service.privacyPolicyUrl) {
      const dl = document.createElement('dl');
      dl.classList.add('row', 'mb-2');
      const dt = document.createElement('dt');
      dt.classList.add('col-sm-4');
      dt.textContent = labels.privacy;
      dl.appendChild(dt);
      const dd = document.createElement('dd');
      dd.classList.add('col-sm-8');
      const a = document.createElement('a');
      a.href = service.privacyPolicyUrl;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = service.privacyPolicyUrl;
      dd.appendChild(a);
      dl.appendChild(dd);
      wrap.appendChild(dl);
    }

    // Banner preview mockup
    const previewBlock = document.createElement('div');
    previewBlock.classList.add('mb-3');
    const previewLabel = document.createElement('div');
    previewLabel.style.fontWeight = '600';
    previewLabel.style.fontSize = '0.9em';
    previewLabel.style.marginBottom = '.25rem';
    previewLabel.textContent = labels.previewTitle;
    previewBlock.appendChild(previewLabel);
    previewBlock.appendChild(ApproveModalHandler.buildBannerPreview(service, purposeMeta, labels));
    const disclaimer = document.createElement('div');
    disclaimer.classList.add('preview-disclaimer');
    disclaimer.textContent = labels.previewDisclaimer;
    previewBlock.appendChild(disclaimer);
    wrap.appendChild(previewBlock);

    // --- Section 2: Raw data -------------------------------------------
    wrap.appendChild(ApproveModalHandler.sectionHeading(labels.sectionRaw));
    const rawDl = document.createElement('dl');
    rawDl.classList.add('row');

    ApproveModalHandler.addRawRow(rawDl, labels.fieldServiceId, ApproveModalHandler.mono(service.id || '—'));
    if (service.vendor) {
      const vendorText = service.vendorCountry ? `${service.vendor} (${service.vendorCountry})` : service.vendor;
      ApproveModalHandler.addRawRow(rawDl, labels.vendor, vendorText);
    }
    if (Array.isArray(service.purposes)) {
      ApproveModalHandler.addRawRow(rawDl, labels.purposes, ApproveModalHandler.rawList(service.purposes));
    }
    if (Array.isArray(service?.matches?.cookies)) {
      ApproveModalHandler.addRawRow(rawDl, labels.cookies, ApproveModalHandler.rawList(service.matches.cookies));
    }
    if (Array.isArray(service?.matches?.origins)) {
      ApproveModalHandler.addRawRow(rawDl, labels.origins, ApproveModalHandler.rawList(service.matches.origins));
    }
    if (service.retention && typeof service.retention === 'object') {
      ApproveModalHandler.addRawRow(rawDl, labels.fieldRetention, ApproveModalHandler.rawList(service.retention));
    }
    wrap.appendChild(rawDl);

    // Library source link
    if (service.id) {
      const sourceP = document.createElement('p');
      sourceP.classList.add('mb-0');
      const sourceA = document.createElement('a');
      sourceA.classList.add('library-source');
      sourceA.href = `${LIBRARY_BASE}${service.id}.json`;
      sourceA.target = '_blank';
      sourceA.rel = 'noopener noreferrer';
      sourceA.textContent = '↗ ' + labels.fieldLibrarySource;
      sourceP.appendChild(sourceA);
      wrap.appendChild(sourceP);
    }

    // --- Section 3: Impact ---------------------------------------------
    wrap.appendChild(ApproveModalHandler.sectionHeading(labels.sectionImpact));
    if (affectedCount > 0) {
      const callout = document.createElement('div');
      callout.classList.add('impact-callout');
      callout.textContent = labels.impactAffected.replace('%d', String(affectedCount));
      wrap.appendChild(callout);
    }
    const futureP = document.createElement('p');
    futureP.classList.add('mb-0', 'text-body-secondary');
    futureP.style.fontSize = '0.92em';
    futureP.textContent = labels.impactFuture;
    wrap.appendChild(futureP);

    return wrap;
  }

  static sectionHeading(text) {
    const h = document.createElement('h4');
    h.classList.add('section');
    h.textContent = text;
    return h;
  }

  static addRawRow(dl, label, value) {
    const dt = document.createElement('dt');
    dt.classList.add('col-sm-4');
    dt.textContent = label;
    dl.appendChild(dt);
    const dd = document.createElement('dd');
    dd.classList.add('col-sm-8');
    if (value instanceof Node) {
      dd.appendChild(value);
    } else {
      dd.textContent = value;
    }
    dl.appendChild(dd);
  }

  static mono(text) {
    const c = document.createElement('code');
    c.textContent = text;
    return c;
  }

  static rawList(value) {
    const c = document.createElement('code');
    c.classList.add('raw-list');
    c.textContent = JSON.stringify(value);
    return c;
  }

  static titleCase(s) {
    return typeof s === 'string' && s.length > 0
      ? s[0].toUpperCase() + s.slice(1)
      : s;
  }

  /**
   * Render a faithful preview of the FE `<simplecmp-service-toggle>` row
   * as it appears inside the consent modal. Mirrors the upstream Lit
   * component's structure: checkbox + meta (title + optional description
   * + purposes line). Uses the default theme tokens so the preview
   * matches what visitors see on a site running stock SimpleCMP styling.
   */
  static buildBannerPreview(service, purposeMeta, labels) {
    const card = document.createElement('div');
    card.classList.add('banner-preview');

    const row = document.createElement('div');
    row.classList.add('toggle-row');

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.checked = false;
    checkbox.disabled = true;
    checkbox.setAttribute('aria-hidden', 'true');
    row.appendChild(checkbox);

    const meta = document.createElement('div');
    meta.classList.add('meta');

    const titleLabel = document.createElement('label');
    const title = document.createElement('span');
    title.classList.add('title');
    title.textContent = service.name || service.id || '—';
    titleLabel.appendChild(title);
    meta.appendChild(titleLabel);

    if (service.description) {
      const desc = document.createElement('p');
      desc.classList.add('description');
      desc.textContent = service.description;
      meta.appendChild(desc);
    }

    if (Array.isArray(service.purposes) && service.purposes.length > 0) {
      const purposesP = document.createElement('p');
      purposesP.classList.add('purposes');
      const purposeNames = service.purposes
        .map((key) => purposeMeta[key]?.label || ApproveModalHandler.titleCase(key))
        .join(', ');
      const label = labels.purposes + (service.purposes.length > 1 ? '' : '');
      purposesP.textContent = `${label}: ${purposeNames}`;
      meta.appendChild(purposesP);
    }

    row.appendChild(meta);
    card.appendChild(row);
    return card;
  }
}

new ApproveModalHandler().initialize();

export default ApproveModalHandler;
