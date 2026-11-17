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
    const showObserve = selected && (data.logsHref || data.monitorHref || data.href);

    const openResource = useCallback(
        (event) => {
            event.preventDefault();
            event.stopPropagation();
            navigateTo(data.href, !!data.external);
        },
        [data.external, data.href],
    );

    const openLogs = useCallback(
        (event) => {
            event.preventDefault();
            event.stopPropagation();
            navigateTo(data.logsHref);
        },
        [data.logsHref],
    );

    const openMonitor = useCallback(
        (event) => {
            event.preventDefault();
            event.stopPropagation();
            navigateTo(data.monitorHref, true);
        },
        [data.monitorHref],
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
                showObserve ? 'has-observe' : '',
            ]
                .filter(Boolean)
                .join(' ')}
            data-id={id}
            onDoubleClick={openResource}
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

            {showObserve ? (
                <div className="gc-flow-node-actions nodrag nopan">
                    {data.logsHref ? (
                        <button type="button" className="gc-flow-node-action" onClick={openLogs}>
                            Logs
                        </button>
                    ) : null}
                    {data.monitorHref ? (
                        <button type="button" className="gc-flow-node-action" onClick={openMonitor}>
                            Monitor
                        </button>
                    ) : null}
                    {data.href ? (
                        <button type="button" className="gc-flow-node-action is-primary" onClick={openResource}>
                            Open
                        </button>
                    ) : null}
                </div>
            ) : data.href ? (
                <button type="button" className="gc-flow-node-open nodrag nopan" onClick={openResource}>
                    Open
                </button>
            ) : null}
        </article>
    );
}

export const ResourceNode = memo(ResourceNodeComponent);
