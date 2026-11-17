import { BaseEdge, getBezierPath } from '@xyflow/react';
import { memo } from 'react';

function FlowEdgeComponent({
    id,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    markerEnd,
    style,
    data,
}) {
    const [edgePath] = getBezierPath({
        sourceX,
        sourceY,
        targetX,
        targetY,
        sourcePosition,
        targetPosition,
        curvature: 0.35,
    });

    const kind = data?.kind || 'http';

    return (
        <BaseEdge
            id={id}
            path={edgePath}
            markerEnd={markerEnd}
            className={`gc-flow-edge-path is-${kind}`}
            style={style}
        />
    );
}

export const FlowEdge = memo(FlowEdgeComponent);
