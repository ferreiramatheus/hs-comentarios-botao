(function () {
	function getModal() {
		return document.getElementById('hs-comentarios-modal');
	}

	function getContainer() {
		return document.getElementById('hs-comentarios-container');
	}

	function getConfig() {
		return window.hsComentariosBotao || {};
	}

	function isMobile(breakpoint) {
		return window.innerWidth <= breakpoint;
	}

	function openModal() {
		var modal = getModal();
		if (!modal) return;

		if (modal.parentElement !== document.body) {
			document.body.appendChild(modal);
		}

		modal.hidden = false;
		document.body.classList.add('hs-comentarios-modal-open');
	}

	function closeModal() {
		var modal = getModal();
		if (!modal) return;

		modal.hidden = true;
		document.body.classList.remove('hs-comentarios-modal-open');
	}

	function updateModalTitle(title) {
		var config = getConfig();
		var titleEl = document.getElementById('hs-comentarios-modal-title');
		if (!titleEl) return;

		titleEl.textContent = title || config.tituloComentarios;
	}

	function setContainerMessage(message, shouldResetTitle) {
		var container = getContainer();
		if (!container) return;

		if (shouldResetTitle) {
			updateModalTitle(getConfig().tituloComentarios);
		}

		container.innerHTML = '<p class="hs-comentarios-loading">' + message + '</p>';
	}

	function setLoading() {
		setContainerMessage(getConfig().carregando, true);
	}

	function setSubmitting() {
		setContainerMessage(getConfig().enviando, false);
	}

	function setError() {
		setContainerMessage(getConfig().erro, true);
	}

	function showTurnstileMessage(message) {
		var container = getContainer();
		if (!container) return;

		container.innerHTML = '<p class="hs-comentarios-loading">' + message + '</p>';
	}

	function updateFormSubmitState(form, enabled) {
		if (!form) return;

		var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
		if (!submitButtons.length) return;

		submitButtons.forEach(function (button) {
			button.disabled = !enabled;
			button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
		});
	}

	function renderTurnstileWidgets(scope) {
		var config = getConfig();
		if (!config.turnstileAtivo || !window.turnstile) return;

		var root = scope || document;
		var widgets = root.querySelectorAll('.hs-turnstile-widget[data-sitekey]');
		if (!widgets.length) return;

		widgets.forEach(function (widget) {
			if (widget.getAttribute('data-widget-rendered') === '1') return;

			var form = widget.closest('form');
			if (!form) return;

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

					if (config.turnstileErroCarregamento) {
						var container = getContainer();
						if (container) {
							container.insertAdjacentHTML('afterbegin', '<p class="hs-comentarios-loading">' + config.turnstileErroCarregamento + '</p>');
						}
					}
				}
			});

			widget.setAttribute('data-widget-rendered', '1');
		});
	}

	function renderTurnstileWidgetsWhenReady(scope, attemptsLeft) {
		var config = getConfig();
		var attempts = typeof attemptsLeft === 'number' ? attemptsLeft : 20;
		if (!config.turnstileAtivo) return;

		if (window.turnstile) {
			renderTurnstileWidgets(scope);
			return;
		}

		if (attempts <= 0) return;

		window.setTimeout(function () {
			renderTurnstileWidgetsWhenReady(scope, attempts - 1);
		}, 250);
	}

	function loadComments(postId, cpage) {
		var config = getConfig();
		var page = parseInt(cpage || 1, 10);
		if (!page || page < 1) {
			page = 1;
		}

		setLoading();
		openModal();

		var url = config.ajaxUrl +
			'?action=hs_carregar_comentarios&post_id=' + encodeURIComponent(postId) +
			'&cpage=' + encodeURIComponent(page) +
			'&nonce=' + encodeURIComponent(config.nonceCarregar);

		fetch(url, {
			method: 'GET',
			credentials: 'same-origin'
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				var container = getContainer();
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

	function extractCommentPageFromResponse(responseUrl) {
		if (!responseUrl) return 1;

		try {
			var urlObj = new URL(responseUrl, window.location.origin);
			var cpageQuery = parseInt(urlObj.searchParams.get('cpage') || '', 10);
			if (cpageQuery > 0) {
				return cpageQuery;
			}
		} catch (error) {
			return 1;
		}

		return 1;
	}

	function submitCommentForm(form) {
		if (!form) return;

		var config = getConfig();
		if (config.turnstileAtivo) {
			var enabledInput = form.querySelector('input[name="hs_turnstile_enabled"]');
			var turnstileEnabled = !enabledInput || enabledInput.value === '1';
			if (!turnstileEnabled) {
				showTurnstileMessage(config.turnstileErroCarregamento);
				return;
			}

			var tokenInput = form.querySelector('input[name="hs_turnstile_token"]');
			if (turnstileEnabled && (!tokenInput || !tokenInput.value)) {
				showTurnstileMessage(config.turnstileObrigatorio);
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

				loadComments(postId, extractCommentPageFromResponse(response.url || ''));
			})
			.catch(function () {
				showTurnstileMessage(config.erroEnvio);
			});
	}

	function resolveModeAction(modo, commentsUrl, postId) {
		var config = getConfig();

		if (modo === 'page') {
			window.location.href = commentsUrl;
			return;
		}

		if (modo === 'modal') {
			loadComments(postId, 1);
			return;
		}

		if (modo === 'modal_desktop_page_mobile') {
			if (isMobile(parseInt(config.mobileBreakpoint, 10))) {
				window.location.href = commentsUrl;
				return;
			}

			loadComments(postId, 1);
		}
	}

	function handleCommentButtonClick(event) {
		var btn = event.target.closest('.hs-comentarios-botao');
		if (!btn) return;

		var config = getConfig();
		var modo = btn.getAttribute('data-modo') || config.modoPadrao;
		var postId = btn.getAttribute('data-post-id');
		var commentsUrl = btn.getAttribute('data-comments-url');

		if (!postId || !commentsUrl) return;

		event.preventDefault();
		resolveModeAction(modo, commentsUrl, postId);
	}

	function resolvePostIdFromContainer(container) {
		if (!container) return null;

		var form = container.querySelector('form.comment-form');
		var postIdField = form ? form.querySelector('input[name="comment_post_ID"]') : null;
		if (postIdField && postIdField.value) {
			return postIdField.value;
		}

		var ajaxWrapper = container.querySelector('.hs-comentarios-ajax-inner[data-post-id]');
		return ajaxWrapper ? ajaxWrapper.getAttribute('data-post-id') : null;
	}

	function bindEvents() {
		document.addEventListener('click', function (event) {
			var closeEl = event.target.closest('[data-hs-close="1"]');
			if (closeEl) {
				closeModal();
				return;
			}

			handleCommentButtonClick(event);
		});

		document.addEventListener('click', function (event) {
			var pageButton = event.target.closest('#hs-comentarios-container .hs-comentarios-page-link[data-cpage]');
			if (!pageButton) return;

			var container = getContainer();
			var postId = resolvePostIdFromContainer(container);
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
		}, true);
	}

	function bootTurnstile() {
		if (!getConfig().turnstileAtivo) return;

		renderTurnstileWidgetsWhenReady(document);
		window.addEventListener('load', function () {
			renderTurnstileWidgetsWhenReady(document);
		});
	}

	bindEvents();
	bootTurnstile();
})();
