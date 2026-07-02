<?php
declare(strict_types=1);

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$apiKey = getenv('GEMINI_API_KEY') ?: '';
$gemmaModel = normalize_model_id(getenv('AI_GEMMA_MODEL') ?: 'gemma4-31b');
$refineModels = array_values(array_unique(array_filter(array_map(
	static fn(string $model): string => normalize_model_id($model),
	array_map('trim', explode(',', getenv('AI_REFINE_MODELS') ?: (getenv('GEMINI_MODELS') ?: 'gemini-3.1-flash-lite,gemini-3-flash')))
))));
$models = array_values(array_unique(array_merge($refineModels, [$gemmaModel])));
$allowModelFallbacks = in_array(strtolower((string)(getenv('AI_FILTER_ALLOW_MODEL_FALLBACKS') ?: '1')), ['1', 'true', 'yes'], true);
if (!$allowModelFallbacks && !empty($refineModels)) {
	$refineModels = array_slice($refineModels, 0, 1);
	$models = array_values(array_unique(array_merge($refineModels, [$gemmaModel])));
}
$promptVersion = 'gemma_flash_monthly_job_type_scam_v4';
$batchSize = max(1, min(20, (int)(getenv('AI_FILTER_BATCH_SIZE') ?: 20)));
$gemmaFirstPassBatchLimit = strpos($gemmaModel, 'gemma-') === 0 ? 1 : 20;
$gemmaFirstPassBatchSize = max(1, min($gemmaFirstPassBatchLimit, (int)(getenv('AI_GEMMA_FIRST_PASS_BATCH_SIZE') ?: 1)));
$gemmaFirstPassRequestsPerRun = max(0, min(50, (int)(getenv('AI_GEMMA_FIRST_PASS_REQUESTS_PER_RUN') ?: 3)));
$flashLiteRefineBatchSize = max(1, min(20, (int)(getenv('AI_FLASH_LITE_REFINE_BATCH_SIZE') ?: 4)));
$contentChars = max(200, min(2400, (int)(getenv('AI_FILTER_CONTENT_CHARS') ?: 900)));
$jobTypeOptionLimit = max(1, min(50, (int)(getenv('AI_JOB_TYPE_OPTION_LIMIT') ?: 25)));
$intervalSeconds = max(30, min(86400, (int)(getenv('AI_FILTER_INTERVAL_SECONDS') ?: 180)));
$lookbackDays = max(1, min(90, (int)(getenv('AI_FILTER_LOOKBACK_DAYS') ?: 14)));
$quotaCooldownSeconds = max(300, min(86400, (int)(getenv('AI_FILTER_QUOTA_COOLDOWN_SECONDS') ?: 21600)));
$dailyRequestBudget = max(0, min(100000, (int)(getenv('AI_FILTER_DAILY_REQUEST_BUDGET') ?: 0)));
$modelDailyLimits = parse_model_daily_limits(getenv('AI_MODEL_DAILY_LIMITS') ?: 'gemini-3.1-flash-lite=500,gemini-3-flash=20,gemma4-31b=1500');
$entryIds = array_values(array_filter(array_map('trim', explode(',', getenv('AI_FILTER_ENTRY_IDS') ?: '')), static fn(string $id): bool => preg_match('/^\d+$/', $id) === 1));
$statePath = getenv('AI_FILTER_STATE_FILE') ?: "/var/www/FreshRSS/data/users/{$user}/rss_leads_ai_state.json";
$highPrioritySyncPath = '/opt/rss-leads-stack/scripts/sync-freshrss-high-priority.php';
if (is_file($highPrioritySyncPath)) {
	require_once $highPrioritySyncPath;
}

function normalize_model_id(string $model): string {
	$model = trim($model);
	$aliases = [
		'gemini-3.1-flash' => 'gemini-3-flash-preview',
		'gemini3.1flash' => 'gemini-3-flash-preview',
		'gemini-3-flash' => 'gemini-3-flash-preview',
		'gemini3flash' => 'gemini-3-flash-preview',
		'gemini-3.1-flash-lite' => 'gemini-3.1-flash-lite',
		'gemini3.1flashlite' => 'gemini-3.1-flash-lite',
		'gemma4b' => 'gemma-4-31b-it',
		'gemma-4b' => 'gemma-4-31b-it',
		'gemma-4-b' => 'gemma-4-31b-it',
		'gemma4-31b' => 'gemma-4-31b-it',
		'gemma-4-31b' => 'gemma-4-31b-it',
		'gemma31b' => 'gemma-4-31b-it',
		'gemma4-26b' => 'gemma-4-26b-a4b-it',
		'gemma-4-26b' => 'gemma-4-26b-a4b-it',
	];
	$key = strtolower(str_replace([' ', '_'], ['', '-'], $model));
	return $aliases[$key] ?? $model;
}

function parse_model_daily_limits(string $value): array {
	$limits = [];
	foreach (explode(',', $value) as $part) {
		$part = trim($part);
		if ($part === '' || strpos($part, '=') === false) {
			continue;
		}
		[$model, $limit] = array_map('trim', explode('=', $part, 2));
		$normalizedModel = normalize_model_id($model);
		$numericLimit = (int)$limit;
		if ($normalizedModel !== '' && $numericLimit > 0) {
			$limits[$normalizedModel] = min(100000, $numericLimit);
		}
	}
	return $limits;
}

function load_state(string $path): array {
	if (!is_file($path)) {
		return [];
	}
	$stateJson = file_get_contents($path);
	$decodedState = is_string($stateJson) ? json_decode($stateJson, true) : null;
	if (is_array($decodedState)) {
		return $decodedState;
	}
	return [];
}

function save_state(string $path, array $state): void {
	$dir = dirname($path);
	if (!is_dir($dir)) {
		return;
	}
	$state['updated_at'] = time();
	file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function push_limited(array &$state, string $key, array $item, int $limit): void {
	if (!isset($state[$key]) || !is_array($state[$key])) {
		$state[$key] = [];
	}
	array_unshift($state[$key], $item);
	$state[$key] = array_slice($state[$key], 0, $limit);
}

function bump_counter(array &$state, string $group, string $key, int $amount = 1): void {
	if (!isset($state[$group]) || !is_array($state[$group])) {
		$state[$group] = [];
	}
	$state[$group][$key] = (int)($state[$group][$key] ?? 0) + $amount;
}

function error_excerpt(string $value, int $limit = 700): string {
	$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
	$value = trim($value);
	if (mb_strlen($value, 'UTF-8') > $limit) {
		return mb_substr($value, 0, $limit, 'UTF-8') . '...';
	}
	return $value;
}

function record_error(array &$state, string $stage, string $type, string $message, array $context = []): void {
	$now = time();
	$event = array_merge([
		'at' => $now,
		'stage' => $stage,
		'type' => $type,
		'message' => error_excerpt($message),
	], $context);
	$state['last_error'] = $event;
	push_limited($state, 'errors', $event, 40);
	bump_counter($state, 'error_counts', $type);
}

function record_request(array &$state, array $attempt, bool $ok, ?int $retryDelay = null): void {
	$status = (int)($attempt['status'] ?? 0);
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
		'model' => (string)($attempt['model'] ?? ''),
		'status' => $status,
		'ok' => $ok,
		'type' => $type,
		'error' => error_excerpt((string)($attempt['error'] ?? '')),
		'retry_delay_seconds' => $retryDelay,
	];
	push_limited($state, 'requests', $event, 80);
	bump_counter($state, 'request_counts', 'total');
	bump_counter($state, 'request_counts', $ok ? 'success' : 'failed');
	bump_counter($state, 'request_counts', $type);
}

function budget_key(int $timestamp): string {
	$dt = new DateTimeImmutable('@' . $timestamp);
	return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
}

