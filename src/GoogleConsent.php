<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

enum GoogleConsent: string
{
    case AdStorage = 'ad_storage';
    case AdUserData = 'ad_user_data';
    case AdPersonalization = 'ad_personalization';
    case AnalyticsStorage = 'analytics_storage';
    case FunctionalityStorage = 'functionality_storage';
    case PersonalizationStorage = 'personalization_storage';
    case SecurityStorage = 'security_storage';
}
