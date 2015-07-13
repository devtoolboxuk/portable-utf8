<?php

/*
 * Copyright (C) 2013 Nicolas Grekas - p@tchwork.com
 *
 * This library is free software; you can redistribute it and/or modify it
 * under the terms of the (at your option):
 * Apache License v2.0 (http://apache.org/licenses/LICENSE-2.0.txt), or
 * GNU General Public License v2.0 (http://gnu.org/licenses/gpl-2.0.txt).
 */

namespace voku\helper;

use voku\helper\shim\Intl;
use voku\helper\shim\Normalizer;

/**
 * Class Bootup
 *
 * this is a bootstrap for the polyfills (iconv / intl / mbstring / normalizer / xml)
 *
 * @package voku\helper
 */
class Bootup
{
  /**
   * bootstrap
   */
  public static function initAll()
  {
    ini_set('default_charset', 'UTF-8');

    self::initUtf8Encode();
    self::initIconv();
    self::initMbstring();
    self::initExif();
    self::initIntl();
    self::initLocale();
  }

  /**
   * init utf8_encode
   */
  protected static function initUtf8Encode()
  {
    function_exists('utf8_encode') or require __DIR__ . '/bootup/utf8_encode.php';
  }

  /**
   * init iconv
   */
  protected static function initIconv()
  {
    if (extension_loaded('iconv')) {
      if ('UTF-8' !== strtoupper(iconv_get_encoding('input_encoding'))) {
        iconv_set_encoding('input_encoding', 'UTF-8');
      }

      if ('UTF-8' !== strtoupper(iconv_get_encoding('internal_encoding'))) {
        iconv_set_encoding('internal_encoding', 'UTF-8');
      }

      if ('UTF-8' !== strtoupper(iconv_get_encoding('output_encoding'))) {
        iconv_set_encoding('output_encoding', 'UTF-8');
      }
    } else if (!defined('ICONV_IMPL')) {
      require __DIR__ . '/bootup/iconv.php';
    }
  }

  /**
   * init mbstring
   */
  protected static function initMbstring()
  {
    if (extension_loaded('mbstring')) {
      if (
          (
              (int)ini_get('mbstring.encoding_translation')
              ||
              in_array(
                  strtolower(ini_get('mbstring.encoding_translation')),
                  array(
                      'on',
                      'yes',
                      'true',
                  ),
                  true
              )
          )
          &&
          !in_array(
              strtolower(ini_get('mbstring.http_input')),
              array(
                  'pass',
                  '8bit',
                  'utf-8',
              ),
              true
          )
      ) {
        user_error(
            'php.ini settings: Please disable mbstring.encoding_translation or set mbstring.http_input to "pass"',
            E_USER_WARNING
        );
      }

      if (MB_OVERLOAD_STRING & (int)ini_get('mbstring.func_overload')) {
        user_error('php.ini settings: Please disable mbstring.func_overload', E_USER_WARNING);
      }

      if (function_exists('mb_regex_encoding')) {
        mb_regex_encoding('UTF-8');
      }
      ini_set('mbstring.script_encoding', 'pass');

      if ('utf-8' !== strtolower(mb_internal_encoding())) {
        mb_internal_encoding('UTF-8');
      }

      if ('none' !== strtolower(mb_substitute_character())) {
        mb_substitute_character('none');
      }

      if (!in_array(
          strtolower(mb_http_output()),
          array(
              'pass',
              '8bit',
          ),
          true
      )
      ) {
        mb_http_output('pass');
      }

      if (!in_array(
          strtolower(mb_language()),
          array(
              'uni',
              'neutral',
          ),
          true
      )
      ) {
        mb_language('uni');
      }
    } else if (!defined('MB_OVERLOAD_MAIL')) {
      extension_loaded('iconv') or self::initIconv();

      require __DIR__ . '/bootup/mbstring.php';
    }
  }

  /**
   * init exif
   */
  protected static function initExif()
  {
    if (extension_loaded('exif')) {
      if (ini_get('exif.encode_unicode') && 'UTF-8' !== strtoupper(ini_get('exif.encode_unicode'))) {
        ini_set('exif.encode_unicode', 'UTF-8');
      }

      if (ini_get('exif.encode_jis') && 'UTF-8' !== strtoupper(ini_get('exif.encode_jis'))) {
        ini_set('exif.encode_jis', 'UTF-8');
      }
    }
  }

