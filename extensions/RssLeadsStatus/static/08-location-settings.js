'use strict';

// Local location picker for high-priority in-person filtering.

var rssLeadsLocationSuggestions = [
	'Bengaluru',
	'Bangalore',
	'Mumbai',
	'Delhi',
	'New Delhi',
	'Gurugram',
	'Gurgaon',
	'Noida',
	'Hyderabad',
	'Pune',
	'Chennai',
	'Kolkata',
	'Ahmedabad',
	'Jaipur',
	'Kochi',
	'Indore',
	'Lucknow',
	'Chandigarh',
	'San Francisco',
	'New York',
	'Los Angeles',
	'London',
	'Singapore',
	'Dubai'
];

function rssLeadsLocationValues() {
	if (!rssLeadsLatestLocationSettings || !Array.isArray(rssLeadsLatestLocationSettings.locations)) {
		return [];
	}
	return rssLeadsLatestLocationSettings.locations.slice();
}

function rssLeadsNormalizeLocationLabel(value) {
	return String(value || '').replace(/\s+/g, ' ').trim();
}

function rssLeadsLocationKey(value) {
	return rssLeadsNormalizeLocationLabel(value).toLowerCase();
}

function rssLeadsSetLocationValues(locations) {
	var seen = {};
	rssLeadsLatestLocationSettings = rssLeadsLatestLocationSettings || {};
	rssLeadsLatestLocationSettings.locations = locations.map(rssLeadsNormalizeLocationLabel).filter(function (location) {
		var key = rssLeadsLocationKey(location);
		if (!key || seen[key]) {
			return false;
		}
		seen[key] = true;
		return true;
	});
	rssLeadsRenderLocationList();
	rssLeadsUpdateLocationButton();
}

function rssLeadsUpdateLocationButton() {
	if (!rssLeadsLocationButton) {
		return;
	}
	var count = rssLeadsLocationValues().length;
	rssLeadsLocationButton.textContent = count ? 'Location ' + count : 'Location';
	rssLeadsLocationButton.classList.toggle('rss-leads-location-empty', count === 0);
	rssLeadsLocationButton.title = count
		? 'Local aliases: ' + rssLeadsLocationValues().join(', ')
		: 'Set local locations for in-person high-priority filtering';
}

function rssLeadsRenderLocationList() {
	if (!rssLeadsLocationList) {
		return;
	}
	rssLeadsClearNode(rssLeadsLocationList);
	var locations = rssLeadsLocationValues();
	if (!locations.length) {
		var empty = document.createElement('p');
		empty.className = 'rss-leads-location-empty-state';
		empty.textContent = 'No local locations set. In-person jobs can still become high priority until you add at least one location.';
		rssLeadsLocationList.appendChild(empty);
		return;
	}
	locations.forEach(function (location) {
		var chip = document.createElement('button');
		chip.type = 'button';
		chip.className = 'rss-leads-location-chip';
		chip.textContent = location + ' x';
		chip.title = 'Remove ' + location;
		chip.addEventListener('click', function () {
			rssLeadsSetLocationValues(rssLeadsLocationValues().filter(function (item) {
				return rssLeadsLocationKey(item) !== rssLeadsLocationKey(location);
			}));
		});
		rssLeadsLocationList.appendChild(chip);
	});
}

function rssLeadsAddLocationFromInput() {
	if (!rssLeadsLocationInput) {
		return;
	}
	var value = rssLeadsNormalizeLocationLabel(rssLeadsLocationInput.value);
	if (!value) {
		return;
	}
	var locations = rssLeadsLocationValues();
	locations.push(value);
	rssLeadsSetLocationValues(locations);
	rssLeadsLocationInput.value = '';
	rssLeadsLocationInput.focus();
}

