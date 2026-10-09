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

    // After a reservation form is submitted GLPI redirects back with the calendar
    // date: `defaultDate` since 11.0.11, `month` + `year` in 11.0.4; both nested in
    // `tab_params` when the back URL is an item form.
    var params = new URLSearchParams(window.location.search);
    var isReservationReturn = params.has('defaultDate')
        || params.has('tab_params[defaultDate]')
        || (params.has('month') && params.has('year'))
        || (params.has('tab_params[month]') && params.has('tab_params[year]'));
    if (!isReservationReturn) {
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
