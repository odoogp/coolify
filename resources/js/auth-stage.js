/**
 * GetCloud auth-stage motion (anime.js).
 *
 * Apple-style aperture open → Artemis settle → living starfield
 * (depth drift, sharp blinks, occasional meteors). Soft visor on focus.
 */

import { animate, createTimeline, createTimer, stagger } from 'animejs';
import { markLoginEnter } from './app-enter.js';

const STATES = new Set(['idle', 'email-focus', 'password-focus', 'submitting', 'error']);

/**
 * @param {HTMLElement} shell
 */
function bindAuthFormStates(shell) {
    if (shell.dataset.authFormBound === '1') {
        return;
    }

    shell.dataset.authFormBound = '1';

    const setState = (state) => {
        if (! STATES.has(state) || shell.dataset.authState === state) {
            return;
        }
        shell.dataset.authState = state;
    };

    shell.addEventListener('focusin', (event) => {
        const target = event.target;
        if (! (target instanceof HTMLElement)) {
            return;
        }
        const name = target.getAttribute('name');
        if (name === 'email') {
            setState('email-focus');
        } else if (name === 'password' || name === 'password_confirmation') {
            setState('password-focus');
        }
    });

    shell.addEventListener('focusout', () => {
        requestAnimationFrame(() => {
            const active = document.activeElement;
            if (! (active instanceof HTMLElement) || ! shell.contains(active)) {
                setState(shell.dataset.authState === 'error' ? 'error' : 'idle');

                return;
            }
            const name = active.getAttribute('name');
            if (name === 'email') {
                setState('email-focus');
            } else if (name === 'password' || name === 'password_confirmation') {
                setState('password-focus');
            } else if (shell.dataset.authState !== 'error' && shell.dataset.authState !== 'submitting') {
                setState('idle');
            }
        });
    });

    shell.addEventListener('submit', () => {
        setState('submitting');
    }, true);

    const active = document.activeElement;
    if (active instanceof HTMLElement && shell.contains(active)) {
        const name = active.getAttribute('name');
        if (name === 'email') {
            setState('email-focus');
        } else if (name === 'password' || name === 'password_confirmation') {
            setState('password-focus');
        }
    }
}

/**
 * Depth drift + true blinks + rare meteors — enveloping living sky.
 *
 * @param {HTMLElement} sky
 */
