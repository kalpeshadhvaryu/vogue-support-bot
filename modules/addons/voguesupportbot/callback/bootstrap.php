<?php

declare(strict_types=1);

$init = dirname(__DIR__, 4) . '/init.php';
if (!is_file($init)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"whmcs_bootstrap_missing","reply":"The assistant is not installed correctly."}';
    exit;
}

require_once $init;
require_once dirname(__DIR__) . '/lib/Autoload.php';
