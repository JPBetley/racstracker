<?php

namespace App\LastWar;

use Illuminate\Http\Client\Response;
use RuntimeException;

class LastWarApiException extends RuntimeException
{
    /**
     * Build an exception from a failed API response.
     *
     * The API returns two incompatible error shapes: `{"detail": "message"}` for
     * ordinary failures (the documented `error` key is often absent), and
     * `{"detail": [{"loc": [...], "msg": "..."}]}` for 422 validation errors.
     * Both are flattened to a single readable message here.
     */
    public static function fromResponse(Response $response, string $context): self
    {
        return new self(sprintf(
            '%s failed with HTTP %d: %s',
            $context,
            $response->status(),
            static::readMessage($response),
        ));
    }

    /**
     * Extract a human-readable message from either error shape.
     */
    private static function readMessage(Response $response): string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return $response->body() === '' ? 'no response body' : $response->body();
        }

        $detail = $body['detail'] ?? $body['error'] ?? null;

        if (is_string($detail)) {
            return $detail;
        }

        if (is_array($detail)) {
            $messages = array_filter(array_map(
                fn ($item) => is_array($item) ? ($item['msg'] ?? null) : (is_string($item) ? $item : null),
                $detail,
            ));

            if ($messages !== []) {
                return implode('; ', $messages);
            }
        }

        return $response->body();
    }
}
