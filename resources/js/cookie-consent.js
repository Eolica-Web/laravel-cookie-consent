/**
 * @typedef {{ name: string, type: 'cookie' | 'local_storage' | 'session_storage' }} DeclaredCookie
 * @typedef {{ key: string, necessary: boolean, cookies: DeclaredCookie[] }} Category
 * @typedef {{ policy: string, revision: string, lifetime: number, cookie: { name: string, domain: string | null }, endpoint: string, googleConsent: Record<string, string[]> | null, services: Record<string, string>, categories: Category[] }} Config
 * @typedef {{ id: string, policy: string, revision: string, categories: string[], at: number }} Consent
 */

const CONFIG = 'script[data-cookie-consent-config]';
const BLOCKED_SCRIPTS = 'script[type="text/plain"][data-cookie-consent]';
const SKIPPED_ATTRIBUTES = ['type', 'data-cookie-consent', 'data-src', 'data-type'];
const EXECUTABLE_TYPES = [
    '',
    'module',
    'application/ecmascript',
    'application/javascript',
    'application/x-ecmascript',
    'application/x-javascript',
    'text/ecmascript',
    'text/javascript',
    'text/javascript1.0',
    'text/javascript1.1',
    'text/javascript1.2',
    'text/javascript1.3',
    'text/javascript1.4',
    'text/javascript1.5',
    'text/jscript',
    'text/livescript',
    'text/x-ecmascript',
    'text/x-javascript',
];
const BANNERS = '[data-cookie-consent-banner]';
const PREFERENCES = '[data-cookie-consent-preferences]';
const CATEGORY_INPUTS = 'input[data-cookie-consent-category]';
const ACTIONS = '[data-cookie-consent-action]';

/**
 * @param {ParentNode} root
 * @param {string} selector
 * @returns {Element[]}
 */
function within(root, selector) {
    const elements = [...root.querySelectorAll(selector)];

    return root instanceof Element && root.matches(selector) ? [root, ...elements] : elements;
}

class CookieConsent {
    /** @type {Config} */
    #config;

    /** @type {Consent | null} */
    #consent;

    /** @type {WeakSet<Element>} */
    #activated = new WeakSet();

    /** @type {Map<string, Promise<void>>} */
    #sources = new Map();

    /** @type {Set<string>} */
    #once = new Set();

    /** @type {Promise<void>} */
    #queue = Promise.resolve();

    /** @type {number} */
    #waiting = 0;

    /** @type {{ category: string, callback: () => void, signal: AbortSignal | undefined }[]} */
    #pending = [];

    /** @type {Set<string>} */
    #ran = new Set();

    /**
     * @param {Config} config
     */
    constructor(config) {
        this.#config = config;
        this.#consent = this.#readConsent();

        this.#removeCookies(this.#deniedCategories());
        this.#pushGoogleConsent('default', (category) => this.isNecessary(category));

        if (this.#consent !== null) {
            this.#updateGoogleConsent();
        }
    }

    /**
     * @returns {Consent | null}
     */
    get consent() {
        return this.#consent;
    }

    /**
     * @returns {boolean}
     */
    get needsConsent() {
        return this.#consent === null;
    }

    /**
     * @returns {string[]}
     */
    get categories() {
        return this.#config.categories.map((category) => category.key);
    }

    /**
     * @param {string} category
     * @returns {boolean}
     */
    allows(category) {
        if (this.isNecessary(category)) {
            return true;
        }

        return this.#consent?.categories.includes(category) ?? false;
    }

    /**
     * @param {string} category
     * @returns {boolean}
     */
    isNecessary(category) {
        return this.#config.categories.some((candidate) => candidate.key === category && candidate.necessary);
    }

    /**
     * @returns {Promise<void>}
     */
    acceptAll() {
        return this.accept(this.categories);
    }

    /**
     * @returns {Promise<void>}
     */
    rejectAll() {
        return this.accept([]);
    }

