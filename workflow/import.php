<?php
/**
 * import.php - logecho-main
 * 
 * @author joyqi
 */

add_workflow('init', function () use ($context) {
    list ($xmlrpc, $username, $password, $methods) = do_workflow('get_params');
    do_workflow('import', $xmlrpc, $username, $password, $methods);
});

// get params
add_workflow('get_params', function () use ($context) {
    // detect url
    while (true) {
        $url = readline("Please enter your blog's xmlrpc url (http://...): ");

        try {
            $url = do_workflow('detect_xmlrpc_url', $url);
            break;
        } catch (Exception $e) {
            console('error', $e->getMessage());
            continue;
        }
    }

    // check username and password
    while (true) {
        $xmlrpc = new IXR_Client($url);
        $methods = $xmlrpc->query('system.listMethods');

        $username = readline('Username: ');
        $password = readline('Password: ');

        if (!in_array('metaWeblog.getRecentPosts', $methods)) {
            fatal('method "%" not found on your server', 'metaWeblog.getRecentPosts');
        }

        try {
            $xmlrpc->query('metaWeblog.getRecentPosts', 1, $username, $password, 1);
            break;
        } catch (Exception $e) {
            console('error', $e->getMessage());
            continue;
        }
    }

    return [$xmlrpc, $username, $password, $methods];
});

// detect xmlrpc
add_workflow('detect_xmlrpc_url', function ($url) {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL             =>  $url,
        CURLOPT_RETURNTRANSFER  =>  true,
        CURLOPT_HEADER          =>  false,
        CURLOPT_SSL_VERIFYPEER  =>  false,
        CURLOPT_SSL_VERIFYHOST  =>  false,
        CURLOPT_TIMEOUT         =>  20,
        CURLOPT_FOLLOWLOCATION  =>  true,
        CURLOPT_MAXREDIRS       =>  3
    ]);

    $reponse = curl_exec($ch);
    $type = trim(explode(';', curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$reponse || 200 != $status) {
        fatal('http server is not available: %s', $url);
    }

    if ('text/html' == $type) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $reponse);
        $xpath = new DOMXPath($dom);

        $items = $xpath->query('//link[@rel="EditURI"]');
        if ($items->length > 0) {
            $href = $items->item(0)->getAttributeNode('href')->nodeValue;
            if (!empty($href)) {
                $rsd = new DOMDocument('1.0', 'UTF-8');
                $rsd->load($href);
                $apis = $rsd->getElementsByTagName('api');

                if ($apis->length > 0) {
                    return $apis->item(0)->getAttributeNode('apiLink')->nodeValue;
                }
            }
        }
    } else if ('text/xml' == $type) {
        return $url;
    }

    fatal('no xmlrpc provider founded');
});