function reset_daily_budget_if_needed(array &$state, int $now, int $dailyRequestBudget): void {
	$key = budget_key($now);
	$remaining = $dailyRequestBudget > 0 ? max(0, $dailyRequestBudget) : null;
	if (!isset($state['daily_budget']) || !is_array($state['daily_budget']) || ($state['daily_budget']['date'] ?? '') !== $key) {
		$recordedToday = 0;
		foreach ($state['requests'] ?? [] as $request) {
			if (is_array($request) && budget_key((int)($request['at'] ?? 0)) === $key) {
				$recordedToday++;
			}
		}
		$remaining = $dailyRequestBudget > 0 ? max(0, $dailyRequestBudget - $recordedToday) : null;
		$state['daily_budget'] = [
			'date' => $key,
			'limit' => $dailyRequestBudget,
			'used' => $recordedToday,
			'remaining' => $remaining,
			'reset_at' => (new DateTimeImmutable($key . ' 00:00:00', new DateTimeZone('Asia/Kolkata')))->modify('+1 day')->getTimestamp(),
		];
	}
	$state['daily_budget']['limit'] = $dailyRequestBudget;
	$recordedToday = 0;
	foreach ($state['requests'] ?? [] as $request) {
		if (is_array($request) && budget_key((int)($request['at'] ?? 0)) === $key) {
			$recordedToday++;
		}
	}
	$state['daily_budget']['used'] = max($recordedToday, (int)($state['daily_budget']['used'] ?? 0));
	$state['daily_budget']['remaining'] = $dailyRequestBudget > 0 ? max(0, $dailyRequestBudget - $state['daily_budget']['used']) : null;
}

function consume_daily_request(array &$state, int $dailyRequestBudget): void {
	reset_daily_budget_if_needed($state, time(), $dailyRequestBudget);
	$state['daily_budget']['used']++;
	$state['daily_budget']['remaining'] = $dailyRequestBudget > 0 ? max(0, $dailyRequestBudget - (int)$state['daily_budget']['used']) : null;
}

function reset_model_daily_limits_if_needed(array &$state, int $now, array $modelDailyLimits): void {
	$key = budget_key($now);
	if (!isset($state['model_daily_budgets']) || !is_array($state['model_daily_budgets']) || ($state['model_daily_budgets']['date'] ?? '') !== $key) {
		$state['model_daily_budgets'] = [
			'date' => $key,
			'models' => [],
			'reset_at' => (new DateTimeImmutable($key . ' 00:00:00', new DateTimeZone('Asia/Kolkata')))->modify('+1 day')->getTimestamp(),
		];
	}
	$state['model_daily_budgets']['date'] = $key;
	$state['model_daily_budgets']['reset_at'] = (new DateTimeImmutable($key . ' 00:00:00', new DateTimeZone('Asia/Kolkata')))->modify('+1 day')->getTimestamp();
	if (!isset($state['model_daily_budgets']['models']) || !is_array($state['model_daily_budgets']['models'])) {
		$state['model_daily_budgets']['models'] = [];
	}
	foreach ($modelDailyLimits as $model => $limit) {
		$used = (int)($state['model_daily_budgets']['models'][$model]['used'] ?? 0);
		$state['model_daily_budgets']['models'][$model] = [
			'limit' => $limit,
			'used' => $used,
			'remaining' => max(0, $limit - $used),
		];
	}
	foreach (array_keys($state['model_daily_budgets']['models']) as $model) {
		if (!isset($modelDailyLimits[$model])) {
			unset($state['model_daily_budgets']['models'][$model]);
		}
	}
}

function model_daily_budget_available(array &$state, string $model, array $modelDailyLimits): bool {
	if (!isset($modelDailyLimits[$model])) {
		return true;
	}
	reset_model_daily_limits_if_needed($state, time(), $modelDailyLimits);
	return (int)($state['model_daily_budgets']['models'][$model]['remaining'] ?? 0) > 0;
}

function consume_model_daily_request(array &$state, string $model, array $modelDailyLimits): void {
	if (!isset($modelDailyLimits[$model])) {
		return;
	}
	reset_model_daily_limits_if_needed($state, time(), $modelDailyLimits);
	$state['model_daily_budgets']['models'][$model]['used']++;
	$limit = (int)$state['model_daily_budgets']['models'][$model]['limit'];
	$state['model_daily_budgets']['models'][$model]['remaining'] = max(0, $limit - (int)$state['model_daily_budgets']['models'][$model]['used']);
}

function local_not_hiring_summary(array $group): ?string {
	$title = mb_strtolower((string)($group['title'] ?? ''), 'UTF-8');
	$text = mb_strtolower((string)($group['text'] ?? ''), 'UTF-8');
	$combined = $title . ' ' . $text;

	$notHiringRules = [
		'Freelancer offer, not a buyer hiring for a role.' => [
			'/\[(for hire|hire me|offer)\]/u',
			'/\b(for hire|hire me|available for work|open to work|portfolio|my services|i offer|i can help|web developer for hire|video editor for hire)\b/u',
		],
		'Advice or discussion post, not a hiring lead.' => [
			'/\b(advice|help me|question|how do i|what should i|discussion|feedback|review my|begging for help|problem with|struggling with)\b/u',
		],
		'Showcase or self-promotion, not a hiring lead.' => [
			'/\b(showcase|case study|launched|built this|check out|self promotion|advertisement|promoting|newsletter|course|template)\b/u',
		],
		'Seller-side prospecting, not someone hiring.' => [
			'/\b(looking for clients|lead generation|appointment setter|how to get clients|sell my service|find customers)\b/u',
		],
		'Job seeker post, not a hiring lead.' => [
			'/\b(resume|cv|job seeker|seeking work|looking for work|internship wanted|entry level candidate)\b/u',
		],
	];

	foreach ($notHiringRules as $summary => $patterns) {
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $combined) === 1) {
				return $summary;
			}
		}
	}

	$hiringSignals = [
		'/\b(hiring|paid|paying|budget|looking for|need(?:ed)?|seeking|wanted|task|job|role|position|contract|editor needed|developer needed|will pay)\b/u',
		'/\[(hiring|paid|task)\]/u',
	];
	foreach ($hiringSignals as $pattern) {
		if (preg_match($pattern, $combined) === 1) {
			return null;
		}
	}

	return null;
}

function start_run(array &$state, int $startedAt, int $intervalSeconds, array $config): void {
	$state['current_status'] = 'running';
	$state['last_started_at'] = $startedAt;
	$state['next_run_at'] = $startedAt + $intervalSeconds;
	$state['config'] = $config;
	bump_counter($state, 'run_counts', 'total');
}

function finish_run(array &$state, string $status, int $finishedAt, string $message, array $batch = []): void {
	$state['current_status'] = $status;
	$state['last_finished_at'] = $finishedAt;
	$state['last_message'] = $message;
	if ($status === 'success') {
		$state['last_success_at'] = $finishedAt;
		bump_counter($state, 'run_counts', 'success');
	} elseif ($status === 'skipped') {
		$state['last_skipped_at'] = $finishedAt;
		bump_counter($state, 'run_counts', 'skipped');
	} else {
		$state['last_failed_at'] = $finishedAt;
		bump_counter($state, 'run_counts', 'failed');
	}
	if (!empty($batch)) {
		$state['last_batch'] = $batch;
	}
}

function active_model_backoffs(array $state, array $models, int $now): array {
	$active = [];
	foreach ($models as $model) {
		$until = (int)($state['model_backoffs'][$model] ?? 0);
		if ($until > $now) {
			$active[$model] = $until;
		}
	}
	return $active;
}

$state = load_state($statePath);
$runStarted = time();
start_run($state, $runStarted, $intervalSeconds, [
	'batch_size' => $batchSize,
	'content_chars' => $contentChars,
	'job_type_option_limit' => $jobTypeOptionLimit,
	'lookback_days' => $lookbackDays,
	'interval_seconds' => $intervalSeconds,
	'quota_cooldown_seconds' => $quotaCooldownSeconds,
	'models' => $models,
	'gemma_model' => $gemmaModel,
	'refine_models' => $refineModels,
	'gemma_first_pass_batch_size' => $gemmaFirstPassBatchSize,
	'gemma_first_pass_requests_per_run' => $gemmaFirstPassRequestsPerRun,
	'flash_lite_refine_batch_size' => $flashLiteRefineBatchSize,
	'allow_model_fallbacks' => $allowModelFallbacks,
	'daily_request_budget' => $dailyRequestBudget,
	'model_daily_limits' => $modelDailyLimits,
	'prompt_version' => $promptVersion,
]);
reset_daily_budget_if_needed($state, $runStarted, $dailyRequestBudget);
reset_model_daily_limits_if_needed($state, $runStarted, $modelDailyLimits);
save_state($statePath, $state);

