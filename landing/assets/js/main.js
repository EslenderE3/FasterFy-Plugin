/* =====================================================================
   FasterFy — Landing scripts (progressive enhancement)
   - Bilingual i18n (English base, Spanish secondary)
   - Language + billing toggles
   - Hardened AJAX waitlist submit (client validation + honeypot)
   - Reveal-on-scroll + count-up (respect reduced motion)
   ===================================================================== */
(function () {
	'use strict';

	/* --------------------------- i18n --------------------------- */
	var I18N = {
		en: {
			'skip': 'Skip to the waitlist',
			'nav.features': 'Features',
			'nav.how': 'How it works',
			'nav.pricing': 'Pricing',
			'nav.faq': 'FAQ',
			'nav.cta': 'Join waitlist',
			'hero.badge': 'Pre-launch · Early access',
			'hero.title': 'Stop optimizing WordPress images by hand.',
			'hero.lead': 'FasterFy converts, compresses and writes SEO alt text for your entire media library automatically — so your site loads faster, ranks higher and you get hours back every week.',
			'form.email.label': 'Work email',
			'form.email.placeholder': 'you@yourcompany.com',
			'form.submit': 'Get early access',
			'form.consent': 'I agree to receive launch updates. No spam — unsubscribe anytime.',
			'form.note': 'Join the waitlist and lock in a founding-member discount.',
			'hero.trust.1': 'No credit card',
			'hero.trust.2': 'Non-destructive · 1-click rollback',
			'hero.trust.3': 'Built for WordPress',
			'float.saved': 'Saved this week',
			'float.joined': 'Joined the waitlist',
			'hero.card.live': 'Live',
			'hero.card.stat1': 'Average weight reduction',
			'hero.card.stat2': 'Images optimized',
			'hero.card.stat3': '+ AVIF output',
			'hero.card.stat4': 'Alt text coverage',
			'hero.card.stat5': 'Originals lost',
			'strip.1': 'WebP & AVIF',
			'strip.2': 'AI Alt Text & SEO',
			'strip.3': 'Bulk gallery processing',
			'strip.4': 'Smart compression',
			'strip.5': 'Auto semantic rename',
			'strip.6': 'SVG sanitization',
			'features.eyebrow': 'What you get',
			'features.title': 'Real work, done for you',
			'features.sub': 'Every feature is built to save time and lift performance — measurable wins, not busywork.',
			'feat.1.t': 'Next-gen formats',
			'feat.1.d': 'Convert JPG to WebP or AVIF and crush PNGs without visible quality loss — the core lever for Core Web Vitals.',
			'feat.2.t': 'AI alt text & SEO',
			'feat.2.d': 'Multimodal vision writes accurate alt text, titles and descriptions — boosting accessibility and search rankings.',
			'feat.3.t': 'Bulk gallery management',
			'feat.3.d': 'Process your whole library in batches. Select, optimize, generate AI text or revert — hundreds of images at once.',
			'feat.4.t': 'Automatic semantic rename',
			'feat.4.d': 'Turn "IMG_4821.jpg" into descriptive, keyword-rich filenames that search engines actually read.',
			'feat.5.t': 'Non-destructive rollback',
			'feat.5.d': 'Every original is backed up before any change. Restore any asset with a single click — zero risk.',
			'feat.6.t': 'Security built in',
			'feat.6.d': 'SVG sanitization strips malicious scripts, and optional NSFW moderation protects your AI usage and brand.',
			'how.eyebrow': 'How it works',
			'how.title': 'Live in minutes, not days',
			'how.1.t': 'Install & connect',
			'how.1.d': 'Activate the plugin on WordPress. No build tools, no servers to manage.',
			'how.2.t': 'Scan your library',
			'how.2.d': 'FasterFy finds every image and shows exactly what can be optimized — retroactively.',
			'how.3.t': 'Optimize in bulk',
			'how.3.d': 'Run a batch and watch weight drop and SEO coverage climb. Roll back anytime.',
			'roi.1': 'Lighter images on average',
			'roi.2v': 'Hours',
			'roi.2': 'Saved every week vs. manual work',
			'roi.3': 'Alt text coverage for SEO',
			'roi.4v': 'Zero',
			'roi.4': 'Originals ever lost',
			'pricing.eyebrow': 'Pricing',
			'pricing.title': 'Simple plans that scale with you',
			'pricing.sub': 'Founding-member pricing for everyone on the waitlist. Prices below are placeholders for pre-launch.',
			'pricing.monthly': 'Monthly',
			'pricing.annual': 'Annual · save 20%',
			'plan.lite.name': 'Lite',
			'plan.lite.desc': 'For personal sites & blogs getting started.',
			'plan.free': 'free forever',
			'plan.lite.cta': 'Start free',
			'plan.lite.f1': 'WebP/AVIF conversion & compression',
			'plan.lite.f2': 'SVG sanitization',
			'plan.lite.f3': 'One-click optimize',
			'plan.lite.f4': 'Non-destructive rollback',
			'plan.lite.f5': '100 AI credits / month',
			'plan.popular': 'Most popular',
			'plan.pro.name': 'Pro',
			'plan.pro.desc': 'For professionals & growing businesses.',
			'plan.permo': '/ month',
			'plan.pro.cta': 'Join waitlist',
			'plan.pro.f1': 'Everything in Lite',
			'plan.pro.f2': 'AI alt text, titles & descriptions',
			'plan.pro.f3': 'Automatic semantic rename',
			'plan.pro.f4': 'Bulk gallery processing',
			'plan.pro.f5': 'NSFW moderation & advanced controls',
			'plan.pro.f6': '5,000 AI credits / month',
			'plan.agency.name': 'Agency',
			'plan.agency.desc': 'For agencies managing many client sites.',
			'plan.agency.cta': 'Join waitlist',
			'plan.agency.f1': 'Everything in Pro',
			'plan.agency.f2': 'Multi-site management',
			'plan.agency.f3': 'Priority processing & support',
			'plan.agency.f4': 'Exclusion rules & throttling',
			'plan.agency.f5': '25,000 AI credits / month',
			'pricing.foot': 'Prices are placeholders and may change at launch. Waitlist members get founding-member rates.',
			'faq.eyebrow': 'FAQ',
			'faq.title': 'Questions, answered',
			'faq.1.q': 'Will it touch my original files?',
			'faq.1.a': 'Never destructively. FasterFy backs up every original before any change, and you can restore any image with one click.',
			'faq.2.q': 'Do I need AI to use it?',
			'faq.2.a': 'No. Technical optimization (WebP/AVIF, compression, SVG) works on its own. AI alt text and SEO are optional add-ons.',
			'faq.3.q': 'Will it work on my host?',
			'faq.3.a': 'Yes. Bulk processing runs in safe, browser-driven batches, so it works reliably even on shared hosting.',
			'faq.4.q': 'When does it launch?',
			'faq.4.a': "We're in pre-launch. Join the waitlist and you'll be first to get access — plus founding-member pricing.",
			'faq.5.q': 'Is my data safe?',
			'faq.5.a': 'API keys are encrypted at rest, SVGs are sanitized, and sensitive images are never sent to AI when moderation is on.',
			'cta.title': 'Be first when FasterFy launches',
			'cta.sub': 'Join the waitlist for early access and founding-member pricing.',
			'cta.btn': 'Join the waitlist',
			'footer.tag': 'AI media optimization for WordPress.',
			'footer.rights': 'All rights reserved.',
			'msg.invalidEmail': 'Please enter a valid email address.',
			'msg.consent': 'Please accept to continue.',
			'msg.sending': 'Sending…',
			'msg.success': "You're on the list! Check your inbox to confirm.",
			'msg.error': 'Something went wrong. Please try again.',
			'msg.throttled': 'Too many attempts. Please try again later.'
		},
		es: {
			'skip': 'Saltar a la lista de espera',
			'nav.features': 'Funciones',
			'nav.how': 'Cómo funciona',
			'nav.pricing': 'Precios',
			'nav.faq': 'Preguntas',
			'nav.cta': 'Unirme a la lista',
			'hero.badge': 'Prelanzamiento · Acceso anticipado',
			'hero.title': 'Deja de optimizar las imágenes de WordPress a mano.',
			'hero.lead': 'FasterFy convierte, comprime y redacta el alt text SEO de toda tu biblioteca de medios automáticamente — para que tu sitio cargue más rápido, posicione mejor y recuperes horas cada semana.',
			'form.email.label': 'Correo de trabajo',
			'form.email.placeholder': 'tu@tuempresa.com',
			'form.submit': 'Quiero acceso anticipado',
			'form.consent': 'Acepto recibir novedades del lanzamiento. Sin spam — cancela cuando quieras.',
			'form.note': 'Únete a la lista y asegura un descuento de miembro fundador.',
			'hero.trust.1': 'Sin tarjeta de crédito',
			'hero.trust.2': 'No destructivo · reversión en 1 clic',
			'hero.trust.3': 'Hecho para WordPress',
			'float.saved': 'Ahorrado esta semana',
			'float.joined': 'En la lista de espera',
			'hero.card.live': 'En vivo',
			'hero.card.stat1': 'Reducción de peso promedio',
			'hero.card.stat2': 'Imágenes optimizadas',
			'hero.card.stat3': '+ salida AVIF',
			'hero.card.stat4': 'Cobertura de alt text',
			'hero.card.stat5': 'Originales perdidos',
			'strip.1': 'WebP y AVIF',
			'strip.2': 'Alt Text con IA y SEO',
			'strip.3': 'Procesamiento masivo',
			'strip.4': 'Compresión inteligente',
			'strip.5': 'Renombrado semántico',
			'strip.6': 'Sanitización de SVG',
			'features.eyebrow': 'Lo que obtienes',
			'features.title': 'Trabajo real, hecho por ti',
			'features.sub': 'Cada función está pensada para ahorrar tiempo y mejorar el rendimiento — resultados medibles, no tareas tediosas.',
			'feat.1.t': 'Formatos de nueva generación',
			'feat.1.d': 'Convierte JPG a WebP o AVIF y comprime PNG sin pérdida visible de calidad — la palanca clave para los Core Web Vitals.',
			'feat.2.t': 'Alt text con IA y SEO',
			'feat.2.d': 'La visión multimodal redacta alt text, títulos y descripciones precisos — mejorando la accesibilidad y el posicionamiento.',
			'feat.3.t': 'Gestión masiva de galerías',
			'feat.3.d': 'Procesa toda tu biblioteca por lotes. Selecciona, optimiza, genera textos con IA o revierte — cientos de imágenes a la vez.',
			'feat.4.t': 'Renombrado semántico automático',
			'feat.4.d': 'Convierte "IMG_4821.jpg" en nombres descriptivos y ricos en palabras clave que los buscadores sí leen.',
			'feat.5.t': 'Reversión no destructiva',
			'feat.5.d': 'Cada original se respalda antes de cualquier cambio. Restaura cualquier archivo con un clic — sin riesgos.',
			'feat.6.t': 'Seguridad incorporada',
			'feat.6.d': 'La sanitización de SVG elimina scripts maliciosos y la moderación NSFW opcional protege tu uso de IA y tu marca.',
			'how.eyebrow': 'Cómo funciona',
			'how.title': 'Operativo en minutos, no en días',
			'how.1.t': 'Instala y conecta',
			'how.1.d': 'Activa el plugin en WordPress. Sin herramientas de compilación ni servidores que administrar.',
			'how.2.t': 'Escanea tu biblioteca',
			'how.2.d': 'FasterFy encuentra cada imagen y muestra exactamente qué se puede optimizar — de forma retroactiva.',
			'how.3.t': 'Optimiza en masa',
			'how.3.d': 'Ejecuta un lote y mira cómo baja el peso y sube la cobertura SEO. Revierte cuando quieras.',
			'roi.1': 'Imágenes más livianas en promedio',
			'roi.2v': 'Horas',
			'roi.2': 'Ahorradas cada semana vs. trabajo manual',
			'roi.3': 'Cobertura de alt text para SEO',
			'roi.4v': 'Cero',
			'roi.4': 'Originales perdidos, nunca',
			'pricing.eyebrow': 'Precios',
			'pricing.title': 'Planes simples que crecen contigo',
			'pricing.sub': 'Precio de miembro fundador para toda la lista de espera. Los precios de abajo son provisionales para el prelanzamiento.',
			'pricing.monthly': 'Mensual',
			'pricing.annual': 'Anual · ahorra 20%',
			'plan.lite.name': 'Lite',
			'plan.lite.desc': 'Para sitios personales y blogs que empiezan.',
			'plan.free': 'gratis para siempre',
			'plan.lite.cta': 'Empezar gratis',
			'plan.lite.f1': 'Conversión WebP/AVIF y compresión',
			'plan.lite.f2': 'Sanitización de SVG',
			'plan.lite.f3': 'Optimización en un clic',
			'plan.lite.f4': 'Reversión no destructiva',
			'plan.lite.f5': '100 créditos de IA / mes',
			'plan.popular': 'Más popular',
			'plan.pro.name': 'Pro',
			'plan.pro.desc': 'Para profesionales y negocios en crecimiento.',
			'plan.permo': '/ mes',
			'plan.pro.cta': 'Unirme a la lista',
			'plan.pro.f1': 'Todo lo de Lite',
			'plan.pro.f2': 'Alt text, títulos y descripciones con IA',
			'plan.pro.f3': 'Renombrado semántico automático',
			'plan.pro.f4': 'Procesamiento masivo de galerías',
			'plan.pro.f5': 'Moderación NSFW y controles avanzados',
			'plan.pro.f6': '5.000 créditos de IA / mes',
			'plan.agency.name': 'Agencia',
			'plan.agency.desc': 'Para agencias que gestionan muchos sitios de clientes.',
			'plan.agency.cta': 'Unirme a la lista',
			'plan.agency.f1': 'Todo lo de Pro',
			'plan.agency.f2': 'Gestión multisitio',
			'plan.agency.f3': 'Procesamiento y soporte prioritario',
			'plan.agency.f4': 'Reglas de exclusión y throttling',
			'plan.agency.f5': '25.000 créditos de IA / mes',
			'pricing.foot': 'Los precios son provisionales y pueden cambiar en el lanzamiento. Los miembros de la lista obtienen tarifas de fundador.',
			'faq.eyebrow': 'Preguntas',
			'faq.title': 'Preguntas, respondidas',
			'faq.1.q': '¿Toca mis archivos originales?',
			'faq.1.a': 'Nunca de forma destructiva. FasterFy respalda cada original antes de cualquier cambio y puedes restaurar cualquier imagen con un clic.',
			'faq.2.q': '¿Necesito IA para usarlo?',
			'faq.2.a': 'No. La optimización técnica (WebP/AVIF, compresión, SVG) funciona por sí sola. El alt text con IA y el SEO son complementos opcionales.',
			'faq.3.q': '¿Funcionará en mi hosting?',
			'faq.3.a': 'Sí. El procesamiento masivo se ejecuta en lotes seguros conducidos por el navegador, así que funciona de forma fiable incluso en hosting compartido.',
			'faq.4.q': '¿Cuándo se lanza?',
			'faq.4.a': 'Estamos en prelanzamiento. Únete a la lista y serás de los primeros en obtener acceso — además del precio de miembro fundador.',
			'faq.5.q': '¿Están seguros mis datos?',
			'faq.5.a': 'Las claves de API se cifran en reposo, los SVG se sanitizan y las imágenes sensibles nunca se envían a la IA cuando la moderación está activa.',
			'cta.title': 'Sé el primero cuando FasterFy se lance',
			'cta.sub': 'Únete a la lista para acceso anticipado y precio de miembro fundador.',
			'cta.btn': 'Unirme a la lista',
			'footer.tag': 'Optimización de medios con IA para WordPress.',
			'footer.rights': 'Todos los derechos reservados.',
			'msg.invalidEmail': 'Introduce una dirección de correo válida.',
			'msg.consent': 'Acepta para continuar.',
			'msg.sending': 'Enviando…',
			'msg.success': '¡Estás en la lista! Revisa tu correo para confirmar.',
			'msg.error': 'Algo salió mal. Inténtalo de nuevo.',
			'msg.throttled': 'Demasiados intentos. Inténtalo más tarde.'
		}
	};

	var STORAGE_KEY = 'fasterfy_lang';
	var currentLang = 'en';

	function t(key) {
		var dict = I18N[currentLang] || I18N.en;
		return dict[key] || I18N.en[key] || '';
	}

	function applyLanguage(lang) {
		if (!I18N[lang]) { lang = 'en'; }
		currentLang = lang;
		document.documentElement.lang = lang;

		// Text nodes.
		document.querySelectorAll('[data-i18n]').forEach(function (el) {
			var key = el.getAttribute('data-i18n');
			var attr = el.getAttribute('data-i18n-attr');
			var val = t(key);
			if (!val) { return; }
			if (attr) {
				el.setAttribute(attr, val);
			} else {
				el.textContent = val;
			}
		});

		// Toggle buttons state.
		document.querySelectorAll('.lang-btn').forEach(function (btn) {
			var active = btn.getAttribute('data-lang') === lang;
			btn.classList.toggle('is-active', active);
			btn.setAttribute('aria-pressed', active ? 'true' : 'false');
		});

		try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) {}
	}

	function initLanguage() {
		var saved = null;
		try { saved = localStorage.getItem(STORAGE_KEY); } catch (e) {}
		if (!saved) {
			var nav = (navigator.language || 'en').toLowerCase();
			saved = nav.indexOf('es') === 0 ? 'es' : 'en';
		}
		applyLanguage(saved);

		document.querySelectorAll('.lang-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				applyLanguage(btn.getAttribute('data-lang'));
			});
		});
	}

	/* ----------------------- Billing toggle ---------------------- */
	function initBilling() {
		var btns = document.querySelectorAll('.billing-btn');
		if (!btns.length) { return; }

		function setBilling(period) {
			document.querySelectorAll('.plan__amount').forEach(function (el) {
				var v = el.getAttribute('data-' + period);
				if (v) { el.textContent = v; }
			});
			btns.forEach(function (b) {
				var active = b.getAttribute('data-billing') === period;
				b.classList.toggle('is-active', active);
				b.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
		}

		btns.forEach(function (b) {
			b.addEventListener('click', function () {
				setBilling(b.getAttribute('data-billing'));
			});
		});
	}

	/* ------------------------ Reveal on scroll ------------------- */
	var REDUCE = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function observe(els, onShow, opts) {
		if (!els.length) { return; }
		if (!('IntersectionObserver' in window)) {
			els.forEach(onShow);
			return;
		}
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					onShow(entry.target);
					io.unobserve(entry.target);
				}
			});
		}, opts || { threshold: 0.16, rootMargin: '0px 0px -8% 0px' });
		els.forEach(function (el) { io.observe(el); });
	}

	function initReveal() {
		if (REDUCE) { return; }

		// Soft reveal-up for headers and the final CTA.
		var reveals = document.querySelectorAll('.section__head, .cta-final__inner, .strip__inner');
		reveals.forEach(function (el) { el.classList.add('reveal-init'); });
		observe(reveals, function (el) { el.classList.add('is-visible'); });

		// Staggered reveal for card grids.
		var grids = document.querySelectorAll('.cards, .steps, .plans, .roi__grid');
		grids.forEach(function (el) { el.classList.add('stagger-init'); });
		observe(grids, function (el) { el.classList.add('is-visible'); });

		// Animate the hero progress bar when it enters the viewport.
		var bars = document.querySelectorAll('.bar');
		observe(bars, function (el) { el.classList.add('is-filled'); }, { threshold: 0.4 });
	}

	/* --------------------- Active-section nav -------------------- */
	function initScrollSpy() {
		var links = Array.prototype.slice.call(document.querySelectorAll('.nav a[href^="#"]'));
		if (!links.length || !('IntersectionObserver' in window)) { return; }

		var map = {};
		links.forEach(function (a) {
			var id = a.getAttribute('href').slice(1);
			var sec = document.getElementById(id);
			if (sec) { map[id] = a; }
		});

		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					links.forEach(function (a) { a.classList.remove('is-active'); });
					var active = map[entry.target.id];
					if (active) { active.classList.add('is-active'); }
				}
			});
		}, { rootMargin: '-45% 0px -50% 0px' });

		Object.keys(map).forEach(function (id) {
			io.observe(document.getElementById(id));
		});
	}

	/* ----------------------- Header on scroll -------------------- */
	function initHeader() {
		var header = document.querySelector('.site-header');
		if (!header) { return; }
		var ticking = false;
		function update() {
			header.classList.toggle('is-scrolled', window.scrollY > 12);
			ticking = false;
		}
		window.addEventListener('scroll', function () {
			if (!ticking) { window.requestAnimationFrame(update); ticking = true; }
		}, { passive: true });
		update();
	}

	/* -------------------------- Count up ------------------------- */
	function initCounters() {
		var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var els = document.querySelectorAll('[data-count]');
		if (!els.length) { return; }

		function run(el) {
			var target = parseInt(el.getAttribute('data-count'), 10) || 0;
			var suffix = (el.textContent.match(/[^0-9.,]+$/) || [''])[0];
			if (reduce || !('IntersectionObserver' in window)) {
				el.textContent = target.toLocaleString() + suffix;
				return;
			}
			var start = null, dur = 1200;
			function step(ts) {
				if (!start) { start = ts; }
				var p = Math.min((ts - start) / dur, 1);
				var eased = 1 - Math.pow(1 - p, 3);
				el.textContent = Math.round(target * eased).toLocaleString() + suffix;
				if (p < 1) { requestAnimationFrame(step); }
			}
			requestAnimationFrame(step);
		}

		if (!('IntersectionObserver' in window)) {
			els.forEach(run);
			return;
		}
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					run(entry.target);
					io.unobserve(entry.target);
				}
			});
		}, { threshold: 0.5 });
		els.forEach(function (el) { io.observe(el); });
	}

	/* ----------------------- Waitlist submit --------------------- */
	function isValidEmail(value) {
		// Pragmatic RFC-ish check; server performs authoritative validation.
		return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
	}

	function initForm() {
		var form = document.getElementById('waitlist-form');
		if (!form) { return; }

		var email = document.getElementById('email');
		var errorEl = document.getElementById('email-error');
		var feedback = document.getElementById('form-feedback');
		var consent = document.getElementById('consent');
		var submitBtn = form.querySelector('button[type="submit"]');

		function showError(msg) {
			if (!errorEl) { return; }
			errorEl.textContent = msg;
			errorEl.hidden = false;
			email.setAttribute('aria-invalid', 'true');
		}
		function clearError() {
			if (!errorEl) { return; }
			errorEl.hidden = true;
			errorEl.textContent = '';
			email.removeAttribute('aria-invalid');
		}
		function showFeedback(msg, kind) {
			if (!feedback) { return; }
			feedback.textContent = msg;
			feedback.hidden = false;
			feedback.classList.remove('is-success', 'is-error');
			feedback.classList.add(kind === 'success' ? 'is-success' : 'is-error');
		}

		email.addEventListener('input', clearError);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			clearError();
			if (feedback) { feedback.hidden = true; }

			if (!isValidEmail((email.value || '').trim())) {
				showError(t('msg.invalidEmail'));
				email.focus();
				return;
			}
			if (consent && !consent.checked) {
				showFeedback(t('msg.consent'), 'error');
				consent.focus();
				return;
			}

			var original = submitBtn.textContent;
			submitBtn.disabled = true;
			submitBtn.textContent = t('msg.sending');

			var data = new FormData(form);
			data.append('lang', currentLang);

			fetch(form.action, {
				method: 'POST',
				body: data,
				headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
				credentials: 'same-origin'
			})
				.then(function (res) {
					return res.json().then(function (json) {
						return { ok: res.ok, status: res.status, body: json };
					});
				})
				.then(function (r) {
					if (r.ok && r.body && r.body.success) {
						showFeedback(r.body.message || t('msg.success'), 'success');
						form.reset();
					} else if (r.status === 429) {
						showFeedback(t('msg.throttled'), 'error');
					} else {
						var m = (r.body && r.body.message) ? r.body.message : t('msg.error');
						showFeedback(m, 'error');
					}
				})
				.catch(function () {
					showFeedback(t('msg.error'), 'error');
				})
				.finally(function () {
					submitBtn.disabled = false;
					submitBtn.textContent = original;
				});
		});
	}

	/* ----------------------------- Init -------------------------- */
	function init() {
		initLanguage();
		initBilling();
		initReveal();
		initScrollSpy();
		initHeader();
		initCounters();
		initForm();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
