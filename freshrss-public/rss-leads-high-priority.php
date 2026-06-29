<?php
declare(strict_types=1);

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: no-store');

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
if (preg_match('/^[A-Za-z0-9_.-]+$/', $user) !== 1) {
	http_response_code(500);
	exit;
}

$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$qualifiedFeedName = 'Reddit Leads - qualified deep-research communities';
$unqualifiedFeedName = 'Reddit Leads - unqualified deep-research communities';
$limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));

function rss_text(string $value): string {
	return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function rss_cdata(string $value): string {
	return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
}

function rss_compact_html(string $html, int $limit = 6000): string {
	$html = trim($html);
	if (mb_strlen($html, 'UTF-8') <= $limit) {
		return $html;
	}
	return mb_substr($html, 0, $limit, 'UTF-8') . '...';
}

function rss_high_priority_guid(string $link): string {
	return 'rss-leads-high:' . sha1($link);
}

function rss_monthly_amount_label(string $monthlyAmount): string {
	$monthlyAmount = trim($monthlyAmount);
	return $monthlyAmount === '' ? 'unknown' : $monthlyAmount;
}

function rss_parse_money_value(string $amount, string $suffix = ''): float {
	$value = (float)str_replace(',', '', $amount);
	if (in_array(strtolower($suffix), ['k', 'm'], true)) {
		$value *= strtolower($suffix) === 'm' ? 1000000 : 1000;
	}
	return $value;
}

function rss_format_monthly_amount(float $min, ?float $max = null, string $suffix = '/mo'): string {
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

function rss_compact_text(string $html, int $limit = 2400): string {
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
	$text = trim($text);
	if (mb_strlen($text, 'UTF-8') > $limit) {
		$text = mb_substr($text, 0, $limit, 'UTF-8');
	}
	return $text;
}

function rss_estimate_monthly_amount(string $title, string $content): string {
	$text = rss_compact_text($title . ' ' . $content);
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
			$min = rss_parse_money_value($match[1], $match[2] ?? '') * $multiplier;
			$max = isset($match[3]) && $match[3] !== '' ? rss_parse_money_value($match[3], $match[4] ?? '') * $multiplier : $min;
			return rss_format_monthly_amount($min, $max, $suffix);
		}
	}
	if (preg_match('~(?:budget|pay|paid|payment|salary|rate)[^$]{0,24}' . $range . '~iu', $text, $match) === 1) {
		$min = rss_parse_money_value($match[1], $match[2] ?? '');
		$max = isset($match[3]) && $match[3] !== '' ? rss_parse_money_value($match[3], $match[4] ?? '') : $min;
		return rss_format_monthly_amount($min, $max, '/mo equiv');
	}
	return 'unknown';
}

function rss_resolve_monthly_amount(string $monthlyAmount, string $title, string $content): string {
	$monthlyAmount = rss_monthly_amount_label($monthlyAmount);
	if ($monthlyAmount !== 'unknown') {
		return $monthlyAmount;
	}
	return rss_estimate_monthly_amount($title, $content);
}

function rss_monthly_amount_tag(string $monthlyAmount): string {
	$tag = mb_strtolower(rss_monthly_amount_label($monthlyAmount), 'UTF-8');
	$tag = preg_replace('/\s+/u', '_', $tag) ?? $tag;
	$tag = preg_replace('/[^a-z0-9$.,:+\/_-]+/u', '', $tag) ?? $tag;
	$tag = trim($tag, '_');
	return 'monthly:' . ($tag === '' ? 'unknown' : $tag);
}

