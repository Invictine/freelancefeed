# FreshRSS Leads Stack

FreshRSS Leads Stack turns Reddit and RSS sources into a small lead dashboard.
It watches selected communities, keeps likely hiring posts visible, marks noisy
posts read, and can use Gemini/Gemma to add short
summaries, priority labels, and reusable job-type tags.

The stack is built around FreshRSS, so you still own the feeds and can read them
from FreshRSS itself or from apps such as FeedFlow.

## What You Get

- A self-hosted FreshRSS instance for lead feeds.
- A ready-made Reddit lead feed covering the subreddits listed in
  [feeds/reddit-leads.yaml](feeds/reddit-leads.yaml).
- FreshRSS `Main stream` for everything FreshRSS has stored.
- `Reddit Leads` for AI-classified `low`, `medium`, `high`, and `x_high`
  Reddit leads.
- `High Priority` for the `high` and `x_high` subset.
- A FreshRSS extension with:
  - Reddit refresh status.
  - Manual refresh button.
  - Reddit rate-limit warnings.
  - subreddit badges on Reddit posts.
  - AI summaries, priority badges, job-type badges, and an AI status dashboard.
- Optional AI classification using Google Gemini/Gemma.
- Optional RSSBridge service for sources that do not provide normal RSS feeds.

## How It Works

```text
Reddit RSS / other RSS
        |
        v
FreshRSS stores articles
        |
        v
AI worker marks not_hiring noise read
        |
        v
Optional AI worker adds summary + priority
        |
        v
High-priority sync copies `high` and `x_high` leads into a dedicated category
        |
        v
Read in FreshRSS or FeedFlow
```

The AI worker uses five priority labels:

| Priority | Meaning |
|---|---|
| `high` | Paid, urgent, budgeted, or ready-to-hire work. |
| `x_high` | Exceptional paid or highly relevant ready-to-hire work. |
| `medium` | Looks paid or real, but urgency or budget is unclear. |
| `low` | Still a possible buyer, but vague, unpaid, or weak. |
| `not_hiring` | Not a buyer hiring for a role, task, or vendor. Examples: freelancer offers, portfolios, hire-me posts, job seekers, advice, showcases, news, spam, or discussion. |

`not_hiring` items are marked read and hidden from the `Reddit Leads` category
view by the FreshRSS extension. They remain stored in FreshRSS, so `Main stream`
can still show them when read items are included.

The AI worker also assigns a `job_type` label such as `video editing` or
`scriptwriting`. These labels are stored in `rss_leads_job_types`; future AI
requests receive the existing list and are told to choose from it when possible,
only creating a new concise label when none fits. High-priority items also get a
FreshRSS/RSS category tag like `job:video_editing`.

`high` and `x_high` items are copied into the `High Priority` category through a
derived feed named `High Priority Reddit Leads`. They remain visible in
`Reddit Leads`, so that category contains the full low-through-x-high set.

## Why It Avoids Limits

The stack tries to avoid both Reddit and AI API limits.

- Reddit is fetched as one combined multireddit RSS feed instead of many
  back-to-back subreddit requests.
- FreshRSS mark-read filters remove obvious noise without calling an AI model.
- The AI worker remembers which posts were already classified for the current
  prompt version, so it does not keep reprocessing the same item.
- Gemma handles fast first-pass caching one post at a time.
- Gemini Flash Lite refines Gemma-cached items in small batches.
- Each model has its own local daily request counter and backoff state.

Default AI request plan:

| Stage | Model alias | API model | Batch size | Local daily cap |
|---|---|---|---:|---:|
| First pass | `gemma4-31b` | `gemma-4-31b-it` | 1 | 1,500 |
| Refinement | `gemini-3.1-flash-lite` | `gemini-3.1-flash-lite` | 4 | 500 |
| Fallback refinement | `gemini-3-flash` | `gemini-3-flash-preview` | 4 | 20 |

Note: the worker does not use `gemini-3.1-flash` because the live Gemini model
list did not expose that name for text generation when this was configured.

## Requirements

- Docker Engine.
- Docker Compose.
- A browser.
- Optional: a Google Gemini API key for AI summaries and priority labels.
- Optional: FeedFlow or another FreshRSS-compatible reader.