    /**
     * @param {string[]} categories
     * @returns {Promise<void>}
     */
    async accept(categories) {
        if (!Array.isArray(categories)) {
            throw new TypeError('The categories to accept must be an array.');
        }

        this.#consent = this.#freeze({
            id: '',
            policy: this.#config.policy,
            revision: this.#config.revision,
            categories: this.categories.filter((key) => this.isNecessary(key) || categories.includes(key)),
            at: Math.floor(Date.now() / 1000),
        });

        this.#writeCookie(this.#consent);
        this.#removeCookies(this.#deniedCategories());
        this.#updateGoogleConsent();

        const stored = this.#store(this.#consent);

        if ([...this.#ran].some((category) => !this.allows(category))) {
            this.#dispatchUpdated();

            await stored;

            window.location.reload();

            return;
        }

        this.scan(document);
        this.#dispatchUpdated();
        this.#runPending();

        await stored;
    }

    showPreferences() {
        document.dispatchEvent(new CustomEvent('cookie-consent:show-preferences'));
    }

    /**
     * @param {string} category
     * @param {() => void} callback
     * @param {{ signal?: AbortSignal }} [options]
     */
    whenAllowed(category, callback, { signal } = {}) {
        if (signal?.aborted) {
            return;
        }

        if (this.allows(category)) {
            this.#ran.add(category);
            this.#enqueue(() => {
                if (!signal?.aborted) {
                    callback();
                }
            });

            return;
        }

        this.#pending.push({ category, callback, signal });
    }

    /**
     * @param {ParentNode} root
     * @returns {Promise<void>}
     */
    scan(root) {
        for (const element of within(root, BLOCKED_SCRIPTS)) {
            this.#enqueue(() => this.#activate(element));
        }

        return this.#queue;
    }

    observe() {
        new MutationObserver((mutations) => {
            const elements = mutations
                .flatMap((mutation) => [...mutation.addedNodes])
                .filter((node) => node instanceof Element);

            if (elements.some((element) => this.#isOtherPolicy(element))) {
                window.location.reload();

                return;
            }

            elements.forEach((element) => this.scan(element));
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    /**
     * @param {Element} root
     * @returns {boolean}
     */
    #isOtherPolicy(root) {
        return within(root, CONFIG).some((element) => {
            try {
                const config = JSON.parse(element.textContent ?? '');

                return config.policy !== this.#config.policy || config.revision !== this.#config.revision;
            } catch {
                return false;
            }
        });
    }

    /**
     * @param {() => Promise<void> | void} task
     */
    #enqueue(task) {
        if (this.#waiting > 0) {
            this.#chain(this.#queue.then(task));

            return;
        }

        try {
            const loading = task();

            if (loading !== undefined) {
                this.#chain(loading);
            }
        } catch (error) {
            reportError(error);
        }
    }

    /**
     * @param {Promise<void>} promise
     */
    #chain(promise) {
        this.#waiting++;
        this.#queue = promise
            .catch((error) => reportError(error))
            .finally(() => {
                this.#waiting--;
            });
    }

    /**
     * @param {Element} element
     * @returns {Promise<void> | undefined}
     */
    #activate(element) {
        if (this.#activated.has(element) || !(element instanceof HTMLScriptElement) || !element.isConnected) {
            return;
        }

        const service = element.dataset.cookieConsent ?? '';

        if (!Object.hasOwn(this.#config.services, service) || !this.allows(this.#config.services[service])) {
            return;
        }

        this.#activated.add(element);
        this.#ran.add(this.#config.services[service]);

        const source = element.dataset.src;
        const runs = !element.hasAttribute('nomodule') && EXECUTABLE_TYPES.includes((element.dataset.type ?? '').trim().toLowerCase());
        const waits = source !== undefined && !element.hasAttribute('async') && runs;
        const loading = source === undefined ? undefined : this.#sources.get(source);

        if (loading !== undefined) {
            return waits ? loading : undefined;
        }

        if (element.hasAttribute('data-navigate-once')) {
            const key = this.#onceKey(element);

            if (this.#once.has(key)) {
                return;
            }

            this.#once.add(key);
        }

        const script = document.createElement('script');

        for (const attribute of element.attributes) {
            if (!SKIPPED_ATTRIBUTES.includes(attribute.name)) {
                script.setAttribute(attribute.name, attribute.value);
            }
        }

        script.nonce = element.nonce;
        script.setAttribute('data-navigate-once', '');
        this.#activated.add(script);

        if (element.dataset.type !== undefined) {
            script.type = element.dataset.type;
        }

        if (source === undefined) {
            script.textContent = element.textContent;
            element.before(script);

            return;
        }

        script.async = !waits;
        script.src = source;

        if (!runs) {
            element.before(script);

            return;
        }

        const loaded = new Promise((resolve) => {
            script.addEventListener('load', () => resolve());
            script.addEventListener('error', () => resolve());
        });

        this.#sources.set(source, loaded);
        element.before(script);

        return waits ? loaded : undefined;
    }

    /**
     * @returns {string[]}
     */
    #deniedCategories() {
        return this.categories.filter((category) => !this.allows(category));
    }

    #runPending() {
        const pending = this.#pending;

        this.#pending = [];

        for (const { category, callback, signal } of pending) {
            this.whenAllowed(category, callback, { signal });
        }
    }

    #updateGoogleConsent() {
        this.#pushGoogleConsent('update', (category) => this.allows(category));
    }

    /**
     * @param {'default' | 'update'} command
     * @param {(category: string) => boolean} allows
     */
    #pushGoogleConsent(command, allows) {
        if (this.#config.googleConsent === null) {
            return;
        }

        const signals = Object.fromEntries(
            Object.entries(this.#config.googleConsent)
                .map(([signal, categories]) => [signal, categories.some((category) => allows(category)) ? 'granted' : 'denied']),
        );

        window.dataLayer = window.dataLayer || [];

        (function () {
            window.dataLayer.push(arguments);
        })('consent', command, signals);
    }

    #dispatchUpdated() {
        document.dispatchEvent(new CustomEvent('cookie-consent:updated', {
            detail: { consent: this.#consent, categories: this.categories.filter((key) => this.allows(key)) },
        }));
    }

    /**
     * @returns {Consent | null}
     */
    #readConsent() {
        const value = this.#cookie(this.#config.cookie.name);

        if (value === undefined) {
            return null;
        }

        try {
            const consent = JSON.parse(decodeURIComponent(value));

            return this.#isValid(consent) ? this.#freeze(consent) : null;
        } catch {
            return null;
        }
    }

    /**
     * @param {HTMLScriptElement} element
     * @returns {string}
     */
    #onceKey(element) {
        const clone = /** @type {HTMLScriptElement} */ (element.cloneNode(true));

        clone.removeAttribute('nonce');

        return clone.outerHTML;
    }

    /**
     * @param {Consent} consent
     * @returns {Consent}
     */
    #freeze(consent) {
        return Object.freeze({ ...consent, categories: Object.freeze([...consent.categories]) });
    }

    /**
     * @param {string} name
     * @returns {string | undefined}
     */
    #cookie(name) {
        const prefix = `${name}=`;

        return document.cookie.split('; ').find((cookie) => cookie.startsWith(prefix))?.slice(prefix.length);
    }

    /**
     * @param {unknown} consent
     * @returns {consent is Consent}
     */
    #isValid(consent) {
        return typeof consent === 'object'
            && consent !== null
            && 'policy' in consent && consent.policy === this.#config.policy
            && 'revision' in consent && consent.revision === this.#config.revision
            && 'categories' in consent && Array.isArray(consent.categories)
            && consent.categories.every((category) => typeof category === 'string')
            && 'at' in consent && Number.isInteger(consent.at)
            && /** @type {number} */ (consent.at) + this.#config.lifetime > Date.now() / 1000;
    }

    /**
     * @param {Consent} consent
     */
    #writeCookie(consent) {
        const expires = new Date((consent.at + this.#config.lifetime) * 1000);

        this.#deleteCookie(this.#config.cookie.name);
        this.#setCookie(this.#config.cookie.name, encodeURIComponent(JSON.stringify(consent)), expires, this.#config.cookie.domain);
    }

    /**
     * @param {string[]} categories
     */
    #removeCookies(categories) {
        const declared = (removed) => this.#config.categories
            .filter((category) => categories.includes(category.key) === removed)
            .flatMap((category) => category.cookies)
            .map(({ name, type }) => ({ type, pattern: this.#pattern(name) }));
        const kept = declared(false);

        for (const { type, pattern } of declared(true)) {
            const removes = (name) => pattern.test(name) && !kept.some((cookie) => cookie.type === type && cookie.pattern.test(name));

            if (type === 'cookie') {
                document.cookie.split('; ')
                    .map((cookie) => cookie.split('=')[0])
                    .filter(removes)
                    .forEach((cookie) => this.#deleteCookie(cookie));
            } else {
                const storage = this.#storage(type);

                if (storage !== null) {
                    Object.keys(storage).filter(removes).forEach((key) => storage.removeItem(key));
                }
            }
        }
    }

    /**
     * @param {string} name
     * @returns {RegExp}
     */
    #pattern(name) {
        return new RegExp(`^${name.split('*').map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('.*')}$`);
    }

    /**
     * @param {'local_storage' | 'session_storage'} type
     * @returns {Storage | null}
     */
    #storage(type) {
        try {
            return type === 'local_storage' ? window.localStorage : window.sessionStorage;
        } catch {
            return null;
        }
    }

    /**
     * @param {string} name
     */
    #deleteCookie(name) {
        const hosts = window.location.hostname.split('.');
        const segments = window.location.pathname.split('/').filter((segment) => segment !== '');
        const domains = [null, ...hosts.slice(0, -1).map((_, index) => `.${hosts.slice(index).join('.')}`)];
        const paths = ['/', ...segments.flatMap((_, index) => {
            const path = `/${segments.slice(0, index + 1).join('/')}`;

            return [path, `${path}/`];
        })];

        for (const domain of domains) {
            for (const path of paths) {
                this.#setCookie(name, '', new Date(0), domain, path);
            }
        }
    }

    /**
     * @param {string} name
     * @param {string} value
     * @param {Date} expires
     * @param {string | null} domain
     * @param {string} [path]
     */
    #setCookie(name, value, expires, domain, path = '/') {
        const attributes = [`${name}=${value}`, `expires=${expires.toUTCString()}`, `path=${path}`, 'SameSite=Lax'];

        if (domain !== null) {
            attributes.push(`domain=${domain}`);
        }

        if (window.location.protocol === 'https:') {
            attributes.push('Secure');
        }

        document.cookie = attributes.join('; ');
    }

    /**
     * @returns {string | undefined}
     */
    #csrfToken() {
        const token = this.#cookie('XSRF-TOKEN');

        try {
            return token === undefined ? undefined : decodeURIComponent(token);
        } catch {
            return undefined;
        }
    }

    /**
     * @param {Consent} consent
     * @returns {Promise<void>}
     */
    async #store(consent) {
        /** @type {Record<string, string>} */
        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Cookie-Consent': '1' };
        const token = this.#csrfToken();

        if (token !== undefined) {
            headers['X-XSRF-TOKEN'] = token;
        }

        try {
            const response = await fetch(this.#config.endpoint, {
                method: 'POST',
                headers,
                body: JSON.stringify({ revision: consent.revision, categories: consent.categories }),
                credentials: 'same-origin',
                signal: AbortSignal.timeout(5000),
            });

            if (response.ok) {
                const stored = await response.json();

                if (this.#isValid(stored)) {
                    this.#consent = this.#freeze(stored);
                }
            } else if (response.status === 409) {
                this.#deleteCookie(this.#config.cookie.name);

                window.location.reload();
            }
        } catch {
        }
    }
}

