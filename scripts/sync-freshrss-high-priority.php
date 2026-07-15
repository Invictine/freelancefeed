<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/RssLeads/Support.php';

const RSS_LEADS_OLD_HIGH_PRIORITY_CATEGORY = 'High Priority';
const RSS_LEADS_OLD_HIGH_PRIORITY_FEED = 'High Priority Reddit Leads';
const RSS_LEADS_HIGH_PRIORITY_CATEGORY = 'High + X-High Priority';
const RSS_LEADS_HIGH_PRIORITY_FEED = 'High + X-High Reddit Leads';
const RSS_LEADS_HIGH_PRIORITY_FEED_URL = 'http://127.0.0.1/rss-leads-high-priority.php';
const RSS_LEADS_LOW_PRIORITY_CATEGORY = 'Low Priority';
const RSS_LEADS_LOW_PRIORITY_FEED = 'Low Priority Reddit Leads';
const RSS_LEADS_LOW_PRIORITY_FEED_URL = 'http://127.0.0.1/rss-leads-high-priority.php?bucket=low';
const RSS_LEADS_MEDIUM_PRIORITY_CATEGORY = 'Medium Priority';
const RSS_LEADS_MEDIUM_PRIORITY_FEED = 'Medium Priority Reddit Leads';
const RSS_LEADS_MEDIUM_PRIORITY_FEED_URL = 'http://127.0.0.1/rss-leads-high-priority.php?bucket=medium';
const RSS_LEADS_NOT_HIRING_CATEGORY = 'Not Hiring';
const RSS_LEADS_NOT_HIRING_FEED = 'Not Hiring Reddit Leads';
const RSS_LEADS_NOT_HIRING_FEED_URL = 'http://127.0.0.1/rss-leads-high-priority.php?bucket=not_hiring';
const RSS_LEADS_ALL_FEED = 'Reddit Leads - all communities';
const RSS_LEADS_QUALIFIED_FEED = 'Reddit Leads - qualified deep-research communities';
const RSS_LEADS_UNQUALIFIED_FEED = 'Reddit Leads - unqualified deep-research communities';
const RSS_LEADS_RECOVERED_FEED = 'Recovered Reddit Leads - AI classified history';
const RSS_LEADS_COMMENT_FEED_LIKE = 'Reddit Leads - comments - %';

function rss_leads_feed_buckets(): array {
	return [
		'low' => [
			'category' => RSS_LEADS_LOW_PRIORITY_CATEGORY,
			'feed' => RSS_LEADS_LOW_PRIORITY_FEED,
			'old_categories' => [],
			'old_feeds' => [],
			'url' => RSS_LEADS_LOW_PRIORITY_FEED_URL,
			'description' => 'AI-classified low-priority Reddit leads from the FreshRSS leads stack.',
			'attributes' => ['lead_bucket' => 'low_priority', 'source' => 'rss_leads_ai', 'priority' => 'low'],
			'priorities' => ['low'],
			'guid_prefix' => 'rss-leads-low:',
			'location_filter' => false,
			'require_known_payment' => false,
		],
		'medium' => [
			'category' => RSS_LEADS_MEDIUM_PRIORITY_CATEGORY,
			'feed' => RSS_LEADS_MEDIUM_PRIORITY_FEED,
			'old_categories' => ['Low-Medium Priority', 'Medium-High Priority'],
			'old_feeds' => ['Low-Medium Reddit Leads', 'Medium-High Reddit Leads'],
			'url' => RSS_LEADS_MEDIUM_PRIORITY_FEED_URL,
			'description' => 'AI-classified medium Reddit leads from the FreshRSS leads stack.',
			'attributes' => ['lead_bucket' => 'medium_priority', 'source' => 'rss_leads_ai', 'priority' => 'medium'],
			'priorities' => ['medium'],
			'guid_prefix' => 'rss-leads-medium:',
			'location_filter' => false,
			'require_known_payment' => false,
		],
		'high' => [
			'category' => RSS_LEADS_HIGH_PRIORITY_CATEGORY,
			'feed' => RSS_LEADS_HIGH_PRIORITY_FEED,
			'old_categories' => [RSS_LEADS_OLD_HIGH_PRIORITY_CATEGORY, 'Top Priority'],
			'old_feeds' => [RSS_LEADS_OLD_HIGH_PRIORITY_FEED, 'High Reddit Leads'],
			'url' => RSS_LEADS_HIGH_PRIORITY_FEED_URL,
			'description' => 'AI-classified high and x-high Reddit leads with known payment from the FreshRSS leads stack.',
			'attributes' => ['lead_bucket' => 'high_priority', 'source' => 'rss_leads_ai', 'priority' => 'high'],
			'priorities' => ['high', 'x_high'],
			'guid_prefix' => 'rss-leads-high:',
			'location_filter' => true,
			'require_known_payment' => true,
		],
		'not_hiring' => [
			'category' => RSS_LEADS_NOT_HIRING_CATEGORY,
			'feed' => RSS_LEADS_NOT_HIRING_FEED,
			'old_categories' => [],
			'old_feeds' => [],
			'url' => RSS_LEADS_NOT_HIRING_FEED_URL,
			'description' => 'AI-classified not-hiring Reddit posts from the FreshRSS leads stack.',
			'attributes' => ['lead_bucket' => 'not_hiring', 'source' => 'rss_leads_ai', 'priority' => 'not_hiring'],
			'priorities' => ['not_hiring'],
			'guid_prefix' => 'rss-leads-not-hiring:',
			'location_filter' => false,
			'require_known_payment' => false,
		],
	];
}

