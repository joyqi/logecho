<?php

// init blog compiler
add_workflow('init', function () use ($context) {

    // init all variables
    $context->indexConfig = [];
    $context->metas = [];
    $context->data = [];
    $context->index = [];
    $context->cached = [];
    $context->sitemap = [];

    if (isset($context->config['blocks']['index'])) {
        $context->indexConfig = $context->config['blocks']['index'];
        unset($context->config['blocks']['index']);
    }

    // init twig
    $loader = new Twig_Loader_Filesystem($context->dir . '_theme');
    $context->template = new Twig_Environment($loader, [
        'autoescape'    =>  false
    ]);

    do_workflow('init_twig', $context->template);

    // read config
    do_workflow('read_metas');
    do_workflow('read_globals');
});

// load extension
add_workflow('init_twig', function (Twig_Environment $twig) use ($context) {
    $twig->addFilter(new Twig_SimpleFilter('more', function ($str, $limit = 0) {
        if ($limit > 0) {
            $str = strip_tags($str);
            return mb_strlen($str, 'UTF-8') > $limit
                ? mb_substr($str, 0, $limit, 'UTF-8') . ' ...' : $str;
        }

        $parts = preg_split("/<!--\s*more\s*-->/is", $str);
        return count($parts) > 1 ? $parts[0] . '<p>...</p>' : $str;
    }));
});

// read metas
add_workflow('read_metas', function () use ($context) {
    // read metas from post
    foreach ($context->config['blocks'] as $type => $block) {
        if (isset($block['source']) && is_string($block['source'])) {
            $files = glob($context->dir . $block['source'] . '/*.md');

            foreach ($files as $file) {
                list ($metas) = do_workflow('get_metas', $file);
                $term = pathinfo($file, PATHINFO_FILENAME);

                $context->index[$type][$term] = $metas['date'];

                // get metas group from file
                foreach ($metas as $key => $relates) {
                    if (!is_array($relates)) {
                        continue;
                    }

                    // link meta and post
                    foreach ($relates as $relate) {
                        $context->metas[$key][$relate][] = [$type, $term];
                    }
                }
            }
        }
    }

    $context->index = array_map(function ($index) {
        arsort($index);
        return array_keys($index);
    }, $context->index);

    if (!empty($context->metas['archive'])) {
        krsort($context->metas['archive']);
    }

    foreach ($context->metas as $type => $relates) {
        foreach ($relates as $key => $relate) {
            usort($relate, function ($a, $b) use ($context) {
                $x = array_search($a[1], $context->index[$a[0]]);
                $y = array_search($b[1], $context->index[$b[0]]);

                return $x > $y ? 1 : -1;
            });

            $context->metas[$type][$key] = $relate;
        }
    }
});

// read globals
add_workflow('read_globals', function () use ($context) {
    if (isset($context->config['globals'])) {
        $context->data = $context->config['globals'];
    }

    $context->data['metas'] = [];
    foreach ($context->config['blocks'] as $key => $val) {
        if (isset($val['source']) && is_string($val['source'])) {
            continue;
        }

        $context->data['metas'][$key] = [];

        $path = '/' . trim($val['target'], '/');
        $url = '/' == substr($val['target'], -1)
            ? $path . '/%s.' . (isset($val['ext']) ? $val['ext'] : 'html') : $path . '#' . $key . '-%s';

        if (isset($val['source']) && is_array($val['source'])) {
            foreach ($val['source'] as $slug => $name) {
                $context->data['metas'][$key][$slug] = [
                    'slug'  =>  $slug,
                    'name'  =>  $name,
                    'url'   =>  sprintf($url, urlencode($slug)),
                    'count' =>  isset($context->metas[$key][$slug]) ? count($context->metas[$key][$slug]) : 0
                ];
            }
        } else if (!isset($val['source']) && !empty($context->metas[$key])) {
            foreach ($context->metas[$key] as $slug => $terms) {
                $context->data['metas'][$key][$slug] = [
                    'slug'  =>  $slug,
                    'name'  =>  $slug,
                    'url'   =>  sprintf($url, urlencode($slug)),
                    'count' =>  count($terms)
                ];
            }
        }
    }

    foreach ($context->data as $key => $val) {
        $context->template->addGlobal($key, $val);
    }
});

