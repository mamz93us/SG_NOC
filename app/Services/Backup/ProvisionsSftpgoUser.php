<?php

namespace App\Services\Backup;

/**
 * Something the NOC provisions an SFTPGo virtual user for.
 *
 * Extracted so SftpgoApiService can serve two unrelated things without either
 * knowing about the other: a `BackupAccount` (a firewall pushing its nightly
 * config) and an `ArchiveScanEndpoint` of type `folder` (a copier writing scans).
 * Both want one upload-only SFTP login with a quota and a home directory, and
 * nothing else about them is alike.
 *
 * An interface rather than widening the client's type hints to a union: a union
 * would have SftpgoApiService importing an archive model to manage a firewall
 * backup, and every later caller would widen it again.
 *
 * Implementors must keep `sftpgoUsername()` stable for the life of the row. It is
 * the account's identity on the SFTPGo side and the first path segment of every
 * file it uploads, so changing it orphans both the remote user and any files
 * already waiting in its old home.
 */
interface ProvisionsSftpgoUser
{
    /** The SFTPGo login. Stable for the life of the row — see the class note. */
    public function sftpgoUsername(): string;

    /** Absolute path of the account's home on the NOC. */
    public function homeDir(): string;

    /** Quota in bytes; 0 means unlimited. */
    public function quotaBytes(): int;

    /**
     * SFTPGo protocol names this account may use.
     *
     * @return array<int,string>
     */
    public function allowedProtocols(): array;

    /**
     * Whether SFTPGo should accept uploads for it right now.
     *
     * Separate from deleting the user: a disabled account keeps its home, its
     * history and its password, and simply refuses the next push — which is what
     * you want while a device is being replaced.
     */
    public function isEnabledForSftpgo(): bool;
}