Most examples below use `docker compose`. If your system still uses Compose v1,
use `docker-compose` instead.

## Quick Start

1. Clone the repository.

```bash
git clone https://github.com/YOUR-USERNAME/rss-leads-stack.git
cd rss-leads-stack
```

2. Create a `.env` file.

Use the FreshRSS username you plan to create during the FreshRSS setup wizard.
The AI key is optional. FreshRSS still works without it.

```text
FRESHRSS_USER=your_username
# GEMINI_API_KEY=your_gemini_api_key
```

If you skip `.env`, the stack defaults to `FRESHRSS_USER=invictine` and AI
requests are skipped unless `GEMINI_API_KEY` is set another way.

Remove the `#` from `GEMINI_API_KEY` only when you have a real API key.

3. Start FreshRSS and the AI worker.

```bash
docker compose up -d
```

4. Open FreshRSS.

Use the address of the machine running Docker:

```text
http://localhost
```

If Docker is running on another server, use that server's hostname or IP
address instead.

5. Complete the FreshRSS installer.

Recommended for a simple single-server setup:

- Database: SQLite.
- Username: the same value you put in `FRESHRSS_USER`.
- Timezone: your preference. The container default is `Asia/Kolkata`.

6. Add the bundled Reddit lead feeds.

Run this after the FreshRSS account exists:

```bash
docker cp scripts/apply-freshrss-reddit-leads.php freshrss:/tmp/apply-freshrss-reddit-leads.php
docker exec freshrss php /tmp/apply-freshrss-reddit-leads.php
```

You should now see the main FreshRSS stream plus two lead categories:

- `High Priority`
- `Reddit Leads`

## Daily Use

Open FreshRSS and read the unread items in `High Priority` first, then continue
with `Reddit Leads`.

The dashboard is designed so the important bits are visible quickly:

- `high`, `medium`, and `low` badges show lead quality.
- `not hiring` means the item was judged to be noise or not a buyer.
- Job-type badges show the kind of work, such as `video editing` or
  `scriptwriting`.
- The AI summary gives a one-sentence reason.
- The Reddit status widget shows when the Reddit feed last refreshed.
- The AI dashboard shows when the next batch will run, what was processed last,
  which model was used, request success/failure counts, and recent errors.

`Main stream` is the place to review everything, including items that were
classified as `not_hiring`.

## Connect FeedFlow

FreshRSS can act as a Google Reader compatible API server.

In FreshRSS:

1. Open `Settings > Authentication`.
2. Enable API access.
3. Set an API password for the account you want FeedFlow to use.

In FeedFlow, add a FreshRSS or Google Reader API account:

```text
Server URL: http://your-freshrss-server
Username:   your FreshRSS username
Password:   the FreshRSS API password
```

If FeedFlow runs in another container on the same Docker network, the server URL
can be:

```text
http://freshrss
```

## Optional RSSBridge

RSSBridge is included but disabled by default. Enable it when you want to create
feeds for websites that do not publish RSS.

```bash
docker compose --profile rssbridge up -d
```

RSSBridge will be available on port `8081`:

```text
http://localhost:8081
```

FreshRSS can reach it inside Docker at:

```text
http://rssbridge/
```

Stop only RSSBridge:

```bash
docker compose stop rssbridge
```

## Configuration

The most common settings go in `.env`.

