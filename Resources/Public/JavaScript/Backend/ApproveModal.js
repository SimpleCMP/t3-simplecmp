import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

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
    let service = {};
    try {
      service = JSON.parse(payloadRaw || '{}');
    } catch (_e) {
      service = {};
    }

    const labels = ApproveModalHandler.readLabels(target);
    const content = ApproveModalHandler.buildContent(service, labels);

    Modal.advanced({
      title: labels.title,
      content,
      type: Modal.types.default,
      severity: SeverityEnum.info,
      size: Modal.sizes.medium,
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
    };
  }

  static buildContent(service, labels) {
    const wrap = document.createElement('div');
    wrap.classList.add('simplecmp-approve-modal');

    const heading = document.createElement('h3');
    heading.classList.add('h5', 'mb-2');
    heading.textContent = service.name || service.id || '—';
    wrap.appendChild(heading);

    if (service.description) {
      const desc = document.createElement('p');
      desc.classList.add('mb-3', 'text-body-secondary');
      desc.textContent = service.description;
      wrap.appendChild(desc);
    }

    const dl = document.createElement('dl');
    dl.classList.add('row', 'mb-0');

    const addRow = (label, value) => {
      if (value === undefined || value === null || value === '') {
        return;
      }
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
    };

    const vendorText = service.vendor
      ? (service.vendorCountry ? `${service.vendor} (${service.vendorCountry})` : service.vendor)
      : null;
    addRow(labels.vendor, vendorText);

    if (Array.isArray(service.purposes) && service.purposes.length > 0) {
      addRow(labels.purposes, service.purposes.join(', '));
    }

    if (service.privacyPolicyUrl) {
      const a = document.createElement('a');
      a.href = service.privacyPolicyUrl;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = service.privacyPolicyUrl;
      addRow(labels.privacy, a);
    }

    const cookies = service?.matches?.cookies;
    if (Array.isArray(cookies) && cookies.length > 0) {
      addRow(labels.cookies, cookies.join(', '));
    }
    const origins = service?.matches?.origins;
    if (Array.isArray(origins) && origins.length > 0) {
      addRow(labels.origins, origins.join(', '));
    }

    wrap.appendChild(dl);
    return wrap;
  }
}

new ApproveModalHandler().initialize();

export default ApproveModalHandler;
