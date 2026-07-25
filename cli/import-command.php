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
     * 为没有封面的番从 Bangumi 抓取并设为特色图(需服务器能访问 api.bgm.tv)。
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
            $img = BGM_Fetcher::search_cover($p->post_title);
            if (!$img) { $miss++; WP_CLI::log("MISS: {$p->post_title}"); usleep(600000); continue; }
            $res = BGM_Fetcher::sideload($img, $p->ID);
            if (is_wp_error($res)) { $miss++; WP_CLI::warning("sideload 失败: {$p->post_title} — " . $res->get_error_message()); usleep(600000); continue; }
            if (BGM_Fetcher::$last_id) { update_post_meta($p->ID, '_bgm_url', 'https://bgm.tv/subject/' . BGM_Fetcher::$last_id); }
            $ok++; WP_CLI::log("OK: {$p->post_title}");
            usleep(600000); // 0.6s 限流
        }
        WP_CLI::success("covers ok=$ok miss=$miss skip=$skip");
    }
}

WP_CLI::add_command('bangumi', 'BGM_CLI_Command');
