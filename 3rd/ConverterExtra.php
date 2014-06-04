<?php

/**
 * parseHTML is a HTML parser which works with PHP 4 and above.
 * It tries to handle invalid HTML to some degree.
 *
 * @version 1.0 beta
 * @author Milian Wolff (mail@milianw.de, http://milianw.de)
 * @license LGPL, see LICENSE_LGPL.txt and the summary below
 * @copyright (C) 2007  Milian Wolff
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */
class Parser {
  /**
   * tags which are always empty (<br /> etc.)
   *
   * @var array<string>
   */
  var $emptyTags = array(
    'br',
    'hr',
    'input',
    'img',
    'area',
    'link',
    'meta',
    'param',
  );
  /**
   * tags with preformatted text
   * whitespaces wont be touched in them
   *
   * @var array<string>
   */
  var $preformattedTags = array(
    'script',
    'style',
    'pre',
    'code',
  );
  /**
   * supress HTML tags inside preformatted tags (see above)
   *
   * @var bool
   */
  var $noTagsInCode = false;
  /**
   * html to be parsed
   *
   * @var string
   */
  var $html = '';
  /**
   * node type:
   *
   * - tag (see isStartTag)
   * - text (includes cdata)
   * - comment
   * - doctype
   * - pi (processing instruction)
   *
   * @var string
   */
  var $nodeType = '';
  /**
   * current node content, i.e. either a
   * simple string (text node), or something like
   * <tag attrib="value"...>
   *
   * @var string
   */
  var $node = '';
  /**
   * wether current node is an opening tag (<a>) or not (</a>)
   * set to NULL if current node is not a tag
   * NOTE: empty tags (<br />) set this to true as well!
   *
   * @var bool | null
   */
  var $isStartTag = null;
  /**
   * wether current node is an empty tag (<br />) or not (<a></a>)
   *
   * @var bool | null
   */
  var $isEmptyTag = null;
  /**
   * tag name
   *
   * @var string | null
   */
  var $tagName = '';
  /**
   * attributes of current tag
   *
   * @var array (attribName=>value) | null
   */
  var $tagAttributes = null;
  /**
   * wether the current tag is a block element
   *
   * @var bool | null
   */
  var $isBlockElement = null;

  /**
   * keep whitespace
   *
   * @var int
   */
  var $keepWhitespace = 0;
  /**
   * list of open tags
   * count this to get current depth
   *
   * @var array
   */
  var $openTags = array();
  /**
   * list of block elements
   *
   * @var array
   * TODO: what shall we do with <del> and <ins> ?!
   */
  var $blockElements = array (
    # tag name => <bool> is block
    # block elements
    'address' => true,
    'blockquote' => true,
    'center' => true,
    'del' => true,
    'dir' => true,
    'div' => true,
    'dl' => true,
    'fieldset' => true,
    'form' => true,
    'h1' => true,
    'h2' => true,
    'h3' => true,
    'h4' => true,
    'h5' => true,
    'h6' => true,
    'hr' => true,
    'ins' => true,
    'isindex' => true,
    'menu' => true,
    'noframes' => true,
    'noscript' => true,
    'ol' => true,
    'p' => true,
    'pre' => true,
    'table' => true,
    'ul' => true,
    # set table elements and list items to block as well
    'thead' => true,
    'tbody' => true,
    'tfoot' => true,
    'td' => true,
    'tr' => true,
    'th' => true,
    'li' => true,
    'dd' => true,
    'dt' => true,
    # header items and html / body as well
    'html' => true,
    'body' => true,
    'head' => true,
    'meta' => true,
    'link' => true,
    'style' => true,
    'title' => true,
    # unfancy media tags, when indented should be rendered as block
    'map' => true,
    'object' => true,
    'param' => true,
    'embed' => true,
    'area' => true,
    # inline elements
    'a' => false,
    'abbr' => false,
    'acronym' => false,
    'applet' => false,
    'b' => false,
    'basefont' => false,
    'bdo' => false,
    'big' => false,
    'br' => false,
    'button' => false,
    'cite' => false,
    'code' => false,
    'del' => false,
    'dfn' => false,
    'em' => false,
    'font' => false,
    'i' => false,
    'img' => false,
    'ins' => false,
    'input' => false,
    'iframe' => false,
    'kbd' => false,
    'label' => false,
    'q' => false,
    'samp' => false,
    'script' => false,
    'select' => false,
    'small' => false,
    'span' => false,
    'strong' => false,
    'sub' => false,
    'sup' => false,
    'textarea' => false,
    'tt' => false,
    'var' => false,
  );
  /**
   * get next node, set $this->html prior!
   *
   * @param void
   * @return bool
   */
  function nextNode() {
    if (empty($this->html)) {
      # we are done with parsing the html string
      return false;
    }
    static $skipWhitespace = true;
    if ($this->isStartTag && !$this->isEmptyTag) {
      array_push($this->openTags, $this->tagName);
      if (in_array($this->tagName, $this->preformattedTags)) {
        # dont truncate whitespaces for <code> or <pre> contents
        $this->keepWhitespace++;
      }
    }

    if ($this->html[0] == '<') {
      $token = substr($this->html, 0, 9);
      if (substr($token, 0, 2) == '<?') {
        # xml prolog or other pi's
        /** TODO **/
        #trigger_error('this might need some work', E_USER_NOTICE);
        $pos = strpos($this->html, '>');
        $this->setNode('pi', $pos + 1);
        return true;
      }
      if (substr($token, 0, 4) == '<!--') {
        # comment
        $pos = strpos($this->html, '-->');
        if ($pos === false) {
          # could not find a closing -->, use next gt instead
          # this is firefox' behaviour
          $pos = strpos($this->html, '>') + 1;
        } else {
          $pos += 3;
        }
        $this->setNode('comment', $pos);

        $skipWhitespace = true;
        return true;
      }
      if ($token == '<!DOCTYPE') {
        # doctype
        $this->setNode('doctype', strpos($this->html, '>')+1);

        $skipWhitespace = true;
        return true;
      }
      if ($token == '<![CDATA[') {
        # cdata, use text node

        # remove leading <![CDATA[
        $this->html = substr($this->html, 9);

        $this->setNode('text', strpos($this->html, ']]>')+3);

        # remove trailing ]]> and trim
        $this->node = substr($this->node, 0, -3);
        $this->handleWhitespaces();

        $skipWhitespace = true;
        return true;
      }
      if ($this->parseTag()) {
        # seems to be a tag
        # handle whitespaces
        if ($this->isBlockElement) {
          $skipWhitespace = true;
        } else {
          $skipWhitespace = false;
        }
        return true;
      }
    }
    if ($this->keepWhitespace) {
      $skipWhitespace = false;
    }
    # when we get here it seems to be a text node
    $pos = strpos($this->html, '<');
    if ($pos === false) {
      $pos = strlen($this->html);
    }
    $this->setNode('text', $pos);
    $this->handleWhitespaces();
    if ($skipWhitespace && $this->node == ' ') {
      return $this->nextNode();
    }
    $skipWhitespace = false;
    return true;
  }
  /**
   * parse tag, set tag name and attributes, see if it's a closing tag and so forth...
   *
   * @param void
   * @return bool
   */
  function parseTag() {
    static $a_ord, $z_ord, $special_ords;
    if (!isset($a_ord)) {
      $a_ord = ord('a');
      $z_ord = ord('z');
      $special_ords = array(
        ord(':'), // for xml:lang
        ord('-'), // for http-equiv
      );
    }

    $tagName = '';

    $pos = 1;
    $isStartTag = $this->html[$pos] != '/';
    if (!$isStartTag) {
      $pos++;
    }
    # get tagName
    while (isset($this->html[$pos])) {
      $pos_ord = ord(strtolower($this->html[$pos]));
      if (($pos_ord >= $a_ord && $pos_ord <= $z_ord) || (!empty($tagName) && is_numeric($this->html[$pos]))) {
        $tagName .= $this->html[$pos];
        $pos++;
      } else {
        $pos--;
        break;
      }
    }

    $tagName = strtolower($tagName);
    if (empty($tagName) || !isset($this->blockElements[$tagName])) {
      # something went wrong => invalid tag
      $this->invalidTag();
      return false;
    }
    if ($this->noTagsInCode && end($this->openTags) == 'code' && !($tagName == 'code' && !$isStartTag)) {
      # we supress all HTML tags inside code tags
      $this->invalidTag();
      return false;
    }

    # get tag attributes
    /** TODO: in html 4 attributes do not need to be quoted **/
    $isEmptyTag = false;
    $attributes = array();
    $currAttrib = '';
    while (isset($this->html[$pos+1])) {
      $pos++;
      # close tag
      if ($this->html[$pos] == '>' || $this->html[$pos].$this->html[$pos+1] == '/>') {
        if ($this->html[$pos] == '/') {
          $isEmptyTag = true;
          $pos++;
        }
        break;
      }

      $pos_ord = ord(strtolower($this->html[$pos]));
      if ( ($pos_ord >= $a_ord && $pos_ord <= $z_ord) || in_array($pos_ord, $special_ords)) {
        # attribute name
        $currAttrib .= $this->html[$pos];
      } elseif (in_array($this->html[$pos], array(' ', "\t", "\n"))) {
        # drop whitespace
      } elseif (in_array($this->html[$pos].$this->html[$pos+1], array('="', "='"))) {
        # get attribute value
        $pos++;
        $await = $this->html[$pos]; # single or double quote
        $pos++;
        $value = '';
        while (isset($this->html[$pos]) && $this->html[$pos] != $await) {
          $value .= $this->html[$pos];
          $pos++;
        }
        $attributes[$currAttrib] = $value;
        $currAttrib = '';
      } else {
        $this->invalidTag();
        return false;
      }
    }
    if ($this->html[$pos] != '>') {
      $this->invalidTag();
      return false;
    }

    if (!empty($currAttrib)) {
      # html 4 allows something like <option selected> instead of <option selected="selected">
      $attributes[$currAttrib] = $currAttrib;
    }
    if (!$isStartTag) {
      if (!empty($attributes) || $tagName != end($this->openTags)) {
        # end tags must not contain any attributes
        # or maybe we did not expect a different tag to be closed
        $this->invalidTag();
        return false;
      }
      array_pop($this->openTags);
      if (in_array($tagName, $this->preformattedTags)) {
        $this->keepWhitespace--;
      }
    }
    $pos++;
    $this->node = substr($this->html, 0, $pos);
    $this->html = substr($this->html, $pos);
    $this->tagName = $tagName;
    $this->tagAttributes = $attributes;
    $this->isStartTag = $isStartTag;
    $this->isEmptyTag = $isEmptyTag || in_array($tagName, $this->emptyTags);
    if ($this->isEmptyTag) {
      # might be not well formed
      $this->node = preg_replace('# */? *>$#', ' />', $this->node);
    }
    $this->nodeType = 'tag';
    $this->isBlockElement = $this->blockElements[$tagName];
    return true;
  }
  /**
   * handle invalid tags
   *
   * @param void
   * @return void
   */
  function invalidTag() {
    $this->html = substr_replace($this->html, '&lt;', 0, 1);
  }
  /**
   * update all vars and make $this->html shorter
   *
   * @param string $type see description for $this->nodeType
   * @param int $pos to which position shall we cut?
   * @return void
   */
  function setNode($type, $pos) {
    if ($this->nodeType == 'tag') {
      # set tag specific vars to null
      # $type == tag should not be called here
      # see this::parseTag() for more
      $this->tagName = null;
      $this->tagAttributes = null;
      $this->isStartTag = null;
      $this->isEmptyTag = null;
      $this->isBlockElement = null;

    }
    $this->nodeType = $type;
    $this->node = substr($this->html, 0, $pos);
    $this->html = substr($this->html, $pos);
  }
  /**
   * check if $this->html begins with $str
   *
   * @param string $str
   * @return bool
   */
  function match($str) {
    return substr($this->html, 0, strlen($str)) == $str;
  }
  /**
   * truncate whitespaces
   *
   * @param void
   * @return void
   */
  function handleWhitespaces() {
    if ($this->keepWhitespace) {
      # <pre> or <code> before...
      return;
    }
    # truncate multiple whitespaces to a single one
    mb_regex_encoding('UTF-8');
    $this->node = mb_ereg_replace('\s+', ' ', $this->node);
  }
  /**
   * normalize self::node
   *
   * @param void
   * @return void
   */
  function normalizeNode() {
    $this->node = '<';
    if (!$this->isStartTag) {
      $this->node .= '/'.$this->tagName.'>';
      return;
    }
    $this->node .= $this->tagName;
    foreach ($this->tagAttributes as $name => $value) {
      $this->node .= ' '.$name.'="'.str_replace('"', '&quot;', $value).'"';
    }
    if ($this->isEmptyTag) {
      $this->node .= ' /';
    }
    $this->node .= '>';
  }
}