if ($apiKey === '') {
	$message = 'GEMINI_API_KEY is not set; skipping AI filter.';
	record_error($state, 'config', 'missing_api_key', $message);
	finish_run($state, 'skipped', time(), $message);
	save_state($statePath, $state);
	fwrite(STDERR, $message . "\n");
	exit(0);
}
$activeModelBackoffs = active_model_backoffs($state, $models, time());
if (!empty($models) && count($activeModelBackoffs) === count($models)) {
	$nextModelBackoff = min($activeModelBackoffs);
	$message = 'All Gemini fallback models are in quota backoff until ' . date(DATE_ATOM, $nextModelBackoff) . '; skipping AI filter.';
	$state['quota_backoff_until'] = $nextModelBackoff;
	$state['next_batch_at'] = max((int)($state['next_run_at'] ?? 0), $nextModelBackoff);
	finish_run($state, 'skipped', time(), $message, [
		'candidate_rows' => 0,
		'unique_links' => 0,
		'local_classified' => 0,
		'sent_items' => 0,
		'returned_items' => 0,
		'saved' => 0,
		'skipped_reason' => 'all_models_quota_backoff',
		'model_backoffs' => $activeModelBackoffs,
		'daily_budget' => $state['daily_budget'] ?? null,
	]);
	save_state($statePath, $state);
	fwrite(STDERR, $message . "\n");
	exit(0);
}

function compact_text(string $html, int $limit): string {
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
	$text = trim($text);
	if (mb_strlen($text, 'UTF-8') > $limit) {
		$text = mb_substr($text, 0, $limit, 'UTF-8');
	}
	return $text;
}

function subreddit_from_url(string $url): string {
	if (preg_match('~reddit\.com/r/([A-Za-z0-9_]{2,21})~i', $url, $match)) {
		return $match[1];
	}
	return '';
}

function gemini_text(array $response): string {
	$text = '';
	foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
		if (isset($part['text']) && is_string($part['text'])) {
			$text .= $part['text'];
		}
	}
	return $text;
}

function valid_classification_array(array $decoded, array $expectedIds = []): bool {
	if (empty($decoded)) {
		return false;
	}
	$expected = [];
	foreach ($expectedIds as $id) {
		$expected[(string)$id] = true;
	}
	foreach ($decoded as $item) {
		if (!is_array($item)) {
			return false;
		}
		$id = (string)($item['id'] ?? '');
		$summary = trim((string)($item['summary'] ?? ''));
		$priority = strtolower((string)($item['priority'] ?? ''));
		if ($id === '' || $summary === '' || !in_array($priority, ['low', 'medium', 'high', 'not_hiring'], true)) {
			return false;
		}
		if (isset($item['job_type']) && mb_strlen((string)$item['job_type'], 'UTF-8') > 96) {
			return false;
		}
		if (!array_key_exists('scam_likelihood', $item) || !is_numeric($item['scam_likelihood'])) {
			return false;
		}
		$scamLikelihood = (int)$item['scam_likelihood'];
		if ($scamLikelihood < 0 || $scamLikelihood > 100) {
			return false;
		}
		if (!empty($expected) && !isset($expected[$id])) {
			return false;
		}
	}
	return true;
}

function normalize_monthly_amount(string $value, string $priority): string {
	if ($priority !== 'high') {
		return '';
	}
	$value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
	$value = trim($value);
	if ($value === '' || preg_match('/^(unknown|unclear|not specified|n\/a|na|none)$/iu', $value) === 1) {
		return 'unknown';
	}
	if (mb_strlen($value, 'UTF-8') > 64) {
		$value = mb_substr($value, 0, 64, 'UTF-8');
	}
	return $value;
}

function parse_money_value(string $amount, string $suffix = ''): float {
	$value = (float)str_replace(',', '', $amount);
	if (in_array(strtolower($suffix), ['k', 'm'], true)) {
		$value *= strtolower($suffix) === 'm' ? 1000000 : 1000;
	}
	return $value;
}

function format_monthly_amount(float $min, ?float $max = null, string $suffix = '/mo'): string {
	$max ??= $min;
	$min = max(1, round($min));
	$max = max(1, round($max));
	if ($max < $min) {
		[$min, $max] = [$max, $min];
	}
	$format = static fn(float $value): string => '$' . number_format((int)$value);
	if (abs($max - $min) <= 1) {
		return $format($min) . $suffix;
	}
	return $format($min) . '-' . $format($max) . $suffix;
}

function estimate_monthly_amount_from_text(string $title, string $content): string {
	$text = compact_text($title . ' ' . $content, 2400);
	$money = '[$]\s*([0-9][0-9,]*(?:\.[0-9]+)?)\s*([kKmM]?)';
	$range = $money . '(?:\s*(?:-|\x{2013}|to)\s*[$]?\s*([0-9][0-9,]*(?:\.[0-9]+)?)\s*([kKmM]?))?';
	$patterns = [
		['~' . $range . '\s*(?:/mo|/month|per month|monthly)\b~iu', 1.0, '/mo'],
		['~' . $range . '\s*(?:/wk|/week|per week|weekly)\b~iu', 4.33, '/mo'],
		['~' . $range . '\s*(?:/hr|/hour|per hour|hourly)\b~iu', 160.0, '/mo'],
		['~' . $range . '\s*(?:/yr|/year|per year|yearly|annual|annually|salary)\b~iu', 1 / 12, '/mo'],
	];
	foreach ($patterns as [$pattern, $multiplier, $suffix]) {
		if (preg_match($pattern, $text, $match) === 1) {
			$min = parse_money_value($match[1], $match[2] ?? '') * $multiplier;
			$max = isset($match[3]) && $match[3] !== '' ? parse_money_value($match[3], $match[4] ?? '') * $multiplier : $min;
			return format_monthly_amount($min, $max, $suffix);
		}
	}
	if (preg_match('~(?:budget|pay|paid|payment|salary|rate)[^$]{0,24}' . $range . '~iu', $text, $match) === 1) {
		$min = parse_money_value($match[1], $match[2] ?? '');
		$max = isset($match[3]) && $match[3] !== '' ? parse_money_value($match[3], $match[4] ?? '') : $min;
		return format_monthly_amount($min, $max, '/mo equiv');
	}
	return 'unknown';
}

function monthly_amount_for_result(array $result, array $group, string $priority): string {
	$monthlyAmount = normalize_monthly_amount((string)($result['monthly_amount'] ?? ''), $priority);
	if ($priority === 'high' && $monthlyAmount === 'unknown') {
		$monthlyAmount = estimate_monthly_amount_from_text((string)($group['title'] ?? ''), (string)($group['text'] ?? ''));
	}
	return $monthlyAmount;
}

function normalize_job_type(string $value, string $priority): string {
	if ($priority === 'not_hiring') {
		return '';
	}
	$value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$value = mb_strtolower($value, 'UTF-8');
	$value = str_replace('&', ' and ', $value);
	$value = preg_replace('/^(?:job|role|type|category)\s*:\s*/u', '', $value) ?? $value;
	$value = preg_replace('/[^a-z0-9+#\/ -]+/u', ' ', $value) ?? $value;
	$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
	$value = trim($value, " \t\n\r\0\x0B-_/.");
	if ($value === '' || preg_match('/^(?:unknown|unclear|not specified|n\/a|na|none|other)$/u', $value) === 1) {
		return '';
	}
	$aliases = [
		'video editor' => 'video editing',
		'video production' => 'video editing',
		'youtube editing' => 'video editing',
		'script writing' => 'scriptwriting',
		'script writer' => 'scriptwriting',
		'youtube scriptwriting' => 'scriptwriting',
		'copy writing' => 'content writing',
		'copywriting' => 'content writing',
		'social media manager' => 'social media management',
		'community management' => 'social media management',
		'workflow automation' => 'automation',
		'n8n automation' => 'automation',
		'web developer' => 'web development',
		'website development' => 'web development',
		'graphic designer' => 'graphic design',
		'thumbnail designer' => 'thumbnail design',
		'podcast editor' => 'podcast editing',
		'voice over' => 'voiceover',
		'voice actor' => 'voiceover',
		'virtual assistance' => 'virtual assistant',
		'mobile app developer' => 'mobile app development',
		'chatbot development' => 'ai chatbot',
	];
	if (isset($aliases[$value])) {
		return $aliases[$value];
	}
	$words = preg_split('/\s+/', $value) ?: [];
	if (count($words) > 5) {
		$value = implode(' ', array_slice($words, 0, 5));
	}
	if (mb_strlen($value, 'UTF-8') > 64) {
		$value = mb_substr($value, 0, 64, 'UTF-8');
		$value = trim($value);
	}
	return $value;
}

