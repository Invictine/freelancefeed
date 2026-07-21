'use strict';

// Codex-backed batch draft queue. Reddit login, review, and Send remain manual.

var rssLeadsMassApplyApiUrl = window.location.protocol + '//' + window.location.hostname + ':8092';
var rssLeadsMassApplyQueue = Object.create(null);
var rssLeadsMassApplyOrder = [];
var rssLeadsMassApplyButton;
var rssLeadsMassApplyPanel;
var rssLeadsMassApplyTemplate;
var rssLeadsMassApplyStatus;
var rssLeadsMassApplyLoginOutput = '';
var rssLeadsMassApplyLoginPollId;
var rssLeadsMassApplyJobId = '';
var rssLeadsMassApplyPollId;
var rssLeadsMassApplyTemplateStorageKey = 'rss-leads-mass-apply-instructions';
var rssLeadsMassApplyTokenStorageKey = 'rss-leads-mass-apply-token';
var rssLeadsMassApplyTokenInput;
var rssLeadsMassApplyLoginButton;
var rssLeadsMassApplyConnected = false;
var rssLeadsMassApplySignInWindow;
var rssLeadsMassApplyCopiedDeviceCode = '';

function rssLeadsMassApplyKey(entry, lead) {
	var entryId = entry.getAttribute && entry.getAttribute('data-entry');
	return String(entryId ? 'entry:' + entryId : lead.link || '').trim();
}

function rssLeadsMassApplyDefaultInstructions() {
	return [
		'Write a direct, human application DM of about 90-140 words.',
		'Show that I understood the client need and use only truthful proof points from my profile.',
		'Do not use placeholders, brackets, fake metrics, or generic hype.',
		'End with one concrete next-step question.'
	].join('\n');
}

function rssLeadsMassApplySavedInstructions() {
	try {
		return window.localStorage.getItem(rssLeadsMassApplyTemplateStorageKey) || rssLeadsMassApplyDefaultInstructions();
	} catch (error) {
		return rssLeadsMassApplyDefaultInstructions();
	}
}

function rssLeadsMassApplySaveInstructions(value) {
	try {
		window.localStorage.setItem(rssLeadsMassApplyTemplateStorageKey, value);
	} catch (error) {
		// The instructions still remain available for this page session.
	}
}

function rssLeadsMassApplyRequest(path, options) {
	options = options || {};
	var token = rssLeadsMassApplyTokenInput ? rssLeadsMassApplyTokenInput.value.trim() : '';
	if (!token) {
		try {
			token = window.localStorage.getItem(rssLeadsMassApplyTokenStorageKey) || '';
		} catch (error) {
			// The panel will ask for the token when storage is unavailable.
		}
	}
	if (!token) {
		return Promise.reject(new Error('Enter the Mass Apply helper token first.'));
	}
	options.cache = 'no-store';
	options.headers = options.headers || {};
	options.headers.Authorization = 'Bearer ' + token;
	if (options.body && typeof options.body !== 'string') {
		options.headers['Content-Type'] = 'application/json';
		options.body = JSON.stringify(options.body);
	}
	return window.fetch(rssLeadsMassApplyApiUrl + path, options).then(function (response) {
		return response.json().catch(function () { return {}; }).then(function (data) {
			if (!response.ok) {
				var error = new Error(data.detail || data.error || ('Mass Apply HTTP ' + response.status));
				error.status = response.status;
				error.code = data.error || '';
				throw error;
			}
			return data;
		});
	});
}

function rssLeadsMassApplyCopyText(value, description) {
	value = String(value || '');
	if (navigator.clipboard && window.isSecureContext) {
		return navigator.clipboard.writeText(value).then(function () {
			rssLeadsShowToast('Copied', description, 'success');
		}).catch(function () {
			window.prompt('Copy this value:', value);
		});
	}
	window.prompt('Copy this value:', value);
	return Promise.resolve();
}

