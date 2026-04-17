(function () {
	function isMobile(breakpoint) {
		return window.innerWidth <= breakpoint;
	}

	function openModal() {
		var modal = document.getElementById('hs-comentarios-modal');
		if (!modal) return;

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
		container.innerHTML = '<p class="hs-comentarios-loading">' + hsComentariosBotao.carregando + '</p>';
	}

	function setError() {
		var container = document.getElementById('hs-comentarios-container');
		if (!container) return;
		container.innerHTML = '<p class="hs-comentarios-loading">' + hsComentariosBotao.erro + '</p>';
	}

	function loadComments(postId) {
		setLoading();
		openModal();

		var url = hsComentariosBotao.ajaxUrl +
			'?action=hs_carregar_comentarios&post_id=' + encodeURIComponent(postId);

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
			})
			.catch(function () {
				setError();
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
			return;
		}

		if (modo === 'modal') {
			event.preventDefault();
			loadComments(postId);
			return;
		}

		if (modo === 'modal_desktop_page_mobile') {
			if (isMobile(parseInt(hsComentariosBotao.mobileBreakpoint, 10))) {
				return;
			}

			event.preventDefault();
			loadComments(postId);
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeModal();
		}
	});
})();