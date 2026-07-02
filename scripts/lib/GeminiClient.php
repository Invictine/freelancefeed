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

		return [
			'model' => $model,
			'raw' => is_string($raw) ? $raw : '',
			'status' => $status,
			'error' => $curlError,
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
						'text' => "Classify each Reddit item. Return one JSON array with objects containing id, summary, monthly_amount, priority, job_type, and scam_likelihood. Do not use markdown.\nPriority must be one of: low, medium, high, not_hiring.\nscam_likelihood must be an integer from 0 to 100 estimating the chance this is a scam or unsafe lead. Raise it for unrealistic pay, vague easy-work promises, payment forwarding, crypto/check schemes, requests for money, account access, off-platform payment, suspicious urgency, or impersonation. Lower it for specific normal paid work with credible details.\nmonthly_amount is only for high priority: estimate monthly USD value like \"$3,000/mo\" from budget, pay, rate, or salary. Convert hourly, weekly, annual, or project pay to monthly equivalent when possible. Use \"unknown\" when no money is stated. Use \"\" for non-high.\nnot_hiring means freelancer offers, hire-me posts, portfolios, job seekers, advice, discussion, showcases, news, spam, or anything not posted by someone hiring for a role/task/vendor.\nhigh means paid urgent/budget/ready hiring. medium means paid hiring without urgency or urgent hiring without budget. low means vague or unpaid but still seeking help.\n" . self::jobTypePromptInstruction($jobTypeOptions) . "\nItems: " . json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
						'enum' => ['low', 'medium', 'high', 'not_hiring'],
					],
					'job_type' => ['type' => 'STRING'],
					'scam_likelihood' => ['type' => 'INTEGER'],
				],
				'required' => ['id', 'summary', 'monthly_amount', 'priority', 'job_type', 'scam_likelihood'],
			],
		];
		return $payload;
	}

	public static function decodeJsonArray(string $text, array $expectedIds = []): ?array {
		$text = trim($text);
		if (strpos($text, '```') === 0) {
			$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
			$text = preg_replace('/\s*```$/', '', $text);
		}
		
		$decoded = json_decode($text, true);
		if (is_array($decoded) && self::isValidClassificationArray($decoded, $expectedIds)) {
			return $decoded;
		}
		return null;
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
			$priority = strtolower((string)($item['priority'] ?? ''));
			if ($id === '' || $summary === '' || !in_array($priority, ['low', 'medium', 'high', 'not_hiring'], true)) {
				return false;
			}
			if (isset($item['job_type']) && mb_strlen((string)$item['job_type'], 'UTF-8') > 96) {
				return false;
			}
			if (!array_key_exists('scam_likelihood', $item) || !is_numeric($item['scam_likelihood'])) {
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
		return 'job_type is a reusable lower-case job category such as video editing, scriptwriting, social media management, automation, or web development. For high/medium/low leads choose the exact best label from Known job_types when one fits. If none fits, create one concise new lower-case 2-4 word label. Do not create synonyms for known labels. Use "" for not_hiring. Known job_types: ' . $known . '.';
	}

	private static function systemPrompt(array $jobTypeOptions): string {
		return 'Score Reddit leads. Output JSON array only. Each item: id, summary<=20 words one sentence, monthly_amount, priority low|medium|high|not_hiring, job_type, scam_likelihood integer 0-100. scam_likelihood is the chance the post is a scam or unsafe lead: high values for unrealistic pay, requests for money, off-platform payment/account access, vague easy-work offers, crypto/check/payment-forwarding, suspicious urgency, or impersonation; low values for specific normal paid work with credible details. monthly_amount is only for high priority: estimate monthly USD value like "$3,000/mo" from budget/pay/rate; convert hourly weekly annual or project pay to monthly equivalent when possible; use "unknown" if no money is stated; use "" for non-high. not_hiring=not posted by someone hiring for a role/task/vendor, including freelancer offers, hire-me posts, portfolio/self-promo, selling services, job seekers, general discussion, advice, showcases, news, or spam. For real buyer/hiring posts: high=paid+urgent/budget/ready; medium=paid/no urgency or urgent/no budget; low=unpaid/vague but still wants help/hiring. ' . self::jobTypePromptInstruction($jobTypeOptions);
	}
}
