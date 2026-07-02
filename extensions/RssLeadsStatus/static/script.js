(function () {
	'use strict';

	var statusUrl = '/rss-leads-status.php';
	var aiUrl = '/rss-leads-ai.php';
	var statusText;
	var refreshButton;
	var aiStatusButton;
	var aiStatusPanel;
	var latestAiStatus;
	var cvProfileButton;
	var cvProfilePanel;
	var cvProfileTextarea;
	var latestStatus;
	var timerId;
	var pollId;
	var aiPollId;
	var subredditObserver;
	var subredditRefreshQueued = false;
	var aiRefreshQueued = false;
	var cvProfileStorageKey = 'rss-leads-cv-profile';

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

	function formatTime(timestamp) {
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

	function formatCountdown(timestamp) {
		if (!timestamp) {
			return 'unknown';
		}
		var seconds = timestamp - Math.floor(Date.now() / 1000);
		if (seconds <= 0) {
			return 'due now';
		}
		return 'in ' + formatAge(seconds).replace(' ago', '');
	}

	function shortText(value, fallback) {
		value = String(value || '').trim();
		if (!value) {
			return fallback || '';
		}
		return value.length > 140 ? value.slice(0, 137) + '...' : value;
	}

	function normalizeText(value) {
		return String(value || '').replace(/\s+/g, ' ').trim();
	}

	function limitText(value, maxLength) {
		value = normalizeText(value);
		if (value.length <= maxLength) {
			return value;
		}
		return value.slice(0, Math.max(0, maxLength - 3)).trim() + '...';
	}

	function showToast(message, detail, tone) {
		var toast = document.getElementById('rss-leads-toast');
		if (!toast) {
			toast = document.createElement('div');
			toast.id = 'rss-leads-toast';
			toast.setAttribute('role', 'status');
			toast.setAttribute('aria-live', 'polite');
			document.body.appendChild(toast);
		}
		toast.className = 'rss-leads-toast-' + (tone || 'info');
		clearNode(toast);
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
				showToast('Reddit rate limit hit', 'FreshRSS received HTTP 429 while refreshing the Reddit leads feed.', 'warning');
			}
		}
	}

	function clearNode(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function getSavedCvProfile() {
		try {
			return String(window.localStorage.getItem(cvProfileStorageKey) || '').trim();
		} catch (error) {
			return '';
		}
	}

	function saveCvProfile(value) {
		try {
			window.localStorage.setItem(cvProfileStorageKey, String(value || '').trim());
		} catch (error) {
			showToast('CV profile not saved', 'Browser storage is unavailable for this FreshRSS page.', 'error');
			return false;
		}
		updateCvProfileButton();
		return true;
	}

	function updateCvProfileButton() {
		if (!cvProfileButton) {
			return;
		}
		var hasProfile = getSavedCvProfile().length > 0;
		cvProfileButton.textContent = hasProfile ? 'CV profile' : 'Add CV profile';
		cvProfileButton.classList.toggle('rss-leads-cv-profile-empty', !hasProfile);
	}

	function renderCvProfilePanel() {
		if (!cvProfilePanel) {
			return;
		}
		clearNode(cvProfilePanel);
		var savedProfile = getSavedCvProfile();

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
			cvProfilePanel.hidden = true;
		});
		header.appendChild(titleBlock);
		header.appendChild(close);

		var body = document.createElement('div');
		body.className = 'rss-leads-cv-body';

		var label = document.createElement('label');
		label.className = 'rss-leads-cv-label';
		label.htmlFor = 'rss-leads-cv-profile-text';
		label.textContent = 'Work profile';

		cvProfileTextarea = document.createElement('textarea');
		cvProfileTextarea.id = 'rss-leads-cv-profile-text';
		cvProfileTextarea.value = savedProfile;
		cvProfileTextarea.placeholder = 'Role, services, proof points, niches, tools, portfolio links, and preferred tone.';

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
			if (saveCvProfile(cvProfileTextarea.value)) {
				cvProfilePanel.hidden = true;
				showToast('CV profile saved', 'Quick apply will use this profile for future ChatGPT prompts.', 'success');
			}
		});
		var clear = document.createElement('button');
		clear.type = 'button';
		clear.className = 'btn rss-leads-cv-clear';
		clear.textContent = 'Clear';
		clear.addEventListener('click', function () {
			cvProfileTextarea.value = '';
			saveCvProfile('');
			savedProfile = '';
			updateEditorState();
		});

		function updateEditorState() {
			var value = String(cvProfileTextarea.value || '');
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
		cvProfileTextarea.addEventListener('input', updateEditorState);
		updateEditorState();

		actions.appendChild(clear);
		actions.appendChild(save);

		cvProfilePanel.appendChild(header);
		body.appendChild(label);
		body.appendChild(cvProfileTextarea);
		body.appendChild(meta);
		body.appendChild(actions);
		cvProfilePanel.appendChild(body);
	}

	function openCvProfilePanel() {
		if (!cvProfilePanel) {
			return;
		}
		if (aiStatusPanel) {
			aiStatusPanel.hidden = true;
		}
		renderCvProfilePanel();
		cvProfilePanel.hidden = false;
		window.setTimeout(function () {
			if (cvProfileTextarea) {
				cvProfileTextarea.focus();
			}
		}, 0);
	}

	function appendMetric(parent, label, value) {
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

	function appendMiniBar(parent, label, value, total, extraClass) {
		var row = document.createElement('div');
		row.className = 'rss-leads-ai-mini-bar' + (extraClass ? ' ' + extraClass : '');
		var top = document.createElement('div');
		var name = document.createElement('span');
		name.textContent = label;
		var count = document.createElement('strong');
		count.textContent = String(value || 0);
		var track = document.createElement('div');
		var fill = document.createElement('span');
		fill.style.width = Math.max(2, Math.min(100, total > 0 ? Math.round((Number(value || 0) / total) * 100) : 0)) + '%';
		top.appendChild(name);
		top.appendChild(count);
		track.appendChild(fill);
		row.appendChild(top);
		row.appendChild(track);
		parent.appendChild(row);
	}

	function appendAnalyticsSection(parent, analytics, benchmark) {
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
		appendMetric(stats, 'Classified 24h', String(analytics.updated_last_24h || 0));
		appendMetric(stats, 'Classified 7d', String(analytics.updated_last_7d || 0));
		appendMetric(stats, 'Unread classified', String(analytics.unread_classified || 0));
		appendMetric(stats, 'Avg scam', String(analytics.avg_scam_likelihood || 0) + '%');
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
			appendMiniBar(distribution, bucket + '%', scamBuckets[bucket] || 0, scamTotal, 'rss-leads-ai-mini-bar-scam');
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
				appendMiniBar(models, model, modelCounts[model] || 0, modelTotal, '');
			});
			section.appendChild(models);
		}

		if (benchmark && (benchmark.models || []).length) {
			var bench = document.createElement('div');
			bench.className = 'rss-leads-ai-benchmark';
			var benchTitle = document.createElement('strong');
			benchTitle.textContent = 'Benchmark';
			var benchMeta = document.createElement('small');
			var highIntelligence = benchmark.high_intelligence_cli ? 'CLI' : (benchmark.high_intelligence_model || benchmark.high_intelligence_agent || benchmark.judge_model || benchmark.judge_agent || 'unknown');
			var highProvider = benchmark.high_intelligence_provider ? ' via ' + benchmark.high_intelligence_provider : '';
			benchMeta.textContent = 'Sample ' + String(benchmark.sample_size || 0) + ' - high intelligence ' + highIntelligence + highProvider + ' - ' + formatTime(benchmark.generated_at);
			bench.appendChild(benchTitle);
			bench.appendChild(benchMeta);
			(benchmark.models || []).forEach(function (row) {
				var item = document.createElement('div');
				item.className = 'rss-leads-ai-benchmark-row';
				var model = document.createElement('span');
				model.textContent = row.model;
				var quality = document.createElement('strong');
				quality.textContent = 'Q ' + String(row.avg_quality || 0) + '/10';
				var speed = document.createElement('span');
				speed.textContent = String(row.avg_latency_ms || 0) + 'ms'
					+ (row.judge_model_used ? ' - judge ' + String(row.judge_model_used) : '')
					+ (row.judge_status && !row.avg_quality ? ' - status ' + String(row.judge_status) : '');
				item.appendChild(model);
				item.appendChild(quality);
				item.appendChild(speed);
				bench.appendChild(item);
				if (row.notes) {
					var note = document.createElement('small');
					note.textContent = row.notes;
					bench.appendChild(note);
				}
			});
			section.appendChild(bench);
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
				name.textContent = shortText(item.title || item.link || 'Untitled', 'Untitled');
				var meta = document.createElement('small');
				meta.textContent = [priorityLabel(String(item.priority || '').toLowerCase()), item.job_type, 'Scam ' + String(item.scam_likelihood || 0) + '%', item.model].filter(Boolean).join(' - ');
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

	function appendProgress(parent, label, used, limit) {
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
		fill.style.width = percent + '%';
		if (percent >= 90) {
			fill.className = 'rss-leads-ai-budget-danger';
		} else if (percent >= 70) {
			fill.className = 'rss-leads-ai-budget-warn';
		}
		top.appendChild(title);
		top.appendChild(value);
		bar.appendChild(fill);
		wrap.appendChild(top);
		wrap.appendChild(bar);
		parent.appendChild(wrap);
	}

	function appendEvent(parent, event, emptyText) {
		var row = document.createElement('li');
		if (!event) {
			row.textContent = emptyText;
			parent.appendChild(row);
			return;
		}
		var title = document.createElement('strong');
		title.textContent = [event.type || event.status || 'event', event.model || event.stage || ''].filter(Boolean).join(' - ');
		var detail = document.createElement('span');
		detail.textContent = shortText(event.message || event.error || ('HTTP ' + (event.status || 0)), 'No detail');
		var meta = document.createElement('small');
		meta.textContent = formatTime(event.at) + (event.retry_delay_seconds ? ' retry ' + event.retry_delay_seconds + 's' : '');
		row.appendChild(title);
		row.appendChild(detail);
		row.appendChild(meta);
		parent.appendChild(row);
	}

	function renderAiStatus(status) {
		latestAiStatus = status || {};
		var state = latestAiStatus.state || {};
		var counts = state.request_counts || {};
		var errors = state.errors || [];
		var latest = latestAiStatus.latest_summary;
		var nextBatch = state.next_batch_at || state.quota_backoff_until || state.next_run_at;
		var statusName = state.current_status || 'unknown';
		var failed = Number(counts.failed || 0);
		var success = Number(counts.success || 0);
		var quota = Number(counts.quota || 0);
		var dailyBudget = state.daily_budget || {};
		var batch = state.last_batch || {};
		var modelDailyBudgets = (state.model_daily_budgets && state.model_daily_budgets.models) || (batch.model_daily_budgets && batch.model_daily_budgets.models) || {};
		var priorityCounts = latestAiStatus.priority_counts || {};
		var jobTypeCounts = latestAiStatus.job_type_counts || {};
		var jobTypes = latestAiStatus.job_types || [];
		var lastErrorAt = state.last_error && state.last_error.at ? Number(state.last_error.at) : 0;
		var lastSuccessAt = Number(state.last_success_at || 0);
		var hasActiveError = statusName === 'failed' || lastErrorAt > lastSuccessAt || Number(state.quota_backoff_until || 0) > Math.floor(Date.now() / 1000);

		if (aiStatusButton) {
			aiStatusButton.className = 'btn rss-leads-ai-status-btn rss-leads-ai-status-' + statusName;
			aiStatusButton.textContent = 'AI ' + statusName + ' - ' + formatCountdown(nextBatch);
			aiStatusButton.title = 'AI requests ok ' + success + ', failed ' + failed + ', quota ' + quota + '. Next batch ' + formatCountdown(nextBatch) + '.';
			if (hasActiveError) {
				aiStatusButton.classList.add('rss-leads-ai-status-has-errors');
			}
		}
		if (!aiStatusPanel) {
			return;
		}

		clearNode(aiStatusPanel);

		var header = document.createElement('div');
		header.className = 'rss-leads-ai-panel-header';
		var title = document.createElement('strong');
		title.textContent = 'AI filter dashboard';
		var close = document.createElement('button');
		close.type = 'button';
		close.textContent = 'Close';
		close.addEventListener('click', function () {
			aiStatusPanel.hidden = true;
		});
		header.appendChild(title);
		header.appendChild(close);
		aiStatusPanel.appendChild(header);

		var overview = document.createElement('div');
		overview.className = 'rss-leads-ai-overview rss-leads-ai-overview-' + statusName;
		var overviewStatus = document.createElement('div');
		overviewStatus.className = 'rss-leads-ai-overview-status';
		var statusPill = document.createElement('span');
		statusPill.className = 'rss-leads-ai-status-pill';
		statusPill.textContent = statusName;
		var statusText = document.createElement('strong');
		statusText.textContent = state.last_message || 'AI filter status is available.';
		overviewStatus.appendChild(statusPill);
		overviewStatus.appendChild(statusText);
		var overviewNext = document.createElement('div');
		overviewNext.className = 'rss-leads-ai-overview-next';
		overviewNext.textContent = 'Next batch ' + formatCountdown(nextBatch) + ' - ' + formatTime(nextBatch);
		overview.appendChild(overviewStatus);
		overview.appendChild(overviewNext);
		aiStatusPanel.appendChild(overview);

		appendProgress(aiStatusPanel, 'Daily Gemini request budget', dailyBudget.used || 0, dailyBudget.limit || 0);

		var modelBudgetNames = Object.keys(modelDailyBudgets);
		if (modelBudgetNames.length) {
			var modelBudgetSection = document.createElement('div');
			modelBudgetSection.className = 'rss-leads-ai-panel-section rss-leads-ai-model-budgets';
			var modelBudgetTitle = document.createElement('h3');
			modelBudgetTitle.textContent = 'Model daily limits';
			modelBudgetSection.appendChild(modelBudgetTitle);
			modelBudgetNames.sort().forEach(function (model) {
				var budget = modelDailyBudgets[model] || {};
				appendProgress(modelBudgetSection, model, budget.used || 0, budget.limit || 0);
			});
			aiStatusPanel.appendChild(modelBudgetSection);
		}

		var metrics = document.createElement('div');
		metrics.className = 'rss-leads-ai-metrics';
		appendMetric(metrics, 'Gemma cached', String(batch.gemma_cached_links || 0));
		appendMetric(metrics, 'Flash refined', String(batch.refined_links || 0));
		appendMetric(metrics, 'Local skips', String(batch.local_classified || 0));
		appendMetric(metrics, 'Saved last batch', String(batch.saved || 0));
		appendMetric(metrics, 'Classified total', String(latestAiStatus.total_classified || 0));
		appendMetric(metrics, 'Job types', String(jobTypes.length || Object.keys(jobTypeCounts).length || (batch.job_type_options || []).length || 0));
		appendMetric(metrics, 'Requests ok', String(success));
		appendMetric(metrics, 'Requests failed', String(failed));
		appendMetric(metrics, 'Quota errors', String(quota));
		appendMetric(metrics, 'Last processed', formatTime(state.last_success_at || state.last_finished_at));
		aiStatusPanel.appendChild(metrics);

		var priorities = document.createElement('div');
		priorities.className = 'rss-leads-ai-priorities';
		['high', 'medium', 'low', 'not_hiring'].forEach(function (priority) {
			var item = document.createElement('span');
			item.className = 'rss-leads-ai-priority-count rss-leads-ai-priority-count-' + priority;
			item.textContent = priorityLabel(priority) + ' ' + String(priorityCounts[priority] || 0);
			priorities.appendChild(item);
		});
		aiStatusPanel.appendChild(priorities);

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
			aiStatusPanel.appendChild(jobTypeWrap);
		}

		appendAnalyticsSection(aiStatusPanel, latestAiStatus.analytics, latestAiStatus.benchmark);

		if (state.quota_backoff_until) {
			var backoff = document.createElement('p');
			backoff.className = 'rss-leads-ai-panel-message';
			backoff.textContent = 'Quota backoff until ' + formatTime(state.quota_backoff_until) + '. No Gemini requests will be sent before then.';
			aiStatusPanel.appendChild(backoff);
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
				row.textContent = model + ' until ' + formatTime(modelBackoffs[model]);
				modelList.appendChild(row);
			});
			modelSection.appendChild(modelTitle);
			modelSection.appendChild(modelList);
			aiStatusPanel.appendChild(modelSection);
		}

		if (latest) {
			var latestSummary = document.createElement('div');
			latestSummary.className = 'rss-leads-ai-panel-section';
			var latestTitle = document.createElement('h3');
			latestTitle.textContent = 'Last AI summary';
			var latestText = document.createElement('p');
			var latestAmount = monthlyAmountLabel(latest);
			var latestJobType = jobTypeLabel(latest);
			var latestScamLikelihood = scamLikelihood(latest);
			var latestLabels = [priorityLabel(String(latest.priority || '').toLowerCase())];
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
			latestMeta.textContent = formatTime(latest.updated_at) + ' via ' + latest.model;
			latestSummary.appendChild(latestTitle);
			latestSummary.appendChild(latestText);
			latestSummary.appendChild(latestMeta);
			aiStatusPanel.appendChild(latestSummary);
		}

		var batchSection = document.createElement('div');
		batchSection.className = 'rss-leads-ai-panel-section';
		var batchTitle = document.createElement('h3');
		batchTitle.textContent = 'Last batch';
		var batchText = document.createElement('p');
		batchText.textContent = 'Candidates ' + (batch.candidate_rows || 0) +
			', links ' + (batch.unique_links || 0) +
			', Gemma first-pass ' + (batch.gemma_first_pass_sent_items || 0) + '/' + (batch.gemma_first_pass_returned_items || 0) +
			', Flash refine ' + (batch.refine_sent_items || 0) + '/' + (batch.refine_returned_items || 0) +
			', saved ' + (batch.saved || 0) +
			(batch.model ? ', last model ' + batch.model : '');
		batchSection.appendChild(batchTitle);
		batchSection.appendChild(batchText);
		aiStatusPanel.appendChild(batchSection);

		var requestList = document.createElement('ul');
		requestList.className = 'rss-leads-ai-event-list';
		var requestsTitle = document.createElement('h3');
		requestsTitle.textContent = 'Recent Gemini requests';
		aiStatusPanel.appendChild(requestsTitle);
		(state.requests || []).slice(0, 8).forEach(function (event) {
			appendEvent(requestList, event, 'No Gemini requests recorded');
		});
		if (!(state.requests || []).length) {
			appendEvent(requestList, null, 'No Gemini requests recorded');
		}
		aiStatusPanel.appendChild(requestList);

		var errorList = document.createElement('ul');
		errorList.className = 'rss-leads-ai-event-list rss-leads-ai-error-list';
		var errorsTitle = document.createElement('h3');
		errorsTitle.textContent = 'Errors';
		aiStatusPanel.appendChild(errorsTitle);
		errors.slice(0, 10).forEach(function (event) {
			appendEvent(errorList, event, 'No errors recorded');
		});
		if (!errors.length) {
			appendEvent(errorList, null, 'No errors recorded');
		}
		aiStatusPanel.appendChild(errorList);
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
				showToast('Manual refresh failed', 'FreshRSS could not start the Reddit feed refresh.', 'error');
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

	function ensureCompactMeta(compactTarget) {
		var meta = compactTarget.querySelector('.rss-leads-title-meta');
		if (!meta) {
			meta = document.createElement('div');
			meta.className = 'rss-leads-title-meta';
			var compactLine = compactTarget.querySelector('.rss-leads-ai-compact');
			compactTarget.insertBefore(meta, compactLine || null);
		}
		return meta;
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
			ensureCompactMeta(compactTarget).appendChild(createCompactSubredditBadge(subreddit));
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
		if (priority === 'not_hiring') {
			return -1;
		}
		return 0;
	}

	function priorityColor(priority) {
		if (priority === 'high') {
			return { background: '#dc2626', text: '#ffffff', darkBackground: '#b91c1c', darkText: '#ffffff' };
		}
		if (priority === 'medium') {
			return { background: '#d97706', text: '#ffffff', darkBackground: '#b45309', darkText: '#ffffff' };
		}
		if (priority === 'not_hiring') {
			return { background: '#475569', text: '#ffffff', darkBackground: '#334155', darkText: '#ffffff' };
		}
		return { background: '#2563eb', text: '#ffffff', darkBackground: '#1d4ed8', darkText: '#ffffff' };
	}

	function priorityLabel(priority) {
		if (priority === 'not_hiring') {
			return 'not hiring';
		}
		return priority;
	}

	function monthlyAmountLabel(result) {
		var priority = String(result && result.priority || '').toLowerCase();
		var amount = String(result && result.monthly_amount || '').trim();
		if (priority !== 'high' || !amount) {
			return '';
		}
		return amount;
	}

	function jobTypeLabel(result) {
		return String(result && result.job_type || '').trim();
	}

	function scamLikelihood(result) {
		var value = Number(result && result.scam_likelihood);
		if (!isFinite(value)) {
			return null;
		}
		value = Math.round(value);
		if (value < 0) {
			return 0;
		}
		if (value > 100) {
			return 100;
		}
		return value;
	}

	function scamLikelihoodTone(score) {
		if (score >= 70) {
			return 'high';
		}
		if (score >= 35) {
			return 'medium';
		}
		return 'low';
	}

	function createScamBadge(score) {
		var badge = document.createElement('span');
		var tone = scamLikelihoodTone(score);
		badge.className = 'rss-leads-ai-scam rss-leads-ai-scam-' + tone;
		badge.textContent = 'Scam ' + score + '%';
		badge.title = 'LLM-estimated scam likelihood: ' + score + '%';
		var cut = Math.max(0, Math.min(100, score));
		var currentHue = Math.max(0, 120 - (cut * 1.2));
		var currentColor = 'hsl(' + currentHue + ', 80%, 30%)';
		badge.style.background = 'linear-gradient(90deg, ' + currentColor + ' 0%, ' + currentColor + ' ' + cut + '%, #1f2937 ' + cut + '%, #1f2937 100%)';
		return badge;
	}

	function applyBadgeColors(element, colors) {
		element.style.setProperty('--rss-leads-badge-bg', colors.background);
		element.style.setProperty('--rss-leads-badge-text', colors.text);
		element.style.setProperty('--rss-leads-badge-dark-bg', colors.darkBackground);
		element.style.setProperty('--rss-leads-badge-dark-text', colors.darkText);
	}

	function firstText(root, selectors) {
		for (var index = 0; index < selectors.length; index++) {
			var node = root.querySelector(selectors[index]);
			var text = node ? normalizeText(node.textContent) : '';
			if (text) {
				return text;
			}
		}
		return '';
	}

	function firstHref(root, selectors) {
		for (var index = 0; index < selectors.length; index++) {
			var node = root.querySelector(selectors[index]);
			var href = node ? String(node.href || node.getAttribute('href') || '').trim() : '';
			if (href && href.indexOf('javascript:') !== 0 && href !== '#') {
				return href;
			}
		}
		return '';
	}

	function removeNodes(root, selectors) {
		selectors.forEach(function (selector) {
			Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) {
				if (node.parentNode) {
					node.parentNode.removeChild(node);
				}
			});
		});
	}

	function articleText(root) {
		var content = root.querySelector('.flux_content .content') || root.querySelector('article.flux_content .content') || root.querySelector('.content');
		if (!content) {
			return firstText(root, ['.flux_header .rss-leads-ai-summary', '.flux_header .item.summary', '.flux_header .summary']);
		}
		var clone = content.cloneNode(true);
		removeNodes(clone, [
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
		return normalizeText(clone.textContent);
	}

	function leadLink(root) {
		var dataLink = root.getAttribute && root.getAttribute('data-link') ? String(root.getAttribute('data-link')).trim() : '';
		return dataLink || firstHref(root, [
			'.flux_content .content > header h1.title a.go_website',
			'.flux_content .content > header h1.title a[href]',
			'.flux_content a.go_website',
			'.flux_header a.go_website',
			'.flux_header a.title[href]',
			'h1.title a[href]'
		]);
	}

	function leadSubreddit(root, link) {
		var badge = firstText(root, ['.rss-leads-subreddit-badge', '.rss-leads-compact-badge']);
		if (badge.indexOf('r/') === 0) {
			return badge;
		}
		var subreddit = extractSubredditFromUrl(link);
		return subreddit ? 'r/' + subreddit : '';
	}

	function leadContext(root) {
		var link = leadLink(root);
		var title = firstText(root, [
			'.flux_content .content > header h1.title',
			'article.flux_content .content > header h1.title',
			'.flux_header .titleAuthorSummaryDate a.title',
			'.flux_header a.title',
			'h1.title'
		]);
		var priority = String(root.getAttribute && root.getAttribute('data-ai-priority') || '').trim();
		var aiSummary = String(root.getAttribute && root.getAttribute('data-ai-summary') || '').trim() ||
			firstText(root, ['.rss-leads-ai-card .rss-leads-ai-summary', '.flux_header .rss-leads-ai-summary']);
		var jobType = String(root.getAttribute && root.getAttribute('data-ai-job-type') || '').trim() ||
			firstText(root, ['.rss-leads-ai-card .rss-leads-ai-job-type', '.flux_header .rss-leads-ai-job-type']);
		var budget = firstText(root, ['.rss-leads-ai-card .rss-leads-ai-monthly', '.flux_header .rss-leads-ai-monthly']);
		var scamRisk = firstText(root, ['.rss-leads-ai-card .rss-leads-ai-scam', '.flux_header .rss-leads-ai-scam']);
		var author = firstText(root, ['.author', '.flux_header .item.website', '.flux_header .website']);

		return {
			title: limitText(title, 220),
			link: link,
			author: limitText(author, 120),
			subreddit: leadSubreddit(root, link),
			priority: priority ? priorityLabel(priority) : '',
			aiSummary: limitText(aiSummary, 500),
			jobType: limitText(jobType, 120),
			budget: limitText(budget, 120),
			scamRisk: limitText(scamRisk, 80),
			content: limitText(articleText(root), 1800)
		};
	}

	function buildQuickApplyPrompt(root) {
		var profile = limitText(getSavedCvProfile(), 2800);
		var lead = leadContext(root);
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

	function copyQuickApplyPrompt(prompt) {
		if (!navigator.clipboard || !window.isSecureContext) {
			return;
		}
		navigator.clipboard.writeText(prompt).catch(function () {
			// Opening ChatGPT is the primary action; clipboard support is best-effort.
		});
	}

	function openQuickApplyPrompt(root) {
		if (!getSavedCvProfile()) {
			openCvProfilePanel();
			showToast('Add CV profile first', 'Save your work experience once, then Quick apply can create ChatGPT prompts.', 'warning');
			return;
		}
		var prompt = buildQuickApplyPrompt(root);
		var url = 'https://chatgpt.com/?q=' + encodeURIComponent(prompt);
		var opened = window.open(url, '_blank');
		copyQuickApplyPrompt(prompt);
		if (opened) {
			try {
				opened.opener = null;
			} catch (error) {
				// Some browsers restrict access immediately after opening a new tab.
			}
			showToast('ChatGPT opened', 'A quick-apply DM prompt was sent to ChatGPT.', 'success');
		} else {
			showToast('ChatGPT popup blocked', 'Allow popups for FreshRSS, then click Quick apply again.', 'error');
		}
	}

	function createQuickApplyButton(root, compact) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'btn rss-leads-quick-apply-btn ' + (compact ? 'rss-leads-quick-apply-compact' : 'rss-leads-quick-apply-expanded');
		button.textContent = compact ? 'Apply' : 'Quick apply';
		button.title = 'Create an application DM in ChatGPT';
		button.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			openQuickApplyPrompt(root);
		});
		return button;
	}

	function annotateQuickApplyEntry(entry) {
		var compactHeader = entry.querySelector('.flux_header');
		if (compactHeader && !compactHeader.querySelector('.rss-leads-quick-apply-compact')) {
			compactHeader.appendChild(createQuickApplyButton(entry, true));
			entry.classList.add('rss-leads-quick-apply-ready');
		}

		var expandedHeader = entry.querySelector('.flux_content .content > header');
		if (expandedHeader && !expandedHeader.querySelector('.rss-leads-quick-apply-actions')) {
			var actions = document.createElement('div');
			actions.className = 'rss-leads-quick-apply-actions';
			actions.appendChild(createQuickApplyButton(entry, false));
			expandedHeader.insertBefore(actions, expandedHeader.firstChild);
		}
	}

	function annotateStandaloneQuickApply(article) {
		var header = article.querySelector('.content > header');
		if (!header || header.querySelector('.rss-leads-quick-apply-actions')) {
			return;
		}
		var actions = document.createElement('div');
		actions.className = 'rss-leads-quick-apply-actions';
		actions.appendChild(createQuickApplyButton(article, false));
		header.insertBefore(actions, header.firstChild);
	}

	function annotateQuickApplyButtons() {
		Array.prototype.forEach.call(document.querySelectorAll('.flux[data-entry]'), annotateQuickApplyEntry);
		Array.prototype.forEach.call(document.querySelectorAll('article.flux_content'), annotateStandaloneQuickApply);
	}

	function renderAiResult(entry, result) {
		var priority = String(result.priority || '').toLowerCase();
		if (!priorityRank(priority)) {
			return;
		}
		entry.setAttribute('data-ai-priority', priority);
		entry.setAttribute('data-ai-summary', result.summary || '');
		if (jobTypeLabel(result)) {
			entry.setAttribute('data-ai-job-type', jobTypeLabel(result));
		} else {
			entry.removeAttribute('data-ai-job-type');
		}

		var compactTarget = entry.querySelector('.flux_header .titleAuthorSummaryDate');
		if (compactTarget) {
			var compactMeta = ensureCompactMeta(compactTarget);
			Array.prototype.forEach.call(compactMeta.querySelectorAll('.rss-leads-ai-job-type, .rss-leads-ai-monthly, .rss-leads-ai-scam'), function (node) {
				node.remove();
			});
			var compactLine = compactTarget.querySelector('.rss-leads-ai-compact');
			if (!compactLine) {
				compactLine = document.createElement('div');
				compactLine.className = 'rss-leads-ai-compact';
				compactTarget.appendChild(compactLine);
			}
			compactLine.innerHTML = '';
			var amount = monthlyAmountLabel(result);
			var compactJobTypeLabel = jobTypeLabel(result);
			var compactScamLikelihood = scamLikelihood(result);
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
			if (compactScamLikelihood !== null) {
				compactMeta.appendChild(createScamBadge(compactScamLikelihood));
			}
			if (result.summary) {
				var compactSummary = document.createElement('span');
				compactSummary.className = 'rss-leads-ai-summary';
				compactSummary.textContent = result.summary;
				compactLine.appendChild(compactSummary);
			}

			var compactTitle = compactTarget.querySelector('a.title');
			if (compactTitle) {
				var inlineAi = compactMeta.querySelector('.rss-leads-ai-inline');
				if (!inlineAi) {
					inlineAi = document.createElement('span');
					inlineAi.className = 'rss-leads-ai-inline';
					compactMeta.insertBefore(inlineAi, compactMeta.firstChild);
				}
				inlineAi.className = 'rss-leads-ai-inline rss-leads-ai-inline-' + priority;
				inlineAi.textContent = priorityLabel(priority);
				applyBadgeColors(inlineAi, priorityColor(priority));

				var subredditBadge = compactMeta.querySelector('.rss-leads-compact-badge');
				if (subredditBadge && inlineAi.nextSibling !== subredditBadge) {
					compactMeta.insertBefore(subredditBadge, inlineAi.nextSibling);
				}
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
		badge.textContent = priorityLabel(priority);

		var amountLabel = monthlyAmountLabel(result);
		var jobType = jobTypeLabel(result);
		var scamScore = scamLikelihood(result);
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
		if (scamScore !== null) {
			card.appendChild(createScamBadge(scamScore));
		}
		card.appendChild(summary);
	}

	function applyAiSort() {
		var stream = document.getElementById('stream');
		if (!stream) {
			return;
		}
		Array.prototype.forEach.call(stream.querySelectorAll('.flux[data-entry]'), function (entry) {
			entry.style.order = '';
		});
		stream.classList.remove('rss-leads-ai-sorted');
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
		var url = aiUrl + '?status=1';
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
					renderAiResults(data.items || {});
					renderAiStatus(data.status || {});
				} else {
					renderAiStatus({
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
			})
			.catch(function (error) {
				renderAiStatus({
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
			annotateQuickApplyButtons();
			queueAiAnnotation();
		});
	}

	function watchSubredditEntries() {
		annotateSubreddits();
		annotateQuickApplyButtons();
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

		cvProfileButton = document.createElement('button');
		cvProfileButton.type = 'button';
		cvProfileButton.className = 'btn rss-leads-cv-profile-btn';
		cvProfileButton.title = 'Edit CV profile used by Quick apply';
		cvProfileButton.addEventListener('click', function () {
			if (!cvProfilePanel || cvProfilePanel.hidden) {
				openCvProfilePanel();
			} else {
				cvProfilePanel.hidden = true;
			}
		});

		aiStatusButton = document.createElement('button');
		aiStatusButton.type = 'button';
		aiStatusButton.className = 'btn rss-leads-ai-status-btn';
		aiStatusButton.textContent = 'AI loading';
		aiStatusButton.title = 'Show AI filter status';
		aiStatusButton.addEventListener('click', function () {
			if (!aiStatusPanel) {
				return;
			}
			aiStatusPanel.hidden = !aiStatusPanel.hidden;
			if (!aiStatusPanel.hidden && cvProfilePanel) {
				cvProfilePanel.hidden = true;
			}
			if (!aiStatusPanel.hidden && latestAiStatus) {
				renderAiStatus(latestAiStatus);
			}
		});

		aiStatusPanel = document.createElement('div');
		aiStatusPanel.id = 'rss-leads-ai-status-panel';
		aiStatusPanel.hidden = true;

		cvProfilePanel = document.createElement('div');
		cvProfilePanel.id = 'rss-leads-cv-profile-panel';
		cvProfilePanel.hidden = true;

		widget.appendChild(statusText);
		widget.appendChild(refreshButton);
		widget.appendChild(cvProfileButton);
		widget.appendChild(aiStatusButton);
		widget.appendChild(aiStatusPanel);
		widget.appendChild(cvProfilePanel);
		target.insertAdjacentElement('afterend', widget);
		updateCvProfileButton();
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
