<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class MailIntegration extends Model
{
    // How the carrier is determined for each incoming email/attachment.
    public const DETECT_SENDER_DOMAIN = 'sender_domain';

    public const DETECT_FILE_CONTENT = 'file_content';

    public const DETECT_FIXED = 'fixed';

    // IMAP command sequence modes.
    public const SEQ_UID = 'uid';

    public const SEQ_MSGN = 'msgn';

    protected $fillable = [
        'name',
        'is_active',
        'poll_minutes',
        'carrier_id',
        'carrier_detection',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_validate_cert',
        'imap_username',
        'imap_folder',
        'imap_sequence',
        'processed_folder',
        'attachment_pattern',
        'from_filter',
        'subject_filter',
        'credentials',
        'archive_disk',
        'archive_base_path',
        'last_checked_at',
        'last_status',
        'last_error',
        'last_processed_at',
    ];

    /**
     * Friendly poll-frequency options (minutes => label) for the UI.
     *
     * @return array<int, string>
     */
    public static function pollFrequencyOptions(): array
    {
        return [
            0 => 'Manual only',
            15 => 'Every 15 minutes',
            30 => 'Every 30 minutes',
            60 => 'Hourly',
            180 => 'Every 3 hours',
            360 => 'Every 6 hours',
            720 => 'Every 12 hours',
            1440 => 'Daily',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'poll_minutes' => 'integer',
            'imap_port' => 'integer',
            'imap_validate_cert' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_processed_at' => 'datetime',
        ];
    }

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'credentials',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * Get decrypted credentials (imap_password, zip_password).
     *
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
     * Set encrypted credentials.
     *
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

    public function getImapPassword(): ?string
    {
        return $this->getCredential('imap_password');
    }

    /**
     * The static password used to open the carrier's emailed ZIP attachments.
     */
    public function getZipPassword(): ?string
    {
        return $this->getCredential('zip_password');
    }

    public function markChecked(string $status, ?string $error = null): void
    {
        $this->update([
            'last_checked_at' => now(),
            'last_status' => $status,
            'last_error' => $error,
        ]);
    }

    /**
     * Whether the scheduler should process this integration now, based on its
     * poll frequency and when it was last processed.
     */
    public function isDueForPoll(): bool
    {
        if (! $this->is_active || empty($this->poll_minutes)) {
            return false;
        }

        return $this->last_processed_at === null
            || $this->last_processed_at->addMinutes($this->poll_minutes)->isPast();
    }
}
