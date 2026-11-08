<?php

namespace GuardLMS\Chamilo;

final class Http
{
    public static function base()
    {
        $base = rtrim(getenv('CHAMILO_GUARDLMS_URL') ?: 'https://guardlms.com', '/');
        if (!preg_match('~^https://[^/?#]+$~D', $base)) {
            throw new \RuntimeException('GuardLMS requires an HTTPS origin.');
        }
        return $base;
    }

    public static function post($path, array $payload, $key = null, $timeout = 15)
    {
        if (!in_array($path, ['/api/externalpush/chamilo', '/api/integrations/exchange', '/api/integrations/disconnect', '/api/integrations/sdk-key', '/api/sdk/errors/collect'], true)) {
            throw new \RuntimeException('Unexpected GuardLMS endpoint.');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($key !== null) {
            $headers[] = ($path === '/api/sdk/errors/collect' ? 'X-SDK-Key: ' : 'Authorization: Bearer ').$key;
        }
        if (isset($payload['_origin'])) {
            $headers[] = 'Origin: '.$payload['_origin'];
            unset($payload['_origin']);
        }
        $handle = curl_init(self::base().$path);
        $body = '';
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_WRITEFUNCTION => static function ($h, $data) use (&$body) {
                if (strlen($body) + strlen($data) > 1048576) {
                    return 0;
                }
                $body .= $data;
                return strlen($data);
            }]);
        $success = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($success === false) {
            throw new \RuntimeException('GuardLMS HTTPS delivery failed. Check connectivity and certificate trust.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(in_array($status, [401, 403], true) ? 'Reconnect required: GuardLMS refused the credential.' : 'GuardLMS refused the report (HTTP '.$status.').', $status);
        }
        return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }
}
