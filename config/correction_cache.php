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
];