// import
add_workflow('import', function ($xmlrpc, $username, $password, $methods) use ($context) {
    $wxrFile = tempnam(sys_get_temp_dir(), 'le');
    $wxr = fopen($wxrFile, 'wb');

    // write begin
    fwrite($wxr, '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:dsq="http://www.disqus.com/"
  xmlns:dc="http://purl.org/dc/elements/1.1/"
  xmlns:wp="http://wordpress.org/export/1.0/"
>
  <channel>');

    $blogId = 1;
    if (in_array('metaWeblog.getUsersBlogs', $methods)) {
        console('info', 'fetching blog info');
        $blogs = $xmlrpc->query('metaWeblog.getUsersBlogs', 1, $username, $password, 100);
        if (empty($blogs)) {
            fatal('user has no available blog');
        }

        $blog = array_shift($blogs);
        $blogId = $blog['blogid'];
    }

    if (in_array('wp.getOptions', $methods)) {
        $options = $xmlrpc->query('wp.getOptions', $blogId, $username, $password);
        $context->config['globals']['url'] = $options['blog_url']['value'];
        $context->config['globals']['title'] = $options['blog_title']['value'];
    }

    $context->config['blocks']['category']['source'] = [];
    if (in_array('metaWeblog.getCategories', $methods)) {
        console('info', 'fetching categories');
        $categories = $xmlrpc->query('metaWeblog.getCategories', $blogId, $username, $password);
        $context->config['blocks']['category']['source'] = [];

        foreach ($categories as $category) {
            $key = explode('.', basename(rtrim($category['htmlUrl'], '/')))[0];
            $context->config['blocks']['category']['source'][$key] = $category['categoryName'];
        }
    }

    if (!file_put_contents($context->dir . 'config.yaml', Spyc::YAMLDump($context->config, 4))) {
        fatal('can not write to config file: %sconfig.yaml', $context->dir);
    }

    if (in_array('metaWeblog.getRecentPosts', $methods)) {
        console('info', 'fetching posts');

        $posts = $xmlrpc->query('metaWeblog.getRecentPosts', $blogId, $username, $password, 1000);
        $source = $context->dir . $context->config['blocks']['post']['source'];
        $target = rtrim($context->config['globals']['url'], '/') . '/'
            . trim(isset($context->config['blocks']['post']['target']) ? $context->config['blocks']['post']['target'] : 'post', '/')
            . '/%s.' . (isset($context->config['blocks']['post']['ext']) ? $context->config['blocks']['post']['ext'] : 'html');

        if (!is_dir($source)) {
            if (!mkdir($source, 0755, true)) {
                fatal('can not make post target directory: %s', $source);
            }
        }

        foreach ($posts as $post) {
            if ('publish' != $post['post_status']) {
                continue;
            }

            console('info', 'add %s', $post['wp_slug']);
            $content = do_workflow('filter_post', $post, $context->config['blocks']['category']['source']);
            file_put_contents($source . '/' . $post['wp_slug'] . '.md', $content);

            if (in_array('wp.getComments', $methods)) {
                $offset = 0;
                console('info', 'fetching comments: %s', $post['postid']);

                do {
                    $comments = $xmlrpc->query('wp.getComments', $blogId, $username, $password, [
                        'post_id'   =>  $post['postid'],
                        'number'    =>  100,
                        'offset'    =>  $offset
                    ]);

                    foreach ($comments as $c) {
                        if (isset($c['type']) && 'comment' != $c['type']) {
                            continue;
                        }

                        fwrite($wxr, "<item>
    <title>{$post['title']}</title>
    <link>" . sprintf($target, $post['wp_slug']) . "</link>
    <dsq:thread_identifier>post:{$post['wp_slug']}</dsq:thread_identifier>
    <wp:post_date_gmt>" . date('Y-m-d H:i:s', $post['dateCreated']->getTimestamp()) . "</wp:post_date_gmt>
    <wp:comment_status>open</wp:comment_status>
    <wp:comment>
        <wp:comment_id>{$c['comment_id']}</wp:comment_id>
        <wp:comment_author>{$c['author']}</wp:comment_author>
        <wp:comment_author_email>{$c['author_email']}</wp:comment_author_email>
        <wp:comment_author_url>{$c['author_url']}</wp:comment_author_url>
        <wp:comment_author_IP>{$c['author_ip']}</wp:comment_author_IP>
        <wp:comment_date_gmt>" . date('Y-m-d H:i:s', $c['date_created_gmt']->getTimestamp()) . "</wp:comment_date_gmt>
        <wp:comment_content><![CDATA[{$c['content']}]]></wp:comment_content>
        <wp:comment_approved>" . ('approve' == $c['status'] ? 1 : 0) . "</wp:comment_approved>
        <wp:comment_parent>{$c['parent']}</wp:comment_parent>
    </wp:comment>
    </item>");
                    }

                    $offset += 100;
                } while (count($comments) == 100);
            }
        }
    }

    fwrite($wxr, '</channel>
</rss>');
    fclose($wxr);
    if (rename($wxrFile, $context->dir . '/wxr.xml')) {
        console('done', 'your comments WXR XML file has exported to %swxr.xml', $context->dir);
    }

});

// filter post
add_workflow('filter_post', function ($post, $categoriesConfig) {
    $text = (isset($post['description']) ? $post['description'] : '')
        . (isset($post['mt_text_more']) ? "\n\n<!--more-->\n\n" . $post['mt_text_more'] : '');

    $text = preg_replace("/<\/p>\s*<\/p>/is", "</p>", $text);
    $text = preg_replace_callback("/<pre[^>]*><code[^>]*>(.+?)<\/code><\/pre>/is", function ($matches) {
        return '<pre>' . $matches[1] . '</pre>';
    }, $text);
    $text = preg_replace_callback("/<code[^>]*>(.+?)<\/code>/is", function ($matches) {
        if (false !== strpos($matches[1], "\n")) {
            return '<pre>' . $matches[1] . '</pre>';
        }

        return '<code>' . $matches[1] . '</code>';
    }, $text);
    $text = preg_replace("/<a[^>]+(href=\"[^\"]+\")[^>]*>/is", "<a \\1>", $text);
    $text = preg_replace("/<img[^>]+(src=\"[^\"]+\")[^>]*\/?>/is", "<img \\1>", $text);
    $parser = new \Markdownify\ConverterExtra();

    $content = "\n" . $post['title'] . "\n"
        . str_repeat('=', strlen($post['title'])) . "\n\n"
        . $parser->parseString($text);

    $date = $post['dateCreated'];
    if (!empty($post['mt_keywords'])) {
        $content = "@tag:{$post['mt_keywords']}\n" . $content;
    }

    if (!empty($post['categories'])) {
        $categories = [];
        foreach ($post['categories'] as $category) {
            $found = array_search($category, $categoriesConfig);
            if (false !== $found) {
                $categories[] = $found;
            }
        }

        if (!empty($categories)) {
            $content = "@category:" . implode(',', $categories) . "\n" . $content;
        }
    }

    $content = "@date:{$date->year}-{$date->month}-{$date->day} {$date->hour}:{$date->minute}:{$date->second}\n"
        . $content;
    $content = "@slug:{$post['wp_slug']}\n" . $content;

    return $content;
});
