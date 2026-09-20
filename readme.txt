=== AI Provider for Command Code ===
Contributors:      bestony
Tags:              ai, connector, claude, gpt, command code
Requires at least: 6.9
Tested up to:      7.1
Stable tag:        1.0.5
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Command Code provider for the PHP AI Client: Claude, GPT, Gemini and leading open models through one API.

== Description ==

Adds [Command Code](https://commandcode.ai/docs/provider) as a provider for the WordPress AI Client.
Command Code routes Claude, GPT, Gemini and the top open models through a single API with
OpenAI-compatible and Anthropic-compatible endpoints, so one API key covers every model the
AI plugin can use.

* The model list is fetched live from `GET /provider/v1/models`, including newly added models.
* Claude models are called on the Anthropic Messages endpoint, everything else on the
  OpenAI-compatible chat completions endpoint — matching the `supported_endpoints` field the API
  reports for each model.
* Tool calling, structured output (JSON schema), chat history, stop sequences and multi-candidate
  generation are supported where the model supports them.
* Zero data retention can be requested per request (see below).

== Screenshots ==

1. The Command Code connector card on Settings → Connectors, with the API key field and the
   models the provider reports.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-command-code/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings → Connectors, open the Command Code card and paste your Provider API key — or
   define it outside the database (see Configuration).

A Command Code plan with API access is required. The Go plan has no API access; Provider, GOAT, Pro,
Max and Team plans do.

== Configuration ==

The API key is read, in order of precedence, from:

1. The `COMMANDCODE_API_KEY` environment variable
2. The `COMMANDCODE_API_KEY` PHP constant
3. The `connectors_ai_commandcode_api_key` option (Settings → Connectors)

All optional settings are environment variables or PHP constants:

* `COMMANDCODE_DEFAULT_MODEL` — model ID to prefer in pickers and feature filters.
  Default: `claude-sonnet-5`.
* `COMMANDCODE_ZDR` — set to `1` to send `x-cmd-zdr: 1` and route only through zero-data-retention
  upstreams. Requests fail with a 422 when the chosen model has no ZDR-capable upstream instead of
  silently falling back. Default: off.
* `COMMANDCODE_BASE_URL` — Provider API base URL.
  Default: `https://api.commandcode.ai/provider/v1`.
* `COMMANDCODE_MODEL_INPUT_MODALITIES` — comma-separated list of modalities your models accept.
  Include `image` (e.g. `text,image`) for deployments that require vision features. Default: text only.
* `COMMANDCODE_REQUEST_TIMEOUT` — request timeout in seconds. Default: `120`.
* `COMMANDCODE_CONNECT_TIMEOUT` — connection timeout in seconds. Default: `10`.
* `COMMANDCODE_STRUCTURED_OUTPUT` — how JSON response requests are shaped. One of:
  * `json_schema` (default) — sends the JSON schema. Use this by default.
  * `json_object` — asks only for valid JSON, without the schema. Widely supported fallback.
  * `none` — sends no `response_format` at all. Use this when a model rejects every form of it.
  If a feature that needs JSON (e.g. Editorial Notes) fails with
  `400 ... "param":"response_format"`, try `json_object`.

To influence which models the AI plugin picks for its features, use the standard filters in your own
plugin or theme — this plugin already puts its preferred model first:

    add_filter( 'wpai_preferred_text_models', function ( $models ) {
        array_unshift( $models, array( 'commandcode', 'gpt-5.6-luna' ) );
        return $models;
    } );

== Frequently Asked Questions ==

= Does this plugin work without the PHP AI Client? =

No. It requires the PHP AI Client SDK, which is provided by WordPress 7.0 or by the AI plugin. The
provider stays silent when the SDK is missing.

= Do I need to configure anything in the database? =

Only the API key, and only if you cannot set an environment variable or constant. The provider adds
no settings page of its own: everything it needs is on Settings → Connectors.

= Which models can be used with vision features? =

Command Code's models endpoint does not report which models accept images, so the plugin declares
image input from a maintained list in the source. If your model supports images but is not on that
list, set `COMMANDCODE_MODEL_INPUT_MODALITIES=text,image` to declare it.

= Why is `temperature` missing on GPT-5 models? =

GPT-5 and later reasoning families reject non-default sampling parameters, so the plugin does not
advertise them for those models. That is upstream behaviour, not a plugin limitation.

= Editorial Notes fails with `400 ... response_format` =

That feature asks for a JSON schema response. Set `COMMANDCODE_STRUCTURED_OUTPUT=json_object` to ask
for plain JSON instead, or `none` to send no `response_format` at all — the prompt still asks for JSON.

= Where is my API key stored? =

In the WordPress options table on your own site, under the AI Client's connector option
(`connectors_ai_commandcode_api_key`), or in an environment variable or PHP constant if you set one.
It is never transmitted anywhere except to the Command Code API when fulfilling a request.

= What data leaves my site? =

Only what you send to the AI: your prompts, and whatever the calling plugin or theme adds to them
(system instructions, conversation history, tool definitions, a JSON schema, or attached files). See
External services below for the exact endpoints.

= Is there a settings page? =

No. Everything this plugin needs lives on Settings → Connectors, and the optional behaviour is
controlled by environment variables or constants.

== External services ==

This plugin connects to the Command Code Provider API, an external service operated by Command Code.
It is required so the WordPress AI Client can route requests to models from your site. Command Code is
a paid service: a plan with API access is needed (the free Go plan has none), and requests are billed
to your Command Code account.

The plugin contacts the following endpoints under `https://api.commandcode.ai/provider/v1`:

* `GET /models` — called when the AI Client refreshes its list of available models, and when it checks
  whether your credentials work. No user content is sent; only your API key, so Command Code can
  return the models available to your account.
* `POST /chat/completions` — called whenever any plugin or theme on your site uses the WordPress AI
  Client to generate text with a non-Claude model. The request carries your API key and the prompt:
  messages, system instruction, tool definitions, and any other parameters the calling code supplied
  (conversation history, a JSON schema for structured output, or files attached to the prompt).
* `POST /messages` — the same as above, for Claude models, which Command Code serves only on this
  endpoint.

No request is made until something on your site asks the AI Client for a generation, or the AI Client
refreshes its model list. Your API key is stored on your own site and is only ever sent to
`api.commandcode.ai`.

Optional: setting `COMMANDCODE_ZDR=1` adds an `x-cmd-zdr: 1` header, asking Command Code to route the
request only through zero-data-retention upstreams. Requests fail with a 422 when a chosen model has
no such upstream, rather than falling back silently.

This service is provided by Command Code:

* Provider API documentation: [https://commandcode.ai/docs/provider](https://commandcode.ai/docs/provider)
* Terms of Service: [https://commandcode.ai/terms](https://commandcode.ai/terms)
* Privacy Policy: [https://commandcode.ai/privacy](https://commandcode.ai/privacy)
* Zero data retention: [https://commandcode.ai/docs/resources/zdr](https://commandcode.ai/docs/resources/zdr)

== Changelog ==

= 1.0.6 =
* Stop reading the `connectors_ai_commandcode_api_key` option directly. Whether a Command Code
  credential is configured is now asked of the AI Client, so the plugin never handles the key the
  user saved in Settings → Connectors. Behaviour is unchanged: the `COMMANDCODE_API_KEY` constant and
  environment variable still work.

= 1.0.3 =
* Document the external service in the readme: the endpoints contacted, what data is sent and when,
  and links to Command Code's Terms of Service and Privacy Policy.
* Ship the GPL-2.0 LICENSE file with the plugin.

= 1.0.2 =
* Escape all exception messages and mark them for translation, so Plugin Check passes cleanly.
* Add a direct-access guard to the autoloader.
* Reduce the readme to five tags.

= 1.0.1 =
* Fix `400 invalid_request_error: response_format` on features that request a JSON schema, such as
  Editorial Notes. The JSON schema is now sent in the wrapper the API expects.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.6 =
The stored Command Code API key is no longer read by the plugin; the AI Client reports whether a credential is configured.

= 1.0.3 =
Adds the External services documentation and the GPL license file.

= 1.0.2 =
Plugin Check cleanup: escaped exception messages, autoloader direct-access guard, five readme tags.

= 1.0.1 =
Fix `400 invalid_request_error: response_format` on JSON schema features such as Editorial Notes.
