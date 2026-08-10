<?php

if (!defined('ABSPATH')) {
	exit;
}

function ttck_is_JSON(...$args) {
    if(is_array(...$args)) return true;
    json_decode(...$args);
    return (json_last_error()===JSON_ERROR_NONE);
}

function ttck_recursive_sanitize_text_field($array) {
    foreach ( $array as $key => &$value ) {
        if ( is_array( $value ) ) {
            $value = ttck_recursive_sanitize_text_field($value);
        }
        else {
            $value = sanitize_text_field( $value );
        }
    }

    return $array;
}

function ttck_generate_random_string($length = 10, $characters=null) {
	if($characters==null) $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$charactersLength = strlen($characters);
	$randomString = '';
	for ($i = 0; $i < $length; $i++) {
		$randomString .= $characters[rand(0, $charactersLength - 1)];
	}
	return $randomString;
}

function ttck_getHeader(){
	$headers = array();

    $copy_server = array(
        'CONTENT_TYPE'   => 'Content-Type',
        'CONTENT_LENGTH' => 'Content-Length',
        'CONTENT_MD5'    => 'Content-Md5',
    );

    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $key = substr($key, 5);
            if (!isset($copy_server[$key]) || !isset($_SERVER[$key])) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
                $headers[$key] = $value;
            }
        } elseif (isset($copy_server[$key])) {
            $headers[$copy_server[$key]] = $value;
        }
    }

    if (!isset($headers['Authorization'])) {
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = sanitize_text_field($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } elseif (isset($_SERVER['PHP_AUTH_USER'])) {
            $basic_pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
            $headers['Authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $basic_pass);
        } elseif (isset($_SERVER['PHP_AUTH_DIGEST'])) {
            $headers['Authorization'] = sanitize_text_field($_SERVER['PHP_AUTH_DIGEST']);
        }
    }

    return $headers;
}

/**
 * Build the regex matching "<prefix><digits>" inside a bank transfer description.
 *
 * $insensitive === 'yes' means "bỏ qua hoa/thường", so it must add the `i` flag.
 * $prefix comes from settings and can contain regex metacharacters, so it is quoted.
 */
function ttck_prefix_regex($prefix, $insensitive){
	$flags = ($insensitive == 'yes') ? 'mi' : 'm';
	return '/' . preg_quote((string) $prefix, '/') . '\d+/' . $flags;
}

/**
 * Bóc mã yêu cầu thanh toán (tiền tố + số) từ nội dung chuyển khoản.
 */
function ttck_parse_code($des, $prefix, $insensitive){
	$re = ttck_prefix_regex($prefix, $insensitive);

	preg_match_all($re, $des, $matches, PREG_SET_ORDER, 0);

	if (count($matches) == 0 )
		return null;

	return $matches[0][0];
}

/**
 * Bóc phần số (ID yêu cầu thanh toán) từ nội dung chuyển khoản.
 */
function ttck_parse_order_id($des, $prefix, $insensitive){
	$orderCode = ttck_parse_code($des, $prefix, $insensitive);
	if ($orderCode === null) {
		return null;
	}

	return intval(substr($orderCode, strlen((string) $prefix)));
}

function ttck_clean_prefix($string)
{
	$string = str_replace(' ', '', $string); // Replaces all spaces with hyphens.
	if (strlen($string) > 15) {
		$string = substr($string, 0, 15);
	}
	return preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
}

function ttck_getCurrentDomain()
{
	$protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

	return sanitize_url($protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
}

function ttck_reset_token() {
	$opt = TTCKPayment::get_settings();
	if(!empty($opt['bank_transfer']['secure_token'])) {
		unset($opt['bank_transfer']['secure_token']);
		TTCKPayment::update_settings($opt);
	}
}