// get metas
add_workflow('get_metas', function ($file) use ($context) {
    $str = ltrim(file_get_contents($file));
    $metas = [];

    $lines = explode("\n", $str);
    foreach ($lines as $index => $line) {
        if (preg_match("/^@([_a-z0-9-]+):(.+)$/i", $line, $matches)) {
            $key = strtolower($matches[1]);
            if ('date' == $key) {
                $metas['date'] = strtotime(trim($matches[2]));
                continue;
            } else if (!isset($context->config['blocks'][$key])) {
                $metas[$key] = trim($matches[2]);
                continue;
            }

            // read block
            $block = $context->config['blocks'][$key];
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
                        $file = $context->dir . $block['source'] . '/' . $value . '.md';
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
    if (!isset($metas['archive']) && isset($context->config['blocks']['archive'])) {
        $metas['archive'][] = date('Y.m', $metas['date']);
    }

    $str = implode("\n", array_slice($lines, $index));
    return [$metas, $str];
});

// parse
add_workflow('parse', function ($text) use ($context) {
    static $handler;

    if (empty($handler)) {
        $parser = isset($context->parser) ? $context->parser : 'markdown';
        $defaults = [
            'markdown' => 'MarkdownExtraExtended#defaultTransform',
            'parsedown' => 'ParsedownExtra#text'
        ];

        $parser = isset($defaults[$parser]) ? $defaults[$parser] : $parser;

        if (strpos($parser, ':')) {
            $parts = explode(':', $parser, 2);
            list ($file, $parser) = $parts;
            require_once $file;
        }

        $method = 'text';
        if (strpos($parser, '#')) {
            list ($className, $method) = explode('#', $parser);
        } else {
            $className = $parser;
        }

        if (!class_exists($className)) {
            fatal('can not find parser class "%s"', $className);
        }

        $ref = new ReflectionClass($className);
        $methodRef = $ref->getMethod($method);

        if (empty($methodRef)) {
            fatal('can not find method "%s:%s"', $className, $method);
        }

        $handler = [$methodRef->isStatic() ? $className : $ref->newInstance(), $method];
    }

    return call_user_func($handler, $text);
});

// get post
add_workflow('get_post', function ($type, $key) use ($context) {
    $block = $context->config['blocks'][$type];
    $file = $context->dir . $block['source'] . '/' . $key . '.md';
    list ($result, $text) = do_workflow('get_metas', $file);
    $base = !empty($context->data['url']) ? rtrim($context->data['url'], '/') : '';

    // expand metas
    foreach ($result as $name => &$metas) {
        if (!is_array($metas)) {
            continue;
        }

        if (isset($context->data['metas'][$name])) {
            $current = $context->data['metas'][$name];
            $metas = array_map(function ($index) use ($current) {
                return $current[$index];
            }, $metas);
        }
    }

    $result['type'] = $type;
    $result['id'] = $type . ':' . $key;
    $result['title'] = $key;
    $result['text'] = $text;
    $result['content'] = do_workflow('parse', $text);
    $result['ext'] = isset($block['ext']) ? $block['ext'] : 'html';
    if (!isset($result['slug'])) {
        $result['slug'] = preg_match("/^[0-9]{4}\.(.+)$/", $key, $matches) ? $matches[1] : $key;
    }
    $result['url'] = '/' . trim($block['target'], '/')
        . '/' . urlencode($result['slug']) . '.' . $result['ext'];
    $result['permalink'] = $base . $result['url'];

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
});

// get post context
add_workflow('get_post_context', function ($type, $key) use ($context) {
    $index = array_search($key, $context->index[$type]);
    $result = [
        'prev'  =>  NULL,
        'next'  =>  NULL
    ];

    if ($index > 0) {
        $result['prev'] = do_workflow('get_post', $type, $context->index[$type][$index - 1]);
    }

    if ($index < (count($context->index[$type]) - 1)) {
        $result['next'] = do_workflow('get_post', $type, $context->index[$type][$index + 1]);
    }

    return $result;
});

// get meta posts
add_workflow('get_meta_posts', function ($type, $key) use ($context) {
    $result = [];

    if (isset($context->metas[$type][$key])) {
        foreach ($context->metas[$type][$key] as $val) {
            list ($postType, $postKey) = $val;

            $index = $postType . ':' . $postKey;
            if (!isset($context->cached[$index])) {
                $context->cached[$index] = do_workflow('get_post', $postType, $postKey);
                unset($context->cached[$index]['content']);
            }

            $result[$postType][] = $context->cached[$index];
        }
    }

    foreach ($result as &$archive) {
        usort($archive, function ($a, $b) use ($context) {
            list ($aType, $aId) = explode(':', $a['id']);
            list ($bType, $bId) = explode(':', $b['id']);

            $x = array_search($aId, $context->index[$aType]);
            $y = array_search($bId, $context->index[$bType]);

            return $x > $y ? 1 : -1;
        });
    }

    return $result;
});

// build
add_workflow('build', function ($template, $file, $data = []) use ($context) {
    $html = $context->template->render($template, $data);

    $file = $context->dir . '/_target/' . $file;
    $dir = dirname($file);

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            fatal('directory is not exists "%s"', $dir);
        }
    }

    file_put_contents($file, $html);
});

