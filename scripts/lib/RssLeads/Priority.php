<?php
declare(strict_types=1);

final class RssLeadsPriority {
	public static function normalizeCvFit(mixed $value): string {
		$value = mb_strtolower(trim((string)$value), 'UTF-8');
		$value = preg_replace('/[\s-]+/u', '_', $value) ?? $value;
		return match ($value) {
			'extreme', 'extremely_high', 'exceptional', 'perfect' => 'extreme',
			'high', 'strong', 'good' => 'high',
			default => 'low',
		};
	}

	public static function monthlyAmountMax(string $value): ?float {
		$value = trim(mb_strtolower($value, 'UTF-8'));
		if ($value === '' || $value === 'unknown') {
			return null;
		}
		if (preg_match_all('/(?:[$]\s*)?([0-9][0-9,]*(?:\.[0-9]+)?)\s*([kKmM]?)/u', $value, $matches, PREG_SET_ORDER) < 1) {
			return null;
		}
		$values = array_map(static function (array $match): float {
			$amount = (float)str_replace(',', '', $match[1]);
			return match (strtolower($match[2] ?? '')) {
				'k' => $amount * 1000,
				'm' => $amount * 1000000,
				default => $amount,
			};
		}, $matches);
		return empty($values) ? null : max($values);
	}

	public static function fromPayAndFit(string $current, string $monthlyAmount, mixed $cvFit, bool $portfolioAvailable = false): string {
		if ($current === 'not_hiring') {
			return 'not_hiring';
		}
		$cvFit = self::normalizeCvFit($cvFit);
		if ($portfolioAvailable && in_array($cvFit, ['high', 'extreme'], true)) {
			return 'x_high';
		}
		$monthlyMax = self::monthlyAmountMax($monthlyAmount);
		if ($monthlyMax !== null && $monthlyMax >= 1000) {
			return 'high';
		}
		if ($portfolioAvailable) {
			return 'medium';
		}
		return 'low';
	}
}