| Setting | Default | What it does |
|---|---|---|
| `FRESHRSS_USER` | `invictine` | FreshRSS account name used by the stack scripts and AI worker. |
| `GEMINI_API_KEY` | empty | Enables AI summaries and priority labels. Leave empty to run without AI. |
| `AI_FILTER_INTERVAL_SECONDS` | `20` | How often the AI worker checks for more work. Keep this low so high-priority notifications can land within two minutes of FreshRSS seeing a post. |
| `AI_FILTER_LOOKBACK_DAYS` | `14` | How far back the AI worker looks for recent posts. |
| `AI_GEMMA_MODEL` | `gemma4-31b` | First-pass model alias. |
| `AI_REFINE_MODELS` | `gemini-3.1-flash-lite,gemini-3-flash` | Refinement model order. |
| `AI_GEMMA_FIRST_PASS_BATCH_SIZE` | `1` | Gemma first-pass items per request. Kept at 1 by design. |
| `AI_GEMMA_FIRST_PASS_REQUESTS_PER_RUN` | `3` | Gemma first-pass requests attempted each worker run. |
| `AI_FLASH_LITE_REFINE_BATCH_SIZE` | `4` | Refinement items per Flash Lite request. |
| `AI_MODEL_DAILY_LIMITS` | `gemini-3.1-flash-lite=500,gemini-3-flash=20,gemma4-31b=1500` | Local per-model request caps. |
| `AI_FILTER_DAILY_REQUEST_BUDGET` | `0` | Optional global daily cap. `0` means track requests but do not enforce a global cap. |
| `AI_JOB_TYPE_OPTION_LIMIT` | `25` | Maximum learned job-type labels shown to the AI as reusable options. |
| `RSS_LEADS_LOCAL_LOCATIONS` | empty | Semicolon-separated local locations for in-person high-priority filtering, such as `Bengaluru; Bangalore; India`. |
| `AI_BENCHMARK_LOW_INTELLIGENCE_MODELS` | `gemma4-31b,gemini-3.1-flash-lite` | Comma-separated candidate models compared by the benchmark runner. Add slower or experimental models manually when needed. |
| `AI_BENCHMARK_SAMPLE_SIZE` | `8` | Number of recent Reddit lead entries used in a benchmark run. |
| `AI_BENCHMARK_HIGH_INTELLIGENCE_API_KEY` | falls back to `GEMINI_API_KEY` | API key used by the high-intelligence quality judge. |
| `AI_BENCHMARK_HIGH_INTELLIGENCE_MODEL` | `gemini-3.1-pro-preview` | High-intelligence API model used to judge benchmark output quality. `gemini-3.1-pro` is accepted as an alias. |
| `AI_BENCHMARK_HIGH_INTELLIGENCE_AGENT` | empty | Optional agent id used instead of `AI_BENCHMARK_HIGH_INTELLIGENCE_MODEL`. |
| `AI_BENCHMARK_HIGH_INTELLIGENCE_PROVIDER` | `antigravity-sdk` | Quality judge provider. Use `antigravity-sdk` for the bundled SDK wrapper, or set another path through CLI/API settings. |
| `AI_BENCHMARK_HIGH_INTELLIGENCE_FALLBACK_MODELS` | `gemini-3.5-flash` | Optional fallback models used by the SDK wrapper when the primary high-intelligence model is unavailable or quota-limited. |
| `AI_BENCHMARK_HIGH_INTELLIGENCE_SDK` | `1` | Enables the bundled Google Antigravity SDK judge wrapper. |
| `AI_BENCHMARK_HIGH_INTELLIGENCE_SDK_COMMAND` | auto | Optional override for the Antigravity SDK judge command. |
| `AI_BENCHMARK_HIGH_INTELLIGENCE_CLI` | empty | Optional CLI command. The benchmark sends the judge prompt on stdin and expects JSON on stdout. |

## AI Benchmark

Run a benchmark when you want to compare classifier quality and speed across
models. It samples recent Reddit lead entries, calls each low-intelligence
candidate model, then asks the configured high-intelligence judge to score output
quality.

```bash
docker compose exec ai-filter php /opt/rss-leads-stack/scripts/benchmark-ai-models.php
```

The latest benchmark is saved beside the FreshRSS user data as
`rss_leads_ai_benchmark.json` and appears in the AI dashboard.

By default the benchmark judges quality through the bundled Google Antigravity
SDK wrapper. The `ai-filter` image installs the SDK from PyPI because the SDK
ships a compiled runtime binary in the wheel. The wrapper reads the judge prompt
from stdin, uses `LocalAgentConfig(model=AI_BENCHMARK_HIGH_INTELLIGENCE_MODEL)`,
and returns compact JSON scores.

To use the SDK judge explicitly:

```text
AI_BENCHMARK_HIGH_INTELLIGENCE_PROVIDER=antigravity-sdk
AI_BENCHMARK_HIGH_INTELLIGENCE_MODEL=gemini-3.1-pro-preview
AI_BENCHMARK_HIGH_INTELLIGENCE_FALLBACK_MODELS=gemini-3.5-flash
```

