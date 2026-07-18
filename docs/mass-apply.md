# Codex Mass Apply

Mass Apply prepares personalized Reddit application DMs in batches. Codex writes
the drafts; the FreshRSS dashboard opens a prefilled Reddit compose page. You
review each draft and press Reddit's own **Send** button manually.

## Start the helper

The helper is part of the existing Compose stack and listens on port `8092`.
Its API accepts browser requests only from the configured FreshRSS origin.

```bash
docker compose up -d --build mass-apply
docker compose logs --tail=100 mass-apply
```

If FreshRSS is opened with another hostname or HTTPS origin, set the exact
origin before rebuilding:

```text
MASS_APPLY_ALLOWED_ORIGINS=http://192.168.1.70
```

Copy the helper's persistent pairing token, then paste it into the Mass Apply
panel's **Helper pairing token** field:

```bash
docker compose exec mass-apply sh -c 'cat "$CODEX_HOME/mass-apply-token"'
```

Treat the pairing token as a password. It prevents other LAN devices from using
the signed-in Codex helper.

## Sign in to Codex

Run the bootstrap script on the Proxmox host. It creates a brand-new temporary
Codex login, displays the device code, installs that credential into the
dedicated Docker volume, and deletes the temporary host copy. It never reads or
changes the host's existing Codex login.

```bash
chmod +x scripts/bootstrap-mass-apply-codex-login.sh
scripts/bootstrap-mass-apply-codex-login.sh
```

Authentication is stored only in the dedicated `codex_auth` Docker volume. Do
not copy, commit, or expose that volume; it contains refreshable account
credentials. Check the installed login with:

```bash
docker compose exec mass-apply codex login status
```

The panel also has a **Sign in to Codex** device-login control for environments
where OpenAI device authorization is reachable directly from Docker. Use the
bootstrap script above on this Proxmox deployment.

## Apply to a batch

1. Select **+ Queue** on up to 20 FreshRSS leads.
2. Open **Mass apply** and edit the reusable DM instructions if needed.
3. Select **Prepare queued DMs** and wait for each Codex draft.
4. Review or edit each draft in FreshRSS.
5. Select **Open Reddit DM**. Sign in to Reddit in that normal browser tab if
   needed, review the recipient and text, then press Reddit's **Send** button.

The helper never stores Reddit credentials and never clicks Reddit's Send
button. Its Codex process runs ephemerally in an empty, read-only workspace.
