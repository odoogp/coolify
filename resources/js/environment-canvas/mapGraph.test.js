import test from 'node:test';
import assert from 'node:assert/strict';
import { graphToFlow, mergeGraphIntoFlow } from './mapGraph.js';

const sampleGraph = {
    nodes: [
        {
            id: 'github:1',
            kind: 'github',
            label: 'GitHub',
            status: 'idle',
            status_label: 'Not linked',
            glyph: 'github',
            panels: ['github'],
            ports: { in: false, out: true },
            x: 10,
            y: 20,
        },
        {
            id: 'domain:1',
            kind: 'domain',
            label: 'app.test',
            status: 'idle',
            status_label: 'HTTPS',
            glyph: 'globe',
            ports: { in: false, out: true },
            x: 10,
            y: 180,
        },
        {
            id: 'stack-app:1',
            kind: 'odoo',
            label: 'odoo',
            status: 'running',
            status_label: 'Running',
            icon: '/svgs/odoo.svg',
            panels: ['logs', 'monitor'],
            monitor_url: 'https://monitor.example.test',
            open_href: 'https://erp.example.test',
            ports: { in: true, out: true },
            x: 300,
            y: 20,
        },
    ],
    edges: [
        { id: 'github:1->stack-app:1', from: 'github:1', to: 'stack-app:1', label: 'git' },
        { id: 'domain:1->stack-app:1', from: 'domain:1', to: 'stack-app:1', label: '443' },
    ],
};

test('graphToFlow maps stack nodes, panels, and typed edges for XYFlow', () => {
    const inspectCalls = [];
    const flow = graphToFlow(sampleGraph, {
        canUpdate: true,
        onInspect: (id, panel) => inspectCalls.push([id, panel]),
    });

    assert.equal(flow.nodes.length, 3);
    assert.equal(flow.nodes[0].type, 'resource');
    assert.equal(flow.nodes[2].data.panels.includes('logs'), true);
    assert.equal(flow.nodes[2].data.monitorUrl, 'https://monitor.example.test');
    assert.equal(typeof flow.nodes[2].data.onInspect, 'function');
    flow.nodes[2].data.onInspect('stack-app:1', 'logs');
    assert.deepEqual(inspectCalls[0], ['stack-app:1', 'logs']);
    assert.equal(flow.edges.length, 2);
    assert.equal(flow.edges[0].className, 'gc-flow-edge is-git');
    assert.equal(flow.edges[1].className, 'gc-flow-edge is-http');
});

test('mergeGraphIntoFlow keeps dragged positions unless replace is requested', () => {
    const flow = graphToFlow(sampleGraph, { canUpdate: true });
    flow.nodes[2].position = { x: 480, y: 160 };

    const polled = {
        ...sampleGraph,
        nodes: sampleGraph.nodes.map((node) =>
            node.id === 'stack-app:1' ? { ...node, status: 'stopped', status_label: 'Stopped', x: 300, y: 20 } : node,
        ),
    };

    const merged = mergeGraphIntoFlow(flow.nodes, flow.edges, polled, { canUpdate: true });
    assert.equal(merged.nodes[2].position.x, 480);
    assert.equal(merged.nodes[2].data.status, 'stopped');

    const replaced = mergeGraphIntoFlow(flow.nodes, flow.edges, polled, {
        canUpdate: true,
        replacePositions: true,
    });
    assert.equal(replaced.nodes[2].position.x, 300);
});
