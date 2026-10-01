<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Http;

final class CurlHttpClient implements HttpClient
{
    /**
     * @param list<string> $headers
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        if (!function_exists('curl_init')) {
            return new HttpResponse(0, '', 'PHP curl extension is not installed.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return new HttpResponse(0, '', 'Could not initialise HTTP client.');
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if (defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            return new HttpResponse(0, '', $error !== '' ? $error : 'HTTP request failed.');
        }
        return new HttpResponse($status, $raw, null);
    }
}
