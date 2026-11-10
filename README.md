# Laravel Cookie Consent

Cookie consent management for Laravel. Declare the cookies of your website in a typed cookie policy, and the package will:

- Render an accessible consent banner and preferences dialog, or let you build your own with any markup.
- Block third-party scripts until their category is allowed, and remove their cookies when consent is revoked.
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
            ->necessary(fn (Category $category) => $category->session()->csrf())
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

### Categories

The `necessary` category is always present and always allowed. The cookie that stores the consent is added to it automatically.

| Method | Key |
|---|---|
| `necessary()` | `necessary` |
| `preferences()` | `preferences` |
| `analytics()` | `analytics` |
| `marketing()` | `marketing` |
| `category('chat', ...)` | `chat` |

The built-in categories come with a translated label and description. Set them with `label()` and `description()` for your own categories, or to override the built-in ones. Both accept a plain string or a translation key.

### Services

A service is anything that stores data on the browser of your visitors and runs its own scripts, like Google Analytics, a chat widget or a video player. It belongs to a category, so it runs when that category is allowed. Give it a key, unique in the whole policy:

| Method | Description |
|---|---|
| `provider(name, url)` | The company behind the service and the URL of its privacy policy, shown in the declaration. Leave it out for services hosted by yourself, like Matomo. |
| `cookie()`, `localStorage()`, `sessionStorage()` | The cookies and storage keys of the service, see [cookies](#cookies). |
| `script(src, content, attributes)` | A script of the service, rendered blocked on every page. See [scripts](#scripts). |

### Cookies

Declare the cookies on their service, or directly on the category when they belong to your own website:

| Method | Storage |
|---|---|
| `cookie(name, purpose, duration)` | Cookie, a `null` duration means a session cookie |
| `localStorage(name, purpose)` | `localStorage` |
| `sessionStorage(name, purpose)` | `sessionStorage` |
| `session()` | The Laravel session cookie, using your session configuration. Only on categories. |
| `csrf()` | The `XSRF-TOKEN` cookie. Only on categories. |

The `purpose` accepts a plain string or a translation key. A `*` in the name matches any characters, so `_ga_*` matches `_ga_ABC123`.

When a visitor revokes a category, the declared cookies and storage keys of that category and its services are removed and the page is reloaded, so the scripts that were already running stop.

The cookies are removed from the browser, so some of them are out of reach:

- **Other paths.** A cookie is removed on the current path and its parents, so a cookie set on `/blog` is removed from `/blog/post` but not from `/shop`. Most third-party scripts set their cookies on `/`.
- **`HttpOnly` cookies.** The browser can't read or remove them. Only your server can set them, so [check the consent on the server](#checking-the-consent-on-the-server) before setting them, and remove them on the server when their category is not allowed.
- **Cookies of other domains.** Cookies set by third-party domains, like `youtube.com`, can't be removed from your website. They are prevented by blocking the scripts until there is consent.

### Policy options

| Method | Description |
|---|---|
| `policyUrl(string\|Closure)` | URL of your cookie policy page, linked from the banner. A closure is resolved when rendering, so you can use `route()`. |
| `lifetime(CarbonInterval)` | How long the consent is valid. Defaults to 12 months. |
| `version(string)` | Asks every visitor for consent again when changed. |
| `cookie(name, domain)` | Name and domain of the consent cookie. Defaults to `cookie_consent`, or `cookie_consent_{id}` for a policy with an [id](#multiple-websites), and the current host. The name may only contain letters, numbers, dashes and underscores. Set a domain like `.example.com` to share the consent between subdomains. |
| `googleConsentMode()` | Enables [Google Consent Mode v2](#google-consent-mode-v2). |
| `id(string)` | Identifies the policy. Required when you have [several policies](#multiple-websites). |
| `domain(string ...)`, `when(Closure)`, `default()` | Choose the requests the policy applies to. See [multiple websites](#multiple-websites). |

Adding, removing or moving a category or a service also asks every visitor for consent again. Use `version()` for any other change that needs a new consent, like declaring a new cookie in an existing service. When a visitor gives their consent on a page rendered before the change, the page is reloaded to show the new policy.

The consent cookie is not encrypted, so the browser can read it. The package excludes it from the `EncryptCookies` middleware for you.

## Scripts

Scripts that set non-necessary cookies must not run until their category is allowed. Declare them on their service, and the package renders them blocked on every page:

```php
->service('google-analytics', fn (Service $service) => $service
    ->script(src: 'https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX', attributes: ['async' => true])
    ->script(content: "gtag('config', 'G-XXXXXXXXXX');"))
```

Each script is rendered with `type="text/plain"`, so the browser does not run it. Once the category of its service is allowed, the package replaces it with a runnable copy that keeps its other attributes, like `async`, `defer` or `id`. Set a `type` attribute to keep a different script type, like `module`.

The `src` and the `content` also accept a closure, which receives the current request when rendering:

```php
->script(content: fn (Request $request) => "gtag('config', 'G-XXXXXXXXXX', { user_id: '{$request->user()?->id}' });")
```

### Scripts of a single page

For a script that only some pages need, like a video player, declare its service on the policy and render the script with the `script` component where you need it:

```blade
<x-cookie-consent::script service="youtube" src="https://www.youtube.com/iframe_api" async />

<x-cookie-consent::script service="youtube">
    // ...
</x-cookie-consent::script>
```

The service must exist in the policy of the current request, otherwise an exception is thrown. The component only renders this markup, so you can also write it by hand, or generate it from any other tool:

```html
<script type="text/plain" data-cookie-consent="youtube" data-src="https://www.youtube.com/iframe_api" async></script>
```

Scripts added to the page later, for example by Livewire, are blocked and activated in the same way. A script whose service is not in the policy never runs.

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

The scripts of the services get the nonce of the `scripts` component, or of the head plugin. Scripts activated after consent keep the nonce of the blocked script.

## Cookie declaration

Render the declaration of your cookies on your cookie policy page:

```blade
<h1>Cookie policy</h1>

<x-cookie-consent::declaration />
```

It renders a table with the cookies of each category, and a button that opens the preferences dialog of the banner. Pass a `class` or any other attribute to merge them on its root element.

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

Every element has a `cookie-consent*` class, which you can use to adjust anything else. Render the banner with `:styles="false"` to drop the default styles and write your own.

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
| `$component->categories()` | The categories of the policy, with their cookies in `$category->getCookies()` and their services in `$category->getServices()` |
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
    <div data-cookie-consent-banner hidden class="fixed inset-x-4 bottom-4 rounded-xl bg-white p-6 shadow-xl [[hidden]]:hidden">
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
| `consent` | The current consent, or `null` |
| `needsConsent` | Whether the visitor has to give consent |
| `categories` | The keys of the categories of the policy |
| `allows(category)` | Whether a category is allowed |
| `isNecessary(category)` | Whether a category is the necessary one |
| `acceptAll()` | Allows every category |
| `rejectAll()` | Allows only the necessary category |
| `accept(categories)` | Allows the given categories, plus the necessary one |
| `showPreferences()` | Asks the preferences dialog to open |
| `whenAllowed(category, callback)` | Runs the callback now if the category is allowed, or once it is |

The script dispatches these events on `document`:

| Event | `detail` |
|---|---|
| `cookie-consent:ready` | `{ consent }` |
| `cookie-consent:updated` | `{ consent, categories }`, with the allowed categories |
| `cookie-consent:show-preferences` | none |

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

When the visitor changes their choice, the page is not reloaded unless a category is revoked. Content rendered on the server will not reflect the new consent until the next request, so prefer [the scripts of a service](#scripts) for anything that must start right after consent.

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
        ->necessary(fn (Category $category) => $category->session());
}
```

```php
public function policy(CookiePolicy $policy): CookiePolicy
{
    return $policy
        ->id('admin')
        ->when(fn (Request $request) => $request->is('admin/*'))
        ->necessary(fn (Category $category) => $category->session());
}
```

Register each provider in `bootstrap/providers.php`. `make:cookie-policy` does it for you.

The first policy whose domains and conditions all match the request is used. Otherwise, the default policy is used: the one without domains or conditions, or the one marked with `default()`. Only one policy can be the default one. When no policy applies to a request, the components render nothing and only the necessary category is allowed.

## Livewire

The script is rendered with `data-navigate-once`, so it keeps working between `wire:navigate` navigations. The scripts of the services are rendered in the `<head>`, so they run once, like any other script in the `<head>` with `wire:navigate`.

Because the page is not reloaded on navigation, analytics tools must track the page views on their own. For Google Analytics 4, enable *Page changes based on browser history events* in the enhanced measurement settings of your data stream.

## Ad blockers

Some ad blockers, like Brave Shields or uBlock Origin with the EasyList Cookie list, block the script of the package. When that happens, the banner is not shown and the blocked scripts never run, which is the same as rejecting every category.

## Routes

The package registers two routes:

| Route | Name |
|---|---|
| `POST /cookie-consent` | `cookie-consent.store` |
| `GET /cookie-consent/cookie-consent.js` | `cookie-consent.script` |

The script is served with a long-lived cache, and its URL changes whenever the script changes.

## Contributing

See the [contributing guide](.github/CONTRIBUTING.md).

## Security

See the [security policy](.github/SECURITY.md).

## License

The MIT License (MIT). See the [license file](LICENSE) for more information.
