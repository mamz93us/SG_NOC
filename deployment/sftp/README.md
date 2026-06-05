# SFTP-inbox → Azure Blob device backups

Network devices (Sophos firewalls, Grandstream UCM, switches, …) push their own
backup files into a **chrooted, SFTP-only inbox** on the NOC VM. A scheduled
Laravel command sweeps the inbox, streams each file up to **Azure Blob**, and
deletes the local copy once the upload is verified — so the inbox can never fill
the VM disk.

```
   ┌────────────┐   SFTP push    ┌──────────────────────────┐   stream    ┌────────────┐
   │  Sophos /  │ ─────────────► │  NOC  /srv/sftp-backups/  │ ──────────► │ Azure Blob │
   │  UCM / SW  │   (key/pass)   │       inbox/<source>/     │  + verify   │ sftp-backups/ │
   └────────────┘                └──────────────────────────┘   + delete  └────────────┘
                                   chroot, internal-sftp only      local
                                                                ▲
                                       php artisan sftp-backups:sweep  (every 5 min via scheduler)
```

Two halves:

| Half | Where | What |
|---|---|---|
| **SFTP service** | system-level on the VPS | sshd chroot + an SFTP-only user. Set up with `setup-sftp.sh`. |
| **Sweeper + prune** | Laravel app | `sftp-backups:sweep` uploads + clears; `sftp-backups:prune` enforces Azure retention. Wired into the scheduler. |

---

## 1. Stand up the SFTP service (on the NOC VM)

Deploy is `git pull` on the VPS, then run the setup script **as root**:

```sh
cd /home/azureuser/phonebook2
sudo bash deployment/sftp/setup-sftp.sh
```

It is idempotent (safe to re-run) and does this:

1. Creates group **`sftpbackup`** and SFTP-only user **`backuppush`** (no shell,
   locked password, home `/` so the session starts at the chroot root).
2. Adds the app user (**`azureuser`** — the user the scheduler runs as) to
   `sftpbackup` so the sweeper can read and delete inbox files.
3. Creates the chroot `**/srv/sftp-backups**` (`root:root 755`) and the writable
   `**/srv/sftp-backups/inbox**` (`backuppush:sftpbackup 2770`, setgid).
