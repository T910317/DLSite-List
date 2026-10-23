(function () {
    'use strict';

    // Shows library works that are another edition of the typed code. It never blocks saving.
    const container = document.querySelector('[data-related-editions-check]');
    const input = document.getElementById('id');

    if (!container || !input || !window.fetch) {
        return;
    }

    const codePattern = /(?:RJ|BJ|VJ)\d+/i;
    let timer = null;
    let lastCode = null;
    let requestId = 0;

    function check() {
        const match = input.value.match(codePattern);
        const code = match ? match[0].toUpperCase() : null;

        if (code === lastCode) {
            return;
        }

        lastCode = code;
        const currentRequest = ++requestId;

        if (code === null) {
            container.innerHTML = '';
            return;
        }

        const url = new URL(container.dataset.url, window.location.href);
        url.searchParams.set('id', code);

        fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
            .then(function (response) {
                return response.ok ? response.text() : '';
            })
            .then(function (html) {
                // Ignore answers to codes that were replaced while the request ran.
                if (currentRequest === requestId) {
                    container.innerHTML = html.trim();
                }
            })
            .catch(function () {
                if (currentRequest === requestId) {
                    container.innerHTML = '';
                }
            });
    }

    input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(check, 400);
    });

    check();
})();
