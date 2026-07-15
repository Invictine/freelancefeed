<?php
declare(strict_types=1);

$freshRssUser = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
if (preg_match('/^[A-Za-z0-9_.-]+$/', $freshRssUser) !== 1) {
	throw new InvalidArgumentException('FreshRSS username contains unsupported characters.');
}

$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$freshRssUser}/db.sqlite";
$categoryName = 'Reddit Leads';
$feedName = 'Recovered Reddit Leads - AI classified history';
$feedUrl = 'rss-leads-recovered://ai-classified-history';
$now = time();

function recovered_html(string $value): string {
	return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function recovered_slug_label(string $value): string {
	$value = trim($value);
	if ($value === '') {
		return '';
	}
	$value = mb_strtolower($value, 'UTF-8');
	$value = str_replace('&', 'and', $value);
	$value = preg_replace('/\s+/u', '_', $value) ?? $value;
	$value = preg_replace('/[^a-z0-9+#$.,:\/_-]+/u', '', $value) ?? $value;
	return trim($value, '_');
}

function recovered_title_from_link(string $link, string $summary): string {
	$path = (string)(parse_url($link, PHP_URL_PATH) ?: '');
	if (preg_match('~/comments/[^/]+/([^/]+)/?~', $path, $match) === 1) {
		$title = rawurldecode($match[1]);
		$title = str_replace(['_', '-'], ' ', $title);
		$title = preg_replace('/\s+/u', ' ', $title) ?? $title;
		$title = trim($title);
		if ($title !== '') {
			return mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');
		}
	}
	$summary = trim($summary);
	return $summary === '' ? 'Recovered Reddit lead' : mb_substr($summary, 0, 180, 'UTF-8');
}

function recovered_author_from_link(string $link): string {
	$path = (string)(parse_url($link, PHP_URL_PATH) ?: '');
	if (preg_match('~/r/([^/]+)/~', $path, $match) === 1) {
		return 'r/' . rawurldecode($match[1]);
	}
	return 'Reddit';
}

function recovered_content(array $row): string {
	$priority = (string)$row['priority'];
	$monthlyAmount = trim((string)$row['monthly_amount']);
	$jobType = trim((string)$row['job_type']);
	$scamLikelihood = max(0, min(100, (int)$row['scam_likelihood']));
	$updatedAt = (int)$row['updated_at'];
	$parts = [
		'<p><strong>Recovered AI history.</strong> The original FreshRSS article body was no longer present, so this entry was rebuilt from the saved AI classification cache.</p>',
		'<p><strong>AI summary:</strong> ' . recovered_html((string)$row['summary']) . '</p>',
		'<ul>',
		'<li><strong>Priority:</strong> ' . recovered_html($priority) . '</li>',
		'<li><strong>Job type:</strong> ' . recovered_html($jobType === '' ? 'unknown' : $jobType) . '</li>',
		'<li><strong>Monthly amount:</strong> ' . recovered_html($monthlyAmount === '' ? 'unknown' : $monthlyAmount) . '</li>',
		'<li><strong>Scam likelihood:</strong> ' . $scamLikelihood . '%</li>',
		'<li><strong>Classified at:</strong> ' . recovered_html(date(DATE_ATOM, $updatedAt)) . '</li>',
		'</ul>',
		'<p><a href="' . recovered_html((string)$row['link']) . '">Open Reddit post</a></p>',
	];
	return implode("\n", $parts);
}

function recovered_tags(array $row): string {
	$tags = ['rss-recovered', 'ai:' . recovered_slug_label((string)$row['priority'])];
	$job = recovered_slug_label((string)$row['job_type']);
	if ($job !== '') {
		$tags[] = 'job:' . $job;
	}
	$monthly = recovered_slug_label((string)$row['monthly_amount']);
	if ($monthly !== '') {
		$tags[] = 'monthly:' . $monthly;
	}
	return implode(' ', array_values(array_unique($tags)));
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$exists = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'rss_leads_ai'")->fetchColumn();
if ((int)$exists !== 1) {
	echo "No rss_leads_ai table found; nothing to recover.\n";
	exit;
}

$db->beginTransaction();

$selectCategory = $db->prepare('SELECT id FROM category WHERE name = :name');
$selectCategory->execute([':name' => $categoryName]);
$categoryId = $selectCategory->fetchColumn();
if ($categoryId === false) {
	$insertCategory = $db->prepare('INSERT INTO category (name, kind, attributes) VALUES (:name, 0, :attributes)');
	$insertCategory->execute([':name' => $categoryName, ':attributes' => '[]']);
	$categoryId = (int)$db->lastInsertId();
}
$categoryId = (int)$categoryId;

$feedAttributes = json_encode([
	'lead_bucket' => 'recovered_ai_history',
	'archiving' => [
		'keep_period' => false,
		'keep_max' => false,
		'keep_min' => false,
		'keep_favourites' => true,
		'keep_labels' => true,
		'keep_unreads' => true,
	],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$selectFeed = $db->prepare('SELECT id FROM feed WHERE name = :name');
$selectFeed->execute([':name' => $feedName]);
$feedId = $selectFeed->fetchColumn();
if ($feedId === false) {
	$insertFeed = $db->prepare('INSERT INTO feed (url, kind, category, name, website, description, lastUpdate, priority, pathEntries, httpAuth, error, ttl, attributes) VALUES (:url, 0, :category, :name, :website, :description, :last_update, 90, "", "", 0, -31536000, :attributes)');
	$insertFeed->execute([
		':url' => $feedUrl,
		':category' => $categoryId,
		':name' => $feedName,
		':website' => 'https://www.reddit.com/',
		':description' => 'Recovered AI-classified Reddit history from rss_leads_ai rows whose original FreshRSS entries were no longer present.',
		':last_update' => $now,
		':attributes' => $feedAttributes,
	]);
	$feedId = (int)$db->lastInsertId();
} else {
	$feedId = (int)$feedId;
	$updateFeed = $db->prepare('UPDATE feed SET url = :url, category = :category, website = :website, description = :description, lastUpdate = :last_update, priority = 90, ttl = -31536000, error = 0, attributes = :attributes WHERE id = :id');
	$updateFeed->execute([
		':url' => $feedUrl,
		':category' => $categoryId,
		':website' => 'https://www.reddit.com/',
		':description' => 'Recovered AI-classified Reddit history from rss_leads_ai rows whose original FreshRSS entries were no longer present.',
		':last_update' => $now,
		':attributes' => $feedAttributes,
		':id' => $feedId,
	]);
}

$rows = $db->query(
	'WITH ranked AS (
		SELECT ai.*,
			ROW_NUMBER() OVER (PARTITION BY ai.link ORDER BY ai.updated_at DESC, ai.entry_id DESC) AS rn
		FROM rss_leads_ai ai
		LEFT JOIN entry original ON original.id = ai.entry_id
		WHERE original.id IS NULL
		  AND NOT EXISTS (SELECT 1 FROM entry visible WHERE visible.link = ai.link)
	)
	SELECT entry_id, link, summary, priority, monthly_amount, job_type, scam_likelihood, model, input_hash, created_at, updated_at
	FROM ranked
	WHERE rn = 1
	ORDER BY updated_at ASC, entry_id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$insertEntry = $db->prepare(
	'INSERT INTO entry (id, guid, title, author, content, link, date, lastSeen, lastModified, lastUserModified, hash, is_read, is_favorite, id_feed, tags, attributes)
	VALUES (:id, :guid, :title, :author, :content, :link, :date, :last_seen, :last_modified, 0, :hash, 1, 0, :id_feed, :tags, :attributes)'
);

$inserted = 0;
foreach ($rows as $row) {
	$link = (string)$row['link'];
	if ($link === '') {
		continue;
	}
	$entryId = (int)$row['entry_id'];
	$timestamp = (int)($row['updated_at'] ?: ($row['created_at'] ?: $now));
	$guid = 'rss-leads-recovered:' . sha1($link);
	$attributes = json_encode([
		'recovered_from' => 'rss_leads_ai',
		'original_entry_id' => (string)$entryId,
		'ai_model' => (string)$row['model'],
		'enclosures' => [],
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$hash = md5($guid . '|' . (string)$row['summary'] . '|' . $timestamp, true);
	$insertEntry->bindValue(':id', $entryId, PDO::PARAM_INT);
	$insertEntry->bindValue(':guid', $guid, PDO::PARAM_STR);
	$insertEntry->bindValue(':title', recovered_title_from_link($link, (string)$row['summary']), PDO::PARAM_STR);
	$insertEntry->bindValue(':author', recovered_author_from_link($link), PDO::PARAM_STR);
	$insertEntry->bindValue(':content', recovered_content($row), PDO::PARAM_STR);
	$insertEntry->bindValue(':link', $link, PDO::PARAM_STR);
	$insertEntry->bindValue(':date', $timestamp, PDO::PARAM_INT);
	$insertEntry->bindValue(':last_seen', $timestamp, PDO::PARAM_INT);
	$insertEntry->bindValue(':last_modified', $timestamp, PDO::PARAM_INT);
	$insertEntry->bindValue(':hash', $hash, PDO::PARAM_LOB);
	$insertEntry->bindValue(':id_feed', $feedId, PDO::PARAM_INT);
	$insertEntry->bindValue(':tags', recovered_tags($row), PDO::PARAM_STR);
	$insertEntry->bindValue(':attributes', $attributes, PDO::PARAM_STR);
	$insertEntry->execute();
	$inserted++;
}

$refreshCache = $db->prepare(
	'UPDATE feed
	SET cache_nbEntries = (SELECT COUNT(*) FROM entry WHERE id_feed = :feed_entries),
		cache_nbUnreads = (SELECT COUNT(*) FROM entry WHERE id_feed = :feed_unreads AND is_read = 0)
	WHERE id = :feed_id'
);
$refreshCache->execute([
	':feed_entries' => $feedId,
	':feed_unreads' => $feedId,
	':feed_id' => $feedId,
]);

$db->commit();

echo "Recovered {$inserted} AI-classified Reddit history entries into {$feedName}.\n";
