<?php
declare(strict_types=1);

final class RssLeadsText {
	public static function compact(string $html, int $limit): string {
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
		$text = trim($text);
		if (mb_strlen($text, 'UTF-8') > $limit) {
			return mb_substr($text, 0, $limit, 'UTF-8');
		}
		return $text;
	}

	public static function normalized(string $value): string {
		$value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = mb_strtolower($value, 'UTF-8');
		$value = preg_replace('/[^a-z0-9+#\/ -]+/u', ' ', $value) ?? $value;
		$value = str_replace(['.', ','], ' ', $value);
		$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
		return trim($value);
	}

	public static function errorExcerpt(string $value, int $limit = 700): string {
		$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
		$value = trim($value);
		if (mb_strlen($value, 'UTF-8') > $limit) {
			return mb_substr($value, 0, $limit, 'UTF-8') . '...';
		}
		return $value;
	}

	public static function htmlExcerpt(string $html, int $limit = 6000): string {
		$html = trim($html);
		if (mb_strlen($html, 'UTF-8') <= $limit) {
			return $html;
		}
		return mb_substr($html, 0, $limit, 'UTF-8') . '...';
	}
}

final class RssLeadsJsonFile {
	public static function readArray(string $path): array {
		if (!is_file($path)) {
			return [];
		}
		$json = file_get_contents($path);
		$decoded = is_string($json) ? json_decode($json, true) : null;
		return is_array($decoded) ? $decoded : [];
	}

	public static function writeArrayAtomic(string $path, array $data): bool {
		$dir = dirname($path);
		if (!is_dir($dir)) {
			return false;
		}
		$tempPath = $path . '.tmp.' . uniqid('', true);
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (file_put_contents($tempPath, $json) === false) {
			return false;
		}
		return rename($tempPath, $path);
	}
}

final class RssLeadsModelIds {
	public static function normalize(string $model): string {
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

	public static function dailyLimits(string $value): array {
		$limits = [];
		foreach (explode(',', $value) as $part) {
			$part = trim($part);
			if ($part === '' || strpos($part, '=') === false) {
				continue;
			}
			[$model, $limit] = array_map('trim', explode('=', $part, 2));
			$normalizedModel = self::normalize($model);
			$numericLimit = (int)$limit;
			if ($normalizedModel !== '' && $numericLimit > 0) {
				$limits[$normalizedModel] = min(100000, $numericLimit);
			}
		}
		return $limits;
	}
}

final class RssLeadsDb {
	public static function sqlite(string $path, int $busyTimeoutMs = 10000): PDO {
		$db = new PDO('sqlite:' . $path);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->exec('PRAGMA busy_timeout = ' . max(0, $busyTimeoutMs));
		return $db;
	}
}
