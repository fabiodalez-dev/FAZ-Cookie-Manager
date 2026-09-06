/** Language selection, drafts, round trips and failed category saves. */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../../../admin/assets/js/pages/cookies.js', import.meta.url), 'utf8');
const dom = new JSDOM(`<!doctype html><select id="faz-category-language">
<option value="en">English</option><option value="ru">Russian</option><option value="uk">Ukrainian</option></select>
<table id="faz-category-edit-table" data-show-ccpa="1"><tbody id="faz-category-edit-rows"></tbody></table>
<ul id="faz-cat-list"></ul><button id="faz-save-categories">Save</button>`, { runScripts: 'outside-only' });
const { window } = dom;
const doc = window.document;
let saved = [];
let reject = false;
let rows = [{ id: 1, slug: 'analytics', name: { en: 'Analytics', ru: 'Аналитические', uk: 'Аналітичні', de: 'Analyse' },
  description: { en: 'English', ru: 'Описание', uk: 'Опис', de: 'Deutsch' }, sell_personal_data: true, share_personal_data: true }];
window.fazConfig = { languages: { default: 'en', selected: ['en', 'ru', 'uk'] } };
window.FAZ = {
  ready() {},
  notify() {},
  get() { return Promise.resolve(structuredClone(rows)); },
  put(path, payload) {
    saved.push(JSON.parse(JSON.stringify(payload)));
    if (reject) return Promise.reject(new Error('Save failed'));
    rows = [{ ...rows[0], ...JSON.parse(JSON.stringify(payload)) }];
    return Promise.resolve(rows[0]);
  },
};
window.eval(source.replace('FAZ.ready(function () {', 'window.categoryTest = { initCategoryLanguage, loadCategories, saveCategoryEdits, renderCategories }; FAZ.ready(function () {'));
window.categoryTest.initCategoryLanguage();
await window.categoryTest.loadCategories(true);
const select = doc.getElementById('faz-category-language');
const name = () => doc.querySelector('.faz-cat-edit-name');
const description = () => doc.querySelector('.faz-cat-edit-desc');
const switchTo = lang => { select.value = lang; select.dispatchEvent(new window.Event('change')); };
const settle = async () => { for (let i = 0; i < 15; i++) await Promise.resolve(); };
assert.equal(name().value, 'Analytics');
switchTo('ru');
assert.equal(name().value, 'Аналитические');
name().value = 'Наш анализ';
description().value = 'Наше описание';
doc.querySelector('.faz-cat-edit-sell').checked = false;
switchTo('uk');
assert.equal(name().value, 'Аналітичні');
assert.equal(doc.querySelector('.faz-cat-edit-sell').checked, false);
name().value = 'Наша аналітика';
switchTo('ru');
assert.equal(name().value, 'Наш анализ');
assert.equal(description().value, 'Наше описание');
window.categoryTest.saveCategoryEdits();
assert.equal(select.disabled, true);
await settle();
assert.equal(select.disabled, false);
assert.deepEqual(saved[0].name, { en: 'Analytics', ru: 'Наш анализ', uk: 'Наша аналітика', de: 'Analyse' });
assert.equal(saved[0].description.uk, 'Опис');
assert.equal(saved[0].description.de, 'Deutsch');
assert.equal(saved[0].sell_personal_data, false);
assert.equal(name().value, 'Наш анализ');
reject = true;
name().value = 'Повторить';
window.categoryTest.saveCategoryEdits();
await settle();
assert.equal(name().value, 'Повторить', 'failed save must preserve the draft');
assert.equal(select.disabled, false);
reject = false;
window.categoryTest.saveCategoryEdits();
await settle();
assert.equal(saved.at(-1).name.ru, 'Повторить');
assert.equal(saved.at(-1).name.uk, 'Наша аналітика');
// An empty translation must remain empty when edited; English is not copied into it.
rows[0].name.uk = '';
await window.categoryTest.loadCategories(true);
switchTo('uk');
assert.equal(name().value, '');
// An unsaved draft belongs to the editor and must not leak into the category
// filter list, which renders from its own copy. These were the same array until
// the editor started writing drafts into it, so any re-render of the filter bar
// showed wording nobody had saved.
switchTo('en');
assert.equal(name().value, 'Analytics');
name().value = 'Draft not saved';
switchTo('ru');           // forces captureCategoryDrafts() to store the draft
window.categoryTest.renderCategories();
assert.ok(
  !doc.getElementById('faz-cat-list').textContent.includes('Draft not saved'),
  'an unsaved draft must not appear in the category filter list'
);
assert.ok(
  doc.getElementById('faz-cat-list').textContent.includes('Analytics'),
  'the filter list still shows the saved name'
);
dom.window.close();
console.log('category editor i18n: 21 checks passed');