// compile post
add_workflow('compile_post', function ($type, $specific = NULL) use ($context) {
    $block = $context->config['blocks'][$type];

    if (!isset($block['source']) || !is_string($block['source']) || empty($block['target'])) {
        fatal('block is not exists "%s"', $type);
    }

    $files = glob($context->dir . $block['source'] . '/*.md');
    $template = $type. '.twig';
    $target = $block['target'] . '/';

    if (isset($block['template'])) {
        $template = $block['template'];
    }

    foreach ($files as $file) {
        console('info', 'compile %s', preg_replace("/\/+/", '/', $file));

        $key = pathinfo($file, PATHINFO_FILENAME);

        if (!empty($specific) && $key != $specific) {
            continue;
        }

        $post = array_merge(do_workflow('get_post', $type, $key),
            do_workflow('get_post_context', $type, $key));
        $currentTemplate = isset($post['template']) ? $post['template'] : $template;

        // add sitemap
        $context->sitemap[$post['url']] = 0.64;

        do_workflow('build', $currentTemplate, $target . $post['slug'] . '.' . $post['ext'], [
            $type   =>  $post
        ]);
    }
});

// compile posts
add_workflow('compile_posts', function () use ($context) {
    foreach ($context->config['blocks'] as $type => $val) {
        if (isset($val['source']) && is_string($val['source'])) {
            do_workflow('compile_post', $type);
        }
    }
});