/**
 * default configuration
 */
define('MDFY_BODYWIDTH', false);
define('MDFY_KEEPHTML', true);

/**
 * HTML to Markdown converter class
 */
class Converter
{
    /**
     * html parser object
     *
     * @var parseHTML
     */
    protected $parser;

    /**
     * markdown output
     *
     * @var string
     */
    protected $output;

    /**
     * stack with tags which where not converted to html
     *
     * @var array<string>
     */
    protected $notConverted = array();

    /**
     * skip conversion to markdown
     *
     * @var bool
     */
    protected $skipConversion = false;

    /* options */

    /**
     * keep html tags which cannot be converted to markdown
     *
     * @var bool
     */
    protected $keepHTML = false;

    /**
     * wrap output, set to 0 to skip wrapping
     *
     * @var int
     */
    protected $bodyWidth = 0;

    /**
     * minimum body width
     *
     * @var int
     */
    protected $minBodyWidth = 25;

    /**
     * position where the link reference will be displayed
     *
     *
     * @var int
     */
    protected $linkPosition;
    const LINK_AFTER_CONTENT = 0;
    const LINK_AFTER_PARAGRAPH = 1;
    const LINK_IN_PARAGRAPH = 2;

    /**
     * stores current buffers
     *
     * @var array<string>
     */
    protected $buffer = array();

    /**
     * stores current buffers
     *
     * @var array<string>
     */
    protected $footnotes = array();

    /**
     * tags with elements which can be handled by markdown
     *
     * @var array<string>
     */
    protected $isMarkdownable = array(
        'p' => array(),
        'ul' => array(),
        'ol' => array(),
        'li' => array(),
        'br' => array(),
        'blockquote' => array(),
        'code' => array(),
        'pre' => array(),
        'a' => array(
            'href' => 'required',
            'title' => 'optional',
        ),
        'strong' => array(),
        'b' => array(),
        'em' => array(),
        'i' => array(),
        'img' => array(
            'src' => 'required',
            'alt' => 'optional',
            'title' => 'optional',
        ),
        'h1' => array(),
        'h2' => array(),
        'h3' => array(),
        'h4' => array(),
        'h5' => array(),
        'h6' => array(),
        'hr' => array(),
    );

    /**
     * html tags to be ignored (contents will be parsed)
     *
     * @var array<string>
     */
    protected $ignore = array(
        'html',
        'body',
    );