4. Creates `/etc/ssh/sftp-authorized-keys/` (device keys live **outside** the
   chroot — sshd reads them before chroot'ing).
5. Writes `/etc/ssh/sshd_config.d/60-sftp-backup.conf` (the `Match Group` block
   from [`sshd_sftp.conf`](sshd_sftp.conf)), runs `sshd -t`, and reloads sshd.

Override any default via env:

```sh
sudo SFTP_USER=backuppush APP_USER=azureuser SFTP_CHROOT=/srv/sftp-backups \
     bash deployment/sftp/setup-sftp.sh
```

> **Why a chroot subfolder?** sshd refuses to chroot into a directory that is
> group/world-writable, so the chroot root itself must stay `root:root`. Devices
> therefore write into the `inbox/` subfolder, never the chroot root.

### Give a device access

**Key auth (preferred):** install the device's public key at
`/etc/ssh/sftp-authorized-keys/backuppush` (`root:root`, `chmod 644`).

**Password auth (only if a device can't do keys):** `sudo passwd backuppush`,
uncomment `PasswordAuthentication yes` in `60-sftp-backup.conf`, then
`sudo sshd -t && sudo systemctl reload ssh`. This scopes password auth to the
`sftpbackup` group only — it does **not** enable it server-wide.

### Group backups by source (optional but recommended)

Create one subfolder per device so backups are organised in Azure:

```sh
sudo -u backuppush mkdir -p /srv/sftp-backups/inbox/sophos-jed \
                            /srv/sftp-backups/inbox/ucm-cai
```

The sweeper records the **first** path segment under `inbox/` as the backup
`source` and preserves the rest of the path in the Azure blob key.

---

## 2. Point the app at the inbox

In the NOC `.env` (see [`.env.example`](../../.env.example) for the full list):

```dotenv
SFTP_BACKUP_INBOX=/srv/sftp-backups/inbox
SFTP_BACKUP_SWEEP_INTERVAL=5          # minutes between sweeps
SFTP_BACKUP_STABILITY_SECONDS=120     # ignore files modified in the last N s (partial-upload guard)
SFTP_BACKUP_DELETE_AFTER_UPLOAD=true  # clear the local copy once the upload is verified
SFTP_BACKUP_RETENTION_DAYS=           # blank = keep Azure blobs forever; set N to auto-prune
# AZURE_BLOB_BACKUP_CONTAINER=        # optional: isolate backups in their own container
```

Azure credentials are shared with the other `azure_*` disks and are read from
**Admin → Settings → Azure Blob** (or `AZURE_BLOB_ACCOUNT` / `AZURE_BLOB_KEY`).
Backups land on the **`azure_backups`** disk (see `config/filesystems.php`) under
the `sftp-backups/` prefix, e.g. `sftp-backups/sophos-jed/20260604-031500-config.tar.gz`.

Then:

```sh
php artisan config:cache
php artisan migrate          # creates the sftp_backups audit table
```

Make sure the scheduler is alive (it already is, via
`deployment/supervisor/` running `php artisan schedule:run`). The two new jobs:

| Command | Schedule | Purpose |
|---|---|---|
| `sftp-backups:sweep` | every `SFTP_BACKUP_SWEEP_INTERVAL` min | upload stable inbox files → Azure, then delete local |
| `sftp-backups:prune` | daily 02:45 | delete Azure blobs older than `SFTP_BACKUP_RETENTION_DAYS` (no-op if unset) |

Run them by hand any time:

```sh
php artisan sftp-backups:sweep --dry-run     # show what would upload, touch nothing
php artisan sftp-backups:sweep               # upload for real
php artisan sftp-backups:sweep --keep        # upload but DON'T delete local (debugging)
php artisan sftp-backups:prune --dry-run     # show what retention would delete
```

---

## 3. Device-side examples

How a few common devices push to the inbox (host `noc.samirgroup.net`, user
`backuppush`, target `inbox/<source>/`):

**Generic client (sftp / lftp / scp):**

```sh
sftp backuppush@noc.samirgroup.net <<'EOF'
put /backups/config-$(date +%F).tar.gz inbox/myhost/
EOF

# or
scp /backups/config.tar.gz backuppush@noc.samirgroup.net:inbox/myhost/
```

**Sophos firewall:** *Backup & firmware → Backup → Schedule*, mode **FTP/SFTP**
(use SFTP), host = NOC, username `backuppush`, path `inbox/sophos-<branch>/`.

**Cisco IOS switch:**

```
copy running-config scp://backuppush@noc.samirgroup.net/inbox/sw-<name>/running-config
```

**Grandstream UCM:** *Maintenance → Backup → Data Sync*, protocol SFTP, the same
host/user, remote path `inbox/ucm-<branch>/`.

> **Tip — atomic uploads.** A well-behaved client uploads to a temp name and
> renames on completion. The sweeper already ignores `*.part`, `*.filepart`,
> `*.tmp`, `*.partial` and waits out `SFTP_BACKUP_STABILITY_SECONDS`, so a
> mid-flight push is never grabbed half-written.

---

## 4. The `sftp_backups` audit table

One row per file, keyed by a deterministic blob path (`source` subfolder + file
mtime + name), which makes the sweep idempotent:

- `status`: `uploaded` | `failed` | `skipped` | `pruned`
- `source`, `relative_path`, `filename`, `size`, `sha256`
- `disk`, `azure_path`, `received_at` (file mtime), `uploaded_at`, `pruned_at`

A failed upload keeps the local file and retries next tick; repeated failures
raise a single open **NocEvent** (`source_type=sftp_backup_failed`) so the
existing notification rules fire, and it auto-resolves after a clean sweep.

---

## 5. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Client connects then disconnects; `/var/log/auth.log` shows *"bad ownership or modes for chroot directory"* | A path component of the chroot is writable by group/other. `chown root:root /srv/sftp-backups && chmod 755 /srv/sftp-backups`. The **inbox** subfolder is the only writable part. |
| `Permission denied` on `put` | Device is writing to the chroot root, not `inbox/`. Prefix the remote path with `inbox/<source>/`. |
| Sweep logs *"Backup disk [azure_backups] is not ready"* | Azure account/key not set. Configure **Admin → Settings → Azure Blob** (or the `AZURE_BLOB_*` env). Files stay in the inbox until it's configured — no data loss. |
| `RecursiveDirectoryIterator(...inbox): Permission denied` | The app user's session/scheduler started **before** it joined `sftpbackup`, so it can't read the `2770` inbox yet. `setup-sftp.sh` now also sets an ACL (`setfacl -R -m u:<app>:rwx <inbox>` + a default ACL) that fixes this instantly; otherwise start a fresh login or `sudo systemctl restart supervisor`. One-off without re-login: `sg sftpbackup -c 'php artisan sftp-backups:sweep --dry-run'`. |
| Sweep logs *"uploaded, but local delete failed"* | The inbox lost its `2770`/setgid or the app user's ACL/group grant. Re-run `setup-sftp.sh` (it re-applies the ACL). |
| Files never picked up | They're newer than `SFTP_BACKUP_STABILITY_SECONDS`, match an ignored suffix, or exceed `SFTP_BACKUP_MAX_BYTES`. Check `php artisan sftp-backups:sweep --dry-run`. |
| Validate sshd after any change | `sudo sshd -t` **before** reloading; a bad `Match` block can lock everyone out. |

### Watch the disk

The inbox is staging only and is cleared after each upload, but if Azure is
mis-configured (or a device floods huge files), it can still grow. Keep an eye
on it — an un-pruned spool is the classic way a branch VM fills up:

```sh
du -sh /srv/sftp-backups/inbox
df -h /srv
```

---

## 6. Security notes

- The `backuppush` account has **no shell** (`internal-sftp` ForceCommand) and a
  **locked password** by default — key auth only unless you opt into passwords.
- It is **chrooted**: it can only see `/srv/sftp-backups`, nothing else on the VM.
- `AllowTcpForwarding`/`X11Forwarding`/`PermitTunnel` are all off — no port
  forwarding or tunnelling through this account.
- One shared push account is the simplest model. For stronger isolation, create
  one user per device (each chrooted to its own subtree); they can all share the
  `sftpbackup` group so the sweeper logic is unchanged.