function rssLeadsMassApplyLoginDetails(output) {
	output = String(output || '');
	var urls = output.match(/https:\/\/[^\s<>"']+/g) || [];
	var officialUrl = '';
	urls.some(function (candidate) {
		candidate = candidate.replace(/[),.;]+$/, '');
		try {
			var parsed = new URL(candidate);
			var host = parsed.hostname.toLowerCase();
			if (host === 'openai.com' || host.slice(-11) === '.openai.com' || host === 'chatgpt.com' || host.slice(-12) === '.chatgpt.com') {
				officialUrl = parsed.href;
				return true;
			}
		} catch (error) {
			// Keep looking for an official URL in the Codex output.
		}
		return false;
	});
	var codes = output.match(/\b[A-Z0-9]{4,12}(?:-[A-Z0-9]{3,12})+\b/g) || [];
	return { url: officialUrl, code: codes.length ? codes[codes.length - 1] : '' };
}

function rssLeadsMassApplySetStatus(message, tone) {
	if (!rssLeadsMassApplyStatus) {
		return;
	}
	rssLeadsMassApplyStatus.textContent = message;
	rssLeadsMassApplyStatus.className = 'rss-leads-mass-status rss-leads-mass-status-' + (tone || 'info');
}

function rssLeadsMassApplySetConnected(connected, codexAuthenticated) {
	rssLeadsMassApplyConnected = connected;
	var helperSetup = rssLeadsMassApplyPanel && rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-helper-setup');
	var connectedRow = rssLeadsMassApplyPanel && rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-connected');
	var codexSetup = rssLeadsMassApplyPanel && rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-codex-setup');
	if (helperSetup) helperSetup.hidden = connected;
	if (connectedRow) connectedRow.hidden = !connected;
	if (codexSetup) codexSetup.hidden = !connected || Boolean(codexAuthenticated);
}

function rssLeadsMassApplySaveToken() {
	var token = rssLeadsMassApplyTokenInput ? rssLeadsMassApplyTokenInput.value.trim() : '';
	try {
		window.localStorage.setItem(rssLeadsMassApplyTokenStorageKey, token);
	} catch (error) {
		// The token remains available for this page session.
	}
	return token;
}

function rssLeadsMassApplyUseLoginDetails(details) {
	if (!details) return;
	if (details.url && rssLeadsMassApplySignInWindow && !rssLeadsMassApplySignInWindow.closed) {
		try {
			rssLeadsMassApplySignInWindow.location.replace(details.url);
			rssLeadsFocusWindow(rssLeadsMassApplySignInWindow);
			rssLeadsMassApplySignInWindow.focus();
			rssLeadsMassApplySignInWindow = null;
		} catch (error) {
			// The visible sign-in link remains available if the reserved tab cannot be updated.
		}
	}
	if (details.code && details.code !== rssLeadsMassApplyCopiedDeviceCode && navigator.clipboard && window.isSecureContext) {
		rssLeadsMassApplyCopiedDeviceCode = details.code;
		navigator.clipboard.writeText(details.code).then(function () {
			rssLeadsShowToast('Codex code copied', 'Paste it into the official sign-in page that just opened.', 'success');
		}).catch(function () {
			// The large Copy code button is the reliable fallback.
		});
	}
}

function rssLeadsMassApplyUpdateButton() {
	if (!rssLeadsMassApplyButton) {
		return;
	}
	var count = rssLeadsMassApplyOrder.length;
	rssLeadsMassApplyButton.textContent = count ? 'Mass apply ' + count : 'Mass apply';
	rssLeadsMassApplyButton.classList.toggle('rss-leads-mass-has-items', count > 0);
}

function rssLeadsMassApplyUpdateEntryButtons() {
	Array.prototype.forEach.call(document.querySelectorAll('.rss-leads-mass-queue-btn'), function (button) {
		var key = button.getAttribute('data-mass-apply-key');
		var selected = Boolean(key && rssLeadsMassApplyQueue[key]);
		button.textContent = selected ? 'Queued' : '+ Queue';
		button.classList.toggle('rss-leads-mass-queued', selected);
		button.setAttribute('aria-pressed', selected ? 'true' : 'false');
	});
}

function rssLeadsMassApplyToggleEntry(entry) {
	var lead = rssLeadsLeadContext(entry);
	var key = rssLeadsMassApplyKey(entry, lead);
	if (!key) {
		rssLeadsShowToast('Could not queue lead', 'This entry has no stable link or entry ID.', 'warning');
		return;
	}
	if (rssLeadsMassApplyQueue[key]) {
		delete rssLeadsMassApplyQueue[key];
		rssLeadsMassApplyOrder = rssLeadsMassApplyOrder.filter(function (item) { return item !== key; });
	} else {
		if (rssLeadsMassApplyOrder.length >= 20) {
			rssLeadsShowToast('Mass Apply queue full', 'Prepare or remove some of the 20 queued leads first.', 'warning');
			return;
		}
		rssLeadsMassApplyQueue[key] = { key: key, lead: lead, draft: '', error: '', status: 'queued' };
		rssLeadsMassApplyOrder.push(key);
	}
	rssLeadsMassApplyUpdateButton();
	rssLeadsMassApplyUpdateEntryButtons();
	if (rssLeadsMassApplyPanel && !rssLeadsMassApplyPanel.hidden) {
		rssLeadsMassApplyRenderQueue();
	}
}

function rssLeadsAnnotateMassApplyButtons() {
	Array.prototype.forEach.call(document.querySelectorAll('.flux[data-entry]'), function (entry) {
		var header = entry.querySelector('.flux_header');
		if (!header || header.querySelector('.rss-leads-mass-queue-btn')) {
			return;
		}
		var lead = rssLeadsLeadContext(entry);
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'btn rss-leads-mass-queue-btn';
		button.textContent = '+ Queue';
		button.title = 'Add this lead to the Codex Mass Apply queue';
		button.setAttribute('data-mass-apply-key', rssLeadsMassApplyKey(entry, lead));
		button.setAttribute('aria-pressed', 'false');
		button.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			rssLeadsMassApplyToggleEntry(entry);
		});
		header.appendChild(button);
	});
	rssLeadsMassApplyUpdateEntryButtons();
}

