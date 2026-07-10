'use strict';

// Shared state, formatting, text helpers, and toast UI.

var rssLeadsStatusUrl = '/rss-leads-status.php';
var rssLeadsAiUrl = '/rss-leads-ai.php';
var rssLeadsLocationUrl = '/rss-leads-location.php';
var rssLeadsStatusText;
var rssLeadsRefreshButton;
var rssLeadsAiStatusButton;
var rssLeadsAiStatusPanel;
var rssLeadsLatestAiStatus;
var rssLeadsCvProfileButton;
var rssLeadsCvProfilePanel;
var rssLeadsCvProfileTextarea;
var rssLeadsLocationButton;
var rssLeadsLocationPanel;
var rssLeadsLocationInput;
var rssLeadsLocationList;
var rssLeadsLocationStatus;
var rssLeadsLatestLocationSettings;
var rssLeadsLatestStatus;
var rssLeadsTimerId;
var rssLeadsPollId;
var rssLeadsAiPollId;
var rssLeadsSubredditObserver;
var rssLeadsSubredditRefreshQueued = false;
var rssLeadsAiRefreshQueued = false;
var rssLeadsAiStatusActiveTab = 'status';
var rssLeadsCvProfileStorageKey = 'rss-leads-cv-profile';
var rssLeadsScrollAnchor = null;
var rssLeadsScrollAnchorUntil = 0;
var rssLeadsScrollStabilizerInstalled = false;

function rssLeadsFormatAge(seconds) {
	if (seconds === null || seconds === undefined || seconds < 0) {
		return 'never';
	}
	if (seconds < 60) {
		return seconds + 's ago';
	}
	if (seconds < 3600) {
		return Math.floor(seconds / 60) + 'm ago';
	}
	return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm ago';
}

function rssLeadsFormatTime(timestamp) {
	if (!timestamp) {
		return 'never';
	}
	var date = new Date(timestamp * 1000);
	return date.toLocaleString([], {
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit'
	});
}

function rssLeadsFormatCountdown(timestamp) {
	if (!timestamp) {
		return 'unknown';
	}
	var seconds = timestamp - Math.floor(Date.now() / 1000);
	if (seconds <= 0) {
		return 'due now';
	}
	return 'in ' + rssLeadsFormatAge(seconds).replace(' ago', '');
}

function rssLeadsShortText(value, fallback) {
	value = String(value || '').trim();
	if (!value) {
		return fallback || '';
	}
	return value.length > 140 ? value.slice(0, 137) + '...' : value;
}

function rssLeadsNormalizeText(value) {
	return String(value || '').replace(/\s+/g, ' ').trim();
}

function rssLeadsLimitText(value, maxLength) {
	value = rssLeadsNormalizeText(value);
	if (value.length <= maxLength) {
		return value;
	}
	return value.slice(0, Math.max(0, maxLength - 3)).trim() + '...';
}

function rssLeadsShowToast(message, detail, tone) {
	var toast = document.getElementById('rss-leads-toast');
	if (!toast) {
		toast = document.createElement('div');
		toast.id = 'rss-leads-toast';
		toast.setAttribute('role', 'status');
		toast.setAttribute('aria-live', 'polite');
		document.body.appendChild(toast);
	}
	toast.className = 'rss-leads-toast-' + (tone || 'info');
	rssLeadsClearNode(toast);
	var title = document.createElement('strong');
	title.textContent = message;
	var body = document.createElement('span');
	body.textContent = detail;
	toast.appendChild(title);
	toast.appendChild(body);
	toast.hidden = false;
	window.clearTimeout(toast._rssLeadsTimeout);
	toast._rssLeadsTimeout = window.setTimeout(function () {
		toast.hidden = true;
	}, 6500);
}

function rssLeadsClosestEntry(node) {
	while (node && node !== document.body) {
		if (node.classList && node.classList.contains('flux') && node.getAttribute('data-entry')) {
			return node;
		}
		node = node.parentNode;
	}
	return null;
}

function rssLeadsEntryById(id) {
	var entries = document.querySelectorAll('.flux[data-entry]');
	for (var index = 0; index < entries.length; index++) {
		if (entries[index].getAttribute('data-entry') === id) {
			return entries[index];
		}
	}
	return null;
}

function rssLeadsCaptureScrollAnchor(entry) {
	if (!entry || !entry.getBoundingClientRect) {
		return null;
	}
	var id = entry.getAttribute('data-entry');
	if (!id) {
		return null;
	}
	return {
		id: id,
		top: entry.getBoundingClientRect().top
	};
}

function rssLeadsRememberScrollAnchor(entry) {
	return null;
}

function rssLeadsActiveScrollAnchor() {
	return null;
}

function rssLeadsCaptureViewportScrollAnchor() {
	var entries = document.querySelectorAll('.flux[data-entry]');
	var best = null;
	for (var index = 0; index < entries.length; index++) {
		var entry = entries[index];
		if (!entry.getBoundingClientRect) {
			continue;
		}
		var rect = entry.getBoundingClientRect();
		if (rect.bottom <= 0) {
			continue;
		}
		if (rect.top > window.innerHeight) {
			break;
		}
		if (!best || Math.abs(rect.top) < Math.abs(best.top)) {
			best = {
				id: entry.getAttribute('data-entry'),
				top: rect.top
			};
		}
	}
	return best && best.id ? best : null;
}

function rssLeadsRestoreScrollAnchor(anchor) {
	return;
}

function rssLeadsInstallScrollStabilizer() {
	if (rssLeadsScrollStabilizerInstalled) {
		return;
	}
	rssLeadsScrollStabilizerInstalled = true;
}
