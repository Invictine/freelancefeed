#!/usr/bin/env python3
"""Poll a FreshRSS high-priority RSS feed and post new entries to Discord."""

from __future__ import annotations

import argparse
import html
import json
import os
import re
import sys
import time
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from datetime import timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from typing import Any
from urllib import error, request


DEFAULT_STATE_FILE = "/var/lib/rss-leads-discord-notifier/sent-guids.json"
USER_AGENT = "rss-leads-discord-notifier/1.0"
DISCORD_EMBED_COLOR = 0xE11D48


@dataclass(frozen=True)
class FeedItem:
    guid: str
    title: str
    link: str
    ai_summary: str
    monthly_amount: str
    source_excerpt: str
    author: str
    published_at: str | None
    categories: tuple[str, ...]
    subreddit: str


def env_bool(name: str, default: bool = False) -> bool:
    value = os.environ.get(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def env_int(name: str, default: int, minimum: int, maximum: int) -> int:
    value = os.environ.get(name)
    if value is None:
        return default
    try:
        parsed = int(value)
    except ValueError:
        return default
    return max(minimum, min(maximum, parsed))


def require_env(name: str) -> str:
	value = os.environ.get(name, "").strip()
	if not value:
		raise RuntimeError(f"{name} is required")
	return value


def optional_role_id() -> str:
    configured = os.environ.get("DISCORD_ROLE_ID", "").strip()
    if not configured:
        return ""
    mention_match = re.fullmatch(r"<@&(\d{10,30})>", configured)
    if mention_match is not None:
        return mention_match.group(1)
    if re.fullmatch(r"\d{10,30}", configured) is None:
        raise RuntimeError("DISCORD_ROLE_ID must be the numeric Discord role ID or a <@&role_id> mention")
    return configured


def role_ping_status(role_id: str) -> str:
    if not role_id:
        return "Discord role ping disabled; DISCORD_ROLE_ID is not set."
    return f"Discord role ping enabled for role ID ending in {role_id[-4:]}."


def truncate(value: str, limit: int) -> str:
    value = value.strip()
    if len(value) <= limit:
        return value
    return value[: max(0, limit - 3)].rstrip() + "..."


def html_to_text(value: str) -> str:
    text = value or ""
    text = re.sub(r"(?i)<\s*br\s*/?\s*>", "\n", text)
    text = re.sub(r"(?i)</\s*p\s*>", "\n\n", text)
    text = re.sub(r"(?i)</\s*li\s*>", "\n", text)
    text = re.sub(r"(?s)<[^>]+>", " ", text)
    text = html.unescape(text)
    lines = [re.sub(r"[ \t]+", " ", line).strip() for line in text.splitlines()]
    return "\n".join(line for line in lines if line).strip()


def subreddit_from_link(link: str) -> str:
    match = re.search(r"reddit\.com/r/([A-Za-z0-9_]{2,21})", link, flags=re.IGNORECASE)
    return f"r/{match.group(1)}" if match else ""


def clean_author(value: str) -> str:
    value = html.unescape(value or "").strip()
    value = re.sub(r"^[;\s]+", "", value)
    value = re.sub(r"\s+", " ", value).strip()
    if value.startswith("/u/"):
        value = value[1:]
    return value


def monthly_amount_from_categories(categories: tuple[str, ...]) -> str:
    for category in categories:
        if category.lower().startswith("monthly:"):
            amount = category.split(":", 1)[1].replace("_", " ").strip()
            return amount or "unknown"
    return ""


def clean_category_label(value: str) -> str:
    value = html.unescape(value or "").strip()
    if value.lower().startswith("r/"):
        return value
    value = value.replace("_", " ")
    value = re.sub(r"\s+", " ", value)
    return value.strip()


def job_type_from_categories(categories: tuple[str, ...]) -> str:
    for category in categories:
        if category.lower().startswith("job:"):
            job_type = clean_category_label(category.split(":", 1)[1])
            if job_type and job_type.lower() != "unknown":
                return job_type
    return ""


def display_tags_from_categories(categories: tuple[str, ...], subreddit: str) -> list[str]:
    tags: list[str] = []
    seen: set[str] = set()
    subreddit_key = subreddit.lower()
    for category in categories:
        lowered = category.lower()
        if lowered.startswith(("monthly:", "job:")):
            continue
        label = clean_category_label(category)
        if not label:
            continue
        if label.lower() == subreddit_key or category.lower() == subreddit_key:
            continue
        key = label.lower()
        if key in seen:
            continue
        seen.add(key)
        tags.append(label)
    return tags


def discord_tag_list(tags: list[str], limit: int = 8) -> str:
    if not tags:
        return ""
    visible = tags[:limit]
    rendered = " ".join(f"`{truncate(tag, 36)}`" for tag in visible)
    hidden = len(tags) - len(visible)
    if hidden > 0:
        rendered += f" +{hidden} more"
    return rendered


def clean_source_excerpt(value: str) -> str:
    value = re.sub(r"(?i)\n?submitted by\s+.*?(?:\s+\[link\]\s+\[comments\])?\s*$", "", value.strip())
    return value.strip()


def split_description(text: str, categories: tuple[str, ...]) -> tuple[str, str, str]:
    summary = ""
    monthly_amount = monthly_amount_from_categories(categories)
    body_lines: list[str] = []

    for line in text.splitlines():
        stripped = line.strip()
        if not stripped:
            continue
        lowered = stripped.lower()
        if lowered.startswith("ai high priority:"):
            summary = stripped.split(":", 1)[1].strip()
            continue
        if lowered.startswith("estimated monthly amount:"):
            monthly_amount = stripped.split(":", 1)[1].strip() or monthly_amount
            continue
        if lowered.startswith("job type:"):
            continue
        body_lines.append(stripped)

    return summary, monthly_amount, clean_source_excerpt("\n".join(body_lines).strip())


def item_text(item: ET.Element, tag: str) -> str:
    child = item.find(tag)
    return "" if child is None or child.text is None else child.text.strip()


def parse_rss_date(value: str) -> str | None:
    if not value:
        return None
    try:
        parsed = parsedate_to_datetime(value)
    except (TypeError, ValueError):
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def fetch_bytes(url: str, timeout: int) -> bytes:
    req = request.Request(url, headers={"User-Agent": USER_AGENT})
    with request.urlopen(req, timeout=timeout) as response:
        return response.read()


def parse_feed(xml_bytes: bytes) -> list[FeedItem]:
    root = ET.fromstring(xml_bytes)
    items = root.findall("./channel/item")
    parsed: list[FeedItem] = []
    for item in items:
        link = item_text(item, "link")
        guid = item_text(item, "guid") or link
        if not guid:
            continue

        categories = tuple(
            sorted(
                {
                    category.text.strip()
                    for category in item.findall("category")
                    if category.text and category.text.strip()
                }
            )
        )
        description_text = html_to_text(item_text(item, "description"))
        ai_summary, monthly_amount, source_excerpt = split_description(description_text, categories)
        parsed.append(
            FeedItem(
                guid=guid,
                title=item_text(item, "title") or link or "High-priority lead",
                link=link,
                ai_summary=ai_summary,
                monthly_amount=monthly_amount,
                source_excerpt=source_excerpt,
                author=clean_author(item_text(item, "author")),
                published_at=parse_rss_date(item_text(item, "pubDate")),
                categories=categories,
                subreddit=subreddit_from_link(link),
            )
        )
    return parsed


def load_state(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {"seen_guids": []}
    with path.open("r", encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        return {"seen_guids": []}
    seen = data.get("seen_guids", [])
    if not isinstance(seen, list):
        seen = []
    return {"seen_guids": [str(guid) for guid in seen if str(guid)]}


def save_state(path: Path, state: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = path.with_suffix(path.suffix + ".tmp")
    with tmp_path.open("w", encoding="utf-8") as handle:
        json.dump(state, handle, indent=2, sort_keys=True)
        handle.write("\n")
    tmp_path.replace(path)


def discord_payload(item: FeedItem, role_id: str = "") -> dict[str, Any]:
    summary = item.ai_summary or "FreshRSS classified this as high priority."
    amount = item.monthly_amount or "unknown"
    job_type = job_type_from_categories(item.categories)
    tags = display_tags_from_categories(item.categories, item.subreddit)
    source_bits = [bit for bit in (item.subreddit, item.author) if bit]
    lead_text = f"New high-priority lead - {amount}"
    content = f"<@&{role_id}> {lead_text}" if role_id else lead_text
    embed: dict[str, Any] = {
        "title": truncate(item.title, 256),
        "description": truncate(summary, 700),
        "color": DISCORD_EMBED_COLOR,
        "footer": {"text": "FreshRSS high-priority lead"},
    }
    if item.link.startswith(("http://", "https://")):
        embed["url"] = item.link
    if item.published_at:
        embed["timestamp"] = item.published_at

    fields = [
        {
            "name": "Estimated monthly",
            "value": truncate(amount, 1024),
            "inline": True,
        }
    ]
    if source_bits:
        fields.append(
            {
                "name": "Source",
                "value": truncate(" | ".join(source_bits), 1024),
                "inline": True,
            }
        )
    if job_type:
        fields.append(
            {
                "name": "Job type",
                "value": truncate(job_type.title(), 1024),
                "inline": True,
            }
        )
    if tags:
        fields.append(
            {
                "name": "Other tags",
                "value": truncate(discord_tag_list(tags), 1024),
                "inline": False,
            }
        )
    if item.source_excerpt:
        fields.append(
            {
                "name": "Post excerpt",
                "value": truncate(item.source_excerpt, 900),
                "inline": False,
            }
        )
    if item.link.startswith(("http://", "https://")):
        fields.append(
            {
                "name": "Open lead",
                "value": f"[View original post]({item.link})",
                "inline": False,
            }
        )
    embed["fields"] = fields

    payload: dict[str, Any] = {
        "username": "RSS Leads",
        "content": content,
        "embeds": [embed],
    }
    if role_id:
        payload["allowed_mentions"] = {
            "parse": [],
            "roles": [role_id],
        }
    return payload


def post_webhook(webhook_url: str, payload: dict[str, Any], timeout: int) -> None:
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = request.Request(
        webhook_url,
        data=body,
        headers={
            "Content-Type": "application/json",
            "User-Agent": USER_AGENT,
        },
        method="POST",
    )

    try:
        with request.urlopen(req, timeout=timeout) as response:
            if response.status not in {200, 204}:
                raise RuntimeError(f"Discord webhook returned HTTP {response.status}")
    except error.HTTPError as exc:
        if exc.code == 429:
            retry_after = 2.0
            try:
                response_data = json.loads(exc.read().decode("utf-8"))
                retry_after = float(response_data.get("retry_after", retry_after))
            except (ValueError, OSError, json.JSONDecodeError):
                pass
            time.sleep(min(max(retry_after, 1.0), 10.0))
            with request.urlopen(req, timeout=timeout) as response:
                if response.status not in {200, 204}:
                    raise RuntimeError(f"Discord webhook returned HTTP {response.status}")
            return
        raise


def check_webhook(webhook_url: str, timeout: int) -> None:
    req = request.Request(webhook_url, headers={"User-Agent": USER_AGENT})
    with request.urlopen(req, timeout=timeout) as response:
        if response.status != 200:
            raise RuntimeError(f"Discord webhook check returned HTTP {response.status}")


def run(args: argparse.Namespace) -> int:
    webhook_url = require_env("DISCORD_WEBHOOK_URL")
    role_id = optional_role_id()
    timeout = env_int("REQUEST_TIMEOUT_SECONDS", 20, 3, 120)
    print(role_ping_status(role_id))

    if args.check_webhook:
        check_webhook(webhook_url, timeout)
        print("Discord webhook is reachable.")
        return 0

    feed_url = require_env("FEED_URL")
    state_file = Path(os.environ.get("STATE_FILE", DEFAULT_STATE_FILE))
    max_items = env_int("MAX_ITEMS_PER_RUN", 10, 1, 50)
    max_seen = env_int("STATE_MAX_GUIDS", 2000, 100, 10000)
    send_existing = env_bool("SEND_EXISTING_ON_FIRST_RUN", False)

    items = parse_feed(fetch_bytes(feed_url, timeout))
    if args.resend_latest > 0:
        resent = 0
        for item in items[: min(args.resend_latest, 25)]:
            post_webhook(webhook_url, discord_payload(item, role_id), timeout)
            resent += 1
        print(f"Resent {resent} latest high-priority lead notification(s) to Discord.")
        return 0

    state_exists = state_file.exists()
    state = load_state(state_file)
    seen_guids = state["seen_guids"]
    seen = set(seen_guids)

    if args.dry_run:
        new_count = sum(1 for item in items if item.guid not in seen)
        print(f"Read {len(items)} feed item(s); {new_count} new item(s) would notify.")
        return 0

    if not state_exists and not send_existing:
        state["seen_guids"] = [item.guid for item in items][-max_seen:]
        save_state(state_file, state)
        print(f"Seeded {len(items)} existing feed item(s); future runs will notify new leads.")
        return 0

    new_items = [item for item in items if item.guid not in seen]
    if not new_items:
        print(f"No new high-priority leads. Feed items read: {len(items)}.")
        return 0

    sent = 0
    for item in list(reversed(new_items))[:max_items]:
        post_webhook(webhook_url, discord_payload(item, role_id), timeout)
        if item.guid not in seen:
            seen.add(item.guid)
            seen_guids.append(item.guid)
        state["seen_guids"] = seen_guids[-max_seen:]
        save_state(state_file, state)
        sent += 1

    print(f"Posted {sent} high-priority lead notification(s) to Discord.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true", help="fetch and parse the feed without posting")
    parser.add_argument("--check-webhook", action="store_true", help="validate the webhook URL without posting")
    parser.add_argument("--resend-latest", type=int, default=0, help="post the latest N feed items regardless of state")
    args = parser.parse_args()
    try:
        return run(args)
    except Exception as exc:
        print(f"rss-leads-discord-notifier failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
