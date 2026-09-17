<?php
/**
 * Runnable self-check for the Command Code provider plugin.
 *
 * Covers the plugin's own logic — model classification, sort order, capability declarations and
 * request building — without needing WordPress, composer or a live API key. One file, no test
 * framework, because this is the smallest thing that fails when the logic breaks.
 *
 * Usage:
 *   php scripts/selfcheck.php
 *   php scripts/selfcheck.php --sdk=/path/to/wordpress-php-ai-client/src   # extra live-SDK checks
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/*
 * The plugin's autoloader refuses to run outside WordPress (Plugin Check requires a direct-access
 * guard on it), so this harness defines ABSPATH the way a WordPress test bootstrap does.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}

require $root . '/src/autoload.php';

use CommandCode\AiProvider\Util\CommandCodeConfig;
use CommandCode\AiProvider\Util\CommandCodeModelCatalog;

$failures = 0;
$checks = 0;

/**
 * Asserts a condition and records the outcome.
 *
 * @param bool $condition The condition to check.
 * @param string $description What is being checked.
 * @return void
 */
function check(bool $condition, string $description): void
{
    global $failures, $checks;
    $checks++;

    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL  {$description}\n");
        return;
    }

    fwrite(STDOUT, "ok    {$description}\n");
}

// --- Routing: which endpoint serves which model (mirrors `supported_endpoints`). ---------------
check(CommandCodeModelCatalog::isAnthropicModel('claude-sonnet-5'), 'claude-sonnet-5 routes to /messages');
check(CommandCodeModelCatalog::isAnthropicModel('claude-haiku-4-5-20251001'), 'dated Claude snapshot routes to /messages');
check(!CommandCodeModelCatalog::isAnthropicModel('deepseek/deepseek-v4-flash'), 'deepseek routes to /chat/completions');
check(!CommandCodeModelCatalog::isAnthropicModel('gpt-5.6-luna'), 'gpt routes to /chat/completions');
check(!CommandCodeModelCatalog::isAnthropicModel('google/gemini-3.8-flash'), 'gemini routes to /chat/completions');

// --- Sampling-parameter rejection (upstream returns 400 for these families). -------------------
foreach (['gpt-5.6-luna', 'gpt-5', 'gpt-5.3-codex', 'codex-mini-latest', 'o3-mini', 'o4-mini'] as $modelId) {
    check(CommandCodeModelCatalog::rejectsSamplingParameters($modelId), "{$modelId} rejects sampling parameters");
}
foreach (['deepseek/deepseek-v4-flash', 'claude-sonnet-5', 'google/gemini-3.8-flash', 'gpt-4o'] as $modelId) {
    check(!CommandCodeModelCatalog::rejectsSamplingParameters($modelId), "{$modelId} accepts sampling parameters");
}

// --- Vision capability (from the capability column on commandcode.ai/models). ------------------
foreach ([
    'claude-opus-5',
    'claude-haiku-4-5-20251001',
    'gpt-5.6-luna',
    'gpt-5.4-mini',
    'google/gemini-3.8-flash',
    'xai/grok-4.5',
    'moonshotai/Kimi-K3',
    'deepseek/deepseek-v4-flash-vision-exp',
    'meta/muse-spark-1.3',
    'Qwen/Qwen3.8-Max',
] as $modelId) {
    check(CommandCodeModelCatalog::supportsImageInput($modelId), "{$modelId} accepts image input");
}
foreach (['deepseek/deepseek-v4-flash', 'zai-org/GLM-5', 'tencent/hy3-paid', 'nvidia/nemotron-3-ultra-550b-a55b'] as $modelId) {
    check(!CommandCodeModelCatalog::supportsImageInput($modelId), "{$modelId} is text-only");
}

// --- Candidate classification. -----------------------------------------------------------------
check(CommandCodeModelCatalog::isDatedSnapshot('claude-haiku-4-5-20251001'), 'dated snapshot detected (compact form)');
check(CommandCodeModelCatalog::isDatedSnapshot('gpt-5.4-2026-01-31'), 'dated snapshot detected (dashed form)');
check(!CommandCodeModelCatalog::isDatedSnapshot('claude-sonnet-5'), 'version-less alias is not a snapshot');
check(CommandCodeModelCatalog::isPreviewOrFree('meituan/LongCat-2.0:free'), 'free tier detected');
check(CommandCodeModelCatalog::isPreviewOrFree('inclusionai/ling-3.0-flash-sante:free'), 'free tier detected with vendor prefix');
check(CommandCodeModelCatalog::isPreviewOrFree('Qwen/Qwen3.6-Max-Preview'), 'preview detected');
check(CommandCodeModelCatalog::isPreviewOrFree('deepseek/deepseek-v4-flash-vision-exp'), 'experimental variant detected');
check(!CommandCodeModelCatalog::isPreviewOrFree('gpt-5.6-terra'), 'regular model is neither free nor preview');

