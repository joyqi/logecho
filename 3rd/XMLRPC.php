<?php

/**
 * http_send
 *
 * @param mixed $url
 * @param array $data
 * @param int $timeout
 * @param mixed $agent
 * @param array $headers
 * @access public
 * @return void
 */
function http_send($url, $data = array(), $timeout = 10, $agent = NULL, array $headers = array()) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_USERAGENT, $agent ? $agent : 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)');

    // disable ssl check
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    }

    if (!empty($headers)) {
        $postHeaders = array();

        foreach ($headers as $key => $val) {
            $postHeaders[] = $key . ': ' . $val;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $postHeaders);
    }

    $response = curl_exec($ch);
    if (false === $response) {
        return false;
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    $foundStatus = false;
    $foundInfo = false;
    $result = array(
        'status'    =>  200,
        'header'    =>  array(),
        'body'      =>  $body
    );
    $lines = array_filter(array_map('trim', explode("\n", trim($header))));

    foreach ($lines as $line) {
        if (0 === strpos($line, "HTTP/")) {
            list($version, $status) = explode(' ', str_replace('  ', ' ', $line));
            $result['status'] = intval($status);
            $result['header'] = array();
        } else {
            list ($key, $val) = explode(':', $line);
            $result['header'][strtolower($key)] = trim($val);
        }
    }

    return $result;
}

/**
 * IXR客户端
 *
 * @package IXR
 */
class XMLRPC
{
    /** 默认客户端 */
    const DEFAULT_USERAGENT = 'The Incutio XML-RPC PHP Library';

    /**
     * 服务端地址
     *
     * @access private
     * @var string
     */
    private $server;

    /**
     * 端口名称
     *
     * @access private
     * @var integer
     */
    private $port;

    /**
     * 路径名称
     *
     * @access private
     * @var string
     */
    private $path;

    /**
     * 地址
     *
     * @access private
     * @var string
     */
    private $url;

    /**
     * 客户端
     *
     * @access private
     * @var string
     */
    private $useragent;

    /**
     * 回执结构体
     *
     * @access private
     * @var string
     */
    private $response;

    /**
     * 消息体
     *
     * @access private
     * @var string
     */
    private $message = false;

    /**
     * 调试开关
     *
     * @access private
     * @var boolean
     */
    private $debug = false;

    /**
     * 请求前缀
     *
     * @access private
     * @var string
     */
    private $prefix = NULL;

    // Storage place for an error message
    private $error = false;

    /**
     * 客户端构造函数
     *
     * @access public
     * @param string $server 服务端地址
     * @param string $path 路径名称
     * @param integer $port 端口名称
     * @param string $useragent 客户端
     * @return void
     */
    public function __construct($server, $path = false, $port = 80, $useragent = self::DEFAULT_USERAGENT, $prefix = NULL)
    {
        if (!$path) {
            $this->url = $server;

            // Assume we have been given a Url instead
            $bits = parse_url($server);
            $this->server = $bits['host'];
            $this->port = isset($bits['port']) ? $bits['port'] : 80;
            $this->path = isset($bits['path']) ? $bits['path'] : '/';

            // Make absolutely sure we have a path
            if (isset($bits['query'])) {
                $this->path .= '?' . $bits['query'];
            }
        } else {
            $this->url = $this->buildUrl(array(
                'scheme'    =>  'http',
                'host'      =>  $server,
                'path'      =>  $path,
                'port'      =>  $port
            ));

            $this->server = $server;
            $this->path = $path;
            $this->port = $port;
        }

        $this->prefix = $prefix;
        $this->useragent = $useragent;
    }

    /**
     * 设置调试模式
     *
     * @access public
     * @return void
     */
    public function __setDebug()
    {
        $this->debug = true;
    }

