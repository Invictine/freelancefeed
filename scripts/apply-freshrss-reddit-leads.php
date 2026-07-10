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
$commentThreadConfigPath = getenv('RSS_LEADS_REDDIT_COMMENT_THREADS_CONFIG') ?: '/opt/rss-leads-stack/feeds/reddit-comment-threads.json';
$redditFeedTtlSeconds = max(60, min(21600, (int)(getenv('RSS_LEADS_REDDIT_TTL_SECONDS') ?: 60)));
$commentFeedTtlSeconds = max(60, min(21600, (int)(getenv('RSS_LEADS_REDDIT_COMMENT_TTL_SECONDS') ?: 60)));
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

$redditCategoryName = 'Reddit Leads';
$allFeedName = 'Reddit Leads - all communities';
$legacyQualifiedFeedName = 'Reddit Leads - qualified deep-research communities';
$legacyUnqualifiedFeedName = 'Reddit Leads - unqualified deep-research communities';
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

function default_reddit_comment_thread_config(): array {
	return [
		'defaults' => [
			'category' => 'Reddit Leads',
			'time' => 'month',
			'sort' => 'new',
			'search_limit' => 25,
			'max_threads' => 2,
			'max_comments' => 80,
			'min_comment_score' => 0,
			'include_replies' => false,
			'cache_seconds' => 60,
		],
		'sources' => [
			[
				'id' => 'socialmedia-weekly-hiring',
				'enabled' => true,
				'subreddit' => 'socialmedia',
				'label' => 'weekly hiring thread',
				'q' => '"Weekly Hiring Thread" OR "Social Media Professionals"',
				'title_patterns' => ['/Weekly Hiring Thread/i', '/Social Media Professionals/i'],
			],
			[
				'id' => 'socialmediamarketing-monthly-hiring',
				'enabled' => true,
				'subreddit' => 'SocialMediaMarketing',
				'label' => 'monthly hiring thread',
				'q' => '"Monthly Hiring Thread" OR "Hiring Thread for Social Media Marketers"',
				'time' => 'year',
				'title_patterns' => ['/Monthly Hiring Thread/i', '/Hiring Thread/i'],
			],
		],
	];
}

function load_reddit_comment_thread_config(string $path): array {
	$config = default_reddit_comment_thread_config();
	if (is_readable($path)) {
		$json = file_get_contents($path);
		$decoded = is_string($json) ? json_decode($json, true) : null;
		if (is_array($decoded)) {
			foreach ($decoded as $key => $value) {
				if ($key === 'defaults' && is_array($value)) {
					$config['defaults'] = array_replace($config['defaults'], $value);
				} else {
					$config[$key] = $value;
				}
			}
		}
	}
	return $config;
}

function reddit_comment_thread_sources(string $path): array {
	$config = load_reddit_comment_thread_config($path);
	$defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
	$sources = [];
	foreach (($config['sources'] ?? []) as $source) {
		if (!is_array($source) || !($source['enabled'] ?? true)) {
			continue;
		}
		$merged = array_replace($defaults, $source);
		if (
			preg_match('/^[A-Za-z0-9_-]+$/', (string)($merged['id'] ?? '')) !== 1
			|| preg_match('/^[A-Za-z0-9_]{2,21}$/', (string)($merged['subreddit'] ?? '')) !== 1
		) {
			continue;
		}
		$sources[] = $merged;
	}
	return $sources;
}

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
		'name' => $allFeedName,
		'category' => $redditCategoryName,
		'lead_bucket' => 'all',
		'priority' => 10,
		'ttl' => $redditFeedTtlSeconds,
		'description' => 'All Reddit source posts covering the deep-research communities. AI routing keeps low through x-high leads visible in Reddit Leads and hides not_hiring noise.',
		'filters' => [],
	],
];

