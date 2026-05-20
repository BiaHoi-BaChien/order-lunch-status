<?php

declare(strict_types=1);

final class CurlSupport
{
    public static function applyCaBundle(CurlHandle $handle, ?string $caBundlePath): void
    {
        if ($caBundlePath === null) {
            return;
        }

        curl_setopt($handle, CURLOPT_CAINFO, $caBundlePath);
    }
}
