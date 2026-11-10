# Laravel Cookie Consent

Cookie consent management for Laravel. Declare the cookies of your website in a typed cookie policy, and the package will:

- Render an accessible consent banner and preferences dialog, or let you build your own with any markup.
- Block third-party scripts until their category is allowed, and remove their cookies when they are not allowed.
- Render a cookie declaration table for your cookie policy page.
- Let you check the consent on the server, with a Blade directive and an injectable service.
- Send the consent to Google Consent Mode v2.
- Support several websites with different policies on the same application.

## Requirements

- PHP 8.5
- Laravel 13

## Installation

```bash
composer require eolica/laravel-cookie-consent
```

Create your cookie policy provider:

```bash
php artisan make:cookie-policy CookiePolicyProvider
```

This creates `app/Providers/CookiePolicyProvider.php` and registers it in `bootstrap/providers.php`.

Then render the scripts in the `<head>` of your layout and the banner before the closing `</body>` tag:

```blade
<head>
    {{-- ... --}}
    <x-cookie-consent::scripts />
</head>
<body>
    {{-- ... --}}
    <x-cookie-consent::banner />
</body>
```

Render the banner on every page. Besides the banner, it renders the preferences dialog, which visitors must be able to open at any time to change their choice.

## Defining the cookie policy

The policy declares the categories of your website, the services that each of them uses, and their cookies and scripts:

```php
namespace App\Providers;

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\CookiePolicyProvider as BaseCookiePolicyProvider;
use Eolica\LaravelCookieConsent\Service;

class CookiePolicyProvider extends BaseCookiePolicyProvider
{
    public function policy(CookiePolicy $policy): CookiePolicy
    {
        return $policy
            ->policyUrl(fn () => route('cookies'))
            ->necessary(fn (Category $category) => $category->add($this->sessionCookie(), $this->csrfCookie()))
            ->preferences(fn (Category $category) => $category
                ->localStorage(name: 'theme', purpose: 'Remembers your colour scheme.'))
            ->analytics(fn (Category $category) => $category
                ->service('google-analytics', fn (Service $service) => $service
                    ->provider('Google', 'https://policies.google.com/privacy')
                    ->cookie(name: '_ga', purpose: 'Distinguishes users.', duration: CarbonInterval::years(2))
                    ->cookie(name: '_ga_*', purpose: 'Persists the session state.', duration: CarbonInterval::years(2))
                    ->script(src: 'https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX', attributes: ['async' => true])
                    ->script(content: <<<'JS'
                        window.dataLayer = window.dataLayer || [];
                        function gtag() { dataLayer.push(arguments); }
                        gtag('js', new Date());
                        gtag('config', 'G-XXXXXXXXXX');
                    JS)))
            ->category('chat', fn (Category $category) => $category
                ->label('Chat')
                ->description('Lets you talk to our support team.')
                ->service('crisp', fn (Service $service) => $service
                    ->provider('Crisp', 'https://crisp.chat/en/privacy/')
                    ->sessionStorage(name: 'crisp_state', purpose: 'Keeps the conversation open.')
                    ->script(src: 'https://client.crisp.chat/l.js', attributes: ['async' => true])));
    }
}
```

The policy that `policy()` returns is the one registered, so it can also be built from a base policy shared between your providers. The provider registers it in its `register()` method, which is final, so add your own bindings to another provider.

### Categories

The `necessary` category is always present and always allowed. The cookie that stores the consent is added to it automatically.

| Method | Key |
|---|---|
| `necessary()` | `necessary` |
| `preferences()` | `preferences` |
| `analytics()` | `analytics` |
| `marketing()` | `marketing` |
| `category('chat', ...)` | `chat` |

