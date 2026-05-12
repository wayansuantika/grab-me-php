const state = {
    user: null,
    csrfToken: window.APP_BOOTSTRAP?.csrfToken || '',
    siteSettings: {},
};

const view = document.getElementById('spa-view');

function appBasePath() {
    const configuredUrl = window.APP_BOOTSTRAP?.appUrl;
    if (configuredUrl) {
        try {
            const configuredPath = new URL(configuredUrl, window.location.origin).pathname;
            return configuredPath.endsWith('/') ? configuredPath : `${configuredPath}/`;
        } catch {
            // Fallbacks below.
        }
    }

    const scriptEl = document.querySelector('script[src*="assets/js/app.js"]');
    if (scriptEl?.src) {
        try {
            const scriptPath = new URL(scriptEl.src, window.location.origin).pathname;
            const marker = '/assets/js/app.js';
            const markerPos = scriptPath.indexOf(marker);
            if (markerPos >= 0) {
                return scriptPath.slice(0, markerPos + 1);
            }
        } catch {
            // Final fallback below.
        }
    }

    return window.location.pathname.replace(/[^/]*$/, '');
}

function apiUrl(url) {
    if (/^https?:\/\//i.test(url)) return url;
    return `${appBasePath()}${String(url).replace(/^\/+/, '')}`;
}

async function apiFetch(url, options = {}) {
    const isJson = !options.body || typeof options.body === 'string';

    const headers = {
        'X-CSRF-TOKEN': state.csrfToken,
        ...(isJson ? { 'Content-Type': 'application/json' } : {}),
        ...(options.headers || {}),
    };

    const requestUrl = apiUrl(url);
    const response = await fetch(requestUrl, { credentials: 'same-origin', ...options, headers });
    const raw = await response.text();
    const contentType = response.headers.get('content-type') || '';
    let data = {};
    try {
        data = raw ? JSON.parse(raw) : {};
    } catch {
        data = {};
    }

    if (!contentType.includes('application/json') && /^\s*</.test(raw)) {
        throw new Error(`API returned HTML instead of JSON (${requestUrl}).`);
    }

    if (!response.ok) {
        throw new Error(data.message || 'Request failed');
    }

    return data;
}

function money(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0);
}

function mediaUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^(https?:|data:|blob:)/i.test(raw)) return raw;
    return apiUrl(raw.replace(/^\/+/, ''));
}

function applySiteBranding(settings = {}) {
    const brand = document.querySelector('.navbar-brand');
    if (!brand) return;

    const logoText = String(settings.site_logo_text || 'GrabMas').trim() || 'GrabMas';
    const logoImage = mediaUrl(settings.site_logo_image || '');

    if (logoImage) {
        brand.innerHTML = `<img src="${logoImage}" alt="${logoText}" class="nav-logo-img">`;
        brand.setAttribute('aria-label', logoText);
    } else {
        brand.textContent = logoText;
        brand.removeAttribute('aria-label');
    }
}

async function loadSiteSettings() {
    try {
        const res = await apiFetch('api/settings');
        const settings = res?.data?.settings || {};
        state.siteSettings = settings;
        applySiteBranding(settings);
    } catch {
        state.siteSettings = {};
        applySiteBranding({});
    }
}

function getErrorMessage(err, fallback = 'Something went wrong.') {
    if (err instanceof Error && err.message) return err.message;
    if (typeof err === 'string' && err.trim() !== '') return err;
    return fallback;
}

function ensureToastHost() {
    let host = document.getElementById('appToastHost');
    if (host) return host;

    host = document.createElement('div');
    host.id = 'appToastHost';
    host.className = 'app-toast-host';
    document.body.appendChild(host);
    return host;
}

function showToast(message, type = 'info', timeoutMs = 3200) {
    const host = ensureToastHost();
    const toast = document.createElement('div');
    toast.className = `app-toast app-toast-${type}`;

    const text = document.createElement('div');
    text.className = 'app-toast-text';
    text.textContent = String(message || '');

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'app-toast-close';
    closeBtn.setAttribute('aria-label', 'Dismiss notification');
    closeBtn.textContent = 'x';

    const removeToast = () => {
        toast.classList.add('closing');
        setTimeout(() => toast.remove(), 180);
    };

    closeBtn.addEventListener('click', removeToast);
    toast.appendChild(text);
    toast.appendChild(closeBtn);
    host.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(removeToast, Math.max(1400, timeoutMs));
}

function avatarFallbackUrl(size = '96x96', label = 'Spa') {
    const [rawW, rawH] = String(size).split('x');
    const width = Math.max(48, Number(rawW) || 96);
    const height = Math.max(48, Number(rawH) || width);
    const fontSize = Math.max(12, Math.round(Math.min(width, height) * 0.26));
    const safeLabel = String(label || 'Spa').slice(0, 6);
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#f4efe6"/><stop offset="100%" stop-color="#e6dcc8"/></linearGradient></defs><rect width="100%" height="100%" fill="url(#g)"/><circle cx="${Math.round(width / 2)}" cy="${Math.round(height * 0.38)}" r="${Math.round(Math.min(width, height) * 0.16)}" fill="#bca780"/><rect x="${Math.round(width * 0.24)}" y="${Math.round(height * 0.62)}" width="${Math.round(width * 0.52)}" height="${Math.round(height * 0.18)}" rx="${Math.round(Math.min(width, height) * 0.09)}" fill="#bca780"/><text x="50%" y="${Math.round(height * 0.92)}" text-anchor="middle" fill="#6e5f45" font-family="Arial, sans-serif" font-size="${fontSize}" font-weight="700">${safeLabel}</text></svg>`;
    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
}

function therapistPhotoUrl(photoUrl, fallbackSize = '96x96') {
    const fallback = avatarFallbackUrl(fallbackSize);
    if (!photoUrl) return fallback;

    if (/^(https?:|data:|blob:)/i.test(photoUrl)) {
        return photoUrl;
    }

    let normalized = String(photoUrl).trim();
    const basePath = appBasePath().replace(/\/+$/, '');
    if (basePath && normalized.startsWith(basePath + '/')) {
        normalized = normalized.slice(basePath.length);
    }
    const clean = normalized.replace(/^\/+/, '');
    return `${appBasePath()}${clean}`;
}

function getDefaultRouteByRole(role) {
    if (role === 'admin') return '#/admin';
    if (role === 'therapist') return '#/therapist-panel';
    return '#/dashboard';
}

function setNavVisible(key, visible) {
    const node = document.querySelector(`[data-nav="${key}"]`);
    if (!node) return;
    node.classList.toggle('d-none', !visible);
}

function applyRoleNavigation() {
    const role = state.user?.role || 'guest';

    const visibilityMap = {
        guest: ['home', 'about', 'services', 'therapists', 'areas', 'booking', 'contact', 'auth'],
        customer: ['home', 'services', 'therapists', 'areas', 'booking', 'contact', 'dashboard', 'logout'],
        therapist: ['home', 'services', 'therapists', 'areas', 'therapist-panel', 'contact', 'logout'],
        admin: ['home', 'admin', 'logout'],
    };

    const allKeys = ['home', 'about', 'services', 'therapists', 'areas', 'booking', 'dashboard', 'therapist-panel', 'admin', 'contact', 'auth', 'logout'];
    const allowed = visibilityMap[role] || visibilityMap.guest;

    allKeys.forEach((key) => setNavVisible(key, allowed.includes(key)));

    document.body.classList.remove('role-guest', 'role-customer', 'role-therapist', 'role-admin');
    document.body.classList.add(`role-${role}`);
}

function bookingStatusBadge(status) {
    const map = {
        pending_payment: 'bg-warning-subtle text-warning-emphasis',
        confirmed: 'bg-success-subtle text-success-emphasis',
        in_progress: 'bg-info-subtle text-info-emphasis',
        completed: 'bg-primary-subtle text-primary-emphasis',
        cancelled: 'bg-danger-subtle text-danger-emphasis',
    };

    return `<span class="badge ${map[status] || 'bg-secondary-subtle text-secondary-emphasis'}">${(status || '').replace('_', ' ')}</span>`;
}

async function loadMe() {
    try {
        const res = await apiFetch('api/auth/me');
        state.user = res.data.user;
        state.csrfToken = res.data.csrf_token;
    } catch {
        state.user = null;
    }

    applyRoleNavigation();
}

function navTitle() {
    if (!state.user) return '<span class="pill">Guest</span>';
    return `<span class="pill">${state.user.role.toUpperCase()}</span> <strong>${state.user.name}</strong>`;
}

