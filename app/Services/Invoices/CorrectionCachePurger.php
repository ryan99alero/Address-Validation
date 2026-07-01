<?php

namespace App\Services\Invoices;

use Illuminate\Support\Facades\DB;

/**
 * Purges a carrier's originated address-correction cache. The cache is a persistent
 * knowledge base separate from invoices, so deleting invoices doesn't clear it —
 * this lets a carrier's cache be reset before/after a re-import. Corrections still
 * referenced by another carrier's invoice lines are kept so nothing else breaks.
 */
class CorrectionCachePurger
{
    /**
     * @return array{deleted: int, kept: int}
     */
    public function purgeCarrier(int $carrierId): array
    {
        $ids = DB::table('corrected_addresses')
            ->where('first_carrier_id', $carrierId)
            ->pluck('id')
            ->all();

        $referenced = DB::table('carrier_invoice_lines')
            ->whereIn('corrected_address_id', $ids)
            ->distinct()
            ->pluck('corrected_address_id')
            ->all();

        $deletable = array_values(array_diff($ids, $referenced));

        DB::table('address_variants')->whereIn('corrected_address_id', $deletable)->delete();
        DB::table('corrected_addresses')->whereIn('id', $deletable)->delete();

        return ['deleted' => count($deletable), 'kept' => count($referenced)];
    }
}
