import { initializeHtmlEditor } from './html-editor.js';
import { initializeTerminalComponent } from './terminal.js';

// Livewire 3.5.19+ re-applies `x-cloak` to morphed elements during wire:navigate
// (via replaceHtmlAttributes). With `[x-cloak]{display:none}` on the app wrapper,
// this blanks the whole page on every navigation until Alpine re-processes it.
// Strip leftover x-cloak after each navigation, but keep it on x-show-gated
// nodes (helpers, menus, modals). Stripping those reveals every closed popup
// when Alpine has not yet re-applied display:none after a morph.
document.addEventListener('livewire:navigated', () => {
    document.querySelectorAll('[x-cloak]').forEach((el) => {
        if (el.hasAttribute('x-show')) {
            return;
        }

        el.removeAttribute('x-cloak');
    });
});

// Register the terminal data provider before Alpine initializes the page.
// Keeping this registration independent from the current route also makes it
// available before Alpine processes terminal markup after wire:navigate.
document.addEventListener('alpine:init', () => {
    initializeTerminalComponent();
    initializeHtmlEditor();
});

/**
 * Smooth-scroll a settings section into view, then flash its border for 500ms
 * after the scroll has settled. Starting the flash immediately makes long
 * jumps (top → bottom) finish scrolling after the animation has already ended.
 *
 * @param {string} id
 */
window.scrollToSettingsSection = function scrollToSettingsSection(id) {
    const el = document.getElementById(id);
    if (!el) {
        return;
    }

    if (typeof el._sectionHighlightCleanup === 'function') {
        el._sectionHighlightCleanup();
    }

    const runHighlight = () => {
        el.classList.remove('is-section-highlight');
        // Force reflow so the 500ms highlight can re-run on repeated clicks.
        void el.offsetWidth;
        el.classList.add('is-section-highlight');
        el._sectionHighlightTimer = window.setTimeout(() => {
            el.classList.remove('is-section-highlight');
        }, 500);
    };

    let finished = false;
    let rafId = 0;
    let scrollEndHandler = null;

    const cleanup = () => {
        if (rafId) {
            window.cancelAnimationFrame(rafId);
            rafId = 0;
        }
        if (scrollEndHandler) {
            window.removeEventListener('scrollend', scrollEndHandler);
            scrollEndHandler = null;
        }
        if (el._sectionHighlightTimer) {
            window.clearTimeout(el._sectionHighlightTimer);
            el._sectionHighlightTimer = null;
        }
    };

    const finish = () => {
        if (finished) {
            return;
        }
        finished = true;
        cleanup();
        runHighlight();
    };

    el._sectionHighlightCleanup = () => {
        finished = true;
        cleanup();
        el.classList.remove('is-section-highlight');
        el._sectionHighlightCleanup = null;
    };

    el.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Prefer the native scrollend event when the browser fires it.
    scrollEndHandler = () => finish();
    window.addEventListener('scrollend', scrollEndHandler, { once: true });

    // Fallback: wait until the target's Y position is stable for a few frames
    // (covers browsers without scrollend, and no-op scrolls when already in view).
    let lastTop = null;
    let stableFrames = 0;
    let frames = 0;
    const maxFrames = 180; // ~3s safety cap

    const tick = () => {
        if (finished) {
            return;
        }

        frames += 1;
        const top = el.getBoundingClientRect().top;

        if (lastTop !== null && Math.abs(top - lastTop) < 0.5) {
            stableFrames += 1;
        } else {
            stableFrames = 0;
        }
        lastTop = top;

        // Skip the first couple frames so we don't flash before smooth scroll starts.
        if (frames > 4 && stableFrames >= 4) {
            finish();
            return;
        }

        if (frames >= maxFrames) {
            finish();
            return;
        }

        rafId = window.requestAnimationFrame(tick);
    };

    rafId = window.requestAnimationFrame(tick);
};

const tileSelector = 'main .grid > a.rounded-xl.shadow-sm, main .grid > article.rounded-xl.shadow-sm, .empty-state';

function placeTileSpot(card, event) {
    const rect = card.getBoundingClientRect();

    if (rect.width === 0 || rect.height === 0) {
        return;
    }

    card.style.setProperty('--spot-x', `${((event.clientX - rect.left) / rect.width) * 100}%`);
    card.style.setProperty('--spot-y', `${((event.clientY - rect.top) / rect.height) * 100}%`);
}

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.addEventListener('pointermove', (event) => {
        const card = event.target.closest?.(tileSelector);

        if (!card) {
            return;
        }

        placeTileSpot(card, event);
    }, { passive: true });

    document.addEventListener('pointerdown', (event) => {
        const card = event.target.closest?.(tileSelector);

        if (!card) {
            return;
        }

        card.classList.add('is-pressed');
        placeTileSpot(card, event);
    });

    document.addEventListener('pointerup', () => {
        document.querySelectorAll('.is-pressed').forEach((card) => {
            card.classList.remove('is-pressed');
        });
    });

    document.addEventListener('pointerout', (event) => {
        const card = event.target.closest?.(tileSelector);

        if (!card || card.contains(event.relatedTarget)) {
            return;
        }

        card.classList.remove('is-pressed');
        card.style.removeProperty('--spot-x');
        card.style.removeProperty('--spot-y');
    });
}
