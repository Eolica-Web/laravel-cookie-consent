<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\ServiceProvider;

final class MakeCookiePolicyCommand extends GeneratorCommand
{
    protected $signature = 'make:cookie-policy
                    {name : The name of the cookie policy provider}
                    {--f|force : Create the class even if the cookie policy provider already exists}';

    protected $description = 'Create a new cookie policy provider class';

    protected $type = 'Cookie policy provider';

    public function handle(): ?bool
    {
        if (parent::handle() === false) {
            return false;
        }

        ServiceProvider::addProviderToBootstrapFile(
            $this->qualifyClass($this->getNameInput()),
            $this->laravel->bootstrapPath('providers.php'),
        );

        return null;
    }

    protected function getStub(): string
    {
        $custom = $this->laravel->basePath('stubs/cookie-policy-provider.stub');

        return file_exists($custom) ? $custom : __DIR__ . '/../../stubs/cookie-policy-provider.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Providers';
    }
}