    /**
     * html tags to be dropped (contents will not be parsed!)
     *
     * @var array<string>
     */
    protected $drop = array(
        'script',
        'head',
        'style',
        'form',
        'area',
        'object',
        'param',
        'iframe',
    );

    /**
     * Markdown indents which could be wrapped
     * @note: use strings in regex format
     *
     * @var array<string>
     */
    protected $wrappableIndents = array(
        '\*   ', # ul
        '\d.  ', # ol
        '\d\d. ', # ol
        '> ', # blockquote
        '', # p
    );

    /**
     * list of chars which have to be escaped in normal text
     * @note: use strings in regex format
     *
     * @var array
     *
     * TODO: what's with block chars / sequences at the beginning of a block?
     */
    protected $escapeInText = array(
        '\*\*([^*]+)\*\*' => '\*\*$1\*\*', # strong
        '\*([^*]+)\*' => '\*$1\*', # em
        '__(?! |_)(.+)(?!<_| )__' => '\_\_$1\_\_', # strong
        '_(?! |_)(.+)(?!<_| )_' => '\_$1\_', # em
        '([-*_])([ ]{0,2}\1){2,}' => '\\\\$0', # hr
        '`' => '\`', # code
        '\[(.+)\](\s*\()' => '\[$1\]$2', # links: [text] (url) => [text\] (url)
        '\[(.+)\](\s*)\[(.*)\]' => '\[$1\]$2\[$3\]', # links: [text][id] => [text\][id\]
        '^#(#{0,5}) ' => '\#$1 ', # header
    );

    /**
     * wether last processed node was a block tag or not
     *
     * @var bool
     */
    protected $lastWasBlockTag = false;

    /**
     * name of last closed tag
     *
     * @var string
     */
    protected $lastClosedTag = '';

    /**
     * number of line breaks before next inline output
     */
    protected $lineBreaks = 0;

    /**
     * node stack, e.g. for <a> and <abbr> tags
     *
     * @var array<array>
     */
    protected $stack = array();

    /**
     * current indentation
     *
     * @var string
     */
    protected $indent = '';

    /**
     * constructor, set options, setup parser
     *
     * @param int $linkPosition define the position of links
     * @param int $bodyWidth wether or not to wrap the output to the given width
     *             defaults to false
     * @param bool $keepHTML wether to keep non markdownable HTML or to discard it
     *             defaults to true (HTML will be kept)
     * @return void
     */
    public function __construct($linkPosition = self::LINK_AFTER_CONTENT, $bodyWidth = MDFY_BODYWIDTH, $keepHTML = MDFY_KEEPHTML)
    {
        $this->linkPosition = $linkPosition;
        $this->keepHTML = $keepHTML;

        if ($bodyWidth > $this->minBodyWidth) {
            $this->bodyWidth = intval($bodyWidth);
        } else {
            $this->bodyWidth = false;
        }

        $this->parser = new Parser;
        $this->parser->noTagsInCode = true;

        # we don't have to do this every time
        $search = array();
        $replace = array();
        foreach ($this->escapeInText as $s => $r) {
            array_push($search, '@(?<!\\\)' . $s . '@U');
            array_push($replace, $r);
        }
        $this->escapeInText = array(
            'search' => $search,
            'replace' => $replace
        );
    }

    /**
     * parse a HTML string
     *
     * @param string $html
     * @return string markdown formatted
     */
    public function parseString($html)
    {
        $this->parser->html = $html;
        $this->parse();

        return $this->output;
    }

    /**
     * set the position where the link reference will be displayed
     *
     * @param int $linkPosition
     * @return void
     */
    public function setLinkPosition($linkPosition)
    {
        $this->linkPosition = $linkPosition;
    }

    /**
     * set keep HTML tags which cannot be converted to markdown
     *
     * @param bool $linkPosition
     * @return void
     */
    public function setKeepHTML($keepHTML)
    {
        $this->keepHTML = $keepHTML;
    }

    /**
     * iterate through the nodes and decide what we
     * shall do with the current node
     *
     * @param void
     * @return void
     */
    protected function parse()
    {
        $this->output = '';
        # drop tags
        $this->parser->html = preg_replace('#<(' . implode('|', $this->drop) . ')[^>]*>.*</\\1>#sU', '', $this->parser->html);
        while ($this->parser->nextNode()) {
            switch ($this->parser->nodeType) {
                case 'doctype':
                    break;
                case 'pi':
                case 'comment':
                    if ($this->keepHTML) {
                        $this->flushLinebreaks();
                        $this->out($this->parser->node);
                        $this->setLineBreaks(2);
                    }
                    # else drop
                    break;
                case 'text':
                    $this->handleText();
                    break;
                case 'tag':
                    if (in_array($this->parser->tagName, $this->ignore)) {
                        break;
                    }
                    if ($this->parser->isStartTag) {
                        $this->flushLinebreaks();
                    }
                    if ($this->skipConversion) {
                        $this->isMarkdownable(); # update notConverted
                        $this->handleTagToText();
                        continue;
                    }
                    if (!$this->parser->keepWhitespace && $this->parser->isBlockElement && $this->parser->isStartTag) {
                        $this->parser->html = ltrim($this->parser->html);
                    }
                    if ($this->isMarkdownable()) {
                        if ($this->parser->isBlockElement && $this->parser->isStartTag && !$this->lastWasBlockTag && !empty($this->output)) {
                            if (!empty($this->buffer)) {
                                $str =& $this->buffer[count($this->buffer) - 1];
                            } else {
                                $str =& $this->output;
                            }
                            if (substr($str, -strlen($this->indent) - 1) != "\n" . $this->indent) {
                                $str .= "\n" . $this->indent;
                            }
                        }
                        $func = 'handleTag_' . $this->parser->tagName;
                        $this->$func();
                        if ($this->linkPosition == self::LINK_AFTER_PARAGRAPH && $this->parser->isBlockElement && !$this->parser->isStartTag && empty($this->parser->openTags)) {
                            $this->flushFootnotes();
                        }
                        if (!$this->parser->isStartTag) {
                            $this->lastClosedTag = $this->parser->tagName;
                        }
                    } else {
                        $this->handleTagToText();
                        $this->lastClosedTag = '';
                    }
                    break;
                default:
                    trigger_error('invalid node type', E_USER_ERROR);
                    break;
            }
            $this->lastWasBlockTag = $this->parser->nodeType == 'tag' && $this->parser->isStartTag && $this->parser->isBlockElement;
        }
        if (!empty($this->buffer)) {
            // trigger_error('buffer was not flushed, this is a bug. please report!', E_USER_WARNING);
            while (!empty($this->buffer)) {
                $this->out($this->unbuffer());
            }
        }
        ### cleanup
        $this->output = rtrim(str_replace('&amp;', '&', str_replace('&lt;', '<', str_replace('&gt;', '>', $this->output))));
        # end parsing, flush stacked tags
        $this->flushFootnotes();
        $this->stack = array();
    }

    /**
     * check if current tag can be converted to Markdown
     *
     * @param void
     * @return bool
     */
    protected function isMarkdownable()
    {
        if (!isset($this->isMarkdownable[$this->parser->tagName])) {
            # simply not markdownable

            return false;
        }
        if ($this->parser->isStartTag) {
            $return = true;
            if ($this->keepHTML) {
                $diff = array_diff(array_keys($this->parser->tagAttributes), array_keys($this->isMarkdownable[$this->parser->tagName]));
                if (!empty($diff)) {
                    # non markdownable attributes given
                    $return = false;
                }
            }
            if ($return) {
                foreach ($this->isMarkdownable[$this->parser->tagName] as $attr => $type) {
                    if ($type == 'required' && !isset($this->parser->tagAttributes[$attr])) {
                        # required markdown attribute not given
                        $return = false;
                        break;
                    }
                }
            }
            if (!$return) {
                array_push($this->notConverted, $this->parser->tagName . '::' . implode('/', $this->parser->openTags));
            }

            return $return;
        } else {
            if (!empty($this->notConverted) && end($this->notConverted) === $this->parser->tagName . '::' . implode('/', $this->parser->openTags)) {
                array_pop($this->notConverted);

                return false;
            }

            return true;
        }
    }

