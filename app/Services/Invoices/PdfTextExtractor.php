<?php

namespace App\Services\Invoices;

/**
 * Extracts the visible text from a (FlateDecode) PDF using only PHP's built-in
 * zlib — no external dependency. UPS invoices are Ricoh AFP2PDF text PDFs whose
 * content streams compress cleanly and decode to readable text.
 */
class PdfTextExtractor
{
    public function extractFile(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return $this->extract((string) file_get_contents($path));
    }

    public function extract(string $raw): string
    {
        $out = '';

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                $data = $this->inflate($stream);
                if ($data === false) {
                    continue;
                }

                // Pull text out of (..)Tj / [..]TJ show-text operators.
                if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $data, $textMatches)) {
                    foreach ($textMatches[0] as $token) {
                        $text = substr($token, 1, -1);
                        $text = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $text);
                        $out .= $text.' ';
                    }
                }
            }
        }

        return trim(preg_replace('/\s+/', ' ', $out));
    }

    /**
     * Inflate a PDF stream, trying zlib then raw deflate. Non-deflate streams
     * (e.g. embedded images) simply return false without emitting warnings.
     */
    private function inflate(string $stream): string|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            $data = gzuncompress($stream);
            if ($data === false) {
                $data = gzinflate(substr($stream, 2));
            }
            if ($data === false) {
                $data = gzinflate($stream);
            }

            return $data;
        } finally {
            restore_error_handler();
        }
    }
}
