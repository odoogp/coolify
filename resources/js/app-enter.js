/**
 * Post-login emerge-from-stars (anime.js).
 *
 * Login dive sets sessionStorage `gc-enter` and ends on a star veil.
 * The app layout starts on that same veil (html.gc-enter), then the shell
 * assembles out of the stars — no spinner / blank loading page.
 */

import { createTimeline } from 'animejs';

const ENTER_KEY = 'gc-enter';

function clearEnterFlag() {
    try {
        sessionStorage.removeItem(ENTER_KEY);
    } catch (error) {
        // ignore
    }
    document.documentElement.classList.remove('gc-enter');
}

/**
 * @returns {Promise<void>}
 */
export async function bootAppEnter() {
    if (! document.documentElement.classList.contains('gc-enter')) {
        return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        clearEnterFlag();

        return;
    }

    const sidebars = document.querySelectorAll('[data-gc-enter="sidebar"]');
    const mains = document.querySelectorAll('[data-gc-enter="main"]');
    const topbars = document.querySelectorAll('[data-gc-enter="topbar"]');
    const veil = document.querySelector('[data-gc-enter="veil"]');
    const isMobile = window.matchMedia('(max-width: 1023px)').matches;

    if (! sidebars.length && ! mains.length && ! topbars.length) {
        clearEnterFlag();

        return;
    }

    const timeline = createTimeline({
        defaults: { ease: 'out(3)' },
        onComplete: () => {
            clearEnterFlag();
        },
    });

    // Hold the starfield a beat so the dive → app seam reads continuous.
    if (veil) {
        timeline.add(veil, {
            opacity: 1,
            scale: isMobile ? 1.06 : 1.1,
            duration: 220,
            ease: 'linear',
        }, 0);
    }

    // Desktop: left → center → top. Mobile: main first (no fixed sidebar).
    if (! isMobile && sidebars.length) {
        timeline.add(sidebars, {
            opacity: [0, 1],
            translateX: [-22, 0],
            duration: 380,
        }, 180);
    }

    if (mains.length) {
        timeline.add(mains, {
            opacity: [0, 1],
            translateY: [isMobile ? 20 : 14, 0],
            scale: [isMobile ? 0.99 : 0.985, 1],
            duration: isMobile ? 420 : 360,
        }, isMobile ? 160 : (sidebars.length ? '-=160' : 200));
    }

    if (topbars.length) {
        timeline.add(topbars, {
            opacity: [0, 1],
            translateY: [isMobile ? -12 : -14, 0],
            duration: 320,
        }, '-=140');
    }

    if (isMobile && sidebars.length) {
        // Mobile drawer/sidebar chrome can appear softly after main.
        timeline.add(sidebars, {
            opacity: [0, 1],
            duration: 280,
        }, '-=80');
    }

    // Stars pull back / dissolve as the shell settles — still not a “loader”.
    if (veil) {
        timeline.add(veil, {
            opacity: [1, 0],
            scale: [isMobile ? 1.06 : 1.1, isMobile ? 1.18 : 1.22],
            duration: 560,
            ease: 'inOut(2)',
        }, 120);
    }

    window.setTimeout(() => {
        if (document.documentElement.classList.contains('gc-enter')) {
            clearEnterFlag();
        }
    }, 2800);
}

export function markLoginEnter() {
    try {
        sessionStorage.setItem(ENTER_KEY, '1');
    } catch (error) {
        // ignore
    }
}
