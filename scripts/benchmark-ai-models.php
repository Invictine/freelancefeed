<?php
declare(strict_types=1);

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$apiKey = getenv('GEMINI_API_KEY') ?: '';
$judgeApiKey = getenv('AI_BENCHMARK_HIGH_INTELLIGENCE_API_KEY') ?: (getenv('ANTIGRAVITY_API_KEY') ?: $apiKey);
$benchmarkPath = getenv('AI_BENCHMARK_STATE_FILE') ?: "/var/www/FreshRSS/data/users/{$user}/rss_leads_ai_benchmark.json";
$sampleSize = max(1, min(30, (int)(getenv('AI_BENCHMARK_SAMPLE_SIZE') ?: 8)));
$contentChars = max(200, min(2400, (int)(getenv('AI_BENCHMARK_CONTENT_CHARS') ?: 900)));
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
	$model = trim($model);
	$aliases = [
		'gemini-3.1-pro' => 'gemini-3.1-pro-preview',
		'gemini3.1pro' => 'gemini-3.1-pro-preview',
		'gemini-3.1-pro-preview' => 'gemini-3.1-pro-preview',
		'gemini3.1propreview' => 'gemini-3.1-pro-preview',
		'gemini-3.1-flash' => 'gemini-3-flash-preview',
		'gemini3.1flash' => 'gemini-3-flash-preview',
		'gemini-3-flash' => 'gemini-3-flash-preview',
		'gemini3flash' => 'gemini-3-flash-preview',
		'gemini-3.1-flash-lite' => 'gemini-3.1-flash-lite',
		'gemini3.1flashlite' => 'gemini-3.1-flash-lite',
		'gemma4b' => 'gemma-4-31b-it',
		'gemma-4b' => 'gemma-4-31b-it',
		'gemma-4-b' => 'gemma-4-31b-it',
		'gemma4-31b' => 'gemma-4-31b-it',
		'gemma-4-31b' => 'gemma-4-31b-it',
		'gemma31b' => 'gemma-4-31b-it',
		'gemma4-26b' => 'gemma-4-26b-a4b-it',
		'gemma-4-26b' => 'gemma-4-26b-a4b-it',
	];
	$key = strtolower(str_replace([' ', '_'], ['', '-'], $model));
	return $aliases[$key] ?? $model;
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

function build_classification_payload(array $items, string $model): array {
	$prompt = "Classify each Reddit lead. Return JSON array only with id, summary, monthly_amount, priority, job_type, and scam_likelihood.\n"
		. "priority must be high, medium, low, or not_hiring. summary <= 20 words. scam_likelihood is 0-100.\n"
		. "monthly_amount is only for high priority; use \"\" for non-high. Items: "
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
					'priority' => ['type' => 'STRING', 'enum' => ['low', 'medium', 'high', 'not_hiring']],
					'job_type' => ['type' => 'STRING'],
					'scam_likelihood' => ['type' => 'INTEGER'],
				],
				'required' => ['id', 'summary', 'monthly_amount', 'priority', 'job_type', 'scam_likelihood'],
			],
		];
	}
	return $payload;
}

function call_generate_content(string $apiKey, string $model, array $payload): array {
	$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
	$started = microtime(true);
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 90,
	]);
	$raw = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$error = curl_error($ch);
	curl_close($ch);
	return [
		'status' => $status,
		'raw' => is_string($raw) ? $raw : '',
		'error' => $error,
		'latency_ms' => (int)round((microtime(true) - $started) * 1000),
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

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
		  AND f.name != "High Priority Reddit Leads"
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

foreach ($models as $model) {
	$attempt = call_generate_content($apiKey, $model, build_classification_payload($items, $model));
	$outputs = [];
	if ($attempt['status'] >= 200 && $attempt['status'] < 300 && $attempt['raw'] !== '') {
		$decodedResponse = json_decode($attempt['raw'], true);
		$text = is_array($decodedResponse) ? gemini_text($decodedResponse) : '';
		$decodedOutput = extract_json($text);
		if (is_array($decodedOutput)) {
			foreach ($decodedOutput as $item) {
				if (is_array($item) && isset($item['id'])) {
					$outputs[(string)$item['id']] = $item;
				}
			}
		}
	}
	$judge = !empty($outputs)
		? call_judge($judgeApiKey, $highIntelligenceModel, $highIntelligenceAgent, $highIntelligenceCli, $highIntelligenceSdkCommand, $items, $outputs, $model)
		: ['status' => 0, 'error' => 'No candidate output to judge', 'latency_ms' => 0, 'score' => null, 'raw_excerpt' => ''];
	$score = is_array($judge['score'] ?? null) ? $judge['score'] : [];
	$judgeStatus = (int)($judge['status'] ?? 0);
	$hasJudgeScore = isset($score['overall_quality']) || isset($score['priority_score']) || isset($score['summary_score']) || isset($score['scam_score']);
	$judgeFailureNote = !$hasJudgeScore && $judgeStatus !== 0
		? 'High-intelligence judge failed status ' . $judgeStatus . '; check AI_BENCHMARK_HIGH_INTELLIGENCE_PROVIDER, AI_BENCHMARK_HIGH_INTELLIGENCE_MODEL, AI_BENCHMARK_HIGH_INTELLIGENCE_AGENT, AI_BENCHMARK_HIGH_INTELLIGENCE_SDK_COMMAND, or AI_BENCHMARK_HIGH_INTELLIGENCE_CLI.'
		: '';
	$success = count($outputs);
	$report['models'][] = [
		'model' => $model,
		'success' => $success,
		'failed' => max(0, count($items) - $success),
		'latency_ms' => (int)$attempt['latency_ms'],
		'avg_latency_ms' => $success > 0 ? (int)round($attempt['latency_ms'] / $success) : 0,
		'http_status' => (int)$attempt['status'],
		'error' => (string)$attempt['error'],
		'avg_quality' => round((float)($score['overall_quality'] ?? 0), 2),
		'avg_priority_score' => round((float)($score['priority_score'] ?? 0), 2),
		'avg_summary_score' => round((float)($score['summary_score'] ?? 0), 2),
		'avg_scam_score' => round((float)($score['scam_score'] ?? 0), 2),
		'judge_status' => $judgeStatus,
		'judge_latency_ms' => (int)($judge['latency_ms'] ?? 0),
		'judge_error' => (string)($judge['error'] ?? ''),
		'judge_fallback' => (string)($judge['fallback'] ?? ''),
		'judge_model_used' => (string)($score['judge_model_used'] ?? ''),
		'notes' => compact_text((string)($score['notes'] ?? $judgeFailureNote), 220),
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
