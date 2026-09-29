// Copyright 2026 Carnegie Mellon University. GPL v3 or later.
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../js/verify.js'), 'utf8');

function browser(security) {
    let submitted = 0;
    const timers = [];
    const form = {dataset: {failure: 'Verification failed'}, elements: {configkeyhash: {value: ''}},
        submit() { submitted++; }};
    const status = {textContent: 'Checking'};
    const window = {SafeExamBrowser: security ? {security} : undefined,
        setTimeout(fn, delay) { const item = {fn, delay}; timers.push(item); return item; },
        clearTimeout(item) { item.cancelled = true; }};
    const document = {readyState: 'complete', getElementById(id) {
        return id === 'torus-seb-verify' ? form : status;
    }};
    window.window = window;
    window.document = document;
    const context = vm.createContext(window);
    vm.runInContext(source, context);
    return {window, form, status, timers, submitted: () => submitted,
        dispatchNative(name) { vm.runInContext(name + '();', context); }};
}

test('waits for native updateKeys callback and posts proof only once', () => {
    let callback;
    const security = {configKey: ':', updateKeys(fn) { callback = fn; }};
    const page = browser(security);
    assert.equal(page.submitted(), 0);
    security.configKey = 'a'.repeat(64);
    callback();
    callback();
    assert.equal(page.submitted(), 1);
    assert.equal(page.form.elements.configkeyhash.value, security.configKey);
    assert.equal(page.timers[0].cancelled, true);
});

test('ordinary browsers time out without submitting', () => {
    const page = browser();
    page.timers.find(t => t.delay === 10000).fn();
    page.timers.find(t => t.delay === 100).fn();
    assert.equal(page.submitted(), 0);
    assert.equal(page.status.textContent, 'Verification failed');
});

test('late native API injection is supported', () => {
    const page = browser();
    page.window.SafeExamBrowser = {security: {configKey: 'b'.repeat(64), updateKeys(fn) { fn(); }}};
    page.timers.find(t => t.delay === 100).fn();
    assert.equal(page.submitted(), 1);
});

test('malformed proof and native errors fail without submission', () => {
    for (const configKey of [':', '', null, 'a'.repeat(63), 'A'.repeat(64)]) {
        const page = browser({configKey, updateKeys(fn) { fn(); }});
        assert.equal(page.submitted(), 0);
        assert.equal(page.status.textContent, 'Verification failed');
    }
    const page = browser({updateKeys() { throw new Error('native error'); }});
    assert.equal(page.submitted(), 0);
    assert.equal(page.status.textContent, 'Verification failed');
});

test('a native callback after timeout does not submit', () => {
    let callback;
    const page = browser({configKey: 'a'.repeat(64), updateKeys(fn) { callback = fn; }});
    page.timers.find(t => t.delay === 10000).fn();
    callback();
    assert.equal(page.submitted(), 0);
});

// macOS SEB posts callback.name to native code, then evaluates name + "();" in the page.
// Source: SafeExamBrowser/seb-mac, Classes/BrowserComponents/SEBAbstractModernWebView.swift.
test('macOS native bridge invokes the callback by its global name', () => {
    let callbackName;
    const security = {configKey: ':', updateKeys(fn) { callbackName = fn.name; }};
    const page = browser(security);
    assert.equal(page.submitted(), 0);
    security.configKey = 'c'.repeat(64);
    page.dispatchNative(callbackName);
    page.dispatchNative(callbackName);
    assert.equal(page.submitted(), 1);
    assert.equal(page.form.elements.configkeyhash.value, security.configKey);
});
