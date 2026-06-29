<?php
declare(strict_types=1);

$freshRssUser = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
if (preg_match('/^[A-Za-z0-9_.-]+$/', $freshRssUser) !== 1) {
	throw new InvalidArgumentException('FreshRSS username contains unsupported characters.');
}

$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$freshRssUser}/db.sqlite";
$configPath = "/var/www/FreshRSS/data/users/{$freshRssUser}/config.php";
$systemConfigPath = '/var/www/FreshRSS/data/config.php';

$redditUserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0';
$subreddits = [
	'forhire',
	'FindVideoEditors',
	'VideoEditingJobs',
	'videography',
	'socialmedia',
	'SocialMediaMarketing',
	'n8n',
	'jobbit',
	'VideoEditors',
	'creators',
	'HireaWriter',
	'podcasting',
	'freelance_forhire',
	'slavelabour',
	'DoneDirtCheap',
	'B2BForHire',
	'SocialMediaManagers',
	'PromptEngineering',
	'Automate',
];

$qualifiedCategoryName = 'Reddit Leads';
$unqualifiedCategoryName = 'Unqualified Reddit Leads';
$qualifiedFeedName = 'Reddit Leads - qualified deep-research communities';
$unqualifiedFeedName = 'Reddit Leads - unqualified deep-research communities';
$feedPath = implode('+', $subreddits);
$feedUrl = 'https://www.reddit.com/r/' . $feedPath . '/new/.rss';
$websiteUrl = 'https://www.reddit.com/r/' . $feedPath . '/new/';

$unqualifiedFilters = [
	'intitle:/\[for hire\]/i OR intitle:/\[hire me\]/i OR intitle:/^hire (a|an) /i OR intitle:"for hire only" OR intitle:"hire me" OR intitle:portfolio',
	'intitle:/\[offer\]/i OR intitle:"self promotion" OR intitle:advertisement',
	'intitle:unpaid OR intitle:volunteer OR intitle:exposure OR intitle:free',
	'intitle:"how do I sell" OR intitle:"looking for clients" OR intitle:"lead generation" OR intitle:"appointment setter"',
	'!intitle:/\[hiring\]/i !intitle:/\[paid\]/i !intitle:/\[task\]/i !intitle:hiring !intitle:paid !intitle:hire !intitle:needed !intitle:looking !intitle:developer !intitle:automation !intitle:editor !intitle:manager !intitle:"social media" !intitle:"podcast editor" !intitle:"will pay" !intitle:prompt !intitle:cofounder !intitle:help',
];

$qualifiedFilters = [
	'intitle:/\[hiring\]/i OR intitle:/\[paid\]/i OR intitle:/\[task\]/i OR intitle:hiring OR intitle:paid OR intitle:needed OR intitle:looking OR intitle:developer OR intitle:automation OR intitle:editor OR intitle:manager OR intitle:"social media" OR intitle:"podcast editor" OR intitle:"will pay" OR intitle:prompt OR intitle:cofounder OR intitle:help',
];

$baseAttributes = [
	'curl_params' => [
		CURLOPT_USERAGENT => $redditUserAgent,
		CURLOPT_HTTPHEADER => [
			'Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8',
			'Accept-Language: en-US,en;q=0.9',
		],
	],
	'reddit_subreddits' => $subreddits,
];

$feeds = [
	[
		'name' => $qualifiedFeedName,
		'category' => $qualifiedCategoryName,
		'priority' => 10,
		'description' => 'Qualified Reddit lead feed covering all 19 subreddits from /opt/rss-leads-stack/deep-research-report.md. FreshRSS filters mark unqualified posts as read.',
		'filters' => $unqualifiedFilters,
	],
	[
		'name' => $unqualifiedFeedName,
		'category' => $unqualifiedCategoryName,
		'priority' => 20,
		'description' => 'Unqualified Reddit lead review feed covering all 19 subreddits from /opt/rss-leads-stack/deep-research-report.md. FreshRSS filters mark likely-qualified posts as read.',
		'filters' => $qualifiedFilters,
	],
];

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->beginTransaction();

