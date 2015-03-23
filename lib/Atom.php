<?php

/**
 * Atom
 *
 * @author qining
 * @category typecho
 * @package Feed
 * @copyright Copyright (c) 2008 Typecho team (http://www.typecho.org)
 * @license GNU General Public License 2.0
 */
class Atom
{
    /** 定义行结束符 */
    const EOL = "\n";

    /**
     * 字符集编码
     *
     * @access private
     * @var string
     */
    private $_charset;

    /**
     * 特殊字符列表
     * 
     * @var array
     * @access private
     */
    private $_special = array();

    /**
     * 聚合地址
     *
     * @access private
     * @var string
     */
    private $_feedUrl;

    /**
     * 基本地址
     *
     * @access private
     * @var unknown
     */
    private $_baseUrl;

    /**
     * 聚合标题
     *
     * @access private
     * @var string
     */
    private $_title;

    /**
     * 聚合副标题
     *
     * @access private
     * @var string
     */
    private $_subTitle;

    /**
     * 所有的items
     *
     * @access private
     * @var array
     */
    private $_items = array();

    /**
     * 创建Feed对象
     *
     * @param string $charset
     */
    public function __construct($charset = 'UTF-8')
    {
        $this->_charset = $charset;
        $this->_special = array_map('chr', range(0, 8));
    }

    /**
     * 去掉一些特殊字符 
     * 
     * @param mixed $str 
     * @access private
     * @return string
     */
    private function encode($str)
    {
        return str_replace($this->_special, '', htmlspecialchars($str, ENT_IGNORE, 'UTF-8'));
    }

    /**
     * 设置标题
     *
     * @access public
     * @param string $title 标题
     * @return void
     */
    public function setTitle($title)
    {
        $this->_title = $title;
    }

    /**
     * 设置副标题
     *
     * @access public
     * @param string $subTitle 副标题
     * @return void
     */
    public function setSubTitle($subTitle)
    {
        $this->_subTitle = $subTitle;
    }

    /**
     * 设置聚合地址
     *
     * @access public
     * @param string $feedUrl 聚合地址
     * @return void
     */
    public function setFeedUrl($feedUrl)
    {
        $this->_feedUrl = $feedUrl;
    }

    /**
     * 设置主页
     *
     * @access public
     * @param string $baseUrl 主页地址
     * @return void
     */
    public function setBaseUrl($baseUrl)
    {
        $this->_baseUrl = $baseUrl;
    }

    /**
     * $item的格式为
     * <code>
     * array (
     *     'title'      =>  'xxx',
     *     'content'    =>  'xxx',
     *     'excerpt'    =>  'xxx',
     *     'date'       =>  'xxx',
     *     'link'       =>  'xxx',
     *     'author'     =>  'xxx',
     *     'comments'   =>  'xxx',
     * )
     * </code>
     *
     * @access public
     * @param array $item
     * @return unknown
     */
    public function addItem(array $item)
    {
        $this->_items[] = $item;
    }

    /**
     * 输出字符串
     *
     * @access public
     * @return string
     */
    public function generate()
    {
        $result = '<?xml version="1.0" encoding="' . $this->_charset . '"?>' . self::EOL;

        $result .= '<feed xmlns="http://www.w3.org/2005/Atom"
xmlns:creativeCommons="http://backend.userland.com/creativeCommonsRssModule"
>' . self::EOL;

        $content = '';
        $lastUpdate = 0;

        foreach ($this->_items as $item) {
            $item['updated'] = $item['updated'] > 0 ? $item['updated'] : $item['published'];

            $content .= '<entry>' . self::EOL;
            $content .= '<title type="text">' . $this->encode($item['title']) . '</title>' . self::EOL;
            $content .= '<link rel="alternate" type="text/html" href="' . $item['link'] . '" />' . self::EOL;
            $content .= '<id>' . (isset($item['id']) ? $item['id'] : $item['link']) . '</id>' . self::EOL;
            $content .= '<updated>' . date('c', $item['updated']) . '</updated>' . self::EOL;
            $content .= '<published>' . date('c', $item['published']) . '</published>' . self::EOL;

            if (!empty($item['author'])) {
                $content .= '<author>
<name>' . $item['author']['name'] . '</name>
<uri>' . $item['author']['url'] . '</uri>
</author>' . self::EOL;
            }

            if (!empty($item['category']) && is_array($item['category'])) {
                foreach ($item['category'] as $category) {
                    $content .= '<category scheme="' . $category['feeds_url'] . '" term="' . $category['name'] . '" />' . self::EOL;
                }
            }

            if (!empty($item['content'])) {
                $content .= '<summary type="html">' . $this->encode($item['content']) . '</summary>' . self::EOL;
            }

            $content .= '</entry>' . self::EOL;

            if ($item['updated'] > $lastUpdate) {
                $lastUpdate = $item['updated'];
            }
        }

        $result .= '<title type="text">' . $this->encode($this->_title) . '</title>
<subtitle type="text">' . $this->encode($this->_subTitle) . '</subtitle>
<updated>' . date('c', $lastUpdate) . '</updated>
<link rel="alternate" type="text/html" href="' . $this->_baseUrl . '" />
<link rel="self" type="application/atom+xml" href="' . $this->_feedUrl . '" />
<id>' . $this->_feedUrl . '</id>
<creativeCommons:license>http://www.creativecommons.org/licenses/by-sa/2.5/rdf</creativeCommons:license>
' . $content . '</feed>';

        return $result;
    }
}
