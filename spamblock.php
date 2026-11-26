<?php
/**
 * Plugin Name: Блокировка иностранного трафика
 * Plugin URI:  https://t.me/joidru
 * Description: Ограничивает иностранный трафик, не блокируя подтверждённых роботов Google и Яндекса.
 * Version:     1.4.0
 * Author:      JOID
 * Author URI:  https://t.me/joidru
 * License:     GPL v3
 * Text Domain: spamblock
 * Update URI:  https://github.com/joidru/wp-geo-bot-blocker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'spamblock_check_request', 0 );
add_action( 'admin_menu', 'spamblock_admin_menu' );
add_action( 'admin_init', 'spamblock_register_settings' );
add_filter( 'pre_set_site_transient_update_plugins', 'spamblock_check_github_update' );
add_filter( 'plugins_api', 'spamblock_github_plugin_info', 20, 3 );
add_action( 'upgrader_process_complete', 'spamblock_clear_update_cache', 10, 2 );

define( 'SPAMBLOCK_VERSION', '1.4.0' );
define( 'SPAMBLOCK_GITHUB_REPOSITORY', 'joidru/wp-geo-bot-blocker' );

function spamblock_get_github_release() {
	$cached = get_site_transient( 'spamblock_github_release' );
	if ( false !== $cached ) {
		return 'unavailable' === $cached ? false : $cached;
	}

	$response = wp_safe_remote_get(
		'https://api.github.com/repos/' . SPAMBLOCK_GITHUB_REPOSITORY . '/releases/latest',
		array(
			'timeout' => 5,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WordPress-SpamBlock/' . SPAMBLOCK_VERSION,
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		set_site_transient( 'spamblock_github_release', 'unavailable', HOUR_IN_SECONDS );
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['tag_name'] ) || empty( $data['html_url'] ) ) {
		set_site_transient( 'spamblock_github_release', 'unavailable', HOUR_IN_SECONDS );
		return false;
	}

	$package = '';
	if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
		foreach ( $data['assets'] as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'spamblock.zip' === $asset['name'] ) {
				$package = esc_url_raw( $asset['browser_download_url'] );
				break;
			}
		}
	}

	$release = array(
		'version'     => ltrim( sanitize_text_field( $data['tag_name'] ), 'vV' ),
		'package'     => $package,
		'url'         => esc_url_raw( $data['html_url'] ),
		'name'        => ! empty( $data['name'] ) ? sanitize_text_field( $data['name'] ) : 'SpamBlock ' . $data['tag_name'],
		'description' => ! empty( $data['body'] ) ? wp_kses_post( $data['body'] ) : '',
		'published'   => ! empty( $data['published_at'] ) ? sanitize_text_field( $data['published_at'] ) : '',
	);

	set_site_transient( 'spamblock_github_release', $release, 6 * HOUR_IN_SECONDS );
	return $release;
}

function spamblock_check_github_update( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
		return $transient;
	}

	$release     = spamblock_get_github_release();
	$plugin_file = plugin_basename( __FILE__ );
	if ( ! $release || empty( $release['package'] ) || ! version_compare( SPAMBLOCK_VERSION, $release['version'], '<' ) ) {
		return $transient;
	}

	$update              = new stdClass();
	$update->id          = 'github.com/' . SPAMBLOCK_GITHUB_REPOSITORY;
	$update->slug        = 'spamblock';
	$update->plugin      = $plugin_file;
	$update->new_version = $release['version'];
	$update->url         = $release['url'];
	$update->package     = $release['package'];
	$update->icons       = array();
	$update->banners     = array();

	$transient->response[ $plugin_file ] = $update;
	return $transient;
}

function spamblock_github_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'spamblock' !== $args->slug ) {
		return $result;
	}

	$release = spamblock_get_github_release();
	if ( ! $release ) {
		return $result;
	}

	$info                = new stdClass();
	$info->name          = 'Блокировка иностранного трафика';
	$info->slug          = 'spamblock';
	$info->version       = $release['version'];
	$info->author        = '<a href="https://t.me/joidru">JOID</a>';
	$info->homepage      = $release['url'];
	$info->download_link = $release['package'];
	$info->last_updated  = $release['published'];
	$info->sections      = array(
		'description' => 'Фильтрация трафика по странам, User-Agent и частоте запросов с исключениями для подтверждённых поисковых роботов.',
		'changelog'   => $release['description'] ? $release['description'] : 'Описание изменений доступно на странице релиза GitHub.',
	);

	return $info;
}

function spamblock_clear_update_cache( $upgrader, $options ) {
	if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
		delete_site_transient( 'spamblock_github_release' );
	}
}

function spamblock_defaults() {
	return array(
		'enabled'           => '1',
		'mode'              => 'block',
		'allowed_countries' => 'RU',
		'message'           => 'Доступ ограничен. Попробуйте зайти позже.',
		'whitelist_ips'     => '',
		'whitelist_paths'   => "/wp-json/\n/robots.txt",
		'rate_limit'        => 120,
		'logging'           => '1',
		'bot_filter'        => '',
		'bot_mode'          => 'monitor',
		'bot_keywords'      => "crawler\nscraper\nspambot\npython-requests\ncurl/\nwget/\nbot\nspider\nhttpclient\nnmap\nmasscan\nsqlmap",
		'log_display'       => 20,
	);
}

function spamblock_options() {
	return wp_parse_args( get_option( 'spamblock_options', array() ), spamblock_defaults() );
}

/**
 * Check public front-end requests only.
 *
 * Important system requests are skipped to avoid breaking WordPress, webhooks,
 * scheduled jobs and administrator access.
 */
