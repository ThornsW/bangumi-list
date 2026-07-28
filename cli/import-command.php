<?php
if (!defined('ABSPATH')) exit;

/**
 * WP-CLI: wp bangumi ...
 */
class BGM_CLI_Command {

    /**
     * 从 JSON 批量导入番剧。
     *
     * JSON 格式: [{"title":"番名","rating":"9.7/10","review":"评语","watch_mode":"常规"}, ...]
     *
     * ## OPTIONS
     * <file>
     * : JSON 文件路径(需运行 wp-cli 的用户可读)。
     *
     * ## EXAMPLES
     *     wp bangumi import /path/to/anime.json
     *
     * @when after_wp_load
     */
    public function import($args, $assoc) {
        list($file) = $args;
        if (!file_exists($file)) { WP_CLI::error("找不到文件: $file"); }
        $items = json_decode(file_get_contents($file), true);
        if (!is_array($items)) { WP_CLI::error("JSON 解析失败"); }

        $created = 0; $dup = 0; $skipped = 0;
        foreach ($items as $it) {
            $title = trim((string) ($it['title'] ?? ''));
            if ($title === '') { $skipped++; continue; }
            if (bgm_should_skip($it['rating'] ?? '', $it['review'] ?? '')) { $skipped++; continue; }

            $existing = get_posts(['post_type' => 'anime', 'title' => $title, 'posts_per_page' => 1, 'fields' => 'ids']);
            if (!empty($existing)) { $dup++; continue; }

            $id = wp_insert_post([
                'post_type'   => 'anime',
                'post_title'  => $title,
                'post_status' => 'publish',
            ], true);
            if (is_wp_error($id)) { WP_CLI::warning("插入失败: $title"); continue; }

            $rating = (string) ($it['rating'] ?? '');
            update_post_meta($id, '_bgm_rating_text', $rating);
            $val = bgm_parse_rating($rating);
            update_post_meta($id, '_bgm_rating_value', $val === null ? '' : $val);
            update_post_meta($id, '_bgm_review', (string) ($it['review'] ?? ''));
            update_post_meta($id, '_bgm_watch_mode', ($it['watch_mode'] ?? '') ?: '常规');
            $created++;
        }
        WP_CLI::success("created=$created dup=$dup skipped=$skipped");
    }

