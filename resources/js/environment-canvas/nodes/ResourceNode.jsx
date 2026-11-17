import { Handle, Position } from '@xyflow/react';
import { memo, useCallback } from 'react';

const DEFAULT_ICON = '/svgs/default.webp';

function Glyph({ name }) {
    if (name === 'globe') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18" />
            </svg>
        );
    }

    if (name === 'github') {
        return (
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 2C6.48 2 2 6.58 2 12.26c0 4.52 2.87 8.35 6.84 9.7.5.1.68-.22.68-.48 0-.24-.01-.87-.01-1.7-2.78.62-3.37-1.37-3.37-1.37-.46-1.2-1.12-1.52-1.12-1.52-.92-.64.07-.63.07-.63 1.02.07 1.56 1.07 1.56 1.07.9 1.58 2.36 1.12 2.94.86.09-.67.35-1.12.63-1.38-2.22-.26-4.56-1.14-4.56-5.07 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.32.1-2.75 0 0 .84-.27 2.75 1.05A9.3 9.3 0 0 1 12 6.8c.85 0 1.7.12 2.5.34 1.9-1.32 2.74-1.05 2.74-1.05.55 1.43.2 2.49.1 2.75.64.72 1.03 1.63 1.03 2.75 0 3.94-2.34 4.8-4.57 5.06.36.32.68.94.68 1.9 0 1.37-.01 2.47-.01 2.8 0 .27.18.59.69.49A10.03 10.03 0 0 0 22 12.26C22 6.58 17.52 2 12 2Z" />
            </svg>
        );
    }

    if (name === 'monitor') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true">
                <rect x="3" y="4" width="18" height="12" rx="2" />
                <path d="M8 20h8M12 16v4" />
            </svg>
        );
    }

    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true">
            <ellipse cx="12" cy="6" rx="8" ry="3" />
            <path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6" />
            <path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" />
        </svg>
    );
}

function navigateTo(href, external = false) {
    if (!href) {
        return;
    }
    if (external) {
        window.open(href, '_blank', 'noopener,noreferrer');
        return;
    }
    if (window.Livewire?.navigate) {
        window.Livewire.navigate(href);
        return;
    }
    window.location.assign(href);
}

function ResourceNodeComponent({ id, data, selected }) {
    const ports = data.ports || { in: true, out: true };
    const panels = Array.isArray(data.panels) ? data.panels : [];
    const hasLogs = panels.includes('logs');
    const hasMonitor = panels.includes('monitor') || panels.includes('metrics');
    const hasGithub = panels.includes('github') || data.kind === 'github';
    const hasActions =
        selected && (hasLogs || hasMonitor || hasGithub || data.openHref || data.href);

    const inspect = useCallback(
        (panel) => {
            if (typeof data.onInspect === 'function') {
                data.onInspect(id, panel);
            }
        },
        [data, id],
    );

    const openDefault = useCallback(
        (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (hasGithub) {
                inspect('github');
                return;
            }
            if (hasLogs) {
                inspect('logs');
                return;
            }
            if (hasMonitor) {
                inspect('monitor');
                return;
            }
            navigateTo(data.openHref || data.href, !!data.external || !!data.openHref);
        },
        [data.external, data.href, data.openHref, hasGithub, hasLogs, hasMonitor, inspect],
    );

    const openSite = useCallback(
        (event) => {
            event.preventDefault();
            event.stopPropagation();
            navigateTo(data.openHref, true);
        },
        [data.openHref],
    );

    const onImageError = useCallback((event) => {
        event.currentTarget.src = DEFAULT_ICON;
    }, []);

    return (
        <article
            className={[
                'gc-flow-node',
                `is-${data.kind || 'service'}`,
                `is-${data.status || 'idle'}`,
                selected ? 'is-selected' : '',
                hasActions ? 'has-observe' : '',
            ]
                .filter(Boolean)
                .join(' ')}
            data-id={id}
            onDoubleClick={openDefault}
        >
            {ports.in ? <Handle type="target" position={Position.Left} className="gc-flow-handle" /> : null}
            {ports.out ? <Handle type="source" position={Position.Right} className="gc-flow-handle" /> : null}

            <div className={`gc-flow-node-icon is-${data.kind || 'service'}`}>
                {data.icon ? (
                    <img src={data.icon} alt="" loading="lazy" onError={onImageError} />
                ) : (
                    <Glyph name={data.glyph || 'disk'} />
                )}
            </div>

            <div className="gc-flow-node-body">
                <div className="gc-flow-node-top">
                    <span className="gc-flow-node-kind">{data.meta || data.kind}</span>
                    <span className={`gc-flow-status is-${data.status || 'idle'}`} title={data.statusLabel || ''} />
                </div>
                <h2 className="gc-flow-node-label">{data.label}</h2>
                <p className="gc-flow-node-meta">{data.statusLabel}</p>
            </div>

            {hasActions ? (
                <div className="gc-flow-node-actions nodrag nopan">
                    {hasGithub ? (
                        <button type="button" className="gc-flow-node-action is-primary" onClick={(e) => { e.stopPropagation(); inspect('github'); }}>
                            GitHub
                        </button>
                    ) : null}
                    {hasLogs ? (
                        <button type="button" className="gc-flow-node-action is-primary" onClick={(e) => { e.stopPropagation(); inspect('logs'); }}>
                            Logs
                        </button>
                    ) : null}
                    {hasMonitor ? (
                        <button type="button" className="gc-flow-node-action" onClick={(e) => { e.stopPropagation(); inspect('metrics'); }}>
                            Metrics
                        </button>
                    ) : null}
                    {data.openHref ? (
                        <button type="button" className="gc-flow-node-action" onClick={openSite}>
                            Open
                        </button>
                    ) : null}
                    {!data.openHref && data.href && data.external ? (
                        <button
                            type="button"
                            className="gc-flow-node-action"
                            onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                navigateTo(data.href, true);
                            }}
                        >
                            Open
                        </button>
                    ) : null}
                </div>
            ) : null}
        </article>
    );
}

export const ResourceNode = memo(ResourceNodeComponent);