function homeTemplate() {
    // Async wrapper — returns a Promise so renderRoute can await it
    return (async () => {
        const [settingsRes, serviceRes, therapistsRes, areasRes] = await Promise.all([
            apiFetch('api/settings').catch(() => ({})),
            apiFetch('api/services').catch(() => ({})),
            apiFetch('api/therapists').catch(() => ({})),
            apiFetch('api/areas').catch(() => ({})),
        ]);

        const settings = settingsRes?.data?.settings || {};
        state.siteSettings = settings;
        applySiteBranding(settings);
        const categories = serviceRes?.data?.categories || [];
        const therapists = therapistsRes?.data?.therapists || [];
        const areas = areasRes?.data?.areas || [];

        const heroDesktop = mediaUrl(settings.hero_image_desktop) || 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&w=1920&q=85';
        const heroMobile  = mediaUrl(settings.hero_image_mobile)  || 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&w=800&q=80';
        const heroTitle   = settings.hero_title    || 'Calm,<br>Delivered<br>to Your Door';
        const heroSub     = settings.hero_subtitle || 'World-class therapists. Signature Balinese treatments. Your villa, hotel, or home.';
        const heroKicker  = settings.hp2_hero_kicker || 'Bali Home Service Spa';
        const heroProof1  = settings.hp2_hero_proof_1 || 'Certified Therapists';
        const heroProof2  = settings.hp2_hero_proof_2 || 'Secure Payments';
        const heroProof3  = settings.hp2_hero_proof_3 || 'Island-Wide Coverage';
        const servicesLabel = settings.hp2_services_label || 'Signature Menu';
        const servicesTitle = settings.hp2_services_title || 'Spa Treatments for Every Mood';
        const servicesLinkText = settings.hp2_services_link_text || 'See full service list';
        const galleryLabel = settings.hp2_gallery_label || 'Experience';
        const galleryTitle = settings.hp2_gallery_title || 'Aesthetic, Calm, and Professional';
        const faqLabel = settings.hp2_faq_label || 'FAQ';
        const faqTitle = settings.hp2_faq_title || 'Questions Before You Book';
        const faqImage = mediaUrl(settings.hp2_faq_image) || 'https://images.unsplash.com/photo-1600334089648-b0d9d3028eb2?auto=format&w=700&q=80';
        const whatsappRaw = String(settings.company_whatsapp || '+62XXXXXXXXXX');
        const whatsappNumber = whatsappRaw.replace(/[^\d]/g, '');
        const whatsappUrl = whatsappNumber
            ? `https://wa.me/${whatsappNumber}?text=${encodeURIComponent('Hi GrabMas, I want to book a home spa session.')}`
            : '#/contact';

        const allServices = categories.flatMap((cat) => Array.isArray(cat.services) ? cat.services : []);
        const activeTherapists = therapists.filter((t) => Number(t.is_active ?? 1) === 1).length;
        const serviceCount = allServices.length;
        const areaCount = areas.filter((a) => Number(a.is_active ?? 1) === 1).length;
        const statTherapists = `${Math.max(activeTherapists, 1)}+`;
        const statServices = `${Math.max(serviceCount, 1)}+`;
        const statAreas = `${Math.max(areaCount, 1)}+`;

        const serviceImages = [
            'https://images.unsplash.com/photo-1600334089648-b0d9d3028eb2?auto=format&w=600&q=75',
            'https://images.unsplash.com/photo-1519823551278-64ac92734fb1?auto=format&w=600&q=75',
            'https://images.unsplash.com/photo-1570172619644-dfd03ed5d881?auto=format&w=600&q=75',
            'https://images.unsplash.com/photo-1571019614242-c5c5dee9f50b?auto=format&w=600&q=75',
            'https://images.unsplash.com/photo-1556228578-8c89e6adf883?auto=format&w=600&q=75',
            'https://images.unsplash.com/photo-1612198273663-69d9c74b5dab?auto=format&w=600&q=75',
        ];

        const galleryData = [
            { url: mediaUrl(settings.gallery_image_1) || 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&w=900&q=75', caption: settings.gallery_caption_1 || 'Signature Massage' },
            { url: mediaUrl(settings.gallery_image_2) || 'https://images.unsplash.com/photo-1600334089648-b0d9d3028eb2?auto=format&w=500&q=75', caption: settings.gallery_caption_2 || 'Aromatherapy' },
            { url: mediaUrl(settings.gallery_image_3) || 'https://images.unsplash.com/photo-1571019614242-c5c5dee9f50b?auto=format&w=500&q=75', caption: settings.gallery_caption_3 || 'Deep Tissue' },
            { url: mediaUrl(settings.gallery_image_4) || 'https://images.unsplash.com/photo-1519823551278-64ac92734fb1?auto=format&w=500&q=75', caption: settings.gallery_caption_4 || 'Couples Session' },
            { url: mediaUrl(settings.gallery_image_5) || 'https://images.unsplash.com/photo-1570172619644-dfd03ed5d881?auto=format&w=500&q=75', caption: settings.gallery_caption_5 || 'Facial Treatment' },
            { url: mediaUrl(settings.gallery_image_6) || 'https://images.unsplash.com/photo-1556228578-8c89e6adf883?auto=format&w=500&q=75', caption: settings.gallery_caption_6 || 'Reflexology' },
            { url: mediaUrl(settings.gallery_image_7) || 'https://images.unsplash.com/photo-1612198273663-69d9c74b5dab?auto=format&w=500&q=75', caption: settings.gallery_caption_7 || 'Body Scrub' },
            { url: mediaUrl(settings.gallery_image_8) || 'https://images.unsplash.com/photo-1614170153058-de5e7c6aa65c?auto=format&w=700&q=75', caption: settings.gallery_caption_8 || 'Hot Stone' },
        ];

        const faqs = [
            { q: 'Which areas in Bali do you cover?', a: 'We cover Ubud, Canggu, Kuta, Sanur, Seminyak, Denpasar, and Tabanan. More areas are added regularly.' },
            { q: 'How do I book a session?', a: 'Click "Book Us", choose your area and preferred date/time, select your treatment, pick a therapist, and confirm. The whole process takes under 2 minutes.' },
            { q: 'What should I prepare before the therapist arrives?', a: 'A quiet, comfortable space with enough room for a massage mat or table. The therapist brings all equipment including oils, towels, and aromatherapy supplies.' },
            { q: 'Are all therapists certified?', a: 'Yes — every GrabMas therapist holds a certified Balinese wellness qualification and has passed our background and skills verification process.' },
            { q: 'Can I reschedule or cancel a booking?', a: 'Cancellations up to 4 hours before the session are fully refunded. Rescheduling is free up to 2 hours before the session starts.' },
            { q: 'What payment methods are accepted?', a: 'We accept all major credit and debit cards via Stripe. Payment is secured at booking confirmation and held until your session is complete.' },
        ];

        const serviceHighlights = categories.length
            ? categories.slice(0, 6).map((cat, i) => `
                <div class="col-md-6 col-xl-4">
                    <article class="hp2-service-card">
                        <img src="${(() => {
                            const withImage = (cat.services || []).find((srv) => srv.image_url);
                            if (withImage?.image_url) {
                                return apiUrl(String(withImage.image_url).replace(/^\/+/, ''));
                            }
                            return serviceImages[i % serviceImages.length];
                        })()}" alt="${cat.name}" class="hp2-service-img" loading="lazy">
                        <div class="hp2-service-body">
                            <h3 class="hp2-service-name">${cat.name}</h3>
                            <p class="hp2-service-desc">${cat.description || 'Elegant treatment crafted for stress relief, recovery, and deep rest at your home.'}</p>
                            <div class="hp2-service-meta">${(cat.services || []).length} treatment${(cat.services || []).length !== 1 ? 's' : ''}</div>
                        </div>
                    </article>
                </div>`).join('')
            : ['Balinese Massage', 'Aromatherapy', 'Deep Tissue', 'Reflexology', 'Facial Add-on', 'Hot Stone'].map((name, i) => `
                <div class="col-md-6 col-xl-4">
                    <article class="hp2-service-card">
                        <img src="${serviceImages[i % serviceImages.length]}" alt="${name}" class="hp2-service-img" loading="lazy">
                        <div class="hp2-service-body">
                            <h3 class="hp2-service-name">${name}</h3>
                            <p class="hp2-service-desc">A premium home-service ritual inspired by Balinese spa tradition.</p>
                            <div class="hp2-service-meta">Featured signature</div>
                        </div>
                    </article>
                </div>`).join('');

        return `
            <section class="hp2-hero">
                <picture>
                    <source media="(max-width:767px)" srcset="${heroMobile}">
                    <img src="${heroDesktop}" alt="GrabMas Home Service Spa" class="hp2-hero-bg" loading="eager">
                </picture>
                <div class="hp2-hero-overlay"></div>
                <div class="container hp2-hero-wrap">
                    <div class="hp2-hero-card">
                        <span class="hp2-kicker">${heroKicker}</span>
                        <h1 class="hp2-title">${heroTitle}</h1>
                        <p class="hp2-sub">${heroSub}</p>
                        <div class="hp2-hero-actions">
                            <a href="#/booking" class="btn hp2-btn-primary">Book at Home</a>
                            <a href="#/services" class="btn hp2-btn-secondary">View Treatments</a>
                        </div>
                        <div class="hp2-proof-row" aria-label="Trust Highlights">
                            <div class="hp2-proof-item">
                                <span class="hp2-proof-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 3l7 3v6c0 4.2-2.6 7.7-7 9-4.4-1.3-7-4.8-7-9V6l7-3z"></path>
                                        <path d="M9.2 12.4l1.9 1.9 3.7-3.7"></path>
                                    </svg>
                                </span>
                                <span>${heroProof1}</span>
                            </div>
                            <div class="hp2-proof-item">
                                <span class="hp2-proof-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3.5" y="10" width="17" height="10" rx="2"></rect>
                                        <path d="M8 10V7.8a4 4 0 0 1 8 0V10"></path>
                                    </svg>
                                </span>
                                <span>${heroProof2}</span>
                            </div>
                            <div class="hp2-proof-item">
                                <span class="hp2-proof-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="8"></circle>
                                        <path d="M4 12h16"></path>
                                        <path d="M12 4a13 13 0 0 1 0 16"></path>
                                        <path d="M12 4a13 13 0 0 0 0 16"></path>
                                    </svg>
                                </span>
                                <span>${heroProof3}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="hp2-intro">
                <div class="container">
                    <div class="row g-4 align-items-center">
                        <div class="col-lg-7">
                            <h2 class="hp2-section-title">Luxury Spa Ritual, Delivered to Your Villa or Home</h2>
                            <p class="hp2-section-text">GrabMas is built for guests and residents in Bali who want premium treatment without traffic, waiting rooms, or scheduling stress. You pick the time, location, and therapy, we deliver the full spa experience to your door.</p>
                        </div>
                        <div class="col-lg-5">
                            <div class="hp2-stat-grid">
                                <div class="hp2-stat"><strong>${statTherapists}</strong><span>Active Therapists</span></div>
                                <div class="hp2-stat"><strong>${statServices}</strong><span>Treatment Options</span></div>
                                <div class="hp2-stat"><strong>${statAreas}</strong><span>Coverage Areas</span></div>
                                <div class="hp2-stat"><strong>2 Min</strong><span>Fast Booking Flow</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="hp2-flow">
                <div class="container">
                    <div class="text-center mb-4">
                        <span class="hp2-label">How It Works</span>
                        <h2 class="hp2-section-title">Built for Home Service Convenience</h2>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4"><article class="hp2-step"><span class="hp2-step-no">01</span><h3>Choose Time & Area</h3><p>Select your preferred date, time, and Bali area in seconds.</p></article></div>
                        <div class="col-md-4"><article class="hp2-step"><span class="hp2-step-no">02</span><h3>Pick Treatments</h3><p>Mix signature massages and add-ons that match your body needs.</p></article></div>
                        <div class="col-md-4"><article class="hp2-step"><span class="hp2-step-no">03</span><h3>Therapist Arrives</h3><p>Our certified therapist comes to your location with all essentials.</p></article></div>
                    </div>
                </div>
            </section>

            <section class="hp2-services">
                <div class="container">
                    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-4">
                        <div>
                            <span class="hp2-label">${servicesLabel}</span>
                            <h2 class="hp2-section-title mb-0">${servicesTitle}</h2>
                        </div>
                        <a href="#/services" class="hp2-link">${servicesLinkText}</a>
                    </div>
                    <div class="row g-4">
                        ${serviceHighlights}
                    </div>
                </div>
            </section>

            <section class="hp2-gallery">
                <div class="container">
                    <div class="text-center mb-4">
                        <span class="hp2-label">${galleryLabel}</span>
                        <h2 class="hp2-section-title">${galleryTitle}</h2>
                    </div>
                    <div class="hp2-gallery-grid">
                        ${galleryData.slice(0, 6).map((img) => `
                            <figure class="hp2-gallery-item">
                                <img src="${img.url}" alt="${img.caption}" loading="lazy">
                                <figcaption>${img.caption}</figcaption>
                            </figure>`).join('')}
                    </div>
                </div>
            </section>

            <section class="hp2-faq">
                <div class="container">
                    <div class="row g-5 align-items-start">
                        <div class="col-lg-5 d-none d-lg-block">
                            <div class="hp2-faq-imgwrap">
                                <img src="${faqImage}" alt="Spa FAQ" class="hp2-faq-img" loading="lazy">
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <span class="hp2-label">${faqLabel}</span>
                            <h2 class="hp2-section-title mb-4">${faqTitle}</h2>
                            <div class="accordion hp-accordion" id="faqAccordion">
                                ${faqs.map((faq, i) => `
                                    <div class="accordion-item hp-faq-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button hp-faq-btn ${i > 0 ? 'collapsed' : ''}" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#hpFaq${i}"
                                                aria-expanded="${i === 0 ? 'true' : 'false'}">
                                                ${faq.q}
                                            </button>
                                        </h3>
                                        <div id="hpFaq${i}" class="accordion-collapse collapse ${i === 0 ? 'show' : ''}" data-bs-parent="#faqAccordion">
                                            <div class="accordion-body hp-faq-body">${faq.a}</div>
                                        </div>
                                    </div>`).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="hp2-final-cta">
                <div class="container">
                    <div class="hp2-final-inner">
                        <span class="hp2-label hp2-label-light">Home Service Concept</span>
                        <h2 class="hp2-final-title">Elegant Bali Spa Session, Wherever You Stay</h2>
                        <p class="hp2-final-sub">From villa staycations to weekly recovery routines, GrabMas gives you premium treatment in your own private space.</p>
                        <div class="hp2-final-actions">
                            <a href="#/booking" class="btn hp2-btn-primary">Start Booking</a>
                            <a href="${whatsappUrl}" class="btn hp2-btn-wa" target="_blank" rel="noopener">Chat Concierge</a>
                        </div>
                    </div>
                </div>
            </section>

            <div class="hp-mobile-quickbar d-md-none" role="region" aria-label="Quick booking actions">
                <a href="#/booking" class="hp-mobile-quick-btn hp-mobile-quick-primary">Book Now</a>
                <a href="${whatsappUrl}" class="hp-mobile-quick-btn hp-mobile-quick-wa" target="_blank" rel="noopener">WhatsApp</a>
            </div>
        `;
    })();
}

function staticTemplate(title, text) {
    return `
        <section class="hero-card">
            <h2 class="section-title">${title}</h2>
            <p class="text-muted">${text}</p>
        </section>
    `;
}

async function servicesTemplate() {
    const res = await apiFetch('api/services');
    const categories = res.data.categories || [];

    const html = categories.map((cat) => `
        <section class="mb-4">
            <h4 class="mb-1">${cat.name}</h4>
            <p class="text-muted mb-3">${cat.description || ''}</p>
            <div class="row g-3">
                ${cat.services.map((srv) => {
                    const imgUrl = srv.image_url ? apiUrl(srv.image_url.replace(/^\//, '')) : '';
                    return `
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="border rounded overflow-hidden h-100 d-flex flex-column">
                            ${imgUrl
                                ? `<div style="aspect-ratio:4/3;overflow:hidden"><img src="${imgUrl}" alt="${srv.name}" style="width:100%;height:100%;object-fit:cover;display:block"></div>`
                                : `<div style="aspect-ratio:4/3;background:#f0f0f0;display:flex;align-items:center;justify-content:center"><svg width="40" height="40" fill="none" stroke="#bbb" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>`
                            }
                            <div class="p-2 flex-grow-1 d-flex flex-column justify-content-between">
                                <div>
                                    <strong class="d-block" style="font-size:.92rem">${srv.name}</strong>
                                    <div class="small text-muted">${srv.duration_minutes} min</div>
                                </div>
                                <div class="mt-1 d-flex justify-content-between align-items-center flex-wrap gap-1">
                                    <span style="font-weight:600">${money(srv.price)}</span>
                                    ${srv.is_addon ? '<span class="pill" style="font-size:.7rem">Add-On</span>' : ''}
                                </div>
                            </div>
                        </div>
                    </div>`;
                }).join('')}
            </div>
        </section>
    `).join('');

    return `<section class="hero-card"><h2 class="section-title">Services</h2>${html}</section>`;
}

async function areasTemplate() {
    const res = await apiFetch('api/areas');
    const areas = res.data.areas || [];

    return `
        <section class="hero-card">
            <h2 class="section-title">Coverage Areas</h2>
            <div class="row g-2">
                ${areas.map((a) => `<div class="col-md-4"><div class="panel-card"><strong>${a.name}</strong><div class="small text-muted">Group ${a.coverage_group}</div></div></div>`).join('')}
            </div>
        </section>
    `;
}

async function therapistsTemplate() {
    const therapistsRes = await apiFetch('api/therapists').catch(() => ({}));
    const list = therapistsRes?.data?.therapists || [];

    return `
        <section class="hero-card">
            <h2 class="section-title">Therapists</h2>
            <div class="row g-3">
                ${list.map((t) => `
                    <div class="col-md-6">
                        <div class="panel-card">
                            <img src="${therapistPhotoUrl(t.photo_url, '96x96')}" alt="${t.name}" class="therapist-avatar" onerror="this.onerror=null;this.src='${avatarFallbackUrl('96x96')}'">
                            <h5>${t.name}</h5>
                            <div class="small text-muted mb-2">${Number(t.experience_years || 0)} years experience</div>
                            <div class="small">Rating: ${t.rating || 'N/A'}</div>
                            <a class="btn btn-sm btn-outline-secondary mt-2" href="#/therapist/${t.id}">View Profile</a>
                        </div>
                    </div>
                `).join('')}
            </div>
        </section>
    `;
}

async function therapistDetailTemplate(id) {
    const res = await apiFetch(`api/therapists/${id}`);
    const t = res?.data?.therapist;

    return `
        <section class="hero-card">
            <div class="d-flex align-items-center gap-3 flex-wrap mb-2">
                <img src="${therapistPhotoUrl(t.photo_url, '120x120')}" alt="${t.name}" class="therapist-avatar-lg" onerror="this.onerror=null;this.src='${avatarFallbackUrl('120x120')}'">
                <h2 class="section-title m-0">${t.name}</h2>
            </div>
            <p class="text-muted">${t.bio || 'No biography yet.'}</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="panel-card">
                        <h6>Specialties</h6>
                        <p>${t.specialty || '-'}</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="panel-card">
                        <h6>Coverage Areas</h6>
                        <p>${(res.data.areas || []).map((a) => a.name).join(', ') || '-'}</p>
                    </div>
                </div>
            </div>
        </section>
    `;
}

async function bookingTemplate() {
    const [areasRes, serviceRes, settingsRes] = await Promise.all([
        apiFetch('api/areas'),
        apiFetch('api/services'),
        apiFetch('api/settings').catch(() => ({})),
    ]);

    const areas = areasRes?.data?.areas || [];
    const settings = settingsRes?.data?.settings || {};
    const bankTransferDetails = settings.bank_transfer_details || 'Bank Transfer\\nBank: BCA\\nAccount Name: GrabMas Spa\\nAccount Number: 1234567890';
    const stripeEnabled = Boolean(window.APP_BOOTSTRAP?.stripeEnabled);
    const bankTransferEnabled = String(settings.payment_bank_transfer_enabled ?? '1') === '1';
    const creditCardEnabledByAdmin = String(settings.payment_credit_card_enabled ?? '1') === '1';
    const creditCardEnabled = stripeEnabled && creditCardEnabledByAdmin;
    const hasPaymentMethod = bankTransferEnabled || creditCardEnabled;

    const mainServices = [];
    const addons = [];
    (serviceRes?.data?.categories || []).forEach((cat) => {
        (cat.services || []).forEach((s) => {
            if (s.is_addon) addons.push(s);
            else mainServices.push(s);
        });
    });

    const today = new Date().toISOString().split('T')[0];

    return `
        <section class="booking-wizard">

            <div class="booking-hero mb-4">
                <p class="pill mb-2">Reserve Your Session</p>
                <h2 class="section-title">Book a Home Spa Treatment</h2>
                <p class="text-muted">Luxury wellness delivered to your Bali villa or hotel — in four simple steps.</p>
            </div>

            <div class="booking-steps mb-4" id="bookingStepBar">
                <div class="booking-step active" data-step="1"><div class="step-num">1</div><div class="step-label">Location & Time</div></div>
                <div class="step-line"></div>
                <div class="booking-step" data-step="2"><div class="step-num">2</div><div class="step-label">Services</div></div>
                <div class="step-line"></div>
                <div class="booking-step" data-step="3"><div class="step-num">3</div><div class="step-label">Therapist</div></div>
                <div class="step-line"></div>
                <div class="booking-step" data-step="4"><div class="step-num">4</div><div class="step-label">Your Details</div></div>
            </div>

            <form id="bookingForm" novalidate>
                <input type="hidden" name="therapist_id" id="bookingTherapistId">

                <!-- Step 1: Location & Time -->
                <div class="booking-panel active" id="bookStep1">
                    <div class="hero-card">
                        <h4 class="section-title mb-4">Where &amp; When</h4>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold mb-2">Coverage Area</label>
                                <div class="row g-2">
                                    ${areas.map((a) => `
                                        <div class="col-6 col-md-4 col-lg-3">
                                            <label class="area-card">
                                                <input type="radio" name="area_id" value="${a.id}" class="d-none" required>
                                                <div class="area-card-inner">
                                                    <span class="area-icon">📍</span>
                                                    <span>${a.name}</span>
                                                </div>
                                            </label>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Preferred Date</label>
                                <input type="date" class="form-control form-control-lg" name="booking_date" min="${today}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Preferred Time</label>
                                <input type="time" class="form-control form-control-lg" name="booking_time" required>
                            </div>
                        </div>
                        <div class="step-footer">
                            <span></span>
                            <button type="button" class="btn btn-luxury btn-next-step" data-next="2">Services →</button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Services -->
                <div class="booking-panel" id="bookStep2">
                    <div class="hero-card">
                        <h4 class="section-title mb-1">Choose Your Treatment</h4>
                        <p class="text-muted small mb-4">Tap cards to select. You may choose multiple services.</p>

                        <h6 class="label-section">Main Services</h6>
                        <div class="service-grid mb-4">
                            ${mainServices.map((s) => {
                                const imgUrl = s.image_url ? apiUrl(s.image_url.replace(/^\//, '')) : '';
                                return `<label class="service-card">
                                    <input type="checkbox" class="d-none service-check" value="${s.id}" data-price="${s.price}" data-name="${s.name}">
                                    <div class="service-card-inner">
                                        ${imgUrl ? `<img src="${imgUrl}" alt="${s.name}" class="service-card-img">` : ''}
                                        <div class="service-name">${s.name}</div>
                                        <div class="small text-muted mb-1">${s.duration_minutes} min</div>
                                        <div class="service-price">${money(s.price)}</div>
                                        <div class="service-check-mark">✓</div>
                                    </div>
                                </label>`;
                            }).join('')}
                        </div>

                        ${addons.length ? `
                        <h6 class="label-section">Optional Add-Ons</h6>
                        <div class="service-grid">
                            ${addons.map((s) => {
                                const imgUrl = s.image_url ? apiUrl(s.image_url.replace(/^\//, '')) : '';
                                return `<label class="service-card addon-card">
                                    <input type="checkbox" class="d-none addon-check" value="${s.id}" data-price="${s.price}" data-name="${s.name}">
                                    <div class="service-card-inner">
                                        ${imgUrl ? `<img src="${imgUrl}" alt="${s.name}" class="service-card-img">` : ''}
                                        <div class="service-name">${s.name}</div>
                                        <div class="small text-muted mb-1">${s.duration_minutes} min</div>
                                        <div class="service-price">${money(s.price)}</div>
                                        <div class="service-check-mark">✓</div>
                                    </div>
                                </label>`;
                            }).join('')}
                        </div>
                        ` : ''}

                        <div class="step-footer mt-4">
                            <button type="button" class="btn btn-outline-secondary btn-prev-step" data-prev="1">← Back</button>
                            <span class="text-muted small" id="serviceCount">0 selected</span>
                            <button type="button" class="btn btn-luxury btn-next-step" data-next="3">Therapist →</button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Therapist (dynamically loaded by area) -->
                <div class="booking-panel" id="bookStep3">
                    <div class="hero-card">
                        <h4 class="section-title mb-1">Choose Your Therapist</h4>
                        <p class="text-muted small mb-4">All therapists are certified and background-verified.</p>
                        <div id="therapistPickGrid" class="therapist-pick-grid">
                            <p class="text-muted small">Loading therapists…</p>
                        </div>
                        <div class="step-footer mt-4">
                            <button type="button" class="btn btn-outline-secondary btn-prev-step" data-prev="2">← Back</button>
                            <button type="button" class="btn btn-luxury btn-next-step" data-next="4">Your Details →</button>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Details + Summary -->
                <div class="booking-panel" id="bookStep4">
                    <div class="row g-4 align-items-start">
                        <div class="col-lg-7">
                            <div class="hero-card">
                                <h4 class="section-title mb-3">Your Details</h4>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Full Name</label>
                                        <input type="text" class="form-control" name="customer_name" placeholder="As on ID" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Phone / WhatsApp</label>
                                        <input type="text" class="form-control" name="customer_phone" placeholder="+62…" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Villa / Hotel Address</label>
                                        <input type="text" class="form-control" name="customer_address" placeholder="Full address for therapist arrival">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Notes for Therapist</label>
                                        <textarea class="form-control" name="notes" rows="3" placeholder="Allergies, preferences, access instructions…"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold mb-2">Payment Method</label>
                                        <div class="d-flex gap-3 flex-wrap" id="paymentMethodGroup">
                                            <label class="service-card m-0" style="max-width:260px;">
                                                <input type="radio" class="d-none" name="payment_method" value="bank_transfer" ${bankTransferEnabled ? '' : 'disabled'} ${bankTransferEnabled ? 'checked' : ''}>
                                                <div class="service-card-inner">
                                                    <div class="service-name">Bank Transfer</div>
                                                    <div class="small text-muted">${bankTransferEnabled ? 'Manual transfer, then send proof.' : 'Temporarily disabled by admin.'}</div>
                                                </div>
                                            </label>
                                            <label class="service-card m-0" style="max-width:260px;">
                                                <input type="radio" class="d-none" name="payment_method" value="credit_card" ${creditCardEnabled ? '' : 'disabled'} ${!bankTransferEnabled && creditCardEnabled ? 'checked' : ''}>
                                                <div class="service-card-inner">
                                                    <div class="service-name">Credit Card (Stripe)</div>
                                                    <div class="small text-muted">${creditCardEnabled ? 'Secure card payment via Stripe.' : (creditCardEnabledByAdmin ? 'Temporarily unavailable (Stripe key not set).' : 'Temporarily disabled by admin.')}</div>
                                                </div>
                                            </label>
                                        </div>
                                        ${hasPaymentMethod ? '' : '<div class="form-text text-danger mt-1">No payment method is currently available. Please contact admin.</div>'}
                                        <div id="bankTransferDetailsBox" class="alert alert-light border mt-2 mb-0 small" style="white-space: pre-line;">${bankTransferDetails}</div>
                                    </div>
                                </div>
                                <div class="step-footer mt-4">
                                    <button type="button" class="btn btn-outline-secondary btn-prev-step" data-prev="3">← Back</button>
                                    <button class="btn btn-luxury px-4" type="submit">Confirm &amp; Pay</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="booking-summary panel-card">
                                <h5 class="mb-3">Order Summary</h5>
                                <div class="summary-row"><span class="text-muted">Area</span><span id="sumArea">—</span></div>
                                <div class="summary-row"><span class="text-muted">Date &amp; Time</span><span id="sumDateTime">—</span></div>
                                <div class="summary-row"><span class="text-muted">Therapist</span><span id="sumTherapist">—</span></div>
                                <hr class="my-2">
                                <div id="summaryServices" class="mb-2 small"></div>
                                <div class="summary-row total-row mt-2"><span>Estimated Total</span><strong id="sumTotal">—</strong></div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div id="bookingResult" class="mt-3"></div>
        </section>
    `;
}

function authTemplate() {
    return `
        <section class="hero-card">
            <div class="row g-4">
                <div class="col-md-6">
                    <h3>Login</h3>
                    <form id="loginForm" class="d-grid gap-2">
                        <input class="form-control" type="email" name="email" placeholder="Email" required>
                        <input class="form-control" type="password" name="password" placeholder="Password" required>
                        <button class="btn btn-luxury" type="submit" data-submit-label="Login">Login</button>
                    </form>
                </div>
                <div class="col-md-6">
                    <h3>Register</h3>
                    <form id="registerForm" class="d-grid gap-2">
                        <input class="form-control" type="text" name="name" placeholder="Full Name" required>
                        <input class="form-control" type="email" name="email" placeholder="Email" required>
                        <input class="form-control" type="password" name="password" placeholder="Password" required>
                        <button class="btn btn-outline-dark" type="submit" data-submit-label="Create Account">Create Account</button>
                    </form>
                </div>
            </div>
            <div id="authResult" class="mt-3"></div>
        </section>
    `;
}

async function customerDashboardTemplate() {
    if (!state.user) return staticTemplate('Customer Dashboard', 'Please login first.');

    const res = await apiFetch('api/bookings/my');
    const bookings = res?.data?.bookings || [];
    const latest = bookings[0] || null;
    const paidBookings = bookings.filter((b) => b.payment_status === 'paid').length;

    return `
        <section class="hero-card customer-index mb-3">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <p class="pill mb-2">Customer Index</p>
                    <h2 class="section-title mb-1">Welcome back, ${state.user.name}</h2>
                    <p class="text-muted mb-0">Plan your next home spa treatment in under 2 minutes.</p>
                </div>
                <a class="btn btn-luxury" href="#/booking">Book New Session</a>
            </div>
        </section>

        <section class="row g-3 mb-3 customer-stats">
            <div class="col-6 col-lg-3"><div class="panel-card stat-card"><div class="small text-muted">Total Bookings</div><div class="stat-value">${bookings.length}</div></div></div>
            <div class="col-6 col-lg-3"><div class="panel-card stat-card"><div class="small text-muted">Paid</div><div class="stat-value">${paidBookings}</div></div></div>
            <div class="col-6 col-lg-3"><div class="panel-card stat-card"><div class="small text-muted">Pending</div><div class="stat-value">${bookings.filter((b) => b.payment_status !== 'paid').length}</div></div></div>
            <div class="col-6 col-lg-3"><div class="panel-card stat-card"><div class="small text-muted">Latest Total</div><div class="stat-value small-value">${latest ? money(latest.total_amount) : '-'}</div></div></div>
        </section>

        <section class="hero-card">
            <h3 class="section-title">My Booking History</h3>
            <div class="d-lg-none d-grid gap-2">
                ${bookings.map((b) => `
                    <div class="panel-card mobile-booking-card">
                        <div class="d-flex justify-content-between align-items-center mb-1"><strong>${b.booking_code}</strong>${bookingStatusBadge(b.booking_status)}</div>
                        <div class="small text-muted">${b.booking_date} ${b.booking_time}</div>
                        <div class="small">Therapist: ${b.therapist_name}</div>
                        <div class="mt-1 fw-semibold">${money(b.total_amount)}</div>
                    </div>
                `).join('') || '<div class="panel-card">No bookings yet.</div>'}
            </div>
            <div class="table-responsive panel-card d-none d-lg-block">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Code</th><th>Date</th><th>Therapist</th><th>Total</th><th>Payment</th><th>Status</th></tr></thead>
                    <tbody>
                        ${bookings.map((b) => `<tr><td>${b.booking_code}</td><td>${b.booking_date} ${b.booking_time}</td><td>${b.therapist_name}</td><td>${money(b.total_amount)}</td><td>${b.payment_status}</td><td>${bookingStatusBadge(b.booking_status)}</td></tr>`).join('') || '<tr><td colspan="6">No bookings yet.</td></tr>'}
                    </tbody>
                </table>
            </div>
        </section>
    `;
}

async function therapistPanelTemplate() {
    if (!state.user || state.user.role !== 'therapist') return staticTemplate('Therapist Panel', 'Therapist account required.');

    const [dashboardRes, bookingsRes, profileRes] = await Promise.all([
        apiFetch('api/therapist/dashboard'),
        apiFetch('api/therapist/bookings'),
        apiFetch('api/therapist/profile'),
    ]);

    const stats = dashboardRes?.data?.stats || {};
    const upcoming = dashboardRes?.data?.upcoming || [];
    const bookings = bookingsRes?.data?.bookings || [];
    const profile = profileRes?.data?.profile || {};

    return `
        <section class="dashboard-shell">
            <aside class="dashboard-sidebar">
                <div class="sidebar-head">
                    <p class="pill mb-2">Therapist Panel</p>
                    <h4 class="m-0">Workspace</h4>
                </div>
                <button class="sidebar-link active" data-panel-target="therapist-overview">Overview</button>
                <button class="sidebar-link" data-panel-target="therapist-bookings">My Bookings</button>
                <button class="sidebar-link" data-panel-target="therapist-profile">Profile</button>
                <button class="sidebar-link" data-panel-target="therapist-availability">Availability</button>
            </aside>

            <div class="dashboard-content">
                <section id="therapist-overview" class="panel-section active">
                    <section class="hero-card therapist-index mb-3">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h2 class="section-title mb-1">Therapist Workspace</h2>
                                <p class="text-muted mb-0">Manage your upcoming sessions and daily schedule smoothly.</p>
                            </div>
                            <a class="btn btn-outline-dark" href="#/therapists">Public Therapist List</a>
                        </div>
                    </section>

                    <section class="row g-3 mb-3">
                        <div class="col-12 col-md-4"><div class="panel-card stat-card therapist"><div class="small text-muted">Total Bookings</div><div class="stat-value">${stats.total_bookings || 0}</div></div></div>
                        <div class="col-12 col-md-4"><div class="panel-card stat-card therapist"><div class="small text-muted">Confirmed</div><div class="stat-value">${stats.confirmed_bookings || 0}</div></div></div>
                        <div class="col-12 col-md-4"><div class="panel-card stat-card therapist"><div class="small text-muted">Completed</div><div class="stat-value">${stats.completed_bookings || 0}</div></div></div>
                    </section>

                    <section class="hero-card">
                        <h3 class="section-title">Upcoming Visits</h3>
                        <div class="therapist-timeline">
                            ${upcoming.map((b) => `
                                <div class="timeline-item">
                                    <div class="timeline-time">${b.booking_date}<br>${b.booking_time}</div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <strong>${b.customer_name}</strong>
                                            ${bookingStatusBadge(b.booking_status)}
                                        </div>
                                        <div class="small text-muted">Booking ${b.booking_code}</div>
                                    </div>
                                </div>
                            `).join('') || '<div class="panel-card">No upcoming bookings.</div>'}
                        </div>
                    </section>
                </section>

                <section id="therapist-bookings" class="panel-section">
                    <section class="hero-card">
                        <h3 class="section-title">My Bookings</h3>
                        <div class="d-grid gap-2 d-lg-none">
                            ${bookings.map((b) => `<div class="panel-card therapist-mobile-card">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="fw-semibold">${b.booking_code}</div>
                                    ${bookingStatusBadge(b.booking_status)}
                                </div>
                                <div class="small text-muted mt-1">${b.booking_date} ${b.booking_time}</div>
                                <div class="small mt-2"><strong>${b.customer_name || '-'}</strong> · ${b.customer_phone || '-'}</div>
                                <div class="small text-muted mt-1" style="white-space:pre-line">${b.order_details || '-'}</div>
                                <div class="small mt-2 d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold">${money(b.total_amount)}</span>
                                    <span>${bookingStatusBadge(b.payment_status)}</span>
                                </div>
                                <div class="mt-3 d-flex">
                                    <button class="btn btn-outline-secondary btn-sm w-100 js-view-booking-therapist" data-booking='${JSON.stringify(b).replace(/'/g,"&#39;")}'>View Details</button>
                                </div>
                            </div>`).join('') || '<div class="panel-card text-muted small">No bookings found.</div>'}
                        </div>
                        <div class="table-responsive panel-card d-none d-lg-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Code</th><th>Date</th><th>Customer</th><th>Services</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                    ${bookings.map((b) => `<tr>
                                        <td><span class="fw-semibold">${b.booking_code}</span></td>
                                        <td>${b.booking_date}<div class="small text-muted">${b.booking_time}</div></td>
                                        <td>${b.customer_name}<div class="small text-muted">${b.customer_phone}</div></td>
                                        <td class="small" style="white-space:pre-line;max-width:140px">${b.order_details || '-'}</td>
                                        <td>${money(b.total_amount)}</td>
                                        <td>${bookingStatusBadge(b.payment_status)}</td>
                                        <td>${bookingStatusBadge(b.booking_status)}</td>
                                        <td><button class="btn btn-outline-secondary btn-sm js-view-booking-therapist" data-booking='${JSON.stringify(b).replace(/'/g,"&#39;")}'>View</button></td>
                                    </tr>`).join('') || '<tr><td colspan="8">No bookings found.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <section id="therapist-profile" class="panel-section">
                    <section class="hero-card">
                        <h3 class="section-title">Edit Profile</h3>
                        <form id="therapistProfileForm" class="row g-3">
                            <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" value="${profile.name || ''}" required></div>
                            <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="${profile.phone || ''}"></div>
                            <div class="col-md-6"><label class="form-label">Specialty</label><input class="form-control" name="specialty" value="${profile.specialty || ''}"></div>
                            <div class="col-md-6"><label class="form-label">Experience (years)</label><input type="number" min="0" class="form-control" name="experience_years" value="${profile.experience_years || 0}"></div>
                            <div class="col-12"><label class="form-label">Bio</label><textarea class="form-control" rows="3" name="bio">${profile.bio || ''}</textarea></div>
                            <div class="col-12"><button class="btn btn-luxury" type="submit">Save Profile</button></div>
                        </form>
                        <div id="therapistProfileResult" class="mt-2"></div>

                        <hr>
                        <h5 class="mb-2">Profile Photo</h5>
                        <div class="d-flex align-items-center gap-3 flex-wrap mb-2">
                            <img src="${therapistPhotoUrl(profile.photo_url, '84x84')}" alt="Therapist photo" class="therapist-avatar-lg" onerror="this.onerror=null;this.src='${avatarFallbackUrl('84x84')}'">
                            <form id="therapistPhotoForm" class="d-flex gap-2 align-items-center flex-wrap" enctype="multipart/form-data">
                                <input type="file" class="form-control form-control-sm" name="photo" accept="image/*" required>
                                <button class="btn btn-outline-dark btn-sm" type="submit">Upload Photo</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="therapistChooseFromLibraryBtn">Choose from File Library</button>
                            </form>
                        </div>
                        <div class="form-text mb-2">Recommended size: 600 x 600 px (1:1 square). JPG, PNG, or WebP up to 2 MB.</div>
                        <div id="therapistPhotoResult"></div>

                        <div class="modal fade" id="therapistMediaLibraryModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Choose Profile Photo from File Library</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div id="therapistMediaLibraryGrid" class="admin-files-grid"></div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </section>

                <section id="therapist-availability" class="panel-section">
                    <section class="hero-card">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <h3 class="section-title m-0">My Availability</h3>
                            <button class="btn ${Number(profile.is_active) === 1 ? 'btn-outline-danger' : 'btn-luxury'}" id="toggleAvailabilityBtn" data-active="${Number(profile.is_active) === 1 ? 1 : 0}">
                                ${Number(profile.is_active) === 1 ? 'Turn Off Availability' : 'Turn On Availability'}
                            </button>
                        </div>
                        <p class="small text-muted">Current status: <strong>${Number(profile.is_active) === 1 ? 'Available' : 'Unavailable'}</strong></p>
                        <div id="therapistAvailabilityResult"></div>
                    </section>
                </section>
            </div>
        </section>
    <!-- Booking Detail Modal (therapist — read-only) -->
    <div class="modal fade" id="therapistBookingDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title">Booking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="therapistBookingDetailBody"></div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    `;
}

async function adminPanelTemplate() {
    if (!state.user || state.user.role !== 'admin') return staticTemplate('Admin Panel', 'Admin account required.');

    const [dashboardRes, therapistsRes, servicesRes, areasRes, bookingsRes, paymentsRes, auditRes, settingsRes, filesRes] = await Promise.all([
        apiFetch('api/admin/dashboard'),
        apiFetch('api/admin/therapists'),
        apiFetch('api/admin/services'),
        apiFetch('api/admin/areas'),
        apiFetch('api/admin/bookings'),
        apiFetch('api/admin/payments'),
        apiFetch('api/admin/audit'),
        apiFetch('api/settings').catch(() => ({})),
        apiFetch('api/admin/files').catch(() => ({})),
    ]);

    const metrics = dashboardRes?.data?.metrics || {};
    const operations = metrics.operations || {};
    const finance = metrics.finance || {};
    const s = dashboardRes?.data?.stats || {};
    const recent = dashboardRes?.data?.recent_bookings || [];
    const bookingSummary = dashboardRes?.data?.booking_summary || [];
    const therapists = therapistsRes?.data?.therapists || [];
    const services = servicesRes?.data?.services || [];
    const categories = servicesRes?.data?.categories || [];
    const areas = areasRes?.data?.areas || [];
    const bookings = bookingsRes?.data?.bookings || [];
    const payments = paymentsRes?.data?.payments || [];
    const auditLogs = auditRes?.data?.logs || [];
    const auditPagination = auditRes?.data?.pagination || {};
    const siteSettings = settingsRes?.data?.settings || {};
    const mediaFiles = filesRes?.data?.files || [];
    const customersTotal = Number(operations.customers_total ?? s.customers ?? 0);
    const customersRegisteredTotal = Number(operations.customers_registered_total ?? customersTotal);
    const therapistsTotal = Number(operations.therapists_total ?? s.therapists ?? 0);
    const therapistsActive = Number(operations.therapists_active ?? 0);
    const bookingsTotal = Number(operations.bookings_total ?? s.bookings ?? 0);
    const collectedAmount = Number(finance.payments_collected_amount ?? s.total_revenue ?? s.payments_paid ?? 0);
    const pendingAmount = Number(finance.payments_pending_amount ?? s.payments_pending ?? 0);
    const weekBookings = bookingSummary.reduce((sum, day) => sum + Number(day.bookings || 0), 0);
    const weekRevenue = bookingSummary.reduce((sum, day) => sum + Number(day.revenue || 0), 0);
    const peakDay = bookingSummary.reduce((best, day) => {
        if (!best || Number(day.bookings || 0) > Number(best.bookings || 0)) return day;
        return best;
    }, null);
    const maxBookings = Math.max(...bookingSummary.map((day) => Number(day.bookings || 0)), 1);

    return `
        <section class="dashboard-shell">
            <aside class="dashboard-sidebar admin-sidebar">
                <div class="sidebar-head">
                    <p class="pill mb-2">Admin Panel</p>
                    <h4 class="m-0">Website Control</h4>
                </div>
                <button class="sidebar-link active" data-panel-target="admin-overview">Overview</button>
                <button class="sidebar-link" data-panel-target="admin-bookings">Bookings</button>
                <button class="sidebar-link" data-panel-target="admin-payments">Payment</button>
                <button class="sidebar-link" data-panel-target="admin-payment-methods">Payment Methods</button>
                <button class="sidebar-link" data-panel-target="admin-audit">Audit Viewer</button>
                <button class="sidebar-link" data-panel-target="admin-therapists">Therapist</button>
                <button class="sidebar-link" data-panel-target="admin-services">Service</button>
                <button class="sidebar-link" data-panel-target="admin-areas">Coverage Areas</button>
                <button class="sidebar-link" data-panel-target="admin-settings">Site Settings</button>
                <button class="sidebar-link" data-panel-target="admin-files">File</button>
            </aside>

            <div class="dashboard-content">
                <section id="admin-overview" class="panel-section active">
                    <section class="hero-card admin-index mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div>
                                <h2 class="section-title mb-1">Operations Command Center</h2>
                                <p class="text-muted mb-0">Monitor customers, therapists, bookings, and revenue from one screen.</p>
                            </div>
                            <div class="pill">Live Data Snapshot</div>
                        </div>
                    </section>

                    <section class="row g-3 mb-3 admin-metrics">
                        <div class="col-6 col-lg-3"><div class="panel-card stat-card admin"><div class="small text-muted">Customers (With Booking)</div><div class="stat-value">${customersTotal}</div><div class="small text-muted mt-1">Registered ${customersRegisteredTotal}</div></div></div>
                        <div class="col-6 col-lg-3"><div class="panel-card stat-card admin"><div class="small text-muted">Total Therapists</div><div class="stat-value">${therapistsTotal}</div><div class="small text-muted mt-1">${therapistsActive} active</div></div></div>
                        <div class="col-6 col-lg-3"><div class="panel-card stat-card admin"><div class="small text-muted">Total Bookings</div><div class="stat-value">${bookingsTotal}</div></div></div>
                        <div class="col-6 col-lg-3"><div class="panel-card stat-card admin"><div class="small text-muted">Collected Revenue</div><div class="stat-value">${money(collectedAmount)}</div><div class="small text-muted mt-1">Pending ${money(pendingAmount)}</div></div></div>
                    </section>

                    <section class="row g-3 mb-3 admin-overview-grid">
                        <div class="col-lg-8">
                            <section class="hero-card admin-summary-card h-100">
                                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                                    <div>
                                        <h3 class="section-title mb-1">Weekly Booking Summary</h3>
                                        <p class="text-muted mb-0">A quick view of bookings created over the last 7 days.</p>
                                    </div>
                                    <div class="pill">${weekBookings} bookings this week</div>
                                </div>
                                <div class="admin-summary-chart" aria-label="Weekly booking chart">
                                    ${bookingSummary.map((day) => {
                                        const count = Number(day.bookings || 0);
                                        const height = Math.max(14, Math.round((count / maxBookings) * 100));
                                        return `
                                            <div class="summary-bar-col">
                                                <div class="summary-bar-wrap">
                                                    <div class="summary-bar" style="height:${height}%" title="${day.label}: ${count} bookings"></div>
                                                </div>
                                                <div class="summary-bar-value">${count}</div>
                                                <div class="summary-bar-label">${day.label}</div>
                                            </div>
                                        `;
                                    }).join('') || '<div class="text-muted small">No booking activity yet.</div>'}
                                </div>
                            </section>
                        </div>
                        <div class="col-lg-4">
                            <section class="hero-card admin-summary-card h-100">
                                <h3 class="section-title mb-3">7-Day Highlights</h3>
                                <div class="d-grid gap-2 admin-summary-stack">
                                    <div class="panel-card summary-highlight">
                                        <div class="small text-muted">Revenue This Week</div>
                                        <div class="stat-value small-value">${money(weekRevenue)}</div>
                                    </div>
                                    <div class="panel-card summary-highlight">
                                        <div class="small text-muted">Peak Day</div>
                                        <div class="fw-semibold">${peakDay?.label || '-'}</div>
                                        <div class="small text-muted">${peakDay ? `${peakDay.bookings} bookings` : 'No recent bookings'}</div>
                                    </div>
                                    <div class="panel-card summary-highlight">
                                        <div class="small text-muted">Average Daily Volume</div>
                                        <div class="fw-semibold">${bookingSummary.length ? (weekBookings / bookingSummary.length).toFixed(1) : '0.0'} bookings</div>
                                        <div class="small text-muted">Based on the last 7 days</div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </section>

                    <section class="hero-card">
                        <h3 class="section-title">Recent Bookings</h3>
                        <div class="d-grid gap-2 admin-feed">
                            ${recent.map((b) => `
                                <div class="panel-card feed-row">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <strong>${b.booking_code}</strong>
                                        ${bookingStatusBadge(b.booking_status)}
                                    </div>
                                    <div class="small text-muted">${b.customer_name} | ${b.booking_date}</div>
                                    <div class="fw-semibold">${money(b.total_amount)}</div>
                                </div>
                            `).join('') || '<div class="panel-card">No recent bookings.</div>'}
                        </div>
                    </section>
                </section>

                <section id="admin-therapists" class="panel-section">
                    <section class="hero-card mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                            <div>
                                <h3 class="section-title mb-1">Therapist List</h3>
                                <p class="text-muted mb-0">Manage therapist accounts, specialties, and availability status from one table.</p>
                            </div>
                            <button class="btn btn-luxury btn-sm" type="button" id="adminNewTherapistBtn">New Therapist</button>
                        </div>
                        <div class="d-grid gap-2 d-lg-none">
                            ${therapists.map((t) => {
                                const g = Array.isArray(t.coverage_groups) && t.coverage_groups.length > 0
                                    ? t.coverage_groups.join(', ')
                                    : 'None';
                                const covLabel = g === 'A, B' ? '<span class="badge bg-success-subtle text-success-emphasis">A + B</span>'
                                    : g === 'A' ? '<span class="badge bg-info-subtle text-info-emphasis">A</span>'
                                    : g === 'B' ? '<span class="badge bg-warning-subtle text-warning-emphasis">B</span>'
                                    : '<span class="badge bg-secondary-subtle text-secondary-emphasis">-</span>';
                                return `<div class="panel-card admin-mobile-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="${therapistPhotoUrl(t.photo_url, '56x56')}" alt="${t.name}" class="therapist-avatar therapist-avatar-admin-mobile" onerror="this.onerror=null;this.src='${avatarFallbackUrl('56x56')}'">
                                            <div>
                                            <div class="fw-semibold">${t.name}</div>
                                            <div class="small text-muted">${t.email}</div>
                                            </div>
                                        </div>
                                        ${Number(t.is_active) === 1 ? '<span class="badge bg-success-subtle text-success-emphasis">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>'}
                                    </div>
                                    <div class="small mt-2">${t.specialty || '-'}</div>
                                    <div class="small text-muted mt-1">${Number(t.experience_years || 0)} yrs · Rating ${Number(t.rating || 0).toFixed(1)}</div>
                                    <div class="mt-2">${covLabel}</div>
                                    <div class="mt-3 d-flex justify-content-end">
                                        <button class="btn btn-outline-secondary btn-sm js-edit-therapist" type="button" data-therapist='${JSON.stringify(t).replace(/'/g, '&#39;')}'>Edit</button>
                                    </div>
                                </div>`;
                            }).join('') || '<div class="panel-card text-muted small">No therapists found.</div>'}
                        </div>
                        <div class="table-responsive panel-card d-none d-lg-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Photo</th><th>Name</th><th>Email</th><th>Specialty</th><th>Exp.</th><th>Rating</th><th>Coverage</th><th>Status</th><th>Action</th></tr></thead>
                                <tbody>
                                    ${therapists.map((t) => {
                                        const g = Array.isArray(t.coverage_groups) && t.coverage_groups.length > 0
                                            ? t.coverage_groups.join(', ')
                                            : 'None';
                                        const covLabel = g === 'A, B' ? '<span class="badge bg-success-subtle text-success-emphasis">A + B</span>'
                                            : g === 'A' ? '<span class="badge bg-info-subtle text-info-emphasis">A</span>'
                                            : g === 'B' ? '<span class="badge bg-warning-subtle text-warning-emphasis">B</span>'
                                            : '<span class="badge bg-secondary-subtle text-secondary-emphasis">-</span>';
                                        return `<tr>
                                            <td><img src="${therapistPhotoUrl(t.photo_url, '56x56')}" alt="${t.name}" class="therapist-avatar therapist-avatar-admin-table" onerror="this.onerror=null;this.src='${avatarFallbackUrl('56x56')}'"></td>
                                            <td>${t.name}</td>
                                            <td>${t.email}</td>
                                            <td>${t.specialty || '-'}</td>
                                            <td>${t.experience_years}</td>
                                            <td>${t.rating}</td>
                                            <td>${covLabel}</td>
                                            <td>${Number(t.is_active) === 1 ? '<span class="badge bg-success-subtle text-success-emphasis">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>'}</td>
                                            <td>
                                                <button class="btn btn-outline-secondary btn-sm js-edit-therapist" type="button" data-therapist='${JSON.stringify(t).replace(/'/g, '&#39;')}'>Edit</button>
                                            </td>
                                        </tr>`;
                                    }).join('') || '<tr><td colspan="9">No therapists found.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div class="modal fade" id="adminTherapistModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header border-0 pb-0">
                                    <div>
                                        <h3 class="section-title mb-1" id="adminTherapistModalTitle">New Therapist</h3>
                                        <p class="text-muted small mb-0" id="adminTherapistModalSubtitle">Create or update a therapist profile without leaving this page.</p>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body pt-3">
                                    <form id="adminTherapistForm" class="d-grid gap-3">
                                        <input type="hidden" name="id" value="">
                                        <div class="row g-3">
                                            <div class="col-md-6"><input class="form-control" name="name" placeholder="Therapist Name" required></div>
                                            <div class="col-md-6"><input type="email" class="form-control" name="email" placeholder="Email" required></div>
                                            <div class="col-md-6"><input class="form-control" name="phone" placeholder="Phone"></div>
                                            <div class="col-md-6"><input class="form-control" name="specialty" placeholder="Specialty"></div>
                                            <div class="col-12">
                                                <label class="form-label small fw-semibold">Profile Photo</label>
                                                <input type="hidden" name="photo_url" id="adminTherapistPhotoUrl" value="">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <input class="form-control" type="file" id="adminTherapistPhotoInput" accept="image/jpeg,image/png" style="flex:1;min-width:220px">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="adminTherapistChooseFromLibraryBtn">Choose from File Library</button>
                                                    <button type="button" class="btn btn-outline-dark btn-sm" id="adminTherapistClearPhotoBtn">Clear</button>
                                                    <div id="adminTherapistPhotoPreview" class="admin-therapist-photo-preview"></div>
                                                    <div id="adminTherapistPhotoBadge" class="d-none">
                                                        <span class="badge bg-info-subtle text-info-emphasis small">From Library</span>
                                                    </div>
                                                </div>
                                                <div class="form-text">Use square image for best result (recommended 600 x 600 px).</div>
                                                <div id="adminTherapistPhotoResult" class="mt-1"></div>
                                            </div>
                                            <div class="col-md-6"><input type="number" min="0" class="form-control" name="experience_years" placeholder="Experience"></div>
                                            <div class="col-md-6"><input type="number" min="1" max="5" step="0.1" class="form-control" name="rating" value="5" placeholder="Rating"></div>
                                            <div class="col-12"><input type="password" class="form-control" name="password" placeholder="Password (required for new therapist)"></div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                                            <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="is_active" checked><label class="form-check-label">Active</label></div>
                                            <div class="small text-muted">Leave password empty when editing to keep the current password.</div>
                                        </div>
                                        <div id="adminTherapistResult"></div>
                                        <div class="d-flex justify-content-end gap-2">
                                            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                                            <button class="btn btn-luxury" type="submit">Save Therapist</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="admin-services" class="panel-section">
                    <section class="hero-card mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                            <div>
                                <h3 class="section-title mb-1">Service List</h3>
                                <p class="text-muted mb-0">Keep service catalog items, pricing, and durations organized in one place.</p>
                            </div>
                            <button class="btn btn-luxury btn-sm" type="button" id="adminNewServiceBtn">New Service</button>
                        </div>
                        <div class="d-grid gap-2 d-lg-none">
                            ${services.map((srv) => `
                                <div class="panel-card admin-mobile-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="fw-semibold">${srv.name}</div>
                                        ${Number(srv.is_active) === 1 ? '<span class="badge bg-success-subtle text-success-emphasis">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>'}
                                    </div>
                                    <div class="small text-muted mt-1">${srv.category_name}</div>
                                    <div class="small mt-2">${srv.duration_minutes} min · ${money(srv.price)}</div>
                                    <div class="mt-2">${Number(srv.is_addon) === 1 ? '<span class="badge bg-info-subtle text-info-emphasis">Add-on</span>' : '<span class="badge bg-light text-dark">Main</span>'}</div>
                                    <div class="mt-3 d-flex justify-content-end">
                                        <button class="btn btn-outline-secondary btn-sm js-edit-service" type="button" data-service='${JSON.stringify(srv).replace(/'/g, '&#39;')}'>Edit</button>
                                    </div>
                                </div>
                            `).join('') || '<div class="panel-card text-muted small">No services found.</div>'}
                        </div>
                        <div class="table-responsive panel-card d-none d-lg-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Image</th><th>Name</th><th>Category</th><th>Duration</th><th>Price</th><th>Type</th><th>Status</th><th>Action</th></tr></thead>
                                <tbody>
                                    ${services.map((srv) => {
                                        const imgUrl = srv.image_url ? apiUrl(srv.image_url.replace(/^\//, '')) : '';
                                        return `
                                        <tr>
                                            <td style="width:56px">${imgUrl ? `<img src="${imgUrl}" alt="" style="width:48px;height:36px;object-fit:cover;border-radius:6px">` : '<span class="text-muted small">—</span>'}</td>
                                            <td>${srv.name}</td><td>${srv.category_name}</td><td>${srv.duration_minutes} min</td><td>${money(srv.price)}</td><td>${Number(srv.is_addon) === 1 ? '<span class="badge bg-info-subtle text-info-emphasis">Add-on</span>' : '<span class="badge bg-light text-dark">Main</span>'}</td><td>${Number(srv.is_active) === 1 ? '<span class="badge bg-success-subtle text-success-emphasis">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>'}</td>
                                            <td><button class="btn btn-outline-secondary btn-sm js-edit-service" type="button" data-service='${JSON.stringify(srv).replace(/'/g, '&#39;')}'>Edit</button></td>
                                        </tr>`;
                                    }).join('') || '<tr><td colspan="8">No services found.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div class="modal fade" id="adminServiceModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header border-0 pb-0">
                                    <div>
                                        <h3 class="section-title mb-1" id="adminServiceModalTitle">New Service</h3>
                                        <p class="text-muted small mb-0" id="adminServiceModalSubtitle">Add or update a service without leaving the service list.</p>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body pt-3">
                                    <form id="adminServiceForm" class="d-grid gap-3">
                                        <input type="hidden" name="id" value="">
                                        <div class="row g-3">
                                            <div class="col-md-7"><input class="form-control" name="name" placeholder="Service Name" required></div>
                                            <div class="col-md-5">
                                                <select class="form-select" name="category_id" required>
                                                    <option value="">Choose Category</option>
                                                    ${categories.map((c) => `<option value="${c.id}">${c.name}</option>`).join('')}
                                                </select>
                                            </div>
                                            <div class="col-12"><textarea class="form-control" name="description" rows="3" placeholder="Description"></textarea></div>
                                            <div class="col-12">
                                                <label class="form-label small fw-semibold">Service Image (JPG/PNG, max 5 MB)</label>
                                                <input type="hidden" name="image_url" id="adminServiceImageUrl" value="">
                                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                                    <input class="form-control" type="file" id="adminServiceImageInput" accept="image/jpeg,image/png" style="flex:1;min-width:200px">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="adminServiceChooseFromLibraryBtn">Choose from File Library</button>
                                                    <div id="adminServiceImagePreview" style="width:80px;height:60px;border-radius:8px;border:1px solid #dee2e6;background:#f8f9fa;background-size:cover;background-position:center;flex-shrink:0"></div>
                                                </div>
                                                <div class="form-text">Recommended size: 800 x 600 px (4:3 ratio) for best fit on booking and services cards.</div>
                                                <div id="adminServiceImageResult" class="mt-1"></div>
                                            </div>
                                            <div class="col-md-4"><input type="number" class="form-control" name="duration_minutes" value="60" placeholder="Duration"></div>
                                            <div class="col-md-4"><input type="number" class="form-control" name="price" placeholder="Price" required></div>
                                            <div class="col-md-4"><input type="number" class="form-control" name="sort_order" value="0" placeholder="Sort"></div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                                            <div class="d-flex gap-3 flex-wrap">
                                                <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="is_addon"><label class="form-check-label">Add-on</label></div>
                                                <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="is_active" checked><label class="form-check-label">Active</label></div>
                                            </div>
                                            <div class="small text-muted">Use sort order to control how services appear on the customer flow.</div>
                                        </div>
                                        <div id="adminServiceResult"></div>
                                        <div class="d-flex justify-content-end gap-2">
                                            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                                            <button class="btn btn-luxury" type="submit">Save Service</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="admin-areas" class="panel-section">
                    <section class="hero-card mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                            <div>
                                <h3 class="section-title mb-1">Coverage Areas</h3>
                                <p class="text-muted mb-0">Edit delivery zones and group membership before assigning them to therapists.</p>
                            </div>
                            <button class="btn btn-luxury btn-sm" type="button" id="adminNewAreaBtn">New Area</button>
                        </div>
                        <div class="d-grid gap-2 d-lg-none">
                            ${areas.map((a) => `
                                <div class="panel-card admin-mobile-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="fw-semibold">${a.name}</div>
                                        ${Number(a.is_active) === 1 ? '<span class="badge bg-success-subtle text-success-emphasis">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>'}
                                    </div>
                                    <div class="mt-2"><span class="badge ${a.coverage_group === 'A' ? 'bg-info-subtle text-info-emphasis' : 'bg-warning-subtle text-warning-emphasis'}">Group ${a.coverage_group}</span></div>
                                    <div class="mt-3 d-flex justify-content-end">
                                        <button class="btn btn-outline-secondary btn-sm js-edit-area" type="button" data-area='${JSON.stringify(a).replace(/'/g, '&#39;')}'>Edit</button>
                                    </div>
                                </div>
                            `).join('') || '<div class="panel-card text-muted small">No areas found.</div>'}
                        </div>
                        <div class="table-responsive panel-card d-none d-lg-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Name</th><th>Group</th><th>Status</th><th>Action</th></tr></thead>
                                <tbody>
                                    ${areas.map((a) => `
                                        <tr>
                                            <td>${a.name}</td><td><span class="badge ${a.coverage_group === 'A' ? 'bg-info-subtle text-info-emphasis' : 'bg-warning-subtle text-warning-emphasis'}">Group ${a.coverage_group}</span></td><td>${Number(a.is_active) === 1 ? '<span class="badge bg-success-subtle text-success-emphasis">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>'}</td>
                                            <td><button class="btn btn-outline-secondary btn-sm js-edit-area" type="button" data-area='${JSON.stringify(a).replace(/'/g, '&#39;')}'>Edit</button></td>
                                        </tr>
                                    `).join('') || '<tr><td colspan="4">No areas found.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="hero-card mb-3">
                        <h3 class="section-title">Assign Therapist Coverage Areas</h3>
                        <p class="text-muted small mb-3">Select a therapist and pick their coverage group — all matching areas are assigned automatically.</p>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small">Therapist</label>
                                <select class="form-select form-select-sm" id="areaTherapistSelect">
                                    <option value="">— Select therapist —</option>
                                    ${therapists.map((t) => {
                                        const g = (t.coverage_groups || []).join('');
                                        const val = g === 'AB' ? 'AB' : (g === 'A' ? 'A' : (g === 'B' ? 'B' : 'none'));
                                        return `<option value="${t.id}" data-group="${val}">${t.name}</option>`;
                                    }).join('')}
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Coverage Group</label>
                                <select class="form-select form-select-sm" id="areaGroupSelect" disabled>
                                    <option value="none">— None —</option>
                                    <option value="A">Group A</option>
                                    <option value="B">Group B</option>
                                    <option value="AB">Both Groups (A + B)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <div id="adminAreasResult" class="small"></div>
                            </div>
                        </div>
                    </section>

                    <div class="modal fade" id="adminAreaModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header border-0 pb-0">
                                    <div>
                                        <h3 class="section-title mb-1" id="adminAreaModalTitle">New Coverage Area</h3>
                                        <p class="text-muted small mb-0" id="adminAreaModalSubtitle">Create or update an area and set its coverage group.</p>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body pt-3">
                                    <form id="adminAreaForm" class="d-grid gap-3">
                                        <input type="hidden" name="id" value="">
                                        <input class="form-control" name="name" placeholder="Area Name" required>
                                        <select class="form-select" name="coverage_group" required>
                                            <option value="A">Group A</option>
                                            <option value="B">Group B</option>
                                        </select>
                                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                                            <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="is_active" checked><label class="form-check-label">Active</label></div>
                                            <div class="small text-muted">Inactive areas stay in the system but are hidden from active coverage selection.</div>
                                        </div>
                                        <div id="adminAreaResult" class="mt-1"></div>
                                        <div class="d-flex justify-content-end gap-2">
                                            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                                            <button class="btn btn-luxury" type="submit">Save Coverage Area</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="admin-bookings" class="panel-section">
                    <section class="hero-card">
                        <h3 class="section-title">Bookings</h3>
                        <div class="d-grid gap-2 d-lg-none">
                            ${bookings.map((b) => `<div class="panel-card admin-mobile-card">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="fw-semibold">${b.booking_code}</div>
                                    ${bookingStatusBadge(b.booking_status)}
                                </div>
                                <div class="small text-muted mt-1">${b.booking_date} ${b.booking_time}</div>
                                <div class="small mt-2"><strong>${b.customer_name || '-'}</strong> · ${b.customer_phone || '-'}</div>
                                <div class="small text-muted mt-1">${b.therapist_name || '-'}</div>
                                <div class="small mt-2">${money(b.total_amount)} · ${bookingStatusBadge(b.payment_status)}</div>
                                <div class="mt-3 d-flex justify-content-end">
                                    <button class="btn btn-outline-secondary btn-sm js-view-booking" data-booking='${JSON.stringify(b).replace(/'/g,"&#39;")}'>View</button>
                                </div>
                            </div>`).join('') || '<div class="panel-card text-muted small">No bookings found.</div>'}
                        </div>
                        <div class="table-responsive panel-card d-none d-lg-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Code</th><th>Date</th><th>Customer</th><th>Services</th><th>Therapist</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                    ${bookings.map((b) => `<tr>
                                        <td><span class="fw-semibold">${b.booking_code}</span></td>
                                        <td>${b.booking_date}<div class="small text-muted">${b.booking_time}</div></td>
                                        <td><strong>${b.customer_name || '-'}</strong><div class="small text-muted">${b.customer_phone || '-'}</div></td>
                                        <td class="small" style="white-space:pre-line;max-width:160px">${b.order_details || '-'}</td>
                                        <td>${b.therapist_name}</td>
                                        <td>${money(b.total_amount)}</td>
                                        <td>${bookingStatusBadge(b.payment_status)}</td>
                                        <td>${bookingStatusBadge(b.booking_status)}</td>
                                        <td><button class="btn btn-outline-secondary btn-sm js-view-booking" data-booking='${JSON.stringify(b).replace(/'/g,"&#39;")}'>View</button></td>
                                    </tr>`).join('') || '<tr><td colspan="9">No bookings found.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <section id="admin-payments" class="panel-section">
                    <section class="hero-card">
                        <h3 class="section-title">Payment Records</h3>
                        <div class="d-grid gap-2 d-lg-none">
                            ${payments.map((p) => {
                                const isBank = p.provider === 'bank_transfer';
                                const paid = p.status === 'succeeded';
                                const actionBtn = isBank
                                    ? (paid
                                        ? `<button class="btn btn-outline-warning btn-sm js-payment-status" data-payment-id="${p.id}" data-target-status="pending">Mark Unpaid</button>`
                                        : `<button class="btn btn-success btn-sm js-payment-status" data-payment-id="${p.id}" data-target-status="paid">Mark Paid</button>`)
                                    : '<span class="text-muted small">Auto</span>';

                                return `<div class="panel-card admin-mobile-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="fw-semibold">${p.booking_code}</div>
                                        ${bookingStatusBadge(p.status)}
                                    </div>
                                    <div class="small mt-2"><strong>${p.customer_name || '-'}</strong> · ${p.customer_phone || '-'}</div>
                                    <div class="small text-muted mt-1">${p.provider} · ${p.created_at}</div>
                                    <div class="fw-semibold mt-2">${money(p.amount)}</div>
                                    <div class="mt-3 d-flex justify-content-end">${actionBtn}</div>
                                </div>`;
                            }).join('') || '<div class="panel-card text-muted small">No payments found.</div>'}
                        </div>
                        <div class="table-responsive panel-card d-none d-lg-block">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Booking</th><th>Customer</th><th>Provider</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                                <tbody>
                                    ${payments.map((p) => {
                                        const isBank = p.provider === 'bank_transfer';
                                        const paid = p.status === 'succeeded';
                                        const actionBtn = isBank
                                            ? (paid
                                                ? `<button class="btn btn-outline-warning btn-sm js-payment-status" data-payment-id="${p.id}" data-target-status="pending">Mark Unpaid</button>`
                                                : `<button class="btn btn-success btn-sm js-payment-status" data-payment-id="${p.id}" data-target-status="paid">Mark Paid</button>`)
                                            : '<span class="text-muted small">Auto</span>';
                                        return `<tr><td>${p.booking_code}</td><td><strong>${p.customer_name || '-'}</strong><div class="small text-muted">${p.customer_phone || '-'}</div></td><td>${p.provider}</td><td>${money(p.amount)}</td><td>${bookingStatusBadge(p.status)}</td><td>${p.created_at}</td><td>${actionBtn}</td></tr>`;
                                    }).join('') || '<tr><td colspan="7">No payments found.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                        <div id="adminPaymentActionResult" class="mt-3"></div>
                    </section>
                </section>

                <section id="admin-payment-methods" class="panel-section">
                    <section class="hero-card">
                        <h3 class="section-title">Payment Methods</h3>
                        <p class="text-muted small mb-4">Enable or disable checkout methods and manage bank transfer instructions shown to customers.</p>
                        <form id="adminPaymentMethodForm" class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="pmBankEnabled" name="payment_bank_transfer_enabled" ${String(siteSettings.payment_bank_transfer_enabled ?? '1') === '1' ? 'checked' : ''}>
                                    <label class="form-check-label" for="pmBankEnabled">Enable Bank Transfer</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="pmCardEnabled" name="payment_credit_card_enabled" ${String(siteSettings.payment_credit_card_enabled ?? '1') === '1' ? 'checked' : ''}>
                                    <label class="form-check-label" for="pmCardEnabled">Enable Credit Card (Stripe)</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Bank Transfer Details</label>
                                <textarea class="form-control" name="bank_transfer_details" rows="5" placeholder="Bank: ...&#10;Account Name: ...&#10;Account Number: ...">${siteSettings.bank_transfer_details || ''}</textarea>
                                <div class="form-text">Shown in checkout when Bank Transfer is selected.</div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-luxury" type="submit">Save Payment Methods</button>
                            </div>
                        </form>
                        <div id="adminPaymentMethodResult" class="mt-3"></div>
                    </section>
                </section>

                <section id="admin-audit" class="panel-section">
                    <section class="hero-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <div>
                                <h3 class="section-title mb-1">Audit Viewer</h3>
                                <p class="text-muted small mb-0">Recent admin write activity for traceability and troubleshooting.</p>
                            </div>
                            <span class="pill">${Number(auditPagination.total || auditLogs.length)} events</span>
                        </div>
                        <div class="d-grid gap-2 admin-audit-feed">
                            ${auditLogs.map((log) => {
                                const actor = log.admin_name || log.admin_email || `Admin #${log.admin_id || '-'}`;
                                const actionLabel = String(log.action || '').replace(/_/g, ' ').split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
                                const targetType = (log.target_type || '').split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
                                const targetId = Number(log.target_id || 0);
                                const bookingCode = String(log.target_booking_code || log.details?.booking_code || '').trim();
                                const targetText = (log.target_type === 'booking' && bookingCode)
                                    ? `Booking ${bookingCode}`
                                    : (targetId > 0 ? `${targetType} #${targetId}` : targetType || 'System');

                                const createdDate = new Date(log.created_at + 'Z');
                                const timeStr = isNaN(createdDate.getTime()) ? log.created_at : createdDate.toLocaleString('en-US', { 
                                    month: 'short', 
                                    day: 'numeric', 
                                    hour: '2-digit', 
                                    minute: '2-digit',
                                    second: '2-digit'
                                });

                                const details = log.details || {};
                                const detailsExcluded = ['source_ip', 'user_agent'];
                                const detailEntries = Object.entries(details)
                                    .filter(([key]) => !detailsExcluded.includes(key))
                                    .map(([key, value]) => {
                                        const displayKey = key.replace(/_/g, ' ').split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
                                        const displayVal = typeof value === 'object' ? JSON.stringify(value) : String(value);
                                        return `<div class="small"><span class="text-muted">${displayKey}:</span> <strong>${displayVal}</strong></div>`;
                                    });

                                const sourceIp = details.source_ip || '-';
                                const actionColor = log.action.includes('cancel') ? 'bg-danger-subtle text-danger-emphasis' 
                                                   : log.action.includes('created') ? 'bg-success-subtle text-success-emphasis'
                                                   : log.action.includes('updated') ? 'bg-info-subtle text-info-emphasis'
                                                   : 'bg-light text-dark';

                                return `<div class="panel-card feed-row" style="border-left:4px solid var(--sage)">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                        <div>
                                            <div class="fw-semibold">${actionLabel}</div>
                                            <div class="small text-muted">${actor} · ${timeStr}</div>
                                        </div>
                                        <span class="badge ${actionColor}">${targetText}</span>
                                    </div>
                                    ${detailEntries.length > 0 ? '<div class="mt-2">' + detailEntries.join('') + '</div>' : ''}
                                    <div class="small text-muted mt-2">IP: ${sourceIp}</div>
                                </div>`;
                            }).join('') || '<div class="panel-card text-center text-muted small">No audit logs available yet.</div>'}
                        </div>
                        <div class="small text-muted mt-3">Page ${Number(auditPagination.page || 1)} of ${Number(auditPagination.total_pages || 1)}</div>
                    </section>
                </section>

                <section id="admin-files" class="panel-section">
                    <section class="hero-card mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div>
                                <h3 class="section-title mb-1">File Manager</h3>
                                <p class="text-muted small mb-0">Upload and manage image files (JPG, PNG). Maximum 5 MB per file.</p>
                            </div>
                            <div class="pill">${mediaFiles.length} file${mediaFiles.length !== 1 ? 's' : ''}</div>
                        </div>
                    </section>

                    <section class="panel-card mb-4">
                        <h6 class="fw-semibold mb-3">Upload New File</h6>
                        <form id="adminFileUploadForm" class="d-flex align-items-end gap-3 flex-wrap">
                            <div style="flex:1;min-width:220px">
                                <label class="form-label small fw-semibold" for="adminFileInput">Select Image (JPG or PNG)</label>
                                <input class="form-control" type="file" id="adminFileInput" name="file" accept="image/jpeg,image/png" required>
                                <div class="form-text">Recommended size: 800 x 600 px (4:3 ratio) for best fit on service cards.</div>
                            </div>
                            <button class="btn btn-luxury" type="submit" id="adminFileUploadBtn" data-submit-label="Upload">Upload</button>
                        </form>
                        <div id="adminFileUploadResult" class="mt-2"></div>
                    </section>

                    <div id="adminFilesGrid" class="admin-files-grid">
                        ${mediaFiles.length === 0
                            ? '<div class="panel-card text-center text-muted small py-5">No files uploaded yet.</div>'
                            : mediaFiles.map((f) => {
                                const url = apiUrl(f.filename.includes('/') ? f.filename : `uploads/files/${f.filename}`);
                                const kb = Math.round(Number(f.file_size) / 1024);
                                const safeOriginal = String(f.original_name).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                return `<div class="admin-file-card" data-file-id="${f.id}">
                                    <div class="admin-file-thumb" style="background-image:url('${url}')"></div>
                                    <div class="admin-file-meta">
                                        <div class="admin-file-name" title="${safeOriginal}">${safeOriginal}</div>
                                        <div class="small text-muted">${kb} KB &middot; ${f.mime_type}</div>
                                        <div class="small text-muted">${String(f.created_at || '').slice(0,10)}</div>
                                    </div>
                                    <div class="admin-file-actions">
                                        <button class="btn btn-sm btn-outline-secondary js-view-file" data-url="${url}" data-name="${safeOriginal}">View</button>
                                        <button class="btn btn-sm btn-outline-primary js-copy-url" data-url="${url}">Copy URL</button>
                                        <button class="btn btn-sm btn-outline-danger js-delete-file" data-file-id="${f.id}" data-filename="${safeOriginal}">Delete</button>
                                    </div>
                                </div>`;
                            }).join('')
                        }
                    </div>
                </section>

    <!-- File Lightbox Modal -->
    <div class="modal fade" id="filePreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="background:transparent;border:none;box-shadow:none">
                <div class="modal-header border-0 pb-1" style="background:rgba(0,0,0,.7);border-radius:.5rem .5rem 0 0">
                    <span class="text-white small fw-semibold" id="filePreviewName" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1"></span>
                    <div class="d-flex gap-2 ms-3">
                        <button class="btn btn-sm btn-outline-light" id="filePreviewCopyBtn">Copy URL</button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-0 text-center" style="background:#111;border-radius:0 0 .5rem .5rem">
                    <img id="filePreviewImg" src="" alt="" style="max-width:100%;max-height:80vh;object-fit:contain;display:block;margin:0 auto">
                </div>
            </div>
        </div>
    </div>

    <!-- Reusable Media Library Modal -->
    <div class="modal fade" id="mediaLibraryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Choose Image from File Library</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="mediaLibraryGrid" class="admin-files-grid"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

                <section id="admin-settings" class="panel-section">
                    <section class="hero-card">
                        <h3 class="section-title">Site Settings</h3>
                        <p class="text-muted small mb-3">Compact controls for branding and HP2 home sections.</p>
                        <form id="adminSettingsForm" class="d-grid gap-3 settings-compact-form">
                            <div class="accordion" id="siteSettingsAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="setHeadBrand">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#setBrand" aria-expanded="true" aria-controls="setBrand">Brand & Logo</button>
                                    </h2>
                                    <div id="setBrand" class="accordion-collapse collapse show" aria-labelledby="setHeadBrand" data-bs-parent="#siteSettingsAccordion">
                                        <div class="accordion-body">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Logo Text</label>
                                                    <input class="form-control" name="site_logo_text" placeholder="GrabMas" value="${siteSettings.site_logo_text || 'GrabMas'}">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Logo Image URL (optional)</label>
                                                    <input class="form-control" name="site_logo_image" placeholder="https://..." value="${siteSettings.site_logo_image || ''}">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="setHeadHero">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#setHero" aria-expanded="false" aria-controls="setHero">HP2 Hero</button>
                                    </h2>
                                    <div id="setHero" class="accordion-collapse collapse" aria-labelledby="setHeadHero" data-bs-parent="#siteSettingsAccordion">
                                        <div class="accordion-body">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Hero Image Desktop</label>
                                                    <input class="form-control" name="hero_image_desktop" placeholder="https://..." value="${siteSettings.hero_image_desktop || ''}">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Hero Image Mobile</label>
                                                    <input class="form-control" name="hero_image_mobile" placeholder="https://..." value="${siteSettings.hero_image_mobile || ''}">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Kicker</label>
                                                    <input class="form-control" name="hp2_hero_kicker" placeholder="Bali Home Service Spa" value="${siteSettings.hp2_hero_kicker || ''}">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Hero Title</label>
                                                    <input class="form-control" name="hero_title" placeholder="Calm,&lt;br&gt;Delivered" value="${siteSettings.hero_title || ''}">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small fw-semibold">Hero Subtitle</label>
                                                    <input class="form-control" name="hero_subtitle" placeholder="Short tagline..." value="${siteSettings.hero_subtitle || ''}">
                                                </div>
                                                <div class="col-md-4"><input class="form-control" name="hp2_hero_proof_1" placeholder="Proof chip 1" value="${siteSettings.hp2_hero_proof_1 || ''}"></div>
                                                <div class="col-md-4"><input class="form-control" name="hp2_hero_proof_2" placeholder="Proof chip 2" value="${siteSettings.hp2_hero_proof_2 || ''}"></div>
                                                <div class="col-md-4"><input class="form-control" name="hp2_hero_proof_3" placeholder="Proof chip 3" value="${siteSettings.hp2_hero_proof_3 || ''}"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="setHeadServices">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#setServices" aria-expanded="false" aria-controls="setServices">HP2 Services</button>
                                    </h2>
                                    <div id="setServices" class="accordion-collapse collapse" aria-labelledby="setHeadServices" data-bs-parent="#siteSettingsAccordion">
                                        <div class="accordion-body">
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">Section Label</label>
                                                    <input class="form-control" name="hp2_services_label" placeholder="Signature Menu" value="${siteSettings.hp2_services_label || ''}">
                                                </div>
                                                <div class="col-md-5">
                                                    <label class="form-label small fw-semibold">Section Title</label>
                                                    <input class="form-control" name="hp2_services_title" placeholder="Spa Treatments for Every Mood" value="${siteSettings.hp2_services_title || ''}">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small fw-semibold">Link Text</label>
                                                    <input class="form-control" name="hp2_services_link_text" placeholder="See full service list" value="${siteSettings.hp2_services_link_text || ''}">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="setHeadGallery">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#setGallery" aria-expanded="false" aria-controls="setGallery">HP2 Gallery</button>
                                    </h2>
                                    <div id="setGallery" class="accordion-collapse collapse" aria-labelledby="setHeadGallery" data-bs-parent="#siteSettingsAccordion">
                                        <div class="accordion-body">
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">Section Label</label>
                                                    <input class="form-control" name="hp2_gallery_label" placeholder="Experience" value="${siteSettings.hp2_gallery_label || ''}">
                                                </div>
                                                <div class="col-md-8">
                                                    <label class="form-label small fw-semibold">Section Title</label>
                                                    <input class="form-control" name="hp2_gallery_title" placeholder="Aesthetic, Calm, and Professional" value="${siteSettings.hp2_gallery_title || ''}">
                                                </div>
                                            </div>
                                            <hr class="my-3">
                                            <p class="small text-muted mb-2"><strong>Gallery Images (8 items)</strong></p>
                                            <div class="row g-2">
                                                ${[1,2,3,4,5,6,7,8].map(i => `
                                                    <div class="col-md-6">
                                                        <input class="form-control form-control-sm mb-1" name="gallery_image_${i}" placeholder="Image ${i} URL" value="${siteSettings[`gallery_image_${i}`] || ''}">
                                                        <input class="form-control form-control-sm" name="gallery_caption_${i}" placeholder="Caption (e.g. Signature Massage)" value="${siteSettings[`gallery_caption_${i}`] || ''}">
                                                    </div>
                                                `).join('')}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="setHeadFaq">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#setFaq" aria-expanded="false" aria-controls="setFaq">HP2 FAQ</button>
                                    </h2>
                                    <div id="setFaq" class="accordion-collapse collapse" aria-labelledby="setHeadFaq" data-bs-parent="#siteSettingsAccordion">
                                        <div class="accordion-body">
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">Section Label</label>
                                                    <input class="form-control" name="hp2_faq_label" placeholder="FAQ" value="${siteSettings.hp2_faq_label || ''}">
                                                </div>
                                                <div class="col-md-8">
                                                    <label class="form-label small fw-semibold">Section Title</label>
                                                    <input class="form-control" name="hp2_faq_title" placeholder="Questions Before You Book" value="${siteSettings.hp2_faq_title || ''}">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small fw-semibold">FAQ Side Image URL</label>
                                                    <input class="form-control" name="hp2_faq_image" placeholder="https://..." value="${siteSettings.hp2_faq_image || ''}">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <button class="btn btn-luxury" type="submit">Save Settings</button>
                            </div>
                        </form>
                        <div id="adminSettingsResult" class="mt-3"></div>
                    </section>
                </section>
            </div>
        </section>
    <!-- Booking Detail Modal -->
    <div class="modal fade" id="bookingDetailModal" tabindex="-1" aria-labelledby="bookingDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="bookingDetailModalLabel">Booking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="bookingDetailBody"></div>
                <div class="modal-footer border-0 pt-0" id="bookingDetailFooter"></div>
            </div>
        </div>
    </div>
    `;
}

function attachAuthHandlers() {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const result = document.getElementById('authResult');

    function setSubmitBusy(form, busyText = 'Processing...') {
        if (!form) return null;
        const submitBtn = form.querySelector('[type="submit"]');
        if (!submitBtn) return null;

        const defaultLabel = submitBtn.dataset.submitLabel || submitBtn.textContent || 'Submit';
        submitBtn.disabled = true;
        submitBtn.textContent = busyText;

        return () => {
            submitBtn.disabled = false;
            submitBtn.textContent = defaultLabel;
        };
    }

    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(loginForm);
            const payload = Object.fromEntries(formData.entries());
            payload.email = String(payload.email || '').trim().toLowerCase();

            const restoreSubmit = setSubmitBusy(loginForm, 'Signing in...');
            if (result) result.innerHTML = '';

            try {
                const res = await apiFetch('api/auth/login', { method: 'POST', body: JSON.stringify(payload) });
                if (!res?.data?.user) {
                    throw new Error(res?.message || `Login response missing user data. Response: ${JSON.stringify(res)}`);
                }
                state.user = res.data.user;
                state.csrfToken = res.data.csrf_token;
                location.hash = getDefaultRouteByRole(state.user.role);
                applyRoleNavigation();
                renderRoute();
            } catch (err) {
                const message = err instanceof Error ? err.message : 'Login failed. Please try again.';
                if (result) result.innerHTML = '';
                showToast(message, 'danger');
            } finally {
                if (typeof restoreSubmit === 'function') restoreSubmit();
            }
        });
    }

    if (registerForm) {
        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(registerForm);
            const payload = Object.fromEntries(formData.entries());
            payload.name = String(payload.name || '').trim();
            payload.email = String(payload.email || '').trim().toLowerCase();

            const restoreSubmit = setSubmitBusy(registerForm, 'Creating account...');
            if (result) result.innerHTML = '';

            try {
                const res = await apiFetch('api/auth/register', { method: 'POST', body: JSON.stringify(payload) });
                if (!res?.data?.user) {
                    throw new Error(res?.message || `Registration response missing user data. Response: ${JSON.stringify(res)}`);
                }
                state.user = res.data.user;
                state.csrfToken = res.data.csrf_token;
                location.hash = getDefaultRouteByRole(state.user.role);
                applyRoleNavigation();
                renderRoute();
            } catch (err) {
                const message = err instanceof Error ? err.message : 'Registration failed. Please try again.';
                if (result) result.innerHTML = '';
                showToast(message, 'danger');
            } finally {
                if (typeof restoreSubmit === 'function') restoreSubmit();
            }
        });
    }
}

function attachGlobalHandlers() {
    const logoutBtn = document.getElementById('navLogout');

    if (logoutBtn && logoutBtn.dataset.bound !== '1') {
        logoutBtn.dataset.bound = '1';

        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();

            try {
                await apiFetch('api/auth/logout', { method: 'POST' });
            } catch {
                // Continue local logout even if API call fails.
            }

            state.user = null;
            applyRoleNavigation();
            location.hash = '#/home';
            renderRoute();
        });
    }
}

function showBookingConfirmModal(bookingCode, totalAmount, paymentMethod) {
    // Inject modal into body if not already there
    if (!document.getElementById('bookingConfirmModal')) {
        const el = document.createElement('div');
        el.innerHTML = `
            <div class="modal fade" id="bookingConfirmModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header border-0 pb-0">
                            <h5 class="modal-title">Booking Confirmed!</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-center py-4">
                            <div style="font-size:3rem;color:var(--sage)">&#10003;</div>
                            <h4 class="mt-2 mb-1" id="bcmCode"></h4>
                            <p class="text-muted mb-1" id="bcmAmount"></p>
                            <p class="small text-muted" id="bcmNote"></p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center pt-0">
                            <button type="button" class="btn btn-luxury" data-bs-dismiss="modal" id="bcmDoneBtn">Done</button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(el.firstElementChild);
    }
    document.getElementById('bcmCode').textContent = bookingCode;
    document.getElementById('bcmAmount').textContent = totalAmount ? money(totalAmount) : '';
    document.getElementById('bcmNote').textContent = paymentMethod === 'bank_transfer'
        ? 'Please transfer payment using the bank details above, then send proof to WhatsApp.'
        : 'Your booking has been received. Our team will confirm shortly.';

    const modal = new bootstrap.Modal(document.getElementById('bookingConfirmModal'));
    const doneBtn = document.getElementById('bcmDoneBtn');
    const onDone = () => {
        modal.hide();
        doneBtn.removeEventListener('click', onDone);
        setTimeout(() => { location.hash = '#/home'; }, 300);
    };
    doneBtn.addEventListener('click', onDone);
    document.getElementById('bookingConfirmModal').addEventListener('hidden.bs.modal', () => {
        setTimeout(() => { location.hash = '#/home'; }, 100);
    }, { once: true });
    modal.show();
}

