@props([
    'split' => false,
])

<div class="flow-tunnel absolute inset-0 h-full w-full -z-10 pointer-events-none" x-data="flowTunnel(@js(['split' => (bool) $split]))"
    aria-hidden="true">
    <canvas x-ref="canvas" class="block h-full w-full"></canvas>
</div>
