const vertexSource = `
attribute vec2 aPosition;
void main() {
    gl_Position = vec4(aPosition, 0.0, 1.0);
}
`;

const fragmentSource = `
precision highp float;
uniform vec2 uResolution;
uniform float uTime;
uniform vec2 uMouse;
uniform float uSplit;

float hash(float n) {
    return fract(sin(n) * 43758.5453123);
}

void main() {
    vec2 uv = (gl_FragCoord.xy - 0.5 * uResolution) / min(uResolution.x, uResolution.y);
    vec2 mouse = clamp(uMouse, vec2(-1.0), vec2(1.0));
    float side = uv.x;

    uv.x += mouse.x * 0.055;
    uv.y += mouse.y * 0.035;

    float signedY = uv.y;
    vec2 tunnel = vec2(uv.x * 0.82, abs(uv.y));
    float radius = length(tunnel);
    float angle = atan(tunnel.y, tunnel.x);
    float depth = 1.0 / (radius + 0.11);
    float ripple = sin(depth * 1.7 - angle * 4.2 + uTime * 0.5 + mouse.x * 2.8);
    angle += ripple * 0.09 * (0.3 + length(mouse));
    depth += ripple * 0.1;

    float bands = abs(fract(angle * 16.0 + depth * 0.38 - uTime * 0.11) - 0.5);
    float streak = smoothstep(0.47, 0.012, bands);
    streak *= smoothstep(1.45, 0.05, radius);

    vec3 ink = vec3(0.012, 0.025, 0.06);
    vec3 tide = vec3(0.09, 0.2, 0.42);
    vec3 ice = vec3(0.8, 0.87, 0.96);
    vec3 color = mix(ink, tide, streak);
    color += ice * streak * streak * 0.5;

    float portal = smoothstep(0.3, 0.015, radius);
    color = mix(color, vec3(0.88, 0.92, 0.98), portal * 0.9);

    float spin = mouse.x * 0.28 + sin(uTime * 0.35) * 0.04;
    float cs = cos(spin);
    float sn = sin(spin);
    vec2 spun = mat2(cs, -sn, sn, cs) * vec2(uv.x, signedY);
    vec2 rack = vec2(spun.x, abs(spun.y) - (0.05 + mouse.y * 0.02));
    vec2 bounds = abs(rack) - vec2(0.058, 0.09);
    float server = smoothstep(0.007, 0.0, max(bounds.x, bounds.y));
    server *= smoothstep(0.36, 0.1, radius);
    float rim = server * (1.0 - smoothstep(0.0, 0.014, max(bounds.x + 0.012, bounds.y + 0.012)));
    float slots = smoothstep(0.02, 0.0, abs(fract(rack.y * 16.0) - 0.5) - 0.32);
    float led = server * slots * step(0.028, rack.x) * step(rack.x, 0.05);
    led *= step(0.7, hash(floor(rack.y * 16.0) + floor(uTime * 2.2)));

    color = mix(color, vec3(0.06, 0.08, 0.11), server * 0.96);
    color += vec3(0.62, 0.78, 1.0) * rim;
    color += vec3(0.35, 0.9, 1.0) * led;
    color = mix(color, color * vec3(0.7, 0.76, 0.84), step(signedY, 0.0) * server);

    if (uSplit > 0.5) {
        float lightSide = smoothstep(0.06, -0.22, side);
        vec3 paper = mix(vec3(0.94, 0.96, 0.99), vec3(0.75, 0.82, 0.92), streak);
        color = mix(color, paper, lightSide);
        float orbit = abs(length(vec2(side - 0.42, signedY)) - 0.34);
        color += ice * (1.0 - lightSide) * smoothstep(0.012, 0.0, orbit) * 0.65;
    }

    gl_FragColor = vec4(color, 1.0);
}
`;

function compile(gl, type, source) {
    const shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    return shader;
}

