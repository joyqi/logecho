<?php
/**
 * filters.php - logecho
 * 
 * @author joyqi
 */

/**
 * @param $stamp
 * @param string $format
 * @return bool|string
 */
function le_filter_date ($stamp, $format = 'Y-m-d') {
    return date($format, $stamp);
}

/**
 * @param $str
 * @param int $limit
 * @return string
 */
function le_filter_more ($str, $limit = 0) {
    if ($limit > 0) {
        $str = strip_tags($str);
        return mb_strlen($str, 'UTF-8') > $limit
            ? mb_substr($str, 0, $limit, 'UTF-8') . ' ...' : $str;
    }

    $parts = preg_split("/<!--\s*more\s*-->/is", $str);
    return count($parts) > 1 ? $parts[0] . '<p>...</p>' : $str;
}
