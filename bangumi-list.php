<?php
/**
 * Plugin Name:       Bangumi List 看番清单
 * Plugin URI:        https://github.com/ThornsW/bangumi-list
 * Description:       一个独立风格的「看番清单」页面:注册「动漫」内容类型,后台像写文章一样记录看过的番(封面/评分/评语),前台用终端风海报网格展示。短代码 [anime_list]。
 * Version:           0.5.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ThornsW
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * 说明:本插件前台文案为硬编码简体中文,未做 i18n,故不声明 Text Domain。
 * 需要其它语言请直接改 includes/render.php 与 assets/anime.js 中的字符串。
 */
if (!defined('ABSPATH')) exit;

define('BGM_VERSION', '0.5.2');
define('BGM_DIR', plugin_dir_path(__FILE__));
define('BGM_URL', plugin_dir_url(__FILE__));

require_once BGM_DIR . 'includes/helpers.php';
require_once BGM_DIR . 'includes/class-bgm-fetcher.php';
require_once BGM_DIR . 'includes/render.php';
if (defined('WP_CLI') && WP_CLI) {
    require_once BGM_DIR . 'cli/import-command.php';
}

/* 1. 注册 CPT:后台可管理,前台无独立永久链接(全部经短代码渲染) */
add_action('init', function () {
    register_post_type('anime', [
        'labels' => [
            'name'          => '看番',
            'singular_name' => '番',
            'add_new'       => '添加番',
            'add_new_item'  => '添加一部番',
            'edit_item'     => '编辑番',
            'menu_name'     => '看番',
        ],
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-format-video',
        'supports'           => ['title', 'thumbnail'],
        'has_archive'        => false,
        'rewrite'            => false,
        'publicly_queryable' => false,
    ]);
    // 特色图支持:合并进主题已有配置,不整体覆盖。
    // 主题若写的是 add_theme_support('post-thumbnails', ['post']),无参调用会把它冲成"全站所有类型",
    // 别的内容类型就会莫名多出「特色图」框。主题在 after_setup_theme 注册,早于 init,故此处读到的是最终值。
    $bgm_thumb = get_theme_support('post-thumbnails');
    if ($bgm_thumb !== true) {
        $bgm_types = (is_array($bgm_thumb) && isset($bgm_thumb[0]) && is_array($bgm_thumb[0])) ? $bgm_thumb[0] : [];
        add_theme_support('post-thumbnails', array_values(array_unique(array_merge($bgm_types, ['anime']))));
    }

    // 资源注册(仅在短代码渲染时 enqueue)
    wp_register_style('bgm-anime', BGM_URL . 'assets/anime.css', [], BGM_VERSION);
    wp_register_script('bgm-anime', BGM_URL . 'assets/anime.js', [], BGM_VERSION, true);
});

/* 2. Meta box */
add_action('add_meta_boxes', function () {
    add_meta_box('bgm_fields', '看番信息', 'bgm_render_metabox', 'anime', 'normal', 'high');
});

function bgm_render_metabox($post) {
    wp_nonce_field('bgm_save', 'bgm_nonce');
    $rating = get_post_meta($post->ID, '_bgm_rating_text', true);
    $review = get_post_meta($post->ID, '_bgm_review', true);
    $mode   = get_post_meta($post->ID, '_bgm_watch_mode', true) ?: '常规';
    $url    = get_post_meta($post->ID, '_bgm_url', true);
    $tier   = bgm_rating_tier(bgm_parse_rating($rating));
    ?>
    <p><label>评分(只写数字,如 <code>9.7</code>;前台按数值折算档位并显示 <code>x.x</code>。也可填「不做评价」这类文本)<br>
        <input type="text" name="bgm_rating_text" id="bgm-rating-input" value="<?php echo esc_attr($rating); ?>" placeholder="9.7" style="width:100%"></label>
        <span style="color:#888">前台档位:</span>
        <b id="bgm-tier-preview"><?php echo esc_html($tier['label']); ?></b></p>
    <p><label>评语<br>
        <textarea name="bgm_review" rows="4" style="width:100%"><?php echo esc_textarea($review); ?></textarea></label></p>
    <p><label>观看方式
        <select name="bgm_watch_mode">
            <option value="常规" <?php selected($mode, '常规'); ?>>常规</option>
            <option value="速看" <?php selected($mode, '速看'); ?>>速看</option>
            <?php
            // 数据中存在下拉之外的历史值(如「常规+二刷+补了漫画」)。
            // 不补成选项的话,打开这类条目保存后会被静默重置为「常规」。
            if ($mode !== '' && $mode !== '常规' && $mode !== '速看') : ?>
            <option value="<?php echo esc_attr($mode); ?>" selected><?php echo esc_html($mode); ?></option>
            <?php endif; ?>
        </select></label>
        &nbsp;<span style="color:#888">(卡片进度条那行显示「速看/常规」,完整文字显示在悬浮的评语层里)</span></p>
    <p><label>Bangumi 链接(选填)<br>
        <input type="url" name="bgm_url" value="<?php echo esc_attr($url); ?>" placeholder="https://bgm.tv/subject/12345" style="width:100%"></label>
        <span style="color:#888">填了前台卡片就可点击跳转;留空则不可点。</span></p>
    <script>
    /* 档位预览。阈值由 bgm_rating_tiers() 注入,不在此写死,避免前后台各存一套边界。 */
    (function () {
        var tiers = <?php echo wp_json_encode(bgm_rating_tiers()); ?>;
        var input = document.getElementById('bgm-rating-input');
        var out   = document.getElementById('bgm-tier-preview');
        if (!input || !out) return;
        input.addEventListener('input', function () {
            var s = input.value.trim();
            if (!/^\d+(\.\d+)?$/.test(s) || parseFloat(s) > 10) { out.textContent = '—'; return; }
            var v = parseFloat(s);
            for (var i = 0; i < tiers.length; i++) {
                if (tiers[i].min === null || v >= tiers[i].min) { out.textContent = tiers[i].label; return; }
            }
            out.textContent = '—';
        });
    })();
    </script>
    <?php
}

add_action('save_post_anime', function ($post_id) {
    if (!isset($_POST['bgm_nonce']) || !wp_verify_nonce($_POST['bgm_nonce'], 'bgm_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $rating = isset($_POST['bgm_rating_text']) ? sanitize_text_field(wp_unslash($_POST['bgm_rating_text'])) : '';
    update_post_meta($post_id, '_bgm_rating_text', $rating);
    $val = bgm_parse_rating($rating);
    update_post_meta($post_id, '_bgm_rating_value', $val === null ? '' : $val);
    update_post_meta($post_id, '_bgm_review', isset($_POST['bgm_review']) ? sanitize_textarea_field(wp_unslash($_POST['bgm_review'])) : '');
    update_post_meta($post_id, '_bgm_watch_mode', isset($_POST['bgm_watch_mode']) ? sanitize_text_field(wp_unslash($_POST['bgm_watch_mode'])) : '常规');
    update_post_meta($post_id, '_bgm_url', isset($_POST['bgm_url']) ? esc_url_raw(wp_unslash($_POST['bgm_url'])) : '');
});

/* 3. 短代码(渲染实现见 includes/render.php) */
add_shortcode('anime_list', 'bgm_render_list');
