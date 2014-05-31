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

define('VERSION', '1.0.0');

$options = getopt('hsd:p:', [
    'help',
    'sync',
    'directory:',
    'post:'
]);

function info($str) {
    echo "\033[33;1m" . preg_replace("/\/+/", '/', $str) . "\033[37;0m\n";
}

if (isset($options['h']) || isset($options['help'])) {
    echo 'LOGECHO ' . VERSION . '
Copyright (c) 2013-' . date('Y') . ' Joyqi (http://70.io)
usage: logecho [-s] [-d working-directory] [-p specific-post]
';
    exit;
}

$dir = getcwd();
if (!empty($options['d'])) {
    $dir = $options['d'];
} else if (!empty($options['directory'])) {
    $dir = $options['directory'];
}

$post = NULL;
if (!empty($options['p'])) {
    $post = $options['p'];
} else if (!empty($options['post'])) {
    $post = $options['post'];
}

try {
    $compiler = new \LE\Compiler($dir);
    if (preg_match("/^([_a-z0-9]+):(.+)$/i", $post, $matches)) {
        $compiler->compileSpecific($matches[1], $matches[2]);
    } else {
        $compiler->compileAll();
    }
} catch (Exception $e) {
    echo "\033[31;1m" . $e->getMessage() . "\033[37;0m\n";
}
