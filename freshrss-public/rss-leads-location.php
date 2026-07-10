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

$userDataDir = "/var/www/FreshRSS/data/users/{$user}";
$userConfigPath = getenv('RSS_LEADS_LOCATION_USER_CONFIG') ?: "{$userDataDir}/rss_leads_location.json";
$staticConfigPath = getenv('RSS_LEADS_LOCATION_CONFIG') ?: '/opt/rss-leads-stack/feeds/local-location.json';
$highPriorityCachePath = getenv('RSS_LEADS_HIGH_PRIORITY_CACHE') ?: "{$userDataDir}/rss_leads_high_priority.xml";

function rss_leads_location_normalize_label(string $value): string {
	$value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
	return trim($value);
}

function rss_leads_location_key(string $value): string {
	return mb_strtolower(rss_leads_location_normalize_label($value), 'UTF-8');
}

function rss_leads_location_clean_list(mixed $locations): array {
	if (!is_array($locations)) {
		return [];
	}
	$clean = [];
	$seen = [];
	foreach ($locations as $location) {
		$label = rss_leads_location_normalize_label((string)$location);
		$key = rss_leads_location_key($label);
		if ($label === '' || $key === '' || isset($seen[$key])) {
			continue;
		}
		if (mb_strlen($label, 'UTF-8') > 80) {
			$label = mb_substr($label, 0, 80, 'UTF-8');
		}
		$seen[$key] = true;
		$clean[] = $label;
		if (count($clean) >= 50) {
			break;
		}
	}
	return $clean;
}

function rss_leads_location_read_file(string $path): array {
	if (!is_readable($path)) {
		return [];
	}
	$json = file_get_contents($path);
	$config = is_string($json) ? json_decode($json, true) : null;
	if (!is_array($config)) {
		return [];
	}
	return rss_leads_location_clean_list($config['locations'] ?? []);
}

function rss_leads_location_effective(array ...$groups): array {
	$merged = [];
	$seen = [];
	foreach ($groups as $locations) {
		foreach ($locations as $location) {
			$key = rss_leads_location_key($location);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$merged[] = $location;
		}
	}
	return $merged;
}

function rss_leads_location_response(string $userConfigPath, string $staticConfigPath): array {
	$userLocations = rss_leads_location_read_file($userConfigPath);
	$staticLocations = rss_leads_location_read_file($staticConfigPath);
	$envLocations = rss_leads_location_clean_list(preg_split('/[;\n|]+/', (string)(getenv('RSS_LEADS_LOCAL_LOCATIONS') ?: '')) ?: []);
	return [
		'locations' => $userLocations,
		'config_locations' => $staticLocations,
		'env_locations' => $envLocations,
		'effective_locations' => rss_leads_location_effective($envLocations, $staticLocations, $userLocations),
		'writable' => is_dir(dirname($userConfigPath)) && is_writable(dirname($userConfigPath)),
		'updated_at' => is_readable($userConfigPath) ? (int)filemtime($userConfigPath) : null,
	];
}

function rss_leads_location_save(string $path, array $locations): void {
	$dir = dirname($path);
	if (!is_dir($dir) || !is_writable($dir)) {
		throw new RuntimeException('location_config_not_writable');
	}
	$payload = [
		'locations' => $locations,
		'updated_at' => time(),
	];
	$tempPath = $path . '.tmp.' . uniqid('', true);
	if (file_put_contents($tempPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) {
		throw new RuntimeException('location_config_write_failed');
	}
	rename($tempPath, $path);
}

try {
	$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	if ($method === 'GET') {
		echo json_encode(rss_leads_location_response($userConfigPath, $staticConfigPath), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
	if ($method !== 'POST') {
		http_response_code(405);
		echo json_encode(['error' => 'method_not_allowed']);
		exit;
	}
	$raw = file_get_contents('php://input');
	$payload = is_string($raw) ? json_decode($raw, true) : null;
	if (!is_array($payload)) {
		http_response_code(400);
		echo json_encode(['error' => 'invalid_json']);
		exit;
	}
	rss_leads_location_save($userConfigPath, rss_leads_location_clean_list($payload['locations'] ?? []));
	if (is_file($highPriorityCachePath)) {
		@unlink($highPriorityCachePath);
	}
	echo json_encode(rss_leads_location_response($userConfigPath, $staticConfigPath), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