// compile metas
add_workflow('compile_metas', function () use ($context) {
    $targets = [];

    foreach ($context->config['blocks'] as $type => $val) {
        if (isset($val['source']) && is_string($val['source'])) {
            continue;
        }

        $target = isset($val['target']) ? $val['target']  : $type . '.html';
        $template = isset($val['template']) ? $val['template'] : $type . '.twig';

        if ('/' == substr($target, -1)) {
            if (!empty($context->data['metas'][$type])) {
                foreach ($context->data['metas'][$type] as $key => $meta) {
                    $targets[$target . $key . '.html:' . $template][] = $type . ':' . $key;
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
                $data[$type] = array_merge($context->data['metas'][$type][$parts[1]],
                    do_workflow('get_meta_posts', $type, $parts[1]));
            } else {
                if (!empty($context->data['metas'][$type])) {
                    foreach ($context->data['metas'][$type] as $key => $val) {
                        $data[$type][$key] = array_merge($val, do_workflow('get_meta_posts', $type, $key));
                    }
                }
            }
        }

        // add sitemap
        $context->sitemap[$target] = 0.8;
        do_workflow('build', $template, $target, $data);
    }
});

// compile index
add_workflow('compile_index', function () use ($context) {
    $index = [];

    if (empty($context->indexConfig)) {
        return;
    }

    $config = $context->indexConfig;
    $template = isset($config['template']) ? $config['template'] : 'index.twig';
    $target = isset($config['target']) ? $config['target'] : 'index.html';
    $limit = isset($config['limit']) ? $config['limit'] : 10;

    foreach ($context->config['blocks'] as $type => $val) {
        if (isset($val['source']) && is_string($val['source'])
            && isset($context->index[$type])) {
            if (is_array($limit)) {
                $currentLimit = isset($limit[$type]) ? $limit[$type] : 0;
            } else {
                $currentLimit = $limit;
            }

            if (0 == $currentLimit) {
                continue;
            }

            $posts = array_slice($context->index[$type], 0, $currentLimit);

            foreach ($posts as $post) {
                $index[$type][] = do_workflow('get_post', $type, $post);
            }
        }
    }

    // add sitemap
    $context->sitemap['/index.html'] = 1;

    do_workflow('build', $template, $target, [
        'index'    =>  $index
    ]);
});

// generate feeds
add_workflow('generate_feeds', function () use ($context) {
    if (!isset($context->config['feeds'])) {
        return;
    }

    $config = $context->config['feeds'];

    if (!isset($config['source']) || !isset($context->index[$config['source']])) {
        return;
    }

    $config = array_merge([
        'title'         =>  isset($context->data['title']) ? $context->data['title'] : 'My Feeds',
        'description'   =>  isset($context->data['description']) ? $context->data['description'] : 'My Feeds Description',
        'recent'        =>  20,
        'target'        =>  'feeds.xml',
        'url'           =>  isset($context->data['url']) ? $context->data['url'] : '/'
    ], $config);

    $feedsUrl = rtrim($config['url'], '/') . '/' . ltrim($config['target'], '/');

    $feeds = new \Atom();
    $feeds->setBaseUrl($config['url']);
    $feeds->setFeedUrl($feedsUrl);
    $feeds->setTitle($config['title']);
    $feeds->setSubTitle($config['description']);

    $posts = array_slice($context->index[$config['source']], 0, $config['recent']);
    foreach ($posts as $post) {
        $post = do_workflow('get_post', $config['source'], $post);
        $item = [
            'title'     =>  $post['title'],
            'link'      =>  $post['permalink'],
            'updated'   =>  $post['date'],
            'published' =>  $post['date'],
            'author'    =>  isset($config['author']) ? [
                'name'  =>  $config['author'],
                'url'   =>  $config['url']
            ] : NULL,
            'content'   =>  $post['content']
        ];

        foreach ($context->config['blocks'] as $type => $val) {
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

    $target = $context->dir . '_target/' . $config['target'];
    $targetDir = dirname($target);

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            fatal('feeds directory is not exists "%s"', $targetDir);
        }
    }

    file_put_contents($target, $feeds->generate());
});

// generate sitemap
add_workflow('generate_sitemap', function () use ($context) {
    $fp = fopen($context->dir . '_target/sitemap.xml', 'wb');
    if (!$fp) {
        fatal('can not write sitemap.xml');
    }

    $base = isset($context->data['url']) ? rtrim($context->data['url'], '/') : '/';

    fwrite($fp, '<?xml version="1.0" encoding="UTF-8"?>
<urlset
    xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
            http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">');

    foreach ($context->sitemap as $url => $priority) {
        $priority = number_format($priority, 2, '.', '');
        $url = $base . '/' . ltrim($url, '/');

        fwrite($fp, "
    <url>
        <loc>{$url}</loc>
        <changefreq>daily</changefreq>
        <priority>$priority</priority>
    </url>");
    }

    fwrite($fp, '
</urlset>');
    fclose($fp);
});

// compile
add_workflow('compile', function () use ($context) {
    do_workflow('compile_index');
    do_workflow('compile_metas');
    do_workflow('compile_posts');
    do_workflow('generate_feeds');
    do_workflow('generate_sitemap');
});
