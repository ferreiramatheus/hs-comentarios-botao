<?php
/**
 * Plugin Name: Hiperstorm - Botão de Comentários v2
 * Plugin URI: https://hiperstorm.com.br/
 * Description: Cria um shortcode para botão de comentários com modal AJAX no desktop e fallback para página dedicada no mobile.
 * Version: 2.0.0
 * Author: Hiperstorm
 * Author URI: https://hiperstorm.com.br/
 * License: GPL2+
 * Text Domain: hs-comentarios-botao
 */

if (!defined('ABSPATH')) {
	exit;
}

final class HS_Comentarios_Botao_V2 {

	private const COMMENT_NONCE_ACTION = 'hs_comentarios_comment_form_action';
	private const COMMENT_NONCE_FIELD = 'hs_comentarios_comment_nonce';
	private const COMMENT_HONEYPOT_FIELD = 'hp_field';

	private $shortcode_usado = false;

	public function __construct() {
		add_shortcode('hs_comentarios_botao', [$this, 'render_shortcode']);

		add_action('wp_enqueue_scripts', [$this, 'registrar_assets']);
		add_action('wp_footer', [$this, 'render_modal_markup']);

		add_action('wp_ajax_hs_carregar_comentarios', [$this, 'ajax_carregar_comentarios']);
		add_action('wp_ajax_nopriv_hs_carregar_comentarios', [$this, 'ajax_carregar_comentarios']);

		add_filter('comment_form_submit_field', [$this, 'inject_turnstile_before_submit'], 10, 2);
		add_action('comment_form', [$this, 'render_campos_antispam']);
		add_action('pre_comment_on_post', [$this, 'validar_nonce_comentario']);
		add_filter('preprocess_comment', [$this, 'validar_honeypot_no_comentario'], 5);
		add_filter('preprocess_comment', [$this, 'validar_turnstile_no_comentario']);

		add_filter('query_vars', [$this, 'registrar_query_vars']);
		add_action('template_redirect', [$this, 'rota_pagina_comentarios']);
	}

	public function registrar_assets() {
		$base_url = plugin_dir_url(__FILE__);
		$base_path = plugin_dir_path(__FILE__);
		$site_key = $this->get_turnstile_site_key();
		$css_file = $base_path . 'assets/hs-comentarios-botao.css';
		$js_file = $base_path . 'assets/hs-comentarios-botao.js';
		$css_version = file_exists($css_file) ? (string) filemtime($css_file) : '2.0.0';
		$js_version = file_exists($js_file) ? (string) filemtime($js_file) : '2.2.0';

		wp_register_style(
			'hs-comentarios-botao',
			$base_url . 'assets/hs-comentarios-botao.css',
			[],
			$css_version
		);

		wp_register_script(
			'hs-comentarios-botao',
			$base_url . 'assets/hs-comentarios-botao.js',
			[],
			$js_version,
			true
		);

		wp_register_script(
			'hs-comentarios-turnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
			[],
			null,
			true
		);

		wp_localize_script('hs-comentarios-botao', 'hsComentariosBotao', [
			'ajaxUrl'         => admin_url('admin-ajax.php'),
			'nonceCarregar'   => wp_create_nonce('hs_carregar_comentarios'),
			'modoPadrao'      => 'modal_desktop_page_mobile',
			'mobileBreakpoint'=> 768,
			'fecharLabel'     => __('Fechar comentários', 'hs-comentarios-botao'),
			'erro'            => __('Não foi possível carregar os comentários.', 'hs-comentarios-botao'),
			'carregando'      => __('Carregando comentários...', 'hs-comentarios-botao'),
			'enviando'        => __('Enviando comentário...', 'hs-comentarios-botao'),
			'erroEnvio'       => __('Não foi possível enviar o comentário. Verifique os campos e tente novamente.', 'hs-comentarios-botao'),
			'turnstileSiteKey'=> $site_key,
			'turnstileAtivo'  => !empty($site_key),
			'turnstileObrigatorio' => __('Confirme o captcha antes de enviar o comentário.', 'hs-comentarios-botao'),
			'turnstileErroCarregamento' => __('Não foi possível carregar o captcha agora. Verifique a configuração das chaves Turnstile.', 'hs-comentarios-botao'),
			'tituloComentarios' => __('Comentários', 'hs-comentarios-botao'),
			'tituloComentarioSingular' => __('%s Comentário', 'hs-comentarios-botao'),
			'tituloComentarioPlural' => __('%s Comentários', 'hs-comentarios-botao'),
		]);
	}

