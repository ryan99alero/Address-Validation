<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Populates zip_centroids from GeoNames US postal data (public domain) — the static ZIP → lat/lng
 * table the heatmaps join to. Re-runnable (upsert). Downloads US.zip by default, or reads a local
 * file if the environment can't fetch it: `php artisan zipcentroids:import --file=/path/US.txt`.
 */
class ImportZipCentroids extends Command
{
    protected $signature = 'zipcentroids:import
        {--file= : Local path to a GeoNames US.txt or US.zip (skips the download)}
        {--url=https://download.geonames.org/export/zip/US.zip : Source URL when no --file is given}';

    protected $description = 'Import US ZIP centroids (GeoNames) into zip_centroids for the heatmaps';

    public function handle(): int
    {
        $source = $this->option('file');

        if (! $source) {
            $url = (string) $this->option('url');
            $this->info("Downloading {$url} …");
            $response = Http::timeout(180)->get($url);
            if ($response->failed()) {
                $this->error('Download failed. Fetch it manually and pass --file=US.zip (or US.txt).');

                return self::FAILURE;
            }
            $source = tempnam(sys_get_temp_dir(), 'zipc_').'.zip';
            file_put_contents($source, $response->body());
        }

        $txtPath = $this->resolveTextFile($source);
        if ($txtPath === null || ! is_readable($txtPath)) {
            $this->error('Could not read US.txt from the given source.');

            return self::FAILURE;
        }

        $handle = fopen($txtPath, 'r');
        if ($handle === false) {
            $this->error("Unable to open {$txtPath}.");

            return self::FAILURE;
        }

        $seen = [];
        $batch = [];
        $count = 0;
        // GeoNames US.txt is tab-delimited: 0 country, 1 postal, 2 place, 4 state(admin_code1),
        // 9 latitude, 10 longitude. Multiple rows per ZIP (one per place) — keep the first.
        while (($line = fgets($handle)) !== false) {
            $cols = explode("\t", rtrim($line, "\r\n"));
            if (count($cols) < 11) {
                continue;
            }
            $zip = $cols[1];
            if (preg_match('/^\d{5}$/', $zip) !== 1 || isset($seen[$zip])) {
                continue;
            }
            $seen[$zip] = true;
            $batch[] = [
                'zip' => $zip,
                'lat' => (float) $cols[9],
                'lng' => (float) $cols[10],
                'city' => mb_substr(trim($cols[2]), 0, 100) ?: null,
                'state' => mb_substr(trim($cols[4]), 0, 2) ?: null,
            ];
            if (count($batch) >= 1000) {
                $count += $this->flush($batch);
                $batch = [];
                $this->output->write('.');
            }
        }
        if ($batch !== []) {
            $count += $this->flush($batch);
        }
        fclose($handle);

        $this->newLine();
        $this->info("Imported/updated {$count} ZIP centroids.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function flush(array $batch): int
    {
        DB::table('zip_centroids')->upsert($batch, ['zip'], ['lat', 'lng', 'city', 'state']);

        return count($batch);
    }

    /**
     * Return a path to the tab-delimited US.txt, extracting it from a .zip if needed.
     */
    private function resolveTextFile(string $source): ?string
    {
        if (! str_ends_with(strtolower($source), '.zip')) {
            return $source;
        }

        $zip = new ZipArchive;
        if ($zip->open($source) !== true) {
            return null;
        }
        $dir = sys_get_temp_dir().'/zipc_'.uniqid();
        @mkdir($dir);
        $zip->extractTo($dir, 'US.txt');
        $zip->close();

        $extracted = $dir.'/US.txt';

        return is_readable($extracted) ? $extracted : null;
    }
}
