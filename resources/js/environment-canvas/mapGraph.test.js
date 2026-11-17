import test from 'node:test';
import assert from 'node:assert/strict';
import { graphToFlow, mergeGraphIntoFlow } from './mapGraph.js';

const sampleGraph = {
    nodes: [
        {
            id: 'domain:1',
            kind: 'domain',
            label: 'app.test',
            status: 'idle',
            status_label: 'HTTPS',
            glyph: 'globe',
            ports: { in: false, out: true },
            x: 10,
            y: 20,
        },
        {
            id: 'service:1',
            kind: 'odoo',
            label: 'odoo-web',
            status: 'running',
            status_label: 'Running',
            icon: '/svgs/odoo.svg',
            ports: { in: true, out: true },
            x: 300,
            y: 20,
        },
    ],
    edges: [{ id: 'domain:1->service:1', from: 'domain:1', to: 'service:1', label: '443' }],
};

test('graphToFlow maps nodes and typed edges for XYFlow', () => {
    const flow = graphToFlow(sampleGraph, { canUpdate: true });

    assert.equal(flow.nodes.length, 2);
    assert.equal(flow.nodes[0].type, 'resource');
    assert.equal(flow.nodes[0].position.x, 10);
    assert.equal(flow.nodes[1].draggable, true);
    assert.equal(flow.edges.length, 1);
    assert.equal(flow.edges[0].source, 'domain:1');
    assert.equal(flow.edges[0].type, 'flow');
    assert.equal(flow.edges[0].className, 'gc-flow-edge is-http');
    assert.equal(flow.edges[0].label, undefined);
    assert.equal(flow.edges[0].animated, true);
});

test('mergeGraphIntoFlow keeps dragged positions unless replace is requested', () => {
    const flow = graphToFlow(sampleGraph, { canUpdate: true });
    flow.nodes[1].position = { x: 480, y: 160 };

    const polled = {
        ...sampleGraph,
        nodes: sampleGraph.nodes.map((node) =>
            node.id === 'service:1' ? { ...node, status: 'stopped', status_label: 'Stopped', x: 300, y: 20 } : node,
        ),
    };

    const merged = mergeGraphIntoFlow(flow.nodes, flow.edges, polled, { canUpdate: true });
    assert.equal(merged.nodes[1].position.x, 480);
    assert.equal(merged.nodes[1].data.status, 'stopped');

    const replaced = mergeGraphIntoFlow(flow.nodes, flow.edges, polled, {
        canUpdate: true,
        replacePositions: true,
    });
    assert.equal(replaced.nodes[1].position.x, 300);
});
