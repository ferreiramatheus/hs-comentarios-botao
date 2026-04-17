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

	private $shortcode_usado = false;

	public function __construct() {
		add_shortcode('hs_comentarios_botao', [$this, 'render_shortcode']);

		add_action('wp_enqueue_scripts', [$this, 'registrar_assets']);
		add_action('wp_footer', [$this, 'render_modal_markup']);

		add_action('wp_ajax_hs_carregar_comentarios', [$this, 'ajax_carregar_comentarios']);
		add_action('wp_ajax_nopriv_hs_carregar_comentarios', [$this, 'ajax_carregar_comentarios']);

		add_action('comment_form_after_fields', [$this, 'render_turnstile_field']);
		add_action('comment_form_logged_in_after', [$this, 'render_turnstile_field']);
		add_filter('preprocess_comment', [$this, 'validar_turnstile_no_comentario']);

		add_filter('query_vars', [$this, 'registrar_query_vars']);
		add_action('template_redirect', [$this, 'rota_pagina_comentarios']);
	}

	public function registrar_assets() {
		$base_url = plugin_dir_url(__FILE__);
		$site_key = $this->get_turnstile_site_key();

		wp_register_style(
			'hs-comentarios-botao',
			$base_url . 'assets/hs-comentarios-botao.css',
			[],
			'2.0.0'
		);

		wp_register_script(
			'hs-comentarios-botao',
			$base_url . 'assets/hs-comentarios-botao.js',
			[],
			'2.2.0',
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
				'modo'               => 'modal_desktop_page_mobile',
				'pagina_url'         => '',
			],
			$atts,
			'hs_comentarios_botao'
		);

		$mostrar_quantidade = strtolower((string) $atts['mostrar_quantidade']) === 'yes';

		if (!empty($atts['texto'])) {
			$label = $atts['texto'];
		} else {
			if ($mostrar_quantidade) {
				if ((int) $comments_number === 0) {
					$label = 'Comentários';
				} elseif ((int) $comments_number === 1) {
					$label = '1 comentário';
				} else {
					$label = number_format_i18n($comments_number) . ' comentários';
				}
			} else {
				$label = 'Comentários';
			}
		}

		$alinhar = in_array($atts['alinhar'], ['left', 'center', 'right'], true)
			? $atts['alinhar']
			: 'left';

		$modo = in_array($atts['modo'], ['modal', 'page', 'modal_desktop_page_mobile'], true)
			? $atts['modo']
			: 'modal_desktop_page_mobile';

		$comments_url = $this->get_comments_page_url($post->ID, $atts['pagina_url']);

		$classes = ['hs-comentarios-botao'];
		if (!empty($atts['class'])) {
			$classes[] = sanitize_html_class($atts['class']);
		}

		$style_wrap = 'text-align:' . esc_attr($alinhar) . ';';
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
		if (!empty($this->get_turnstile_site_key())) {
			wp_enqueue_script('hs-comentarios-turnstile');
		}

		ob_start();
		?>
		<div class="hs-comentarios-botao-wrap" style="<?php echo esc_attr($style_wrap); ?>">
			<button
				type="button"
				class="<?php echo esc_attr(implode(' ', $classes)); ?>"
				style="<?php echo esc_attr($style_btn); ?>"
				aria-label="<?php echo esc_attr($label); ?>"
				data-post-id="<?php echo esc_attr($post->ID); ?>"
				data-modo="<?php echo esc_attr($modo); ?>"
				data-comments-url="<?php echo esc_url($comments_url); ?>"
			>
				<?php echo esc_html($label); ?>
			</button>
		</div>
		<?php
		return ob_get_clean();
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

		echo '<div class="hs-comentarios-ajax-inner">';

		echo $this->get_comments_list_html($post_id, $cpage, true);

		if (comments_open($post_id)) {
			comment_form([
				'title_reply'        => __('Deixe um comentário', 'hs-comentarios-botao'),
				'title_reply_before' => '<h3 id="reply-title" class="comment-reply-title">',
				'title_reply_after'  => '</h3>',
			], $post_id);
		}

		echo '</div>';

		wp_reset_postdata();

		$html = ob_get_clean();

		wp_send_json_success([
			'html' => $html,
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
		if (!empty($this->get_turnstile_site_key())) {
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

		$post_obj = get_post($post_id);
		$modified = $post_obj ? strtotime((string) $post_obj->post_modified_gmt) : 0;
		$cache_key_raw = $post_id . '|' . $cpage . '|' . $per_page . '|' . $total_comments . '|' . $modified;
		$cache_key = 'hs_cb_comments_' . md5($cache_key_raw);

		$list_html = get_transient($cache_key);
		if (false === $list_html) {
			$offset = ($cpage - 1) * $per_page;
			$comments = get_comments([
				'post_id' => $post_id,
				'status'  => 'approve',
				'number'  => $per_page,
				'offset'  => $offset,
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			]);

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
			set_transient($cache_key, $list_html, MINUTE_IN_SECONDS * 5);
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

	public function render_turnstile_field() {
		$site_key = $this->get_turnstile_site_key();
		if (empty($site_key)) {
			return;
		}

		echo '<input type="hidden" name="hs_turnstile_enabled" value="1" />';
		echo '<input type="hidden" name="hs_turnstile_token" value="" />';
		echo '<div class="hs-turnstile-widget" data-sitekey="' . esc_attr($site_key) . '"></div>';
	}

	public function validar_turnstile_no_comentario($commentdata) {
		$turnstile_ativo = isset($_POST['hs_turnstile_enabled']) && '1' === (string) wp_unslash($_POST['hs_turnstile_enabled']);
		if (!$turnstile_ativo) {
			return $commentdata;
		}

		$site_key = $this->get_turnstile_site_key();
		$secret_key = $this->get_turnstile_secret_key();
		if (empty($site_key) || empty($secret_key)) {
			wp_die(
				esc_html__('O captcha de comentários não está configurado.', 'hs-comentarios-botao'),
				esc_html__('Erro de validação', 'hs-comentarios-botao'),
				['response' => 400]
			);
		}

		$token = '';
		if (isset($_POST['hs_turnstile_token'])) {
			$token = sanitize_text_field((string) wp_unslash($_POST['hs_turnstile_token']));
		}

		if (empty($token) && isset($_POST['cf-turnstile-response'])) {
			$token = sanitize_text_field((string) wp_unslash($_POST['cf-turnstile-response']));
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
