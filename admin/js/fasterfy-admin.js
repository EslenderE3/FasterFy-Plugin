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
		media: { items: [], total: 0, page: 1, status: 'all', loading: false },
		logs: { items: [], total: 0, page: 1, loading: false },
		polling: null
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
		var wrap = document.querySelector( '.ff-toasts' );
		if ( ! wrap ) {
			wrap = document.createElement( 'div' );
			wrap.className = 'ff-toasts';
			document.body.appendChild( wrap );
		}
		var t = document.createElement( 'div' );
		t.className = 'ff-toast ff-toast--' + ( type || 'info' );
		t.textContent = msg;
		wrap.appendChild( t );
		setTimeout( function () {
			t.style.opacity = '0';
			setTimeout( function () { t.remove(); }, 300 );
		}, 3200 );
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
		{ id: 'dashboard', label: 'Resumen', icon: 'dashboard' },
		{ id: 'media', label: 'Biblioteca', icon: 'media' },
		{ id: 'performance', label: 'Rendimiento', icon: 'perf' },
		{ id: 'ai', label: 'IA & SEO', icon: 'ai' },
		{ id: 'settings', label: 'Ajustes', icon: 'settings' },
		{ id: 'logs', label: 'Registros', icon: 'logs' }
	];

	function renderShell() {
		var app = document.getElementById( 'fasterfy-app' );
		app.removeAttribute( 'data-loading' );
		app.setAttribute( 'data-theme', State.theme );

		var nav = NAV.map( function ( n ) {
			return '<div class="ff-nav__item' + ( State.route === n.id ? ' is-active' : '' ) + '" data-route="' + n.id + '">' +
				ICONS[ n.icon ] + '<span>' + n.label + '</span></div>';
		} ).join( '' );

		app.innerHTML =
			'<div class="ff-shell">' +
				'<aside class="ff-sidebar">' +
					'<div class="ff-brand"><span class="ff-brand__dot"></span>FasterFy</div>' +
					'<div class="ff-brand__tag">AI Media Optimizer</div>' +
					'<nav class="ff-nav">' + nav + '</nav>' +
					'<div class="ff-sidebar__foot">' +
						'<div class="ff-modeswitch" data-action="mode">' +
							'<button data-mode="lite" class="' + ( ! isPro() ? 'is-active' : '' ) + '">Lite</button>' +
							'<button data-mode="pro" class="' + ( isPro() ? 'is-active' : '' ) + '">Pro</button>' +
						'</div>' +
						'<button class="ff-btn ff-btn--ghost ff-btn--sm ff-mt" data-action="theme" style="width:100%">' +
							( State.theme === 'dark' ? '☀ Tema claro' : '🌙 Tema oscuro' ) + '</button>' +
					'</div>' +
				'</aside>' +
				'<main class="ff-main" id="ff-view"></main>' +
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
			'<div id="ff-dash-body"><div class="ff-empty"><span class="ff-spinner"></span> Cargando datos…</div></div>';

		loadSummary().then( renderDashboardBody );
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
								'<stop offset="0%" stop-color="#6d5efc"/><stop offset="100%" stop-color="#1fd1a3"/>' +
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
			'<div class="ff-toolbar">' +
				'<div class="ff-tabs" data-action="media-tab">' +
					tabBtn( 'all', 'Todos' ) + tabBtn( 'pending', 'Pendientes' ) + tabBtn( 'optimized', 'Optimizados' ) +
				'</div>' +
			'</div>' +
			'<div class="ff-card"><div id="ff-media-table"></div></div>';
		loadMedia();
		refreshQueueStatus();
	}

	/**
	 * Tarjeta de acciones masivas (procesamiento de toda la biblioteca por lotes).
	 */
	function bulkActionsCard() {
		var aiOn = State.settings.ai && State.settings.ai.enabled;
		var buttons = '<button class="ff-btn ff-btn--primary" data-action="start-queue" data-mode="optimize">⚡ Optimizar todo</button>';
		if ( aiOn ) {
			buttons += '<button class="ff-btn ff-btn--accent" data-action="start-queue" data-mode="both">✨ Optimizar + IA (todo)</button>';
			buttons += '<button class="ff-btn" data-action="start-queue" data-mode="ai">🧠 Generar textos IA (todo)</button>';
		}
		return '<div class="ff-card ff-card--pad-lg" style="margin-bottom:18px">' +
			'<h3>Acciones masivas</h3>' +
			'<p class="ff-muted" style="margin:-6px 0 16px;font-size:13px">Procesa toda la biblioteca en segundo plano, por lotes. Puedes cerrar esta página: el trabajo continúa en el servidor.</p>' +
			'<div class="ff-row" id="ff-bulk-buttons">' + buttons + '</div>' +
			( aiOn ? '' : '<p class="ff-muted ff-mt" style="font-size:12px">💡 Activa la IA en la pestaña <b>IA & SEO</b> para generar textos en lote.</p>' ) +
			'<div id="ff-media-queue" class="ff-mt"></div>' +
		'</div>';
	}

	/** Consulta el estado de la cola y pinta la barra de progreso de la Biblioteca. */
	function refreshQueueStatus() {
		return Api.get( '/queue/status' ).then( function ( res ) {
			State.queue = res.queue;
			if ( State.summary ) { State.summary.library = res.library; State.summary.queue = res.queue; }
			renderMediaQueueBar();
			if ( res.queue.status === 'running' ) { startPolling(); }
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
		var rows = State.media.items.map( function ( it ) {
			var badge = '<span class="ff-badge ff-badge--' + it.status + '">' + h( it.status ) + '</span>';
			var actions = '<div class="ff-row-actions">';
			actions += '<button class="ff-btn ff-btn--sm" data-action="optimize-one" data-id="' + it.id + '">Optimizar</button>';
			if ( State.settings.ai && State.settings.ai.enabled ) {
				actions += '<button class="ff-btn ff-btn--sm" data-action="ai-one" data-id="' + it.id + '">IA</button>';
			}
			if ( it.has_backup ) {
				actions += '<button class="ff-btn ff-btn--sm ff-btn--danger" data-action="rollback-one" data-id="' + it.id + '">Revertir</button>';
			}
			actions += '</div>';

			return '<tr data-row="' + it.id + '">' +
				'<td><img class="ff-thumb" src="' + ( it.thumb || '' ) + '" alt=""></td>' +
				'<td><b>#' + it.id + '</b><br><span class="ff-muted">' + h( it.mime ) + '</span></td>' +
				'<td>' + badge + ( it.format_to ? '<br><span class="ff-muted" style="font-size:11px">' + h( it.format_to ) + '</span>' : '' ) + '</td>' +
				'<td>' + ( it.saved_bytes ? '<b style="color:var(--ff-accent)">' + bytes( it.saved_bytes ) + '</b>' : '<span class="ff-muted">—</span>' ) + '</td>' +
				'<td class="ff-muted" style="max-width:280px">' + ( it.alt ? h( it.alt ) : '<i>sin alt text</i>' ) +
					( 'error' === it.ai_status ? ' <span class="ff-badge ff-badge--error">IA ✕</span>' : ( 'done' === it.ai_status ? ' <span class="ff-badge ff-badge--optimized">IA ✓</span>' : ( 'blocked' === it.ai_status ? ' <span class="ff-badge ff-badge--blocked">bloqueado</span>' : '' ) ) ) + '</td>' +
				'<td>' + actions + '</td>' +
			'</tr>';
		} ).join( '' );

		var pages = Math.ceil( State.media.total / 20 );
		el.innerHTML =
			'<table class="ff-table"><thead><tr>' +
				'<th></th><th>ID</th><th>Estado</th><th>Ahorro</th><th>Alt text (IA)</th><th></th>' +
			'</tr></thead><tbody>' + rows + '</tbody></table>' + pagination( State.media.page, pages, 'media-page' );
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
		view.innerHTML = topbar( 'Rendimiento', 'Impacto de FasterFy en Web Core Vitals (estimación).', '' ) +
			'<div id="ff-perf-body"><div class="ff-empty"><span class="ff-spinner"></span> Calculando…</div></div>';
		loadSummary().then( function () {
			var body = document.getElementById( 'ff-perf-body' );
			if ( ! body ) { return; }
			var lib = State.summary.library;
			var saved = lib.total_saved || 0;
			// Estimaciones derivadas (didácticas) del ahorro de bytes.
			var avgConn = 1.5 * 1024 * 1024 / 8; // ~1.5 Mbps en bytes/s.
			var msSaved = Math.round( ( saved / avgConn ) * 1000 );
			var lcpGain = Math.min( 60, Math.round( pct( lib.optimized, lib.total ) * 0.6 ) );

			body.innerHTML =
				'<div class="ff-card ff-card--pad-lg ff-mt">' +
					'<h3>Retorno de inversión técnico</h3>' +
					'<div class="ff-metrics">' +
						'<div class="ff-metric"><b>' + bytes( saved ) + '</b><small>Bytes ahorrados (total)</small></div>' +
						'<div class="ff-metric"><b>' + ( msSaved > 1000 ? ( msSaved / 1000 ).toFixed( 1 ) + ' s' : msSaved + ' ms' ) + '</b><small>Tiempo de carga estimado salvado</small></div>' +
						'<div class="ff-metric"><b>-' + lcpGain + '%</b><small>Mejora estimada de LCP</small></div>' +
					'</div>' +
				'</div>' +
				'<div class="ff-card ff-card--pad-lg ff-mt-lg">' +
					'<h3>Comparativa antes / después</h3>' +
					perfBars( lib ) +
					'<p class="ff-muted ff-mt" style="font-size:12px">* Métricas estimadas a partir del volumen de bytes optimizados. Para datos reales conecta Google PageSpeed Insights mediante el hook <code>fasterfy_performance_metrics</code>.</p>' +
				'</div>';
		} );
	}

	function perfBars( lib ) {
		var optimized = lib.optimized || 0;
		var total = lib.total || 1;
		var beforeW = 100;
		var afterW = Math.max( 12, 100 - Math.round( ( optimized / total ) * 70 ) );
		return '<div class="ff-compare"><div class="ff-compare__bar"><i>Antes (peso de página relativo)</i>' +
			'<div class="ff-bar-track ff-bar-before"><span style="width:' + beforeW + '%"></span></div></div></div>' +
			'<div class="ff-compare"><div class="ff-compare__bar"><i>Después de FasterFy</i>' +
			'<div class="ff-bar-track ff-bar-after"><span style="width:' + afterW + '%"></span></div></div></div>';
	}

	/* ============================================================
	 * Vista: IA & SEO
	 * ============================================================ */
	function viewAI( view ) {
		var ai = State.settings.ai || {};
		var mod = State.settings.moderation || {};
		view.innerHTML = topbar( 'IA & SEO', 'Reconocimiento de imagen, generación de Alt Text y moderación.',
			'<button class="ff-btn" data-action="ai-test">🔌 Probar conexión</button>' ) +
			'<div class="ff-card ff-card--pad-lg ff-mt">' +
				'<div class="ff-settings-grid">' +
					'<div class="ff-section-title">Modelo multimodal</div>' +
					toggleField( 'ai.enabled', 'Activar IA', ai.enabled, 'Habilita el análisis de visión y la generación de metadatos.' ) +
					field( 'ai.provider', 'Proveedor', selectInput( 'ai.provider', ai.provider, [ [ 'openai', 'OpenAI-compatible' ], [ 'fasterfy_cloud', 'FasterFy Cloud' ] ] ) ) +
					field( 'ai.api_base', 'Endpoint base', textInput( 'ai.api_base', ai.api_base ) ) +
					field( 'ai.api_key', 'API Key' + ( ai.has_api_key ? ' (configurada ✓)' : '' ), passwordInput( 'ai.api_key', '', ai.has_api_key ? '•••••••• (deja vacío para conservar)' : 'sk-…' ) ) +
					field( 'ai.vision_model', 'Modelo de visión', textInput( 'ai.vision_model', ai.vision_model ) ) +
					field( 'ai.language', 'Idioma de descripciones', textInput( 'ai.language', ai.language ) ) +
					rangeField( 'ai.temperature', 'Temperatura (anti-alucinación)', ai.temperature, 0, 1, 0.05 ) +
					rangeField( 'ai.alt_max_length', 'Longitud máx. del Alt Text', ai.alt_max_length, 20, 300, 5 ) +

					'<div class="ff-section-title">Generación</div>' +
					toggleField( 'ai.generate_alt', 'Generar Alt Text', ai.generate_alt, 'Inyecta _wp_attachment_image_alt de forma nativa.' ) +
					toggleField( 'ai.generate_title', 'Generar título / leyenda', ai.generate_title ) +
					toggleField( 'ai.hyphenate_title', 'Título con guiones (SEO)', ai.hyphenate_title, 'Separa las palabras del título con guiones. Ej.: Retrato-de-hombre-joven.' ) +
					toggleField( 'ai.generate_description', 'Generar descripción', ai.generate_description, 'Rellena el campo Descripción del adjunto.' ) +
					toggleField( 'ai.semantic_rename', 'Renombrado semántico SEO', ai.semantic_rename, 'Renombra el archivo con keywords y actualiza la BD.' ) +

					'<div class="ff-section-title">Moderación de contenido</div>' +
					toggleField( 'moderation.enabled', 'Moderación NSFW activa', mod.enabled, 'Evalúa cada activo antes de la IA generativa.' ) +
					toggleField( 'moderation.block_generative', 'Bloquear IA en contenido sensible', mod.block_generative, 'Optimiza técnicamente pero omite la IA generativa.' ) +
					rangeField( 'moderation.nsfw_threshold', 'Umbral de bloqueo', mod.nsfw_threshold, 0, 1, 0.05 ) +
					field( 'moderation.fallback_alt', 'Alt text de respaldo', textInput( 'moderation.fallback_alt', mod.fallback_alt ) ) +
				'</div>' +
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
					field( 'conversion.target_format', 'Formato objetivo (JPG)', selectInput( 'conversion.target_format', c.target_format, [ [ 'webp', 'WebP' ], [ 'avif', 'AVIF' ], [ 'auto', 'Automático (mejor disponible)' ] ] ) ) +
					field( 'conversion.png_strategy', 'Estrategia PNG', selectInput( 'conversion.png_strategy', c.png_strategy, [ [ 'lossy', 'Con pérdida (cuantización)' ], [ 'lossless', 'Sin pérdida' ] ] ) ) +
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
		return Api.get( '/media?status=' + State.media.status + '&page=' + State.media.page + '&per_page=20' )
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
	function startPolling() {
		stopPolling();
		State.polling = setInterval( function () {
			Api.get( '/queue/status' ).then( function ( res ) {
				State.queue = res.queue;
				if ( State.summary ) { State.summary.library = res.library; State.summary.queue = res.queue; }
				updateQueueViews();
				if ( res.queue.status !== 'running' ) {
					stopPolling();
					if ( 'completed' === res.queue.status ) { toast( 'Procesamiento completado 🎉', 'success' ); }
					if ( 'exhausted' === res.queue.status ) { toast( 'Cola pausada: cuota de créditos agotada.', 'info' ); }
					if ( 'media' === State.route ) { loadMedia(); }
				}
			} );
		}, 2500 );
	}
	function stopPolling() {
		if ( State.polling ) { clearInterval( State.polling ); State.polling = null; }
	}

	/* ============================================================
	 * Acciones (event delegation)
	 * ============================================================ */
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
				Api.post( '/queue/start', { mode: mode } ).then( function ( res ) {
					State.queue = res.queue; toast( 'Procesamiento iniciado en segundo plano.', 'success' );
					updateQueueViews();
					startPolling();
				} ).catch( function ( er ) { toast( er.message, 'error' ); } );
				break;
			case 'pause-queue':
				Api.post( '/queue/pause' ).then( function ( res ) { State.queue = res.queue; stopPolling(); updateQueueViews(); toast( 'Cola pausada.', 'info' ); } );
				break;
			case 'resume-queue':
				Api.post( '/queue/resume' ).then( function ( res ) { State.queue = res.queue; startPolling(); updateQueueViews(); toast( 'Cola reanudada.', 'success' ); } );
				break;
			case 'cancel-queue':
				Api.post( '/queue/cancel' ).then( function ( res ) { State.queue = res.queue; stopPolling(); updateQueueViews(); toast( 'Cola detenida.', 'info' ); } );
				break;
			case 'media-tab':
				var statusBtn = e.target.closest( '[data-status]' );
				if ( statusBtn ) { State.media.status = statusBtn.getAttribute( 'data-status' ); State.media.page = 1; renderRoute(); }
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
			if ( res && res.queue && res.queue.status === 'running' ) { startPolling(); }
			renderDashboardBody();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