function attachBookingHandlers() {
    const bookingForm = document.getElementById('bookingForm');
    const result = document.getElementById('bookingResult');

    if (!bookingForm) return;

    const bankTransferDetails = document.getElementById('bankTransferDetailsBox')?.textContent?.trim()
        || 'Bank transfer details are not available.';

    function isCreditCardEnabled() {
        const cardRadio = bookingForm.querySelector('[name="payment_method"][value="credit_card"]');
        return Boolean(cardRadio && !cardRadio.disabled);
    }

    function hasPaymentMethod() {
        const bankRadio = bookingForm.querySelector('[name="payment_method"][value="bank_transfer"]');
        const cardRadio = bookingForm.querySelector('[name="payment_method"][value="credit_card"]');
        return Boolean((bankRadio && !bankRadio.disabled) || (cardRadio && !cardRadio.disabled));
    }

    // ── Helpers ────────────────────────────────────────────────────────
    function goToStep(n) {
        document.querySelectorAll('.booking-panel').forEach((p) => p.classList.remove('active'));
        const panel = document.getElementById(`bookStep${n}`);
        if (panel) { panel.classList.add('active'); panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        document.querySelectorAll('#bookingStepBar .booking-step').forEach((s) => {
            const sn = parseInt(s.dataset.step, 10);
            s.classList.toggle('active', sn === n);
            s.classList.toggle('done', sn < n);
        });
        document.querySelectorAll('#bookingStepBar .step-line').forEach((line, i) => {
            line.classList.toggle('done', i < n - 1);
        });
        if (n === 4) updateSummary();
    }

    function updateSummary() {
        const areaRadio = bookingForm.querySelector('[name="area_id"]:checked');
        const date = bookingForm.querySelector('[name="booking_date"]')?.value || '';
        const time = bookingForm.querySelector('[name="booking_time"]')?.value || '';
        const therapistRadio = bookingForm.querySelector('.therapist-radio:checked');

        const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        setEl('sumArea', areaRadio ? areaRadio.closest('.area-card')?.querySelector('span:last-child')?.textContent || areaRadio.value : '—');
        setEl('sumDateTime', date && time ? `${date} at ${time}` : '—');
        setEl('sumTherapist', therapistRadio ? (therapistRadio.dataset.name || therapistRadio.value) : '—');

        const checkedServices = Array.from(document.querySelectorAll('.service-check:checked, .addon-check:checked'));
        let total = 0;
        const servicesEl = document.getElementById('summaryServices');
        if (servicesEl) {
            servicesEl.innerHTML = checkedServices.map((cb) => {
                const price = Number(cb.dataset.price || 0);
                total += price;
                return `<div class="summary-row"><span>${cb.dataset.name}</span><span>${money(price)}</span></div>`;
            }).join('') || '<div class="text-muted small">No services selected.</div>';
        }
        setEl('sumTotal', total > 0 ? money(total) : '—');
    }

    function syncPaymentMethodUI() {
        const selected = bookingForm.querySelector('[name="payment_method"]:checked');
        let value = selected ? selected.value : 'bank_transfer';
        if (value === 'credit_card' && !isCreditCardEnabled()) {
            const bankRadio = bookingForm.querySelector('[name="payment_method"][value="bank_transfer"]');
            if (bankRadio && !bankRadio.disabled) {
                bankRadio.checked = true;
                value = 'bank_transfer';
            }
        }
        bookingForm.querySelectorAll('#paymentMethodGroup .service-card').forEach((card) => {
            const radio = card.querySelector('input[type="radio"]');
            card.classList.toggle('selected', Boolean(radio && radio.checked));
        });
        const bankBox = document.getElementById('bankTransferDetailsBox');
        if (bankBox) bankBox.classList.toggle('d-none', value !== 'bank_transfer');

        const submitBtn = bookingForm.querySelector('[type="submit"]');
        if (submitBtn) submitBtn.disabled = !hasPaymentMethod();
    }

    // ── Step navigation ────────────────────────────────────────────────
    function buildTherapistCard(t) {
        return `
            <label class="therapist-pick-card">
                <input type="radio" name="therapist_pick" value="${t.id}" class="d-none therapist-radio" data-name="${t.name}">
                <div class="therapist-pick-inner">
                    <img src="${therapistPhotoUrl(t.photo_url, '72x72')}" alt="${t.name}" class="therapist-pick-img" onerror="this.onerror=null;this.src='${avatarFallbackUrl('72x72')}'">
                    <div class="therapist-pick-info">
                        <div class="fw-bold">${t.name}</div>
                        <div class="small text-muted">${t.specialty || 'General Therapy'}</div>
                        <div class="small mt-1">${Number(t.experience_years || 0)} yrs · ⭐ ${Number(t.rating || 5).toFixed(1)}</div>
                    </div>
                    <div class="pick-check-icon">✓</div>
                </div>
            </label>`;
    }

    async function loadTherapistsForStep3() {
        const grid = document.getElementById('therapistPickGrid');
        if (!grid) return;
        const areaRadio = bookingForm.querySelector('[name="area_id"]:checked');
        const date = bookingForm.querySelector('[name="booking_date"]')?.value || '';
        const time = bookingForm.querySelector('[name="booking_time"]')?.value || '';
        let url = 'api/therapists';
        const params = new URLSearchParams();
        if (areaRadio) params.set('area_id', areaRadio.value);
        if (date) params.set('date', date);
        if (time) params.set('time', time);
        if ([...params].length) url += '?' + params.toString();

        grid.innerHTML = '<p class="text-muted small">Loading therapists…</p>';
        try {
            const res = await apiFetch(url).catch(() => ({}));
            let list = res?.data?.therapists || [];

            // Fallback: if selected filters return empty, show all therapists so step 3 never feels broken.
            if (!list.length) {
                const fallbackRes = await apiFetch('api/therapists').catch(() => ({}));
                list = fallbackRes?.data?.therapists || [];
            }

            if (!list.length) {
                grid.innerHTML = '<p class="text-muted small">No therapists available for the selected area. Try a different area or date.</p>';
                return;
            }
            grid.innerHTML = list.map(buildTherapistCard).join('');
            // Re-attach pick handlers for dynamically rendered cards
            grid.querySelectorAll('.therapist-pick-card').forEach((card) => {
                card.addEventListener('click', () => {
                    grid.querySelectorAll('.therapist-pick-card').forEach((c) => c.classList.remove('selected'));
                    card.classList.add('selected');
                    const radio = card.querySelector('input[type="radio"]');
                    if (radio) radio.checked = true;
                });
            });
        } catch {
            grid.innerHTML = '<p class="text-danger small">Failed to load therapists. Please try again.</p>';
        }
    }

    document.querySelectorAll('.btn-next-step').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const next = parseInt(btn.dataset.next, 10);
            if (next === 2) {
                const area = bookingForm.querySelector('[name="area_id"]:checked');
                const date = bookingForm.querySelector('[name="booking_date"]')?.value;
                const time = bookingForm.querySelector('[name="booking_time"]')?.value;
                if (!area || !date || !time) {
                    alert('Please select area, date, and time first.');
                    return;
                }
            }
            if (next === 3) {
                const anyService = bookingForm.querySelector('.service-check:checked');
                if (!anyService) { alert('Please select at least one service.'); return; }
                goToStep(3);
                await loadTherapistsForStep3();
                return;
            }
            if (next === 4) {
                const therapistPick = bookingForm.querySelector('.therapist-radio:checked');
                if (!therapistPick) { alert('Please select a therapist.'); return; }
                document.getElementById('bookingTherapistId').value = therapistPick.value;
            }
            goToStep(next);
        });
    });

    document.querySelectorAll('.btn-prev-step').forEach((btn) => {
        btn.addEventListener('click', () => goToStep(parseInt(btn.dataset.prev, 10)));
    });

    // ── Service card toggle ────────────────────────────────────────────
    document.querySelectorAll('.service-card').forEach((card) => {
        card.addEventListener('click', () => {
            const cb = card.querySelector('input[type="checkbox"]');
            if (!cb) return;
            cb.checked = !cb.checked;
            card.classList.toggle('selected', cb.checked);
            const count = bookingForm.querySelectorAll('.service-check:checked').length;
            const countEl = document.getElementById('serviceCount');
            if (countEl) countEl.textContent = `${count} selected`;
        });
    });

    // ── Area card toggle ───────────────────────────────────────────────
    document.querySelectorAll('.area-card').forEach((card) => {
        card.addEventListener('click', () => {
            bookingForm.querySelectorAll('.area-card').forEach((c) => c.classList.remove('selected'));
            card.classList.add('selected');
        });
    });

    bookingForm.querySelectorAll('[name="payment_method"]').forEach((radio) => {
        radio.addEventListener('change', syncPaymentMethodUI);
    });
    syncPaymentMethodUI();

    function clearBookingFormAndState() {
        bookingForm.reset();
        const therapistIdInput = document.getElementById('bookingTherapistId');
        if (therapistIdInput) therapistIdInput.value = '';

        bookingForm.querySelectorAll('.area-card, .service-card, .therapist-pick-card').forEach((el) => {
            el.classList.remove('selected');
        });

        const countEl = document.getElementById('serviceCount');
        if (countEl) countEl.textContent = '0 selected';

        const grid = document.getElementById('therapistPickGrid');
        if (grid) grid.innerHTML = '<p class="text-muted small">Loading therapists…</p>';

        syncPaymentMethodUI();
        updateSummary();
        goToStep(1);
    }

    // ── Submit ─────────────────────────────────────────────────────────
    bookingForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(bookingForm);
        const serviceIds = Array.from(bookingForm.querySelectorAll('.service-check:checked')).map((cb) => Number(cb.value));
        const addonIds = Array.from(bookingForm.querySelectorAll('.addon-check:checked')).map((cb) => Number(cb.value));
        const paymentMethod = String(formData.get('payment_method') || 'bank_transfer');

        if (!hasPaymentMethod()) {
            result.innerHTML = '<div class="alert alert-danger">No payment method is currently available. Please contact admin.</div>';
            return;
        }

        const payload = {
            area_id: Number(formData.get('area_id')),
            therapist_id: Number(formData.get('therapist_id')),
            booking_date: formData.get('booking_date'),
            booking_time: formData.get('booking_time'),
            customer_name: formData.get('customer_name'),
            customer_phone: formData.get('customer_phone'),
            customer_address: formData.get('customer_address'),
            notes: formData.get('notes'),
            service_ids: serviceIds,
            addon_ids: addonIds,
            payment_method: paymentMethod,
        };

        const submitBtn = bookingForm.querySelector('[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Processing…'; }

        try {
            // Refresh CSRF token before submitting (ensures guest sessions have a valid token)
            try {
                const csrfRes = await fetch(apiUrl('api/csrf'), { credentials: 'same-origin' });
                const csrfData = await csrfRes.json().catch(() => ({}));
                if (csrfData.csrf_token) state.csrfToken = csrfData.csrf_token;
            } catch { /* ignore, proceed with existing token */ }

            const bookingRes = await apiFetch('api/bookings', { method: 'POST', body: JSON.stringify(payload) });
            if (paymentMethod === 'credit_card') {
                if (!isCreditCardEnabled()) {
                    result.innerHTML = `
                        <div class="alert alert-warning" style="white-space: pre-line;">
                            <strong>Booking ${bookingRes.data.booking_code} created.</strong><br>
                            Credit Card is currently unavailable.<br>
                            Please use Bank Transfer details below:<br><br>
                            ${bankTransferDetails}
                        </div>
                    `;
                    result.scrollIntoView({ behavior: 'smooth' });
                    return;
                }

                const paymentRes = await apiFetch('api/payments/create-intent', {
                    method: 'POST',
                    body: JSON.stringify({
                        booking_id: bookingRes.data.booking_id,
                        customer_phone: payload.customer_phone,
                    }),
                });

                result.innerHTML = `
                    <div class="alert alert-success">
                        <strong>Booking ${bookingRes.data.booking_code} confirmed!</strong><br>
                        Credit Card selected. Stripe payment intent is ready${paymentRes.data.client_secret ? '.' : ', but no client secret was returned.'}
                    </div>
                `;
            } else {
                result.innerHTML = `
                    <div class="alert alert-success" style="white-space: pre-line;">
                        <strong>Booking ${bookingRes.data.booking_code} created.</strong><br>
                        Please complete payment by bank transfer using the details below, then send payment proof to WhatsApp support.<br><br>
                        ${bankTransferDetails}
                    </div>
                `;
            }

            // Show booking confirmation modal
            showBookingConfirmModal(bookingRes.data.booking_code, bookingRes.data.total_amount, paymentMethod);
            clearBookingFormAndState();
        } catch (err) {
            result.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Confirm & Pay'; }
        }
    });
}

