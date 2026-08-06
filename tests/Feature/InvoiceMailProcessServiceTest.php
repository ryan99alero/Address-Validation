<?php

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\MailIntegration;
use App\Services\CarrierInvoiceParserService;
use App\Services\Mail\InvoiceMailProcessService;
use Illuminate\Support\Facades\Storage;

function processService(): InvoiceMailProcessService
{
    return app(InvoiceMailProcessService::class);
}

test('fixed detection returns the integration carrier', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups']);
    $integration = new MailIntegration(['carrier_detection' => MailIntegration::DETECT_FIXED]);
    $integration->setRelation('carrier', $carrier);

    expect(processService()->detectCarrier($integration, null, '/tmp/x.pdf')->slug)->toBe('ups');
});

test('file-content detection reads the carrier from the file', function () {
    $ups = Carrier::factory()->create(['slug' => 'ups']);
    Carrier::factory()->create(['slug' => 'fedex']);

    $path = sys_get_temp_dir().'/ups_'.bin2hex(random_bytes(3)).'.pdf';
    file_put_contents($path, 'XXX UPS Delivery Service Invoice XXX');

    $integration = new MailIntegration(['carrier_detection' => MailIntegration::DETECT_FILE_CONTENT]);

    expect(processService()->detectCarrier($integration, null, $path)->id)->toBe($ups->id);

    unlink($path);
});

test('auto-switches to message-number mode when the server rejects UID commands', function () {
    $integration = MailIntegration::create([
        'name' => 'Zimbra UPS',
        'imap_host' => 'mail.example.com',
        'imap_username' => 'invoices@example.com',
        'imap_sequence' => 'uid',
    ]);

    $method = new ReflectionMethod(processService(), 'handleMailOpFailure');
    $stats = ['mail_warnings' => []];
    $method->invokeArgs(processService(), [
        $integration,
        'mark read',
        new RuntimeException('BAD parse error: command not permitted with UID'),
        &$stats,
    ]);

    expect($integration->fresh()->imap_sequence)->toBe('msgn');
    expect($stats['mail_warnings'][0])->toContain('message-number mode');
});

test('does not switch sequence mode on an unrelated mail error', function () {
    $integration = MailIntegration::create([
        'name' => 'Standard UPS',
        'imap_host' => 'mail.example.com',
        'imap_username' => 'invoices@example.com',
        'imap_sequence' => 'uid',
    ]);

    $method = new ReflectionMethod(processService(), 'handleMailOpFailure');
    $stats = ['mail_warnings' => []];
    $method->invokeArgs(processService(), [
        $integration,
        'move',
        new RuntimeException('Connection timed out'),
        &$stats,
    ]);

    expect($integration->fresh()->imap_sequence)->toBe('uid');
    expect($stats['mail_warnings'][0])->toContain('move:');
});

function fakeAttachment(string $name, string $content): object
{
    return new class($name, $content)
    {
        public function __construct(private string $name, private string $content) {}

        public function getName(): string
        {
            return $this->name;
        }

        public function getContent(): string
        {
            return $this->content;
        }
    };
}

function fakeMessage(array $attachments, int $uid = 1): object
{
    return new class($attachments, $uid)
    {
        public function __construct(private array $attachments, private int $uid) {}

        public function getAttachments(): array
        {
            return $this->attachments;
        }

        public function getUid(): int
        {
            return $this->uid;
        }
    };
}

function runProcessMessage(MailIntegration $integration, object $message): array
{
    $service = app(InvoiceMailProcessService::class);
    $stats = ['messages' => 0, 'invoices' => 0, 'skipped' => 0, 'corrections' => 0, 'errors' => [], 'mail_warnings' => []];
    (new ReflectionMethod($service, 'processMessage'))->invokeArgs($service, [$integration, $message, &$stats]);

    return $stats;
}

test('a raw PDF attachment is ingested directly, not treated as a ZIP', function () {
    Storage::fake('local');
    $carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $invoice = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_date' => '2026-07-29', 'status' => 'pending']);

    // Uppercase .PDF + a lowercase *.pdf pattern proves the match is case-insensitive.
    $parser = Mockery::mock(CarrierInvoiceParserService::class);
    $parser->lastSkipReason = null;
    $parser->shouldReceive('importFile')->once()
        ->withArgs(fn ($carrierId, $path, $name) => $carrierId === $carrier->id && str_ends_with(strtolower($path), '.pdf'))
        ->andReturn([$invoice->id]);
    $this->instance(CarrierInvoiceParserService::class, $parser);

    $integration = MailIntegration::create([
        'name' => 'FedEx', 'imap_host' => 'mail.example.com', 'imap_username' => 'invoices@example.com',
        'carrier_id' => $carrier->id, 'carrier_detection' => MailIntegration::DETECT_FIXED,
        'attachment_pattern' => '*.pdf', 'archive_disk' => 'local', 'archive_base_path' => 'invoices/processed',
    ]);

    $message = fakeMessage([fakeAttachment('FedEx_invoice_2026-07-29_08_38.PDF', 'pdf-bytes')]);
    $stats = runProcessMessage($integration, $message);

    expect($stats['errors'])->toBe([])
        ->and($stats['invoices'])->toBe(1);
});

test('an attachment the engine cannot ingest is skipped with an error, not parsed', function () {
    $carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);

    $parser = Mockery::mock(CarrierInvoiceParserService::class);
    $parser->shouldReceive('importFile')->never();
    $this->instance(CarrierInvoiceParserService::class, $parser);

    $integration = MailIntegration::create([
        'name' => 'FedEx', 'imap_host' => 'mail.example.com', 'imap_username' => 'invoices@example.com',
        'carrier_id' => $carrier->id, 'carrier_detection' => MailIntegration::DETECT_FIXED,
        'attachment_pattern' => '*', // grab everything so the type gate (not the glob) is what filters
    ]);

    $stats = runProcessMessage($integration, fakeMessage([fakeAttachment('signature.png', 'not-an-invoice')]));

    expect($stats['errors'])->toHaveCount(1)
        ->and($stats['errors'][0])->toContain('unsupported attachment type');
});

test('archives the PDF to base/Carrier/Year/Month', function () {
    Storage::fake('local');

    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $integration = MailIntegration::create([
        'name' => 'UPS',
        'imap_host' => 'mail.example.com',
        'imap_username' => 'invoices@example.com',
        'archive_disk' => 'local',
        'archive_base_path' => 'invoices/processed',
    ]);
    $invoice = CarrierInvoice::create([
        'carrier_id' => $carrier->id,
        'filename' => 'Invoice_test.PDF',
        'file_hash' => hash('sha256', 'x'),
        'invoice_date' => '2026-05-23',
        'status' => 'pending',
    ]);

    $src = sys_get_temp_dir().'/Invoice_test.PDF';
    file_put_contents($src, 'pdf-bytes');

    $dest = processService()->archive($integration, $carrier, $invoice, $src);

    expect($dest)->toBe('invoices/processed/UPS/2026/05/Invoice_test.PDF');
    Storage::disk('local')->assertExists($dest);
    expect($invoice->fresh()->archived_path)->toBe($dest);

    unlink($src);
});
