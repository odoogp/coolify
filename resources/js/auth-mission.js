/**
 * Auth mission scene: one NASA Artemis III plate, one camera shot per auth page.
 *
 * - First visit in a session: black aperture opens on the scene.
 * - Moving between auth pages: the camera glides from the previous shot.
 * - Signing in: the camera pushes to the rocket and the aperture closes;
 *   the app layout then emerges from the same star veil (bootAppEnter).
 *
 * No dependencies. Respects prefers-reduced-motion.
 */

const SHOT_KEY = 'gc-shot';
const INTRO_KEY = 'gc-intro';
const ENTER_KEY = 'gc-enter';

/** focus = point in the photo (0–1), s = zoom, mf/ms = portrait overrides, side = card side. */
const VIEWS = {
    login: { focus: [0.25, 0.52], mf: [0.26, 0.44], s: 1.1, ms: 1.05, side: 'right' },
    register: { focus: [0.69, 0.42], mf: [0.69, 0.22], s: 1.22, ms: 1.1, side: 'left' },
    forgot: { focus: [0.842, 0.406], s: 2.1, ms: 1.9, side: 'left' },
    reset: { focus: [0.69, 0.66], mf: [0.69, 0.5], s: 1.55, ms: 1.35, side: 'left' },
    twofactor: { focus: [0.245, 0.43], s: 2.3, ms: 1.9, side: 'right' },
    confirm: { focus: [0.25, 0.47], mf: [0.26, 0.4], s: 1.7, ms: 1.5, side: 'right' },
    verify: { focus: [0.5, 0.64], mf: [0.5, 0.56], s: 1.75, ms: 1.6, side: 'right' },
    invitation: { focus: [0.25, 0.52], mf: [0.26, 0.44], s: 1.1, ms: 1.05, side: 'right' },
};

const MARGIN = 22;
const OCCLUDERS = [[0.63, 0.08, 0.76, 0.8], [0.1, 0.29, 0.36, 1], [0.45, 0.58, 0.56, 0.95]];