function attachTherapistPanelHandlers() {
    const toggleBtn = document.getElementById('toggleAvailabilityBtn');
    const availabilityResult = document.getElementById('therapistAvailabilityResult');
    const profileForm = document.getElementById('therapistProfileForm');
    const profileResult = document.getElementById('therapistProfileResult');

    if (toggleBtn && availabilityResult) {
        toggleBtn.addEventListener('click', async () => {
            const current = Number(toggleBtn.dataset.active || 0);
            const next = current === 1 ? 0 : 1;

            try {
                const res = await apiFetch('api/therapist/availability', {
                    method: 'POST',
                    body: JSON.stringify({ is_active: next }),
                });
                availabilityResult.innerHTML = '';
                showToast(res.message || 'Availability updated.', 'success');
                renderRoute();
            } catch (err) {
                availabilityResult.innerHTML = '';
                showToast(getErrorMessage(err, 'Failed to update availability.'), 'danger');
            }
        });
    }

    if (profileForm && profileResult) {
        profileForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(profileForm);
            const payload = Object.fromEntries(fd.entries());
            payload.experience_years = Number(payload.experience_years || 0);

            try {
                const res = await apiFetch('api/therapist/profile', { method: 'POST', body: JSON.stringify(payload) });
                profileResult.innerHTML = '';
                showToast(res.message || 'Profile updated.', 'success');
                setTimeout(() => renderRoute(), 300);
            } catch (err) {
                profileResult.innerHTML = '';
                showToast(getErrorMessage(err, 'Failed to update profile.'), 'danger');
            }
        });
    }

    const photoForm = document.getElementById('therapistPhotoForm');
    const photoResult = document.getElementById('therapistPhotoResult');
    const therapistMediaLibraryModalEl = document.getElementById('therapistMediaLibraryModal');
    const therapistMediaLibraryGrid = document.getElementById('therapistMediaLibraryGrid');
    const therapistMediaLibraryModal = therapistMediaLibraryModalEl ? new bootstrap.Modal(therapistMediaLibraryModalEl) : null;
    const chooseFromLibraryBtn = document.getElementById('therapistChooseFromLibraryBtn');
    if (photoForm && photoResult) {
        photoForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(photoForm);

            try {
                const response = await fetch(apiUrl('api/therapist/profile-photo'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': state.csrfToken },
                    body: fd,
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Failed to upload photo.');
                }

                photoResult.innerHTML = '';
                showToast(data.message || 'Photo uploaded.', 'success');
                setTimeout(() => renderRoute(), 300);
            } catch (err) {
                photoResult.innerHTML = '';
                showToast(getErrorMessage(err, 'Failed to upload photo.'), 'danger');
            }
        });

        chooseFromLibraryBtn?.addEventListener('click', async () => {
            if (!therapistMediaLibraryModal || !therapistMediaLibraryGrid) return;
            therapistMediaLibraryGrid.innerHTML = '<div class="panel-card text-center text-muted small py-4">Loading files...</div>';
            therapistMediaLibraryModal.show();
            try {
                const res = await apiFetch('api/therapist/files');
                const files = res?.data?.files || [];
                if (!files.length) {
                    therapistMediaLibraryGrid.innerHTML = '<div class="panel-card text-center text-muted small py-5">No files available.</div>';
                    return;
                }
                therapistMediaLibraryGrid.innerHTML = files.map((f) => {
                    const filePath = f.filename.includes('/') ? f.filename : `uploads/files/${f.filename}`;
                    const url = apiUrl(filePath);
                    const safeOriginal = String(f.original_name || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    return `<button type="button" class="admin-file-card js-therapist-media-item text-start" data-url="${url}" style="padding:0;border:1px solid rgba(0,0,0,.08)">
                        <div class="admin-file-thumb" style="background-image:url('${url}')"></div>
                        <div class="admin-file-meta">
                            <div class="admin-file-name" title="${safeOriginal}">${safeOriginal || 'image'}</div>
                            <div class="small text-muted">Use as profile photo</div>
                        </div>
                    </button>`;
                }).join('');

                therapistMediaLibraryGrid.querySelectorAll('.js-therapist-media-item').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const fullUrl = String(btn.dataset.url || '');
                        let photoUrl = fullUrl;
                        try {
                            const parsed = new URL(fullUrl, window.location.origin);
                            const basePath = appBasePath().replace(/\/+$/, '');
                            let path = parsed.pathname;
                            if (basePath && path.startsWith(basePath + '/')) {
                                path = path.slice(basePath.length);
                            }
                            photoUrl = path.startsWith('/') ? path : '/' + path;
                        } catch {
                            // keep fallback value
                        }

                        try {
                            const data = await apiFetch('api/therapist/profile-photo/select', {
                                method: 'POST',
                                body: JSON.stringify({ photo_url: photoUrl }),
                            });
                            therapistMediaLibraryModal.hide();
                            showToast(data.message || 'Profile photo updated.', 'success');
                            setTimeout(() => renderRoute(), 300);
                        } catch (err) {
                            showToast(getErrorMessage(err, 'Failed to set profile photo.'), 'danger');
                        }
                    });
                });
            } catch (err) {
                therapistMediaLibraryGrid.innerHTML = `<div class="panel-card text-center text-danger small py-4">${getErrorMessage(err, 'Failed to load files.')}</div>`;
            }
        });
    }

    // ── Booking Detail Modal (therapist — read-only) ──
    document.querySelectorAll('.js-view-booking-therapist').forEach((btn) => {
        btn.addEventListener('click', () => {
            const raw = btn.getAttribute('data-booking') || '{}';
            const b = JSON.parse(raw.replaceAll('&#39;', "'"));
            const bodyEl = document.getElementById('therapistBookingDetailBody');
            if (!bodyEl) return;
            bodyEl.innerHTML = buildBookingDetailHtml(b);
            new bootstrap.Modal(document.getElementById('therapistBookingDetailModal')).show();
        });
    });
}

