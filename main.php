<?php
/**
 * main.php - logecho
 * 
 * @author joyqi
 */

spl_autoload_register(function ($class) {
    if (0 === strpos(ltrim($class, '\\'), 'LE\\')) {
        $class = substr(ltrim($class, '\\'), 3);
        $path = 'lib';
    } else {
        $path = '3rd';
    }

    $file = $path . '/' . str_replace(array('_', '\\'), '/', $class) . '.php';
    include_once 'phar://logecho.phar/' . $file;
});

/**
 * print info
 *
 * @param $str
 */
function info($str) {
    echo "\033[33;1m" . preg_replace("/\/+/", '/', $str) . "\033[37;0m\n";
}

/**
 * build working directory
 *
 * @param $dir
 * @return \LE\Compiler
 */
function build($dir) {
    if (file_exists($dir . '/filters.php')) {
        require_once $dir . '/filters.php';
    }

    require_once 'phar://logecho.phar/filters.php';

    try {
        // run compiler
        $compiler = new \LE\Compiler($dir);
        $compiler->compile();

        // run build command
        $build = new \LE\Command($dir, $compiler->getConfig(), 'build');
        $build->run();
    } catch (Exception $e) {
        echo "\033[31;1m" . $e->getMessage() . "\033[37;0m\n";
    }

    return $compiler;
}

define('VERSION', '1.0.0');

array_shift($argv);
$argv[0] = isset($argv[0]) ? $argv[0] : 'help';
$dir = isset($argv[1]) ? $argv[1] : getcwd();

switch ($argv[0]) {
    case 'build':
        build($dir);
        break;
    case 'sync':
        $compiler = build($dir);
        $sync = new \LE\Command($dir, $compiler->getConfig(), 'sync');
        $sync->run();
        break;
    case 'serve':
        build($dir);

        $target = rtrim($dir, '/') . '/_target';
        info('Listening on localhost:7000');
        info('Document root is ' . $target);
        info('Press Ctrl-C to quit');
        exec('/usr/bin/env php -S localhost:7000 -t ' . $target);
        break;
    case 'help':
    default:
        echo 'LOGECHO ' . VERSION . '
Copyright (c) 2013-' . date('Y') . ' Logecho (http://logecho.com)
usage: logecho (build|sync|serve|help) /your-working-directory
';
        break;
}
