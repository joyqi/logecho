<?php
/**
 * server.php - logecho
 * 
 * @author joyqi
 */

$dir = '{document-root}';
$build = '{build-command}';

if (!is_dir($dir)) {
    exit;
}

$ignore = rtrim($dir, '/') . '/_target';
$temp = sys_get_temp_dir() . '/lm-' . md5(realpath($dir));

function check($dir, $ignore) {
    $result = '';
    $items = new \DirectoryIterator($dir);

    foreach ($items as $item) {
        if ($item->isDot()) {
            continue;
        }

        $path = $item->getPathname();

        if (0 === strpos(basename($path), '.')) {
            continue;
        }

        if ($item->isFile() && 0 !== strpos($path, $ignore . '/')) {
            $result .= md5_file($path);
        } else if ($item->isDir() && rtrim($path, '/') != $ignore) {
            $result .= check($path, $ignore);
        }
    }

    return md5($result);
}

$md5 = check($dir, $ignore);
$old = file_exists($temp) ? file_get_contents($temp) : '';

if ($md5 != $old) {
    exec($build);
    file_put_contents($temp, $md5);
}

return false;
