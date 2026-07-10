<?php
declare(strict_types=1);

$freshRssUser = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
if (preg_match('/^[A-Za-z0-9_.-]+$/', $freshRssUser) !== 1) {
	throw new InvalidArgumentException('FreshRSS username contains unsupported characters.');
}

date_default_timezone_set(getenv('TZ') ?: 'Asia/Kolkata');

$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$freshRssUser}/db.sqlite";
$highPriorityKeepDays = max(1, min(365, (int)(getenv('RSS_LEADS_HIGH_PRIORITY_KEEP_DAYS') ?: 7)));
$lowMediumMaxEntries = max(0, min(100000, (int)(getenv('RSS_LEADS_LOW_MEDIUM_MAX_ENTRIES') ?: 3000)));
$deleteNotHiringEod = in_array(strtolower((string)(getenv('RSS_LEADS_DELETE_NOT_HIRING_EOD') ?: '1')), ['1', 'true', 'yes', 'on'], true);
$dryRun = in_array('--dry-run', $argv ?? [], true);
$now = time();
$highPriorityCutoff = $now - ($highPriorityKeepDays * 86400);
$todayStart = strtotime('today', $now);
if ($todayStart === false) {
	$todayStart = $now;
}

$highPrioritySyncPath = __DIR__ . '/sync-freshrss-high-priority.php';
if (is_file($highPrioritySyncPath)) {
	require_once $highPrioritySyncPath;
}

function retention_prepare(PDO $db, string $sql, array $params = []): void {
	$stmt = $db->prepare($sql);
	foreach ($params as $name => $value) {
		$stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
	}
	$stmt->execute();
}

function retention_table_counts(PDO $db, string $sql): array {
	$counts = [];
	foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$counts[(string)$row['reason']] = (int)$row['count'];
	}
	return $counts;
}

$latestAiSql = 'SELECT link, priority, ROW_NUMBER() OVER (PARTITION BY link ORDER BY updated_at DESC, entry_id DESC) AS rn FROM rss_leads_ai';
$sourceFeedSql = '(f.name LIKE "Reddit Leads - %" OR f.name = "Recovered Reddit Leads - AI classified history" OR e.link LIKE "%reddit.com/r/%") AND f.name != "High Priority Reddit Leads"';

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->beginTransaction();
try {
	$db->exec('DROP TABLE IF EXISTS temp.rss_leads_retention_delete_ids');
	$db->exec('CREATE TEMP TABLE rss_leads_retention_delete_ids (id INTEGER PRIMARY KEY, reason TEXT NOT NULL)');

	retention_prepare(
		$db,
		'WITH latest_ai AS (' . $latestAiSql . ')
		INSERT OR IGNORE INTO rss_leads_retention_delete_ids (id, reason)
		SELECT e.id, "high_priority_older_than_keep"
		FROM entry e
		JOIN feed f ON f.id = e.id_feed
		JOIN latest_ai ai ON ai.link = e.link AND ai.rn = 1
		WHERE ' . $sourceFeedSql . '
		  AND ai.priority IN ("high", "x_high")
		  AND e.date < :cutoff',
		[':cutoff' => $highPriorityCutoff]
	);

	retention_prepare(
		$db,
		'INSERT OR IGNORE INTO rss_leads_retention_delete_ids (id, reason)
		SELECT e.id, "high_priority_feed_older_than_keep"
		FROM entry e
		JOIN feed f ON f.id = e.id_feed
		WHERE f.name = "High Priority Reddit Leads"
		  AND e.date < :cutoff',
		[':cutoff' => $highPriorityCutoff]
	);

	if ($deleteNotHiringEod) {
		retention_prepare(
			$db,
			'WITH latest_ai AS (' . $latestAiSql . ')
			INSERT OR IGNORE INTO rss_leads_retention_delete_ids (id, reason)
			SELECT e.id, "not_hiring_before_today"
			FROM entry e
			JOIN feed f ON f.id = e.id_feed
			JOIN latest_ai ai ON ai.link = e.link AND ai.rn = 1
			WHERE ' . $sourceFeedSql . '
			  AND ai.priority = "not_hiring"
			  AND e.date < :today_start',
			[':today_start' => $todayStart]
		);
	}

	if ($lowMediumMaxEntries > 0) {
		retention_prepare(
			$db,
			'WITH latest_ai AS (' . $latestAiSql . '),
			ranked AS (
				SELECT e.id,
					ROW_NUMBER() OVER (ORDER BY e.date DESC, e.id DESC) AS rn
				FROM entry e
				JOIN feed f ON f.id = e.id_feed
				JOIN latest_ai ai ON ai.link = e.link AND ai.rn = 1
				WHERE ' . $sourceFeedSql . '
				  AND ai.priority IN ("low", "medium")
			)
			INSERT OR IGNORE INTO rss_leads_retention_delete_ids (id, reason)
			SELECT id, "low_medium_over_cap"
			FROM ranked
			WHERE rn > :max_entries',
			[':max_entries' => $lowMediumMaxEntries]
		);
	}

	$plannedCounts = retention_table_counts($db, 'SELECT reason, COUNT(*) AS count FROM rss_leads_retention_delete_ids GROUP BY reason ORDER BY reason');
	$plannedTotal = (int)$db->query('SELECT COUNT(*) FROM rss_leads_retention_delete_ids')->fetchColumn();

	$result = [
		'dry_run' => $dryRun,
		'timezone' => date_default_timezone_get(),
		'config' => [
			'high_priority_keep_days' => $highPriorityKeepDays,
			'high_priority_cutoff' => date(DATE_ATOM, $highPriorityCutoff),
			'low_medium_max_entries' => $lowMediumMaxEntries,
			'delete_not_hiring_eod' => $deleteNotHiringEod,
			'not_hiring_cutoff' => date(DATE_ATOM, $todayStart),
		],
		'planned_delete_total' => $plannedTotal,
		'planned_delete_counts' => $plannedCounts,
		'deleted_entries' => 0,
		'deleted_entry_tags' => 0,
		'deleted_ai_rows_for_entries' => 0,
		'deleted_orphan_ai_rows' => 0,
		'high_priority_sync' => null,
	];

	if ($dryRun || $plannedTotal === 0) {
		$db->rollBack();
		echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		exit(0);
	}

	$result['deleted_entry_tags'] = $db->exec('DELETE FROM entrytag WHERE id_entry IN (SELECT id FROM rss_leads_retention_delete_ids)') ?: 0;
	$result['deleted_ai_rows_for_entries'] = $db->exec('DELETE FROM rss_leads_ai WHERE entry_id IN (SELECT id FROM rss_leads_retention_delete_ids)') ?: 0;
	$result['deleted_entries'] = $db->exec('DELETE FROM entry WHERE id IN (SELECT id FROM rss_leads_retention_delete_ids)') ?: 0;
	$result['deleted_orphan_ai_rows'] = $db->exec('DELETE FROM rss_leads_ai WHERE NOT EXISTS (SELECT 1 FROM entry e WHERE e.id = rss_leads_ai.entry_id)') ?: 0;
	$db->exec(
		'UPDATE feed
		SET cache_nbEntries = (SELECT COUNT(*) FROM entry WHERE id_feed = feed.id),
			cache_nbUnreads = (SELECT COUNT(*) FROM entry WHERE id_feed = feed.id AND is_read = 0)'
	);
	$db->commit();

	if (function_exists('rss_leads_sync_high_priority_feed')) {
		$result['high_priority_sync'] = rss_leads_sync_high_priority_feed($db);
	}

	echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
	if ($db->inTransaction()) {
		$db->rollBack();
	}
	throw $e;
}
