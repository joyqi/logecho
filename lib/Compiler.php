<?php
/**
 * Compiler.php - logecho
 * 
 * @author joyqi
 */

namespace LE;

/**
 * Class Compiler
 * @package LE
 */
class Compiler
{
    /**
     * @var string
     */
    private $_dir = '';

    /**
     * @var array
     */
    private $_config = [];

    /**
     * @var array
     */
    private $_metas = [];

    /**
     * @var \MarkdownExtraExtended
     */
    private $_parser;

    /**
     * @var \Twig_Environment
     */
    private $_template;

    /**
     * @var array
     */
    private $_data = [];

    /**
     * @var array
     */
    private $_index = [];

    /**
     * cached meta posts
     *
     * @var array
     */
    private $_cached = [];

    /**
     * construct with config file
     *
     * @param $dir
     * @throws \Exception
     */
    public function __construct($dir)
    {
        global $twig;

        if (!is_dir($dir)) {
            throw new \Exception('Directory is not exists: ' . $dir);
        }

        $this->_parser = new \MarkdownExtraExtended();
        $this->_dir = $dir . '/';

        $loader = new \Twig_Loader_Filesystem($this->_dir . '_theme');
        $this->_template = new \Twig_Environment($loader, [
            'autoescape'    =>  false
        ]);
        $twig = $this->_template;

        if (file_exists($dir . '/functions.php')) {
            require_once $dir . '/functions.php';
        }

        require_once 'phar://logecho.phar/functions.php';

        $this->readConfig();
        $this->readMetas();
        $this->readGlobals();
    }

    /**
     * @throws \Exception
     */
    private function readConfig()
    {
        $file = $this->_dir . 'config.yaml';
        if (!file_exists($file)) {
            throw new \Exception('Config file is not exists: ' . $file);
        }

        $config = \Spyc::YAMLLoad($file);
        if (!$config) {
            throw new \Exception('Config file is not a valid yaml file');
        }

        $this->_config = $config;
    }

    /**
     * read metas from file
     */
    private function readMetas()
    {
        info('Read metas and index');
        // read metas from post
        foreach ($this->_config['blocks'] as $type => $block) {
            if (isset($block['source']) && is_string($block['source'])) {
                $files = glob($this->_dir . $block['source'] . '/*.md');
                foreach ($files as $file) {
                    $metas = $this->getMetas($file);
                    $term = explode('.', basename($file))[0];

                    $this->_index[$type][$term] = $metas['date'];

                    // get metas group from file
                    foreach ($metas as $key => $relates) {
                        if (!is_array($relates)) {
                            continue;
                        }

                        // link meta and post
                        foreach ($relates as $relate) {
                            $this->_metas[$key][$relate][] = [$type, $term];
                        }
                    }
                }
            }
        }

        $this->_index = array_map(function ($index) {
            arsort($index);
            return array_keys($index);
        }, $this->_index);

        if (!empty($this->_metas['archive'])) {
            krsort($this->_metas['archive']);
        }

        foreach ($this->_metas as $type => $relates) {
            foreach ($relates as $key => $relate) {
                usort($relate, function ($a, $b) {
                    $x = array_search($a[1], $this->_index[$a[0]]);
                    $y = array_search($b[1], $this->_index[$b[0]]);

                    return $x > $y ? 1 : -1;
                });

                $this->_metas[$type][$key] = $relate;
            }
        }
    }

