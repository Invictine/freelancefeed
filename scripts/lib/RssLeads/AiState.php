<?php
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

final class RssLeadsAiState {
	public static function pushLimited(array &$state, string $key, array $item, int $limit): void {
		if (!isset($state[$key]) || !is_array($state[$key])) {
			$state[$key] = [];
		}
		array_unshift($state[$key], $item);
		$state[$key] = array_slice($state[$key], 0, $limit);
	}

	public static function bumpCounter(array &$state, string $group, string $key, int $amount = 1): void {
		if (!isset($state[$group]) || !is_array($state[$group])) {
			$state[$group] = [];
		}
		$state[$group][$key] = (int)($state[$group][$key] ?? 0) + $amount;
	}

	public static function recordError(array &$state, string $stage, string $type, string $message, array $context = []): void {
		$event = array_merge([
			'at' => time(),
			'stage' => $stage,
			'type' => $type,
			'message' => RssLeadsText::errorExcerpt($message),
		], $context);
		$state['last_error'] = $event;
		self::pushLimited($state, 'errors', $event, 40);
		self::bumpCounter($state, 'error_counts', $type);
	}

	public static function recordRequest(array &$state, array $attempt, bool $ok, ?int $retryDelay = null): void {
		$status = (int)($attempt['status'] ?? 0);
		$model = (string)($attempt['model'] ?? '');
		$usage = is_array($attempt['usage'] ?? null) ? $attempt['usage'] : [];
		$tokens = [
			'prompt' => max(0, (int)($usage['prompt_tokens'] ?? 0)),
			'candidate' => max(0, (int)($usage['candidate_tokens'] ?? 0)),
			'cached' => max(0, (int)($usage['cached_tokens'] ?? 0)),
			'thoughts' => max(0, (int)($usage['thoughts_tokens'] ?? 0)),
			'tool_use_prompt' => max(0, (int)($usage['tool_use_prompt_tokens'] ?? 0)),
			'total' => max(0, (int)($usage['total_tokens'] ?? 0)),
		];
		$type = 'ok';
		if (!$ok) {
			if ($status === 429) {
				$type = 'quota';
			} elseif ($status >= 500) {
				$type = 'http_5xx';
			} elseif ($status >= 400) {
				$type = 'http_4xx';
			} elseif ((string)($attempt['error'] ?? '') !== '') {
				$type = 'network';
			} else {
				$type = 'unknown';
			}
		}

		$event = [
			'at' => time(),
			'model' => $model,
			'status' => $status,
			'ok' => $ok,
			'type' => $type,
			'error' => RssLeadsText::errorExcerpt((string)($attempt['error'] ?? '')),
			'retry_delay_seconds' => $retryDelay,
			'tokens' => $tokens,
		];
		if ((string)($usage['service_tier'] ?? '') !== '') {
			$event['service_tier'] = (string)$usage['service_tier'];
		}
		self::pushLimited($state, 'requests', $event, 80);
		self::bumpCounter($state, 'request_counts', 'total');
		self::bumpCounter($state, 'request_counts', $ok ? 'success' : 'failed');
		self::bumpCounter($state, 'request_counts', $type);

		if ($ok && $tokens['total'] > 0) {
			self::addTokenCounts($state, $model, $tokens);
		}
	}

	private static function addTokenCounts(array &$state, string $model, array $tokens): void {
		self::bumpCounter($state, 'token_counts', 'requests_with_usage');
		foreach (['prompt', 'candidate', 'cached', 'thoughts', 'tool_use_prompt', 'total'] as $key) {
			self::bumpCounter($state, 'token_counts', $key, (int)($tokens[$key] ?? 0));
		}
		if ($model === '') {
			return;
		}
		if (!isset($state['token_counts_by_model']) || !is_array($state['token_counts_by_model'])) {
			$state['token_counts_by_model'] = [];
		}
		if (!isset($state['token_counts_by_model'][$model]) || !is_array($state['token_counts_by_model'][$model])) {
			$state['token_counts_by_model'][$model] = [];
		}
		$state['token_counts_by_model'][$model]['requests_with_usage'] = (int)($state['token_counts_by_model'][$model]['requests_with_usage'] ?? 0) + 1;
		foreach (['prompt', 'candidate', 'cached', 'thoughts', 'tool_use_prompt', 'total'] as $key) {
			$state['token_counts_by_model'][$model][$key] = (int)($state['token_counts_by_model'][$model][$key] ?? 0) + (int)($tokens[$key] ?? 0);
		}
	}
}
