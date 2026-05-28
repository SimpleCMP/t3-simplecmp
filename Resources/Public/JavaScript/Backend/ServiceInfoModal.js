import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

/**
 * Per-service info modal for the Bibliothek + Dienste tabs.
 *
 * Trigger: any element with `data-info-trigger`. Reads the full
 * service JSON from `data-info-payload` and the i18n labels from
 * `data-label-*` attributes. Description is expected to be already
 * locale-resolved by the controller (read-only display — no fallback
 * shown on top of the localized text).
 */
class ServiceInfoModalHandler {
  initialize() {
    document.addEventListener('click', this.onClick, true);
  }

  onClick(event) {
    const target = event.target instanceof Element
      ? event.target.closest('[data-info-trigger]')
      : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();

    let service = {};
    try {
      service = JSON.parse(target.getAttribute('data-info-payload') || '{}');
    } catch (_e) {
      service = {};
    }

    const labels = ServiceInfoModalHandler.readLabels(target);
    const content = ServiceInfoModalHandler.buildContent(service, labels);

    Modal.advanced({
      title: labels.modalTitle || service.name || service.id || '—',
      content,
      severity: SeverityEnum.notice,
      size: Modal.sizes.large,
      buttons: [
        {
          text: labels.close || 'Close',
          btnClass: 'btn-default',
          trigger: (_e, m) => m.hideModal(),
        },
      ],
    });
  }

  static readLabels(el) {
    const get = (key) => el.getAttribute('data-label-' + key) || '';
    return {
      modalTitle: get('modal-title'),
      close: get('close'),
      sectionGeneral: get('section-general'),
      sectionPurposes: get('section-purposes'),
      sectionMatchers: get('section-matchers'),
      sectionVendor: get('section-vendor'),
      fieldId: get('field-id'),
      fieldVendor: get('field-vendor'),
      fieldVendorCountry: get('field-vendor-country'),
      fieldPrivacyPolicy: get('field-privacy-policy'),
      fieldCookies: get('field-cookies'),
      fieldOrigins: get('field-origins'),
      fieldVendorAddress: get('field-vendor-address'),
      fieldVendorOptOut: get('field-vendor-optout'),
      fieldVendorPartner: get('field-vendor-partner'),
      fieldVendorDescription: get('field-vendor-description'),
    };
  }