function buildBookingDetailHtml(b) {
    const services = (b.order_details || '-').replace(/\n/g, '<br>');
    return `
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="small text-muted">Booking Code</div>
                <div class="fw-bold">${b.booking_code}</div>
            </div>
            <div class="col-sm-6">
                <div class="small text-muted">Date &amp; Time</div>
                <div>${b.booking_date} &nbsp; ${b.booking_time}</div>
            </div>
            <div class="col-sm-6">
                <div class="small text-muted">Customer</div>
                <div class="fw-semibold">${b.customer_name || '-'}</div>
                <div class="small">${b.customer_phone || ''}</div>
                <div class="small text-muted">${b.customer_address || ''}</div>
            </div>
            <div class="col-sm-6">
                <div class="small text-muted">Therapist / Area</div>
                <div>${b.therapist_name || '-'}</div>
                <div class="small text-muted">${b.area_name || ''}</div>
            </div>
            <div class="col-12">
                <div class="small text-muted">Services Booked</div>
                <div>${services}</div>
            </div>
            <div class="col-sm-4">
                <div class="small text-muted">Total</div>
                <div class="fw-bold">${money(b.total_amount)}</div>
            </div>
            <div class="col-sm-4">
                <div class="small text-muted">Payment</div>
                <div>${bookingStatusBadge(b.payment_status)} <span class="small text-muted">${b.payment_method || ''}</span></div>
            </div>
            <div class="col-sm-4">
                <div class="small text-muted">Status</div>
                <div>${bookingStatusBadge(b.booking_status)}</div>
            </div>
            ${b.notes ? `<div class="col-12"><div class="small text-muted">Notes</div><div class="small">${b.notes}</div></div>` : ''}
        </div>
    `;
}

