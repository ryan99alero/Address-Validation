<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Gate a Filament Resource or Page to admins only. canAccess() controls BOTH the
 * navigation visibility and direct-URL access (a non-admin gets a 403), so a
 * regular LDAP user never sees or reaches it. Admin status comes from the
 * is_admin column, synced from the configured LDAP admin group on login.
 */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        return Auth::user()?->isAdmin() ?? false;
    }
}