const ease = {
    io: (k) => (k < 0.5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2),
    outExpo: (k) => (k === 1 ? 1 : 1 - Math.pow(2, -9 * k)),
    inCubic: (k) => k * k * k,
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const store = {
    get(key) {
        try {
            return sessionStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            sessionStorage.setItem(key, value);
        } catch {
            // Private mode: the scene still works, it just forgets the last shot.
        }
    },
    remove(key) {
        try {
            sessionStorage.removeItem(key);
        } catch {
            // ignore
        }
    },
};

/**
 * @param {HTMLElement} shell
 */
function mountMission(shell) {
    if (shell.dataset.missionBound === '1') {
        return;
    }
    shell.dataset.missionBound = '1';

    const scene = VIEWS[shell.dataset.scene] ? shell.dataset.scene : 'login';
    const camera = shell.querySelector('[data-mission-camera]');
    const plate = shell.querySelector('[data-mission-plate]');
    const canvas = shell.querySelector('[data-mission-sky]');
    const card = shell.querySelector('[data-mission-card]');
    const aperture = shell.querySelector('[data-mission-aperture]');
    const mark = shell.querySelector('[data-mission-mark]');
    const message = shell.querySelector('[data-mission-message]');

    if (! camera || ! plate || ! canvas || ! card) {
        return;
    }

    const ctx = canvas.getContext('2d');
    const reduced = reducedMotion();

    // Signed-in flag only matters after a successful POST; landing here means it did not.
    store.remove(ENTER_KEY);

    let vw = 0;
    let vh = 0;
    let W = 0;
    let H = 0;
    let dpr = 1;
    let mobile = false;
    const cam = { tx: 0, ty: 0, s: 1.2 };
    let view = { tx: 0, ty: 0, s: 1.2 };
    let tween = null;
    const ptr = { x: 0, y: 0, tx: 0, ty: 0 };
    let alive = true;

    const sideFor = () => (mobile ? 'bottom' : VIEWS[scene].side);

    function layout() {
        vw = window.innerWidth;
        vh = window.innerHeight;
        mobile = vw < 760;
        W = plate.offsetWidth || Math.max(vw, (vh * 16) / 9);
        H = plate.offsetHeight || (W * 9) / 16;
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.round(vw * dpr);
        canvas.height = Math.round(vh * dpr);
        shell.dataset.side = sideFor();
    }

    function clampCam(c) {
        const minS = Math.max((vw + 2 * MARGIN) / W, (vh + 2 * MARGIN) / H);
        const s = Math.max(c.s, minS);

        return {
            s,
            tx: Math.min(-MARGIN, Math.max(vw - s * W + MARGIN, c.tx)),
            ty: Math.min(-MARGIN, Math.max(vh - s * H + MARGIN, c.ty)),
        };
    }

    function targetFor(name, override = {}) {
        const v = VIEWS[name] || VIEWS.login;
        const f = override.focus || (mobile && v.mf) || v.focus;
        const side = mobile ? 'bottom' : v.side;
        const ax = side === 'right' ? 0.32 : side === 'left' ? 0.68 : 0.5;
        const ay = side === 'bottom' ? 0.2 : 0.5;
        let s = override.s ?? (mobile ? v.ms : v.s);
        if (side === 'bottom') {
            // Portrait: zoom just enough that the subject can rise above the sheet.
            s = Math.max(s, (vh * (1 - ay) + MARGIN) / (H * (1 - f[1])));
        }

        return clampCam({ tx: ax * vw - s * f[0] * W, ty: ay * vh - s * f[1] * H, s });
    }

    function zoomAbout(c, factor) {
        const cx = vw / 2;
        const cy = vh / 2;

        return { s: c.s * factor, tx: cx - (cx - c.tx) * factor, ty: cy - (cy - c.ty) * factor };
    }

    function moveTo(to, duration, easing = ease.io) {
        tween?.resolve();
        if (! duration || reduced) {
            Object.assign(cam, to);
            tween = null;

            return Promise.resolve();
        }

        return new Promise((resolve) => {
            tween = { from: { ...cam }, to, t0: performance.now(), duration, easing, resolve };
        });
    }

    /* Sky: stars, meteors and lunar dust, in photo space, drawn in screen space. */
    const rand = (a, b) => a + Math.random() * (b - a);
    const stars = [];
    while (stars.length < 340) {
        const x = Math.random();
        const y = 0.72 * Math.pow(Math.random(), 1.25);
        if (OCCLUDERS.some((o) => x > o[0] && x < o[2] && y > o[1] && y < o[3])) {
            continue;
        }
        const depth = Math.random();
        stars.push({ x, y, depth, r: 0.35 + depth * 1.05, a: 0.25 + depth * 0.65, ph: Math.random() * 6.28, sp: rand(0.4, 1.6), blink: Math.random() });
    }
    const dust = Array.from({ length: 90 }, () => ({ x: Math.random(), y: rand(0.8, 1), r: rand(0.5, 1.6), a: rand(0.08, 0.3), sp: rand(0.002, 0.007), ph: Math.random() * 6.28 }));
    const meteors = [];
    let nextMeteor = performance.now() + 2600;

    const project = (x, y, depth = 0) => [
        view.tx + view.s * x * W - ptr.x * depth * 10,
        view.ty + view.s * y * H - ptr.y * depth * 6,
    ];

    function drawSky(now) {
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, vw, vh);
        const t = now / 1000;
        const zoom = Math.sqrt(view.s);

        for (const star of stars) {
            const [x, y] = project(star.x, star.y, star.depth);
            if (x < -4 || y < -4 || x > vw + 4 || y > vh + 4) {
                continue;
            }
            let a = reduced ? star.a : star.a * (0.55 + 0.45 * Math.sin(t * star.sp + star.ph));
            let r = star.r * zoom;
            if (! reduced && ((t * 0.09 + star.blink) % 1) < 0.004) {
                a = 1;
                r *= 1.8;
            }
            if (star.depth > 0.85) {
                ctx.fillStyle = `rgba(190,210,255,${a * 0.14})`;
                ctx.beginPath();
                ctx.arc(x, y, r * 3.2, 0, 6.283);
                ctx.fill();
            }
            ctx.fillStyle = `rgba(255,255,255,${a})`;
            ctx.beginPath();
            ctx.arc(x, y, r, 0, 6.283);
            ctx.fill();
        }

        if (reduced) {
            return;
        }

        for (const d of dust) {
            d.x += d.sp / 60;
            if (d.x > 1.02) {
                d.x = -0.02;
            }
            const [x, y] = project(d.x, d.y + Math.sin(t * 0.6 + d.ph) * 0.004, 0.6);
            if (x < -4 || y < -4 || x > vw + 4 || y > vh + 4) {
                continue;
            }
            ctx.fillStyle = `rgba(230,236,255,${d.a})`;
            ctx.beginPath();
            ctx.arc(x, y, d.r * zoom, 0, 6.283);
            ctx.fill();
        }

        if (now > nextMeteor) {
            meteors.push({ x: rand(0.15, 0.95), y: rand(0.02, 0.3), t0: now, life: rand(700, 1100), len: rand(0.06, 0.11) });
            nextMeteor = now + rand(5000, 11000);
        }
        for (let i = meteors.length - 1; i >= 0; i--) {
            const m = meteors[i];
            const k = (now - m.t0) / m.life;
            if (k >= 1) {
                meteors.splice(i, 1);
                continue;
            }
            const hx = m.x - k * m.len * 1.4;
            const hy = m.y + k * m.len * 0.55;
            const [x1, y1] = project(hx, hy, 0.4);
            const [x0, y0] = project(hx + m.len * 0.9, hy - m.len * 0.35, 0.4);
            const gradient = ctx.createLinearGradient(x0, y0, x1, y1);
            gradient.addColorStop(0, 'rgba(255,255,255,0)');
            gradient.addColorStop(1, `rgba(255,255,255,${0.85 * Math.sin(k * Math.PI)})`);
            ctx.strokeStyle = gradient;
            ctx.lineWidth = 1.3;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(x0, y0);
            ctx.lineTo(x1, y1);
            ctx.stroke();
        }
    }

    function frame(now) {
        if (! alive || ! shell.isConnected) {
            alive = false;

            return;
        }
        if (tween) {
            const k = Math.min(1, (now - tween.t0) / tween.duration);
            const e = tween.easing(k);
            cam.tx = tween.from.tx + (tween.to.tx - tween.from.tx) * e;
            cam.ty = tween.from.ty + (tween.to.ty - tween.from.ty) * e;
            cam.s = tween.from.s + (tween.to.s - tween.from.s) * e;
            if (k >= 1) {
                const done = tween.resolve;
                tween = null;
                done();
            }
        }
        let c = { ...cam };
        if (! reduced) {
            ptr.x += (ptr.tx - ptr.x) * 0.045;
            ptr.y += (ptr.ty - ptr.y) * 0.045;
            c = zoomAbout(c, 1 + 0.009 * (0.5 + 0.5 * Math.sin((now / 1000) * ((2 * Math.PI) / 16))));
            c.tx += -ptr.x * 16 + Math.sin(now / 9000) * 6;
            c.ty += -ptr.y * 10 + Math.cos(now / 11000) * 4;
        }
        view = clampCam(c);
        camera.style.transform = `translate3d(${view.tx}px, ${view.ty}px, 0) scale(${view.s})`;
        if (! document.hidden) {
            drawSky(now);
        }
        requestAnimationFrame(frame);
    }

    /* Card choreography */
    const staggered = [
        ...card.querySelectorAll('.auth-card-heading > *'),
        ...card.querySelectorAll('.auth-card-body > *:not(form), .auth-card-body form > *'),
        ...card.querySelectorAll('.auth-card-footer'),
    ];
    staggered.forEach((el, i) => {
        el.setAttribute('data-mission-st', '');
        el.style.setProperty('--i', String(Math.min(i, 14)));
    });

    function showCard() {
        card.classList.remove('is-hidden', 'is-out', 'is-in');
        void card.offsetWidth;
        card.classList.add('is-in');
        if (shell.dataset.authState === 'error') {
            setTimeout(shake, reduced ? 0 : 700);
        }
    }

    function shake() {
        if (reduced) {
            return;
        }
        card.animate([
            { transform: 'translateX(0)' }, { transform: 'translateX(-10px)' }, { transform: 'translateX(8px)' },
            { transform: 'translateX(-5px)' }, { transform: 'translateX(3px)' }, { transform: 'translateX(0)' },
        ], { duration: 460, easing: 'cubic-bezier(.36,.07,.19,.97)' });
    }

    function rememberShot() {
        store.set(SHOT_KEY, JSON.stringify({ scene, t: Date.now() }));
    }

    async function intro(target) {
        aperture.hidden = false;
        aperture.style.setProperty('--mission-r', '0%');
        mark.animate([
            { opacity: 0, transform: 'scale(.9)', filter: 'blur(8px)' },
            { opacity: 1, transform: 'none', filter: 'blur(0)' },
        ], { duration: 800, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'forwards' });
        await sleep(1000);
        Object.assign(cam, zoomAbout(target, 1.32));
        moveTo(target, 2800, ease.outExpo);
        mark.animate([{ opacity: 1 }, { opacity: 0, transform: 'scale(1.08)', filter: 'blur(6px)' }], { duration: 450, easing: 'ease-in', fill: 'forwards' });
        const open = aperture.animate([{ '--mission-r': '0%' }, { '--mission-r': '160%' }], { duration: 1700, easing: 'cubic-bezier(.65,0,.35,1)', fill: 'forwards' });
        setTimeout(showCard, 1150);
        await open.finished.catch(() => {});
        aperture.hidden = true;
        open.cancel();
    }

    async function start() {
        layout();
        card.classList.add('is-hidden');
        const target = targetFor(scene);
        try {
            await shell.querySelector('.auth-mission-plate img')?.decode();
        } catch {
            // Show the scene anyway; the image paints when it arrives.
        }
        layout();
        const fresh = targetFor(scene);
        Object.assign(target, fresh);

        if (reduced) {
            Object.assign(cam, target);
            showCard();

            return;
        }

        let previous = null;
        try {
            previous = JSON.parse(store.get(SHOT_KEY) || 'null');
        } catch {
            previous = null;
        }

        if (previous && previous.scene !== scene && Date.now() - previous.t < 15000 && VIEWS[previous.scene]) {
            Object.assign(cam, targetFor(previous.scene));
            moveTo(target, 1500, ease.io);
            setTimeout(showCard, 640);
        } else if (! store.get(INTRO_KEY)) {
            store.set(INTRO_KEY, '1');
            await intro(target);
        } else {
            Object.assign(cam, zoomAbout(target, 1.08));
            moveTo(target, 1800, ease.outExpo);
            setTimeout(showCard, 180);
        }
        store.remove(SHOT_KEY);
    }

    /* Leaving: links glide out, forms remember the shot. */
    card.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (! link || link.target === '_blank' || event.metaKey || event.ctrlKey || event.shiftKey) {
            return;
        }
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.href === window.location.href || link.getAttribute('href').startsWith('#')) {
            return;
        }
        event.preventDefault();
        rememberShot();
        card.classList.remove('is-in');
        card.classList.add('is-out');
        setTimeout(() => {
            window.location.href = url.href;
        }, reduced ? 0 : 280);
    });

    shell.addEventListener('submit', (event) => {
        const form = event.target;
        if (form.closest('.auth-locale')) {
            rememberShot();

            return;
        }
        rememberShot();
        if (['login', 'twofactor', 'confirm', 'register', 'invitation'].includes(scene)) {
            store.set(ENTER_KEY, '1');
            shell.dataset.focus = '';
            if (! reduced) {
                moveTo(targetFor(scene, { focus: [0.69, 0.2], s: 2.4 }), 2400, ease.inCubic);
            }
        }
    }, true);

    /* Focus reactions */
    shell.addEventListener('focusin', (event) => {
        const name = event.target.getAttribute?.('name') || '';
        if (name === 'code') {
            shell.dataset.focus = 'code';
        } else if (name === 'email' || name === 'name') {
            shell.dataset.focus = 'email';
        } else if (name.startsWith('password') || name === 'recovery_code') {
            shell.dataset.focus = 'password';
        } else {
            shell.dataset.focus = '';
        }
    });
    shell.addEventListener('focusout', () => {
        requestAnimationFrame(() => {
            if (! shell.contains(document.activeElement)) {
                shell.dataset.focus = '';
            }
        });
    });

    window.addEventListener('pointermove', (event) => {
        ptr.tx = (event.clientX / vw) * 2 - 1;
        ptr.ty = (event.clientY / vh) * 2 - 1;
        if (card.contains(event.target)) {
            const rect = card.getBoundingClientRect();
            card.style.setProperty('--mx', `${event.clientX - rect.left}px`);
            card.style.setProperty('--my', `${event.clientY - rect.top}px`);
        }
    }, { passive: true });

    window.addEventListener('resize', () => {
        layout();
        if (! tween) {
            Object.assign(cam, targetFor(scene));
        }
    });

    // Back/forward cache: the page comes back mid-exit; show the card again.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            showCard();
            store.remove(ENTER_KEY);
        }
    });

    layout();
    Object.assign(cam, targetFor(scene));
    requestAnimationFrame(frame);
    start();

    if (message) {
        message.textContent = '';
    }
}