    /**
     * read globals from config and metas
     */
    private function readGlobals()
    {
        if (isset($this->_config['globals'])) {
            $this->_data = $this->_config['globals'];
        }

        $this->_data['metas'] = [];
        foreach ($this->_config['blocks'] as $key => $val) {
            if (isset($val['source']) && is_string($val['source'])) {
                continue;
            }

            $this->_data['metas'][$key] = [];

            $path = '/' . trim($val['target'], '/');
            $url = '/' == substr($val['target'], -1)
                ? $path . '/%s.' . (isset($val['ext']) ? $val['ext'] : 'html') : $path . '#' . $key . '-%s';

            if (isset($val['source']) && is_array($val['source'])) {
                foreach ($val['source'] as $slug => $name) {
                    $this->_data['metas'][$key][$slug] = [
                        'slug'  =>  $slug,
                        'name'  =>  $name,
                        'url'   =>  sprintf($url, urlencode($slug)),
                        'count' =>  isset($this->_metas[$key][$slug]) ? count($this->_metas[$key][$slug]) : 0
                    ];
                }
            } else if (!isset($val['source']) && !empty($this->_metas[$key])) {
                foreach ($this->_metas[$key] as $slug => $terms) {
                    $this->_data['metas'][$key][$slug] = [
                        'slug'  =>  $slug,
                        'name'  =>  $slug,
                        'url'   =>  sprintf($url, urlencode($slug)),
                        'count' =>  count($terms)
                    ];
                }
            }
        }

        foreach ($this->_data as $key => $val) {
            $this->_template->addGlobal($key, $val);
        }
    }

    /**
     * read metas from string
     *
     * @param $file
     * @param $str
     * @return array
     */
    private function getMetas($file, &$str = '')
    {
        $str = ltrim(file_get_contents($file));
        $metas = [];

        $lines = explode("\n", $str);
        foreach ($lines as $index => $line) {
            if (preg_match("/^@([_a-z0-9-]+):(.+)$/i", $line, $matches)) {
                $key = strtolower($matches[1]);
                if ('date' == $key) {
                    $metas['date'] = strtotime(trim($matches[2]));
                    continue;
                } else if (!isset($this->_config['blocks'][$key])) {
                    $metas[$key] = trim($matches[2]);
                    continue;
                }

                // read block
                $block = $this->_config['blocks'][$key];
                $values = array_map('trim', explode(',', trim($matches[2])));

                foreach ($values as $value) {
                    if (isset($block['source'])) {
                        if (is_array($block['source'])) {
                            // specific by hash object
                            if (isset($block['source'][$value])) {
                                $metas[$key][] = $value;
                            } else if (in_array($value, $block['source'])) {
                                $metas[$key][] = array_search($value, $block['source']);
                            }
                        } else {
                            $file = $this->_dir . $block['source'] . '/' . $value . '.md';
                            if (file_exists($file)) {
                                $metas[$key][] = $value;
                            }
                        }
                    } else {
                        $metas[$key][] = $value;
                    }
                }
            } else {
                break;
            }
        }

        if (!isset($metas['date'])) {
            $metas['date'] = filemtime($file);
        }

        // special archive block
        if (!isset($metas['archive']) && isset($this->_config['blocks']['archive'])) {
            $metas['archive'][] = date('Y.m', $metas['date']);
        }
        $str = implode("\n", array_slice($lines, $index));
        return $metas;
    }

    /**
     * @param $type
     * @param $key
     * @return array
     */
    private function getPost($type, $key)
    {
        $block = $this->_config['blocks'][$type];
        $file = $this->_dir . $block['source'] . '/' . $key . '.md';
        $result = $this->getMetas($file, $text);

        // expand metas
        foreach ($result as $name => &$metas) {
            if (!is_array($metas)) {
                continue;
            }

            if (isset($this->_data['metas'][$name])) {
                $current = $this->_data['metas'][$name];
                $metas = array_map(function ($index) use ($current) {
                    return $current[$index];
                }, $metas);
            }
        }

        $result['type'] = $type;
        $result['id'] = $result['title'] = $key;
        $result['text'] = $text;
        $result['content'] = $this->_parser->transform($text);
        $result['ext'] = isset($block['ext']) ? $block['ext'] : 'html';
        if (!isset($result['slug'])) {
            $result['slug'] = $key;
        }
        $result['url'] = '/' . trim($block['target'], '/')
            . '/' . urlencode($result['slug']) . '.' . $result['ext'];

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' .
            '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/></head><body>'
            . $result['content'] . '</body></html>');
        $xpath = new \DOMXPath($dom);

        $items = $xpath->query('//h1|//h2');

