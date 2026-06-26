<?php
declare(strict_types=1);

final class RssLeadsStatusExtension extends Minz_Extension {
	#[\Override]
	public function init(): void {
		parent::init();

		Minz_View::appendStyle($this->getFileUrl('style.css'));
		Minz_View::appendScript($this->getFileUrl('script.js'));
	}
}