    /**
     * output footnotes
     *
     * @param void
     * @return void
     */
    protected function flushFootnotes()
    {
        $out = false;
        foreach ($this->footnotes as $k => $tag) {
            if (!isset($tag['unstacked'])) {
                if (!$out) {
                    $out = true;
                    $this->out("\n\n", true);
                } else {
                    $this->out("\n", true);
                }
                $this->out(' [' . $tag['linkID'] . ']: ' . $this->getLinkReference($tag), true);
                $tag['unstacked'] = true;
                $this->footnotes[$k] = $tag;
            }
        }
    }

    /**
     * return formated link reference
     *
     * @param array $tag
     * @return string link reference
     */
    protected function getLinkReference($tag)
    {
        return $tag['href'] . (isset($tag['title']) ? ' "' . $tag['title'] . '"' : '');
    }

    /**
     * flush enqued linebreaks
     *
     * @param void
     * @return void
     */
    protected function flushLinebreaks()
    {
        if ($this->lineBreaks && !empty($this->output)) {
            $this->out(str_repeat("\n" . $this->indent, $this->lineBreaks), true);
        }
        $this->lineBreaks = 0;
    }

    /**
     * handle non Markdownable tags
     *
     * @param void
     * @return void
     */
    protected function handleTagToText()
    {
        if (!$this->keepHTML) {
            if (!$this->parser->isStartTag && $this->parser->isBlockElement) {
                $this->setLineBreaks(2);
            }
        } else {
            # dont convert to markdown inside this tag
            /** TODO: markdown extra **/
            if (!$this->parser->isEmptyTag) {
                if ($this->parser->isStartTag) {
                    if (!$this->skipConversion) {
                        $this->skipConversion = $this->parser->tagName . '::' . implode('/', $this->parser->openTags);
                    }
                } else {
                    if ($this->skipConversion == $this->parser->tagName . '::' . implode('/', $this->parser->openTags)) {
                        $this->skipConversion = false;
                    }
                }
            }

            if ($this->parser->isBlockElement) {
                if ($this->parser->isStartTag) {
                    if (in_array($this->parent(), array('ins', 'del'))) {
                        # looks like ins or del are block elements now
                        $this->out("\n", true);
                        $this->indent('  ');
                    }
                    if ($this->parser->tagName != 'pre') {
                        $this->out($this->parser->node . "\n" . $this->indent);
                        if (!$this->parser->isEmptyTag) {
                            $this->indent('  ');
                        } else {
                            $this->setLineBreaks(1);
                        }
                        $this->parser->html = ltrim($this->parser->html);
                    } else {
                        # don't indent inside <pre> tags
                        $this->out($this->parser->node);
                        static $indent;
                        $indent = $this->indent;
                        $this->indent = '';
                    }
                } else {
                    if (!$this->parser->keepWhitespace) {
                        $this->output = rtrim($this->output);
                    }
                    if ($this->parser->tagName != 'pre') {
                        $this->indent('  ');
                        $this->out("\n" . $this->indent . $this->parser->node);
                    } else {
                        # reset indentation
                        $this->out($this->parser->node);
                        static $indent;
                        $this->indent = $indent;
                    }

                    if (in_array($this->parent(), array('ins', 'del'))) {
                        # ins or del was block element
                        $this->out("\n");
                        $this->indent('  ');
                    }
                    if ($this->parser->tagName == 'li') {
                        $this->setLineBreaks(1);
                    } else {
                        $this->setLineBreaks(2);
                    }
                }
            } else {
                $this->out($this->parser->node);
            }
            if (in_array($this->parser->tagName, array('code', 'pre'))) {
                if ($this->parser->isStartTag) {
                    $this->buffer();
                } else {
                    # add stuff so cleanup just reverses this
                    $this->out(str_replace('&lt;', '&amp;lt;', str_replace('&gt;', '&amp;gt;', $this->unbuffer())));
                }
            }
        }
    }

    /**
     * handle plain text
     *
     * @param void
     * @return void
     */
    protected function handleText()
    {
        if ($this->hasParent('pre') && strpos($this->parser->node, "\n") !== false) {
            $this->parser->node = str_replace("\n", "\n" . $this->indent, $this->parser->node);
        }
        if (!$this->hasParent('code') && !$this->hasParent('pre')) {
            # entity decode
            $this->parser->node = $this->decode($this->parser->node);
            if (!$this->skipConversion) {
                # escape some chars in normal Text
                $this->parser->node = preg_replace($this->escapeInText['search'], $this->escapeInText['replace'], $this->parser->node);
            }
        } else {
            $this->parser->node = str_replace(array('&quot;', '&apos'), array('"', '\''), $this->parser->node);
        }
        $this->out($this->parser->node);
        $this->lastClosedTag = '';
    }

    /**
     * handle <em> and <i> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_em()
    {
        $this->out('*', true);
    }

    protected function handleTag_i()
    {
        $this->handleTag_em();
    }

    /**
     * handle <strong> and <b> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_strong()
    {
        $this->out('**', true);
    }

    protected function handleTag_b()
    {
        $this->handleTag_strong();
    }

    /**
     * handle <h1> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_h1()
    {
        $this->handleHeader(1);
    }

    /**
     * handle <h2> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_h2()
    {
        $this->handleHeader(2);
    }

    /**
     * handle <h3> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_h3()
    {
        $this->handleHeader(3);
    }

    /**
     * handle <h4> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_h4()
    {
        $this->handleHeader(4);
    }

    /**
     * handle <h5> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_h5()
    {
        $this->handleHeader(5);
    }

    /**
     * handle <h6> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_h6()
    {
        $this->handleHeader(6);
    }

    /**
     * handle header tags (<h1> - <h6>)
     *
     * @param int $level 1-6
     * @return void
     */
    protected function handleHeader($level)
    {
        if ($this->parser->isStartTag) {
            $this->out(str_repeat('#', $level) . ' ', true);
        } else {
            $this->setLineBreaks(2);
        }
    }

    /**
     * handle <p> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_p()
    {
        if (!$this->parser->isStartTag) {
            $this->setLineBreaks(2);
        }
    }

    /**
     * handle <a> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_a()
    {
        if ($this->parser->isStartTag) {
            $this->buffer();
            $this->handleTag_a_parser();
            $this->stack();
        } else {
            $tag = $this->unstack();
            $buffer = $this->unbuffer();
            $this->handleTag_a_converter($tag, $buffer);
            $this->out($this->handleTag_a_converter($tag, $buffer), true);
        }
    }

    /**
     * handle <a> tags parsing
     *
     * @param void
     * @return void
     */
    protected function handleTag_a_parser()
    {
        if (isset($this->parser->tagAttributes['title'])) {
            $this->parser->tagAttributes['title'] = $this->decode($this->parser->tagAttributes['title']);
        } else {
            $this->parser->tagAttributes['title'] = null;
        }
        $this->parser->tagAttributes['href'] = $this->decode(trim($this->parser->tagAttributes['href']));
    }

