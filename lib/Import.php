<?php
/**
 * Import.php - logecho
 * 
 * @author joyqi
 */

namespace LE;


class Import
{
    /**
     * @var $url
     */
    private $_url;

    /**
     * @param $url
     * @param $dir
     * @throws \Exception
     */
    public function __construct($url, $dir)
    {
        $xmlrpcUrl = $this->detectXmlrpcUrl($url);
        $xmlrpc = new \XMLRPC($xmlrpcUrl);
        $methods = $xmlrpc->system->listMethods();

        $username = get_input('Username:');
        $password = get_input('Password:');
        info("Login to {$url} with {$username}:{$password}");

        $config = \Spyc::YAMLLoadString(file_get_contents('phar://logecho.phar/sample/config.yaml'));

        $blogId = 1;
        if (in_array('metaWeblog.getUsersBlogs', $methods)) {
            info('Fetching blog info');
            $blogs = $xmlrpc->metaWeblog->getUsersBlogs(1, $username, $password, 100);
            if (empty($blogs)) {
                throw new \Exception('User has no available blog');
            }

            $blog = array_shift($blogs);
            $blogId = $blog['blogid'];
        }

        if (in_array('wp.getOptions', $methods)) {
            $options = $xmlrpc->wp->getOptions($blogId, $username, $password);
            $config['global']['url'] = $options['blog_url']['value'];
            $config['global']['title'] = $options['blog_title']['value'];
        }

        $config['blocks']['category']['source'] = [];
        if (in_array('metaWeblog.getCategories', $methods)) {
            info('Fetching categories');
            $categories = $xmlrpc->metaWeblog->getCategories($blogId, $username, $password);
            $config['blocks']['category']['source'] = [];

            foreach ($categories as $category) {
                $key = explode('.', basename(rtrim($category['htmlUrl'], '/')))[0];
                $config['blocks']['category']['source'][$key] = $category['categoryName'];
            }
        }

        if (!file_put_contents($dir . '/config.yaml', \Spyc::YAMLDump($config))) {
            throw new \Exception('Can not write to config file: ' . $dir . '/config.yaml');
        }

        if (in_array('metaWeblog.getRecentPosts', $methods)) {
            info('Fetching posts');
            $posts = $xmlrpc->metaWeblog->getRecentPosts($blogId, $username, $password, 1000);
            $source = $dir . '/' . $config['blocks']['post']['source'];

            if (!is_dir($source)) {
                if (!mkdir($source, 0755, true)) {
                    throw new \Exception('Can not make post target directory: ' . $source);
                }
            }

            foreach ($posts as $post) {
                if ('publish' != $post['post_status']) {
                    continue;
                }

                info('Add ' . $post['wp_slug']);
                $content = $this->filterPost($post, $config['blocks']['category']['source']);
                file_put_contents($source . '/' . $post['wp_slug'] . '.md', $content);
            }
        }
    }

    /**
     * @param array $post
     * @param array $categoriesConfig
     * @return array
     */
    private function filterPost(array $post, array $categoriesConfig)
    {
        $text = (isset($post['description']) ? $post['description'] : '')
            . (isset($post['mt_text_more']) ? $post['mt_text_more'] : '');

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
        $parser = new \ConverterExtra();

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
    }

    /**
     * @param $url
     * @return mixed
     * @throws \Exception
     */
    private function detectXmlrpcUrl($url)
    {
        info('Detect url ' .$url);
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
            throw new \Exception('Http server is not available: ' . $url);
        }

        if ('text/html' == $type) {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $reponse);
            $xpath = new \DOMXPath($dom);

            $items = $xpath->query('//link[@rel="EditURI"]');
            if ($items->length > 0) {
                $href = $items->item(0)->getAttributeNode('href')->nodeValue;
                if (!empty($href)) {
                    info('Detect rsd file ' . $href);
                    $rsd = new \DOMDocument('1.0', 'UTF-8');
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

        throw new \Exception('No xmlrpc url founded');
    }
}
