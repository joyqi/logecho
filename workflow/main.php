<?php
/**
 * main.php - logecho-main
 * 
 * @author joyqi
 */

// run
le_add_workflow('run', function () use ($context) {
    if (version_compare(PHP_VERSION, '5.4.0', '<')) {
        le_fatal('php version must be greater than 5.4.0, you have %s', PHP_VERSION);
    }

    le_do_workflow('read_opt');
});

// read_opt
le_add_workflow('read_opt', function () use ($context) {
    global $argv;

    $opts = [
        'init'      => 'Create an empty Logecho directory',
        'build'     => 'Build contents to _target directory',
        'sync'      => 'Sync _target by using your sync config',
        'serve'     => 'Start a http server to watch your site',
        'watch'     => '',
        'archive'   => '',
        'help'      => 'Show help documents',
        'import'    => 'Import data from other blogging platform which is using xmlrpc'
    ];

    if (count($argv) > 0 && $argv[0] == $_SERVER['PHP_SELF']) {
        array_shift($argv);
    }

    if (count($argv) == 0) {
        $argv[] = 'help';
    }

    $help = function () use ($opts) {
        echo "usage: logecho <command> <path>\n\n";
        echo "Here are the most commonly used logecho commands:\n";

        foreach ($opts as $name => $words) {
            $name = str_pad($name, 12, ' ', STR_PAD_RIGHT);
            echo "  {$name}{$words}\n";
        }
    };

    $name = array_shift($argv);
    if (!isset($opts[$name])) {
        le_console('error', 'can not handle %s command, please use the following commands', $name);
        $help();
        exit(1);
    }

    if ('help' == $name) {
        $help();
    } else {
        if ($name != 'update') {
            if (count($argv) < 1) {
                le_fatal('a blog directory is required');
            }

            list ($dir) = $argv;
            if (!is_dir($dir)) {
                le_fatal('blog directory "%s" is not exists', $dir);
            }

            $context->dir = rtrim($dir, '/') . '/';
            $context->cmd = __DEBUG__ ? $_SERVER['_'] . ' ' . $_SERVER['PHP_SELF'] : $_SERVER['PHP_SELF'];

            if ($name != 'init') {
                le_do_workflow('read_config');
            }
        }

        array_unshift($argv, $name);
        call_user_func_array('le_do_workflow', $argv);

        le_console('done', $name);
    }
});

// read config
le_add_workflow('read_config', function () use ($context) {
    $file = $context->dir . 'config.yaml';
    if (!file_exists($file)) {
        le_fatal('can not find config file "%s"', $file);
    }

    $config = Spyc::YAMLLoad($file);
    if (!$config) {
        le_fatal('config file is not a valid yaml file');
    }

    $context->config = $config;
});

// sync directory
le_add_workflow('sync', function ($source = null, $target = null) use ($context) {
    $url = $context->config['sync'];
    if (empty($url)) {
        le_fatal('Missing sync url configure');
    }
    
    le_do_workflow('build');
    $source = $context->dir . '_target';
    $img = tempnam(sys_get_temp_dir(), 'le');
    $data = '';
    
    // compress all files
    $files = le_get_all_files($source);
    $offset = strlen($source);
    $first = true;

    foreach ($files as $file => $path) {
        if ($file[0] == '.') {
            continue;
        }

        $original = substr($path, $offset);
        $data .= ($first ? '' : "\n") . $original . ' ' . base64_encode(file_get_contents($path));
        $first = false;
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL             =>  $url,
        CURLOPT_RETURNTRANSFER  =>  true,
        CURLOPT_HEADER          =>  false,
        CURLOPT_SSL_VERIFYPEER  =>  false,
        CURLOPT_SSL_VERIFYHOST  =>  false,
        CURLOPT_TIMEOUT         =>  20,
        CURLOPT_POST            =>  true,
        CURLOPT_POSTFIELDS      =>  $data
    ]);

    $response = curl_exec($ch);
    if (false === $response) {
        le_fatal(curl_error($ch));
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (200 != $code) {
        le_fatal($code);
    }
});

