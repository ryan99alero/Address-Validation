<?php

namespace App\Services\Mail;

use ZipArchive;

class ZipExtractor
{
    /**
     * Extract a (optionally password-protected) ZIP to a destination directory.
     *
     * UPS emails ZipCrypto-protected archives, which PHP's ZipArchive can open
     * with a password. AES-encrypted archives are not supported by libzip here
     * and would require 7z (not installed) — that case is reported as an error.
     *
     * @return array{ok: bool, files: array<int, string>, error: ?string}
     */
    public function extract(string $zipPath, string $destDir, ?string $password = null): array
    {
        if (! is_file($zipPath)) {
            return ['ok' => false, 'files' => [], 'error' => "ZIP not found: {$zipPath}"];
        }

        if (! is_dir($destDir) && ! mkdir($destDir, 0775, true) && ! is_dir($destDir)) {
            return ['ok' => false, 'files' => [], 'error' => "Could not create destination: {$destDir}"];
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            return ['ok' => false, 'files' => [], 'error' => "Could not open ZIP (error code {$opened})"];
        }

        if ($password !== null && $password !== '') {
            $zip->setPassword($password);
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $names[] = $name;
            }
        }

        $extracted = $zip->extractTo($destDir);
        $zip->close();

        if (! $extracted) {
            return [
                'ok' => false,
                'files' => [],
                'error' => 'Extraction failed — wrong password or unsupported encryption. '
                    .'UPS uses ZipCrypto (supported); AES-encrypted ZIPs need 7z, which is not installed.',
            ];
        }

        $files = [];
        foreach ($names as $name) {
            $path = rtrim($destDir, '/').'/'.$name;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return ['ok' => true, 'files' => $files, 'error' => null];
    }
}