function rssLeadsMassApplyOpenReddit(item) {
	if (!item.draft) {
		rssLeadsShowToast('Draft not ready', 'Prepare this DM with Codex before opening Reddit.', 'warning');
		return;
	}
	var url = rssLeadsRedditComposeUrl(item.lead.redditUsername, rssLeadsQuickApplySubject(item.lead), item.draft);
	var opened = window.open(url, '_blank');
	rssLeadsFocusWindow(opened);
	if (opened) {
		item.status = 'opened';
		item.openedAt = Date.now();
		rssLeadsMassApplyRenderQueue();
		rssLeadsShowToast('Reddit draft opened', 'Review it and use Reddit\'s Send button manually.', 'success');
	} else {
		rssLeadsShowToast('Reddit popup blocked', 'Allow popups, then click Open Reddit DM again.', 'error');
	}
}

function rssLeadsMassApplyRemove(key) {
	delete rssLeadsMassApplyQueue[key];
	rssLeadsMassApplyOrder = rssLeadsMassApplyOrder.filter(function (item) { return item !== key; });
	rssLeadsMassApplyUpdateButton();
	rssLeadsMassApplyUpdateEntryButtons();
	rssLeadsMassApplyRenderQueue();
}

function rssLeadsMassApplyRenderQueue() {
	if (!rssLeadsMassApplyPanel) {
		return;
	}
	var list = rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-list');
	if (!list) {
		return;
	}
	rssLeadsClearNode(list);
	if (!rssLeadsMassApplyOrder.length) {
		var empty = document.createElement('p');
		empty.className = 'rss-leads-mass-empty';
		empty.textContent = 'Queue leads with the + Queue button in the feed.';
		list.appendChild(empty);
		return;
	}
	rssLeadsMassApplyOrder.forEach(function (key, index) {
		var item = rssLeadsMassApplyQueue[key];
		if (!item) return;
		var card = document.createElement('section');
		card.className = 'rss-leads-mass-card';
		var heading = document.createElement('div');
		heading.className = 'rss-leads-mass-card-heading';
		var title = document.createElement('strong');
		title.textContent = (index + 1) + '. ' + (item.lead.title || 'Untitled Reddit lead');
		var meta = document.createElement('span');
		meta.textContent = item.lead.redditUsername ? 'u/' + item.lead.redditUsername : 'Recipient not detected';
		heading.appendChild(title);
		heading.appendChild(meta);
		var textarea = document.createElement('textarea');
		textarea.className = 'rss-leads-mass-draft';
		textarea.rows = 5;
		textarea.placeholder = item.status === 'generating' ? 'Codex is preparing this DM...' : 'Prepared DM will appear here.';
		textarea.value = item.draft || '';
		textarea.disabled = item.status === 'generating';
		textarea.addEventListener('input', function () { item.draft = textarea.value; });
		var error = document.createElement('p');
		error.className = 'rss-leads-mass-item-status';
		error.textContent = item.error || (item.status === 'generating' ? 'Generating...' : (item.status === 'opened' ? 'Opened in Reddit. Review and send it manually.' : ''));
		var actions = document.createElement('div');
		actions.className = 'rss-leads-mass-card-actions';
		var open = document.createElement('button');
		open.type = 'button';
		open.className = 'rss-leads-mass-primary';
		open.textContent = item.status === 'opened' ? 'Reopen Reddit DM' : 'Open Reddit DM';
		open.disabled = !item.draft;
		open.addEventListener('click', function () { rssLeadsMassApplyOpenReddit(item); });
		var remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'rss-leads-mass-secondary';
		remove.textContent = 'Remove';
		remove.addEventListener('click', function () { rssLeadsMassApplyRemove(key); });
		actions.appendChild(open);
		actions.appendChild(remove);
		card.appendChild(heading);
		card.appendChild(textarea);
		card.appendChild(error);
		card.appendChild(actions);
		list.appendChild(card);
	});
}

