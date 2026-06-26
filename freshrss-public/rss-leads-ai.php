<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = getenv('FRESHRSS_USER') ?: 'invictine';
$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
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
	'error' => null,
];

try {
	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$exists = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'rss_leads_ai'")->fetchColumn();
	if ($exists === false) {
		$response['ok'] = true;
		echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	if (!empty($ids)) {
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$stmt = $db->prepare("SELECT entry_id, summary, priority, model, updated_at FROM rss_leads_ai WHERE entry_id IN ($placeholders)");
		$stmt->execute($ids);
	} else {
		$limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
		$stmt = $db->prepare('SELECT entry_id, summary, priority, model, updated_at FROM rss_leads_ai ORDER BY updated_at DESC LIMIT ?');
		$stmt->execute([$limit]);
	}

	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$entryId = (string)$row['entry_id'];
		$response['items'][$entryId] = [
			'summary' => (string)$row['summary'],
			'priority' => (string)$row['priority'],
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