    /**
     * 把库里的评分文本归一成「只留数字」,去掉手写的 /10 后缀。
     *
     * 前台已改为自动补 /10,旧数据里写成 9.7/10 的照样显示正常(渲染时会归一,
     * 不会出现 9.7/10/10),所以本命令只是让库里的值和现在后台该填的写法保持一致,
     * 属于可选的清理。「不做评价」这类自由文本不动。可反复跑。
     *
     * ## OPTIONS
     * [--dry-run]
     * : 只报告将要改什么,不写数据库。
     *
     * ## EXAMPLES
     *     wp bangumi normalize-ratings --dry-run
     *     wp bangumi normalize-ratings
     *
     * @subcommand normalize-ratings
     * @when after_wp_load
     */
    public function normalize_ratings($args, $assoc) {
        $dry = isset($assoc['dry-run']);
        if ($dry) WP_CLI::log("--dry-run:只报告,不写库\n");

        $ids = get_posts(['post_type' => 'anime', 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids']);

        $changed = 0; $same = 0; $text = 0;
        foreach ($ids as $id) {
            $old = (string) get_post_meta($id, '_bgm_rating_text', true);
            $d   = bgm_rating_display($old);

            if ($d['unit'] === '') { $text++; continue; }   // 空或自由文本,不动
            if ($d['num'] === $old) { $same++; continue; }  // 已经是纯数字

            WP_CLI::log(($dry ? 'WOULD: ' : 'OK: ') . get_the_title($id) . "  [{$old}] → [{$d['num']}]");
            if (!$dry) {
                update_post_meta($id, '_bgm_rating_text', $d['num']);
                // 数值 meta 理论上不变,顺手重算一遍保证两者始终同步
                $val = bgm_parse_rating($d['num']);
                update_post_meta($id, '_bgm_rating_value', $val === null ? '' : $val);
            }
            $changed++;
        }

        WP_CLI::success("normalize-ratings changed=$changed 已是数字=$same 非数字或空=$text");
    }

    /**
     * 为没有封面的番从 Bangumi 抓取并设为特色图(需服务器能访问 api.bgm.tv)。
     *
     * 会逐个试搜索结果,第一个通过比例闸(默认宽/高 0.5–0.9)的才收下,
     * 借此挡掉「特别纪念动画」「联动 PV」这类条目的横版主视觉图。
     * 服务器连不通 mikanani.me 时,建议改用本机的 tools/fetch-covers-local.py
     * 抓好图再 `wp bangumi import-covers` 入库,覆盖率和画质都更好。
     *
     * ## OPTIONS
     * [--limit=<n>]
     * : 只处理前 n 部(默认全部)。
     * [--overwrite]
     * : 已有封面也重新抓取覆盖。
     *
     * ## EXAMPLES
     *     wp bangumi fetch-covers --limit=10
     *     wp bangumi fetch-covers --overwrite
     *
     * @subcommand fetch-covers
     * @when after_wp_load
     */
    public function fetch_covers($args, $assoc) {
        $limit     = isset($assoc['limit']) ? (int) $assoc['limit'] : -1;
        $overwrite = isset($assoc['overwrite']);
        $posts = get_posts(['post_type' => 'anime', 'posts_per_page' => $limit, 'post_status' => 'publish']);

        $ok = 0; $miss = 0; $skip = 0;
        foreach ($posts as $p) {
            if (!$overwrite && has_post_thumbnail($p->ID)) { $skip++; continue; }

            $cands = BGM_Fetcher::search_covers($p->post_title, 5);
            if (empty($cands)) {
                $miss++; WP_CLI::log("MISS: {$p->post_title}(搜索无结果)");
                usleep(600000); continue;
            }

            $done = false;
            foreach ($cands as $c) {
                $res = BGM_Fetcher::sideload($c['img'], $p->ID);
                usleep(600000); // 0.6s 限流
                if (!is_wp_error($res)) {
                    if ($c['id']) update_post_meta($p->ID, '_bgm_url', 'https://bgm.tv/subject/' . $c['id']);
                    $ok++; WP_CLI::log("OK: {$p->post_title} ← 「{$c['name']}」");
                    $done = true; break;
                }
                WP_CLI::log("     拒收「{$c['name']}」: " . $res->get_error_message());
            }
            if (!$done) { $miss++; WP_CLI::warning("无合格封面: {$p->post_title}"); }
        }
        WP_CLI::success("covers ok=$ok miss=$miss skip=$skip");
    }

    /**
     * 从本地目录导入封面并设为特色图(完全不走网络)。
     *
     * 配合本机的 tools/fetch-covers-local.py 使用 —— 那个脚本在能访问 mikanani.me
     * 的机器上抓图,产出 covers/ 与 manifest.json,scp 上来后由本命令入库。
     * 源文件只读不删,可以反复跑。
     *
     * ## OPTIONS
     * <dir>
     * : 封面目录(内含图片与 manifest.json)。
     * [--manifest=<file>]
     * : manifest.json 路径,默认 <dir>/manifest.json。
     * [--overwrite]
     * : 已有特色图的番也覆盖(默认跳过)。
     * [--dry-run]
     * : 只报告将要做什么,不写数据库。
     *
     * ## EXAMPLES
     *     wp bangumi import-covers /tmp/covers
     *     wp bangumi import-covers /tmp/covers --overwrite
     *     wp bangumi import-covers /tmp/covers --dry-run
     *
     * @subcommand import-covers
     * @when after_wp_load
     */
    public function import_covers($args, $assoc) {
        list($dir) = $args;
        $dir = rtrim($dir, '/');
        $real_dir = realpath($dir);
        if (!$real_dir || !is_dir($real_dir)) { WP_CLI::error("不是目录: $dir"); }

        $manifest_file = $assoc['manifest'] ?? ($real_dir . '/manifest.json');
        if (!is_readable($manifest_file)) {
            WP_CLI::error("读不到 manifest: $manifest_file(先在本机跑 tools/fetch-covers-local.py)");
        }
        $manifest = json_decode(file_get_contents($manifest_file), true);
        if (!is_array($manifest) || empty($manifest['covers'])) {
            WP_CLI::error("manifest 格式不对,缺 covers 数组: $manifest_file");
        }

        $overwrite = isset($assoc['overwrite']);
        $dry       = isset($assoc['dry-run']);
        if ($dry) WP_CLI::log("--dry-run:只报告,不写库\n");

        $ok = 0; $skip = 0; $fail = 0; $nofile = 0;
        foreach ($manifest['covers'] as $row) {
            $title = (string) ($row['title'] ?? '');
            $file  = (string) ($row['file'] ?? '');

            if ($file === '') { $nofile++; WP_CLI::log("SKIP: {$title}(本机没抓到封面)"); continue; }

            // 只认文件名,不让 manifest 里的路径逃出 <dir>
            $path = $real_dir . '/' . basename($file);
            if (!is_readable($path)) { $fail++; WP_CLI::warning("找不到图片: " . basename($file) . "({$title})"); continue; }

            $post_id = $this->resolve_anime_id($row['post_id'] ?? '', $title);
            if (!$post_id) { $fail++; WP_CLI::warning("对不上番: {$title}"); continue; }

            if (!$overwrite && has_post_thumbnail($post_id)) {
                $skip++; WP_CLI::log("SKIP: {$title}(已有特色图,加 --overwrite 才覆盖)"); continue;
            }

            if ($dry) {
                $ok++; WP_CLI::log("WOULD: {$title} ← " . basename($file)
                    . " [" . ($row['source'] ?? '?') . " " . ($row['width'] ?? '?') . "x" . ($row['height'] ?? '?') . "]");
                continue;
            }

            $att = BGM_Fetcher::sideload_path($path, $post_id, basename($file));
            if (is_wp_error($att)) { $fail++; WP_CLI::warning("入库失败 {$title}: " . $att->get_error_message()); continue; }

            if (!empty($row['source_id']) && ($row['source'] ?? '') === 'bgm') {
                update_post_meta($post_id, '_bgm_url', 'https://bgm.tv/subject/' . (int) $row['source_id']);
            }
            $ok++; WP_CLI::log("OK: {$title} ← " . basename($file)
                . " [" . ($row['source'] ?? '?') . " r=" . ($row['ratio'] ?? '?') . "]");
        }

        WP_CLI::success("import-covers ok=$ok skip=$skip fail=$fail 无图=$nofile");
    }

    /** manifest 里的 post_id 优先;对不上再按番名找 */
    protected function resolve_anime_id($post_id, $title) {
        $post_id = (int) $post_id;
        if ($post_id > 0) {
            $p = get_post($post_id);
            if ($p && $p->post_type === 'anime') return $post_id;
        }
        if ($title === '') return 0;
        $found = get_posts([
            'post_type'      => 'anime',
            'title'          => $title,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
        return empty($found) ? 0 : (int) $found[0];
    }
}

WP_CLI::add_command('bangumi', 'BGM_CLI_Command');
