<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Build one UPS Billing Data CSV row (fixed 250-column layout) with only the columns the
 * importer reads populated.
 *
 * @return array<int, string>
 */
function upsRow(string $invNumber, string $invDate, string $tracking, string $amount, string $shipDate, string $code = 'ISS'): array
{
    $row = array_fill(0, 82, '');
    $row[1] = '0000691317';   // account
    $row[4] = $invDate;       // invoice date
    $row[5] = $invNumber;     // invoice number
    $row[11] = $shipDate;     // ship date
    $row[13] = $tracking;     // tracking
    $row[28] = '1.0';         // weight
    $row[33] = '005';         // zone
    $row[35] = $code;         // charge category detail code
    $row[45] = 'Ground Commercial'; // description
    $row[52] = $amount;       // net amount

    return $row;
}

/**
 * @param  array<int, array<int, string>>  $rows
 */
function writeUpsCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'upscsv_').'.csv';
    $h = fopen($path, 'w');
    foreach ($rows as $row) {
        fputcsv($h, $row, ',', '"', '');
    }
    fclose($h);

    return $path;
}
