<?php

namespace App\Services\Mail;

use App\Models\MailIntegration;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;

class MailboxService
{
    /**
     * Build a (not-yet-connected) IMAP client from a MailIntegration's stored config.
     */
    public function clientFor(MailIntegration $integration): Client
    {
        // UID is the standard, most robust sequence and works on most servers.
        // Some servers (e.g. Zimbra) reject UID STORE/EXPUNGE with "command not
        // permitted with UID"; those integrations are auto-switched to message
        // numbers, honored here.
        $sequence = $integration->imap_sequence === MailIntegration::SEQ_MSGN
            ? IMAP::ST_MSGN
            : IMAP::ST_UID;

        config(['imap.options.sequence' => $sequence]);

        return (new ClientManager)->make([
            'host' => $integration->imap_host,
            'port' => $integration->imap_port,
            'encryption' => $integration->imap_encryption === 'none' ? false : $integration->imap_encryption,
            'validate_cert' => $integration->imap_validate_cert,
            'username' => $integration->imap_username,
            'password' => $integration->getImapPassword(),
            'protocol' => 'imap',
            'options' => ['sequence' => $sequence],
        ]);
    }

    /**
     * Attempt to connect and reach the configured folder.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(MailIntegration $integration): array
    {
        try {
            $client = $this->clientFor($integration);
            $client->connect();

            $folderName = $integration->imap_folder ?: 'INBOX';
            $folder = $this->resolveFolder($client, $folderName);

            if (! $folder) {
                $available = implode(', ', $this->availableFolderPaths($client));
                $client->disconnect();
                $message = "Connected, but folder '{$folderName}' was not found. Available folders: {$available}";
                $integration->markChecked('error', $message);

                return ['ok' => false, 'message' => $message];
            }

            $client->disconnect();
            $integration->markChecked('ok');

            return ['ok' => true, 'message' => "Connected successfully. Folder '{$folder->path}' is reachable."];
        } catch (Throwable $e) {
            $integration->markChecked('error', $e->getMessage());

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Return the server's advertised IMAP capabilities (CAPABILITY response).
     *
     * @return array<int, string>
     */
    public function capabilities(MailIntegration $integration): array
    {
        $client = $this->clientFor($integration);
        $client->connect();

        try {
            $caps = $client->getConnection()->getCapabilities()->validatedData();
        } finally {
            $client->disconnect();
        }

        return array_values(array_filter(array_map('strval', is_array($caps) ? $caps : [])));
    }

    /**
     * Resolve a folder by name, tolerating the INBOX special case and the
     * mailbox display-name vs IMAP-path / casing differences servers expose.
     */
    public function resolveFolder(Client $client, string $name): ?Folder
    {
        if ($folder = $client->getFolderByPath($name)) {
            return $folder;
        }

        // The inbox's IMAP path is canonically "INBOX" regardless of UI label.
        if (strcasecmp($name, 'inbox') === 0 && ($folder = $client->getFolderByPath('INBOX'))) {
            return $folder;
        }

        // Fall back to a flat, case-insensitive search over all folders.
        foreach ($client->getFolders(false) as $folder) {
            if (strcasecmp($folder->path ?? '', $name) === 0 || strcasecmp($folder->name ?? '', $name) === 0) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function availableFolderPaths(Client $client): array
    {
        return $client->getFolders(false)
            ->map(fn (Folder $folder): string => $folder->path)
            ->all();
    }
}
