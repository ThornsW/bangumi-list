#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
本机侧封面抓取器 —— Bangumi List 看番清单。

为什么放在本机而不是插件里:很多服务器(尤其某些地区的云主机)连不通 mikanani.me
甚至 api.bgm.tv,插件内直抓会全部 MISS。改由能上网的机器抓好图再回传,
VPS 侧用 `wp bangumi import-covers` 从本地目录入库,全程不走网络。

网络受限时用 --proxy 指定代理,或设 HTTPS_PROXY 环境变量。脚本开跑前会先探一次
图源,连不通就直接报错退出,不会让你空等几小时。

抓取顺序(每部番):
    mikan 搜 → 标题比对挑最像的 → 下载原图 → 比例闸
        ↓ miss / 不过闸
    bgm 搜 → 逐个候选下载 → 比例闸
        ↓ 都不过
    记为 miss,前台继续显示「待配封面」占位卡

比例闸的由来,两类坏图:
  ① 横版主视觉 —— bgm 的 type=2 是全库搜索,混着「特别纪念动画」「联动 PV」这类
     条目,它们不用海报而用横版 keyvisual(实测 1.41、1.59)。进了 3:4 的框会被
     裁掉一半宽度。
  ② 蓝光碟/DVD 盒封扫描 —— 接近正方形(实测 328x384 = 0.854)。不是横版,更隐蔽,
     但和满屏 0.707 的海报排在一起明显违和。
正规竖版海报实测集中在 0.707(√2/2),区间 0.659–0.756。上限取 0.8 既留足余量,
又同时挡得住这两类。

只用标准库,没有 requests / Pillow 依赖。

用法:
    # 1. VPS 上导出番名列表
    wp post list --post_type=anime --post_status=publish \
        --format=json --fields=ID,post_title > anime-list.json

    # 2. 回本机跑(HTTP(S)_PROXY 环境变量会被自动采用)
    python3 tools/fetch-covers-local.py anime-list.json -o covers/

    # 3. 回传并入库
    scp -r covers/ vps:/tmp/covers
    ssh vps 'wp bangumi import-covers /tmp/covers'
