<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Capture fatal errors that crash FrankenPHP workers (→ 502).
// These are NOT caught by Symfony's exception handler.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        file_put_contents('php://stderr', sprintf(
            "[FATAL] %s in %s:%d\n",
            $error['message'],
            $error['file'],
            $error['line']
        ));
    }
});

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
