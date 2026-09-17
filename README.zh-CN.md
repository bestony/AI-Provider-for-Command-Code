# AI Provider for Command Code

[English](README.md) · **简体中文**

把 [Command Code](https://commandcode.ai/docs/provider) 接入 WordPress AI Client：一个 API Key
即可使用 Claude、GPT、Gemini 以及主流开源模型。

## 功能

* 模型列表实时取自 `GET /provider/v1/models`，新上线的模型无需更新插件即可出现。
* Claude 模型走 Anthropic Messages 端点，其余模型走 OpenAI 兼容的 chat completions 端点 —— 与
  接口为每个模型返回的 `supported_endpoints` 字段保持一致。
* 在模型能力允许的范围内支持工具调用、结构化输出（JSON Schema）、多轮对话、停止序列与多候选生成。

## 环境要求

* WordPress 6.9 及以上，且具备 PHP AI Client SDK（WordPress 7.0 自带，或由 AI 插件提供）
* PHP 7.4 及以上
* 一个带 API 权限的 Command Code 套餐 —— 免费的 Go 套餐没有 API 权限；Provider、GOAT、Pro、Max、
  Team 套餐可用

## 安装

从 [Releases](../../releases) 下载 zip，用 **插件 → 安装插件 → 上传插件** 上传；或把插件目录复制到
`wp-content/plugins/ai-provider-for-command-code/`。启用后进入 **设置 → 连接器（Connectors）**，打开
Command Code 卡片，粘贴你的 Provider API Key。

## 配置

API Key 的读取优先级依次为：

1. `COMMANDCODE_API_KEY` 环境变量
2. `COMMANDCODE_API_KEY` PHP 常量
3. `connectors_ai_commandcode_api_key` 选项（设置 → 连接器）

可选配置均为环境变量或 PHP 常量：

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `COMMANDCODE_DEFAULT_MODEL` | `claude-sonnet-5` | 在模型选择器和功能筛选器中优先使用的模型 ID |
| `COMMANDCODE_ZDR` | 关闭 | 设为 `1` 时发送 `x-cmd-zdr: 1`，只路由到零数据保留的上游；若所选模型没有此类上游，请求会以 422 失败而不是静默降级 |
| `COMMANDCODE_BASE_URL` | `https://api.commandcode.ai/provider/v1` | Provider API 基础地址 |
| `COMMANDCODE_MODEL_INPUT_MODALITIES` | 仅文本 | 逗号分隔的输入模态，如需视觉功能设为 `text,image` |
| `COMMANDCODE_REQUEST_TIMEOUT` | `120` | 请求超时（秒） |
| `COMMANDCODE_CONNECT_TIMEOUT` | `10` | 连接超时（秒） |
| `COMMANDCODE_STRUCTURED_OUTPUT` | `json_schema` | 取值 `json_schema`、`json_object` 或 `none`；若某个 JSON 功能报 `400 ... "param":"response_format"`，改用 `json_object` |

若想改变 AI 插件为各功能挑选模型的顺序，使用标准过滤器即可 —— 本插件已把自己的首选模型放在最前：

```php
add_filter( 'wpai_preferred_text_models', function ( $models ) {
    array_unshift( $models, array( 'commandcode', 'gpt-5.6-luna' ) );
    return $models;
} );
```

## 数据与隐私

你发给 AI 的提示词，以及调用方插件或主题附加的内容（系统指令、对话历史、工具定义、JSON Schema、附件）
都会发送到 `api.commandcode.ai`。API Key 保存在你自己的站点上，且只会发送到该域名。只有在你的站点
实际请求 AI Client 生成内容时才会发起请求。

* Provider API 文档：<https://commandcode.ai/docs/provider>
* 服务条款：<https://commandcode.ai/terms>
* 隐私政策：<https://commandcode.ai/privacy>
* 零数据保留：<https://commandcode.ai/docs/resources/zdr>

## 开发

```
php scripts/selfcheck.php                                    # 逻辑自检，无需 WordPress
php scripts/selfcheck.php --sdk=/path/to/wordpress-php-ai-client/src   # 额外跑一遍真实 SDK 的检查
scripts/bump-version.sh patch                                # 递增版本 patch|minor|major，之后自行补 changelog
```

发布：推送与插件版本一致的 tag（`git tag v1.0.4 && git push origin v1.0.4`）。
[发布工作流](.github/workflows/release.yml) 会校验 tag 与 `Version:` / `Stable tag:` 是否一致，
打包插件 zip，并从 `readme.txt` 取更新日志创建 Release。

## 许可证

GPL-2.0-or-later，见 [LICENSE](LICENSE)。

完整的 WordPress 插件说明（含更新日志）在 [readme.txt](readme.txt)。
