<?php

namespace App\Console\Commands;

use App\Models\AccountOwner;
use App\Models\CarrierAccount;
use App\Models\CompanySetting;
use App\Models\Plant;
use App\Models\ShipViaCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the structured account model from today's fragmented data — idempotent and
 * non-destructive. Creates plants, a Company owner, and one carrier_account per distinct
 * (carrier, account_number), then links ship-via codes to them. Owners are only assigned
 * when the stopgap account_owner text is present; blank stays NULL (never guessed as ours —
 * a wrong guess is exactly the "ship on a client's dime" failure). BestWay is untouched;
 * this only populates the new tables for the human to verify.
 */
class BackfillCarrierAccounts extends Command
{
    protected $signature = 'accounts:backfill';

    protected $description = 'Seed plants + carrier accounts + owners from existing ship-via codes and company settings';

    public function handle(): int
    {
        $plants = $this->seedPlants();
        $company = $this->companyOwner();
        [$accounts, $linked, $ownersCreated] = $this->seedAccountsAndLink($company);
        $this->seedCompanySettingAccounts($company);

        $this->info("Plants: {$plants}");
        $this->info("Carrier accounts: {$accounts} | ship-via codes linked: {$linked}");
        $this->info('Owners: 1 company'.($ownersCreated ? " + {$ownersCreated} customer(s) from tagged text" : '').'.');
        $this->info('Accounts still needing an owner: '.CarrierAccount::whereNull('account_owner_id')->count().' (assign in the UI).');

        return self::SUCCESS;
    }

    private function seedPlants(): int
    {
        $codes = ShipViaCode::query()->whereNotNull('plant_id')->distinct()->pluck('plant_id')
            ->merge(DB::table('import_batches')->whereNotNull('bestway_plant_id')->distinct()->pluck('bestway_plant_id'))
            ->map(fn ($c): string => strtoupper(trim((string) $c)))
            ->filter()
            ->unique();

        foreach ($codes as $code) {
            Plant::firstOrCreate(['code' => $code]);
        }

        return $codes->count();
    }

    private function companyOwner(): AccountOwner
    {
        $name = CompanySetting::instance()->company_name ?: 'Our Company';

        return AccountOwner::firstOrCreate(
            ['type' => AccountOwner::TYPE_COMPANY],
            ['name' => $name],
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int} [accounts created, codes linked, customer owners created]
     */
    private function seedAccountsAndLink(AccountOwner $company): array
    {
        $accounts = 0;
        $linked = 0;
        $customerOwners = 0;

        $rows = ShipViaCode::query()
            ->where('payment_type', ShipViaCode::PAYMENT_SENDER)
            ->whereNotNull('account_number')
            ->where('account_number', '<>', '')
            ->get(['id', 'carrier_id', 'account_number', 'account_owner', 'plant_id']);

        foreach ($rows->groupBy(fn (ShipViaCode $c): string => $c->carrier_id.'|'.strtoupper(trim((string) $c->account_number))) as $group) {
            $first = $group->first();
            $number = strtoupper(trim((string) $first->account_number));

            $ownerId = $this->resolveOwnerId($group->pluck('account_owner')->filter()->first(), $company, $customerOwners);

            $account = CarrierAccount::firstOrCreate(
                ['carrier_id' => $first->carrier_id, 'account_number' => $number],
                [
                    'account_owner_id' => $ownerId,
                    'nickname' => trim(optional($first->carrier)->name.' '.$number),
                ],
            );

            if ($account->wasRecentlyCreated) {
                $accounts++;
            }

            $linked += ShipViaCode::whereIn('id', $group->pluck('id'))
                ->whereNull('carrier_account_id')
                ->update(['carrier_account_id' => $account->id]);
        }

        return [$accounts, $linked, $customerOwners];
    }

    /**
     * Map a stopgap account_owner text to an owner id: blank → null (never guessed); a match
     * on the company name → the company owner; anything else → a customer owner by that name.
     */
    private function resolveOwnerId(?string $text, AccountOwner $company, int &$customerOwners): ?int
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $canon = fn (string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower($s));
        if ($canon($text) !== '' && str_contains($canon($company->name), $canon($text))) {
            return $company->id;
        }

        $owner = AccountOwner::firstOrCreate(
            ['name' => $text],
            ['type' => AccountOwner::TYPE_CUSTOMER],
        );
        if ($owner->wasRecentlyCreated) {
            $customerOwners++;
        }

        return $owner->id;
    }

    private function seedCompanySettingAccounts(AccountOwner $company): void
    {
        $settings = CompanySetting::instance();
        $map = ['ups' => $settings->ups_account_number, 'fedex' => $settings->fedex_account_number];

        foreach ($map as $slug => $number) {
            $number = strtoupper(trim((string) $number));
            if ($number === '') {
                continue;
            }
            $carrierId = DB::table('carriers')->where('slug', $slug)->value('id');
            if (! $carrierId) {
                continue;
            }
            CarrierAccount::firstOrCreate(
                ['carrier_id' => $carrierId, 'account_number' => $number],
                ['account_owner_id' => $company->id, 'nickname' => strtoupper($slug)." {$number}"],
            );
        }
    }
}