    /**
     * buildUrl
     *
     * @param array $params
     * @access private
     * @return void
     */
    private function buildUrl(array $params)
    {
        return (isset($params['scheme']) ? $params['scheme'] . '://' : null)
        . (isset($params['user']) ? $params['user'] . (isset($params['pass']) ? ':' . $params['pass'] : null) . '@' : null)
        . (isset($params['host']) ? $params['host'] : null)
        . (isset($params['port']) ? ':' . $params['port'] : null)
        . (isset($params['path']) ? $params['path'] : null)
        . (isset($params['query']) ? '?' . $params['query'] : null)
        . (isset($params['fragment']) ? '#' . $params['fragment'] : null);
    }

    /**
     * 执行请求
     *
     * @access public
     * @return void
     */
    public function __rpcCall()
    {
        $args = func_get_args();
        $method = array_shift($args);
        $request = new IXR_Request($method, $args);
        $xml = $request->getXml();
        $response = http_send($this->url, $xml, 60, $this->useragent);

        if (!$response || 200 != $response['status']) {
            $this->error = new IXR_Error(-32700, 'network error. response with status: '
                . (isset($response['status']) ? $response['status'] : '0'));
            return false;
        }

        $contents = $response['body'];

        if ($this->debug) {
            echo '<pre>'.htmlspecialchars($contents)."\n</pre>\n\n";
        }

        // Now parse what we've got back
        $this->message = new IXR_Message($contents);
        if (!$this->message->parse()) {
            // XML error
            $this->error = new IXR_Error(-32700, 'parse error. not well formed');
            return false;
        }

        // Is the message a fault?
        if ($this->message->messageType == 'fault') {
            $this->error = new IXR_Error($this->message->faultCode, $this->message->faultString);
            return false;
        }

        // Message must be OK
        return true;
    }

    /**
     * 增加前缀
     * <code>
     * $rpc->metaWeblog->newPost();
     * </code>
     *
     * @access public
     * @param string $prefix 前缀
     * @return void
     */
    public function __get($prefix)
    {
        return new XMLRPC($this->server, $this->path, $this->port, $this->useragent, $this->prefix . $prefix . '.');
    }

    /**
     * 增加魔术特性
     * by 70
     *
     * @access public
     * @return mixed
     */
    public function __call($method, $args)
    {
        array_unshift($args, $this->prefix . $method);
        $return = call_user_func_array(array($this, '__rpcCall'), $args);

        if ($return) {
            return $this->__getResponse();
        } else {
            throw new IXR_Exception($this->__getErrorMessage(), $this->__getErrorCode());
        }
    }

    /**
     * 获得返回值
     *
     * @access public
     * @return void
     */
    public function __getResponse()
    {
        // methodResponses can only have one param - return that
        return $this->message->params[0];
    }

    /**
     * 是否为错误
     *
     * @access public
     * @return void
     */
    public function __isError()
    {
        return (is_object($this->error));
    }

    /**
     * 获取错误代码
     *
     * @access public
     * @return void
     */
    public function __getErrorCode()
    {
        return $this->error->code;
    }

    /**
     * 获取错误消息
     *
     * @access public
     * @return void
     */
    public function __getErrorMessage()
    {
        return $this->error->message;
    }
}

/**
 * IXR Base64编码
 *
 * @package IXR
 */
class IXR_Base64
{
    /**
     * 编码数据
     *
     * @var string
     */
    private $data;

    /**
     * 初始化数据
     *
     * @param string $data
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * 获取XML数据
     *
     * @return string
     */
    public function getXml()
    {
        return '<base64>' . base64_encode($this->data) . '</base64>';
    }
}

/**
 * IXR日期
 *
 * @package IXR
 */
