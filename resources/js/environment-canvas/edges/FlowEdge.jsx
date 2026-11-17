import { BaseEdge, EdgeLabelRenderer, getBezierPath } from '@xyflow/react';
import { memo, useState } from 'react';

function FlowEdgeComponent({
    id,
    source,
    target,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    markerEnd,
    style,
    data,
    selected,
}) {
    const [hovered, setHovered] = useState(false);
    const [edgePath, labelX, labelY] = getBezierPath({
        sourceX,
        sourceY,
        targetX,
        targetY,
        sourcePosition,
        targetPosition,
        curvature: 0.35,
    });

    const kind = data?.kind || 'http';
    const removable = !!data?.removable && typeof data?.onRemove === 'function';
    const showRemove = removable && (hovered || selected);

    return (
        <>
            <g onMouseEnter={() => setHovered(true)} onMouseLeave={() => setHovered(false)}>
                <BaseEdge
                    id={id}
                    path={edgePath}
                    markerEnd={markerEnd}
                    className={`gc-flow-edge-path is-${kind}`}
                    style={style}
                />
                <path
                    d={edgePath}
                    fill="none"
                    stroke="transparent"
                    strokeWidth={28}
                    className="gc-flow-edge-hit"
                />
            </g>
            {removable && (
                <EdgeLabelRenderer>
                    <div
                        className={`gc-flow-edge-remove-wrap nodrag nopan${showRemove ? ' is-visible' : ''}`}
                        style={{
                            position: 'absolute',
                            transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
                            pointerEvents: showRemove ? 'all' : 'none',
                        }}
                        onMouseEnter={() => setHovered(true)}
                        onMouseLeave={() => setHovered(false)}
                    >
                        <button
                            type="button"
                            className="gc-flow-edge-remove"
                            title={data?.removeLabel || 'Remove connection'}
                            aria-label={data?.removeLabel || 'Remove connection'}
                            onClick={(event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                data.onRemove(source, target, id);
                            }}
                        >
                            ×
                        </button>
                    </div>
                </EdgeLabelRenderer>
            )}
        </>
    );
}

export const FlowEdge = memo(FlowEdgeComponent);
