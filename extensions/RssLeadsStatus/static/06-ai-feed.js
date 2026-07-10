'use strict';

// AI result rendering, filtering, pinning, and API fetches.

function rssLeadsOrderLeadSidebar() {
	var orderByLabel = {
		'reddit leads': 'rss-leads-sidebar-reddit',
		'low priority': 'rss-leads-sidebar-low',
		'low-medium priority': 'rss-leads-sidebar-low-medium',
		'medium priority': 'rss-leads-sidebar-medium',
		'top priority': 'rss-leads-sidebar-priority',
		'low medium high priority': 'rss-leads-sidebar-priority',
		'low/medium/high priority': 'rss-leads-sidebar-priority',
		'low, medium, high priority': 'rss-leads-sidebar-priority',
		'priority leads': 'rss-leads-sidebar-priority',
		'high priority': 'rss-leads-sidebar-high',
		'not hiring': 'rss-leads-sidebar-not-hiring'
	};
	var orderClasses = [
		'rss-leads-sidebar-main',
		'rss-leads-sidebar-reddit',
		'rss-leads-sidebar-low',
		'rss-leads-sidebar-low-medium',
		'rss-leads-sidebar-medium',
		'rss-leads-sidebar-priority',
		'rss-leads-sidebar-high',
		'rss-leads-sidebar-not-hiring'
	];
	Array.prototype.forEach.call(document.querySelectorAll('#sidebar > li'), function (item) {
		item.classList.remove.apply(item.classList, orderClasses);
		if (item.classList.contains('category') && item.classList.contains('all')) {
			item.classList.add('rss-leads-sidebar-main');
			return;
		}
		var labelNode = item.querySelector('a.tree-folder-title, a.title, a[href*="get="]');
		var label = rssLeadsNormalizeText(labelNode ? labelNode.textContent : item.textContent).toLowerCase();
		label = label.replace(/\s+\d+$/, '');
		if (Object.prototype.hasOwnProperty.call(orderByLabel, label)) {
			item.classList.add(orderByLabel[label]);
		}
	});
}

function rssLeadsRedditLeadsCategoryGetId() {
	var links = document.querySelectorAll('#aside_feed a.tree-folder-title, #aside_feed a[href*="get="]');
	for (var index = 0; index < links.length; index++) {
		var link = links[index];
		if (rssLeadsNormalizeText(link.textContent) !== 'Reddit Leads') {
			continue;
		}
		try {
			return new URL(link.href, window.location.href).searchParams.get('get') || '';
		} catch (error) {
			var match = String(link.getAttribute('href') || '').match(/[?&]get=([^&]+)/);
			return match ? decodeURIComponent(match[1]) : '';
		}
	}
	return '';
}

function rssLeadsIsRedditLeadsView() {
	var categoryId = rssLeadsRedditLeadsCategoryGetId();
	if (!categoryId) {
		return false;
	}
	try {
		return new URL(window.location.href).searchParams.get('get') === categoryId;
	} catch (error) {
		return window.location.search.indexOf('get=' + encodeURIComponent(categoryId)) !== -1;
	}
}

function rssLeadsUpdateRedditLeadsVisibility(entry) {
	if (!entry || !entry.classList) {
		return;
	}
	var hide = rssLeadsIsRedditLeadsView() && rssLeadsNormalizePriority(entry.getAttribute('data-ai-priority')) === 'not_hiring';
	entry.classList.toggle('rss-leads-hidden-not-hiring', hide);
}

function rssLeadsUpdateAllRedditLeadsVisibility() {
	Array.prototype.forEach.call(document.querySelectorAll('.flux[data-entry]'), rssLeadsUpdateRedditLeadsVisibility);
}

