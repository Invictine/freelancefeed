'use strict';

// FreshRSS status widget, CV profile panel, and AI dashboard rendering.

function rssLeadsUpdateTimer() {
	if (!rssLeadsStatusText || !rssLeadsLatestStatus) {
		return;
	}
	var now = Math.floor(Date.now() / 1000);
	var age = rssLeadsLatestStatus.last_update > 0 ? now - rssLeadsLatestStatus.last_update : null;
	rssLeadsStatusText.textContent = 'Reddit refreshed ' + rssLeadsFormatAge(age);
	rssLeadsStatusText.title = rssLeadsLatestStatus.last_update_iso || '';
}

function rssLeadsRenderStatus(data) {
	rssLeadsLatestStatus = data;
	rssLeadsUpdateTimer();

	if (data.recent_429 && data.latest_429_ts) {
		var key = 'rss-leads-last-429-toast';
		var previous = window.localStorage.getItem(key);
		var current = String(data.latest_429_ts);
		if (previous !== current) {
			window.localStorage.setItem(key, current);
			rssLeadsShowToast('Reddit rate limit hit', 'FreshRSS received HTTP 429 while refreshing the Reddit leads feed.', 'warning');
		}
	}
}

function rssLeadsClearNode(node) {
	while (node.firstChild) {
		node.removeChild(node.firstChild);
	}
}

function rssLeadsGetSavedCvProfile() {
	try {
		return String(window.localStorage.getItem(rssLeadsCvProfileStorageKey) || '').trim();
	} catch (error) {
		return '';
	}
}

function rssLeadsSaveCvProfile(value) {
	try {
		window.localStorage.setItem(rssLeadsCvProfileStorageKey, String(value || '').trim());
	} catch (error) {
		rssLeadsShowToast('CV profile not saved', 'Browser storage is unavailable for this FreshRSS page.', 'error');
		return false;
	}
	rssLeadsUpdateCvProfileButton();
	return true;
}

function rssLeadsUpdateCvProfileButton() {
	if (!rssLeadsCvProfileButton) {
		return;
	}
	var hasProfile = rssLeadsGetSavedCvProfile().length > 0;
	rssLeadsCvProfileButton.textContent = hasProfile ? 'CV profile' : 'Add CV profile';
	rssLeadsCvProfileButton.classList.toggle('rss-leads-cv-profile-empty', !hasProfile);
}

function rssLeadsRenderCvProfilePanel() {
	if (!rssLeadsCvProfilePanel) {
		return;
	}
	rssLeadsClearNode(rssLeadsCvProfilePanel);
	var savedProfile = rssLeadsGetSavedCvProfile();

	var header = document.createElement('div');
	header.className = 'rss-leads-cv-panel-header';
	var titleBlock = document.createElement('div');
	titleBlock.className = 'rss-leads-cv-title-block';
	var title = document.createElement('strong');
	title.textContent = 'CV profile';
	var status = document.createElement('span');
	status.className = 'rss-leads-cv-status';
	titleBlock.appendChild(title);
	titleBlock.appendChild(status);
	var close = document.createElement('button');
	close.type = 'button';
	close.className = 'rss-leads-cv-close';
	close.textContent = 'Close';
	close.addEventListener('click', function () {
		rssLeadsCvProfilePanel.hidden = true;
	});
	header.appendChild(titleBlock);
	header.appendChild(close);

	var body = document.createElement('div');
	body.className = 'rss-leads-cv-body';

	var label = document.createElement('label');
	label.className = 'rss-leads-cv-label';
	label.htmlFor = 'rss-leads-cv-profile-text';
	label.textContent = 'Work profile';

	rssLeadsCvProfileTextarea = document.createElement('textarea');
	rssLeadsCvProfileTextarea.id = 'rss-leads-cv-profile-text';
	rssLeadsCvProfileTextarea.value = savedProfile;
	rssLeadsCvProfileTextarea.placeholder = 'Role, services, proof points, niches, tools, portfolio links, and preferred tone.';

	var meta = document.createElement('div');
	meta.className = 'rss-leads-cv-meta';
	var counter = document.createElement('span');
	counter.className = 'rss-leads-cv-counter';
	meta.appendChild(counter);

	var actions = document.createElement('div');
	actions.className = 'rss-leads-cv-actions';
	var save = document.createElement('button');
	save.type = 'button';
	save.className = 'btn rss-leads-cv-save';
	save.textContent = 'Save profile';
	save.addEventListener('click', function () {
		if (rssLeadsSaveCvProfile(rssLeadsCvProfileTextarea.value)) {
			rssLeadsCvProfilePanel.hidden = true;
			rssLeadsShowToast('CV profile saved', 'Quick apply will use this profile for future ChatGPT prompts.', 'success');
		}
	});
	var clear = document.createElement('button');
	clear.type = 'button';
	clear.className = 'btn rss-leads-cv-clear';
	clear.textContent = 'Clear';
	clear.addEventListener('click', function () {
		rssLeadsCvProfileTextarea.value = '';
		rssLeadsSaveCvProfile('');
		savedProfile = '';
		updateEditorState();
	});

	function updateEditorState() {
		var value = String(rssLeadsCvProfileTextarea.value || '');
		var trimmed = value.trim();
		counter.textContent = String(value.length) + ' chars';
		if (trimmed !== savedProfile) {
			status.className = 'rss-leads-cv-status rss-leads-cv-status-unsaved';
			status.textContent = 'Unsaved';
		} else if (trimmed) {
			status.className = 'rss-leads-cv-status rss-leads-cv-status-saved';
			status.textContent = 'Saved';
		} else {
			status.className = 'rss-leads-cv-status rss-leads-cv-status-empty';
			status.textContent = 'Empty';
		}
	}
	rssLeadsCvProfileTextarea.addEventListener('input', updateEditorState);
	updateEditorState();

	actions.appendChild(clear);
	actions.appendChild(save);

	rssLeadsCvProfilePanel.appendChild(header);
	body.appendChild(label);
	body.appendChild(rssLeadsCvProfileTextarea);
	body.appendChild(meta);
	body.appendChild(actions);
	rssLeadsCvProfilePanel.appendChild(body);
}

