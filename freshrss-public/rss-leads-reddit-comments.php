<?php
declare(strict_types=1);

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: no-store');

$freshRssUser = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
if (preg_match('/^[A-Za-z0-9_.-]+$/', $freshRssUser) !== 1) {
	http_response_code(500);
	exit;
}

$sourceId = (string)($_GET['source'] ?? '');
if (preg_match('/^[A-Za-z0-9_-]+$/', $sourceId) !== 1) {
	http_response_code(400);
	echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"><channel><title>Invalid Reddit comment source</title></channel></rss>";
	exit;
}

function rss_leads_default_comment_thread_config(): array {
	return [
		'timezone' => 'Asia/Kolkata',
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
			'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0',
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

function rss_leads_load_comment_thread_config(): array {
	$config = rss_leads_default_comment_thread_config();
	$path = getenv('RSS_LEADS_REDDIT_COMMENT_THREADS_CONFIG') ?: '/opt/rss-leads-stack/feeds/reddit-comment-threads.json';
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

function rss_leads_comment_sources(array $config): array {
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
			|| trim((string)($merged['q'] ?? '')) === ''
		) {
			continue;
		}
		$sources[(string)$merged['id']] = $merged;
	}
	return $sources;
}

function rss_text(string $value): string {
	return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function rss_cdata(string $value): string {
	return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
}

function compact_text(string $html, int $limit): string {
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
	$text = trim($text);
	if (mb_strlen($text, 'UTF-8') > $limit) {
		return mb_substr($text, 0, $limit, 'UTF-8');
	}
	return $text;
}

function reddit_xml(string $url, string $userAgent, int $timeoutSeconds = 12): SimpleXMLElement {
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
			CURLOPT_TIMEOUT => $timeoutSeconds,
			CURLOPT_USERAGENT => $userAgent,
			CURLOPT_HTTPHEADER => [
				'Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8',
				'Accept-Language: en-US,en;q=0.9',
			],
		]);
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		if (!is_string($body) || $status >= 400) {
			throw new RuntimeException('Reddit request failed with HTTP ' . $status . ($error !== '' ? ': ' . $error : ''));
		}
	} else {
		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => $timeoutSeconds,
				'header' => "User-Agent: {$userAgent}\r\nAccept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8\r\nAccept-Language: en-US,en;q=0.9\r\n",
			],
		]);
		$body = file_get_contents($url, false, $context);
		if (!is_string($body)) {
			throw new RuntimeException('Reddit request failed.');
		}
	}

	$xml = @simplexml_load_string($body);
	if (!$xml instanceof SimpleXMLElement) {
		throw new RuntimeException('Reddit returned invalid XML.');
	}
	return $xml;
}

function atom_entries(SimpleXMLElement $xml): array {
	$atom = $xml->children('http://www.w3.org/2005/Atom');
	if (count($atom->entry) > 0) {
		return iterator_to_array($atom->entry, false);
	}
	return iterator_to_array($xml->entry, false);
}

function atom_link(SimpleXMLElement $entry): string {
	$atom = $entry->children('http://www.w3.org/2005/Atom');
	$links = count($atom->link) > 0 ? $atom->link : $entry->link;
	foreach ($links as $link) {
		$attrs = $link->attributes();
		$href = (string)($attrs['href'] ?? '');
		if ($href !== '') {
			return $href;
		}
	}
	return '';
}

function atom_text(SimpleXMLElement $entry, string $name): string {
	$atom = $entry->children('http://www.w3.org/2005/Atom');
	if (isset($atom->{$name})) {
		return (string)$atom->{$name};
	}
	return isset($entry->{$name}) ? (string)$entry->{$name} : '';
}

function atom_author(SimpleXMLElement $entry): string {
	$atom = $entry->children('http://www.w3.org/2005/Atom');
	$name = '';
	if (isset($atom->author->name)) {
		$name = (string)$atom->author->name;
	} elseif (isset($entry->author->name)) {
		$name = (string)$entry->author->name;
	}
	$name = preg_replace('~^/?u/~i', '', trim($name)) ?? trim($name);
	return $name;
}