    /**
     * handle <a> tags conversion
     *
     * @param array $tag
     * @param string $buffer
     * @return string The markdownified link
     */
    protected function handleTag_a_converter($tag, $buffer)
    {
        if (empty($tag['href']) && empty($tag['title'])) {
            # empty links... testcase mania, who would possibly do anything like that?!
            return '[' . $buffer . ']()';
        }

        if ($buffer == $tag['href'] && empty($tag['title'])) {
            # <http://example.com>
            return '<' . $buffer . '>';
        }

        $bufferDecoded = $this->decode(trim($buffer));
        if (substr($tag['href'], 0, 7) == 'mailto:' && 'mailto:' . $bufferDecoded == $tag['href']) {
            if (is_null($tag['title'])) {
                # <mail@example.com>
                return '<' . $bufferDecoded . '>';
            }
            # [mail@example.com][1]
            # ...
            #  [1]: mailto:mail@example.com Title
            $tag['href'] = 'mailto:' . $bufferDecoded;
        }

        if ($this->linkPosition == self::LINK_IN_PARAGRAPH) {
            return '[' . $buffer . '](' . $this->getLinkReference($tag) . ')';
        }

        # [This link][id]
        foreach ($this->footnotes as $tag2) {
            if ($tag2['href'] == $tag['href'] && $tag2['title'] === $tag['title']) {
                $tag['linkID'] = $tag2['linkID'];
                break;
            }
        }
        if (!isset($tag['linkID'])) {
            $tag['linkID'] = count($this->footnotes) + 1;
            array_push($this->footnotes, $tag);
        }

        return '[' . $buffer . '][' . $tag['linkID'] . ']';
    }

    /**
     * handle <img /> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_img()
    {
        if (!$this->parser->isStartTag) {
            return; # just to be sure this is really an empty tag...
        }

        if (isset($this->parser->tagAttributes['title'])) {
            $this->parser->tagAttributes['title'] = $this->decode($this->parser->tagAttributes['title']);
        } else {
            $this->parser->tagAttributes['title'] = null;
        }
        if (isset($this->parser->tagAttributes['alt'])) {
            $this->parser->tagAttributes['alt'] = $this->decode($this->parser->tagAttributes['alt']);
        } else {
            $this->parser->tagAttributes['alt'] = null;
        }

        if (empty($this->parser->tagAttributes['src'])) {
            # support for "empty" images... dunno if this is really needed
            # but there are some test cases which do that...
            if (!empty($this->parser->tagAttributes['title'])) {
                $this->parser->tagAttributes['title'] = ' ' . $this->parser->tagAttributes['title'] . ' ';
            }
            $this->out('![' . $this->parser->tagAttributes['alt'] . '](' . $this->parser->tagAttributes['title'] . ')', true);

            return;
        } else {
            $this->parser->tagAttributes['src'] = $this->decode($this->parser->tagAttributes['src']);
        }

        $out = '![' . $this->parser->tagAttributes['alt'] . ']';
        if ($this->linkPosition == self::LINK_IN_PARAGRAPH) {
            $out .= '(' . $this->parser->tagAttributes['src'];
            if ($this->parser->tagAttributes['title']) {
                $out .= ' "' . $this->parser->tagAttributes['title'] . '"';
            }
            $out .= ')';
            $this->out($out, true);
            return ;
        }

        # ![This image][id]
        $link_id = false;
        if (!empty($this->footnotes)) {
            foreach ($this->footnotes as $tag) {
                if ($tag['href'] == $this->parser->tagAttributes['src']
                    && $tag['title'] === $this->parser->tagAttributes['title']
                ) {
                    $link_id = $tag['linkID'];
                    break;
                }
            }
        }
        if (!$link_id) {
            $link_id = count($this->footnotes) + 1;
            $tag = array(
                'href' => $this->parser->tagAttributes['src'],
                'linkID' => $link_id,
                'title' => $this->parser->tagAttributes['title']
            );
            array_push($this->footnotes, $tag);
        }
        $out .= '[' . $link_id . ']';

        $this->out($out, true);
    }

    /**
     * handle <code> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_code()
    {
        if ($this->hasParent('pre')) {
            # ignore code blocks inside <pre>

            return;
        }
        if ($this->parser->isStartTag) {
            $this->buffer();
        } else {
            $buffer = $this->unbuffer();
            # use as many backticks as needed
            preg_match_all('#`+#', $buffer, $matches);
            if (!empty($matches[0])) {
                rsort($matches[0]);

                $ticks = '`';
                while (true) {
                    if (!in_array($ticks, $matches[0])) {
                        break;
                    }
                    $ticks .= '`';
                }
            } else {
                $ticks = '`';
            }
            if ($buffer[0] == '`' || substr($buffer, -1) == '`') {
                $buffer = ' ' . $buffer . ' ';
            }
            $this->out($ticks . $buffer . $ticks, true);
        }
    }

    /**
     * handle <pre> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_pre()
    {
        if ($this->keepHTML && $this->parser->isStartTag) {
            # check if a simple <code> follows
            if (!preg_match('#^\s*<code\s*>#Us', $this->parser->html)) {
                # this is no standard markdown code block
                $this->handleTagToText();

                return;
            }
        }
        $this->indent('    ');
        if (!$this->parser->isStartTag) {
            $this->setLineBreaks(2);
        } else {
            $this->parser->html = ltrim($this->parser->html);
        }
    }

    /**
     * handle <blockquote> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_blockquote()
    {
        $this->indent('> ');
    }

    /**
     * handle <ul> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_ul()
    {
        if ($this->parser->isStartTag) {
            $this->stack();
            if (!$this->keepHTML && $this->lastClosedTag == $this->parser->tagName) {
                $this->out("\n" . $this->indent . '<!-- -->' . "\n" . $this->indent . "\n" . $this->indent);
            }
        } else {
            $this->unstack();
            if ($this->parent() != 'li' || preg_match('#^\s*(</li\s*>\s*<li\s*>\s*)?<(p|blockquote)\s*>#sU', $this->parser->html)) {
                # dont make Markdown add unneeded paragraphs
                $this->setLineBreaks(2);
            }
        }
    }

    /**
     * handle <ul> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_ol()
    {
        # same as above
        $this->parser->tagAttributes['num'] = 0;
        $this->handleTag_ul();
    }

    /**
     * handle <li> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_li()
    {
        if ($this->parent() == 'ol') {
            $parent =& $this->getStacked('ol');
            if ($this->parser->isStartTag) {
                $parent['num']++;
                $this->out($parent['num'] . '.' . str_repeat(' ', 3 - strlen($parent['num'])), true);
            }
            $this->indent('    ', false);
        } else {
            if ($this->parser->isStartTag) {
                $this->out('*   ', true);
            }
            $this->indent('    ', false);
        }
        if (!$this->parser->isStartTag) {
            $this->setLineBreaks(1);
        }
    }

    /**
     * handle <hr /> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_hr()
    {
        if (!$this->parser->isStartTag) {
            return; # just to be sure this really is an empty tag
        }
        $this->out('* * *', true);
        $this->setLineBreaks(2);
    }

    /**
     * handle <br /> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_br()
    {
        $this->out("  \n" . $this->indent, true);
        $this->parser->html = ltrim($this->parser->html);
    }

    /**
     * add current node to the stack
     * this only stores the attributes
     *
     * @param void
     * @return void
     */
    protected function stack()
    {
        if (!isset($this->stack[$this->parser->tagName])) {
            $this->stack[$this->parser->tagName] = array();
        }
        array_push($this->stack[$this->parser->tagName], $this->parser->tagAttributes);
    }

    /**
     * remove current tag from stack
     *
     * @param void
     * @return array
     */
    protected function unstack()
    {
        if (!isset($this->stack[$this->parser->tagName]) || !is_array($this->stack[$this->parser->tagName])) {
            trigger_error('Trying to unstack from empty stack. This must not happen.', E_USER_ERROR);
        }

        return array_pop($this->stack[$this->parser->tagName]);
    }

