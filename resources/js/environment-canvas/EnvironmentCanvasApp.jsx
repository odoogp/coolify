import {
    Background,
    BackgroundVariant,
    Controls,
    MiniMap,
    ReactFlow,
    ReactFlowProvider,
    SelectionMode,
    useEdgesState,
    useNodesState,
    useReactFlow,
} from '@xyflow/react';
import { useCallback, useEffect, useMemo, useRef } from 'react';
import { FlowEdge } from './edges/FlowEdge';
import { graphToFlow, mergeGraphIntoFlow } from './mapGraph';
import { ResourceNode } from './nodes/ResourceNode';

const nodeTypes = { resource: ResourceNode };
const edgeTypes = { flow: FlowEdge };

function FitController({ bootKey }) {
    const { fitView } = useReactFlow();

    useEffect(() => {
        const run = () => fitView({ padding: 0.22, duration: 280, maxZoom: 1.05 });
        const timer = window.setTimeout(run, 40);
        const onFit = () => window.setTimeout(run, 30);
        window.addEventListener('gc-canvas:fit', onFit);

        const host = document.getElementById('gc-environment-canvas-root');
        const observer = host
            ? new ResizeObserver(() => {
                  window.requestAnimationFrame(run);
              })
            : null;
        if (host && observer) {
            observer.observe(host);
        }

        return () => {
            window.clearTimeout(timer);
            window.removeEventListener('gc-canvas:fit', onFit);
            observer?.disconnect();
        };
    }, [bootKey, fitView]);

    return null;
}

function CanvasInner({ graph, canUpdate, savePosition, bootKey }) {
    const initial = useMemo(() => graphToFlow(graph, { canUpdate }), []);
    const [nodes, setNodes, onNodesChange] = useNodesState(initial.nodes);
    const [edges, setEdges, onEdgesChange] = useEdgesState(initial.edges);
    const saveTimer = useRef(null);

    useEffect(() => {
        const onUpdate = (event) => {
            const detail = event.detail || {};
            const nextGraph = detail.graph ?? detail;
            if (!nextGraph?.nodes) {
                return;
            }
            const replacePositions = !!detail.replacePositions;
            setNodes((currentNodes) => {
                const merged = mergeGraphIntoFlow(currentNodes, [], nextGraph, {
                    canUpdate,
                    replacePositions,
                });
                setEdges(merged.edges);
                return merged.nodes;
            });
            if (replacePositions) {
                window.dispatchEvent(new CustomEvent('gc-canvas:fit'));
            }
        };

        window.addEventListener('gc-canvas:update', onUpdate);
        return () => window.removeEventListener('gc-canvas:update', onUpdate);
    }, [canUpdate, setEdges, setNodes]);

    const persistPosition = useCallback(
        (node) => {
            if (!canUpdate || !savePosition || !node) {
                return;
            }
            window.clearTimeout(saveTimer.current);
            saveTimer.current = window.setTimeout(() => {
                savePosition(node.id, node.position.x, node.position.y);
            }, 120);
        },
        [canUpdate, savePosition],
    );

    const onNodeDragStop = useCallback(
        (_event, node) => {
            persistPosition(node);
        },
        [persistPosition],
    );

    const onSelectionDragStop = useCallback(
        (_event, selectedNodes) => {
            (selectedNodes || []).forEach((node) => persistPosition(node));
        },
        [persistPosition],
    );

    const minimapNodeColor = useCallback((node) => {
        switch (node.data?.kind) {
            case 'domain':
                return '#34d399';
            case 'odoo':
            case 'service':
            case 'application':
                return '#a78bfa';
            case 'database':
                return '#60a5fa';
            case 'volume':
                return '#38bdf8';
            default:
                return '#94a3b8';
        }
    }, []);

    return (
        <ReactFlow
            nodes={nodes}
            edges={edges}
            onNodesChange={onNodesChange}
            onEdgesChange={onEdgesChange}
            onNodeDragStop={onNodeDragStop}
            onSelectionDragStop={onSelectionDragStop}
            nodeTypes={nodeTypes}
            edgeTypes={edgeTypes}
            nodesDraggable={canUpdate}
            nodesConnectable={false}
            elementsSelectable
            selectionOnDrag={false}
            panOnDrag
            panOnScroll
            zoomOnScroll
            zoomOnPinch
            multiSelectionKeyCode="Shift"
            selectionMode={SelectionMode.Partial}
            onlyRenderVisibleElements={false}
            colorMode="dark"
            fitView
            fitViewOptions={{ padding: 0.22, maxZoom: 1.05 }}
            minZoom={0.25}
            maxZoom={1.75}
            proOptions={{ hideAttribution: true }}
            defaultEdgeOptions={{
                type: 'flow',
                interactionWidth: 28,
            }}
        >
            <FitController bootKey={bootKey} />
            <Background
                id="gc-flow-dots"
                variant={BackgroundVariant.Dots}
                gap={18}
                size={1.4}
                color="color-mix(in oklab, var(--dash-dim) 45%, transparent)"
            />
            <Controls showInteractive={false} position="top-right" />
            <MiniMap
                pannable
                zoomable
                nodeColor={minimapNodeColor}
                maskColor="rgb(2 6 23 / 0.55)"
                position="bottom-right"
            />
        </ReactFlow>
    );
}

export function EnvironmentCanvasApp(props) {
    return (
        <ReactFlowProvider>
            <CanvasInner {...props} />
        </ReactFlowProvider>
    );
}