The current API key may need paid Pro quota for `gemini-3.1-pro-preview`. When
that model is quota-limited, the SDK wrapper falls back to the configured
fallback list and records the model it actually used in `judge_model_used`.

If you want to use a local high-intelligence CLI judge, set
`AI_BENCHMARK_HIGH_INTELLIGENCE_CLI` to a command that reads the full judging
prompt from stdin and writes a JSON object to stdout:

```json
{"overall_quality":8,"priority_score":8,"summary_score":9,"scam_score":7,"notes":"Short note"}
```

Set `AI_BENCHMARK_HIGH_INTELLIGENCE_SDK=0` when you want the benchmark to skip
the SDK wrapper and use the CLI/API paths instead.

## Reddit Sources

The current subreddit list and feed policy live in
[feeds/reddit-leads.yaml](feeds/reddit-leads.yaml).
Recurring comment-thread sources live in
[feeds/reddit-comment-threads.json](feeds/reddit-comment-threads.json).

The included sources focus on:

- freelance hiring boards
- video editing and creator work
- social media hiring threads
- automation and AI workflow demand
- low-budget task boards
- market-research communities

You can also import [feeds/reddit-leads.opml](feeds/reddit-leads.opml) manually
from `Subscription management > Import/export` in FreshRSS, but the setup script
is preferred because it also installs the qualified/unqualified filters. The AI
worker keeps the `High Priority` category synced after items are classified.

Thread-based communities are handled through local FreshRSS feeds named
`Reddit Leads - comments - ...`. Each source searches for the latest matching
weekly, monthly, or daily thread, fetches comments from the matching post, and
emits those comments as RSS items. These items go through the same FreshRSS
filters, AI classification, and high-priority routing as normal Reddit posts.

To add another recurring-thread subreddit, add an enabled object to
`feeds/reddit-comment-threads.json`:

```json
{
  "id": "example-weekly-hiring",
  "enabled": true,
  "subreddit": "example",
  "label": "weekly hiring thread",
  "q": "\"Weekly Hiring Thread\"",
  "title_patterns": ["/Weekly Hiring Thread/i"]
}
```

Then rerun `scripts/apply-freshrss-reddit-leads.php` in the FreshRSS container.
The source id becomes the local feed URL parameter:
`http://127.0.0.1/rss-leads-reddit-comments.php?source=example-weekly-hiring`.

## Local Location

High-priority routing can account for jobs that require an in-person or hybrid
location. In FreshRSS, use the `Location` button next to the Reddit leads
controls. Add each city, region, or alias that posts may use, then save. For
example, add both `Bengaluru` and `Bangalore` if you want either spelling to
count as local.

The UI stores locations in the FreshRSS user data file
`rss_leads_location.json`. You can also set `RSS_LEADS_LOCAL_LOCATIONS` in
`.env`, using semicolons between aliases:

```text
RSS_LEADS_LOCAL_LOCATIONS=Bengaluru; Bangalore; India
```

You can also edit [feeds/local-location.json](feeds/local-location.json). The
effective list is the union of `.env`, that file, and the UI-saved aliases. When
locations are configured, in-person or hybrid jobs are only allowed into
`High Priority` if the post text contains one of those configured locations.
Remote jobs are not filtered by location.

## Project Structure

| Path | Purpose |
|---|---|
| [docker-compose.yml](docker-compose.yml) | FreshRSS, AI worker, and optional RSSBridge services. |
| [feeds/reddit-leads.yaml](feeds/reddit-leads.yaml) | Human-readable source list and feed policy. |
| [feeds/reddit-comment-threads.json](feeds/reddit-comment-threads.json) | Recurring Reddit thread comment sources. |
| [feeds/local-location.json](feeds/local-location.json) | Optional local aliases used to filter in-person high-priority jobs. |
| [feeds/reddit-leads.opml](feeds/reddit-leads.opml) | Manual FreshRSS import file. |
| [scripts/apply-freshrss-reddit-leads.php](scripts/apply-freshrss-reddit-leads.php) | Installs or updates the bundled Reddit feeds and filters. |
| [scripts/gemini-ai-filter.php](scripts/gemini-ai-filter.php) | AI summary, priority, model-limit, and routing worker. |
| [scripts/benchmark-ai-models.php](scripts/benchmark-ai-models.php) | Compares model speed and judged output quality for the AI classifier. |
| [scripts/antigravity-high-intelligence-judge.py](scripts/antigravity-high-intelligence-judge.py) | Google Antigravity SDK wrapper used by the benchmark quality judge. |
| [extensions/RssLeadsStatus](extensions/RssLeadsStatus) | FreshRSS UI extension for Reddit status, badges, and AI dashboard. |
| [freshrss-public](freshrss-public) | Small JSON/RSS endpoints used by the FreshRSS extension and local feeds. |
| [deep-research-report.md](deep-research-report.md) | Original research notes behind the first Reddit source list. |

