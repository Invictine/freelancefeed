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

On this Proxmox deployment, Compose runs inside container `102` and uses the
Compose v1 command:

```bash
pct exec 102 -- sh -lc 'cd /opt/rss-leads-stack && docker-compose up -d --build mass-apply'
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

### Easiest: sign in from FreshRSS

1. On the Proxmox host, print the helper pairing token:

   ```bash
   pct exec 102 -- docker exec rss-leads-mass-apply sh -c 'cat "$CODEX_HOME/mass-apply-token"'
   ```

2. In FreshRSS, select **Mass apply**, select **Paste token**, then
   **Connect helper**. You only need to do this once per browser.
3. Select **Pair Codex**. The panel reserves a tab before contacting the helper,
   then opens the official OpenAI sign-in page automatically so popup blockers
   do not interrupt the handoff.
4. Paste the one-time code. The panel copies it automatically when clipboard
   access is allowed and always displays a large **Copy code** fallback. Enter
   the code only on the official OpenAI/ChatGPT page.
5. Return to FreshRSS. The status updates automatically to **Helper connected ·
   Codex paired and ready**. The helper-token form stays out of the way unless
   you select **Change token** or the saved token stops working.

Device-code authentication is the appropriate Codex flow for this headless
container. The resulting refreshable credentials stay in the dedicated
`codex_auth` Docker volume. See OpenAI's
[Codex authentication guide](https://learn.chatgpt.com/docs/auth) for the
supported sign-in methods and credential-storage guidance.

### Alternative: bootstrap from the Proxmox terminal

Run the bootstrap script on the Proxmox host. It creates a brand-new temporary
Codex login, displays the device code, installs that credential into the
dedicated Docker volume, prints the helper token and next steps, and deletes the
temporary host copy. It never reads or changes the host's existing Codex login.

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

If sign-in fails, select **Pair Codex** again for a fresh one-time code.
Codes expire and should never be shared with another person.

## Apply to a batch

1. Select **+ Queue** on up to 20 FreshRSS leads.
2. Make sure **CV profile** contains the truthful experience and portfolio
   proof that Codex may use.
3. Open **Mass apply** and edit the reusable DM instructions if needed.
4. Select **Prepare queued DMs** and wait for each Codex draft.
5. Review or edit each draft in FreshRSS.
6. Select **Open next ready DM** (or **Open Reddit DM** on a specific row).
   Sign in to Reddit in that normal browser tab if
   needed, review the recipient and text, then press Reddit's **Send** button.

The helper never stores Reddit credentials and never clicks Reddit's Send
button. Its Codex process runs ephemerally in an empty, read-only workspace.
