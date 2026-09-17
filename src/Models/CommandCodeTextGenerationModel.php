<?php
/**
 * Command Code text generation model class file.
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

namespace CommandCode\AiProvider\Models;

use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use CommandCode\AiProvider\Util\CommandCodeConfig;
use CommandCode\AiProvider\Util\CommandCodeModelCatalog;

/**
 * Text generation model for Command Code models served by the OpenAI-compatible endpoint.
 *
 * Everything below the request body — message mapping, tool calls, structured output, response
 * parsing, token usage — is handled by the SDK base class. Only Command Code's request shape quirks
 * need overriding.
 */
class CommandCodeTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    use CommandCodeRequestTrait;

    /**
     * {@inheritDoc}
     *
     * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt The prompt to generate text for.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);

        /*
         * An empty array signals "send no response_format" (see prepareResponseFormatParam()); leaving
         * the key in place would send `"response_format": []`, which is a 400.
         */
        if (isset($params['response_format']) && $params['response_format'] === []) {
            unset($params['response_format']);
        }

        if (CommandCodeModelCatalog::rejectsSamplingParameters($this->metadata()->getId())) {
            // These families return a 400 when a non-default sampling value is sent.
            unset(
                $params['temperature'],
                $params['top_p'],
                $params['presence_penalty'],
                $params['frequency_penalty'],
                $params['logprobs'],
                $params['top_logprobs']
            );
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * Fixes the request shape for structured output, which the SDK base class gets wrong.
     *
     * The base class sends `{"type":"json_schema","json_schema":<schema>}`, but the OpenAI API — and
     * therefore the Command Code gateway — expects the schema wrapped in a named object:
     * `{"type":"json_schema","json_schema":{"name":...,"schema":{...}}}`. Without the wrapper the
     * gateway answers `400 invalid_request_error, param: response_format`, which is what the AI
     * plugin's Editorial Notes feature (an `as_json_response()` call) was hitting.
     *
     * @param array<string, mixed>|null $outputSchema The output schema, or null for plain JSON mode.
     * @return array<string, mixed> The response format parameter, or an empty array to send none.
     */
    protected function prepareResponseFormatParam(?array $outputSchema): array
    {
        $mode = CommandCodeConfig::getStructuredOutputMode();

        if ($mode === 'none') {
            return [];
        }

        if ($mode === 'json_object' || !is_array($outputSchema)) {
            return ['type' => 'json_object'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'command_code_response',
                'schema' => $outputSchema,
            ],
        ];
    }
}
