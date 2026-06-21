<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\ViewHelpers\Library;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Returns the number of bundled library entries not yet adopted into the
 * live service registry. Used by ModuleNav to show a notification badge.
 */
final class AvailableCountViewHelper extends AbstractViewHelper
{
    public function render(): int
    {
        $serviceRepository = GeneralUtility::makeInstance(ServiceRepository::class);
        $adoptedSet = [];
        foreach ($serviceRepository->findAll() as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $adoptedSet[$id] = true;
            }
        }
        $count = 0;
        foreach (ServicesLibrary::services() as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id !== '' && !isset($adoptedSet[$id])) {
                $count++;
            }
        }
        return $count;
    }
}