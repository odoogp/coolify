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
 * @param {{
 *   canUpdate?: boolean,
 *   onInspect?: (nodeId: string, panel: string) => void,
 *   onRemoveConnection?: (from: string, to: string, edgeId: string) => void,
 *   removeLabel?: string,
 * }} [options]
 */
export function graphToFlow(graph, options = {}) {
    const canUpdate = !!options.canUpdate;
    const onInspect = typeof options.onInspect === 'function' ? options.onInspect : null;
    const onRemoveConnection =
        typeof options.onRemoveConnection === 'function' ? options.onRemoveConnection : null;
    const removeLabel = options.removeLabel || 'Remove connection';
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
                openHref: node.open_href || null,
                configHref: node.config_href || null,
                panels: Array.isArray(node.panels) ? node.panels : [],
                monitorUrl: node.monitor_url || null,
                github: node.github || null,
                serviceUuid: node.service_uuid || null,
                container: node.container || null,
                parentId: node.parent_id || null,
                mountPath: node.mount_path || null,
                fqdn: node.fqdn || null,
                volumes: Array.isArray(node.volumes) ? node.volumes : [],
                domains: Array.isArray(node.domains) ? node.domains : [],
                meta: node.meta || null,
                icon: node.icon || null,
                glyph: node.glyph || null,
                external: !!node.external,
                ports: node.ports || { in: true, out: true },
                onInspect,
            },
        })),
        edges: edges
            .filter((edge) => byId[edge.from] && byId[edge.to])
            .map((edge) => {
                const target = byId[edge.to];
                const source = byId[edge.from];
                const kind =
                    source?.kind === 'github' || edge.label === 'git'
                        ? 'git'
                        : edge.label === 'intranet' || edge.label === 'tunnel'
                          ? 'intranet'
                          : source?.kind === 'agent' || target?.kind === 'agent' || edge.label === 'context'
                            ? 'context'
                            : edge.label === 'link'
                              ? 'intranet'
                              : target?.kind === 'volume' || edge.label === 'mount'
                                ? 'mount'
                                : target?.kind === 'database' || edge.label === 'db'
                                  ? 'db'
                                  : 'http';
                const removable = canUpdate && (kind === 'intranet' || kind === 'context');

                return {
                    id: edge.id,
                    source: edge.from,
                    target: edge.to,
                    type: 'flow',
                    animated:
                        (kind === 'http' || kind === 'intranet') &&
                        (target?.status === 'running' || target?.status === 'building'),
                    className: `gc-flow-edge is-${kind}${removable ? ' is-removable' : ''}`,
                    markerEnd: {
                        type: MarkerType.ArrowClosed,
                        width: 14,
                        height: 14,
                        color:
                            kind === 'http'
                                ? '#34d399'
                                : kind === 'intranet'
                                  ? '#2dd4bf'
                                  : kind === 'db'
                                    ? '#60a5fa'
                                    : kind === 'git'
                                      ? '#c4b5fd'
                                      : kind === 'context'
                                        ? '#f472b6'
                                        : '#94a3b8',
                    },
                    data: {
                        kind,
                        removable,
                        removeLabel,
                        onRemove: removable ? onRemoveConnection : null,
                    },
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
 * @param {{
 *   canUpdate?: boolean,
 *   replacePositions?: boolean,
 *   onInspect?: (nodeId: string, panel: string) => void,
 *   onRemoveConnection?: (from: string, to: string, edgeId: string) => void,
 *   removeLabel?: string,
 * }} [options]
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
            data: {
                ...node.data,
                onInspect: options.onInspect || node.data.onInspect,
            },
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