function rssLeadsOpenCvProfilePanel() {
	if (!rssLeadsCvProfilePanel) {
		return;
	}
	if (rssLeadsAiStatusPanel) {
		rssLeadsAiStatusPanel.hidden = true;
	}
	rssLeadsRenderCvProfilePanel();
	rssLeadsCvProfilePanel.hidden = false;
	window.setTimeout(function () {
		if (rssLeadsCvProfileTextarea) {
			rssLeadsCvProfileTextarea.focus();
		}
	}, 0);
}

function rssLeadsAppendMetric(parent, label, value) {
	var item = document.createElement('div');
	item.className = 'rss-leads-ai-metric';
	var labelNode = document.createElement('span');
	labelNode.className = 'rss-leads-ai-metric-label';
	labelNode.textContent = label;
	var valueNode = document.createElement('strong');
	valueNode.textContent = value;
	item.appendChild(labelNode);
	item.appendChild(valueNode);
	parent.appendChild(item);
}

function rssLeadsFormatNumber(value) {
	var number = Number(value || 0);
	if (!Number.isFinite(number)) {
		return '0';
	}
	return Math.round(number).toLocaleString();
}

function rssLeadsFillWidthClass(percent) {
	var bucket = Math.max(0, Math.min(100, Math.round(Number(percent || 0) / 10) * 10));
	return 'rss-leads-fill-' + String(bucket);
}

function rssLeadsAppendMiniBar(parent, label, value, total, extraClass) {
	var row = document.createElement('div');
	row.className = 'rss-leads-ai-mini-bar' + (extraClass ? ' ' + extraClass : '');
	var top = document.createElement('div');
	var name = document.createElement('span');
	name.textContent = label;
	var count = document.createElement('strong');
	count.textContent = String(value || 0);
	var track = document.createElement('div');
	var fill = document.createElement('span');
	fill.className = rssLeadsFillWidthClass(Math.max(2, Math.min(100, total > 0 ? Math.round((Number(value || 0) / total) * 100) : 0)));
	top.appendChild(name);
	top.appendChild(count);
	track.appendChild(fill);
	row.appendChild(top);
	row.appendChild(track);
	parent.appendChild(row);
}

