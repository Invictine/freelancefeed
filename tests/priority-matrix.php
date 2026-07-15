<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/lib/RssLeads/Priority.php';

$cases = [
	['$99/mo', 'high', false, 'low'],
	['$100/mo', 'low', false, 'low'],
	['$999.99/mo', 'high', false, 'low'],
	['$1,000/mo', 'low', false, 'high'],
	['$5k/mo', 'low', false, 'high'],
	['unknown', 'extreme', false, 'low'],
	['$80-$1,200/mo', 'low', false, 'high'],
	['unknown', 'low', true, 'medium'],
	['$99/mo', 'low', true, 'medium'],
	['$999.99/mo', 'low', true, 'medium'],
	['$1,000/mo', 'low', true, 'high'],
	['unknown', 'high', true, 'x_high'],
	['$99/mo', 'high', true, 'x_high'],
	['unknown', 'extreme', true, 'x_high'],
];

foreach ($cases as [$amount, $fit, $portfolioAvailable, $expected]) {
	$actual = RssLeadsPriority::fromPayAndFit('low', $amount, $fit, $portfolioAvailable);
	if ($actual !== $expected) {
		throw new RuntimeException("{$amount} + {$fit} + portfolio=" . ($portfolioAvailable ? 'yes' : 'no') . ": expected {$expected}, got {$actual}");
	}
	echo "PASS {$amount} + {$fit} + portfolio=" . ($portfolioAvailable ? 'yes' : 'no') . " => {$actual}\n";
}

if (RssLeadsPriority::fromPayAndFit('not_hiring', '$9,000/mo', 'extreme', true) !== 'not_hiring') {
	throw new RuntimeException('not_hiring must remain excluded');
}
echo "PASS not_hiring remains excluded\n";
