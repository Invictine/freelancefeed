'use strict';

// Quick Apply prompt generation and buttons.

var rssLeadsQuickApplyPanel;
var rssLeadsQuickApplyActiveRoot;

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

function rssLeadsRedditUsernameFromValue(value) {
	value = String(value || '').trim();
	if (!value) {
		return '';
	}
	try {
		value = decodeURIComponent(value);
	} catch (error) {
		// Keep the original value when it is not URI encoded.
	}
	var match = value.match(/(?:reddit\.com\/(?:user|u)\/|(?:^|[;\s])\/?u\/)([A-Za-z0-9_-]{3,20})(?:[/?#\s]|$)/i);
	if (!match || /^(?:automoderator|\[deleted\])$/i.test(match[1])) {
		return '';
	}
	return match[1];
}

function rssLeadsLeadRedditUsername(root) {
	var author = rssLeadsFirstText(root, [
		'.author',
		'.item.author',
		'.flux_header .author',
		'.flux_content .author'
	]);
	var username = rssLeadsRedditUsernameFromValue(author);
	if (username) {
		return username;
	}

	var anchors = root.querySelectorAll ? root.querySelectorAll('a[href]') : [];
	for (var index = 0; index < anchors.length; index++) {
		username = rssLeadsRedditUsernameFromValue(anchors[index].href || anchors[index].getAttribute('href'));
		if (username) {
			return username;
		}
	}
	return '';
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
	var redditUsername = rssLeadsLeadRedditUsername(root);
	var author = redditUsername ? 'u/' + redditUsername : rssLeadsFirstText(root, ['.author', '.flux_header .item.website', '.flux_header .website']);

	return {
		title: rssLeadsLimitText(title, 220),
		link: link,
		author: rssLeadsLimitText(author, 120),
		redditUsername: redditUsername,
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
		'- Return only the finished DM text, with no introduction, explanation, quotation marks, or Markdown fence.',
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

function rssLeadsQuickApplyChatGptUrl(prompt) {
	return 'https://chatgpt.com/?q=' + encodeURIComponent(prompt);
}

function rssLeadsRedditComposeUrl(username, subject, message) {
	var params = [];
	if (username) {
		params.push('to=' + encodeURIComponent(username));
	}
	if (subject) {
		params.push('subject=' + encodeURIComponent(subject));
	}
	if (message) {
		params.push('message=' + encodeURIComponent(message));
	}
	return 'https://www.reddit.com/message/compose/' + (params.length ? '?' + params.join('&') : '');
}

function rssLeadsQuickApplySubject(lead) {
	return rssLeadsLimitText('Application: ' + (lead.title || lead.jobType || 'Reddit opportunity'), 100);
}

function rssLeadsSetQuickApplyButtonState(root, state) {
	Array.prototype.forEach.call(root.querySelectorAll('.rss-leads-quick-apply-btn'), function (button) {
		var compact = button.classList.contains('rss-leads-quick-apply-compact');
		button.setAttribute('data-quick-apply-state', state);
		if (state === 'draft-ready') {
			button.textContent = compact ? 'Open DM' : 'Review application DM';
			button.title = 'Review the prepared text and open the Reddit DM';
		} else if (state === 'opened') {
			button.textContent = compact ? 'Reopen' : 'Reopen application DM';
			button.title = 'Review or reopen this application DM';
		} else if (state === 'awaiting-response') {
			button.textContent = compact ? 'Finish DM' : 'Finish quick apply';
			button.title = 'Paste the finished ChatGPT response and open Reddit';
		} else {
			button.textContent = compact ? 'Apply' : 'Quick apply';
			button.title = 'Draft an application DM with ChatGPT, then open Reddit';
		}
	});
}

function rssLeadsFocusWindow(opened) {
	if (!opened) {
		return;
	}
	try {
		opened.opener = null;
	} catch (error) {
		// Some browsers restrict access immediately after opening a new tab.
	}
}

function rssLeadsUseQuickApplyResponse(root, response) {
	var session = root._rssLeadsQuickApplySession;
	response = String(response || '').trim();
	if (!session || !response) {
		rssLeadsShowToast('ChatGPT reply not found', 'Copy the finished ChatGPT DM, then click Paste DM again.', 'warning');
		return;
	}
	if (response === session.prompt || response.indexOf('Create a concise DM I can send') === 0) {
		rssLeadsShowToast('Prompt is still copied', 'Use ChatGPT\'s Copy button on the finished response, then click Paste DM again.', 'warning');
		return;
	}
	session.response = response;
	rssLeadsSetQuickApplyButtonState(root, 'draft-ready');
	rssLeadsQuickApplyRender(root);
}

function rssLeadsPasteQuickApplyResponse(root) {
	if (navigator.clipboard && navigator.clipboard.readText && window.isSecureContext) {
		navigator.clipboard.readText().then(function (response) {
			rssLeadsUseQuickApplyResponse(root, response);
		}).catch(function () {
			var textarea = rssLeadsQuickApplyPanel && rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-draft');
			if (textarea) textarea.focus();
			rssLeadsShowToast('Paste into the DM box', 'Clipboard access was blocked, so use Ctrl+V or long-press Paste.', 'warning');
		});
		return;
	}
	var textarea = rssLeadsQuickApplyPanel && rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-draft');
	if (textarea) textarea.focus();
	rssLeadsShowToast('Paste into the DM box', 'Use Ctrl+V or long-press Paste, then review the text.', 'info');
}

function rssLeadsQuickApplyOpenChatGpt(root) {
	var session = root && root._rssLeadsQuickApplySession;
	if (!session) return;
	var opened = window.open(rssLeadsQuickApplyChatGptUrl(session.prompt), '_blank');
	rssLeadsCopyQuickApplyPrompt(session.prompt);
	rssLeadsFocusWindow(opened);
	if (opened) {
		rssLeadsShowToast('ChatGPT opened', 'Generate the DM, copy it, then return to the Quick Apply panel.', 'success');
	} else {
		rssLeadsShowToast('ChatGPT popup blocked', 'Allow popups for FreshRSS, then select Open ChatGPT.', 'error');
	}
}

function rssLeadsQuickApplyOpenReddit(root) {
	var session = root && root._rssLeadsQuickApplySession;
	var textarea = rssLeadsQuickApplyPanel && rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-draft');
	var response = textarea ? textarea.value.trim() : String(session && session.response || '').trim();
	if (!session || !response) {
		rssLeadsShowToast('Paste the finished DM first', 'The Reddit button becomes available after the DM text is added.', 'warning');
		return;
	}
	if (response === session.prompt || response.indexOf('Create a concise DM I can send') === 0) {
		rssLeadsShowToast('That is the prompt, not the DM', 'Copy ChatGPT\'s finished response and paste it into the DM box.', 'warning');
		return;
	}
	session.response = response;
	var opened = window.open(rssLeadsRedditComposeUrl(session.username, session.subject, response), '_blank');
	rssLeadsFocusWindow(opened);
	if (!opened) {
		rssLeadsShowToast('Reddit popup blocked', 'Allow popups for FreshRSS, then select Open Reddit DM again.', 'error');
		return;
	}
	session.opened = true;
	rssLeadsSetQuickApplyButtonState(root, 'opened');
	rssLeadsQuickApplyPanel.hidden = true;
	rssLeadsShowToast('Reddit DM filled', 'Review the recipient and message, then send it manually.', 'success');
}

function rssLeadsQuickApplyBuildPanel() {
	if (rssLeadsQuickApplyPanel) return;
	rssLeadsQuickApplyPanel = document.createElement('section');
	rssLeadsQuickApplyPanel.id = 'rss-leads-quick-apply-panel';
	rssLeadsQuickApplyPanel.hidden = true;
	rssLeadsQuickApplyPanel.setAttribute('aria-label', 'Quick Apply');
	rssLeadsQuickApplyPanel.innerHTML = [
		'<div class="rss-leads-quick-apply-panel-header">',
		'<div><strong>Quick Apply</strong><span class="rss-leads-quick-apply-lead"></span></div>',
		'<button type="button" class="rss-leads-quick-apply-close" aria-label="Close Quick Apply">Close</button>',
		'</div>',
		'<ol class="rss-leads-quick-apply-steps">',
		'<li><strong>Draft</strong> in ChatGPT</li>',
		'<li><strong>Paste and review</strong> the finished DM here</li>',
		'<li><strong>Open Reddit</strong> with the DM filled in</li>',
		'</ol>',
		'<label class="rss-leads-quick-apply-label" for="rss-leads-quick-apply-draft">Finished DM</label>',
		'<textarea id="rss-leads-quick-apply-draft" class="rss-leads-quick-apply-draft" rows="7" placeholder="Paste ChatGPT\'s finished DM here. You can edit it before opening Reddit."></textarea>',
		'<div class="rss-leads-quick-apply-panel-actions">',
		'<button type="button" class="rss-leads-quick-apply-secondary rss-leads-quick-apply-chatgpt">Open ChatGPT</button>',
		'<button type="button" class="rss-leads-quick-apply-secondary rss-leads-quick-apply-paste">Paste from clipboard</button>',
		'<button type="button" class="rss-leads-quick-apply-primary rss-leads-quick-apply-reddit" disabled>Open Reddit DM</button>',
		'</div>',
		'<p class="rss-leads-quick-apply-note">Nothing is sent automatically. Review the DM and use Reddit\'s Send button yourself.</p>'
	].join('');
	rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-close').addEventListener('click', function () {
		rssLeadsQuickApplyPanel.hidden = true;
	});
	rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-chatgpt').addEventListener('click', function () {
		rssLeadsQuickApplyOpenChatGpt(rssLeadsQuickApplyActiveRoot);
	});
	rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-paste').addEventListener('click', function () {
		rssLeadsPasteQuickApplyResponse(rssLeadsQuickApplyActiveRoot);
	});
	rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-reddit').addEventListener('click', function () {
		rssLeadsQuickApplyOpenReddit(rssLeadsQuickApplyActiveRoot);
	});
	rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-draft').addEventListener('input', function (event) {
		var root = rssLeadsQuickApplyActiveRoot;
		var session = root && root._rssLeadsQuickApplySession;
		if (session) session.response = event.target.value;
		rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-reddit').disabled = !event.target.value.trim();
		if (root) rssLeadsSetQuickApplyButtonState(root, event.target.value.trim() ? 'draft-ready' : 'awaiting-response');
	});
	document.body.appendChild(rssLeadsQuickApplyPanel);
}

