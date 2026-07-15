'use strict';

// Mutation observer, debounce queues, widget mounting, and startup.

function rssLeadsModulesReady() {
	return [
		'rssLeadsAnnotateQuickApplyButtons',
		'rssLeadsAnnotateSubreddits',
		'rssLeadsFetchAiResults',
		'rssLeadsOrderLeadSidebar',
		'rssLeadsPollStatus',
		'rssLeadsRenderFallbackAiResults',
		'rssLeadsUpdateAllRedditLeadsVisibility',
		'rssLeadsUpdateCvProfileButton',
		'rssLeadsUpdateLocationButton',
		'rssLeadsUpdateTimer'
	].every(function (name) {
		return typeof window[name] === 'function';
	});
}

function rssLeadsStartWhenReady() {
	if (!rssLeadsModulesReady()) {
		window.setTimeout(rssLeadsStartWhenReady, 50);
		return;
	}
	rssLeadsInit();
}

function rssLeadsQueueAiAnnotation() {
	if (rssLeadsAiRefreshQueued) {
		return;
	}
	rssLeadsAiRefreshQueued = true;
	window.setTimeout(function () {
		rssLeadsAiRefreshQueued = false;
		rssLeadsFetchAiResults(false);
	}, 800);
}

function rssLeadsQueueSubredditAnnotation(mutations) {
	var hasNewEntries = !mutations || Array.prototype.some.call(mutations, function (mutation) {
		return Array.prototype.some.call(mutation.addedNodes || [], function (node) {
			return node.nodeType === 1 && ((node.matches && node.matches('.flux[data-entry], article.flux_content')) || (node.querySelector && node.querySelector('.flux[data-entry], article.flux_content')));
		});
	});
	if (!hasNewEntries) {
		return;
	}
	if (rssLeadsSubredditRefreshQueued) {
		return;
	}
	rssLeadsSubredditRefreshQueued = true;
	window.requestAnimationFrame(function () {
		var scrollAnchor = rssLeadsActiveScrollAnchor();
		rssLeadsSubredditRefreshQueued = false;
		rssLeadsIndexEntries(document);
		rssLeadsOrderLeadSidebar();
		rssLeadsAnnotateSubreddits();
		rssLeadsRenderFallbackAiResults({});
		rssLeadsUpdateAllRedditLeadsVisibility();
		rssLeadsAnnotateQuickApplyButtons();
		rssLeadsQueueAiAnnotation();
		rssLeadsRestoreScrollAnchor(scrollAnchor);
	});
}

function rssLeadsWatchSubredditEntries() {
	rssLeadsIndexEntries(document);
	rssLeadsOrderLeadSidebar();
	rssLeadsAnnotateSubreddits();
	rssLeadsRenderFallbackAiResults({});
	rssLeadsUpdateAllRedditLeadsVisibility();
	rssLeadsAnnotateQuickApplyButtons();
	if (rssLeadsSubredditObserver || !window.MutationObserver) {
		return;
	}
	rssLeadsSubredditObserver = new MutationObserver(rssLeadsQueueSubredditAnnotation);
	rssLeadsSubredditObserver.observe(document.body, {
		childList: true,
		subtree: true
	});
}

