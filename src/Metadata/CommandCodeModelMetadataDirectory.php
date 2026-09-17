<?php
/**
 * Command Code model metadata directory class file.
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

namespace CommandCode\AiProvider\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use CommandCode\AiProvider\Provider\CommandCodeProvider;
use CommandCode\AiProvider\Util\CommandCodeConfig;
use CommandCode\AiProvider\Util\CommandCodeModelCatalog;

/**
 * Class for the Command Code model metadata directory.
 *
 * Command Code's models endpoint returns `{id, name, context_length, supported_endpoints}` and no
 * capability information, so capabilities and options are declared here. That declaration is the
 * single source of truth: the SDK decides which model may serve a request by matching it against
 * these values, so under-declaring makes a model unusable and over-declaring turns into a 400 from
 * the upstream.
 *
 * @phpstan-type ModelsResponseData array{
 *     data: list<array{id: string, name?: string, context_length?: int}>
 * }
 */
class CommandCodeModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * {@inheritDoc}
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The API endpoint path, relative to the base URL.
     * @param array<string, string|list<string>> $headers The request headers.
     * @param string|array<string, mixed>|null $data The request data.
     * @return Request The request object.
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        /*
         * The base class does not pass request options here, so without this the model list request
         * is sent with WordPress' 5 second HTTP default.
         */
        return new Request(
            $method,
            CommandCodeProvider::url($path),
            $headers,
            $data,
            CommandCodeConfig::createRequestOptions()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param Response $response The response from the API endpoint to list models.
     * @return list<ModelMetadata> List of model metadata objects.
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !is_array($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData('Command Code', 'data');
        }

        $preferredModelId = CommandCodeConfig::getDefaultModelId();

        $models = [];
        foreach ($responseData['data'] as $modelData) {
            if (!is_array($modelData) || !isset($modelData['id']) || !is_string($modelData['id'])) {
                continue;
            }

            $modelId = $modelData['id'];
            $displayName = isset($modelData['name']) && is_string($modelData['name']) && $modelData['name'] !== ''
                ? $modelData['name']
                : $modelId;

            $models[] = new ModelMetadata(
                $modelId,
                $displayName,
                [
                    CapabilityEnum::textGeneration(),
                    CapabilityEnum::chatHistory(),
                ],
                $this->createSupportedOptions($modelId)
            );
        }

        usort(
            $models,
            static function (ModelMetadata $a, ModelMetadata $b) use ($preferredModelId): int {
                // An explicitly configured model is pinned to the top of every picker.
                if ($preferredModelId !== '') {
                    $aPreferred = $a->getId() === $preferredModelId ? 0 : 1;
                    $bPreferred = $b->getId() === $preferredModelId ? 0 : 1;
                    if ($aPreferred !== $bPreferred) {
                        return $aPreferred <=> $bPreferred;
                    }
                }

                return CommandCodeModelCatalog::compareModelIds($a->getId(), $b->getId());
            }
        );

        return $models;
    }

    /**
     * Builds the supported options for a model.
     *
     * @param string $modelId The model ID.
     * @return list<SupportedOption> The supported options.
     */
    private function createSupportedOptions(string $modelId): array
    {
        $inputModalities = [[ModalityEnum::text()]];
        // Either the catalog knows this model takes images, or the deployment asserts it does.
        if (
            CommandCodeModelCatalog::supportsImageInput($modelId)
            || CommandCodeConfig::declaresImageInput()
        ) {
            $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
        }

        $commonOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];

        if (CommandCodeModelCatalog::isAnthropicModel($modelId)) {
            /*
             * The Messages API has no `n` (candidate count) and no logprobs parameters, so those are
             * left undeclared: the SDK will then pick a different model or fail fast, instead of the
             * plugin sending a parameter the endpoint rejects.
             */
            return array_merge($commonOptions, [
                new SupportedOption(OptionEnum::temperature()),
                new SupportedOption(OptionEnum::topP()),
                new SupportedOption(OptionEnum::topK()),
            ]);
        }

        $samplingOptions = [
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::logprobs()),
            new SupportedOption(OptionEnum::topLogprobs()),
        ];

        /*
         * Reasoning families reject non-default sampling values with a 400, so they must not advertise
         * these options. Command Code proxies the upstream models, so the upstream behaviour applies.
         */
        if (CommandCodeModelCatalog::rejectsSamplingParameters($modelId)) {
            return $commonOptions;
        }

        return array_merge($commonOptions, $samplingOptions);
    }
}
