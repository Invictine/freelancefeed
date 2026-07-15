<?php
declare(strict_types=1);

class GeminiClient {
	private string $apiKey;
	private int $quotaCooldownSeconds;
	private int $intervalSeconds;

	public function __construct(string $apiKey, int $quotaCooldownSeconds, int $intervalSeconds) {
		$this->apiKey = $apiKey;
		$this->quotaCooldownSeconds = $quotaCooldownSeconds;
		$this->intervalSeconds = $intervalSeconds;
	}

	public function call(string $model, array $payload): array {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);
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
		$response = is_string($raw) ? json_decode($raw, true) : null;
		$usage = is_array($response) && is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];

		return [
			'model' => $model,
			'raw' => is_string($raw) ? $raw : '',
			'status' => $status,
			'error' => $curlError,
			'usage' => [
				'prompt_tokens' => (int)($usage['promptTokenCount'] ?? 0),
				'cached_tokens' => (int)($usage['cachedContentTokenCount'] ?? 0),
				'candidate_tokens' => (int)($usage['candidatesTokenCount'] ?? 0),
				'tool_use_prompt_tokens' => (int)($usage['toolUsePromptTokenCount'] ?? 0),
				'thoughts_tokens' => (int)($usage['thoughtsTokenCount'] ?? 0),
				'total_tokens' => (int)($usage['totalTokenCount'] ?? 0),
				'service_tier' => (string)($usage['serviceTier'] ?? ''),
			],
		];
	}

	public static function extractText(array $response): string {
		$text = '';
		foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
			if (isset($part['text']) && is_string($part['text'])) {
				$text .= $part['text'];
			}
		}
		return $text;
	}

	public static function parseRetryDelay(string $raw): ?int {
		$response = json_decode($raw, true);
		if (!is_array($response)) {
			return null;
		}
		foreach ($response['error']['details'] ?? [] as $detail) {
			$retryDelay = $detail['retryDelay'] ?? null;
			if (is_string($retryDelay) && preg_match('/^(\d+)s$/', $retryDelay, $match)) {
				return (int)$match[1];
			}
		}
		return null;
	}

	public static function buildPayload(array $items, string $model, array $jobTypeOptions): array {
		$maxOutputTokens = 160 + (count($items) * 128);
		if (strpos($model, 'gemma-') === 0) {
			$gemmaOutputTokens = 1024 + (count($items) * 320);
			return [
				'contents' => [[
					'parts' => [[
						'text' => "Classify each Reddit lead. Return only valid JSON, with no Markdown or explanation.\nReturn a bare JSON array. Required row fields: id, summary, monthly_amount, priority, job_type, cv_fit, scam_likelihood.\nEvery input item must have one output row with the same id. summary must be 20 words or fewer. cv_fit must be low, high, or extreme by comparing the role with cv_profile_for_all_items when supplied: low means weak/no demonstrated match, high means the portfolio directly demonstrates the core work, extreme means an unusually exact match with multiple directly relevant capabilities or proof points.\nUse these exact deterministic tiers: when cv_profile_for_all_items is supplied, high or extreme cv_fit is x_high and low cv_fit is at least medium; otherwise known monthly value of at least $1,000 is high and lower or unknown value is low. A low portfolio match with known monthly value of at least $1,000 remains high. not_hiring is only for posts not buying/hiring.\nmonthly_amount must be a monthly USD equivalent like \"$3,000/mo\" converted from hourly, weekly, annual, or project pay; use \"unknown\" when unstated. scam_likelihood is an integer 0-100.\n" . self::jobTypePromptInstruction($jobTypeOptions) . "\nItems: " . json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					]],
				]],
				'generationConfig' => [
					'temperature' => 0,
					'maxOutputTokens' => $gemmaOutputTokens,
				],
			];
		}
		$payload = [
			'systemInstruction' => [
				'parts' => [['text' => self::systemPrompt($jobTypeOptions)]],
			],
			'contents' => [[
				'parts' => [[
					'text' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				]],
			]],
			'generationConfig' => [
				'temperature' => 0,
				'maxOutputTokens' => $maxOutputTokens,
				'response_mime_type' => 'application/json',
			],
		];
		$payload['generationConfig']['response_schema'] = [
			'type' => 'ARRAY',
			'items' => [
				'type' => 'OBJECT',
				'properties' => [
					'id' => ['type' => 'STRING'],
					'summary' => ['type' => 'STRING'],
					'monthly_amount' => ['type' => 'STRING'],
					'priority' => [
						'type' => 'STRING',
							'enum' => ['low', 'medium', 'high', 'x_high', 'not_hiring'],
					],
					'job_type' => ['type' => 'STRING'],
					'cv_fit' => ['type' => 'STRING', 'enum' => ['low', 'high', 'extreme']],
					'scam_likelihood' => ['type' => 'INTEGER'],
				],
				'required' => ['id', 'summary', 'monthly_amount', 'priority', 'job_type', 'cv_fit', 'scam_likelihood'],
			],
		];
		return $payload;
	}

	public static function buildArbitrationPayload(array $items, string $model, array $jobTypeOptions): array {
		$maxOutputTokens = 220 + (count($items) * 180);
		$instruction = "Resolve conflicting Reddit lead classifications. Return only valid JSON array with fields: id, summary, monthly_amount, priority, job_type, cv_fit, scam_likelihood.\n"
			. "Compare each role with cv_profile_for_all_items when present. cv_fit is low, high, or extreme. With a supplied portfolio, high/extreme fit=x_high and low fit is at least medium; a low fit with monthly value >=$1,000 remains high. Without a supplied portfolio, monthly value >=$1,000=high and all other buyer leads=low. not_hiring is only for posts not buying/hiring.\n"
			. self::jobTypePromptInstruction($jobTypeOptions);
		if (strpos($model, 'gemma-') === 0) {
			return [
				'contents' => [[
					'parts' => [[
						'text' => $instruction . "\nItems: " . json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					]],
				]],
				'generationConfig' => [
					'temperature' => 0,
					'maxOutputTokens' => 1024 + (count($items) * 320),
				],
			];
		}
		return [
			'systemInstruction' => [
				'parts' => [['text' => $instruction]],
			],
			'contents' => [[
				'parts' => [[
					'text' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				]],
			]],
			'generationConfig' => [
				'temperature' => 0,
				'maxOutputTokens' => $maxOutputTokens,
				'response_mime_type' => 'application/json',
				'response_schema' => [
					'type' => 'ARRAY',
					'items' => [
						'type' => 'OBJECT',
						'properties' => [
							'id' => ['type' => 'STRING'],
							'summary' => ['type' => 'STRING'],
							'monthly_amount' => ['type' => 'STRING'],
							'priority' => [
								'type' => 'STRING',
								'enum' => ['low', 'medium', 'high', 'x_high', 'not_hiring'],
							],
							'job_type' => ['type' => 'STRING'],
							'cv_fit' => ['type' => 'STRING', 'enum' => ['low', 'high', 'extreme']],
							'scam_likelihood' => ['type' => 'INTEGER'],
						],
						'required' => ['id', 'summary', 'monthly_amount', 'priority', 'job_type', 'cv_fit', 'scam_likelihood'],
					],
				],
			],
		];
	}

	public static function decodeJsonArray(string $text, array $expectedIds = []): ?array {
		$text = trim($text);
		if (strpos($text, '```') === 0) {
			$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
			$text = preg_replace('/\s*```$/', '', $text);
		}

		$expected = [];
		foreach ($expectedIds as $id) {
			$expected[(string)$id] = true;
		}
		$best = null;
		$bestMatches = -1;
		$bestCount = -1;
		foreach (self::extractJsonValues($text) as $decoded) {
			$rows = self::normalizeClassificationRows($decoded);
			if (empty($rows)) {
				continue;
			}
			$deduped = [];
			$seen = [];
			$matches = empty($expected) ? count($rows) : 0;
			foreach ($rows as $row) {
				$id = (string)($row['id'] ?? '');
				if ($id === '' || isset($seen[$id])) {
					continue;
				}
				$seen[$id] = true;
				$deduped[] = $row;
				if (isset($expected[$id])) {
					$matches++;
				}
			}
			$count = count($deduped);
			if (
				$count > 0
				&& self::isValidClassificationArray($deduped, $expectedIds)
				&& ($matches > $bestMatches || ($matches === $bestMatches && $count > $bestCount))
			) {
				$best = $deduped;
				$bestMatches = $matches;
				$bestCount = $count;
			}
		}
		return $best;
	}

	private static function extractJsonValues(string $text): array {
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

	private static function normalizeClassificationRows($decoded): array {
		if (!is_array($decoded)) {
			return [];
		}
		if (self::isClassificationRow($decoded)) {
			$decoded['priority'] = self::normalizePriority((string)$decoded['priority']);
			return [$decoded];
		}
		foreach (['results', 'items', 'classifications', 'outputs', 'leads', 'data'] as $key) {
			if (isset($decoded[$key]) && is_array($decoded[$key])) {
				$nested = self::normalizeClassificationRows($decoded[$key]);
				if (!empty($nested)) {
					return $nested;
				}
			}
		}
		$rows = [];
		foreach ($decoded as $key => $value) {
			if (!is_array($value)) {
				continue;
			}
				if (!isset($value['id']) && is_string($key) && preg_match('/^[A-Za-z]?\d+$/', $key) === 1) {
					$value['id'] = $key;
				}
				if (self::isClassificationRow($value)) {
					$value['priority'] = self::normalizePriority((string)$value['priority']);
					$rows[] = $value;
					continue;
				}
			foreach (self::normalizeClassificationRows($value) as $row) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	private static function isClassificationRow($value): bool {
		return is_array($value)
			&& isset($value['id'])
			&& is_scalar($value['id'])
			&& isset($value['summary'])
			&& is_scalar($value['summary'])
			&& isset($value['priority'])
			&& is_scalar($value['priority']);
	}

	private static function normalizePriority(string $priority): string {
		$priority = strtolower(trim($priority));
		$priority = preg_replace('/[\s-]+/', '_', $priority) ?? $priority;
		if (in_array($priority, ['xhigh', 'extra_high', 'very_high'], true)) {
			return 'x_high';
		}
		if (in_array($priority, ['not_hire', 'not_hiring_lead'], true)) {
			return 'not_hiring';
		}
		return $priority;
	}

	private static function isValidClassificationArray(array $decoded, array $expectedIds = []): bool {
		if (empty($decoded)) {
			return false;
		}
		$expected = [];
		foreach ($expectedIds as $id) {
			$expected[(string)$id] = true;
		}
		foreach ($decoded as $item) {
			if (!is_array($item)) {
				return false;
			}
			$id = (string)($item['id'] ?? '');
			$summary = trim((string)($item['summary'] ?? ''));
			$priority = self::normalizePriority((string)($item['priority'] ?? ''));
			if ($id === '' || $summary === '' || !in_array($priority, ['low', 'medium', 'high', 'x_high', 'not_hiring'], true)) {
				return false;
			}
			if (isset($item['job_type']) && mb_strlen((string)$item['job_type'], 'UTF-8') > 96) {
				return false;
			}
			if (!array_key_exists('scam_likelihood', $item) || !is_numeric($item['scam_likelihood'])) {
				return false;
			}
			if (!in_array((string)($item['cv_fit'] ?? ''), ['low', 'high', 'extreme'], true)) {
				return false;
			}
			$scamLikelihood = (int)$item['scam_likelihood'];
			if ($scamLikelihood < 0 || $scamLikelihood > 100) {
				return false;
			}
			if (!empty($expected) && !isset($expected[$id])) {
				return false;
			}
		}
		return true;
	}

	private static function jobTypePromptInstruction(array $jobTypeOptions): string {
		$options = array_values(array_filter(array_map('trim', $jobTypeOptions), static fn(string $value): bool => $value !== ''));
		$known = empty($options) ? 'none yet' : implode(', ', array_slice($options, 0, 30));
		return 'job_type is a reusable lower-case job category such as video editing, scriptwriting, social media management, automation, or web development. For x_high/high/medium/low leads choose the exact best label from Known job_types when one fits. If none fits, create one concise new lower-case 2-4 word label. Do not create synonyms for known labels. Use "" for not_hiring. Known job_types: ' . $known . '.';
	}

	private static function systemPrompt(array $jobTypeOptions): string {
		return 'Score Reddit leads. Output JSON array only. Each item: id, summary<=20 words, monthly_amount, priority x_high|high|medium|low|not_hiring, job_type, cv_fit low|high|extreme, scam_likelihood 0-100. Compare each role with cv_profile_for_all_items when supplied. low fit means weak/no demonstrated match; high means the portfolio directly demonstrates the core work; extreme means unusually exact fit with multiple relevant proof points. With a supplied portfolio, high/extreme fit=x_high and low fit is at least medium; low fit with monthly USD >=$1,000 remains high. Without a portfolio, monthly USD >=$1,000=high and all other buyer leads=low. not_hiring is only for posts not buying/hiring. Convert hourly, weekly, annual, and project pay to monthly_amount; use unknown when unstated. ' . self::jobTypePromptInstruction($jobTypeOptions);
	}
}
