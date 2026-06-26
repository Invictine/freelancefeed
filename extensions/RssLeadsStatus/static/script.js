(function () {
	'use strict';

	var statusUrl = '/rss-leads-status.php';
	var aiUrl = '/rss-leads-ai.php';
	var statusText;
	var refreshButton;
	var latestStatus;
	var timerId;
	var pollId;
	var aiPollId;
	var subredditObserver;
	var subredditRefreshQueued = false;
	var aiRefreshQueued = false;

	function formatAge(seconds) {
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

	function showToast(message, detail) {
		var toast = document.getElementById('rss-leads-toast');
		if (!toast) {
			toast = document.createElement('div');
			toast.id = 'rss-leads-toast';
			document.body.appendChild(toast);
		}
		toast.innerHTML = '<strong>' + message + '</strong><span>' + detail + '</span>';
		toast.hidden = false;
		window.clearTimeout(toast._rssLeadsTimeout);
		toast._rssLeadsTimeout = window.setTimeout(function () {
			toast.hidden = true;
		}, 10000);
	}

	function updateTimer() {
		if (!statusText || !latestStatus) {
			return;
		}
		var now = Math.floor(Date.now() / 1000);
		var age = latestStatus.last_update > 0 ? now - latestStatus.last_update : null;
		statusText.textContent = 'Reddit refreshed ' + formatAge(age);
		statusText.title = latestStatus.last_update_iso || '';
	}

	function renderStatus(data) {
		latestStatus = data;
		updateTimer();

		if (data.recent_429 && data.latest_429_ts) {
			var key = 'rss-leads-last-429-toast';
			var previous = window.localStorage.getItem(key);
			var current = String(data.latest_429_ts);
			if (previous !== current) {
				window.localStorage.setItem(key, current);
				showToast('Reddit rate limit hit', 'FreshRSS received HTTP 429 while refreshing the Reddit leads feed.');
			}
		}
	}

	function pollStatus() {
		window.fetch(statusUrl, {
			credentials: 'same-origin',
			cache: 'no-store'
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('status ' + response.status);
				}
				return response.json();
			})
			.then(renderStatus)
			.catch(function () {
				if (statusText) {
					statusText.textContent = 'Reddit status unavailable';
				}
			});
	}

	function manualRefresh() {
		if (!refreshButton) {
			return;
		}
		refreshButton.disabled = true;
		statusText.textContent = 'Refreshing Reddit...';
		var feedIds = latestStatus && latestStatus.feed_ids && latestStatus.feed_ids.length
			? latestStatus.feed_ids
			: [latestStatus && latestStatus.feed_id].filter(Boolean);
		Promise.all(feedIds.map(function (feedId) {
			return window.fetch('/i/?c=feed&a=actualize&id=' + encodeURIComponent(feedId) + '&ajax=1', {
				credentials: 'same-origin',
				cache: 'no-store'
			});
		}))
			.then(function () {
				window.setTimeout(pollStatus, 1500);
				window.setTimeout(function () {
					refreshButton.disabled = false;
				}, 2000);
			})
			.catch(function () {
				refreshButton.disabled = false;
				showToast('Manual refresh failed', 'FreshRSS could not start the Reddit feed refresh.');
				pollStatus();
			});
	}

	function normalizeSubredditName(value) {
		var match = String(value || '').match(/^[A-Za-z0-9_]{2,21}$/);
		return match ? match[0] : null;
	}

	function extractSubredditFromUrl(value) {
		if (!value) {
			return null;
		}

		try {
			var url = new URL(value, window.location.href);
			var host = url.hostname.replace(/^www\./, '').replace(/^old\./, '').replace(/^new\./, '');
			if (host !== 'reddit.com') {
				return null;
			}
			var segments = url.pathname.split('/').filter(Boolean);
			for (var index = 0; index < segments.length - 1; index++) {
				if (segments[index].toLowerCase() === 'r') {
					return normalizeSubredditName(decodeURIComponent(segments[index + 1]));
				}
			}
		} catch (error) {
			var fallback = String(value).match(/(?:^|\/\/)(?:www\.|old\.|new\.)?reddit\.com\/r\/([A-Za-z0-9_]{2,21})(?:[/?#]|$)/i);
			return fallback ? normalizeSubredditName(fallback[1]) : null;
		}

		return null;
	}

	function subredditColor(subreddit) {
		var hash = 0;
		var value = String(subreddit || '').toLowerCase();
		var palette = [
			{ background: '#eff6ff', text: '#1d4ed8', darkBackground: '#172554', darkText: '#93c5fd' },
			{ background: '#f0fdf4', text: '#15803d', darkBackground: '#052e16', darkText: '#86efac' },
			{ background: '#f0f9ff', text: '#0369a1', darkBackground: '#082f49', darkText: '#7dd3fc' },
			{ background: '#faf5ff', text: '#7e22ce', darkBackground: '#3b0764', darkText: '#d8b4fe' },
			{ background: '#fef2f2', text: '#b91c1c', darkBackground: '#450a0a', darkText: '#fca5a5' }
		];
		for (var index = 0; index < value.length; index++) {
			hash = ((hash << 5) - hash) + value.charCodeAt(index);
			hash |= 0;
		}

		return palette[Math.abs(hash) % palette.length];
	}

	function createSubredditBadge(subreddit, extraClass) {
		var colors = subredditColor(subreddit);
		var badge = document.createElement('a');
		badge.className = 'rss-leads-subreddit-badge' + (extraClass ? ' ' + extraClass : '');
		badge.href = 'https://www.reddit.com/r/' + encodeURIComponent(subreddit) + '/';
		badge.target = '_blank';
		badge.rel = 'noreferrer';
		badge.textContent = 'r/' + subreddit;
		badge.title = 'Open r/' + subreddit + ' on Reddit';
		badge.style.setProperty('--rss-leads-subreddit-bg', colors.background);
		badge.style.setProperty('--rss-leads-subreddit-text', colors.text);
		badge.style.setProperty('--rss-leads-subreddit-dark-bg', colors.darkBackground);
		badge.style.setProperty('--rss-leads-subreddit-dark-text', colors.darkText);
		return badge;
	}

	function createCompactSubredditBadge(subreddit) {
		var colors = subredditColor(subreddit);
		var badge = document.createElement('span');
		badge.className = 'rss-leads-subreddit-badge rss-leads-compact-badge';
		badge.textContent = 'r/' + subreddit;
		badge.title = 'r/' + subreddit;
		badge.style.setProperty('--rss-leads-subreddit-bg', colors.background);
		badge.style.setProperty('--rss-leads-subreddit-text', colors.text);
		badge.style.setProperty('--rss-leads-subreddit-dark-bg', colors.darkBackground);
		badge.style.setProperty('--rss-leads-subreddit-dark-text', colors.darkText);
		return badge;
	}

	function annotateFeedEntry(entry) {
		if (entry.querySelector('.rss-leads-subreddit-badge')) {
			return;
		}

		var subreddit = extractSubredditFromUrl(entry.getAttribute('data-link'));
		if (!subreddit) {
			var articleLink = entry.querySelector('a.go_website, .item.titleAuthorSummaryDate a.title');
			subreddit = articleLink ? extractSubredditFromUrl(articleLink.href) : null;
		}
		if (!subreddit) {
			return;
		}

		var compactTarget = entry.querySelector('.flux_header .titleAuthorSummaryDate');
		if (compactTarget) {
			var compactTitle = compactTarget.querySelector('a.title');
			if (compactTitle) {
				compactTitle.insertBefore(createCompactSubredditBadge(subreddit), compactTitle.firstChild);
			}
		}

		var expandedTitle = entry.querySelector('.flux_content .content > header h1.title');
		if (expandedTitle && !expandedTitle.parentNode.querySelector('.rss-leads-subreddit-badge')) {
			expandedTitle.parentNode.insertBefore(createSubredditBadge(subreddit), expandedTitle);
		}
	}

	function annotateStandaloneArticle(article) {
		var header = article.querySelector('.content > header');
		if (!header || header.querySelector('.rss-leads-subreddit-badge')) {
			return;
		}

		var title = header.querySelector('h1.title');
		var articleLink = title ? title.querySelector('a.go_website, a[href]') : null;
		var subreddit = articleLink ? extractSubredditFromUrl(articleLink.href) : null;
		if (!subreddit || !title) {
			return;
		}

		header.insertBefore(createSubredditBadge(subreddit), title);
	}

	function annotateSubreddits() {
		Array.prototype.forEach.call(document.querySelectorAll('.flux[data-link]'), annotateFeedEntry);
		Array.prototype.forEach.call(document.querySelectorAll('article.flux_content'), annotateStandaloneArticle);
	}

	function priorityRank(priority) {
		if (priority === 'high') {
			return 3;
		}
		if (priority === 'medium') {
			return 2;
		}
		if (priority === 'low') {
			return 1;
		}
		return 0;
	}

	function renderAiResult(entry, result) {
		var priority = String(result.priority || '').toLowerCase();
		if (!priorityRank(priority)) {
			return;
		}
		entry.setAttribute('data-ai-priority', priority);
		entry.setAttribute('data-ai-summary', result.summary || '');

		var compactTarget = entry.querySelector('.flux_header .titleAuthorSummaryDate');
		if (compactTarget) {
			var compactLine = compactTarget.querySelector('.rss-leads-ai-compact');
			if (!compactLine) {
				compactLine = document.createElement('div');
				compactLine.className = 'rss-leads-ai-compact';
				compactTarget.appendChild(compactLine);
			}
			compactLine.innerHTML = '';

			var compactBadge = document.createElement('span');
			compactBadge.className = 'rss-leads-ai-priority rss-leads-ai-priority-' + priority;
			compactBadge.textContent = priority;

			var compactSummary = document.createElement('span');
			compactSummary.className = 'rss-leads-ai-summary';
			compactSummary.textContent = result.summary || '';

			compactLine.appendChild(compactBadge);
			compactLine.appendChild(compactSummary);

			var compactTitle = compactTarget.querySelector('a.title');
			if (compactTitle) {
				var inlineAi = compactTitle.querySelector('.rss-leads-ai-inline');
				if (!inlineAi) {
					inlineAi = document.createElement('span');
					inlineAi.className = 'rss-leads-ai-inline';
					var firstAiAnchor = compactTitle.querySelector('.rss-leads-compact-badge');
					if (firstAiAnchor && firstAiAnchor.nextSibling) {
						compactTitle.insertBefore(inlineAi, firstAiAnchor.nextSibling);
					} else {
						compactTitle.insertBefore(inlineAi, compactTitle.firstChild);
					}
				}
				inlineAi.className = 'rss-leads-ai-inline rss-leads-ai-inline-' + priority;
				inlineAi.textContent = 'AI: ' + priority + ' - ' + (result.summary || '');
			}
		}

		var header = entry.querySelector('.flux_content .content > header');
		if (!header) {
			return;
		}
		var title = header.querySelector('h1.title');
		if (!title) {
			return;
		}

		var card = header.querySelector('.rss-leads-ai-card');
		if (!card) {
			card = document.createElement('div');
			card.className = 'rss-leads-ai-card';
			title.insertAdjacentElement('afterend', card);
		}

		card.innerHTML = '';
		var badge = document.createElement('span');
		badge.className = 'rss-leads-ai-priority rss-leads-ai-priority-' + priority;
		badge.textContent = priority;

		var summary = document.createElement('span');
		summary.className = 'rss-leads-ai-summary';
		summary.textContent = result.summary || '';

		card.appendChild(badge);
		card.appendChild(summary);
	}

	function applyAiSort() {
		var stream = document.getElementById('stream');
		if (!stream) {
			return;
		}
		var hasRankedEntries = false;
		Array.prototype.forEach.call(stream.querySelectorAll('.flux[data-entry]'), function (entry) {
			var rank = priorityRank(entry.getAttribute('data-ai-priority'));
			if (rank > 0) {
				hasRankedEntries = true;
				entry.style.order = String(40 - (rank * 10));
			} else {
				entry.style.order = '50';
			}
		});
		if (hasRankedEntries) {
			stream.classList.add('rss-leads-ai-sorted');
		}
	}

	function renderAiResults(items) {
		Object.keys(items || {}).forEach(function (entryId) {
			Array.prototype.some.call(document.querySelectorAll('.flux[data-entry]'), function (entry) {
				if (entry.getAttribute('data-entry') === entryId) {
					renderAiResult(entry, items[entryId]);
					return true;
				}
				return false;
			});
		});
		applyAiSort();
	}

	function fetchAiResults() {
		var ids = [];
		Array.prototype.forEach.call(document.querySelectorAll('.flux[data-entry]'), function (entry) {
			var id = entry.getAttribute('data-entry');
			if (id && ids.indexOf(id) === -1) {
				ids.push(id);
			}
		});
		if (!ids.length) {
			return;
		}

		window.fetch(aiUrl + '?ids=' + encodeURIComponent(ids.slice(0, 120).join(',')), {
			credentials: 'same-origin',
			cache: 'no-store'
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('status ' + response.status);
				}
				return response.json();
			})
			.then(function (data) {
				if (data && data.ok) {
					renderAiResults(data.items || {});
				}
			})
			.catch(function () {
				// AI results are opportunistic; keep the feed usable if the worker has not run yet.
			});
	}

	function queueAiAnnotation() {
		if (aiRefreshQueued) {
			return;
		}
		aiRefreshQueued = true;
		window.setTimeout(function () {
			aiRefreshQueued = false;
			fetchAiResults();
		}, 800);
	}

	function queueSubredditAnnotation() {
		if (subredditRefreshQueued) {
			return;
		}
		subredditRefreshQueued = true;
		window.requestAnimationFrame(function () {
			subredditRefreshQueued = false;
			annotateSubreddits();
			queueAiAnnotation();
		});
	}

	function watchSubredditEntries() {
		annotateSubreddits();
		if (subredditObserver || !window.MutationObserver) {
			return;
		}
		subredditObserver = new MutationObserver(queueSubredditAnnotation);
		subredditObserver.observe(document.body, {
			childList: true,
			subtree: true
		});
	}

	function mountWidget() {
		if (document.getElementById('rss-leads-status-widget')) {
			return true;
		}
		var target = document.getElementById('nav_menu_actualize') || document.getElementById('nav_menu_actions');
		if (!target) {
			return false;
		}

		var widget = document.createElement('div');
		widget.id = 'rss-leads-status-widget';

		statusText = document.createElement('span');
		statusText.className = 'rss-leads-status-text';
		statusText.textContent = 'Reddit status loading';

		refreshButton = document.createElement('button');
		refreshButton.type = 'button';
		refreshButton.className = 'btn rss-leads-refresh-btn';
		refreshButton.textContent = 'Refresh Reddit';
		refreshButton.title = 'Refresh Reddit leads feed now';
		refreshButton.addEventListener('click', manualRefresh);

		widget.appendChild(statusText);
		widget.appendChild(refreshButton);
		target.insertAdjacentElement('afterend', widget);
		return true;
	}

	function init() {
		if (!mountWidget()) {
			window.setTimeout(init, 500);
			return;
		}
		pollStatus();
		watchSubredditEntries();
		fetchAiResults();
		timerId = window.setInterval(updateTimer, 1000);
		pollId = window.setInterval(pollStatus, 15000);
		aiPollId = window.setInterval(fetchAiResults, 60000);
		window.addEventListener('beforeunload', function () {
			window.clearInterval(timerId);
			window.clearInterval(pollId);
			window.clearInterval(aiPollId);
			if (subredditObserver) {
				subredditObserver.disconnect();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
