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
 * Phase-4 lock-down of the five banner-config "live" tables.
 *
 * Once Phase 4 is on, the editor flow is: edit drafts via the
 * SimpleCMP module tabs → click "Veröffentlichen" → the
 * `DraftPublishService` atomically promotes draft into live + fires
 * a snapshot with `trigger_event='publish'`. Direct edits to the
 * live tables would bypass that audit-trail step, so we refuse them
 * with a FlashMessage pointing the operator at the right surface.
 *
 * Mirrors the Phase-1/2/3 append-only enforcement pattern (TCA
 * `readOnly`/`hideTable` is the first defence; this hook is the
 * second). Direct SQL bypasses everything by design — the audit log
 * is the operator-disciplined record.
 */
final class EnforceLiveBannerConfigReadOnly implements SingletonInterface
{
    private const array TABLES = [
        'tx_t3simplecmp_service',
        'tx_t3simplecmp_theme',
        'tx_t3simplecmp_translation_override',
        'tx_t3simplecmp_managed_tracker',
        'tx_t3simplecmp_allowed_stylesheet_host',
        'tx_t3simplecmp_publish_lock',
        // Phase 5 — active settings is editor-API-blocked too; only
        // the SettingsController is allowed to write here.
        'tx_t3simplecmp_active_settings',
    ];

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
        if (!in_array($table, self::TABLES, true)) {
            return;
        }
        $this->refuse(
            sprintf(
                'Direct edits to %s are blocked. Use the SimpleCMP module tab and click "Veröffentlichen" to push draft changes live.',
                $table,
            ),
            $dataHandler,
        );
        $fieldArray = [];
    }

    public function processCmdmap_preProcessCommandMap(
        array &$commandMap,
        DataHandler $dataHandler,
    ): void {
        $blockedCommands = ['delete', 'move', 'copy', 'undelete'];
        foreach (self::TABLES as $table) {
            if (!isset($commandMap[$table]) || !is_array($commandMap[$table])) {
                continue;
            }
            foreach ($commandMap[$table] as $uid => $commands) {
                if (!is_array($commands)) {
                    continue;
                }
                foreach ($commands as $command => $_value) {
                    if (in_array($command, $blockedCommands, true)) {
                        unset($commandMap[$table][$uid][$command]);
                        $this->refuse(
                            sprintf(
                                'Refused %s on %s uid=%s — Phase 4 routes mutations through Veröffentlichen.',
                                $command,
                                $table,
                                (string) $uid,
                            ),
                            $dataHandler,
                        );
                    }
                }
            }
        }
    }

    private function refuse(string $message, DataHandler $dataHandler): void
    {
        $this->logger()->warning('EnforceLiveBannerConfigReadOnly refused: {message}', ['message' => $message]);
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            'SimpleCMP banner config (live)',
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