function spamblock_check_request() {
	$options = spamblock_options();
	if ( empty( $options['enabled'] ) ) {
		return;
	}

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
		return;
	}

	$ip = spamblock_get_client_ip();
	if ( ! $ip || ! spamblock_is_public_ip( $ip ) ) {
		return;
	}

	if ( spamblock_is_allowlisted( $ip, $options ) ) {
		return;
	}

	// A User-Agent is only a reason to perform verification, never proof by itself.
	if ( spamblock_is_verified_search_bot( $ip ) ) {
		return;
	}

	$bot_keyword = spamblock_match_bot_keyword( $options );
	if ( $bot_keyword ) {
		spamblock_record_event( 'user_agent:' . $bot_keyword, $ip, $options );
		if ( 'block' === $options['bot_mode'] ) {
			spamblock_deny_request( 403, $options['message'] );
		}
		return;
	}

	$country = spamblock_get_country_code( $ip );
	$allowed = preg_split( '/[\s,;]+/', strtoupper( $options['allowed_countries'] ), -1, PREG_SPLIT_NO_EMPTY );
	$allowed = (array) apply_filters( 'spamblock_allowed_countries', $allowed );
	$allowed = array_map( 'strtoupper', $allowed );

	// Fail open when GeoIP is unavailable: an external outage must not take down the site.
	if ( '' !== $country && ! in_array( $country, $allowed, true ) ) {
		spamblock_handle_event( 'country:' . $country, $ip, 403, $options );
		return;
	}

	spamblock_rate_limit( $ip, $options );
}

