<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Consent;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Events\ConsentUpdated;
use Eolica\LaravelCookieConsent\Http\Controllers\StoreConsentController;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

covers(StoreConsentController::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-10 12:00:00');

    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->lifetime(CarbonInterval::days(10))
        ->cookie(name: 'shop_consent', domain: '.tienda.test')
        ->analytics(static fn() => null)
        ->marketing(static fn() => null));
});

/**
 * @param array<string, mixed> $body
 */
function storeConsent(array $body, bool $header = true, string $scheme = 'https'): Response
{
    return app(Kernel::class)->handle(Request::create(
        "{$scheme}://tienda.test/cookie-consent",
        'POST',
        server: ['CONTENT_TYPE' => 'application/json', ...($header ? ['HTTP_X_COOKIE_CONSENT' => '1'] : [])],
        content: json_encode($body, JSON_THROW_ON_ERROR),
    ));
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function consentBody(array $overrides = []): array
{
    return [
        'policy' => 'shop',
        'revision' => app(CookieConsentManager::class)->getPolicy('shop')->getRevision(),
        'categories' => ['analytics'],
        ...$overrides,
    ];
}

function consentCookie(Response $response): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === 'shop_consent') {
            return $cookie;
        }
    }

    return null;
}

function fakeEvents(): EventFake
{
    $events = new EventFake(app(Dispatcher::class), [ConsentUpdated::class]);

    app()->instance('events', $events);

    return $events;
}

it('stores the consent in a cookie', function (): void {
    Str::createUlidsUsing(static fn(): Ulid => new Ulid('01JC0000000000000000000000'));

    $response = storeConsent(consentBody(['categories' => ['marketing', 'analytics']]));
    $consent = Consent::fromJson((string) $response->getContent());
    $cookie = consentCookie($response);

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->headers->get('Content-Type'))
        ->toBe('application/json')
        ->and($consent)
        ->not
        ->toBeNull()
        ->and($consent?->id)
        ->toBe('01JC0000000000000000000000')
        ->and($consent?->policy)
        ->toBe('shop')
        ->and($consent?->revision)
        ->toBe(app(CookieConsentManager::class)->getPolicy('shop')->getRevision())
        ->and($consent?->categories)
        ->toBe(['necessary', 'analytics', 'marketing'])
        ->and($consent?->givenAt->toDateTimeString())
        ->toBe('2026-11-10 12:00:00')
        ->and($cookie?->getValue())
        ->toBe($response->getContent())
        ->and($cookie?->getExpiresTime())
        ->toBe(CarbonImmutable::parse('2026-11-20 12:00:00')->getTimestamp())
        ->and($cookie?->getPath())
        ->toBe('/')
        ->and($cookie?->getDomain())
        ->toBe('.tienda.test')
        ->and($cookie?->isSecure())
        ->toBeTrue()
        ->and($cookie?->isHttpOnly())
        ->toBeFalse()
        ->and($cookie?->isRaw())
        ->toBeFalse()
        ->and($cookie?->getSameSite())
        ->toBe('lax');
});

it('stores a cookie that is not secure over http', function (): void {
    expect(consentCookie(storeConsent(consentBody(), scheme: 'http'))?->isSecure())->toBeFalse();
});

it('always stores the necessary category once', function (array $categories, array $stored): void {
    $consent = Consent::fromJson((string) storeConsent(consentBody(['categories' => $categories]))->getContent());

    expect($consent?->categories)->toBe($stored);
})->with([
    'none' => [[], ['necessary']],
    'necessary' => [['necessary'], ['necessary']],
    'skipping one' => [['marketing'], ['necessary', 'marketing']],
    'repeated' => [['analytics', 'analytics', 'necessary'], ['necessary', 'analytics']],
]);

it('dispatches the consent updated event', function (): void {
    $events = fakeEvents();

    $response = storeConsent(consentBody());

    $events->assertDispatchedTimes(ConsentUpdated::class, times: 1);
    $events->assertDispatched(
        ConsentUpdated::class,
        static fn(ConsentUpdated $event): bool => (
            $event->consent->toJson() === $response->getContent()
            && $event->policy === app(CookieConsentManager::class)->getPolicy('shop')
            && $event->request->getHost() === 'tienda.test'
        ),
    );
});

it('rejects requests without the header', function (): void {
    $events = fakeEvents();

    $response = storeConsent(consentBody(), header: false);

    expect($response->getStatusCode())
        ->toBe(403)
        ->and($response->getContent())
        ->toBe('{"message":"The X-Cookie-Consent header is required."}')
        ->and(consentCookie($response))
        ->toBeNull();

    $events->assertNotDispatched(ConsentUpdated::class);
});

it('rejects unknown policies', function (mixed $policy): void {
    $response = storeConsent(consentBody(['policy' => $policy]));

    expect($response->getStatusCode())
        ->toBe(422)
        ->and($response->getContent())
        ->toBe('{"message":"The policy is not registered."}')
        ->and(consentCookie($response))
        ->toBeNull();
})->with(['blog', 1, null]);

it('rejects consents for another revision', function (): void {
    $response = storeConsent(consentBody(['revision' => 'old']));
    $revision = app(CookieConsentManager::class)->getPolicy('shop')->getRevision();

    expect($response->getStatusCode())
        ->toBe(409)
        ->and($response->getContent())
        ->toBe(json_encode(['message' => 'The policy has changed.', 'revision' => $revision]))
        ->and(consentCookie($response))
        ->toBeNull();
});

it('rejects invalid categories', function (mixed $categories): void {
    $events = fakeEvents();

    $response = storeConsent(consentBody(['categories' => $categories]));

    expect($response->getStatusCode())
        ->toBe(422)
        ->and($response->getContent())
        ->toBe('{"message":"The categories must be a list of the policy categories."}')
        ->and(consentCookie($response))
        ->toBeNull();

    $events->assertNotDispatched(ConsentUpdated::class);
})->with([
    'missing' => null,
    'not an array' => 'analytics',
    'not a list' => [['a' => 'analytics']],
    'not a string' => [['analytics', 1]],
    'not in the policy' => [['analytics', 'preferences']],
]);
