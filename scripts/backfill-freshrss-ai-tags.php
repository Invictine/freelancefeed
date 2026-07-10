<?php
declare(strict_types=1);

$user = getenv('RSS_LEADS_USER') ?: (getenv('FRESHRSS_USER') ?: 'invictine');
if (preg_match('/^[A-Za-z0-9_.-]+$/', $user) !== 1) {
	fwrite(STDERR, "Invalid FreshRSS user.\n");
	exit(1);
}

$dbPath = getenv('FRESHRSS_DB') ?: "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
$sourceFeedNames = [
	'Reddit Leads - all communities',
	'Reddit Leads - qualified deep-research communities',
	'Reddit Leads - unqualified deep-research communities',
];
$commentFeedLike = 'Reddit Leads - comments - %';

function ai_tag_value(string $value, string $fallback = 'unknown'): string {
	$value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$value = mb_strtolower($value, 'UTF-8');
	$value = str_replace('&', 'and', $value);
	$value = preg_replace('/\s+/u', '_', $value) ?? $value;
	$value = preg_replace('/[^a-z0-9$.,:+\/_-]+/u', '', $value) ?? $value;
	$value = trim($value, '_');
	return $value === '' ? $fallback : $value;
}

function scam_likelihood_tag(int $score): string {
	if ($score >= 70) {
		return 'scam:high';
	}
	if ($score >= 35) {
		return 'scam:medium';
	}
	return 'scam:low';
}

function tags_with_ai_labels(string $tags, string $priority, string $monthlyAmount, string $jobType, int $scamLikelihood): string {
	$existing = preg_split('/\s+/', trim($tags)) ?: [];
	$merged = [];
	foreach ($existing as $tag) {
		$tag = trim($tag);
		$lowerTag = mb_strtolower($tag, 'UTF-8');
		if (
			$tag === ''
			|| str_starts_with($lowerTag, 'priority:')
			|| str_starts_with($lowerTag, 'monthly:')
			|| str_starts_with($lowerTag, 'job:')
			|| str_starts_with($lowerTag, 'scam:')
		) {
			continue;
		}
		$merged[$tag] = true;
	}
	$merged['priority:' . ai_tag_value($priority)] = true;
	if ($monthlyAmount !== '') {
		$merged['monthly:' . ai_tag_value($monthlyAmount)] = true;
	}
	if ($jobType !== '') {
		$merged['job:' . ai_tag_value($jobType)] = true;
	}
	if ($scamLikelihood > 0) {
		$merged[scam_likelihood_tag($scamLikelihood)] = true;
	}
	return implode(' ', array_keys($merged));
}

try {
	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$feedPlaceholders = implode(',', array_fill(0, count($sourceFeedNames), '?'));
	$sql = 'WITH latest_ai AS (
			SELECT link, priority, monthly_amount, job_type, scam_likelihood
			FROM (
				SELECT link, priority, monthly_amount, job_type, scam_likelihood,
					ROW_NUMBER() OVER (PARTITION BY link ORDER BY updated_at DESC, entry_id DESC) AS rn
				FROM rss_leads_ai
			)
			WHERE rn = 1
		)
		SELECT e.id, e.tags, ai.priority, ai.monthly_amount, ai.job_type, ai.scam_likelihood
		FROM entry e
		JOIN feed f ON f.id = e.id_feed
		JOIN latest_ai ai ON ai.link = e.link
		WHERE f.name IN (' . $feedPlaceholders . ')
		   OR f.name LIKE ?';
	$stmt = $db->prepare($sql);
	$stmt->execute(array_merge($sourceFeedNames, [$commentFeedLike]));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$update = $db->prepare('UPDATE entry SET tags = :tags, lastUserModified = :updated_at WHERE id = :id');
	$now = time();
	$updated = 0;
	$db->beginTransaction();
	foreach ($rows as $row) {
		$tags = tags_with_ai_labels(
			(string)$row['tags'],
			(string)$row['priority'],
			(string)$row['monthly_amount'],
			(string)$row['job_type'],
			max(0, min(100, (int)$row['scam_likelihood']))
		);
		if ($tags === (string)$row['tags']) {
			continue;
		}
		$update->execute([
			':id' => (int)$row['id'],
			':tags' => $tags,
			':updated_at' => $now,
		]);
		$updated += $update->rowCount();
	}
	$db->commit();
	echo 'Backfilled AI tags: ' . json_encode([
		'matched_entries' => count($rows),
		'updated_entries' => $updated,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
	if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
		$db->rollBack();
	}
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