  static buildContent(service, labels) {
    const wrap = document.createElement('div');
    wrap.classList.add('simplecmp-info-modal');

    const style = document.createElement('style');
    style.textContent = `
      .simplecmp-info-modal h4.section { margin-top:1.25rem;margin-bottom:.5rem;font-size:.85rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#6c757d; }
      .simplecmp-info-modal h4.section:first-of-type { margin-top:0; }
      .simplecmp-info-modal dl.row { margin-bottom:.4rem; }
      .simplecmp-info-modal dt { font-weight:600;color:#495057; }
      .simplecmp-info-modal dd { margin-bottom:.25rem; }
      .simplecmp-info-modal code.inline { background:#f4f4f4;padding:1px 6px;border-radius:3px;font-size:.85em; }
      .simplecmp-info-modal .matcher-list { display:flex;flex-wrap:wrap;gap:.35rem;margin:0;padding:0;list-style:none; }
      .simplecmp-info-modal .matcher-list li { background:#f4f4f4;padding:2px 8px;border-radius:3px;font-family:monospace;font-size:.85em; }
      .simplecmp-info-modal .badge-purpose { background:#5e9ed6;color:#fff;font-weight:500;margin-right:.25rem; }
      .simplecmp-info-modal .vendor-block { background:#f6fafd;border-left:3px solid #5e9ed6;padding:.6rem .85rem;border-radius:0 3px 3px 0;margin-bottom:.5rem; }
      .simplecmp-info-modal address { font-style:normal;white-space:pre-line; }
    `;
    wrap.appendChild(style);

    // Header — name (description is shown right below if available)
    const headerH = document.createElement('h3');
    headerH.classList.add('h5', 'mb-1');
    headerH.textContent = service.name || service.id || '—';
    wrap.appendChild(headerH);
    if (service.resolvedDescription) {
      const desc = document.createElement('p');
      desc.classList.add('mb-3', 'text-body-secondary');
      desc.textContent = service.resolvedDescription;
      wrap.appendChild(desc);
    }

    // --- Allgemein ----------------------------------------------------
    wrap.appendChild(ServiceInfoModalHandler.section(labels.sectionGeneral));
    const generalDl = document.createElement('dl');
    generalDl.classList.add('row');
    ServiceInfoModalHandler.appendRow(generalDl, labels.fieldId, () => {
      const c = document.createElement('code');
      c.classList.add('inline');
      c.textContent = service.id || '—';
      return c;
    });
    if (service.vendor) {
      ServiceInfoModalHandler.appendRow(
        generalDl,
        labels.fieldVendor,
        () => document.createTextNode(
          service.vendorCountry
            ? `${service.vendor} (${service.vendorCountry})`
            : String(service.vendor)
        ),
      );
    }
    if (service.privacyPolicyUrl) {
      ServiceInfoModalHandler.appendRow(generalDl, labels.fieldPrivacyPolicy, () => {
        const a = document.createElement('a');
        a.href = service.privacyPolicyUrl;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = service.privacyPolicyUrl;
        return a;
      });
    }
    wrap.appendChild(generalDl);

    // --- Zwecke -------------------------------------------------------
    if (Array.isArray(service.purposes) && service.purposes.length > 0) {
      wrap.appendChild(ServiceInfoModalHandler.section(labels.sectionPurposes));
      const purposesEl = document.createElement('p');
      purposesEl.classList.add('mb-2');
      for (const p of service.purposes) {
        const b = document.createElement('span');
        b.classList.add('badge', 'badge-purpose');
        b.textContent = String(p);
        purposesEl.appendChild(b);
      }
      wrap.appendChild(purposesEl);
    }

    // --- Matcher (Cookies / Origins) ----------------------------------
    const cookies = service.matches?.cookies || [];
    const origins = service.matches?.origins || [];
    if ((Array.isArray(cookies) && cookies.length > 0) || (Array.isArray(origins) && origins.length > 0)) {
      wrap.appendChild(ServiceInfoModalHandler.section(labels.sectionMatchers));
      const matchersDl = document.createElement('dl');
      matchersDl.classList.add('row');
      if (Array.isArray(cookies) && cookies.length > 0) {
        ServiceInfoModalHandler.appendRow(matchersDl, labels.fieldCookies, () => {
          return ServiceInfoModalHandler.matcherList(cookies, (c) => {
            if (typeof c === 'string') return c;
            if (c && typeof c === 'object' && typeof c.name === 'string') {
              return c.requireOrigin
                ? `${c.name} ⊂ ${c.requireOrigin}`
                : c.name;
            }
            return JSON.stringify(c);
          });
        });
      }
      if (Array.isArray(origins) && origins.length > 0) {
        ServiceInfoModalHandler.appendRow(matchersDl, labels.fieldOrigins, () => {
          return ServiceInfoModalHandler.matcherList(origins, (o) => String(o));
        });
      }
      wrap.appendChild(matchersDl);
    }

    // --- Anbieter-Informationen (L2 disclosure) -----------------------
    const hasVendorL2 = Boolean(
      service.vendorAddress || service.vendorOptOutUrl
      || service.vendorPartner || service.vendorDescription
    );
    if (hasVendorL2) {
      wrap.appendChild(ServiceInfoModalHandler.section(labels.sectionVendor));
      const block = document.createElement('div');
      block.classList.add('vendor-block');
      const vendorDl = document.createElement('dl');
      vendorDl.classList.add('row', 'mb-0');
      if (service.vendorAddress) {
        ServiceInfoModalHandler.appendRow(vendorDl, labels.fieldVendorAddress, () => {
          const addr = document.createElement('address');
          addr.textContent = String(service.vendorAddress);
          return addr;
        });
      }
      if (service.vendorOptOutUrl) {
        ServiceInfoModalHandler.appendRow(vendorDl, labels.fieldVendorOptOut, () => {
          const a = document.createElement('a');
          a.href = service.vendorOptOutUrl;
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          a.textContent = service.vendorOptOutUrl;
          return a;
        });
      }
      if (service.vendorPartner) {
        ServiceInfoModalHandler.appendRow(vendorDl, labels.fieldVendorPartner, () => {
          return document.createTextNode(String(service.vendorPartner));
        });
      }
      if (service.vendorDescription) {
        ServiceInfoModalHandler.appendRow(vendorDl, labels.fieldVendorDescription, () => {
          return document.createTextNode(String(service.vendorDescription));
        });
      }
      block.appendChild(vendorDl);
      wrap.appendChild(block);
    }

    return wrap;
  }

  static section(text) {
    const h = document.createElement('h4');
    h.classList.add('section');
    h.textContent = text || '';
    return h;
  }

  static appendRow(dl, label, valueFn) {
    const dt = document.createElement('dt');
    dt.classList.add('col-sm-4');
    dt.textContent = label || '';
    const dd = document.createElement('dd');
    dd.classList.add('col-sm-8');
    dd.appendChild(valueFn());
    dl.appendChild(dt);
    dl.appendChild(dd);
  }

  static matcherList(items, render) {
    const ul = document.createElement('ul');
    ul.classList.add('matcher-list');
    for (const item of items) {
      const li = document.createElement('li');
      li.textContent = render(item);
      ul.appendChild(li);
    }
    return ul;
  }
}

new ServiceInfoModalHandler().initialize();
