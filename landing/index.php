<?php
/**
 * FasterFy — Pre-launch landing page.
 *
 * @package FasterFy\Landing
 */

declare( strict_types=1 );

namespace FasterFy\Landing;

require __DIR__ . '/includes/bootstrap.php';

send_security_headers();

$csrf = csrf_token();

// Stamp the moment the form was served (used by the time-trap on submit).
$_SESSION[ FORM_RENDERED_KEY ] = time();

$asset_v = '1.2.0'; // Bump to bust cache on deploy.
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#1F1F1F">

	<title>FasterFy — AI Media Optimizer for WordPress | Join the waitlist</title>
	<meta name="description" content="FasterFy automates WordPress image optimization: WebP/AVIF conversion, smart compression, AI alt text &amp; SEO, bulk gallery management and 1-click rollback. Join the pre-launch waitlist.">

	<link rel="canonical" href="https://fasterfy.app/">
	<meta name="robots" content="index, follow">

	<!-- Open Graph / social -->
	<meta property="og:type" content="website">
	<meta property="og:site_name" content="FasterFy">
	<meta property="og:title" content="FasterFy — AI Media Optimizer for WordPress">
	<meta property="og:description" content="Stop optimizing images by hand. WebP/AVIF, AI alt text &amp; SEO, bulk processing and 1-click rollback for WordPress. Join the waitlist.">
	<meta property="og:image" content="assets/img/og-image.svg">
	<meta property="og:url" content="https://fasterfy.app/">
	<meta name="twitter:card" content="summary_large_image">
	<meta name="twitter:title" content="FasterFy — AI Media Optimizer for WordPress">
	<meta name="twitter:description" content="Automate WordPress image optimization with AI. Join the pre-launch waitlist.">
	<meta name="twitter:image" content="assets/img/og-image.svg">

	<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
	<link rel="apple-touch-icon" href="assets/img/favicon.svg">

	<!-- Preload critical assets for a fast LCP -->
	<link rel="preload" as="image" href="assets/img/fasterfy-mark.svg">

	<!-- Fonts: Space Grotesk (display) + Inter (body) -->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">

	<link rel="stylesheet" href="assets/css/styles.css?v=<?php echo rawurlencode( $asset_v ); ?>">

	<!-- Structured data -->
	<script type="application/ld+json">
	{
		"@context": "https://schema.org",
		"@type": "SoftwareApplication",
		"name": "FasterFy",
		"applicationCategory": "Plugin, BusinessApplication",
		"operatingSystem": "WordPress",
		"description": "AI-powered media optimizer for WordPress: WebP/AVIF conversion, smart compression, AI alt text and SEO, bulk gallery management and non-destructive rollback.",
		"offers": { "@type": "Offer", "price": "0", "priceCurrency": "USD" }
	}
	</script>
