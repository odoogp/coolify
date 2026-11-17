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
            parent_id: 'stack-app:1',
            fqdn: 'https://app.test',
            panels: ['settings', 'config'],
            config_href: 'https://app.example.test/domains',
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
            panels: ['logs', 'monitor', 'config'],
            monitor_url: 'https://monitor.example.test',
            config_href: 'https://app.example.test/config',
            open_href: 'https://erp.example.test',
            volumes: [{ id: 'compose-vol:1:odoo-extra-addons', label: 'odoo-extra-addons', mount_path: '/mnt/extra-addons' }],
            domains: [{ id: 'domain:1', label: 'app.test', fqdn: 'https://app.test' }],
            ports: { in: true, out: true },
            x: 300,
            y: 20,
        },
        {
            id: 'compose-vol:1:odoo-extra-addons',
            kind: 'volume',
            label: 'odoo-extra-addons',
            status: 'idle',
            status_label: 'Mounted',
            parent_id: 'stack-app:1',
            mount_path: '/mnt/extra-addons',
            panels: ['settings', 'config'],
            glyph: 'disk',
            ports: { in: true, out: false },
            x: 324,
            y: 138,
        },
    ],
    edges: [
        { id: 'github:1->stack-app:1', from: 'github:1', to: 'stack-app:1', label: 'git' },
        { id: 'domain:1->stack-app:1', from: 'domain:1', to: 'stack-app:1', label: '443' },
        { id: 'stack-app:1->compose-vol:1:odoo-extra-addons', from: 'stack-app:1', to: 'compose-vol:1:odoo-extra-addons', label: 'mount' },
    ],
};

test('graphToFlow maps stack nodes, panels, and typed edges for XYFlow', () => {
    const inspectCalls = [];
    const flow = graphToFlow(sampleGraph, {
        canUpdate: true,
        onInspect: (id, panel) => inspectCalls.push([id, panel]),
    });

    assert.equal(flow.nodes.length, 4);
    assert.equal(flow.nodes[0].type, 'resource');
    assert.equal(flow.nodes[2].data.panels.includes('logs'), true);
    assert.equal(flow.nodes[2].data.panels.includes('config'), true);
    assert.equal(flow.nodes[2].data.monitorUrl, 'https://monitor.example.test');
    assert.equal(flow.nodes[2].data.configHref, 'https://app.example.test/config');
    assert.equal(flow.nodes[1].data.parentId, 'stack-app:1');
    assert.equal(flow.nodes[1].data.fqdn, 'https://app.test');
    assert.equal(flow.nodes[2].data.volumes[0].label, 'odoo-extra-addons');
    assert.equal(flow.nodes[2].data.domains[0].label, 'app.test');
    assert.equal(flow.nodes[3].data.kind, 'volume');
    assert.equal(flow.nodes[3].data.parentId, 'stack-app:1');
    assert.equal(flow.nodes[3].data.mountPath, '/mnt/extra-addons');
    assert.equal(typeof flow.nodes[2].data.onInspect, 'function');
    flow.nodes[2].data.onInspect('stack-app:1', 'logs');
    assert.deepEqual(inspectCalls[0], ['stack-app:1', 'logs']);
    assert.equal(flow.edges.length, 3);
    assert.equal(flow.edges[0].className, 'gc-flow-edge is-git');
    assert.equal(flow.edges[1].className, 'gc-flow-edge is-http');
    assert.equal(flow.edges[2].className, 'gc-flow-edge is-mount');
    assert.equal(flow.edges[0].data.removable, false);
    assert.equal(flow.edges[1].data.removable, false);
    assert.equal(flow.edges[2].data.removable, false);
});

test('graphToFlow marks user intranet and context edges as removable', () => {
    const removed = [];
    const flow = graphToFlow(
        {
            nodes: [
                { id: 'stack-app:a', kind: 'service', label: 'A', status: 'running', x: 0, y: 0 },
                { id: 'stack-app:b', kind: 'service', label: 'B', status: 'running', x: 200, y: 0 },
                { id: 'agent:1', kind: 'agent', label: 'Agent', status: 'idle', x: 100, y: 120 },
            ],
            edges: [
                { id: 'link:a->b', from: 'stack-app:a', to: 'stack-app:b', label: 'intranet' },
                { id: 'agent:1->stack-app:a', from: 'agent:1', to: 'stack-app:a', label: 'context' },
            ],
        },
        {
            canUpdate: true,
            onRemoveConnection: (from, to, edgeId) => removed.push([from, to, edgeId]),
        },
    );

    assert.equal(flow.edges[0].data.removable, true);
    assert.equal(flow.edges[0].className, 'gc-flow-edge is-intranet is-removable');
    assert.equal(flow.edges[1].data.removable, true);
    assert.equal(typeof flow.edges[0].data.onRemove, 'function');
    flow.edges[0].data.onRemove('stack-app:a', 'stack-app:b', 'link:a->b');
    assert.deepEqual(removed[0], ['stack-app:a', 'stack-app:b', 'link:a->b']);
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
