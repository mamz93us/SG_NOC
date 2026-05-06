# Ansible playbooks — SG_NOC branch VMs

Two playbooks:

- **`install-branch.yml`** — one-shot bootstrap of a fresh Ubuntu 24.04
  branch VM. Clones the repo, writes `/etc/sg-noc-branch.env`, runs
  `install.sh`, prints back the generated DB password and API token.
- **`update-all.yml`** — daily-ops playbook. Pulls latest code on every
  branch and runs `update.sh` to apply config and restart services.
  Rolls 3 hosts at a time so a bad commit can't down the whole estate.

## Setup

```bash
# On your laptop (or the NOC VM):
sudo apt-get install -y ansible

cd deployment/branch-vm/ansible
cp inventory.yml.example inventory.yml
# Edit inventory.yml with each branch VM's tunnel IP and SSH key path.

# Smoke-test connectivity before bootstrapping anything
ansible -i inventory.yml branch-vms -m ping
```

## Bootstrap a single new branch

```bash
ansible-playbook -i inventory.yml install-branch.yml --limit jed-noc \
    -e "noc_allowed_cidr=10.0.0.0/8 retention_days=60 timezone=Asia/Riyadh"
```

Save the API token printed at the end into the NOC's Laravel `.env` as
`BRANCH_API_TOKEN_JED` (uppercase the branch_id).

## Update all branches

```bash
ansible-playbook -i inventory.yml update-all.yml
```

## Update one branch

```bash
ansible-playbook -i inventory.yml update-all.yml --limit ryd-noc
```

## Rollback

If a `git pull` brings something broken, roll back manually on each VM
(or via Ansible ad-hoc):

```bash
ansible -i inventory.yml branch-vms -b -a \
    "git -C /opt/sg-noc reset --hard <last-good-sha>"
ansible-playbook -i inventory.yml update-all.yml
```

## SSH key reuse

The example inventory points to `~/.ssh/sg-noc-branches.pem`. Generate
one key shared across branches OR list per-host keys in inventory.yml.
Don't reuse the production NOC SSH key for this — it's a separate trust
boundary.
