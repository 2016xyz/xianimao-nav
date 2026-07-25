<?php
/**
 * 出站 HTTPS / SSL 辅助：CA 证书包探测与统一 curl/stream 配置
 * 解决 Windows 常见 “unable to get local issuer certificate”
 */

if (!function_exists('security_ssl_verify_peer')) {
    /**
     * 出站 HTTPS 是否校验证书（默认开启；setting ssl_verify_peer=0 可关闭）
     * 也可通过环境变量 NAV_SSL_VERIFY_PEER=0 关闭
     */
    function security_ssl_verify_peer()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $env = getenv('NAV_SSL_VERIFY_PEER');
        if ($env !== false && $env !== '') {
            $env = strtolower(trim((string) $env));
            if (in_array($env, ['0', 'false', 'off', 'no'], true)) {
                return $cached = false;
            }
            if (in_array($env, ['1', 'true', 'on', 'yes'], true)) {
                return $cached = true;
            }
        }
        if (function_exists('setting_get')) {
            $v = strtolower(trim((string) setting_get('ssl_verify_peer', '1')));
            if (in_array($v, ['0', 'false', 'off', 'no'], true)) {
                return $cached = false;
            }
        }
        return $cached = true;
    }
}

if (!function_exists('security_ssl_cafile')) {
    /**
     * 解析可用的 CA 证书包路径
     * @return string 空字符串表示未找到
     */
    function security_ssl_cafile()
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        $candidates = [];
        $env = getenv('SSL_CERT_FILE');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        $envNav = getenv('NAV_SSL_CAFILE');
        if (is_string($envNav) && $envNav !== '') {
            $candidates[] = $envNav;
        }
        if (function_exists('setting_get')) {
            $fromSetting = (string) setting_get('ssl_cafile', '');
            if ($fromSetting !== '') {
                $candidates[] = $fromSetting;
            }
        }
        $ini = (string) ini_get('curl.cainfo');
        if ($ini !== '') {
            $candidates[] = $ini;
        }
        $iniOpen = (string) ini_get('openssl.cafile');
        if ($iniOpen !== '') {
            $candidates[] = $iniOpen;
        }
        if (defined('ROOT_PATH')) {
            $candidates[] = ROOT_PATH . '/config/cacert.pem';
            $candidates[] = ROOT_PATH . '/data/cacert.pem';
        }
        $candidates[] = 'C:/php/extras/ssl/cacert.pem';
        $candidates[] = 'C:/Windows/System32/curl-ca-bundle.crt';
        foreach ($candidates as $path) {
            $path = str_replace('\\', '/', trim((string) $path));
            if ($path !== '' && is_file($path) && is_readable($path)) {
                return $resolved = $path;
            }
        }
        return $resolved = '';
    }
}

if (!function_exists('security_curl_set_ssl')) {
    /**
     * 为 curl 句柄统一应用 SSL 校验与 CA 证书包
     * @param resource|\CurlHandle $ch
     */
    function security_curl_set_ssl($ch)
    {
        $verify = security_ssl_verify_peer();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
        if ($verify) {
            $ca = security_ssl_cafile();
            if ($ca !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }
        }
    }
}

if (!function_exists('security_stream_ssl_opts')) {
    /**
     * stream_context 用的 SSL 选项
     * @return array<string,mixed>
     */
    function security_stream_ssl_opts()
    {
        $verify = security_ssl_verify_peer();
        $opts = [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
        ];
        if ($verify) {
            $ca = security_ssl_cafile();
            if ($ca !== '') {
                $opts['cafile'] = $ca;
            }
        }
        return $opts;
    }
}
