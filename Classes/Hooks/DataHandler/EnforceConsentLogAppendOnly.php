<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Hooks\DataHandler;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Editor-level guard against mutations of `tx_t3simplecmp_consent_log`
 * (Phase 2 audit trail).
 *
 * Same second-line-of-defence shape as the Phase-1
 * {@see EnforceConfigSnapshotAppendOnly} hook:
 *   - TCA `readOnly: true` + `hideTable: true` hides the table from
 *     the BE editor surface (first line).
 *   - This hook refuses UPDATE on existing rows + any `cmd`
 *     delete/move/copy/undelete with a clear FlashMessage so an
 *     editor sees why the action failed (second line).
 *
 * Direct SQL access remains possible by design — production retention
 * is a Phase-3 CLI workflow with its own audit log over the deletion
 * decision.
 */
final class EnforceConsentLogAppendOnly implements SingletonInterface
{
    private const string TABLE = 'tx_t3simplecmp_consent_log';

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        mixed $id,
        array &$fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE) {
            return;
        }
        if ($status !== 'update') {
            // INSERTs come from the consent-log endpoint via direct
            // DBAL — they never go through DataHandler. So if an
            // editor managed to trigger a `new` DataHandler call for
            // this table, it's unauthorized.
            $this->refuse('A consent-log row cannot be created via the BE editor.', $dataHandler);
            $fieldArray = [];
            return;
        }
        $this->refuse(
            'Consent-log rows are append-only. Existing decisions cannot be modified through the editor — use the CLI to amend retention if needed (Phase-3 workflow).',
            $dataHandler,
        );
        $fieldArray = [];
    }

    /**
     * @param array<string, mixed>|string|int|null $value
     */
    public function processCmdmap_preProcessCommandMap(
        array &$commandMap,
        DataHandler $dataHandler,
    ): void {
        if (!isset($commandMap[self::TABLE]) || !is_array($commandMap[self::TABLE])) {
            return;
        }
        $blockedCommands = ['delete', 'move', 'copy', 'undelete'];
        foreach ($commandMap[self::TABLE] as $uid => $commands) {
            if (!is_array($commands)) {
                continue;
            }
            foreach ($commands as $command => $_value) {
                if (in_array($command, $blockedCommands, true)) {
                    unset($commandMap[self::TABLE][$uid][$command]);
                    $this->refuse(
                        sprintf(
                            'Refused %s on tx_t3simplecmp_consent_log uid=%s — consent decisions are append-only.',
                            $command,
                            (string) $uid,
                        ),
                        $dataHandler,
                    );
                }
            }
        }
    }

    private function refuse(string $message, DataHandler $dataHandler): void
    {
        $this->logger()->warning('EnforceConsentLogAppendOnly refused: {message}', ['message' => $message]);
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            'SimpleCMP consent log',
            ContextualFeedbackSeverity::ERROR,
            true,
        );
        GeneralUtility::makeInstance(FlashMessageService::class)
            ->getMessageQueueByIdentifier()
            ->addMessage($flashMessage);
    }

    private function logger(): LoggerInterface
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
            ->getLogger(self::class);
    }
}