function spamblock_match_bot_keyword( $options ) {
	if ( empty( $options['bot_filter'] ) ) {
		return '';
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
	if ( '' === $user_agent ) {
		return 'empty_user_agent';
	}

	$keywords = preg_split( '/\R+/', strtolower( $options['bot_keywords'] ), -1, PREG_SPLIT_NO_EMPTY );
	foreach ( $keywords as $keyword ) {
		$keyword = trim( $keyword );
		if ( $keyword && false !== strpos( $user_agent, $keyword ) ) {
			return substr( sanitize_key( $keyword ), 0, 40 );
		}
	}

	return '';
}

function spamblock_is_allowlisted( $ip, $options ) {
	$ips = preg_split( '/[\s,;]+/', $options['whitelist_ips'], -1, PREG_SPLIT_NO_EMPTY );
	if ( in_array( $ip, $ips, true ) ) {
		return true;
	}

	$path  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	$path  = (string) wp_parse_url( $path, PHP_URL_PATH );
	$paths = preg_split( '/\R+/', $options['whitelist_paths'], -1, PREG_SPLIT_NO_EMPTY );
	foreach ( $paths as $prefix ) {
		$prefix = trim( $prefix );
		if ( $prefix && 0 === strpos( $path, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Return the direct peer address. Proxy headers are deliberately not trusted by
 * default because visitors can forge them. Hosting integrations may override it.
 */
function spamblock_get_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ip     = (string) apply_filters( 'spamblock_client_ip', $remote );

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

function spamblock_is_public_ip( $ip ) {
	return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/**
 * Verify Google and Yandex crawlers with forward-confirmed reverse DNS.
 */
function spamblock_is_verified_search_bot( $ip ) {
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';

	if ( false !== strpos( $user_agent, 'google' ) ) {
		return spamblock_verify_bot_dns( $ip, array( 'googlebot.com', 'google.com' ), 'google' );
	}

	if ( false !== strpos( $user_agent, 'yandex' ) ) {
		return spamblock_verify_bot_dns( $ip, array( 'yandex.ru', 'yandex.net', 'yandex.com' ), 'yandex' );
	}

	return false;
}

function spamblock_verify_bot_dns( $ip, $allowed_domains, $provider ) {
	$cache_key = 'spamblock_bot_' . md5( $provider . '|' . $ip );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return '1' === $cached;
	}

	$hostname = strtolower( rtrim( (string) gethostbyaddr( $ip ), '.' ) );
	$valid    = false;

	if ( $hostname && $hostname !== $ip && spamblock_hostname_has_suffix( $hostname, $allowed_domains ) ) {
		$addresses = spamblock_resolve_hostname( $hostname );
		$valid     = in_array( $ip, $addresses, true );
	}

	set_transient( $cache_key, $valid ? '1' : '0', $valid ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
	return $valid;
}

function spamblock_hostname_has_suffix( $hostname, $domains ) {
	foreach ( $domains as $domain ) {
		if ( $hostname === $domain || substr( $hostname, -strlen( '.' . $domain ) ) === '.' . $domain ) {
			return true;
		}
	}

	return false;
}

function spamblock_resolve_hostname( $hostname ) {
	$addresses = array();

	if ( function_exists( 'dns_get_record' ) ) {
		$records = @dns_get_record( $hostname, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$addresses[] = $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
		}
	}

	// Fallback for hosts where dns_get_record() is disabled.
	if ( empty( $addresses ) ) {
		$fallback = gethostbynamel( $hostname );
		if ( is_array( $fallback ) ) {
			$addresses = $fallback;
		}
	}

	return array_values( array_unique( $addresses ) );
}

/**
 * Resolve country via an HTTPS endpoint and cache successful responses.
 */
function spamblock_get_country_code( $ip ) {
	$cloudflare = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) : '';
	if ( preg_match( '/^[A-Z]{2}$/', $cloudflare ) ) {
		return $cloudflare;
	}

	$cache_key = 'spamblock_country_' . md5( $ip );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return 'unknown' === $cached ? '' : $cached;
	}

	$url      = 'https://ipwho.is/' . rawurlencode( $ip ) . '?fields=success,country_code';
	$response = wp_safe_remote_get( $url, array( 'timeout' => 3, 'redirection' => 0 ) );

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $cache_key, 'unknown', 10 * MINUTE_IN_SECONDS );
		return '';
	}

	$data    = json_decode( wp_remote_retrieve_body( $response ), true );
	$country = ! empty( $data['success'] ) && ! empty( $data['country_code'] ) ? strtoupper( sanitize_text_field( $data['country_code'] ) ) : '';

	if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
		set_transient( $cache_key, 'unknown', 10 * MINUTE_IN_SECONDS );
		return '';
	}

	set_transient( $cache_key, $country, WEEK_IN_SECONDS );
	return $country;
}

function spamblock_rate_limit( $ip, $options ) {
	$limit = min( 10000, max( 0, absint( $options['rate_limit'] ) ) );
	if ( 0 === $limit ) {
		return;
	}

	$key   = 'spamblock_rate_' . md5( $ip . '|' . gmdate( 'YmdHi' ) );
	$count = (int) get_transient( $key ) + 1;
	set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );

	if ( $count > $limit ) {
		spamblock_handle_event( 'rate_limit', $ip, 429, $options );
	}
}