function rssLeadsBuildLocationPanel() {
	if (!rssLeadsLocationPanel || rssLeadsLocationPanel.childNodes.length) {
		return;
	}
	var header = document.createElement('div');
	header.className = 'rss-leads-location-panel-header';
	var title = document.createElement('strong');
	title.textContent = 'Local location';
	var close = document.createElement('button');
	close.type = 'button';
	close.className = 'rss-leads-location-close';
	close.textContent = 'Close';
	close.addEventListener('click', function () {
		rssLeadsLocationPanel.hidden = true;
	});
	header.appendChild(title);
	header.appendChild(close);

	var body = document.createElement('div');
	body.className = 'rss-leads-location-body';

	var label = document.createElement('label');
	label.className = 'rss-leads-location-label';
	label.setAttribute('for', 'rss-leads-location-input');
	label.textContent = 'Add city, region, or alias';

	var row = document.createElement('div');
	row.className = 'rss-leads-location-picker-row';
	rssLeadsLocationInput = document.createElement('input');
	rssLeadsLocationInput.id = 'rss-leads-location-input';
	rssLeadsLocationInput.type = 'text';
	rssLeadsLocationInput.setAttribute('list', 'rss-leads-location-suggestions');
	rssLeadsLocationInput.placeholder = 'Bengaluru, Bangalore, Karnataka';
	rssLeadsLocationInput.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			rssLeadsAddLocationFromInput();
		}
	});

	var datalist = document.createElement('datalist');
	datalist.id = 'rss-leads-location-suggestions';
	rssLeadsLocationSuggestions.forEach(function (suggestion) {
		var option = document.createElement('option');
		option.value = suggestion;
		datalist.appendChild(option);
	});

	var add = document.createElement('button');
	add.type = 'button';
	add.className = 'rss-leads-location-add';
	add.textContent = 'Add';
	add.addEventListener('click', rssLeadsAddLocationFromInput);
	row.appendChild(rssLeadsLocationInput);
	row.appendChild(add);

	rssLeadsLocationList = document.createElement('div');
	rssLeadsLocationList.className = 'rss-leads-location-list';

	rssLeadsLocationStatus = document.createElement('p');
	rssLeadsLocationStatus.className = 'rss-leads-location-status';

	var actions = document.createElement('div');
	actions.className = 'rss-leads-location-actions';
	var clear = document.createElement('button');
	clear.type = 'button';
	clear.className = 'rss-leads-location-secondary';
	clear.textContent = 'Clear';
	clear.addEventListener('click', function () {
		rssLeadsSetLocationValues([]);
	});
	var save = document.createElement('button');
	save.type = 'button';
	save.className = 'rss-leads-location-save';
	save.textContent = 'Save location';
	save.addEventListener('click', rssLeadsSaveLocationSettings);
	actions.appendChild(clear);
	actions.appendChild(save);

	body.appendChild(label);
	body.appendChild(row);
	body.appendChild(datalist);
	body.appendChild(rssLeadsLocationList);
	body.appendChild(rssLeadsLocationStatus);
	body.appendChild(actions);
	rssLeadsLocationPanel.appendChild(header);
	rssLeadsLocationPanel.appendChild(body);
}

function rssLeadsRenderLocationStatus(settings) {
	if (!rssLeadsLocationStatus) {
		return;
	}
	var effective = settings && Array.isArray(settings.effective_locations) ? settings.effective_locations : rssLeadsLocationValues();
	if (!effective.length) {
		rssLeadsLocationStatus.textContent = 'Location filter is off. Remote jobs are unaffected either way.';
		return;
	}
	rssLeadsLocationStatus.textContent = 'In-person or hybrid jobs must mention: ' + effective.join(', ') + '. Remote jobs are not filtered.';
}

function rssLeadsFetchLocationSettings() {
	return window.fetch(rssLeadsLocationUrl, {
		credentials: 'same-origin',
		cache: 'no-store'
	})
		.then(function (response) {
			if (!response.ok) {
				throw new Error('location status ' + response.status);
			}
			return response.json();
		})
		.then(function (settings) {
			rssLeadsLatestLocationSettings = settings;
			rssLeadsRenderLocationList();
			rssLeadsRenderLocationStatus(settings);
			rssLeadsUpdateLocationButton();
			return settings;
		})
		.catch(function () {
			rssLeadsUpdateLocationButton();
			return null;
		});
}

function rssLeadsSaveLocationSettings() {
	var locations = rssLeadsLocationValues();
	return window.fetch(rssLeadsLocationUrl, {
		method: 'POST',
		credentials: 'same-origin',
		cache: 'no-store',
		headers: {
			'Content-Type': 'application/json'
		},
		body: JSON.stringify({ locations: locations })
	})
		.then(function (response) {
			if (!response.ok) {
				throw new Error('location save ' + response.status);
			}
			return response.json();
		})
		.then(function (settings) {
			rssLeadsLatestLocationSettings = settings;
			rssLeadsRenderLocationList();
			rssLeadsRenderLocationStatus(settings);
			rssLeadsUpdateLocationButton();
			rssLeadsShowToast('Location saved', 'High-priority location filtering will use the updated aliases.', 'success');
			return settings;
		})
		.catch(function () {
			rssLeadsShowToast('Location save failed', 'FreshRSS could not save the local location settings.', 'error');
		});
}

function rssLeadsOpenLocationPanel() {
	rssLeadsBuildLocationPanel();
	rssLeadsLocationPanel.hidden = false;
	rssLeadsFetchLocationSettings().then(function () {
		rssLeadsRenderLocationList();
		rssLeadsRenderLocationStatus(rssLeadsLatestLocationSettings);
		if (rssLeadsLocationInput) {
			rssLeadsLocationInput.focus();
		}
	});
}
