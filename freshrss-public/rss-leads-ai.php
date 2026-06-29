<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$statePath = getenv('AI_FILTER_STATE_FILE') ?: "/var/www/FreshRSS/data/users/{$user}/rss_leads_ai_state.json";
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

function latest_ai_by_link_sql(bool $hasMonthlyAmount, bool $hasJobType): string {
	$monthlyAmountSelect = $hasMonthlyAmount ? 'monthly_amount' : "'' AS monthly_amount";
	$jobTypeSelect = $hasJobType ? 'job_type' : "'' AS job_type";
	return 'SELECT entry_id, link, summary, priority, monthly_amount, job_type, model, updated_at
		FROM (
			SELECT entry_id, link, summary, priority, ' . $monthlyAmountSelect . ', ' . $jobTypeSelect . ', model, updated_at,
				ROW_NUMBER() OVER (PARTITION BY link ORDER BY updated_at DESC, entry_id DESC) AS rn
			FROM rss_leads_ai
		)
		WHERE rn = 1';
}

try {
	$response['status']['state'] = public_ai_state(read_ai_state($statePath));

	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	if (!ai_table_exists($db, 'rss_leads_ai')) {
		$response['ok'] = true;
		echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
	$hasMonthlyAmount = ai_table_has_column($db, 'monthly_amount');
	$hasJobType = ai_table_has_column($db, 'job_type');
	$latestSql = latest_ai_by_link_sql($hasMonthlyAmount, $hasJobType);

	foreach ($db->query('SELECT priority, COUNT(*) AS count FROM (' . $latestSql . ') GROUP BY priority') as $row) {
		$response['status']['priority_counts'][(string)$row['priority']] = (int)$row['count'];
		$response['status']['total_classified'] += (int)$row['count'];
	}

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

	$latest = $db->query('SELECT entry_id, summary, priority, monthly_amount, job_type, model, updated_at FROM (' . $latestSql . ') ORDER BY updated_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	if (is_array($latest)) {
		$response['status']['latest_summary'] = [
			'entry_id' => (string)$latest['entry_id'],
			'summary' => (string)$latest['summary'],
			'priority' => (string)$latest['priority'],
			'monthly_amount' => (string)$latest['monthly_amount'],
			'job_type' => (string)$latest['job_type'],
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
		$stmt = $db->prepare(
			"WITH requested(entry_id) AS (VALUES {$values}),
			latest_ai AS (" . $latestSql . ")
			SELECT requested.entry_id AS requested_entry_id,
				COALESCE(direct.summary, by_link.summary) AS summary,
				COALESCE(direct.priority, by_link.priority) AS priority,
				{$directMonthlyAmount} AS monthly_amount,
				{$directJobType} AS job_type,
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
		$stmt = $db->prepare('SELECT entry_id AS requested_entry_id, summary, priority, monthly_amount, job_type, model, updated_at FROM (' . $latestSql . ') ORDER BY updated_at DESC LIMIT ?');
		$stmt->execute([$limit]);
	}

	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$entryId = (string)$row['requested_entry_id'];
		$response['items'][$entryId] = [
			'summary' => (string)$row['summary'],
			'priority' => (string)$row['priority'],
			'monthly_amount' => (string)$row['monthly_amount'],
			'job_type' => (string)$row['job_type'],
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