$selectCategory = $db->prepare('SELECT id FROM category WHERE name = :name');
$insertCategory = $db->prepare('INSERT INTO category (name, kind, attributes) VALUES (:name, 0, :attributes)');
$categoryIds = [];
foreach (array_unique(array_column($feeds, 'category')) as $categoryName) {
	$selectCategory->execute([':name' => $categoryName]);
	$categoryId = $selectCategory->fetchColumn();
	if ($categoryId === false) {
		$insertCategory->execute([':name' => $categoryName, ':attributes' => '[]']);
		$categoryId = (int)$db->lastInsertId();
	}
	$categoryIds[$categoryName] = (int)$categoryId;
}

$selectFeed = $db->prepare('SELECT id FROM feed WHERE name = :name');
$insertFeed = $db->prepare('INSERT INTO feed (url, kind, category, name, website, description, lastUpdate, priority, pathEntries, httpAuth, error, ttl, attributes) VALUES (:url, 0, :category, :name, :website, :description, 0, :priority, "", "", 0, 60, :attributes)');
$updateFeed = $db->prepare('UPDATE feed SET url = :url, kind = 0, category = :category, website = :website, description = :description, priority = :priority, ttl = 60, error = 0, attributes = :attributes WHERE id = :id');

$feedNames = [];
foreach ($feeds as $feed) {
	$attributes = $baseAttributes;
	$attributes['filters'] = array_map(static fn(string $filter): array => [
		'search' => $filter,
		'actions' => ['read'],
	], $feed['filters']);
	$attributes['lead_bucket'] = $feed['category'] === $unqualifiedCategoryName ? 'unqualified' : 'qualified';
	$attributesJson = json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$params = [
		':url' => $feedUrl,
		':category' => $categoryIds[$feed['category']],
		':name' => $feed['name'],
		':website' => $websiteUrl,
		':description' => $feed['description'],
		':priority' => $feed['priority'],
		':attributes' => $attributesJson,
	];
	$feedNames[] = $feed['name'];

	$selectFeed->execute([':name' => $feed['name']]);
	$existingId = $selectFeed->fetchColumn();
	if ($existingId === false) {
		$insertFeed->execute($params);
	} else {
		$updateFeed->execute([
			':url' => $params[':url'],
			':category' => $params[':category'],
			':website' => $params[':website'],
			':description' => $params[':description'],
			':priority' => $params[':priority'],
			':attributes' => $params[':attributes'],
			':id' => (int)$existingId,
		]);
	}
}

$placeholders = implode(',', array_fill(0, count($feedNames), '?'));
$selectOld = $db->prepare("SELECT id FROM feed WHERE name LIKE 'Reddit Leads - %' AND name NOT IN ($placeholders)");
$selectOld->execute($feedNames);
$oldFeedIds = array_map('intval', $selectOld->fetchAll(PDO::FETCH_COLUMN));
if (!empty($oldFeedIds)) {
	$oldPlaceholders = implode(',', array_fill(0, count($oldFeedIds), '?'));
	$deleteOldEntries = $db->prepare("DELETE FROM entry WHERE id_feed IN ($oldPlaceholders)");
	$deleteOldEntries->execute($oldFeedIds);
}
$deleteOld = $db->prepare("DELETE FROM feed WHERE name LIKE 'Reddit Leads - %' AND name NOT IN ($placeholders)");
$deleteOld->execute($feedNames);

$config = require $configPath;
$config['timezone'] = 'Asia/Kolkata';
$config['ttl_default'] = 60;
$config['sort'] = 'id';
$config['sort_order'] = 'DESC';
file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

$systemConfig = require $systemConfigPath;
$systemConfig['limits']['cache_duration'] = 60;
$systemConfig['limits']['cache_duration_min'] = 60;
$systemConfig['limits']['cache_duration_max'] = 60;
file_put_contents($systemConfigPath, "<?php\nreturn " . var_export($systemConfig, true) . ";\n");

$db->commit();

echo 'Applied qualified and unqualified Reddit feeds covering ' . count($subreddits) . " deep-research subreddits.\n";
