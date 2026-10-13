<?php
/**
 * Plugin Name: WPCSV Express
 * Description: Fast WP-CLI CSV importer. Create posts from posts.csv, then download featured images.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: WPCSV Express
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpcsv-express
 *
 * Install: copy this file to wp-content/mu-plugins/
 * CSV file: posts.csv in the WordPress root (same folder as wp-config.php)
 *
 *   cd /PathToWPRootFolder/public_html
 *   wp csv-import posts --dry-run
 *   wp csv-import posts
 *   wp csv-import thumbs --dry-run
 *   wp csv-import thumbs
 */

if (!defined('ABSPATH') && php_sapi_name() !== 'cli') {
    exit;
}

if (!defined('WPCX_VERSION')) {
    define('WPCX_VERSION', '1.0.0');
}

$wpcx_argv = isset($GLOBALS['argv']) ? implode(' ', (array) $GLOBALS['argv']) : '';
$wpcx_cli  = (php_sapi_name() === 'cli') && (
    stripos($wpcx_argv, 'csv-import') !== false
    || (isset($argv[0]) && basename($argv[0]) === 'wpcsv-express.php')
);

if ($wpcx_cli) {
    if (!defined('WP_IMPORTING')) {
        define('WP_IMPORTING', true);
    }
    if (!defined('LITESPEED_DISABLE_ALL')) {
        define('LITESPEED_DISABLE_ALL', true);
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
}

if (!defined('ABSPATH') && php_sapi_name() === 'cli') {
    $wpcx_dir  = __DIR__;
    $wpcx_load = '';
    for ($wpcx_i = 0; $wpcx_i < 6; $wpcx_i++) {
        if (is_readable($wpcx_dir . '/wp-load.php')) {
            $wpcx_load = $wpcx_dir . '/wp-load.php';
            break;
        }
        $wpcx_dir = dirname($wpcx_dir);
    }
    if ($wpcx_load === '') {
        fwrite(STDERR, "wp-load.php not found. Place wpcsv-express.php in wp-content/mu-plugins/\n");
        exit(1);
    }
    require_once $wpcx_load;
}

if (!defined('ABSPATH')) {
    exit;
}

if ($wpcx_cli) {
    add_filter('litespeed_purge_post', '__return_false', 1);
    add_filter('litespeed_purge_all', '__return_false', 1);
    add_filter('litespeed_const_disable_all', '__return_true', 1);
    add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 99);
    add_filter('wp_revisions_to_keep', '__return_zero', 1);
}

if (defined('WP_CLI') && WP_CLI && !class_exists('WPCSV_Express_CLI')) {
    WP_CLI::add_command('csv-import', 'WPCSV_Express_CLI');
}

if (!class_exists('WPCSV_Express_CLI')) {
class WPCSV_Express_CLI {
    /**
     * Create posts from posts.csv. Skips rows that already exist.
     *
     * ## OPTIONS
     *
     * [--csv=<path>]
     * : CSV path. Default: WordPress root / posts.csv
     *
     * [--batch=<n>]
     * : Max new posts this run. 0 = all remaining. Default: 0
     *
     * [--pending-category=<name>]
     * : Category that stays pending. Default: Uncut
     *
     * [--dry-run]
     * : Count only. Does not write to the database.
     *
     * ## EXAMPLES
     *
     *     wp csv-import posts --dry-run
     *     wp csv-import posts
     *
     * @when after_wp_load
     */
    public function posts($args, $assoc) {
        wpcx_boot();
        wpcx_import_posts(wpcx_opts($assoc));
    }

    /**
     * Download CSV thumbnails into the Media Library and set featured images.
     * Skips posts that already have a featured image.
     *
     * ## OPTIONS
     *
     * [--csv=<path>]
     * : CSV path. Default: WordPress root / posts.csv
     *
     * [--concurrency=<n>]
     * : Parallel downloads. Default: 8
     *
     * [--dry-run]
     * : Count only. Does not download files.
     *
     * ## EXAMPLES
     *
     *     wp csv-import thumbs --dry-run
     *     wp csv-import thumbs
     *
     * @when after_wp_load
     */
    public function thumbs($args, $assoc) {
        wpcx_boot();
        wpcx_import_thumbs(wpcx_opts($assoc));
    }
}
}

