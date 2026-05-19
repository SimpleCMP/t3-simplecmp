<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Backend\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Localization\LanguageService;
use WapplerSystems\SimpleCmpTypo3\Service\RegistryListPresenter;

/**
 * TCA form element that renders an inline "this service is no longer
 * in the bundled library" callout at the top of the
 * `tx_simplecmptypo3_service` edit form — but only when the row is
 * actually orphaned (Verwaist):
 *
 * - `library_adopted_at = 0` → Eigene (custom-curated) → render nothing.
 * - `library_adopted_at > 0` AND `service_id` in current library → Aus
 *   Bibliothek → render nothing.
 * - `library_adopted_at > 0` AND `service_id` NOT in library → Verwaist
 *   → render the yellow callout with the adoption date.
 *
 * Wired via TCA `type: user, renderType: simplecmpOrphanCallout`.
 * Registered in `ext_localconf.php`. The Dienste BE tab carries the
 * same warning at list level; this surfaces it again where the admin
 * is actually editing the row so the situation can't be missed.
 */
final class OrphanCalloutFieldElement extends AbstractFormElement
{
    public function render(): array
    {
        $result = $this->initializeResultArray();

        $row = $this->data['databaseRow'] ?? [];
        $adoptedAt = (int) ($row['library_adopted_at'] ?? 0);
        if ($adoptedAt === 0) {
            return $result;
        }
        $serviceId = (string) ($row['service_id'] ?? '');
        if ($serviceId === '') {
            return $result;
        }
        $libraryIds = RegistryListPresenter::libraryIdSet();
        if (isset($libraryIds[$serviceId])) {
            // Library still has the service → not orphaned.
            return $result;
        }

        $lang = $this->getLanguageService();
        $title = $lang->sL(
            'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.orphanCallout.title',
        );
        $bodyTemplate = $lang->sL(
            'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.orphanCallout.body',
        );
        $adoptedDate = date('Y-m-d', $adoptedAt);
        // The translation source carries a literal `%s` we splice the
        // adoption date into. Escape the template first so any future
        // translator-introduced HTML doesn't render, then substitute
        // the (already-safe) ISO date.
        $body = str_replace(
            '%s',
            htmlspecialchars($adoptedDate, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($bodyTemplate, ENT_QUOTES, 'UTF-8'),
        );
        $titleEscaped = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $result['html'] = <<<HTML
<div class="alert alert-warning" role="alert">
    <strong>{$titleEscaped}</strong>
    <div>{$body}</div>
</div>
HTML;
        return $result;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
