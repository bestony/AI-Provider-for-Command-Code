# AI Provider for Command Code

[Command Code](https://commandcode.ai/docs/provider) as a provider for the WordPress AI Client:
Claude, GPT, Gemini and leading open models through one API key.

## What it does

* Fetches the model list live from `GET /provider/v1/models`, so newly added models show up on their own.
* Calls Claude models on the Anthropic Messages endpoint and everything else on the OpenAI-compatible
  chat completions endpoint, matching the `supported_endpoints` field the API reports per model.
* Supports tool calling, structured output (JSON schema), chat history, stop sequences and
  multi-candidate generation wherever the model supports them.

## Requirements

* WordPress 6.9 or newer, with the PHP AI Client SDK (bundled in WordPress 7.0, or provided by the AI plugin)
* PHP 7.4 or newer
* A Command Code plan with API access — the free Go plan has none; Provider, GOAT, Pro, Max and Team do

## Install

Download the zip from [Releases](../../releases) and upload it through **Plugins → Add New → Upload
Plugin**, or copy the plugin folder to `wp-content/plugins/ai-provider-for-command-code/`. Activate it,
then open **Settings → Connectors**, open the Command Code card and paste your Provider API key.

## Configuration

The API key is read in order of precedence from:

1. the `COMMANDCODE_API_KEY` environment variable
2. the `COMMANDCODE_API_KEY` PHP constant
3. the `connectors_ai_commandcode_api_key` option (Settings → Connectors)

Optional settings are environment variables or PHP constants:

| Setting | Default | Purpose |
| --- | --- | --- |
| `COMMANDCODE_DEFAULT_MODEL` | `claude-sonnet-5` | Model ID to prefer in pickers and feature filters |
| `COMMANDCODE_ZDR` | off | `1` sends `x-cmd-zdr: 1`, routing only through zero-data-retention upstreams; a model with no ZDR upstream fails with 422 instead of falling back |
| `COMMANDCODE_BASE_URL` | `https://api.commandcode.ai/provider/v1` | Provider API base URL |
| `COMMANDCODE_MODEL_INPUT_MODALITIES` | text only | Comma-separated input modalities, e.g. `text,image` for vision features |
| `COMMANDCODE_REQUEST_TIMEOUT` | `120` | Request timeout, seconds |
| `COMMANDCODE_CONNECT_TIMEOUT` | `10` | Connection timeout, seconds |
| `COMMANDCODE_STRUCTURED_OUTPUT` | `json_schema` | `json_schema`, `json_object`, or `none` — use `json_object` if a JSON feature fails with `400 ... "param":"response_format"` |

To change which model the AI plugin picks for its features, use the standard filter — this plugin
already puts its preferred model first:

```php
add_filter( 'wpai_preferred_text_models', function ( $models ) {
    array_unshift( $models, array( 'commandcode', 'gpt-5.6-luna' ) );
    return $models;
} );
```

## Data and privacy

Prompts you send to the AI, plus whatever the calling plugin or theme adds to them (system
instructions, conversation history, tool definitions, a JSON schema, attached files), are sent to
Command Code at `api.commandcode.ai`. Your API key is stored on your own site and is only ever sent
to that host. Nothing is sent until your site actually asks the AI Client for a generation.

* Provider API docs: <https://commandcode.ai/docs/provider>
* Terms of Service: <https://commandcode.ai/terms>
* Privacy Policy: <https://commandcode.ai/privacy>
* Zero data retention: <https://commandcode.ai/docs/resources/zdr>

## Development

```
php scripts/selfcheck.php                                    # logic checks, no WordPress needed
php scripts/selfcheck.php --sdk=/path/to/wordpress-php-ai-client/src   # plus live-SDK checks
scripts/bump-version.sh patch                                # bump patch|minor|major, then add a changelog entry
```

Release: push a tag matching the plugin version (`git tag v1.0.4 && git push origin v1.0.4`).
The [release workflow](.github/workflows/release.yml) verifies the tag against `Version:`/`Stable tag:`,
builds the plugin zip and publishes it with a changelog taken from `readme.txt`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

The full WordPress plugin readme, including the changelog, lives in [readme.txt](readme.txt).