    /**
     * get last stacked element of type $tagName
     *
     * @param string $tagName
     * @return array
     */
    protected function &getStacked($tagName)
    {
        // no end() so it can be referenced
        return $this->stack[$tagName][count($this->stack[$tagName]) - 1];
    }

    /**
     * set number of line breaks before next start tag
     *
     * @param int $number
     * @return void
     */
    protected function setLineBreaks($number)
    {
        if ($this->lineBreaks < $number) {
            $this->lineBreaks = $number;
        }
    }

    /**
     * buffer next parser output until unbuffer() is called
     *
     * @param void
     * @return void
     */
    protected function buffer()
    {
        array_push($this->buffer, '');
    }

    /**
     * end current buffer and return buffered output
     *
     * @param void
     * @return string
     */
    protected function unbuffer()
    {
        return array_pop($this->buffer);
    }

    /**
     * append string to the correct var, either
     * directly to $this->output or to the current
     * buffers
     *
     * @param string $put
     * @param boolean $nowrap
     * @return void
     */
    protected function out($put, $nowrap = false)
    {
        if (empty($put)) {
            return;
        }
        if (!empty($this->buffer)) {
            $this->buffer[count($this->buffer) - 1] .= $put;
        } else {
            if ($this->bodyWidth && !$this->parser->keepWhitespace) { # wrap lines
                // get last line
                $pos = strrpos($this->output, "\n");
                if ($pos === false) {
                    $line = $this->output;
                } else {
                    $line = substr($this->output, $pos);
                }

                if ($nowrap) {
                    if ($put[0] != "\n" && $this->strlen($line) + $this->strlen($put) > $this->bodyWidth) {
                        $this->output .= "\n" . $this->indent . $put;
                    } else {
                        $this->output .= $put;
                    }

                    return;
                } else {
                    $put .= "\n"; # make sure we get all lines in the while below
                    $lineLen = $this->strlen($line);
                    while ($pos = strpos($put, "\n")) {
                        $putLine = substr($put, 0, $pos + 1);
                        $put = substr($put, $pos + 1);
                        $putLen = $this->strlen($putLine);
                        if ($lineLen + $putLen < $this->bodyWidth) {
                            $this->output .= $putLine;
                            $lineLen = $putLen;
                        } else {
                            $split = preg_split('#^(.{0,' . ($this->bodyWidth - $lineLen) . '})\b#', $putLine, 2, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_DELIM_CAPTURE);
                            $this->output .= rtrim($split[1][0]) . "\n" . $this->indent . $this->wordwrap(ltrim($split[2][0]), $this->bodyWidth, "\n" . $this->indent, false);
                        }
                    }
                    $this->output = substr($this->output, 0, -1);

                    return;
                }
            } else {
                $this->output .= $put;
            }
        }
    }

    /**
     * indent next output (start tag) or unindent (end tag)
     *
     * @param string $str indentation
     * @param bool $output add indendation to output
     * @return void
     */
    protected function indent($str, $output = true)
    {
        if ($this->parser->isStartTag) {
            $this->indent .= $str;
            if ($output) {
                $this->out($str, true);
            }
        } else {
            $this->indent = substr($this->indent, 0, -strlen($str));
        }
    }

    /**
     * decode email addresses
     *
     * @author derernst@gmx.ch <http://www.php.net/manual/en/function.html-entity-decode.php#68536>
     * @author Milian Wolff <http://milianw.de>
     */
    protected function decode($text, $quote_style = ENT_QUOTES)
    {
        return html_entity_decode($text, $quote_style, 'UTF-8');
    }

    /**
     * callback for decode() which converts a hexadecimal entity to UTF-8
     *
     * @param array $matches
     * @return string UTF-8 encoded
     */
    protected function _decode_hex($matches)
    {
        return $this->unichr(hexdec($matches[1]));
    }

    /**
     * callback for decode() which converts a numerical entity to UTF-8
     *
     * @param array $matches
     * @return string UTF-8 encoded
     */
    protected function _decode_numeric($matches)
    {
        return $this->unichr($matches[1]);
    }

    /**
     * UTF-8 chr() which supports numeric entities
     *
     * @author grey - greywyvern - com <http://www.php.net/manual/en/function.chr.php#55978>
     * @param array $matches
     * @return string UTF-8 encoded
     */
    protected function unichr($dec)
    {
        if ($dec < 128) {
            $utf = chr($dec);
        } elseif ($dec < 2048) {
            $utf = chr(192 + (($dec - ($dec % 64)) / 64));
            $utf .= chr(128 + ($dec % 64));
        } else {
            $utf = chr(224 + (($dec - ($dec % 4096)) / 4096));
            $utf .= chr(128 + ((($dec % 4096) - ($dec % 64)) / 64));
            $utf .= chr(128 + ($dec % 64));
        }

        return $utf;
    }

    /**
     * UTF-8 strlen()
     *
     * @param string $str
     * @return int
     *
     * @author dtorop 932 at hotmail dot com <http://www.php.net/manual/en/function.strlen.php#37975>
     * @author Milian Wolff <http://milianw.de>
     */
    protected function strlen($str)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($str, 'UTF-8');
        } else {
            return preg_match_all('/[\x00-\x7F\xC0-\xFD]/', $str, $var_empty);
        }
    }

    /**
     * wordwrap for utf8 encoded strings
     *
     * @param string $str
     * @param integer $len
     * @param string $what
     * @return string
     */
    protected function wordwrap($str, $width, $break, $cut = false)
    {
        if (!$cut) {
            $regexp = '#^(?:[\x00-\x7F]|[\xC0-\xFF][\x80-\xBF]+){1,' . $width . '}\b#';
        } else {
            $regexp = '#^(?:[\x00-\x7F]|[\xC0-\xFF][\x80-\xBF]+){' . $width . '}#';
        }
        $return = '';
        while (preg_match($regexp, $str, $matches)) {
            $string = $matches[0];
            $str = ltrim(substr($str, strlen($string)));
            if (!$cut && isset($str[0]) && in_array($str[0], array('.', '!', ';', ':', '?', ','))) {
                $string .= $str[0];
                $str = ltrim(substr($str, 1));
            }
            $return .= $string . $break;
        }

        return $return . ltrim($str);
    }

    /**
     * check if current node has a $tagName as parent (somewhere, not only the direct parent)
     *
     * @param string $tagName
     * @return bool
     */
    protected function hasParent($tagName)
    {
        return in_array($tagName, $this->parser->openTags);
    }

    /**
     * get tagName of direct parent tag
     *
     * @param void
     * @return string $tagName
     */
    protected function parent()
    {
        return end($this->parser->openTags);
    }
}


class ConverterExtra extends Converter
{

    /**
     * table data, including rows with content and the maximum width of each col
     *
     * @var array
     */
    protected $table = array();

    /**
     * current col
     *
     * @var int
     */
    protected $col = -1;

    /**
     * current row
     *
     * @var int
     */
    protected $row = 0;