if (!function_exists('wpcx_boot')) {
function wpcx_boot() {
    ignore_user_abort(true);
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
    wp_suspend_cache_invalidation(true);
    wp_defer_term_counting(true);
}

function wpcx_opts($assoc) {
    return array(
        'csv'              => !empty($assoc['csv']) ? $assoc['csv'] : wpcx_default_csv(),
        'batch'            => isset($assoc['batch']) ? (int) $assoc['batch'] : 0,
        'concurrency'      => isset($assoc['concurrency']) ? (int) $assoc['concurrency'] : 8,
        'pending_category' => isset($assoc['pending-category']) ? $assoc['pending-category'] : 'Uncut',
        'dry_run'          => isset($assoc['dry-run']),
    );
}

function wpcx_default_csv() {
    return ABSPATH . 'posts.csv';
}

function wpcx_read($path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "CSV not readable: {$path}\n");
        exit(1);
    }

    $handle = fopen($path, 'r');
    $header = fgetcsv($handle);
    $header = array_map(function ($name) {
        return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)));
    }, $header);

    $map = array();
    foreach ($header as $i => $name) {
        $map[$name] = $i;
    }
    if (!isset($map['title'])) {
        fclose($handle);
        fwrite(STDERR, "CSV needs a Title column.\n");
        exit(1);
    }

    $rows = array();
    while (($cols = fgetcsv($handle)) !== false) {
        $title = wpcx_col($cols, $map, 'title');
        $video = wpcx_col($cols, $map, 'video_url');
        if ($title === '' && $video === '') {
            continue;
        }
        $rows[] = array(
            'title'      => $title,
            'duration'   => wpcx_col($cols, $map, 'duration'),
            'video_url'  => $video,
            'categories' => wpcx_list(wpcx_col($cols, $map, 'categories')),
            'models'     => wpcx_list(wpcx_first($cols, $map, array('model', 'models', 'actor', 'actors', 'actress'))),
            'series'     => wpcx_list(wpcx_first($cols, $map, array('series', 'web series', 'web_series'))),
            'thumbnail'  => wpcx_col($cols, $map, 'thumbnail'),
            'tags'       => wpcx_list(wpcx_col($cols, $map, 'tags')),
        );
    }
    fclose($handle);
    return $rows;
}

function wpcx_col($cols, $map, $key) {
    return (isset($map[$key], $cols[$map[$key]])) ? trim((string) $cols[$map[$key]]) : '';
}

