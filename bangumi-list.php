<?php
/**
 * Plugin Name:       Bangumi List 看番清单
 * Plugin URI:        https://github.com/ThornsW/bangumi-list
 * Description:       一个独立风格的「看番清单」页面:注册「动漫」内容类型,后台像写文章一样记录看过的番(封面/评分/评语),前台用终端风海报网格展示。短代码 [anime_list]。
 * Version:           0.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ThornsW
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bangumi-list
 */
if (!defined('ABSPATH')) exit;

define('BGM_VERSION', '0.2.0');
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
    ?>
    <p><label>评分(自由文本,如 <code>9.7/10</code>)<br>
        <input type="text" name="bgm_rating_text" value="<?php echo esc_attr($rating); ?>" style="width:100%"></label></p>
    <p><label>评语<br>
        <textarea name="bgm_review" rows="4" style="width:100%"><?php echo esc_textarea($review); ?></textarea></label></p>
    <p><label>观看方式
        <select name="bgm_watch_mode">
            <option value="常规" <?php selected($mode, '常规'); ?>>常规</option>
            <option value="速看" <?php selected($mode, '速看'); ?>>速看</option>
        </select></label>
        &nbsp;<span style="color:#888">（后台保留,前台不显示)</span></p>
    <p><label>Bangumi 链接(选填)<br>
        <input type="url" name="bgm_url" value="<?php echo esc_attr($url); ?>" style="width:100%"></label></p>
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
