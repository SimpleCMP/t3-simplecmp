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
 * Editor-level guard against mutations of `tx_t3simplecmp_config_snapshot`.
 *
 * TCA already hides the table with `readOnly: true` + `hideTable: true`,
 * but a determined editor could still craft a DataHandler call through
 * the CLI / List-module / a custom EXT. This hook is the second line —
 * it refuses UPDATE on existing rows and any `cmd` (delete/move/copy/
 * undelete) operations with a clear FlashMessage so the editor sees
 * why the action failed.
 *
 * Note: this is **editor-level enforcement** only. Direct SQL via
 * `database:query` or a manual MySQL session bypasses TYPO3 entirely
 * and is intentionally NOT blocked here. Production retention is a
 * Phase-3 CLI workflow with its own audit log over the retention
 * decision.
 */
final class EnforceConfigSnapshotAppendOnly implements SingletonInterface
{
    private const string TABLE = 'tx_t3simplecmp_config_snapshot';

    /**
     * Refuse the data write before it touches the DB. `$fieldArray =
     * []` is the TYPO3-idiomatic "skip this row" signal — DataHandler
     * sees an empty fields list and short-circuits the INSERT/UPDATE.
     *
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
            // INSERTs come from the listener + the CLI command via
            // direct DBAL — they never go through DataHandler. So if
            // an editor managed to trigger a `new` DataHandler call
            // for this table, it's also unauthorized. Block both.
            $this->refuse('A snapshot row cannot be created via the BE editor.', $dataHandler);
            $fieldArray = [];
            return;
        }
        $this->refuse(
            'Audit snapshots are append-only. Existing rows cannot be modified through the editor — use the CLI to amend retention if needed (Phase-3 workflow).',
            $dataHandler,
        );
        $fieldArray = [];
    }

    /**
     * Block delete / move / copy / undelete via the command map. Same
     * second-line-of-defence reasoning as
     * {@see processDatamap_postProcessFieldArray()}.
     *
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
                            'Refused %s on tx_t3simplecmp_config_snapshot uid=%s — audit snapshots are append-only.',
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
        $this->logger()->warning('EnforceConfigSnapshotAppendOnly refused: {message}', ['message' => $message]);
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            'SimpleCMP audit snapshot',
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
