/**
 * @typedef {{ name: string, type: 'cookie' | 'local_storage' | 'session_storage' }} DeclaredCookie
 * @typedef {{ key: string, necessary: boolean, cookies: DeclaredCookie[] }} Category
 * @typedef {{ policy: string, revision: string, lifetime: number, cookie: { name: string, domain: string | null }, endpoint: string, googleConsent: Record<string, string[]> | null, services: Record<string, string>, categories: Category[] }} Config
 * @typedef {{ id: string, policy: string, revision: string, categories: string[], at: number }} Consent
 */

const BLOCKED_SCRIPTS = 'script[type="text/plain"][data-cookie-consent]';
const SKIPPED_ATTRIBUTES = ['type', 'data-cookie-consent', 'data-src', 'data-type'];
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

    /** @type {Set<string>} */
    #sources = new Set();

    /** @type {{ category: string, callback: () => void }[]} */
    #pending = [];

    /**
     * @param {Config} config
     */
    constructor(config) {
        this.#config = config;
        this.#consent = this.#readConsent();

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
        const revoked = this.categories.filter((key) => this.allows(key) && !this.isNecessary(key) && !categories.includes(key));

        this.#consent = {
            id: '',
            policy: this.#config.policy,
            revision: this.#config.revision,
            categories: this.categories.filter((key) => this.isNecessary(key) || categories.includes(key)),
            at: Math.floor(Date.now() / 1000),
        };

        this.#writeCookie(this.#consent);
        this.#removeCookies(revoked);
        this.#updateGoogleConsent();

        const stored = this.#store(this.#consent);

        if (revoked.length > 0) {
            await stored;

            window.location.reload();

            return;
        }

        this.#dispatchUpdated();
        this.scan(document);
        this.#runPending();

        await stored;
    }

    showPreferences() {
        document.dispatchEvent(new CustomEvent('cookie-consent:show-preferences'));
    }

    /**
     * @param {string} category
     * @param {() => void} callback
     */
    whenAllowed(category, callback) {
        if (this.allows(category)) {
            callback();

            return;
        }

        this.#pending.push({ category, callback });
    }

    /**
     * @param {ParentNode} root
     */
    scan(root) {
        within(root, BLOCKED_SCRIPTS).forEach((element) => this.#activate(element));
    }

    observe() {
        new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach((node) => {
                    if (node instanceof Element) {
                        this.scan(node);
                    }
                });
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    /**
     * @param {Element} element
     */
    #activate(element) {
        if (this.#activated.has(element) || !(element instanceof HTMLScriptElement)) {
            return;
        }

        const service = element.dataset.cookieConsent ?? '';

        if (!Object.hasOwn(this.#config.services, service) || !this.allows(this.#config.services[service])) {
            return;
        }

        this.#activated.add(element);

        const source = element.dataset.src;

        if (source !== undefined && this.#sources.has(source)) {
            return;
        }

        const script = document.createElement('script');

        for (const attribute of element.attributes) {
            if (!SKIPPED_ATTRIBUTES.includes(attribute.name)) {
                script.setAttribute(attribute.name, attribute.value);
            }
        }

        script.nonce = element.nonce;

        if (element.dataset.type !== undefined) {
            script.type = element.dataset.type;
        }

        if (source !== undefined) {
            this.#sources.add(source);
            script.async = element.hasAttribute('async');
            script.src = source;
        } else {
            script.textContent = element.textContent;
        }

        element.after(script);
    }

    #runPending() {
        const pending = this.#pending;

        this.#pending = [];

        for (const { category, callback } of pending) {
            this.whenAllowed(category, callback);
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
        const prefix = `${this.#config.cookie.name}=`;
        const value = document.cookie.split('; ').find((cookie) => cookie.startsWith(prefix))?.slice(prefix.length);

        if (value === undefined) {
            return null;
        }

        try {
            const consent = JSON.parse(decodeURIComponent(value));

            return this.#isValid(consent) ? consent : null;
        } catch {
            return null;
        }
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

        this.#setCookie(this.#config.cookie.name, encodeURIComponent(JSON.stringify(consent)), expires, this.#config.cookie.domain);
    }

    /**
     * @param {string[]} categories
     */
    #removeCookies(categories) {
        const declared = this.#config.categories
            .filter((category) => categories.includes(category.key))
            .flatMap((category) => category.cookies);

        for (const { name, type } of declared) {
            const pattern = new RegExp(`^${name.split('*').map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('.*')}$`);

            if (type === 'cookie') {
                document.cookie.split('; ')
                    .map((cookie) => cookie.split('=')[0])
                    .filter((cookie) => pattern.test(cookie))
                    .forEach((cookie) => this.#deleteCookie(cookie));
            } else {
                const storage = type === 'local_storage' ? window.localStorage : window.sessionStorage;

                Object.keys(storage).filter((key) => pattern.test(key)).forEach((key) => storage.removeItem(key));
            }
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
     * @param {Consent} consent
     * @returns {Promise<void>}
     */
    async #store(consent) {
        try {
            const response = await fetch(this.#config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Cookie-Consent': '1' },
                body: JSON.stringify({ policy: consent.policy, revision: consent.revision, categories: consent.categories }),
                credentials: 'same-origin',
                signal: AbortSignal.timeout(5000),
            });

            if (response.ok) {
                this.#consent = await response.json();
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

const element = document.querySelector('script[data-cookie-consent-config]');

if (element === null) {
    console.warn('The cookie consent configuration is missing, render the <x-cookie-consent::scripts /> component.');
} else if (window.CookieConsent === undefined) {
    const cookieConsent = new CookieConsent(JSON.parse(element.textContent ?? '{}'));

    window.CookieConsent = cookieConsent;

    cookieConsent.scan(document);
    cookieConsent.observe();

    new CookieConsentBindings(cookieConsent).observe();

    document.dispatchEvent(new CustomEvent('cookie-consent:ready', { detail: { consent: cookieConsent.consent } }));
}
