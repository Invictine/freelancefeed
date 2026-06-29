# RSS Leads Discord Notifier

This notifier polls the FreshRSS high-priority RSS endpoint and posts each new
lead to a Discord webhook. It is designed to run as a systemd timer inside a
small LXC container.

The service keeps sent RSS GUIDs in:

```text
/var/lib/rss-leads-discord-notifier/sent-guids.json
```

By default, the first run seeds the current feed items as already seen so old
leads are not posted to Discord. Set `SEND_EXISTING_ON_FIRST_RUN=1` only if you
want to backfill existing high-priority items.

Set `DISCORD_ROLE_ID` in `/etc/rss-leads-discord-notifier.env` to either the
numeric Discord role ID or a copied role mention such as
`<@&123456789012345678>` to ping that role on every new lead. The notifier sends
an explicit `allowed_mentions` payload so only that configured role can be
pinged. If the role still does not notify, make sure the role is mentionable in
Discord.
