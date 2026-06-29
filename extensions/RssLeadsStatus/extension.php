<?php
declare(strict_types=1);

final class RssLeadsStatusExtension extends Minz_Extension {
	#[\Override]
	public function init(): void {
		parent::init();

		$styleVersion = (string)@filemtime(__DIR__ . '/static/style.css');
		$scriptVersion = (string)@filemtime(__DIR__ . '/static/script.js');
		Minz_View::appendStyle($this->getFileUrl('style.css') . '?v=' . $styleVersion);
		Minz_View::appendScript($this->getFileUrl('script.js') . '?v=' . $scriptVersion);
	}
}
