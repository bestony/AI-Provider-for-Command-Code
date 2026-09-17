<?php
/**
 * Model catalog helpers.
 *
 * Pure string logic, no WordPress and no SDK: this is the part of the plugin worth unit testing, and
 * `scripts/selfcheck.php` does exactly that.
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

namespace CommandCode\AiProvider\Util;

/**
 * Classification rules for Command Code model IDs.
 *
 * Command Code's `/models` endpoint reports only `id`, `name`, `context_length` and
 * `supported_endpoints`, so every capability this plugin declares is derived from the model ID here.
 */
final class CommandCodeModelCatalog
{
    /**
     * Model IDs that accept image input, verified against the capability column on
     * https://commandcode.ai/models (checked 2026-09-18: 50 of 70 rows list "Vision").
     *
     * Patterns are matched against the API model ID, not the page slug, because the two differ
     * (page `gpt-5-6-sol` is API `gpt-5.6-sol`).
     *
     * @var list<string>
     */
    private const VISION_MODEL_PATTERNS = [
        '#^claude-#',
        '#^gpt-5\.(?:3-codex|4|5|6)#',
        '#^google/gemini-3\.[5-8]-flash#',
        '#^xai/grok-4\.[56]#',
        '#^z-ai/glm-5\.3-flash#',
        '#^moonshotai/Kimi-K(?:2\.[5-7]|3)#',
        '#^xiaomi/mimo-v2\.5#',
        '#^MiniMaxAI/MiniMax-M3#',
        '#^Qwen/Qwen3\.(?:7|8)#',
        '#^stepfun/Step-3\.7-Flash#',
        '#^deepseek/deepseek-v4(?:\.1-flash|-flash-vision-exp)#',
        '#^meta/muse-spark-#',
        '#^thinkingmachines/inkling#',
        '#^sakana/fugu-ultra#',
    ];

    /**
     * Model families that reject non-default sampling parameters.
     *
     * Same rule the official OpenAI provider applies: GPT-5 and later reasoning families reject
     * `temperature` and friends with a 400. Only families whose behaviour has been verified are
     * listed; check the upstream docs before adding another.
     *
     * @var string
     */
    private const SAMPLING_REJECTING_PATTERN = '/^(?:gpt-5(?:\.\d+)?|codex|o[134])(?:-|$)/';

    /**
     * Whether a model is served by the Anthropic Messages endpoint.
     *
     * Mirrors the `supported_endpoints` field: Claude models answer on `/messages` only, and no other
     * model answers there. This decides which model class handles the request.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the model uses the Anthropic Messages API.
     */
    public static function isAnthropicModel(string $modelId): bool
    {
        return strpos($modelId, 'claude-') === 0;
    }

    /**
     * Whether a model accepts image input.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the model accepts images.
     */
    public static function supportsImageInput(string $modelId): bool
    {
        foreach (self::VISION_MODEL_PATTERNS as $pattern) {
            if (preg_match($pattern, $modelId) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a model rejects non-default sampling parameters such as `temperature`.
     *
     * @param string $modelId The model ID.
     * @return bool Whether sampling parameters must not be sent.
     */
    public static function rejectsSamplingParameters(string $modelId): bool
    {
        $shortId = self::shortId($modelId);

        return preg_match(self::SAMPLING_REJECTING_PATTERN, $shortId) === 1;
    }

    /**
     * Orders model IDs so the models a user is most likely to want appear first.
     *
     * Not a judgement about which model is best: the goal is that the first entry in the picker is a
     * current flagship rather than an alphabetically lucky free preview.
     *
     * @param string $modelIdA The first model ID.
     * @param string $modelIdB The second model ID.
     * @return int Negative if the first model should sort first, positive otherwise.
     */
    public static function compareModelIds(string $modelIdA, string $modelIdB): int
    {
        foreach ([
            // Dated snapshots come after the alias that tracks the latest version.
            ['CommandCode\\AiProvider\\Util\\CommandCodeModelCatalog', 'isDatedSnapshot'],
            // Free-tier and experimental models last: they are rate-limited and short-lived.
            ['CommandCode\\AiProvider\\Util\\CommandCodeModelCatalog', 'isPreviewOrFree'],
        ] as $criterion) {
            $a = call_user_func($criterion, $modelIdA) ? 1 : 0;
            $b = call_user_func($criterion, $modelIdB) ? 1 : 0;
            if ($a !== $b) {
                return $a <=> $b;
            }
        }

        return strnatcasecmp($modelIdA, $modelIdB);
    }

    /**
     * Whether a model ID ends in a dated snapshot suffix.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the ID is a dated snapshot.
     */
    public static function isDatedSnapshot(string $modelId): bool
    {
        return preg_match('/-\d{8}$|-\d{4}-\d{2}-\d{2}$/', $modelId) === 1;
    }

    /**
     * Whether a model is a free or preview variant.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the model is free or in preview.
     */
    public static function isPreviewOrFree(string $modelId): bool
    {
        $shortId = self::shortId($modelId);

        return strpos($shortId, ':free') !== false
            || stripos($shortId, 'preview') !== false
            || stripos($shortId, '-exp') !== false;
    }

    /**
     * Strips the vendor prefix from a model ID.
     *
     * Model IDs come in two shapes: `claude-sonnet-5` and `deepseek/deepseek-v4-flash`. Family rules
     * apply to the last segment.
     *
     * @param string $modelId The model ID.
     * @return string The ID without its vendor prefix.
     */
    private static function shortId(string $modelId): string
    {
        $slashPosition = strrpos($modelId, '/');

        return $slashPosition === false ? $modelId : substr($modelId, $slashPosition + 1);
    }
}
