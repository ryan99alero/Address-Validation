<?php

return [
    // A correction whose two ZIPs are farther apart than this (miles) is suspect — routed to human
    // review instead of auto-threaded. Legitimate ZIP reshuffles (e.g. Irvine) are local.
    'guard_distance_miles' => (int) env('CORRECTION_GUARD_DISTANCE_MILES', 50),

    // A state-changing correction farther apart than this is treated as garbage (carrier "corrected"
    // to a different place — e.g. Houston TX -> Arkansas) and rejected/deactivated, never applied.
    'garbage_distance_miles' => (int) env('CORRECTION_GARBAGE_DISTANCE_MILES', 200),

    // Stop auto-threading a pair once this many applied flips exist between them (either direction) —
    // a carrier that keeps reversing goes to human review instead of oscillating forever.
    'flip_flop_threshold' => (int) env('CORRECTION_FLIPFLOP_THRESHOLD', 2),

    // Phase 3: detect + thread re-corrections at invoice-ingest time so the cache converges instead of
    // fragmenting. Kill-switch — set CORRECTION_INGEST_THREADING=false to fall back to the old
    // file-and-forget behavior instantly (no deploy).
    'ingest_threading' => (bool) env('CORRECTION_INGEST_THREADING', true),

    // Phase 4: a good address is trusted as fee-free for a carrier until this many days after its last
    // clean confirmation; past that the nightly reverify job re-checks it against the carrier's API.
    'verification_max_age_days' => (int) env('CORRECTION_VERIFICATION_MAX_AGE_DAYS', 365),

    // Max addresses the nightly reverify dispatches per carrier per run (drains a big backlog over time
    // without API-storming). 0 = disable the reverify job entirely.
    'verification_daily_limit' => (int) env('CORRECTION_VERIFICATION_DAILY_LIMIT', 50),
];
