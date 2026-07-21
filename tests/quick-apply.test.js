'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

global.navigator = {};
global.window = { isSecureContext: false };
global.rssLeadsNormalizeText = function (value) {
	return String(value || '').replace(/\s+/g, ' ').trim();
};
global.rssLeadsLimitText = function (value, maxLength) {
	value = global.rssLeadsNormalizeText(value);
	return value.length <= maxLength ? value : value.slice(0, maxLength - 3).trim() + '...';
};
global.rssLeadsExtractSubredditFromUrl = function () { return ''; };
global.rssLeadsPriorityLabel = function (value) { return value; };

const source = fs.readFileSync('extensions/RssLeadsStatus/static/05-quick-apply.js', 'utf8');
vm.runInThisContext(source, { filename: '05-quick-apply.js' });

assert.equal(rssLeadsRedditUsernameFromValue(';/u/Low_Direction5276'), 'Low_Direction5276');
assert.equal(rssLeadsRedditUsernameFromValue('https://www.reddit.com/user/Ok-Year-2443/'), 'Ok-Year-2443');
assert.equal(rssLeadsRedditUsernameFromValue('https://reddit.com/u/example_user'), 'example_user');
assert.equal(rssLeadsRedditUsernameFromValue('r/forhire'), '');
assert.equal(rssLeadsRedditUsernameFromValue(';/u/AutoModerator'), '');

const root = {
	querySelectorAll: function (selector) {
		return selector === 'a[href]' ? [{ href: 'https://www.reddit.com/user/Client_Name/' }] : [];
	},
	querySelector: function () { return null; }
};
assert.equal(rssLeadsLeadRedditUsername(root), 'Client_Name');

const rootWithAuthor = {
	querySelectorAll: function () {
		return [{ href: 'https://www.reddit.com/user/Wrong_Body_Mention/' }];
	},
	querySelector: function (selector) {
		return selector === '.author' ? { textContent: ';/u/Actual_Author' } : null;
	}
};
assert.equal(rssLeadsLeadRedditUsername(rootWithAuthor), 'Actual_Author');

assert.equal(
	rssLeadsRedditComposeUrl('Client_Name', 'Application: Video editor', 'Hello there\n\nI can help.'),
	'https://www.reddit.com/message/compose/?to=Client_Name&subject=Application%3A%20Video%20editor&message=Hello%20there%0A%0AI%20can%20help.'
);
assert.equal(
	rssLeadsQuickApplyChatGptUrl('Draft this DM\nwithout inventing facts.'),
	'https://chatgpt.com/?q=Draft%20this%20DM%0Awithout%20inventing%20facts.'
);

console.log('quick-apply tests passed');