function bootStarfield(sky) {
    const far = sky.querySelector('.auth-orbit-stars--far');
    const mid = sky.querySelector('.auth-orbit-stars--mid');
    const near = sky.querySelector('.auth-orbit-stars--near');
    const farStars = sky.querySelectorAll('.auth-orbit-star--far');
    const midStars = sky.querySelectorAll('.auth-orbit-star--mid');
    const nearStars = sky.querySelectorAll('.auth-orbit-star--near');
    const meteors = [...sky.querySelectorAll('.auth-orbit-meteor')];

    if (far) {
        animate(far, {
            translateX: [0, -34],
            translateY: [0, 6],
            duration: 120000,
            alternate: true,
            loop: true,
            ease: 'inOut(1.2)',
        });
    }

    if (mid) {
        animate(mid, {
            translateX: [0, -58],
            translateY: [0, 10],
            duration: 82000,
            alternate: true,
            loop: true,
            ease: 'inOut(1.2)',
        });
    }

    if (near) {
        animate(near, {
            translateX: [0, -86],
            translateY: [0, 14],
            duration: 54000,
            alternate: true,
            loop: true,
            ease: 'inOut(1.2)',
        });
    }

    // Soft ambient shimmer (far field)
    if (farStars.length) {
        animate(farStars, {
            opacity: (_el, i) => {
                const base = 0.28 + (i % 4) * 0.05;

                return [base, base + 0.22, base * 0.85, base];
            },
            duration: (_el, i) => 4200 + (i % 9) * 480,
            delay: stagger(40, { start: 0 }),
            loop: true,
            ease: 'inOut(2)',
        });
    }

    // Mid layer: irregular twinkle
    if (midStars.length) {
        animate(midStars, {
            opacity: (_el, i) => {
                const dim = 0.18 + (i % 5) * 0.04;
                const peak = 0.72 + (i % 4) * 0.06;

                return [dim, peak, dim * 1.1, dim * 0.55, peak * 0.85, dim];
            },
            duration: (_el, i) => 2600 + (i % 11) * 380 + (i % 3) * 220,
            delay: stagger(55, { start: 80 }),
            loop: true,
            ease: 'inOut(1.8)',
        });
    }

    // Near layer: sharp blinks + soft scale flare (enveloping “alive” sky)
    if (nearStars.length) {
        animate(nearStars, {
            opacity: (_el, i) => {
                const dim = 0.08 + (i % 3) * 0.04;
                const peak = 0.92 + (i % 5) * 0.015;

                // Hold dim → snap bright → hold → fade (real scintillation)
                return [dim, dim, peak, peak * 0.55, dim * 0.6, dim];
            },
            scale: (_el, i) => {
                const rest = 1;
                const flare = 1.35 + (i % 4) * 0.08;

                return [rest, rest, flare, 1.1, rest, rest];
            },
            duration: (_el, i) => 1800 + (i % 13) * 290 + (i % 5) * 160,
            delay: stagger(70, { start: 160 }),
            loop: true,
            ease: 'inOut(2.4)',
        });
    }

    if (! meteors.length) {
        return;
    }

    let meteorBusy = false;
    let meteorIndex = 0;

    const fireMeteor = () => {
        if (meteorBusy || document.hidden) {
            return;
        }

        const meteor = meteors[meteorIndex % meteors.length];
        meteorIndex += 1;
        if (! (meteor instanceof HTMLElement)) {
            return;
        }

        meteorBusy = true;

        const skyBox = sky.getBoundingClientRect();
        const startX = 8 + Math.random() * 62;
        const startY = 4 + Math.random() * 42;
        const angle = -18 - Math.random() * 22;
        const distance = Math.min(skyBox.width, 920) * (0.42 + Math.random() * 0.28);
        const duration = 680 + Math.random() * 520;
        const streak = meteor.querySelector('.auth-orbit-meteor-streak');
        const trail = meteor.querySelector('.auth-orbit-meteor-trail');

        meteor.style.left = `${startX}%`;
        meteor.style.top = `${startY}%`;
        meteor.style.opacity = '0';
        meteor.style.translate = '0px 0px';

        if (streak instanceof HTMLElement) {
            streak.style.transform = `rotate(${angle}deg)`;
        }

        if (trail instanceof HTMLElement) {
            trail.style.width = `${70 + Math.random() * 70}px`;
        }

        const radians = (angle * Math.PI) / 180;
        const dx = Math.cos(radians) * distance;
        const dy = Math.sin(radians) * distance;

        animate(meteor, {
            translateX: [0, dx],
            translateY: [0, dy],
            opacity: [0, 1, 1, 0],
            duration,
            ease: 'linear',
            onComplete: () => {
                meteor.style.opacity = '0';
                meteor.style.translate = '0px 0px';
                meteorBusy = false;
            },
        });
    };

    const scheduleNextMeteor = () => {
        const wait = 2800 + Math.random() * 5600;
        const timer = createTimer({
            duration: wait,
            onComplete: () => {
                fireMeteor();
                scheduleNextMeteor();
            },
        });
        timer.play?.();
    };

    const first = createTimer({
        duration: 2200 + Math.random() * 1200,
        onComplete: () => {
            fireMeteor();
            scheduleNextMeteor();
        },
    });
    first.play?.();
}

/**
 * One wordmark only: burst → fly to card slot → atomic handoff → card reveals.
 *
 * @param {{
 *   shell: HTMLElement,
 *   orbit: HTMLElement | null,
 *   heroWrap: HTMLElement | null,
 *   sky: HTMLElement | null,
 *   visor: HTMLElement | null,
 *   brand: HTMLElement | null,
 *   openBrand: HTMLElement | null,
 *   card: HTMLElement | null,
 *   veil: HTMLElement | null,
 *   tagline: HTMLElement | null,
 *   locale: HTMLElement | null,
 *   skipOpen: boolean,
 * }} parts
 */
