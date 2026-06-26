# FreshRSS Leads Stack

This Docker Compose stack runs FreshRSS as the central RSS backend and
optionally runs RSSBridge for sites that do not provide native feeds.

The web ports are exposed on the Docker host. In the dedicated Proxmox LXC,
FreshRSS is available at `http://invictinefeed.local` or
`http://192.168.1.70`; optional RSSBridge is available at port `8081`.

## Prerequisites

- Docker Engine
- Docker Compose v2 (`docker compose`)

## Start FreshRSS

From `/opt/rss-leads-stack`:

```bash
docker compose pull
docker compose up -d
```

Open `http://invictinefeed.local` from a browser on the LAN and complete the
FreshRSS installer.

For the initial single-host setup, select SQLite during installation. The
database, application data, and installed extensions are retained in named
Docker volumes.

FreshRSS refreshes feeds automatically every minute using `CRON_MIN=*/1`.
The container timezone is `Asia/Kolkata`.

## Optional RSSBridge service

Start FreshRSS and RSSBridge together:

```bash
docker compose --profile rssbridge up -d
```

RSSBridge is then available on the LXC's LAN address at:

```text
http://<LXC-IP>:8081
```

From inside the Compose network, FreshRSS can reach RSSBridge at:

```text
http://rssbridge/
```

To stop RSSBridge while leaving FreshRSS running:

```bash
docker compose stop rssbridge
```

## FeedFlow connection

Complete the FreshRSS web installer and create the account that FeedFlow will
use. In FreshRSS:

1. Open **Settings > Authentication**.
2. Enable API access.
3. Set an API password for the FeedFlow account.

In FeedFlow, add a FreshRSS/Google Reader API account using:

```text
Server URL: http://invictinefeed.local
Username:   your FreshRSS username
Password:   the API password configured in FreshRSS
```

If FeedFlow runs in another container on the same Docker network, use
`http://freshrss` instead. Keep port `8080` limited to a trusted LAN, or add an
authenticated reverse proxy before exposing it publicly.

## Reddit leads feeds

The Reddit lead sources from `deep-research-report.md` are captured in:

- `feeds/reddit-leads.opml` - OPML import file for FreshRSS.
- `feeds/reddit-leads.yaml` - source-of-truth policy with priorities and custom requirements.
- `scripts/apply-freshrss-reddit-leads.php` - repeatable live updater for the
  FreshRSS SQLite database in the LXC.

Import the OPML in FreshRSS from **Subscription management > Import/export**.
The live updater subscribes FreshRSS to one Reddit multireddit RSS feed covering
all 19 subreddits from `deep-research-report.md`. Reddit search RSS and many
back-to-back per-subreddit RSS requests returned `403` / `429` from FreshRSS, so
the live feed uses one `/new/.rss` request with a browser-like user agent and
FreshRSS mark-read filters for local buyer-intent filtering.
The Reddit feed TTL is set to 60 seconds for near-real-time polling. Reddit may
occasionally return `429` rate-limit responses at this cadence.

The stack also installs a small FreshRSS extension from
`extensions/RssLeadsStatus`. It adds a Reddit status widget to the FreshRSS nav
showing time since last refresh, a manual **Refresh Reddit** button, and a toast
when FreshRSS logs a recent Reddit `429`. The widget reads
`/rss-leads-status.php`, which is bind-mounted from `freshrss-public/`. The same
extension also adds a prominent `r/subreddit` badge to Reddit posts in the
FreshRSS feed and article views by reading each entry's Reddit URL.

## Gemini AI lead filter

Set `GEMINI_API_KEY` in the Compose environment to enable the `ai-filter`
sidecar. It scans recent Reddit posts, sends compact batches to Gemini, and
stores a one-sentence summary plus `low`, `medium`, or `high` priority in the
FreshRSS SQLite database. Priority is based on urgency and money offered.

Optional settings:

```text
GEMINI_MODEL=gemini-2.5-flash-lite
GEMINI_MODELS=gemini-2.5-flash-lite,gemini-2.5-flash,gemini-flash-latest
AI_FILTER_BATCH_SIZE=8
AI_FILTER_CONTENT_CHARS=900
AI_FILTER_INTERVAL_SECONDS=300
AI_FILTER_LOOKBACK_DAYS=14
```

The FreshRSS extension reads `/rss-leads-ai.php` and displays the AI summary in
expanded article headers. Classified feed items are visually ordered
high/medium/low in the current view. The worker uses a concise JSON-only prompt
and batches posts to reduce token usage.

The updater creates two FreshRSS subscriptions to the same multireddit feed:

- `Reddit Leads - qualified deep-research communities` in the `Reddit Leads`
  category. Filters mark unqualified posts read, leaving likely leads unread.
- `Reddit Leads - unqualified deep-research communities` in the
  `Unqualified Reddit Leads` category. Filters mark likely-qualified posts read,
  leaving review/noise posts unread in a separate category.

Use `feeds/reddit-leads.yaml` when tuning the subreddit list or filters.
Thread-based communities such as `r/socialmedia` and `r/SocialMediaMarketing`
still need manual comment review because the actual leads usually live inside
the weekly or monthly hiring thread.

To reapply the live feed set inside the FreshRSS container:

```bash
docker cp scripts/apply-freshrss-reddit-leads.php freshrss:/tmp/apply-freshrss-reddit-leads.php
docker exec freshrss php /tmp/apply-freshrss-reddit-leads.php
```

### Custom filter reference

