<?php

namespace App\Domain\Assets\Providers;

/**
 * sha256 by streaming — never from provider metadata (an S3 ETag is not a
 * content hash once an upload is multipart).
 */
trait ComputesObjectChecksum
{
    public function checksum(string $key): string
    {
        $stream = $this->stream($key);
        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    break;
                }
                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }
}
