<?php

use Carbure\Lib\ClassLoader;
use Carbure\Lib\Logger;
use Carbure\Lib\Setup;
use Carbure\Lib\Webservice;

// Not installed yet: only the installation wizard of the portal answers (/api/setup)
$env = preg_replace('/[^a-z0-9_-]/i', '', getenv('APP_ENV') ?: 'prod');
if (!is_file(dirname(__DIR__) . "/data/conf/$env.ini") && !is_file(dirname(__DIR__) . "/conf/$env.ini")) {
    require_once __DIR__ . '/lib/ClassLoader.php';
    ClassLoader::register();
    Setup::serve(dirname(__DIR__), $env);
    return;
}

require_once __DIR__ . '/autoload.php';

try {

    // if the path doesn't contain a '.' we consider it as a request to the webservice
    // (the query string may: a log file name, a search on "S.N.C.F"...)
    // /.well-known/: the OAuth metadata of the MCP server
    $requestPath = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    if (strpos($requestPath, ".") === false || str_starts_with($requestPath, '/.well-known/')) {

        // Access log: method, path (tokens masked), status and duration, at the end of
        // the request (registered first: written before the buffer is flushed)
        register_shutdown_function([Logger::class, 'access']);

        // We execute the webservice
        echo Webservice::exec();
        return;
    }

} catch (Exception $e) {
    // Exception occurred, we return a 500 error
    Webservice::error(500, $e);

} catch (Error $e) {
    // Error occurred, we return the error code if it is an HTTP client error, 400 otherwise
    $code = $e->getCode();
    Webservice::error(($code >= 400 && $code < 500) ? $code : 400, $e);
}