function estimate_job_type_from_text(string $title, string $content): string {
	$text = mb_strtolower(compact_text($title . ' ' . $content, 2400), 'UTF-8');
	$rules = [
		'video editing' => '/\b(video edit(?:or|ing)?|youtube editor|shorts editor|reels editor|tiktok editor|premiere pro|after effects)\b/u',
		'scriptwriting' => '/\b(script ?writer|script writing|write scripts?|youtube scripts?|screenwriter)\b/u',
		'content writing' => '/\b(content writer|copywriter|blog writer|article writer|ghostwriter|writing job)\b/u',
		'social media management' => '/\b(social media manager|community manager|instagram|twitter|x account|tiktok account|content calendar)\b/u',
		'automation' => '/\b(automation|n8n|zapier|make\.com|workflow|integrations?)\b/u',
		'web development' => '/\b(web developer|website|wordpress|shopify|frontend|backend|landing page)\b/u',
		'graphic design' => '/\b(graphic designer|logo|brand design|banner|creative design)\b/u',
		'thumbnail design' => '/\b(thumbnail|youtube thumb)\b/u',
		'podcast editing' => '/\b(podcast editor|audio editor|podcast editing)\b/u',
		'voiceover' => '/\b(voice ?over|voice actor|narration)\b/u',
		'seo' => '/\b(seo|search engine optimization|backlinks?)\b/u',
		'virtual assistant' => '/\b(virtual assistant|admin assistant|executive assistant|va\b)\b/u',
		'mobile app development' => '/\b(mobile app|ios app|android app|react native|flutter)\b/u',
		'ai chatbot' => '/\b(chatbot|ai agent|llm|openai|gemini bot)\b/u',
		'data entry' => '/\b(data entry|spreadsheet|excel|google sheets)\b/u',
		'lead generation' => '/\b(lead generation|appointment setting|cold email|outreach)\b/u',
	];
	foreach ($rules as $jobType => $pattern) {
		if (preg_match($pattern, $text) === 1) {
			return $jobType;
		}
	}
	return 'general help';
}

function job_type_for_result(array $result, array $group, string $priority): string {
	$jobType = normalize_job_type((string)($result['job_type'] ?? ''), $priority);
	if ($priority !== 'not_hiring' && $jobType === '') {
		$jobType = estimate_job_type_from_text((string)($group['title'] ?? ''), (string)($group['text'] ?? ''));
	}
	return $jobType;
}

function scam_likelihood_for_result(array $result): int {
	if (!array_key_exists('scam_likelihood', $result) || !is_numeric($result['scam_likelihood'])) {
		return 0;
	}
	return max(0, min(100, (int)round((float)$result['scam_likelihood'])));
}

function job_type_slug(string $jobType): string {
	$slug = mb_strtolower($jobType, 'UTF-8');
	$slug = str_replace('&', ' and ', $slug);
	$slug = preg_replace('/[^a-z0-9+#\/]+/u', '-', $slug) ?? $slug;
	$slug = preg_replace('/-+/u', '-', $slug) ?? $slug;
	return trim($slug, '-');
}

function job_type_prompt_instruction(array $jobTypeOptions): string {
	$options = array_values(array_filter(array_map('trim', $jobTypeOptions), static fn(string $value): bool => $value !== ''));
	$known = empty($options) ? 'none yet' : implode(', ', array_slice($options, 0, 30));
	return 'job_type is a reusable lower-case job category such as video editing, scriptwriting, social media management, automation, or web development. For high/medium/low leads choose the exact best label from Known job_types when one fits. If none fits, create one concise new lower-case 2-4 word label. Do not create synonyms for known labels. Use "" for not_hiring. Known job_types: ' . $known . '.';
}

function decode_json_array(string $text, array $expectedIds = []): ?array {
	$decoded = json_decode($text, true);
	if (is_array($decoded) && valid_classification_array($decoded, $expectedIds)) {
		return $decoded;
	}

	$offset = 0;
	$length = strlen($text);
	while (($start = strpos($text, '[', $offset)) !== false) {
		$depth = 0;
		$inString = false;
		$escaped = false;
		for ($i = $start; $i < $length; $i++) {
			$char = $text[$i];
			if ($inString) {
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === '"') {
				$inString = true;
				continue;
			}
			if ($char === '[') {
				$depth++;
				continue;
			}
			if ($char === ']') {
				$depth--;
				if ($depth === 0) {
					$candidate = substr($text, $start, $i - $start + 1);
					$decoded = json_decode($candidate, true);
					if (is_array($decoded) && valid_classification_array($decoded, $expectedIds)) {
						return $decoded;
					}
					break;
				}
			}
		}
		$offset = $start + 1;
	}
	return null;
}

function call_gemini(string $apiKey, string $model, array $payload): array {
	$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 45,
	]);
	$raw = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);

	return [
		'model' => $model,
		'raw' => is_string($raw) ? $raw : '',
		'status' => $status,
		'error' => $curlError,
	];
}

function retry_delay_seconds(string $raw): ?int {
	$response = json_decode($raw, true);
	if (!is_array($response)) {
		return null;
	}
	foreach ($response['error']['details'] ?? [] as $detail) {
		$retryDelay = $detail['retryDelay'] ?? null;
		if (is_string($retryDelay) && preg_match('/^(\d+)s$/', $retryDelay, $match)) {
			return (int)$match[1];
		}
	}
	return null;
}

function classification_system_prompt(array $jobTypeOptions): string {
	return 'Score Reddit leads. Output JSON array only. Each item: id, summary<=20 words one sentence, monthly_amount, priority low|medium|high|not_hiring, job_type, scam_likelihood integer 0-100. scam_likelihood is the chance the post is a scam or unsafe lead: high values for unrealistic pay, requests for money, off-platform payment/account access, vague easy-work offers, crypto/check/payment-forwarding, suspicious urgency, or impersonation; low values for specific normal paid work with credible details. monthly_amount is only for high priority: estimate monthly USD value like "$3,000/mo" from budget/pay/rate; convert hourly weekly annual or project pay to monthly equivalent when possible; use "unknown" if no money is stated; use "" for non-high. not_hiring=not posted by someone hiring for a role/task/vendor, including freelancer offers, hire-me posts, portfolio/self-promo, selling services, job seekers, general discussion, advice, showcases, news, or spam. For real buyer/hiring posts: high=paid+urgent/budget/ready; medium=paid/no urgency or urgent/no budget; low=unpaid/vague but still wants help/hiring. ' . job_type_prompt_instruction($jobTypeOptions);
}

