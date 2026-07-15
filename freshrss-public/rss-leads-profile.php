<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
if (preg_match('/^[A-Za-z0-9_.-]+$/', $user) !== 1) {
	http_response_code(500);
	echo json_encode(['error' => 'invalid_user']);
	exit;
}

$path = getenv('RSS_LEADS_CV_PROFILE_FILE') ?: "/var/www/FreshRSS/data/users/{$user}/rss_leads_profile.json";

function rss_leads_clean_profile(mixed $value): string {
	$value = is_scalar($value) ? (string)$value : '';
	$value = str_replace("\0", '', $value);
	$value = preg_replace('/\r\n?/u', "\n", $value) ?? $value;
	$value = trim($value);
	return mb_strlen($value, 'UTF-8') > 12000 ? mb_substr($value, 0, 12000, 'UTF-8') : $value;
}

function rss_leads_read_profile(string $path): array {
	$data = [];
	if (is_readable($path)) {
		$decoded = json_decode((string)file_get_contents($path), true);
		$data = is_array($decoded) ? $decoded : [];
	}
	return [
		'profile' => rss_leads_clean_profile($data['profile'] ?? ''),
		'updated_at' => (int)($data['updated_at'] ?? 0),
		'writable' => is_dir(dirname($path)) && is_writable(dirname($path)),
	];
}

try {
	$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	if ($method === 'GET') {
		echo json_encode(rss_leads_read_profile($path), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
	if ($method !== 'POST') {
		http_response_code(405);
		echo json_encode(['error' => 'method_not_allowed']);
		exit;
	}
	$payload = json_decode((string)file_get_contents('php://input'), true);
	if (!is_array($payload)) {
		http_response_code(400);
		echo json_encode(['error' => 'invalid_json']);
		exit;
	}
	if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
		throw new RuntimeException('profile_config_not_writable');
	}
	$data = ['profile' => rss_leads_clean_profile($payload['profile'] ?? ''), 'updated_at' => time()];
	$temp = $path . '.tmp.' . uniqid('', true);
	if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) === false || !rename($temp, $path)) {
		@unlink($temp);
		throw new RuntimeException('profile_config_write_failed');
	}
	echo json_encode(rss_leads_read_profile($path), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
