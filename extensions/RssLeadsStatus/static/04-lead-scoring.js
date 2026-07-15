'use strict';

// Lead text extraction, priority labels, money parsing, and fallback classification.

function rssLeadsPriorityRank(priority) {
	if (priority === 'x_high') {
		return 4;
	}
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

function rssLeadsPriorityColor(priority) {
	if (priority === 'x_high') {
		return { background: '#be123c', text: '#ffffff', darkBackground: '#9f1239', darkText: '#ffffff' };
	}
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

function rssLeadsPriorityLabel(priority) {
	if (priority === 'x_high') {
		return 'x-high';
	}
	if (priority === 'not_hiring') {
		return 'not hiring';
	}
	return priority;
}

function rssLeadsNormalizePriority(value) {
	var priority = String(value || '').toLowerCase().replace(/[\s-]+/g, '_');
	if (priority === 'xhigh' || priority === 'extra_high' || priority === 'very_high') {
		return 'x_high';
	}
	if (priority === 'not_hiring' || priority === 'not_hire' || priority === 'not_hiring_lead') {
		return 'not_hiring';
	}
	return priority;
}

function rssLeadsMonthlyAmountLabel(result) {
	var priority = rssLeadsNormalizePriority(result && result.priority);
	var amount = String(result && result.monthly_amount || '').trim();
	if ((priority !== 'medium' && priority !== 'high' && priority !== 'x_high') || !amount) {
		return '';
	}
	if (rssLeadsHourlyLabelLooksHourly(amount)) {
		return '';
	}
	return amount;
}

function rssLeadsHourlyAmountLabel(result) {
	return String(result && result.hourly_amount || '').trim();
}

function rssLeadsHourlyLabelLooksHourly(value) {
	return /(?:\/\s*(?:hr|hour)|per\s+hour|hourly)\b/i.test(String(value || ''));
}

function rssLeadsJobTypeLabel(result) {
	return String(result && result.job_type || '').trim();
}

function rssLeadsScamLikelihood(result) {
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

function rssLeadsShouldShowScamBadge(score) {
	return score !== null && score > 0;
}

function rssLeadsScamLikelihoodTone(score) {
	if (score >= 70) {
		return 'high';
	}
	if (score >= 35) {
		return 'medium';
	}
	return 'low';
}

function rssLeadsCreateScamBadge(score) {
	var badge = document.createElement('span');
	var tone = rssLeadsScamLikelihoodTone(score);
	badge.className = 'rss-leads-ai-scam rss-leads-ai-scam-' + tone;
	badge.textContent = 'Scam ' + score + '%';
	badge.title = 'LLM-estimated scam likelihood: ' + score + '%';
	return badge;
}

function rssLeadsApplyBadgeColors(element, colors) {
	// Badge colors are declared in CSS by priority class to keep FreshRSS CSP clean.
}

function rssLeadsCompactTitleText(entry) {
	return rssLeadsFirstText(entry, [
		'.flux_header .titleAuthorSummaryDate a.title',
		'.flux_header a.title',
		'.flux_content .content > header h1.title',
		'h1.title'
	]);
}

function rssLeadsFeedSourceText(entry) {
	return rssLeadsFirstText(entry, [
		'.flux_header .item.website',
		'.flux_header .website',
		'.flux_header .item.feed',
		'.flux_header .feed'
	]);
}

function rssLeadsTitleLooksHiring(text) {
	return /\b(?:hiring|paid|needed|need|looking for|seeking|wanted|will pay|opportunity|task|job|developer|editor|manager|assistant|support|sales|wordpress|shopify|woocommerce|automation|localization)\b/i.test(text);
}

function rssLeadsTitleLooksNotHiring(text) {
	if (/\[(?:hiring|paid|task)\]/i.test(text)) {
		return false;
	}
	return /(?:\[(?:for hire|hire me|offer)\]|\bfor hire\b|\bhire me\b|\bavailable for (?:work|projects)\b|\bportfolio\b|\bseeking (?:work|clients|a job|job)\b|\blooking for (?:work|clients|a job|job)\b|\bmy first\b|\bhow (?:do|to)\b|\bwhat(?:'s| is| do)\b|\bshould i\b|\bdo not pay\b|\bsubscription\b|\basked ai\b|\bfollowers?\b|\btestimonials?\b)/i.test(text);
}

function rssLeadsTermMap(value) {
	var stopWords = {
		about: true,
		after: true,
		also: true,
		based: true,
		being: true,
		business: true,
		client: true,
		clients: true,
		company: true,
		could: true,
		experience: true,
		from: true,
		have: true,
		help: true,
		into: true,
		looking: true,
		make: true,
		need: true,
		needs: true,
		post: true,
		project: true,
		projects: true,
		role: true,
		service: true,
		services: true,
		some: true,
		that: true,
		their: true,
		them: true,
		this: true,
		with: true,
		work: true,
		would: true
	};
	var terms = {};
	String(value || '').toLowerCase().replace(/[a-z0-9+#/.-]{4,}/g, function (term) {
		term = term.replace(/^[^a-z0-9+#]+|[^a-z0-9+#]+$/g, '');
		if (term && !stopWords[term]) {
			terms[term] = true;
		}
		return term;
	});
	return terms;
}

function rssLeadsProfilePhraseMatches(profile, text) {
	var matches = 0;
	var lowerText = String(text || '').toLowerCase();
	var words = Object.keys(rssLeadsTermMap(profile));
	for (var index = 0; index < words.length - 1; index++) {
		var pair = words[index] + ' ' + words[index + 1];
		if (pair.length >= 10 && lowerText.indexOf(pair) !== -1) {
			matches++;
		}
	}
	return matches;
}

function rssLeadsCvFit(text) {
	var profile = rssLeadsGetSavedCvProfile();
	if (!profile) {
		return 'low';
	}
	var profileTerms = rssLeadsTermMap(profile);
	var textTerms = rssLeadsTermMap(text);
	var matched = 0;
	Object.keys(profileTerms).forEach(function (term) {
		if (textTerms[term]) {
			matched++;
		}
	});
	var phrases = rssLeadsProfilePhraseMatches(profile, text);
	if (matched >= 9 || (matched >= 6 && phrases >= 2)) {
		return 'extreme';
	}
	return matched >= 4 || (matched >= 3 && phrases > 0) ? 'high' : 'low';
}

function rssLeadsCvProfileLooksHighlyRelevant(text) {
	return rssLeadsCvFit(text) !== 'low';
}

function rssLeadsComputerVisionLooksRelevant(text) {
	return /\b(?:computer vision|opencv|object detection|image recognition|image segmentation|vision model|cv model|ocr|yolo|image labeling|label images|visual inspection)\b/i.test(text);
}

function rssLeadsExtremelyRelevantSignal(text) {
	return rssLeadsCvFit(text) === 'extreme';
}

function rssLeadsMoneyNumber(value, suffix) {
	var number = Number(String(value || '').replace(/,/g, ''));
	if (!isFinite(number)) {
		return 0;
	}
	suffix = String(suffix || '').toLowerCase();
	if (suffix === 'm') {
		return number * 1000000;
	}
	if (suffix === 'k') {
		return number * 1000;
	}
	return number;
}

function rssLeadsMoneyLabel(currency, min, max, unit) {
	var symbol = currency || '$';
	var roundedMin = Math.max(1, Math.round(min));
	var roundedMax = Math.max(1, Math.round(max || min));
	var range = Math.abs(roundedMax - roundedMin) <= 1
		? symbol + String(roundedMin)
		: symbol + String(roundedMin) + '-' + symbol + String(roundedMax);
	return unit ? range + unit : range;
}

function rssLeadsPaymentKnown(amount) {
	amount = String(amount || '').trim().toLowerCase();
	return amount !== '' && amount !== 'unknown';
}

function rssLeadsHighRequiresPayment(priority, amount) {
	return priority;
}

function rssLeadsNormalizeCvFit(value) {
	value = String(value || '').toLowerCase().replace(/[\s-]+/g, '_');
	if (value === 'extreme' || value === 'exceptional' || value === 'perfect') {
		return 'extreme';
	}
	return value === 'high' || value === 'strong' || value === 'good' ? 'high' : 'low';
}

function rssLeadsBestHourlySignal(signals) {
	return (signals || []).filter(function (signal) {
		return signal && signal.unit === '/hr';
	}).sort(function (left, right) {
		return right.max - left.max;
	})[0] || null;
}

function rssLeadsMoneyPriority(signal, cvFit) {
	var portfolioAvailable = Boolean(String(rssLeadsGetSavedCvProfile() || '').trim());
	if (portfolioAvailable && (cvFit === 'high' || cvFit === 'extreme')) {
		return 'x_high';
	}
	if (signal && signal.monthly >= 1000) {
		return 'high';
	}
	return portfolioAvailable ? 'medium' : 'low';
}

function rssLeadsMoneySignals(text) {
	var signals = [];
	var normalized = String(text || '').replace(/\s+/g, ' ');
	var pattern = /([\$\u00a3\u20ac])\s*([0-9][0-9,]*(?:\.[0-9]+)?)\s*([kKmM]?)(?:\s*(?:-|\u2013|to)\s*[\$\u00a3\u20ac]?\s*([0-9][0-9,]*(?:\.[0-9]+)?)\s*([kKmM]?))?/g;
	var match;
	while ((match = pattern.exec(normalized)) !== null) {
		var before = normalized.slice(Math.max(0, match.index - 24), match.index).toLowerCase();
		var after = normalized.slice(pattern.lastIndex, pattern.lastIndex + 42).toLowerCase();
		var context = before + ' ' + after;
		var min = rssLeadsMoneyNumber(match[2], match[3]);
		var max = match[4] ? rssLeadsMoneyNumber(match[4], match[5]) : min;
		if (max < min) {
			var swap = min;
			min = max;
			max = swap;
		}
		var unit = '';
		var monthly = max;
		if (/(?:\/\s*(?:hr|hour)|per\s+hour|hourly)\b/.test(context)) {
			unit = '/hr';
			monthly = max * 160;
		} else if (/(?:\/\s*(?:wk|week)|per\s+week|weekly)\b/.test(context)) {
			unit = '/wk';
			monthly = max * 4.33;
		} else if (/(?:\/\s*(?:mo|month)|per\s+month|monthly)\b/.test(context)) {
			unit = '/mo';
			monthly = max;
		} else if (/(?:\/\s*(?:yr|year)|per\s+year|yearly|annual|annually|salary)\b/.test(context)) {
			unit = '/yr';
			monthly = max / 12;
		}
		signals.push({
			currency: match[1],
			min: min,
			max: max,
			monthly: monthly,
			unit: unit
		});
	}
	signals.sort(function (a, b) {
		return b.monthly - a.monthly;
	});
	return signals;
}

function rssLeadsFallbackJobType(text) {
	var lower = String(text || '').toLowerCase();
	var patterns = [
		[/wordpress|woocommerce|shopify/, 'web development'],
		[/web\s*(?:site|development|developer)|frontend|backend|landing page/, 'web development'],
		[/video|editor|editing/, 'video editing'],
		[/social media|instagram|tiktok|facebook|ads/, 'social media'],
		[/virtual assistant|\bva\b|admin|data entry|support/, 'virtual assistant'],
		[/sales|closed deal|outreach|partnership|lead generation/, 'sales'],
		[/reddit|posting|copy-paste|copy paste/, 'reddit posting'],
		[/automation|n8n|zapier|workflow|ai chatbot|chatbot/, 'automation'],
		[/locali[sz]ation|translation|polish|english/, 'localization'],
		[/podcast|script|writer|writing|copy/, 'writing']
	];
	for (var index = 0; index < patterns.length; index++) {
		if (patterns[index][0].test(lower)) {
			return patterns[index][1];
		}
	}
	return '';
}

function rssLeadsFallbackAiResult(entry) {
	var title = rssLeadsCompactTitleText(entry);
	var source = rssLeadsFeedSourceText(entry);
	var link = rssLeadsLeadLink(entry);
	var subreddit = rssLeadsSubredditFromFeedEntry(entry);
	var combined = [title, source, rssLeadsArticleText(entry)].join(' ');
	var sourceLower = source.toLowerCase();
	if (!subreddit && sourceLower.indexOf('reddit leads') === -1 && link.indexOf('reddit.com/r/') === -1) {
		return null;
	}
	var signals = rssLeadsMoneySignals(combined);
	var bestMoney = signals[0] || null;
	var cvFit = rssLeadsCvFit(combined);
	if (sourceLower.indexOf('high priority') !== -1 || sourceLower.indexOf('medium-high') !== -1 || sourceLower.indexOf('medium high') !== -1) {
		var highPriority = rssLeadsMoneyPriority(bestMoney, cvFit);
		var highHourly = rssLeadsBestHourlySignal(signals);
		var highMonthlyAmount = (highPriority === 'medium' || highPriority === 'high' || highPriority === 'x_high') && bestMoney && bestMoney.unit !== '/hr' ? rssLeadsMoneyLabel(bestMoney.currency, bestMoney.min, bestMoney.max, bestMoney.unit) : '';
		var highHourlyAmount = highHourly ? rssLeadsMoneyLabel(highHourly.currency, highHourly.min, highHourly.max, highHourly.unit) : '';
		highPriority = rssLeadsHighRequiresPayment(highPriority, highMonthlyAmount || highHourlyAmount);
		return {
			fallback: true,
			priority: highPriority,
			summary: '',
			monthly_amount: highMonthlyAmount,
			hourly_amount: highHourlyAmount,
				job_type: rssLeadsFallbackJobType(combined),
				cv_fit: cvFit,
			scam_likelihood: 0
		};
	}
	if (sourceLower.indexOf('unqualified') !== -1 || rssLeadsTitleLooksNotHiring(title)) {
		return {
			fallback: true,
			priority: 'not_hiring',
			summary: '',
			monthly_amount: '',
			job_type: '',
			scam_likelihood: 0
		};
	}
	var priority = rssLeadsMoneyPriority(bestMoney, cvFit);
	if (!bestMoney && !rssLeadsTitleLooksHiring(title)) {
		priority = 'not_hiring';
	} else if (priority !== 'x_high' && rssLeadsExtremelyRelevantSignal(combined)) {
		priority = 'x_high';
	}
	var hourly = rssLeadsBestHourlySignal(signals);
	var monthlyAmount = (priority === 'medium' || priority === 'high' || priority === 'x_high') && bestMoney && bestMoney.unit !== '/hr' ? rssLeadsMoneyLabel(bestMoney.currency, bestMoney.min, bestMoney.max, bestMoney.unit) : '';
	var hourlyAmount = hourly ? rssLeadsMoneyLabel(hourly.currency, hourly.min, hourly.max, hourly.unit) : '';
	priority = rssLeadsHighRequiresPayment(priority, monthlyAmount || hourlyAmount);
	return {
		fallback: true,
		priority: priority,
		summary: '',
		monthly_amount: monthlyAmount,
		hourly_amount: hourlyAmount,
		job_type: priority === 'not_hiring' ? '' : rssLeadsFallbackJobType(combined),
		cv_fit: priority === 'not_hiring' ? 'low' : cvFit,
		scam_likelihood: 0
	};
}

function rssLeadsDisplayResultForEntry(entry, result) {
	var display = {};
	Object.keys(result || {}).forEach(function (key) {
		display[key] = result[key];
	});
	var priority = rssLeadsNormalizePriority(display.priority);
	if (!priority || priority === 'not_hiring') {
		display.priority = priority;
		return display;
	}

	var context = [
		rssLeadsCompactTitleText(entry),
		rssLeadsFeedSourceText(entry),
		rssLeadsArticleText(entry),
		String(display.summary || ''),
		String(display.job_type || ''),
		String(display.monthly_amount || '')
	].join(' ');
	var signals = rssLeadsMoneySignals(context);
	var bestMoney = signals[0] || null;
	var hourly = rssLeadsBestHourlySignal(signals);
	var cvFit = result && result.cv_fit ? rssLeadsNormalizeCvFit(result.cv_fit) : rssLeadsCvFit(context);
	var moneyPriority = rssLeadsMoneyPriority(bestMoney, cvFit);
	if (rssLeadsPriorityRank(priority) < rssLeadsPriorityRank('medium') && rssLeadsPriorityRank(moneyPriority) >= rssLeadsPriorityRank('medium')) {
		priority = 'medium';
	}
	priority = moneyPriority;
	display.cv_fit = cvFit;
	if (hourly && !String(display.hourly_amount || '').trim()) {
		display.hourly_amount = rssLeadsMoneyLabel(hourly.currency, hourly.min, hourly.max, hourly.unit);
	}
	if (rssLeadsHourlyLabelLooksHourly(display.monthly_amount)) {
		display.hourly_amount = String(display.monthly_amount || '').trim();
		display.monthly_amount = '';
	}
	if ((priority === 'medium' || priority === 'high' || priority === 'x_high') && !String(display.monthly_amount || '').trim() && bestMoney && bestMoney.unit !== '/hr') {
		display.monthly_amount = rssLeadsMoneyLabel(bestMoney.currency, bestMoney.min, bestMoney.max, bestMoney.unit);
	}
	priority = rssLeadsHighRequiresPayment(priority, String(display.monthly_amount || '') || String(display.hourly_amount || ''));
	display.priority = priority;
	return display;
}
