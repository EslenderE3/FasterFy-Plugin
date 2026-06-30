/**
 * FasterFy — Panel SPA (JavaScript vanilla, sin dependencias de build).
 *
 * Consume la API REST fasterfy/v1 y renderiza un panel de control de nivel
 * SaaS: dashboard, biblioteca, ajustes (Lite/Pro), rendimiento y logs.
 */
( function () {
	'use strict';

	if ( typeof window.FasterFyData === 'undefined' ) {
		return;
	}

	var DATA = window.FasterFyData;

	/* ============================================================
	 * Cliente API
	 * ============================================================ */
	var Api = {
		request: function ( path, options ) {
			options = options || {};
			var url = DATA.restUrl.replace( /\/$/, '' ) + path;
			var opts = {
				method: options.method || 'GET',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': DATA.nonce
				}
			};
			if ( options.body ) {
				opts.body = JSON.stringify( options.body );
			}
			return fetch( url, opts ).then( function ( res ) {
				return res.json().then( function ( json ) {
					if ( ! res.ok || ( json && json.ok === false ) ) {
						throw new Error( ( json && json.message ) || ( 'HTTP ' + res.status ) );
					}
					return json;
				} );
			} );
		},
		get: function ( p ) { return this.request( p, { method: 'GET' } ); },
		post: function ( p, body ) { return this.request( p, { method: 'POST', body: body } ); },
		put: function ( p, body ) { return this.request( p, { method: 'PUT', body: body } ); },
		del: function ( p ) { return this.request( p, { method: 'DELETE' } ); }
	};

	/* ============================================================
	 * Estado global
	 * ============================================================ */
	var State = {
		route: 'dashboard',
		theme: localStorage.getItem( 'fasterfy_theme' ) || 'dark',
		settings: DATA.settings || {},
		caps: DATA.capabilities || {},
		summary: null,
		queue: null,
		media: { items: [], total: 0, page: 1, status: 'all', search: '', orderby: 'recent', selected: [], view: ( ( function () { try { return localStorage.getItem( 'fasterfy_media_view' ) || 'list'; } catch ( e ) { return 'list'; } } )() ), loading: false },
		logs: { items: [], total: 0, page: 1, loading: false },
		polling: null,
		driving: false,
		driveErrors: 0
	};

	/* ============================================================
	 * Utilidades
	 * ============================================================ */
	function h( str ) {
		var d = document.createElement( 'div' );
		d.textContent = str == null ? '' : String( str );
		return d.innerHTML;
	}
	function bytes( n ) {
		n = Number( n ) || 0;
		if ( n < 1024 ) { return n + ' B'; }
		var u = [ 'KB', 'MB', 'GB', 'TB' ], i = -1;
		do { n /= 1024; i++; } while ( n >= 1024 && i < u.length - 1 );
		return n.toFixed( n < 10 ? 1 : 0 ) + ' ' + u[ i ];
	}
	function pct( a, b ) { return b > 0 ? Math.round( ( a / b ) * 100 ) : 0; }
	function isPro() { return State.settings.mode === 'pro'; }

	var toastTimer;
	function toast( msg, type ) {
		type = type || 'info';
		var wrap = document.querySelector( '.ff-toasts' );
		if ( ! wrap ) {
			wrap = document.createElement( 'div' );
			wrap.className = 'ff-toasts';
			document.body.appendChild( wrap );
		}
		var icons = { success: '✓', error: '✕', info: 'ℹ' };
		var t = document.createElement( 'div' );
		t.className = 'ff-toast ff-toast--' + type;
		t.innerHTML = '<span class="ff-toast__ico">' + ( icons[ type ] || 'ℹ' ) + '</span><span class="ff-toast__msg"></span>';
		t.querySelector( '.ff-toast__msg' ).textContent = msg;
		wrap.appendChild( t );
		setTimeout( function () {
			t.style.opacity = '0';
			t.style.transform = 'translateY(-8px)';
			setTimeout( function () { t.remove(); }, 300 );
		}, 4000 );
	}

	/* ============================================================
	 * Iconos (SVG inline)
	 * ============================================================ */
	var ICONS = {
		dashboard: '<svg class="ff-nav__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
		media: '<svg class="ff-nav__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5L5 20"/></svg>',
		settings: '<svg class="ff-nav__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 2.6 14H2.5a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 2.7-1.1V2.5a2 2 0 1 1 4 0v.1A1.6 1.6 0 0 0 17 4.6a1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8v.1a1.6 1.6 0 0 0 1.5 1H22a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/></svg>',
		perf: '<svg class="ff-nav__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m7 14 3-3 3 3 5-6"/></svg>',
		logs: '<svg class="ff-nav__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg>',
		ai: '<svg class="ff-nav__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8"/><circle cx="12" cy="12" r="3"/></svg>'
	};

	/* ============================================================
	 * Layout
	 * ============================================================ */
	var NAV = [
		{ id: 'dashboard', label: 'Resumen', icon: 'dashboard', lite: true },
		{ id: 'media', label: 'Biblioteca', icon: 'media', lite: true },
		{ id: 'performance', label: 'Rendimiento', icon: 'perf', lite: true },
		{ id: 'ai', label: 'IA & SEO', icon: 'ai', lite: true },
		{ id: 'settings', label: 'Ajustes', icon: 'settings', lite: true },
		{ id: 'logs', label: 'Registros', icon: 'logs', lite: false }
	];

	/** Escudo de marca FasterFy (hexágono verde + rayo + chispa). */
	function brandMark( size ) {
		size = size || 32;
		return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
			'<path d="M24 2 44 13 44 35 24 46 4 35 4 13Z" fill="#33EE33"/>' +
			'<path d="M26 10 L15 27 L22 27 L19 39 L33 21 L25 21 Z" fill="#1F1F1F"/>' +
			'<path d="M33 20.5 L34.5 24.4 L38.4 25.9 L34.5 27.4 L33 31.3 L31.5 27.4 L27.6 25.9 L31.5 24.4 Z" fill="#ffffff"/>' +
		'</svg>';
	}

	function renderShell() {
		var app = document.getElementById( 'fasterfy-app' );
		app.removeAttribute( 'data-loading' );
		app.setAttribute( 'data-theme', State.theme );

		// El modo Lite oculta secciones técnicas.
		var navItems = NAV.filter( function ( n ) { return isPro() || n.lite !== false; } );
		if ( ! isPro() && State.route === 'logs' ) { State.route = 'dashboard'; }

		var nav = navItems.map( function ( n ) {
			return '<div class="ff-nav__item' + ( State.route === n.id ? ' is-active' : '' ) + '" data-route="' + n.id + '">' +
				ICONS[ n.icon ] + '<span>' + n.label + '</span></div>';
		} ).join( '' );

		app.innerHTML =
			'<div class="ff-shell">' +
				'<aside class="ff-sidebar">' +
					'<div class="ff-brand">' + brandMark( 32 ) + '<span class="ff-brand__name">Faster<i>Fy</i></span></div>' +
					'<div class="ff-brand__tag">AI Media Optimizer</div>' +
					'<nav class="ff-nav">' + nav + '</nav>' +
					'<div class="ff-sidebar__foot">' +
						'<div class="ff-modeswitch" data-action="mode">' +
							'<button data-mode="lite" class="' + ( ! isPro() ? 'is-active' : '' ) + '">Lite</button>' +
							'<button data-mode="pro" class="' + ( isPro() ? 'is-active' : '' ) + '">Pro</button>' +
						'</div>' +
						'<p class="ff-mode-hint">' + ( isPro() ? 'Modo avanzado: control total.' : 'Modo simple: todo en un clic.' ) + '</p>' +
					'</div>' +
				'</aside>' +
				'<main class="ff-main" id="ff-view"></main>' +
				'<button class="ff-themebtn" data-action="theme" title="Cambiar tema">' + ( State.theme === 'dark' ? '☀' : '🌙' ) + '</button>' +
			'</div>';

		renderRoute();
	}

	function renderRoute() {
		var view = document.getElementById( 'ff-view' );
		if ( ! view ) { return; }
		switch ( State.route ) {
			case 'dashboard': return viewDashboard( view );
			case 'media': return viewMedia( view );
			case 'performance': return viewPerformance( view );
			case 'ai': return viewAI( view );
			case 'settings': return viewSettings( view );
			case 'logs': return viewLogs( view );
		}
	}

	function topbar( title, subtitle, actions ) {
		return '<div class="ff-topbar"><div><h1>' + h( title ) + '</h1><p>' + h( subtitle ) + '</p></div>' +
			'<div class="ff-topbar__actions">' + ( actions || '' ) + '</div></div>';
	}

	function queuePill( status ) {
		var labels = { idle: 'Inactiva', running: 'Procesando', paused: 'Pausada', completed: 'Completada', exhausted: 'Sin créditos' };
		return '<span class="ff-pill ff-pill--' + status + '"><span class="ff-pill__dot"></span>' + ( labels[ status ] || status ) + '</span>';
	}

	/* ============================================================
	 * Vista: Dashboard
	 * ============================================================ */
	function viewDashboard( view ) {
		view.innerHTML = topbar( 'Resumen', 'Estado global de optimización de tu biblioteca de medios.',
			'<button class="ff-btn ff-btn--primary" data-action="start-queue" data-mode="' + ( State.settings.ai && State.settings.ai.enabled ? 'both' : 'optimize' ) + '">⚡ Optimizar todo</button>' ) +
			dashboardBanner() +
			'<div id="ff-dash-body"><div class="ff-empty"><span class="ff-spinner"></span> Cargando datos…</div></div>';

		State.heroIndex = 0;
		startHero();
		loadSummary().then( renderDashboardBody );
	}

	/* Banner/hero con slider de beneficios. */
	var HERO_SLIDES = [
		{ icon: '⚡', title: 'Hasta 80% más livianas', text: 'Convierte a WebP/AVIF y comprime automáticamente, sin perder calidad visible.' },
		{ icon: '🧠', title: 'SEO con Inteligencia Artificial', text: 'Genera alt text, títulos y descripciones para posicionar mejor tus imágenes.' },
		{ icon: '📦', title: 'Procesamiento masivo', text: 'Optimiza toda tu biblioteca por lotes, en segundo plano, sin bloquear tu sitio.' },
		{ icon: '↩', title: 'No destructivo', text: 'Guardamos el original: revierte cualquier imagen a su versión inicial con un clic.' }
	];

	function dashboardBanner() {
		var slides = HERO_SLIDES.map( function ( s, i ) {
			return '<div class="ff-hero__slide' + ( 0 === i ? ' is-active' : '' ) + '">' +
				'<span class="ff-hero__ico">' + s.icon + '</span>' +
				'<div><b>' + h( s.title ) + '</b><span>' + h( s.text ) + '</span></div></div>';
		} ).join( '' );
		var dots = HERO_SLIDES.map( function ( s, i ) {
			return '<button class="ff-hero__dot' + ( 0 === i ? ' is-active' : '' ) + '" data-action="hero-dot" data-i="' + i + '"></button>';
		} ).join( '' );
		return '<div class="ff-hero" id="ff-hero">' +
			'<div class="ff-hero__left">' +
				'<div class="ff-hero__brand">' + brandMark( 44 ) + '<div class="ff-hero__name">Faster<i>Fy</i><span>AI Media Optimizer</span></div></div>' +
			'</div>' +
			'<div class="ff-hero__right">' +
				'<div class="ff-hero__slides">' + slides + '</div>' +
				'<div class="ff-hero__dots">' + dots + '</div>' +
			'</div>' +
		'</div>';
	}

	function heroGo( i ) {
		var hero = document.getElementById( 'ff-hero' );
		if ( ! hero ) { return; }
		var slides = hero.querySelectorAll( '.ff-hero__slide' );
		var dots = hero.querySelectorAll( '.ff-hero__dot' );
		if ( ! slides.length ) { return; }
		State.heroIndex = ( i + slides.length ) % slides.length;
		slides.forEach( function ( el, n ) { el.classList.toggle( 'is-active', n === State.heroIndex ); } );
		dots.forEach( function ( el, n ) { el.classList.toggle( 'is-active', n === State.heroIndex ); } );
	}

	function startHero() {
		if ( State.heroTimer ) { clearInterval( State.heroTimer ); }
		State.heroTimer = setInterval( function () {
			if ( ! document.getElementById( 'ff-hero' ) ) { clearInterval( State.heroTimer ); State.heroTimer = null; return; }
			heroGo( ( State.heroIndex || 0 ) + 1 );
		}, 4500 );
	}

	function renderDashboardBody() {
		var body = document.getElementById( 'ff-dash-body' );
		if ( ! body || ! State.summary ) { return; }
		var lib = State.summary.library;
		var q = State.queue || State.summary.queue;
		var saved = lib.total_saved || 0;
		var ratio = pct( lib.optimized, lib.total );
		var circ = 2 * Math.PI * 54;
		var dash = circ - ( circ * ratio / 100 );

		body.innerHTML =
			'<div class="ff-grid ff-grid--stats ff-mt">' +
				statCard( 'Activos totales', lib.total, 'En la biblioteca de medios', '🖼️' ) +
				statCard( 'Optimizados', lib.optimized, ratio + '% del total', '✅' ) +
				statCard( 'Pendientes', lib.pending, 'Listos para procesar', '⏳' ) +
				statCard( 'Espacio ahorrado', bytes( saved ), 'Acumulado histórico', '💾' ) +
			'</div>' +
			'<div class="ff-grid ff-grid--2 ff-mt-lg">' +
				'<div class="ff-card ff-card--pad-lg">' +
					'<h3>Estado del procesamiento ' + queuePill( q.status ) + '</h3>' +
					queuePanel( q ) +
				'</div>' +
				'<div class="ff-card ff-card--pad-lg">' +
					'<h3>Progreso global</h3>' +
					'<div class="ff-ring">' +
						'<svg width="130" height="130" viewBox="0 0 130 130">' +
							'<defs><linearGradient id="ffgrad" x1="0" y1="0" x2="1" y2="1">' +
								'<stop offset="0%" stop-color="#33ee33"/><stop offset="100%" stop-color="#18c93a"/>' +
							'</linearGradient></defs>' +
							'<circle class="ff-ring__track" cx="65" cy="65" r="54"/>' +
							'<circle class="ff-ring__bar" cx="65" cy="65" r="54" stroke-dasharray="' + circ + '" stroke-dashoffset="' + dash + '"/>' +
						'</svg>' +
						'<div class="ff-ring__center"><div class="ff-ring__pct">' + ratio + '%</div><div class="ff-card__sub">optimizado</div></div>' +
					'</div>' +
					byTypeList( lib.by_type ) +
				'</div>' +
			'</div>' +
			capabilitiesCard();
	}

	function statCard( label, value, sub, ico ) {
		return '<div class="ff-card"><div class="ff-card__ico">' + ico + '</div>' +
			'<div class="ff-card__label">' + h( label ) + '</div>' +
			'<div class="ff-card__value">' + h( value ) + '</div>' +
			'<div class="ff-card__sub">' + h( sub ) + '</div>' +
			'<div class="ff-spark"></div></div>';
	}

	function queuePanel( q ) {
		var total = q.total || 0;
		var done = q.processed || 0;
		var p = pct( done, total );
		var running = q.status === 'running';
		var controls = '';
		if ( running ) {
			controls = '<button class="ff-btn ff-btn--sm" data-action="pause-queue">⏸ Pausar</button>' +
				'<button class="ff-btn ff-btn--sm ff-btn--danger" data-action="cancel-queue">✕ Detener</button>';
		} else if ( q.status === 'paused' ) {
			controls = '<button class="ff-btn ff-btn--sm ff-btn--accent" data-action="resume-queue">▶ Reanudar</button>' +
				'<button class="ff-btn ff-btn--sm ff-btn--danger" data-action="cancel-queue">✕ Detener</button>';
		} else {
			controls = '<button class="ff-btn ff-btn--sm ff-btn--primary" data-action="start-queue" data-mode="' + ( State.settings.ai && State.settings.ai.enabled ? 'both' : 'optimize' ) + '">⚡ Iniciar</button>';
		}

		return '<div class="ff-progress"><span style="width:' + p + '%"></span></div>' +
			'<div class="ff-queue-meta">' +
				'<div><b>' + done + ' / ' + total + '</b>Procesados</div>' +
				'<div><b>' + ( q.succeeded || 0 ) + '</b>Con éxito</div>' +
				'<div><b>' + ( q.skipped || 0 ) + '</b>Omitidos</div>' +
				'<div><b>' + ( q.failed || 0 ) + '</b>Errores</div>' +
			'</div>' +
			'<div class="ff-row ff-mt">' + controls +
				'<span class="ff-muted" style="font-size:12px">Motor: ' + h( q.engine || '—' ) + '</span></div>';
	}

	function byTypeList( byType ) {
		byType = byType || {};
		var names = { 'image/jpeg': 'JPEG → WebP/AVIF', 'image/jpg': 'JPG → WebP/AVIF', 'image/png': 'PNG (compresión)', 'image/svg+xml': 'SVG (sanitizado)' };
		var rows = Object.keys( byType ).map( function ( k ) {
			return '<div class="ff-row" style="justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--ff-border)">' +
				'<span class="ff-muted">' + h( names[ k ] || k ) + '</span><b>' + byType[ k ] + '</b></div>';
		} ).join( '' );
		return '<div class="ff-mt">' + ( rows || '<p class="ff-muted">Sin activos.</p>' ) + '</div>';
	}

	function capabilitiesCard() {
		var c = State.caps.image || {};
		function cap( on, label ) {
			return '<div class="ff-cap"><span class="ff-cap__dot ' + ( on ? 'on' : 'off' ) + '"></span>' + label + '</div>';
		}
		return '<div class="ff-card ff-mt-lg"><h3>Capacidades del servidor</h3><div class="ff-caps">' +
			cap( c.imagick, 'Imagick' ) + cap( c.gd, 'GD' ) +
			cap( c.webp, 'WebP' ) + cap( c.avif, 'AVIF' ) +
			cap( c.png_quantize, 'Cuantización PNG' ) +
			cap( State.caps.queue_engine === 'action_scheduler', 'Action Scheduler' ) +
			'</div><p class="ff-muted ff-mt" style="font-size:12px">Motor de cola: <b>' + h( State.caps.queue_engine ) + '</b></p></div>';
	}

	/* ============================================================
	 * Vista: Biblioteca (medios)
	 * ============================================================ */
	function viewMedia( view ) {
		view.innerHTML = topbar( 'Biblioteca', 'Optimización retroactiva y mutación nativa de medios.', '' ) +
			bulkActionsCard() +
			'<div class="ff-toolbar" style="justify-content:space-between">' +
				'<div class="ff-tabs" data-action="media-tab">' +
					tabBtn( 'all', 'Todos' ) + tabBtn( 'pending', 'Pendientes' ) + tabBtn( 'optimized', 'Optimizados' ) +
				'</div>' +
				'<div class="ff-tabs" data-action="media-view">' +
					'<button data-view="list" class="' + ( 'grid' !== State.media.view ? 'is-active' : '' ) + '">☰ Lista</button>' +
					'<button data-view="grid" class="' + ( 'grid' === State.media.view ? 'is-active' : '' ) + '">▦ Cuadrícula</button>' +
				'</div>' +
			'</div>' +
			'<div class="ff-toolbar" style="justify-content:space-between;gap:10px">' +
				'<input type="search" class="ff-input" id="ff-search" placeholder="🔍 Buscar por nombre…" value="' + h( State.media.search ) + '" style="max-width:320px">' +
				'<select class="ff-select" id="ff-orderby" style="max-width:220px">' +
					sortOpt( 'recent', 'Más recientes' ) + sortOpt( 'oldest', 'Más antiguas' ) +
					sortOpt( 'savings', 'Mayor ahorro' ) + sortOpt( 'title', 'Nombre (A-Z)' ) + sortOpt( 'type', 'Tipo de archivo' ) +
				'</select>' +
			'</div>' +
			'<div class="ff-card"><div id="ff-media-table"></div></div>';
		loadMedia();
		refreshQueueStatus();
	}

	/**
	 * Tarjeta de acciones masivas. En modo Lite muestra un único botón
	 * "todo en uno"; en modo Pro muestra las 3 acciones con descripción.
	 */
	function bulkActionsCard() {
		var aiOn = State.settings.ai && State.settings.ai.enabled;
		var btns = '<button class="ff-btn ff-btn--primary" data-action="start-queue" data-mode="optimize">⚡ Optimizar todo</button>';
		if ( aiOn ) {
			btns += '<button class="ff-btn ff-btn--accent" data-action="start-queue" data-mode="ai">🧠 Generar IA en todo</button>';
		}
		btns += '<button class="ff-btn ff-btn--danger" data-action="start-queue" data-mode="rollback">↩ Revertir todo</button>';

		return '<div class="ff-card ff-card--pad-lg" style="margin-bottom:18px">' +
			'<h3>Acciones masivas</h3>' +
			'<p class="ff-muted" style="margin:-6px 0 16px;font-size:13px">Aplica una acción a toda la biblioteca, por lotes. Mantén esta pestaña abierta mientras avanza la barra de progreso.</p>' +
			'<div class="ff-row" id="ff-bulk-buttons" style="gap:10px;flex-wrap:wrap">' + btns + '</div>' +
			( aiOn ? '' : '<p class="ff-muted ff-mt" style="font-size:12px">💡 Activa la IA en la pestaña <b>IA & SEO</b> para generar textos.</p>' ) +
			'<div id="ff-media-queue" class="ff-mt"></div>' +
		'</div>';
	}

	function sortOpt( v, label ) {
		return '<option value="' + v + '"' + ( State.media.orderby === v ? ' selected' : '' ) + '>' + label + '</option>';
	}

	/** Consulta el estado de la cola y pinta la barra de progreso de la Biblioteca. */
	function refreshQueueStatus() {
		return Api.get( '/queue/status' ).then( function ( res ) {
			State.queue = res.queue;
			if ( State.summary ) { State.summary.library = res.library; State.summary.queue = res.queue; }
			renderMediaQueueBar();
			if ( res.queue.status === 'running' ) { startDriving(); }
		} ).catch( function () {} );
	}

	/** Etiqueta legible del modo de cola. */
	function queueModeLabel( mode ) {
		if ( 'ai' === mode ) { return '(textos IA)'; }
		if ( 'both' === mode ) { return '(optimización + IA)'; }
		return '(optimización)';
	}

	/** Pinta la barra de progreso de la cola dentro de la Biblioteca. */
	function renderMediaQueueBar() {
		var el = document.getElementById( 'ff-media-queue' );
		if ( ! el ) { return; }
		var q = State.queue;
		if ( ! q || 'idle' === q.status ) { el.innerHTML = ''; return; }
		el.innerHTML = '<div style="border-top:1px solid var(--ff-border);padding-top:16px;margin-top:6px">' +
			'<div class="ff-row" style="justify-content:space-between;margin-bottom:10px">' +
				'<b>Progreso ' + queueModeLabel( q.mode ) + '</b>' + queuePill( q.status ) +
			'</div>' + queuePanel( q ) + '</div>';
	}

	/** Refresca cualquier vista de cola visible (dashboard y/o biblioteca). */
	function updateQueueViews() {
		if ( document.getElementById( 'ff-dash-body' ) ) { renderDashboardBody(); }
		if ( document.getElementById( 'ff-media-queue' ) ) { renderMediaQueueBar(); }
	}

	function tabBtn( id, label ) {
		return '<button data-status="' + id + '" class="' + ( State.media.status === id ? 'is-active' : '' ) + '">' + label + '</button>';
	}

	/** ¿Está seleccionado este id? */
	function isSelected( id ) { return State.media.selected.indexOf( id ) >= 0; }

	/** Casilla de selección de un elemento. */
	function selCheck( it ) {
		return '<input type="checkbox" class="ff-check" data-action="select" data-id="' + it.id + '"' + ( isSelected( it.id ) ? ' checked' : '' ) + ' title="Seleccionar">';
	}

	/** ¿Están seleccionados todos los de la página actual? */
	function allPageSelected() {
		return State.media.items.length > 0 && State.media.items.every( function ( it ) { return isSelected( it.id ); } );
	}

	/** Barra superior de la galería: "Seleccionar todo" + acciones sobre la selección. */
	function mediaTopBar() {
		var n = State.media.selected.length;
		var aiOn = State.settings.ai && State.settings.ai.enabled;
		var left = '<label class="ff-selall"><input type="checkbox" class="ff-check" data-action="select-all"' + ( allPageSelected() ? ' checked' : '' ) + '> Seleccionar todo' + ( n ? ' · ' + n + ' elegida(s)' : '' ) + '</label>';
		var right = '';
		if ( n ) {
			right = '<button class="ff-btn ff-btn--sm ff-btn--primary" data-action="sel-optimize">⚡ Optimizar</button>';
			if ( aiOn ) { right += '<button class="ff-btn ff-btn--sm ff-btn--accent" data-action="sel-ai">🧠 Generar IA</button>'; }
			right += '<button class="ff-btn ff-btn--sm ff-btn--danger" data-action="sel-rollback">↩ Revertir</button>';
			right += '<button class="ff-btn ff-btn--sm ff-btn--ghost" data-action="sel-clear">Limpiar</button>';
		}
		return '<div class="ff-selbar' + ( n ? ' is-active' : '' ) + '"><div class="ff-row" style="gap:10px">' + left + '</div>' +
			'<div class="ff-row" style="gap:8px;flex-wrap:wrap">' + right + '</div></div>';
	}

	/** Botones de acción de un elemento (compartidos por lista y cuadrícula). */
	function mediaItemActions( it ) {
		var a = '<button class="ff-btn ff-btn--sm ff-btn--ghost" data-action="detail" data-id="' + it.id + '">Detalles</button>';
		a += '<button class="ff-btn ff-btn--sm" data-action="optimize-one" data-id="' + it.id + '">Optimizar</button>';
		if ( State.settings.ai && State.settings.ai.enabled ) {
			a += '<button class="ff-btn ff-btn--sm" data-action="ai-one" data-id="' + it.id + '">IA</button>';
		}
		if ( it.has_backup ) {
			a += '<button class="ff-btn ff-btn--sm ff-btn--danger" data-action="rollback-one" data-id="' + it.id + '">Revertir</button>';
		}
		return a;
	}

	/* ============================================================
	 * Vista de detalle (ficha completa de una imagen)
	 * ============================================================ */
	function openDetail( id ) {
		showDetailOverlay( '<div class="ff-empty"><span class="ff-spinner"></span> Cargando ficha…</div>' );
		Api.get( '/media/detail?id=' + id ).then( function ( res ) {
			renderDetailModal( res.detail );
		} ).catch( function ( e ) { toast( e.message, 'error' ); closeDetail(); } );
	}

	function showDetailOverlay( inner ) {
		var ov = document.getElementById( 'ff-detail' );
		if ( ! ov ) {
			ov = document.createElement( 'div' );
			ov.id = 'ff-detail';
			ov.className = 'ff-modal';
			document.getElementById( 'fasterfy-app' ).appendChild( ov );
		}
		ov.innerHTML = '<div class="ff-modal__overlay" data-action="detail-close"></div><div class="ff-modal__panel">' + inner + '</div>';
	}

	function closeDetail() {
		var ov = document.getElementById( 'ff-detail' );
		if ( ov ) { ov.remove(); }
	}

	function detailRow( label, value ) {
		return '<div class="ff-drow"><span>' + h( label ) + '</span><b>' + value + '</b></div>';
	}

	function renderDetailModal( d ) {
		var statusBadge = '<span class="ff-badge ff-badge--' + d.status + '">' + h( d.status ) + '</span>';
		var aiB = aiBadge( d );
		var conv = ( d.format_from && d.format_to && d.format_from !== d.format_to )
			? ( h( d.format_from ) + ' → ' + h( d.format_to ) )
			: h( d.mime );
		var savings = d.original_size
			? ( '<b style="color:var(--ff-primary)">' + bytes( d.saved_bytes ) + '</b> (' + d.savings_percent + '%)' )
			: '<span class="ff-muted">—</span>';

		var info = detailRow( 'Estado', statusBadge + ' ' + ( aiB || '' ) ) +
			detailRow( 'Formato', conv ) +
			detailRow( 'Dimensiones', d.width && d.height ? ( d.width + ' × ' + d.height + ' px' ) : '—' ) +
			detailRow( 'Tamaño actual', d.filesize ? bytes( d.filesize ) : '—' ) +
			( d.original_size ? detailRow( 'Tamaño original', bytes( d.original_size ) ) : '' ) +
			( d.optimized_size ? detailRow( 'Tamaño optimizado', bytes( d.optimized_size ) ) : '' ) +
			detailRow( 'Ahorro', savings ) +
			( d.renamed_to ? detailRow( 'Renombrado a', h( d.renamed_to ) ) : '' ) +
			( d.optimized_at ? detailRow( 'Optimizado el', h( d.optimized_at ) + ' UTC' ) : '' ) +
			detailRow( 'Respaldo', d.has_backup ? 'Sí ✓' : 'No' );

		function textBlock( label, val ) {
			return '<div class="ff-dtext"><label>' + h( label ) + '</label><p>' + ( val ? h( val ) : '<i class="ff-muted">— vacío —</i>' ) + '</p></div>';
		}

		var actions = '<button class="ff-btn ff-btn--sm" data-action="optimize-one" data-id="' + d.id + '">⚡ Optimizar</button>';
		if ( State.settings.ai && State.settings.ai.enabled ) { actions += '<button class="ff-btn ff-btn--sm ff-btn--accent" data-action="ai-one" data-id="' + d.id + '">🧠 Generar IA</button>'; }
		if ( d.has_backup ) { actions += '<button class="ff-btn ff-btn--sm ff-btn--danger" data-action="rollback-one" data-id="' + d.id + '">↩ Revertir</button>'; }
		if ( d.edit_link ) { actions += '<a class="ff-btn ff-btn--sm ff-btn--ghost" href="' + d.edit_link + '" target="_blank" rel="noopener">Abrir en WP ↗</a>'; }

		var html =
			'<button class="ff-modal__close" data-action="detail-close" title="Cerrar">✕</button>' +
			'<div class="ff-detail">' +
				'<div class="ff-detail__media"><img src="' + ( d.preview || d.url || '' ) + '" alt=""><div class="ff-detail__file">' + h( d.filename ) + ' · #' + d.id + '</div></div>' +
				'<div class="ff-detail__info">' +
					'<h3 style="margin-top:0">' + ( d.title ? h( d.title ) : 'Imagen #' + d.id ) + '</h3>' +
					'<div class="ff-dgrid">' + info + '</div>' +
					'<div class="ff-section-title" style="margin:18px 0 10px">Textos (SEO)</div>' +
					textBlock( 'Texto alternativo (alt)', d.alt ) +
					textBlock( 'Título', d.title ) +
					textBlock( 'Leyenda', d.caption ) +
					textBlock( 'Descripción', d.description ) +
					'<div class="ff-row ff-mt" style="gap:8px;flex-wrap:wrap">' + actions + '</div>' +
				'</div>' +
			'</div>';
		showDetailOverlay( html );
	}

	/** Etiqueta del estado de IA. */
	function aiBadge( it ) {
		if ( 'error' === it.ai_status ) { return '<span class="ff-badge ff-badge--error">IA ✕</span>'; }
		if ( 'done' === it.ai_status ) { return '<span class="ff-badge ff-badge--optimized">IA ✓</span>'; }
		if ( 'blocked' === it.ai_status ) { return '<span class="ff-badge ff-badge--blocked">bloqueado</span>'; }
		return '';
	}

	/** Tarjeta de un elemento para la vista en cuadrícula. */
	function mediaCard( it ) {
		return '<div class="ff-mcard' + ( isSelected( it.id ) ? ' is-selected' : '' ) + '">' +
			'<div class="ff-mcard__thumb"><label class="ff-mcard__check">' + selCheck( it ) + '</label>' +
				'<img data-action="detail" data-id="' + it.id + '" style="cursor:pointer" src="' + ( it.thumb || '' ) + '" alt="" loading="lazy"></div>' +
			'<div class="ff-mcard__body">' +
				'<div class="ff-row" style="gap:6px;flex-wrap:wrap">' +
					'<span class="ff-badge ff-badge--' + it.status + '">' + h( it.status ) + '</span>' + aiBadge( it ) +
				'</div>' +
				'<div class="ff-mcard__alt">' + ( it.alt ? h( it.alt ) : '<i class="ff-muted">sin alt text</i>' ) + '</div>' +
				( it.saved_bytes ? '<div class="ff-muted" style="font-size:11px">Ahorro: <b style="color:var(--ff-accent)">' + bytes( it.saved_bytes ) + '</b></div>' : '' ) +
				'<div class="ff-row-actions" style="justify-content:flex-start;flex-wrap:wrap;margin-top:auto">' + mediaItemActions( it ) + '</div>' +
			'</div>' +
		'</div>';
	}

	function renderMediaTable() {
		var el = document.getElementById( 'ff-media-table' );
		if ( ! el ) { return; }
		if ( State.media.loading ) {
			el.innerHTML = '<div class="ff-empty"><span class="ff-spinner"></span> Cargando…</div>';
			return;
		}
		if ( ! State.media.items.length ) {
			el.innerHTML = '<div class="ff-empty">No hay activos en esta vista.</div>';
			return;
		}

		var pages = Math.ceil( State.media.total / 24 );
		var pager = pagination( State.media.page, pages, 'media-page' );
		var bar   = mediaTopBar();

		// Vista en cuadrícula (ideal para pantallas pequeñas).
		if ( 'grid' === State.media.view ) {
			el.innerHTML = bar + '<div class="ff-mediagrid">' + State.media.items.map( mediaCard ).join( '' ) + '</div>' + pager;
			return;
		}

		// Vista en lista (tabla).
		var rows = State.media.items.map( function ( it ) {
			var badge = '<span class="ff-badge ff-badge--' + it.status + '">' + h( it.status ) + '</span>';
			return '<tr data-row="' + it.id + '"' + ( isSelected( it.id ) ? ' class="is-selected"' : '' ) + '>' +
				'<td>' + selCheck( it ) + '</td>' +
				'<td><img class="ff-thumb" data-action="detail" data-id="' + it.id + '" style="cursor:pointer" src="' + ( it.thumb || '' ) + '" alt="" loading="lazy"></td>' +
				'<td><b>#' + it.id + '</b><br><span class="ff-muted">' + h( it.mime ) + '</span></td>' +
				'<td>' + badge + ( it.format_to ? '<br><span class="ff-muted" style="font-size:11px">' + h( it.format_to ) + '</span>' : '' ) + '</td>' +
				'<td>' + ( it.saved_bytes ? '<b style="color:var(--ff-accent)">' + bytes( it.saved_bytes ) + '</b>' : '<span class="ff-muted">—</span>' ) + '</td>' +
				'<td class="ff-muted" style="max-width:280px">' + ( it.alt ? h( it.alt ) : '<i>sin alt text</i>' ) + ' ' + aiBadge( it ) + '</td>' +
				'<td><div class="ff-row-actions">' + mediaItemActions( it ) + '</div></td>' +
			'</tr>';
		} ).join( '' );

		el.innerHTML = bar +
			'<table class="ff-table"><thead><tr>' +
				'<th style="width:32px"></th><th></th><th>ID</th><th>Estado</th><th>Ahorro</th><th>Alt text (IA)</th><th></th>' +
			'</tr></thead><tbody>' + rows + '</tbody></table>' + pager;
	}

	function pagination( page, pages, action ) {
		if ( pages <= 1 ) { return ''; }
		return '<div class="ff-paging">' +
			'<button class="ff-btn ff-btn--sm" data-action="' + action + '" data-page="' + ( page - 1 ) + '"' + ( page <= 1 ? ' disabled' : '' ) + '>←</button>' +
			'<span class="ff-muted">Página ' + page + ' de ' + pages + '</span>' +
			'<button class="ff-btn ff-btn--sm" data-action="' + action + '" data-page="' + ( page + 1 ) + '"' + ( page >= pages ? ' disabled' : '' ) + '>→</button>' +
		'</div>';
	}

	/* ============================================================
	 * Vista: Rendimiento (Performance Tracker)
	 * ============================================================ */
	function viewPerformance( view ) {
		view.innerHTML = topbar( 'Rendimiento', 'Impacto de FasterFy en el peso de tu sitio y en Web Core Vitals (estimación).', '' ) +
			'<div id="ff-perf-body"><div class="ff-empty"><span class="ff-spinner"></span> Calculando…</div></div>';
		loadSummary().then( renderPerformance );
	}

	function msHuman( ms ) {
		return ms >= 1000 ? ( ms / 1000 ).toFixed( 1 ) + ' s' : ms + ' ms';
	}

	function renderPerformance() {
		var body = document.getElementById( 'ff-perf-body' );
		if ( ! body || ! State.summary ) { return; }
		var lib = State.summary.library;
		var saved = lib.total_saved || 0;
		var optimized = lib.optimized || 0;
		var total = lib.total || 0;
		var ratio = pct( optimized, total );
		var avgConn = 1.5 * 1024 * 1024 / 8; // ~1.5 Mbps en bytes/s.
		var msSaved = Math.round( ( saved / avgConn ) * 1000 );
		var avgPerImg = optimized ? Math.round( saved / optimized ) : 0;
		var afterW = Math.max( 15, 100 - Math.round( ratio * 0.6 ) );
		var lcpGain = Math.min( 60, Math.round( ratio * 0.6 ) );

		body.innerHTML =
			'<div class="ff-grid ff-grid--stats ff-mt">' +
				statCard( 'Espacio ahorrado', bytes( saved ), 'Total acumulado', '💾' ) +
				statCard( 'Imágenes optimizadas', optimized, ratio + '% de la biblioteca', '✅' ) +
				statCard( 'Carga salvada (est.)', msHuman( msSaved ), 'Conexión móvil ~1.5 Mbps', '⚡' ) +
				statCard( 'Ahorro medio/imagen', bytes( avgPerImg ), 'Por activo optimizado', '📉' ) +
			'</div>' +
			'<div class="ff-grid ff-grid--2 ff-mt-lg">' +
				'<div class="ff-card ff-card--pad-lg"><h3>Progreso de optimización</h3>' +
					'<div class="ff-ring">' + perfDonut( ratio ) +
						'<div><div class="ff-card__sub">Optimizadas <b style="color:var(--ff-text)">' + optimized + '</b></div>' +
						'<div class="ff-card__sub" style="margin-top:6px">Pendientes <b style="color:var(--ff-text)">' + ( lib.pending || 0 ) + '</b></div>' +
						'<div class="ff-card__sub" style="margin-top:6px">Total <b style="color:var(--ff-text)">' + total + '</b></div></div>' +
					'</div>' +
				'</div>' +
				'<div class="ff-card ff-card--pad-lg"><h3>Composición de la biblioteca</h3>' + perfTypeBars( lib.by_type ) + '</div>' +
			'</div>' +
			'<div class="ff-card ff-card--pad-lg ff-mt-lg">' +
				'<h3>Comparativa antes / después</h3>' +
				'<div class="ff-compare"><div class="ff-compare__bar"><i>Antes — peso relativo de imágenes</i>' +
					'<div class="ff-bar-track ff-bar-before"><span data-grow="100%" style="width:0">100%</span></div></div></div>' +
				'<div class="ff-compare"><div class="ff-compare__bar"><i>Después de FasterFy <span class="ff-badge ff-badge--optimized">−' + ( 100 - afterW ) + '%</span></i>' +
					'<div class="ff-bar-track ff-bar-after"><span data-grow="' + afterW + '%" style="width:0">' + afterW + '%</span></div></div></div>' +
				'<div class="ff-metrics ff-mt-lg">' +
					'<div class="ff-metric"><b>' + bytes( saved ) + '</b><small>menos que transferir</small></div>' +
					'<div class="ff-metric"><b>' + msHuman( msSaved ) + '</b><small>tiempo de carga salvado</small></div>' +
					'<div class="ff-metric"><b>−' + lcpGain + '%</b><small>mejora estimada de LCP</small></div>' +
				'</div>' +
				'<p class="ff-muted ff-mt" style="font-size:12px">* Estimaciones a partir del volumen de bytes optimizados. Para métricas reales de Core Web Vitals, conecta Google PageSpeed Insights mediante el hook <code>fasterfy_performance_metrics</code>.</p>' +
			'</div>';

		animatePerf();
	}

	function perfDonut( ratio ) {
		var circ = 2 * Math.PI * 54;
		var off = circ - ( circ * ratio / 100 );
		return '<div class="ff-donut">' +
			'<svg width="150" height="150" viewBox="0 0 150 150">' +
				'<defs><linearGradient id="ffgrad" x1="0" y1="0" x2="1" y2="1">' +
					'<stop offset="0%" stop-color="#33ee33"/><stop offset="100%" stop-color="#18c93a"/>' +
				'</linearGradient></defs>' +
				'<circle class="ff-ring__track" cx="75" cy="75" r="54"/>' +
				'<circle class="ff-ring__bar" cx="75" cy="75" r="54" stroke-dasharray="' + circ + '" stroke-dashoffset="' + circ + '" data-doffset="' + off + '"/>' +
			'</svg>' +
			'<div class="ff-donut__label"><b>' + ratio + '%</b><span>optimizado</span></div>' +
		'</div>';
	}

	function perfTypeBars( byType ) {
		byType = byType || {};
		var names = { 'image/jpeg': 'JPEG', 'image/jpg': 'JPG', 'image/png': 'PNG', 'image/webp': 'WebP', 'image/avif': 'AVIF', 'image/svg+xml': 'SVG' };
		var keys = Object.keys( byType );
		if ( ! keys.length ) { return '<p class="ff-muted">Sin activos todavía.</p>'; }
		var max = Math.max.apply( null, keys.map( function ( k ) { return byType[ k ]; } ).concat( [ 1 ] ) );
		return keys.map( function ( k ) {
			var w = Math.round( ( byType[ k ] / max ) * 100 );
			return '<div class="ff-pbar"><i>' + h( names[ k ] || k ) + '</i>' +
				'<div class="ff-pbar__track"><span data-grow="' + w + '%" style="width:0"></span></div>' +
				'<b>' + byType[ k ] + '</b></div>';
		} ).join( '' );
	}

	function animatePerf() {
		setTimeout( function () {
			var body = document.getElementById( 'ff-perf-body' );
			if ( ! body ) { return; }
			body.querySelectorAll( '[data-grow]' ).forEach( function ( el ) { el.style.width = el.getAttribute( 'data-grow' ); } );
			body.querySelectorAll( '[data-doffset]' ).forEach( function ( el ) { el.setAttribute( 'stroke-dashoffset', el.getAttribute( 'data-doffset' ) ); } );
		}, 80 );
	}

	/* ============================================================
	 * Vista: IA & SEO
	 * ============================================================ */
	function viewAI( view ) {
		var ai = State.settings.ai || {};
		var mod = State.settings.moderation || {};
		var pro = isPro();
		view.innerHTML = topbar( 'IA & SEO', 'Reconocimiento de imagen, generación de Alt Text y moderación.',
			'<button class="ff-btn" data-action="ai-test">🔌 Probar conexión</button>' ) +
			'<div class="ff-card ff-card--pad-lg ff-mt">' +
				'<div class="ff-ai-note">🔎 FasterFy envía la imagen al proveedor de IA que configures para generar el texto. Los resultados pueden contener errores y conviene revisarlos; no sustituyen el criterio humano. No se usan tus imágenes para entrenar modelos. Recuerda declarar tu proveedor de IA en tu política de privacidad.</div>' +
				'<div class="ff-settings-grid">' +
					'<div class="ff-section-title">Modelo multimodal</div>' +
					toggleField( 'ai.enabled', 'Activar IA', ai.enabled, 'Habilita el análisis de visión y la generación de metadatos.' ) +
					field( 'ai.provider', 'Proveedor', selectInput( 'ai.provider', ai.provider, [ [ 'openai', 'OpenAI-compatible' ], [ 'fasterfy_cloud', 'FasterFy Cloud' ] ] ) ) +
					field( 'ai.api_base', 'Endpoint base', textInput( 'ai.api_base', ai.api_base ) ) +
					field( 'ai.api_key', 'API Key' + ( ai.has_api_key ? ' (configurada ✓)' : '' ), passwordInput( 'ai.api_key', '', ai.has_api_key ? '•••••••• (deja vacío para conservar)' : 'sk-…' ) ) +
					field( 'ai.vision_model', 'Modelo de visión', textInput( 'ai.vision_model', ai.vision_model ) ) +
					field( 'ai.language', 'Idioma de descripciones', textInput( 'ai.language', ai.language ) ) +
					( pro ? rangeField( 'ai.temperature', 'Temperatura (anti-alucinación)', ai.temperature, 0, 1, 0.05 ) : '' ) +
					( pro ? rangeField( 'ai.alt_max_length', 'Longitud máx. del Alt Text', ai.alt_max_length, 20, 300, 5 ) : '' ) +

					'<div class="ff-section-title">Generación</div>' +
					toggleField( 'ai.generate_alt', 'Generar Alt Text', ai.generate_alt, 'Inyecta _wp_attachment_image_alt de forma nativa.' ) +
					toggleField( 'ai.generate_title', 'Generar título / leyenda', ai.generate_title ) +
					toggleField( 'ai.hyphenate_title', 'Título con guiones (SEO)', ai.hyphenate_title, 'Separa las palabras del título con guiones. Ej.: Retrato-de-hombre-joven.' ) +
					toggleField( 'ai.generate_description', 'Generar descripción', ai.generate_description, 'Rellena el campo Descripción del adjunto.' ) +
					( pro ? toggleField( 'ai.semantic_rename', 'Renombrado semántico SEO', ai.semantic_rename, 'Renombra el archivo con keywords y actualiza la BD.' ) : '' ) +

					( pro ? '<div class="ff-section-title">Moderación de contenido (Pro)</div>' : '' ) +
					( pro ? toggleField( 'moderation.enabled', 'Moderación NSFW activa', mod.enabled, 'Evalúa cada activo antes de la IA generativa.' ) : '' ) +
					( pro ? toggleField( 'moderation.block_generative', 'Bloquear IA en contenido sensible', mod.block_generative, 'Optimiza técnicamente pero omite la IA generativa.' ) : '' ) +
					( pro ? rangeField( 'moderation.nsfw_threshold', 'Umbral de bloqueo', mod.nsfw_threshold, 0, 1, 0.05 ) : '' ) +
					( pro ? field( 'moderation.fallback_alt', 'Alt text de respaldo', textInput( 'moderation.fallback_alt', mod.fallback_alt ) ) : '' ) +
				'</div>' +
				( ! pro ? '<p class="ff-muted ff-mt" style="font-size:12px">Cambia a <b>Pro</b> para ajustar temperatura, renombrado semántico y moderación.</p>' : '' ) +
				'<div class="ff-row ff-mt-lg"><button class="ff-btn ff-btn--primary ff-btn--lg" data-action="save-settings">Guardar cambios</button></div>' +
			'</div>';
	}

	/* ============================================================
	 * Vista: Ajustes
	 * ============================================================ */
	function viewSettings( view ) {
		var c = State.settings.conversion || {};
		var t = State.settings.throttling || {};
		var a = State.settings.automation || {};
		var adv = State.settings.advanced || {};
		var ex = State.settings.exclusions || {};

		var pro = isPro();

		view.innerHTML = topbar( 'Ajustes', 'Configura el pipeline de optimización y la automatización.', '' ) +
			'<div class="ff-card ff-card--pad-lg ff-mt">' +
				'<div class="ff-settings-grid">' +
					'<div class="ff-section-title">Conversión y compresión</div>' +
					field( 'conversion.target_format', 'Formato objetivo (JPG/PNG)', selectInput( 'conversion.target_format', c.target_format, [ [ 'webp', 'WebP' ], [ 'avif', 'AVIF' ], [ 'auto', 'Automático (mejor disponible)' ] ] ) ) +
					field( 'conversion.png_strategy', 'Estrategia PNG', selectInput( 'conversion.png_strategy', c.png_strategy, [ [ 'lossy', 'Con pérdida (cuantización)' ], [ 'lossless', 'Sin pérdida' ] ] ) ) +
					toggleField( 'conversion.png_to_webp', 'Convertir PNG a WebP/AVIF si ahorra más', c.png_to_webp, 'Recomendado: usa el "Formato objetivo" elegido (WebP o AVIF, ambos conservan transparencia). Si no ahorra, intenta comprimir el PNG.' ) +
					rangeField( 'conversion.webp_quality', 'Calidad WebP', c.webp_quality, 1, 100, 1 ) +
					rangeField( 'conversion.avif_quality', 'Calidad AVIF', c.avif_quality, 1, 100, 1 ) +
					( pro ? rangeField( 'conversion.png_max_colors', 'Colores PNG (lossy)', c.png_max_colors, 2, 256, 2 ) : '' ) +
					( pro ? field( 'conversion.max_width', 'Ancho máximo (px, 0=off)', numberInput( 'conversion.max_width', c.max_width ) ) : '' ) +
					toggleField( 'conversion.sanitize_svg', 'Sanitizar SVG', c.sanitize_svg, 'Elimina scripts y metadatos de diseño.' ) +
					toggleField( 'conversion.strip_metadata', 'Eliminar metadatos EXIF', c.strip_metadata ) +
					toggleField( 'conversion.keep_original', 'Conservar original como respaldo', c.keep_original ) +

					'<div class="ff-section-title">Automatización</div>' +
					toggleField( 'automation.optimize_on_upload', 'Optimizar al subir', a.optimize_on_upload, 'Procesa automáticamente cada subida nueva.' ) +
					toggleField( 'automation.ai_on_upload', 'Aplicar IA al subir', a.ai_on_upload ) +

					( pro ? '<div class="ff-section-title">Concurrencia y throttling (Pro)</div>' : '' ) +
					( pro ? rangeField( 'throttling.batch_size', 'Tamaño de lote', t.batch_size, 1, 100, 1 ) : '' ) +
					( pro ? rangeField( 'throttling.max_concurrency', 'Concurrencia máx.', t.max_concurrency, 1, 50, 1 ) : '' ) +
					( pro ? rangeField( 'throttling.cooldown_seconds', 'Enfriamiento entre lotes (s)', t.cooldown_seconds, 0, 300, 1 ) : '' ) +

					( pro ? '<div class="ff-section-title">Exclusiones (Pro)</div>' : '' ) +
					( pro ? field( 'exclusions.directories', 'Directorios excluidos (uno por línea)', textareaInput( 'exclusions.directories', ( ex.directories || [] ).join( '\n' ) ) ) : '' ) +
					( pro ? field( 'exclusions.attachment_ids', 'IDs excluidos (separados por coma)', textInput( 'exclusions.attachment_ids', ( ex.attachment_ids || [] ).join( ',' ) ) ) : '' ) +

					'<div class="ff-section-title">Avanzado</div>' +
					field( 'advanced.log_level', 'Nivel de log', selectInput( 'advanced.log_level', adv.log_level, [ [ 'debug', 'Debug' ], [ 'info', 'Info' ], [ 'warning', 'Warning' ], [ 'error', 'Error' ] ] ) ) +
					toggleField( 'advanced.prefer_action_scheduler', 'Preferir Action Scheduler', adv.prefer_action_scheduler ) +
					toggleField( 'advanced.delete_data_on_uninstall', 'Borrar datos al desinstalar', adv.delete_data_on_uninstall ) +
				'</div>' +
				'<div class="ff-row ff-mt-lg">' +
					'<button class="ff-btn ff-btn--primary ff-btn--lg" data-action="save-settings">Guardar cambios</button>' +
					( ! pro ? '<span class="ff-muted">Cambia a <b>Pro</b> para opciones avanzadas.</span>' : '' ) +
				'</div>' +
			'</div>';
	}

	/* ============================================================
	 * Vista: Logs
	 * ============================================================ */
	function viewLogs( view ) {
		view.innerHTML = topbar( 'Registros', 'Trazabilidad del procesamiento, IA y errores.',
			'<button class="ff-btn ff-btn--danger" data-action="clear-logs">🗑 Limpiar</button>' ) +
			'<div class="ff-card"><div id="ff-logs-body"></div></div>';
		loadLogs();
	}

	function renderLogs() {
		var el = document.getElementById( 'ff-logs-body' );
		if ( ! el ) { return; }
		if ( State.logs.loading ) {
			el.innerHTML = '<div class="ff-empty"><span class="ff-spinner"></span> Cargando…</div>';
			return;
		}
		if ( ! State.logs.items.length ) {
			el.innerHTML = '<div class="ff-empty">No hay registros todavía.</div>';
			return;
		}
		var rows = State.logs.items.map( function ( r ) {
			return '<div class="ff-log__row ff-log">' +
				'<span class="ff-muted">' + h( r.created_at ) + '</span>' +
				'<span class="ff-log__lvl ' + r.level + '">' + r.level + '</span>' +
				'<span class="ff-muted">' + h( r.context ) + ( r.attachment_id ? ' #' + r.attachment_id : '' ) + '</span>' +
				'<span>' + h( r.message ) + '</span>' +
			'</div>';
		} ).join( '' );
		var pages = Math.ceil( State.logs.total / 50 );
		el.innerHTML = rows + pagination( State.logs.page, pages, 'logs-page' );
	}

	/* ============================================================
	 * Campos de formulario (helpers)
	 * ============================================================ */
	function field( key, label, input ) {
		return '<div class="ff-field"><label>' + h( label ) + '</label>' + input + '</div>';
	}
	function textInput( key, val ) {
		return '<input type="text" class="ff-input" data-setting="' + key + '" value="' + h( val || '' ) + '">';
	}
	function passwordInput( key, val, ph ) {
		return '<input type="password" class="ff-input" data-setting="' + key + '" value="' + h( val || '' ) + '" placeholder="' + h( ph || '' ) + '" autocomplete="new-password">';
	}
	function numberInput( key, val ) {
		return '<input type="number" class="ff-input" data-setting="' + key + '" value="' + h( val != null ? val : 0 ) + '">';
	}
	function textareaInput( key, val ) {
		return '<textarea class="ff-input" rows="3" data-setting="' + key + '" data-type="lines">' + h( val || '' ) + '</textarea>';
	}
	function selectInput( key, val, options ) {
		var opts = options.map( function ( o ) {
			return '<option value="' + o[ 0 ] + '"' + ( String( val ) === o[ 0 ] ? ' selected' : '' ) + '>' + h( o[ 1 ] ) + '</option>';
		} ).join( '' );
		return '<select class="ff-select" data-setting="' + key + '">' + opts + '</select>';
	}
	function rangeField( key, label, val, min, max, step ) {
		return '<div class="ff-field"><label>' + h( label ) + ' <b data-rangeval="' + key + '" style="color:var(--ff-primary-2)">' + ( val != null ? val : '' ) + '</b></label>' +
			'<input type="range" class="ff-range" data-setting="' + key + '" data-type="number" min="' + min + '" max="' + max + '" step="' + step + '" value="' + ( val != null ? val : min ) + '"></div>';
	}
	function toggleField( key, label, checked, hint ) {
		return '<div class="ff-field"><label class="ff-toggle">' +
			'<input type="checkbox" data-setting="' + key + '" data-type="bool"' + ( checked ? ' checked' : '' ) + '>' +
			'<span class="ff-toggle__track"></span><span>' + h( label ) + '</span></label>' +
			( hint ? '<div class="ff-hint">' + h( hint ) + '</div>' : '' ) + '</div>';
	}

	/* ============================================================
	 * Recolección y guardado de ajustes
	 * ============================================================ */
	function collectSettings() {
		var out = {};
		document.querySelectorAll( '[data-setting]' ).forEach( function ( el ) {
			var key = el.getAttribute( 'data-setting' );
			var type = el.getAttribute( 'data-type' );
			var val;
			if ( type === 'bool' ) { val = el.checked; }
			else if ( type === 'number' ) { val = parseFloat( el.value ); }
			else if ( type === 'lines' ) { val = el.value.split( '\n' ).map( function ( s ) { return s.trim(); } ).filter( Boolean ); }
			else { val = el.value; }

			// API key vacía => no la enviamos (se conserva la existente).
			if ( key === 'ai.api_key' && val === '' ) { return; }
			// IDs excluidos como array.
			if ( key === 'exclusions.attachment_ids' && typeof val === 'string' ) {
				val = val.split( ',' ).map( function ( s ) { return parseInt( s, 10 ); } ).filter( function ( n ) { return ! isNaN( n ); } );
			}
			setDeep( out, key, val );
		} );
		return out;
	}
	function setDeep( obj, path, val ) {
		var parts = path.split( '.' );
		var cur = obj;
		for ( var i = 0; i < parts.length - 1; i++ ) {
			cur[ parts[ i ] ] = cur[ parts[ i ] ] || {};
			cur = cur[ parts[ i ] ];
		}
		cur[ parts[ parts.length - 1 ] ] = val;
	}

	function saveSettings() {
		var payload = collectSettings();
		Api.post( '/settings', { settings: payload } ).then( function ( res ) {
			State.settings = res.settings;
			toast( 'Ajustes guardados correctamente.', 'success' );
		} ).catch( function ( e ) { toast( 'Error al guardar: ' + e.message, 'error' ); } );
	}

	/* ============================================================
	 * Cargadores de datos
	 * ============================================================ */
	function loadSummary() {
		return Api.get( '/summary' ).then( function ( res ) {
			State.summary = res;
			State.queue = res.queue;
			return res;
		} ).catch( function ( e ) { toast( e.message, 'error' ); } );
	}
	function loadMedia() {
		State.media.loading = true;
		renderMediaTable();
		return Api.get( '/media?status=' + State.media.status + '&page=' + State.media.page + '&per_page=24&orderby=' + encodeURIComponent( State.media.orderby ) + '&search=' + encodeURIComponent( State.media.search ) )
			.then( function ( res ) {
				State.media.items = res.items;
				State.media.total = res.total;
				State.media.loading = false;
				renderMediaTable();
			} ).catch( function ( e ) { State.media.loading = false; toast( e.message, 'error' ); renderMediaTable(); } );
	}
	function loadLogs() {
		State.logs.loading = true;
		renderLogs();
		return Api.get( '/logs?page=' + State.logs.page + '&per_page=50' ).then( function ( res ) {
			State.logs.items = res.items;
			State.logs.total = res.total;
			State.logs.loading = false;
			renderLogs();
		} ).catch( function ( e ) { State.logs.loading = false; toast( e.message, 'error' ); renderLogs(); } );
	}

	/* ============================================================
	 * Polling de la cola
	 * ============================================================ */
	function startDriving() {
		if ( State.driving ) { return; }
		State.driving = true;
		State.driveErrors = 0;
		driveTick();
	}
	function stopDriving() {
		State.driving = false;
	}
	function driveTick() {
		if ( ! State.driving ) { return; }
		Api.post( '/queue/run' ).then( function ( res ) {
			State.driveErrors = 0;
			State.queue = res.queue;
			if ( State.summary ) { State.summary.library = res.library; State.summary.queue = res.queue; }
			updateQueueViews();
			if ( 'running' === res.queue.status ) {
				setTimeout( driveTick, 500 );
			} else {
				stopDriving();
				if ( 'completed' === res.queue.status ) { toast( 'Procesamiento completado 🎉', 'success' ); }
				if ( 'exhausted' === res.queue.status ) { toast( 'Cola pausada: cuota de créditos agotada.', 'info' ); }
				if ( 'media' === State.route ) { loadMedia(); }
				if ( 'dashboard' === State.route ) { loadSummary().then( renderDashboardBody ); }
			}
		} ).catch( function ( e ) {
			State.driveErrors = ( State.driveErrors || 0 ) + 1;
			if ( State.driveErrors > 3 ) {
				stopDriving();
				toast( 'Procesamiento detenido por errores repetidos: ' + e.message, 'error' );
			} else {
				setTimeout( driveTick, 2500 );
			}
		} );
	}

	/* ============================================================
	 * Acciones (event delegation)
	 * ============================================================ */
	/** Ejecuta una petición por cada id, en secuencia, con aviso de progreso. */
	function runSequential( ids, makeReq, doneLabel ) {
		var total = ids.length;
		var ok = 0;
		toast( 'Procesando ' + total + ' imagen(es)…', 'info' );
		function next() {
			if ( ! ids.length ) {
				toast( doneLabel + ': ' + ok + '/' + total + ' ✓', 'success' );
				State.media.selected = [];
				loadMedia();
				return;
			}
			var id = ids.shift();
			makeReq( id ).then( function () { ok++; } ).catch( function () {} ).then( function () { next(); } );
		}
		next();
	}

	function onClick( e ) {
		var nav = e.target.closest( '[data-route]' );
		if ( nav ) { State.route = nav.getAttribute( 'data-route' ); renderShell(); return; }

		var actionEl = e.target.closest( '[data-action]' );
		if ( ! actionEl ) { return; }
		var action = actionEl.getAttribute( 'data-action' );

		switch ( action ) {
			case 'mode':
				var modeBtn = e.target.closest( '[data-mode]' );
				if ( modeBtn ) {
					State.settings.mode = modeBtn.getAttribute( 'data-mode' );
					Api.post( '/settings', { settings: { mode: State.settings.mode } } ).then( function ( r ) { State.settings = r.settings; } );
					renderShell();
				}
				break;
			case 'theme':
				State.theme = State.theme === 'dark' ? 'light' : 'dark';
				localStorage.setItem( 'fasterfy_theme', State.theme );
				renderShell();
				break;
			case 'start-queue':
				var mode = actionEl.getAttribute( 'data-mode' ) || 'optimize';
				if ( 'rollback' === mode && ! window.confirm( '¿Revertir TODA la biblioteca a las imágenes originales? Esto deshace la optimización en todas las que tengan respaldo.' ) ) {
					break;
				}
				Api.post( '/queue/start', { mode: mode } ).then( function ( res ) {
					State.queue = res.queue; State.driveErrors = 0; toast( 'Procesamiento iniciado en segundo plano.', 'success' );
					updateQueueViews();
					startDriving();
				} ).catch( function ( er ) { toast( er.message, 'error' ); } );
				break;
			case 'pause-queue':
				Api.post( '/queue/pause' ).then( function ( res ) { State.queue = res.queue; stopDriving(); updateQueueViews(); toast( 'Cola pausada.', 'info' ); } );
				break;
			case 'resume-queue':
				Api.post( '/queue/resume' ).then( function ( res ) { State.queue = res.queue; startDriving(); updateQueueViews(); toast( 'Cola reanudada.', 'success' ); } );
				break;
			case 'cancel-queue':
				Api.post( '/queue/cancel' ).then( function ( res ) { State.queue = res.queue; stopDriving(); updateQueueViews(); toast( 'Cola detenida.', 'info' ); } );
				break;
			case 'media-tab':
				var statusBtn = e.target.closest( '[data-status]' );
				if ( statusBtn ) { State.media.status = statusBtn.getAttribute( 'data-status' ); State.media.page = 1; renderRoute(); }
				break;
			case 'media-view':
				var viewBtn = e.target.closest( '[data-view]' );
				if ( viewBtn ) {
					State.media.view = viewBtn.getAttribute( 'data-view' );
					try { localStorage.setItem( 'fasterfy_media_view', State.media.view ); } catch ( err ) {}
					renderRoute();
				}
				break;
			case 'media-page':
				State.media.page = parseInt( actionEl.getAttribute( 'data-page' ), 10 ); loadMedia();
				break;
			case 'logs-page':
				State.logs.page = parseInt( actionEl.getAttribute( 'data-page' ), 10 ); loadLogs();
				break;
			case 'optimize-one':
				itemAction( actionEl, '/optimize', { id: +actionEl.getAttribute( 'data-id' ), mode: 'optimize' }, 'Optimizado' );
				break;
			case 'ai-one':
				itemAction( actionEl, '/ai/item', { id: +actionEl.getAttribute( 'data-id' ) }, 'IA aplicada' );
				break;
			case 'rollback-one':
				itemAction( actionEl, '/rollback', { id: +actionEl.getAttribute( 'data-id' ) }, 'Revertido al original' );
				break;
			case 'select':
				var cb = e.target.closest( '[data-action="select"]' );
				if ( cb ) {
					var sid = +cb.getAttribute( 'data-id' );
					var idx = State.media.selected.indexOf( sid );
					if ( cb.checked && idx < 0 ) { State.media.selected.push( sid ); }
					else if ( ! cb.checked && idx >= 0 ) { State.media.selected.splice( idx, 1 ); }
					renderMediaTable();
				}
				break;
			case 'sel-clear':
				State.media.selected = [];
				renderMediaTable();
				break;
			case 'detail':
				openDetail( +actionEl.getAttribute( 'data-id' ) );
				break;
			case 'detail-close':
				closeDetail();
				break;
			case 'hero-dot':
				heroGo( parseInt( actionEl.getAttribute( 'data-i' ), 10 ) );
				if ( State.heroTimer ) { clearInterval( State.heroTimer ); startHero(); }
				break;
			case 'select-all':
				if ( allPageSelected() ) {
					var pidsOff = State.media.items.map( function ( it ) { return it.id; } );
					State.media.selected = State.media.selected.filter( function ( id ) { return pidsOff.indexOf( id ) < 0; } );
				} else {
					State.media.items.forEach( function ( it ) { if ( State.media.selected.indexOf( it.id ) < 0 ) { State.media.selected.push( it.id ); } } );
				}
				renderMediaTable();
				break;
			case 'sel-optimize':
				runSequential( State.media.selected.slice(), function ( id ) { return Api.post( '/optimize', { id: id, mode: 'optimize' } ); }, 'Optimizadas' );
				break;
			case 'sel-ai':
				runSequential( State.media.selected.slice(), function ( id ) { return Api.post( '/ai/item', { id: id } ); }, 'Textos generados' );
				break;
			case 'sel-rollback':
				runSequential( State.media.selected.slice(), function ( id ) { return Api.post( '/rollback', { id: id } ); }, 'Revertidas' );
				break;
			case 'save-settings':
				saveSettings();
				break;
			case 'clear-logs':
				Api.del( '/logs' ).then( function () { State.logs.page = 1; loadLogs(); toast( 'Registros limpiados.', 'success' ); } );
				break;
			case 'ai-test':
				actionEl.innerHTML = '<span class="ff-spinner"></span> Probando…';
				Api.post( '/ai/test' ).then( function ( res ) {
					actionEl.innerHTML = '🔌 Probar conexión';
					toast( res.health.message, res.health.ok ? 'success' : 'error' );
				} ).catch( function ( er ) { actionEl.innerHTML = '🔌 Probar conexión'; toast( er.message, 'error' ); } );
				break;
		}
	}

	function itemAction( btn, path, body, okMsg ) {
		var original = btn.innerHTML;
		btn.innerHTML = '<span class="ff-spinner"></span>';
		btn.disabled = true;
		Api.post( path, body ).then( function () {
			toast( okMsg, 'success' );
			loadMedia();
		} ).catch( function ( e ) {
			btn.innerHTML = original; btn.disabled = false;
			toast( e.message, 'error' );
		} );
	}

	function onInput( e ) {
		var el = e.target;
		if ( el.classList.contains( 'ff-range' ) ) {
			var key = el.getAttribute( 'data-setting' );
			var label = document.querySelector( '[data-rangeval="' + key + '"]' );
			if ( label ) { label.textContent = el.value; }
		}
		if ( 'ff-search' === el.id ) {
			clearTimeout( State._searchTimer );
			State._searchTimer = setTimeout( function () {
				State.media.search = el.value.trim();
				State.media.page = 1;
				loadMedia();
			}, 450 );
		}
		if ( 'ff-orderby' === el.id ) {
			State.media.orderby = el.value;
			State.media.page = 1;
			loadMedia();
		}
	}

	/* ============================================================
	 * Init
	 * ============================================================ */
	function init() {
		var app = document.getElementById( 'fasterfy-app' );
		if ( ! app ) { return; }
		app.addEventListener( 'click', onClick );
		app.addEventListener( 'input', onInput );
		renderShell();
		// Si hay una cola en marcha al cargar, arranca el polling.
		loadSummary().then( function ( res ) {
			if ( res && res.queue && res.queue.status === 'running' ) { startDriving(); }
			renderDashboardBody();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