function rss_leads_bucket_guid(array $bucket, string $link): string {
	return (string)$bucket['guid_prefix'] . sha1($link);
}

function rss_leads_high_priority_guid(string $link): string {
	return rss_leads_bucket_guid(rss_leads_feed_buckets()['high'], $link);
}

function rss_leads_compact_html(string $html, int $limit = 6000): string {
	return RssLeadsText::htmlExcerpt($html, $limit);
}

function rss_leads_monthly_amount_label(string $monthlyAmount): string {
	$monthlyAmount = trim($monthlyAmount);
	return $monthlyAmount === '' ? 'unknown' : $monthlyAmount;
}

function rss_leads_parse_money_value(string $amount, string $suffix = ''): float {
	$value = (float)str_replace(',', '', $amount);
	if (in_array(strtolower($suffix), ['k', 'm'], true)) {
		$value *= strtolower($suffix) === 'm' ? 1000000 : 1000;
	}
	return $value;
}

function rss_leads_format_monthly_amount(float $min, ?float $max = null, string $suffix = '/mo'): string {
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

function rss_leads_compact_text(string $html, int $limit = 2400): string {
	return RssLeadsText::compact($html, $limit);
}

function rss_leads_normalized_text(string $value): string {
	return RssLeadsText::normalized($value);
}

function rss_leads_configured_locations(): array {
	$locations = [];
	$env = trim((string)(getenv('RSS_LEADS_LOCAL_LOCATIONS') ?: ''));
	if ($env !== '') {
		foreach (preg_split('/[;\n|]+/', $env) ?: [] as $location) {
			$location = rss_leads_normalized_text($location);
			if ($location !== '') {
				$locations[$location] = true;
			}
		}
	}

	$configPaths = [
		getenv('RSS_LEADS_LOCATION_CONFIG') ?: '/opt/rss-leads-stack/feeds/local-location.json',
		getenv('RSS_LEADS_LOCATION_USER_CONFIG') ?: '/var/www/FreshRSS/data/users/' . (getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine')) . '/rss_leads_location.json',
	];
	foreach ($configPaths as $configPath) {
		if (!is_readable($configPath)) {
			continue;
		}
		$json = file_get_contents($configPath);
		$config = is_string($json) ? json_decode($json, true) : null;
		if (!is_array($config)) {
			continue;
		}
		foreach (($config['locations'] ?? []) as $location) {
			$location = rss_leads_normalized_text((string)$location);
			if ($location !== '') {
				$locations[$location] = true;
			}
		}
	}

	return array_keys($locations);
}

function rss_leads_in_person_required(string $text): bool {
	$normalized = rss_leads_normalized_text($text);
	$inPersonPatterns = [
		'/\b(in person|in-person|onsite|on-site|on site|office based|office-based|hybrid|local candidates?|must be (?:based|located)|based in|located in|relocat(?:e|ion)|commut(?:e|ing))\b/u',
		'/\b(?:near|around|within) [a-z][a-z .,-]{2,40}\b/u',
	];
	foreach ($inPersonPatterns as $pattern) {
		if (preg_match($pattern, $normalized) === 1) {
			return true;
		}
	}
	if (preg_match('/\bremote\b/u', $normalized) === 1 && preg_match('/\bhybrid\b/u', $normalized) !== 1) {
		return false;
	}
	return false;
}

function rss_leads_matches_local_location(string $text, array $locations): bool {
	if (empty($locations)) {
		return true;
	}
	$normalized = ' ' . rss_leads_normalized_text($text) . ' ';
	foreach ($locations as $location) {
		if ($location !== '' && str_contains($normalized, ' ' . $location . ' ')) {
			return true;
		}
	}
	return false;
}

function rss_leads_location_allowed_for_high_priority(array $source, array $locations): bool {
	if (empty($locations)) {
		return true;
	}
	$text = (string)($source['title'] ?? '') . ' ' . (string)($source['content'] ?? '');
	if (!rss_leads_in_person_required($text)) {
		return true;
	}
	return rss_leads_matches_local_location($text, $locations);
}

function rss_leads_estimate_monthly_amount(string $title, string $content): string {
	$text = rss_leads_compact_text($title . ' ' . $content);
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
			$min = rss_leads_parse_money_value($match[1], $match[2] ?? '') * $multiplier;
			$max = isset($match[3]) && $match[3] !== '' ? rss_leads_parse_money_value($match[3], $match[4] ?? '') * $multiplier : $min;
			return rss_leads_format_monthly_amount($min, $max, $suffix);
		}
	}
	if (preg_match('~(?:budget|pay|paid|payment|salary|rate)[^$]{0,24}' . $range . '~iu', $text, $match) === 1) {
		$min = rss_leads_parse_money_value($match[1], $match[2] ?? '');
		$max = isset($match[3]) && $match[3] !== '' ? rss_leads_parse_money_value($match[3], $match[4] ?? '') : $min;
		return rss_leads_format_monthly_amount($min, $max, '/mo equiv');
	}
	return 'unknown';
}

