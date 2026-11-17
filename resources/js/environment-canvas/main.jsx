import { createRoot } from 'react-dom/client';
import { EnvironmentCanvasApp } from './EnvironmentCanvasApp';
import '@xyflow/react/dist/style.css';

/** @type {Map<Element, import('react-dom/client').Root>} */
const roots = new Map();

/**
 * @param {HTMLElement|null} el
 * @param {{
 *   graph: object,
 *   canUpdate: boolean,
 *   savePosition?: (id: string, x: number, y: number) => void|Promise<void>,
 *   onInspect?: (nodeId: string, panel: string) => void|Promise<void>,
 *   bootKey?: string|number,
 * }} options
 */
export function mountEnvironmentCanvas(el, options) {
    if (!el) {
        return null;
    }

    let root = roots.get(el);
    if (!root) {
        root = createRoot(el);
        roots.set(el, root);
    }

    root.render(
        <EnvironmentCanvasApp
            graph={options.graph || { nodes: [], edges: [], meta: {} }}
            canUpdate={!!options.canUpdate}
            savePosition={options.savePosition}
            onInspect={options.onInspect}
            bootKey={options.bootKey || Date.now()}
        />,
    );

    return root;
}

export function unmountEnvironmentCanvas(el) {
    const root = roots.get(el);
    if (!root) {
        return;
    }
    root.unmount();
    roots.delete(el);
}

window.gcEnvironmentCanvas = {
    mount: mountEnvironmentCanvas,
    unmount: unmountEnvironmentCanvas,
};

document.addEventListener('livewire:navigating', () => {
    document.querySelectorAll('[data-gc-environment-canvas]').forEach((el) => {
        unmountEnvironmentCanvas(el);
    });
});
