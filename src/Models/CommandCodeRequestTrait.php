<?php
/**
 * Shared request creation for Command Code models.
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

namespace CommandCode\AiProvider\Models;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use CommandCode\AiProvider\Provider\CommandCodeProvider;
use CommandCode\AiProvider\Util\CommandCodeConfig;

/**
 * Builds requests against the Command Code Provider API.
 *
 * Both Command Code routes authenticate with `Authorization: Bearer <key>`, which the SDK's default
 * API key authentication already applies, so no custom authentication class is needed.
 */
trait CommandCodeRequestTrait
{
    /**
     * Creates a request object for the Command Code API.
     *
     * Satisfies the abstract `createRequest()` of the OpenAI-compatible base class.
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The API endpoint path, relative to the base URL.
     * @param array<string, string|list<string>> $headers The request headers.
     * @param string|array<string, mixed>|null $data The request data.
     * @return Request The request object.
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            CommandCodeProvider::url($path),
            self::withCommandCodeHeaders($headers),
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * Adds the headers Command Code accepts on top of the caller's own.
     *
     * @param array<string, string|list<string>> $headers The caller's headers.
     * @return array<string, string|list<string>> The augmented headers.
     */
    private static function withCommandCodeHeaders(array $headers): array
    {
        $headers['User-Agent'] = CommandCodeConfig::getUserAgent();

        /*
         * Zero data retention is opt-in: when set, Command Code routes the request only through
         * ZDR-capable upstreams and fails with a 422 if the model has none, rather than silently
         * falling back to a non-ZDR provider.
         */
        if (CommandCodeConfig::isZeroDataRetentionEnabled()) {
            $headers['x-cmd-zdr'] = '1';
        }

        return $headers;
    }
}