    /**
     * constructor, see Markdownify::Markdownify() for more information
     */
    public function __construct($linksAfterEachParagraph = self::LINK_AFTER_CONTENT, $bodyWidth = MDFY_BODYWIDTH, $keepHTML = MDFY_KEEPHTML)
    {
        parent::__construct($linksAfterEachParagraph, $bodyWidth, $keepHTML);

        ### new markdownable tags & attributes
        # header ids: # foo {bar}
        $this->isMarkdownable['h1']['id'] = 'optional';
        $this->isMarkdownable['h1']['class'] = 'optional';
        $this->isMarkdownable['h2']['id'] = 'optional';
        $this->isMarkdownable['h2']['class'] = 'optional';
        $this->isMarkdownable['h3']['id'] = 'optional';
        $this->isMarkdownable['h3']['class'] = 'optional';
        $this->isMarkdownable['h4']['id'] = 'optional';
        $this->isMarkdownable['h4']['class'] = 'optional';
        $this->isMarkdownable['h5']['id'] = 'optional';
        $this->isMarkdownable['h5']['class'] = 'optional';
        $this->isMarkdownable['h6']['id'] = 'optional';
        $this->isMarkdownable['h6']['class'] = 'optional';
        # tables
        $this->isMarkdownable['table'] = array();
        $this->isMarkdownable['th'] = array(
            'align' => 'optional',
        );
        $this->isMarkdownable['td'] = array(
            'align' => 'optional',
        );
        $this->isMarkdownable['tr'] = array();
        array_push($this->ignore, 'thead');
        array_push($this->ignore, 'tbody');
        array_push($this->ignore, 'tfoot');
        # definition lists
        $this->isMarkdownable['dl'] = array();
        $this->isMarkdownable['dd'] = array();
        $this->isMarkdownable['dt'] = array();
        # link class
        $this->isMarkdownable['a']['id'] = 'optional';
        $this->isMarkdownable['a']['class'] = 'optional';
        # footnotes
        $this->isMarkdownable['fnref'] = array(
            'target' => 'required',
        );
        $this->isMarkdownable['footnotes'] = array();
        $this->isMarkdownable['fn'] = array(
            'name' => 'required',
        );
        $this->parser->blockElements['fnref'] = false;
        $this->parser->blockElements['fn'] = true;
        $this->parser->blockElements['footnotes'] = true;
        # abbr
        $this->isMarkdownable['abbr'] = array(
            'title' => 'required',
        );
        # build RegEx lookahead to decide wether table can pe parsed or not
        $inlineTags = array_keys($this->parser->blockElements, false);
        $colContents = '(?:[^<]|<(?:' . implode('|', $inlineTags) . '|[^a-z]))+';
        $this->tableLookaheadHeader = '{
    ^\s*(?:<thead\s*>)?\s*                                  # open optional thead
      <tr\s*>\s*(?:                                         # start required row with headers
        <th(?:\s+align=("|\')(?:left|center|right)\1)?\s*>  # header with optional align
        \s*' . $colContents . '\s*                              # contents
        </th>\s*                                            # close header
      )+</tr>                                               # close row with headers
    \s*(?:</thead>)?                                        # close optional thead
    }sxi';
        $this->tdSubstitute = '\s*' . $colContents . '\s*           # contents
          </td>\s*';
        $this->tableLookaheadBody = '{
      \s*(?:<tbody\s*>)?\s*                                 # open optional tbody
        (?:<tr\s*>\s*                                       # start row
          %s                                                # cols to be substituted
        </tr>)+                                             # close row
      \s*(?:</tbody>)?                                      # close optional tbody
    \s*</table>                                             # close table
    }sxi';
    }

    /**
     * handle header tags (<h1> - <h6>)
     *
     * @param int $level 1-6
     * @return void
     */
    protected function handleHeader($level)
    {
        if ($this->parser->isStartTag) {
            $this->parser->tagAttributes['cssSelector'] = $this->getCurrentCssSelector();
            $this->stack();
        } else {
            $tag = $this->unstack();
            if (!empty($tag['cssSelector'])) {
                # {#id.class}
                $this->out(' {'.$tag['cssSelector'].'}');
            }
        }
        parent::handleHeader($level);
    }

    /**
     * handle <a> tags parsing
     *
     * @param void
     * @return void
     */
    protected function handleTag_a_parser()
    {
        parent::handleTag_a_parser();
        $this->parser->tagAttributes['cssSelector'] = $this->getCurrentCssSelector();
    }

    /**
     * handle <a> tags conversion
     *
     * @param array $tag
     * @param string $buffer
     * @return string The markdownified link
     */
    protected function handleTag_a_converter($tag, $buffer)
    {
        $output = parent::handleTag_a_converter($tag, $buffer);
        if (!empty($tag['cssSelector'])) {
            # [This link][id]{#id.class}
            $output .= '{'.$tag['cssSelector'].'}';
        }

        return $output;
    }

