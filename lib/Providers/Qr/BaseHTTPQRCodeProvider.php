<?php

declare(strict_types=1);

namespace Serevinus\Auth\Providers\Qr;

abstract class BaseHTTPQRCodeProvider implements IQRCodeProvider
{
    protected bool $verifyssl = true;

    protected function getContent(string $url): string
    {
        $curlhandle = curl_init();

        curl_setopt_array($curlhandle, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_DNS_CACHE_TIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifyssl,
            CURLOPT_USERAGENT => 'TwoFactorAuth',
        ));
        $data = curl_exec($curlhandle);
        if ($data === false) {
            throw new QRException(curl_error($curlhandle));
        }

        curl_close($curlhandle);
        return $data;
    }
}
