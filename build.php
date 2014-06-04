<?php
/**
 * build.php - logecho
 * 
 * @author joyqi
 */

if (file_exists('./logecho')) {
    unlink('./logecho');
}

$phar = new Phar(__DIR__ . '/logecho.phar',
    FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, 'logecho.phar');
$phar->startBuffering();
$phar->buildFromDirectory(__DIR__, '/\.php$/');
$phar->buildFromDirectory(__DIR__, '/\.twig$/');
$phar->buildFromDirectory(__DIR__, '/\.css$/');
$phar->buildFromDirectory(__DIR__, '/\.md$/');
$phar->buildFromDirectory(__DIR__, '/\.yaml$/');
$phar->delete('build.php');
$phar->setStub('#!/usr/bin/env php
<?php
Phar::mapPhar("logecho.phar");
include "phar://logecho.phar/main.php";
__HALT_COMPILER();
?>');
$phar->stopBuffering();
rename('./logecho.phar', './logecho');
chmod('./logecho', 0755);
