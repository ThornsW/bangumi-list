<?php
if (!defined('ABSPATH')) exit;

/**
 * 短代码 [anime_list] 渲染。可配属性:
 *   title    页面大标题(默认「看番清单」)
 *   subtitle 副标题一行(默认「# 记录看过的番、我的评分与短评」)
 *   handle   终端标题栏用户名(默认「user@blog」)
 *   path     终端路径标签(默认「~/anime」)
 *   accent   主题强调色 hex(默认 #7ee787)
 * 例: [anime_list title="我的番剧" handle="me@site" accent="#22d3ee"]
 */
function bgm_render_list($atts = []) {
    $a = shortcode_atts([
        'title'    => '看番清单',
        'subtitle' => '# 记录看过的番、我的评分与短评',
        'handle'   => 'user@blog',
        'path'     => '~/anime',
        'accent'   => '#7ee787',
    ], $atts, 'anime_list');

    wp_enqueue_style('bgm-anime');
    wp_enqueue_script('bgm-anime');

    // 不在 SQL 里按 meta 排序:WP_Query 的 'meta_key' 会对 postmeta 做 INNER JOIN,
    // 缺这个 key 的 anime(非本插件路径创建的)会在前台静默消失且不报错。
    $q = new WP_Query([
        'post_type'           => 'anime',
        'posts_per_page'      => -1,
        'post_status'         => 'publish',
        'orderby'             => 'date',
        'order'               => 'DESC',
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
    ]);

    // 评分降序改在 PHP 里排(WP_Query 已预热 meta 缓存,不产生额外查询)。
    // 无评分的沉底;同分按发布时间新→旧,避免 PHP 7.4 的 usort 不稳定导致顺序漂移。
    $posts = $q->posts;
    usort($posts, function ($x, $y) {
        $vx = get_post_meta($x->ID, '_bgm_rating_value', true);
        $vy = get_post_meta($y->ID, '_bgm_rating_value', true);
        $nx = ($vx === '' || $vx === null) ? -1.0 : (float) $vx;
        $ny = ($vy === '' || $vy === null) ? -1.0 : (float) $vy;
        if ($nx !== $ny) return ($ny > $nx) ? 1 : -1;
        return strcmp((string) $y->post_date_gmt, (string) $x->post_date_gmt);
    });

    $count = count($posts);

    // 「最近更新」取全部番里最新的修改时间,而不是渲染当天的日期
    $updated_ts = 0;
    foreach ($posts as $p) {
        $t = (int) get_post_modified_time('U', true, $p);
        if ($t > $updated_ts) $updated_ts = $t;
    }
    $updated = $updated_ts ? wp_date('Y/m/d', $updated_ts) : wp_date('Y/m/d');

    $accent = sanitize_hex_color($a['accent']) ?: '#7ee787';

    ob_start(); ?>
    <div class="bgm-app" style="--acc:<?php echo esc_attr($accent); ?>">
      <div class="bgm-term">
        <div class="bgm-titlebar">
          <span class="bgm-tl" style="background:#ff5f56"></span>
          <span class="bgm-tl" style="background:#ffbd2e"></span>
          <span class="bgm-tl" style="background:#27c93f"></span>
          <span class="bgm-path"><?php echo esc_html($a['handle']); ?>: <b><?php echo esc_html($a['path']); ?></b></span>
          <span class="bgm-shell">zsh</span>
        </div>
        <div class="bgm-body">
          <div class="bgm-hero">
            <div class="bgm-cmd"><span class="bgm-dollar">$</span> cat <?php echo esc_html($a['title']); ?>.md</div>
            <h1 class="bgm-h1"><?php echo esc_html($a['title']); ?><span class="bgm-cur">▋</span></h1>
            <div class="bgm-desc"><?php echo esc_html($a['subtitle']); ?></div>
            <div class="bgm-stats">
              <span>已收录 <b><?php echo (int) $count; ?></b> 部</span>
              <span class="bgm-muted">最近更新 <?php echo esc_html($updated); ?></span>
            </div>
          </div>

          <div class="bgm-toolbar">
            <span class="bgm-count">// <?php echo (int) $count; ?> 部</span>
            <span class="bgm-grow"></span>
            <?php // 排序文案只此一份,anime.js 切换时读 data 属性
            $sort_score = '评分 ↓'; $sort_date = '最近添加 ↓'; ?>
            <button type="button" class="bgm-sort"
                    data-label-score="<?php echo esc_attr($sort_score); ?>"
                    data-label-date="<?php echo esc_attr($sort_date); ?>">sort: <b><?php
              echo esc_html($sort_score); ?></b></button>
            <span class="bgm-search"><span class="bgm-g">grep</span>
              <input type="text" class="bgm-search-input" placeholder="搜番名…" aria-label="搜索番名"></span>
          </div>

          <div class="bgm-grid">
            <?php foreach ($posts as $p) :
                $id     = $p->ID;
                $title  = get_the_title($p);
                $rv     = get_post_meta($id, '_bgm_rating_value', true);
                $review = get_post_meta($id, '_bgm_review', true);
                $mode   = get_post_meta($id, '_bgm_watch_mode', true);
                $url    = get_post_meta($id, '_bgm_url', true);
                $score  = ($rv === '' || $rv === null) ? null : (float) $rv;
                $pct    = $score === null ? 0 : max(0, min(100, $score * 10));
                $tier   = bgm_rating_tier($score);
                $ts     = get_post_time('U', true, $p);
                // 搜索范围含评语:悬浮层内容同属清单数据
                $searchKey = bgm_strtolower($title . ' ' . (string) $review);
                // 有链接的渲染成 <a>,直接获得键盘可达性与语义;无链接的退回 div 并补 tabindex,
                // 否则这些卡片的评语对键盘用户不可达。
                $tag   = $url ? 'a' : 'div';
                $attrs = $url
                    ? ' href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer"'
                    : ' tabindex="0"';
            ?>
            <<?php echo $tag . $attrs; ?> class="bgm-card" data-title="<?php echo esc_attr($searchKey); ?>"
                 data-score="<?php echo $score === null ? '' : esc_attr($score); ?>"
                 data-date="<?php echo esc_attr($ts); ?>"
                 title="<?php echo esc_attr($title); ?>">
              <div class="bgm-poster<?php echo has_post_thumbnail($id) ? '' : ' bgm-ph'; ?>">
                <?php if (has_post_thumbnail($id)) : ?>
                  <?php echo get_the_post_thumbnail($id, 'medium', ['class' => 'bgm-cover', 'loading' => 'lazy', 'alt' => esc_attr($title)]); ?>
                <?php else : ?>
                  <span class="bgm-phdash"></span>
                  <div class="bgm-phinner"><div class="bgm-phttl"><?php echo esc_html(bgm_normalize_title($title)); ?></div>
                    <div class="bgm-phtag">· 待配封面 ·</div></div>
                <?php endif; ?>
                <span class="bgm-badge bgm-t-<?php echo esc_attr($tier['key']); ?>"><?php
                  echo esc_html($tier['label']); ?></span>
                <span class="bgm-ptitle"><?php echo esc_html($title); ?></span>
              </div>
              <div class="bgm-meter">
                <span class="bgm-track"><span class="bgm-fill" style="width:<?php echo esc_attr($pct); ?>%"></span></span>
                <?php $ms = bgm_watch_mode_short($mode); ?>
                <span class="bgm-mode-i bgm-mode-<?php echo esc_attr($ms['key']); ?>"><?php
                  echo esc_html($ms['label']); ?></span>
                <span class="bgm-score"><?php echo esc_html(bgm_rating_number($score)); ?></span>
              </div>
              <div class="bgm-review"><span class="bgm-rt"><?php echo esc_html($title); ?></span>
                <?php if (trim((string) $review) !== '') : ?>
                <span class="bgm-rc"><?php echo esc_html($review); ?></span>
                <?php endif; ?>
                <span class="bgm-rf">
                  <?php // 观看方式的完整原文,补全行内简写掉的信息
                  if (trim((string) $mode) !== '') : ?>
                  <em class="bgm-rmode"><?php echo esc_html($mode); ?></em>
                  <?php endif; ?>
                  <?php // 触屏下卡片自身的跳转被 JS 拦截用于展开评语,故提供显式出口。
                        // 用 span 而非 a:外层已是 <a>,嵌套链接为非法 HTML;
                        // JS 对此元素不拦截,点击沿用外层 href。
                  if ($url) : ?>
                  <span class="bgm-go">bgm ↗</span>
                  <?php endif; ?>
                </span>
              </div>
            </<?php echo $tag; ?>>
            <?php endforeach; ?>
          </div>

          <div class="bgm-footline"><span class="bgm-dollar">$</span> <span class="bgm-cur">▋</span></div>
        </div>
      </div>
    </div>
    <?php
    // 全程未调用 the_post(),没有污染全局 $post,故无需 wp_reset_postdata()
    return ob_get_clean();
}