export function bootAuthMission() {
    document.querySelectorAll('.auth-shell-mission').forEach((shell) => mountMission(shell));
}

/**
 * After a successful sign-in the app layout starts hidden (html.gc-enter, set in
 * layouts/base) and assembles out of the same star veil the login closed on.
 */
export async function bootAppEnter() {
    const html = document.documentElement;
    if (! html.classList.contains('gc-enter')) {
        return;
    }
    store.remove(ENTER_KEY);

    if (document.querySelector('.auth-shell-mission') || reducedMotion()) {
        html.classList.remove('gc-enter');

        return;
    }

    const veil = document.createElement('div');
    veil.className = 'gc-enter-veil';
    veil.setAttribute('aria-hidden', 'true');
    const canvas = document.createElement('canvas');
    veil.appendChild(canvas);
    document.body.appendChild(veil);

    const ctx = canvas.getContext('2d');
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = window.innerWidth * dpr;
    canvas.height = window.innerHeight * dpr;
    const stars = Array.from({ length: 260 }, () => ({ x: Math.random(), y: Math.random(), r: Math.random() * 1.2 + 0.3, a: Math.random() * 0.7 + 0.2 }));
    let running = true;
    const t0 = performance.now();
    const draw = (now) => {
        if (! running) {
            return;
        }
        const k = Math.min(1, (now - t0) / 1400);
        const zoom = 1 + k * k * 0.6;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
        for (const s of stars) {
            ctx.fillStyle = `rgba(255,255,255,${s.a})`;
            ctx.beginPath();
            ctx.arc(window.innerWidth / 2 + (s.x - 0.5) * window.innerWidth * zoom, window.innerHeight / 2 + (s.y - 0.5) * window.innerHeight * zoom, s.r * (1 + k), 0, 6.283);
            ctx.fill();
        }
        requestAnimationFrame(draw);
    };
    requestAnimationFrame(draw);

    await sleep(420);
    const easing = 'cubic-bezier(.22,1,.36,1)';
    const layers = [...document.body.children].filter((el) => el !== veil && ! ['SCRIPT', 'STYLE', 'TEMPLATE'].includes(el.tagName));
    html.classList.remove('gc-enter');
    veil.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 900, easing: 'ease-in-out', fill: 'forwards' });
    // Opacity only: a transform/filter on these wrappers would re-anchor the fixed top bar and sidebar.
    layers.forEach((el) => {
        el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 800, easing, delay: 200, fill: 'backwards' });
    });
    const main = document.querySelector('main');
    if (main) {
        [...main.querySelectorAll(':scope > * > *')].slice(0, 10).forEach((el, i) => {
            el.animate([{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 520, easing, delay: 420 + i * 50, fill: 'backwards' });
        });
    }
    await sleep(1300);
    running = false;
    veil.remove();
}