function rssLeadsMassApplyRenderLogin(data) {
	data = data || {};
	var codex = data.codex || {};
	var login = data.login || {};
	rssLeadsMassApplyLoginOutput = login.output || rssLeadsMassApplyLoginOutput;
	var output = rssLeadsMassApplyPanel && rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-login-output');
	if (output) {
		output.textContent = rssLeadsMassApplyLoginOutput;
		output.hidden = !rssLeadsMassApplyLoginOutput;
	}
	var loginLog = rssLeadsMassApplyPanel && rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-login-log');
	if (loginLog) loginLog.hidden = !rssLeadsMassApplyLoginOutput;
	var details = rssLeadsMassApplyLoginDetails(rssLeadsMassApplyLoginOutput);
	rssLeadsMassApplySetConnected(true, codex.authenticated);
	rssLeadsMassApplyUseLoginDetails(details);
	var loginDetails = rssLeadsMassApplyPanel && rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-login-details');
	if (loginDetails) loginDetails.hidden = Boolean(codex.authenticated || (!details.url && !details.code));
	var signInLink = rssLeadsMassApplyPanel && rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-signin-link');
	if (signInLink) {
		signInLink.href = details.url || '#';
		signInLink.hidden = !details.url;
	}
	var codeButton = rssLeadsMassApplyPanel && rssLeadsMassApplyPanel.querySelector('.rss-leads-mass-code-copy');
	if (codeButton) {
		codeButton.hidden = !details.code;
		codeButton.setAttribute('data-device-code', details.code);
		codeButton.textContent = details.code ? 'Copy code ' + details.code : 'Copy one-time code';
	}
	if (rssLeadsMassApplyLoginButton) {
		rssLeadsMassApplyLoginButton.disabled = Boolean(login.running || codex.authenticated);
		rssLeadsMassApplyLoginButton.textContent = codex.authenticated ? 'Codex paired' : (login.running ? 'Waiting for approval...' : 'Pair Codex');
	}
	if (codex.authenticated) {
		if (rssLeadsMassApplySignInWindow && !rssLeadsMassApplySignInWindow.closed) rssLeadsMassApplySignInWindow.close();
		rssLeadsMassApplySignInWindow = null;
		rssLeadsMassApplySetStatus('Helper connected · Codex paired and ready.', 'success');
	} else if (login.running) {
		rssLeadsMassApplySetStatus('Finish pairing on the official OpenAI page. This status updates automatically.', 'warning');
	} else if (login.exitCode !== null && login.exitCode !== 0) {
		rssLeadsMassApplySetStatus('Codex pairing stopped before approval. Select Pair Codex to try again.', 'error');
	} else {
		rssLeadsMassApplySetStatus('Helper connected · pair Codex once to prepare drafts.', 'warning');
	}
	window.clearTimeout(rssLeadsMassApplyLoginPollId);
	if (login.running) {
		rssLeadsMassApplyLoginPollId = window.setTimeout(rssLeadsMassApplyCheckStatus, 1800);
	}
}