function source_threads(array $source): array {
	$subreddit = (string)$source['subreddit'];
	$query = (string)$source['q'];
	$sort = preg_replace('/[^a-z]/i', '', (string)($source['sort'] ?? 'new')) ?: 'new';
	$time = preg_replace('/[^a-z]/i', '', (string)($source['time'] ?? 'month')) ?: 'month';
	$limit = max(1, min(100, (int)($source['search_limit'] ?? 25)));
	$url = 'https://www.reddit.com/r/' . rawurlencode($subreddit) . '/search.rss?restrict_sr=1'
		. '&sort=' . rawurlencode($sort)
		. '&t=' . rawurlencode($time)
		. '&limit=' . $limit
		. '&q=' . rawurlencode($query);
	$xml = reddit_xml($url, (string)$source['user_agent']);
	$patterns = is_array($source['title_patterns'] ?? null) ? $source['title_patterns'] : [];
	$threads = [];
	foreach (atom_entries($xml) as $entry) {
		$title = html_entity_decode(atom_text($entry, 'title'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$link = atom_link($entry);
		$matches = empty($patterns);
		foreach ($patterns as $pattern) {
			if (@preg_match((string)$pattern, $title) === 1) {
				$matches = true;
				break;
			}
		}
		if (!$matches || preg_match('~/comments/([a-z0-9]+)/~i', $link, $match) !== 1) {
			continue;
		}
		$threads[] = [
			'id' => $match[1],
			'title' => $title,
			'permalink' => parse_url($link, PHP_URL_PATH) ?: ('/r/' . $subreddit . '/comments/' . $match[1] . '/'),
			'link' => $link,
		];
		if (count($threads) >= max(1, min(10, (int)($source['max_threads'] ?? 2)))) {
			break;
		}
	}
	return $threads;
}

function flatten_comments(array $children, bool $includeReplies, array &$comments): void {
	foreach ($children as $child) {
		if (($child['kind'] ?? '') !== 't1' || !is_array($child['data'] ?? null)) {
			continue;
		}
		$data = $child['data'];
		$comments[] = $data;
		if ($includeReplies && is_array($data['replies'] ?? null)) {
			flatten_comments($data['replies']['data']['children'] ?? [], $includeReplies, $comments);
		}
	}
}

function thread_comments(array $source, array $thread): array {
	$subreddit = (string)$source['subreddit'];
	$path = (string)($thread['permalink'] ?? '');
	if ($path === '') {
		$path = '/r/' . $subreddit . '/comments/' . rawurlencode((string)$thread['id']) . '/';
	}
	$url = 'https://www.reddit.com' . rtrim($path, '/') . '.rss?sort=new&limit=500';
	$xml = reddit_xml($url, (string)$source['user_agent']);
	$comments = [];
	foreach (atom_entries($xml) as $entry) {
		$link = atom_link($entry);
		$id = '';
		if (preg_match('~/comments/[a-z0-9]+/[^/]+/([a-z0-9]+)/?~i', $link, $match) === 1) {
			$id = $match[1];
		} else {
			$id = sha1(atom_text($entry, 'id') . $link);
		}
		$published = strtotime(atom_text($entry, 'published')) ?: (strtotime(atom_text($entry, 'updated')) ?: time());
		$comments[] = [
			'id' => $id,
			'author' => atom_author($entry),
			'body' => compact_text(atom_text($entry, 'content'), 4000),
			'permalink' => parse_url($link, PHP_URL_PATH) ?: $path,
			'created_utc' => $published,
			'score' => 0,
		];
	}
	return $comments;
}

function comment_item_title(array $source, array $thread, array $comment): string {
	$subreddit = (string)$source['subreddit'];
	$author = (string)($comment['author'] ?? 'unknown');
	$body = compact_text((string)($comment['body'] ?? ''), 110);
	if ($body === '') {
		$body = compact_text((string)($thread['title'] ?? 'comment'), 90);
	}
	return '[r/' . $subreddit . ' comment] u/' . $author . ': ' . $body;
}

function valid_comment(array $comment, int $minScore): bool {
	$body = trim((string)($comment['body'] ?? ''));
	$author = (string)($comment['author'] ?? '');
	if ($body === '' || in_array($body, ['[deleted]', '[removed]'], true)) {
		return false;
	}
	if ($author === '' || in_array(strtolower($author), ['automoderator', '[deleted]'], true)) {
		return false;
	}
	return (int)($comment['score'] ?? 0) >= $minScore;
}

function build_feed(array $source): string {
	$now = time();
	$subreddit = (string)$source['subreddit'];
	$label = (string)($source['label'] ?? $source['id']);
	$title = 'Reddit Leads - comments - r/' . $subreddit . ' - ' . $label;
	$threads = source_threads($source);
	$items = [];
	$seen = [];
	$minScore = (int)($source['min_comment_score'] ?? 0);
	foreach ($threads as $thread) {
		foreach (thread_comments($source, $thread) as $comment) {
			$id = (string)($comment['id'] ?? '');
			if ($id === '' || isset($seen[$id]) || !valid_comment($comment, $minScore)) {
				continue;
			}
			$seen[$id] = true;
			$comment['rss_leads_thread'] = $thread;
			$items[] = $comment;
		}
	}
	usort($items, static fn(array $a, array $b): int => (int)($b['created_utc'] ?? 0) <=> (int)($a['created_utc'] ?? 0));
	$items = array_slice($items, 0, max(1, min(300, (int)($source['max_comments'] ?? 80))));

	$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
	$xml .= "<rss version=\"2.0\">\n\t<channel>\n";
	$xml .= "\t\t<title>" . rss_text($title) . "</title>\n";
	$xml .= "\t\t<link>" . rss_text('https://www.reddit.com/r/' . $subreddit . '/') . "</link>\n";
	$xml .= "\t\t<description>" . rss_text('Comments from recurring Reddit hiring threads.') . "</description>\n";
	$xml .= "\t\t<lastBuildDate>" . date(DATE_RSS, $now) . "</lastBuildDate>\n";

	foreach ($items as $item) {
		$thread = is_array($item['rss_leads_thread'] ?? null) ? $item['rss_leads_thread'] : [];
		$link = 'https://www.reddit.com' . (string)($item['permalink'] ?? ($thread['permalink'] ?? ''));
		$threadLink = 'https://www.reddit.com' . (string)($thread['permalink'] ?? '');
		$bodyHtml = nl2br(htmlspecialchars((string)($item['body'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$description = '<p><strong>Source thread:</strong> <a href="' . htmlspecialchars($threadLink, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . htmlspecialchars((string)($thread['title'] ?? $label), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a></p>'
			. '<p><strong>Subreddit:</strong> r/' . htmlspecialchars($subreddit, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' | <strong>Author:</strong> u/' . htmlspecialchars((string)($item['author'] ?? 'unknown'), ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' | <strong>Score:</strong> ' . (int)($item['score'] ?? 0) . '</p>'
			. $bodyHtml;
		$xml .= "\t\t<item>\n";
		$xml .= "\t\t\t<title>" . rss_text(comment_item_title($source, $thread, $item)) . "</title>\n";
		$xml .= "\t\t\t<link>" . rss_text($link) . "</link>\n";
		$xml .= "\t\t\t<guid isPermaLink=\"false\">" . rss_text('reddit-comment:' . (string)$item['id']) . "</guid>\n";
		$xml .= "\t\t\t<pubDate>" . date(DATE_RSS, (int)($item['created_utc'] ?? $now)) . "</pubDate>\n";
		$xml .= "\t\t\t<category>" . rss_text('reddit-comment-thread') . "</category>\n";
		$xml .= "\t\t\t<category>" . rss_text('r/' . $subreddit) . "</category>\n";
		$xml .= "\t\t\t<description>" . rss_cdata($description) . "</description>\n";
		$xml .= "\t\t</item>\n";
	}
	$xml .= "\t</channel>\n</rss>\n";
	return $xml;
}

$config = rss_leads_load_comment_thread_config();
$sources = rss_leads_comment_sources($config);
if (!isset($sources[$sourceId])) {
	http_response_code(404);
	echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"><channel><title>Unknown Reddit comment source</title></channel></rss>";
	exit;
}

$source = $sources[$sourceId];
$cacheSeconds = max(0, min(3600, (int)($source['cache_seconds'] ?? 300)));
$cacheDir = "/var/www/FreshRSS/data/users/{$freshRssUser}";
$cachePath = $cacheDir . '/rss_leads_reddit_comments_' . $sourceId . '.xml';
if ($cacheSeconds > 0 && is_readable($cachePath) && filemtime($cachePath) !== false && (time() - (int)filemtime($cachePath)) < $cacheSeconds) {
	readfile($cachePath);
	exit;
}

try {
	$xml = build_feed($source);
	if (is_dir($cacheDir)) {
		$tempPath = $cachePath . '.tmp.' . uniqid('', true);
		if (file_put_contents($tempPath, $xml) !== false) {
			rename($tempPath, $cachePath);
		}
	}
	echo $xml;
} catch (Throwable $e) {
	$title = 'Reddit Leads - comments - ' . $sourceId . ' unavailable';
	echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\">\n\t<channel>\n\t\t<title>" . rss_text($title) . "</title>\n\t\t<description>" . rss_text($e->getMessage()) . "</description>\n\t\t<lastBuildDate>" . date(DATE_RSS) . "</lastBuildDate>\n\t</channel>\n</rss>\n";
}
