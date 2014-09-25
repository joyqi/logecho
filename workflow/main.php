<?php
/**
 * main.php - logecho-main
 * 
 * @author joyqi
 */

// run
add_workflow('run', function () use ($context) {
    if (version_compare(PHP_VERSION, '5.4.0', '<')) {
        fatal('php version must be greater than 5.4.0, you have %s', PHP_VERSION);
    }

    do_workflow('read_opt');
});

// read_opt
add_workflow('read_opt', function () use ($context) {
    global $argv;

    $opts = [
        'init'  => 'init a blog directory using example config',
        'build' => 'build contents to _target directory',
        'sync'  => 'sync _target by using your sync config',
        'serve' => 'start a http server to watch your site',
        'watch' => '',
        'help'  => 'help documents',
        'update'=> 'update logecho to latest version',
        'import'=> 'import data from other blogging platform which is using xmlrpc'
    ];

    if (count($argv) > 0 && $argv[0] == $_SERVER['PHP_SELF']) {
        array_shift($argv);
    }

    if (count($argv) == 0) {
        $argv[] = 'help';
    }

    $help = function () use ($opts) {
        foreach ($opts as $name => $words) {
            echo "{$name}\t{$words}\n";
        }
    };

    $name = array_shift($argv);
    if (!isset($opts[$name])) {
        console('error', 'can not handle %s command, please use the following commands', $name);
        $help();
        exit(1);
    }

    if ('help' == $name) {
        $help();
    } else {
        if ($name != 'update') {
            if (count($argv) < 1) {
                fatal('a blog directory argument is required');
            }

            list ($dir) = $argv;
            if (!is_dir($dir)) {
                fatal('blog directory "%s" is not exists', $dir);
            }

            $context->dir = rtrim($dir, '/') . '/';

            if ($name != 'init') {
                do_workflow('read_config');
            }
        }

        array_unshift($argv, $name);
        call_user_func_array('do_workflow', $argv);

        console('done', $name);
    }
});

// read config
add_workflow('read_config', function () use ($context) {
    $file = $context->dir . 'config.yaml';
    if (!file_exists($file)) {
        fatal('can not find config file "%s"', $file);
    }

    $config = Spyc::YAMLLoad($file);
    if (!$config) {
        fatal('config file is not a valid yaml file');
    }

    $context->config = $config;
});

// run command
add_workflow('run_command', function ($type) use ($context) {
    $pwd = [
        '@HOME'     =>  $context->dir,
        '@TARGET'   =>  $context->dir . '/_target',
        '@THEME'    =>  $context->dir . '/_theme'
    ];

    if (!isset($context->config[$type]) || !is_array($context->config[$type])) {
        return;
    }

    foreach ($context->config[$type] as $command) {
        $command = str_replace(array_keys($pwd), array_values($pwd), $command);
        console('info', $command);

        passthru($command, $return);
        if ($return) {
            fatal('command interrupted');
        }
    }
});

// build all
add_workflow('build', function () use ($context) {
    do_workflow('compile.init');
    do_workflow('compile.compile');
    do_workflow('run_command', 'build');
});

// init
add_workflow('init', function () use ($context) {
    if (file_exists($context->dir . 'config.yaml')) {
        $confirm = readline('target dir is not empty, continue? (Y/n) ');

        if (strtolower($confirm) != 'y') {
            exit;
        }
    }

    $dir = __DIR__ . '/../sample';
    $offset = strlen($dir);

    $files = $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,
                FilesystemIterator::KEY_AS_PATHNAME
                | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = $file->getPathname();
        $file = $file->getFilename();

        if ($file[0] == '.') {
            continue;
        }

        $original = substr($path, $offset);
        $target = $context->dir . $original;
        $dir = dirname($target);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        copy($path, $target);
    }
});

// sync
add_workflow('sync', function () {
    do_workflow('run_command', 'sync');
});


// sync
add_workflow('serve', function () use ($context) {
    $cmd = __DEBUG__ ? $_SERVER['_'] . ' ' . $_SERVER['PHP_SELF'] : $_SERVER['PHP_SELF'];

    $proc = proc_open($cmd . ' watch ' . $context->dir, [
        0   =>  ['pipe', 'r'],
        1   =>  ['pipe', 'w'],
        2   =>  ['file', sys_get_temp_dir() . '/logecho-error.log', 'a']
    ], $pipes, getcwd());
    stream_set_blocking($pipes[0], 0);
    stream_set_blocking($pipes[1], 0);

    $target = $context->dir . '_target';

    console('info', 'Listening on localhost:7000');
    console('info', 'Document root is %s', $target);
    console('info', 'Press Ctrl-C to quit');
    exec('/usr/bin/env php -S localhost:7000 -t ' . $target);
});

// watch
add_workflow('watch', function () use ($context) {
    $lastSum = '';

    while (true) {
        // get sources
        $sources = [];
        $sum = '';

        foreach ($context->config['blocks'] as $type => $block) {
            if (!isset($block['source']) || !is_string($block['source'])) {
                continue;
            }

            $source = trim($block['source'], '/');
            $source = empty($source) ? '/' : '/' . $source . '/';

            $sources[] = preg_quote($source, '/');
        }

        if (!empty($sources)) {
            $sources[] = "\/_theme\/";
            $regex = "/^" . preg_quote(rtrim($context->dir, '/'))
                . "(" . implode('|', $sources) . ")/";

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($context->dir,
                FilesystemIterator::KEY_AS_PATHNAME
                | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $path = $file->getPathname();
                $file = $file->getFilename();

                if (!preg_match($regex, $path) || $file[0] == '.') {
                    continue;
                }

                $sum .= md5_file($path);
            }

            $sum = md5($sum . md5_file($context->dir . 'config.yaml'));
            if ($lastSum != $sum) {
                try {
                    do_workflow('read_config');
                    do_workflow('build');
                } catch (Exception $e) {
                    console('error', $e->getMessage());
                }

                $lastSum = $sum;
            }
        }

        sleep(1);
    }
});

// import
add_workflow('import', function () use ($context) {
    do_workflow('import.init');
});