function rssLeadsMassApplyCheckStatus() {
	if (!rssLeadsMassApplySaveToken()) {
		rssLeadsMassApplySetConnected(false, false);
		rssLeadsMassApplySetStatus('Paste the helper token to connect Mass Apply.', 'warning');
		return Promise.resolve(null);
	}
	return rssLeadsMassApplyRequest('/api/status').then(function (data) {
		rssLeadsMassApplyRenderLogin(data);
		return data;
	}).catch(function (error) {
		if (error.status === 401) {
			rssLeadsMassApplySetConnected(false, false);
			rssLeadsMassApplySetStatus('That helper token did not work. Paste the current token and connect again.', 'error');
		} else {
			rssLeadsMassApplySetStatus('Helper unavailable: ' + error.message, 'error');
		}
		return null;
	});
}

function rssLeadsMassApplyLogin() {
	rssLeadsMassApplyCopiedDeviceCode = '';
	rssLeadsMassApplySignInWindow = window.open('about:blank', 'rss-leads-codex-pairing');
	if (rssLeadsMassApplySignInWindow) {
		try {
			rssLeadsMassApplySignInWindow.document.title = 'Connecting to Codex';
			rssLeadsMassApplySignInWindow.document.body.textContent = 'Preparing the official Codex sign-in page...';
		} catch (error) {
			// The tab can still be redirected once the helper returns the official URL.
		}
	}
	rssLeadsMassApplySetStatus('Starting Codex pairing...', 'info');
	return rssLeadsMassApplyRequest('/api/login', { method: 'POST', body: {} }).then(function (data) {
		rssLeadsMassApplyRenderLogin(data);
	}).catch(function (error) {
		if (rssLeadsMassApplySignInWindow && !rssLeadsMassApplySignInWindow.closed) rssLeadsMassApplySignInWindow.close();
		rssLeadsMassApplySignInWindow = null;
		rssLeadsMassApplySetStatus('Could not start Codex pairing: ' + error.message, 'error');
	});
}

function rssLeadsMassApplyApplyJob(job) {
	(job.results || []).forEach(function (result) {
		var item = rssLeadsMassApplyQueue[result.id];
		if (!item) return;
		item.draft = result.draft || '';
		item.error = result.error || '';
		item.status = result.error ? 'error' : 'ready';
	});
	if (job.status === 'completed' || job.status === 'completed_with_errors' || job.status === 'failed') {
		window.clearTimeout(rssLeadsMassApplyPollId);
		rssLeadsMassApplyJobId = '';
		rssLeadsMassApplySetStatus(job.status === 'completed' ? 'All Codex drafts are ready.' : 'Drafting finished with errors. Review the affected rows.', job.status === 'completed' ? 'success' : 'warning');
	}
	rssLeadsMassApplyRenderQueue();
}

function rssLeadsMassApplyPollJob() {
	if (!rssLeadsMassApplyJobId) return;
	rssLeadsMassApplyRequest('/api/jobs/' + encodeURIComponent(rssLeadsMassApplyJobId)).then(function (data) {
		rssLeadsMassApplyApplyJob(data.job || {});
		if (rssLeadsMassApplyJobId) rssLeadsMassApplyPollId = window.setTimeout(rssLeadsMassApplyPollJob, 1800);
	}).catch(function (error) {
		rssLeadsMassApplySetStatus('Draft status failed: ' + error.message, 'error');
		rssLeadsMassApplyPollId = window.setTimeout(rssLeadsMassApplyPollJob, 3500);
	});
}

