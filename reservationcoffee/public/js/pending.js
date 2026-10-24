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

    // 1. Form prefill helper for GLPI form renderer (including flatpickr date/time)
    if (window.location.pathname.indexOf('/Form/Render/') !== -1) {
        var searchParams = new URLSearchParams(window.location.search);
        var prefillForm = function () {
            var form = document.getElementById('forms_form_answers');
            if (!form) {
                return;
            }
            searchParams.forEach(function (val, rawKey) {
                if (!val) {
                    return;
                }
                var uuidDashes = rawKey.replace(/_/g, '-');
                var input = form.querySelector('[name="questions[' + uuidDashes + ']"]')
                    || form.querySelector('[name="questions[' + rawKey + ']"]');
                if (!input) {
                    var container = form.querySelector('[data-glpi-form-renderer-uuid="' + uuidDashes + '"]')
                        || form.querySelector('[data-glpi-form-renderer-uuid="' + rawKey + '"]');
                    if (container) {
                        input = container.querySelector('input:not([type="hidden"]), textarea, select');
                    }
                }
                if (!input) {
                    return;
                }
                var fp = input._flatpickr || (input.parentElement && input.parentElement._flatpickr);
                if (fp && typeof fp.setDate === 'function') {
                    fp.setDate(val, true);
                } else if (!input.value) {
                    input.value = val;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(prefillForm, 50);
            });
        } else {
            setTimeout(prefillForm, 50);
        }
        return;
    }

    // 2. After a reservation form is submitted GLPI redirects back with the calendar
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