function rssLeadsQuickApplyRender(root) {
	rssLeadsQuickApplyBuildPanel();
	rssLeadsQuickApplyActiveRoot = root;
	var session = root && root._rssLeadsQuickApplySession;
	if (!session) return;
	var leadLabel = rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-lead');
	leadLabel.textContent = session.title + (session.username ? ' · u/' + session.username : ' · recipient not detected');
	var textarea = rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-draft');
	textarea.value = session.response || '';
	var redditButton = rssLeadsQuickApplyPanel.querySelector('.rss-leads-quick-apply-reddit');
	redditButton.disabled = !textarea.value.trim();
	redditButton.textContent = session.opened ? 'Reopen Reddit DM' : 'Open Reddit DM';
	rssLeadsQuickApplyPanel.hidden = false;
}

function rssLeadsOpenQuickApplyPrompt(root) {
	if (root._rssLeadsQuickApplySession) {
		rssLeadsQuickApplyRender(root);
		return;
	}
	if (!rssLeadsGetSavedCvProfile()) {
		rssLeadsOpenCvProfilePanel();
		rssLeadsShowToast('Add CV profile first', 'Save your work experience once, then Quick apply can create ChatGPT prompts.', 'warning');
		return;
	}
	var lead = rssLeadsLeadContext(root);
	var prompt = rssLeadsBuildQuickApplyPrompt(root);
	root._rssLeadsQuickApplySession = {
		prompt: prompt,
		title: lead.title || 'Reddit opportunity',
		username: lead.redditUsername,
		subject: rssLeadsQuickApplySubject(lead),
		response: '',
		opened: false
	};
	rssLeadsSetQuickApplyButtonState(root, 'awaiting-response');
	rssLeadsQuickApplyRender(root);
	rssLeadsQuickApplyOpenChatGpt(root);
}

function rssLeadsCreateQuickApplyButton(root, compact) {
	var button = document.createElement('button');
	button.type = 'button';
	button.className = 'btn rss-leads-quick-apply-btn ' + (compact ? 'rss-leads-quick-apply-compact' : 'rss-leads-quick-apply-expanded');
	button.textContent = compact ? 'Apply' : 'Quick apply';
	button.title = 'Draft an application DM with ChatGPT, then open Reddit';
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
