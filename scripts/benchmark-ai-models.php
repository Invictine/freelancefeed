<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/RssLeads/Support.php';

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$apiKey = getenv('GEMINI_API_KEY') ?: '';
$judgeApiKey = getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_API_KEY') ?: (getenv('ANTIGRAVITY_API_KEY') ?: $apiKey);
$benchmarkPath = getenv('AI_BENCHMARK_STATE_FILE') ?: "/var/www/FreshRSS/data/users/{$user}/rss_leads_ai_benchmark.json";
$sampleSize = max(1, min(30, (int)(getenv('AI_BENCHMARK_SAMPLE_SIZE') ?: 8)));
$contentChars = max(200, min(2400, (int)(getenv('AI_BENCHMARK_CONTENT_CHARS') ?: 900)));
$candidateTimeoutSeconds = max(30, min(300, (int)(getenv('AI_BENCHMARK_CANDIDATE_TIMEOUT_SECONDS') ?: 180)));
$highIntelligenceModel = normalize_model_id(getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_MODEL') ?: (getenv('ANTIGRAVITY_JUDGE_MODEL') ?: 'gemini-3.1-pro-preview'));
$highIntelligenceAgent = trim((string)(getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_AGENT') ?: (getenv('ANTIGRAVITY_JUDGE_AGENT') ?: '')));
$highIntelligenceCli = trim((string)(getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_CLI') ?: (getenv('ANTIGRAVITY_CLI') ?: '')));
$highIntelligenceProvider = strtolower(trim((string)(getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_PROVIDER') ?: 'antigravity-sdk')));
$highIntelligenceFallbackModels = array_values(array_unique(array_filter(array_map(
	static fn(string $model): string => normalize_model_id($model),
	array_map('trim', explode(',', getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_FALLBACK_MODELS') ?: 'gemini-3.5-flash'))
))));
$sdkFlag = strtolower(trim((string)(getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_SDK') ?: '')));
$sdkDisabled = in_array($sdkFlag, ['0', 'false', 'no', 'off'], true);
$sdkEnabled = in_array($sdkFlag, ['1', 'true', 'yes', 'on'], true);
$useAntigravitySdk = !$sdkDisabled && (
	in_array($highIntelligenceProvider, ['antigravity', 'antigravity-sdk', 'sdk'], true)
	|| $sdkEnabled
);
$highIntelligenceSdkCommand = trim((string)(getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_SDK_COMMAND') ?: ''));
if ($useAntigravitySdk && $highIntelligenceSdkCommand === '') {
	$venvPython = '/opt/rss-leads-antigravity-venv/bin/python';
	$python = is_executable($venvPython) ? $venvPython : 'python3';
	$highIntelligenceSdkCommand = $python . ' /opt/rss-leads-stack/scripts/antigravity-high-intelligence-judge.py';
}
$benchmarkModels = getenv('AI_BENCHMARK_LOW_INTELLIGENCE_MODELS') ?: (getenv('AI_BENCHMARK_MODELS') ?: (getenv('AI_REFINE_MODELS') ?: (getenv('GEMINI_MODELS') ?: 'gemma4-31b,gemini-3.1-flash-lite,gemini-3-flash')));
$models = array_values(array_unique(array_filter(array_map(
	static fn(string $model): string => normalize_model_id($model),
	array_map('trim', explode(',', $benchmarkModels))
))));

if ($apiKey === '') {
	fwrite(STDERR, "GEMINI_API_KEY is required for candidate model benchmark calls.\n");
	exit(1);
}
if ($judgeApiKey === '' && $highIntelligenceCli === '' && $highIntelligenceSdkCommand === '') {
	fwrite(STDERR, "AI_BENCHMARK_HIGH_INTELLIGENCE_API_KEY, GEMINI_API_KEY, AI_BENCHMARK_HIGH_INTELLIGENCE_SDK_COMMAND, or AI_BENCHMARK_HIGH_INTELLIGENCE_CLI is required for quality judge calls.\n");
	exit(1);
}
if (empty($models)) {
	fwrite(STDERR, "AI_BENCHMARK_LOW_INTELLIGENCE_MODELS did not contain any usable model names.\n");
	exit(1);
}

function normalize_model_id(string $model): string {
	return RssLeadsModelIds::normalize($model);
}

function compact_text(string $html, int $limit): string {
	return RssLeadsText::compact($html, $limit);
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

function extract_json(string $text): mixed {
	$decoded = json_decode($text, true);
	if (is_array($decoded)) {
		return $decoded;
	}
	$length = strlen($text);
	for ($start = 0; $start < $length; $start++) {
		$open = $text[$start] ?? '';
		if ($open !== '[' && $open !== '{') {
			continue;
		}
		$close = $open === '[' ? ']' : '}';
		$depth = 0;
		$inString = false;
		$escaped = false;
		for ($i = $start; $i < $length; $i++) {
			$char = $text[$i];
			if ($inString) {
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === '"') {
				$inString = true;
				continue;
			}
			if ($char === $open) {
				$depth++;
			} elseif ($char === $close) {
				$depth--;
				if ($depth === 0) {
					$candidate = substr($text, $start, $i - $start + 1);
					$decoded = json_decode($candidate, true);
					if (is_array($decoded)) {
						return $decoded;
					}
					break;
				}
			}
		}
	}
	return null;
}

function extract_json_values(string $text): array {
	$values = [];
	$decoded = json_decode($text, true);
	if (is_array($decoded)) {
		$values[] = $decoded;
	}
	$length = strlen($text);
	for ($start = 0; $start < $length; $start++) {
		$open = $text[$start] ?? '';
		if ($open !== '[' && $open !== '{') {
			continue;
		}
		$close = $open === '[' ? ']' : '}';
		$depth = 0;
		$inString = false;
		$escaped = false;
		for ($i = $start; $i < $length; $i++) {
			$char = $text[$i];
			if ($inString) {
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === '"') {
				$inString = true;
				continue;
			}
			if ($char === $open) {
				$depth++;
			} elseif ($char === $close) {
				$depth--;
				if ($depth === 0) {
					$candidate = substr($text, $start, $i - $start + 1);
					$decoded = json_decode($candidate, true);
					if (is_array($decoded)) {
						$values[] = $decoded;
					}
					break;
				}
			}
		}
	}
	return $values;
}

function build_classification_payload(array $items, string $model): array {
	$prompt = "Classify each Reddit lead. Return only valid JSON, with no Markdown or explanation.\n"
		. "Return a bare JSON array. If you cannot return a bare array, return one object with key results containing the rows.\n"
		. "Required row fields: id, summary, monthly_amount, priority, job_type, scam_likelihood.\n"
		. "Every input item must have one output row with the same id. priority must be x_high, high, medium, low, or not_hiring. summary <= 20 words. scam_likelihood is 0-100.\n"
		. "monthly_amount is for medium, high, and x_high priority: estimate monthly USD value like \"$3,000/mo\" from budget, pay, rate, or salary. Convert hourly, weekly, annual, or project pay to monthly equivalent when possible. Use \"unknown\" when no money is stated. Use \"\" only for low or not_hiring.\n"
		. "Any real hiring post mentioning more than $5/hr or at least $200/month must be at least medium. high and x_high require known stated payment; if payment is unknown, use medium at most. x_high is reserved for exceptional paid buyer posts around $75/hr, $8,000/mo, $2,000/wk, $96,000/yr, $5,000 project budget, or very direct urgent AI automation, chatbot/LLM, web/app, workflow automation, or computer-vision work. Items: "
		. json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$payload = [
		'contents' => [[
			'parts' => [['text' => $prompt]],
		]],
		'generationConfig' => [
			'temperature' => 0,
			'maxOutputTokens' => 1024 + (count($items) * 220),
		],
	];
	if (strpos($model, 'gemma-') !== 0) {
		$payload['generationConfig']['response_mime_type'] = 'application/json';
		$payload['generationConfig']['response_schema'] = [
			'type' => 'ARRAY',
			'items' => [
				'type' => 'OBJECT',
				'properties' => [
					'id' => ['type' => 'STRING'],
					'summary' => ['type' => 'STRING'],
					'monthly_amount' => ['type' => 'STRING'],
					'priority' => ['type' => 'STRING', 'enum' => ['low', 'medium', 'high', 'x_high', 'not_hiring']],
					'job_type' => ['type' => 'STRING'],
					'scam_likelihood' => ['type' => 'INTEGER'],
				],
				'required' => ['id', 'summary', 'monthly_amount', 'priority', 'job_type', 'scam_likelihood'],
			],
		];
	}
	return $payload;
}

function is_benchmark_output_row(mixed $value): bool {
	return is_array($value)
		&& isset($value['id'])
		&& is_scalar($value['id'])
		&& isset($value['summary'])
		&& is_scalar($value['summary'])
		&& isset($value['priority'])
		&& is_scalar($value['priority']);
}

function normalize_benchmark_outputs(mixed $decodedOutput): array {
	if (!is_array($decodedOutput)) {
		return [];
	}
	if (is_benchmark_output_row($decodedOutput)) {
		return [$decodedOutput];
	}
	foreach (['results', 'items', 'classifications', 'outputs', 'leads', 'data'] as $key) {
		if (isset($decodedOutput[$key]) && is_array($decodedOutput[$key])) {
			$nested = normalize_benchmark_outputs($decodedOutput[$key]);
			if (!empty($nested)) {
				return $nested;
			}
		}
	}
	$rows = [];
	foreach ($decodedOutput as $key => $value) {
		if (!is_array($value)) {
			continue;
		}
		if (!isset($value['id']) && is_string($key) && preg_match('/^s\d+$/', $key) === 1) {
			$value['id'] = $key;
		}
		if (is_benchmark_output_row($value)) {
			$rows[] = $value;
			continue;
		}
		foreach (normalize_benchmark_outputs($value) as $row) {
			$rows[] = $row;
		}
	}
	return $rows;
}

function parsed_outputs_by_id(string $text, array $expectedIds): array {
	$expected = array_fill_keys($expectedIds, true);
	$best = [];
	$bestMatches = -1;
	foreach (extract_json_values($text) as $decodedOutput) {
		$rows = normalize_benchmark_outputs($decodedOutput);
		$outputs = [];
		$matches = 0;
		foreach ($rows as $item) {
			$id = (string)$item['id'];
			if ($id === '') {
				continue;
			}
			$outputs[$id] = $item;
			if (isset($expected[$id])) {
				$matches++;
			}
		}
		if ($matches > $bestMatches || ($matches === $bestMatches && count($outputs) > count($best))) {
			$best = $outputs;
			$bestMatches = $matches;
		}
	}
	return $best;
}

function local_validity_judge(array $items, array $outputsById): array {
	$expectedIds = array_values(array_map(static fn(array $item): string => (string)$item['id'], $items));
	$expected = array_fill_keys($expectedIds, true);
	$total = max(1, count($expectedIds));
	$validPriorities = ['x_high' => true, 'high' => true, 'medium' => true, 'low' => true, 'not_hiring' => true];
	$rowScores = [];
	$priorityScores = [];
	$summaryScores = [];
	$scamScores = [];
	foreach ($expectedIds as $id) {
		$row = $outputsById[$id] ?? null;
		if (!is_array($row)) {
			$rowScores[] = 0;
			$priorityScores[] = 0;
			$summaryScores[] = 0;
			$scamScores[] = 0;
			continue;
		}
		$priority = strtolower((string)($row['priority'] ?? ''));
		$summary = compact_text((string)($row['summary'] ?? ''), 240);
		$scam = $row['scam_likelihood'] ?? null;
		$monthlyAmount = trim((string)($row['monthly_amount'] ?? ''));
		$jobType = trim((string)($row['job_type'] ?? ''));
		$summaryWords = preg_split('/\s+/u', trim($summary)) ?: [];
		$priorityOk = isset($validPriorities[$priority]) ? 10 : 0;
		$summaryOk = $summary !== '' ? ($summaryWords && count($summaryWords) <= 24 ? 10 : 7) : 0;
		$scamOk = is_numeric($scam) && (int)$scam >= 0 && (int)$scam <= 100 ? 10 : 0;
		$monthlyKnown = $monthlyAmount !== '' && strtolower($monthlyAmount) !== 'unknown';
		$monthlyOk = in_array($priority, ['high', 'x_high'], true)
			? ($monthlyKnown ? 10 : 0)
			: (in_array($priority, ['medium'], true) || $monthlyAmount === '' ? 10 : 6);
		$jobOk = $priority === 'not_hiring' || $jobType !== '' ? 10 : 6;
		$priorityScores[] = $priorityOk;
		$summaryScores[] = $summaryOk;
		$scamScores[] = $scamOk;
		$rowScores[] = ($priorityOk + $summaryOk + $scamOk + $monthlyOk + $jobOk) / 5;
	}
	$avg = static fn(array $values): float => count($values) ? array_sum($values) / count($values) : 0.0;
	$unexpected = array_diff(array_keys($outputsById), array_keys($expected));
	$coveragePenalty = count($unexpected) > 0 ? min(2, count($unexpected) / $total) : 0;
	$overall = max(0, $avg($rowScores) - $coveragePenalty);
	return [
		'overall_quality' => round($overall, 2),
		'priority_score' => round($avg($priorityScores), 2),
		'summary_score' => round($avg($summaryScores), 2),
		'scam_score' => round($avg($scamScores), 2),
		'notes' => 'High-intelligence judge unavailable; using local structural validity fallback, not semantic quality.',
		'judge_model_used' => 'local-validity',
	];
}

function call_generate_content(string $apiKey, string $model, array $payload, int $timeoutSeconds = 180): array {
	$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
	$started = microtime(true);
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => $timeoutSeconds,
	]);
	$raw = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$error = curl_error($ch);
	curl_close($ch);
	$decoded = is_string($raw) ? json_decode($raw, true) : null;
	$usage = is_array($decoded) && is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [];
	return [
		'status' => $status,
		'raw' => is_string($raw) ? $raw : '',
		'error' => $error,
		'latency_ms' => (int)round((microtime(true) - $started) * 1000),
		'tokens' => [
			'prompt' => (int)($usage['promptTokenCount'] ?? 0),
			'candidate' => (int)($usage['candidatesTokenCount'] ?? 0),
			'total' => (int)($usage['totalTokenCount'] ?? 0),
		],
	];
}

function interaction_text(array $response): string {
	if (isset($response['output_text']) && is_string($response['output_text'])) {
		return $response['output_text'];
	}
	if (isset($response['outputText']) && is_string($response['outputText'])) {
		return $response['outputText'];
	}
	$text = '';
	foreach ($response['steps'] ?? [] as $step) {
		foreach ($step['content'] ?? [] as $part) {
			if (isset($part['text']) && is_string($part['text'])) {
				$text .= $part['text'];
			}
		}
	}
	foreach ($response['output'] ?? [] as $part) {
		if (isset($part['text']) && is_string($part['text'])) {
			$text .= $part['text'];
		}
	}
	return $text;
}

function call_high_intelligence_cli(string $command, string $prompt, int $timeoutSeconds = 180): array {
	$started = microtime(true);
	$descriptorSpec = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	$process = proc_open($command, $descriptorSpec, $pipes);
	if (!is_resource($process)) {
		return [
			'status' => 127,
			'error' => 'Unable to start high-intelligence CLI command.',
			'latency_ms' => (int)round((microtime(true) - $started) * 1000),
			'score' => null,
			'raw_excerpt' => '',
			'fallback' => 'cli',
		];
	}
	fwrite($pipes[0], $prompt);
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$stdout = '';
	$stderr = '';
	$timedOut = false;
	do {
		$stdout .= stream_get_contents($pipes[1]);
		$stderr .= stream_get_contents($pipes[2]);
		$status = proc_get_status($process);
		if (!$status['running']) {
			break;
		}
		if ((microtime(true) - $started) > $timeoutSeconds) {
			$timedOut = true;
			proc_terminate($process);
			break;
		}
		usleep(100000);
	} while (true);
	$stdout .= stream_get_contents($pipes[1]);
	$stderr .= stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);
	$score = extract_json($stdout);
	return [
		'status' => $timedOut ? 124 : (int)$exitCode,
		'error' => $timedOut ? 'High-intelligence CLI timed out.' : trim($stderr),
		'latency_ms' => (int)round((microtime(true) - $started) * 1000),
		'score' => is_array($score) ? $score : null,
		'raw_excerpt' => substr($stdout !== '' ? $stdout : $stderr, 0, 500),
		'fallback' => 'cli',
	];
}

function call_judge(string $apiKey, string $judgeModel, string $judgeAgent, string $highIntelligenceCli, string $highIntelligenceSdkCommand, array $items, array $outputsById, string $candidateModel): array {
	$prompt = "Evaluate this Reddit lead classifier output. Return JSON object only with keys overall_quality, priority_score, summary_score, scam_score, notes.\n"
		. "Scores are 0-10. Reward concise useful summaries, correct hiring/not_hiring separation, reasonable priority, reusable job_type, and calibrated scam_likelihood.\n"
		. "Priority rules: any real hiring post with stated pay above $5/hr or at least $200/month must be at least medium; high and x_high require known stated payment after conversion; unknown-payment posts must not be high or x_high. Penalize stale JSON, missing ids, empty summaries, and high/x_high with unknown monthly_amount.\n"
		. "Input items: " . json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
		. "Candidate model: " . $candidateModel . "\n"
		. "Candidate output: " . json_encode(array_values($outputsById), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if ($highIntelligenceSdkCommand !== '') {
		$result = call_high_intelligence_cli($highIntelligenceSdkCommand, $prompt, 240);
		$result['fallback'] = 'antigravity-sdk';
		return $result;
	}
	if ($highIntelligenceCli !== '') {
		return call_high_intelligence_cli($highIntelligenceCli, $prompt);
	}
	$payload = $judgeAgent !== ''
		? [
			'agent' => $judgeAgent,
			'input' => $prompt,
			'environment' => 'remote',
		]
		: [
			'model' => $judgeModel,
			'system_instruction' => 'You are a strict evaluator for lead-classification quality. Return valid compact JSON only.',
			'input' => $prompt,
			'generation_config' => [
				'temperature' => 0,
				'max_output_tokens' => 900,
			],
		];
	$started = microtime(true);
	$ch = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => [
			'Content-Type: application/json',
			'x-goog-api-key: ' . $apiKey,
		],
		CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 180,
	]);
	$raw = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$error = curl_error($ch);
	curl_close($ch);
	$text = '';
	$decodedRaw = is_string($raw) ? json_decode($raw, true) : null;
	if (is_array($decodedRaw)) {
		$text = interaction_text($decodedRaw);
	}
	$score = is_string($raw) ? extract_json($text !== '' ? $text : $raw) : null;
	if ($judgeAgent === '' && ($status === 404 || $status === 400) && $judgeModel !== '') {
		$fallback = call_generate_content($apiKey, $judgeModel, [
			'systemInstruction' => [
				'parts' => [['text' => 'You are a strict evaluator for lead-classification quality. Return valid compact JSON only.']],
			],
			'contents' => [[
				'parts' => [['text' => $prompt]],
			]],
			'generationConfig' => [
				'temperature' => 0,
				'maxOutputTokens' => 900,
				'response_mime_type' => 'application/json',
			],
		]);
		$fallbackResponse = json_decode($fallback['raw'], true);
		$fallbackText = is_array($fallbackResponse) ? gemini_text($fallbackResponse) : '';
		$fallbackScore = extract_json($fallbackText !== '' ? $fallbackText : $fallback['raw']);
		return [
			'status' => (int)$fallback['status'],
			'error' => (string)$fallback['error'],
			'latency_ms' => (int)$fallback['latency_ms'],
			'score' => is_array($fallbackScore) ? $fallbackScore : null,
			'raw_excerpt' => substr((string)$fallback['raw'], 0, 500),
			'fallback' => 'generateContent',
		];
	}
	return [
		'status' => $status,
		'error' => $error,
		'latency_ms' => (int)round((microtime(true) - $started) * 1000),
		'score' => is_array($score) ? $score : null,
		'raw_excerpt' => substr(is_string($raw) ? $raw : '', 0, 500),
	];
}

$db = RssLeadsDb::sqlite($dbPath);
$entryIds = array_values(array_filter(array_map('trim', explode(',', getenv('AI_BENCHMARK_ENTRY_IDS') ?: '')), static fn(string $id): bool => preg_match('/^\d+$/', $id) === 1));
if (!empty($entryIds)) {
	$entryIds = array_slice(array_unique($entryIds), 0, $sampleSize);
	$placeholders = implode(',', array_fill(0, count($entryIds), '?'));
	$stmt = $db->prepare("SELECT e.id, e.title, e.content, e.link, e.date, f.name AS feed_name
		FROM entry e
		JOIN feed f ON f.id = e.id_feed
		WHERE e.id IN ($placeholders)
		ORDER BY e.date DESC");
	$stmt->execute($entryIds);
} else {
	$stmt = $db->prepare('SELECT e.id, e.title, e.content, e.link, e.date, f.name AS feed_name
		FROM entry e
		JOIN feed f ON f.id = e.id_feed
		WHERE (f.name LIKE "Reddit Leads%" OR e.link LIKE "%reddit.com/r/%")
		  AND f.name NOT IN ("High Priority Reddit Leads", "High Reddit Leads", "High + X-High Reddit Leads", "Medium Priority Reddit Leads", "Low-Medium Reddit Leads", "Low Priority Reddit Leads", "Not Hiring Reddit Leads")
		ORDER BY e.date DESC
		LIMIT :limit');
	$stmt->bindValue(':limit', $sampleSize, PDO::PARAM_INT);
	$stmt->execute();
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$items = [];
$n = 1;
foreach ($rows as $row) {
	$items[] = [
		'id' => 's' . $n++,
		'entry_id' => (string)$row['id'],
		'title' => compact_text((string)$row['title'], 180),
		'text' => compact_text((string)$row['content'], $contentChars),
		'link' => (string)$row['link'],
	];
}
if (empty($items)) {
	fwrite(STDERR, "No Reddit lead entries found for benchmark.\n");
	exit(1);
}

$report = [
	'generated_at' => time(),
	'sample_size' => count($items),
	'low_intelligence_models' => $models,
	'candidate_models' => $models,
	'high_intelligence_model' => $highIntelligenceCli === '' && $highIntelligenceAgent === '' ? $highIntelligenceModel : '',
	'high_intelligence_agent' => $highIntelligenceAgent,
	'high_intelligence_cli' => $highIntelligenceCli,
	'high_intelligence_provider' => $highIntelligenceSdkCommand !== '' ? 'antigravity-sdk' : ($highIntelligenceProvider ?: 'api'),
	'high_intelligence_sdk_command' => $highIntelligenceSdkCommand,
	'high_intelligence_fallback_models' => $highIntelligenceFallbackModels,
	'judge_model' => $highIntelligenceCli === '' && $highIntelligenceAgent === '' ? $highIntelligenceModel : '',
	'judge_agent' => $highIntelligenceAgent,
	'models' => [],
	'items' => $items,
];
$expectedIds = array_values(array_map(static fn(array $item): string => (string)$item['id'], $items));

foreach ($models as $model) {
	$attempt = call_generate_content($apiKey, $model, build_classification_payload($items, $model), $candidateTimeoutSeconds);
	$outputs = [];
	$outputText = '';
	if ($attempt['status'] >= 200 && $attempt['status'] < 300 && $attempt['raw'] !== '') {
		$decodedResponse = json_decode($attempt['raw'], true);
		$outputText = is_array($decodedResponse) ? gemini_text($decodedResponse) : '';
		$outputs = parsed_outputs_by_id($outputText !== '' ? $outputText : $attempt['raw'], $expectedIds);
	}
	$judge = !empty($outputs)
		? call_judge($judgeApiKey, $highIntelligenceModel, $highIntelligenceAgent, $highIntelligenceCli, $highIntelligenceSdkCommand, $items, $outputs, $model)
		: ['status' => 0, 'error' => 'No candidate output to judge', 'latency_ms' => 0, 'score' => null, 'raw_excerpt' => ''];
	$score = is_array($judge['score'] ?? null) ? $judge['score'] : [];
	$judgeStatus = (int)($judge['status'] ?? 0);
	$hasJudgeScore = isset($score['overall_quality']) || isset($score['priority_score']) || isset($score['summary_score']) || isset($score['scam_score']);
	$usedLocalJudgeFallback = false;
	if (!$hasJudgeScore && !empty($outputs)) {
		$score = local_validity_judge($items, $outputs);
		$hasJudgeScore = true;
		$usedLocalJudgeFallback = true;
	}
	$judgeFailureNote = !$hasJudgeScore && $judgeStatus !== 0
		? 'High-intelligence judge failed status ' . $judgeStatus . '; check AI_BENCHMARK_HIGH_INTELLIGENCE_PROVIDER, AI_BENCHMARK_HIGH_INTELLIGENCE_MODEL, AI_BENCHMARK_HIGH_INTELLIGENCE_AGENT, AI_BENCHMARK_HIGH_INTELLIGENCE_SDK_COMMAND, or AI_BENCHMARK_HIGH_INTELLIGENCE_CLI.'
		: '';
	$success = count($outputs);
	$parseNote = $attempt['status'] >= 200 && $attempt['status'] < 300 && $success === 0
		? 'HTTP 200 but no benchmark rows could be parsed from candidate output.'
		: '';
	$scoreNotes = trim((string)($score['notes'] ?? ''));
	if ($scoreNotes === '') {
		$scoreNotes = $judgeFailureNote !== '' ? $judgeFailureNote : $parseNote;
	}
	$report['models'][] = [
		'model' => $model,
		'success' => $success,
		'failed' => max(0, count($items) - $success),
		'latency_ms' => (int)$attempt['latency_ms'],
		'avg_latency_ms' => $success > 0 ? (int)round($attempt['latency_ms'] / $success) : 0,
		'http_status' => (int)$attempt['status'],
		'error' => (string)$attempt['error'],
		'prompt_tokens' => (int)($attempt['tokens']['prompt'] ?? 0),
		'candidate_tokens' => (int)($attempt['tokens']['candidate'] ?? 0),
		'total_tokens' => (int)($attempt['tokens']['total'] ?? 0),
		'tokens_per_item' => count($items) > 0 ? round(((int)($attempt['tokens']['total'] ?? 0)) / count($items), 1) : 0,
		'output_excerpt' => compact_text($outputText !== '' ? $outputText : (string)$attempt['raw'], 700),
		'parse_note' => $parseNote,
		'avg_quality' => round((float)($score['overall_quality'] ?? 0), 2),
		'avg_priority_score' => round((float)($score['priority_score'] ?? 0), 2),
		'avg_summary_score' => round((float)($score['summary_score'] ?? 0), 2),
		'avg_scam_score' => round((float)($score['scam_score'] ?? 0), 2),
		'judge_status' => $judgeStatus,
		'judge_latency_ms' => (int)($judge['latency_ms'] ?? 0),
		'judge_error' => (string)($judge['error'] ?? ''),
		'judge_fallback' => $usedLocalJudgeFallback ? 'local-validity' : (string)($judge['fallback'] ?? ''),
		'judge_model_used' => (string)($score['judge_model_used'] ?? ''),
		'notes' => compact_text($scoreNotes, 220),
	];
	echo $model . ': success=' . $success . '/' . count($items)
		. ' avg_latency_ms=' . ($success > 0 ? (int)round($attempt['latency_ms'] / $success) : 0)
		. ' quality=' . round((float)($score['overall_quality'] ?? 0), 2)
		. "\n";
}

usort($report['models'], static function (array $left, array $right): int {
	$quality = ((float)($right['avg_quality'] ?? 0)) <=> ((float)($left['avg_quality'] ?? 0));
	if ($quality !== 0) {
		return $quality;
	}
	return ((int)($left['avg_latency_ms'] ?? 0)) <=> ((int)($right['avg_latency_ms'] ?? 0));
});

$dir = dirname($benchmarkPath);
if (!is_dir($dir)) {
	fwrite(STDERR, "Benchmark directory does not exist: {$dir}\n");
	exit(1);
}
file_put_contents($benchmarkPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "Saved benchmark report to {$benchmarkPath}\n";