$commentThreadSources = reddit_comment_thread_sources($commentThreadConfigPath);
foreach ($commentThreadSources as $source) {
	$subreddit = (string)$source['subreddit'];
	$label = trim((string)($source['label'] ?? $source['id']));
	$sourceId = (string)$source['id'];
	$feeds[] = [
		'name' => 'Reddit Leads - comments - r/' . $subreddit . ' - ' . $label,
		'category' => (string)($source['category'] ?? $redditCategoryName),
		'lead_bucket' => 'comment_thread',
		'priority' => 30,
		'ttl' => max($commentFeedTtlSeconds, (int)($source['cache_seconds'] ?? $commentFeedTtlSeconds)),
		'description' => 'Comments from recurring Reddit hiring thread source "' . $sourceId . '". FreshRSS filters mark obvious seller/noise comments as read.',
		'filters' => $unqualifiedFilters,
		'url' => 'http://127.0.0.1/rss-leads-reddit-comments.php?source=' . rawurlencode($sourceId),
		'website' => 'https://www.reddit.com/r/' . $subreddit . '/',
		'reddit_comment_source' => [
			'id' => $sourceId,
			'subreddit' => $subreddit,
			'label' => $label,
			'q' => (string)($source['q'] ?? ''),
		],
	];
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->beginTransaction();
$appliedAt = time();

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
$selectAllFeed = $db->prepare('SELECT id FROM feed WHERE name = :name');
$selectAllFeed->execute([':name' => $allFeedName]);
if ($selectAllFeed->fetchColumn() === false) {
	$renameLegacyQualified = $db->prepare('UPDATE feed SET name = :new_name, description = :description WHERE name = :old_name');
	$renameLegacyQualified->execute([
		':new_name' => $allFeedName,
		':description' => 'All Reddit source posts covering the deep-research communities. AI routing keeps low through x-high leads visible in Reddit Leads and hides not_hiring noise.',
		':old_name' => $legacyQualifiedFeedName,
	]);
}

$selectFeed = $db->prepare('SELECT id FROM feed WHERE name = :name');
$insertFeed = $db->prepare('INSERT INTO feed (url, kind, category, name, website, description, lastUpdate, priority, pathEntries, httpAuth, error, ttl, attributes) VALUES (:url, 0, :category, :name, :website, :description, 0, :priority, "", "", 0, :ttl, :attributes)');
$updateFeed = $db->prepare('UPDATE feed SET url = :url, kind = 0, category = :category, website = :website, description = :description, priority = :priority, ttl = :ttl, error = 0, lastUpdate = MAX(lastUpdate, :last_update), attributes = :attributes WHERE id = :id');

$feedNames = [];
foreach ($feeds as $feed) {
	$attributes = $baseAttributes;
	if (isset($feed['reddit_comment_source'])) {
		$attributes['reddit_comment_source'] = $feed['reddit_comment_source'];
	}
	$attributes['filters'] = array_map(static fn(string $filter): array => [
		'search' => $filter,
		'actions' => ['read'],
	], $feed['filters'] ?? []);
	$attributes['lead_bucket'] = $feed['lead_bucket'];
	$attributesJson = json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$params = [
		':url' => (string)($feed['url'] ?? $feedUrl),
		':category' => $categoryIds[$feed['category']],
		':name' => $feed['name'],
		':website' => (string)($feed['website'] ?? $websiteUrl),
		':description' => $feed['description'],
		':priority' => $feed['priority'],
		':ttl' => max(60, min(21600, (int)($feed['ttl'] ?? $redditFeedTtlSeconds))),
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
			':ttl' => $params[':ttl'],
			':last_update' => $appliedAt,
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
	$selectAllFeed->execute([':name' => $allFeedName]);
	$allFeedId = (int)$selectAllFeed->fetchColumn();
	$oldPlaceholders = implode(',', array_fill(0, count($oldFeedIds), '?'));
	$deleteDuplicateOldEntries = $db->prepare(
		"DELETE FROM entry
		WHERE id_feed IN ($oldPlaceholders)
		  AND link IN (SELECT link FROM entry WHERE id_feed = ?)"
	);
	$deleteDuplicateOldEntries->execute(array_merge($oldFeedIds, [$allFeedId]));
	$moveOldEntries = $db->prepare("UPDATE entry SET id_feed = ?, lastUserModified = ? WHERE id_feed IN ($oldPlaceholders)");
	$moveOldEntries->execute(array_merge([$allFeedId, $appliedAt], $oldFeedIds));
	$deleteOldFeeds = $db->prepare("DELETE FROM feed WHERE id IN ($oldPlaceholders)");
	$deleteOldFeeds->execute($oldFeedIds);
}

$deleteEmptyLegacyCategory = $db->prepare(
	'DELETE FROM category
	WHERE name = :name
	  AND id NOT IN (SELECT DISTINCT category FROM feed)'
);
foreach (['Unqualified Reddit Leads', 'Archived Reddit Sources'] as $legacyCategoryName) {
	$deleteEmptyLegacyCategory->execute([':name' => $legacyCategoryName]);
}

$deleteRecoveredEntries = $db->prepare('DELETE FROM entry WHERE id_feed IN (SELECT id FROM feed WHERE name = :name)');
$deleteRecoveredEntries->execute([':name' => 'Recovered Reddit Leads - AI classified history']);
$deleteRecoveredFeed = $db->prepare('DELETE FROM feed WHERE name = :name');
$deleteRecoveredFeed->execute([':name' => 'Recovered Reddit Leads - AI classified history']);

$config = require $configPath;
$config['timezone'] = 'Asia/Kolkata';
$config['ttl_default'] = $redditFeedTtlSeconds;
$config['sort'] = 'id';
$config['sort_order'] = 'DESC';
$config['default_view'] = 'all';
$config['default_state'] = 3;
$config['display_categories'] = 'all';
$config['hide_read_feeds'] = false;
$config['since_hours_posts_per_rss'] = 0;
$config['max_posts_per_rss'] = 5000;
$config['archiving'] = [
	'keep_period' => false,
	'keep_max' => false,
	'keep_min' => false,
	'keep_favourites' => true,
	'keep_labels' => true,
	'keep_unreads' => true,
];
file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

$systemConfig = require $systemConfigPath;
$systemConfig['limits']['cache_duration'] = $redditFeedTtlSeconds;
$systemConfig['limits']['cache_duration_min'] = min(60, $redditFeedTtlSeconds);
$systemConfig['limits']['cache_duration_max'] = max(21600, $redditFeedTtlSeconds);
file_put_contents($systemConfigPath, "<?php\nreturn " . var_export($systemConfig, true) . ";\n");

$db->commit();

echo 'Applied all-priority Reddit feed covering ' . count($subreddits) . ' deep-research subreddits';
if (!empty($commentThreadSources)) {
	echo ' plus ' . count($commentThreadSources) . ' recurring comment-thread sources';
}
echo ".\n";
