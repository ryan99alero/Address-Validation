<?php

use App\Services\Mail\ZipExtractor;

beforeEach(function () {
    $this->work = sys_get_temp_dir().'/zipextractor_'.bin2hex(random_bytes(4));
    mkdir($this->work, 0775, true);
});

afterEach(function () {
    $dir = $this->work;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
});

test('extracts a plain zip', function () {
    $zipPath = $this->work.'/plain.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('Invoice_1.PDF', 'hello-pdf');
    $zip->close();

    $result = (new ZipExtractor)->extract($zipPath, $this->work.'/out');

    expect($result['ok'])->toBeTrue();
    expect($result['files'])->toHaveCount(1);
    expect(file_get_contents($result['files'][0]))->toBe('hello-pdf');
});

test('returns an error for a missing zip', function () {
    $result = (new ZipExtractor)->extract($this->work.'/nope.zip', $this->work.'/out');

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('not found');
});

test('extracts a password-protected zip when the platform supports it', function () {
    $zipPath = $this->work.'/secret.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('Invoice_1.PDF', 'secret-pdf');
    $encrypted = $zip->setEncryptionName('Invoice_1.PDF', ZipArchive::EM_AES_256, 'static-pw');
    $zip->close();

    if (! $encrypted) {
        $this->markTestSkipped('libzip on this platform cannot create encrypted ZIPs');
    }

    $result = (new ZipExtractor)->extract($zipPath, $this->work.'/out', 'static-pw');

    expect($result['ok'])->toBeTrue();
    expect(file_get_contents($result['files'][0]))->toBe('secret-pdf');
});
