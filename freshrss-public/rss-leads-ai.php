<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$statePath = getenv('AI_FILTER_STATE_FILE') ?: "/var/www/FreshRSS/data/users/{$user}/rss_leads_ai_state.json";
$benchmarkPath = getenv('AI_BENCHMARK_STATE_FILE') ?: "/var/www/FreshRSS/data/users/{$user}/rss_leads_ai_benchmark.json";
$idsParam = (string)($_GET['ids'] ?? '');
$ids = [];
foreach (explode(',', $idsParam) as $id) {
	if (preg_match('/^\d+$/', trim($id))) {
		$ids[] = trim($id);
	}
}
$ids = array_slice(array_values(array_unique($ids)), 0, 120);

$response = [
	'ok' => false,
	'items' => [],
	'status' => [
		'state' => [],
		'latest_summary' => null,
		'priority_counts' => [],
		'job_type_counts' => [],
		'job_types' => [],
		'analytics' => [],
		'benchmark' => null,
		'total_classified' => 0,
	],
	'error' => null,
];

function read_ai_state(string $path): array {
	if (!is_file($path)) {
		return [];
	}
	$json = file_get_contents($path);
	$decoded = is_string($json) ? json_decode($json, true) : null;
	return is_array($decoded) ? $decoded : [];
}

function read_benchmark_report(string $path): ?array {
	if (!is_file($path)) {
		return null;
	}
	$json = file_get_contents($path);
	$decoded = is_string($json) ? json_decode($json, true) : null;
	if (!is_array($decoded)) {
		return null;
	}
	return [
		'generated_at' => (int)($decoded['generated_at'] ?? 0),
		'sample_size' => (int)($decoded['sample_size'] ?? 0),
		'high_intelligence_model' => (string)($decoded['high_intelligence_model'] ?? ($decoded['judge_model'] ?? '')),
		'high_intelligence_agent' => (string)($decoded['high_intelligence_agent'] ?? ($decoded['judge_agent'] ?? '')),
		'high_intelligence_cli' => (string)($decoded['high_intelligence_cli'] ?? ''),
		'high_intelligence_provider' => (string)($decoded['high_intelligence_provider'] ?? ''),
		'high_intelligence_sdk_command' => (string)($decoded['high_intelligence_sdk_command'] ?? ''),
		'high_intelligence_fallback_models' => is_array($decoded['high_intelligence_fallback_models'] ?? null) ? array_values($decoded['high_intelligence_fallback_models']) : [],
		'low_intelligence_models' => is_array($decoded['low_intelligence_models'] ?? null) ? array_values($decoded['low_intelligence_models']) : (is_array($decoded['candidate_models'] ?? null) ? array_values($decoded['candidate_models']) : []),
		'judge_model' => (string)($decoded['judge_model'] ?? ''),
		'judge_agent' => (string)($decoded['judge_agent'] ?? ''),
		'models' => array_values(array_map(static function ($row): array {
			$row = is_array($row) ? $row : [];
			return [
				'model' => (string)($row['model'] ?? ''),
				'success' => (int)($row['success'] ?? 0),
				'failed' => (int)($row['failed'] ?? 0),
				'avg_latency_ms' => (int)($row['avg_latency_ms'] ?? 0),
				'avg_quality' => round((float)($row['avg_quality'] ?? 0), 2),
				'avg_priority_score' => round((float)($row['avg_priority_score'] ?? 0), 2),
				'avg_summary_score' => round((float)($row['avg_summary_score'] ?? 0), 2),
				'avg_scam_score' => round((float)($row['avg_scam_score'] ?? 0), 2),
				'judge_status' => (int)($row['judge_status'] ?? 0),
				'judge_fallback' => (string)($row['judge_fallback'] ?? ''),
				'judge_model_used' => (string)($row['judge_model_used'] ?? ''),
				'notes' => (string)($row['notes'] ?? ''),
			];
		}, is_array($decoded['models'] ?? null) ? $decoded['models'] : [])),
	];
}

function public_ai_state(array $state): array {
	$allowed = [
		'updated_at',
		'current_status',
		'last_started_at',
		'last_finished_at',
		'last_success_at',
		'last_failed_at',
		'last_skipped_at',
		'last_message',
		'last_error',
		'last_batch',
		'next_run_at',
		'next_batch_at',
		'quota_backoff_until',
		'model_backoffs',
		'last_quota_error_at',
		'last_quota_model',
		'run_counts',
		'request_counts',
		'error_counts',
		'daily_budget',
		'model_daily_budgets',
		'requests',
		'errors',
		'config',
	];
	$public = [];
	foreach ($allowed as $key) {
		if (array_key_exists($key, $state)) {
			$public[$key] = $state[$key];
		}
	}
	if (isset($public['requests']) && is_array($public['requests'])) {
		$public['requests'] = array_slice($public['requests'], 0, 20);
	}
	if (isset($public['errors']) && is_array($public['errors'])) {
		$public['errors'] = array_slice($public['errors'], 0, 20);
	}
	return $public;
}