function wpcx_first($cols, $map, $keys) {
    foreach ($keys as $key) {
        $value = wpcx_col($cols, $map, $key);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function wpcx_list($value) {
    if ($value === '') {
        return array();
    }
    $parts = array_map('trim', explode(',', $value));
    return array_values(array_unique(array_filter($parts)));
}

function wpcx_key($value) {
    return strtolower(trim((string) $value));
}

function wpcx_has_pending_cat($categories, $pending_name) {
    $want = wpcx_key($pending_name);
    if ($want === '') {
        return false;
    }
    foreach ($categories as $cat) {
        if (wpcx_key($cat) === $want) {
            return true;
        }
    }
    return false;
}

function wpcx_existing_maps() {
    global $wpdb;

    $by_title = array();
    $by_video = array();
    $thumbs   = array();
    $titles   = array();
    $slugs    = array();

    $posts = $wpdb->get_results("SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status != 'trash'");
    foreach ($posts as $post) {
        $id = (int) $post->ID;
        $titles[$id] = $post->post_title;
        $k = wpcx_key($post->post_title);
        if ($k !== '' && !isset($by_title[$k])) {
            $by_title[$k] = $id;
        }
        if ($post->post_name !== '') {
            $slugs[$post->post_name] = true;
        }
    }

    $videos = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('video_url','_video_url')");
    foreach ($videos as $row) {
        if ($row->meta_value !== '') {
            $by_video[wpcx_key($row->meta_value)] = (int) $row->post_id;
        }
    }

    $ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value != '' AND meta_value != '0'");
    foreach ($ids as $id) {
        $thumbs[(int) $id] = true;
    }

    return array(
        'by_title' => $by_title,
        'by_video' => $by_video,
        'thumbs'   => $thumbs,
        'titles'   => $titles,
        'slugs'    => $slugs,
    );
}

function wpcx_find_post($row, $maps) {
    if ($row['video_url'] !== '') {
        $k = wpcx_key($row['video_url']);
        if (isset($maps['by_video'][$k])) {
            return $maps['by_video'][$k];
        }
    }
    $k = wpcx_key($row['title']);
    if ($k !== '' && isset($maps['by_title'][$k])) {
        return $maps['by_title'][$k];
    }
    return 0;
}

function wpcx_import_posts($opts) {
    $rows = wpcx_read($opts['csv']);
    $maps = wpcx_existing_maps();
    $pending_name = $opts['pending_category'];
    $author = wpcx_author();

    $to_create = array();
    $to_terms  = array();
    $skipped   = 0;
    foreach ($rows as $row) {
        $post_id = wpcx_find_post($row, $maps);
        if ($post_id) {
            $skipped++;
            $to_terms[] = array($post_id, $row);
            continue;
        }
        $to_create[] = $row;
    }

    echo sprintf(
        "CSV %d | already exist %d | to create %d | file %s\n",
        count($rows),
        $skipped,
        count($to_create),
        $opts['csv']
    );

    if ($opts['dry_run']) {
        wpcx_done();
        return;
    }

    $limit = (int) $opts['batch'];
    if ($limit > 0) {
        $to_create = array_slice($to_create, 0, $limit);
    }

    $terms = wpcx_term_cache(array_merge($to_create, array_map(function ($job) {
        return $job[1];
    }, $to_terms)));

    $created = 0;
    $failed  = 0;
    if ($to_create) {
        $dates = wpcx_dates($to_create, $pending_name);
        $started = microtime(true);
        $total = count($to_create);
        foreach ($to_create as $i => $row) {
            $ok = wpcx_insert_post($row, $dates[$i], $author, $maps, $terms, $pending_name);
            if ($ok) {
                $created++;
            } else {
                $failed++;
            }
            if (($created + $failed) % 100 === 0 || ($i + 1) === $total) {
                wpcx_line('Posts', $created + $failed, $total, $created, $failed, $started);
            }
        }
        echo PHP_EOL;
    }

    if ($to_terms) {
        foreach ($to_terms as $job) {
            wpcx_insert_rels($job[0], $job[1], $terms);
        }
    }

    wpcx_recount($terms);
    echo sprintf("Finished posts. created %d  failed %d  skipped %d\n", $created, $failed, $skipped);
    wpcx_done();
}

function wpcx_dates($rows, $pending_name) {
    $publish = array();
    $pending = array();
    foreach ($rows as $i => $row) {
        if (wpcx_has_pending_cat($row['categories'], $pending_name)) {
            $pending[] = $i;
        } else {
            $publish[] = $i;
        }
    }
    $publish = array_reverse($publish);

    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $day = new DateTimeImmutable('now', $tz);
    $day = $day->setTime(0, 0, 0);
    mt_srand(crc32((string) home_url('/')));

    $queue = array();
    $fill = function () use (&$day, &$queue) {
        $n = mt_rand(10, 25);
        $times = array();
        for ($i = 0; $i < $n; $i++) {
            $times[] = sprintf('%02d:%02d:%02d', mt_rand(8, 23), mt_rand(0, 59), mt_rand(0, 59));
        }
        rsort($times);
        foreach ($times as $t) {
            $p = explode(':', $t);
            $queue[] = $day->setTime((int) $p[0], (int) $p[1], (int) $p[2])->format('Y-m-d H:i:s');
        }
        $day = $day->modify('-1 day');
    };

    $out = array();
    foreach ($publish as $i) {
        if (!$queue) {
            $fill();
        }
        $out[$i] = array('status' => 'publish', 'date' => array_shift($queue));
    }
    foreach ($pending as $i) {
        if (!$queue) {
            $fill();
        }
        $out[$i] = array('status' => 'pending', 'date' => array_shift($queue));
    }
    ksort($out);
    return $out;
}

function wpcx_first_taxonomy($slugs) {
    foreach ($slugs as $slug) {
        if (taxonomy_exists($slug)) {
            return $slug;
        }
    }
    return '';
}

function wpcx_tax_map() {
    return array(
        'model'  => wpcx_first_taxonomy(array('model', 'actor', 'actress')),
        'series' => wpcx_first_taxonomy(array('series', 'web_series')),
    );
}

function wpcx_term_cache($rows) {
    $tax = wpcx_tax_map();
    $needed = array(
        'category' => array(),
        'post_tag' => array(),
    );
    if ($tax['model']) {
        $needed[$tax['model']] = array();
    }
    if ($tax['series']) {
        $needed[$tax['series']] = array();
    }

    foreach ($rows as $row) {
        foreach ($row['categories'] as $name) {
            $needed['category'][$name] = true;
        }
        foreach ($row['tags'] as $name) {
            $needed['post_tag'][$name] = true;
        }
        if ($tax['model']) {
            foreach ($row['models'] as $name) {
                $needed[$tax['model']][$name] = true;
            }
        }
        if ($tax['series']) {
            foreach ($row['series'] as $name) {
                $needed[$tax['series']][$name] = true;
            }
        }
    }

    $map = array();
    foreach ($needed as $taxonomy => $names) {
        if (!taxonomy_exists($taxonomy)) {
            continue;
        }
        foreach (array_keys($names) as $name) {
            $key = wpcx_key($name);
            $existing = term_exists($name, $taxonomy);
            if ($existing && !is_wp_error($existing)) {
                $term_id = (int) (is_array($existing) ? $existing['term_id'] : $existing);
            } else {
                $created = wp_insert_term($name, $taxonomy);
                if (is_wp_error($created)) {
                    continue;
                }
                $term_id = (int) $created['term_id'];
            }
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $map[$taxonomy][$key] = (int) $term->term_taxonomy_id;
            }
        }
    }
    return $map;
}

function wpcx_insert_post($row, $plan, $author, &$maps, $terms, $pending_name) {
    global $wpdb;

    $title = wp_strip_all_tags($row['title']);
    $video = esc_url_raw($row['video_url']);
    if ($title === '') {
        return false;
    }

    $status = (!empty($plan['status'])) ? $plan['status'] : 'publish';
    if (wpcx_has_pending_cat($row['categories'], $pending_name)) {
        $status = 'pending';
    }
    $date = !empty($plan['date']) ? $plan['date'] : current_time('mysql');
    $gmt  = get_gmt_from_date($date);
    $slug = wpcx_unique_slug($title, $maps['slugs']);
    $now  = current_time('mysql');

    $ok = $wpdb->insert(
        $wpdb->posts,
        array(
            'post_author'       => $author,
            'post_date'         => $date,
            'post_date_gmt'     => $gmt,
            'post_content'      => '',
            'post_title'        => $title,
            'post_excerpt'      => '',
            'post_status'       => $status,
            'comment_status'    => 'closed',
            'ping_status'       => 'closed',
            'post_name'         => $slug,
            'post_modified'     => $now,
            'post_modified_gmt' => get_gmt_from_date($now),
            'guid'              => '',
            'post_type'         => 'post',
        )
    );
    if (!$ok) {
        return false;
    }

    $post_id = (int) $wpdb->insert_id;
    $wpdb->update($wpdb->posts, array('guid' => home_url('/?p=' . $post_id)), array('ID' => $post_id));

    $maps['by_title'][wpcx_key($title)] = $post_id;
    if ($video !== '') {
        $maps['by_video'][wpcx_key($video)] = $post_id;
    }
    $maps['titles'][$post_id] = $title;

    $meta = array();
    $meta[] = array($post_id, 'video_url', $video);
    if ($row['duration'] !== '') {
        $meta[] = array($post_id, 'duration', sanitize_text_field($row['duration']));
    }
    if ($row['thumbnail'] !== '') {
        $meta[] = array($post_id, '_csv_thumbnail_url', esc_url_raw($row['thumbnail']));
    }
    wpcx_insert_meta($meta);
    wpcx_insert_rels($post_id, $row, $terms);

    return true;
}

function wpcx_insert_meta($rows) {
    global $wpdb;
    if (!$rows) {
        return;
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        $sql = array();
        foreach ($chunk as $row) {
            $sql[] = $wpdb->prepare('(%d,%s,%s)', $row[0], $row[1], $row[2]);
        }
        $wpdb->query("INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode(',', $sql));
    }
}

function wpcx_insert_rels($post_id, $row, $terms) {
    global $wpdb;
    $pairs = array();
    $tax = wpcx_tax_map();
    $sets = array(
        'category' => $row['categories'],
        'post_tag' => $row['tags'],
    );
    if ($tax['model']) {
        $sets[$tax['model']] = $row['models'];
    }
    if ($tax['series']) {
        $sets[$tax['series']] = $row['series'];
    }
    foreach ($sets as $taxonomy => $names) {
        if (empty($terms[$taxonomy])) {
            continue;
        }
        foreach ($names as $name) {
            $key = wpcx_key($name);
            if (!empty($terms[$taxonomy][$key])) {
                $pairs[] = array($post_id, $terms[$taxonomy][$key]);
            }
        }
    }
    if (!$pairs) {
        return 0;
    }
    $sql = array();
    foreach ($pairs as $pair) {
        $sql[] = $wpdb->prepare('(%d,%d,0)', $pair[0], $pair[1]);
    }
    $wpdb->query("INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES " . implode(',', $sql));
    return count($pairs);
}

function wpcx_recount($terms) {
    foreach ($terms as $taxonomy => $by_name) {
        $ids = array_values(array_unique(array_map('intval', $by_name)));
        if ($ids && taxonomy_exists($taxonomy)) {
            wp_update_term_count_now($ids, $taxonomy);
        }
    }
}

function wpcx_unique_slug($title, &$used) {
    $slug = sanitize_title($title);
    if ($slug === '') {
        $slug = 'post';
    }
    if (strlen($slug) > 180) {
        $slug = substr($slug, 0, 180);
    }
    $base = $slug;
    $n = 2;
    while (isset($used[$slug])) {
        $slug = $base . '-' . $n;
        $n++;
    }
    $used[$slug] = true;
    return $slug;
}

function wpcx_author() {
    $admins = get_users(array(
        'role'    => 'administrator',
        'number'  => 1,
        'orderby' => 'ID',
        'fields'  => 'ID',
    ));
    if (!empty($admins)) {
        return (int) $admins[0];
    }
    return 1;
}

function wpcx_import_thumbs($opts) {
    $rows = wpcx_read($opts['csv']);
    $maps = wpcx_existing_maps();
    $jobs = array();
    $matched = 0;
    $has_image = 0;
    $missing = 0;
    $no_url = 0;

    foreach ($rows as $row) {
        if ($row['thumbnail'] === '') {
            $no_url++;
            continue;
        }
        $post_id = wpcx_find_post($row, $maps);
        if (!$post_id) {
            $missing++;
            continue;
        }
        $matched++;
        if (isset($maps['thumbs'][$post_id])) {
            $has_image++;
            continue;
        }
        $jobs[] = array(
            'post_id' => $post_id,
            'title'   => isset($maps['titles'][$post_id]) ? $maps['titles'][$post_id] : $row['title'],
            'url'     => $row['thumbnail'],
        );
        $maps['thumbs'][$post_id] = true;
    }

    echo sprintf(
        "CSV %d | matched %d | already have image %d | queued %d | no post %d | no url %d\n",
        count($rows),
        $matched,
        $has_image,
        count($jobs),
        $missing,
        $no_url
    );

    if ($opts['dry_run'] || !$jobs) {
        wpcx_done();
        return;
    }

    $concurrency = max(4, min(12, (int) $opts['concurrency']));
    echo "Downloading {$concurrency} at a time.\n";

    $progress = array(
        'ok'      => 0,
        'fail'    => 0,
        'total'   => count($jobs),
        'started' => microtime(true),
    );
    wpcx_download($jobs, $concurrency, $progress);
    echo PHP_EOL . sprintf("Finished thumbs. ok %d  fail %d\n", $progress['ok'], $progress['fail']);
    wpcx_done();
}

function wpcx_download($jobs, $concurrency, &$progress) {
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        fwrite(STDERR, $uploads['error'] . "\n");
        return;
    }
    wp_mkdir_p($uploads['path']);
    $tmp_dir = trailingslashit(sys_get_temp_dir()) . 'wpcsv-express-' . getmypid();
    wp_mkdir_p($tmp_dir);

    $mh = curl_multi_init();
    $queue = $jobs;
    $active = array();

    $add = function () use (&$queue, &$active, $mh, $tmp_dir, $concurrency) {
        while (count($active) < $concurrency && $queue) {
            $item = array_shift($queue);
            $tmp = $tmp_dir . '/' . $item['post_id'] . '.bin';
            $fp = fopen($tmp, 'wb');
            if (!$fp) {
                continue;
            }
            $ch = curl_init($item['url']);
            curl_setopt_array($ch, array(
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_NOSIGNAL       => true,
                CURLOPT_USERAGENT      => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                CURLOPT_HTTPHEADER     => array('Accept: */*'),
            ));
            curl_multi_add_handle($mh, $ch);
            $active[(int) $ch] = compact('ch', 'fp', 'tmp') + array('item' => $item);
        }
    };

    $add();
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.3);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int) $ch;
            if (!isset($active[$key])) {
                continue;
            }
            $job = $active[$key];
            fclose($job['fp']);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ok = ($info['result'] === CURLE_OK && $code >= 200 && $code < 300);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[$key]);
            wpcx_finish_image($job['item'], $job['tmp'], $ok, $uploads, $progress);
            $add();
        }
    } while ($running || $active || $queue);

    curl_multi_close($mh);
    @rmdir($tmp_dir);
}

