<?php
declare(strict_types=1);

final class RssLeadsStatusExtension extends Minz_Extension {
	#[\Override]
	public function init(): void {
		parent::init();

		$styleVersion = (string)@filemtime(__DIR__ . '/static/style.css');
		Minz_View::appendStyle($this->getFileUrl('style.css') . '?v=' . $styleVersion);
		$scripts = [
			'01-core.js',
			'02-status-widget.js',
			'03-reddit-ui.js',
			'04-lead-scoring.js',
			'05-quick-apply.js',
			'06-ai-feed.js',
			'08-location-settings.js',
			'07-bootstrap.js',
		];
		foreach ($scripts as $script) {
			$scriptVersion = (string)@filemtime(__DIR__ . '/static/' . $script);
			Minz_View::appendScript($this->getFileUrl($script) . '?v=' . $scriptVersion);
		}
	}
}