function playAuthOpen(parts) {
    const {
        shell,
        heroWrap,
        sky,
        visor,
        brand,
        openBrand,
        card,
        veil,
        tagline,
        locale,
        skipOpen,
    } = parts;

    const finishOpen = () => {
        shell.classList.remove('auth-shell--opening');
        if (veil instanceof HTMLElement) {
            veil.style.opacity = '0';
            veil.style.pointerEvents = 'none';
        }
        if (openBrand instanceof HTMLElement) {
            openBrand.style.opacity = '0';
            openBrand.style.visibility = 'hidden';
        }
        if (brand instanceof HTMLElement) {
            brand.style.opacity = '1';
            brand.style.visibility = 'visible';
        }
        if (card instanceof HTMLElement) {
            card.style.visibility = 'visible';
            card.style.pointerEvents = '';
            card.animate(
                [
                    { opacity: 0, transform: 'translateY(22px) scale(0.97)' },
                    { opacity: 1, transform: 'translateY(0) scale(1)' },
                ],
                { duration: 720, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', fill: 'forwards' },
            );
            card.style.opacity = '1';
            card.style.transform = 'none';
        }
        if (locale instanceof HTMLElement) {
            locale.style.opacity = '0.85';
        }
        if (tagline instanceof HTMLElement) {
            tagline.style.opacity = '1';
        }
    };

    if (skipOpen) {
        finishOpen();

        return;
    }

    shell.classList.add('auth-shell--opening');

    if (brand instanceof HTMLElement) {
        brand.style.opacity = '0';
        brand.style.visibility = 'hidden';
    }

    if (card instanceof HTMLElement) {
        card.style.opacity = '0';
        card.style.visibility = 'hidden';
        card.style.pointerEvents = 'none';
        card.style.transform = 'translateY(22px) scale(0.97)';
    }

    const open = createTimeline({
        defaults: { ease: 'out(3)' },
    });

    // 1) Center flyer — the only visible wordmark (scale from center during burst)
    if (openBrand instanceof HTMLElement) {
        const startW = Math.min(248, window.innerWidth * 0.58);
        openBrand.style.visibility = 'visible';
        openBrand.style.width = `${startW}px`;
        openBrand.style.transformOrigin = 'center center';
        openBrand.style.transform = 'scale(0.72)';
        openBrand.style.filter = 'drop-shadow(0 0 18px rgb(160 180 255 / 0.3))';

        // Approximate height before paint; refined after width is set.
        void openBrand.offsetWidth;
        const bh = openBrand.getBoundingClientRect().height || 42;
        const cx = Math.max(0, (window.innerWidth - startW) / 2);
        const cy = Math.max(0, window.innerHeight * 0.4 - bh / 2);
        openBrand.style.left = `${cx}px`;
        openBrand.style.top = `${cy}px`;
        openBrand.style.opacity = '0';

        open.add(openBrand, {
            opacity: [0, 1],
            scale: [0.72, 1.06],
            duration: 680,
            ease: 'out(3)',
        }, 60);

        open.add(openBrand, {
            scale: [1.06, 1.34, 1],
            filter: [
                'drop-shadow(0 0 18px rgb(160 180 255 / 0.3))',
                'drop-shadow(0 0 56px rgb(210 225 255 / 0.95))',
                'drop-shadow(0 0 20px rgb(160 180 255 / 0.35))',
            ],
            duration: 820,
            ease: 'inOut(2.2)',
        }, 680);
    }

    // 2) Scene behind the logo
    if (sky) {
        open.add(sky, {
            opacity: [0, 1],
            duration: 1300,
            ease: 'out(2)',
        }, 420);
    }

    if (veil) {
        open.add(veil, {
            opacity: [1, 0],
            scale: [1, 1.05],
            duration: 1400,
            ease: 'inOut(2.2)',
        }, 820);
    }

    if (heroWrap) {
        open.add(heroWrap, {
            opacity: [0, 1],
            scale: [1.18, 1],
            filter: ['brightness(0.22)', 'brightness(1)'],
            duration: 1900,
            ease: 'out(3.2)',
        }, 900);
    }

    if (visor) {
        open.add(visor, {
            opacity: [0, 0.28],
            duration: 800,
            ease: 'out(2)',
        }, 2000);
    }

    // 3) Conciliation: logo flies home while the glass materializes around the landing slot.
    open.add({
        duration: 40,
        onComplete: () => {
            if (! (openBrand instanceof HTMLElement) || ! (brand instanceof HTMLElement) || ! (card instanceof HTMLElement)) {
                finishOpen();

                return;
            }

            // Layout the card (still transparent) so we can measure the brand slot.
            card.style.visibility = 'hidden';
            card.style.opacity = '0';
            void card.offsetWidth;

            const from = openBrand.getBoundingClientRect();
            const to = brand.getBoundingClientRect();

            if (to.width < 2 || to.height < 2) {
                finishOpen();

                return;
            }

            openBrand.style.transformOrigin = 'top left';
            openBrand.style.transform = 'none';
            openBrand.style.left = `${from.left}px`;
            openBrand.style.top = `${from.top}px`;
            openBrand.style.width = `${from.width}px`;

            // Brand slot stays empty until handoff — only the flyer is the logo.
            brand.style.visibility = 'hidden';
            brand.style.opacity = '0';

            // Card becomes visible but starts transparent; rises as the logo approaches.
            card.style.visibility = 'visible';
            card.style.pointerEvents = 'none';
            card.style.opacity = '0';
            card.style.translate = '0px 14px';

            const flightMs = 980;

            animate(openBrand, {
                left: to.left,
                top: to.top,
                width: to.width,
                filter: 'drop-shadow(0 2px 10px rgb(0 0 0 / 0.35))',
                duration: flightMs,
                ease: 'inOut(2.6)',
                onComplete: () => {
                    // Same-frame swap so it never reads as two logos.
                    brand.style.visibility = 'visible';
                    brand.style.opacity = '1';
                    openBrand.style.opacity = '0';
                    openBrand.style.visibility = 'hidden';
                    card.style.pointerEvents = '';

                    if (locale instanceof HTMLElement) {
                        animate(locale, { opacity: [0, 0.85], duration: 320 });
                    }
                    if (tagline instanceof HTMLElement) {
                        animate(tagline, { opacity: [0, 1], duration: 380 });
                    }
                    finishOpen();
                },
            });

            // Glass eases in with the flight (slightly softer ease so logo leads the eye).
            animate(card, {
                opacity: [0, 1],
                translateY: [14, 0],
                duration: flightMs,
                delay: 90,
                ease: 'out(3)',
            });
        },
    }, 2100);

    const safety = createTimer({
        duration: 5200,
        onComplete: finishOpen,
    });
    safety.play?.();
}

/**
 * Multi-layer parallax + optional device tilt (gyro).
 * Form chrome stays pinned so focus/submit tweens never fight the RAF loop.
 *
 * @param {{
 *   visual: HTMLElement,
 *   mission: HTMLElement | null,
 *   heroWrap: HTMLElement,
 *   sky: HTMLElement | null,
 *   visor: HTMLElement | null,
 * }} layers
 */
function bootImmersiveParallax(layers) {
    const { visual, mission, heroWrap, sky, visor } = layers;
    let px = 0;
    let py = 0;
    let tx = 0;
    let ty = 0;
    let raf = 0;
    /** @type {((e: DeviceOrientationEvent) => void) | null} */
    let onOrient = null;

    const tick = () => {
        px += (tx - px) * 0.055;
        py += (ty - py) * 0.055;
        heroWrap.style.translate = `${px * 10}px ${py * 7}px`;
        if (visor) {
            visor.style.translate = `${px * 7}px ${py * 5}px`;
        }
        if (sky) {
            sky.style.translate = `${px * -18}px ${py * -9}px`;
        }
        raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);

    const onMove = (event) => {
        const rect = (mission || visual).getBoundingClientRect();
        if (rect.width < 1 || rect.height < 1) {
            return;
        }
        tx = ((event.clientX - rect.left) / rect.width - 0.5) * 2;
        ty = ((event.clientY - rect.top) / rect.height - 0.5) * 2;
    };

    const reset = () => {
        // Keep a whisper of gyro bias if active; pointer leave zeros pointer target.
        if (! onOrient) {
            tx = 0;
            ty = 0;
        }
    };

    visual.addEventListener('pointermove', onMove);
    mission?.addEventListener('pointermove', onMove);
    visual.addEventListener('pointerleave', reset);
    mission?.addEventListener('pointerleave', reset);

    const finePointer = window.matchMedia('(pointer: fine)').matches;
    const canTilt = ! finePointer
        && typeof window.DeviceOrientationEvent !== 'undefined'
        && window.isSecureContext;

    if (canTilt) {
        onOrient = (event) => {
            if (document.hidden) {
                return;
            }
            const beta = typeof event.beta === 'number' ? event.beta : 0; // -180..180 front-back
            const gamma = typeof event.gamma === 'number' ? event.gamma : 0; // -90..90 left-right
            tx = Math.max(-1, Math.min(1, gamma / 18));
            ty = Math.max(-1, Math.min(1, (beta - 45) / 28));
        };

        const bindOrient = () => {
            if (onOrient) {
                window.addEventListener('deviceorientation', onOrient, { passive: true });
            }
        };

        // iOS 13+ requires a user gesture for permission; fail soft otherwise.
        const DOE = window.DeviceOrientationEvent;
        if (typeof DOE.requestPermission === 'function') {
            const ask = () => {
                DOE.requestPermission()
                    .then((state) => {
                        if (state === 'granted') {
                            bindOrient();
                        }
                    })
                    .catch(() => {
                        // ignore — pointer parallax remains
                    });
                mission?.removeEventListener('pointerdown', ask);
                visual.removeEventListener('pointerdown', ask);
            };
            mission?.addEventListener('pointerdown', ask, { once: true });
            visual.addEventListener('pointerdown', ask, { once: true });
        } else {
            bindOrient();
        }
    }

    void raf;
}

/**
 * @param {HTMLElement} shell
 * @returns {Promise<void>}
 */
export async function bootAuthStage(shell) {
    if (! (shell instanceof HTMLElement)) {
        return;
    }

    if (shell.dataset.authStageBooted === '1') {
        return;
    }

    bindAuthFormStates(shell);

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const reduceData = window.matchMedia('(prefers-reduced-data: reduce)').matches;

    if (reduceMotion || reduceData) {
        shell.classList.add('auth-shell--static');
        shell.dataset.authState = shell.dataset.authState || 'idle';
        shell.dataset.authStageBooted = '1';

        return;
    }

    const stage = shell.querySelector('.auth-stage');
    const mission = shell.querySelector('.auth-mission');
    if (! (stage instanceof HTMLElement) && ! (mission instanceof HTMLElement)) {
        shell.dataset.authStageBooted = '1';

        return;
    }

    shell.dataset.authStageBooted = '1';
    stage?.classList.add('auth-stage--anime');

    const brand = shell.querySelector('.auth-login-brand');
    const openBrand = shell.querySelector('.auth-open-brand');
    const card = shell.querySelector('.auth-card');
    const orbit = shell.querySelector('.auth-orbit');
    const heroWrap = shell.querySelector('.auth-orbit-hero-wrap');
    const hero = shell.querySelector('.auth-orbit-hero');
    const visor = shell.querySelector('.auth-orbit-visor-glow');
    const sky = shell.querySelector('.auth-orbit-sky');
    const veil = shell.querySelector('.auth-orbit-open-veil');
    const tagline = shell.querySelector('.auth-mission-tagline');
    const locale = shell.querySelector('.auth-mission-locale');
    const visual = shell.querySelector('.auth-mission-visual') || stage;
    const skipOpen = shell.dataset.authState === 'error';

    playAuthOpen({
        shell,
        orbit,
        heroWrap,
        sky,
        visor,
        brand,
        openBrand,
        card,
        veil,
        tagline,
        locale,
        skipOpen,
    });

    if (hero) {
        // Slow ken-burns breathe after open settle
        animate(hero, {
            scale: [1, 1.028],
            translateX: [0, -6],
            translateY: [0, -3],
            duration: 28000,
            alternate: true,
            loop: true,
            ease: 'inOut(2)',
        });
    }

    if (sky instanceof HTMLElement) {
        bootStarfield(sky);
    }

    if (visual instanceof HTMLElement && heroWrap instanceof HTMLElement) {
        bootImmersiveParallax({
            visual,
            mission: mission instanceof HTMLElement ? mission : null,
            heroWrap,
            sky: sky instanceof HTMLElement ? sky : null,
            visor: visor instanceof HTMLElement ? visor : null,
        });
    }

    /** @type {ReturnType<typeof createTimeline> | null} */
    let stateTween = null;
    let currentState = '';

    const clearEnterOnError = () => {
        try {
            sessionStorage.removeItem('gc-enter');
        } catch (error) {
            // ignore
        }
    };

    const applyState = (raw) => {
        const state = STATES.has(raw) ? raw : 'idle';
        if (state === currentState) {
            return;
        }
        currentState = state;

        if (shell.dataset.authState !== state) {
            shell.dataset.authState = state;
        }

        if (stateTween) {
            stateTween.pause();
            stateTween = null;
        }

        stateTween = createTimeline({ defaults: { duration: 520, ease: 'out(3)' } });

        if (state === 'email-focus') {
            // Cooler sky / violet cast
            if (heroWrap) {
                stateTween.add(heroWrap, { filter: 'brightness(1.03) saturate(0.94) hue-rotate(-8deg)' }, 0);
            }
            if (visor) {
                stateTween.add(visor, { opacity: 0.48 }, 0);
            }
            if (sky) {
                stateTween.add(sky, { filter: 'brightness(1.14) saturate(1.2) hue-rotate(-14deg)' }, 0);
            }
            if (brand) {
                stateTween.add(brand, { scale: 1.015 }, 0);
            }
        } else if (state === 'password-focus') {
            // Visor contrast punch
            if (heroWrap) {
                stateTween.add(heroWrap, { filter: 'brightness(1.08) contrast(1.06) saturate(1.05)' }, 0);
            }
            if (visor) {
                stateTween.add(visor, { opacity: 0.68 }, 0);
            }
            if (sky) {
                stateTween.add(sky, { filter: 'brightness(1.1) saturate(1.05)' }, 0);
            }
        } else if (state === 'submitting') {
            markLoginEnter();
            shell.classList.add('auth-shell--entering');

            const isMobile = window.matchMedia('(max-width: 900px)').matches;
            const diveVeil = shell.querySelector('.auth-dive-veil');

            // Glass dissolves — out to the side on desktop, down on mobile.
            if (card) {
                stateTween.add(card, {
                    opacity: 0,
                    scale: 0.94,
                    translateX: isMobile ? 0 : 48,
                    translateY: isMobile ? 36 : 8,
                    filter: 'blur(8px)',
                    duration: isMobile ? 560 : 640,
                    ease: 'in(2)',
                }, 0);
            }
            if (locale) {
                stateTween.add(locale, { opacity: 0, duration: 360 }, 0);
            }
            if (tagline) {
                stateTween.add(tagline, { opacity: 0, duration: 360 }, 0);
            }

            // Plunge into the starfield (hero falls away, sky rushes forward).
            if (heroWrap) {
                stateTween.add(heroWrap, {
                    scale: [1, isMobile ? 1.45 : 1.65],
                    translateY: [0, isMobile ? -48 : -96],
                    opacity: [1, 0.08],
                    filter: ['brightness(1)', 'brightness(0.3) blur(3px)'],
                    duration: 1100,
                    ease: 'in(1.85)',
                }, 40);
            }
            if (visor) {
                stateTween.add(visor, { opacity: 0, duration: 420 }, 0);
            }
            if (sky) {
                stateTween.add(sky, {
                    scale: [1, isMobile ? 2.6 : 3.2],
                    translateY: [0, isMobile ? 24 : 40],
                    filter: ['brightness(1)', 'brightness(1.7)'],
                    duration: 1100,
                    ease: 'in(1.7)',
                }, 60);
            }
            if (orbit) {
                stateTween.add(orbit, {
                    scale: [1, 1.2],
                    duration: 1100,
                    ease: 'in(1.8)',
                }, 40);
            }

            // Match the app enter veil so navigation never feels like a loader.
            if (diveVeil instanceof HTMLElement) {
                diveVeil.style.opacity = '0';
                diveVeil.style.transform = 'scale(1.1)';
                stateTween.add(diveVeil, {
                    opacity: [0, 1],
                    scale: [1.1, 1],
                    duration: 780,
                    ease: 'in(1.6)',
                }, 380);
            }
        } else if (state === 'error') {
            clearEnterOnError();
            shell.classList.remove('auth-shell--entering');
            const diveVeil = shell.querySelector('.auth-dive-veil');
            if (diveVeil instanceof HTMLElement) {
                stateTween.add(diveVeil, { opacity: 0, scale: 1.08, duration: 280 }, 0);
            }
            if (card) {
                stateTween.add(card, {
                    opacity: 1,
                    translateX: [0, -6, 6, -3, 3, 0],
                    translateY: 0,
                    scale: 1,
                    filter: 'none',
                    duration: 400,
                }, 0);
            }
            if (locale) {
                stateTween.add(locale, { opacity: 0.85 }, 0);
            }
            if (tagline) {
                stateTween.add(tagline, { opacity: 1 }, 0);
            }
            if (heroWrap) {
                stateTween.add(heroWrap, { scale: 1, translateY: 0, opacity: 1, filter: 'brightness(0.96)' }, 0);
            }
            if (visor) {
                stateTween.add(visor, { opacity: 0.4 }, 0);
            }
            if (sky) {
                stateTween.add(sky, { scale: 1, translateY: 0, filter: 'brightness(0.95) hue-rotate(0deg)' }, 0);
            }
            if (orbit) {
                stateTween.add(orbit, { scale: 1, duration: 320 }, 0);
            }
        } else {
            shell.classList.remove('auth-shell--entering');
            const diveVeil = shell.querySelector('.auth-dive-veil');
            if (diveVeil instanceof HTMLElement) {
                stateTween.add(diveVeil, { opacity: 0, scale: 1.08, duration: 280 }, 0);
            }
            if (heroWrap) {
                stateTween.add(heroWrap, {
                    scale: 1,
                    translateY: 0,
                    opacity: 1,
                    filter: 'brightness(1) saturate(1) contrast(1) hue-rotate(0deg)',
                }, 0);
            }
            if (visor) {
                stateTween.add(visor, { opacity: 0.28 }, 0);
            }
            if (sky) {
                stateTween.add(sky, { scale: 1, translateY: 0, filter: 'brightness(1) saturate(1) hue-rotate(0deg)' }, 0);
            }
            if (orbit) {
                stateTween.add(orbit, { scale: 1, duration: 320 }, 0);
            }
            if (brand) {
                stateTween.add(brand, { scale: 1, opacity: 1 }, 0);
            }
            if (card) {
                stateTween.add(card, {
                    opacity: 1,
                    translateX: 0,
                    translateY: 0,
                    scale: 1,
                    filter: 'none',
                }, 0);
            }
            if (locale) {
                stateTween.add(locale, { opacity: 0.85 }, 0);
            }
            if (tagline) {
                stateTween.add(tagline, { opacity: 1 }, 0);
            }
        }
    };

    if (shell.dataset.authState === 'error') {
        clearEnterOnError();
    }

    applyState(shell.dataset.authState || 'idle');

    shell.addEventListener('auth-state', (event) => {
        const detail = event instanceof CustomEvent ? event.detail : null;
        applyState(typeof detail === 'string' ? detail : 'idle');
    });

    const observer = new MutationObserver(() => {
        applyState(shell.dataset.authState || 'idle');
    });
    observer.observe(shell, { attributes: true, attributeFilter: ['data-auth-state'] });
}

export function bootAuthStagesIn(root = document) {
    root.querySelectorAll('.auth-shell').forEach((shell) => {
        void bootAuthStage(shell);
    });
}