function rssLeadsAppendAnalyticsSection(parent, analytics) {
	if (!analytics) {
		return;
	}
	var section = document.createElement('div');
	section.className = 'rss-leads-ai-analytics';

	var title = document.createElement('h3');
	title.textContent = 'Output analytics';
	section.appendChild(title);

	var stats = document.createElement('div');
	stats.className = 'rss-leads-ai-metrics rss-leads-ai-analytics-metrics';
	rssLeadsAppendMetric(stats, 'Classified 24h', String(analytics.updated_last_24h || 0));
	rssLeadsAppendMetric(stats, 'Classified 7d', String(analytics.updated_last_7d || 0));
	rssLeadsAppendMetric(stats, 'Unread classified', String(analytics.unread_classified || 0));
	rssLeadsAppendMetric(stats, 'Avg scam', String(analytics.avg_scam_likelihood || 0) + '%');
	section.appendChild(stats);

	var distribution = document.createElement('div');
	distribution.className = 'rss-leads-ai-distribution';
	var scamBuckets = analytics.scam_buckets || {};
	var scamTotal = Object.keys(scamBuckets).reduce(function (sum, bucket) {
		return sum + Number(scamBuckets[bucket] || 0);
	}, 0);
	var scamTitle = document.createElement('strong');
	scamTitle.textContent = 'Scam risk distribution';
	distribution.appendChild(scamTitle);
	['0', '1-24', '25-49', '50-74', '75-100'].forEach(function (bucket) {
		rssLeadsAppendMiniBar(distribution, bucket + '%', scamBuckets[bucket] || 0, scamTotal, 'rss-leads-ai-mini-bar-scam');
	});
	section.appendChild(distribution);

	var modelCounts = analytics.model_counts || {};
	var modelNames = Object.keys(modelCounts);
	if (modelNames.length) {
		var models = document.createElement('div');
		models.className = 'rss-leads-ai-distribution';
		var modelTitle = document.createElement('strong');
		modelTitle.textContent = 'Model mix';
		models.appendChild(modelTitle);
		var modelTotal = modelNames.reduce(function (sum, model) {
			return sum + Number(modelCounts[model] || 0);
		}, 0);
		modelNames.forEach(function (model) {
			rssLeadsAppendMiniBar(models, model, modelCounts[model] || 0, modelTotal, '');
		});
		section.appendChild(models);
	}

	var recent = analytics.recent || [];
	if (recent.length) {
		var recentWrap = document.createElement('div');
		recentWrap.className = 'rss-leads-ai-recent-output';
		var recentTitle = document.createElement('strong');
		recentTitle.textContent = 'Recent output';
		recentWrap.appendChild(recentTitle);
		recent.slice(0, 8).forEach(function (item) {
			var row = document.createElement('div');
			row.className = 'rss-leads-ai-recent-row';
			var top = document.createElement('div');
			var name = document.createElement('span');
			name.textContent = rssLeadsShortText(item.title || item.link || 'Untitled', 'Untitled');
			var meta = document.createElement('small');
			meta.textContent = [rssLeadsPriorityLabel(String(item.priority || '').toLowerCase()), item.job_type, 'Scam ' + String(item.scam_likelihood || 0) + '%', item.model].filter(Boolean).join(' - ');
			var summary = document.createElement('p');
			summary.textContent = item.summary || '';
			top.appendChild(name);
			top.appendChild(meta);
			row.appendChild(top);
			row.appendChild(summary);
			recentWrap.appendChild(row);
		});
		section.appendChild(recentWrap);
	}

	parent.appendChild(section);
}

function rssLeadsAppendBenchmarkSection(parent, benchmark) {
	var section = document.createElement('div');
	section.className = 'rss-leads-ai-benchmark-tab';

	var title = document.createElement('h3');
	title.textContent = 'Benchmark';
	section.appendChild(title);

	if (!benchmark || !(benchmark.models || []).length) {
		var empty = document.createElement('p');
		empty.className = 'rss-leads-ai-panel-message';
		empty.textContent = 'No benchmark report is available yet. Run php /opt/rss-leads-stack/scripts/benchmark-ai-models.php in the ai-filter container.';
		section.appendChild(empty);
		parent.appendChild(section);
		return;
	}

	var highIntelligence = benchmark.high_intelligence_cli ? 'CLI' : (benchmark.high_intelligence_model || benchmark.high_intelligence_agent || benchmark.judge_model || benchmark.judge_agent || 'unknown');
	var highProvider = benchmark.high_intelligence_provider ? ' via ' + benchmark.high_intelligence_provider : '';
	var meta = document.createElement('div');
	meta.className = 'rss-leads-ai-benchmark-meta';
	rssLeadsAppendMetric(meta, 'Sample', String(benchmark.sample_size || 0));
	rssLeadsAppendMetric(meta, 'Generated', rssLeadsFormatTime(benchmark.generated_at));
	rssLeadsAppendMetric(meta, 'Judge', highIntelligence + highProvider);
	rssLeadsAppendMetric(meta, 'Candidates', String((benchmark.models || []).length));
	section.appendChild(meta);

	var rows = document.createElement('div');
	rows.className = 'rss-leads-ai-benchmark-list';
	(benchmark.models || []).forEach(function (row, index) {
		var item = document.createElement('div');
		item.className = 'rss-leads-ai-benchmark-card';

		var top = document.createElement('div');
		top.className = 'rss-leads-ai-benchmark-card-top';
		var rank = document.createElement('span');
		rank.className = 'rss-leads-ai-benchmark-rank';
		rank.textContent = '#' + String(index + 1);
		var model = document.createElement('strong');
		model.textContent = row.model || 'unknown model';
		var quality = document.createElement('span');
		quality.className = 'rss-leads-ai-benchmark-quality';
		quality.textContent = 'Q ' + String(row.avg_quality || 0) + '/10';
		top.appendChild(rank);
		top.appendChild(model);
		top.appendChild(quality);
		item.appendChild(top);

		var metrics = document.createElement('div');
		metrics.className = 'rss-leads-ai-benchmark-card-metrics';
		rssLeadsAppendMetric(metrics, 'Rows', String(row.success || 0) + '/' + String((row.success || 0) + (row.failed || 0)));
		rssLeadsAppendMetric(metrics, 'Latency', String(row.avg_latency_ms || 0) + 'ms');
		rssLeadsAppendMetric(metrics, 'Tokens/item', String(row.tokens_per_item || 0));
		rssLeadsAppendMetric(metrics, 'Tokens', rssLeadsFormatNumber(row.total_tokens || 0));
		rssLeadsAppendMetric(metrics, 'Priority', String(row.avg_priority_score || 0) + '/10');
		rssLeadsAppendMetric(metrics, 'Summary', String(row.avg_summary_score || 0) + '/10');
		rssLeadsAppendMetric(metrics, 'Scam', String(row.avg_scam_score || 0) + '/10');
		rssLeadsAppendMetric(metrics, 'HTTP', String(row.http_status || 0));
		item.appendChild(metrics);

		var judge = document.createElement('small');
		judge.textContent = 'Judge status ' + String(row.judge_status || 0)
			+ (row.judge_model_used ? ' - model ' + String(row.judge_model_used) : '')
			+ (row.judge_latency_ms ? ' - ' + String(row.judge_latency_ms) + 'ms' : '')
			+ (row.judge_fallback ? ' - ' + String(row.judge_fallback) : '');
		item.appendChild(judge);

		if (row.notes || row.parse_note || row.error || row.judge_error) {
			var notes = document.createElement('p');
			notes.textContent = [row.notes, row.parse_note, row.error, row.judge_error].filter(Boolean).join(' ');
			item.appendChild(notes);
		}

		if (row.output_excerpt) {
			var details = document.createElement('details');
			var summary = document.createElement('summary');
			summary.textContent = 'Candidate output excerpt';
			var excerpt = document.createElement('pre');
			excerpt.textContent = String(row.output_excerpt || '');
			details.appendChild(summary);
			details.appendChild(excerpt);
			item.appendChild(details);
		}

		rows.appendChild(item);
	});
	section.appendChild(rows);

	var samples = benchmark.items || [];
	if (samples.length) {
		var sampleWrap = document.createElement('div');
		sampleWrap.className = 'rss-leads-ai-benchmark-samples';
		var sampleTitle = document.createElement('strong');
		sampleTitle.textContent = 'Sample items';
		sampleWrap.appendChild(sampleTitle);
		samples.slice(0, 8).forEach(function (sample) {
			var sampleRow = document.createElement('small');
			sampleRow.textContent = [sample.id, sample.title].filter(Boolean).join(' - ');
			sampleWrap.appendChild(sampleRow);
		});
		section.appendChild(sampleWrap);
	}

	parent.appendChild(section);
}