These are FreshRSS **mark as read** filters. Matching articles stay in the
database but are automatically buried as read, so the unread view stays focused
on people looking to hire.

All Reddit feeds also use this fetch user agent to reduce default-bot blocking:

```text
Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0
```

| Feed | Filter | What it does |
|---|---|---|
| `r/forhire` | `intitle:/\[for hire\]/i OR intitle:"hire me" OR intitle:portfolio` | Marks freelancer offer, hire-me, and portfolio posts as read. |
| `r/forhire` | `!intitle:/\[hiring\]/i !intitle:hiring` | Marks posts as read if the title does not contain `[HIRING]` or `hiring`. |
| `r/FindVideoEditors` | `intitle:/\[for hire\]/i OR intitle:unpaid OR intitle:volunteer OR intitle:free` | Marks editor offers and unpaid/free/volunteer requests as read. |
| `r/FindVideoEditors` | `!intitle:/\[hiring\]/i !intitle:/\[paid\]/i !intitle:hiring !intitle:paid` | Marks posts as read unless the title shows hiring or paid intent. |
| `r/VideoEditingJobs` | `intitle:/\[for hire\]/i OR intitle:unpaid OR intitle:volunteer OR intitle:free` | Marks editor offers and unpaid/free/volunteer requests as read. |
| `r/VideoEditingJobs` | `!intitle:/\[hiring\]/i !intitle:/\[paid\]/i !intitle:hiring !intitle:paid` | Marks posts as read unless the title shows hiring or paid intent. |
| `r/videography` | `intitle:unpaid OR intitle:volunteer OR intitle:exposure OR intitle:free` | Marks unpaid, volunteer, exposure-only, and free-work posts as read. |
| `r/videography` | `!intitle:/\[hiring\]/i !intitle:hiring` | Marks posts as read if the title does not contain `[HIRING]` or `hiring`. |
| `r/socialmedia` | `intitle:"self promotion" OR intitle:"for hire" OR intitle:advertisement` | Marks self-promo, for-hire, and advertisement threads as read. |
| `r/socialmedia` | `!intitle:"Weekly Hiring Thread" !intitle:"Social Media Professionals"` | Marks posts as read unless they are the weekly hiring thread. |
| `r/SocialMediaMarketing` | `intitle:"self promotion" OR intitle:advertisement OR intitle:"for hire"` | Marks self-promo, advertisement, and for-hire threads as read. |
| `r/SocialMediaMarketing` | `!intitle:"Monthly Hiring Thread" !intitle:"Hiring Thread"` | Marks posts as read unless they are a monthly/social-media hiring thread. |
| `r/n8n` | `intitle:"how do I sell" OR intitle:"looking for clients" OR intitle:"for hire"` | Marks seller-side prospecting and for-hire posts as read. |
| `r/n8n` | `!intitle:hiring !intitle:developer !intitle:automation !intitle:cofounder` | Marks posts as read unless they look like hiring, developer, automation, or cofounder opportunities. |
| `r/jobbit` | `intitle:/\[for hire\]/i OR intitle:"For Hire only" OR intitle:"hire me"` | Marks for-hire, hire-me, and for-hire megathread posts as read. |
| `r/jobbit` | `!intitle:/\[hiring\]/i !intitle:hiring` | Marks posts as read if the title does not contain `[HIRING]` or `hiring`. |
| `r/VideoEditors` | `intitle:/\[for hire\]/i OR intitle:unpaid OR intitle:volunteer OR intitle:free` | Marks editor offers and unpaid/free/volunteer requests as read. |
| `r/VideoEditors` | `!intitle:/\[hiring\]/i !intitle:hiring` | Marks posts as read if the title does not contain `[HIRING]` or `hiring`. |
| `r/creators` | `intitle:/\[for hire\]/i OR intitle:unpaid OR intitle:volunteer OR intitle:free` | Marks creator-service offers and unpaid/free/volunteer posts as read. |
| `r/creators` | `!intitle:hiring !intitle:paid !intitle:editor !intitle:"social media"` | Marks posts as read unless they mention hiring, paid work, editor needs, or social media needs. |
| `r/HireaWriter` | `intitle:/\[hire me\]/i OR intitle:/\[for hire\]/i` | Marks writer offer posts as read. |
| `r/HireaWriter` | `!intitle:/\[hiring\]/i !intitle:hiring` | Marks posts as read if the title does not contain `[HIRING]` or `hiring`. |
| `r/podcasting` | `intitle:/\[for hire\]/i OR intitle:unpaid OR intitle:volunteer OR intitle:free` | Marks podcast-service offers and unpaid/free/volunteer posts as read. |
| `r/podcasting` | `!intitle:hiring !intitle:"podcast editor" !intitle:paid` | Marks posts as read unless they mention hiring, podcast editor needs, or paid work. |

## Verify

Check container state:

```bash
docker compose ps
```

Check FreshRSS locally:

```bash
curl -I http://127.0.0.1
```

If RSSBridge is enabled:

```bash
curl -I http://127.0.0.1:8081
```

Inspect logs:

```bash
docker compose logs --tail=100 freshrss
docker compose --profile rssbridge logs --tail=100 rssbridge
```

Validate the Compose file without starting containers:

```bash
docker compose config
docker compose --profile rssbridge config
```

## Stop the stack

```bash
docker compose --profile rssbridge down
```

Named volumes are preserved by `down`. Do not add `--volumes` unless the
FreshRSS data is intentionally being removed.