export function initializeFlowTunnel() {
    window.Alpine.data('flowTunnel', (config = {}) => ({
        split: Boolean(config.split),
        frame: 0,
        gl: null,
        program: null,
        mouse: { x: 0, y: 0 },
        target: { x: 0, y: 0 },
        start: 0,
        reduced: false,
        resizeObserver: null,
        onMove: null,
        onVisibility: null,
        init() {
            const canvas = this.$refs.canvas;
            const gl = canvas.getContext('webgl', {
                alpha: false,
                antialias: false,
                depth: false,
                stencil: false,
                powerPreference: 'high-performance',
            });

            if (! gl) {
                return;
            }

            this.gl = gl;
            this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.start = performance.now();

            const vertex = compile(gl, gl.VERTEX_SHADER, vertexSource);
            const fragment = compile(gl, gl.FRAGMENT_SHADER, fragmentSource);
            const program = gl.createProgram();
            gl.attachShader(program, vertex);
            gl.attachShader(program, fragment);
            gl.linkProgram(program);
            gl.deleteShader(vertex);
            gl.deleteShader(fragment);

            if (! gl.getProgramParameter(program, gl.LINK_STATUS)) {
                gl.deleteProgram(program);

                return;
            }

            this.program = program;
            gl.useProgram(program);

            const buffer = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
            gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
            const position = gl.getAttribLocation(program, 'aPosition');
            gl.enableVertexAttribArray(position);
            gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0);

            this.resolution = gl.getUniformLocation(program, 'uResolution');
            this.time = gl.getUniformLocation(program, 'uTime');
            this.mouseUniform = gl.getUniformLocation(program, 'uMouse');
            this.splitUniform = gl.getUniformLocation(program, 'uSplit');

            this.onMove = (event) => {
                this.target.x = (event.clientX / window.innerWidth) * 2 - 1;
                this.target.y = (event.clientY / window.innerHeight) * -2 + 1;
            };
            this.onVisibility = () => {
                cancelAnimationFrame(this.frame);
                if (document.hidden || this.reduced) {
                    return;
                }

                this.frame = requestAnimationFrame((now) => this.draw(now));
            };

            window.addEventListener('pointermove', this.onMove, { passive: true });
            document.addEventListener('visibilitychange', this.onVisibility);
            this.resizeObserver = new ResizeObserver(() => this.resize());
            this.resizeObserver.observe(canvas);
            this.resize();
            this.draw(performance.now());
        },
        resize() {
            const canvas = this.$refs.canvas;
            const gl = this.gl;
            if (! canvas || ! gl) {
                return;
            }

            const width = Math.max(1, canvas.clientWidth || window.innerWidth);
            const height = Math.max(1, canvas.clientHeight || window.innerHeight);
            const longest = Math.max(width, height);
            const pixelRatio = longest > 1920
                ? 1920 / longest
                : Math.min(window.devicePixelRatio || 1, 1.5);
            const nextWidth = Math.round(width * pixelRatio);
            const nextHeight = Math.round(height * pixelRatio);

            if (canvas.width !== nextWidth || canvas.height !== nextHeight) {
                canvas.width = nextWidth;
                canvas.height = nextHeight;
                gl.viewport(0, 0, nextWidth, nextHeight);
            }
        },
        draw(now) {
            const gl = this.gl;
            if (! gl || ! this.program) {
                return;
            }

            this.mouse.x += (this.target.x - this.mouse.x) * 0.06;
            this.mouse.y += (this.target.y - this.mouse.y) * 0.06;
            gl.useProgram(this.program);
            gl.uniform2f(this.resolution, gl.drawingBufferWidth, gl.drawingBufferHeight);
            gl.uniform1f(this.time, (now - this.start) / 1000);
            gl.uniform2f(this.mouseUniform, this.mouse.x, this.mouse.y);
            gl.uniform1f(this.splitUniform, this.split ? 1 : 0);
            gl.drawArrays(gl.TRIANGLES, 0, 3);

            if (! this.reduced && ! document.hidden) {
                this.frame = requestAnimationFrame((next) => this.draw(next));
            }
        },
        destroy() {
            cancelAnimationFrame(this.frame);
            this.resizeObserver?.disconnect();
            if (this.onMove) {
                window.removeEventListener('pointermove', this.onMove);
            }
            if (this.onVisibility) {
                document.removeEventListener('visibilitychange', this.onVisibility);
            }
            this.gl?.getExtension('WEBGL_lose_context')?.loseContext();
            this.gl = null;
            this.program = null;
        },
    }));
}
