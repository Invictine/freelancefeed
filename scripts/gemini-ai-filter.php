<?php
declare(strict_types=1);

$user = getenv('FRESHRSS_USER') ?: 'invictine';
$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$apiKey = getenv('GEMINI_API_KEY') ?: '';
$model = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite';
$models = array_values(array_filter(array_map('trim', explode(',', getenv('GEMINI_MODELS') ?: $model . ',gemini-2.5-flash,gemini-flash-latest'))));
$batchSize = max(1, min(20, (int)(getenv('AI_FILTER_BATCH_SIZE') ?: 8)));
$contentChars = max(200, min(2400, (int)(getenv('AI_FILTER_CONTENT_CHARS') ?: 900)));
$lookbackDays = max(1, min(90, (int)(getenv('AI_FILTER_LOOKBACK_DAYS') ?: 14)));

if ($apiKey === '') {
	fwrite(STDERR, "GEMINI_API_KEY is not set; skipping AI filter.\n");
	exit(0);
}

function compact_text(string $html, int $limit): string {
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
	$text = trim($text);
	if (mb_strlen($text, 'UTF-8') > $limit) {
		$text = mb_substr($text, 0, $limit, 'UTF-8');
	}
	return $text;
}

function subreddit_from_url(string $url): string {
	if (preg_match('~reddit\.com/r/([A-Za-z0-9_]{2,21})~i', $url, $match)) {
		return $match[1];
	}
	return '';
}

function gemini_text(array $response): string {
	$text = '';
	foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
		if (isset($part['text']) && is_string($part['text'])) {
			$text .= $part['text'];
		}
	}
	return $text;
}

function decode_json_array(string $text): ?array {
	$decoded = json_decode($text, true);
	if (is_array($decoded)) {
		return $decoded;
	}

	$start = strpos($text, '[');
	$end = strrpos($text, ']');
	if ($start !== false && $end !== false && $end > $start) {
		$decoded = json_decode(substr($text, $start, $end - $start + 1), true);
		if (is_array($decoded)) {
			return $decoded;
		}
	}
	return null;
}

function call_gemini(string $apiKey, string $model, array $payload): array {
	$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 45,
	]);
	$raw = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);

	return [
		'model' => $model,
		'raw' => is_string($raw) ? $raw : '',
		'status' => $status,
		'error' => $curlError,
	];
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
	'CREATE TABLE IF NOT EXISTS rss_leads_ai (
		entry_id INTEGER PRIMARY KEY,
		link TEXT NOT NULL,
		summary TEXT NOT NULL,
		priority TEXT NOT NULL CHECK(priority IN ("low", "medium", "high")),
		model TEXT NOT NULL,
		input_hash TEXT NOT NULL,
		created_at INTEGER NOT NULL,
		updated_at INTEGER NOT NULL
	)'
);
$db->exec('CREATE INDEX IF NOT EXISTS idx_rss_leads_ai_priority ON rss_leads_ai(priority, updated_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_rss_leads_ai_link ON rss_leads_ai(link)');

$since = time() - ($lookbackDays * 86400);
$stmt = $db->prepare(
	'SELECT e.id, e.title, e.content, e.link, e.date, f.name AS feed_name
	 FROM entry e
	 JOIN feed f ON f.id = e.id_feed
	 LEFT JOIN rss_leads_ai ai ON ai.entry_id = e.id
	 WHERE ai.entry_id IS NULL
	   AND e.date >= :since
	   AND (f.name LIKE "Reddit Leads%" OR e.link LIKE "%reddit.com/r/%")
	 ORDER BY e.date DESC
	 LIMIT :limit'
);
$stmt->bindValue(':since', $since, PDO::PARAM_INT);
$stmt->bindValue(':limit', $batchSize * 2, PDO::PARAM_INT);
$stmt->execute();

$groups = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$link = (string)$row['link'];
	if (!isset($groups[$link])) {
		$groups[$link] = [
			'ids' => [],
			'title' => compact_text((string)$row['title'], 180),
			'subreddit' => subreddit_from_url($link),
			'text' => compact_text((string)$row['content'], $contentChars),
			'link' => $link,
		];
	}
	$groups[$link]['ids'][] = (int)$row['id'];
	if (count($groups) >= $batchSize) {
		break;
	}
}

