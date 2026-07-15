'use strict';

// Reddit subreddit badges and compact title metadata helpers.

function rssLeadsNormalizeSubredditName(value) {
	var match = String(value || '').match(/^[A-Za-z0-9_]{2,21}$/);
	return match ? match[0] : null;
}

function rssLeadsExtractSubredditFromUrl(value) {
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
				return rssLeadsNormalizeSubredditName(decodeURIComponent(segments[index + 1]));
			}
		}
	} catch (error) {
		var fallback = String(value).match(/(?:^|\/\/)(?:www\.|old\.|new\.)?reddit\.com\/r\/([A-Za-z0-9_]{2,21})(?:[/?#]|$)/i);
		return fallback ? rssLeadsNormalizeSubredditName(fallback[1]) : null;
	}

	return null;
}

function rssLeadsSubredditTone(subreddit) {
	var hash = 0;
	var value = String(subreddit || '').toLowerCase();
	for (var index = 0; index < value.length; index++) {
		hash = ((hash << 5) - hash) + value.charCodeAt(index);
		hash |= 0;
	}

	return Math.abs(hash) % 5;
}

function rssLeadsCreateSubredditBadge(subreddit, extraClass) {
	var badge = document.createElement('a');
	badge.className = 'rss-leads-subreddit-badge rss-leads-subreddit-tone-' + String(rssLeadsSubredditTone(subreddit)) + (extraClass ? ' ' + extraClass : '');
	badge.href = 'https://www.reddit.com/r/' + encodeURIComponent(subreddit) + '/';
	badge.target = '_blank';
	badge.rel = 'noreferrer';
	badge.textContent = 'r/' + subreddit;
	badge.title = 'Open r/' + subreddit + ' on Reddit';
	return badge;
}

function rssLeadsCreateCompactSubredditBadge(subreddit) {
	var badge = document.createElement('span');
	badge.className = 'rss-leads-subreddit-badge rss-leads-compact-badge rss-leads-subreddit-tone-' + String(rssLeadsSubredditTone(subreddit));
	badge.textContent = 'r/' + subreddit;
	badge.title = 'r/' + subreddit;
	return badge;
}

function rssLeadsSubredditFromFeedEntry(entry) {
	var subreddit = rssLeadsExtractSubredditFromUrl(entry.getAttribute('data-link'));
	if (!subreddit) {
		var articleLink = entry.querySelector('a.go_website, .item.titleAuthorSummaryDate a.title, .item.titleAuthorSummaryDate a[href]');
		subreddit = articleLink ? rssLeadsExtractSubredditFromUrl(articleLink.href) : null;
	}
	return subreddit;
}

function rssLeadsEnsureCompactMeta(compactTarget) {
	var meta = compactTarget.querySelector('.rss-leads-title-meta');
	if (!meta) {
		meta = document.createElement('div');
		meta.className = 'rss-leads-title-meta';
		var title = compactTarget.querySelector('a.title, .title');
		var compactLine = compactTarget.querySelector('.rss-leads-ai-compact');
		compactTarget.classList.add('rss-leads-title-meta-ready');
		compactTarget.insertBefore(meta, title || compactLine || null);
	}
	return meta;
}

function rssLeadsClearCompactAi(compactTarget) {
	var meta = compactTarget.querySelector('.rss-leads-title-meta');
	if (meta) {
		Array.prototype.forEach.call(meta.querySelectorAll('.rss-leads-ai-inline, .rss-leads-ai-job-type, .rss-leads-ai-cv-fit, .rss-leads-ai-monthly, .rss-leads-ai-hourly, .rss-leads-ai-scam, .rss-leads-compact-badge'), function (node) {
			if (node.parentNode) {
				node.parentNode.removeChild(node);
			}
		});
		if (!meta.children.length && meta.parentNode) {
			meta.parentNode.removeChild(meta);
			compactTarget.classList.remove('rss-leads-title-meta-ready');
		}
	}
	Array.prototype.forEach.call(compactTarget.querySelectorAll('.rss-leads-ai-compact'), function (node) {
		if (node.parentNode) {
			node.parentNode.removeChild(node);
		}
	});
}

function rssLeadsAnnotateFeedEntry(entry) {
	var subreddit = rssLeadsSubredditFromFeedEntry(entry);
	if (!subreddit) {
		return;
	}

	var compactTarget = entry.querySelector('.flux_header .titleAuthorSummaryDate');
	if (compactTarget && !compactTarget.querySelector('.rss-leads-compact-badge')) {
		rssLeadsEnsureCompactMeta(compactTarget).appendChild(rssLeadsCreateCompactSubredditBadge(subreddit));
	}

	var expandedTitle = entry.querySelector('.flux_content .content > header h1.title');
	if (expandedTitle && !expandedTitle.parentNode.querySelector('.rss-leads-subreddit-badge')) {
		expandedTitle.parentNode.insertBefore(rssLeadsCreateSubredditBadge(subreddit), expandedTitle);
	}
}

function rssLeadsAnnotateStandaloneArticle(article) {
	var header = article.querySelector('.content > header');
	if (!header || header.querySelector('.rss-leads-subreddit-badge')) {
		return;
	}

	var title = header.querySelector('h1.title');
	var articleLink = title ? title.querySelector('a.go_website, a[href]') : null;
	var subreddit = articleLink ? rssLeadsExtractSubredditFromUrl(articleLink.href) : null;
	if (!subreddit || !title) {
		return;
	}

	header.insertBefore(rssLeadsCreateSubredditBadge(subreddit), title);
}

function rssLeadsAnnotateSubreddits() {
	Array.prototype.forEach.call(document.querySelectorAll('.flux[data-link]'), rssLeadsAnnotateFeedEntry);
	Array.prototype.forEach.call(document.querySelectorAll('article.flux_content'), rssLeadsAnnotateStandaloneArticle);
}