function rss_author_label(string $author): string {
	$author = html_entity_decode($author, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$author = preg_replace('/^[;\s]+/u', '', $author) ?? $author;
	$author = preg_replace('/\s+/u', ' ', $author) ?? $author;
	return trim($author);
}

function rss_job_type_label(string $jobType): string {
	$jobType = preg_replace('/\s+/u', ' ', trim($jobType)) ?? trim($jobType);
	return $jobType;
}

function rss_job_type_tag(string $jobType): string {
	$tag = mb_strtolower(rss_job_type_label($jobType), 'UTF-8');
	$tag = str_replace('&', 'and', $tag);
	$tag = preg_replace('/\s+/u', '_', $tag) ?? $tag;
	$tag = preg_replace('/[^a-z0-9+#\/_-]+/u', '', $tag) ?? $tag;
	$tag = trim($tag, '_');
	return 'job:' . ($tag === '' ? 'unknown' : $tag);
}

function rss_ai_has_column(PDO $db, string $name): bool {
	foreach ($db->query('PRAGMA table_info(rss_leads_ai)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
		if (($column['name'] ?? '') === $name) {
			return true;
		}
	}
	return false;
}

function rss_ai_has_monthly_amount(PDO $db): bool {
	return rss_ai_has_column($db, 'monthly_amount');
}

try {
	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$monthlyAmountSelect = rss_ai_has_monthly_amount($db) ? 'monthly_amount' : "'' AS monthly_amount";
	$jobTypeSelect = rss_ai_has_column($db, 'job_type') ? 'job_type' : "'' AS job_type";

	$stmt = $db->prepare(
		'WITH latest_ai AS (
			SELECT link, summary, priority, ' . $monthlyAmountSelect . ', ' . $jobTypeSelect . ', model, updated_at,
				ROW_NUMBER() OVER (PARTITION BY link ORDER BY updated_at DESC, entry_id DESC) AS rn
			FROM rss_leads_ai
		),
		source_entries AS (
			SELECT e.id, e.guid, e.title, e.author, e.content, e.link, e.date, e.tags, f.name AS feed_name,
				ROW_NUMBER() OVER (
					PARTITION BY e.link
					ORDER BY
						CASE WHEN f.name = :qualified_feed_rank THEN 0 ELSE 1 END,
						e.date DESC,
						e.id DESC
				) AS rn
			FROM entry e
			JOIN feed f ON f.id = e.id_feed
			WHERE f.name IN (:qualified_feed_source, :unqualified_feed)
		)
		SELECT e.guid, e.title, e.author, e.content, e.link, e.date, e.tags, ai.summary, ai.monthly_amount, ai.job_type, ai.model, ai.updated_at
		FROM latest_ai ai
		JOIN source_entries e ON e.link = ai.link AND e.rn = 1
		WHERE ai.rn = 1
		  AND ai.priority = "high"
		ORDER BY e.date DESC, ai.updated_at DESC
		LIMIT :limit'
	);
	$stmt->bindValue(':qualified_feed_rank', $qualifiedFeedName, PDO::PARAM_STR);
	$stmt->bindValue(':qualified_feed_source', $qualifiedFeedName, PDO::PARAM_STR);
	$stmt->bindValue(':unqualified_feed', $unqualifiedFeedName, PDO::PARAM_STR);
	$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
	$stmt->execute();
	$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
	http_response_code(500);
	$items = [];
}

$now = time();
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<rss version=\"2.0\">\n";
echo "\t<channel>\n";
echo "\t\t<title>High Priority Reddit Leads</title>\n";
echo "\t\t<link>http://127.0.0.1/rss-leads-high-priority.php</link>\n";
echo "\t\t<description>AI-classified high priority Reddit leads from FreshRSS.</description>\n";
echo "\t\t<lastBuildDate>" . date(DATE_RSS, $now) . "</lastBuildDate>\n";

foreach ($items as $item) {
	$link = (string)$item['link'];
	$summary = trim((string)$item['summary']);
	$content = rss_compact_html((string)$item['content']);
	$monthlyAmount = rss_resolve_monthly_amount((string)($item['monthly_amount'] ?? ''), (string)$item['title'], (string)$item['content']);
	$jobType = rss_job_type_label((string)($item['job_type'] ?? ''));
	$description = '<p><strong>AI high priority:</strong> ' . htmlspecialchars($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
		. '<p><strong>Estimated monthly amount:</strong> ' . htmlspecialchars(rss_monthly_amount_label($monthlyAmount), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
		. '<p><strong>Job type:</strong> ' . htmlspecialchars($jobType === '' ? 'unknown' : $jobType, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
		. $content;
	echo "\t\t<item>\n";
	echo "\t\t\t<title>" . rss_text((string)$item['title']) . "</title>\n";
	echo "\t\t\t<link>" . rss_text($link) . "</link>\n";
	echo "\t\t\t<guid isPermaLink=\"false\">" . rss_text(rss_high_priority_guid($link)) . "</guid>\n";
	echo "\t\t\t<pubDate>" . date(DATE_RSS, (int)$item['date']) . "</pubDate>\n";
	$author = rss_author_label((string)($item['author'] ?? ''));
	if ($author !== '') {
		echo "\t\t\t<author>" . rss_text($author) . "</author>\n";
	}
	echo "\t\t\t<category>" . rss_text(rss_monthly_amount_tag($monthlyAmount)) . "</category>\n";
	echo "\t\t\t<category>" . rss_text(rss_job_type_tag($jobType)) . "</category>\n";
	foreach (preg_split('/\s+/', trim((string)$item['tags'])) ?: [] as $tag) {
		$tag = trim($tag, "# \t\n\r\0\x0B");
		$lowerTag = mb_strtolower($tag, 'UTF-8');
		if ($tag !== '' && !str_starts_with($lowerTag, 'monthly:') && !str_starts_with($lowerTag, 'job:')) {
			echo "\t\t\t<category>" . rss_text($tag) . "</category>\n";
		}
	}
	echo "\t\t\t<description>" . rss_cdata($description) . "</description>\n";
	echo "\t\t</item>\n";
}

echo "\t</channel>\n";
echo "</rss>\n";