function attachAdminPanelHandlers() {
    const therapistForm = document.getElementById('adminTherapistForm');
    const therapistResult = document.getElementById('adminTherapistResult');
    const therapistModalEl = document.getElementById('adminTherapistModal');
    const therapistModalTitle = document.getElementById('adminTherapistModalTitle');
    const therapistModalSubtitle = document.getElementById('adminTherapistModalSubtitle');
    const newTherapistBtn = document.getElementById('adminNewTherapistBtn');
    const serviceForm = document.getElementById('adminServiceForm');
    const serviceResult = document.getElementById('adminServiceResult');
    const serviceModalEl = document.getElementById('adminServiceModal');
    const serviceModalTitle = document.getElementById('adminServiceModalTitle');
    const serviceModalSubtitle = document.getElementById('adminServiceModalSubtitle');
    const newServiceBtn = document.getElementById('adminNewServiceBtn');
    const areaForm = document.getElementById('adminAreaForm');
    const areaResult = document.getElementById('adminAreaResult');
    const areaModalEl = document.getElementById('adminAreaModal');
    const areaModalTitle = document.getElementById('adminAreaModalTitle');
    const areaModalSubtitle = document.getElementById('adminAreaModalSubtitle');
    const newAreaBtn = document.getElementById('adminNewAreaBtn');

    const mediaLibraryModalEl = document.getElementById('mediaLibraryModal');
    const mediaLibraryGrid = document.getElementById('mediaLibraryGrid');
    const mediaLibraryModal = mediaLibraryModalEl ? new bootstrap.Modal(mediaLibraryModalEl) : null;
    let mediaLibraryOnSelect = null;

    const toStoredMediaUrl = (rawUrl) => {
        const url = String(rawUrl || '').trim();
        if (!url) return '';
        if (/^(data:|blob:)/i.test(url)) {
            return url;
        }

        try {
            const parsed = new URL(url, window.location.origin);
            if (parsed.origin === window.location.origin) {
                const basePath = appBasePath().replace(/\/+$/, '');
                let path = parsed.pathname;
                if (basePath && path.startsWith(basePath + '/')) {
                    path = path.slice(basePath.length);
                }
                return path.startsWith('/') ? path : '/' + path;
            }
            return url;
        } catch {
            // Fall through for plain relative paths.
        }

        return url.startsWith('/') ? url : '/' + url.replace(/^\/+/, '');
    };

    const openMediaLibrary = async (onSelect) => {
        if (!mediaLibraryModal || !mediaLibraryGrid) return;
        mediaLibraryOnSelect = typeof onSelect === 'function' ? onSelect : null;
        mediaLibraryGrid.innerHTML = '<div class="panel-card text-center text-muted small py-4">Loading files...</div>';
        mediaLibraryModal.show();

        try {
            const res = await apiFetch('api/admin/files');
            const files = res?.data?.files || [];
            if (!files.length) {
                mediaLibraryGrid.innerHTML = '<div class="panel-card text-center text-muted small py-5">No files uploaded yet.</div>';
                return;
            }
            mediaLibraryGrid.innerHTML = files.map((f) => {
                const filePath = f.filename.includes('/') ? f.filename : `uploads/files/${f.filename}`;
                const url = apiUrl(filePath);
                const safeOriginal = String(f.original_name || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                return `<button type="button" class="admin-file-card js-media-library-item text-start" data-url="${url}" style="padding:0;border:1px solid rgba(0,0,0,.08)">
                    <div class="admin-file-thumb" style="background-image:url('${url}')"></div>
                    <div class="admin-file-meta">
                        <div class="admin-file-name" title="${safeOriginal}">${safeOriginal || 'image'}</div>
                        <div class="small text-muted">Use this image</div>
                    </div>
                </button>`;
            }).join('');

            mediaLibraryGrid.querySelectorAll('.js-media-library-item').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const selectedUrl = toStoredMediaUrl(btn.dataset.url || '');
                    if (mediaLibraryOnSelect && selectedUrl) {
                        mediaLibraryOnSelect(selectedUrl);
                    }
                    mediaLibraryModal.hide();
                });
            });
        } catch (err) {
            mediaLibraryGrid.innerHTML = `<div class="panel-card text-center text-danger small py-4">${getErrorMessage(err, 'Failed to load files.')}</div>`;
        }
    };

    mediaLibraryModalEl?.addEventListener('hidden.bs.modal', () => {
        mediaLibraryOnSelect = null;
    });

    if (therapistForm && therapistResult) {
        const therapistModal = therapistModalEl ? new bootstrap.Modal(therapistModalEl) : null;
        const therapistPhotoInput = document.getElementById('adminTherapistPhotoInput');
        const therapistPhotoUrlInput = document.getElementById('adminTherapistPhotoUrl');
        const therapistPhotoPreview = document.getElementById('adminTherapistPhotoPreview');
        const therapistPhotoResult = document.getElementById('adminTherapistPhotoResult');
        const therapistChooseBtn = document.getElementById('adminTherapistChooseFromLibraryBtn');
        const therapistClearPhotoBtn = document.getElementById('adminTherapistClearPhotoBtn');

        const setTherapistPhotoPreview = (storedUrl) => {
            if (!therapistPhotoPreview) return;
            const finalUrl = therapistPhotoUrl(storedUrl || '', '56x56');
            therapistPhotoPreview.style.backgroundImage = `url('${finalUrl}')`;
            const badgeEl = document.getElementById('adminTherapistPhotoBadge');
            if (badgeEl) {
                const isFromLibrary = storedUrl && (storedUrl.startsWith('/uploads/') || storedUrl.includes('uploads/'));
                badgeEl.classList.toggle('d-none', !isFromLibrary);
                if (isFromLibrary) {
                    const badge = badgeEl.querySelector('.badge');
                    if (badge) {
                        badge.classList.remove('bg-danger-subtle', 'text-danger-emphasis');
                        badge.classList.add('bg-info-subtle', 'text-info-emphasis');
                        badge.textContent = 'From Library';
                    }
                }
            }
            therapistPhotoPreview.onerror = () => {
                const badgeEl = document.getElementById('adminTherapistPhotoBadge');
                if (badgeEl && !badgeEl.classList.contains('d-none')) {
                    const badge = badgeEl.querySelector('.badge');
                    if (badge) {
                        badge.classList.remove('bg-info-subtle', 'text-info-emphasis');
                        badge.classList.add('bg-danger-subtle', 'text-danger-emphasis');
                        badge.textContent = 'Missing from Library';
                    }
                }
            };
        };

        const resetTherapistForm = () => {
            therapistForm.reset();
            therapistForm.querySelector('[name="id"]').value = '';
            therapistForm.querySelector('[name="rating"]').value = '5';
            therapistForm.querySelector('[name="is_active"]').checked = true;
            if (therapistPhotoInput) therapistPhotoInput.value = '';
            if (therapistPhotoUrlInput) therapistPhotoUrlInput.value = '';
            if (therapistPhotoResult) therapistPhotoResult.innerHTML = '';
            setTherapistPhotoPreview('');
            therapistResult.innerHTML = '';
        };

        const populateTherapistForm = (data = {}) => {
            therapistForm.querySelector('[name="id"]').value = data.id || '';
            therapistForm.querySelector('[name="name"]').value = data.name || '';
            therapistForm.querySelector('[name="email"]').value = data.email || '';
            therapistForm.querySelector('[name="phone"]').value = data.phone || '';
            therapistForm.querySelector('[name="specialty"]').value = data.specialty || '';
            therapistForm.querySelector('[name="experience_years"]').value = data.experience_years || 0;
            therapistForm.querySelector('[name="rating"]').value = data.rating || 5;
            therapistForm.querySelector('[name="password"]').value = '';
            therapistForm.querySelector('[name="is_active"]').checked = Number(data.is_active ?? 1) === 1;
            if (therapistPhotoInput) therapistPhotoInput.value = '';
            if (therapistPhotoUrlInput) therapistPhotoUrlInput.value = data.photo_url || '';
            if (therapistPhotoResult) therapistPhotoResult.innerHTML = '';
            setTherapistPhotoPreview(data.photo_url || '');
        };

        therapistChooseBtn?.addEventListener('click', () => {
            openMediaLibrary((selectedUrl) => {
                if (therapistPhotoUrlInput) therapistPhotoUrlInput.value = selectedUrl;
                if (therapistPhotoInput) therapistPhotoInput.value = '';
                setTherapistPhotoPreview(selectedUrl);
                if (therapistPhotoResult) {
                    therapistPhotoResult.innerHTML = '<div class="small text-success">Profile photo selected from File Library.</div>';
                }
            });
        });

        therapistClearPhotoBtn?.addEventListener('click', () => {
            if (therapistPhotoUrlInput) therapistPhotoUrlInput.value = '';
            if (therapistPhotoInput) therapistPhotoInput.value = '';
            setTherapistPhotoPreview('');
            if (therapistPhotoResult) {
                therapistPhotoResult.innerHTML = '<div class="small text-muted">Profile photo cleared. Save therapist to apply.</div>';
            }
        });

        therapistPhotoInput?.addEventListener('change', async () => {
            if (!therapistPhotoInput.files?.length) return;
            const fd = new FormData();
            fd.append('file', therapistPhotoInput.files[0]);
            if (therapistPhotoResult) therapistPhotoResult.innerHTML = '<div class="small text-muted">Uploading photo...</div>';
            try {
                const res = await apiFetch('api/admin/files/upload', {
                    method: 'POST',
                    body: fd,
                    headers: {},
                });
                const photoUrl = String(res?.data?.url || '');
                if (!photoUrl) throw new Error('Invalid upload response.');
                if (therapistPhotoUrlInput) therapistPhotoUrlInput.value = photoUrl;
                setTherapistPhotoPreview(photoUrl);
                if (therapistPhotoResult) therapistPhotoResult.innerHTML = '<div class="small text-success">Photo uploaded and selected.</div>';
            } catch (err) {
                if (therapistPhotoResult) therapistPhotoResult.innerHTML = `<div class="small text-danger">${getErrorMessage(err, 'Failed to upload photo.')}</div>`;
            }
        });

        const openTherapistModal = (data = null) => {
            if (data) {
                therapistModalTitle.textContent = 'Edit Therapist';
                therapistModalSubtitle.textContent = 'Update therapist details and keep the current password unless you set a new one.';
                populateTherapistForm(data);
            } else {
                therapistModalTitle.textContent = 'New Therapist';
                therapistModalSubtitle.textContent = 'Create a therapist profile and account in one flow.';
                resetTherapistForm();
            }

            therapistModal?.show();
        };

        newTherapistBtn?.addEventListener('click', () => openTherapistModal());

        therapistForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(therapistForm);
            const payload = Object.fromEntries(fd.entries());
            payload.id = Number(payload.id || 0);
            payload.experience_years = Number(payload.experience_years || 0);
            payload.rating = Number(payload.rating || 5);
            payload.is_active = therapistForm.querySelector('[name="is_active"]').checked;
            payload.photo_url = String(payload.photo_url || '').trim();

            try {
                const res = await apiFetch('api/admin/therapists/save', { method: 'POST', body: JSON.stringify(payload) });
                showToast(res.message || 'Therapist saved.', 'success');
                setTimeout(() => {
                    therapistModal?.hide();
                    resetTherapistForm();
                    renderRoute();
                }, 300);
            } catch (err) {
                showToast(getErrorMessage(err, 'Failed to save therapist.'), 'danger');
            }
        });

        document.querySelectorAll('.js-edit-therapist').forEach((btn) => {
            btn.addEventListener('click', () => {
                const raw = btn.getAttribute('data-therapist') || '{}';
                const data = JSON.parse(raw.replaceAll('&#39;', "'"));
                openTherapistModal(data);
            });
        });

        therapistModalEl?.addEventListener('hidden.bs.modal', () => {
            resetTherapistForm();
            therapistModalTitle.textContent = 'New Therapist';
            therapistModalSubtitle.textContent = 'Create a therapist profile and account in one flow.';
        });
    }

    if (serviceForm && serviceResult) {
        const serviceModal = serviceModalEl ? new bootstrap.Modal(serviceModalEl) : null;

        const resetServiceForm = () => {
            serviceForm.reset();
            serviceForm.querySelector('[name="id"]').value = '';
            const imageUrlInput = serviceForm.querySelector('[name="image_url"]');
            if (imageUrlInput) imageUrlInput.value = '';
            serviceForm.querySelector('[name="duration_minutes"]').value = '60';
            serviceForm.querySelector('[name="sort_order"]').value = '0';
            serviceForm.querySelector('[name="is_addon"]').checked = false;
            serviceForm.querySelector('[name="is_active"]').checked = true;
            serviceResult.innerHTML = '';
            const previewEl = document.getElementById('adminServiceImagePreview');
            if (previewEl) { previewEl.style.backgroundImage = 'none'; previewEl.dataset.currentUrl = ''; previewEl.dataset.serviceId = ''; }
            const imgResult = document.getElementById('adminServiceImageResult');
            if (imgResult) imgResult.innerHTML = '';
        };

        const populateServiceForm = (data = {}) => {
            serviceForm.querySelector('[name="id"]').value = data.id || '';
            serviceForm.querySelector('[name="name"]').value = data.name || '';
            serviceForm.querySelector('[name="category_id"]').value = data.category_id || '';
            serviceForm.querySelector('[name="description"]').value = data.description || '';
            const imageUrlInput = serviceForm.querySelector('[name="image_url"]');
            if (imageUrlInput) imageUrlInput.value = data.image_url || '';
            serviceForm.querySelector('[name="duration_minutes"]').value = data.duration_minutes || 60;
            serviceForm.querySelector('[name="price"]').value = data.price || '';
            serviceForm.querySelector('[name="sort_order"]').value = data.sort_order || 0;
            serviceForm.querySelector('[name="is_addon"]').checked = Number(data.is_addon) === 1;
            serviceForm.querySelector('[name="is_active"]').checked = Number(data.is_active ?? 1) === 1;
            // Show current image preview
            const previewEl = document.getElementById('adminServiceImagePreview');
            const imgInput = document.getElementById('adminServiceImageInput');
            if (previewEl) {
                const imgUrl = data.image_url ? apiUrl(data.image_url.replace(/^\//, '')) : '';
                previewEl.style.backgroundImage = imgUrl ? `url('${imgUrl}')` : 'none';
                previewEl.dataset.currentUrl = data.image_url || '';
                previewEl.dataset.serviceId = data.id || '';
            }
            if (imgInput) imgInput.value = '';
        };

        const serviceChooseBtn = document.getElementById('adminServiceChooseFromLibraryBtn');
        serviceChooseBtn?.addEventListener('click', () => {
            openMediaLibrary((selectedUrl) => {
                const imageUrlInput = serviceForm.querySelector('[name="image_url"]');
                const previewEl = document.getElementById('adminServiceImagePreview');
                const imgInput = document.getElementById('adminServiceImageInput');
                if (imageUrlInput) imageUrlInput.value = selectedUrl;
                if (previewEl) {
                    const finalUrl = apiUrl(selectedUrl.replace(/^\//, ''));
                    previewEl.style.backgroundImage = `url('${finalUrl}')`;
                    previewEl.dataset.currentUrl = selectedUrl;
                }
                if (imgInput) imgInput.value = '';
                const imgResult = document.getElementById('adminServiceImageResult');
                if (imgResult) imgResult.innerHTML = '<div class="small text-success">Image selected from File Library.</div>';
            });
        });

        const openServiceModal = (data = null) => {
            if (data) {
                serviceModalTitle.textContent = 'Edit Service';
                serviceModalSubtitle.textContent = 'Update pricing, category, and visibility for an existing service.';
                populateServiceForm(data);
            } else {
                serviceModalTitle.textContent = 'New Service';
                serviceModalSubtitle.textContent = 'Add or update a service without leaving the service list.';
                resetServiceForm();
            }

            serviceModal?.show();
        };

        newServiceBtn?.addEventListener('click', () => openServiceModal());

        serviceForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(serviceForm);
            const payload = Object.fromEntries(fd.entries());
            payload.id = Number(payload.id || 0);
            payload.category_id = Number(payload.category_id || 0);
            payload.duration_minutes = Number(payload.duration_minutes || 60);
            payload.price = Number(payload.price || 0);
            payload.sort_order = Number(payload.sort_order || 0);
            payload.is_addon = serviceForm.querySelector('[name="is_addon"]').checked;
            payload.is_active = serviceForm.querySelector('[name="is_active"]').checked;

            try {
                const res = await apiFetch('api/admin/services/save', { method: 'POST', body: JSON.stringify(payload) });
                // After saving, upload image if one was selected
                const imgInput = document.getElementById('adminServiceImageInput');
                const savedId = Number(res.data?.id || payload.id || 0);
                if (imgInput?.files?.length && savedId > 0) {
                    const imgFd = new FormData();
                    imgFd.append('image', imgInput.files[0]);
                    imgFd.append('service_id', String(savedId));
                    try {
                        await apiFetch(`api/admin/services/${savedId}/image`, { method: 'POST', body: imgFd, headers: {} });
                    } catch (imgErr) {
                        showToast('Service saved but image upload failed: ' + getErrorMessage(imgErr, ''), 'warning');
                    }
                }
                showToast(res.message || 'Service saved.', 'success');
                setTimeout(() => {
                    serviceModal?.hide();
                    resetServiceForm();
                    renderRoute();
                }, 300);
            } catch (err) {
                showToast(getErrorMessage(err, 'Failed to save service.'), 'danger');
            }
        });

        document.querySelectorAll('.js-edit-service').forEach((btn) => {
            btn.addEventListener('click', () => {
                const raw = btn.getAttribute('data-service') || '{}';
                const data = JSON.parse(raw.replaceAll('&#39;', "'"));
                openServiceModal(data);
            });
        });

        serviceModalEl?.addEventListener('hidden.bs.modal', () => {
            resetServiceForm();
            serviceModalTitle.textContent = 'New Service';
            serviceModalSubtitle.textContent = 'Add or update a service without leaving the service list.';
        });
    }

    if (areaForm && areaResult) {
        const areaModal = areaModalEl ? new bootstrap.Modal(areaModalEl) : null;

        const resetAreaForm = () => {
            areaForm.reset();
            areaForm.querySelector('[name="id"]').value = '';
            areaForm.querySelector('[name="coverage_group"]').value = 'A';
            areaForm.querySelector('[name="is_active"]').checked = true;
            areaResult.innerHTML = '';
        };

        const populateAreaForm = (data = {}) => {
            areaForm.querySelector('[name="id"]').value = data.id || '';
            areaForm.querySelector('[name="name"]').value = data.name || '';
            areaForm.querySelector('[name="coverage_group"]').value = data.coverage_group || 'A';
            areaForm.querySelector('[name="is_active"]').checked = Number(data.is_active ?? 1) === 1;
        };

        const openAreaModal = (data = null) => {
            if (data) {
                areaModalTitle.textContent = 'Edit Coverage Area';
                areaModalSubtitle.textContent = 'Update area naming, group assignment, and active status.';
                populateAreaForm(data);
            } else {
                areaModalTitle.textContent = 'New Coverage Area';
                areaModalSubtitle.textContent = 'Create or update an area and set its coverage group.';
                resetAreaForm();
            }

            areaModal?.show();
        };

        newAreaBtn?.addEventListener('click', () => openAreaModal());

        areaForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(areaForm);
            const payload = Object.fromEntries(fd.entries());
            payload.id = Number(payload.id || 0);
            payload.is_active = areaForm.querySelector('[name="is_active"]').checked;

            try {
                const res = await apiFetch('api/admin/areas/save', { method: 'POST', body: JSON.stringify(payload) });
                showToast(res.message || 'Coverage area saved.', 'success');
                setTimeout(() => {
                    areaModal?.hide();
                    resetAreaForm();
                    renderRoute();
                }, 300);
            } catch (err) {
                showToast(getErrorMessage(err, 'Failed to save coverage area.'), 'danger');
            }
        });

        document.querySelectorAll('.js-edit-area').forEach((btn) => {
            btn.addEventListener('click', () => {
                const raw = btn.getAttribute('data-area') || '{}';
                const data = JSON.parse(raw.replaceAll('&#39;', "'"));
                openAreaModal(data);
            });
        });

        areaModalEl?.addEventListener('hidden.bs.modal', () => {
            resetAreaForm();
            areaModalTitle.textContent = 'New Coverage Area';
            areaModalSubtitle.textContent = 'Create or update an area and set its coverage group.';
        });
    }

    const settingsForm = document.getElementById('adminSettingsForm');
    const settingsResult = document.getElementById('adminSettingsResult');
    if (settingsForm && settingsResult) {
        const settingsImageFieldPattern = /^(site_logo_image|hero_image_desktop|hero_image_mobile|hp2_faq_image|gallery_image_\d+)$/;
        settingsForm.querySelectorAll('input[name]').forEach((input) => {
            const fieldName = String(input.getAttribute('name') || '');
            if (!settingsImageFieldPattern.test(fieldName)) return;
            if (input.dataset.mediaChooserBound === '1') return;
            input.dataset.mediaChooserBound = '1';

            const chooserBtn = document.createElement('button');
            chooserBtn.type = 'button';
            chooserBtn.className = 'btn btn-outline-secondary btn-sm mt-1';
            chooserBtn.textContent = 'Use from File Library';
            chooserBtn.addEventListener('click', () => {
                openMediaLibrary((selectedUrl) => {
                    input.value = selectedUrl;
                    showToast('Image selected for ' + fieldName.replace(/_/g, ' ') + '.', 'success');
                });
            });
            input.insertAdjacentElement('afterend', chooserBtn);
        });

        settingsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(settingsForm);
            const payload = Object.fromEntries(fd.entries());
            try {
                const res = await apiFetch('api/admin/settings', { method: 'POST', body: JSON.stringify(payload) });
                settingsResult.innerHTML = '';
                showToast(res.message || 'Settings updated.', 'success');
            } catch (err) {
                settingsResult.innerHTML = '';
                showToast(getErrorMessage(err, 'Failed to save settings.'), 'danger');
            }
        });
    }

    // ── File Manager ──────────────────────────────────────────────────
    const fileUploadForm = document.getElementById('adminFileUploadForm');
    const fileUploadResult = document.getElementById('adminFileUploadResult');
    const fileUploadBtn = document.getElementById('adminFileUploadBtn');
    if (fileUploadForm) {
        fileUploadForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fileInput = fileUploadForm.querySelector('[name="file"]');
            if (!fileInput?.files?.length) {
                showToast('Please select a file to upload.', 'danger');
                return;
            }
            const fd = new FormData();
            fd.append('file', fileInput.files[0]);
            if (fileUploadBtn) { fileUploadBtn.disabled = true; fileUploadBtn.textContent = 'Uploading…'; }
            if (fileUploadResult) fileUploadResult.innerHTML = '';
            try {
                const res = await apiFetch('api/admin/files/upload', {
                    method: 'POST',
                    body: fd,
                    headers: {},
                });
                showToast(res.message || 'File uploaded.', 'success');
                setTimeout(() => renderRoute(), 300);
            } catch (err) {
                showToast(getErrorMessage(err, 'Upload failed.'), 'danger');
            } finally {
                if (fileUploadBtn) { fileUploadBtn.disabled = false; fileUploadBtn.textContent = fileUploadBtn.dataset.submitLabel || 'Upload'; }
            }
        });
    }

    // ── File lightbox ─────────────────────────────────────────────────
    const filePreviewModalEl = document.getElementById('filePreviewModal');
    const filePreviewImg = document.getElementById('filePreviewImg');
    const filePreviewName = document.getElementById('filePreviewName');
    const filePreviewCopyBtn = document.getElementById('filePreviewCopyBtn');
    const filePreviewModal = filePreviewModalEl ? new bootstrap.Modal(filePreviewModalEl) : null;

    if (filePreviewModalEl && filePreviewCopyBtn) {
        filePreviewCopyBtn.addEventListener('click', async () => {
            const url = filePreviewImg?.src || '';
            if (!url) return;
            try {
                await navigator.clipboard.writeText(url);
                const orig = filePreviewCopyBtn.textContent;
                filePreviewCopyBtn.textContent = 'Copied!';
                setTimeout(() => { filePreviewCopyBtn.textContent = orig; }, 1800);
            } catch {
                showToast('Could not copy URL.', 'danger');
            }
        });
    }

    document.querySelectorAll('.js-view-file').forEach((btn) => {
        btn.addEventListener('click', () => {
            const url = String(btn.dataset.url || '');
            const name = String(btn.dataset.name || '');
            if (!url || !filePreviewModal) return;
            if (filePreviewImg) filePreviewImg.src = url;
            if (filePreviewName) filePreviewName.textContent = name;
            filePreviewModal.show();
        });
    });

    document.querySelectorAll('.js-copy-url').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const url = String(btn.dataset.url || '');
            if (!url) return;
            try {
                await navigator.clipboard.writeText(url);
                const orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = orig; }, 1800);
            } catch {
                showToast('Could not copy URL.', 'danger');
            }
        });
    });

    document.querySelectorAll('.js-delete-file').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const fileId = Number(btn.dataset.fileId || 0);
            const filename = String(btn.dataset.filename || 'this file');
            if (!confirm(`Delete "${filename}"? This cannot be undone.`)) return;
            btn.disabled = true;
            try {
                const res = await apiFetch(`api/admin/files/${fileId}/delete`, { method: 'POST' });
                showToast(res.message || 'File deleted.', 'success');
                setTimeout(() => renderRoute(), 300);
            } catch (err) {
                showToast(getErrorMessage(err, 'Failed to delete file.'), 'danger');
                btn.disabled = false;
            }
        });
    });

    const paymentMethodForm = document.getElementById('adminPaymentMethodForm');
    const paymentMethodResult = document.getElementById('adminPaymentMethodResult');
    if (paymentMethodForm && paymentMethodResult) {
        paymentMethodForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(paymentMethodForm);
            const payload = {
                bank_transfer_details: String(fd.get('bank_transfer_details') || ''),
                payment_bank_transfer_enabled: paymentMethodForm.querySelector('[name="payment_bank_transfer_enabled"]')?.checked ? '1' : '0',
                payment_credit_card_enabled: paymentMethodForm.querySelector('[name="payment_credit_card_enabled"]')?.checked ? '1' : '0',
            };

            try {
                const res = await apiFetch('api/admin/settings', { method: 'POST', body: JSON.stringify(payload) });
                paymentMethodResult.innerHTML = '';
                showToast(res.message || 'Payment methods updated.', 'success');
            } catch (err) {
                paymentMethodResult.innerHTML = '';
                showToast(getErrorMessage(err, 'Failed to update payment methods.'), 'danger');
            }
        });
    }

    document.querySelectorAll('.js-payment-status').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const paymentId = Number(btn.dataset.paymentId || 0);
            const targetStatus = String(btn.dataset.targetStatus || '');
            if (!paymentId || !targetStatus) return;

            btn.disabled = true;
            try {
                const res = await apiFetch('api/admin/payments/confirm', {
                    method: 'POST',
                    body: JSON.stringify({ payment_id: paymentId, target_status: targetStatus }),
                });
                const resultEl = document.getElementById('adminPaymentActionResult');
                if (resultEl) resultEl.innerHTML = '';
                showToast(res.message || 'Payment status updated.', 'success');
                setTimeout(() => renderRoute(), 300);
            } catch (err) {
                const resultEl = document.getElementById('adminPaymentActionResult');
                if (resultEl) resultEl.innerHTML = '';
                showToast(getErrorMessage(err, 'Failed to update payment status.'), 'danger');
                btn.disabled = false;
            }
        });
    });

    // ── Coverage Area Assignment (group-based, auto-save) ──
        // ── Booking Detail Modal (admin) ──
        document.querySelectorAll('.js-view-booking').forEach((btn) => {
            btn.addEventListener('click', () => {
                const raw = btn.getAttribute('data-booking') || '{}';
                const b = JSON.parse(raw.replaceAll('&#39;', "'"));
                const bodyEl = document.getElementById('bookingDetailBody');
                const footerEl = document.getElementById('bookingDetailFooter');
                if (!bodyEl || !footerEl) return;

                bodyEl.innerHTML = buildBookingDetailHtml(b);

                const isCancelled = b.booking_status === 'cancelled';
                const isBank = b.payment_method === 'bank_transfer';
                const isPaid = b.payment_status === 'paid';
                const paymentId = Number(b.payment_id || 0);

                let actions = '';
                if (!isCancelled) {
                    if (isBank && paymentId) {
                        if (isPaid) {
                            actions += `<button class="btn btn-outline-warning btn-sm js-modal-payment" data-payment-id="${paymentId}" data-target-status="pending">Mark Unpaid</button>`;
                        } else {
                            actions += `<button class="btn btn-success btn-sm js-modal-payment" data-payment-id="${paymentId}" data-target-status="paid">Mark Paid</button>`;
                        }
                    }
                    actions += `<button class="btn btn-outline-danger btn-sm js-modal-cancel" data-booking-id="${b.id}">Cancel Booking</button>`;
                }
                actions += `<button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>`;
                footerEl.innerHTML = actions;

                // Wire action buttons
                footerEl.querySelectorAll('.js-modal-payment').forEach((ab) => {
                    ab.addEventListener('click', async () => {
                        ab.disabled = true;
                        try {
                            const res = await apiFetch('api/admin/payments/confirm', {
                                method: 'POST',
                                body: JSON.stringify({ payment_id: Number(ab.dataset.paymentId), target_status: ab.dataset.targetStatus }),
                            });
                            showToast(res.message || 'Payment status updated.', 'success');
                            setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('bookingDetailModal'))?.hide(); renderRoute(); }, 800);
                        } catch (err) {
                            showToast(getErrorMessage(err, 'Failed to update payment status.'), 'danger');
                            ab.disabled = false;
                        }
                    });
                });
                footerEl.querySelectorAll('.js-modal-cancel').forEach((ab) => {
                    ab.addEventListener('click', async () => {
                        if (!confirm('Cancel this booking? If paid by bank transfer a manual refund is required.')) return;
                        ab.disabled = true;
                        try {
                            const res = await apiFetch('api/admin/bookings/cancel', {
                                method: 'POST',
                                body: JSON.stringify({ booking_id: Number(ab.dataset.bookingId) }),
                            });
                            showToast(res.message || 'Booking cancelled.', 'warning');
                            setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('bookingDetailModal'))?.hide(); renderRoute(); }, 800);
                        } catch (err) {
                            showToast(getErrorMessage(err, 'Failed to cancel booking.'), 'danger');
                            ab.disabled = false;
                        }
                    });
                });

                new bootstrap.Modal(document.getElementById('bookingDetailModal')).show();
            });
        });

    const areaTherapistSel = document.getElementById('areaTherapistSelect');
    const areaGroupSel     = document.getElementById('areaGroupSelect');
    const areasResult      = document.getElementById('adminAreasResult');

    async function saveTherapistGroup(therapistId, groupValue) {
        const groups = groupValue === 'AB' ? ['A', 'B'] : (groupValue === 'A' ? ['A'] : (groupValue === 'B' ? ['B'] : []));
        areasResult.innerHTML = '<span class="text-muted">Saving…</span>';
        try {
            const res = await apiFetch('api/admin/therapists/save-areas', {
                method: 'POST',
                body: JSON.stringify({ therapist_id: Number(therapistId), groups }),
            });
            areasResult.innerHTML = '';
            showToast(res.message || 'Coverage assignment saved.', 'success');
            // Update data-group on the option so re-selecting reflects new value
            const opt = areaTherapistSel.options[areaTherapistSel.selectedIndex];
            if (opt) opt.dataset.group = groupValue;
        } catch (err) {
            areasResult.innerHTML = '';
            showToast(getErrorMessage(err, 'Failed to save coverage assignment.'), 'danger');
        }
    }

    if (areaTherapistSel && areaGroupSel) {
        // Selecting a therapist pre-loads their current group and enables the group dropdown
        areaTherapistSel.addEventListener('change', () => {
            const opt = areaTherapistSel.options[areaTherapistSel.selectedIndex];
            const tid = opt?.value;
            if (!tid) {
                areaGroupSel.value = 'none';
                areaGroupSel.disabled = true;
                if (areasResult) areasResult.innerHTML = '';
                return;
            }
            areaGroupSel.value = opt.dataset.group || 'none';
            areaGroupSel.disabled = false;
            if (areasResult) areasResult.innerHTML = '';
        });

        // Changing the group auto-saves immediately
        areaGroupSel.addEventListener('change', () => {
            const tid = areaTherapistSel.value;
            if (!tid) return;
            saveTherapistGroup(tid, areaGroupSel.value);
        });
    }
}

