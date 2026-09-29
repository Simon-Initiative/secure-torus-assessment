// Copyright 2026 Carnegie Mellon University.
// License: http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.

/**
 * Submit only the URL-salted Config Key obtained from SEB's native JavaScript API.
 * No raw key or expected proof is supplied by Moodle to this script.
 */
(function() {
    'use strict';
    const start = function() {
        const form = document.getElementById('torus-seb-verify');
        if (!form) {
            return;
        }
        const status = document.getElementById('torus-seb-status');
        let finished = false;
        const fail = function() {
            if (!finished) {
                finished = true;
                status.textContent = form.dataset.failure;
            }
        };
        const timeout = window.setTimeout(fail, 10000);
        const poll = function() {
            if (finished) {
                return;
            }
            const security = window.SafeExamBrowser && window.SafeExamBrowser.security;
            if (!security || typeof security.updateKeys !== 'function') {
                window.setTimeout(poll, 100);
                return;
            }
            try {
                // macOS SEB invokes callback.name in the page, not the function object.
                // Keep an explicitly named callback reachable on window, including after timeout.
                window.torusSEBKeysUpdated = function torusSEBKeysUpdated() {
                    if (finished) {
                        return;
                    }
                    const proof = security.configKey;
                    if (typeof proof !== 'string' || !/^[0-9a-f]{64}$/.test(proof)) {
                        fail();
                        return;
                    }
                    finished = true;
                    window.clearTimeout(timeout);
                    form.elements.configkeyhash.value = proof;
                    form.submit();
                };
                security.updateKeys(window.torusSEBKeysUpdated);
            } catch (error) {
                fail();
            }
        };
        poll();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, {once: true});
    } else {
        start();
    }
}());
