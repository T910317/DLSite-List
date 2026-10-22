(() => {
    if (window.indexHoverPreviewInitialized) {
        return;
    }

    window.indexHoverPreviewInitialized = true;

    const SHOW_DELAY_MS = 300;
    const SLIDE_INTERVAL_MS = 1500;
    const CACHE_LIMIT = 50;
    const IMAGE_CHECK_LIMIT = 500;
    const TRIGGER_SELECTOR = '[data-index-hover-preview]';
    const TOGGLE_SELECTOR = '[data-index-hover-preview-toggle]';

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    // Responses are kept per rendered page only; any Livewire re-render clears them.
    const cache = new Map();
    // Sample images that failed once are skipped for the rest of the page lifetime.
    const failedImages = new Set();
    // One load check per sample image URL, shared by the image count and the slideshow.
    const imageChecks = new Map();

    let active = null;
    let showTimer = null;
    let slideTimer = null;
    let token = 0;

    const card = () => document.getElementById('index-hover-preview');

    const label = (name) => card()?.dataset[name] ?? '';

    const coverFor = (productId) => document.querySelector(
        `${TRIGGER_SELECTOR}[data-index-hover-preview="${CSS.escape(productId)}"]`,
    );

    const clearShowTimer = () => {
        window.clearTimeout(showTimer);
        showTimer = null;
    };

    const stopSlides = () => {
        window.clearTimeout(slideTimer);
        slideTimer = null;

        document.querySelectorAll('[data-index-hover-preview-slide]').forEach((slide) => slide.remove());
    };

    const hide = () => {
        token++;
        clearShowTimer();
        stopSlides();

        const element = card();

        if (element) {
            element.hidden = true;
            element.replaceChildren();
        }

        if (active) {
            active.cover?.removeAttribute('aria-describedby');
            active.toggle?.setAttribute('aria-expanded', 'false');
        }

        active = null;
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

    const position = () => {
        const element = card();

        if (!element || !active || element.hidden) {
            return;
        }

        const trigger = active.cover ?? active.toggle;
        const anchor = trigger.getBoundingClientRect();
        const row = trigger.closest('tr')?.getBoundingClientRect() ?? anchor;
        const gap = 8;
        const margin = 16;
        const width = element.offsetWidth;
        const height = element.offsetHeight;
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = window.innerHeight;

        const left = Math.max(margin, Math.min(anchor.left, viewportWidth - margin - width));
        let top;

        // Keep the hovered row readable: open below it, or above it when there is no room below.
        if (row.bottom + gap + height <= viewportHeight - margin) {
            top = row.bottom + gap;
        } else if (row.top - gap - height >= margin) {
            top = row.top - gap - height;
        } else {
            // Tall card-style rows on narrow screens: stay right under the cover.
            top = anchor.bottom + gap;
        }

        top = Math.max(margin, Math.min(top, viewportHeight - margin - height));

        element.style.left = `${Math.round(left)}px`;
        element.style.top = `${Math.round(top)}px`;
    };

    const appendSection = (parent, heading, content) => {
        const section = document.createElement('div');
        section.className = 'index-hover-preview__section';

        const title = document.createElement('dt');
        title.textContent = heading;

        const value = document.createElement('dd');

        if (Array.isArray(content)) {
            const list = document.createElement('ul');
            list.className = 'index-hover-preview__chips';

            content.forEach((item) => {
                const chip = document.createElement('li');
                chip.textContent = item;
                list.append(chip);
            });

            value.append(list);
        } else {
            value.textContent = content;
        }

        section.append(title, value);
        parent.append(section);
    };

    const renderStatus = (message) => {
        const element = card();

        if (!element) {
            return;
        }

        const status = document.createElement('p');
        status.className = 'index-hover-preview__status';
        status.textContent = message;

        element.replaceChildren(status);
        element.hidden = false;
        position();
    };

    const renderPreview = (data) => {
        const element = card();

        if (!element) {
            return;
        }

        const title = document.createElement('p');
        title.className = 'index-hover-preview__title';
        title.textContent = data.title;

        const children = [title];

        if (data.alternateTitle) {
            const alternate = document.createElement('p');
            alternate.className = 'index-hover-preview__subtitle';
            alternate.textContent = data.alternateTitle;
            children.push(alternate);
        }

        const details = document.createElement('dl');
        details.className = 'index-hover-preview__details';

        appendSection(details, label('labelCode'), data.id);

        if (data.makers?.length) {
            appendSection(details, label('labelMakers'), data.makers.join(' / '));
        }

        if (data.formats?.length) {
            appendSection(details, label('labelFormats'), data.formats.join(', '));
        }

        if (data.tags?.length) {
            appendSection(details, label('labelTags'), data.tags);
        }

        if (data.description) {
            appendSection(details, label('labelDescription'), data.description);
        }

        children.push(details);

        element.replaceChildren(...children);
        element.hidden = false;
        position();
    };

    const renderImageCount = (count) => {
        const element = card();

        if (!element || count === 0) {
            return;
        }

        const images = document.createElement('p');
        images.className = 'index-hover-preview__meta';
        images.textContent = label('labelImages').replace(':count', count);

        element.append(images);
        position();
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

    // Only images that actually load are counted and cycled; broken ones are dropped up front.
    const loadableImages = async (images) => {
        const results = await Promise.all(images.map(checkImage));

        return images.filter((source, index) => results[index]);
    };

    const playSlides = (cover, images, current) => {
        if (reducedMotion.matches || !cover.isConnected) {
            return;
        }

        const candidates = images.filter((source) => !failedImages.has(source));

        if (candidates.length === 0) {
            stopSlides();

            return;
        }

        const index = candidates.length === 1 ? 0 : (candidates.indexOf(current) + 1) % candidates.length;
        const source = candidates[index];
        const slideToken = token;
        const loader = new Image();

        loader.addEventListener('load', () => {
            if (slideToken !== token || !cover.isConnected) {
                return;
            }

            let slide = cover.querySelector('[data-index-hover-preview-slide]');

            if (!slide) {
                slide = document.createElement('img');
                slide.className = 'index-hover-preview-slide';
                slide.alt = '';
                slide.setAttribute('data-index-hover-preview-slide', '');
                cover.append(slide);
            }

            slide.src = source;

            if (candidates.length > 1) {
                slideTimer = window.setTimeout(() => playSlides(cover, images, source), SLIDE_INTERVAL_MS);
            }
        });

        loader.addEventListener('error', () => {
            if (slideToken !== token) {
                return;
            }

            failedImages.add(source);
            // Move on at the normal pace; a broken image never gets requested again.
            slideTimer = window.setTimeout(() => playSlides(cover, images, current), SLIDE_INTERVAL_MS);
        });

        loader.src = source;
    };

    const show = async (cover, toggle) => {
        const productId = (cover ?? toggle).dataset.indexHoverPreview ?? toggle?.dataset.indexHoverPreviewToggle;

        if (!productId || document.querySelector('dialog[open]')) {
            return;
        }

        hide();

        const showToken = token;
        active = { productId, cover, toggle };
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

        renderPreview(data);

        if (!Array.isArray(data.images) || data.images.length === 0) {
            return;
        }

        const images = await loadableImages(data.images);

        if (showToken !== token) {
            return;
        }

        renderImageCount(images.length);

        if (cover && images.length > 0) {
            playSlides(cover, images, null);
        }
    };

    const scheduleShow = (cover) => {
        if (active?.cover === cover) {
            return;
        }

        hide();
        showTimer = window.setTimeout(() => show(cover, null), SHOW_DELAY_MS);
    };

    document.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch' || !(event.target instanceof Element)) {
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

        if (active?.cover || showTimer) {
            hide();
        }
    });

    // Clicking a cover keeps its normal job (Image Viewer or DLsite link); the preview just gets out of the way.
    document.addEventListener('pointerdown', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (event.target.closest(TOGGLE_SELECTOR) || event.target.closest('#index-hover-preview')) {
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

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && (active || showTimer)) {
            const toggle = active?.toggle;

            hide();
            toggle?.focus();
        }
    });

    window.addEventListener('scroll', () => {
        if (active || showTimer) {
            hide();
        }
    }, { capture: true, passive: true });

    window.addEventListener('resize', position, { passive: true });

    reducedMotion.addEventListener?.('change', () => {
        if (reducedMotion.matches) {
            stopSlides();
        }
    });

    const registerLivewireHooks = () => {
        // Search, sorting, filters and paging all re-render the table: drop the old card, slides and data.
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
