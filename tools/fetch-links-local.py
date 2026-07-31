#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""本机批量补 Bangumi 条目链接(_bgm_url)。

fetch-covers-local.py 的姊妹工具:那个抓图,本工具抓 subject 链接。
番名匹配、查询降级、相似度打分复用该模块,避免两套规则漂移。

产物 links.json 形如:
    [{"post_id":"1497","title":"《进击的巨人》全季","subject_id":"12","score":0.86,
      "matched_name":"进击的巨人","url":"https://bgm.tv/subject/12","low_confidence":false}]
scp 上 VPS 后用 wp eval 入库。

⚠️ 链接错配肉眼不可见(不同于封面),入库前必须核对结尾的两份报告:
   - 重复 subject_id:季数串台的典型症状,title_score() 分不清季数
   - 低分:译名不同所致的错配,重复 id 检查覆盖不到
   两份报告只覆盖本批次;库中已有链接需另行并入比对。
"""

import argparse
import importlib.util
import json
import os
import sys
import time
import urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
BGM_SEARCH = "https://api.bgm.tv/search/subject/{kw}?type=2&responseGroup=small&max_results={n}"


def load_sibling():
    """把 fetch-covers-local.py 当模块加载。

    文件名含连字符不是合法标识符,普通 import 不可用,故走 importlib。
    复用其 http_get / title_score / query_variants,勿重复实现。
    """
    path = os.path.join(HERE, "fetch-covers-local.py")
    if not os.path.exists(path):
        raise SystemExit("错误:找不到同目录的 fetch-covers-local.py,本工具依赖它的匹配逻辑")
    spec = importlib.util.spec_from_file_location("bgm_cover_tool", path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def bgm_subjects(f, title, timeout, limit=10):
    """搜 bgm 返回 [(分数, 番名, subject_id)],按分数降序。

    与 fetch-covers-local.py 的 bgm_candidates() 的差别:那边跳过无配图的条目,
    本函数只需 id,无图条目一并收下。
    """
    url = BGM_SEARCH.format(kw=urllib.parse.quote(title), n=limit)
    body = f.http_get(url, timeout=timeout)
    if not body:
        return []
    try:
        data = json.loads(body.decode("utf-8", "replace"))
    except ValueError:
        return []
    out = []
    for item in (data.get("list") or []):
        sid = str(item.get("id") or "")
        if not sid:
            continue
        name = item.get("name_cn") or item.get("name") or ""
        out.append((f.title_score(title, name), name, sid))
    out.sort(key=lambda x: x[0], reverse=True)
    return out


def main():
    ap = argparse.ArgumentParser(description="批量补 Bangumi 条目链接")
    ap.add_argument("list_json", help="番名列表 JSON(ID + post_title)")
    ap.add_argument("-o", "--out", default="links.json", help="产物路径(默认 links.json)")
    ap.add_argument("--delay", type=float, default=0.8, help="请求间隔秒(默认 0.8,不应低于 0.3)")
    ap.add_argument("--timeout", type=float, default=25, help="单次超时秒(默认 25)")
    ap.add_argument("--min-score", type=float, default=0.55, dest="min_score",
                    help="低于此分标记 low_confidence(默认 0.55;仍会入库,只是标记)")
    args = ap.parse_args()

    try:
        sys.stdout.reconfigure(line_buffering=True)
    except AttributeError:
        pass

    f = load_sibling()
    items = f.load_list(args.list_json)
    if not items:
        raise SystemExit("列表是空的")

    print("共 %d 部,间隔 %.1fs\n" % (len(items), args.delay))
    results, missed = [], []

    for i, it in enumerate(items, 1):
        title = it["title"]
        print("[%d/%d] %s" % (i, len(items), title))
        best = None
        # 与抓图同一套降级策略:原串无结果则去书名号、标点换空格、只取最长片段。
        # 打分始终按原番名计算:放宽的是查询,不是采纳条件。
        for variant in f.query_variants(title):
            cands = bgm_subjects(f, variant, args.timeout)
            time.sleep(args.delay)
            if not cands:
                continue
            if variant != title:
                print("      换个搜法:「%s」" % variant)
            best = max(cands, key=lambda x: x[0])
            break

        if not best:
            print("      MISS 搜不到")
            missed.append(it)
            continue

        score, name, sid = best
        low = score < args.min_score
        print("      OK   subject/%s ←「%s」score=%.2f%s"
              % (sid, name, score, "  ⚠低分" if low else ""))
        results.append({
            "post_id": str(it["id"]), "title": title, "subject_id": sid,
            "matched_name": name, "score": round(score, 3),
            "url": "https://bgm.tv/subject/%s" % sid, "low_confidence": low,
        })

    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(results, fh, ensure_ascii=False, indent=2)

    print("\n完成: ok=%d miss=%d  →  %s" % (len(results), len(missed), args.out))

    # 检查一:重复 subject_id。两部番指向同一条目,通常即季数串台。
    by_sid = {}
    for r in results:
        by_sid.setdefault(r["subject_id"], []).append(r)
    dups = {k: v for k, v in by_sid.items() if len(v) > 1}
    if dups:
        print("\n⚠️ 重复 subject_id(疑似季数串台,必须人工核对):")
        for sid, rows in dups.items():
            print("   subject/%s ←「%s」" % (sid, rows[0]["matched_name"]))
            for r in rows:
                print("       post %s  %s" % (r["post_id"], r["title"]))
    else:
        print("\n重复 subject_id 检查:无 ✓")

    # 检查二:低分。译名差异导致的错配,重复 id 检查覆盖不到。
    lows = [r for r in results if r["low_confidence"]]
    if lows:
        print("\n⚠️ 相似度偏低(译名可能不同,建议抽查):")
        for r in lows:
            print("   %.2f  post %s  %s  ←「%s」  https://bgm.tv/subject/%s"
                  % (r["score"], r["post_id"], r["title"], r["matched_name"], r["subject_id"]))
    else:
        print("低分检查:无 ✓")

    if missed:
        print("\n搜不到的 %d 部(保持无链接,卡片不可点):" % len(missed))
        for m in missed:
            print("   post %s  %s" % (m["id"], m["title"]))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n已中断。", file=sys.stderr)
