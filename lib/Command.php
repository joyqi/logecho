<?php
/**
 * Command.php - logecho
 * 
 * @author joyqi
 */

namespace LE;

/**
 * Class Command
 * @package LE
 */
class Command
{
    /**
     * @var array
     */
    private $_pwd = [
        '@HOME'     =>  './',
        '@TARGET'   =>  './_target',
        '@THEME'    =>  './_theme'
    ];

    /**
     * @var array
     */
    private $_commands = [];

    /**
     * @param string $dir
     * @param array $config
     * @param $type
     */
    public function __construct($dir, array $config, $type)
    {
        $this->_pwd = [
            '@HOME'     =>  $dir,
            '@TARGET'   =>  rtrim($dir, '/') . '/_target',
            '@THEME'    =>  rtrim($dir, '/') . '/_theme'
        ];

        if (isset($config[$type]) && is_array($config[$type])) {
            $this->_commands = $config[$type];
            info('Run ' . $type);
        }
    }

    /**
     * run all commands
     */
    public function run()
    {
        foreach ($this->_commands as $command) {
            $command = str_replace(array_keys($this->_pwd), array_values($this->_pwd), $command);
            echo "\033[34;1m{$command}\033[37;0m\n";
            echo "\033[33;1m";
            passthru($command);
            echo "\033[37;0m";
        }
    }
}
