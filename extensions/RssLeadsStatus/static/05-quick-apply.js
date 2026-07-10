'use strict';

// Quick Apply prompt generation and buttons.

function rssLeadsFirstText(root, selectors) {
	for (var index = 0; index < selectors.length; index++) {
		var node = root.querySelector(selectors[index]);
		var text = node ? rssLeadsNormalizeText(node.textContent) : '';
		if (text) {
			return text;
		}
	}
	return '';
}

function rssLeadsFirstHref(root, selectors) {
	for (var index = 0; index < selectors.length; index++) {
		var node = root.querySelector(selectors[index]);
		var href = node ? String(node.href || node.getAttribute('href') || '').trim() : '';
		if (href && href.indexOf('javascript:') !== 0 && href !== '#') {
			return href;
		}
	}
	return '';
}

function rssLeadsRemoveNodes(root, selectors) {
	selectors.forEach(function (selector) {
		Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) {
			if (node.parentNode) {
				node.parentNode.removeChild(node);
			}
		});
	});
}

function rssLeadsArticleText(root) {
	var content = root.querySelector('.flux_content .content') || root.querySelector('article.flux_content .content') || root.querySelector('.content');
	if (!content) {
		return rssLeadsFirstText(root, ['.flux_header .rss-leads-ai-summary', '.flux_header .item.summary', '.flux_header .summary']);
	}
	var clone = content.cloneNode(true);
	rssLeadsRemoveNodes(clone, [
		'script',
		'style',
		'header',
		'footer',
		'nav',
		'.rss-leads-ai-card',
		'.rss-leads-quick-apply-actions',
		'.rss-leads-quick-apply-btn',
		'.item.manage',
		'.dropdown'
	]);
	return rssLeadsNormalizeText(clone.textContent);
}

function rssLeadsLeadLink(root) {
	var dataLink = root.getAttribute && root.getAttribute('data-link') ? String(root.getAttribute('data-link')).trim() : '';
	return dataLink || rssLeadsFirstHref(root, [
		'.flux_content .content > header h1.title a.go_website',
		'.flux_content .content > header h1.title a[href]',
		'.flux_content a.go_website',
		'.flux_header a.go_website',
		'.flux_header a.title[href]',
		'h1.title a[href]'
	]);
}

function rssLeadsLeadSubreddit(root, link) {
	var badge = rssLeadsFirstText(root, ['.rss-leads-subreddit-badge', '.rss-leads-compact-badge']);
	if (badge.indexOf('r/') === 0) {
		return badge;
	}
	var subreddit = rssLeadsExtractSubredditFromUrl(link);
	return subreddit ? 'r/' + subreddit : '';
}

function rssLeadsLeadContext(root) {
	var link = rssLeadsLeadLink(root);
	var title = rssLeadsFirstText(root, [
		'.flux_content .content > header h1.title',
		'article.flux_content .content > header h1.title',
		'.flux_header .titleAuthorSummaryDate a.title',
		'.flux_header a.title',
		'h1.title'
	]);
	var priority = String(root.getAttribute && root.getAttribute('data-ai-priority') || '').trim();
	var aiSummary = String(root.getAttribute && root.getAttribute('data-ai-summary') || '').trim() ||
		rssLeadsFirstText(root, ['.rss-leads-ai-card .rss-leads-ai-summary', '.flux_header .rss-leads-ai-summary']);
	var jobType = String(root.getAttribute && root.getAttribute('data-ai-job-type') || '').trim() ||
		rssLeadsFirstText(root, ['.rss-leads-ai-card .rss-leads-ai-job-type', '.flux_header .rss-leads-ai-job-type']);
	var budget = rssLeadsFirstText(root, ['.rss-leads-ai-card .rss-leads-ai-hourly', '.flux_header .rss-leads-ai-hourly', '.rss-leads-ai-card .rss-leads-ai-monthly', '.flux_header .rss-leads-ai-monthly']);
	var scamRisk = rssLeadsFirstText(root, ['.rss-leads-ai-card .rss-leads-ai-scam', '.flux_header .rss-leads-ai-scam']);
	var author = rssLeadsFirstText(root, ['.author', '.flux_header .item.website', '.flux_header .website']);

	return {
		title: rssLeadsLimitText(title, 220),
		link: link,
		author: rssLeadsLimitText(author, 120),
		subreddit: rssLeadsLeadSubreddit(root, link),
		priority: priority ? rssLeadsPriorityLabel(priority) : '',
		aiSummary: rssLeadsLimitText(aiSummary, 500),
		jobType: rssLeadsLimitText(jobType, 120),
		budget: rssLeadsLimitText(budget, 120),
		scamRisk: rssLeadsLimitText(scamRisk, 80),
		content: rssLeadsLimitText(rssLeadsArticleText(root), 1800)
	};
}

