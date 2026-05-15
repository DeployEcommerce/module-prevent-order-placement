/**
 * Copyright © DeployEcommerce. All rights reserved.
 *
 * Attaches on-blur match-count previews to each row of the blocklist field array.
 * Rendered marker carries the AJAX endpoint + form key; this module finds the
 * sibling field-array table, listens for blur/change on each row's inputs/select,
 * posts the current row state to the endpoint, and renders an inline result row
 * directly below.
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    var TEXT_FIELDS = ['street', 'city', 'county', 'postcode', 'phone'];
    var SELECT_FIELDS = ['address_scope'];
    var FIELDS = TEXT_FIELDS.concat(SELECT_FIELDS);

    // Field-array columns: 5 text inputs + 1 select + 1 delete-button column = 7.
    var ROW_COLSPAN = TEXT_FIELDS.length + SELECT_FIELDS.length + 1;

    function colorFor(percent) {
        if (percent > 10) {
            return '#d10202'; // red
        }
        if (percent >= 5) {
            return '#e07c00'; // orange
        }
        return '#1a7f37'; // green
    }

    function formatNumber(n) {
        return Number(n).toLocaleString('en-GB');
    }

    function renderResult($note, data) {
        if (data && data.too_loose) {
            $note.html(
                '<span style="color:#e07c00;">'
                + $t('Rule too loose to estimate. Enter at least %1 characters per field.')
                    .replace('%1', data.minimum || 2)
                + '</span>'
            );
            return;
        }

        var orders = data.orders || {};
        var quotes = data.quotes || {};
        var orderPct = Number(orders.percent || 0);
        var quotePct = Number(quotes.percent || 0);
        var color = colorFor(Math.max(orderPct, quotePct));

        var orderText = $t('Matches: %1 / %2 orders (%3%)')
            .replace('%1', formatNumber(orders.matched))
            .replace('%2', formatNumber(orders.total))
            .replace('%3', orderPct.toFixed(2));
        var quoteText = $t('Active quotes with addresses: %1 / %2 (%3%)')
            .replace('%1', formatNumber(quotes.matched))
            .replace('%2', formatNumber(quotes.total))
            .replace('%3', quotePct.toFixed(2));

        $note.html(
            '<span class="dep-bp-badge" style="display:inline-block;padding:3px 8px;border-radius:3px;color:#fff;background:' + color + ';margin-right:8px;font-weight:600;">'
            + orderText
            + '</span>'
            + '<span style="color:#555;">'
            + quoteText
            + '</span>'
        );
    }

    function renderError($note, message) {
        $note.html(
            '<span style="color:#d10202;">'
            + $t('Preview failed') + ': ' + (message || $t('Unknown error'))
            + '</span>'
        );
    }

    function readRow($row) {
        var rule = {};
        FIELDS.forEach(function (field) {
            var $input = $row.find('[name$="[' + field + ']"]');
            if (!$input.length) {
                $input = $row.find('select[name$="[' + field + ']"]');
            }
            rule[field] = $input.length ? $input.val() : '';
        });
        return rule;
    }

    function digitsOnly(value) {
        return (value || '').toString().replace(/\D+/g, '');
    }

    function normalizedFieldValue(field, value) {
        if (field === 'phone') {
            return digitsOnly(value);
        }
        return (value || '').toString().trim().toLowerCase();
    }

    function rowHasAnyCriterion(rule) {
        // Mirror the backend's normalization so a phone like "n/a" — which the
        // server reduces to "" and the backend rule-loader would drop entirely —
        // doesn't trigger a preview that comes back as a false 0-match.
        return TEXT_FIELDS.some(function (f) {
            return normalizedFieldValue(f, rule[f]) !== '';
        });
    }

    function getNoteRow($row) {
        var $next = $row.next();
        return ($next.length && $next.hasClass('dep-bp-note-row')) ? $next : null;
    }

    function ensureNoteRow($row) {
        var $existing = getNoteRow($row);
        if ($existing) {
            return $existing.find('.dep-bp-note');
        }
        var $newRow = $(
            '<tr class="dep-bp-note-row">'
            + '<td colspan="' + ROW_COLSPAN + '" style="padding:6px 10px 12px;font-size:12px;">'
            + '<span class="dep-bp-note" role="status" aria-live="polite" style="color:#999;">'
            + $t('Type a value to preview matches…')
            + '</span>'
            + '</td>'
            + '</tr>'
        );
        $row.after($newRow);
        return $newRow.find('.dep-bp-note');
    }

    function abortInFlight($row) {
        var pending = $row.data('dep-bp-xhr');
        if (pending && typeof pending.abort === 'function') {
            pending.abort();
        }
        $row.removeData('dep-bp-xhr');
    }

    function requestPreview($row, marker) {
        var rule = readRow($row);
        var $note = ensureNoteRow($row);

        if (!rowHasAnyCriterion(rule)) {
            abortInFlight($row);
            $row.removeData('dep-bp-last-rule');
            $note.html('<span style="color:#999;">' + $t('Add at least one field to preview matches.') + '</span>');
            return;
        }

        // Skip identical re-fires (tab-through, repeated blur on unchanged field).
        // If the same signature is either already in flight or already rendered,
        // there's no new work to do — avoid hammering the DB on every blur.
        var signature = JSON.stringify(rule);
        if ($row.data('dep-bp-last-rule') === signature) {
            return;
        }

        // Cancel any still-pending request for this row so a slower older response
        // can't overwrite the count for the latest edit.
        abortInFlight($row);
        $row.data('dep-bp-last-rule', signature);

        $note.html('<span style="color:#777;">' + $t('Checking matches…') + '</span>');

        var xhr = $.ajax({
            url: marker.endpoint,
            method: 'POST',
            dataType: 'json',
            data: $.extend({}, rule, { form_key: marker.formKey })
        });

        $row.data('dep-bp-xhr', xhr);

        xhr.done(function (data) {
            // Discard if a newer request superseded this one mid-flight.
            if ($row.data('dep-bp-xhr') !== xhr) {
                return;
            }
            $row.removeData('dep-bp-xhr');
            if (!data || data.error) {
                renderError($note, data && data.error);
                return;
            }
            renderResult($note, data);
        }).fail(function (failed, textStatus) {
            if (textStatus === 'abort' || $row.data('dep-bp-xhr') !== xhr) {
                return;
            }
            $row.removeData('dep-bp-xhr');
            // Clear the cached signature so the admin can retry the same rule
            // by re-blurring; otherwise a transient failure would stay sticky.
            $row.removeData('dep-bp-last-rule');
            renderError($note, failed && failed.responseJSON && failed.responseJSON.error);
        });
    }

    function attach(marker) {
        var $table = $('#' + marker.elementId).closest('td').find('table.admin__control-table').first();
        if (!$table.length) {
            return;
        }

        // Text inputs: fire on blur only (avoid duplicate firing from change+blur).
        $table.on('blur', 'input.input-text', function (event) {
            var $row = $(event.target).closest('tr');
            if (!$row.length || $row.hasClass('dep-bp-note-row')) {
                return;
            }
            requestPreview($row, marker);
        });

        // Selects: fire on change.
        $table.on('change', 'select', function (event) {
            var $row = $(event.target).closest('tr');
            if (!$row.length || $row.hasClass('dep-bp-note-row')) {
                return;
            }
            requestPreview($row, marker);
        });

        // Magento's field-array delete button removes the row in its own click
        // handler, which may stop propagation. Register on the table's DOM node
        // in the *capture* phase so this runs before Magento's handler fires —
        // covering both mouse (click) and keyboard (Enter/Space → click)
        // activation paths.
        var tableEl = $table.get(0);
        var handleDeleteTrigger = function (event) {
            // Magento action buttons can wrap a label `<span>`, so the event target
            // may be the child — walk up to the `.action-delete` element first.
            var $trigger = $(event.target).closest('.action-delete');
            if (!$trigger.length) {
                return;
            }
            var $row = $trigger.closest('tr');
            if (!$row.length) {
                return;
            }
            var $note = getNoteRow($row);
            abortInFlight($row);
            if ($note) {
                $note.remove();
            }
        };
        tableEl.addEventListener('click', handleDeleteTrigger, true);
        tableEl.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            handleDeleteTrigger(event);
        }, true);
    }

    return function () {
        $('.dep-blocklist-preview').each(function () {
            var $marker = $(this);
            attach({
                elementId: $marker.data('blocklist-element-id'),
                endpoint: $marker.data('blocklist-endpoint'),
                formKey: $marker.data('blocklist-form-key')
            });
        });
    };
});