// build all
le_add_workflow('build', function () use ($context) {
    le_do_workflow('compile.init');
    le_do_workflow('compile.compile');

    $source = $context->dir . '_public';
    $target = $context->dir . '_target/public';

    // delete all files in target
    $files = le_get_all_files($target);
    $dirs = [];

    foreach ($files as $file => $path) {
        $dir = dirname($path);

        // do not remove root directory
        if (!in_array($dir, $dirs) && realpath($dir) != realpath($target)) {
            $dirs[] = $dir;
        }

        // remove all files first
        if (!unlink($path)) {
            le_fatal('can not unlink file %s, permission denied', $path);
        }
    }

    // remove all dirs
    $dirs = array_reverse($dirs);
    foreach ($dirs as $dir) {
        if (!rmdir($dir)) {
            le_fatal('can not rm directory %s, permission denied', $dir);
        }
    }

    // copy all files
    $files = le_get_all_files($source);
    $offset = strlen($source);

    foreach ($files as $file => $path) {
        if ($file[0] == '.') {
            continue;
        }

        $original = substr($path, $offset);
        $current = $target . '/' . $original;
        $dir = dirname($current);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                le_fatal('can not make directory %s, permission denied', $dir);
            }
        }

        copy($path, $current);
    }
});

// init
le_add_workflow('init', function () use ($context) {
    if (file_exists($context->dir . 'config.yaml')) {
        $confirm = readline('target dir is not empty, continue? (Y/n) ');

        if (strtolower($confirm) != 'y') {
            exit;
        }
    }

    $dir = __DIR__ . '/../sample';
    $offset = strlen($dir);

    $files = le_get_all_files($dir);

    foreach ($files as $file => $path) {
        if ($file[0] == '.') {
            continue;
        }

        $original = substr($path, $offset);
        $target = $context->dir . $original;
        $dir = dirname($target);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                le_fatal('can not make directory %s, permission denied', $dir);
            }
        }

        copy($path, $target);
    }
});

// serve
le_add_workflow('serve', function () use ($context) {
    $target = $context->dir . '_target';
    if (!is_dir($target)) {
        le_console('info', 'building target files, please wait ...');
        exec($context->cmd . ' build ' . $context->dir);
    }

    $proc = proc_open($context->cmd . ' watch ' . $context->dir, [
        0   =>  ['pipe', 'r'],
        1   =>  ['pipe', 'w'],
        2   =>  ['file', sys_get_temp_dir() . '/logecho-error.log', 'a']
    ], $pipes, getcwd());
    stream_set_blocking($pipes[0], 0);
    stream_set_blocking($pipes[1], 0);

    le_console('info', 'Listening on localhost:7000');
    le_console('info', 'Document root is %s', $target);
    le_console('info', 'Press Ctrl-C to quit');
    exec('/usr/bin/env php -S localhost:7000 -t ' . $target);
});

// archive
le_add_workflow('archive', function () use ($context) {
    // init complier
    le_do_workflow('compile.init');
    
    foreach ($context->config['blocks'] as $type => $block) {
        if (!isset($block['source']) || !is_string($block['source'])) {
            continue;
        }

        $source = trim($block['source'], '/');
        $files = glob($context->dir . '/' . $source . '/*.md');
        $list = [];

        foreach ($files as $file) {
            list ($metas) = le_do_workflow('compile.get_metas', $file);
            $date = $metas['date'];
            
            $list[$file] = $date;
        }

        asort($list);
        $index = 1;

        foreach ($list as $file => $date) {
            $info = pathinfo($file);
            $fileName = $info['filename'];
            $dir = $info['dirname'];

            if (preg_match("/^[0-9]{4}\.(.+)$/", $fileName, $matches)) {
                $fileName = $matches[1];
            }

            $source = realpath($file);
            $target = rtrim($dir, '/') . '/' . str_pad($index, 4, '0', STR_PAD_LEFT) . '.' . $fileName . '.md';

            if ($source != $target && !file_exists($target)) {
                le_console('info', basename($source) . ' => ' . basename($target));
                rename($source, $target);
            }

            $index ++;
        }
    }
});

// watch
le_add_workflow('watch', function () use ($context) {
    $lastSum = '';

    while (true) {
        // get sources
        $sources = ["\/_theme\/", "\/_public\/"];
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
            $regex = "/^" . preg_quote(rtrim($context->dir, '/'))
                . "(" . implode('|', $sources) . ")/";

            $files = le_get_all_files($context->dir);

            foreach ($files as $file => $path) {
                if (!preg_match($regex, $path) || $file[0] == '.') {
                    continue;
                }

                $sum .= md5_file($path);
            }

            $sum = md5($sum . md5_file($context->dir . 'config.yaml'));
            if ($lastSum != $sum) {
                exec($context->cmd . ' build ' . $context->dir);
                $lastSum = $sum;
            }
        }

        sleep(1);
    }
});

// import
le_add_workflow('import', function () use ($context) {
    le_do_workflow('import.init');
});