        if ($items->length > 0) {
            $result['title'] = str_replace(["\n", "\r", "\t"], '', $items->item(0)->nodeValue);
            $items->item(0)->parentNode->removeChild($items->item(0));
            $html = $dom->saveHTML($dom->documentElement);
            $start = strpos($html, '<body>');
            $stop = strrpos($html, '</body>');
            $result['content'] = substr($html, $start + 6, $stop - $start - 6);
        }

        return $result;
    }

    /**
     * @param $type
     * @param $key
     * @return array
     */
    private function getPostContext($type, $key)
    {
        $index = array_search($key, $this->_index[$type]);
        $result = [
            'prev'  =>  NULL,
            'next'  =>  NULL
        ];

        if ($index > 0) {
            $result['prev'] = $this->getPost($type, $this->_index[$type][$index - 1]);
        }

        if ($index < (count($this->_index[$type]) - 1)) {
            $result['next'] = $this->getPost($type, $this->_index[$type][$index + 1]);
        }

        return $result;
    }

    /**
     * @param $type
     * @param $key
     * @return array
     */
    private function getMetaPosts($type, $key)
    {
        $result = [];

        if (isset($this->_metas[$type][$key])) {
            foreach ($this->_metas[$type][$key] as $val) {
                list ($postType, $postKey) = $val;

                $index = $postType . ':' . $postKey;
                if (!isset($this->_cached[$index])) {
                    $this->_cached[$index] = $this->getPost($postType, $postKey);
                    unset($this->_cached[$index]['content']);
                }

                $result[$postType][] = $this->_cached[$index];
            }
        }

        foreach ($result as &$archive) {
            usort($archive, function ($a, $b) {
                $x = array_search($a['id'], $this->_index[$a['type']]);
                $y = array_search($b['id'], $this->_index[$b['type']]);

                return $x > $y ? 1 : -1;
            });
        }

        return $result;
    }

    /**
     * @param $template
     * @param $file
     * @param array $data
     * @throws \Exception
     */
    private function build($template, $file, array $data = [])
    {
        info("+ {$file}");
        $html = $this->_template->render($template, $data);

        $file = $this->_dir . '/_target/' . $file;
        $dir = dirname($file);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \Exception('Target directory is not exists: ' . $dir);
            }
        }

        file_put_contents($file, $html);
    }

    /**
     * @param $type
     * @param null $specific
     * @throws \Exception
     */
    private function compilePost($type, $specific = NULL)
    {
        $block = $this->_config['blocks'][$type];

        if (!isset($block['source']) || !is_string($block['source']) || empty($block['target'])) {
            throw new \Exception('Block not exists: ' . $type);
        }

        $files = glob($this->_dir . $block['source'] . '/*.md');
        $template = $type. '.twig';
        $target = $block['target'] . '/';

        if (isset($block['template'])) {
            $template = $block['template'];
        }

        foreach ($files as $file) {
            $key = explode('.', basename($file))[0];

            if (!empty($specific) && $key != $specific) {
                continue;
            }

            $post = array_merge($this->getPost($type, $key),
                $this->getPostContext($type, $key));
            $currentTemplate = isset($post['template']) ? $post['template'] : $template;

            $this->build($currentTemplate, $target . $post['slug'] . '.' . $post['ext'], [
                $type   =>  $post
            ]);
        }
    }

    /**
     * compile all posts
     */
    private function compilePosts()
    {
        foreach ($this->_config['blocks'] as $type => $val) {
            if (isset($val['source']) && is_string($val['source'])) {
                $this->compilePost($type);
            }
        }
    }

    /**
     * compile all metas
     */
    private function compileMetas()
    {
        $targets = [];

        foreach ($this->_config['blocks'] as $type => $val) {
            if (isset($val['source']) && is_string($val['source'])) {
                continue;
            }

            $target = isset($val['target']) ? $val['target']  : $type . '.html';
            $template = isset($val['template']) ? $val['template'] : $type . '.twig';

            if ('/' == substr($target, -1)) {
                if (!empty($this->_data['metas'][$type])) {
                    foreach ($this->_data['metas'][$type] as $key => $meta) {
                        $targets[$target . $key . '.html'][] = $type . ':' . $key;
                    }
                }
            } else {
                $targets[$target . ':' . $template][] = $type;
            }
        }

        foreach ($targets as $define => $links) {
            $data = [];
            $links = array_unique($links);
            list ($target, $template) = explode(':', $define);

            foreach ($links as $link) {
                $parts = explode(':', $link);
                $type = $parts[0];

                if (isset($parts[1])) {
                    $data[$type] = array_merge($this->_data['metas'][$type][$parts[1]],
                        $this->getMetaPosts($type, $parts[1]));
                } else {
                    if (!empty($this->_data['metas'][$type])) {
                        foreach ($this->_data['metas'][$type] as $key => $val) {
                            $data[$type][$key] = array_merge($val, $this->getMetaPosts($type, $key));
                        }
                    }
                }
            }

            $this->build($template, $target, $data);
        }
    }

    /**
     * compile index page
     */
    private function compileIndex()
    {
        $recent = [];

        foreach ($this->_config['blocks'] as $type => $val) {
            if (isset($val['source']) && is_string($val['source'])
                && isset($val['recent']) && isset($this->_index[$type])) {
                $posts = array_slice($this->_index[$type], 0, $val['recent']);

                foreach ($posts as $post) {
                    $recent[$type][] = $this->getPost($type, $post);
                }
            }
        }

        $this->build('index.twig', 'index.html', [
            'recent'    =>  $recent
        ]);
    }

    /**
     * @throws \Exception
     */
    private function generateFeeds()
    {
        if (!isset($this->_config['feeds'])) {
            return;
        }

        $config = $this->_config['feeds'];

        if (!isset($config['source']) || !isset($this->_index[$config['source']])) {
            return;
        }

        $config = array_merge([
            'title'         =>  isset($this->_data['title']) ? $this->_data['title'] : 'My Feeds',
            'description'   =>  isset($this->_data['description']) ? $this->_data['description'] : 'My Feeds Description',
            'recent'        =>  20,
            'target'        =>  'feeds.xml',
            'base'          =>  isset($this->_data['url']) ? $this->_data['url'] : '/'
        ], $config);

        $feedsUrl = rtrim($config['base'], '/') . '/' . ltrim($config['target'], '/');

        $feeds = new \Atom();
        $feeds->setBaseUrl($config['base']);
        $feeds->setFeedUrl($feedsUrl);
        $feeds->setTitle($config['title']);
        $feeds->setSubTitle($config['description']);

        $posts = array_slice($this->_index[$config['source']], 0, $config['recent']);
        foreach ($posts as $post) {
            $post = $this->getPost($config['source'], $post);
            $item = [
                'title'     =>  $post['title'],
                'link'      =>  $post['url'],
                'updated'   =>  $post['date'],
                'published' =>  $post['date'],
                'author'    =>  isset($config['author']) ? [
                        'name'  =>  $config['author'],
                        'url'   =>  $config['base']
                        ] : NULL,
                'content'   =>  $post['content']
            ];

            foreach ($this->_config['blocks'] as $type => $val) {
                if (isset($val['source']) && is_string($val['source'])) {
                    continue;
                }

                if ('archive' != $type && !empty($post[$type])) {
                    foreach ($post[$type] as $meta) {
                        $item['category'][] = [
                            'feeds_url' =>  $meta['url'],
                            'name'      =>  $meta['name']
                        ];
                    }
                }
            }

            $feeds->addItem($item);
        }

        info('Generate feeds');
        $target = $this->_dir . '_target/' . $config['target'];
        $targetDir = dirname($target);

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new \Exception('Feeds directory is not exists: ' . $targetDir);
            }
        }

        file_put_contents($target, $feeds->generate());
    }

    /**
     * compile all files
     */
    public function compile()
    {
        $this->compileIndex();
        $this->compileMetas();
        $this->compilePosts();
        $this->generateFeeds();

        echo "\033[32;1mFinish compile all\033[37;0m\n";
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->_config;
    }
}
