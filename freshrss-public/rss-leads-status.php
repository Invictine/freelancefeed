<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
if (preg_match('/^[A-Za-z0-9_.-]+$/', $user) !== 1) {
	http_response_code(500);
	exit;
}

$dbPath = "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$logPath = "/var/www/FreshRSS/data/users/{$user}/log.txt";
$feedNames = [
	'Reddit Leads - qualified deep-research communities',
	'Reddit Leads - unqualified deep-research communities',
];
$now = time();

$response = [
	'ok' => false,
	'now' => $now,
	'feed_id' => null,
	'feed_ids' => [],
	'feed_name' => $feedNames[0],
	'feeds' => [],
	'last_update' => 0,
	'last_update_iso' => null,
	'ttl' => null,
	'error' => null,
	'entries' => null,
	'unread' => null,
	'latest_429_ts' => null,
	'latest_429_iso' => null,
	'recent_429' => false,
	'recent_429_window_seconds' => 300,
];

try {
	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$placeholders = implode(',', array_fill(0, count($feedNames), '?'));
	$feed = $db->prepare("SELECT id, name, error, ttl, lastUpdate FROM feed WHERE name IN ($placeholders) ORDER BY priority, id");
	$feed->execute($feedNames);
	$rows = $feed->fetchAll(PDO::FETCH_ASSOC);
	if (!empty($rows)) {
		$lastUpdate = 0;
		$response['ok'] = true;
		$response['feed_id'] = (int)$rows[0]['id'];
		$response['error'] = 0;
		$response['ttl'] = (int)$rows[0]['ttl'];
		foreach ($rows as $row) {
			$feedId = (int)$row['id'];
			$rowLastUpdate = (int)$row['lastUpdate'];
			$lastUpdate = max($lastUpdate, $rowLastUpdate);
			$response['error'] = max((int)$response['error'], (int)$row['error']);
			$response['feed_ids'][] = $feedId;
			$response['feeds'][] = [
				'id' => $feedId,
				'name' => (string)$row['name'],
				'error' => (int)$row['error'],
				'ttl' => (int)$row['ttl'],
				'last_update' => $rowLastUpdate,
				'last_update_iso' => $rowLastUpdate > 0 ? date(DATE_ATOM, $rowLastUpdate) : null,
			];
		}
		$response['last_update'] = $lastUpdate;
		$response['last_update_iso'] = $lastUpdate > 0 ? date(DATE_ATOM, $lastUpdate) : null;

		$countPlaceholders = implode(',', array_fill(0, count($response['feed_ids']), '?'));
		$counts = $db->prepare("SELECT COUNT(*) AS entries, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread FROM entry WHERE id_feed IN ($countPlaceholders)");
		$counts->execute($response['feed_ids']);
		$countRow = $counts->fetch(PDO::FETCH_ASSOC);
		$response['entries'] = (int)($countRow['entries'] ?? 0);
		$response['unread'] = (int)($countRow['unread'] ?? 0);
	}

	if (is_readable($logPath)) {
		$log = file_get_contents($logPath);
		if (is_string($log) && preg_match_all('/\[(?<date>[^\]]+)\] \[warning\] --- HTTP 429 Too Many Requests! \[(?<url>[^\]]*reddit[^\]]*)\]/', $log, $matches)) {
			$latest = null;
			foreach ($matches['date'] as $date) {
				$ts = strtotime($date);
				if ($ts !== false && ($latest === null || $ts > $latest)) {
					$latest = $ts;
				}
			}
			if ($latest !== null) {
				$response['latest_429_ts'] = $latest;
				$response['latest_429_iso'] = date(DATE_ATOM, $latest);
				$response['recent_429'] = ($now - $latest) <= $response['recent_429_window_seconds'];
			}
		}
	}
} catch (Throwable $e) {
	http_response_code(500);
	$response['error_message'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
