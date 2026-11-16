/* Toggle only the relevant editor. Hidden controls keep their values when changing mode. */
(function () {
    'use strict';
    function applyMode(container) {
        var select = container.querySelector('.mcar-send-mode');
        if (!select) return;
        var isPattern = select.value === 'pattern';
        var pattern = container.querySelector('.mcar-pattern-panel');
        var plain = container.querySelector('.mcar-plain-panel');
        if (pattern) pattern.hidden = !isPattern;
        if (plain) plain.hidden = isPattern;
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.mcar-stage-config').forEach(function (container) {
            var select = container.querySelector('.mcar-send-mode');
            if (select) select.addEventListener('change', function () { applyMode(container); });
            applyMode(container);
        });
    });
}());