class IXR_Date {
    var $year;
    var $month;
    var $day;
    var $hour;
    var $minute;
    var $second;
    function IXR_Date($time) {
        // $time can be a PHP timestamp or an ISO one
        if (is_numeric($time)) {
            $this->parseTimestamp($time);
        } else {
            $this->parseIso($time);
        }
    }
    function parseTimestamp($timestamp) {
        $this->year = date('Y', $timestamp);
        $this->month = date('m', $timestamp);
        $this->day = date('d', $timestamp);
        $this->hour = date('H', $timestamp);
        $this->minute = date('i', $timestamp);
        $this->second = date('s', $timestamp);
    }
    function parseIso($iso) {
        $this->year = substr($iso, 0, 4);
        $this->month = substr($iso, 4, 2);
        $this->day = substr($iso, 6, 2);
        $this->hour = substr($iso, 9, 2);
        $this->minute = substr($iso, 12, 2);
        $this->second = substr($iso, 15, 2);
    }
    function getIso() {
        return $this->year.$this->month.$this->day.'T'.$this->hour.':'.$this->minute.':'.$this->second;
    }
    function getXml() {
        return '<dateTime.iso8601>'.$this->getIso().'</dateTime.iso8601>';
    }
    function getTimestamp() {
        return mktime($this->hour, $this->minute, $this->second, $this->month, $this->day, $this->year);
    }
}

/**
 * IXR错误
 *
 * @package IXR
 */
class IXR_Error
{
    /**
     * 错误代码
     *
     * @access public
     * @var integer
     */
    public $code;

    /**
     * 错误消息
     *
     * @access public
     * @var string
     */
    public $message;

    /**
     * 构造函数
     *
     * @access public
     * @param integer $code 错误代码
     * @param string $message 错误消息
     * @return void
     */
    public function __construct($code, $message)
    {
        $this->code = $code;
        $this->message = $message;
    }

    /**
     * 获取xml
     *
     * @access public
     * @return string
     */
    public function getXml()
    {
        $xml = <<<EOD
<methodResponse>
  <fault>
    <value>
      <struct>
        <member>
          <name>faultCode</name>
          <value><int>{$this->code}</int></value>
        </member>
        <member>
          <name>faultString</name>
          <value><string>{$this->message}</string></value>
        </member>
      </struct>
    </value>
  </fault>
</methodResponse>

EOD;
        return $xml;
    }
}

/**
 * IXR异常类
 *
 * @package IXR
 */
class IXR_Exception extends Exception
{}

/**
 * IXR消息
 *
 * @package IXR
 */
class IXR_Message {
    var $message;
    var $messageType;  // methodCall / methodResponse / fault
    var $faultCode;
    var $faultString;
    var $methodName;
    var $params;
    // Current variable stacks
    var $_arraystructs = array();   // The stack used to keep track of the current array/struct
    var $_arraystructstypes = array(); // Stack keeping track of if things are structs or array
    var $_currentStructName = array();  // A stack as well
    var $_param;
    var $_value;
    var $_currentTag;
    var $_currentTagContents;
    // The XML parser
    var $_parser;
    function IXR_Message ($message) {
        $this->message = $message;
    }