function rssLeadsMassApplyPrepare() {
	if (!rssLeadsMassApplyOrder.length || rssLeadsMassApplyJobId) return;
	if (!rssLeadsGetSavedCvProfile()) {
		rssLeadsMassApplySetStatus('Save your CV profile before preparing personalized DMs.', 'warning');
		rssLeadsShowToast('Add CV profile first', 'Mass Apply uses it to personalize every Codex draft.', 'warning');
		return;
	}
	var instructions = rssLeadsMassApplyTemplate ? rssLeadsMassApplyTemplate.value.trim() : rssLeadsMassApplyDefaultInstructions();
	rssLeadsMassApplySaveInstructions(instructions);
	var leads = rssLeadsMassApplyOrder.map(function (key) {
		var item = rssLeadsMassApplyQueue[key];
		item.status = 'generating';
		item.error = '';
		var lead = {};
		Object.keys(item.lead).forEach(function (name) { lead[name] = item.lead[name]; });
		lead.id = key;
		return lead;
	});
	rssLeadsMassApplyRenderQueue();
	rssLeadsMassApplySetStatus('Submitting ' + leads.length + ' lead' + (leads.length === 1 ? '' : 's') + ' to Codex...', 'info');
	rssLeadsMassApplyRequest('/api/jobs', {
		method: 'POST',
		body: { leads: leads, profile: rssLeadsGetSavedCvProfile(), instructions: instructions }
	}).then(function (data) {
		rssLeadsMassApplyJobId = data.job.id;
		rssLeadsMassApplyPollJob();
	}).catch(function (error) {
		rssLeadsMassApplyOrder.forEach(function (key) { rssLeadsMassApplyQueue[key].status = 'queued'; });
		rssLeadsMassApplyRenderQueue();
		rssLeadsMassApplySetStatus('Could not prepare drafts: ' + error.message, 'error');
	});
}

function rssLeadsMassApplyOpenNext() {
	for (var index = 0; index < rssLeadsMassApplyOrder.length; index++) {
		var item = rssLeadsMassApplyQueue[rssLeadsMassApplyOrder[index]];
		if (item && item.draft && item.status !== 'opened') {
			rssLeadsMassApplyOpenReddit(item);
			return;
		}
	}
	rssLeadsShowToast('No unopened DMs', 'Prepare the queue first, or use Reopen Reddit DM on an earlier draft.', 'warning');
}