function rssLeadsAppendAiStatusTabs(parent) {
	var tabs = document.createElement('div');
	tabs.className = 'rss-leads-ai-tabs';
	[
		{ id: 'status', label: 'Status' },
		{ id: 'analytics', label: 'Analytics' },
		{ id: 'benchmark', label: 'Benchmark' }
	].forEach(function (tab) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'rss-leads-ai-tab' + (rssLeadsAiStatusActiveTab === tab.id ? ' rss-leads-ai-tab-active' : '');
		button.textContent = tab.label;
		button.setAttribute('aria-pressed', rssLeadsAiStatusActiveTab === tab.id ? 'true' : 'false');
		button.addEventListener('click', function () {
			rssLeadsAiStatusActiveTab = tab.id;
			rssLeadsRenderAiStatus(rssLeadsLatestAiStatus);
		});
		tabs.appendChild(button);
	});
	parent.appendChild(tabs);
}

function rssLeadsAppendProgress(parent, label, used, limit) {
	var safeUsed = Math.max(0, Number(used || 0));
	var numericLimit = Number(limit || 0);
	if (numericLimit <= 0) {
		var tracking = document.createElement('div');
		tracking.className = 'rss-leads-ai-budget';
		var trackingTop = document.createElement('div');
		trackingTop.className = 'rss-leads-ai-budget-top';
		var trackingTitle = document.createElement('strong');
		trackingTitle.textContent = label;
		var trackingValue = document.createElement('span');
		trackingValue.textContent = safeUsed + ' requests tracked, no app cap';
		trackingTop.appendChild(trackingTitle);
		trackingTop.appendChild(trackingValue);
		tracking.appendChild(trackingTop);
		parent.appendChild(tracking);
		return;
	}
	var safeLimit = Math.max(1, numericLimit);
	var percent = Math.min(100, Math.round((safeUsed / safeLimit) * 100));
	var wrap = document.createElement('div');
	wrap.className = 'rss-leads-ai-budget';
	var top = document.createElement('div');
	top.className = 'rss-leads-ai-budget-top';
	var title = document.createElement('strong');
	title.textContent = label;
	var value = document.createElement('span');
	value.textContent = safeUsed + ' / ' + safeLimit + ' requests';
	var bar = document.createElement('div');
	bar.className = 'rss-leads-ai-budget-bar';
	var fill = document.createElement('span');
	fill.className = rssLeadsFillWidthClass(percent);
	if (percent >= 90) {
		fill.className += ' rss-leads-ai-budget-danger';
	} else if (percent >= 70) {
		fill.className += ' rss-leads-ai-budget-warn';
	}
	top.appendChild(title);
	top.appendChild(value);
	bar.appendChild(fill);
	wrap.appendChild(top);
	wrap.appendChild(bar);
	parent.appendChild(wrap);
}