// --- Sort order: aliases before snapshots, paid before free, then natural order. --------------
check(CommandCodeModelCatalog::compareModelIds('claude-sonnet-5', 'claude-haiku-4-5-20251001') < 0, 'alias sorts before dated snapshot');
check(CommandCodeModelCatalog::compareModelIds('gpt-5.6-terra', 'meituan/LongCat-2.0:free') < 0, 'paid model sorts before free model');
check(CommandCodeModelCatalog::compareModelIds('gpt-5.4', 'gpt-5.10') < 0, 'versions compare numerically, not lexically');
check(CommandCodeModelCatalog::compareModelIds('gpt-5.4', 'gpt-5.4') === 0, 'comparing a model with itself is neutral');

// --- Configuration defaults. ------------------------------------------------------------------
check(CommandCodeConfig::getBaseUrl() === 'https://api.commandcode.ai/provider/v1', 'default base URL');
check(CommandCodeConfig::getRequestTimeout() >= 60.0, 'request timeout is long enough for an LLM call');
check(CommandCodeConfig::getUserAgent() === 'ai-provider-for-command-code/1.0.5', 'user agent identifies the plugin');
check(!CommandCodeConfig::isZeroDataRetentionEnabled(), 'ZDR is off unless asked for');

// --- Request building, against the real SDK when one is available. ----------------------------
$sdkPath = null;
foreach ($argv as $index => $argument) {
    if (strpos($argument, '--sdk=') === 0) {
        $sdkPath = substr($argument, 6);
    }
}

