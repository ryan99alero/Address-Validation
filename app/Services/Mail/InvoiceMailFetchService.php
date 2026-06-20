<?php

namespace App\Services\Mail;

use App\Models\MailIntegration;
use RuntimeException;

class InvoiceMailFetchService
{
    public function __construct(
        protected MailboxService $mailbox,
        protected ZipExtractor $zipExtractor,
    ) {}

    /**
     * Fetch invoice attachments from a mailbox and unzip them into a per-message
     * staging area. Non-destructive: emails are NOT moved, deleted, or flagged.
     *
     * @return array{messages: int, attachments: int, files: int, errors: array<int, string>, staged: array<int, string>}
     */
    public function fetch(MailIntegration $integration, int $limit = 25): array
    {
        $client = $this->mailbox->clientFor($integration);
        $client->connect();

        $folderName = $integration->imap_folder ?: 'INBOX';
        $folder = $this->mailbox->resolveFolder($client, $folderName);

        if (! $folder) {
            $client->disconnect();
            throw new RuntimeException("Folder '{$folderName}' was not found.");
        }

        $pattern = $integration->attachment_pattern ?: '*.zip';
        $password = $integration->getZipPassword();

        $stats = ['messages' => 0, 'attachments' => 0, 'files' => 0, 'errors' => [], 'staged' => []];

        $query = $folder->query()->leaveUnread()->limit($limit);

        // Apply optional server-side filters to target this carrier's invoices.
        $hasCriteria = false;
        if (! empty($integration->from_filter)) {
            $query->whereFrom($integration->from_filter);
            $hasCriteria = true;
        }
        if (! empty($integration->subject_filter)) {
            $query->whereSubject($integration->subject_filter);
            $hasCriteria = true;
        }

        // With no criteria, send "SEARCH ALL"; an empty SEARCH makes some servers
        // (Zimbra) reject with "BAD parse error: zero-length content".
        if (! $hasCriteria) {
            $query->whereAll();
        }

        $messages = $query->get();

        foreach ($messages as $message) {
            $stats['messages']++;
            $uid = $message->getUid();

            foreach ($message->getAttachments() as $attachment) {
                $name = $attachment->getName();
                if (! $name || ! fnmatch($pattern, $name)) {
                    continue;
                }

                $stats['attachments']++;

                $base = storage_path("app/invoices/incoming/{$integration->id}/{$uid}");
                if (! is_dir($base)) {
                    mkdir($base, 0775, true);
                }

                $zipPath = $base.'/'.$name;
                file_put_contents($zipPath, $attachment->getContent());

                $result = $this->zipExtractor->extract($zipPath, $base.'/extracted', $password);

                if (! $result['ok']) {
                    $stats['errors'][] = "{$name}: {$result['error']}";

                    continue;
                }

                $stats['files'] += count($result['files']);
                $stats['staged'] = array_merge($stats['staged'], $result['files']);
            }
        }

        $client->disconnect();

        return $stats;
    }
}