class CookieConsentBindings {
    /** @type {CookieConsent} */
    #consent;

    /**
     * @param {CookieConsent} consent
     */
    constructor(consent) {
        this.#consent = consent;
    }

    observe() {
        document.addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest(ACTIONS) : null;

            if (button instanceof HTMLElement) {
                event.preventDefault();
                this.#handle(button.dataset.cookieConsentAction ?? '', button);
            }
        });

        document.addEventListener('cookie-consent:updated', () => {
            this.#render(document);
            this.#closePreferences();
        });

        document.addEventListener('cookie-consent:show-preferences', () => this.#openPreferences());

        new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach((node) => {
                    if (node instanceof Element) {
                        this.#render(node);
                    }
                });
            }
        }).observe(document.documentElement, { childList: true, subtree: true });

        this.#render(document);
    }

    /**
     * @param {string} action
     * @param {HTMLElement} button
     */
    #handle(action, button) {
        if (action === 'accept-all') {
            this.#consent.acceptAll();
        } else if (action === 'reject-all') {
            this.#consent.rejectAll();
        } else if (action === 'save') {
            this.#consent.accept(this.#selected(button));
        } else if (action === 'show-preferences') {
            this.#consent.showPreferences();
        } else if (action === 'close-preferences') {
            this.#closePreferences();
        }
    }

    /**
     * @param {HTMLElement} button
     * @returns {string[]}
     */
    #selected(button) {
        const root = button.closest(PREFERENCES) ?? button.closest(BANNERS) ?? document;

        return within(root, CATEGORY_INPUTS)
            .filter((input) => input instanceof HTMLInputElement && input.checked)
            .map((input) => /** @type {HTMLInputElement} */ (input).dataset.cookieConsentCategory ?? '');
    }

    /**
     * @param {ParentNode} root
     */
    #render(root) {
        within(root, BANNERS).forEach((banner) => {
            if (banner instanceof HTMLElement) {
                banner.hidden = !this.#consent.needsConsent;
            }
        });

        within(root, CATEGORY_INPUTS).forEach((input) => {
            if (input instanceof HTMLInputElement) {
                const category = input.dataset.cookieConsentCategory ?? '';

                input.checked = this.#consent.allows(category);
                input.disabled = this.#consent.isNecessary(category);
            }
        });
    }

    #openPreferences() {
        this.#render(document);

        document.querySelectorAll(PREFERENCES).forEach((element) => {
            if (element instanceof HTMLDialogElement) {
                element.showModal();
            } else if (element instanceof HTMLElement) {
                element.hidden = false;
            }
        });
    }

    #closePreferences() {
        document.querySelectorAll(PREFERENCES).forEach((element) => {
            if (element instanceof HTMLDialogElement) {
                element.close();
            } else if (element instanceof HTMLElement) {
                element.hidden = true;
            }
        });
    }
}

const element = document.querySelector(CONFIG);

if (element === null) {
    console.warn('The cookie consent configuration is missing, render the <x-cookie-consent::scripts /> component.');
} else if (!Object.hasOwn(window, 'CookieConsent')) {
    const cookieConsent = new CookieConsent(JSON.parse(element.textContent ?? '{}'));

    window.CookieConsent = cookieConsent;

    cookieConsent.scan(document);
    cookieConsent.observe();

    new CookieConsentBindings(cookieConsent).observe();

    document.dispatchEvent(new CustomEvent('cookie-consent:ready', { detail: { consent: cookieConsent.consent } }));
}
