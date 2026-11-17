(function () {
    'use strict';

    var nodes = document.querySelectorAll('[data-countdown]');

    function tick() {
        var now = Date.now();

        nodes.forEach(function (node) {
            var raw = node.getAttribute('data-countdown') || '';
            var target = Date.parse(raw.replace(' ', 'T') + 'Z');

            if (Number.isNaN(target)) {
                return;
            }

            var delta = target - now;

            if (delta <= 0) {
                node.textContent = 'Started';
                return;
            }

            var days = Math.floor(delta / 86400000);
            var hours = Math.floor((delta % 86400000) / 3600000);
            var minutes = Math.floor((delta % 3600000) / 60000);
            var parts = [];

            if (days > 0) parts.push(days + 'd');
            if (hours > 0) parts.push(hours + 'h');
            parts.push(minutes + 'm');

            node.textContent = 'Starts in ' + parts.join(' ');
        });
    }

    if (nodes.length > 0) {
        tick();
        setInterval(tick, 30000);
    }
})();
