<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class FolderIntegration extends Model
{
    public const TYPE_LOCAL = 'local';

    public const TYPE_SMB = 'smb';

    protected $fillable = [
        'name',
        'is_active',
        'carrier_id',
        'connection_type',
        'base_path',
        'smb_host',
        'smb_share',
        'credentials',
        'recursive',
        'prefer_csv',
        'file_pattern',
        'poll_minutes',
        'last_processed_at',
        'last_checked_at',
        'last_status',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'recursive' => 'boolean',
            'prefer_csv' => 'boolean',
            'poll_minutes' => 'integer',
            'last_processed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @var array<int, string>
     */
    protected $hidden = ['credentials'];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCredentials(): array
    {
        if (empty($this->credentials)) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($this->credentials), true) ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function setCredentials(array $credentials): void
    {
        $this->credentials = Crypt::encryptString(json_encode($credentials));
    }

    public function getCredential(string $key, mixed $default = null): mixed
    {
        return $this->getCredentials()[$key] ?? $default;
    }

    /**
     * @return array<int, string>
     */
    public function extensions(): array
    {
        return collect(explode(',', (string) $this->file_pattern))
            ->map(fn (string $p): string => strtolower(trim(ltrim($p, '*.'))))
            ->filter()
            ->values()
            ->all();
    }

    public function markChecked(string $status, ?string $error = null): void
    {
        $this->update(['last_checked_at' => now(), 'last_status' => $status, 'last_error' => $error]);
    }
}