function rssLeadsMassApplyBuildPanel() {
	if (!rssLeadsMassApplyPanel || rssLeadsMassApplyPanel.childNodes.length) return;
	var header = document.createElement('div');
	header.className = 'rss-leads-mass-header';
	var title = document.createElement('strong');
	title.textContent = 'Codex Mass Apply';
	var close = document.createElement('button');
	close.type = 'button';
	close.className = 'rss-leads-mass-secondary';
	close.textContent = 'Close';
	close.addEventListener('click', function () { rssLeadsMassApplyPanel.hidden = true; });
	header.appendChild(title);
	header.appendChild(close);
	rssLeadsMassApplyStatus = document.createElement('p');
	rssLeadsMassApplyStatus.className = 'rss-leads-mass-status';
	var setup = document.createElement('section');
	setup.className = 'rss-leads-mass-setup rss-leads-mass-helper-setup';
	var setupTitle = document.createElement('strong');
	setupTitle.textContent = 'Connect the helper';
	var setupText = document.createElement('p');
	setupText.textContent = 'Paste the helper token once. It stays in this browser and is never added to a draft.';
	var tokenLabel = document.createElement('label');
	tokenLabel.className = 'rss-leads-mass-label';
	tokenLabel.textContent = 'Helper token';
	rssLeadsMassApplyTokenInput = document.createElement('input');
	rssLeadsMassApplyTokenInput.className = 'rss-leads-mass-token';
	rssLeadsMassApplyTokenInput.type = 'password';
	rssLeadsMassApplyTokenInput.autocomplete = 'off';
	rssLeadsMassApplyTokenInput.placeholder = 'Paste helper token';
	try {
		rssLeadsMassApplyTokenInput.value = window.localStorage.getItem(rssLeadsMassApplyTokenStorageKey) || '';
	} catch (error) {
		// Leave it empty when browser storage is unavailable.
	}
	var connectActions = document.createElement('div');
	connectActions.className = 'rss-leads-mass-actions';
	var pasteToken = document.createElement('button');
	pasteToken.type = 'button';
	pasteToken.className = 'rss-leads-mass-secondary';
	pasteToken.textContent = 'Paste token';
	pasteToken.addEventListener('click', function () {
		if (navigator.clipboard && navigator.clipboard.readText && window.isSecureContext) {
			navigator.clipboard.readText().then(function (token) {
				rssLeadsMassApplyTokenInput.value = String(token || '').trim();
				rssLeadsMassApplyCheckStatus();
			}).catch(function () {
				rssLeadsMassApplyTokenInput.focus();
				rssLeadsShowToast('Paste the helper token', 'Clipboard access was blocked, so use Ctrl+V or long-press Paste.', 'warning');
			});
		} else {
			rssLeadsMassApplyTokenInput.focus();
			rssLeadsShowToast('Paste the helper token', 'Use Ctrl+V or long-press Paste, then select Connect helper.', 'info');
		}
	});
	var connect = document.createElement('button');
	connect.type = 'button';
	connect.className = 'rss-leads-mass-primary';
	connect.textContent = 'Connect helper';
	connect.addEventListener('click', rssLeadsMassApplyCheckStatus);
	rssLeadsMassApplyTokenInput.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') rssLeadsMassApplyCheckStatus();
	});
	connectActions.appendChild(pasteToken);
	connectActions.appendChild(connect);
	var tokenHelp = document.createElement('details');
	tokenHelp.className = 'rss-leads-mass-token-help';
	var tokenHelpSummary = document.createElement('summary');
	tokenHelpSummary.textContent = 'Where do I get the helper token?';
	var tokenCommand = document.createElement('code');
	tokenCommand.className = 'rss-leads-mass-command';
	tokenCommand.textContent = 'pct exec 102 -- docker exec rss-leads-mass-apply sh -c \'cat "$CODEX_HOME/mass-apply-token"\'';
	tokenHelp.appendChild(tokenHelpSummary);
	tokenHelp.appendChild(tokenCommand);
	setup.appendChild(setupTitle);
	setup.appendChild(setupText);
	setup.appendChild(tokenLabel);
	setup.appendChild(rssLeadsMassApplyTokenInput);
	setup.appendChild(connectActions);
	setup.appendChild(tokenHelp);
	var securityNote = document.createElement('p');
	securityNote.className = 'rss-leads-mass-security-note';
	securityNote.textContent = 'Treat this token like a password.';
	setup.appendChild(securityNote);
	var connected = document.createElement('div');
	connected.className = 'rss-leads-mass-connected';
	connected.hidden = true;
	var connectedText = document.createElement('span');
	connectedText.textContent = '✓ Helper connected';
	var changeToken = document.createElement('button');
	changeToken.type = 'button';
	changeToken.className = 'rss-leads-mass-link-button';
	changeToken.textContent = 'Change token';
	changeToken.addEventListener('click', function () {
		rssLeadsMassApplySetConnected(false, false);
		rssLeadsMassApplyTokenInput.focus();
	});
	connected.appendChild(connectedText);
	connected.appendChild(changeToken);
	var codexSetup = document.createElement('section');
	codexSetup.className = 'rss-leads-mass-setup rss-leads-mass-codex-setup';
	codexSetup.hidden = true;
	var codexTitle = document.createElement('strong');
	codexTitle.textContent = 'Pair Codex';
	var codexText = document.createElement('p');
	codexText.textContent = 'The official sign-in page opens automatically and the one-time code is copied when your browser allows it.';
	rssLeadsMassApplyLoginButton = document.createElement('button');
	rssLeadsMassApplyLoginButton.type = 'button';
	rssLeadsMassApplyLoginButton.className = 'rss-leads-mass-primary';
	rssLeadsMassApplyLoginButton.textContent = 'Pair Codex';
	rssLeadsMassApplyLoginButton.addEventListener('click', rssLeadsMassApplyLogin);
	codexSetup.appendChild(codexTitle);
	codexSetup.appendChild(codexText);
	codexSetup.appendChild(rssLeadsMassApplyLoginButton);
	var loginOutput = document.createElement('pre');
	loginOutput.className = 'rss-leads-mass-login-output';
	loginOutput.hidden = true;
	var loginLog = document.createElement('details');
	loginLog.className = 'rss-leads-mass-login-log';
	loginLog.hidden = true;
	var loginLogSummary = document.createElement('summary');
	loginLogSummary.textContent = 'Pairing details';
	loginLog.appendChild(loginLogSummary);
	loginLog.appendChild(loginOutput);
	var loginDetails = document.createElement('div');
	loginDetails.className = 'rss-leads-mass-login-details';
	var signInLink = document.createElement('a');
	signInLink.className = 'rss-leads-mass-primary rss-leads-mass-signin-link';
	signInLink.textContent = 'Open official sign-in page';
	signInLink.target = '_blank';
	signInLink.rel = 'noopener noreferrer';
	signInLink.hidden = true;
	var codeCopy = document.createElement('button');
	codeCopy.type = 'button';
	codeCopy.className = 'rss-leads-mass-code-copy';
	codeCopy.textContent = 'Copy one-time code';
	codeCopy.hidden = true;
	codeCopy.addEventListener('click', function () {
		rssLeadsMassApplyCopyText(codeCopy.getAttribute('data-device-code'), 'The Codex one-time code is ready to paste.');
	});
	loginDetails.appendChild(signInLink);
	loginDetails.appendChild(codeCopy);
	var templateLabel = document.createElement('label');
	templateLabel.className = 'rss-leads-mass-label';
	templateLabel.textContent = 'Reusable DM instructions';
	rssLeadsMassApplyTemplate = document.createElement('textarea');
	rssLeadsMassApplyTemplate.className = 'rss-leads-mass-template';
	rssLeadsMassApplyTemplate.rows = 4;
	rssLeadsMassApplyTemplate.value = rssLeadsMassApplySavedInstructions();
	rssLeadsMassApplyTemplate.addEventListener('change', function () { rssLeadsMassApplySaveInstructions(rssLeadsMassApplyTemplate.value); });
	var queueActions = document.createElement('div');
	queueActions.className = 'rss-leads-mass-actions';
	var prepare = document.createElement('button');
	prepare.type = 'button';
	prepare.className = 'rss-leads-mass-primary';
	prepare.textContent = 'Prepare queued DMs';
	prepare.addEventListener('click', rssLeadsMassApplyPrepare);
	var next = document.createElement('button');
	next.type = 'button';
	next.className = 'rss-leads-mass-primary';
	next.textContent = 'Open next ready DM';
	next.addEventListener('click', rssLeadsMassApplyOpenNext);
	var clear = document.createElement('button');
	clear.type = 'button';
	clear.className = 'rss-leads-mass-secondary';
	clear.textContent = 'Clear queue';
	clear.addEventListener('click', function () {
		rssLeadsMassApplyQueue = Object.create(null);
		rssLeadsMassApplyOrder = [];
		rssLeadsMassApplyUpdateButton();
		rssLeadsMassApplyUpdateEntryButtons();
		rssLeadsMassApplyRenderQueue();
	});
	queueActions.appendChild(prepare);
	queueActions.appendChild(next);
	queueActions.appendChild(clear);
	var list = document.createElement('div');
	list.className = 'rss-leads-mass-list';
	rssLeadsMassApplyPanel.appendChild(header);
	rssLeadsMassApplyPanel.appendChild(rssLeadsMassApplyStatus);
	rssLeadsMassApplyPanel.appendChild(setup);
	rssLeadsMassApplyPanel.appendChild(connected);
	rssLeadsMassApplyPanel.appendChild(codexSetup);
	rssLeadsMassApplyPanel.appendChild(loginDetails);
	rssLeadsMassApplyPanel.appendChild(loginLog);
	rssLeadsMassApplyPanel.appendChild(templateLabel);
	rssLeadsMassApplyPanel.appendChild(rssLeadsMassApplyTemplate);
	rssLeadsMassApplyPanel.appendChild(queueActions);
	rssLeadsMassApplyPanel.appendChild(list);
}

function rssLeadsMassApplyInit() {
	if (rssLeadsMassApplyButton) return;
	var widget = document.getElementById('rss-leads-status-widget');
	if (!widget) return;
	rssLeadsMassApplyButton = document.createElement('button');
	rssLeadsMassApplyButton.type = 'button';
	rssLeadsMassApplyButton.className = 'btn rss-leads-mass-button';
	rssLeadsMassApplyButton.textContent = 'Mass apply';
	rssLeadsMassApplyButton.addEventListener('click', function () {
		rssLeadsMassApplyPanel.hidden = !rssLeadsMassApplyPanel.hidden;
		if (!rssLeadsMassApplyPanel.hidden) {
			rssLeadsMassApplyRenderQueue();
			rssLeadsMassApplyCheckStatus();
		}
	});
	rssLeadsMassApplyPanel = document.createElement('div');
	rssLeadsMassApplyPanel.id = 'rss-leads-mass-panel';
	rssLeadsMassApplyPanel.hidden = true;
	widget.appendChild(rssLeadsMassApplyButton);
	widget.appendChild(rssLeadsMassApplyPanel);
	rssLeadsMassApplyBuildPanel();
	rssLeadsMassApplyUpdateButton();
	rssLeadsAnnotateMassApplyButtons();
}