function rssLeadsMountWidget() {
	if (document.getElementById('rss-leads-status-widget')) {
		return true;
	}
	var target = document.getElementById('nav_menu_actualize') || document.getElementById('nav_menu_actions');
	if (!target) {
		return false;
	}

	var widget = document.createElement('div');
	widget.id = 'rss-leads-status-widget';

	rssLeadsStatusText = document.createElement('span');
	rssLeadsStatusText.className = 'rss-leads-status-text';
	rssLeadsStatusText.textContent = 'Reddit status loading';

	rssLeadsRefreshButton = document.createElement('button');
	rssLeadsRefreshButton.type = 'button';
	rssLeadsRefreshButton.className = 'btn rss-leads-refresh-btn';
	rssLeadsRefreshButton.textContent = 'Refresh Reddit';
	rssLeadsRefreshButton.title = 'Refresh Reddit leads feed now';
	rssLeadsRefreshButton.addEventListener('click', rssLeadsManualRefresh);

	rssLeadsCvProfileButton = document.createElement('button');
	rssLeadsCvProfileButton.type = 'button';
	rssLeadsCvProfileButton.className = 'btn rss-leads-cv-profile-btn';
	rssLeadsCvProfileButton.title = 'Edit CV profile used by Quick apply';
	rssLeadsCvProfileButton.addEventListener('click', function () {
		if (!rssLeadsCvProfilePanel || rssLeadsCvProfilePanel.hidden) {
			rssLeadsOpenCvProfilePanel();
			if (rssLeadsLocationPanel) {
				rssLeadsLocationPanel.hidden = true;
			}
		} else {
			rssLeadsCvProfilePanel.hidden = true;
		}
	});

	rssLeadsLocationButton = document.createElement('button');
	rssLeadsLocationButton.type = 'button';
	rssLeadsLocationButton.className = 'btn rss-leads-location-btn';
	rssLeadsLocationButton.title = 'Set local locations for in-person high-priority filtering';
	rssLeadsLocationButton.addEventListener('click', function () {
		if (!rssLeadsLocationPanel || rssLeadsLocationPanel.hidden) {
			rssLeadsOpenLocationPanel();
			if (rssLeadsCvProfilePanel) {
				rssLeadsCvProfilePanel.hidden = true;
			}
			if (rssLeadsAiStatusPanel) {
				rssLeadsAiStatusPanel.hidden = true;
			}
		} else {
			rssLeadsLocationPanel.hidden = true;
		}
	});

	rssLeadsAiStatusButton = document.createElement('button');
	rssLeadsAiStatusButton.type = 'button';
	rssLeadsAiStatusButton.className = 'btn rss-leads-ai-status-btn';
	rssLeadsAiStatusButton.textContent = 'AI loading';
	rssLeadsAiStatusButton.title = 'Show AI filter status';
	rssLeadsAiStatusButton.addEventListener('click', function () {
		if (!rssLeadsAiStatusPanel) {
			return;
		}
		rssLeadsAiStatusPanel.hidden = !rssLeadsAiStatusPanel.hidden;
		if (!rssLeadsAiStatusPanel.hidden && rssLeadsCvProfilePanel) {
			rssLeadsCvProfilePanel.hidden = true;
		}
		if (!rssLeadsAiStatusPanel.hidden && rssLeadsLocationPanel) {
			rssLeadsLocationPanel.hidden = true;
		}
		if (!rssLeadsAiStatusPanel.hidden && rssLeadsLatestAiStatus) {
			rssLeadsRenderAiStatus(rssLeadsLatestAiStatus);
		}
	});

	rssLeadsAiStatusPanel = document.createElement('div');
	rssLeadsAiStatusPanel.id = 'rss-leads-ai-status-panel';
	rssLeadsAiStatusPanel.hidden = true;

	rssLeadsCvProfilePanel = document.createElement('div');
	rssLeadsCvProfilePanel.id = 'rss-leads-cv-profile-panel';
	rssLeadsCvProfilePanel.hidden = true;

	rssLeadsLocationPanel = document.createElement('div');
	rssLeadsLocationPanel.id = 'rss-leads-location-panel';
	rssLeadsLocationPanel.hidden = true;

	widget.appendChild(rssLeadsStatusText);
	widget.appendChild(rssLeadsRefreshButton);
	widget.appendChild(rssLeadsCvProfileButton);
	widget.appendChild(rssLeadsLocationButton);
	widget.appendChild(rssLeadsAiStatusButton);
	widget.appendChild(rssLeadsAiStatusPanel);
	widget.appendChild(rssLeadsCvProfilePanel);
	widget.appendChild(rssLeadsLocationPanel);
	target.insertAdjacentElement('afterend', widget);
	rssLeadsUpdateCvProfileButton();
	rssLeadsUpdateLocationButton();
	return true;
}

function rssLeadsInit() {
	if (!rssLeadsMountWidget()) {
		window.setTimeout(rssLeadsInit, 500);
		return;
	}
	rssLeadsPollStatus();
	rssLeadsFetchLocationSettings();
	rssLeadsFetchCvProfile();
	rssLeadsInstallScrollStabilizer();
	rssLeadsWatchSubredditEntries();
	rssLeadsFetchAiResults(true);
	rssLeadsTimerId = window.setInterval(rssLeadsUpdateTimer, 1000);
	rssLeadsPollId = window.setInterval(rssLeadsPollStatus, 15000);
	rssLeadsAiPollId = window.setInterval(function () {
		rssLeadsFetchAiResults(true);
	}, 60000);
	window.addEventListener('beforeunload', function () {
		window.clearInterval(rssLeadsTimerId);
		window.clearInterval(rssLeadsPollId);
		window.clearInterval(rssLeadsAiPollId);
		if (rssLeadsSubredditObserver) {
			rssLeadsSubredditObserver.disconnect();
		}
	});
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', rssLeadsStartWhenReady);
} else {
	rssLeadsStartWhenReady();
}
