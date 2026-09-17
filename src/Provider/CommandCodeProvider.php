<?php
/**
 * Command Code provider class file.
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

namespace CommandCode\AiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use CommandCode\AiProvider\Metadata\CommandCodeModelMetadataDirectory;
use CommandCode\AiProvider\Models\CommandCodeAnthropicTextGenerationModel;
use CommandCode\AiProvider\Models\CommandCodeTextGenerationModel;
use CommandCode\AiProvider\Util\CommandCodeConfig;
use CommandCode\AiProvider\Util\CommandCodeModelCatalog;

/**
 * Class for the Command Code provider.
 *
 * Command Code exposes an OpenAI-compatible `/chat/completions` endpoint for its open and GPT/Gemini
 * models, and an Anthropic-compatible `/messages` endpoint for its Claude models. Both routes accept
 * `Authorization: Bearer <key>`, so one provider covers both.
 */
class CommandCodeProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @return string The base URL for the Provider API.
     */
    protected static function baseUrl(): string
    {
        return CommandCodeConfig::getBaseUrl();
    }

    /**
     * {@inheritDoc}
     *
     * @param ModelMetadata $modelMetadata The model metadata.
     * @param ProviderMetadata $providerMetadata The provider metadata.
     * @return ModelInterface The model instance.
     * @throws RuntimeException If the model has no supported capability for this provider.
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if (!$capability->isTextGeneration()) {
                continue;
            }

            /*
             * Two protocols behind one provider: Claude models are only served on the Anthropic
             * Messages endpoint, everything else on the OpenAI-compatible one. The `/models` endpoint
             * reports the same split in its `supported_endpoints` field.
             */
            $model = CommandCodeModelCatalog::isAnthropicModel($modelMetadata->getId())
                ? new CommandCodeAnthropicTextGenerationModel($modelMetadata, $providerMetadata)
                : new CommandCodeTextGenerationModel($modelMetadata, $providerMetadata);

            /*
             * Command Code requests routinely run for tens of seconds. Without this the request is sent
             * with WordPress' 5 second default and times out.
             */
            $model->setRequestOptions(CommandCodeConfig::createRequestOptions());

            return $model;
        }

        throw new RuntimeException(
            sprintf(
                /* translators: %s: model ID. */
                esc_html__('The model "%s" has no supported capability for Command Code.', 'ai-provider-for-command-code'),
                esc_html($modelMetadata->getId())
            )
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return ProviderMetadata The provider metadata.
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            CommandCodeConfig::PROVIDER_ID,
            'Command Code',
            ProviderTypeEnum::cloud(),
            'https://commandcode.ai/billing',
            RequestAuthenticationMethod::apiKey(),
        ];

        // Provider description support was added in SDK 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $description = 'Text generation with Claude, GPT, Gemini and leading open models routed through Command Code.';
            $args[] = function_exists('__')
                ? __('Text generation with Claude, GPT, Gemini and leading open models routed through Command Code.', 'ai-provider-for-command-code')
                : $description;
        }

        // Provider logoPath support was added in SDK 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $args[] = dirname(__DIR__, 2) . '/assets/images/commandcode.svg';
        }

        return new ProviderMetadata(...$args);
    }

    /**
     * {@inheritDoc}
     *
     * @return ProviderAvailabilityInterface The provider availability check.
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // Valid credentials are confirmed by listing models, which requires the API key.
        return new ListModelsApiBasedProviderAvailability(static::modelMetadataDirectory());
    }

    /**
     * {@inheritDoc}
     *
     * @return ModelMetadataDirectoryInterface The model metadata directory.
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new CommandCodeModelMetadataDirectory();
    }
}
