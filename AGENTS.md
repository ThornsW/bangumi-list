# AGENTS.md

## 项目定位

本仓库是独立 WordPress 插件 `Bangumi List 看番清单` 的唯一源码项目，负责：

- 注册 `anime` 自定义内容类型和后台编辑字段。
- 通过 `[anime_list]` 短代码渲染看番清单。
- 提供 WP-CLI 导入、评分规范化和封面处理命令。
- 提供本机数据转换与封面抓取工具。

本项目不负责服务器盘点、部署记录、数据库备份或线上运维；这些工作属于相邻的运维项目。修改本仓库不会自动部署到生产环境。

## 兼容性与发布

- WordPress 5.8+
- PHP 7.4+
- 插件目录名必须保持为 `bangumi-list`
- 前端使用原生 JavaScript/CSS，无构建链
- 用户界面目前为硬编码简体中文，尚未启用 i18n

发布新版本时同步检查：

1. `bangumi-list.php` 插件头中的 `Version`
2. `BGM_VERSION`
3. README 中受版本影响的命令和行为
4. Git tag、Release 名称和发布包目录结构

不要引入 PHP 7.4 不支持的语法。优先发布经过版本化的 Release 包，不把整个开发仓库直接上传到生产环境。

## 目录职责

- `bangumi-list.php`：插件入口、CPT、后台字段、保存逻辑和 WordPress 接线
- `includes/helpers.php`：纯函数和可独立验证的逻辑
- `includes/class-bgm-fetcher.php`：封面搜索、校验和媒体库导入
- `includes/render.php`：短代码查询与 HTML 渲染
- `cli/import-command.php`：`wp bangumi ...` 命令
- `assets/`：作用域化 CSS 和原生 JavaScript
- `tools/`：本机维护工具
- `examples/`：公开示例和格式转换示例
- `docs/`：公开截图与说明素材
- `data/`：本机私人数据和恢复材料，不属于源码或发布物

保持现有单一职责，不把服务器部署脚本、线上运维记录或凭据混入本仓库。

## 本地私人数据

`data/` 保存个人清单、导入 JSON、封面、manifest 和历史恢复材料，已由 `.gitignore` 忽略。

- 不提交 `data/`，不加入 Release、部署包、Issue、PR 或公开日志。
- 不在回复中展示个人清单全文或批量输出其中内容。
- 默认保留；删除、覆盖、批量重建或对外复制前取得用户明确同意。
- 不在其中保存数据库导出、`wp-config.php`、密码、token、cookie 或私钥。
- 线上 WordPress 数据库和媒体库才是运行时数据源；`data/` 不会自动同步线上状态。
- 封面仅作为个人博客的本地工作资料，版权归各自版权方。

`covers/`、`anime-list.json`、`examples/*.local.json` 和 Python 缓存同样是本地生成物，不得提交或发布。

## 代码约定

- 全局 PHP 函数、类、常量、过滤器和资源句柄继续使用 `bgm_`、`BGM_` 或 `bangumi_list_` 前缀。
- WordPress 输入必须进行 nonce、权限、`wp_unslash()` 和对应的 sanitize 检查。
- HTML、属性和 URL 输出必须使用对应的 escaping 函数。
- 数据库和媒体库操作优先使用 WordPress API，不直接拼接 SQL。
- 外部下载使用 HTTPS、合理超时、文件类型与封面比例校验；manifest 路径必须限制在指定目录内，避免路径穿越。
- CSS 保持在 `.bgm-*` 范围内；资源只在短代码实际渲染时加载。
- 前端继续使用原生 JavaScript；引入构建链或第三方运行时依赖前先说明必要性。
- 用户可见行为、短代码属性、WP-CLI 参数或数据格式改变时同步更新 README。
- 抓取 Bangumi 或蜜柑数据时保留明确的 User-Agent 和限流；请求间隔不得低于 0.3 秒。

## Python 环境

本机使用 Miniforge/mamba，不要向 `base` 安装项目依赖。

- `tools/fetch-covers-local.py` 当前只依赖 Python 3.7+ 标准库。
- `examples/xlsx_to_json.py` 依赖 `openpyxl`。
- 本项目尚未声明固定 Python 环境；运行 Python 工具或安装依赖前先确认应使用的环境。
- 出现稳定的第三方依赖时，创建 `environment.yml` 并使用独立 mamba 环境。

## 验证

根据改动范围执行最小充分验证。

PHP 修改：

```bash
find . -path './data' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

前端 JavaScript 修改：

```bash
node --check assets/anime.js
```

Python 修改（先确认并激活项目环境）：

```bash
python -m py_compile tools/fetch-covers-local.py examples/xlsx_to_json.py
```

当前没有自动化测试框架。涉及核心行为时重点验证：

- 插件可启用且没有 PHP warning/fatal。
- `[anime_list]` 能渲染空清单、有封面及无封面的条目。
- 搜索、两种排序和移动端点按交互正常。
- 后台保存继续执行 nonce、权限和数据清洗。
- 评分解析兼容纯数字、`x/10`、空值和自由文本。
- 封面比例、最小宽度、失败回退及源文件保留逻辑正确。

`wp bangumi normalize-ratings` 和 `wp bangumi import-covers` 应先使用 `--dry-run`。`wp bangumi import`、`wp bangumi fetch-covers` 以及任何没有 dry-run 的写命令不得直接在生产环境试验。

## 安全与线上操作

禁止把 SSH、面板、数据库或 WordPress 密码，API token、cookie、authorization header、`wp-config.php` 内容、SSL 私钥、数据库导出和私人清单写入仓库、命令记录或最终回复。

生产环境的安装、升级、启停、导入、封面覆盖或数据库写操作必须：

1. 转到运维项目并遵守其 `AGENTS.md`；本仓库不承担部署职责。
2. 先做只读检查，确认线上版本和实时状态。
3. 取得用户对线上变更的明确同意。
4. 按既有备份目录规范创建带 `manifest.txt` 的备份，内含插件目录拷贝与相关 meta 导出。
5. 小步部署并验证 PHP、WordPress、HTTP、日志和磁盘空间。
6. 把部署结果、备份位置和回滚方式写入运维项目的操作日志。

具体的服务器地址、WordPress 根目录、插件部署路径和备份目录属于环境信息，
不写入本仓库；需要时从运维项目获取。

线上插件目录是精简版，只含运行时文件（插件入口、`includes/`、`assets/`、`cli/`），
不要把 `.git/`、`data/`、`tools/`、`examples/`、`docs/`、Python 缓存、私人清单
或临时抓图目录上传到生产环境。

## 沟通

- 默认使用中文，除非用户明确要求其他语言。
- 先给结论，再给必要证据和可执行步骤。
- 完成修改后说明改动范围、验证结果和仍存在的风险。