function spamblock_handle_event( $reason, $ip, $status, $options ) {
	spamblock_record_event( $reason, $ip, $options );

	if ( 'monitor' !== $options['mode'] ) {
		spamblock_deny_request( $status, $options['message'] );
	}
}

function spamblock_record_event( $reason, $ip, $options ) {
	$stats            = get_option( 'spamblock_stats', array() );
	$stats['total']   = isset( $stats['total'] ) ? (int) $stats['total'] + 1 : 1;
	$stats['reasons'] = isset( $stats['reasons'] ) && is_array( $stats['reasons'] ) ? $stats['reasons'] : array();
	$stats['reasons'][ $reason ] = isset( $stats['reasons'][ $reason ] ) ? (int) $stats['reasons'][ $reason ] + 1 : 1;
	$stats['updated'] = time();
	update_option( 'spamblock_stats', $stats, false );

	if ( empty( $options['logging'] ) ) {
		return;
	}

	$logs   = get_option( 'spamblock_log', array() );
	$logs[] = array(
		'time'   => time(),
		'reason' => sanitize_text_field( $reason ),
		'ip'     => $ip,
		'path'   => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( substr( wp_unslash( $_SERVER['REQUEST_URI'] ), 0, 160 ) ) : '',
	);
	update_option( 'spamblock_log', array_slice( $logs, -100 ), false );
}

function spamblock_deny_request( $status = 403, $message = '' ) {
	$status = 429 === (int) $status ? 429 : 403;
	$message = $message ? $message : spamblock_defaults()['message'];

	if ( 429 === $status ) {
		header( 'Retry-After: 300' );
	}

	wp_die(
		esc_html( $message ),
		esc_html__( 'Доступ ограничен', 'spamblock' ),
		array( 'response' => $status )
	);
}

function spamblock_register_settings() {
	register_setting( 'spamblock', 'spamblock_options', array( 'sanitize_callback' => 'spamblock_sanitize_options' ) );
}

function spamblock_sanitize_options( $input ) {
	$defaults = spamblock_defaults();
	$input    = is_array( $input ) ? $input : array();
	$output   = $defaults;
	$log_display = absint( isset( $input['log_display'] ) ? $input['log_display'] : 20 );

	$output['enabled']           = empty( $input['enabled'] ) ? '' : '1';
	$output['logging']           = empty( $input['logging'] ) ? '' : '1';
	$output['bot_filter']        = empty( $input['bot_filter'] ) ? '' : '1';
	$output['mode']              = isset( $input['mode'] ) && 'monitor' === $input['mode'] ? 'monitor' : 'block';
	$output['bot_mode']          = isset( $input['bot_mode'] ) && 'block' === $input['bot_mode'] ? 'block' : 'monitor';
	$output['allowed_countries'] = preg_replace( '/[^A-Za-z,;\s]/', '', isset( $input['allowed_countries'] ) ? $input['allowed_countries'] : 'RU' );
	$output['message']           = sanitize_text_field( isset( $input['message'] ) ? $input['message'] : $defaults['message'] );
	$output['whitelist_ips']     = sanitize_textarea_field( isset( $input['whitelist_ips'] ) ? $input['whitelist_ips'] : '' );
	$output['whitelist_paths']   = sanitize_textarea_field( isset( $input['whitelist_paths'] ) ? $input['whitelist_paths'] : '' );
	$output['rate_limit']        = min( 10000, max( 0, absint( isset( $input['rate_limit'] ) ? $input['rate_limit'] : 120 ) ) );
	$output['bot_keywords']      = sanitize_textarea_field( isset( $input['bot_keywords'] ) ? $input['bot_keywords'] : $defaults['bot_keywords'] );
	$output['log_display']       = in_array( $log_display, array( 10, 20, 50 ), true ) ? $log_display : 20;

	return $output;
}