function rssLeadsAppendEvent(parent, event, emptyText) {
	var row = document.createElement('li');
	if (!event) {
		row.textContent = emptyText;
		parent.appendChild(row);
		return;
	}
	var title = document.createElement('strong');
	title.textContent = [event.type || event.status || 'event', event.model || event.stage || ''].filter(Boolean).join(' - ');
	var detail = document.createElement('span');
	detail.textContent = rssLeadsShortText(event.message || event.error || ('HTTP ' + (event.status || 0)), 'No detail');
	var meta = document.createElement('small');
	var tokens = event.tokens && event.tokens.total ? ' - ' + rssLeadsFormatNumber(event.tokens.total) + ' tok' : '';
	meta.textContent = rssLeadsFormatTime(event.at) + tokens + (event.retry_delay_seconds ? ' retry ' + event.retry_delay_seconds + 's' : '');
	row.appendChild(title);
	row.appendChild(detail);
	row.appendChild(meta);
	parent.appendChild(row);
}

function rssLeadsRenderAiStatus(status) {
	rssLeadsLatestAiStatus = status || {};
	var state = rssLeadsLatestAiStatus.state || {};
	var counts = state.request_counts || {};
	var errors = state.errors || [];
	var latest = rssLeadsLatestAiStatus.latest_summary;
	var nextBatch = state.next_batch_at || state.quota_backoff_until || state.next_run_at;
	var statusName = state.current_status || 'unknown';
	var failed = Number(counts.failed || 0);
	var success = Number(counts.success || 0);
	var quota = Number(counts.quota || 0);
	var dailyBudget = state.daily_budget || {};
	var batch = state.last_batch || {};
	var modelDailyBudgets = (state.model_daily_budgets && state.model_daily_budgets.models) || (batch.model_daily_budgets && batch.model_daily_budgets.models) || {};
	var priorityCounts = rssLeadsLatestAiStatus.priority_counts || {};
	var jobTypeCounts = rssLeadsLatestAiStatus.job_type_counts || {};
	var jobTypes = rssLeadsLatestAiStatus.job_types || [];
	var promptQuality = rssLeadsLatestAiStatus.prompt_quality || {};
	var tokenCounts = state.token_counts || {};
	var tokenCountsByModel = state.token_counts_by_model || {};
	var lastErrorAt = state.last_error && state.last_error.at ? Number(state.last_error.at) : 0;
	var lastSuccessAt = Number(state.last_success_at || 0);
	var hasActiveError = statusName === 'failed' || lastErrorAt > lastSuccessAt || Number(state.quota_backoff_until || 0) > Math.floor(Date.now() / 1000);

	if (rssLeadsAiStatusButton) {
		rssLeadsAiStatusButton.className = 'btn rss-leads-ai-status-btn rss-leads-ai-status-' + statusName;
		rssLeadsAiStatusButton.textContent = 'AI ' + statusName + ' - ' + rssLeadsFormatCountdown(nextBatch);
		rssLeadsAiStatusButton.title = 'AI requests ok ' + success + ', failed ' + failed + ', quota ' + quota + '. Next batch ' + rssLeadsFormatCountdown(nextBatch) + '.';
		if (hasActiveError) {
			rssLeadsAiStatusButton.classList.add('rss-leads-ai-status-has-errors');
		}
	}
	if (!rssLeadsAiStatusPanel) {
		return;
	}

	rssLeadsClearNode(rssLeadsAiStatusPanel);

	var header = document.createElement('div');
	header.className = 'rss-leads-ai-panel-header';
	var title = document.createElement('strong');
	title.textContent = 'AI filter dashboard';
	var close = document.createElement('button');
	close.type = 'button';
	close.textContent = 'Close';
	close.addEventListener('click', function () {
		rssLeadsAiStatusPanel.hidden = true;
	});
	header.appendChild(title);
	header.appendChild(close);
	rssLeadsAiStatusPanel.appendChild(header);
	rssLeadsAppendAiStatusTabs(rssLeadsAiStatusPanel);

	if (rssLeadsAiStatusActiveTab === 'benchmark') {
		rssLeadsAppendBenchmarkSection(rssLeadsAiStatusPanel, rssLeadsLatestAiStatus.benchmark);
		return;
	}
	if (rssLeadsAiStatusActiveTab === 'analytics') {
		rssLeadsAppendAnalyticsSection(rssLeadsAiStatusPanel, rssLeadsLatestAiStatus.analytics);
		return;
	}

	var overview = document.createElement('div');
	overview.className = 'rss-leads-ai-overview rss-leads-ai-overview-' + statusName;
	var overviewStatus = document.createElement('div');
	overviewStatus.className = 'rss-leads-ai-overview-status';
	var statusPill = document.createElement('span');
	statusPill.className = 'rss-leads-ai-status-pill';
	statusPill.textContent = statusName;
	var rssLeadsStatusText = document.createElement('strong');
	rssLeadsStatusText.textContent = state.last_message || 'AI filter status is available.';
	overviewStatus.appendChild(statusPill);
	overviewStatus.appendChild(rssLeadsStatusText);
	var overviewNext = document.createElement('div');
	overviewNext.className = 'rss-leads-ai-overview-next';
	overviewNext.textContent = 'Next batch ' + rssLeadsFormatCountdown(nextBatch) + ' - ' + rssLeadsFormatTime(nextBatch);
	overview.appendChild(overviewStatus);
	overview.appendChild(overviewNext);
	rssLeadsAiStatusPanel.appendChild(overview);

	rssLeadsAppendProgress(rssLeadsAiStatusPanel, 'Daily Gemini request budget', dailyBudget.used || 0, dailyBudget.limit || 0);

	var tokenSection = document.createElement('div');
	tokenSection.className = 'rss-leads-ai-panel-section rss-leads-ai-token-section';
	var tokenTitle = document.createElement('h3');
	tokenTitle.textContent = 'Token usage';
	var tokenMetrics = document.createElement('div');
	tokenMetrics.className = 'rss-leads-ai-metrics';
	rssLeadsAppendMetric(tokenMetrics, 'Total tokens', rssLeadsFormatNumber(tokenCounts.total || 0));
	rssLeadsAppendMetric(tokenMetrics, 'Prompt tokens', rssLeadsFormatNumber(tokenCounts.prompt || 0));
	rssLeadsAppendMetric(tokenMetrics, 'Output tokens', rssLeadsFormatNumber(tokenCounts.candidate || 0));
	rssLeadsAppendMetric(tokenMetrics, 'Thinking tokens', rssLeadsFormatNumber(tokenCounts.thoughts || 0));
	rssLeadsAppendMetric(tokenMetrics, 'Usage-bearing calls', rssLeadsFormatNumber(tokenCounts.requests_with_usage || 0));
	tokenSection.appendChild(tokenTitle);
	tokenSection.appendChild(tokenMetrics);
	var tokenModelNames = Object.keys(tokenCountsByModel).sort(function (left, right) {
		return Number((tokenCountsByModel[right] || {}).total || 0) - Number((tokenCountsByModel[left] || {}).total || 0);
	});
	if (tokenModelNames.length) {
		var tokenMix = document.createElement('div');
		tokenMix.className = 'rss-leads-ai-distribution';
		var tokenMixTitle = document.createElement('strong');
		tokenMixTitle.textContent = 'Token mix by model';
		tokenMix.appendChild(tokenMixTitle);
		var tokenTotal = tokenModelNames.reduce(function (sum, model) {
			return sum + Number((tokenCountsByModel[model] || {}).total || 0);
		}, 0);
		tokenModelNames.slice(0, 6).forEach(function (model) {
			rssLeadsAppendMiniBar(tokenMix, model, (tokenCountsByModel[model] || {}).total || 0, tokenTotal, '');
		});
		tokenSection.appendChild(tokenMix);
	}
	rssLeadsAiStatusPanel.appendChild(tokenSection);

	var modelBudgetNames = Object.keys(modelDailyBudgets);
	if (modelBudgetNames.length) {
		var modelBudgetSection = document.createElement('div');
		modelBudgetSection.className = 'rss-leads-ai-panel-section rss-leads-ai-model-budgets';
		var modelBudgetTitle = document.createElement('h3');
		modelBudgetTitle.textContent = 'Model daily limits';
		modelBudgetSection.appendChild(modelBudgetTitle);
		modelBudgetNames.sort().forEach(function (model) {
			var budget = modelDailyBudgets[model] || {};
			rssLeadsAppendProgress(modelBudgetSection, model, budget.used || 0, budget.limit || 0);
		});
		rssLeadsAiStatusPanel.appendChild(modelBudgetSection);
	}

	var metrics = document.createElement('div');
	metrics.className = 'rss-leads-ai-metrics';
	rssLeadsAppendMetric(metrics, 'Gemma cached', String(batch.gemma_cached_links || 0));
	rssLeadsAppendMetric(metrics, 'Flash refined', String(batch.refined_links || 0));
	rssLeadsAppendMetric(metrics, 'Local skips', String(batch.local_classified || 0));
	rssLeadsAppendMetric(metrics, 'Saved last batch', String(batch.saved || 0));
	rssLeadsAppendMetric(metrics, 'Classified total', String(rssLeadsLatestAiStatus.total_classified || 0));
	rssLeadsAppendMetric(metrics, 'Job types', String(jobTypes.length || Object.keys(jobTypeCounts).length || (batch.job_type_options || []).length || 0));
	rssLeadsAppendMetric(metrics, 'Requests ok', String(success));
	rssLeadsAppendMetric(metrics, 'Requests failed', String(failed));
	rssLeadsAppendMetric(metrics, 'Quota errors', String(quota));
	rssLeadsAppendMetric(metrics, 'Last processed', rssLeadsFormatTime(state.last_success_at || state.last_finished_at));
	rssLeadsAiStatusPanel.appendChild(metrics);

	var qualitySection = document.createElement('div');
	qualitySection.className = 'rss-leads-ai-panel-section rss-leads-ai-quality-section';
	var qualityTitle = document.createElement('h3');
	qualityTitle.textContent = 'Prompt quality';
	var qualityMetrics = document.createElement('div');
	qualityMetrics.className = 'rss-leads-ai-metrics';
	rssLeadsAppendMetric(qualityMetrics, 'Request fail rate', String(promptQuality.request_failure_rate || 0) + '%');
	rssLeadsAppendMetric(qualityMetrics, 'JSON fail rate', String(promptQuality.json_failure_rate || 0) + '%');
	rssLeadsAppendMetric(qualityMetrics, 'Invalid JSON', String(promptQuality.invalid_json_count || 0));
	rssLeadsAppendMetric(qualityMetrics, 'Invalid rows', String(promptQuality.invalid_classification_count || 0));
	rssLeadsAppendMetric(qualityMetrics, 'Gemma return rate', String(promptQuality.gemma_return_rate || 0) + '%');
	rssLeadsAppendMetric(qualityMetrics, 'Arbitration', String(promptQuality.priority_arbitrated || 0) + '/' + String(promptQuality.priority_conflicts || 0));
	qualitySection.appendChild(qualityTitle);
	qualitySection.appendChild(qualityMetrics);
	rssLeadsAiStatusPanel.appendChild(qualitySection);

	var priorities = document.createElement('div');
	priorities.className = 'rss-leads-ai-priorities';
	['x_high', 'high', 'medium', 'low', 'not_hiring'].forEach(function (priority) {
		var item = document.createElement('span');
		item.className = 'rss-leads-ai-priority-count rss-leads-ai-priority-count-' + priority;
		item.textContent = rssLeadsPriorityLabel(priority) + ' ' + String(priorityCounts[priority] || 0);
		priorities.appendChild(item);
	});
	rssLeadsAiStatusPanel.appendChild(priorities);

	var jobTypeNames = Object.keys(jobTypeCounts).sort(function (left, right) {
		return Number(jobTypeCounts[right] || 0) - Number(jobTypeCounts[left] || 0);
	});
	if (!jobTypeNames.length && jobTypes.length) {
		jobTypeNames = jobTypes.map(function (item) {
			return String(item && item.name || '').trim();
		}).filter(Boolean);
	}
	if (jobTypeNames.length) {
		var jobTypeWrap = document.createElement('div');
		jobTypeWrap.className = 'rss-leads-ai-job-types';
		jobTypeNames.slice(0, 12).forEach(function (jobType) {
			var item = document.createElement('span');
			item.className = 'rss-leads-ai-job-type-count';
			var count = jobTypeCounts[jobType];
			item.textContent = jobType + (count ? ' ' + String(count) : '');
			jobTypeWrap.appendChild(item);
		});
		rssLeadsAiStatusPanel.appendChild(jobTypeWrap);
	}

	if (state.quota_backoff_until) {
		var backoff = document.createElement('p');
		backoff.className = 'rss-leads-ai-panel-message';
		backoff.textContent = 'Quota backoff until ' + rssLeadsFormatTime(state.quota_backoff_until) + '. No Gemini requests will be sent before then.';
		rssLeadsAiStatusPanel.appendChild(backoff);
	}

	var modelBackoffs = state.model_backoffs || {};
	var backoffModels = Object.keys(modelBackoffs).filter(function (model) {
		return Number(modelBackoffs[model] || 0) > Math.floor(Date.now() / 1000);
	});
	if (backoffModels.length) {
		var modelSection = document.createElement('div');
		modelSection.className = 'rss-leads-ai-panel-section';
		var modelTitle = document.createElement('h3');
		modelTitle.textContent = 'Model fallback backoffs';
		var modelList = document.createElement('ul');
		modelList.className = 'rss-leads-ai-model-list';
		backoffModels.forEach(function (model) {
			var row = document.createElement('li');
			row.textContent = model + ' until ' + rssLeadsFormatTime(modelBackoffs[model]);
			modelList.appendChild(row);
		});
		modelSection.appendChild(modelTitle);
		modelSection.appendChild(modelList);
		rssLeadsAiStatusPanel.appendChild(modelSection);
	}

	if (latest) {
		var latestSummary = document.createElement('div');
		latestSummary.className = 'rss-leads-ai-panel-section';
		var latestTitle = document.createElement('h3');
		latestTitle.textContent = 'Last AI summary';
		var latestText = document.createElement('p');
		var latestAmount = rssLeadsMonthlyAmountLabel(latest);
		var latestJobType = rssLeadsJobTypeLabel(latest);
		var latestScamLikelihood = rssLeadsScamLikelihood(latest);
		var latestLabels = [rssLeadsPriorityLabel(String(latest.priority || '').toLowerCase())];
		if (latestJobType) {
			latestLabels.push(latestJobType);
		}
		if (latestAmount) {
			latestLabels.push(latestAmount);
		}
		if (latestScamLikelihood !== null) {
			latestLabels.push('Scam ' + latestScamLikelihood + '%');
		}
		latestText.textContent = latestLabels.join(' - ') + ': ' + latest.summary;
		var latestMeta = document.createElement('small');
		latestMeta.textContent = rssLeadsFormatTime(latest.updated_at) + ' via ' + latest.model;
		latestSummary.appendChild(latestTitle);
		latestSummary.appendChild(latestText);
		latestSummary.appendChild(latestMeta);
		rssLeadsAiStatusPanel.appendChild(latestSummary);
	}

	var batchSection = document.createElement('div');
	batchSection.className = 'rss-leads-ai-panel-section';
	var batchTitle = document.createElement('h3');
	batchTitle.textContent = 'Last batch';
	var batchText = document.createElement('p');
	batchText.textContent = 'Candidates ' + (batch.candidate_rows || 0) +
		', links ' + (batch.unique_links || 0) +
		', Gemma double-pass ' + (batch.gemma_first_pass_sent_items || 0) + ' sent / ' + (batch.gemma_first_pass_returned_items || 0) + ' returned' +
		', conflicts ' + (batch.priority_conflicts || 0) +
		', Gemini arbitration ' + (batch.priority_arbiter_sent_items || 0) + '/' + (batch.priority_arbiter_returned_items || 0) +
		', Flash refine ' + (batch.refine_sent_items || 0) + '/' + (batch.refine_returned_items || 0) +
		', saved ' + (batch.saved || 0) +
		(batch.model ? ', last model ' + batch.model : '');
	batchSection.appendChild(batchTitle);
	batchSection.appendChild(batchText);
	rssLeadsAiStatusPanel.appendChild(batchSection);

	var requestList = document.createElement('ul');
	requestList.className = 'rss-leads-ai-event-list';
	var requestsTitle = document.createElement('h3');
	requestsTitle.textContent = 'Recent Gemini requests';
	rssLeadsAiStatusPanel.appendChild(requestsTitle);
	(state.requests || []).slice(0, 8).forEach(function (event) {
		rssLeadsAppendEvent(requestList, event, 'No Gemini requests recorded');
	});
	if (!(state.requests || []).length) {
		rssLeadsAppendEvent(requestList, null, 'No Gemini requests recorded');
	}
	rssLeadsAiStatusPanel.appendChild(requestList);

	var errorList = document.createElement('ul');
	errorList.className = 'rss-leads-ai-event-list rss-leads-ai-error-list';
	var errorsTitle = document.createElement('h3');
	errorsTitle.textContent = 'Errors';
	rssLeadsAiStatusPanel.appendChild(errorsTitle);
	errors.slice(0, 10).forEach(function (event) {
		rssLeadsAppendEvent(errorList, event, 'No errors recorded');
	});
	if (!errors.length) {
		rssLeadsAppendEvent(errorList, null, 'No errors recorded');
	}
	rssLeadsAiStatusPanel.appendChild(errorList);
}