if (empty($groups)) {
	echo "No unanalyzed recent Reddit entries.\n";
	exit(0);
}

$items = [];
$idToLink = [];
$n = 1;
foreach ($groups as $link => $group) {
	$id = 'p' . $n++;
	$idToLink[$id] = $link;
	$items[] = [
		'id' => $id,
		'sr' => $group['subreddit'],
		'title' => $group['title'],
		'text' => $group['text'],
	];
}

$system = 'Score Reddit leads. Output JSON array only. Each item: id, summary<=20 words one sentence, priority low|medium|high. Priority by urgency and money: high=paid+urgent/budget/ready; medium=paid/no urgency or urgent/no budget; low=unpaid/vague/seller/discussion.';
$payload = [
	'systemInstruction' => [
		'parts' => [['text' => $system]],
	],
	'contents' => [[
		'parts' => [[
			'text' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]],
	]],
	'generationConfig' => [
		'temperature' => 0,
		'maxOutputTokens' => 128 + ($batchSize * 64),
		'response_mime_type' => 'application/json',
		'response_schema' => [
			'type' => 'ARRAY',
			'items' => [
				'type' => 'OBJECT',
				'properties' => [
					'id' => ['type' => 'STRING'],
					'summary' => ['type' => 'STRING'],
					'priority' => [
						'type' => 'STRING',
						'enum' => ['low', 'medium', 'high'],
					],
				],
				'required' => ['id', 'summary', 'priority'],
			],
		],
	],
];

$attempt = null;
$results = null;
foreach ($models as $candidateModel) {
	$attempt = call_gemini($apiKey, $candidateModel, $payload);
	if ($attempt['raw'] !== '' && $attempt['status'] >= 200 && $attempt['status'] < 300) {
		$response = json_decode($attempt['raw'], true);
		$text = is_array($response) ? gemini_text($response) : '';
		$decoded = decode_json_array($text);
		if (is_array($decoded)) {
			$model = $candidateModel;
			$results = $decoded;
			break;
		}
		fwrite(STDERR, "Gemini returned non-JSON model={$candidateModel} result=" . mb_substr($text, 0, 500, 'UTF-8') . "\n");
		continue;
	}
	fwrite(STDERR, "Gemini request failed model={$candidateModel} status={$attempt['status']} error={$attempt['error']} body={$attempt['raw']}\n");
}

if ($results === null) {
	fwrite(STDERR, "All Gemini model attempts failed.\n");
	exit(1);
}

$upsert = $db->prepare(
	'INSERT INTO rss_leads_ai (entry_id, link, summary, priority, model, input_hash, created_at, updated_at)
	 VALUES (:entry_id, :link, :summary, :priority, :model, :input_hash, :created_at, :updated_at)
	 ON CONFLICT(entry_id) DO UPDATE SET
		link = excluded.link,
		summary = excluded.summary,
		priority = excluded.priority,
		model = excluded.model,
		input_hash = excluded.input_hash,
		updated_at = excluded.updated_at'
);

$now = time();
$saved = 0;
foreach ($results as $result) {
	$id = is_array($result) ? (string)($result['id'] ?? '') : '';
	$link = $idToLink[$id] ?? null;
	if ($link === null) {
		continue;
	}
	$summary = compact_text((string)($result['summary'] ?? ''), 180);
	$priority = strtolower((string)($result['priority'] ?? 'low'));
	if (!in_array($priority, ['low', 'medium', 'high'], true) || $summary === '') {
		continue;
	}
	$hash = hash('sha256', json_encode($groups[$link], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	foreach ($groups[$link]['ids'] as $entryId) {
		$upsert->execute([
			':entry_id' => $entryId,
			':link' => $link,
			':summary' => $summary,
			':priority' => $priority,
			':model' => $model,
			':input_hash' => $hash,
			':created_at' => $now,
			':updated_at' => $now,
		]);
		$saved++;
	}
}

echo "Saved {$saved} AI classifications.\n";