function spamblock_admin_menu() {
	add_options_page( 'Блокировка трафика', 'Блокировка трафика', 'manage_options', 'spamblock', 'spamblock_settings_page' );
}

function spamblock_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$options    = spamblock_options();
	$stats      = get_option( 'spamblock_stats', array() );
	$all_logs   = array_reverse( get_option( 'spamblock_log', array() ) );
	$logs       = array_slice( $all_logs, 0, absint( $options['log_display'] ) );
	$current_ip = spamblock_get_client_ip();
	?>
	<style>
		.spamblock-wrap{max-width:1180px}.spamblock-hero{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:18px 0;padding:20px 24px;background:linear-gradient(135deg,#172554,#1d4ed8);color:#fff;border-radius:14px;box-shadow:0 8px 24px rgba(30,64,175,.18)}.spamblock-hero h1{margin:0;color:#fff}.spamblock-hero p{margin:6px 0 0;color:#dbeafe}.spamblock-ip{flex:none;padding:10px 14px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:10px}.spamblock-ip code{color:#fff;background:transparent}.spamblock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.spamblock-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.04)}.spamblock-card h2{margin:0 0 16px;font-size:17px}.spamblock-field{margin:0 0 15px}.spamblock-field:last-child{margin-bottom:0}.spamblock-field>label:first-child{display:block;margin-bottom:6px;font-weight:600}.spamblock-field input[type=text],.spamblock-field input[type=number],.spamblock-field textarea,.spamblock-field select{width:100%;max-width:none}.spamblock-field textarea{min-height:92px;resize:vertical}.spamblock-switch{display:flex;align-items:center;gap:8px}.spamblock-help{margin:5px 0 0;color:#646970;font-size:12px}.spamblock-full{grid-column:1/-1}.spamblock-stats{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px}.spamblock-stat{padding:9px 12px;background:#f0f6fc;border-radius:8px}.spamblock-table-wrap{overflow:auto;max-height:520px;border:1px solid #dcdcde;border-radius:8px}.spamblock-table-wrap table{border:0}.spamblock-table-wrap thead th{position:sticky;top:0;background:#f6f7f7;z-index:1}.spamblock-actions{position:sticky;bottom:0;margin-top:16px;padding:12px 16px;background:rgba(240,240,241,.94);border:1px solid #dcdcde;border-radius:10px;backdrop-filter:blur(8px)}.spamblock-actions p{margin:0;padding:0}@media(max-width:782px){.spamblock-grid{grid-template-columns:1fr}.spamblock-full{grid-column:auto}.spamblock-hero{align-items:flex-start;flex-direction:column}.spamblock-ip{width:calc(100% - 28px)}}
	</style>
	<div class="wrap spamblock-wrap">
		<div class="spamblock-hero">
			<div><h1>Блокировка трафика</h1><p>Защита по странам, частоте запросов и User-Agent.</p></div>
			<div class="spamblock-ip"><strong>Ваш IP</strong><br><code><?php echo esc_html( $current_ip ? $current_ip : 'не определён' ); ?></code></div>
		</div>
		<form method="post" action="options.php">
			<?php settings_fields( 'spamblock' ); ?>
			<div class="spamblock-grid">
				<section class="spamblock-card">
					<h2>Основная защита</h2>
					<div class="spamblock-field"><label class="spamblock-switch"><input type="checkbox" name="spamblock_options[enabled]" value="1" <?php checked( $options['enabled'], '1' ); ?>> Плагин включён</label></div>
					<div class="spamblock-field"><label for="spamblock-mode">Режим геоблокировки</label><select id="spamblock-mode" name="spamblock_options[mode]"><option value="monitor" <?php selected( $options['mode'], 'monitor' ); ?>>Наблюдение — только журнал</option><option value="block" <?php selected( $options['mode'], 'block' ); ?>>Блокировка</option></select></div>
					<div class="spamblock-field"><label for="spamblock-countries">Разрешённые страны</label><input type="text" id="spamblock-countries" name="spamblock_options[allowed_countries]" value="<?php echo esc_attr( $options['allowed_countries'] ); ?>"><p class="spamblock-help">Коды через запятую: RU, BY, KZ.</p></div>
					<div class="spamblock-field"><label for="spamblock-message">Сообщение при блокировке</label><input type="text" id="spamblock-message" name="spamblock_options[message]" value="<?php echo esc_attr( $options['message'] ); ?>"></div>
					<div class="spamblock-field"><label for="spamblock-rate">Запросов с IP в минуту</label><input id="spamblock-rate" type="number" min="0" max="10000" name="spamblock_options[rate_limit]" value="<?php echo esc_attr( $options['rate_limit'] ); ?>"><p class="spamblock-help">0 отключает ограничение.</p></div>
				</section>

				<section class="spamblock-card">
					<h2>Фильтр User-Agent</h2>
					<div class="spamblock-field"><label class="spamblock-switch"><input type="checkbox" name="spamblock_options[bot_filter]" value="1" <?php checked( $options['bot_filter'], '1' ); ?>> Проверять ключевые слова</label><p class="spamblock-help">Подтверждённые роботы Google и Яндекса всегда пропускаются.</p></div>
					<div class="spamblock-field"><label for="spamblock-bot-mode">Действие при совпадении</label><select id="spamblock-bot-mode" name="spamblock_options[bot_mode]"><option value="monitor" <?php selected( $options['bot_mode'], 'monitor' ); ?>>Записать в журнал</option><option value="block" <?php selected( $options['bot_mode'], 'block' ); ?>>Заблокировать</option></select></div>
					<div class="spamblock-field"><label for="spamblock-keywords">Ключевые слова</label><textarea id="spamblock-keywords" class="code" rows="10" name="spamblock_options[bot_keywords]"><?php echo esc_textarea( $options['bot_keywords'] ); ?></textarea><p class="spamblock-help">По одному слову или фрагменту на строку, без учёта регистра.</p></div>
				</section>

				<section class="spamblock-card">
					<h2>Белые списки</h2>
					<div class="spamblock-field"><label for="spamblock-ips">IP-адреса</label><textarea id="spamblock-ips" class="code" rows="5" name="spamblock_options[whitelist_ips]"><?php echo esc_textarea( $options['whitelist_ips'] ); ?></textarea><p class="spamblock-help">По одному IPv4 или IPv6 на строку.</p></div>
					<div class="spamblock-field"><label for="spamblock-paths">Пути URL</label><textarea id="spamblock-paths" class="code" rows="5" name="spamblock_options[whitelist_paths]"><?php echo esc_textarea( $options['whitelist_paths'] ); ?></textarea><p class="spamblock-help">Префиксы путей по одному на строку.</p></div>
				</section>

				<section class="spamblock-card">
					<h2>Журнал</h2>
					<div class="spamblock-field"><label class="spamblock-switch"><input type="checkbox" name="spamblock_options[logging]" value="1" <?php checked( $options['logging'], '1' ); ?>> Сохранять события</label><p class="spamblock-help">В базе остаются последние 100 событий с полными IP.</p></div>
					<div class="spamblock-field"><label for="spamblock-log-display">Показывать строк на странице</label><select id="spamblock-log-display" name="spamblock_options[log_display]"><?php foreach ( array( 10, 20, 50 ) as $amount ) : ?><option value="<?php echo esc_attr( $amount ); ?>" <?php selected( (int) $options['log_display'], $amount ); ?>><?php echo esc_html( $amount ); ?></option><?php endforeach; ?></select></div>
					<p class="spamblock-help">IP-адрес может считаться персональными данными. Учитывайте вашу политику конфиденциальности.</p>
				</section>
