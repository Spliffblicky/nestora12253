<?php
declare(strict_types=1);

/**
 * Loads backend/.env and exposes cfg().
 * Nothing secret lives in the code: copy .env.example to .env and edit it.
 */

define('APP_ROOT', dirname(__DIR__));

function load_env(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $GLOBALS['__env'][trim($k)] = $v;
    }
}

function cfg(string $key, $default = null)
{
    $v = $GLOBALS['__env'][$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

$GLOBALS['__env'] = [];
load_env(APP_ROOT . '/.env');
date_default_timezone_set('UTC');   // Ghana is UTC+0; everything is stored in UTC