</head>
<body>
	<a class="skip-link" href="#waitlist" data-i18n="skip">Skip to the waitlist</a>

	<!-- ============================ HEADER ============================ -->
	<header class="site-header" id="top">
		<div class="container header__inner">
			<a class="brand" href="#top" aria-label="FasterFy home">
				<img class="brand__mark" src="assets/img/fasterfy-mark.svg" width="36" height="36" alt="" aria-hidden="true">
				<span class="brand__word">Faster<em>Fy</em></span>
			</a>

			<nav class="nav" aria-label="Primary">
				<a href="#features" data-i18n="nav.features">Features</a>
				<a href="#how" data-i18n="nav.how">How it works</a>
				<a href="#pricing" data-i18n="nav.pricing">Pricing</a>
				<a href="#faq" data-i18n="nav.faq">FAQ</a>
			</nav>

			<div class="header__actions">
				<div class="lang-switch" role="group" aria-label="Language">
					<button type="button" class="lang-btn is-active" data-lang="en" aria-pressed="true">EN</button>
					<button type="button" class="lang-btn" data-lang="es" aria-pressed="false">ES</button>
				</div>
				<a class="btn btn--primary btn--sm" href="#waitlist" data-i18n="nav.cta">Join waitlist</a>
			</div>
		</div>
	</header>

	<main id="main">
		<!-- ============================ HERO ============================ -->
		<section class="hero" aria-labelledby="hero-title">
			<div class="container">
				<div class="hero__copy">
					<p class="eyebrow eyebrow--center">
						<span class="dot" aria-hidden="true"></span>
						<span data-i18n="hero.badge">Pre-launch · Early access</span>
					</p>

					<h1 id="hero-title" data-i18n="hero.title">
						Stop optimizing WordPress images by hand.
					</h1>

					<p class="hero__lead" data-i18n="hero.lead">
						FasterFy converts, compresses and writes SEO alt text for your entire
						media library automatically — so your site loads faster, ranks higher
						and you get hours back every week.
					</p>

					<!-- ===================== WAITLIST FORM ===================== -->
					<form id="waitlist-form" class="waitlist" action="api/waitlist.php" method="post" novalidate>
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars( $csrf, ENT_QUOTES, 'UTF-8' ); ?>">

						<!-- Honeypot: hidden from humans, irresistible to bots. -->
						<div class="hp-field" aria-hidden="true">
							<label for="company_website">Company website</label>
							<input type="text" id="company_website" name="company_website" tabindex="-1" autocomplete="off">
						</div>

						<div class="waitlist__row">
							<div class="field">
								<label class="sr-only" for="email" data-i18n="form.email.label">Work email</label>
								<input
									type="email"
									id="email"
									name="email"
									required
									autocomplete="email"
									inputmode="email"
									placeholder="you@yourcompany.com"
									data-i18n-attr="placeholder"
									data-i18n="form.email.placeholder"
									aria-describedby="email-error form-note">
							</div>
							<button type="submit" class="btn btn--primary" data-i18n="form.submit">
								Get early access
							</button>
						</div>

						<p class="field-error" id="email-error" role="alert" hidden></p>

						<label class="consent">
							<input type="checkbox" name="consent" id="consent" value="1" required>
							<span data-i18n="form.consent">
								I agree to receive launch updates. No spam — unsubscribe anytime.
							</span>
						</label>

						<p class="form-note" id="form-note" data-i18n="form.note">
							Join the waitlist and lock in a founding-member discount.
						</p>

						<!-- Success / error live region (populated by JS) -->
						<div class="form-feedback" id="form-feedback" role="status" aria-live="polite" hidden></div>
					</form>

					<ul class="hero__trust" aria-label="Why teams trust FasterFy">
						<li data-i18n="hero.trust.1">No credit card</li>
						<li data-i18n="hero.trust.2">Non-destructive · 1-click rollback</li>
						<li data-i18n="hero.trust.3">Built for WordPress</li>
					</ul>
				</div>

				<!-- Hero dashboard showcase: results card + floating accent widgets -->
				<div class="showcase" aria-label="Optimization results preview">
					<div class="float-card float-card--a">
						<span class="float-card__label" data-i18n="float.saved">Saved this week</span>
						<span class="float-card__value"><em>12.4</em> h</span>
						<div class="spark" aria-hidden="true">
							<i style="height:40%"></i><i style="height:65%"></i><i style="height:50%"></i><i style="height:80%"></i><i style="height:100%"></i>
						</div>
					</div>

					<div class="result-card">
						<div class="result-card__head">
							<img src="assets/img/fasterfy-mark.svg" width="30" height="30" alt="" aria-hidden="true">
							<span>FasterFy</span>
							<span class="pill" data-i18n="hero.card.live">Live</span>
						</div>
						<div class="stat">
							<span class="stat__value" data-count="73">73%</span>
							<span class="stat__label" data-i18n="hero.card.stat1">Average weight reduction</span>
						</div>
						<div class="bar"><span style="width:73%"></span></div>
						<div class="result-card__grid">
							<div>
								<strong data-count="1248">1,248</strong>
								<span data-i18n="hero.card.stat2">Images optimized</span>
							</div>
							<div>
								<strong>WebP</strong>
								<span data-i18n="hero.card.stat3">+ AVIF output</span>
							</div>
							<div>
								<strong data-count="100">100%</strong>
								<span data-i18n="hero.card.stat4">Alt text coverage</span>
							</div>
							<div>
								<strong>0</strong>
								<span data-i18n="hero.card.stat5">Originals lost</span>
							</div>
						</div>
					</div>

					<div class="float-card float-card--b">
						<span class="float-card__label" data-i18n="float.joined">Joined the waitlist</span>
						<span class="float-card__value" data-count="1248">1,248</span>
						<div class="float-card__avatars" aria-hidden="true">
							<span>A</span><span>M</span><span>J</span><span>+</span>
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- ===================== TRUST / CONTEXT STRIP ===================== -->
		<section class="strip" aria-label="Highlights">
			<div class="container strip__inner">
				<span data-i18n="strip.1">WebP &amp; AVIF</span>
				<span data-i18n="strip.2">AI Alt Text &amp; SEO</span>
				<span data-i18n="strip.3">Bulk gallery processing</span>
				<span data-i18n="strip.4">Smart compression</span>
				<span data-i18n="strip.5">Auto semantic rename</span>
				<span data-i18n="strip.6">SVG sanitization</span>
			</div>
		</section>

		<!-- ============================ FEATURES ============================ -->
		<section class="section" id="features" aria-labelledby="features-title">
			<div class="container">
				<header class="section__head">
					<p class="eyebrow eyebrow--center" data-i18n="features.eyebrow">What you get</p>
					<h2 id="features-title" data-i18n="features.title">Real work, done for you</h2>
					<p class="section__sub" data-i18n="features.sub">
						Every feature is built to save time and lift performance — measurable wins, not busywork.
					</p>
				</header>

				<ul class="cards" role="list">
					<li class="card">
						<div class="card__icon" aria-hidden="true">⚡</div>
						<h3 data-i18n="feat.1.t">Next-gen formats</h3>
						<p data-i18n="feat.1.d">Convert JPG to WebP or AVIF and crush PNGs without visible quality loss — the core lever for Core Web Vitals.</p>
					</li>
					<li class="card">
						<div class="card__icon" aria-hidden="true">🤖</div>
						<h3 data-i18n="feat.2.t">AI alt text &amp; SEO</h3>
						<p data-i18n="feat.2.d">Multimodal vision writes accurate alt text, titles and descriptions — boosting accessibility and search rankings.</p>
					</li>
					<li class="card">
						<div class="card__icon" aria-hidden="true">🗂️</div>
						<h3 data-i18n="feat.3.t">Bulk gallery management</h3>
						<p data-i18n="feat.3.d">Process your whole library in batches. Select, optimize, generate AI text or revert — hundreds of images at once.</p>
					</li>
					<li class="card">
						<div class="card__icon" aria-hidden="true">✏️</div>
						<h3 data-i18n="feat.4.t">Automatic semantic rename</h3>
						<p data-i18n="feat.4.d">Turn "IMG_4821.jpg" into descriptive, keyword-rich filenames that search engines actually read.</p>
					</li>
					<li class="card">
						<div class="card__icon" aria-hidden="true">↩️</div>
						<h3 data-i18n="feat.5.t">Non-destructive rollback</h3>
						<p data-i18n="feat.5.d">Every original is backed up before any change. Restore any asset with a single click — zero risk.</p>
					</li>
					<li class="card">
						<div class="card__icon" aria-hidden="true">🛡️</div>
						<h3 data-i18n="feat.6.t">Security built in</h3>
						<p data-i18n="feat.6.d">SVG sanitization strips malicious scripts, and optional NSFW moderation protects your AI usage and brand.</p>
					</li>
				</ul>
			</div>
		</section>

		<!-- ============================ HOW IT WORKS ============================ -->
		<section class="section section--alt" id="how" aria-labelledby="how-title">
			<div class="container">
				<header class="section__head">
					<p class="eyebrow eyebrow--center" data-i18n="how.eyebrow">How it works</p>
					<h2 id="how-title" data-i18n="how.title">Live in minutes, not days</h2>
				</header>

				<ol class="steps" role="list">
					<li class="step">
						<span class="step__num" aria-hidden="true">1</span>
						<h3 data-i18n="how.1.t">Install &amp; connect</h3>
						<p data-i18n="how.1.d">Activate the plugin on WordPress. No build tools, no servers to manage.</p>
					</li>
					<li class="step">
						<span class="step__num" aria-hidden="true">2</span>
						<h3 data-i18n="how.2.t">Scan your library</h3>
						<p data-i18n="how.2.d">FasterFy finds every image and shows exactly what can be optimized — retroactively.</p>
					</li>
					<li class="step">
						<span class="step__num" aria-hidden="true">3</span>
						<h3 data-i18n="how.3.t">Optimize in bulk</h3>
						<p data-i18n="how.3.d">Run a batch and watch weight drop and SEO coverage climb. Roll back anytime.</p>
					</li>
				</ol>
			</div>
		</section>

		<!-- ============================ ROI BAND ============================ -->
		<section class="roi" aria-label="Impact in numbers">
			<div class="container roi__grid">
				<div class="roi__item">
					<span class="roi__arrow" aria-hidden="true">↑ 73%</span>
					<strong data-count="73">73%</strong>
					<span data-i18n="roi.1">Lighter images on average</span>
				</div>
				<div class="roi__item">
					<span class="roi__arrow" aria-hidden="true">↑</span>
					<strong data-i18n="roi.2v">Hours</strong>
					<span data-i18n="roi.2">Saved every week vs. manual work</span>
				</div>
				<div class="roi__item">
					<span class="roi__arrow" aria-hidden="true">↑ 100%</span>
					<strong>100%</strong>
					<span data-i18n="roi.3">Alt text coverage for SEO</span>
				</div>
				<div class="roi__item">
					<span class="roi__arrow" aria-hidden="true">✓</span>
					<strong data-i18n="roi.4v">Zero</strong>
					<span data-i18n="roi.4">Originals ever lost</span>
				</div>
			</div>
		</section>

		<!-- ============================ PRICING ============================ -->
		<section class="section" id="pricing" aria-labelledby="pricing-title">
			<div class="container">
				<header class="section__head">
					<p class="eyebrow eyebrow--center" data-i18n="pricing.eyebrow">Pricing</p>
					<h2 id="pricing-title" data-i18n="pricing.title">Simple plans that scale with you</h2>
					<p class="section__sub" data-i18n="pricing.sub">
						Founding-member pricing for everyone on the waitlist. Prices below are placeholders for pre-launch.
					</p>

					<div class="billing-toggle" role="group" aria-label="Billing period">
						<button type="button" class="billing-btn is-active" data-billing="monthly" aria-pressed="true" data-i18n="pricing.monthly">Monthly</button>
						<button type="button" class="billing-btn" data-billing="annual" aria-pressed="false" data-i18n="pricing.annual">Annual · save 20%</button>
					</div>
				</header>

				<div class="plans">
					<!-- Lite -->
					<article class="plan">
						<h3 class="plan__name" data-i18n="plan.lite.name">Lite</h3>
						<p class="plan__desc" data-i18n="plan.lite.desc">For personal sites &amp; blogs getting started.</p>
						<p class="plan__price">
							<span class="plan__amount">$0</span>
							<span class="plan__period" data-i18n="plan.free">free forever</span>
						</p>
						<a class="btn btn--ghost btn--block" href="#waitlist" data-i18n="plan.lite.cta">Start free</a>
						<ul class="plan__features" role="list">
							<li data-i18n="plan.lite.f1">WebP/AVIF conversion &amp; compression</li>
							<li data-i18n="plan.lite.f2">SVG sanitization</li>
							<li data-i18n="plan.lite.f3">One-click optimize</li>
							<li data-i18n="plan.lite.f4">Non-destructive rollback</li>
							<li data-i18n="plan.lite.f5">100 AI credits / month</li>
						</ul>
					</article>

					<!-- Pro (highlighted) -->
					<article class="plan plan--featured">
						<span class="plan__badge" data-i18n="plan.popular">Most popular</span>
						<h3 class="plan__name" data-i18n="plan.pro.name">Pro</h3>
						<p class="plan__desc" data-i18n="plan.pro.desc">For professionals &amp; growing businesses.</p>
						<p class="plan__price">
							<span class="plan__amount" data-monthly="$19" data-annual="$15">$19</span>
							<span class="plan__period" data-i18n="plan.permo">/ month</span>
						</p>
						<a class="btn btn--primary btn--block" href="#waitlist" data-i18n="plan.pro.cta">Join waitlist</a>
						<ul class="plan__features" role="list">
							<li data-i18n="plan.pro.f1">Everything in Lite</li>
							<li data-i18n="plan.pro.f2">AI alt text, titles &amp; descriptions</li>
							<li data-i18n="plan.pro.f3">Automatic semantic rename</li>
							<li data-i18n="plan.pro.f4">Bulk gallery processing</li>
							<li data-i18n="plan.pro.f5">NSFW moderation &amp; advanced controls</li>
							<li data-i18n="plan.pro.f6">5,000 AI credits / month</li>
						</ul>
					</article>

					<!-- Agency -->
					<article class="plan">
						<h3 class="plan__name" data-i18n="plan.agency.name">Agency</h3>
						<p class="plan__desc" data-i18n="plan.agency.desc">For agencies managing many client sites.</p>
						<p class="plan__price">
							<span class="plan__amount" data-monthly="$59" data-annual="$47">$59</span>
							<span class="plan__period" data-i18n="plan.permo">/ month</span>
						</p>
						<a class="btn btn--ghost btn--block" href="#waitlist" data-i18n="plan.agency.cta">Join waitlist</a>
						<ul class="plan__features" role="list">
							<li data-i18n="plan.agency.f1">Everything in Pro</li>
							<li data-i18n="plan.agency.f2">Multi-site management</li>
							<li data-i18n="plan.agency.f3">Priority processing &amp; support</li>
							<li data-i18n="plan.agency.f4">Exclusion rules &amp; throttling</li>
							<li data-i18n="plan.agency.f5">25,000 AI credits / month</li>
						</ul>
					</article>
				</div>
				<p class="pricing__foot" data-i18n="pricing.foot">Prices are placeholders and may change at launch. Waitlist members get founding-member rates.</p>
			</div>
		</section>

		<!-- ============================ FAQ ============================ -->
		<section class="section section--alt" id="faq" aria-labelledby="faq-title">
			<div class="container container--narrow">
				<header class="section__head">
					<p class="eyebrow eyebrow--center" data-i18n="faq.eyebrow">FAQ</p>
					<h2 id="faq-title" data-i18n="faq.title">Questions, answered</h2>
				</header>

				<div class="faq">
					<details>
						<summary data-i18n="faq.1.q">Will it touch my original files?</summary>
						<p data-i18n="faq.1.a">Never destructively. FasterFy backs up every original before any change, and you can restore any image with one click.</p>
					</details>
					<details>
						<summary data-i18n="faq.2.q">Do I need AI to use it?</summary>
						<p data-i18n="faq.2.a">No. Technical optimization (WebP/AVIF, compression, SVG) works on its own. AI alt text and SEO are optional add-ons.</p>
					</details>
					<details>
						<summary data-i18n="faq.3.q">Will it work on my host?</summary>
						<p data-i18n="faq.3.a">Yes. Bulk processing runs in safe, browser-driven batches, so it works reliably even on shared hosting.</p>
					</details>
					<details>
						<summary data-i18n="faq.4.q">When does it launch?</summary>
						<p data-i18n="faq.4.a">We're in pre-launch. Join the waitlist and you'll be first to get access — plus founding-member pricing.</p>
					</details>
					<details>
						<summary data-i18n="faq.5.q">Is my data safe?</summary>
						<p data-i18n="faq.5.a">API keys are encrypted at rest, SVGs are sanitized, and sensitive images are never sent to AI when moderation is on.</p>
					</details>
				</div>
			</div>
		</section>

		<!-- ============================ FINAL CTA ============================ -->
		<section class="cta-final" aria-labelledby="cta-title">
			<div class="container cta-final__inner">
				<h2 id="cta-title" data-i18n="cta.title">Be first when FasterFy launches</h2>
				<p data-i18n="cta.sub">Join the waitlist for early access and founding-member pricing.</p>
				<a class="btn btn--dark btn--lg" href="#waitlist" data-i18n="cta.btn">Join the waitlist</a>
			</div>
		</section>
	</main>

	<!-- ============================ FOOTER ============================ -->
	<footer class="site-footer">
		<div class="container footer__inner">
			<div class="footer__brand">
				<img class="brand__mark" src="assets/img/fasterfy-mark.svg" width="30" height="30" alt="" aria-hidden="true">
				<span class="brand__word">Faster<em>Fy</em></span>
				<p data-i18n="footer.tag">AI media optimization for WordPress.</p>
			</div>
			<nav class="footer__links" aria-label="Footer">
				<a href="#features" data-i18n="nav.features">Features</a>
				<a href="#pricing" data-i18n="nav.pricing">Pricing</a>
				<a href="#faq" data-i18n="nav.faq">FAQ</a>
				<a href="#waitlist" data-i18n="nav.cta">Join waitlist</a>
			</nav>
		</div>
		<div class="container footer__bottom">
			<p>&copy; <?php echo (int) gmdate( 'Y' ); ?> FasterFy. <span data-i18n="footer.rights">All rights reserved.</span></p>
		</div>
	</footer>

	<script src="assets/js/main.js?v=<?php echo rawurlencode( $asset_v ); ?>" defer></script>
</body>
</html>
