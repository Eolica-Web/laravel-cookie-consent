<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withParallel()
    ->withPaths([__DIR__.'/lang', __DIR__.'/routes', __DIR__.'/src', __DIR__.'/tests'])
    ->withRootFiles()
    ->withPhpSets()
    ->withSkip([])
;
