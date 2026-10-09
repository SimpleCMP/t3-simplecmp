<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Command\CliEditorSession;
use SimpleCMP\T3SimpleCmp\Command\SetupTrackerCommand;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerFieldSpec;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * `--remove` must delete the DRAFT copy of the tracker. CliEditorSession::open()
 * copies the live rows into the draft table under new uids, so the live uid
 * from findBySite() matches nothing there — the command used to report
 * success while the tracker stayed in place.
 */
final class SetupTrackerCommandRemoveTest extends TestCase
{
    private ManagedTrackerRepository&MockObject $trackers;

    protected function setUp(): void
    {
        $this->trackers = $this->createMock(ManagedTrackerRepository::class);
        $this->trackers->method('findBySite')->willReturn([
            ['uid' => 5, 'site' => 'default', 'tracker_type' => 'gtm', 'service_id' => 'gtm', 'config' => []],
        ]);
    }

    #[Test]
    public function removeDeletesTheDraftCopyNotTheLiveUid(): void
    {
        $this->trackers->method('findBySiteDraft')->willReturn([
            ['uid' => 12, 'site' => 'default', 'tracker_type' => 'gtm', 'service_id' => 'gtm', 'config' => []],
        ]);
        $this->trackers->expects(self::once())->method('deleteDraft')->with('default', 12)->willReturn(1);

        $tester = $this->executeCommand(['--site' => 'default', '--be-user' => 'admin', '--remove' => 'gtm']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function removeFailsWhenTheDraftHasNoSuchTracker(): void
    {
        $this->trackers->method('findBySiteDraft')->willReturn([]);
        $this->trackers->expects(self::never())->method('deleteDraft');

        $tester = $this->executeCommand(['--site' => 'default', '--be-user' => 'admin', '--remove' => 'gtm']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('was not found in the draft', $tester->getDisplay());
    }

    /** @param array<string, string> $input */
    private function executeCommand(array $input): CommandTester
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($this->createMock(Site::class));
        $session = $this->createMock(CliEditorSession::class);
        $session->method('resolveBeUser')->willReturn(1);

        $command = new SetupTrackerCommand(
            new TrackerRegistry([]),
            $this->createMock(TrackerFieldSpec::class),
            $this->trackers,
            $this->createMock(EffectiveSettingsResolver::class),
            $session,
            $siteFinder,
        );
        $tester = new CommandTester($command);
        $tester->execute($input);
        return $tester;
    }
}