function ai_table_has_column(PDO $db, string $name): bool {
	foreach ($db->query('PRAGMA table_info(rss_leads_ai)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
		if (($column['name'] ?? '') === $name) {
			return true;
		}
	}
	return false;
}

function ai_table_exists(PDO $db, string $table): bool {
	$stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
	$stmt->execute([$table]);
	return $stmt->fetchColumn() !== false;
}

function latest_ai_by_link_sql(bool $hasMonthlyAmount, bool $hasJobType, bool $hasScamLikelihood): string {
	$monthlyAmountSelect = $hasMonthlyAmount ? 'monthly_amount' : "'' AS monthly_amount";
	$jobTypeSelect = $hasJobType ? 'job_type' : "'' AS job_type";
	$scamLikelihoodSelect = $hasScamLikelihood ? 'scam_likelihood' : '0 AS scam_likelihood';
	return 'SELECT entry_id, link, summary, priority, monthly_amount, job_type, scam_likelihood, model, updated_at
		FROM (
			SELECT entry_id, link, summary, priority, ' . $monthlyAmountSelect . ', ' . $jobTypeSelect . ', ' . $scamLikelihoodSelect . ', model, updated_at,
				ROW_NUMBER() OVER (PARTITION BY link ORDER BY updated_at DESC, entry_id DESC) AS rn
			FROM rss_leads_ai
		)
		WHERE rn = 1';
}

try {
	$response['status']['state'] = public_ai_state(read_ai_state($statePath));
	$response['status']['benchmark'] = read_benchmark_report($benchmarkPath);

	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	if (!ai_table_exists($db, 'rss_leads_ai')) {
		$response['ok'] = true;
		echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
	$hasMonthlyAmount = ai_table_has_column($db, 'monthly_amount');
	$hasJobType = ai_table_has_column($db, 'job_type');
	$hasScamLikelihood = ai_table_has_column($db, 'scam_likelihood');
	$latestSql = latest_ai_by_link_sql($hasMonthlyAmount, $hasJobType, $hasScamLikelihood);

	foreach ($db->query('SELECT priority, COUNT(*) AS count FROM (' . $latestSql . ') GROUP BY priority') as $row) {
		$response['status']['priority_counts'][(string)$row['priority']] = (int)$row['count'];
		$response['status']['total_classified'] += (int)$row['count'];
	}

	$analytics = [
		'updated_last_24h' => 0,
		'updated_last_7d' => 0,
		'unread_classified' => 0,
		'avg_scam_likelihood' => 0,
		'scam_buckets' => [
			'0' => 0,
			'1-24' => 0,
			'25-49' => 0,
			'50-74' => 0,
			'75-100' => 0,
		],
		'model_counts' => [],
		'recent' => [],
	];
	$now = time();
	$analytics['updated_last_24h'] = (int)$db->query('SELECT COUNT(*) FROM (' . $latestSql . ') WHERE updated_at >= ' . (int)($now - 86400))->fetchColumn();
	$analytics['updated_last_7d'] = (int)$db->query('SELECT COUNT(*) FROM (' . $latestSql . ') WHERE updated_at >= ' . (int)($now - 604800))->fetchColumn();
	$analytics['unread_classified'] = (int)$db->query('SELECT COUNT(*) FROM (' . $latestSql . ') ai JOIN entry e ON e.link = ai.link WHERE e.is_read = 0')->fetchColumn();
	if ($hasScamLikelihood) {
		$analytics['avg_scam_likelihood'] = round((float)$db->query('SELECT AVG(scam_likelihood) FROM (' . $latestSql . ')')->fetchColumn(), 1);
		foreach ($db->query('SELECT scam_likelihood FROM (' . $latestSql . ')') as $row) {
			$score = max(0, min(100, (int)$row['scam_likelihood']));
			if ($score === 0) {
				$analytics['scam_buckets']['0']++;
			} elseif ($score < 25) {
				$analytics['scam_buckets']['1-24']++;
			} elseif ($score < 50) {
				$analytics['scam_buckets']['25-49']++;
			} elseif ($score < 75) {
				$analytics['scam_buckets']['50-74']++;
			} else {
				$analytics['scam_buckets']['75-100']++;
			}
		}
	}
	foreach ($db->query('SELECT model, COUNT(*) AS count FROM (' . $latestSql . ') GROUP BY model ORDER BY count DESC, model ASC LIMIT 8') as $row) {
		$analytics['model_counts'][(string)$row['model']] = (int)$row['count'];
	}
	$recent = $db->query('SELECT ai.entry_id, ai.summary, ai.priority, ai.monthly_amount, ai.job_type, ai.scam_likelihood, ai.model, ai.updated_at, e.title, e.link, f.name AS feed_name
		FROM (' . $latestSql . ') ai
		LEFT JOIN entry e ON e.id = ai.entry_id
		LEFT JOIN feed f ON f.id = e.id_feed
		ORDER BY ai.updated_at DESC
		LIMIT 12');
	foreach ($recent as $row) {
		$analytics['recent'][] = [
			'entry_id' => (string)$row['entry_id'],
			'title' => (string)($row['title'] ?? ''),
			'link' => (string)($row['link'] ?? ''),
			'feed_name' => (string)($row['feed_name'] ?? ''),
			'summary' => (string)$row['summary'],
			'priority' => (string)$row['priority'],
			'monthly_amount' => (string)$row['monthly_amount'],
			'job_type' => (string)$row['job_type'],
			'scam_likelihood' => max(0, min(100, (int)$row['scam_likelihood'])),
			'model' => (string)$row['model'],
			'updated_at' => (int)$row['updated_at'],
		];
	}
	$response['status']['analytics'] = $analytics;

	if ($hasJobType) {
		foreach ($db->query('SELECT job_type, COUNT(*) AS count FROM (' . $latestSql . ') WHERE job_type != "" GROUP BY job_type ORDER BY count DESC, job_type ASC LIMIT 20') as $row) {
			$response['status']['job_type_counts'][(string)$row['job_type']] = (int)$row['count'];
		}
	}

	if (ai_table_exists($db, 'rss_leads_job_types')) {
		foreach ($db->query('SELECT name, usage_count, first_seen_at, last_seen_at FROM rss_leads_job_types ORDER BY usage_count DESC, last_seen_at DESC, name ASC LIMIT 30') as $row) {
			$response['status']['job_types'][] = [
				'name' => (string)$row['name'],
				'usage_count' => (int)$row['usage_count'],
				'first_seen_at' => (int)$row['first_seen_at'],
				'last_seen_at' => (int)$row['last_seen_at'],
			];
		}
	}

	$latest = $db->query('SELECT entry_id, summary, priority, monthly_amount, job_type, scam_likelihood, model, updated_at FROM (' . $latestSql . ') ORDER BY updated_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	if (is_array($latest)) {
		$response['status']['latest_summary'] = [
			'entry_id' => (string)$latest['entry_id'],
			'summary' => (string)$latest['summary'],
			'priority' => (string)$latest['priority'],
			'monthly_amount' => (string)$latest['monthly_amount'],
			'job_type' => (string)$latest['job_type'],
			'scam_likelihood' => max(0, min(100, (int)$latest['scam_likelihood'])),
			'model' => (string)$latest['model'],
			'updated_at' => (int)$latest['updated_at'],
		];
	}

	if (!empty($ids)) {
		$values = implode(',', array_fill(0, count($ids), '(?)'));
		$directMonthlyAmount = $hasMonthlyAmount
			? "COALESCE(direct.monthly_amount, by_link.monthly_amount, '')"
			: "COALESCE(by_link.monthly_amount, '')";
		$directJobType = $hasJobType
			? "COALESCE(direct.job_type, by_link.job_type, '')"
			: "COALESCE(by_link.job_type, '')";
		$directScamLikelihood = $hasScamLikelihood
			? "COALESCE(direct.scam_likelihood, by_link.scam_likelihood, 0)"
			: "COALESCE(by_link.scam_likelihood, 0)";
		$stmt = $db->prepare(
			"WITH requested(entry_id) AS (VALUES {$values}),
			latest_ai AS (" . $latestSql . ")
			SELECT requested.entry_id AS requested_entry_id,
				COALESCE(direct.summary, by_link.summary) AS summary,
				COALESCE(direct.priority, by_link.priority) AS priority,
				{$directMonthlyAmount} AS monthly_amount,
				{$directJobType} AS job_type,
				{$directScamLikelihood} AS scam_likelihood,
				COALESCE(direct.model, by_link.model) AS model,
				COALESCE(direct.updated_at, by_link.updated_at) AS updated_at
			FROM requested
			LEFT JOIN rss_leads_ai direct ON direct.entry_id = requested.entry_id
			LEFT JOIN entry e ON e.id = requested.entry_id
			LEFT JOIN latest_ai by_link ON by_link.link = e.link
			WHERE COALESCE(direct.entry_id, by_link.entry_id) IS NOT NULL"
		);
		$stmt->execute($ids);
	} else {
		$limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
		$stmt = $db->prepare('SELECT entry_id AS requested_entry_id, summary, priority, monthly_amount, job_type, scam_likelihood, model, updated_at FROM (' . $latestSql . ') ORDER BY updated_at DESC LIMIT ?');
		$stmt->execute([$limit]);
	}

	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$entryId = (string)$row['requested_entry_id'];
		$response['items'][$entryId] = [
			'summary' => (string)$row['summary'],
			'priority' => (string)$row['priority'],
			'monthly_amount' => (string)$row['monthly_amount'],
			'job_type' => (string)$row['job_type'],
			'scam_likelihood' => max(0, min(100, (int)$row['scam_likelihood'])),
			'model' => (string)$row['model'],
			'updated_at' => (int)$row['updated_at'],
		];
	}
	$response['ok'] = true;
} catch (Throwable $e) {
	http_response_code(500);
	$response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
