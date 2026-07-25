# Bangumi List 看番清单

一个给 WordPress 加「看番清单」页面的独立插件:后台像写文章一样记录看过的番(封面 / 评分 / 评语),前台用**终端风海报网格**展示,风格与博客文章完全隔离。

> A WordPress plugin that adds a terminal-styled anime watch-list page. Manage entries in wp-admin, render anywhere with the `[anime_list]` shortcode.

![看番清单前台效果](docs/screenshot.png)

<p align="center"><sub>前台效果 · 短代码 <code>[anime_list]</code> · 图为 <a href="https://thornsw.cn/anime">thornsw.cn/anime</a></sub></p>

## 特性

- 注册「动漫」内容类型(CPT `anime`),后台增删改,自带列表 / 编辑界面(特色图当封面)
- 每部番:番名、封面、评分(自由文本,如 `9.7/10`,也能写"神作"这种)、评语、观看方式、Bangumi 链接
- 前台短代码 `[anime_list]`:终端窗口外壳 + 海报网格,评分徽章 + 进度条,悬停(桌面)/ 点按(移动)看评语
- 前端搜索(番名)+ 按评分 / 最近添加排序,纯 JS,无需翻页
- 封面可从 Bangumi 自动抓取(WP-CLI),或后台手动上传;抓不到自动用「文字卡」占位
- 样式作用域化(`.bgm-*`),不污染主题;复用等宽字体(推荐 Maple Mono NF CN)
- 标题、副标题、终端用户名、主题色均可通过短代码属性自定义

## 要求

- WordPress 5.8+
- PHP 7.4+
- (可选)WP-CLI —— 用于批量导入 / 自动抓封面

## 安装

### 推荐:下载 Release

去 **[Releases](https://github.com/ThornsW/bangumi-list/releases/latest)** 下载 `bangumi-list-x.y.z.zip`,后台「插件 → 安装插件 → 上传插件」选它,**无需改名**。

> ⚠️ 别用 Release 页面上的「Source code (zip)」,也别用仓库主页绿色按钮的「Download ZIP」—— 那两个解压出来分别是 `bangumi-list-x.y.z/` 和 `bangumi-list-main/`,目录名不对。

### 从源码安装

插件目录必须叫 `bangumi-list`,否则和文档、日后的更新机制对不上。

```bash
# 方式 A:git clone(目录名天然正确)
cd wp-content/plugins/
git clone https://github.com/ThornsW/bangumi-list.git

# 方式 B:下载源码 zip 后改名
cd wp-content/plugins/
unzip ~/bangumi-list-main.zip
mv bangumi-list-main bangumi-list
```

### 装好之后

1. 后台「插件」启用 **Bangumi List 看番清单**
2. 新建一个页面,内容写上短代码 `[anime_list]`,发布(建议用全宽 / 空白页面模板)
3. 后台左侧「看番」里添加番剧,设置「特色图」当封面

## 短代码用法

`[anime_list]`,可选属性:

| 属性 | 说明 | 默认 |
|---|---|---|
| `title` | 页面大标题 | `看番清单` |
| `subtitle` | 副标题一行 | `# 记录看过的番、我的评分与短评` |
| `handle` | 终端标题栏用户名 | `user@blog` |
| `path` | 终端路径标签 | `~/anime` |
| `accent` | 主题强调色(hex) | `#7ee787` |

例:`[anime_list title="我的番剧" handle="me@site" accent="#22d3ee"]`

## 批量导入(WP-CLI)

JSON 格式(见 `examples/sample-anime.json`):

```json
[{ "title": "番名", "rating": "9.7/10", "review": "评语", "watch_mode": "常规" }]
```

导入:

```bash
wp bangumi import /path/to/anime.json
```

- 幂等:按番名判重,重复执行安全
- 自动跳过"没看成"条目(评分和评语都空,或含「看不了 / G了」)
- 从 Excel 转 JSON 可参考 `examples/xlsx_to_json.py`

## 封面

两种方式:

**1. 自动抓取(需服务器能访问 `api.bgm.tv`)**

```bash
wp bangumi fetch-covers --limit=10     # 先试 10 部,核对匹配
wp bangumi fetch-covers                # 全部
wp bangumi fetch-covers --overwrite    # 连已有封面也重抓
```

封面会下载进你的媒体库(不盗链),匹配不到的保持文字卡占位。

**2. 手动**:后台编辑番 → 设「特色图」。

> ⚠️ 部分服务器(尤其某些地区的云主机)可能因 DNS 污染 / 网络原因访问不到 `api.bgm.tv`,此时 `fetch-covers` 会全部 MISS。可在能联网的机器上先抓好封面再上传,或直接后台手动传特色图。

## 自定义

- **字体**:默认等宽字体栈(首选 Maple Mono NF CN)。想统一观感,可在主题里自托管该字体。
- **抓取 User-Agent**:默认取站点地址,可用过滤器 `bangumi_list_user_agent` 覆盖。
- **配色 / 文案**:见上方短代码属性。

## 数据模型

CPT `anime` + post meta:`_bgm_rating_text`(评分文本)、`_bgm_rating_value`(解析出的数字,排序用)、`_bgm_review`(评语)、`_bgm_watch_mode`(观看方式,前台不显示)、`_bgm_url`(bgm 链接)。

## 注意

- 站点若用了页面缓存(如 WP Fastest Cache),前端改动后记得清缓存。
- 「观看方式」仅后台保留,前台默认不显示不筛选。

## 致谢

封面 / 番剧数据来自 [Bangumi 番组计划](https://bgm.tv)。

## 许可证

GPL-2.0-or-later,详见 [LICENSE](LICENSE)。
