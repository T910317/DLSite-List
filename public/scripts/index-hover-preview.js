(() => {
    if (window.indexHoverPreviewInitialized) {
        return;
    }

    window.indexHoverPreviewInitialized = true;

    const SHOW_DELAY_MS = 300;
    const CACHE_LIMIT = 50;
    const IMAGE_CHECK_LIMIT = 500;
    // One page per wheel notch; trackpads send many small deltas, so they are summed first.
    const WHEEL_STEP = 40;
    const WHEEL_COOLDOWN_MS = 150;
    const WHEEL_IDLE_MS = 250;
    const TRIGGER_SELECTOR = '[data-index-hover-preview]';
    const TOGGLE_SELECTOR = '[data-index-hover-preview-toggle]';

    // Responses are kept per rendered page only; any Livewire re-render clears them.
    const cache = new Map();
    // Images that failed once are skipped for the rest of the page lifetime.
    const failedImages = new Set();
    // One load check per image URL, shared by every preview that uses it.
    const imageChecks = new Map();

    let active = null;
    let showTimer = null;
    let token = 0;
    let wheelDelta = 0;
    let wheelLastAt = 0;
    let wheelLockedUntil = 0;

    const layer = () => document.getElementById('index-hover-preview');

    const label = (name) => layer()?.dataset[name] ?? '';

    const coverFor = (productId) => document.querySelector(
        `${TRIGGER_SELECTOR}[data-index-hover-preview="${CSS.escape(productId)}"]`,
    );

    const isInsideLayer = (node) => node instanceof Node && Boolean(layer()?.contains(node));

    const clearShowTimer = () => {
        window.clearTimeout(showTimer);
        showTimer = null;
    };

    const hide = () => {
        token++;
        clearShowTimer();

        const root = layer();

        if (root) {
            root.hidden = true;
            root.replaceChildren();
        }

        if (active) {
            active.cover?.removeAttribute('aria-describedby');
            active.toggle?.setAttribute('aria-expanded', 'false');
        }

        active = null;
        wheelDelta = 0;
    };

    const reset = () => {
        hide();
        cache.clear();
    };

    const fetchPreview = (cover, productId) => {
        if (cache.has(productId)) {
            return cache.get(productId);
        }

        const componentId = cover.closest('[wire\\:id]')?.getAttribute('wire:id');
        const component = componentId ? window.Livewire?.find(componentId) : null;

        if (!component) {
            return Promise.reject(new Error('Hover preview Livewire component was not found.'));
        }

        const request = Promise.resolve(component.hoverPreview(productId));

        if (cache.size >= CACHE_LIMIT) {
            cache.delete(cache.keys().next().value);
        }

        cache.set(productId, request);
        // A failed request is not cached, but it is also not retried until the pointer comes back.
        request.catch(() => cache.delete(productId));

        return request;
    };

    const element = (tag, className, text) => {
        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    };

    const iconButton = (className, icon, text) => {
        const button = element('button', className);
        button.type = 'button';
        // The wheel and arrow keys page through images; the buttons are for pointers and touch.
        button.tabIndex = -1;
        button.setAttribute('aria-label', text);
        button.title = text;

        const glyph = element('i', `fa-solid ${icon}`);
        glyph.setAttribute('aria-hidden', 'true');
        button.append(glyph);

        return button;
    };

    const openLayer = (panelChildren) => {
        const root = layer();

        if (!root) {
            return null;
        }

        const panel = element('div', 'index-hover-preview__panel');
        const close = iconButton('index-hover-preview__close', 'fa-xmark', label('labelClose'));
        close.setAttribute('data-index-hover-preview-close', '');

        panel.append(...panelChildren, close);
        root.replaceChildren(panel);
        root.hidden = false;

        return panel;
    };

    const renderStatus = (message) => {
        openLayer([element('p', 'index-hover-preview__status', message)]);
    };

    const appendSection = (parent, heading, content) => {
        const section = element('div', 'index-hover-preview__section');
        const value = element('dd');

        if (content instanceof Node) {
            value.append(content);
        } else if (Array.isArray(content)) {
            const list = element('ul', 'index-hover-preview__chips');

            content.forEach((item) => list.append(element('li', '', item)));
            value.append(list);
        } else {
            value.textContent = content;
        }

        section.append(element('dt', '', heading), value);
        parent.append(section);
    };

    // Up to two decimals, so screen readers hear a DLsite average of 4.53 as 4.53, not 4.5.
    const formatNumber = (number) => String(Math.round(number * 100) / 100);

    // Reusable star meter: `value` on a 0..`max` scale drawn as `count` stars, partial stars included,
    // e.g. an own score of 7 / 10 lights 3.5 stars and a DLsite average of 4.3 / 5 lights 4.3 stars.
    const renderStars = (value, max, count = 5) => {
        const ratio = max > 0 ? Math.min(Math.max(value / max, 0), 1) : 0;
        const stars = '★'.repeat(count);
        const meter = element('span', 'index-hover-preview__stars');
        meter.setAttribute('role', 'img');
        meter.setAttribute(
            'aria-label',
            label('labelRating').replace(':value', formatNumber(value)).replace(':max', formatNumber(max)),
        );

        const fill = element('span', 'index-hover-preview__stars-fill', stars);
        fill.style.width = `${ratio * 100}%`;

        meter.append(element('span', 'index-hover-preview__stars-track', stars), fill);

        return meter;
    };

    const renderScore = (score, scoreLabel) => {
        const wrapper = element('span', 'index-hover-preview__score');
        // The stars already announce "7 out of 10"; the visible number is for sighted readers only.
        const number = element('span', 'index-hover-preview__score-value', `${score} / 10`);
        number.setAttribute('aria-hidden', 'true');

        wrapper.append(renderStars(score, 10), number);

        if (scoreLabel) {
            wrapper.append(element('span', 'index-hover-preview__score-label', scoreLabel));
        }

        return wrapper;
    };

    const renderDlsiteRating = (rating, countLabel) => {
        const wrapper = element('span', 'index-hover-preview__score');
        // As with the own score, the stars announce "4.53 out of 5" and the visible number is hidden from screen readers.
        const number = element('span', 'index-hover-preview__score-value', rating.toFixed(2));
        number.setAttribute('aria-hidden', 'true');

        wrapper.append(renderStars(rating, 5), number);

        if (countLabel) {
            wrapper.append(element('span', 'index-hover-preview__score-label', countLabel));
        }

        return wrapper;
    };

    const renderPreview = (data) => {
        const stage = element('div', 'index-hover-preview__stage');
        stage.append(element('p', 'index-hover-preview__status', label('labelLoading')));

        const info = element('div', 'index-hover-preview__info');
        info.append(element('p', 'index-hover-preview__title', data.title));

        if (data.alternateTitle) {
            info.append(element('p', 'index-hover-preview__subtitle', data.alternateTitle));
        }

        const details = element('dl', 'index-hover-preview__details');

        appendSection(details, label('labelCode'), data.id);

        if (data.makers?.length) {
            appendSection(details, label('labelMakers'), data.makers.join(' / '));
        }

        if (data.formats?.length) {
            appendSection(details, label('labelFormats'), data.formats.join(', '));
        }

        if (Number.isFinite(data.score)) {
            appendSection(details, label('labelScore'), renderScore(data.score, data.scoreLabel));
        }

        // Only works with a saved DLsite average get this row.
        if (Number.isFinite(data.dlsiteRating)) {
            appendSection(
                details,
                label('labelDlsiteRating'),
                renderDlsiteRating(data.dlsiteRating, data.dlsiteRatingCountLabel),
            );
        }

        if (data.tags?.length) {
            appendSection(details, label('labelTags'), data.tags);
        }

        if (data.description) {
            appendSection(details, label('labelDescription'), data.description);
        }

        info.append(details);

        return openLayer([stage, info]) ? stage : null;
    };

    const showPage = (index) => {
        if (!active?.pages?.length || !active.stage) {
            return;
        }

        const pages = active.pages;
        const page = Math.max(0, Math.min(index, pages.length - 1));
        const stage = active.stage;

        active.page = page;
        stage.querySelector('.index-hover-preview__image').src = pages[page];
        stage.querySelector('.index-hover-preview__counter').textContent = `${page + 1} / ${pages.length}`;
        stage.querySelector('[data-index-hover-preview-previous]').disabled = page === 0;
        stage.querySelector('[data-index-hover-preview-next]').disabled = page === pages.length - 1;
    };

    const step = (direction) => {
        if (active?.pages?.length > 1) {
            showPage(active.page + direction);
        }
    };

    const renderPages = (stage, pages) => {
        if (pages.length === 0) {
            stage.replaceChildren(element('p', 'index-hover-preview__status', label('labelNoImage')));

            return;
        }

        const image = element('img', 'index-hover-preview__image');
        image.alt = '';

        const previous = iconButton('index-hover-preview__nav is-previous', 'fa-chevron-left', label('labelPrevious'));
        previous.setAttribute('data-index-hover-preview-previous', '');

        const next = iconButton('index-hover-preview__nav is-next', 'fa-chevron-right', label('labelNext'));
        next.setAttribute('data-index-hover-preview-next', '');

        const footer = element('div', 'index-hover-preview__footer');
        footer.append(element('span', 'index-hover-preview__counter'));

        if (pages.length > 1) {
            footer.append(element('span', 'index-hover-preview__hint', label('labelScroll')));
        } else {
            previous.hidden = true;
            next.hidden = true;
        }

        stage.replaceChildren(image, previous, next, footer);
        active.stage = stage;
        active.pages = pages;
        showPage(0);
    };

    const checkImage = (source) => {
        if (failedImages.has(source)) {
            return Promise.resolve(false);
        }

        if (!imageChecks.has(source)) {
            if (imageChecks.size >= IMAGE_CHECK_LIMIT) {
                imageChecks.delete(imageChecks.keys().next().value);
            }

            imageChecks.set(source, new Promise((resolve) => {
                const loader = new Image();

                loader.addEventListener('load', () => resolve(true), { once: true });
                loader.addEventListener('error', () => {
                    failedImages.add(source);
                    resolve(false);
                }, { once: true });

                loader.src = source;
            }));
        }

        return imageChecks.get(source);
    };

    // Only images that actually load become pages; broken ones are dropped up front.
    const loadableImages = async (images) => {
        const results = await Promise.all(images.map(checkImage));

        return images.filter((source, index) => results[index]);
    };

    const show = async (cover, toggle) => {
        const productId = (cover ?? toggle).dataset.indexHoverPreview ?? toggle?.dataset.indexHoverPreviewToggle;

        if (!productId || document.querySelector('dialog[open]')) {
            return;
        }

        hide();

        const showToken = token;
        active = { productId, cover, toggle, pages: [], page: 0, stage: null };
        cover?.setAttribute('aria-describedby', 'index-hover-preview');
        toggle?.setAttribute('aria-expanded', 'true');

        renderStatus(label('labelLoading'));

        let data;

        try {
            data = await fetchPreview(cover ?? toggle, productId);
        } catch {
            if (showToken === token) {
                renderStatus(label('labelError'));
            }

            return;
        }

        // Ignore late answers for a work the pointer has already left.
        if (showToken !== token || !data || data.id !== productId) {
            return;
        }

        const stage = renderPreview(data);

        if (!stage) {
            return;
        }

        const pages = await loadableImages(Array.isArray(data.images) ? data.images : []);

        if (showToken !== token) {
            return;
        }

        renderPages(stage, pages);
    };

    const scheduleShow = (cover) => {
        if (active?.cover === cover) {
            return;
        }

        hide();
        showTimer = window.setTimeout(() => show(cover, null), SHOW_DELAY_MS);
    };

    const pointIsOnCover = (event) => {
        const rect = active?.cover?.getBoundingClientRect();

        return Boolean(rect)
            && event.clientX >= rect.left && event.clientX <= rect.right
            && event.clientY >= rect.top && event.clientY <= rect.bottom;
    };

    document.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch' || !(event.target instanceof Element)) {
            return;
        }

        // The preview covers the page; moving off the panel onto the dimmed edge closes it,
        // except right where the cover sits underneath, so it does not flicker on open.
        if (active && event.target === layer()) {
            if (!pointIsOnCover(event)) {
                hide();
            }

            return;
        }

        const cover = event.target.closest(TRIGGER_SELECTOR);

        if (cover && !(event.relatedTarget instanceof Node && cover.contains(event.relatedTarget))) {
            scheduleShow(cover);
        }
    });

    document.addEventListener('pointerout', (event) => {
        if (event.pointerType === 'touch' || !(event.target instanceof Element)) {
            return;
        }

        const cover = event.target.closest(TRIGGER_SELECTOR);

        if (!cover || (event.relatedTarget instanceof Node && cover.contains(event.relatedTarget))) {
            return;
        }

        // The opened preview sits on top of the cover, so the pointer "leaving" into it keeps it open.
        if (isInsideLayer(event.relatedTarget)) {
            return;
        }

        if (active?.cover === cover || showTimer) {
            hide();
        }
    });

    document.addEventListener('focusin', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const cover = event.target.closest(TRIGGER_SELECTOR);

        if (cover && cover.matches(':focus-visible')) {
            scheduleShow(cover);
        }
    });

    document.addEventListener('focusout', (event) => {
        if (!(event.target instanceof Element) || !event.target.closest(TRIGGER_SELECTOR)) {
            return;
        }

        if (isInsideLayer(event.relatedTarget)) {
            return;
        }

        if (active?.cover || showTimer) {
            hide();
        }
    });

    // Clicking a cover before the preview opens keeps its normal job (Image Viewer or DLsite link).
    document.addEventListener('pointerdown', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (event.target.closest(TOGGLE_SELECTOR) || isInsideLayer(event.target)) {
            return;
        }

        if (active || showTimer) {
            hide();
        }
    }, true);

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (isInsideLayer(event.target)) {
            if (event.target === layer() || event.target.closest('[data-index-hover-preview-close]')) {
                const toggle = active?.toggle;

                hide();
                toggle?.focus();
            } else if (event.target.closest('[data-index-hover-preview-previous]')) {
                step(-1);
            } else if (event.target.closest('[data-index-hover-preview-next]')) {
                step(1);
            }

            return;
        }

        const toggle = event.target.closest(TOGGLE_SELECTOR);

        if (!toggle) {
            return;
        }

        event.preventDefault();

        if (active?.toggle === toggle) {
            hide();

            return;
        }

        show(coverFor(toggle.dataset.indexHoverPreviewToggle), toggle);
    });

    // The wheel turns pages instead of scrolling the Index underneath.
    document.addEventListener('wheel', (event) => {
        if (!active || !isInsideLayer(event.target)) {
            return;
        }

        event.preventDefault();

        const now = performance.now();

        if (now - wheelLastAt > WHEEL_IDLE_MS) {
            wheelDelta = 0;
        }

        wheelLastAt = now;

        if (now < wheelLockedUntil) {
            return;
        }

        wheelDelta += event.deltaMode === WheelEvent.DOM_DELTA_PIXEL ? event.deltaY : event.deltaY * 40;

        if (Math.abs(wheelDelta) >= WHEEL_STEP) {
            step(Math.sign(wheelDelta));
            wheelDelta = 0;
            wheelLockedUntil = now + WHEEL_COOLDOWN_MS;
        }
    }, { passive: false });

    const PAGE_KEYS = {
        ArrowDown: 1,
        ArrowRight: 1,
        PageDown: 1,
        ArrowUp: -1,
        ArrowLeft: -1,
        PageUp: -1,
    };

    document.addEventListener('keydown', (event) => {
        if (!active && !showTimer) {
            return;
        }

        if (event.key === 'Escape') {
            const toggle = active?.toggle;

            hide();
            toggle?.focus();

            return;
        }

        if (active && PAGE_KEYS[event.key] && !event.altKey && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
            step(PAGE_KEYS[event.key]);
        }
    });

    window.addEventListener('scroll', (event) => {
        // Scrolling the long details inside the preview is fine; scrolling the Index closes it.
        if ((active || showTimer) && !isInsideLayer(event.target)) {
            hide();
        }
    }, { capture: true, passive: true });

    const registerLivewireHooks = () => {
        // Search, sorting, filters and paging all re-render the table: drop the old preview and data.
        window.Livewire.hook('commit', ({ component, succeed }) => {
            if (component?.name && component.name !== 'product-index') {
                return;
            }

            succeed(({ effects }) => {
                if (effects?.html) {
                    reset();
                }
            });
        });
    };

    if (window.Livewire) {
        registerLivewireHooks();
    } else {
        document.addEventListener('livewire:init', registerLivewireHooks, { once: true });
    }

    document.addEventListener('livewire:navigated', reset);
})();
