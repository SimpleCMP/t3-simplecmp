<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Updates;

use SimpleCMP\ServicesLibrary\ServicesLibrary;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Attribute\Autoconfigure;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\ChattyInterface;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;

/**
 * Backfill `library_adopted_at` for registry rows that pre-date the
 * column.
 *
 * The Dienste BE tab derives a row's source (Eigene / Aus Bibliothek /
 * Verwaist) from `library_adopted_at`. Rows created before the column
 * existed default to 0, which would otherwise classify them as Eigene
 * even if they were originally adopted from the bundled library. This
 * wizard sets `library_adopted_at = NOW()` for every row whose
 * `service_id` is currently in the bundled library — a heuristic but
 * accurate enough: if a row's ID matches a library entry today, it
 * almost certainly came from the library.
 *
 * Idempotent: only touches rows where `library_adopted_at = 0`. Safe
 * to re-run.
 */
#[UpgradeWizard('simplecmpTypo3BackfillLibraryAdoptedAt')]
#[Autoconfigure(public: true)]
final class BackfillLibraryAdoptedAtUpgrade implements UpgradeWizardInterface, ChattyInterface
{
    private ?OutputInterface $output = null;

    public function __construct(
        private readonly ServiceRepository $serviceRepository,
    ) {
    }

    public function getTitle(): string
    {
        return 'SimpleCMP: backfill library_adopted_at on existing registry rows';
    }

    public function getDescription(): string
    {
        return 'Stamps tx_simplecmptypo3_service.library_adopted_at = NOW() for rows whose '
            . 'service_id is in the bundled simplecmp/services-library. Without this, the new '
            . 'Dienste BE tab would classify previously-adopted services as "Eigene" instead '
            . 'of "Aus Bibliothek". Idempotent — safe to re-run.';
    }

    public function executeUpdate(): bool
    {
        $libraryIds = $this->libraryIds();
        $updated = $this->serviceRepository->backfillLibraryAdoptedAt($libraryIds);
        $this->output?->writeln(sprintf(
            'simplecmp_typo3: backfilled library_adopted_at on %d row(s).',
            $updated,
        ));
        return true;
    }

    public function updateNecessary(): bool
    {
        return $this->serviceRepository->countRowsNeedingBackfill($this->libraryIds()) > 0;
    }

    /**
     * @return list<string>
     */
    public function getPrerequisites(): array
    {
        return [
            \TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * @return list<string>
     */
    private function libraryIds(): array
    {
        $ids = [];
        foreach (ServicesLibrary::services() as $entry) {
            if (isset($entry['id'])) {
                $ids[] = (string) $entry['id'];
            }
        }
        return $ids;
    }
}
