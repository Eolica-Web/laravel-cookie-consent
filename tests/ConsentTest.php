<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Consent;
use Eolica\LaravelCookieConsent\CookiePolicy;

covers(Consent::class);

/**
 * @param array<string, mixed> $overrides
 */
function consentJson(array $overrides = []): string
{
    return json_encode([
        'id' => '01JC0000000000000000000000',
        'policy' => 'shop',
        'revision' => 'abc',
        'categories' => ['necessary', 'analytics'],
        'at' => 1_762_646_400,
        ...$overrides,
    ], JSON_THROW_ON_ERROR);
}

it('decodes the cookie value', function (): void {
    expect(Consent::fromJson(consentJson()))
        ->toEqual(new Consent(
            id: '01JC0000000000000000000000',
            policy: 'shop',
            revision: 'abc',
            categories: ['necessary', 'analytics'],
            givenAt: CarbonImmutable::createFromTimestamp(1_762_646_400),
        ));
});

it('fails to decode invalid values', function (string $json): void {
    expect(Consent::fromJson($json))->toBeNull();
})->with([
    'not json' => '{',
    'not an object' => '"consent"',
    'missing id' => fn() => consentJson(['id' => null]),
    'id not a string' => fn() => consentJson(['id' => 1]),
    'policy not a string' => fn() => consentJson(['policy' => 1]),
    'revision not a string' => fn() => consentJson(['revision' => 1]),
    'categories not an array' => fn() => consentJson(['categories' => 'analytics']),
    'categories not a list' => fn() => consentJson(['categories' => ['a' => 'analytics']]),
    'category not a string' => fn() => consentJson(['categories' => ['analytics', 1]]),
    'at not an integer' => fn() => consentJson(['at' => '1762646400']),
]);

it('allows the given categories', function (): void {
    $consent = Consent::fromJson(consentJson(['categories' => ['analytics']]));

    expect($consent?->allows('analytics'))->toBeTrue()->and($consent?->allows('marketing'))->toBeFalse();
});

it('is valid for the policy and revision it was given for while it lasts', function (): void {
    $policy = new CookiePolicy()->id('shop')->lifetime(CarbonInterval::days(10));
    $consent = new Consent('id', 'shop', $policy->getRevision(), [], CarbonImmutable::parse('2026-11-01 12:00:00'));

    expect($consent->isValidFor($policy, CarbonImmutable::parse('2026-11-11 11:59:59')))
        ->toBeTrue()
        ->and($consent->isValidFor($policy, CarbonImmutable::parse('2026-11-11 12:00:00')))
        ->toBeFalse();
});

it('is not valid for another policy or revision', function (): void {
    $policy = new CookiePolicy()->id('shop');
    $now = CarbonImmutable::parse('2026-11-01 12:00:00');

    expect(new Consent('id', 'blog', $policy->getRevision(), [], $now)->isValidFor($policy, $now))
        ->toBeFalse()
        ->and(new Consent('id', 'shop', 'other', [], $now)->isValidFor($policy, $now))
        ->toBeFalse();
});

it('encodes the cookie value', function (): void {
    $consent = Consent::fromJson(consentJson());

    expect($consent?->toJson())->toBe(consentJson());
});
