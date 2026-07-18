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
var rssLeadsMassApplyJobId = '';
var rssLeadsMassApplyPollId;
var rssLeadsMassApplyTemplateStorageKey = 'rss-leads-mass-apply-instructions';
var rssLeadsMassApplyTokenStorageKey = 'rss-leads-mass-apply-token';
var rssLeadsMassApplyTokenInput;

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
				throw new Error(data.detail || data.error || ('Mass Apply HTTP ' + response.status));
			}
			return data;
		});
	});
}

function rssLeadsMassApplySetStatus(message, tone) {
	if (!rssLeadsMassApplyStatus) {
		return;
	}
	rssLeadsMassApplyStatus.textContent = message;
	rssLeadsMassApplyStatus.className = 'rss-leads-mass-status rss-leads-mass-status-' + (tone || 'info');
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
		error.textContent = item.error || (item.status === 'generating' ? 'Generating...' : '');
		var actions = document.createElement('div');
		actions.className = 'rss-leads-mass-card-actions';
		var open = document.createElement('button');
		open.type = 'button';
		open.className = 'rss-leads-mass-primary';
		open.textContent = 'Open Reddit DM';
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
	if (codex.authenticated) {
		rssLeadsMassApplySetStatus('Codex signed in and ready.', 'success');
	} else if (login.running) {
		rssLeadsMassApplySetStatus('Codex sign-in is waiting for device approval. Follow the instructions below.', 'warning');
	} else {
		rssLeadsMassApplySetStatus('Codex is not signed in for Mass Apply.', 'warning');
	}
}

function rssLeadsMassApplyCheckStatus() {
	return rssLeadsMassApplyRequest('/api/status').then(function (data) {
		rssLeadsMassApplyRenderLogin(data);
		return data;
	}).catch(function (error) {
		rssLeadsMassApplySetStatus('Helper unavailable: ' + error.message, 'error');
		return null;
	});
}

function rssLeadsMassApplyLogin() {
	rssLeadsMassApplySetStatus('Starting Codex device sign-in...', 'info');
	return rssLeadsMassApplyRequest('/api/login', { method: 'POST', body: {} }).then(function (data) {
		rssLeadsMassApplyRenderLogin({ login: data.login, codex: { authenticated: false } });
		window.setTimeout(rssLeadsMassApplyCheckStatus, 2000);
	}).catch(function (error) {
		rssLeadsMassApplySetStatus('Could not start sign-in: ' + error.message, 'error');
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
		if (item && item.draft) {
			rssLeadsMassApplyOpenReddit(item);
			return;
		}
	}
	rssLeadsShowToast('No prepared DMs', 'Prepare the queue with Codex first.', 'warning');
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
	var authActions = document.createElement('div');
	authActions.className = 'rss-leads-mass-actions';
	var check = document.createElement('button');
	check.type = 'button';
	check.className = 'rss-leads-mass-secondary';
	check.textContent = 'Check Codex';
	check.addEventListener('click', rssLeadsMassApplyCheckStatus);
	var login = document.createElement('button');
	login.type = 'button';
	login.className = 'rss-leads-mass-secondary';
	login.textContent = 'Sign in to Codex';
	login.addEventListener('click', rssLeadsMassApplyLogin);
	authActions.appendChild(check);
	authActions.appendChild(login);
	var loginOutput = document.createElement('pre');
	loginOutput.className = 'rss-leads-mass-login-output';
	loginOutput.hidden = true;
	var tokenLabel = document.createElement('label');
	tokenLabel.className = 'rss-leads-mass-label';
	tokenLabel.textContent = 'Helper pairing token';
	rssLeadsMassApplyTokenInput = document.createElement('input');
	rssLeadsMassApplyTokenInput.className = 'rss-leads-mass-token';
	rssLeadsMassApplyTokenInput.type = 'password';
	rssLeadsMassApplyTokenInput.autocomplete = 'off';
	try {
		rssLeadsMassApplyTokenInput.value = window.localStorage.getItem(rssLeadsMassApplyTokenStorageKey) || '';
	} catch (error) {
		// Leave it empty when browser storage is unavailable.
	}
	rssLeadsMassApplyTokenInput.addEventListener('change', function () {
		try {
			window.localStorage.setItem(rssLeadsMassApplyTokenStorageKey, rssLeadsMassApplyTokenInput.value.trim());
		} catch (error) {
			// The token remains available for this page session.
		}
		rssLeadsMassApplyCheckStatus();
	});
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
	rssLeadsMassApplyPanel.appendChild(authActions);
	rssLeadsMassApplyPanel.appendChild(loginOutput);
	rssLeadsMassApplyPanel.appendChild(tokenLabel);
	rssLeadsMassApplyPanel.appendChild(rssLeadsMassApplyTokenInput);
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