## Check That It Is Working

Check running containers:

```bash
docker compose ps
```

Check FreshRSS:

```bash
curl -I http://127.0.0.1
```

Check AI worker logs:

```bash
docker compose logs --tail=100 ai-filter
```

Check FreshRSS logs:

```bash
docker compose logs --tail=100 freshrss
```

Validate the Compose file:

```bash
docker compose config
docker compose --profile rssbridge config
```

## Troubleshooting

### FreshRSS opens, but no Reddit lead categories appear

Run the feed setup script after completing the FreshRSS installer:

```bash
docker cp scripts/apply-freshrss-reddit-leads.php freshrss:/tmp/apply-freshrss-reddit-leads.php
docker exec freshrss php /tmp/apply-freshrss-reddit-leads.php
```

Make sure `FRESHRSS_USER` matches the FreshRSS account name.

### AI summaries do not appear

Check these first:

- `GEMINI_API_KEY` is set.
- `FRESHRSS_USER` matches your FreshRSS account.
- The `ai-filter` container is running.
- The AI dashboard in FreshRSS does not show quota/backoff errors.

Useful command:

```bash
docker compose logs --tail=100 ai-filter
```

### Reddit refreshes are slow or show rate limits

Reddit may return `429` responses when it sees frequent automated fetches. The
stack reduces this by using one combined RSS feed, but rate limits can still
happen. Wait a few minutes, reduce refresh frequency, or refresh manually less
often.

### The AI dashboard shows old errors

The dashboard keeps recent errors so you can debug past failures. If the current
status is `success` and new summaries are appearing, old entries are usually just
history.

## Updating

Pull the latest code and recreate the containers:

```bash
git pull
docker compose up -d
```

Reapply the Reddit feed setup if the source list or filters changed:

```bash
docker cp scripts/apply-freshrss-reddit-leads.php freshrss:/tmp/apply-freshrss-reddit-leads.php
docker exec freshrss php /tmp/apply-freshrss-reddit-leads.php
```

## Stopping

Stop containers but keep data:

```bash
docker compose down
```

Docker named volumes keep FreshRSS data. Do not run `docker compose down
--volumes` unless you intentionally want to remove the FreshRSS database and
settings.

## Privacy and Security

- Do not commit your `.env` file or Gemini API key.
- If AI is enabled, Reddit post titles and compact post text are sent to the
  configured Google Gemini/Gemma models.
- FreshRSS is exposed on port `80` by default. Put it behind a trusted network,
  VPN, or authenticated reverse proxy before exposing it to the public internet.
- FreshRSS data is stored in Docker named volumes on the host.

## Planned Improvements & Roadmap

- **Architecture:** Move background processing from the `while true` loop in the container to a dedicated cron task or queue system for better stability.
- **State Management:** Implement atomic writes or migrate JSON state tracking entirely into SQLite to prevent file corruption during container restarts.
- **Code Refactoring:** Break down the monolithic `gemini-ai-filter.php` into smaller, object-oriented classes (e.g., `GeminiClient`, `DatabaseRepository`) for improved maintainability.
- **AI Integration:** Fully utilize Gemini's native Structured Outputs for JSON responses to remove heuristic parsing.
- **UI Extension:** Refactor the vanilla JS dashboard (`script.js`) into a lightweight framework like Alpine.js or Preact to reduce DOM manipulation boilerplate.

## License

No license file is included yet. Before publishing this as an open source
project, add a `LICENSE` file that matches how you want others to use it.