  /**
   * init intl
   */
  protected static function initIntl()
  {
    if (defined('GRAPHEME_CLUSTER_RX')) {
      return;
    }

    define('GRAPHEME_CLUSTER_RX', PCRE_VERSION >= '8.32' ? '\X' : Intl::GRAPHEME_CLUSTER_RX);

    if (!extension_loaded('intl')) {
      extension_loaded('iconv') or self::initIconv();
      extension_loaded('mbstring') or self::initMbstring();

      require __DIR__ . '/bootup/intl.php';
    }
  }

  /**
   * init locale
   */
  protected static function initLocale()
  {
    // With non-UTF-8 locale, basename() bugs.
    // Be aware that setlocale() can be slow.
    // You'd better properly configure your LANG environment variable to an UTF-8 locale.

    if ('' === basename('§')) {
      setlocale(LC_ALL, 'C.UTF-8', 'C');
      setlocale(
          LC_CTYPE,
          'en_US.UTF-8',
          'fr_FR.UTF-8',
          'es_ES.UTF-8',
          'de_DE.UTF-8',
          'ru_RU.UTF-8',
          'pt_BR.UTF-8',
          'it_IT.UTF-8',
          'ja_JP.UTF-8',
          'zh_CN.UTF-8',
          '0'
      );
    }
  }

  /**
   * Get random bytes
   *
   * @ref https://github.com/paragonie/random_compat/
   *
   * @param  int $length Output length
   *
   * @return  string
   */
  public static function get_random_bytes($length)
  {
    if (!$length || !ctype_digit((string)$length)) {
      return false;
    } else {
      $length = (int)$length;
    }

    // Unfortunately, none of the following PRNGs is guaranteed to exist ...

    if (defined(MCRYPT_DEV_URANDOM) === true) {
      $output = mcrypt_create_iv($length, MCRYPT_DEV_URANDOM);

      if (
          $output !== false
          &&
          UTF8::strlen($output, '8bit') === $length
      ) {
        return $output;
      }
    }

    /**
     * Use "/dev/arandom" or "/dev/urandom" for random numbers
     *
     * @ref http://sockpuppet.org/blog/2014/02/25/safely-generate-random-numbers
     */

    $fp = false;
    static $_urandom = null;

    $_urandom = ($_urandom === null ? is_readable('/dev/urandom') : false);
    $arandom = ($_urandom === false ? is_readable('/dev/arandom') : false);

    if (
        (
            $_urandom
            ||
            $arandom

        )
        &&
        !ini_get('open_basedir')
    ) {

      if ($_urandom) {
        $fp = fopen('/dev/urandom', 'rb');
      } else {
        $fp = fopen('/dev/arandom', 'rb');
      }

    }

    if ($fp) {

      if (function_exists('stream_set_chunk_size')) {
        stream_set_chunk_size($fp, $length);
      }

      $streamSet = 0;
      if (function_exists('stream_set_read_buffer')) {
        $streamSet = stream_set_read_buffer($fp, 0);
      }

      if ($streamSet === 0) {
        $remaining = $length;
        $buf = '';
        do {
          $read = fread($fp, $remaining);

          // we can't safely read from "urandom", so break here
          if ($read === false) {
            $buf = false;
            break;
          }

          // decrease the number of bytes returned from remaining
          $remaining -= UTF8::strlen($read, '8bit');
          $buf .= $read;

        } while ($remaining > 0);

        fclose($fp);

        if ($buf !== false) {
          if (UTF8::strlen($buf, '8bit') === $length) {
            return $buf;
          }
        }

      }
    }

    /*
     * PHP can be used to access COM objects on Windows platforms
     *
     * @ref http://php.net/manual/en/ref.com.php
     */
    if (extension_loaded('com_dotnet') && class_exists('COM') === true) {
      // init
      $buf = '';

      /** @noinspection PhpUndefinedClassInspection */
      $util = new COM('CAPICOM.Utilities.1');

      /**
       * Let's not let it loop forever. If we run N times and fail to
       * get N bytes of random data, then CAPICOM has failed us.
       */
      $execCount = 0;

      do {

        /** @noinspection PhpUndefinedMethodInspection */
        $buf .= base64_decode($util->GetRandom($length, 0));
        if (UTF8::strlen($buf, '8bit') >= $length) {
          return UTF8::substr($buf, 0, $length);
        }

        ++$execCount;

      } while ($execCount < $length);
    }

    /**
     * fallback to "openssl_random_pseudo_bytes()"
     */
    if (function_exists('openssl_random_pseudo_bytes')) {
      $output = openssl_random_pseudo_bytes($length, $strong);
      if ($output !== false && $strong === true) {
        if (UTF8::strlen($output, '8bit') === $length) {
          return $output;
        }
      }
    }

    return false;
  }