function build_classification_payload(array $items, string $model, array $jobTypeOptions): array {
	$maxOutputTokens = 160 + (count($items) * 128);
	if (strpos($model, 'gemma-') === 0) {
		$gemmaOutputTokens = 1024 + (count($items) * 320);
		return [
			'contents' => [[
				'parts' => [[
					'text' => "Classify each Reddit item. Return one JSON array with objects containing id, summary, monthly_amount, priority, job_type, and scam_likelihood. Do not use markdown.\nPriority must be one of: low, medium, high, not_hiring.\nscam_likelihood must be an integer from 0 to 100 estimating the chance this is a scam or unsafe lead. Raise it for unrealistic pay, vague easy-work promises, payment forwarding, crypto/check schemes, requests for money, account access, off-platform payment, suspicious urgency, or impersonation. Lower it for specific normal paid work with credible details.\nmonthly_amount is only for high priority: estimate monthly USD value like \"$3,000/mo\" from budget, pay, rate, or salary. Convert hourly, weekly, annual, or project pay to monthly equivalent when possible. Use \"unknown\" when no money is stated. Use \"\" for non-high.\nnot_hiring means freelancer offers, hire-me posts, portfolios, job seekers, advice, discussion, showcases, news, spam, or anything not posted by someone hiring for a role/task/vendor.\nhigh means paid urgent/budget/ready hiring. medium means paid hiring without urgency or urgent hiring without budget. low means vague or unpaid but still seeking help.\n" . job_type_prompt_instruction($jobTypeOptions) . "\nItems: " . json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				]],
			]],
			'generationConfig' => [
				'temperature' => 0,
				'maxOutputTokens' => $gemmaOutputTokens,
			],
		];
	}
	$payload = [
		'systemInstruction' => [
			'parts' => [['text' => classification_system_prompt($jobTypeOptions)]],
		],
		'contents' => [[
			'parts' => [[
				'text' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			]],
		]],
		'generationConfig' => [
			'temperature' => 0,
			'maxOutputTokens' => $maxOutputTokens,
			'response_mime_type' => 'application/json',
		],
	];
	$payload['generationConfig']['response_schema'] = [
		'type' => 'ARRAY',
		'items' => [
			'type' => 'OBJECT',
			'properties' => [
				'id' => ['type' => 'STRING'],
				'summary' => ['type' => 'STRING'],
				'monthly_amount' => ['type' => 'STRING'],
				'priority' => [
					'type' => 'STRING',
					'enum' => ['low', 'medium', 'high', 'not_hiring'],
				],
				'job_type' => ['type' => 'STRING'],
				'scam_likelihood' => ['type' => 'INTEGER'],
			],
			'required' => ['id', 'summary', 'monthly_amount', 'priority', 'job_type', 'scam_likelihood'],
		],
	];
	return $payload;
}

function run_classification_batch(
	string $apiKey,
	array $models,
	array $items,
	array &$state,
	string $statePath,
	int $dailyRequestBudget,
	array $modelDailyLimits,
	array $jobTypeOptions,
	int $quotaCooldownSeconds,
	int $intervalSeconds
): array {
	foreach ($models as $candidateModel) {
		$expectedIds = array_values(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $items));
		$modelBackoffUntil = (int)($state['model_backoffs'][$candidateModel] ?? 0);
		if ($modelBackoffUntil > time()) {
			record_error($state, 'budget', 'model_quota_backoff_active', 'Skipping model due to active quota backoff.', [
				'model' => $candidateModel,
				'backoff_until' => $modelBackoffUntil,
			]);
			continue;
		}
		reset_daily_budget_if_needed($state, time(), $dailyRequestBudget);
		if ($dailyRequestBudget > 0 && (int)($state['daily_budget']['remaining'] ?? 0) <= 0) {
			$message = 'Daily Gemini request budget used before model request.';
			record_error($state, 'budget', 'daily_request_budget_exhausted', $message, [
				'model' => $candidateModel,
				'daily_budget' => $state['daily_budget'],
			]);
			break;
		}
		reset_model_daily_limits_if_needed($state, time(), $modelDailyLimits);
		if (!model_daily_budget_available($state, $candidateModel, $modelDailyLimits)) {
			$resetAt = (int)($state['model_daily_budgets']['reset_at'] ?? (time() + 86400));
			if (!isset($state['model_backoffs']) || !is_array($state['model_backoffs'])) {
				$state['model_backoffs'] = [];
			}
			$state['model_backoffs'][$candidateModel] = $resetAt;
			record_error($state, 'budget', 'model_daily_limit_exhausted', 'Local model daily request limit reached.', [
				'model' => $candidateModel,
				'backoff_until' => $resetAt,
				'model_daily_budget' => $state['model_daily_budgets']['models'][$candidateModel] ?? null,
			]);
			continue;
		}
		consume_daily_request($state, $dailyRequestBudget);
		consume_model_daily_request($state, $candidateModel, $modelDailyLimits);
		save_state($statePath, $state);
		$attempt = call_gemini($apiKey, $candidateModel, build_classification_payload($items, $candidateModel, $jobTypeOptions));
		if ($attempt['raw'] !== '' && $attempt['status'] >= 200 && $attempt['status'] < 300) {
			record_request($state, $attempt, true);
			$response = json_decode($attempt['raw'], true);
			$text = is_array($response) ? gemini_text($response) : '';
			$decoded = decode_json_array($text, $expectedIds);
			if (is_array($decoded)) {
				if (isset($state['model_backoffs'][$candidateModel])) {
					unset($state['model_backoffs'][$candidateModel]);
				}
				$state['quota_backoff_until'] = 0;
				return ['results' => $decoded, 'model' => $candidateModel];
			}
			$message = "Gemini returned non-JSON model={$candidateModel} result=" . mb_substr($text, 0, 500, 'UTF-8');
			record_error($state, 'gemini_response', 'invalid_json', $message, [
				'model' => $candidateModel,
				'status' => $attempt['status'],
			]);
			fwrite(STDERR, $message . "\n");
			continue;
		}
		fwrite(STDERR, "Gemini request failed model={$candidateModel} status={$attempt['status']} error={$attempt['error']} body={$attempt['raw']}\n");
		$retryDelay = $attempt['status'] === 429 ? (retry_delay_seconds($attempt['raw']) ?? 0) : null;
		record_request($state, $attempt, false, $retryDelay);
		record_error($state, 'gemini_request', $attempt['status'] === 429 ? 'quota_exhausted' : 'request_failed', (string)($attempt['raw'] ?: $attempt['error'] ?: 'Gemini request failed.'), [
			'model' => $candidateModel,
			'status' => $attempt['status'],
			'retry_delay_seconds' => $retryDelay,
		]);
		if ($attempt['status'] === 429) {
			$modelBackoffUntil = time() + max($quotaCooldownSeconds, $retryDelay);
			if (!isset($state['model_backoffs']) || !is_array($state['model_backoffs'])) {
				$state['model_backoffs'] = [];
			}
			$state['model_backoffs'][$candidateModel] = $modelBackoffUntil;
			$state['last_quota_error_at'] = time();
			$state['last_quota_model'] = $candidateModel;
			$state['next_batch_at'] = (int)($state['next_run_at'] ?? (time() + $intervalSeconds));
			save_state($statePath, $state);
			fwrite(STDERR, 'Gemini quota exhausted for model=' . $candidateModel . '; backing that model off until ' . date(DATE_ATOM, $modelBackoffUntil) . ".\n");
			continue;
		}
	}
	return ['results' => null, 'model' => null];
}

