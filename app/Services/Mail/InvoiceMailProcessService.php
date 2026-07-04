<?php

namespace App\Services\Mail;

use App\Models\Carrier;
use App\Models\CarrierImportFile;
use App\Models\CarrierInvoice;
use App\Models\MailIntegration;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class InvoiceMailProcessService
{
    public function __construct(
        protected MailboxService $mailbox,
        protected ZipExtractor $zipExtractor,
        protected CarrierInvoiceParserService $parser,
    ) {}

    /**
     * Full pipeline for one integration: fetch unseen invoice emails, unzip,
     * parse into the correction cache, archive each PDF to Carrier/Year/Month,
     * then mark the email read (and move it if a processed folder is set).
     *
     * @return array{messages: int, invoices: int, skipped: int, corrections: int, errors: array<int, string>}
     */
    public function process(MailIntegration $integration, int $limit = 50): array
    {
        $stats = ['messages' => 0, 'invoices' => 0, 'skipped' => 0, 'corrections' => 0, 'errors' => [], 'mail_warnings' => []];

        $client = $this->mailbox->clientFor($integration);
        $client->connect();

        $folderName = $integration->imap_folder ?: 'INBOX';
        $folder = $this->mailbox->resolveFolder($client, $folderName);
        if (! $folder) {
            $client->disconnect();
            throw new RuntimeException("Folder '{$folderName}' was not found.");
        }

        // Only unseen messages; leaveUnread() so reading bodies doesn't flag them
        // (we flag explicitly on success, leaving failures unread for retry).
        // Resolve the destination folder once (if a move is configured).
        $processedPath = null;
        if (! empty($integration->processed_folder)) {
            $processedFolder = $this->mailbox->resolveFolder($client, $integration->processed_folder);
            if ($processedFolder) {
                $processedPath = $processedFolder->path;
            } else {
                $stats['mail_warnings'][] = "Processed folder '{$integration->processed_folder}' not found; emails marked read but not moved.";
            }
        }

        $query = $folder->query()->leaveUnread()->unseen()->limit($limit);
        if (! empty($integration->from_filter)) {
            $query->whereFrom($integration->from_filter);
        }
        if (! empty($integration->subject_filter)) {
            $query->whereSubject($integration->subject_filter);
        }

        $expungeNeeded = false;

        foreach ($query->get() as $message) {
            $stats['messages']++;

            try {
                $this->processMessage($integration, $message, $stats);
            } catch (Throwable $e) {
                // Leave the message unread so the next run retries it.
                $stats['errors'][] = $e->getMessage();

                continue;
            }

            // Mark read / move is best-effort: a mail-server quirk here must not
            // fail processing (file-hash dedupe already prevents reprocessing).
            $this->markHandled($integration, $message, $processedPath, $stats, $expungeNeeded);
        }

        // Expunge once at the end so message numbers stay stable during the loop.
        if ($expungeNeeded) {
            try {
                $client->expunge();
            } catch (Throwable $e) {
                $stats['mail_warnings'][] = 'expunge: '.$e->getMessage();
            }
        }

        $client->disconnect();
        $integration->update(['last_processed_at' => now()]);

        return $stats;
    }

    /**
     * Mark an email read and (optionally) move it. Best-effort: failures are
     * recorded as warnings and never abort processing.
     *
     * @param  array<string, mixed>  $stats
     */
    protected function markHandled(MailIntegration $integration, object $message, ?string $processedPath, array &$stats, bool &$expungeNeeded): void
    {
        try {
            $message->setFlag('Seen');
        } catch (Throwable $e) {
            $this->handleMailOpFailure($integration, 'mark read', $e, $stats);
        }

        if ($processedPath === null) {
            return;
        }

        try {
            // Move via raw core IMAP: connection-level COPY (no post-copy re-fetch,
            // which throws "no headers found" on some servers) + mark \Deleted.
            // The whole run is expunged once at the end. Avoids the MOVE extension
            // and webklex's fragile copy()/move() helpers.
            $copied = $message->getClient()->getConnection()->copyMessage(
                $processedPath,
                $message->getSequenceId(),
                null,
                $message->getSequence(),
            );

            if ($copied->boolean()) {
                $message->setFlag('Deleted');
                $expungeNeeded = true;
            } else {
                $stats['mail_warnings'][] = 'move: copy was not accepted by the server';
            }
        } catch (Throwable $e) {
            $this->handleMailOpFailure($integration, 'move', $e, $stats);
        }
    }

    /**
     * Record a mail-state failure, and auto-switch the integration to
     * message-number sequence when a server rejects UID commands (e.g. Zimbra).
     *
     * @param  array<string, mixed>  $stats
     */
    protected function handleMailOpFailure(MailIntegration $integration, string $op, Throwable $e, array &$stats): void
    {
        $message = $e->getMessage();

        // Only a failed flag update (STORE) is a true UID-rejection signal worth
        // switching modes for. Move failures are usually the unsupported MOVE
        // extension, not UID — switching wouldn't help.
        if ($op === 'mark read'
            && stripos($message, 'not permitted with uid') !== false
            && $integration->imap_sequence !== MailIntegration::SEQ_MSGN) {
            $integration->update(['imap_sequence' => MailIntegration::SEQ_MSGN]);
            $stats['mail_warnings'][] = 'This server rejects UID flag commands; switched it to message-number mode. Run again to mark these emails read.';

            return;
        }

        $stats['mail_warnings'][] = "{$op}: {$message}";
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function processMessage(MailIntegration $integration, object $message, array &$stats): void
    {
        $pattern = $integration->attachment_pattern ?: '*.zip';
        $password = $integration->getZipPassword();
        $workDir = storage_path("app/invoices/work/{$integration->id}/".$message->getUid());

        foreach ($message->getAttachments() as $attachment) {
            $name = $attachment->getName();
            if (! $name || ! fnmatch($pattern, $name)) {
                continue;
            }

            if (! is_dir($workDir)) {
                mkdir($workDir, 0775, true);
            }

            $zipPath = $workDir.'/'.$name;
            file_put_contents($zipPath, $attachment->getContent());

            $result = $this->zipExtractor->extract($zipPath, $workDir.'/extracted', $password);
            if (! $result['ok']) {
                $stats['errors'][] = "{$name}: {$result['error']}";

                continue;
            }

            foreach ($result['files'] as $pdfPath) {
                $this->processFile($integration, $message, $pdfPath, $stats);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function processFile(MailIntegration $integration, object $message, string $pdfPath, array &$stats): void
    {
        $hash = hash_file('sha256', $pdfPath);

        // File-level dedupe: same invoice file already processed (legacy pre-split records
        // set CarrierInvoice.file_hash; the split model tracks it on carrier_import_files).
        if (CarrierInvoice::where('file_hash', $hash)->exists()
            || CarrierImportFile::where('file_hash', $hash)->exists()) {
            $stats['skipped']++;

            return;
        }

        $carrier = $this->detectCarrier($integration, $message, $pdfPath);
        if (! $carrier) {
            $stats['errors'][] = 'Could not determine carrier for '.basename($pdfPath);

            return;
        }

        // Split-model ingest: one file may yield several real invoices (by number+date).
        // importFile() routes UPS/FedEx CSV vs PDF to the format-specific parsers that
        // extract charges + shipments + DIM audit, not just address corrections.
        $invoiceIds = $this->parser->importFile($carrier->id, $pdfPath, basename($pdfPath));

        if ($invoiceIds === []) {
            $stats['errors'][] = 'No invoice parsed from '.basename($pdfPath);

            return;
        }

        $file = CarrierImportFile::create([
            'carrier_id' => $carrier->id,
            'file_hash' => $hash,
            'filename' => basename($pdfPath),
            'source_reference' => 'mail:'.$integration->id,
            'invoice_count' => count($invoiceIds),
            'imported_at' => now(),
        ]);
        $file->invoices()->syncWithoutDetaching($invoiceIds);

        foreach (CarrierInvoice::whereIn('id', $invoiceIds)->get() as $invoice) {
            $stats['invoices']++;
            $stats['corrections'] += $invoice->correctionLines()->count();
            $this->archive($integration, $carrier, $invoice, $pdfPath);
        }
    }

    /**
     * Determine which carrier a file belongs to per the integration's mode.
     */
    public function detectCarrier(MailIntegration $integration, ?object $message, string $pdfPath): ?Carrier
    {
        return match ($integration->carrier_detection) {
            MailIntegration::DETECT_FIXED => $integration->carrier,
            MailIntegration::DETECT_SENDER_DOMAIN => $message ? $this->carrierFromSender($message) : null,
            MailIntegration::DETECT_FILE_CONTENT => $this->carrierFromContent($pdfPath) ?? $integration->carrier,
            default => $integration->carrier,
        };
    }

    protected function carrierFromSender(object $message): ?Carrier
    {
        $from = strtolower((string) ($message->getFrom()[0]->mail ?? ''));

        return match (true) {
            str_contains($from, 'ups.com') => Carrier::where('slug', 'ups')->first(),
            str_contains($from, 'fedex.com') => Carrier::where('slug', 'fedex')->first(),
            default => null,
        };
    }

    protected function carrierFromContent(string $pdfPath): ?Carrier
    {
        $head = strtoupper(substr((string) file_get_contents($pdfPath), 0, 4000));

        return match (true) {
            str_contains($head, 'UPS') => Carrier::where('slug', 'ups')->first(),
            str_contains($head, 'FEDEX') => Carrier::where('slug', 'fedex')->first(),
            default => null,
        };
    }

    /**
     * Archive the source PDF to {disk}:{base}/{Carrier}/{Year}/{Month}/file.
     */
    public function archive(MailIntegration $integration, Carrier $carrier, CarrierInvoice $invoice, string $pdfPath): string
    {
        $date = $invoice->invoice_date ? Carbon::parse($invoice->invoice_date) : now();

        $destination = implode('/', [
            trim($integration->archive_base_path, '/'),
            $this->folderSafe($carrier->name ?: $carrier->slug),
            $date->format('Y'),
            $date->format('m'),
            basename($pdfPath),
        ]);

        Storage::disk($integration->archive_disk)->put($destination, (string) file_get_contents($pdfPath));
        $invoice->update(['archived_path' => $destination]);

        return $destination;
    }

    protected function folderSafe(string $value): string
    {
        return trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $value)) ?: 'Unknown';
    }
}
