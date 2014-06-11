<?php
/**
 * Watch.php - logecho
 * 
 * @author joyqi
 */

namespace LE;


class Watch
{
    /**
     * @var string
     */
    private $_dir;

    /**
     * @var string
     */
    private $_temp;

    /**
     * @var string
     */
    private $_ignore;

    /**
     * @param string $dir
     * @throws \Exception
     */
    public function __construct($dir)
    {
        $this->_dir = $dir;

        if (!is_dir($dir)) {
            throw new \Exception('Root directory is not exists: ' . $dir);
        }

        $this->_ignore = rtrim($dir, '/') . '/_target';
        $this->_temp = sys_get_temp_dir() . '/lm-' . md5(realpath($dir));
    }

    /**
     * @param $dir
     * @return string
     */
    private function check($dir)
    {
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

            if ($item->isFile() && 0 !== strpos($path, $this->_ignore . '/')) {
                $result .= md5_file($path);
            } else if ($item->isDir() && rtrim($path, '/') != $this->_ignore) {
                $result .= $this->check($path, $this->_ignore);
            }
        }

        return md5($result);
    }

    /**
     * @param $callback
     */
    public function watch($callback)
    {
        info('Watching ' . $this->_dir . ' ...');

        while (true) {
            $md5 = $this->check($this->_dir);
            $old = file_exists($this->_temp) ? file_get_contents($this->_temp) : '';

            if ($md5 != $old) {
                info('[' . date('c') . '] Files changed');
                $callback();
                file_put_contents($this->_temp, $md5);
            }

            sleep(1);
        }
    }
}