function ai_table_columns(PDO $db): array {
	$columns = [];
	foreach ($db->query('PRAGMA table_info(rss_leads_ai)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
		$name = (string)($column['name'] ?? '');
		if ($name !== '') {
			$columns[$name] = true;
		}
	}
	return $columns;
}

function ensure_job_type_table(PDO $db): void {
	$db->exec('CREATE TABLE IF NOT EXISTS rss_leads_job_types (
		slug TEXT PRIMARY KEY,
		name TEXT NOT NULL,
		usage_count INTEGER NOT NULL DEFAULT 0,
		first_seen_at INTEGER NOT NULL,
		last_seen_at INTEGER NOT NULL
	)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_rss_leads_job_types_usage ON rss_leads_job_types(usage_count DESC, last_seen_at DESC)');
	$db->exec('INSERT OR IGNORE INTO rss_leads_job_types (slug, name, usage_count, first_seen_at, last_seen_at)
		SELECT DISTINCT
			lower(replace(trim(job_type), \' \', \'-\')) AS slug,
			trim(job_type) AS name,
			0 AS usage_count,
			COALESCE(MIN(updated_at), strftime(\'%s\', \'now\')) AS first_seen_at,
			COALESCE(MAX(updated_at), strftime(\'%s\', \'now\')) AS last_seen_at
		FROM rss_leads_ai
		WHERE trim(job_type) != \'\'
		GROUP BY trim(job_type)');
}

function load_job_type_options(PDO $db, int $limit): array {
	$stmt = $db->prepare('SELECT name FROM rss_leads_job_types ORDER BY usage_count DESC, last_seen_at DESC, name ASC LIMIT :limit');
	$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
	$stmt->execute();
	$options = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$name = normalize_job_type((string)($row['name'] ?? ''), 'medium');
		if ($name !== '') {
			$options[$name] = true;
		}
	}
	return array_keys($options);
}

function record_job_type(PDOStatement $upsertJobType, string $jobType, int $now): void {
	if ($jobType === '') {
		return;
	}
	$slug = job_type_slug($jobType);
	if ($slug === '') {
		return;
	}
	$upsertJobType->execute([
		':slug' => $slug,
		':name' => $jobType,
		':now' => $now,
	]);
}

function migrate_ai_table(PDO $db): void {
	$createSql = 'CREATE TABLE IF NOT EXISTS rss_leads_ai (
		entry_id INTEGER PRIMARY KEY,
		link TEXT NOT NULL,
		summary TEXT NOT NULL,
		priority TEXT NOT NULL CHECK(priority IN ("low", "medium", "high", "not_hiring")),
		monthly_amount TEXT NOT NULL DEFAULT \'\',
		job_type TEXT NOT NULL DEFAULT \'\',
		scam_likelihood INTEGER NOT NULL DEFAULT 0 CHECK(scam_likelihood >= 0 AND scam_likelihood <= 100),
		model TEXT NOT NULL,
		input_hash TEXT NOT NULL,
		created_at INTEGER NOT NULL,
		updated_at INTEGER NOT NULL
	)';

	$existingSql = $db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'rss_leads_ai'")->fetchColumn();
	if ($existingSql === false) {
		$db->exec($createSql);
		ensure_job_type_table($db);
		return;
	}
	$columns = ai_table_columns($db);
	$hasMonthlyAmount = isset($columns['monthly_amount']);
	$hasJobType = isset($columns['job_type']);
	$hasScamLikelihood = isset($columns['scam_likelihood']);
	$prioritySupportsNotHiring = is_string($existingSql) && strpos($existingSql, '"not_hiring"') !== false;
	if ($prioritySupportsNotHiring) {
		if (!$hasMonthlyAmount) {
			$db->exec('ALTER TABLE rss_leads_ai ADD COLUMN monthly_amount TEXT NOT NULL DEFAULT \'\'');
		}
		if (!$hasJobType) {
			$db->exec('ALTER TABLE rss_leads_ai ADD COLUMN job_type TEXT NOT NULL DEFAULT \'\'');
		}
		if (!$hasScamLikelihood) {
			$db->exec('ALTER TABLE rss_leads_ai ADD COLUMN scam_likelihood INTEGER NOT NULL DEFAULT 0');
		}
		ensure_job_type_table($db);
		return;
	}
	if (!$hasMonthlyAmount) {
		$db->exec('ALTER TABLE rss_leads_ai ADD COLUMN monthly_amount TEXT NOT NULL DEFAULT \'\'');
		$hasMonthlyAmount = true;
	}
	if (!$hasJobType) {
		$db->exec('ALTER TABLE rss_leads_ai ADD COLUMN job_type TEXT NOT NULL DEFAULT \'\'');
		$hasJobType = true;
	}
	if (!$hasScamLikelihood) {
		$db->exec('ALTER TABLE rss_leads_ai ADD COLUMN scam_likelihood INTEGER NOT NULL DEFAULT 0');
		$hasScamLikelihood = true;
	}

	$db->beginTransaction();
	$db->exec('DROP TABLE IF EXISTS rss_leads_ai_new');
	$db->exec(str_replace('rss_leads_ai', 'rss_leads_ai_new', $createSql));
	$monthlyAmountSelect = $hasMonthlyAmount ? 'monthly_amount' : "'' AS monthly_amount";
	$jobTypeSelect = $hasJobType ? 'job_type' : "'' AS job_type";
	$scamLikelihoodSelect = $hasScamLikelihood ? 'scam_likelihood' : '0 AS scam_likelihood';
	$db->exec('INSERT INTO rss_leads_ai_new (entry_id, link, summary, priority, monthly_amount, job_type, scam_likelihood, model, input_hash, created_at, updated_at)
		SELECT entry_id, link, summary, priority, ' . $monthlyAmountSelect . ', ' . $jobTypeSelect . ', ' . $scamLikelihoodSelect . ', model, input_hash, created_at, updated_at FROM rss_leads_ai');
	$db->exec('DROP TABLE rss_leads_ai');
	$db->exec('ALTER TABLE rss_leads_ai_new RENAME TO rss_leads_ai');
	$db->commit();
	ensure_job_type_table($db);
}

function sync_high_priority_category_if_available(PDO $db, array &$state): array {
	if (!function_exists('rss_leads_sync_high_priority_feed')) {
		return [
			'available' => false,
		];
	}

	try {
		$result = rss_leads_sync_high_priority_feed($db);
		$result['available'] = true;
		return $result;
	} catch (Throwable $e) {
		record_error($state, 'database', 'high_priority_sync_failed', $e->getMessage());
		return [
			'available' => true,
			'error' => $e->getMessage(),
		];
	}
}

try {
	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	migrate_ai_table($db);
	$db->exec('CREATE INDEX IF NOT EXISTS idx_rss_leads_ai_priority ON rss_leads_ai(priority, updated_at)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_rss_leads_ai_link ON rss_leads_ai(link)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_rss_leads_ai_job_type ON rss_leads_ai(job_type, updated_at)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_rss_leads_ai_scam_likelihood ON rss_leads_ai(scam_likelihood, updated_at)');
	$jobTypeOptions = load_job_type_options($db, $jobTypeOptionLimit);
} catch (Throwable $e) {
	record_error($state, 'database', 'db_open_or_migration_failed', $e->getMessage(), [
		'db_path' => $dbPath,
	]);
	finish_run($state, 'failed', time(), 'Database open or migration failed.');
	save_state($statePath, $state);
	throw $e;
}

$since = time() - ($lookbackDays * 86400);
try {
	if (!empty($entryIds)) {
		$entryIds = array_slice(array_unique($entryIds), 0, $batchSize * 2);
		$placeholders = implode(',', array_fill(0, count($entryIds), '?'));
		$stmt = $db->prepare(
			"SELECT e.id, e.title, e.content, e.link, e.date, f.name AS feed_name
			 FROM entry e
			 JOIN feed f ON f.id = e.id_feed
			 WHERE e.id IN ($placeholders)
			   AND f.name != \"High Priority Reddit Leads\"
			 ORDER BY e.date DESC"
		);
		$stmt->execute($entryIds);
	} else {
		$stmt = $db->prepare(
			'SELECT e.id, e.title, e.content, e.link, e.date, f.name AS feed_name
			 FROM entry e
			 JOIN feed f ON f.id = e.id_feed
			 LEFT JOIN rss_leads_ai ai ON ai.entry_id = e.id
			 WHERE (ai.entry_id IS NULL OR ai.input_hash NOT LIKE :prompt_hash_prefix)
			   AND e.date >= :since
			   AND (f.name LIKE "Reddit Leads%" OR e.link LIKE "%reddit.com/r/%")
			   AND f.name != "High Priority Reddit Leads"
			 ORDER BY e.date DESC
			 LIMIT :limit'
		);
		$stmt->bindValue(':prompt_hash_prefix', $promptVersion . ':%', PDO::PARAM_STR);
		$stmt->bindValue(':since', $since, PDO::PARAM_INT);
		$stmt->bindValue(':limit', $batchSize * 6, PDO::PARAM_INT);
		$stmt->execute();
	}
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
	record_error($state, 'database', 'entry_query_failed', $e->getMessage());
	finish_run($state, 'failed', time(), 'Entry query failed.');
	save_state($statePath, $state);
	throw $e;
}

$groups = [];
foreach ($rows as $row) {
	$link = (string)$row['link'];
	if (!isset($groups[$link])) {
		$groups[$link] = [
			'ids' => [],
			'title' => compact_text((string)$row['title'], 180),
			'subreddit' => subreddit_from_url($link),
			'text' => compact_text((string)$row['content'], $contentChars),
			'link' => $link,
		];
	}
	$groups[$link]['ids'][] = (int)$row['id'];
	if (count($groups) >= $batchSize) {
		break;
	}
}

$upsert = $db->prepare(
	'INSERT INTO rss_leads_ai (entry_id, link, summary, priority, monthly_amount, job_type, scam_likelihood, model, input_hash, created_at, updated_at)
	 VALUES (:entry_id, :link, :summary, :priority, :monthly_amount, :job_type, :scam_likelihood, :model, :input_hash, :created_at, :updated_at)
	 ON CONFLICT(entry_id) DO UPDATE SET
		link = excluded.link,
		summary = excluded.summary,
		priority = excluded.priority,
		monthly_amount = excluded.monthly_amount,
		job_type = excluded.job_type,
		scam_likelihood = excluded.scam_likelihood,
		model = excluded.model,
		input_hash = excluded.input_hash,
		updated_at = excluded.updated_at'
);
$upsertJobType = $db->prepare(
	'INSERT INTO rss_leads_job_types (slug, name, usage_count, first_seen_at, last_seen_at)
	 VALUES (:slug, :name, 1, :now, :now)
	 ON CONFLICT(slug) DO UPDATE SET
		name = excluded.name,
		usage_count = usage_count + 1,
		last_seen_at = excluded.last_seen_at'
);
$routeByLink = $db->prepare(
	'UPDATE entry
	 SET is_read = CASE
		WHEN id_feed IN (SELECT id FROM feed WHERE name = "Reddit Leads - unqualified deep-research communities") THEN :unqualified_read
		WHEN id_feed IN (SELECT id FROM feed WHERE name = "Reddit Leads - qualified deep-research communities") THEN :qualified_read
		ELSE is_read
	 END,
	 lastUserModified = :updated_at
	 WHERE link = :link
	   AND id_feed IN (
		SELECT id FROM feed
		WHERE name IN (
			"Reddit Leads - qualified deep-research communities",
			"Reddit Leads - unqualified deep-research communities"
		)
	   )'
);

function save_classification(PDOStatement $upsert, PDOStatement $routeByLink, PDOStatement $upsertJobType, array $group, string $link, string $summary, string $priority, string $monthlyAmount, string $jobType, int $scamLikelihood, string $model, string $hash, int $now): int {
	$saved = 0;
	foreach ($group['ids'] as $entryId) {
		$upsert->execute([
			':entry_id' => $entryId,
			':link' => $link,
			':summary' => $summary,
			':priority' => $priority,
			':monthly_amount' => $monthlyAmount,
			':job_type' => $jobType,
			':scam_likelihood' => $scamLikelihood,
			':model' => $model,
			':input_hash' => $hash,
			':created_at' => $now,
			':updated_at' => $now,
	]);
	$saved++;
	}
	$isNotHiring = $priority === 'not_hiring';
	$isHighPriority = $priority === 'high';
	$routeByLink->execute([
		':qualified_read' => ($isNotHiring || $isHighPriority) ? 1 : 0,
		':unqualified_read' => $isNotHiring ? 0 : 1,
		':updated_at' => $now,
		':link' => $link,
	]);
	record_job_type($upsertJobType, $jobType, $now);
	return $saved;
}

$refineRows = [];
if (empty($entryIds)) {
	try {
		$refineStmt = $db->prepare(
			'SELECT e.id, e.title, e.content, e.link, e.date, f.name AS feed_name, ai.priority AS ai_priority, ai.updated_at AS ai_updated_at
			 FROM entry e
			 JOIN feed f ON f.id = e.id_feed
			 JOIN rss_leads_ai ai ON ai.entry_id = e.id
			 WHERE ai.input_hash LIKE :gemma_hash_prefix
			   AND e.date >= :since
			   AND (f.name LIKE "Reddit Leads%" OR e.link LIKE "%reddit.com/r/%")
			   AND f.name != "High Priority Reddit Leads"
			 ORDER BY
			   CASE ai.priority
				 WHEN "high" THEN 0
				 WHEN "medium" THEN 1
				 WHEN "low" THEN 2
				 ELSE 3
			   END,
			   ai.updated_at ASC
			 LIMIT :limit'
		);
		$refineStmt->bindValue(':gemma_hash_prefix', $promptVersion . ':gemma:%', PDO::PARAM_STR);
		$refineStmt->bindValue(':since', $since, PDO::PARAM_INT);
		$refineStmt->bindValue(':limit', $flashLiteRefineBatchSize * 4, PDO::PARAM_INT);
		$refineStmt->execute();
		$refineRows = $refineStmt->fetchAll(PDO::FETCH_ASSOC);
	} catch (Throwable $e) {
		record_error($state, 'database', 'refine_query_failed', $e->getMessage());
	}
}

$refineGroups = [];
foreach ($refineRows as $row) {
	$link = (string)$row['link'];
	if (!isset($refineGroups[$link])) {
		$refineGroups[$link] = [
			'ids' => [],
			'title' => compact_text((string)$row['title'], 180),
			'subreddit' => subreddit_from_url($link),
			'text' => compact_text((string)$row['content'], $contentChars),
			'link' => $link,
		];
	}
	$refineGroups[$link]['ids'][] = (int)$row['id'];
	if (count($refineGroups) >= $flashLiteRefineBatchSize) {
		break;
	}
}

$now = time();
$saved = 0;
$priorityCounts = [];
$jobTypeCounts = [];
$locallyClassified = 0;
$geminiGroups = [];
foreach ($groups as $link => $group) {
	$localSummary = local_not_hiring_summary($group);
	if ($localSummary === null) {
		$geminiGroups[$link] = $group;
		continue;
	}
	$hash = $promptVersion . ':local:' . hash('sha256', json_encode($group, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	try {
		$saved += save_classification($upsert, $routeByLink, $upsertJobType, $group, $link, $localSummary, 'not_hiring', '', '', 0, 'local-heuristic', $hash, $now);
		$priorityCounts['not_hiring'] = ($priorityCounts['not_hiring'] ?? 0) + 1;
		$locallyClassified++;
	} catch (Throwable $e) {
		record_error($state, 'database', 'local_classification_save_failed', $e->getMessage(), [
			'link' => $link,
			'priority' => 'not_hiring',
		]);
	}
}

if (empty($geminiGroups) && empty($refineGroups)) {
	$message = "Saved {$saved} AI classifications without Gemini requests.";
	$state['next_batch_at'] = (int)($state['next_run_at'] ?? ($now + $intervalSeconds));
	$highPrioritySync = sync_high_priority_category_if_available($db, $state);
	finish_run($state, 'success', time(), $message, [
		'candidate_rows' => count($rows),
		'unique_links' => count($groups),
		'local_classified' => $locallyClassified,
		'refine_candidates' => 0,
		'refine_sent_items' => 0,
		'refine_returned_items' => 0,
		'refined_links' => 0,
		'gemma_candidates' => 0,
		'gemma_first_pass_batch_size' => $gemmaFirstPassBatchSize,
		'gemma_first_pass_batches' => 0,
		'gemma_first_pass_sent_items' => 0,
		'gemma_first_pass_returned_items' => 0,
		'gemma_cached_links' => 0,
		'sent_items' => 0,
		'returned_items' => 0,
		'saved' => $saved,
		'model' => 'local-heuristic',
		'model_daily_budgets' => $state['model_daily_budgets'] ?? null,
		'priority_counts' => $priorityCounts,
		'job_type_counts' => $jobTypeCounts,
		'job_type_options' => $jobTypeOptions,
		'high_priority_sync' => $highPrioritySync,
		'prompt_version' => $promptVersion,
	]);
	save_state($statePath, $state);
	echo $message . "\n";
	exit(0);
}

reset_daily_budget_if_needed($state, $now, $dailyRequestBudget);
if ($dailyRequestBudget > 0 && (int)($state['daily_budget']['remaining'] ?? 0) <= 0) {
	$message = 'Daily Gemini request budget used; skipping remote AI batch.';
	$state['next_batch_at'] = max((int)($state['next_run_at'] ?? 0), (int)($state['daily_budget']['reset_at'] ?? 0));
	$highPrioritySync = sync_high_priority_category_if_available($db, $state);
	finish_run($state, 'skipped', time(), $message, [
		'candidate_rows' => count($rows),
		'unique_links' => count($groups),
		'local_classified' => $locallyClassified,
		'sent_items' => 0,
		'returned_items' => 0,
		'saved' => $saved,
		'daily_budget' => $state['daily_budget'],
		'model_daily_budgets' => $state['model_daily_budgets'] ?? null,
		'priority_counts' => $priorityCounts,
		'job_type_counts' => $jobTypeCounts,
		'job_type_options' => $jobTypeOptions,
		'high_priority_sync' => $highPrioritySync,
		'prompt_version' => $promptVersion,
	]);
	save_state($statePath, $state);
	echo $message . "\n";
	exit(0);
}

$refineSentItems = 0;
$refineReturnedItems = 0;
$refinedLinks = 0;
$refineModel = null;
if (!empty($refineGroups)) {
	$refineItems = [];
	$refineIdToLink = [];
	$n = 1;
	foreach ($refineGroups as $link => $group) {
		$id = 'r' . $n++;
		$refineIdToLink[$id] = $link;
		$refineItems[] = [
			'id' => $id,
			'sr' => $group['subreddit'],
			'title' => $group['title'],
			'text' => $group['text'],
		];
	}
	$refineSentItems = count($refineItems);
	$jobTypeOptions = load_job_type_options($db, $jobTypeOptionLimit);
	$refineRun = run_classification_batch($apiKey, $refineModels, $refineItems, $state, $statePath, $dailyRequestBudget, $modelDailyLimits, $jobTypeOptions, $quotaCooldownSeconds, $intervalSeconds);
	$refineResults = is_array($refineRun['results']) ? $refineRun['results'] : [];
	$refineReturnedItems = count($refineResults);
	$refineModel = is_string($refineRun['model'] ?? null) ? $refineRun['model'] : null;
	foreach ($refineResults as $result) {
		$id = is_array($result) ? (string)($result['id'] ?? '') : '';
		$link = $refineIdToLink[$id] ?? null;
		if ($link === null) {
			continue;
		}
		$summary = compact_text((string)($result['summary'] ?? ''), 180);
		$priority = strtolower((string)($result['priority'] ?? 'low'));
		if (!in_array($priority, ['low', 'medium', 'high', 'not_hiring'], true) || $summary === '') {
			record_error($state, 'gemini_response', 'invalid_refine_classification', 'Flash Lite returned an invalid priority or empty summary.', [
				'priority' => $priority,
				'id' => $id,
			]);
			continue;
		}
		$group = $refineGroups[$link] ?? null;
		if ($group === null) {
			continue;
		}
		$monthlyAmount = monthly_amount_for_result($result, $group, $priority);
		$jobType = job_type_for_result($result, $group, $priority);
		$scamLikelihood = scam_likelihood_for_result($result);
		$priorityCounts[$priority] = ($priorityCounts[$priority] ?? 0) + 1;
		if ($jobType !== '') {
			$jobTypeCounts[$jobType] = ($jobTypeCounts[$jobType] ?? 0) + 1;
		}
		$hash = $promptVersion . ':refine:' . hash('sha256', json_encode($group, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		try {
			$saved += save_classification($upsert, $routeByLink, $upsertJobType, $group, $link, $summary, $priority, $monthlyAmount, $jobType, $scamLikelihood, $refineModel ?: 'gemini-refine', $hash, $now);
			$refinedLinks++;
		} catch (Throwable $e) {
			record_error($state, 'database', 'refine_save_failed', $e->getMessage(), [
				'link' => $link,
				'priority' => $priority,
			]);
		}
	}
	$jobTypeOptions = load_job_type_options($db, $jobTypeOptionLimit);
}

$gemmaSentItems = 0;
$gemmaReturnedItems = 0;
$gemmaCachedLinks = 0;
$gemmaBatches = 0;
$gemmaQueue = array_slice($geminiGroups, 0, $gemmaFirstPassRequestsPerRun * $gemmaFirstPassBatchSize, true);
while (!empty($gemmaQueue) && $gemmaBatches < $gemmaFirstPassRequestsPerRun) {
	$batchGroups = array_slice($gemmaQueue, 0, $gemmaFirstPassBatchSize, true);
	$gemmaQueue = array_slice($gemmaQueue, count($batchGroups), null, true);
	if (empty($batchGroups)) {
		break;
	}
	$gemmaItems = [];
	$gemmaIdToLink = [];
	$n = 1;
	foreach ($batchGroups as $link => $group) {
		$id = 'g' . $n++;
		$gemmaIdToLink[$id] = $link;
		$gemmaItems[] = [
			'id' => $id,
			'sr' => $group['subreddit'],
			'title' => $group['title'],
			'text' => $group['text'],
		];
	}
	$gemmaSentItems += count($gemmaItems);
	$gemmaBatches++;
	$jobTypeOptions = load_job_type_options($db, $jobTypeOptionLimit);
	$gemmaRun = run_classification_batch($apiKey, [$gemmaModel], $gemmaItems, $state, $statePath, $dailyRequestBudget, $modelDailyLimits, $jobTypeOptions, $quotaCooldownSeconds, $intervalSeconds);
	$gemmaResults = is_array($gemmaRun['results']) ? $gemmaRun['results'] : [];
	$gemmaReturnedItems += count($gemmaResults);
	if (empty($gemmaResults)) {
		break;
	}
	foreach ($gemmaResults as $result) {
		$id = is_array($result) ? (string)($result['id'] ?? '') : '';
		$link = $gemmaIdToLink[$id] ?? null;
		if ($link === null) {
			continue;
		}
		$summary = compact_text((string)($result['summary'] ?? ''), 180);
		$priority = strtolower((string)($result['priority'] ?? 'low'));
		if (!in_array($priority, ['low', 'medium', 'high', 'not_hiring'], true) || $summary === '') {
			record_error($state, 'gemini_response', 'invalid_gemma_classification', 'Gemma returned an invalid priority or empty summary.', [
				'priority' => $priority,
				'id' => $id,
			]);
			continue;
		}
		$group = $batchGroups[$link] ?? null;
		if ($group === null) {
			continue;
		}
		$monthlyAmount = monthly_amount_for_result($result, $group, $priority);
		$jobType = job_type_for_result($result, $group, $priority);
		$scamLikelihood = scam_likelihood_for_result($result);
		$priorityCounts[$priority] = ($priorityCounts[$priority] ?? 0) + 1;
		if ($jobType !== '') {
			$jobTypeCounts[$jobType] = ($jobTypeCounts[$jobType] ?? 0) + 1;
		}
		$hash = $promptVersion . ':gemma:' . hash('sha256', json_encode($group, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		try {
			$saved += save_classification($upsert, $routeByLink, $upsertJobType, $group, $link, $summary, $priority, $monthlyAmount, $jobType, $scamLikelihood, $gemmaModel, $hash, $now);
			$gemmaCachedLinks++;
		} catch (Throwable $e) {
			record_error($state, 'database', 'gemma_save_failed', $e->getMessage(), [
				'link' => $link,
				'priority' => $priority,
			]);
		}
	}
	$jobTypeOptions = load_job_type_options($db, $jobTypeOptionLimit);
}

$message = "Saved {$saved} AI classifications.";
$state['quota_backoff_until'] = 0;
$state['next_batch_at'] = (int)($state['next_run_at'] ?? ($now + $intervalSeconds));
$highPrioritySync = sync_high_priority_category_if_available($db, $state);
finish_run($state, 'success', time(), $message, [
	'candidate_rows' => count($rows),
	'unique_links' => count($groups),
	'local_classified' => $locallyClassified,
	'refine_candidates' => count($refineGroups),
	'refine_sent_items' => $refineSentItems,
	'refine_returned_items' => $refineReturnedItems,
	'refined_links' => $refinedLinks,
	'refine_model' => $refineModel,
	'gemma_candidates' => count($geminiGroups),
	'gemma_first_pass_batch_size' => $gemmaFirstPassBatchSize,
	'gemma_first_pass_batches' => $gemmaBatches,
	'gemma_first_pass_sent_items' => $gemmaSentItems,
	'gemma_first_pass_returned_items' => $gemmaReturnedItems,
	'gemma_cached_links' => $gemmaCachedLinks,
	'sent_items' => $refineSentItems + $gemmaSentItems,
	'returned_items' => $refineReturnedItems + $gemmaReturnedItems,
	'saved' => $saved,
	'model' => $refineModel ?: ($gemmaCachedLinks > 0 ? $gemmaModel : 'local-heuristic'),
	'daily_budget' => $state['daily_budget'] ?? null,
	'model_daily_budgets' => $state['model_daily_budgets'] ?? null,
	'priority_counts' => $priorityCounts,
	'job_type_counts' => $jobTypeCounts,
	'job_type_options' => $jobTypeOptions,
	'high_priority_sync' => $highPrioritySync,
	'prompt_version' => $promptVersion,
]);
save_state($statePath, $state);

echo $message . "\n";
