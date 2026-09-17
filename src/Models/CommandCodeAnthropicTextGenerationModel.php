<?php
/**
 * Command Code Anthropic-compatible text generation model class file.
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

namespace CommandCode\AiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use CommandCode\AiProvider\Provider\CommandCodeProvider;

/**
 * Text generation model for Command Code models served by the Anthropic Messages endpoint.
 *
 * Command Code serves its Claude models on `/messages` only — they are rejected on
 * `/chat/completions` — so this maps the SDK's uniform prompt model onto the Anthropic wire format
 * by hand. The result is translated back into the same DTOs the OpenAI-compatible base class
 * produces, so upstream code cannot tell the two routes apart.
 */
class CommandCodeAnthropicTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    use CommandCodeRequestTrait;

    /**
     * Default `max_tokens`. The Messages API requires the parameter, and unlike OpenAI there is no
     * "as long as it needs" default.
     *
     * @var int
     */
    private const DEFAULT_MAX_TOKENS = 4096;

    /**
     * Beta flag required by the Messages API for JSON schema structured output.
     *
     * Command Code passes its `/messages` route through to Anthropic, so the same opt-in header the
     * official Anthropic provider sends is sent here.
     *
     * @var string
     */
    private const STRUCTURED_OUTPUT_BETA = 'structured-outputs-2025-11-13';

    /**
     * {@inheritDoc}
     *
     * @param list<Message> $prompt The prompt to generate text for.
     * @return GenerativeAiResult The generation result.
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $params = $this->prepareGenerateTextParams($prompt);

        $headers = ['Content-Type' => 'application/json'];
        if (isset($params['output_format'])) {
            $headers['anthropic-beta'] = self::STRUCTURED_OUTPUT_BETA;
        }

        $request = $this->createRequest(HttpMethodEnum::POST(), 'messages', $headers, $params);

        // Add authentication credentials to the request.
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        // Send and process the request.
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @param list<Message> $prompt The prompt to generate text for.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'model' => $this->metadata()->getId(),
            'messages' => $this->prepareMessagesParam($prompt),
            'max_tokens' => $config->getMaxTokens() ?? self::DEFAULT_MAX_TOKENS,
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction) {
            $params['system'] = $systemInstruction;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $params['top_k'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $params['stop_sequences'] = $stopSequences;
        }

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null) {
            $params['n'] = $candidateCount;
        }

        /*
         * Structured output in the Messages API is the JSON schema form. Plain JSON mode when there is
         * no schema is not expressible, so it is left to the prompt. Command Code's `/messages` route
         * is a passthrough whose support for `output_format` has not been verified against a live key.
         */
        $outputSchema = $config->getOutputSchema();
        if ('application/json' === $config->getOutputMimeType() && $outputSchema) {
            $params['output_format'] = [
                'type' => 'json_schema',
                'schema' => $outputSchema,
            ];
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations)) {
            $params['tools'] = $this->prepareToolsParam($functionDeclarations);
        }

        // Escape hatch for Command Code and Anthropic options this plugin does not model yet.
        foreach ($config->getCustomOptions() as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        /* translators: %s: name of the conflicting option. */
                        esc_html__('The custom option "%s" conflicts with an existing parameter.', 'ai-provider-for-command-code'),
                        esc_html((string) $key)
                    )
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Prepares the messages parameter for the API request.
     *
     * @param list<Message> $messages The messages to prepare.
     * @return list<array<string, mixed>> The prepared messages parameter.
     */
    protected function prepareMessagesParam(array $messages): array
    {
        return array_values(array_map(
            function (Message $message): array {
                return [
                    'role' => $message->getRole()->isModel() ? 'assistant' : 'user',
                    'content' => array_values(array_filter(array_map(
                        [$this, 'getMessagePartData'],
                        $message->getParts()
                    ))),
                ];
            },
            $messages
        ));
    }

    /**
     * Returns the Anthropic API specific data for a message part.
     *
     * @param MessagePart $part The message part to get the data for.
     * @return array<string, mixed>|null The data for the message part, or null if not applicable.
     * @throws InvalidArgumentException If the message part type or data is unsupported.
     */
    protected function getMessagePartData(MessagePart $part): ?array
    {
        $type = $part->getType();

        if ($type->isText()) {
            if ($part->getChannel()->isThought()) {
                // Prior-turn reasoning: replay it as thinking so the model sees its own trace.
                return [
                    'type' => 'thinking',
                    'thinking' => (string) $part->getText(),
                ];
            }
            return [
                'type' => 'text',
                'text' => (string) $part->getText(),
            ];
        }

        if ($type->isFile()) {
            return $this->getFilePartData($part);
        }

        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                throw new RuntimeException('The function call typed message part must contain a function call.');
            }

            /*
             * An empty argument list must serialize to `{}`, not `[]`: json_encode() turns a PHP
             * empty array into an array, and the Messages API rejects it where an object is expected.
             */
            $input = $functionCall->getArgs();
            if ($input === null || (is_array($input) && count($input) === 0)) {
                $input = new \stdClass();
            }

            return [
                'type' => 'tool_use',
                'id' => $functionCall->getId(),
                'name' => $functionCall->getName(),
                'input' => $input,
            ];
        }

        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                throw new RuntimeException('The function response typed message part must contain a function response.');
            }
            return [
                'type' => 'tool_result',
                'tool_use_id' => $functionResponse->getId(),
                'content' => json_encode($functionResponse->getResponse()),
            ];
        }

        throw new InvalidArgumentException(
            sprintf(
                /* translators: %s: message part type. */
                esc_html__('Unsupported message part type "%s".', 'ai-provider-for-command-code'),
                esc_html((string) $type)
            )
        );
    }

    /**
     * Returns the Anthropic API specific data for a file message part.
     *
     * @param MessagePart $part The file message part.
     * @return array<string, mixed> The data for the message part.
     * @throws InvalidArgumentException If the file type is unsupported.
     * @throws RuntimeException If the file part is malformed.
     */
    private function getFilePartData(MessagePart $part): array
    {
        $file = $part->getFile();
        if (!$file) {
            throw new RuntimeException('The file typed message part must contain a file.');
        }

        if ($file->isRemote()) {
            $fileUrl = $file->getUrl();
            if (!$fileUrl) {
                throw new RuntimeException('The remote file must contain a URL.');
            }
            if ($file->isImage()) {
                return [
                    'type' => 'image',
                    'source' => ['type' => 'url', 'url' => $fileUrl],
                ];
            }
            if ($file->isDocument()) {
                return [
                    'type' => 'document',
                    'source' => ['type' => 'url', 'url' => $fileUrl],
                ];
            }
            throw new InvalidArgumentException(
                sprintf(
                    /* translators: %s: MIME type of the file. */
                    esc_html__('Unsupported MIME type "%s" for remote file message part.', 'ai-provider-for-command-code'),
                    esc_html($file->getMimeType())
                )
            );
        }

        $base64Data = $file->getBase64Data();
        if (!$base64Data) {
            throw new RuntimeException('The inline file must contain base64 data.');
        }

        if ($file->isImage()) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $file->getMimeType(),
                    'data' => $base64Data,
                ],
            ];
        }

        if ($file->isDocument()) {
            return [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $file->getMimeType(),
                    'data' => $base64Data,
                ],
            ];
        }

        throw new InvalidArgumentException(
            sprintf(
                /* translators: %s: MIME type of the file. */
                esc_html__('Unsupported MIME type "%s" for inline file message part.', 'ai-provider-for-command-code'),
                esc_html($file->getMimeType())
            )
        );
    }

    /**
     * Prepares the tools parameter for the API request.
     *
     * @param list<FunctionDeclaration> $functionDeclarations The function declarations.
     * @return list<array<string, mixed>> The prepared tools parameter.
     */
    protected function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = [];

        foreach ($functionDeclarations as $functionDeclaration) {
            /*
             * `input_schema` is required even for functions with no parameters, where an empty object
             * schema is the correct stand-in.
             */
            $inputSchema = $functionDeclaration->getParameters();
            if ($inputSchema === null) {
                $inputSchema = [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ];
            }

            $tools[] = array_filter([
                'name' => $functionDeclaration->getName(),
                'description' => $functionDeclaration->getDescription(),
                'input_schema' => $inputSchema,
            ]);
        }

        return $tools;
    }

    /**
     * Parses the response from the API endpoint to a generative AI result.
     *
     * @param Response $response The response from the API endpoint.
     * @return GenerativeAiResult The parsed generative AI result.
     * @throws RuntimeException If the response is missing required data.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $providerName = $this->providerMetadata()->getName();
        $responseData = $response->getData();

        if (!isset($responseData['content']) || !is_array($responseData['content']) || !$responseData['content']) {
            throw ResponseException::fromMissingData(esc_html($providerName), 'content');
        }
        if (!array_is_list($responseData['content'])) {
            throw ResponseException::fromInvalidData(
                esc_html($providerName),
                'content',
                'The value must be an indexed array.'
            );
        }

        $role = isset($responseData['role']) && 'user' === $responseData['role']
            ? MessageRoleEnum::user()
            : MessageRoleEnum::model();

        $parts = [];
        foreach ($responseData['content'] as $index => $partData) {
            try {
                $part = $this->parseResponseContentMessagePart($partData);
                if ($part) {
                    $parts[] = $part;
                }
            } catch (InvalidArgumentException $e) {
                throw ResponseException::fromInvalidData(
                    esc_html($providerName),
                    esc_html("content[{$index}]"),
                    esc_html($e->getMessage())
                );
            }
        }

        if (!isset($responseData['stop_reason']) || !is_string($responseData['stop_reason'])) {
            throw ResponseException::fromMissingData(esc_html($providerName), 'stop_reason');
        }

        switch ($responseData['stop_reason']) {
            case 'end_turn':
            case 'stop_sequence':
            case 'pause_turn':
                $finishReason = FinishReasonEnum::stop();
                break;
            case 'max_tokens':
            case 'model_context_window_exceeded':
                $finishReason = FinishReasonEnum::length();
                break;
            case 'refusal':
                $finishReason = FinishReasonEnum::contentFilter();
                break;
            case 'tool_use':
                $finishReason = FinishReasonEnum::toolCalls();
                break;
            default:
                /*
                 * Deliberately not defaulting to "stop": reporting a complete answer for an unknown
                 * termination reason hides truncated or rejected responses from the caller.
                 */
                throw ResponseException::fromInvalidData(
                    esc_html($providerName),
                    'stop_reason',
                    sprintf(
                        /* translators: %s: stop reason returned by the API. */
                        esc_html__('Invalid stop reason "%s".', 'ai-provider-for-command-code'),
                        esc_html((string) $responseData['stop_reason'])
                    )
                );
        }

        $candidates = [new Candidate(new Message($role, $parts), $finishReason)];

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        if (isset($responseData['usage']) && is_array($responseData['usage'])) {
            $usage = $responseData['usage'];
            // Cached prompt tokens are billed as input, so they count towards the prompt total.
            $promptTokens = (int) ($usage['input_tokens'] ?? 0)
                + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                + (int) ($usage['cache_read_input_tokens'] ?? 0);
            $completionTokens = (int) ($usage['output_tokens'] ?? 0);

            $tokenUsage = new TokenUsage($promptTokens, $completionTokens, $promptTokens + $completionTokens);
        } else {
            $tokenUsage = new TokenUsage(0, 0, 0);
        }

        // Keep everything not consumed above available to callers as provider specific metadata.
        $additionalData = $responseData;
        unset(
            $additionalData['id'],
            $additionalData['role'],
            $additionalData['content'],
            $additionalData['stop_reason'],
            $additionalData['usage']
        );

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses a message part from the content in the API response.
     *
     * @param array<string, mixed> $partData The message part data from the API response.
     * @return MessagePart|null The parsed message part, or null to ignore it.
     * @throws InvalidArgumentException If the part shape is invalid.
     */
    protected function parseResponseContentMessagePart(array $partData): ?MessagePart
    {
        if (!isset($partData['type']) || !is_string($partData['type'])) {
            throw new InvalidArgumentException('Part is missing a type field.');
        }

        switch ($partData['type']) {
            case 'text':
                if (!isset($partData['text']) || !is_string($partData['text'])) {
                    throw new InvalidArgumentException('Part has an invalid text shape.');
                }
                return new MessagePart($partData['text']);

            case 'thinking':
                if (!isset($partData['thinking']) || !is_string($partData['thinking'])) {
                    throw new InvalidArgumentException('Part has an invalid thinking shape.');
                }
                return new MessagePart($partData['thinking'], MessagePartChannelEnum::thought());

            case 'tool_use':
                if (
                    !isset($partData['id'], $partData['name'], $partData['input'])
                    || !is_string($partData['id'])
                    || !is_string($partData['name'])
                ) {
                    throw new InvalidArgumentException('Part has an invalid tool_use shape.');
                }
                // An empty input object means "no arguments" and is normalized to null.
                $args = $partData['input'];
                if (is_array($args) && count($args) === 0) {
                    $args = null;
                }
                return new MessagePart(new FunctionCall($partData['id'], $partData['name'], $args));

            default:
                /*
                 * redacted_thinking, server_tool_use, web_search_tool_result and anything added later:
                 * nothing to map them to, and dropping them keeps a response usable rather than fatal.
                 */
                return null;
        }
    }
}