function attachDashboardSidebarHandlers() {
    const isMobileSidebar = () => window.matchMedia('(max-width: 991px)').matches;
    const iconMap = {
        'admin-overview': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="11" width="7" height="10"/><rect x="3" y="13" width="7" height="8"/></svg>',
        'admin-bookings': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'admin-payments': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/></svg>',
        'admin-payment-methods': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M7 12h10"/><path d="M7 15h6"/></svg>',
        'admin-audit': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>',
        'admin-therapists': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-3.3 4.3-5 8-5s6.4 1.7 8 5"/></svg>',
        'admin-services': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.2 4.8L19 9l-4.8 2.2L12 16l-2.2-4.8L5 9l4.8-2.2L12 2z"/><path d="M5 18h14"/></svg>',
        'admin-areas': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s7-5.5 7-12a7 7 0 1 0-14 0c0 6.5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>',
        'admin-settings': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1 1 0 0 0 .2 1.1l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1 1 0 0 0-1.1-.2 1 1 0 0 0-.6.9V20a2 2 0 1 1-4 0v-.2a1 1 0 0 0-.6-.9 1 1 0 0 0-1.1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1 1 0 0 0 .2-1.1 1 1 0 0 0-.9-.6H4a2 2 0 1 1 0-4h.2a1 1 0 0 0 .9-.6 1 1 0 0 0-.2-1.1l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1 1 0 0 0 1.1.2 1 1 0 0 0 .6-.9V4a2 2 0 1 1 4 0v.2a1 1 0 0 0 .6.9 1 1 0 0 0 1.1-.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1 1 0 0 0-.2 1.1 1 1 0 0 0 .9.6H20a2 2 0 1 1 0 4h-.2a1 1 0 0 0-.9.6z"/></svg>',
        'admin-files': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'therapist-overview': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v11h14V10"/></svg>',
        'therapist-bookings': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'therapist-profile': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-3.3 4.3-5 8-5s6.4 1.7 8 5"/></svg>',
        'therapist-availability': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        default: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/></svg>',
    };

    const rawHash = location.hash.replace(/^#/, '') || '/home';
    const [basePath, queryStr] = rawHash.split('?');
    const sectionParam = new URLSearchParams(queryStr || '').get('section') || '';
    const normalizedSection = (basePath === '/therapist-panel' && sectionParam === 'therapist-bookings')
        ? 'therapist-overview'
        : sectionParam;

    if (normalizedSection !== sectionParam) {
        history.replaceState(null, '', '#' + basePath + '?section=' + normalizedSection);
    }

    document.querySelectorAll('.dashboard-shell').forEach((shell) => {
        const sidebar = shell.querySelector('.dashboard-sidebar');
        const links = shell.querySelectorAll('.sidebar-link[data-panel-target]');
        const sections = shell.querySelectorAll('.panel-section');

        links.forEach((link) => {
            if (link.dataset.chromeReady === '1') return;

            const target = link.getAttribute('data-panel-target') || 'default';
            const labelText = (link.textContent || '').trim();

            link.textContent = '';

            const iconWrap = document.createElement('span');
            iconWrap.className = 'sidebar-icon';
            iconWrap.innerHTML = iconMap[target] || iconMap.default;

            const label = document.createElement('span');
            label.className = 'sidebar-label';
            label.textContent = labelText;

            link.appendChild(iconWrap);
            link.appendChild(label);
            link.dataset.chromeReady = '1';
        });

        let mobileToggle = sidebar?.querySelector('.sidebar-mobile-toggle');
        if (sidebar && !mobileToggle) {
            mobileToggle = document.createElement('button');
            mobileToggle.type = 'button';
            mobileToggle.className = 'sidebar-mobile-toggle d-lg-none';
            mobileToggle.innerHTML = '<span class="toggle-left"><span class="hamburger" aria-hidden="true"><span></span><span></span><span></span></span><span class="label">Menu</span></span><span class="caret" aria-hidden="true"></span>';
            sidebar.insertBefore(mobileToggle, links[0] || null);
        }

        const syncMobileToggleLabel = () => {
            if (!mobileToggle) return;
            const activeLink = shell.querySelector('.sidebar-link.active');
            const activeText = (activeLink?.textContent || 'Menu').trim();
            const labelEl = mobileToggle.querySelector('.label');
            if (labelEl) labelEl.textContent = activeText;
        };

        const closeMobileMenu = () => {
            if (!sidebar) return;
            sidebar.classList.remove('mobile-menu-open');
        };

        const setMobileMenuState = (open) => {
            if (!sidebar) return;

            if (!isMobileSidebar()) {
                sidebar.classList.remove('mobile-menu-open');
                links.forEach((link) => {
                    link.hidden = false;
                    link.style.display = '';
                });
                if (mobileToggle) mobileToggle.setAttribute('aria-expanded', 'false');
                return;
            }

            sidebar.classList.toggle('mobile-menu-open', open);
            links.forEach((link) => {
                link.hidden = !open;
                link.style.display = open ? 'flex' : 'none';
            });
            if (mobileToggle) mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        if (mobileToggle && !mobileToggle.dataset.bound) {
            mobileToggle.dataset.bound = '1';
            mobileToggle.setAttribute('aria-expanded', 'false');
            mobileToggle.addEventListener('click', () => {
                if (!sidebar || !isMobileSidebar()) return;

                const nextOpen = !sidebar.classList.contains('mobile-menu-open');
                setMobileMenuState(nextOpen);
            });
        }

        // Restore active section from URL hash on render
        if (normalizedSection) {
            const matchingLink = shell.querySelector(`.sidebar-link[data-panel-target="${normalizedSection}"]`);
            if (matchingLink) {
                links.forEach((l) => l.classList.remove('active'));
                matchingLink.classList.add('active');
                sections.forEach((s) => s.classList.toggle('active', s.id === normalizedSection));
            }
        }

        links.forEach((link) => {
            link.addEventListener('click', () => {
                const target = link.getAttribute('data-panel-target');
                if (!target) return;

                links.forEach((l) => l.classList.remove('active'));
                link.classList.add('active');

                sections.forEach((section) => {
                    section.classList.toggle('active', section.id === target);
                });

                syncMobileToggleLabel();
                if (isMobileSidebar()) {
                    setMobileMenuState(false);
                }

                // Sync URL without triggering hashchange / re-render
                history.replaceState(null, '', '#' + basePath + '?section=' + target);
            });
        });

        if (isMobileSidebar()) setMobileMenuState(false);
        else setMobileMenuState(false);

        if (sidebar && !sidebar.dataset.mobileMenuResizeBound) {
            sidebar.dataset.mobileMenuResizeBound = '1';
            window.addEventListener('resize', () => {
                setMobileMenuState(false);
                syncMobileToggleLabel();
            });
        }

        syncMobileToggleLabel();
    });
}

async function renderRoute() {
    const rawHash = location.hash.replace(/^#/, '') || '/home';
    const [path] = rawHash.split('?');

    // Avoid showing stale previous-route markup while async templates are loading.
    view.innerHTML = '<section class="hero-card"><div class="small text-muted">Loading...</div></section>';

    // Full-bleed layout for guest home and admin/therapist workspaces
    const isGuestHome = path === '/home' && !state.user;
    const isWorkspaceRoute = path === '/admin' || path === '/therapist-panel' || (path === '/home' && ['admin', 'therapist'].includes(state.user?.role || ''));
    const useContainer = !(isGuestHome || isWorkspaceRoute);

    view.classList.toggle('container', useContainer);
    view.classList.toggle('py-4', useContainer);
    view.classList.toggle('hp-fullpage', isGuestHome);
    view.classList.toggle('workspace-route', isWorkspaceRoute);

    try {
        if (path === '/home') {
            if (state.user?.role === 'admin') view.innerHTML = await adminPanelTemplate();
            else if (state.user?.role === 'therapist') view.innerHTML = await therapistPanelTemplate();
            else if (state.user?.role === 'customer') view.innerHTML = await customerDashboardTemplate();
            else view.innerHTML = await homeTemplate();
        }
        else if (path === '/about') view.innerHTML = staticTemplate('About', 'A modern luxury spa-at-home reservation platform crafted for Bali.');
        else if (path === '/services') view.innerHTML = await servicesTemplate();
        else if (path === '/therapists') view.innerHTML = await therapistsTemplate();
        else if (path.startsWith('/therapist/')) view.innerHTML = await therapistDetailTemplate(path.split('/')[2]);
        else if (path === '/areas') view.innerHTML = await areasTemplate();
        else if (path === '/booking') view.innerHTML = await bookingTemplate();
        else if (path === '/contact') view.innerHTML = staticTemplate('Contact', 'WhatsApp concierge and 24/7 support placeholder.');
        else if (path === '/auth') view.innerHTML = authTemplate();
        else if (path === '/dashboard') view.innerHTML = await customerDashboardTemplate();
        else if (path === '/therapist-panel') view.innerHTML = await therapistPanelTemplate();
        else if (path === '/admin') view.innerHTML = await adminPanelTemplate();
        else view.innerHTML = staticTemplate('Page Not Found', 'The page you requested does not exist.');

        attachAuthHandlers();
        attachBookingHandlers();
        attachTherapistPanelHandlers();
        attachAdminPanelHandlers();
        attachDashboardSidebarHandlers();
    } catch (err) {
        view.innerHTML = `<section class="hero-card"><div class="alert alert-danger">${err.message}</div></section>`;
    }
}

window.addEventListener('hashchange', renderRoute);

(async function boot() {
    await loadSiteSettings();
    await loadMe();
    attachGlobalHandlers();
    renderRoute();
})();
