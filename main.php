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
    // run compiler
    $compiler = new \LE\Compiler($dir);
    $compiler->compile();

    // run build command
    $build = new \LE\Command($dir, $compiler->getConfig(), 'build');
    $build->run();

    return $compiler;
}

function get_input($word) {
    fwrite(STDOUT, "{$word}\n");
    return trim(fgets(STDIN));
}

set_exception_handler(function ($e) {
    echo "\033[31;1m" . $e->getMessage() . "\033[37;0m\n";
    exit(1);
});

define('VERSION', '1.0.0');

array_shift($argv);
$argv[0] = isset($argv[0]) ? $argv[0] : 'help';

$dirArg = 1;
if ('import' == $argv[0]) {
    $dirArg = 2;
}

$dir = isset($argv[$dirArg]) ? $argv[$dirArg] : getcwd();

switch ($argv[0]) {
    case 'build':
        build($dir);
        break;
    case 'sync':
        $compiler = build($dir);
        $sync = new \LE\Command($dir, $compiler->getConfig(), 'sync');
        $sync->run();
        break;
    case 'update':
        print_r(realpath($_SERVER['SCRIPT_FILENAME']));
        break;
    case 'import':
        if (empty($argv[1]) || !filter_var($argv[1], FILTER_VALIDATE_URL)) {
            throw new Exception('You must specific an url to import (eg. logecho import http://example.com ~/example)');
        }

        $url = $argv[1];
        $confirm = get_input("Import \"{$url}\" to \"{$dir}\"? (Y/n)");
        if ('Y' != $confirm) {
            exit;
        }

        new \LE\Import($url, $dir);
        break;
    case 'init':
        $init = new \LE\Command($dir, [
            'init'  =>  [
                'mkdir -p @THEME',
                'mkdir -p @HOME/posts'
            ]
        ], 'init');
        $init->run();

        info('Copy files ...');
        copy('phar://logecho.phar/sample/config.yaml', $dir . '/config.yaml');
        copy('phar://logecho.phar/sample/posts/hello-world.md', $dir . '/posts/hello-world.md');
        copy('phar://logecho.phar/sample/_theme/archives.twig', $dir . '/_theme/archives.twig');
        copy('phar://logecho.phar/sample/_theme/footer.twig', $dir . '/_theme/footer.twig');
        copy('phar://logecho.phar/sample/_theme/header.twig', $dir . '/_theme/header.twig');
        copy('phar://logecho.phar/sample/_theme/index.twig', $dir . '/_theme/index.twig');
        copy('phar://logecho.phar/sample/_theme/post.twig', $dir . '/_theme/post.twig');
        copy('phar://logecho.phar/sample/_theme/style.css', $dir . '/_theme/style.css');
        info('Finished init ' . $dir);

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
usage: logecho (build|sync|serve|help|update|import) [your-working-directory]
';
        break;
}
