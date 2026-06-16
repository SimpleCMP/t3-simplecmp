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
 * Editor-level guard against mutations of
 * `tx_t3simplecmp_audit_retention_log` (Phase 3 self-audit log).
 *
 * Same shape as the Phase-1/2 enforcement hooks:
 *   - TCA `readOnly: true` + `hideTable: true` is the first defence.
 *   - This hook refuses any `update`/`new` datamap entry and any
 *     `delete`/`move`/`copy`/`undelete` cmdmap entry that somehow
 *     targets the table.
 *
 * INSERTs come exclusively from
 * {@see \SimpleCMP\T3SimpleCmp\Service\AuditRetentionService} via
 * direct DBAL — they never traverse DataHandler, so any DH `new`
 * call for this table is unauthorized.
 *
 * Direct SQL access remains possible by design — the visibility of
 * this log in the Auskunfts-tab is meant to make a silent wipe
 * conspicuous.
 */
final class EnforceAuditRetentionLogAppendOnly implements SingletonInterface
{
    private const string TABLE = 'tx_t3simplecmp_audit_retention_log';

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
            $this->refuse(
                'An audit-retention-log row cannot be created via the BE editor — only the simplecmp:audit-retention CLI may write here.',
                $dataHandler,
            );
            $fieldArray = [];
            return;
        }
        $this->refuse(
            'Audit-retention-log rows are append-only. The deletion record cannot be modified after the fact — that is the entire point of the log.',
            $dataHandler,
        );
        $fieldArray = [];
    }

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
                            'Refused %s on tx_t3simplecmp_audit_retention_log uid=%s — the retention log is append-only.',
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
        $this->logger()->warning('EnforceAuditRetentionLogAppendOnly refused: {message}', ['message' => $message]);
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            'SimpleCMP audit retention log',
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