function rss_leads_resolve_monthly_amount(string $monthlyAmount, string $title, string $content): string {
	$monthlyAmount = rss_leads_monthly_amount_label($monthlyAmount);
	if ($monthlyAmount !== 'unknown') {
		return $monthlyAmount;
	}
	return rss_leads_estimate_monthly_amount($title, $content);
}

function rss_leads_has_known_payment(string $monthlyAmount, string $title, string $content): bool {
	$resolved = rss_leads_resolve_monthly_amount($monthlyAmount, $title, $content);
	return trim($resolved) !== '' && mb_strtolower(trim($resolved), 'UTF-8') !== 'unknown';
}

function rss_leads_monthly_amount_tag(string $monthlyAmount): string {
	$tag = mb_strtolower(rss_leads_monthly_amount_label($monthlyAmount), 'UTF-8');
	$tag = preg_replace('/\s+/u', '_', $tag) ?? $tag;
	$tag = preg_replace('/[^a-z0-9$.,:+\/_-]+/u', '', $tag) ?? $tag;
	$tag = trim($tag, '_');
	return 'monthly:' . ($tag === '' ? 'unknown' : $tag);
}

function rss_leads_author_label(string $author): string {
	$author = html_entity_decode($author, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$author = preg_replace('/^[;\s]+/u', '', $author) ?? $author;
	$author = preg_replace('/\s+/u', ' ', $author) ?? $author;
	return trim($author);
}

function rss_leads_job_type_label(string $jobType): string {
	$jobType = preg_replace('/\s+/u', ' ', trim($jobType)) ?? trim($jobType);
	return $jobType;
}

function rss_leads_job_type_tag(string $jobType): string {
	$tag = mb_strtolower(rss_leads_job_type_label($jobType), 'UTF-8');
	$tag = str_replace('&', 'and', $tag);
	$tag = preg_replace('/\s+/u', '_', $tag) ?? $tag;
	$tag = preg_replace('/[^a-z0-9+#\/_-]+/u', '', $tag) ?? $tag;
	$tag = trim($tag, '_');
	return 'job:' . ($tag === '' ? 'unknown' : $tag);
}

function rss_leads_tags_with_ai_labels(string $tags, string $monthlyAmount, string $jobType): string {
	$existing = preg_split('/\s+/', trim($tags)) ?: [];
	$merged = [];
	foreach ($existing as $tag) {
		$tag = trim($tag);
		$lowerTag = mb_strtolower($tag, 'UTF-8');
		if ($tag === '' || str_starts_with($lowerTag, 'monthly:') || str_starts_with($lowerTag, 'job:')) {
			continue;
		}
		$merged[$tag] = true;
	}
	$merged[rss_leads_monthly_amount_tag($monthlyAmount)] = true;
	$merged[rss_leads_job_type_tag($jobType)] = true;
	return implode(' ', array_keys($merged));
}

function rss_leads_ai_has_column(PDO $db, string $name): bool {
	foreach ($db->query('PRAGMA table_info(rss_leads_ai)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
		if (($column['name'] ?? '') === $name) {
			return true;
		}
	}
	return false;
}

function rss_leads_ai_has_monthly_amount(PDO $db): bool {
	return rss_leads_ai_has_column($db, 'monthly_amount');
}

function rss_leads_priority_label(string $priority): string {
	return match ($priority) {
		'x_high' => 'x-high',
		'not_hiring' => 'not hiring',
		default => $priority,
	};
}

function rss_leads_high_priority_content(string $priority, string $summary, string $monthlyAmount, string $jobType, string $content): string {
	$summaryHtml = htmlspecialchars(trim($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$monthlyHtml = htmlspecialchars(rss_leads_monthly_amount_label($monthlyAmount), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$jobTypeHtml = htmlspecialchars(rss_leads_job_type_label($jobType) === '' ? 'unknown' : rss_leads_job_type_label($jobType), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$priorityHtml = htmlspecialchars(rss_leads_priority_label($priority), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	return '<p><strong>AI ' . $priorityHtml . ':</strong> ' . $summaryHtml . '</p>'
		. '<p><strong>Estimated monthly amount:</strong> ' . $monthlyHtml . '</p>'
		. '<p><strong>Job type:</strong> ' . $jobTypeHtml . '</p>'
		. rss_leads_compact_html($content);
}

function rss_leads_ensure_bucket_feed(PDO $db, array $bucket): int {
	$selectCategory = $db->prepare('SELECT id FROM category WHERE name = :name');
	$selectCategory->execute([':name' => (string)$bucket['category']]);
	$categoryId = $selectCategory->fetchColumn();
	if ($categoryId === false) {
		foreach (($bucket['old_categories'] ?? []) as $oldCategory) {
			$selectCategory->execute([':name' => (string)$oldCategory]);
			$categoryId = $selectCategory->fetchColumn();
			if ($categoryId !== false) {
				$renameCategory = $db->prepare('UPDATE category SET name = :name WHERE id = :id');
				$renameCategory->execute([
					':name' => (string)$bucket['category'],
					':id' => (int)$categoryId,
				]);
				break;
			}
		}
	}
	if ($categoryId === false) {
		$insertCategory = $db->prepare('INSERT INTO category (name, kind, attributes) VALUES (:name, 0, :attributes)');
		$insertCategory->execute([
			':name' => (string)$bucket['category'],
			':attributes' => '[]',
		]);
		$categoryId = (int)$db->lastInsertId();
	}

	$attributes = json_encode($bucket['attributes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	$selectFeed = $db->prepare('SELECT id FROM feed WHERE name = :name');
	$selectFeed->execute([':name' => (string)$bucket['feed']]);
	$feedId = $selectFeed->fetchColumn();
	if ($feedId === false) {
		foreach (($bucket['old_feeds'] ?? []) as $oldFeed) {
			$selectFeed->execute([':name' => (string)$oldFeed]);
			$feedId = $selectFeed->fetchColumn();
			if ($feedId !== false) {
				break;
			}
		}
	}
	if ($feedId === false) {
		$insertFeed = $db->prepare(
			'INSERT INTO feed (url, kind, category, name, website, description, lastUpdate, priority, pathEntries, httpAuth, error, ttl, attributes, cache_nbEntries, cache_nbUnreads)
			 VALUES (:url, 0, :category, :name, :website, :description, 0, 1, "", "", 0, 60, :attributes, 0, 0)'
		);
		$insertFeed->execute([
			':url' => (string)$bucket['url'],
			':category' => (int)$categoryId,
			':name' => (string)$bucket['feed'],
			':website' => (string)$bucket['url'],
			':description' => (string)$bucket['description'],
			':attributes' => $attributes,
		]);
		return (int)$db->lastInsertId();
	}

	$updateFeed = $db->prepare(
		'UPDATE feed
		 SET url = :url,
			 kind = 0,
			 category = :category,
			 name = :name,
			 website = :website,
			 description = :description,
			 priority = 1,
			 ttl = 60,
			 error = 0,
			 attributes = :attributes
		 WHERE id = :id'
	);
	$updateFeed->execute([
		':url' => (string)$bucket['url'],
		':category' => (int)$categoryId,
		':name' => (string)$bucket['feed'],
		':website' => (string)$bucket['url'],
		':description' => (string)$bucket['description'],
		':attributes' => $attributes,
		':id' => (int)$feedId,
	]);
	return (int)$feedId;
}

function rss_leads_ensure_high_priority_feed(PDO $db): int {
	return rss_leads_ensure_bucket_feed($db, rss_leads_feed_buckets()['high']);
}

function rss_leads_latest_bucket_entries(PDO $db, array $bucket): array {
	$monthlyAmountSelect = rss_leads_ai_has_monthly_amount($db) ? 'monthly_amount' : "'' AS monthly_amount";
	$jobTypeSelect = rss_leads_ai_has_column($db, 'job_type') ? 'job_type' : "'' AS job_type";
	$allowedPriorities = ['low' => true, 'medium' => true, 'high' => true, 'x_high' => true, 'not_hiring' => true];
	$priorities = array_values(array_filter((array)$bucket['priorities'], static fn(string $priority): bool => isset($allowedPriorities[$priority])));
	if (empty($priorities)) {
		return [];
	}
	$prioritySql = implode(', ', array_map(static fn(string $priority): string => '"' . $priority . '"', $priorities));
	$stmt = $db->prepare(
		'WITH latest_ai AS (
			SELECT link, summary, priority, ' . $monthlyAmountSelect . ', ' . $jobTypeSelect . ', model, input_hash, updated_at,
				ROW_NUMBER() OVER (PARTITION BY link ORDER BY updated_at DESC, entry_id DESC) AS rn
			FROM rss_leads_ai
		),
		source_entries AS (
			SELECT e.id, e.guid, e.title, e.author, e.content, e.link, e.date, e.lastSeen,
				e.lastModified, e.lastUserModified, e.hash, e.is_favorite, e.tags, e.attributes,
				f.name AS feed_name,
				ROW_NUMBER() OVER (
					PARTITION BY e.link
					ORDER BY
						CASE
							WHEN f.name = :all_feed_rank THEN 0
							WHEN f.name = :qualified_feed_rank THEN 1
							WHEN f.name = :unqualified_feed_rank THEN 2
							ELSE 3
						END,
						CASE WHEN e.is_read = 0 THEN 0 ELSE 1 END,
						e.date DESC,
						e.id DESC
				) AS rn
			FROM entry e
			JOIN feed f ON f.id = e.id_feed
			WHERE f.name IN (:all_feed_source, :qualified_feed_source, :unqualified_feed, :recovered_feed)
			   OR f.name LIKE :comment_feed_like
		)
		SELECT e.*, ai.summary, ai.priority, ai.monthly_amount, ai.job_type, ai.model, ai.input_hash, ai.updated_at AS ai_updated_at
		FROM latest_ai ai
		JOIN source_entries e ON e.link = ai.link AND e.rn = 1
		WHERE ai.rn = 1
		  AND ai.priority IN (' . $prioritySql . ')
		ORDER BY e.date DESC, ai.updated_at DESC'
	);
	$stmt->execute([
		':all_feed_rank' => RSS_LEADS_ALL_FEED,
		':qualified_feed_rank' => RSS_LEADS_QUALIFIED_FEED,
		':unqualified_feed_rank' => RSS_LEADS_UNQUALIFIED_FEED,
		':all_feed_source' => RSS_LEADS_ALL_FEED,
		':qualified_feed_source' => RSS_LEADS_QUALIFIED_FEED,
		':unqualified_feed' => RSS_LEADS_UNQUALIFIED_FEED,
		':recovered_feed' => RSS_LEADS_RECOVERED_FEED,
		':comment_feed_like' => RSS_LEADS_COMMENT_FEED_LIKE,
	]);
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rss_leads_latest_high_priority_entries(PDO $db): array {
	return rss_leads_latest_bucket_entries($db, rss_leads_feed_buckets()['high']);
}

function rss_leads_bucket_entry_id(PDO $db, array $bucket, int $date, string $link, ?int $currentId = null): int {
	$timestamp = max(1, $date);
	$base = $timestamp * 1000000;
	$offset = (int)(hexdec(substr(sha1(rss_leads_bucket_guid($bucket, $link)), 0, 10)) % 1000000);
	$select = $db->prepare('SELECT id FROM entry WHERE id = :id');
	for ($attempt = 0; $attempt < 1000000; $attempt++) {
		$candidate = $base + (($offset + $attempt) % 1000000);
		$select->execute([':id' => $candidate]);
		$owner = $select->fetchColumn();
		if ($owner === false || ($currentId !== null && (int)$owner === $currentId)) {
			return $candidate;
		}
	}
	throw new RuntimeException('Unable to allocate a date-based RSS leads bucket entry id.');
}

function rss_leads_high_priority_entry_id(PDO $db, int $date, string $link, ?int $currentId = null): int {
	return rss_leads_bucket_entry_id($db, rss_leads_feed_buckets()['high'], $date, $link, $currentId);
}

function rss_leads_update_high_priority_cache(PDO $db, int $feedId, int $now): void {
	$cache = $db->prepare(
		'UPDATE feed
		 SET cache_nbEntries = (SELECT COUNT(*) FROM entry WHERE id_feed = :feed_id),
			 cache_nbUnreads = (SELECT COUNT(*) FROM entry WHERE id_feed = :feed_id_unread AND is_read = 0),
			 lastUpdate = :updated_at,
			 error = 0
		 WHERE id = :feed_id_where'
	);
	$cache->execute([
		':feed_id' => $feedId,
		':feed_id_unread' => $feedId,
		':feed_id_where' => $feedId,
		':updated_at' => $now,
	]);
}

function rss_leads_update_source_feed_caches(PDO $db): void {
	$cache = $db->prepare(
		'UPDATE feed
		 SET cache_nbEntries = (SELECT COUNT(*) FROM entry WHERE id_feed = feed.id),
			 cache_nbUnreads = (SELECT COUNT(*) FROM entry WHERE id_feed = feed.id AND is_read = 0)
		 WHERE name IN (:all_feed, :qualified_feed, :unqualified_feed, :recovered_feed)
		    OR name LIKE :comment_feed_like'
	);
	$cache->execute([
		':all_feed' => RSS_LEADS_ALL_FEED,
		':qualified_feed' => RSS_LEADS_QUALIFIED_FEED,
		':unqualified_feed' => RSS_LEADS_UNQUALIFIED_FEED,
		':recovered_feed' => RSS_LEADS_RECOVERED_FEED,
		':comment_feed_like' => RSS_LEADS_COMMENT_FEED_LIKE,
	]);
}

function rss_leads_sync_bucket_feed(PDO $db, string $bucketKey, array $bucket, int $now): array {
	$created = 0;
	$updated = 0;
	$removed = 0;
	$rekeyed = 0;
	$feedId = rss_leads_ensure_bucket_feed($db, $bucket);
	$sources = rss_leads_latest_bucket_entries($db, $bucket);
	if (!empty($bucket['location_filter'])) {
		$locations = rss_leads_configured_locations();
		$sources = array_values(array_filter($sources, static fn(array $source): bool => rss_leads_location_allowed_for_high_priority($source, $locations)));
	}
	if (!empty($bucket['require_known_payment'])) {
		$sources = array_values(array_filter($sources, static fn(array $source): bool => rss_leads_has_known_payment(
			(string)($source['monthly_amount'] ?? ''),
			(string)($source['title'] ?? ''),
			(string)($source['content'] ?? '')
		)));
	}
	$sourceCount = count($sources);
	$currentLinks = [];

	$selectExisting = $db->prepare(
		'SELECT id
		 FROM entry
		 WHERE id_feed = :feed_id
		   AND (guid = :guid OR link = :link)
		 ORDER BY CASE WHEN guid = :guid_rank THEN 0 ELSE 1 END
		 LIMIT 1'
	);
	$insertEntry = $db->prepare(
		'INSERT INTO entry (id, guid, title, author, content, link, date, lastSeen, lastModified, lastUserModified, hash, is_read, is_favorite, id_feed, tags, attributes)
		 VALUES (:id, :guid, :title, :author, :content, :link, :date, :last_seen, :last_modified, :last_user_modified, :hash, :is_read, :is_favorite, :id_feed, :tags, :attributes)'
	);
	$updateEntry = $db->prepare(
		'UPDATE entry
		 SET id = :new_id,
			 guid = :guid,
			 title = :title,
			 author = :author,
			 content = :content,
			 link = :link,
			 date = :date,
			 lastSeen = :last_seen,
			 lastModified = :last_modified,
			 hash = :hash,
			 is_favorite = :is_favorite,
			 tags = :tags,
			 attributes = :attributes
		 WHERE id = :id'
	);

	foreach ($sources as $source) {
		$link = (string)$source['link'];
		$currentLinks[$link] = true;
		$guid = rss_leads_bucket_guid($bucket, $link);
		$entryDate = (int)$source['date'];
		$monthlyAmount = rss_leads_resolve_monthly_amount((string)($source['monthly_amount'] ?? ''), (string)$source['title'], (string)$source['content']);
		$jobType = rss_leads_job_type_label((string)($source['job_type'] ?? ''));
		$author = rss_leads_author_label((string)($source['author'] ?? ''));
		$content = rss_leads_high_priority_content((string)$source['priority'], (string)$source['summary'], $monthlyAmount, $jobType, (string)$source['content']);
		$tags = rss_leads_tags_with_ai_labels((string)($source['tags'] ?? ''), $monthlyAmount, $jobType);

		$selectExisting->execute([
			':feed_id' => $feedId,
			':guid' => $guid,
			':link' => $link,
			':guid_rank' => $guid,
		]);
		$existingId = $selectExisting->fetchColumn();
		if ($existingId === false) {
			$isRecoveredSource = (string)($source['feed_name'] ?? '') === RSS_LEADS_RECOVERED_FEED;
			$entryId = rss_leads_bucket_entry_id($db, $bucket, $entryDate, $link);
			$insertEntry->execute([
				':id' => $entryId,
				':guid' => $guid,
				':title' => (string)$source['title'],
				':author' => $author,
				':content' => $content,
				':link' => $link,
				':date' => $entryDate,
				':last_seen' => (int)($source['lastSeen'] ?? $now),
				':last_modified' => (int)($source['lastModified'] ?? 0),
				':last_user_modified' => $now,
				':hash' => $source['hash'],
				':is_read' => $isRecoveredSource || $bucketKey === 'not_hiring' ? 1 : 0,
				':is_favorite' => (int)($source['is_favorite'] ?? 0),
				':id_feed' => $feedId,
				':tags' => $tags,
				':attributes' => (string)($source['attributes'] ?? '[]'),
			]);
			$created++;
			continue;
		}

		$existingId = (int)$existingId;
		$entryId = rss_leads_bucket_entry_id($db, $bucket, $entryDate, $link, $existingId);
		if ($entryId !== $existingId) {
			$rekeyed++;
		}
		$updateEntry->execute([
			':new_id' => $entryId,
			':guid' => $guid,
			':title' => (string)$source['title'],
			':author' => $author,
			':content' => $content,
			':link' => $link,
			':date' => $entryDate,
			':last_seen' => (int)($source['lastSeen'] ?? $now),
			':last_modified' => (int)($source['lastModified'] ?? 0),
			':hash' => $source['hash'],
			':is_favorite' => (int)($source['is_favorite'] ?? 0),
			':tags' => $tags,
			':attributes' => (string)($source['attributes'] ?? '[]'),
			':id' => $existingId,
		]);
		$updated++;
	}

	$existingEntries = $db->prepare('SELECT id, link FROM entry WHERE id_feed = :feed_id');
	$existingEntries->execute([':feed_id' => $feedId]);
	$deleteEntry = $db->prepare('DELETE FROM entry WHERE id = :id AND id_feed = :feed_id');
	foreach ($existingEntries->fetchAll(PDO::FETCH_ASSOC) as $entry) {
		if (isset($currentLinks[(string)$entry['link']])) {
			continue;
		}
		$deleteEntry->execute([
			':id' => (int)$entry['id'],
			':feed_id' => $feedId,
		]);
		$removed++;
	}

	if ($bucketKey === 'not_hiring') {
		$markBucketRead = $db->prepare('UPDATE entry SET is_read = 1, lastUserModified = :updated_at WHERE id_feed = :feed_id');
		$markBucketRead->execute([
			':updated_at' => $now,
			':feed_id' => $feedId,
		]);
	}

	rss_leads_update_high_priority_cache($db, $feedId, $now);

	return [
		'feed_id' => $feedId,
		'source_links' => $sourceCount,
		'created' => $created,
		'updated' => $updated,
		'rekeyed' => $rekeyed,
		'removed' => $removed,
	];
}

function rss_leads_sync_priority_feeds(PDO $db, ?int $now = null): array {
	$now ??= time();
	$results = [];

	$ownsTransaction = !$db->inTransaction();
	if ($ownsTransaction) {
		$db->beginTransaction();
	}

	try {
		foreach (rss_leads_feed_buckets() as $bucketKey => $bucket) {
			$results[$bucketKey] = rss_leads_sync_bucket_feed($db, $bucketKey, $bucket, $now);
		}
		$obsolete = $db->prepare('DELETE FROM category WHERE name = :name AND NOT EXISTS (SELECT 1 FROM feed WHERE feed.category = category.id)');
		foreach (['Top Priority', 'Low-Medium Priority', 'Medium-High Priority', 'High Priority'] as $name) {
			$obsolete->execute([':name' => $name]);
		}
		rss_leads_update_source_feed_caches($db);

		if ($ownsTransaction) {
			$db->commit();
		}
	} catch (Throwable $e) {
		if ($ownsTransaction && $db->inTransaction()) {
			$db->rollBack();
		}
		throw $e;
	}

	return $results;
}

function rss_leads_sync_high_priority_feed(PDO $db, ?int $now = null): array {
	return rss_leads_sync_priority_feeds($db, $now);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
	if (preg_match('/^[A-Za-z0-9_.-]+$/', $user) !== 1) {
		throw new InvalidArgumentException('FreshRSS username contains unsupported characters.');
	}

	$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
	$db = RssLeadsDb::sqlite($dbPath);
	$result = rss_leads_sync_high_priority_feed($db);
	echo 'Synced priority feeds: ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
