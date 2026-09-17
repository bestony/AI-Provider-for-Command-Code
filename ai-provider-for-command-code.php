<?php
/**
 * Plugin Name:       AI Provider for Command Code
 * Plugin URI:        https://github.com/bestony/AI-Provider-for-Command-Code
 * Description:       Command Code provider for the WordPress AI Client.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           1.0.3
 * Author:            Bestony
 * Author URI:        https://github.com/bestony
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-command-code
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

namespace CommandCode\AiProvider;

use WordPress\AiClient\AiClient;
use CommandCode\AiProvider\Provider\CommandCodeProvider;
use CommandCode\AiProvider\Util\CommandCodeConfig;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the provider with the AI Client.
 *
 * Runs on `init` priority 5: WordPress core builds the Connectors entries at priority 10 from
 * whatever providers are in the registry, so a later priority means no Connectors card (and no
 * place for the user to paste an API key).
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(CommandCodeProvider::class)) {
        return;
    }

    $registry->registerProvider(CommandCodeProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);

/**
 * Puts Command Code models first in the AI plugin's model preference lists.
 *
 * The AI plugin's defaults are Anthropic/Google/OpenAI, none of which the user may have configured.
 * Existing entries from other providers are preserved, and duplicates of the chosen model are removed.
 *
 * @param mixed $preferredModels List of `[provider_id, model_id]` tuples.
 * @return array<int, array{string, string}>
 */
function prefer_command_code_models($preferredModels): array
{
    $preferredList = is_array($preferredModels) ? array_values($preferredModels) : [];

    // Without a credential the provider never yields candidates, so there is nothing to prioritise.
    if (!CommandCodeConfig::hasCredentials()) {
        return $preferredList;
    }

    $defaultModel = CommandCodeConfig::getDefaultModelId();
    if ($defaultModel === '') {
        return $preferredList;
    }

    $preferred = [[CommandCodeConfig::PROVIDER_ID, $defaultModel]];
    foreach ($preferredList as $entry) {
        if (!is_array($entry) || count($entry) < 2) {
            continue;
        }
        $entry = array_values($entry);
        if (CommandCodeConfig::PROVIDER_ID === $entry[0]) {
            continue;
        }
        $preferred[] = [$entry[0], $entry[1]];
    }

    return $preferred;
}

add_filter('wpai_preferred_text_models', __NAMESPACE__ . '\\prefer_command_code_models');
add_filter('wpai_preferred_vision_models', __NAMESPACE__ . '\\prefer_command_code_models');

/**
 * Enables the alt text feature when the user explicitly opted this plugin in.
 *
 * Command Code's `/models` list does not report image input, so the SDK cannot prove any model
 * supports vision. Declaring it here is the escape hatch for deployments that know their model does
 * (see COMMANDCODE_MODEL_INPUT_MODALITIES in readme.txt). Nothing is forced off: when the option is
 * unset the filter returns its input untouched.
 *
 * @param mixed $enabled Whether the feature is enabled.
 * @return mixed
 */
function maybe_enable_vision_feature($enabled)
{
    if (CommandCodeConfig::hasCredentials() && CommandCodeConfig::declaresImageInput()) {
        return true;
    }

    return $enabled;
}

add_filter('wpai_feature_alt-text-generation_enabled', __NAMESPACE__ . '\\maybe_enable_vision_feature');
