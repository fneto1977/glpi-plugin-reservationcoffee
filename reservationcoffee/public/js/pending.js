/**
 * -------------------------------------------------------------------------
 * Reservation Coffee Break plugin for GLPI
 * -------------------------------------------------------------------------
 * This file is part of Reservation Coffee Break, licensed under GPLv3+.
 * -------------------------------------------------------------------------
 */

/* global CFG_GLPI */

(function () {
    'use strict';

    // GLPI always adds `defaultDate` (or `tab_params[defaultDate]`) to the URL
    // it redirects to after a reservation form is submitted.
    var params = new URLSearchParams(window.location.search);
    if (!params.has('defaultDate') && !params.has('tab_params[defaultDate]')) {
        return;
    }

    var rootDoc = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) ? String(CFG_GLPI.root_doc) : '';
    var offerPrefix = rootDoc + '/plugins/reservationcoffee/offer/';

    fetch(rootDoc + '/plugins/reservationcoffee/pending', {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
    }).then(function (response) {
        if (response.status !== 200) {
            return null;
        }
        return response.json();
    }).then(function (pending) {
        if (!pending || typeof pending.redirect_url !== 'string') {
            return;
        }
        var target = pending.redirect_url;
        // Only follow URLs pointing to this plugin's offer page.
        if (target.indexOf(offerPrefix) !== 0 || !/^\d+$/.test(target.substring(offerPrefix.length))) {
            return;
        }
        window.location.assign(target);
    }).catch(function () {
        // The reservation is already saved; without the offer the native flow simply continues.
    });
})();
