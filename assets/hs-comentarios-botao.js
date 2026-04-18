(function () {
	function isMobile(breakpoint) {
		return window.innerWidth <= breakpoint;
	}

	function openModal() {
		var modal = document.getElementById('hs-comentarios-modal');
		if (!modal) return;

		if (modal.parentElement !== document.body) {
			document.body.appendChild(modal);
		}

		modal.hidden = false;
		document.body.classList.add('hs-comentarios-modal-open');
	}

	function closeModal() {
		var modal = document.getElementById('hs-comentarios-modal');
		if (!modal) return;

		modal.hidden = true;
		document.body.classList.remove('hs-comentarios-modal-open');
	}

	function setLoading() {
		var container = document.getElementById('hs-comentarios-container');
		if (!container) return;
		updateModalTitle(hsComentariosBotao.tituloComentarios);
		container.innerHTML = '<p class="hs-comentarios-loading">' + hsComentariosBotao.carregando + '</p>';
	}

	function setSubmitting() {
		var container = document.getElementById('hs-comentarios-container');
		if (!container) return;
		container.innerHTML = '<p class="hs-comentarios-loading">' + hsComentariosBotao.enviando + '</p>';
	}

	function updateFormSubmitState(form, enabled) {
		if (!form) {
			return;
		}

		var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
		if (!submitButtons.length) {
			return;
		}

		submitButtons.forEach(function (button) {
			button.disabled = !enabled;
			button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
		});
	}

	function renderTurnstileWidgets(scope) {
		if (!hsComentariosBotao.turnstileAtivo || !window.turnstile) {
			return;
		}

		var root = scope || document;
		var widgets = root.querySelectorAll('.hs-turnstile-widget[data-sitekey]');
		if (!widgets.length) {
			return;
		}

		widgets.forEach(function (widget) {
			if (widget.getAttribute('data-widget-rendered') === '1') {
				return;
			}

			var form = widget.closest('form');
			if (!form) {
				return;
			}

			var hiddenToken = form.querySelector('input[name="hs_turnstile_token"]');
			if (!hiddenToken) {
				hiddenToken = document.createElement('input');
				hiddenToken.type = 'hidden';
				hiddenToken.name = 'hs_turnstile_token';
				form.appendChild(hiddenToken);
			}

			var hiddenEnabled = form.querySelector('input[name="hs_turnstile_enabled"]');
			if (!hiddenEnabled) {
				hiddenEnabled = document.createElement('input');
				hiddenEnabled.type = 'hidden';
				hiddenEnabled.name = 'hs_turnstile_enabled';
				hiddenEnabled.value = '1';
				form.appendChild(hiddenEnabled);
			}

			updateFormSubmitState(form, false);

			window.turnstile.render(widget, {
				sitekey: widget.getAttribute('data-sitekey'),
				callback: function (token) {
					hiddenToken.value = token || '';
					hiddenEnabled.value = '1';
					updateFormSubmitState(form, !!(token && token.length));
				},
				'expired-callback': function () {
					hiddenToken.value = '';
					updateFormSubmitState(form, false);
				},
				'error-callback': function () {
					hiddenToken.value = '';
					hiddenEnabled.value = '0';
					updateFormSubmitState(form, false);

					var container = document.getElementById('hs-comentarios-container');
					if (container && hsComentariosBotao.turnstileErroCarregamento) {
						container.insertAdjacentHTML('afterbegin', '<p class="hs-comentarios-loading">' + hsComentariosBotao.turnstileErroCarregamento + '</p>');
					}
				}
			});

			widget.setAttribute('data-widget-rendered', '1');
		});
	}

	function renderTurnstileWidgetsWhenReady(scope, attemptsLeft) {
		var attempts = typeof attemptsLeft === 'number' ? attemptsLeft : 20;
		if (!hsComentariosBotao.turnstileAtivo) {
			return;
		}

		if (window.turnstile) {
			renderTurnstileWidgets(scope);
			return;
		}

		if (attempts <= 0) {
			return;
		}

		window.setTimeout(function () {
			renderTurnstileWidgetsWhenReady(scope, attempts - 1);
		}, 250);
	}

	function setError() {
		var container = document.getElementById('hs-comentarios-container');
		if (!container) return;
		updateModalTitle(hsComentariosBotao.tituloComentarios);
		container.innerHTML = '<p class="hs-comentarios-loading">' + hsComentariosBotao.erro + '</p>';
	}

	function updateModalTitle(title) {
		var titleEl = document.getElementById('hs-comentarios-modal-title');
		if (!titleEl) return;

		titleEl.textContent = title || hsComentariosBotao.tituloComentarios;
	}

	function loadComments(postId, cpage) {
		var page = parseInt(cpage || 1, 10);
		if (!page || page < 1) {
			page = 1;
		}

		setLoading();
		openModal();

		var url = hsComentariosBotao.ajaxUrl +
			'?action=hs_carregar_comentarios&post_id=' + encodeURIComponent(postId) +
			'&cpage=' + encodeURIComponent(page) +
			'&nonce=' + encodeURIComponent(hsComentariosBotao.nonceCarregar);

		fetch(url, {
			method: 'GET',
			credentials: 'same-origin'
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				var container = document.getElementById('hs-comentarios-container');
				if (!container) return;

				if (!data || !data.success || !data.data || !data.data.html) {
					setError();
					return;
				}

				container.innerHTML = data.data.html;
				updateModalTitle(data.data.modalTitle);
				renderTurnstileWidgetsWhenReady(container);
			})
			.catch(function () {
				setError();
			});
	}

	function submitCommentForm(form) {
		if (!form) return;

		if (hsComentariosBotao.turnstileAtivo) {
			var enabledInput = form.querySelector('input[name="hs_turnstile_enabled"]');
			var turnstileEnabled = !enabledInput || enabledInput.value === '1';
			if (!turnstileEnabled) {
				// Falha de configuração/carregamento do Turnstile: não bloqueia envio.
				var tokenInputFallback = form.querySelector('input[name="hs_turnstile_token"]');
				if (tokenInputFallback) {
					tokenInputFallback.value = '';
				}
			}

			var tokenInput = form.querySelector('input[name="hs_turnstile_token"]');
			if (turnstileEnabled && (!tokenInput || !tokenInput.value)) {
				var container = document.getElementById('hs-comentarios-container');
				if (container) {
					container.innerHTML = '<p class="hs-comentarios-loading">' + hsComentariosBotao.turnstileObrigatorio + '</p>';
				}
				return;
			}
		}

		var postIdField = form.querySelector('input[name="comment_post_ID"]');
		var postId = postIdField ? postIdField.value : '';
		if (!postId) return;

		setSubmitting();

		fetch(form.action, {
			method: 'POST',
			credentials: 'same-origin',
			body: new FormData(form)
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('comment_submit_failed');
				}

					loadComments(postId, 1);
				})
			.catch(function () {
				var container = document.getElementById('hs-comentarios-container');
				if (!container) return;
				container.innerHTML = '<p class="hs-comentarios-loading">' + hsComentariosBotao.erroEnvio + '</p>';
			});
	}

	document.addEventListener('click', function (event) {
		var closeEl = event.target.closest('[data-hs-close="1"]');
		if (closeEl) {
			closeModal();
			return;
		}

		var btn = event.target.closest('.hs-comentarios-botao');
		if (!btn) return;

		var modo = btn.getAttribute('data-modo') || hsComentariosBotao.modoPadrao;
		var postId = btn.getAttribute('data-post-id');
		var commentsUrl = btn.getAttribute('data-comments-url');

		if (!postId || !commentsUrl) return;

		if (modo === 'page') {
			window.location.href = commentsUrl;
			return;
		}

			if (modo === 'modal') {
				event.preventDefault();
				loadComments(postId, 1);
				return;
			}

		if (modo === 'modal_desktop_page_mobile') {
			if (isMobile(parseInt(hsComentariosBotao.mobileBreakpoint, 10))) {
				window.location.href = commentsUrl;
				return;
			}

				event.preventDefault();
				loadComments(postId, 1);
			}
		});

		document.addEventListener('click', function (event) {
			var pageButton = event.target.closest('#hs-comentarios-container .hs-comentarios-page-link[data-cpage]');
			if (!pageButton) return;

			var container = document.getElementById('hs-comentarios-container');
			var form = container ? container.querySelector('form.comment-form') : null;
			var postIdField = form ? form.querySelector('input[name="comment_post_ID"]') : null;
			var postId = postIdField ? postIdField.value : null;
			if (!postId && container) {
				var ajaxWrapper = container.querySelector('.hs-comentarios-ajax-inner[data-post-id]');
				postId = ajaxWrapper ? ajaxWrapper.getAttribute('data-post-id') : null;
			}
			var cpage = parseInt(pageButton.getAttribute('data-cpage'), 10);
			if (!postId || !cpage || cpage < 1) return;

			event.preventDefault();
			loadComments(postId, cpage);
		});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeModal();
		}
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || !form.matches('#hs-comentarios-container form.comment-form')) {
			return;
		}

		event.preventDefault();
		submitCommentForm(form);
	});

	if (hsComentariosBotao.turnstileAtivo) {
		renderTurnstileWidgetsWhenReady(document);
		window.addEventListener('load', function () {
			renderTurnstileWidgetsWhenReady(document);
		});
	}
})();