function rssLeadsRenderAiResult(entry, result) {
	result = rssLeadsDisplayResultForEntry(entry, result);
	var priority = rssLeadsNormalizePriority(result.priority);
	if (!rssLeadsPriorityRank(priority)) {
		return;
	}
	entry.setAttribute('data-ai-priority', priority);
	if (result.fallback) {
		entry.setAttribute('data-ai-fallback', '1');
	} else {
		entry.removeAttribute('data-ai-fallback');
	}
	entry.setAttribute('data-ai-summary', result.summary || '');
	if (rssLeadsJobTypeLabel(result)) {
		entry.setAttribute('data-ai-job-type', rssLeadsJobTypeLabel(result));
	} else {
		entry.removeAttribute('data-ai-job-type');
	}
	rssLeadsUpdateRedditLeadsVisibility(entry);

	var compactTarget = entry.querySelector('.flux_header .titleAuthorSummaryDate');
	if (compactTarget) {
		rssLeadsClearCompactAi(compactTarget);
		var compactMeta = rssLeadsEnsureCompactMeta(compactTarget);
		var inlineAi = document.createElement('span');
		inlineAi.className = 'rss-leads-ai-inline rss-leads-ai-inline-' + priority;
		inlineAi.textContent = rssLeadsPriorityLabel(priority);
		rssLeadsApplyBadgeColors(inlineAi, rssLeadsPriorityColor(priority));
		compactMeta.appendChild(inlineAi);

		var subreddit = rssLeadsSubredditFromFeedEntry(entry);
		if (subreddit) {
			compactMeta.appendChild(rssLeadsCreateCompactSubredditBadge(subreddit));
		}

		var amount = rssLeadsMonthlyAmountLabel(result);
		var hourlyAmount = rssLeadsHourlyAmountLabel(result);
		var compactJobTypeLabel = rssLeadsJobTypeLabel(result);
		var compactScamLikelihood = rssLeadsScamLikelihood(result);
		if (compactJobTypeLabel) {
			var compactJobType = document.createElement('span');
			compactJobType.className = 'rss-leads-ai-job-type';
			compactJobType.textContent = compactJobTypeLabel;
			compactMeta.appendChild(compactJobType);
		}
		if (amount) {
			var compactAmount = document.createElement('span');
			compactAmount.className = 'rss-leads-ai-monthly';
			compactAmount.textContent = amount;
			compactMeta.appendChild(compactAmount);
		}
		if (hourlyAmount) {
			var compactHourly = document.createElement('span');
			compactHourly.className = 'rss-leads-ai-hourly';
			compactHourly.textContent = hourlyAmount;
			compactMeta.appendChild(compactHourly);
		}
		if (rssLeadsShouldShowScamBadge(compactScamLikelihood)) {
			compactMeta.appendChild(rssLeadsCreateScamBadge(compactScamLikelihood));
		}
		if ((priority === 'high' || priority === 'x_high') && result.summary) {
			var compactLine = document.createElement('div');
			compactLine.className = 'rss-leads-ai-compact';
			var compactSummary = document.createElement('span');
			compactSummary.className = 'rss-leads-ai-summary';
			compactSummary.textContent = result.summary;
			compactLine.appendChild(compactSummary);
			compactTarget.appendChild(compactLine);
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
	badge.textContent = rssLeadsPriorityLabel(priority);

	var amountLabel = rssLeadsMonthlyAmountLabel(result);
	var hourlyLabel = rssLeadsHourlyAmountLabel(result);
	var jobType = rssLeadsJobTypeLabel(result);
	var scamScore = rssLeadsScamLikelihood(result);
	var summary = document.createElement('span');
	summary.className = 'rss-leads-ai-summary';
	summary.textContent = result.summary || '';

	card.appendChild(badge);
	if (jobType) {
		var jobTypeBadge = document.createElement('span');
		jobTypeBadge.className = 'rss-leads-ai-job-type';
		jobTypeBadge.textContent = jobType;
		card.appendChild(jobTypeBadge);
	}
	if (amountLabel) {
		var amountBadge = document.createElement('span');
		amountBadge.className = 'rss-leads-ai-monthly';
		amountBadge.textContent = amountLabel;
		card.appendChild(amountBadge);
	}
	if (hourlyLabel) {
		var hourlyBadge = document.createElement('span');
		hourlyBadge.className = 'rss-leads-ai-hourly';
		hourlyBadge.textContent = hourlyLabel;
		card.appendChild(hourlyBadge);
	}
	if (rssLeadsShouldShowScamBadge(scamScore)) {
		card.appendChild(rssLeadsCreateScamBadge(scamScore));
	}
	card.appendChild(summary);
}

function rssLeadsEntryIsUnread(entry) {
	if (!entry || !entry.classList) {
		return false;
	}
	if (
		entry.classList.contains('not_read')
		|| entry.classList.contains('unread')
		|| entry.classList.contains('flux_unread')
		|| entry.classList.contains('state_unread')
	) {
		return true;
	}
	if (
		entry.classList.contains('read')
		|| entry.classList.contains('flux_read')
		|| entry.classList.contains('state_read')
	) {
		return false;
	}
	var dataRead = String(entry.getAttribute('data-read') || entry.getAttribute('aria-read') || '').toLowerCase();
	if (dataRead === '0' || dataRead === 'false' || dataRead === 'unread') {
		return true;
	}
	if (dataRead === '1' || dataRead === 'true' || dataRead === 'read') {
		return false;
	}
	return true;
}

function rssLeadsApplyAiSort() {
	var stream = document.getElementById('stream');
	if (!stream) {
		return;
	}
	var scrollAnchor = rssLeadsActiveScrollAnchor();
	rssLeadsUpdateAllRedditLeadsVisibility();
	Array.prototype.forEach.call(stream.querySelectorAll('.flux[data-entry]'), function (entry) {
		entry.removeAttribute('data-ai-pinned');
	});
	stream.classList.remove('rss-leads-ai-sorted');
	rssLeadsRestoreScrollAnchor(scrollAnchor);
}

function rssLeadsRenderAiResults(items) {
	var scrollAnchor = rssLeadsActiveScrollAnchor();
	var classifiedIds = {};
	Object.keys(items || {}).forEach(function (entryId) {
		Array.prototype.some.call(document.querySelectorAll('.flux[data-entry]'), function (entry) {
			if (entry.getAttribute('data-entry') === entryId) {
				rssLeadsRenderAiResult(entry, items[entryId]);
				classifiedIds[entryId] = true;
				return true;
			}
			return false;
		});
	});
	rssLeadsRenderFallbackAiResults(classifiedIds, true);
	rssLeadsApplyAiSort();
	rssLeadsRestoreScrollAnchor(scrollAnchor);
}

function rssLeadsRenderFallbackAiResults(classifiedIds, skipSort) {
	var scrollAnchor = rssLeadsActiveScrollAnchor();
	Array.prototype.forEach.call(document.querySelectorAll('.flux[data-entry]'), function (entry) {
		var id = entry.getAttribute('data-entry') || '';
		if (id && classifiedIds[id]) {
			return;
		}
		var hasPriority = entry.hasAttribute('data-ai-priority');
		var isFallback = entry.getAttribute('data-ai-fallback') === '1';
		if (hasPriority && !isFallback) {
			return;
		}
		if (isFallback && entry.querySelector('.flux_header .rss-leads-ai-inline')) {
			return;
		}
		var result = rssLeadsFallbackAiResult(entry);
		if (result) {
			rssLeadsRenderAiResult(entry, result);
		}
	});
	if (!skipSort) {
		rssLeadsUpdateAllRedditLeadsVisibility();
		rssLeadsApplyAiSort();
	}
	rssLeadsRestoreScrollAnchor(scrollAnchor);
}

function rssLeadsFetchAiResults(includeStatus) {
	includeStatus = includeStatus === true;
	var ids = [];
	Array.prototype.forEach.call(document.querySelectorAll('.flux[data-entry]'), function (entry) {
		var id = entry.getAttribute('data-entry');
		if (id && ids.indexOf(id) === -1) {
			ids.push(id);
		}
	});
	var url = rssLeadsAiUrl + (includeStatus ? '?status=1' : '?status=0');
	if (ids.length) {
		url += '&ids=' + encodeURIComponent(ids.slice(0, 120).join(','));
	}

	window.fetch(url, {
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
				rssLeadsRenderAiResults(data.items || {});
				if (includeStatus) {
					rssLeadsRenderAiStatus(data.status || {});
				}
			} else {
				if (includeStatus) {
					rssLeadsRenderAiStatus({
						state: {
							current_status: 'failed',
							last_message: data && data.error ? data.error : 'AI status endpoint returned an error.',
							errors: [{
								at: Math.floor(Date.now() / 1000),
								stage: 'browser_fetch',
								type: 'ai_endpoint_error',
								message: data && data.error ? data.error : 'AI status endpoint returned an error.'
							}]
						}
					});
				}
			}
		})
		.catch(function (error) {
			rssLeadsRenderFallbackAiResults({});
			if (includeStatus) {
				rssLeadsRenderAiStatus({
					state: {
						current_status: 'failed',
						last_message: 'AI status unavailable.',
						errors: [{
							at: Math.floor(Date.now() / 1000),
							stage: 'browser_fetch',
							type: 'ai_status_fetch_failed',
							message: error && error.message ? error.message : 'Could not fetch AI status.'
						}]
					}
				});
			}
		});
}