function rssLeadsPollStatus() {
	window.fetch(rssLeadsStatusUrl, {
		credentials: 'same-origin',
		cache: 'no-store'
	})
		.then(function (response) {
			if (!response.ok) {
				throw new Error('status ' + response.status);
			}
			return response.json();
		})
		.then(rssLeadsRenderStatus)
		.catch(function () {
			if (rssLeadsStatusText) {
				rssLeadsStatusText.textContent = 'Reddit status unavailable';
			}
		});
}

function rssLeadsManualRefresh() {
	if (!rssLeadsRefreshButton) {
		return;
	}
	rssLeadsRefreshButton.disabled = true;
	rssLeadsStatusText.textContent = 'Refreshing Reddit...';
	var feedIds = rssLeadsLatestStatus && rssLeadsLatestStatus.feed_ids && rssLeadsLatestStatus.feed_ids.length
		? rssLeadsLatestStatus.feed_ids
		: [rssLeadsLatestStatus && rssLeadsLatestStatus.feed_id].filter(Boolean);
	Promise.all(feedIds.map(function (feedId) {
		return window.fetch('/i/?c=feed&a=actualize&id=' + encodeURIComponent(feedId) + '&ajax=1', {
			credentials: 'same-origin',
			cache: 'no-store'
		});
	}))
		.then(function () {
			window.setTimeout(rssLeadsPollStatus, 1500);
			window.setTimeout(function () {
				rssLeadsRefreshButton.disabled = false;
			}, 2000);
		})
		.catch(function () {
			rssLeadsRefreshButton.disabled = false;
			rssLeadsShowToast('Manual refresh failed', 'FreshRSS could not start the Reddit feed refresh.', 'error');
			rssLeadsPollStatus();
		});
}
