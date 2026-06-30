<?php

namespace App\Services\Invoices;

use App\Models\FolderIntegration;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\IShare;
use Icewind\SMB\ServerFactory;
use RuntimeException;
use Throwable;

/**
 * Direct SMB/CIFS access for Folder Integrations — no OS mount required. Wraps
 * icewind/smb (which uses the smbclient binary or libsmbclient-php) so a share
 * can be configured entirely from the GUI with host + share + credentials.
 */
class SmbInvoiceReader
{
    public function share(FolderIntegration $folder): IShare
    {
        [$workgroup, $username] = $this->splitDomain((string) $folder->getCredential('smb_username'));

        try {
            $auth = new BasicAuth($username, $workgroup, (string) $folder->getCredential('smb_password'));
            $server = (new ServerFactory)->createServer((string) $folder->smb_host, $auth);

            return $server->getShare((string) $folder->smb_share);
        } catch (Throwable $e) {
            throw new RuntimeException('SMB connection failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * List remote file paths (within the share) matching the extensions.
     *
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    public function listFiles(FolderIntegration $folder, array $extensions, bool $recursive): array
    {
        $share = $this->share($folder);
        $files = $this->collect($share, trim((string) $folder->base_path, '/'), $extensions, $recursive);
        sort($files);

        return $files;
    }

    public function download(FolderIntegration $folder, string $remotePath, string $localPath): void
    {
        $this->share($folder)->get($remotePath, $localPath);
    }

    /**
     * Connect and list the base folder — returns the entry count, throws on any
     * failure (auth, host, share, path). Used by the GUI "Test connection".
     */
    public function testConnection(FolderIntegration $folder): int
    {
        try {
            return count($this->share($folder)->dir(trim((string) $folder->base_path, '/')));
        } catch (Throwable $e) {
            throw new RuntimeException($this->friendly($e), 0, $e);
        }
    }

    /**
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    private function collect(IShare $share, string $path, array $extensions, bool $recursive): array
    {
        $files = [];

        foreach ($share->dir($path) as $info) {
            if ($info->isDirectory()) {
                if ($recursive) {
                    $files = array_merge($files, $this->collect($share, $info->getPath(), $extensions, true));
                }

                continue;
            }

            if (in_array(strtolower(pathinfo($info->getName(), PATHINFO_EXTENSION)), $extensions, true)) {
                $files[] = $info->getPath();
            }
        }

        return $files;
    }

    /**
     * Split DOMAIN\user (or user) into [workgroup, username].
     *
     * @return array{0: string, 1: string}
     */
    private function splitDomain(string $username): array
    {
        if (str_contains($username, '\\')) {
            [$domain, $user] = explode('\\', $username, 2);

            return [$domain, $user];
        }

        return ['', $username];
    }

    private function friendly(Throwable $e): string
    {
        $class = class_basename($e->getPrevious() ?? $e);
        $message = $e->getMessage() ?: (string) ($e->getPrevious()?->getMessage() ?? '');

        return match (true) {
            // smbclient maps "authenticated but no rights" to ACCESS_DENIED, and the
            // wrapper surfaces it as ConnectionRefused/Forbidden — almost always a
            // missing AD domain on the username.
            $class === 'ForbiddenException' || $class === 'ConnectionRefusedException' || str_contains($message, 'ACCESS_DENIED') => 'Access denied. The account authenticated but lacks permission to the share/path — for an Active Directory account, set the Username as DOMAIN\\username (e.g. RAND\\jdoe).',
            $class === 'AuthenticationException' || str_contains($message, 'NT_STATUS_LOGON_FAILURE') => 'Authentication failed — check the password and use DOMAIN\\username for an AD account.',
            str_contains($message, 'NT_STATUS_BAD_NETWORK_NAME') => 'Share not found — check the Share / UNC Root.',
            $class === 'NotFoundException' || str_contains($message, 'NOT_FOUND') => 'Path within the share not found — check the Path within Share.',
            str_contains($message, 'NT_STATUS_HOST') || str_contains($message, 'getaddrinfo') || str_contains($message, 'Connection to') => 'Could not reach the server — check the Server Name / IP.',
            str_contains($message, 'smbclient') || str_contains($message, 'libsmbclient') => 'SMB client not available on the server.',
            default => $message !== '' ? $message : 'SMB error ('.$class.') — for an AD account, try Username = DOMAIN\\username.',
        };
    }
}
