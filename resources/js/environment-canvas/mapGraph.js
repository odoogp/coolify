import { MarkerType } from '@xyflow/react';

/**
 * @typedef {object} CanvasNode
 * @property {string} id
 * @property {string} kind
 * @property {string} label
 * @property {string} status
 * @property {string} [status_label]
 * @property {string|null} [href]
 * @property {string|null} [meta]
 * @property {string|null} [icon]
 * @property {string|null} [glyph]
 * @property {boolean} [external]
 * @property {{in?: boolean, out?: boolean}} [ports]
 * @property {number} x
 * @property {number} y
 */

/**
 * @typedef {object} CanvasEdge
 * @property {string} id
 * @property {string} from
 * @property {string} to
 * @property {string} [label]
 */

/**
 * @typedef {object} CanvasGraph
 * @property {CanvasNode[]} nodes
 * @property {CanvasEdge[]} edges
 * @property {object} [meta]
 */

/**
 * @param {CanvasGraph|null|undefined} graph
 * @param {{ canUpdate?: boolean }} [options]
 */
export function graphToFlow(graph, options = {}) {
    const canUpdate = !!options.canUpdate;
    const nodes = Array.isArray(graph?.nodes) ? graph.nodes : [];
    const edges = Array.isArray(graph?.edges) ? graph.edges : [];
    const byId = Object.fromEntries(nodes.map((node) => [node.id, node]));

    return {
        nodes: nodes.map((node) => ({
            id: node.id,
            type: 'resource',
            position: { x: Number(node.x) || 0, y: Number(node.y) || 0 },
            draggable: canUpdate,
            selectable: true,
            data: {
                kind: node.kind,
                label: node.label,
                status: node.status,
                statusLabel: node.status_label || node.status,
                href: node.href || null,
                logsHref: node.logs_href || null,
                monitorHref: node.monitor_href || null,
                meta: node.meta || null,
                icon: node.icon || null,
                glyph: node.glyph || null,
                external: !!node.external,
                ports: node.ports || { in: true, out: true },
            },
        })),
        edges: edges
            .filter((edge) => byId[edge.from] && byId[edge.to])
            .map((edge) => {
                const target = byId[edge.to];
                const kind =
                    target?.kind === 'volume' || edge.label === 'mount'
                        ? 'mount'
                        : target?.kind === 'database' || edge.label === 'db'
                          ? 'db'
                          : 'http';

                return {
                    id: edge.id,
                    source: edge.from,
                    target: edge.to,
                    // No edge labels — XYFlow's default label chips look noisy vs Railway/n8n.
                    type: 'flow',
                    animated: kind === 'http' && (target?.status === 'running' || target?.status === 'building'),
                    className: `gc-flow-edge is-${kind}`,
                    markerEnd: {
                        type: MarkerType.ArrowClosed,
                        width: 14,
                        height: 14,
                        color: kind === 'http' ? '#34d399' : kind === 'db' ? '#60a5fa' : '#94a3b8',
                    },
                    data: { kind },
                };
            }),
    };
}

/**
 * Merge a polled graph into the live React Flow state without jumping positions.
 *
 * @param {import('@xyflow/react').Node[]} currentNodes
 * @param {import('@xyflow/react').Edge[]} currentEdges
 * @param {CanvasGraph} graph
 * @param {{ canUpdate?: boolean, replacePositions?: boolean }} [options]
 */
export function mergeGraphIntoFlow(currentNodes, currentEdges, graph, options = {}) {
    const mapped = graphToFlow(graph, options);
    const currentById = Object.fromEntries(currentNodes.map((node) => [node.id, node]));

    const nodes = mapped.nodes.map((node) => {
        const previous = currentById[node.id];
        if (!previous || options.replacePositions) {
            return node;
        }

        return {
            ...node,
            position: previous.position,
            selected: previous.selected,
        };
    });

    const edgeIds = new Set(mapped.edges.map((edge) => edge.id));
    const selectedEdgeIds = new Set(
        currentEdges.filter((edge) => edge.selected && edgeIds.has(edge.id)).map((edge) => edge.id),
    );

    const edges = mapped.edges.map((edge) => ({
        ...edge,
        selected: selectedEdgeIds.has(edge.id),
    }));

    return { nodes, edges };
}