    function traversal(DOMNode $node)
    {
        if (XML_ELEMENT_NODE == $node->nodeType) {
            $this->tag_open(NULL, $node->tagName, NULL);
        }

        if (XML_TEXT_NODE == $node->nodeType) {
            $this->cdata(NULL, $node->nodeValue);
        }

        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $this->traversal($child);
            }
        }

        if (XML_ELEMENT_NODE == $node->nodeType) {
            $this->tag_close(NULL, $node->tagName);
        }
    }

    function parse() {
        // first remove the XML declaration
        $this->message = preg_replace('/<\?xml(.*)?\?'.'>/', '', $this->message);
        if (trim($this->message) == '') {
            return false;
        }

        //reject overly long 2 byte sequences, as well as characters above U+10000 and replace with ?
        $this->message = preg_replace('/[\x00-\x08\x10\x0B\x0C\x0E-\x19\x7F]'.
            '|[\x00-\x7F][\x80-\xBF]+'.
            '|([\xC0\xC1]|[\xF0-\xFF])[\x80-\xBF]*'.
            '|[\xC2-\xDF]((?![\x80-\xBF])|[\x80-\xBF]{2,})'.
            '|[\xE0-\xEF](([\x80-\xBF](?![\x80-\xBF]))|(?![\x80-\xBF]{2})|[\x80-\xBF]{3,})/S',
            '?', $this->message);

        //reject overly long 3 byte sequences and UTF-16 surrogates and replace with ?
        $this->message = preg_replace('/\xE0[\x80-\x9F][\x80-\xBF]'.
            '|\xED[\xA0-\xBF][\x80-\xBF]/S','?', $this->message);

        $dom = new DOMDocument();
        $status = @$dom->loadXML($this->message);
        if (!$status) {
            return false;
        }

        $this->traversal($dom);

        /*
        $this->_parser = xml_parser_create('UTF-8');
        // Set XML parser to take the case of tags in to account
        xml_parser_set_option($this->_parser, XML_OPTION_CASE_FOLDING, false);
        xml_parser_set_option($this->_parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
        // Set XML parser callback functions
        xml_set_object($this->_parser, $this);
        xml_set_element_handler($this->_parser, 'tag_open', 'tag_close');
        xml_set_character_data_handler($this->_parser, 'cdata');
        if (!xml_parse($this->_parser, $this->message)) {
            throw new Exception(sprintf('XML error: %s at line %d',
                xml_error_string(xml_get_error_code($this->_parser)),
                xml_get_current_line_number($this->_parser)));
        }
        xml_parser_free($this->_parser);
        // Grab the error messages, if any
        if ($this->messageType == 'fault') {
            $this->faultCode = $this->params[0]['faultCode'];
            $this->faultString = $this->params[0]['faultString'];
        }
        */
        return true;
    }
    function tag_open($parser, $tag, $attr) {
        $this->currentTag = $tag;
        switch($tag) {
            case 'methodCall':
            case 'methodResponse':
            case 'fault':
                $this->messageType = $tag;
                break;
            /* Deal with stacks of arrays and structs */
            case 'data':    // data is to all intents and puposes more interesting than array
                $this->_arraystructstypes[] = 'array';
                $this->_arraystructs[] = array();
                break;
            case 'struct':
                $this->_arraystructstypes[] = 'struct';
                $this->_arraystructs[] = array();
                break;
        }
    }
    function cdata($parser, $cdata) {
        $this->_currentTagContents .= $cdata;
    }
    function tag_close($parser, $tag) {
        $valueFlag = false;
        switch($tag) {
            case 'int':
            case 'i4':
                $value = (int)trim($this->_currentTagContents);
                $this->_currentTagContents = '';
                $valueFlag = true;
                break;
            case 'double':
                $value = (double)trim($this->_currentTagContents);
                $this->_currentTagContents = '';
                $valueFlag = true;
                break;
            case 'string':
                $value = (string)trim($this->_currentTagContents);
                $this->_currentTagContents = '';
                $valueFlag = true;
                break;
            case 'dateTime.iso8601':
                $value = new IXR_Date(trim($this->_currentTagContents));
                // $value = $iso->getTimestamp();
                $this->_currentTagContents = '';
                $valueFlag = true;
                break;
            case 'value':
                // "If no type is indicated, the type is string."
                if (trim($this->_currentTagContents) != '') {
                    $value = (string)$this->_currentTagContents;
                    $this->_currentTagContents = '';
                    $valueFlag = true;
                }
                break;
            case 'boolean':
                $value = (boolean)trim($this->_currentTagContents);
                $this->_currentTagContents = '';
                $valueFlag = true;
                break;
            case 'base64':
                $value = base64_decode($this->_currentTagContents);
                $this->_currentTagContents = '';
                $valueFlag = true;
                break;
            /* Deal with stacks of arrays and structs */
            case 'data':
            case 'struct':
                $value = array_pop($this->_arraystructs);
                array_pop($this->_arraystructstypes);
                $valueFlag = true;
                break;
            case 'member':
                array_pop($this->_currentStructName);
                break;
            case 'name':
                $this->_currentStructName[] = trim($this->_currentTagContents);
                $this->_currentTagContents = '';
                break;
            case 'methodName':
                $this->methodName = trim($this->_currentTagContents);
                $this->_currentTagContents = '';
                break;
        }
        if ($valueFlag) {
            /*
            if (!is_array($value) && !is_object($value)) {
                $value = trim($value);
            }
            */
            if (count($this->_arraystructs) > 0) {
                // Add value to struct or array
                if ($this->_arraystructstypes[count($this->_arraystructstypes)-1] == 'struct') {
                    // Add to struct
                    $this->_arraystructs[count($this->_arraystructs)-1][$this->_currentStructName[count($this->_currentStructName)-1]] = $value;
                } else {
                    // Add to array
                    $this->_arraystructs[count($this->_arraystructs)-1][] = $value;
                }
            } else {
                // Just add as a paramater
                $this->params[] = $value;
            }
        }
    }
}

