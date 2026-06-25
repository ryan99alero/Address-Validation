<?php

namespace App\Console\Commands;

use App\Contracts\ReportSnapshotProvider;
use App\Filament\Pages\CarrierComparison;
use App\Filament\Pages\CarrierCorrectionAudit;
use App\Filament\Pages\CarrierFeeSummary;
use App\Filament\Pages\CorrectionHotspots;
use App\Models\ReportSnapshot;
use Illuminate\Console\Command;
use Throwable;

class RefreshReportSnapshots extends Command
{
    protected $signature = 'reports:refresh {--report= : Only rebuild a single report key}';

    protected $description = 'Rebuild the pre-computed snapshots that back the heavy report pages';

    /**
     * @var array<int, class-string<ReportSnapshotProvider>>
     */
    private const REPORTS = [
        CarrierComparison::class,
        CarrierFeeSummary::class,
        CarrierCorrectionAudit::class,
        CorrectionHotspots::class,
    ];

    public function handle(): int
    {
        $only = $this->option('report');
        $failed = 0;

        foreach (self::REPORTS as $report) {
            $key = $report::reportKey();

            if ($only && $only !== $key) {
                continue;
            }

            try {
                // Stale combos (from prior filter exploration) go; we rebuild the
                // landing view so a fresh visitor always hits a ready snapshot.
                ReportSnapshot::where('report_key', $key)->delete();

                $filters = $report::defaultFilters();
                $start = microtime(true);
                $data = $report::computeData($filters);
                $snapshot = ReportSnapshot::store($key, $filters, $data, (int) ((microtime(true) - $start) * 1000));

                $this->info(sprintf('%s: %d rows in %d ms', $key, $snapshot->row_count, $snapshot->duration_ms));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('%s failed: %s', $key, $e->getMessage()));
                report($e);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