"""

import argparse
import html
import json
import os
import re
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from difflib import SequenceMatcher

MIKAN_HOST = "https://mikanani.me"
BGM_SEARCH = "https://api.bgm.tv/search/subject/{kw}?type=2&responseGroup=small&max_results={n}"

UA = "bangumi-list-cover-fetcher/1.0 (+https://github.com/ThornsW/bangumi-list)"
# mikan 是常规站点不是 API,用浏览器 UA 更不容易被中间层拦;bgm 官方要求带可识别 UA。
UA_BROWSER = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")

# mikan 搜索结果卡片:<a href="/Home/Bangumi/{id}"> … data-src="{图}" … class="an-text" title="{番名}"
MIKAN_CARD_RE = re.compile(
    r'<a\s+href="/Home/Bangumi/(?P<id>\d+)"'
    r'.*?data-src="(?P<img>/images/Bangumi/[^"]+)"'
    r'.*?class="an-text"[^>]*?title="(?P<name>[^"]*)"',
    re.S,
)

def _is_sep(ch):
    """标点 / 符号 / 空白 —— 比对和拆词时一律当分隔符。

    原本这里是手写的字符表,结果把 ASCII 逗号写了两遍、真正的全角逗号 U+FF0C 反而漏了
    (两者字形几乎一样,肉眼校不出来),导致「夫妇之上,恋人未满」拆不开。
    改用 Unicode 类别判定,不再靠枚举。
    """
    return unicodedata.category(ch)[0] in ("P", "S", "Z", "C")


def _sep_to_space(s):
    """标点换空格,用于生成宽松查询串。"""
    return "".join(" " if _is_sep(ch) else ch for ch in s)


def _split_on_sep(s):
    """按标点切段,返回非空片段。"""
    out, cur = [], []
    for ch in s:
        if _is_sep(ch):
            if cur:
                out.append("".join(cur))
                cur = []
        else:
            cur.append(ch)
    if cur:
        out.append("".join(cur))
    return out


def _trim_sep(s):
    """剥掉首尾的包裹符与空白(《》「」等),中间的保留。"""
    i, j = 0, len(s)
    while i < j and _is_sep(s[i]):
        i += 1
    while j > i and _is_sep(s[j - 1]):
        j -= 1
    return s[i:j]

# 个人清单里的番名常带自加的后缀(「第一季」「系列」「一二季」「-电影」),
# 图源按正式番名索引,带着这些搜必然落空。剥掉再搜一次。
_SUFFIX_RE = re.compile(
    r"(?:[\s\-–—·・]*"
    r"(?:第?[一二三四五六七八九十0-9]+(?:季|期|部|季度)|系列|剧场版|劇場版|电影|映画"
    r"|完结篇|总集篇|OVA|OAD|TV版|全集|合集|正篇)"
    r")+\s*$",
    re.I,
)


# --------------------------------------------------------------------------- 工具

def normalize_title(s):
    """归一化番名:全角转半角、去包裹符与标点、小写。用于跨站标题比对。"""
    s = unicodedata.normalize("NFKC", str(s or ""))
    return "".join(ch for ch in s if not _is_sep(ch)).lower()


def title_score(query, candidate):
    """0–1 的相似度。完全相同 > 互相包含 > 编辑距离。"""
    a, b = normalize_title(query), normalize_title(candidate)
    if not a or not b:
        return 0.0
    if a == b:
        return 1.0
    if a in b or b in a:
        # 包含关系按长度比给分,而且衰减要够狠:
        #   「我的青春恋爱物语果然有问题」对「…… 完」(13/14) → 0.98,该收
        #   「原神」对「原神展心之所向的旅途纪念动画」(2/14) → 0.53,该拒
        # 短番名会被任意长标题包含,给固定高分等于放弃判断。
        return 0.45 + 0.55 * (min(len(a), len(b)) / max(len(a), len(b)))
    return SequenceMatcher(None, a, b).ratio()


def query_variants(title):
    """按「宽松度递增」给几个查询串。

    mikan 搜「夫妇以上,恋人未满」是 0 结果,搜「夫妇以上」才有 —— 全角标点会
    把它的匹配打断。所以原串没结果时,依次退到去包裹符、标点换空格、只取最长片段。
    比对分数始终按原番名算,放宽的只是「搜什么」,不是「收什么」。
    """
    seen, out = set(), []

    def add(s):
        s = (s or "").strip()
        if s and s not in seen:
            seen.add(s)
            out.append(s)

    add(title)
    bare = _trim_sep(title)
    add(bare)
    add(_trim_sep(_SUFFIX_RE.sub("", bare)))     # 剥掉「第一季」「系列」这类自加后缀
    add(_sep_to_space(title).strip())            # 标点换空格
    parts = _split_on_sep(title)
    if parts:
        longest = max(parts, key=len)
        add(longest)
        add(_trim_sep(_SUFFIX_RE.sub("", longest)))
    return out


def image_size(buf):
    """从字节流头部读出 (宽, 高, 扩展名);认不出返回 (None, None, None)。

    自己解析头部是为了不引入 Pillow —— 这脚本要能在用户机器上裸跑。
    """
    if len(buf) < 24:
        return None, None, None

    # PNG: 8 字节签名 + IHDR(宽高各 4 字节大端)
    if buf[:8] == b"\x89PNG\r\n\x1a\n":
        w = int.from_bytes(buf[16:20], "big")
        h = int.from_bytes(buf[20:24], "big")
        return w, h, "png"

    # GIF: 'GIF87a'/'GIF89a' + 宽高各 2 字节小端
    if buf[:6] in (b"GIF87a", b"GIF89a"):
        w = int.from_bytes(buf[6:8], "little")
        h = int.from_bytes(buf[8:10], "little")
        return w, h, "gif"

    # WebP: RIFF....WEBP,分 VP8 / VP8L / VP8X 三种块
    if buf[:4] == b"RIFF" and buf[8:12] == b"WEBP":
        chunk = buf[12:16]
        if chunk == b"VP8 " and len(buf) >= 30:
            w = int.from_bytes(buf[26:28], "little") & 0x3FFF
            h = int.from_bytes(buf[28:30], "little") & 0x3FFF
            return w, h, "webp"
        if chunk == b"VP8L" and len(buf) >= 25:
            bits = int.from_bytes(buf[21:25], "little")
            return (bits & 0x3FFF) + 1, ((bits >> 14) & 0x3FFF) + 1, "webp"
        if chunk == b"VP8X" and len(buf) >= 30:
            w = int.from_bytes(buf[24:27], "little") + 1
            h = int.from_bytes(buf[27:30], "little") + 1
            return w, h, "webp"
        return None, None, "webp"

    # JPEG: 逐段跳到 SOFn 读宽高。C4/C8/CC 分别是 DHT/JPG/DAC,不是 SOF。
    if buf[:2] == b"\xff\xd8":
        i, n = 2, len(buf)
        while i + 9 < n:
            if buf[i] != 0xFF:
                i += 1
                continue
            marker = buf[i + 1]
            if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
                i += 2
                continue
            seg_len = int.from_bytes(buf[i + 2:i + 4], "big")
            if seg_len < 2:
                break
            if 0xC0 <= marker <= 0xCF and marker not in (0xC4, 0xC8, 0xCC):
                h = int.from_bytes(buf[i + 5:i + 7], "big")
                w = int.from_bytes(buf[i + 7:i + 9], "big")
                return w, h, "jpg"
            i += 2 + seg_len
        return None, None, "jpg"

    return None, None, None


def http_get(url, referer=None, browser_ua=False, timeout=25, retries=2):
    """GET 回字节;失败返回 None(打印一行原因,不抛)。"""
    headers = {
        "User-Agent": UA_BROWSER if browser_ua else UA,
        "Accept": "*/*",
        "Accept-Language": "zh-CN,zh;q=0.9",
    }
    if referer:
        headers["Referer"] = referer
    for attempt in range(retries + 1):
        try:
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                return resp.read()
        except (urllib.error.URLError, urllib.error.HTTPError, OSError) as exc:
            if attempt >= retries:
                print("      ! 请求失败 %s — %s" % (url[:80], exc), file=sys.stderr)
                return None
            time.sleep(1.2 * (attempt + 1))
    return None


# --------------------------------------------------------------------------- 图源

def mikan_candidates(title, timeout, delay=0.0, log=None):
    """搜 mikan,回 [(分数, 番名, 图片URL, 番组id)],按分数降序。

    原番名搜不到时会依次退到更宽松的查询串(见 query_variants)。
    """
    for i, q in enumerate(query_variants(title)):
        if i and log:
            log("      mikan 换个搜法:「%s」" % q)
        url = MIKAN_HOST + "/Home/Search?searchstr=" + urllib.parse.quote(q)
        body = http_get(url, referer=MIKAN_HOST + "/", browser_ua=True, timeout=timeout)
        if i:
            time.sleep(delay)
        if not body:
            continue
        text = body.decode("utf-8", "replace")

        out, seen = [], set()
        for m in MIKAN_CARD_RE.finditer(text):
            img = html.unescape(m.group("img")).split("?")[0]  # 去掉 ?width=400 才是原图
            if img in seen:
                continue
            seen.add(img)
            name = html.unescape(m.group("name"))
            # 分数始终按原番名算,查询放宽不等于收货标准放宽
            out.append((title_score(title, name), name, MIKAN_HOST + img, m.group("id")))
        if out:
            out.sort(key=lambda x: x[0], reverse=True)
            return out
    return []


def bgm_candidates(title, timeout, limit=5):
    """搜 bgm,回 [(分数, 番名, 图片URL, subject_id)],按分数降序。"""
    url = BGM_SEARCH.format(kw=urllib.parse.quote(title), n=limit)
    body = http_get(url, timeout=timeout)
    if not body:
        return []
    try:
        data = json.loads(body.decode("utf-8", "replace"))
    except ValueError:
        return []

    out = []
    for item in (data.get("list") or []):
        images = item.get("images") or {}
        img = images.get("large") or images.get("common") or ""
        if not img:
            continue
        # API 回的是 http://,换成 https 免得被中间层拦或降级
        if img.startswith("http://"):
            img = "https://" + img[len("http://"):]
        name = item.get("name_cn") or item.get("name") or ""
        out.append((title_score(title, name), name, img, str(item.get("id") or "")))
    out.sort(key=lambda x: x[0], reverse=True)
    return out


def try_candidates(cands, source, args, log):
    """逐个候选下载 + 过比例闸,返回第一个合格的 dict;都不合格返回 None。

    单结果特例:搜索只返回一条时,把标题门槛降到 --min-score-single。
    理由是各站译名不一致(「金色时光」在 mikan 叫「青春纪行」,字面比对必为 0),
    但搜索引擎既然认得这个别名、且没有第二个候选可选,就没有「挑错」的风险可言 ——
    字面分在这里帮不上忙,只会误杀。这类结果会标记 low_confidence 供事后核对。
    """
    single = len(cands) == 1
    gate = args.min_score_single if single else args.min_score

    for score, name, img_url, sid in cands[:args.max_candidates]:
        if score < gate:
            log("      跳过 %s「%s」(标题相似度 %.2f < %.2f)" % (source, name, score, gate))
            continue
        low_conf = score < args.min_score

        referer = MIKAN_HOST + "/" if source == "mikan" else None
        buf = http_get(img_url, referer=referer, browser_ua=(source == "mikan"),
                       timeout=args.timeout)
        time.sleep(args.delay)
        if not buf:
            continue

        w, h, ext = image_size(buf)
        if not w or not h:
            log("      跳过 %s「%s」(认不出图片格式)" % (source, name))
            continue

        ratio = w / h
        if not (args.ratio_min <= ratio <= args.ratio_max):
            log("      拦下 %s「%s」%dx%d 比例 %.3f —— 横版图,不是海报" % (source, name, w, h, ratio))
            continue
        if w < args.min_width:
            log("      拦下 %s「%s」%dx%d —— 宽度不足 %dpx" % (source, name, w, h, args.min_width))
            continue

        if low_conf:
            log("      ⚠ %s「%s」标题对不上(%.2f),但它是唯一结果,姑且收下 —— 记得核对" %
                (source, name, score))

        return {"data": buf, "source": source, "matched_name": name, "score": round(score, 3),
                "url": img_url, "width": w, "height": h, "ratio": round(ratio, 3),
                "ext": ext or "jpg", "source_id": sid, "low_confidence": low_conf}
    return None


def preflight(sources, timeout, log):
    """开跑前逐个探图源,连不通或解析不出来的直接禁用,返回还能用的。

    没有这一步的话,图源被墙时每个番名会白白耗掉「查询变体 × 重试」的全部超时 ——
    实测 60 部要空转约两小时,最后一张图也没有。宁可 30 秒内失败并说清原因。

    探测顺便验证解析器:mikan 连得通但一张卡都认不出,通常意味着它页面结构变了。
    """
    probe = "孤独摇滚"      # 常见番名,两个源都应当有
    alive = []
    for s in sources:
        t0 = time.time()
        # 先单次 ping(不重试)快速判死活,通了再花时间验解析,免得连不通时白等重试
        if s == "mikan":
            reachable = http_get(MIKAN_HOST + "/", referer=MIKAN_HOST + "/", browser_ua=True,
                                 timeout=min(timeout, 8), retries=0) is not None
            got = mikan_candidates(probe, timeout=min(timeout, 12), delay=0) if reachable else None
        else:
            reachable = http_get("https://api.bgm.tv/", timeout=min(timeout, 8),
                                 retries=0) is not None
            got = bgm_candidates(probe, timeout=min(timeout, 12), limit=1) if reachable else None

        dt = time.time() - t0
        if got:
            log("  %-6s 可用          (%.1fs)" % (s, dt))
            alive.append(s)
        elif reachable:
            log("  %-6s 连得通但没解析出结果 —— 站点结构可能已变,本次跳过 (%.1fs)" % (s, dt))
        else:
            log("  %-6s 连不通,本次跳过 (%.1fs)" % (s, dt))
    return alive


# --------------------------------------------------------------------------- 主流程

def load_list(path):
    """读番名列表。兼容 wp-cli 的 ID/post_title 与手写的 id/title。"""
    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
    except FileNotFoundError:
        raise SystemExit(
            "错误:找不到 %s\n"
            "  番名列表从服务器导出:\n"
            "    wp post list --post_type=anime --post_status=publish \\\n"
            "       --format=json --fields=ID,post_title > anime-list.json\n"
            "  手写也行,最简形式:[{\"title\":\"番名\"}]" % path)
    except UnicodeDecodeError:
        raise SystemExit("错误:%s 不是 UTF-8 编码的文本" % path)
    except json.JSONDecodeError as exc:
        raise SystemExit("错误:%s 不是合法 JSON —— %s" % (path, exc))

    if not isinstance(data, list):
        raise SystemExit("错误:%s 顶层必须是数组,形如 [{\"title\":\"番名\"}]" % path)

    items = []
    for row in data:
        if not isinstance(row, dict):
            continue
        title = (row.get("post_title") or row.get("title") or "").strip()
        if not title:
            continue
        pid = row.get("ID") or row.get("id") or row.get("post_id") or ""
        items.append({"id": str(pid), "title": title})
    return items


def main():
    ap = argparse.ArgumentParser(
        description="本机抓取番剧封面(mikan 优先,bgm 兜底,带比例闸)",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="产物: <出力目录>/*.jpg 与 manifest.json,scp 上 VPS 后用 "
               "`wp bangumi import-covers <目录>` 入库。",
    )
    ap.add_argument("list_json", help="番名列表 JSON(wp post list 导出的即可)")
    ap.add_argument("-o", "--out", default="covers", help="输出目录(默认 covers)")
    ap.add_argument("--source", choices=["both", "mikan", "bgm"], default="both",
                    help="图源(默认 both:mikan 优先、bgm 兜底)")
    ap.add_argument("--overwrite", action="store_true", help="已抓过的也重抓")
    ap.add_argument("--limit", type=int, default=0, help="只处理前 N 部(调试用)")
    ap.add_argument("--delay", type=float, default=0.8, help="请求间隔秒数(默认 0.8,别调太小)")
    ap.add_argument("--timeout", type=float, default=25, help="单次请求超时秒数(默认 25)")
    ap.add_argument("--ratio-min", type=float, default=0.5, dest="ratio_min",
                    help="比例闸下限 宽/高(默认 0.5)")
    ap.add_argument("--ratio-max", type=float, default=0.8, dest="ratio_max",
                    help="比例闸上限 宽/高(默认 0.8,横版图与近正方形的碟盒封会被拦下)")
    ap.add_argument("--min-width", type=int, default=200, dest="min_width",
                    help="最小宽度像素(默认 200,挡掉 150px 的缩略图兜底)")
    ap.add_argument("--min-score", type=float, default=0.55, dest="min_score",
                    help="标题相似度门槛(默认 0.55)")
    ap.add_argument("--min-score-single", type=float, default=0.0, dest="min_score_single",
                    help="搜索只有一条结果时的宽松门槛(默认 0 即一律收下并标记,"
                         "用于译名完全不同的番,如「金色时光」在 mikan 叫「青春纪行」)")
    ap.add_argument("--max-candidates", type=int, default=5, dest="max_candidates",
                    help="每个图源最多试几个候选(默认 5)")
    ap.add_argument("--proxy", default="", help="代理地址,如 http://127.0.0.1:7890")
    ap.add_argument("--no-preflight", action="store_true", dest="no_preflight",
                    help="跳过开跑前的图源探测(默认会探,免得图源不通时空转几小时)")
    ap.add_argument("-q", "--quiet", action="store_true", help="只打结果行")
    args = ap.parse_args()

    # 输出重定向到文件时 Python 默认块缓冲,进度会一直看不见 —— 这活儿要跑好几分钟,
    # 用户多半会 `> log 2>&1` 然后 tail,所以强制行缓冲。
    try:
        sys.stdout.reconfigure(line_buffering=True)
    except AttributeError:      # Python < 3.7
        pass

    if args.delay < 0.3:
        print("⚠ --delay %.2fs 太激进。mikan 与 bgm 都是公益站点,请求间隔已抬回 0.3s。\n"
              "  真要更快请自建缓存,别去压他们的服务器。" % args.delay, file=sys.stderr)
        args.delay = 0.3

    if args.proxy:
        opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({"http": args.proxy, "https": args.proxy}))
        urllib.request.install_opener(opener)

    def log(msg):
        if not args.quiet:
            print(msg)

    items = load_list(args.list_json)
    if args.limit > 0:
        items = items[:args.limit]
    if not items:
        raise SystemExit("番名列表是空的,没什么可抓")

    os.makedirs(args.out, exist_ok=True)
    manifest_path = os.path.join(args.out, "manifest.json")

    # 续跑:已有 manifest 就沿用,--overwrite 才重头来
    manifest = {}
    if os.path.exists(manifest_path) and not args.overwrite:
        try:
            with open(manifest_path, "r", encoding="utf-8") as fh:
                for row in json.load(fh).get("covers", []):
                    manifest[row["key"]] = row
        except (ValueError, KeyError, OSError):
            manifest = {}

    order = {"both": ["mikan", "bgm"], "mikan": ["mikan"], "bgm": ["bgm"]}[args.source]

    if not args.no_preflight:
        print("探测图源…")
        order = preflight(order, args.timeout, print)
        if not order:
            raise SystemExit(
                "\n所有图源都用不了,已中止(没必要空转几小时)。\n"
                "  · 受限网络下 mikanani.me / api.bgm.tv 常需代理:\n"
                "      --proxy http://127.0.0.1:7890   或设 HTTPS_PROXY 环境变量\n"
                "  · 确认代理可用后重跑即可;已抓到的图会自动跳过。\n"
                "  · 确实想跳过探测就加 --no-preflight。")
        print()

    stats = {"ok": 0, "skip": 0, "miss": 0}
    results = []

    print("共 %d 部,图源顺序:%s,比例闸 %.2f–%.2f\n" %
          (len(items), " → ".join(order), args.ratio_min, args.ratio_max))

    for idx, item in enumerate(items, 1):
        title, pid = item["title"], item["id"]
        key = pid or title
        head = "[%d/%d] %s" % (idx, len(items), title)

        # 续跑:只跳过「上次真抓到且文件还在」的;上次 MISS 的重试一遍
        # (file 在 MISS 行里是 null,不能用 get(...,"") 兜,得显式 or)
        old = manifest.get(key)
        old_file = (old or {}).get("file") or ""
        if old_file and not args.overwrite and os.path.exists(os.path.join(args.out, old_file)):
            log("%s\n      已有,跳过" % head)
            results.append(old)
            stats["skip"] += 1
            continue

        log(head)
        # 标题对得上的优先;只有当所有图源都拿不出确信结果时,才退而用标记过的低置信图。
        # 否则 mikan 的一条低置信单结果会抢在 bgm 的精确命中前面。
        hit, shaky_hit = None, None
        for source in order:
            cands = (mikan_candidates(title, args.timeout, args.delay, log) if source == "mikan"
                     else bgm_candidates(title, args.timeout, args.max_candidates))
            time.sleep(args.delay)
            if not cands:
                log("      %s 无结果" % source)
                continue
            found = try_candidates(cands, source, args, log)
            if not found:
                continue
            if not found["low_confidence"]:
                hit = found
                break
            if shaky_hit is None:
                shaky_hit = found
        hit = hit or shaky_hit

        if not hit:
            print("      MISS —— 前台会显示「待配封面」占位卡")
            stats["miss"] += 1
            results.append({"key": key, "post_id": pid, "title": title, "file": None,
                            "source": None, "reason": "no acceptable cover"})
            continue

        # 文件名用 post id,避开番名里的标点与编码问题;没有 id 时退回序号
        stem = ("bgm-%s" % pid) if pid else ("bgm-idx%03d" % idx)
        fname = "%s.%s" % (stem, hit["ext"])
        with open(os.path.join(args.out, fname), "wb") as fh:
            fh.write(hit["data"])

        row = {"key": key, "post_id": pid, "title": title, "file": fname,
               "source": hit["source"], "matched_name": hit["matched_name"],
               "score": hit["score"], "width": hit["width"], "height": hit["height"],
               "ratio": hit["ratio"], "source_id": hit["source_id"], "url": hit["url"],
               "low_confidence": hit["low_confidence"]}
        results.append(row)
        stats["ok"] += 1
        print("      OK%s %s ← %s「%s」%dx%d r=%.3f" %
              (" ⚠" if hit["low_confidence"] else "  ", fname, hit["source"],
               hit["matched_name"], hit["width"], hit["height"], hit["ratio"]))

    with open(manifest_path, "w", encoding="utf-8") as fh:
        json.dump({"generated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
                   "ratio_gate": [args.ratio_min, args.ratio_max],
                   "covers": results}, fh, ensure_ascii=False, indent=2)

    print("\n完成: ok=%d skip=%d miss=%d" % (stats["ok"], stats["skip"], stats["miss"]))
    print("产物: %s" % os.path.abspath(args.out))
    by_source = {}
    for r in results:
        if r.get("source"):
            by_source[r["source"]] = by_source.get(r["source"], 0) + 1
    if by_source:
        print("图源分布: %s" % ", ".join("%s=%d" % kv for kv in sorted(by_source.items())))
    print("\n下一步:\n    scp -r %s vps:/tmp/covers\n    ssh vps 'wp bangumi import-covers /tmp/covers'"
          % args.out)

    # 标题对不上但收下的,单独列出来让人过一眼
    shaky = [r for r in results if r.get("low_confidence")]
    if shaky:
        print("\n⚠ 以下 %d 部标题没对上(多半是译名不同),图已收但建议核对:" % len(shaky))
        for r in shaky:
            print("    - %s  ←  %s「%s」(%s)" %
                  (r["title"], r["source"], r["matched_name"], r["file"]))

    # miss 清单单独列一遍,方便手动补图
    missed = [r["title"] for r in results if not r.get("file")]
    if missed:
        print("\n没抓到封面的 %d 部(后台手动传特色图即可):" % len(missed))
        for t in missed:
            print("    - %s" % t)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n已中断。已抓到的图和 manifest 都还在,重跑会自动续上。", file=sys.stderr)
        sys.exit(130)