function wpcx_finish_image($item, $tmp, $ok, $uploads, &$progress) {
    if (!$ok || !wpcx_is_image($tmp)) {
        $wp_tmp = download_url($item['url'], 30);
        if (!is_wp_error($wp_tmp) && wpcx_is_image($wp_tmp)) {
            if ($tmp && file_exists($tmp)) {
                @unlink($tmp);
            }
            $tmp = $wp_tmp;
            $ok = true;
        }
    }

    $done = $ok && wpcx_is_image($tmp) && wpcx_attach($item['post_id'], $item['title'], $item['url'], $tmp, $uploads);
    if ($tmp && file_exists($tmp)) {
        @unlink($tmp);
    }
    if ($done) {
        $progress['ok']++;
    } else {
        $progress['fail']++;
    }
    wpcx_line('Thumbs', $progress['ok'] + $progress['fail'], $progress['total'], $progress['ok'], $progress['fail'], $progress['started']);
}

function wpcx_is_image($path) {
    if (!$path || !is_readable($path) || filesize($path) < 80) {
        return false;
    }
    $fh = fopen($path, 'rb');
    $head = strtolower(fread($fh, 40));
    fclose($fh);
    return (strpos($head, '<html') === false && strpos($head, '<!doc') === false);
}

function wpcx_attach($post_id, $title, $url, $tmp, $uploads) {
    global $wpdb;

    $title = wp_strip_all_tags($title);
    $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'), true)) {
        $ext = 'webp';
    }
    $name = sanitize_file_name($title);
    $name = preg_replace('/\.[a-z0-9]+$/i', '', $name);
    if ($name === '') {
        $name = 'image-' . $post_id;
    }
    if (strlen($name) > 180) {
        $name = substr($name, 0, 180);
    }
    $filename = $name . '.' . $ext;
    $n = 2;
    while (file_exists(trailingslashit($uploads['path']) . $filename)) {
        $filename = $name . '-' . $n . '.' . $ext;
        $n++;
    }
    $dest = trailingslashit($uploads['path']) . $filename;
    if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
        return false;
    }
    @chmod($dest, 0644);

    $width = 0;
    $height = 0;
    $info = @getimagesize($dest);
    if (is_array($info)) {
        $width = (int) $info[0];
        $height = (int) $info[1];
    }
    $mimes = array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
    );
    $mime = isset($mimes[$ext]) ? $mimes[$ext] : 'image/webp';
    $rel = ltrim(trailingslashit(isset($uploads['subdir']) ? $uploads['subdir'] : ''), '/') . $filename;
    $now = current_time('mysql');

    $ok = $wpdb->insert($wpdb->posts, array(
        'post_author'       => 1,
        'post_date'         => $now,
        'post_date_gmt'     => get_gmt_from_date($now),
        'post_content'      => $title,
        'post_title'        => $title,
        'post_excerpt'      => $title,
        'post_status'       => 'inherit',
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_name'         => sanitize_title($title),
        'post_modified'     => $now,
        'post_modified_gmt' => get_gmt_from_date($now),
        'post_parent'       => $post_id,
        'guid'              => trailingslashit($uploads['url']) . $filename,
        'post_type'         => 'attachment',
        'post_mime_type'    => $mime,
    ));
    if (!$ok) {
        @unlink($dest);
        return false;
    }

    $attachment_id = (int) $wpdb->insert_id;
    $meta = maybe_serialize(array(
        'width' => $width, 'height' => $height, 'file' => $rel,
        'filesize' => (int) filesize($dest), 'sizes' => array(), 'image_meta' => array(),
    ));
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES
         (%d, '_wp_attached_file', %s),
         (%d, '_wp_attachment_metadata', %s),
         (%d, '_wp_attachment_image_alt', %s),
         (%d, '_thumbnail_id', %d)",
        $attachment_id, $rel,
        $attachment_id, $meta,
        $attachment_id, $title,
        $post_id, $attachment_id
    ));
    return true;
}

function wpcx_line($label, $done, $total, $ok, $fail, $started) {
    $elapsed = max(0.001, microtime(true) - $started);
    $rate = $done / $elapsed;
    $left = max(0, $total - $done);
    $eta = $rate > 0 ? (int) round($left / $rate) : 0;
    if ($eta < 60) {
        $eta_text = $eta . 's';
    } elseif ($eta < 3600) {
        $eta_text = floor($eta / 60) . 'm ' . ($eta % 60) . 's';
    } else {
        $eta_text = floor($eta / 3600) . 'h ' . floor(($eta % 3600) / 60) . 'm';
    }
    echo "\r{$label} {$done}/{$total} | ok {$ok} fail {$fail} | " . number_format($rate, 1) . "/sec | left {$eta_text}   ";
    flush();
}

function wpcx_done() {
    wp_defer_term_counting(false);
    wp_suspend_cache_invalidation(false);
}
}