/**
 * IXR请求体
 *
 * @package IXR
 */
class IXR_Request {
    var $method;
    var $args;
    var $xml;
    function IXR_Request($method, $args) {
        $this->method = $method;
        $this->args = $args;
        $this->xml = <<<EOD
<?xml version="1.0"?>
<methodCall>
<methodName>{$this->method}</methodName>
<params>

EOD;
        foreach ($this->args as $arg) {
            $this->xml .= '<param><value>';
            $v = new IXR_Value($arg);
            $this->xml .= $v->getXml();
            $this->xml .= "</value></param>\n";
        }
        $this->xml .= '</params></methodCall>';
    }
    function getLength() {
        return strlen($this->xml);
    }
    function getXml() {
        return $this->xml;
    }
}

/**
 * IXR值
 *
 * @package IXR
 */
class IXR_Value {
    var $data;
    var $type;
    function IXR_Value ($data, $type = false) {
        $this->data = $data;
        if (!$type) {
            $type = $this->calculateType();
        }
        $this->type = $type;
        if ($type == 'struct') {
            /* Turn all the values in the array in to new IXR_Value objects */
            foreach ($this->data as $key => $value) {
                $this->data[$key] = new IXR_Value($value);
            }
        }
        if ($type == 'array') {
            for ($i = 0, $j = count($this->data); $i < $j; $i++) {
                $this->data[$i] = new IXR_Value($this->data[$i]);
            }
        }
    }
    function calculateType() {
        if ($this->data === true || $this->data === false) {
            return 'boolean';
        }
        if (is_integer($this->data)) {
            return 'int';
        }
        if (is_double($this->data)) {
            return 'double';
        }
        // Deal with IXR object types base64 and date
        if (is_object($this->data) && is_a($this->data, 'IXR_Date')) {
            return 'date';
        }
        if (is_object($this->data) && is_a($this->data, 'IXR_Base64')) {
            return 'base64';
        }
        // If it is a normal PHP object convert it in to a struct
        if (is_object($this->data)) {

            $this->data = get_object_vars($this->data);
            return 'struct';
        }
        if (!is_array($this->data)) {
            return 'string';
        }
        /* We have an array - is it an array or a struct ? */
        if ($this->isStruct($this->data)) {
            return 'struct';
        } else {
            return 'array';
        }
    }
    function getXml() {
        /* Return XML for this value */
        switch ($this->type) {
            case 'boolean':
                return '<boolean>'.(($this->data) ? '1' : '0').'</boolean>';
                break;
            case 'int':
                return '<int>'.$this->data.'</int>';
                break;
            case 'double':
                return '<double>'.$this->data.'</double>';
                break;
            case 'string':
                return '<string>'.htmlspecialchars($this->data).'</string>';
                break;
            case 'array':
                $return = '<array><data>'."\n";
                foreach ($this->data as $item) {
                    $return .= '  <value>'.$item->getXml()."</value>\n";
                }
                $return .= '</data></array>';
                return $return;
                break;
            case 'struct':
                $return = '<struct>'."\n";
                foreach ($this->data as $name => $value) {
                    $return .= "  <member><name>$name</name><value>";
                    $return .= $value->getXml()."</value></member>\n";
                }
                $return .= '</struct>';
                return $return;
                break;
            case 'date':
            case 'base64':
                return $this->data->getXml();
                break;
        }
        return false;
    }
    function isStruct($array) {
        /* Nasty function to check if an array is a struct or not */
        $expected = 0;
        foreach ($array as $key => $value) {
            if ((string)$key != (string)$expected) {
                return true;
            }
            $expected++;
        }
        return false;
    }
}