    /**
     * handle <abbr> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_abbr()
    {
        if ($this->parser->isStartTag) {
            $this->stack();
            $this->buffer();
        } else {
            $tag = $this->unstack();
            $tag['text'] = $this->unbuffer();
            $add = true;
            foreach ($this->stack['abbr'] as $stacked) {
                if ($stacked['text'] == $tag['text']) {
                    /** TODO: differing abbr definitions, i.e. different titles for same text **/
                    $add = false;
                    break;
                }
            }
            $this->out($tag['text']);
            if ($add) {
                array_push($this->stack['abbr'], $tag);
            }
        }
    }

    /**
     * flush stacked abbr tags
     *
     * @param void
     * @return void
     */
    protected function flushStacked_abbr()
    {
        $out = array();
        foreach ($this->stack['abbr'] as $k => $tag) {
            if (!isset($tag['unstacked'])) {
                array_push($out, ' *[' . $tag['text'] . ']: ' . $tag['title']);
                $tag['unstacked'] = true;
                $this->stack['abbr'][$k] = $tag;
            }
        }
        if (!empty($out)) {
            $this->out("\n\n" . implode("\n", $out));
        }
    }

    /**
     * handle <table> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_table()
    {
        if ($this->parser->isStartTag) {
            # check if upcoming table can be converted
            if ($this->keepHTML) {
                if (preg_match($this->tableLookaheadHeader, $this->parser->html, $matches)) {
                    # header seems good, now check body
                    # get align & number of cols
                    preg_match_all('#<th(?:\s+align=("|\')(left|right|center)\1)?\s*>#si', $matches[0], $cols);
                    $regEx = '';
                    $i = 1;
                    $aligns = array();
                    foreach ($cols[2] as $align) {
                        $align = strtolower($align);
                        array_push($aligns, $align);
                        if (empty($align)) {
                            $align = 'left'; # default value
                        }
                        $td = '\s+align=("|\')' . $align . '\\' . $i;
                        $i++;
                        if ($align == 'left') {
                            # look for empty align or left
                            $td = '(?:' . $td . ')?';
                        }
                        $td = '<td' . $td . '\s*>';
                        $regEx .= $td . $this->tdSubstitute;
                    }
                    $regEx = sprintf($this->tableLookaheadBody, $regEx);
                    if (preg_match($regEx, $this->parser->html, $matches, null, strlen($matches[0]))) {
                        # this is a markdownable table tag!
                        $this->table = array(
                            'rows' => array(),
                            'col_widths' => array(),
                            'aligns' => $aligns,
                        );
                        $this->row = 0;
                    } else {
                        # non markdownable table
                        $this->handleTagToText();
                    }
                } else {
                    # non markdownable table
                    $this->handleTagToText();
                }
            } else {
                $this->table = array(
                    'rows' => array(),
                    'col_widths' => array(),
                    'aligns' => array(),
                );
                $this->row = 0;
            }
        } else {
            # finally build the table in Markdown Extra syntax
            $separator = array();
            # seperator with correct align identifikators
            foreach ($this->table['aligns'] as $col => $align) {
                if (!$this->keepHTML && !isset($this->table['col_widths'][$col])) {
                    break;
                }
                $left = ' ';
                $right = ' ';
                switch ($align) {
                    case 'left':
                        $left = ':';
                        break;
                    case 'center':
                        $right = ':';
                        $left = ':';
                    case 'right':
                        $right = ':';
                        break;
                }
                array_push($separator, $left . str_repeat('-', $this->table['col_widths'][$col]) . $right);
            }
            $separator = '|' . implode('|', $separator) . '|';

            $rows = array();
            # add padding
            array_walk_recursive($this->table['rows'], array(&$this, 'alignTdContent'));
            $header = array_shift($this->table['rows']);
            array_push($rows, '| ' . implode(' | ', $header) . ' |');
            array_push($rows, $separator);
            foreach ($this->table['rows'] as $row) {
                array_push($rows, '| ' . implode(' | ', $row) . ' |');
            }
            $this->out(implode("\n" . $this->indent, $rows));
            $this->table = array();
            $this->setLineBreaks(2);
        }
    }

    /**
     * properly pad content so it is aligned as whished
     * should be used with array_walk_recursive on $this->table['rows']
     *
     * @param string &$content
     * @param int $col
     * @return void
     */
    protected function alignTdContent(&$content, $col)
    {
        switch ($this->table['aligns'][$col]) {
            default:
            case 'left':
                $content .= str_repeat(' ', $this->table['col_widths'][$col] - $this->strlen($content));
                break;
            case 'right':
                $content = str_repeat(' ', $this->table['col_widths'][$col] - $this->strlen($content)) . $content;
                break;
            case 'center':
                $paddingNeeded = $this->table['col_widths'][$col] - $this->strlen($content);
                $left = floor($paddingNeeded / 2);
                $right = $paddingNeeded - $left;
                $content = str_repeat(' ', $left) . $content . str_repeat(' ', $right);
                break;
        }
    }

    /**
     * handle <tr> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_tr()
    {
        if ($this->parser->isStartTag) {
            $this->col = -1;
        } else {
            $this->row++;
        }
    }

    /**
     * handle <td> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_td()
    {
        if ($this->parser->isStartTag) {
            $this->col++;
            if (!isset($this->table['col_widths'][$this->col])) {
                $this->table['col_widths'][$this->col] = 0;
            }
            $this->buffer();
        } else {
            $buffer = trim($this->unbuffer());
            $this->table['col_widths'][$this->col] = max($this->table['col_widths'][$this->col], $this->strlen($buffer));
            $this->table['rows'][$this->row][$this->col] = $buffer;
        }
    }

    /**
     * handle <th> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_th()
    {
        if (!$this->keepHTML && !isset($this->table['rows'][1]) && !isset($this->table['aligns'][$this->col + 1])) {
            if (isset($this->parser->tagAttributes['align'])) {
                $this->table['aligns'][$this->col + 1] = $this->parser->tagAttributes['align'];
            } else {
                $this->table['aligns'][$this->col + 1] = '';
            }
        }
        $this->handleTag_td();
    }

    /**
     * handle <dl> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_dl()
    {
        if (!$this->parser->isStartTag) {
            $this->setLineBreaks(2);
        }
    }

    /**
     * handle <dt> tags
     *
     * @param void
     * @return void
     **/
    protected function handleTag_dt()
    {
        if (!$this->parser->isStartTag) {
            $this->setLineBreaks(1);
        }
    }

    /**
     * handle <dd> tags
     *
     * @param void
     * @return void
     */
    protected function handleTag_dd()
    {
        if ($this->parser->isStartTag) {
            if (substr(ltrim($this->parser->html), 0, 3) == '<p>') {
                # next comes a paragraph, so we'll need an extra line
                $this->out("\n" . $this->indent);
            } elseif (substr($this->output, -2) == "\n\n") {
                $this->output = substr($this->output, 0, -1);
            }
            $this->out(':   ');
            $this->indent('    ', false);
        } else {
            # lookahead for next dt
            if (substr(ltrim($this->parser->html), 0, 4) == '<dt>') {
                $this->setLineBreaks(2);
            } else {
                $this->setLineBreaks(1);
            }
            $this->indent('    ');
        }
    }

    /**
     * handle <fnref /> tags (custom footnote references, see markdownify_extra::parseString())
     *
     * @param void
     * @return void
     */
    protected function handleTag_fnref()
    {
        $this->out('[^' . $this->parser->tagAttributes['target'] . ']');
    }

    /**
     * handle <fn> tags (custom footnotes, see markdownify_extra::parseString()
     * and markdownify_extra::_makeFootnotes())
     *
     * @param void
     * @return void
     */
    protected function handleTag_fn()
    {
        if ($this->parser->isStartTag) {
            $this->out('[^' . $this->parser->tagAttributes['name'] . ']:');
            $this->setLineBreaks(1);
        } else {
            $this->setLineBreaks(2);
        }
        $this->indent('    ');
    }

    /**
     * handle <footnotes> tag (custom footnotes, see markdownify_extra::parseString()
     *  and markdownify_extra::_makeFootnotes())
     *
     * @param void
     * @return void
     */
    protected function handleTag_footnotes()
    {
        if (!$this->parser->isStartTag) {
            $this->setLineBreaks(2);
        }
    }

    /**
     * parse a HTML string, clean up footnotes prior
     *
     * @param string $HTML input
     * @return string Markdown formatted output
     */
    public function parseString($html)
    {
        /** TODO: custom markdown-extra options, e.g. titles & classes **/
        # <sup id="fnref:..."><a href"#fn..." rel="footnote">...</a></sup>
        # => <fnref target="..." />
        $html = preg_replace('@<sup id="fnref:([^"]+)">\s*<a href="#fn:\1" rel="footnote">\s*\d+\s*</a>\s*</sup>@Us', '<fnref target="$1" />', $html);
        # <div class="footnotes">
        # <hr />
        # <ol>
        #
        # <li id="fn:...">...</li>
        # ...
        #
        # </ol>
        # </div>
        # =>
        # <footnotes>
        #   <fn name="...">...</fn>
        #   ...
        # </footnotes>
        $html = preg_replace_callback('#<div class="footnotes">\s*<hr />\s*<ol>\s*(.+)\s*</ol>\s*</div>#Us', array(&$this, '_makeFootnotes'), $html);

        return parent::parseString($html);
    }

    /**
     * replace HTML representation of footnotes with something more easily parsable
     *
     * @note this is a callback to be used in parseString()
     *
     * @param array $matches
     * @return string
     */
    protected function _makeFootnotes($matches)
    {
        # <li id="fn:1">
        #   ...
        #   <a href="#fnref:block" rev="footnote">&#8617;</a></p>
        # </li>
        # => <fn name="1">...</fn>
        # remove footnote link
        $fns = preg_replace('@\s*(&#160;\s*)?<a href="#fnref:[^"]+" rev="footnote"[^>]*>&#8617;</a>\s*@s', '', $matches[1]);
        # remove empty paragraph
        $fns = preg_replace('@<p>\s*</p>@s', '', $fns);
        # <li id="fn:1">...</li> -> <footnote nr="1">...</footnote>
        $fns = str_replace('<li id="fn:', '<fn name="', $fns);

        $fns = '<footnotes>' . $fns . '</footnotes>';

        return preg_replace('#</li>\s*(?=(?:<fn|</footnotes>))#s', '</fn>$1', $fns);
    }

    /**
     * handle <a> tags parsing
     *
     * @param void
     * @return void
     */
    protected function getCurrentCssSelector()
    {
        $cssSelector = '';
        if (isset($this->parser->tagAttributes['id'])) {
            $cssSelector .= '#'.$this->decode($this->parser->tagAttributes['id']);
        }
        if (isset($this->parser->tagAttributes['class'])) {
            $classes = explode(' ', $this->decode($this->parser->tagAttributes['class']));
            $classes = array_filter($classes);
            $cssSelector .= '.'.join('.', $classes);
        }
        return $cssSelector;
    }
}