	public function registrar_query_vars($vars) {
		$vars[] = 'hs_comentarios_page';
		$vars[] = 'post_id';
		return $vars;
	}

	public function render_shortcode($atts = []) {
		if (!is_singular('post')) {
			return '';
		}

		global $post;

		if (!$post instanceof WP_Post) {
			return '';
		}

		$comments_open   = comments_open($post->ID);
		$comments_number = get_comments_number($post->ID);

		if (!$comments_open && (int) $comments_number === 0) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'texto'              => '',
				'mostrar_quantidade' => 'yes',
				'class'              => '',
				'cor_fundo'          => '',
				'cor_texto'          => '',
				'alinhar'            => 'left',
				'width'              => 'block',
				'margin'             => '',
				'modo'               => 'modal_desktop_page_mobile',
				'pagina_url'         => '',
			],
			$atts,
			'hs_comentarios_botao'
		);

		$mostrar_quantidade = strtolower((string) $atts['mostrar_quantidade']) === 'yes';
		$label = $this->build_shortcode_label($atts['texto'], $mostrar_quantidade, (int) $comments_number);

		$alinhar = $this->sanitize_shortcode_attr_value($atts['alinhar'], ['left', 'center', 'right'], 'left');
		$width = $this->sanitize_shortcode_attr_value($atts['width'], ['block', 'full'], 'block');
		$modo = $this->sanitize_shortcode_attr_value($atts['modo'], ['modal', 'page', 'modal_desktop_page_mobile'], 'modal_desktop_page_mobile');

		$comments_url = $this->get_comments_page_url($post->ID, $atts['pagina_url']);

		$classes = ['hs-comentarios-botao'];
		if (!empty($atts['class'])) {
			$custom_classes = preg_split('/\s+/', (string) $atts['class']);
			if (is_array($custom_classes)) {
				foreach ($custom_classes as $custom_class) {
					$sanitized = sanitize_html_class($custom_class);
					if (!empty($sanitized)) {
						$classes[] = $sanitized;
					}
				}
			}
		}

		$style_wrap_parts = ['text-align:' . esc_attr($alinhar)];
		$classes[] = 'hs-comentarios-botao--width-' . $width;
		$wrapper_classes = ['hs-comentarios-botao-wrap', 'hs-comentarios-botao-wrap--width-' . $width];
		$margin = $this->sanitize_margin_value($atts['margin']);

		if (null !== $margin) {
			$style_wrap_parts[] = 'margin:' . $margin . 'px';
		}

		$style_wrap = implode(';', $style_wrap_parts) . ';';
		$inline_styles = [];

		if (!empty($atts['cor_fundo'])) {
			$cor_fundo = $this->sanitize_color_value($atts['cor_fundo']);
			if ($cor_fundo) {
				$inline_styles[] = 'background:' . $cor_fundo;
			}
		}

		if (!empty($atts['cor_texto'])) {
			$cor_texto = $this->sanitize_color_value($atts['cor_texto']);
			if ($cor_texto) {
				$inline_styles[] = 'color:' . $cor_texto;
			}
		}

		$style_btn = !empty($inline_styles) ? implode(';', $inline_styles) . ';' : '';

		$this->shortcode_usado = true;
		wp_enqueue_style('hs-comentarios-botao');
		wp_enqueue_script('hs-comentarios-botao');
		if ($this->is_turnstile_enabled()) {
			wp_enqueue_script('hs-comentarios-turnstile');
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>" style="<?php echo esc_attr($style_wrap); ?>">
			<button
				type="button"
				class="<?php echo esc_attr(implode(' ', $classes)); ?>"
				style="<?php echo esc_attr($style_btn); ?>"
				aria-label="<?php echo esc_attr($label); ?>"
				data-post-id="<?php echo esc_attr($post->ID); ?>"
				data-modo="<?php echo esc_attr($modo); ?>"
				data-comments-url="<?php echo esc_url($comments_url); ?>"
			>
				<span class="hs-comentarios-botao__icon" aria-hidden="true">
					<svg viewBox="0 0 248.32 248.32" focusable="false">
						<path d="M175.86,228.15l68.96,15.05-15.01-68.96c7.7-15.61,11.99-33.16,11.99-51.72,0-64.97-52.69-117.66-117.66-117.66-64.97,0-117.62,52.69-117.62,117.66,0,64.93,52.64,117.62,117.62,117.62,18.56,0,36.11-4.29,51.72-11.99Z" fill="currentColor"/>
					</svg>
				</span>
				<span class="hs-comentarios-botao__label"><?php echo esc_html($label); ?></span>
			</button>
		</div>
		<?php
		return ob_get_clean();
	}

	private function sanitize_shortcode_attr_value($value, $allowed_values, $default) {
		return in_array($value, $allowed_values, true)
			? $value
			: $default;
	}

	private function build_shortcode_label($custom_text, $show_count, $comments_number) {
		if (!empty($custom_text)) {
			return (string) $custom_text;
		}

		if (!$show_count) {
			return 'Comentários';
		}

		if ($comments_number <= 0) {
			return 'Comentários';
		}

		if (1 === $comments_number) {
			return '1 comentário';
		}

		return number_format_i18n($comments_number) . ' comentários';
	}

	private function get_comments_page_url($post_id, $pagina_url = '') {
		if (!empty($pagina_url)) {
			return add_query_arg(
				[
					'post_id' => (int) $post_id,
				],
				esc_url_raw($pagina_url)
			);
		}

		return add_query_arg(
			[
				'hs_comentarios_page' => 1,
				'post_id'             => (int) $post_id,
			],
			home_url('/')
		);
	}

	public function render_modal_markup() {
		if (!$this->shortcode_usado) {
			return;
		}
		?>
		<div id="hs-comentarios-modal" class="hs-comentarios-modal" hidden>
			<div class="hs-comentarios-modal__overlay" data-hs-close="1"></div>

			<div class="hs-comentarios-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="hs-comentarios-modal-title">
				<div class="hs-comentarios-modal__body">
					<button type="button" class="hs-comentarios-modal__close" data-hs-close="1" aria-label="<?php echo esc_attr__('Fechar comentários', 'hs-comentarios-botao'); ?>">
						&times;
					</button>

					<h2 id="hs-comentarios-modal-title" class="hs-comentarios-modal__title">
						<?php esc_html_e('Comentários', 'hs-comentarios-botao'); ?>
					</h2>

					<div id="hs-comentarios-container" class="hs-comentarios-container">
						<p class="hs-comentarios-loading"><?php esc_html_e('Carregando comentários...', 'hs-comentarios-botao'); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_carregar_comentarios() {
		$nonce_ok = check_ajax_referer('hs_carregar_comentarios', 'nonce', false);
		if (!$nonce_ok) {
			wp_send_json_error(['message' => 'Requisição inválida.'], 403);
		}

		$post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
		$cpage = isset($_GET['cpage']) ? max(1, absint($_GET['cpage'])) : 1;

		if (!$post_id) {
			wp_send_json_error(['message' => 'Post inválido.'], 400);
		}

		$post_obj = get_post($post_id);

		if (!$post_obj || 'post' !== $post_obj->post_type || 'publish' !== $post_obj->post_status) {
			wp_send_json_error(['message' => 'Post não encontrado.'], 404);
		}

		if (!comments_open($post_id) && (int) get_comments_number($post_id) === 0) {
			wp_send_json_error(['message' => 'Comentários indisponíveis.'], 404);
		}

		global $post;
		$post = $post_obj;
		setup_postdata($post);

		ob_start();

		echo '<div class="hs-comentarios-ajax-inner" data-post-id="' . esc_attr($post_id) . '">';

		echo $this->get_comments_list_html($post_id, $cpage, true);

		if (comments_open($post_id)) {
			comment_form([
				'title_reply'        => __('Deixe um comentário', 'hs-comentarios-botao'),
				'title_reply_before' => '<h3 id="reply-title" class="comment-reply-title">',
				'title_reply_after'  => '</h3>',
				'fields'             => $this->get_comment_form_fields_without_url(),
			], $post_id);
		}

		echo '</div>';

		wp_reset_postdata();

		$html = ob_get_clean();
		$comments_number = (int) get_comments_number($post_id);
		$modal_title = $comments_number > 0
			? sprintf(
				_n('%s Comentário', '%s Comentários', $comments_number, 'hs-comentarios-botao'),
				number_format_i18n($comments_number)
			)
			: __('Comentários', 'hs-comentarios-botao');

		wp_send_json_success([
			'html' => $html,
			'modalTitle' => $modal_title,
		]);
	}

	public function rota_pagina_comentarios() {
		if (!(int) get_query_var('hs_comentarios_page')) {
			return;
		}

		$post_id = absint(get_query_var('post_id'));

		if (!$post_id) {
			wp_die('Post inválido.', 'Comentários', ['response' => 400]);
		}

		$post = get_post($post_id);

		if (!$post || 'post' !== $post->post_type || 'publish' !== $post->post_status) {
			wp_die('Post não encontrado.', 'Comentários', ['response' => 404]);
		}

		status_header(200);
		nocache_headers();

		$this->render_comments_page($post);
		exit;
	}

	private function render_comments_page($post_obj) {
		$title = 'Comentários - ' . get_the_title($post_obj);
		$post_url = get_permalink($post_obj->ID);
		$request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
		$current_url_raw = home_url($request_uri);
		$current_comments_page_url = wp_validate_redirect($current_url_raw, home_url('/'));
		$cpage = isset($_GET['cpage']) ? max(1, absint($_GET['cpage'])) : 1;

		wp_enqueue_script('hs-comentarios-botao');
		if ($this->is_turnstile_enabled()) {
			wp_enqueue_script('hs-comentarios-turnstile');
		}

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo('charset'); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html($title); ?></title>
			<?php wp_head(); ?>
			<style>
				body.hs-comentarios-page {
					margin: 0;
					padding: 0;
					background: #f5f5f5;
				}
				.hs-comentarios-page-wrap {
					max-width: 860px;
					margin: 0 auto;
					padding: 24px 16px 48px;
				}
				.hs-comentarios-page-card {
					background: #fff;
					border-radius: 10px;
					padding: 24px;
					box-shadow: 0 2px 12px rgba(0,0,0,.06);
				}
				.hs-comentarios-page-topo {
					margin-bottom: 24px;
				}
				.hs-comentarios-page-topo a {
					text-decoration: none;
				}
				.hs-comentarios-page-topo h1 {
					margin: 8px 0 0;
					font-size: 28px;
					line-height: 1.2;
				}
			</style>
		</head>
		<body <?php body_class('hs-comentarios-page'); ?>>
			<div class="hs-comentarios-page-wrap">
				<div class="hs-comentarios-page-card">
					<div class="hs-comentarios-page-topo">
						<div>
							<a href="<?php echo esc_url($post_url); ?>">← Voltar para o post</a>
						</div>
						<h1><?php echo esc_html(get_the_title($post_obj)); ?></h1>
					</div>

					<?php
					global $post;
					$previous_post = $post;
					$post = $post_obj;
					setup_postdata($post);

						echo $this->get_comments_list_html($post_obj->ID, $cpage, false);

					if (comments_open($post_obj->ID)) {
						comment_form([
							'title_reply'        => __('Deixe um comentário', 'hs-comentarios-botao'),
							'title_reply_before' => '<h2 id="reply-title" class="comment-reply-title">',
							'title_reply_after'  => '</h2>',
							'fields'             => $this->get_comment_form_fields_without_url(),
							'submit_field'       => '<input type="hidden" name="redirect_to" value="' . esc_attr($current_comments_page_url) . '" />%1$s %2$s',
						], $post_obj->ID);
					}

					wp_reset_postdata();
					$post = $previous_post;
					?>
				</div>
			</div>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}

	private function get_comments_list_html($post_id, $cpage = 1, $is_ajax = false) {
		$per_page = (int) get_option('comments_per_page');
		if ($per_page < 1) {
			$per_page = 20;
		}

		$total_comments = get_comments_number($post_id);
		$total_pages = max(1, (int) ceil($total_comments / $per_page));
		$cpage = min(max(1, (int) $cpage), $total_pages);
		$include_unapproved = $this->get_include_unapproved_for_current_visitor();
		$allow_unapproved_for_visitor = !empty($include_unapproved);

		$post_obj = get_post($post_id);
		$modified = $post_obj ? strtotime((string) $post_obj->post_modified_gmt) : 0;
		$cache_key_raw = $post_id . '|' . $cpage . '|' . $per_page . '|' . $total_comments . '|' . $modified;
		$cache_key = 'hs_cb_comments_' . md5($cache_key_raw);

		$list_html = $allow_unapproved_for_visitor ? false : get_transient($cache_key);
		if (false === $list_html) {
			$offset = ($cpage - 1) * $per_page;
			$query_args = [
				'post_id' => $post_id,
				'number'  => $per_page,
				'offset'  => $offset,
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			];

			if ($allow_unapproved_for_visitor) {
				$query_args['status'] = 'all';
				$query_args['include_unapproved'] = $include_unapproved;
			} else {
				$query_args['status'] = 'approve';
			}

			$comments = get_comments($query_args);

			ob_start();
			if (!empty($comments)) {
				echo '<div class="hs-comentarios-lista">';
				wp_list_comments([
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 48,
				], $comments);
				echo '</div>';
			} else {
				echo '<p>Ainda não há comentários.</p>';
			}
			$list_html = ob_get_clean();
			if (!$allow_unapproved_for_visitor) {
				set_transient($cache_key, $list_html, MINUTE_IN_SECONDS * 5);
			}
		}

		ob_start();
		echo $list_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ($total_pages > 1) {
			echo '<nav class="hs-comentarios-paginacao" aria-label="Paginação dos comentários">';
			if ($cpage > 1) {
				$prev_page = $cpage - 1;
				if ($is_ajax) {
					echo '<button type="button" class="hs-comentarios-page-link" data-cpage="' . esc_attr($prev_page) . '">← Comentários anteriores</button>';
				} else {
					$prev_url = add_query_arg('cpage', $prev_page);
					echo '<a class="hs-comentarios-page-link" href="' . esc_url($prev_url) . '">← Comentários anteriores</a>';
				}
			}

			if ($cpage < $total_pages) {
				$next_page = $cpage + 1;
				if ($is_ajax) {
					echo '<button type="button" class="hs-comentarios-page-link" data-cpage="' . esc_attr($next_page) . '">Próximos comentários →</button>';
				} else {
					$next_url = add_query_arg('cpage', $next_page);
					echo '<a class="hs-comentarios-page-link" href="' . esc_url($next_url) . '">Próximos comentários →</a>';
				}
			}
			echo '</nav>';
		}

		return ob_get_clean();
	}

	private function get_include_unapproved_for_current_visitor() {
		$include_unapproved = [];
		$current_user_id = get_current_user_id();

		if ($current_user_id > 0) {
			$include_unapproved[] = $current_user_id;
		}

		$commenter = wp_get_current_commenter();
		$comment_author_email = isset($commenter['comment_author_email']) ? sanitize_email((string) $commenter['comment_author_email']) : '';
		if (!empty($comment_author_email)) {
			$include_unapproved[] = $comment_author_email;
		}

		return array_values(array_unique($include_unapproved));
	}

	private function get_comment_form_fields_without_url() {
		$commenter = wp_get_current_commenter();
		$req = get_option('require_name_email');
		$required_text = $req ? ' required' : '';
		$aria_required = $req ? ' aria-required="true"' : '';

		$author_value = isset($commenter['comment_author']) ? (string) $commenter['comment_author'] : '';
		$email_value = isset($commenter['comment_author_email']) ? (string) $commenter['comment_author_email'] : '';

		return [
			'author' => '<p class="comment-form-author"><label for="author">' . __('Nome') . ($req ? ' *' : '') . '</label> <input id="author" name="author" type="text" value="' . esc_attr($author_value) . '" size="30" maxlength="245" autocomplete="name"' . $aria_required . $required_text . ' /></p>',
			'email'  => '<p class="comment-form-email"><label for="email">' . __('E-mail') . ($req ? ' *' : '') . '</label> <input id="email" name="email" type="email" value="' . esc_attr($email_value) . '" size="30" maxlength="100" autocomplete="email"' . $aria_required . $required_text . ' /></p>',
		];
	}

	private function sanitize_color_value($value) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		$hex = sanitize_hex_color($value);
		if (!empty($hex)) {
			return $hex;
		}

		$is_rgb = preg_match('/^rgba?\(\s*(?:25[0-5]|2[0-4]\d|1?\d?\d)\s*,\s*(?:25[0-5]|2[0-4]\d|1?\d?\d)\s*,\s*(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/', $value);
		if ($is_rgb) {
			return $value;
		}

		$is_hsl = preg_match('/^hsla?\(\s*(?:[12]?\d?\d|3[0-5]\d|360)\s*,\s*(?:100|[1-9]?\d)%\s*,\s*(?:100|[1-9]?\d)%(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/', $value);
		if ($is_hsl) {
			return $value;
		}

		return '';
	}

	private function sanitize_margin_value($margin) {
		if ($margin === '' || $margin === null) {
			return null;
		}

		if (!is_scalar($margin)) {
			return null;
		}

		$margin = trim((string) $margin);
		if (!preg_match('/^\d+$/', $margin)) {
			return null;
		}

		return (int) $margin;
	}

	private function get_turnstile_site_key() {
		$site_key = defined('HS_COMENTARIOS_TURNSTILE_SITE_KEY') ? HS_COMENTARIOS_TURNSTILE_SITE_KEY : '';
		if (empty($site_key)) {
			$site_key = getenv('HS_COMENTARIOS_TURNSTILE_SITE_KEY');
		}

		/**
		 * Permite sobrescrever a site key do Turnstile.
		 */
		$site_key = apply_filters('hs_comentarios_turnstile_site_key', $site_key);

		return is_string($site_key) ? trim($site_key) : '';
	}

	private function get_turnstile_secret_key() {
		$secret_key = defined('HS_COMENTARIOS_TURNSTILE_SECRET_KEY') ? HS_COMENTARIOS_TURNSTILE_SECRET_KEY : '';
		if (empty($secret_key)) {
			$secret_key = getenv('HS_COMENTARIOS_TURNSTILE_SECRET_KEY');
		}

		/**
		 * Permite sobrescrever a secret key do Turnstile.
		 */
		$secret_key = apply_filters('hs_comentarios_turnstile_secret_key', $secret_key);

		return is_string($secret_key) ? trim($secret_key) : '';
	}

	private function is_turnstile_enabled() {
		return !empty($this->get_turnstile_site_key());
	}

	public function render_turnstile_field() {
		$site_key = $this->get_turnstile_site_key();
		if (empty($site_key)) {
			return;
		}

		echo '<input type="hidden" name="hs_turnstile_enabled" value="1" />';
		echo '<input type="hidden" name="hs_turnstile_token" value="" />';
		echo '<div class="hs-turnstile-widget" data-sitekey="' . esc_attr($site_key) . '"></div>';
	}

	public function inject_turnstile_before_submit($submit_field, $args) {
		$site_key = $this->get_turnstile_site_key();
		if (empty($site_key)) {
			return $submit_field;
		}

		ob_start();
		echo '<div class="hs-turnstile-field-wrap">';
		$this->render_turnstile_field();
		echo '</div>';
		$turnstile_html = ob_get_clean();

		return $turnstile_html . $submit_field;
	}

	public function render_campos_antispam() {
		wp_nonce_field(self::COMMENT_NONCE_ACTION, self::COMMENT_NONCE_FIELD);
		echo '<p class="comment-form-hp-field" style="display:none !important;">';
		echo '<label for="' . esc_attr(self::COMMENT_HONEYPOT_FIELD) . '">' . esc_html__('Não preencha este campo.', 'hs-comentarios-botao') . '</label>';
		echo '<input id="' . esc_attr(self::COMMENT_HONEYPOT_FIELD) . '" name="' . esc_attr(self::COMMENT_HONEYPOT_FIELD) . '" type="text" value="" tabindex="-1" autocomplete="off" />';
		echo '</p>';
	}

	public function validar_nonce_comentario($post_id) {
		unset($post_id);
		$nonce_raw = isset($_POST[self::COMMENT_NONCE_FIELD]) ? wp_unslash($_POST[self::COMMENT_NONCE_FIELD]) : '';
		$nonce = is_string($nonce_raw) ? sanitize_text_field($nonce_raw) : '';
		$nonce_valido = !empty($nonce) && wp_verify_nonce($nonce, self::COMMENT_NONCE_ACTION);

		if (!$nonce_valido) {
			wp_die(
				esc_html__('Falha de segurança ao enviar o comentário. Recarregue a página e tente novamente.', 'hs-comentarios-botao'),
				esc_html__('Comentário bloqueado', 'hs-comentarios-botao'),
				['response' => 403]
			);
		}
	}

	public function validar_honeypot_no_comentario($commentdata) {
		$honeypot_raw = isset($_POST[self::COMMENT_HONEYPOT_FIELD]) ? wp_unslash($_POST[self::COMMENT_HONEYPOT_FIELD]) : '';
		$honeypot = is_string($honeypot_raw) ? trim(sanitize_text_field($honeypot_raw)) : '';

		if ('' !== $honeypot) {
			wp_die(
				esc_html__('Comentário bloqueado por suspeita de spam.', 'hs-comentarios-botao'),
				esc_html__('Comentário bloqueado', 'hs-comentarios-botao'),
				['response' => 400]
			);
		}

		return $commentdata;
	}

	public function validar_turnstile_no_comentario($commentdata) {
		$site_key = $this->get_turnstile_site_key();
		$secret_key = $this->get_turnstile_secret_key();
		$turnstile_configurado = !empty($site_key) || !empty($secret_key);
		if (!$turnstile_configurado) {
			return $commentdata;
		}

		if (empty($site_key) || empty($secret_key)) {
			wp_die(
				esc_html__('O captcha de comentários não está configurado.', 'hs-comentarios-botao'),
				esc_html__('Erro de validação', 'hs-comentarios-botao'),
				['response' => 400]
			);
		}

		$token = '';
		if (isset($_POST['cf-turnstile-response'])) {
			$token = sanitize_text_field((string) wp_unslash($_POST['cf-turnstile-response']));
		}

		if (empty($token) && isset($_POST['hs_turnstile_token'])) {
			$token = sanitize_text_field((string) wp_unslash($_POST['hs_turnstile_token']));
		}

		if (empty($token)) {
			wp_die(
				esc_html__('Confirme o captcha para enviar seu comentário.', 'hs-comentarios-botao'),
				esc_html__('Captcha obrigatório', 'hs-comentarios-botao'),
				['response' => 400]
			);
		}

		$remote_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		$verify_response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
			'timeout' => 10,
			'body'    => [
				'secret'   => $secret_key,
				'response' => $token,
				'remoteip' => $remote_ip,
			],
		]);

		if (is_wp_error($verify_response)) {
			wp_die(
				esc_html__('Não foi possível validar o captcha agora. Tente novamente.', 'hs-comentarios-botao'),
				esc_html__('Erro de validação', 'hs-comentarios-botao'),
				['response' => 400]
			);
		}

		$body = json_decode(wp_remote_retrieve_body($verify_response), true);
		$success = is_array($body) && !empty($body['success']);

		if (!$success) {
			wp_die(
				esc_html__('Captcha inválido ou expirado. Atualize a página e tente novamente.', 'hs-comentarios-botao'),
				esc_html__('Captcha inválido', 'hs-comentarios-botao'),
				['response' => 400]
			);
		}

		return $commentdata;
	}
}

new HS_Comentarios_Botao_V2();