  /**
   * Determines if the current version of PHP is equal to or greater than the supplied value
   *
   * @param  string
   *
   * @return  bool  TRUE if the current version is $version or higher
   */
  public static function is_php($version)
  {
    static $_is_php;
    $version = (string)$version;
    if (!isset($_is_php[$version])) {
      $_is_php[$version] = version_compare(PHP_VERSION, $version, '>=');
    }

    return $_is_php[$version];
  }

  /**
   * filter request-uri
   *
   * @param null $uri
   * @param bool $exit
   *
   * @return bool|mixed|null
   */
  public static function filterRequestUri($uri = null, $exit = true)
  {
    if (!isset($uri)) {
      if (!isset($_SERVER['REQUEST_URI'])) {
        return false;
      } else {
        $uri = $_SERVER['REQUEST_URI'];
      }
    }

    // Ensures the URL is well formed UTF-8
    // When not, assumes Windows-1252 and redirects to the corresponding UTF-8 encoded URL

    if (!preg_match('//u', urldecode($uri))) {
      $uri = preg_replace_callback(
          '/[\x80-\xFF]+/',
          function ($m) {
            return urlencode($m[0]);
          },
          $uri
      );

      $uri = preg_replace_callback(
          '/(?:%[89A-F][0-9A-F])+/i',
          function ($m) {
            return urlencode(UTF8::encode('UTF-8', urldecode($m[0])));
          },
          $uri
      );

      if ($exit === true) {
        // Use ob_start() to buffer content and avoid problem of headers already sent...
        if (headers_sent() === false) {
          $severProtocol = (isset($_SERVER["SERVER_PROTOCOL"]) ? $_SERVER["SERVER_PROTOCOL"] : 'HTTP/1.1');
          header($severProtocol . ' 301 Moved Permanently');
          header('Location: ' . $uri);
          exit();
        }
      }
    }

    return $uri;
  }

  /**
   * filter request inputs
   *
   * Ensures inputs are well formed UTF-8
   * When not, assumes Windows-1252 and converts to UTF-8
   * Tests only values, not keys
   *
   * @param int    $normalization_form
   * @param string $leading_combining
   */
  public static function filterRequestInputs($normalization_form = 4 /* n::NFC */, $leading_combining = '◌')
  {
    $a = array(
        &$_FILES,
        &$_ENV,
        &$_GET,
        &$_POST,
        &$_COOKIE,
        &$_SERVER,
        &$_REQUEST,
    );

    foreach ($a[0] as &$r) {
      $a[] = array(
          &$r['name'],
          &$r['type'],
      );
    }
    unset($a[0]);

    $len = count($a) + 1;
    for ($i = 1; $i < $len; ++$i) {
      foreach ($a[$i] as &$r) {
        $s = $r; // $r is a ref, $s a copy
        if (is_array($s)) {
          $a[$len++] =& $r;
        } else {
          $r = self::filterString($s, $normalization_form, $leading_combining);
        }
      }

      unset($a[$i]);
    }
  }

  /**
   * @param        $s
   * @param int    $normalization_form
   * @param string $leading_combining
   *
   * @return array|bool|mixed|string
   */
  public static function filterString($s, $normalization_form = 4 /* n::NFC */, $leading_combining = '◌')
  {
    if (false !== strpos($s, "\r")) {
      // Workaround https://bugs.php.net/65732
      $s = str_replace("\r\n", "\n", $s);
      $s = strtr($s, "\r", "\n");
    }

    if (preg_match('/[\x80-\xFF]/', $s)) {
      if (Normalizer::isNormalized($s, $normalization_form)) {
        $n = '-';
      } else {
        $n = Normalizer::normalize($s, $normalization_form);
        if (isset($n[0])) {
          $s = $n;
        } else {
          $s = UTF8::encode('UTF-8', $s);
        }
      }

      if ($s[0] >= "\x80" && isset($n[0], $leading_combining[0]) && preg_match('/^\p{Mn}/u', $s)) {
        // Prevent leading combining chars
        // for NFC-safe concatenations.
        $s = $leading_combining . $s;
      }
    }

    return $s;
  }
}
