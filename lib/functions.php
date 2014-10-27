<?php

// detect debug
define('__DEBUG__', $_SERVER['_'] != $_SERVER['PHP_SELF']);

// register autoloader
spl_autoload_register(function ($class) {
    $file = '/3rd/' . str_replace(array('_', '\\'), '/', $class) . '.php';

    include_once __DIR__ . $file;
});

// handle exception
set_exception_handler(function (Exception $e) {
    console('error', $e->getMessage());
    exit(1);
});

/**
 * print string to console
 * 
 * @param string $type 
 * @param string $str 
 */
function console($type, $str) {
    $colors = [
        'info'      =>  '33',
        'done'      =>  '32',
        'error'     =>  '31',
        'debug'     =>  '36'
    ];

    $color = isset($colors[$type]) ? $colors[$type] : '37';

    $args = array_slice(func_get_args(), 2);
    array_unshift($args, $str);
    $str = call_user_func_array('sprintf', $args);

    echo "\033[{$color};1m[" . $type . "]\t\033[37;0m {$str}\n";
}

/**
 * trigger a fatal error 
 * 
 * @param string $str
 * @throws Exception
 */
function fatal($str) {
    $args = func_get_args();
    throw new Exception(call_user_func_array('sprintf', $args));
}

// init global vars
global $workflow, $context;
$workflow = [];
$context = new stdClass();

/**
 * get current caller file 
 * 
 * @return string
 */
function get_current_namespace() {
    static $file;

    $traces = debug_backtrace(!DEBUG_BACKTRACE_IGNORE_ARGS & !DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
    $current = array_pop($traces);

    $file = isset($current['file']) ? $current['file'] : $file;

    return pathinfo($file, PATHINFO_FILENAME);
}

/**
 * get a dir recursive iterator  
 * 
 * @param string $dir 
 * @return RecursiveIteratorIterator
 */
function get_all_files($dir) {
    return new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,
        FilesystemIterator::KEY_AS_FILENAME
        | FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS));
}

/**
 * add workflow  
 * 
 * @param string $name 
 * @param mixed $func 
 */
function add_workflow($name, $func) {
    global $workflow;

    $ns = get_current_namespace();
    $workflow[$ns . '.' . $name] = $func;
}

/**
 * @param $name
 * @return mixed
 */
function do_workflow($name) {
    global $workflow, $context;
    
    $args = func_get_args();
    array_shift($args);

    $parts = explode('.', $name, 2);
    if (2 == count($parts)) {
        list ($ns) = $parts;
    } else {
        $ns = get_current_namespace();
        $name = $ns . '.' . $name;
    }

    require_once __DIR__ . '/../workflow/' . $ns . '.php';

    if (!isset($workflow[$name])) {
        fatal('can not find workflow "%s"', $name);
    }

    $desc = implode(', ', array_map(function ($arg) {
        return is_string($arg) ? mb_strimwidth(
            str_replace(["\r", "\n"], '', $arg)
            , 0, 10, '...', 'UTF-8') : '...';
    }, $args));

    console('debug', '%s%s', $name, empty($desc) ? '' : ': ' . $desc);
    return call_user_func_array($workflow[$name], $args);
}

