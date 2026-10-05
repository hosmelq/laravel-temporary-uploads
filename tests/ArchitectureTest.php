<?php

declare(strict_types=1);

arch()->preset()->laravel();
arch()->preset()->php();
arch()->preset()->security();

arch('strict types')
    ->expect('HosmelQ\TemporaryUploads')
    ->toUseStrictTypes();
