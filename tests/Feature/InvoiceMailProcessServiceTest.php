<?php

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\MailIntegration;
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
