<?php
/**
 * Plugin configuration reader.
 *
 * Intentionally free of WordPress functions so it can be loaded (and exercised) outside WordPress.
 * Every value is resolved as: environment variable > PHP constant > built-in default.
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

namespace CommandCode\AiProvider\Util;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

/**
 * Reads the plugin's optional configuration.
 */
final class CommandCodeConfig
{
    /**
     * The plugin version, reported in the User-Agent header.
     *
     * @var string
     */
    public const VERSION = '1.0.4';

    /**
     * Base URL of the Command Code Provider API.
     *
     * @var string
     */
    public const DEFAULT_BASE_URL = 'https://api.commandcode.ai/provider/v1';

    /**
     * The provider ID used by the SDK registry, the Connectors option name and the filter tuples.
     *
     * Frozen: it decides `connectors_ai_commandcode_api_key`, `COMMANDCODE_API_KEY` and the value
     * users pass to model preference filters.
     *
     * @var string
     */
    public const PROVIDER_ID = 'commandcode';

    /**
     * Model pushed to the front of the list and used for the AI plugin's preference filters.
     *
     * A static default: the Command Code model list endpoint reports no capability data, so there is
     * nothing to compute a "best" model from. Override with the `connectors_ai_commandcode_default_model`
     * option or the `COMMANDCODE_DEFAULT_MODEL` constant.
     *
     * @var string
     */
    public const DEFAULT_MODEL = 'claude-sonnet-5';

    /**
     * Resolves configuration from an environment variable or a PHP constant.
     *
     * @param string $name The variable/constant name.
     * @return string The value, or an empty string when unset.
     */
    public static function env(string $name): string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (defined($name)) {
            $constant = constant($name);
            if (is_scalar($constant)) {
                return (string) $constant;
            }
        }

        return '';
    }

    /**
     * Resolves a boolean configuration value.
     * @param string $name The variable/constant name.
     * @return bool The resolved value.
     */
    public static function envBool(string $name): bool
    {
        $value = self::env($name);
        if ($value === '') {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Gets the Provider API base URL.
     *
     * @return string The base URL, without a trailing slash.
     */
    public static function getBaseUrl(): string
    {
        $url = self::env('COMMANDCODE_BASE_URL');

        return $url === '' ? self::DEFAULT_BASE_URL : rtrim($url, '/');
    }

    /**
     * Whether requests should ask Command Code for zero-data-retention routing.
     *
     * When enabled, requests only route through ZDR-capable upstreams and fail with a 422 if the
     * chosen model has none. Opt in with `COMMANDCODE_ZDR=1` (or `CMD_ZDR=1`, the CLI's own switch).
     *
     * @return bool Whether ZDR routing is requested.
     */
    public static function isZeroDataRetentionEnabled(): bool
    {
        return self::envBool('COMMANDCODE_ZDR') || self::envBool('CMD_ZDR');
    }

    /**
     * Gets how structured output (JSON response) requests are shaped.
     *
     * The Command Code gateway serves several upstream model families behind one endpoint, and they do
     * not agree on structured output. `json_schema` is the OpenAI shape (a JSON schema is sent and the
     * model is constrained to it); `json_object` only asks for valid JSON and is the widely supported
     * fallback; `none` sends no `response_format` at all, which is the escape hatch when a model rejects
     * every form of it.
     *
     * @return string One of `json_schema`, `json_object` or `none`.
     */
    public static function getStructuredOutputMode(): string
    {
        $mode = strtolower(self::env('COMMANDCODE_STRUCTURED_OUTPUT'));

        return in_array($mode, ['json_schema', 'json_object', 'none'], true) ? $mode : 'json_schema';
    }

    /**
     * Gets the model ID to prefer.
     *
     * @return string The model ID, or an empty string to leave the AI plugin's own defaults alone.
     */
    public static function getDefaultModelId(): string
    {
        $configured = self::env('COMMANDCODE_DEFAULT_MODEL');
        if ($configured !== '') {
            return $configured;
        }

        if (function_exists('get_option')) {
            $option = get_option('connectors_ai_commandcode_default_model', '');
            if (is_string($option) && $option !== '') {
                return $option;
            }
        }

        return self::DEFAULT_MODEL;
    }

    /**
     * Whether this deployment claims its models accept image input.
     *
     * The Command Code models endpoint does not report modalities, so the plugin cannot prove vision
     * support. Set `COMMANDCODE_MODEL_INPUT_MODALITIES` to a comma-separated list containing `image`
     * (e.g. `text,image`) to declare it.
     *
     * @return bool Whether image input is declared.
     */
    public static function declaresImageInput(): bool
    {
        $modalities = self::env('COMMANDCODE_MODEL_INPUT_MODALITIES');
        if ($modalities === '') {
            return false;
        }

        $modalities = array_map('trim', explode(',', strtolower($modalities)));

        return in_array('image', $modalities, true);
    }

    /**
     * Gets the request timeout in seconds.
     *
     * WordPress' HTTP default is 5 seconds, which no LLM request survives.
     *
     * @return float The timeout in seconds.
     */
    public static function getRequestTimeout(): float
    {
        $timeout = self::env('COMMANDCODE_REQUEST_TIMEOUT');

        return $timeout === '' ? 120.0 : (float) $timeout;
    }

    /**
     * Gets the connection timeout in seconds.
     *
     * @return float The connection timeout in seconds.
     */
    public static function getConnectTimeout(): float
    {
        $connectTimeout = self::env('COMMANDCODE_CONNECT_TIMEOUT');

        return $connectTimeout === '' ? 10.0 : (float) $connectTimeout;
    }

    /**
     * Whether a Command Code credential is present.
     *
     * A purely local check (environment, constant, option) so it can be called from filters without
     * triggering a network request.
     *
     * @return bool Whether credentials are configured.
     */
    public static function hasCredentials(): bool
    {
        if (self::env('COMMANDCODE_API_KEY') !== '') {
            return true;
        }

        if (function_exists('get_option')) {
            $option = get_option('connectors_ai_commandcode_api_key', '');
            if (is_string($option) && $option !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates the request options used for every Command Code request, including the model list.
     *
     * @return RequestOptions The request options.
     */
    public static function createRequestOptions(): RequestOptions
    {
        $options = new RequestOptions();
        $options->setTimeout(self::getRequestTimeout());
        $options->setConnectTimeout(self::getConnectTimeout());

        return $options;
    }

    /**
     * Gets the User-Agent header value.
     *
     * @return string The User-Agent value.
     */
    public static function getUserAgent(): string
    {
        return 'ai-provider-for-command-code/' . self::VERSION;
    }
}