The built-in categories come with a translated label and description. Set them with `label()` and `description()` for your own categories, or to override the built-in ones. Both accept a plain string or a translation key. Without them, your own categories use the translations `cookie-consent::categories.{key}.label` and `cookie-consent::categories.{key}.description`, which you can add after [publishing the translations](#translations). Otherwise, the banner shows those keys.

### Services

A service is anything that stores data on the browser of your visitors and runs its own scripts, like Google Analytics, a chat widget or a video player. It belongs to a category, so it runs when that category is allowed. Give it a key, unique in the whole policy:

| Method | Description |
|---|---|
| `provider(name, url)` | The company behind the service and the URL of its privacy policy. The banner and the declaration list the providers of every category, even of services without cookies, like YouTube embeds or Google Fonts. Leave it out for services hosted by yourself, like Matomo. |
| `cookie()`, `localStorage()`, `sessionStorage()` | The cookies and storage keys of the service, see [cookies](#cookies). |
| `script(src, content, attributes)` | A script of the service, rendered blocked on every page. See [scripts](#scripts). |

### Cookies

Declare the cookies on their service, or directly on the category when they belong to your own website:

| Method | Storage |
|---|---|
| `cookie(name, purpose, duration)` | Cookie, the duration is a `DateInterval`, like `CarbonInterval::years(2)`, and `null` means a session cookie |
| `localStorage(name, purpose)` | `localStorage` |
| `sessionStorage(name, purpose)` | `sessionStorage` |
| `add(Cookie ...)` | Cookies built beforehand |

The `purpose` accepts a plain string or a translation key. A `*` in the name matches any characters, so `_ga_*` matches `_ga_ABC123`. The declaration shows the durations exactly, so days are never rounded to months or years: declare `CarbonInterval::years(2)` to show *2 years*, as `CarbonInterval::days(730)` shows *730 days*.

The cookies of a service take its provider. To declare a cookie from another company, build it with its own provider, which is kept:

```php
use Eolica\LaravelCookieConsent\Cookie;

->add(new Cookie('__cf_bm', 'Blocks bots.', provider: 'Cloudflare', providerUrl: 'https://www.cloudflare.com/privacypolicy/'))
```

`CookiePolicyProvider` builds the cookies of Laravel for you, using your session configuration:

| Method | Cookie |
|---|---|
| `sessionCookie()` | The session cookie |
| `csrfCookie()` | The `XSRF-TOKEN` cookie. Leave it out if your application does not set it, like with `PreventRequestForgery::useOriginOnly()` |

The declared cookies and storage keys of the categories that are not allowed, and of their services, are removed on every page load and whenever the visitor gives their consent. This also removes the cookies left by an expired consent, or by a consent for a previous version of the policy, as long as the policy still declares them. A cookie that also matches a cookie of an allowed category, or of the necessary one, is kept. When the visitor revokes a category whose scripts or callbacks already ran on the page, the page is reloaded so they stop. On a page that is the response to a form submission, the reload asks the visitor to submit the form again, so redirect after handling a form, as Laravel usually does, on the pages where the visitor can change their consent.

The cookies are removed from the browser, so some of them are out of reach:

- **Other paths.** A cookie is removed on the current path and its parents, so a cookie set on `/blog` is removed from `/blog/post` but not from `/shop`. Most third-party scripts set their cookies on `/`.
- **`HttpOnly` cookies.** The browser can't read or remove them. Only your server can set them, so [check the consent on the server](#checking-the-consent-on-the-server) before setting them, and remove them on the server when their category is not allowed.
- **Cookies of other domains.** Cookies set by third-party domains, like `youtube.com`, can't be removed from your website. They are prevented by blocking the scripts until there is consent.

### Policy options

| Method | Description |
|---|---|
| `policyUrl(string\|Closure)` | URL of your cookie policy page, linked from the banner. A closure is resolved when rendering, so you can use `route()`. |
| `lifetime(DateInterval)` | How long the consent is valid, like `CarbonInterval::months(6)`. Defaults to 12 months. Browsers keep cookies for 400 days at most, so with a longer lifetime the consent is asked again after about 13 months. |
| `version(string)` | Asks every visitor for consent again when changed. |
| `cookie(name, domain)` | Name and domain of the consent cookie. Defaults to `cookie_consent`, or `cookie_consent_{id}` for a policy with an [id](#multiple-websites), and the current host. The name may only contain letters, numbers, dashes and underscores. Set a domain like `.example.com` to share the consent between subdomains. |
| `googleConsentMode()` | Enables [Google Consent Mode v2](#google-consent-mode-v2). |
| `id(string)` | Identifies the policy. Required when you have [several policies](#multiple-websites). |
| `domain(string ...)`, `when(Closure)`, `default()` | Choose the requests the policy applies to. See [multiple websites](#multiple-websites). |
| `middleware(string ...)` | Middleware of the route that stores the consent. See [middleware](#middleware). |

Adding or removing a category or a service, or moving a service to another category, also asks every visitor for consent again. Use `version()` for any other change that needs a new consent, like declaring a new cookie in an existing service. When a visitor gives their consent on a page rendered before the change, the page is reloaded to show the new policy. If you use a full-page cache, like a CDN or `spatie/laravel-responsecache`, clear it when you deploy a change to the policy. Otherwise, visitors keep getting the previous policy and cannot store their consent until the cache expires.

The consent cookie is not encrypted, so the browser can read it. The package excludes it from the `EncryptCookies` middleware for you.

## Scripts

Scripts that set non-necessary cookies must not run until their category is allowed. Declare them on their service, and the package renders them blocked on every page:

```php
->service('google-analytics', fn (Service $service) => $service
    ->script(src: 'https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX', attributes: ['async' => true])
    ->script(content: "gtag('config', 'G-XXXXXXXXXX');"))
```

Each script is rendered with `type="text/plain"`, so the browser does not run it. Once the category of its service is allowed, the package inserts a runnable copy before it that keeps its other attributes, like `async`, `defer` or `id`, so `document.getElementById()` finds the copy. The blocked script stays on the page, so Livewire navigations do not run it again, and the copy gets `data-navigate-once`, so Livewire does not run it again when going back to a page. Set a `type` attribute to keep a different script type, like `module`. The scripts run in the order of the page: a script waits for the external scripts before it to load, even `defer` scripts and modules, so the code that uses a library finds it loaded. It does not wait for them if they are `async`, or the browser does not run them, like `nomodule` scripts or scripts whose `type` is not JavaScript. An external script that never loads keeps the scripts after it waiting. A script removed from the page before its turn is skipped, and runs if it is added again.

The `src` and the `content` also accept a closure, which receives the current request when rendering:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Js;

->script(content: fn (Request $request) => 'gtag("config", "G-XXXXXXXXXX", { user_id: ' . Js::from($request->user()?->id) . ' });')
```

The content is JavaScript code, so turn the values you add to it into JavaScript with `Js::from()`. The package only escapes `</script` and `<!--`, which would end the script early in the HTML.

### Scripts of a single page

For a script that only some pages need, like a video player, declare its service on the policy and render the script with the `script` component where you need it:

```blade
<x-cookie-consent::script service="youtube" src="https://www.youtube.com/iframe_api" async />

<div id="player"></div>

<x-cookie-consent::script service="youtube">
    window.onYouTubeIframeAPIReady = () => new YT.Player('player', { videoId: '...' });

    if (window.YT?.Player) onYouTubeIframeAPIReady();
</x-cookie-consent::script>
```

The service must exist in the policy of the current request, otherwise an exception is thrown. The component only renders this markup, so you can also write it by hand, or generate it from any other tool:

```html
<script type="text/plain" data-cookie-consent="youtube" data-src="https://www.youtube.com/iframe_api" async></script>
```

Scripts added to the page later, for example by Livewire, are blocked and activated in the same way. A script whose service is not in the policy never runs.

An external script is loaded once while the page is open. When another script with the same `src` is activated, for example when a Livewire navigation goes back to the page, it is not loaded again, and the scripts after it run right away. Write the code that uses it so it also works when the library is already loaded and when it runs again, like the code above. A `const`, `let` or `class` at the top of a script fails when it runs again, so declare them inside a function or a block.

### With seophp/head

If you manage the `<head>` with [seophp/head](https://github.com/seo-php/seo), register the plugin instead of using the `scripts` component. It adds the configuration, the script of the package and the scripts of the services:

```php
use Eolica\LaravelCookieConsent\Head\CookieConsentPlugin;

$head->plugins([
    CookieConsentPlugin::make(),
]);
```

Pass `styles: true` to render the banner styles in the `<head>` too. In that case, render the banner with `<x-cookie-consent::banner :styles="false" />`, so the styles are not rendered twice. Pass a `nonce` if you use a Content Security Policy.

## Content Security Policy

Pass the nonce of your Content Security Policy to the components that render inline code:

```blade
<x-cookie-consent::scripts :nonce="$nonce" />
<x-cookie-consent::banner :nonce="$nonce" />
<x-cookie-consent::script service="youtube" :nonce="$nonce">...</x-cookie-consent::script>
```

The scripts of the services get the nonce of the `scripts` component, or of the head plugin. Scripts activated after consent keep the nonce of the blocked script, so a policy with only a nonce runs the ones with a valid nonce.

With `'strict-dynamic'`, the browser trusts every script that the package creates, with or without a nonce. Any blocked script of an allowed service that ends up on the page runs, including one injected through content that is not escaped. Never render untrusted content as HTML, with `{!! !!}`, `x-html` or `innerHTML`, without sanitizing it first.

## Cookie declaration

Render the declaration of your cookies on your cookie policy page:

```blade
<h1>Cookie policy</h1>

<x-cookie-consent::declaration />
```

It renders a table with the cookies of each category, and a button that opens the preferences dialog of the banner. Pass a `class` or any other attribute to merge them on its root element.

The declaration uses the styles of the banner. On a page without the default banner, or with `:styles="false"`, render the styles with [the seophp/head plugin](#with-seophphead) or write your own.

## Customizing

### Styles

The default banner, preferences dialog and declaration use CSS variables. Override them in your stylesheet:

```css
:root {
    --cookie-consent-color: #1f2937;
    --cookie-consent-muted-color: #4b5563;
    --cookie-consent-background: #ffffff;
    --cookie-consent-border-color: #e5e7eb;
    --cookie-consent-button-color: #ffffff;
    --cookie-consent-button-background: #111827;
    --cookie-consent-button-radius: 0.5rem;
    --cookie-consent-focus-color: #2563eb;
    --cookie-consent-backdrop: rgb(0 0 0 / 0.5);
    --cookie-consent-font-family: inherit;
    --cookie-consent-font-size: 0.9375rem;
    --cookie-consent-radius: 0.75rem;
    --cookie-consent-z-index: 1000;
}
```

Every element has a `cookie-consent*` class, which you can use to adjust anything else, except the elements of the cookie tables, which you can reach from their container, `.cookie-consent-category__table` or `.cookie-consent-declaration__table`. Render the banner with `:styles="false"` to drop the default styles and write your own.

### Translations

The package ships with English and Spanish translations. Publish them to change the texts or add a language:

```bash
php artisan vendor:publish --tag=cookie-consent-translations
```

### Views

Publish the views to change the markup of the default components:

```bash
php artisan vendor:publish --tag=cookie-consent-views
```

### Your own markup

To build the banner or the declaration with your own markup, for example with Tailwind CSS, write it in the slot of the component. The slot replaces the default view, and the default styles are not rendered. Use `$component` to access the policy of the current request:

| Method | Returns |
|---|---|
| `$component->categories()` | The categories of the policy, with their cookies in `$category->getCookies()`, their services in `$category->getServices()` and the privacy policy URLs of their providers, keyed by name, in `$category->getProviders()` |
| `$component->policyUrl()` | The URL of your cookie policy page |
| `$component->label($category)` | The translated label of a category |
| `$component->description($category)` | The translated description of a category |
| `$component->purpose($cookie)` | The translated purpose of a cookie |
| `$component->provider($cookie)` | The provider of a cookie, or the translated text for first-party cookies |
| `$component->duration($cookie)` | The translated duration of a cookie |

The slot is not rendered when no policy applies to the current request.

The package handles any element with the following data attributes:

| Attribute | Behaviour |
|---|---|
| `data-cookie-consent-banner` | Hidden while the visitor has a valid consent, shown otherwise. Render it with the `hidden` attribute, so it does not flash before the script runs. |
| `data-cookie-consent-preferences` | Opened with `showModal()` when it is a `<dialog>`, or by removing the `hidden` attribute otherwise. |
| `data-cookie-consent-category="analytics"` | A checkbox, kept in sync with the consent. The checkbox of the necessary category is disabled. |
| `data-cookie-consent-action="..."` | A button that runs an action: `accept-all`, `reject-all`, `save`, `show-preferences` or `close-preferences`. `save` allows the categories of the checked checkboxes inside the same preferences element or banner. |

```blade
<x-cookie-consent::banner>
    <div data-cookie-consent-banner hidden class="fixed inset-x-4 bottom-4 rounded-xl bg-white p-6 shadow-xl [&[hidden]]:hidden">
        <p>
            We use cookies to improve your experience.
            <a href="{{ $component->policyUrl() }}" class="underline">Cookie policy</a>
        </p>
        <div class="mt-4 flex gap-2">
            <button type="button" data-cookie-consent-action="reject-all">Reject all</button>
            <button type="button" data-cookie-consent-action="show-preferences">Customize</button>
            <button type="button" data-cookie-consent-action="accept-all">Accept all</button>
        </div>
    </div>

    <dialog data-cookie-consent-preferences class="rounded-xl p-6 backdrop:bg-black/50">
        @foreach ($component->categories() as $category)
            <label class="flex gap-3">
                <input type="checkbox" data-cookie-consent-category="{{ $category->getKey() }}">
                <span>
                    <span class="font-medium">{{ $component->label($category) }}</span>
                    <span class="block text-sm text-gray-500">{{ $component->description($category) }}</span>
                </span>
            </label>
        @endforeach
        <div class="mt-4 flex gap-2">
            <button type="button" data-cookie-consent-action="reject-all">Reject all</button>
            <button type="button" data-cookie-consent-action="save">Save preferences</button>
            <button type="button" data-cookie-consent-action="accept-all">Accept all</button>
        </div>
    </dialog>
</x-cookie-consent::banner>
```

The Tailwind CSS v3 preflight hides `[hidden]` elements with a low specificity, so a display utility like `flex` or `block` shows them anyway. Add `[&[hidden]]:hidden`, as in the example above, or wrap the content in a plain element. Tailwind CSS v4 does not need it.

A button with `data-cookie-consent-action="show-preferences"` works anywhere on the page, so you can add one to your footer:

```html
<button type="button" data-cookie-consent-action="show-preferences">Cookie settings</button>
```

If you prefer to drive everything from JavaScript, like with Alpine.js, skip the data attributes and use the [JavaScript API](#javascript-api).

## JavaScript API

The script exposes the consent on `window.CookieConsent`:

| Member | Description |
|---|---|
| `consent` | The current consent, or `null`. It is frozen, so change it with `accept()` |
| `needsConsent` | Whether the visitor has to give consent |
| `categories` | The keys of the categories of the policy |
| `allows(category)` | Whether a category is allowed |
| `isNecessary(category)` | Whether a category is the necessary one |
| `acceptAll()` | Allows every category |
| `rejectAll()` | Allows only the necessary category |
| `accept(categories)` | Allows the given categories, plus the necessary one |
| `showPreferences()` | Asks the preferences dialog to open |
| `whenAllowed(category, callback, { signal })` | Runs the callback once the category is allowed, like a script placed after the scripts of the services that were activated before it, so it can use what they define, like `gtag()`. It runs right away when the category is allowed and no script is waiting for an external script to load. An error in the callback is reported with `reportError()` and does not stop the other callbacks. Abort the optional `signal` to cancel a callback that has not run yet, like with `addEventListener()` |

The script dispatches these events on `document`:

| Event | `detail` |
|---|---|
| `cookie-consent:ready` | `{ consent }` |
| `cookie-consent:updated` | `{ consent, categories }`, with the allowed categories. It is dispatched as soon as the visitor chooses, before the server stores the consent, so `consent.id` is empty. Await `accept()`, `acceptAll()` or `rejectAll()` to read the `id` |
| `cookie-consent:show-preferences` | none |

The events do not wait for the external scripts of the services to load, so call `whenAllowed()` from their listeners to run code that uses a service. Call it only when the category is allowed, otherwise every choice without it leaves another callback waiting.

The script is a module, so it runs after the page is parsed. Listen to `cookie-consent:ready`, or check `window.CookieConsent` first, when your code may run before it:

```html
<div
    x-data="{ needsConsent: false, sync() { this.needsConsent = CookieConsent.needsConsent } }"
    x-init="if (window.CookieConsent) sync()"
    @cookie-consent:ready.document="sync()"
    @cookie-consent:updated.document="sync()"
>
    <div x-show="needsConsent">
        <button type="button" @click="CookieConsent.rejectAll()">Reject all</button>
        <button type="button" @click="CookieConsent.acceptAll()">Accept all</button>
    </div>
</div>
```

## Checking the consent on the server

Use the `@consent` directive to render content only when a category is allowed:

```blade
@consent('marketing')
    <iframe src="https://www.youtube.com/embed/..."></iframe>
@else
    <p>Allow marketing cookies to watch this video.</p>
@endconsent
```

Or inject the `CookieConsentManager`:

```php
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Illuminate\Http\Request;

public function show(Request $request, CookieConsentManager $cookieConsent)
{
    if ($cookieConsent->allows($request, 'analytics')) {
        // ...
    }

    $consent = $cookieConsent->getConsent($request);
}
```

Both throw an exception when the category is not in the policy of the page, so a typo does not hide content silently, and return `false` on pages without a policy, except for the necessary category. In a layout shared by policies with different categories, check that the policy has the category first with `$cookieConsent->resolvePolicy($request)?->hasCategory('chat')`.

The consent has the `id`, `policy`, `revision` and `categories` of the choice, and the date it was given in `givenAt`, a `DateTimeImmutable` in the timezone of the application. The `id` is empty when the server did not store the consent, for example because the request failed, and then there is no record of it either. It is read from a cookie that the visitor can edit, so keep [a record of the consent](#keeping-a-record-of-the-consent) when you need to prove it.

When the visitor changes their choice, the page is not reloaded unless a category whose scripts or callbacks already ran is revoked. Content rendered on the server will not reflect the new consent until the next request, so prefer [the scripts of a service](#scripts) for anything that must start right after consent.

Content rendered with `@consent` or `allows()` depends on the cookie of each visitor, so do not store those pages in a full-page cache, like a CDN or `spatie/laravel-responsecache`, unless the cache varies on the consent cookie. Otherwise, every visitor gets the content rendered for the first one. [Scripts](#scripts) and [`whenAllowed()`](#javascript-api) are decided in the browser, so they work with those caches.

### Keeping a record of the consent

Every consent is sent to the server, which dispatches an `Eolica\LaravelCookieConsent\Events\ConsentUpdated` event. Listen to it to keep a record of the consents, which you may need to prove that a visitor gave consent:

```php
namespace App\Listeners;

use App\Models\ConsentRecord;
use Eolica\LaravelCookieConsent\Events\ConsentUpdated;

class RecordCookieConsent
{
    public function handle(ConsentUpdated $event): void
    {
        ConsentRecord::create([
            'consent_id' => $event->consent->id,
            'policy' => $event->consent->policy,
            'revision' => $event->consent->revision,
            'categories' => $event->consent->categories,
            'given_at' => $event->consent->givenAt,
            'ip' => $event->request->ip(),
        ]);
    }
}
```

The route that stores the consent has no middleware by default, so `$event->request->user()` is `null`. Add the `web` middleware to [the route](#middleware) to know the user who gave the consent.

The event carries the request and the policy, which cannot be serialized, so the listener cannot implement `ShouldQueue`. To do the work in the background, like sending the record to an external service, copy what you need in the listener and dispatch a job:

```php
public function handle(ConsentUpdated $event): void
{
    SendConsentRecord::dispatch($event->consent, $event->request->ip(), $event->request->userAgent());
}
```

## Google Consent Mode v2

Enable Google Consent Mode on the policy:

```php
return $policy
    ->googleConsentMode()
    ->analytics(fn (Category $category) => $category->cookie(/* ... */));
```

The package sends `gtag('consent', 'default', ...)` with every signal denied except those of the necessary category, and `gtag('consent', 'update', ...)` with the consent of the visitor. Each signal is granted when any of its categories is allowed:

| Category | Signals |
|---|---|
| `necessary` | `security_storage` |
| `preferences` | `functionality_storage`, `personalization_storage` |
| `analytics` | `analytics_storage` |
| `marketing` | `ad_storage`, `ad_user_data`, `ad_personalization` |

Choose the signals of a category with `googleConsent()`:

```php
use Eolica\LaravelCookieConsent\GoogleConsent;

->category('ads', fn (Category $category) => $category
    ->label('Advertising')
    ->description('Used to show you relevant advertising.')
    ->googleConsent(GoogleConsent::AdStorage, GoogleConsent::AdUserData))
```

The package implements the basic mode of Google Consent Mode: Google tags must not load until their category is allowed. Declare the Google tag as [scripts of its service](#scripts), including the inline snippet that calls `gtag('config', ...)`. The advanced mode, which loads the Google tags before consent, is not supported.

## Multiple websites

When one application serves several websites, give each website its own policy with a different `id`, and choose the requests it applies to with `domain()` or `when()`:

```php
public function policy(CookiePolicy $policy): CookiePolicy
{
    return $policy
        ->id('shop')
        ->domain('shop.example.com', '*.shop.example.com')
        ->necessary(fn (Category $category) => $category->add($this->sessionCookie()));
}
```

```php
use Illuminate\Http\Request;

public function policy(CookiePolicy $policy): CookiePolicy
{
    return $policy
        ->id('admin')
        ->when(fn (Request $request) => $request->is('admin/*'))
        ->necessary(fn (Category $category) => $category->add($this->sessionCookie()));
}
```

Register each provider in `bootstrap/providers.php`. `make:cookie-policy` does it for you.

Each policy already has its own consent cookie. If another application also uses the package on the same host, like one installed in another subdirectory, or under the same domain and shares its consent cookie with `cookie(domain: '.example.com')`, give your policies a different cookie name with `cookie(name:)`, so their consent cookies do not overwrite each other.

The script of the package and the route that stores the consent are generated with `route()`, so they follow the root URL of the application, which is the URL of the current request by default. Do not force a single root URL with `URL::forceRootUrl()` when the application serves several domains, or the browser loads them from another domain and blocks them.

The conditions run on every request, including the requests of Livewire components and other requests that load parts of a page, which do not have the URL of the page. Prefer `domain()` when you can, and see [Livewire](#livewire) for conditions on the path.

The first policy whose domains and conditions all match the request is used. Otherwise, the default policy is used: the one without domains or conditions, or the one marked with `default()`. Only one policy can be the default one. When no policy applies to a request, only the necessary category is allowed, and the `scripts`, `banner` and `declaration` components render nothing. The `script` component still renders its blocked script, which never runs, because the script of the package is not loaded.

The policies are checked in the order of their providers in `bootstrap/providers.php`, which `make:cookie-policy` keeps sorted by class name, like `make:provider`. When two policies can match the same request, make their conditions exclusive instead of relying on that order, like `->when(fn (Request $request) => ! $request->is('admin/*'))` on the policy of the shop.

## Livewire

The script is rendered with `data-navigate-once`, so it keeps working between `wire:navigate` navigations. The scripts of the services are rendered in the `<head>`, so they run once, like any other script in the `<head>` with `wire:navigate`. A blocked script in the `<body>` with `data-navigate-once` also runs once, like any other script with that attribute. When a navigation leads to a page with another policy, the page is reloaded to use it. A navigation to a page without a policy keeps the policy of the previous page, because Livewire keeps the scripts of the `<head>`. Link to those pages without `wire:navigate` if they must not use it.

When a component updates, its request goes to Livewire, not to the URL of the page, so a condition like `$request->is('admin/*')` does not match it. `@consent`, `allows()` and the `script` component would then use another policy. Use the path of the page that Livewire keeps instead:

```php
use Illuminate\Support\Str;
use Livewire\LivewireManager;

->when(fn () => Str::is('admin/*', app(LivewireManager::class)->originalPath()))
```

The blocked scripts of a page are removed with it, so they never run on another page. A `whenAllowed()` callback that is still waiting for consent stays, and Livewire runs the scripts of the `<body>` again on every navigation, so cancel it when leaving the page. Use a module, so it runs after the script of the package:

```html
<script type="module">
    const controller = new AbortController();

    document.addEventListener('livewire:navigating', () => controller.abort(), { once: true });

    CookieConsent.whenAllowed('marketing', () => showVideo(), { signal: controller.signal });
</script>
```

Because the page is not reloaded on navigation, analytics tools must track the page views on their own. For Google Analytics 4, enable *Page changes based on browser history events* in the enhanced measurement settings of your data stream.

## Ad blockers

Some ad blockers, like Brave Shields or uBlock Origin with the EasyList Cookie list, block the script of the package. When that happens, the banner is not shown and the blocked scripts never run, which is the same as rejecting every category.

## Routes

The package registers these routes:

| Route | Name |
|---|---|
| `POST /cookie-consent/{id}` | `cookie-consent.store.{id}`, one for every policy, like `cookie-consent.store.default` |
| `GET /cookie-consent/cookie-consent.js` | `cookie-consent.script` |

The script is served with a long-lived cache, and its URL changes whenever the script changes. A request for another version of the script is not cached, so a server that still has the previous version during a deployment does not leave it cached under the new URL.

The script is served by Laravel, not from the `public` directory. If it returns a 404 and your nginx configuration has a `location` block for `.js` files, add a fallback to Laravel to that block:

```nginx
location ~* \.(js|css|png|jpg|svg|woff2)$ {
    try_files $uri /index.php?$query_string;
    expires 1y;
}
```

The routes to store the consent are registered when the application boots, so register your policies with a `CookiePolicyProvider`. To register a policy from another service provider, do it in its `register()` method:

```php
public function register(): void
{
    $this->callAfterResolving(CookieConsentManager::class, function (CookieConsentManager $manager): void {
        $manager->policy(fn (CookiePolicy $policy) => $policy->id('shop')->domain('shop.example.com'));
    });
}
```

### Middleware

The route that stores the consent of a policy has no middleware by default, so it works without a session or a CSRF token. Add middleware to it on the policy:

```php
public function policy(CookiePolicy $policy): CookiePolicy
{
    return $policy
        ->middleware('web', 'throttle:10,1')
        ->necessary(fn (Category $category) => $category->add($this->sessionCookie(), $this->csrfCookie()));
}
```

For example:

- `web`, to know the authenticated user in the [`ConsentUpdated` event](#keeping-a-record-of-the-consent). The script sends the CSRF token for you.
- `throttle`, to limit how many consents a visitor can store. The route is public and every consent dispatches the `ConsentUpdated` event, so limit it if you keep a record of the consents. A visitor stores one or two consents per visit, so a low limit is enough. Behind a load balancer or a CDN, [configure the trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies), or every visitor shares the IP address of the proxy and the limit is reached for all of them. When the limit is reached, the consent still works in the browser, but it is not stored on the server.
- The middleware that identifies the tenant in a multi-tenant application, so your listeners store the consent in the right place. Give the central website its own policy without it.

## Contributing

See the [contributing guide](.github/CONTRIBUTING.md).

## Security

See the [security policy](.github/SECURITY.md).

## License

The MIT License (MIT). See the [license file](LICENSE) for more information.
