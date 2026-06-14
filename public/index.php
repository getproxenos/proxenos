<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Standard Symfony Runtime front controller. Under FrankenPHP worker mode
// (FRANKENPHP_CONFIG="worker .../public/index.php") symfony/runtime keeps this
// kernel resident and loops requests through it; in classic mode it boots per
// request. One file serves both paths.
return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