function rssLeadsBuildQuickApplyPrompt(root) {
	var profile = rssLeadsLimitText(rssLeadsGetSavedCvProfile(), 2800);
	var lead = rssLeadsLeadContext(root);
	var lines = [
		'Create a concise DM I can send to apply for this opportunity.',
		'Use my CV/profile and work experience below. Match my experience to the lead, sound direct and human, and do not invent facts.',
		'Rules:',
		'- Keep it around 90-140 words.',
		'- Open with a natural greeting only if a name is visible.',
		'- Show that I understood the client need.',
		'- Include 2-3 relevant proof points or capabilities from my profile.',
		'- Ask one concrete next-step question.',
		'- Do not include placeholders, brackets, or fake metrics.',
		'',
		'My CV/profile:',
		profile,
		'',
		'Lead details:',
		'Title: ' + (lead.title || 'Unknown'),
		lead.author ? 'Author/source: ' + lead.author : '',
		lead.subreddit ? 'Subreddit: ' + lead.subreddit : '',
		lead.priority ? 'AI priority: ' + lead.priority : '',
		lead.jobType ? 'Job type: ' + lead.jobType : '',
		lead.budget ? 'Budget: ' + lead.budget : '',
		lead.scamRisk ? 'Scam likelihood: ' + lead.scamRisk : '',
		lead.aiSummary ? 'AI summary: ' + lead.aiSummary : '',
		lead.link ? 'URL: ' + lead.link : '',
		lead.content ? 'Post text: ' + lead.content : ''
	];
	return lines.filter(function (line) {
		return line !== '';
	}).join('\n');
}

function rssLeadsCopyQuickApplyPrompt(prompt) {
	if (!navigator.clipboard || !window.isSecureContext) {
		return;
	}
	navigator.clipboard.writeText(prompt).catch(function () {
		// Opening ChatGPT is the primary action; clipboard support is best-effort.
	});
}

function rssLeadsOpenQuickApplyPrompt(root) {
	if (!rssLeadsGetSavedCvProfile()) {
		rssLeadsOpenCvProfilePanel();
		rssLeadsShowToast('Add CV profile first', 'Save your work experience once, then Quick apply can create ChatGPT prompts.', 'warning');
		return;
	}
	var prompt = rssLeadsBuildQuickApplyPrompt(root);
	var url = 'https://chatgpt.com/?q=' + encodeURIComponent(prompt);
	var opened = window.open(url, '_blank');
	rssLeadsCopyQuickApplyPrompt(prompt);
	if (opened) {
		try {
			opened.opener = null;
		} catch (error) {
			// Some browsers restrict access immediately after opening a new tab.
		}
		rssLeadsShowToast('ChatGPT opened', 'A quick-apply DM prompt was sent to ChatGPT.', 'success');
	} else {
		rssLeadsShowToast('ChatGPT popup blocked', 'Allow popups for FreshRSS, then click Quick apply again.', 'error');
	}
}

function rssLeadsCreateQuickApplyButton(root, compact) {
	var button = document.createElement('button');
	button.type = 'button';
	button.className = 'btn rss-leads-quick-apply-btn ' + (compact ? 'rss-leads-quick-apply-compact' : 'rss-leads-quick-apply-expanded');
	button.textContent = compact ? 'Apply' : 'Quick apply';
	button.title = 'Create an application DM in ChatGPT';
	button.addEventListener('click', function (event) {
		event.preventDefault();
		event.stopPropagation();
		rssLeadsOpenQuickApplyPrompt(root);
	});
	return button;
}

function rssLeadsAnnotateQuickApplyEntry(entry) {
	var compactHeader = entry.querySelector('.flux_header');
	if (compactHeader && !compactHeader.querySelector('.rss-leads-quick-apply-compact')) {
		compactHeader.appendChild(rssLeadsCreateQuickApplyButton(entry, true));
		entry.classList.add('rss-leads-quick-apply-ready');
	}

	var expandedHeader = entry.querySelector('.flux_content .content > header');
	if (expandedHeader && !expandedHeader.querySelector('.rss-leads-quick-apply-actions')) {
		var actions = document.createElement('div');
		actions.className = 'rss-leads-quick-apply-actions';
		actions.appendChild(rssLeadsCreateQuickApplyButton(entry, false));
		expandedHeader.insertBefore(actions, expandedHeader.firstChild);
	}
}

function rssLeadsAnnotateStandaloneQuickApply(article) {
	var header = article.querySelector('.content > header');
	if (!header || header.querySelector('.rss-leads-quick-apply-actions')) {
		return;
	}
	var actions = document.createElement('div');
	actions.className = 'rss-leads-quick-apply-actions';
	actions.appendChild(rssLeadsCreateQuickApplyButton(article, false));
	header.insertBefore(actions, header.firstChild);
}

function rssLeadsAnnotateQuickApplyButtons() {
	Array.prototype.forEach.call(document.querySelectorAll('.flux[data-entry]'), rssLeadsAnnotateQuickApplyEntry);
	Array.prototype.forEach.call(document.querySelectorAll('article.flux_content'), rssLeadsAnnotateStandaloneQuickApply);
}
