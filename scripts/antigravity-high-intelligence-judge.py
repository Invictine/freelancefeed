#!/usr/bin/env python3
"""Judge benchmark output with the Google Antigravity SDK."""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import sys
from pathlib import Path
from typing import Any

logging.getLogger().setLevel(logging.ERROR)


def extract_json(text: str) -> dict[str, Any] | None:
    try:
        parsed = json.loads(text)
        return parsed if isinstance(parsed, dict) else None
    except json.JSONDecodeError:
        pass

    match = re.search(r"\{.*\}", text, flags=re.DOTALL)
    if not match:
        return None

    try:
        parsed = json.loads(match.group(0))
    except json.JSONDecodeError:
        return None
    return parsed if isinstance(parsed, dict) else None


def clamp_score(value: Any) -> float:
    try:
        score = float(value)
    except (TypeError, ValueError):
        return 0.0
    return max(0.0, min(10.0, score))


def normalize_model_id(model: str) -> str:
    aliases = {
        "gemini-3.1-pro": "gemini-3.1-pro-preview",
        "gemini3.1pro": "gemini-3.1-pro-preview",
        "gemini-3.1-pro-preview": "gemini-3.1-pro-preview",
        "gemini3.1propreview": "gemini-3.1-pro-preview",
        "gemini-3-pro": "gemini-3-pro-preview",
        "gemini3pro": "gemini-3-pro-preview",
        "gemini-3.1-flash-lite": "gemini-3.1-flash-lite",
        "gemini3.1flashlite": "gemini-3.1-flash-lite",
    }
    key = model.strip().lower().replace(" ", "").replace("_", "-")
    return aliases.get(key, model.strip())


def unique_models(models: list[str]) -> list[str]:
    seen: set[str] = set()
    normalized: list[str] = []
    for model in models:
        model_id = normalize_model_id(model)
        if not model_id or model_id in seen:
            continue
        seen.add(model_id)
        normalized.append(model_id)
    return normalized


async def judge(prompt: str) -> dict[str, Any]:
    try:
        from google.antigravity import Agent
        from google.antigravity import BuiltinTools
        from google.antigravity import CapabilitiesConfig
        from google.antigravity import LocalAgentConfig
    except ImportError as exc:
        raise RuntimeError(
            "google-antigravity is not installed. Build the ai-filter image or run "
            "`pip install google-antigravity` in the configured Python environment."
        ) from exc

    api_key = (
        os.environ.get("AI_BENCHMARK_HIGH_INTELLIGENCE_API_KEY")
        or os.environ.get("ANTIGRAVITY_API_KEY")
        or os.environ.get("GEMINI_API_KEY")
        or ""
    )
    if not api_key:
        raise RuntimeError(
            "A judge API key is required. Set AI_BENCHMARK_HIGH_INTELLIGENCE_API_KEY "
            "or GEMINI_API_KEY."
        )

    app_data_dir = Path(
        os.environ.get("AI_BENCHMARK_ANTIGRAVITY_APP_DATA_DIR")
        or "/tmp/rss-leads-antigravity-app"
    )
    save_dir = Path(
        os.environ.get("AI_BENCHMARK_ANTIGRAVITY_SAVE_DIR")
        or "/tmp/rss-leads-antigravity-sessions"
    )
    workspace = Path(
        os.environ.get("AI_BENCHMARK_ANTIGRAVITY_WORKSPACE") or "/tmp"
    )
    app_data_dir.mkdir(parents=True, exist_ok=True)
    save_dir.mkdir(parents=True, exist_ok=True)

    schema = {
        "type": "object",
        "properties": {
            "overall_quality": {"type": "number"},
            "priority_score": {"type": "number"},
            "summary_score": {"type": "number"},
            "scam_score": {"type": "number"},
            "notes": {"type": "string"},
        },
        "required": [
            "overall_quality",
            "priority_score",
            "summary_score",
            "scam_score",
            "notes",
        ],
    }
    primary_model = normalize_model_id(
        os.environ.get("AI_BENCHMARK_HIGH_INTELLIGENCE_MODEL")
        or os.environ.get("ANTIGRAVITY_JUDGE_MODEL")
        or "gemini-3.1-pro-preview"
    )
    fallback_models = [
        item.strip()
        for item in (
            os.environ.get("AI_BENCHMARK_HIGH_INTELLIGENCE_FALLBACK_MODELS")
            or "gemini-3.5-flash"
        ).split(",")
        if item.strip()
    ]
    models = unique_models([primary_model, *fallback_models])
    errors: list[str] = []

    for model in models:
        try:
            return await judge_with_model(
                prompt=prompt,
                model=model,
                api_key=api_key,
                app_data_dir=app_data_dir,
                save_dir=save_dir,
                workspace=workspace,
                schema=schema,
            )
        except Exception as exc:  # pylint: disable=broad-exception-caught
            errors.append(f"{model}: {exc}")

    raise RuntimeError("All Antigravity SDK judge models failed. " + " | ".join(errors))


async def judge_with_model(
    *,
    prompt: str,
    model: str,
    api_key: str,
    app_data_dir: Path,
    save_dir: Path,
    workspace: Path,
    schema: dict[str, Any],
) -> dict[str, Any]:
    from google.antigravity import Agent
    from google.antigravity import BuiltinTools
    from google.antigravity import CapabilitiesConfig
    from google.antigravity import LocalAgentConfig

    config = LocalAgentConfig(
        model=model,
        api_key=api_key,
        system_instructions=(
            "You are a strict evaluator for lead-classification quality. "
            "Return compact JSON only. Do not use tools."
        ),
        capabilities=CapabilitiesConfig(enabled_tools=[BuiltinTools.FINISH]),
        response_schema=schema,
        workspaces=[str(workspace)],
        app_data_dir=str(app_data_dir),
        save_dir=str(save_dir),
    )

    async with Agent(config) as agent:
        response = await agent.chat(prompt)
        text = await response.text()

    parsed = extract_json(text)
    if parsed is None:
        raise RuntimeError("Antigravity SDK judge did not return JSON: " + text[:500])

    return {
        "overall_quality": clamp_score(parsed.get("overall_quality")),
        "priority_score": clamp_score(parsed.get("priority_score")),
        "summary_score": clamp_score(parsed.get("summary_score")),
        "scam_score": clamp_score(parsed.get("scam_score")),
        "notes": str(parsed.get("notes") or "")[:500],
        "judge_model_used": model,
    }


async def main() -> int:
    prompt = sys.stdin.read().strip()
    if not prompt:
        print("No judge prompt received on stdin.", file=sys.stderr)
        return 2

    try:
        result = await judge(prompt)
    except Exception as exc:  # pylint: disable=broad-exception-caught
        print(str(exc), file=sys.stderr)
        return 1

    print(json.dumps(result, separators=(",", ":"), ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