if ($sdkPath !== null && is_file($sdkPath . '/polyfills.php')) {
    require $sdkPath . '/polyfills.php';
    spl_autoload_register(static function (string $class) use ($sdkPath): void {
        $prefix = 'WordPress\\AiClient\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $file = $sdkPath . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    use_command_code_sdk_checks();
} else {
    fwrite(STDOUT, "skip  SDK-dependent checks (pass --sdk=<path to php-ai-client/src> to run them)\n");
}

/**
 * Checks that need the real SDK classes: declared options and the built request.
 *
 * @return void
 */
function use_command_code_sdk_checks(): void
{
    $directory = new \CommandCode\AiProvider\Metadata\CommandCodeModelMetadataDirectory();

    $response = new \WordPress\AiClient\Providers\Http\DTO\Response(
        200,
        [],
        json_encode([
            'object' => 'list',
            'data' => [
                ['id' => 'deepseek/deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash (latest)'],
                ['id' => 'claude-sonnet-5', 'name' => 'Claude Sonnet 5'],
                ['id' => 'gpt-5.6-luna', 'name' => 'GPT-5.6 Luna'],
                ['id' => 'meituan/LongCat-2.0:free', 'name' => 'LongCat 2.0'],
                ['id' => 'claude-haiku-4-5-20251001', 'name' => 'Claude Haiku 4.5'],
            ],
        ])
    );

    // parseResponseToModelMetadataList() is protected: reach it through a subclass.
    $parser = new class extends \CommandCode\AiProvider\Metadata\CommandCodeModelMetadataDirectory {
        /**
         * Exposes the protected parser.
         *
         * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The model list response.
         * @return list<\WordPress\AiClient\Providers\Models\DTO\ModelMetadata> The parsed models.
         */
        public function parse(\WordPress\AiClient\Providers\Http\DTO\Response $response): array
        {
            return $this->parseResponseToModelMetadataList($response);
        }
    };

    $models = $parser->parse($response);
    check(count($models) === 5, 'all five models in the list response are parsed');

    $byId = [];
    $orderedIds = [];
    foreach ($models as $model) {
        $byId[$model->getId()] = $model;
        $orderedIds[] = $model->getId();
    }

    check($orderedIds[0] === 'claude-sonnet-5', 'preferred model is sorted first');
    check(
        array_search('meituan/LongCat-2.0:free', $orderedIds, true)
            > array_search('gpt-5.6-luna', $orderedIds, true),
        'free tier model sorts after paid models'
    );
    check(
        array_search('claude-haiku-4-5-20251001', $orderedIds, true)
            > array_search('claude-sonnet-5', $orderedIds, true),
        'dated snapshot sorts after the version-less alias'
    );

    $optionNames = static function (\WordPress\AiClient\Providers\Models\DTO\ModelMetadata $model): array {
        return array_map(
            static fn($option): string => $option->getName()->value,
            $model->getSupportedOptions()
        );
    };

    check(in_array('temperature', $optionNames($byId['claude-sonnet-5']), true), 'Claude declares temperature');
    check(!in_array('candidateCount', $optionNames($byId['claude-sonnet-5']), true), 'Claude does not declare a candidate count');
    check(in_array('temperature', $optionNames($byId['deepseek/deepseek-v4-flash']), true), 'DeepSeek declares temperature');
    check(!in_array('temperature', $optionNames($byId['gpt-5.6-luna']), true), 'GPT-5 family does not declare temperature');

    $inputModalities = null;
    foreach ($byId['claude-sonnet-5']->getSupportedOptions() as $option) {
        if ($option->getName()->isInputModalities()) {
            $inputModalities = $option->getSupportedValues();
        }
    }
    check(
        is_array($inputModalities) && count($inputModalities) === 2,
        'vision model declares two input modality combinations'
    );

    // Request building: base URL, path, auth header and the ZDR/User-Agent headers.
    $request = new \WordPress\AiClient\Providers\Http\DTO\Request(
        \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum::POST(),
        \CommandCode\AiProvider\Provider\CommandCodeProvider::url('messages'),
        ['Content-Type' => 'application/json', 'User-Agent' => CommandCodeConfig::getUserAgent()],
        ['model' => 'claude-sonnet-5']
    );
    $authenticated = (new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('test-key'))
        ->authenticateRequest($request);
    $headers = $authenticated->getHeaders();

    check(
        $authenticated->getUri() === 'https://api.commandcode.ai/provider/v1/messages',
        'request URL points at the Command Code Messages endpoint'
    );
    check(
        isset($headers['Authorization'][0]) && $headers['Authorization'][0] === 'Bearer test-key',
        'request carries the Bearer token'
    );
    check(!isset($headers['x-cmd-zdr']), 'no ZDR header unless enabled');

    // --- Both model classes, end to end through a fake HTTP transporter. -----------------------
    $providerMetadata = \CommandCode\AiProvider\Provider\CommandCodeProvider::metadata();

    /**
     * Records the request it is handed and replays a canned response.
     */
    $transporter = new class implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
        /** @var \WordPress\AiClient\Providers\Http\DTO\Request|null */
        public $request = null;

        /** @var array<string, mixed> */
        public $body = [];

        /** @var array<string, mixed> */
        public $queue = [];

        /**
         * Sends a request and records it.
         *
         * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
         * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Transport options.
         * @return \WordPress\AiClient\Providers\Http\DTO\Response The canned response.
         */
        public function send(
            \WordPress\AiClient\Providers\Http\DTO\Request $request,
            ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null
        ): \WordPress\AiClient\Providers\Http\DTO\Response {
            $this->request = $request;
            $this->body = (array) $request->getData();

            return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode($this->queue));
        }
    };

    // Claude: Anthropic Messages route, with a tool call and usage reporting.
    $anthropicModel = new \CommandCode\AiProvider\Models\CommandCodeAnthropicTextGenerationModel(
        $byId['claude-sonnet-5'],
        $providerMetadata
    );
    $anthropicModel->setHttpTransporter($transporter);
    $anthropicModel->setRequestAuthentication(
        new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('test-key')
    );
    $anthropicModel->setRequestOptions(CommandCodeConfig::createRequestOptions());

    // The model asks for these parameters via its config; the canned response is read by the fake transport.
    $config = \WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'systemInstruction' => 'Be terse.',
        'maxTokens' => 256,
        'temperature' => 0.2,
        'functionDeclarations' => [],
    ]);
    $anthropicModel->setConfig($config);

    // Reply shaped like the Anthropic Messages API.
    $anthropicReply = [
        'id' => 'msg_123',
        'role' => 'assistant',
        'content' => [
            ['type' => 'thinking', 'thinking' => 'pondering'],
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'tool_use', 'id' => 'tool_1', 'name' => 'get_weather', 'input' => []],
        ],
        'stop_reason' => 'tool_use',
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cache_read_input_tokens' => 100,
        ],
    ];

    $transporter->queue = $anthropicReply;
    $result = $anthropicModel->generateTextResult([new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [new \WordPress\AiClient\Messages\DTO\MessagePart('hi')]
    )]);

    check(
        $transporter->request->getUri() === 'https://api.commandcode.ai/provider/v1/messages',
        'Claude request goes to the Messages endpoint'
    );
    check($transporter->body['model'] === 'claude-sonnet-5', 'Claude request carries the model ID');
    check($transporter->body['max_tokens'] === 256, 'max_tokens is forwarded');
    check($transporter->body['temperature'] === 0.2, 'temperature is forwarded');
    check($transporter->body['system'] === 'Be terse.', 'system instruction becomes the system parameter');

    $candidate = $result->getCandidates()[0];
    $texts = [];
    foreach ($candidate->getMessage()->getParts() as $part) {
        if ($part->getType()->isText()) {
            $texts[] = $part->getText();
        }
    }
    check(in_array('Hello', $texts, true), 'Claude response text is parsed');
    check(in_array('pondering', $texts, true), 'Claude thinking block is parsed into a thought part');
    check(
        $candidate->getFinishReason()->isToolCalls(),
        'stop_reason tool_use maps to the tool-calls finish reason'
    );
    check($result->getTokenUsage()->getPromptTokens() === 110, 'cached prompt tokens are counted as input');
    check($result->getTokenUsage()->getCompletionTokens() === 5, 'output tokens are counted');

    // Non-Claude: OpenAI-compatible route, parsed by the SDK base class.
    $openAiModel = new \CommandCode\AiProvider\Models\CommandCodeTextGenerationModel(
        $byId['gpt-5.6-luna'],
        $providerMetadata
    );
    $openAiModel->setHttpTransporter($transporter);
    $openAiModel->setRequestAuthentication(
        new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('test-key')
    );
    $openAiModel->setRequestOptions(CommandCodeConfig::createRequestOptions());
    $openAiModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'temperature' => 0.9,
        'maxTokens' => 128,
        // What the AI plugin's Editorial Notes feature sends via as_json_response().
        'outputMimeType' => 'application/json',
        'outputSchema' => [
            'type' => 'object',
            'properties' => ['suggestions' => ['type' => 'array']],
        ],
    ]));

    $transporter->queue = [
        'id' => 'chatcmpl-1',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'Hi there'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10],
    ];
    $openAiResult = $openAiModel->generateTextResult([new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [new \WordPress\AiClient\Messages\DTO\MessagePart('hi')]
    )]);

    check(
        $transporter->request->getUri() === 'https://api.commandcode.ai/provider/v1/chat/completions',
        'GPT request goes to the chat completions endpoint'
    );
    check(
        !isset($transporter->body['temperature']),
        'temperature is stripped for reasoning families that reject it'
    );

    /*
     * Regression: the SDK base class emitted {"type":"json_schema","json_schema":<schema>}, which the
     * gateway rejects with 400 invalid_request_error, param: response_format.
     */
    $responseFormat = $transporter->body['response_format'] ?? null;
    check(
        is_array($responseFormat) && ($responseFormat['type'] ?? null) === 'json_schema',
        'structured output requests use the json_schema response format'
    );
    check(
        isset($responseFormat['json_schema']['name'], $responseFormat['json_schema']['schema']),
        'the JSON schema is wrapped in a named json_schema object'
    );
    check(
        ($responseFormat['json_schema']['schema']['type'] ?? null) === 'object',
        'the schema wrapper carries the caller schema unchanged'
    );
    check(
        ($responseFormat['json_schema']['schema']['properties']['suggestions']['type'] ?? null) === 'array',
        'nested schema properties survive the wrapping'
    );
    check(
        $openAiResult->getCandidates()[0]->getMessage()->getParts()[0]->getText() === 'Hi there',
        'OpenAI-compatible response text is parsed'
    );
}

// --- Result. -----------------------------------------------------------------------------------
fwrite(STDOUT, sprintf("\n%d checks, %d failure(s)\n", $checks, $failures));

exit($failures === 0 ? 0 : 1);
