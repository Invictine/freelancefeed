<?php
declare(strict_types=1);

const RSS_LEADS_HIGH_PRIORITY_CATEGORY = 'High Priority';
const RSS_LEADS_HIGH_PRIORITY_FEED = 'High Priority Reddit Leads';
const RSS_LEADS_HIGH_PRIORITY_FEED_URL = 'http://127.0.0.1/rss-leads-high-priority.php';
const RSS_LEADS_QUALIFIED_FEED = 'Reddit Leads - qualified deep-research communities';
const RSS_LEADS_UNQUALIFIED_FEED = 'Reddit Leads - unqualified deep-research communities';

function rss_leads_high_priority_guid(string $link): string {
	return 'rss-leads-high:' . sha1($link);
}

function rss_leads_compact_html(string $html, int $limit = 6000): string {
	$html = trim($html);
	if (mb_strlen($html, 'UTF-8') <= $limit) {
		return $html;
	}
	return mb_substr($html, 0, $limit, 'UTF-8') . '...';
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
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
	$text = trim($text);
	if (mb_strlen($text, 'UTF-8') > $limit) {
		$text = mb_substr($text, 0, $limit, 'UTF-8');
	}
	return $text;
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

function rss_leads_high_priority_content(string $summary, string $monthlyAmount, string $jobType, string $content): string {
	$summaryHtml = htmlspecialchars(trim($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$monthlyHtml = htmlspecialchars(rss_leads_monthly_amount_label($monthlyAmount), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$jobTypeHtml = htmlspecialchars(rss_leads_job_type_label($jobType) === '' ? 'unknown' : rss_leads_job_type_label($jobType), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	return '<p><strong>AI high priority:</strong> ' . $summaryHtml . '</p>'
		. '<p><strong>Estimated monthly amount:</strong> ' . $monthlyHtml . '</p>'
		. '<p><strong>Job type:</strong> ' . $jobTypeHtml . '</p>'
		. rss_leads_compact_html($content);
}

function rss_leads_ensure_high_priority_feed(PDO $db): int {
	$selectCategory = $db->prepare('SELECT id FROM category WHERE name = :name');
	$selectCategory->execute([':name' => RSS_LEADS_HIGH_PRIORITY_CATEGORY]);
	$categoryId = $selectCategory->fetchColumn();
	if ($categoryId === false) {
		$insertCategory = $db->prepare('INSERT INTO category (name, kind, attributes) VALUES (:name, 0, :attributes)');
		$insertCategory->execute([
			':name' => RSS_LEADS_HIGH_PRIORITY_CATEGORY,
			':attributes' => '[]',
		]);
		$categoryId = (int)$db->lastInsertId();
	}

	$attributes = json_encode([
		'lead_bucket' => 'high_priority',
		'source' => 'rss_leads_ai',
		'priority' => 'high',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	$selectFeed = $db->prepare('SELECT id FROM feed WHERE name = :name');
	$selectFeed->execute([':name' => RSS_LEADS_HIGH_PRIORITY_FEED]);
	$feedId = $selectFeed->fetchColumn();
	if ($feedId === false) {
		$insertFeed = $db->prepare(
			'INSERT INTO feed (url, kind, category, name, website, description, lastUpdate, priority, pathEntries, httpAuth, error, ttl, attributes, cache_nbEntries, cache_nbUnreads)
			 VALUES (:url, 0, :category, :name, :website, :description, 0, 1, "", "", 0, 60, :attributes, 0, 0)'
		);
		$insertFeed->execute([
			':url' => RSS_LEADS_HIGH_PRIORITY_FEED_URL,
			':category' => (int)$categoryId,
			':name' => RSS_LEADS_HIGH_PRIORITY_FEED,
			':website' => RSS_LEADS_HIGH_PRIORITY_FEED_URL,
			':description' => 'AI-classified high priority Reddit leads from the FreshRSS leads stack.',
			':attributes' => $attributes,
		]);
		return (int)$db->lastInsertId();
	}

	$updateFeed = $db->prepare(
		'UPDATE feed
		 SET url = :url,
			 kind = 0,
			 category = :category,
			 website = :website,
			 description = :description,
			 priority = 1,
			 ttl = 60,
			 error = 0,
			 attributes = :attributes
		 WHERE id = :id'
	);
	$updateFeed->execute([
		':url' => RSS_LEADS_HIGH_PRIORITY_FEED_URL,
		':category' => (int)$categoryId,
		':website' => RSS_LEADS_HIGH_PRIORITY_FEED_URL,
		':description' => 'AI-classified high priority Reddit leads from the FreshRSS leads stack.',
		':attributes' => $attributes,
		':id' => (int)$feedId,
	]);
	return (int)$feedId;
}

function rss_leads_latest_high_priority_entries(PDO $db): array {
	$monthlyAmountSelect = rss_leads_ai_has_monthly_amount($db) ? 'monthly_amount' : "'' AS monthly_amount";
	$jobTypeSelect = rss_leads_ai_has_column($db, 'job_type') ? 'job_type' : "'' AS job_type";
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
						CASE WHEN f.name = :qualified_feed_rank THEN 0 ELSE 1 END,
						CASE WHEN e.is_read = 0 THEN 0 ELSE 1 END,
						e.date DESC,
						e.id DESC
				) AS rn
			FROM entry e
			JOIN feed f ON f.id = e.id_feed
			WHERE f.name IN (:qualified_feed_source, :unqualified_feed)
		)
		SELECT e.*, ai.summary, ai.monthly_amount, ai.job_type, ai.model, ai.input_hash, ai.updated_at AS ai_updated_at
		FROM latest_ai ai
		JOIN source_entries e ON e.link = ai.link AND e.rn = 1
		WHERE ai.rn = 1
		  AND ai.priority = "high"
		ORDER BY e.date DESC, ai.updated_at DESC'
	);
	$stmt->execute([
		':qualified_feed_rank' => RSS_LEADS_QUALIFIED_FEED,
		':qualified_feed_source' => RSS_LEADS_QUALIFIED_FEED,
		':unqualified_feed' => RSS_LEADS_UNQUALIFIED_FEED,
	]);
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rss_leads_next_entry_id(PDO $db, int $minimum): int {
	$maxId = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM entry')->fetchColumn();
	return max($maxId + 1, $minimum + 1);
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
		 WHERE name IN (:qualified_feed, :unqualified_feed)'
	);
	$cache->execute([
		':qualified_feed' => RSS_LEADS_QUALIFIED_FEED,
		':unqualified_feed' => RSS_LEADS_UNQUALIFIED_FEED,
	]);
}

function rss_leads_sync_high_priority_feed(PDO $db, ?int $now = null): array {
	$now ??= time();
	$created = 0;
	$updated = 0;
	$removed = 0;
	$feedId = 0;
	$sourceCount = 0;

	$ownsTransaction = !$db->inTransaction();
	if ($ownsTransaction) {
		$db->beginTransaction();
	}

	try {
		$feedId = rss_leads_ensure_high_priority_feed($db);
		$sources = rss_leads_latest_high_priority_entries($db);
		$sourceCount = count($sources);
		$currentLinks = [];

		$selectExisting = $db->prepare('SELECT id FROM entry WHERE id_feed = :feed_id AND guid = :guid');
		$markSourceRead = $db->prepare(
			'UPDATE entry
			 SET is_read = 1,
				 lastUserModified = :updated_at
			 WHERE link = :link
			   AND id_feed IN (
				 SELECT id FROM feed WHERE name IN (:qualified_feed, :unqualified_feed)
			   )'
		);
		$insertEntry = $db->prepare(
			'INSERT INTO entry (id, guid, title, author, content, link, date, lastSeen, lastModified, lastUserModified, hash, is_read, is_favorite, id_feed, tags, attributes)
			 VALUES (:id, :guid, :title, :author, :content, :link, :date, :last_seen, :last_modified, :last_user_modified, :hash, 0, :is_favorite, :id_feed, :tags, :attributes)'
		);
		$updateEntry = $db->prepare(
			'UPDATE entry
			 SET title = :title,
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

		$nextId = rss_leads_next_entry_id($db, $now * 1000000);
		foreach ($sources as $source) {
			$link = (string)$source['link'];
			$currentLinks[$link] = true;
			$guid = rss_leads_high_priority_guid($link);
			$monthlyAmount = rss_leads_resolve_monthly_amount((string)($source['monthly_amount'] ?? ''), (string)$source['title'], (string)$source['content']);
			$jobType = rss_leads_job_type_label((string)($source['job_type'] ?? ''));
			$author = rss_leads_author_label((string)($source['author'] ?? ''));
			$content = rss_leads_high_priority_content((string)$source['summary'], $monthlyAmount, $jobType, (string)$source['content']);
			$tags = rss_leads_tags_with_ai_labels((string)($source['tags'] ?? ''), $monthlyAmount, $jobType);
			$markSourceRead->execute([
				':updated_at' => $now,
				':link' => $link,
				':qualified_feed' => RSS_LEADS_QUALIFIED_FEED,
				':unqualified_feed' => RSS_LEADS_UNQUALIFIED_FEED,
			]);

			$selectExisting->execute([
				':feed_id' => $feedId,
				':guid' => $guid,
			]);
			$existingId = $selectExisting->fetchColumn();
			if ($existingId === false) {
				$insertEntry->execute([
					':id' => $nextId++,
					':guid' => $guid,
					':title' => (string)$source['title'],
					':author' => $author,
					':content' => $content,
					':link' => $link,
					':date' => (int)$source['date'],
					':last_seen' => (int)($source['lastSeen'] ?? $now),
					':last_modified' => (int)($source['lastModified'] ?? 0),
					':last_user_modified' => $now,
					':hash' => $source['hash'],
					':is_favorite' => (int)($source['is_favorite'] ?? 0),
					':id_feed' => $feedId,
					':tags' => $tags,
					':attributes' => (string)($source['attributes'] ?? '[]'),
				]);
				$created++;
				continue;
			}

			$updateEntry->execute([
				':title' => (string)$source['title'],
				':author' => $author,
				':content' => $content,
				':link' => $link,
				':date' => (int)$source['date'],
				':last_seen' => (int)($source['lastSeen'] ?? $now),
				':last_modified' => (int)($source['lastModified'] ?? 0),
				':hash' => $source['hash'],
				':is_favorite' => (int)($source['is_favorite'] ?? 0),
				':tags' => $tags,
				':attributes' => (string)($source['attributes'] ?? '[]'),
				':id' => (int)$existingId,
			]);
			$updated++;
		}

		$existingHigh = $db->prepare('SELECT id, link FROM entry WHERE id_feed = :feed_id');
		$existingHigh->execute([':feed_id' => $feedId]);
		$deleteEntry = $db->prepare('DELETE FROM entry WHERE id = :id AND id_feed = :feed_id');
		foreach ($existingHigh->fetchAll(PDO::FETCH_ASSOC) as $entry) {
			if (isset($currentLinks[(string)$entry['link']])) {
				continue;
			}
			$deleteEntry->execute([
				':id' => (int)$entry['id'],
				':feed_id' => $feedId,
			]);
			$removed++;
		}

		rss_leads_update_high_priority_cache($db, $feedId, $now);
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

	return [
		'feed_id' => $feedId,
		'source_high_links' => $sourceCount,
		'created' => $created,
		'updated' => $updated,
		'removed' => $removed,
	];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
	if (preg_match('/^[A-Za-z0-9_.-]+$/', $user) !== 1) {
		throw new InvalidArgumentException('FreshRSS username contains unsupported characters.');
	}

	$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$result = rss_leads_sync_high_priority_feed($db);
	echo 'Synced high priority feed: ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
