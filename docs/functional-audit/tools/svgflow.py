"""Tiny flowchart engine that renders standard-symbol diagrams as inline SVG.

Coordinates are grid cells (col, row); the diagram converts them to pixels.
Shapes: oval (start/end), rect (process), diamond (decision), para (input/output),
cyl (storage), doc (logs/reports), note (free text), group (lane/background).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from html import escape

FONT = "-apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Helvetica Neue', Arial, sans-serif"
MONO = "'SF Mono', Menlo, Consolas, monospace"

# fill, stroke, text
KINDS = {
    "coolify": ("#E7EFFD", "#1D4ED8", "#0B2A6F"),
    "own": ("#F1E8FD", "#7C3AED", "#3B0F80"),
    "ext": ("#E3F5EA", "#059669", "#064E3B"),
    "partial": ("#FFF2DF", "#D97706", "#6B3A02"),
    "risk": ("#FDE6E6", "#DC2626", "#7F1212"),
    "proposal": ("#F1F2F4", "#6B7280", "#1F2937"),
    "neutral": ("#FFFFFF", "#334155", "#0F172A"),
}

KIND_LABEL = {
    "coolify": "Original Coolify",
    "own": "Personalización propia",
    "ext": "Integración externa",
    "partial": "Parcial / con limitaciones",
    "risk": "Error, bloqueo o riesgo",
    "proposal": "Propuesta nueva",
    "neutral": "Inicio / fin",
}


def wrap(text: str, width_px: float, size: float) -> list[str]:
    per_line = max(4, int(width_px / (size * 0.55)))
    lines: list[str] = []
    for paragraph in text.split("\n"):
        words, line = [], ""
        for word in paragraph.split(" "):
            while len(word) > per_line:
                cut = max((word.rfind(ch, 0, per_line) for ch in "_/\\:.-("), default=-1)
                cut = cut + 1 if cut > 2 else per_line
                words.append(word[:cut])
                word = word[cut:]
            words.append(word)
        for word in words:
            candidate = f"{line} {word}".strip()
            if len(candidate) <= per_line or not line:
                line = candidate
            else:
                lines.append(line)
                line = word
        lines.append(line)
    return lines


@dataclass
class Node:
    id: str
    label: str
    col: float
    row: float
    shape: str = "rect"
    kind: str = "coolify"
    w: float | None = None
    h: float | None = None
    ref: str = ""
    size: float = 12.5


@dataclass
class Edge:
    a: str
    b: str
    label: str = ""
    route: str = "auto"
    style: str = "solid"  # solid | dash | err
    via: list[tuple[float, float]] = field(default_factory=list)
    out: str | None = None  # force exit side: l r t b
    inn: str | None = None  # force entry side


@dataclass
class Group:
    label: str
    col: float
    row: float
    cols: float
    rows: float
    kind: str = "neutral"
    dashed: bool = False


class Diagram:
    _count = 0

    def __init__(self, width: int, height: int, cw: float = 150, rh: float = 92, ox: float = 20, oy: float = 20,
                 node_w: float = 132, node_h: float = 52):
        self.width, self.height = width, height
        self.cw, self.rh, self.ox, self.oy = cw, rh, ox, oy
        self.node_w, self.node_h = node_w, node_h
        self.nodes: dict[str, Node] = {}
        self.edges: list[Edge] = []
        self.groups: list[Group] = []
        Diagram._count += 1
        self.uid = f"d{Diagram._count}"

    # building -------------------------------------------------------------
    def n(self, id: str, label: str, col: float, row: float, shape: str = "rect", kind: str = "coolify", **kw) -> Node:
        node = Node(id, label, col, row, shape, kind, **kw)
        self.nodes[id] = node
        return node

    def e(self, a: str, b: str, label: str = "", **kw) -> Edge:
        edge = Edge(a, b, label, **kw)
        self.edges.append(edge)
        return edge

    def chain(self, *ids: str, **kw) -> None:
        for a, b in zip(ids, ids[1:]):
            self.e(a, b, **kw)

    def g(self, label: str, col: float, row: float, cols: float, rows: float, kind: str = "neutral", dashed: bool = False) -> None:
        self.groups.append(Group(label, col, row, cols, rows, kind, dashed))

    # geometry -------------------------------------------------------------
    def px(self, col: float, row: float) -> tuple[float, float]:
        return self.ox + col * self.cw + self.cw / 2, self.oy + row * self.rh + self.rh / 2

    def box(self, node: Node) -> tuple[float, float, float, float]:
        cx, cy = self.px(node.col, node.row)
        w = (node.w or 1) * self.node_w if node.w and node.w < 10 else (node.w or self.node_w)
        h = (node.h or 1) * self.node_h if node.h and node.h < 10 else (node.h or self.node_h)
        if node.shape == "diamond" and not node.w:
            w *= 1.12
        if node.shape == "diamond" and not node.h:
            h *= 1.35
        inner_w = w * (0.6 if node.shape == "diamond" else 0.88)
        text_h = len(wrap(node.label, inner_w, node.size)) * node.size * 1.18
        if node.ref:
            text_h += len(wrap(node.ref, inner_w, 9.5)) * 11.5 + 2
        need = text_h * (1.9 if node.shape == "diamond" else 1) + (26 if node.shape in ("cyl", "doc") else 16)
        return cx, cy, w, max(h, need)

    def port(self, node: Node, side: str) -> tuple[float, float]:
        cx, cy, w, h = self.box(node)
        skew = 7 if node.shape == "para" else 0
        return {
            "l": (cx - w / 2 + skew, cy),
            "r": (cx + w / 2 - skew, cy),
            "t": (cx, cy - h / 2),
            "b": (cx, cy + h / 2 - (5 if node.shape == "doc" else 0)),
        }[side]

    @staticmethod
    def facing(ax: float, ay: float, bx: float, by: float, horizontal: bool) -> str:
        if horizontal:
            return "r" if bx >= ax else "l"
        return "b" if by >= ay else "t"

    def route(self, edge: Edge) -> list[tuple[float, float]]:
        a, b = self.nodes[edge.a], self.nodes[edge.b]
        ax, ay, _, _ = self.box(a)
        bx, by, _, _ = self.box(b)

        if edge.via:
            pts = [self.px(c, r) for c, r in edge.via]
            fx, fy = pts[0]
            lx, ly = pts[-1]
            out = edge.out or (self.facing(ax, ay, fx, fy, abs(fx - ax) > abs(fy - ay)))
            inn = edge.inn or (self.facing(bx, by, lx, ly, abs(lx - bx) > abs(ly - by)))
            start, end = self.port(a, out), self.port(b, inn)
            path = [start]
            first = pts[0]
            path.append((first[0], start[1]) if out in "lr" else (start[0], first[1]))
            path.extend(pts)
            last = pts[-1]
            path.append((end[0], last[1]) if inn in "tb" else (last[0], end[1]))
            path.append(end)
            return path

        mode = edge.route
        if mode == "auto":
            if abs(ay - by) < 2:
                mode = "h"
            elif abs(ax - bx) < 2:
                mode = "v"
            else:
                mode = "hvh" if abs(bx - ax) > abs(by - ay) else "vhv"

        if mode == "h":
            s = self.port(a, edge.out or self.facing(ax, ay, bx, by, True))
            t = self.port(b, edge.inn or self.facing(bx, by, ax, ay, True))
            return [s, (t[0], s[1])] if abs(s[1] - t[1]) < 2 else [s, ((s[0] + t[0]) / 2, s[1]), ((s[0] + t[0]) / 2, t[1]), t]
        if mode == "v":
            s = self.port(a, edge.out or self.facing(ax, ay, bx, by, False))
            t = self.port(b, edge.inn or self.facing(bx, by, ax, ay, False))
            return [s, (s[0], t[1])] if abs(s[0] - t[0]) < 2 else [s, (s[0], (s[1] + t[1]) / 2), (t[0], (s[1] + t[1]) / 2), t]
        if mode == "hv":
            s = self.port(a, edge.out or self.facing(ax, ay, bx, by, True))
            t = self.port(b, edge.inn or self.facing(bx, by, ax, ay, False))
            return [s, (t[0], s[1]), t]
        if mode == "vh":
            s = self.port(a, edge.out or self.facing(ax, ay, bx, by, False))
            t = self.port(b, edge.inn or self.facing(bx, by, ax, ay, True))
            return [s, (s[0], t[1]), t]
        if mode == "hvh":
            s = self.port(a, edge.out or self.facing(ax, ay, bx, by, True))
            t = self.port(b, edge.inn or self.facing(bx, by, ax, ay, True))
            mx = (s[0] + t[0]) / 2
            return [s, (mx, s[1]), (mx, t[1]), t]
        # vhv
        s = self.port(a, edge.out or self.facing(ax, ay, bx, by, False))
        t = self.port(b, edge.inn or self.facing(bx, by, ax, ay, False))
        my = (s[1] + t[1]) / 2
        return [s, (s[0], my), (t[0], my), t]

    # rendering ------------------------------------------------------------
    def shape_svg(self, node: Node) -> str:
        cx, cy, w, h = self.box(node)
        fill, stroke, ink = KINDS[node.kind]
        dash = ' stroke-dasharray="5 4"' if node.kind == "proposal" else ""
        common = f'fill="{fill}" stroke="{stroke}" stroke-width="1.6"{dash}'
        x, y = cx - w / 2, cy - h / 2
        s = node.shape
        if s == "oval":
            body = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{h / 2}" {common}/>'
        elif s == "diamond":
            body = f'<polygon points="{cx},{y} {x + w},{cy} {cx},{y + h} {x},{cy}" {common}/>'
        elif s == "para":
            k = 12
            body = f'<polygon points="{x + k},{y} {x + w},{y} {x + w - k},{y + h} {x},{y + h}" {common}/>'
        elif s == "cyl":
            e = 7
            body = (f'<path d="M{x},{y + e} L{x},{y + h - e} A{w / 2},{e} 0 0 0 {x + w},{y + h - e} L{x + w},{y + e} '
                    f'A{w / 2},{e} 0 0 0 {x},{y + e} Z" {common}/>'
                    f'<path d="M{x},{y + e} A{w / 2},{e} 0 0 0 {x + w},{y + e}" fill="none" stroke="{stroke}" stroke-width="1.6"/>')
        elif s == "doc":
            wave = 7
            body = (f'<path d="M{x},{y} L{x + w},{y} L{x + w},{y + h - wave} '
                    f'C{x + w * 0.75},{y + h - wave * 2.4} {x + w * 0.25},{y + h + wave * 0.6} {x},{y + h - wave} Z" {common}/>')
        elif s == "note":
            body = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="4" fill="#FFFBEA" stroke="#CBB26A" stroke-width="1"/>'
            ink = "#4A3B0A"
        else:
            body = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="7" {common}/>'

        inner_w = w * (0.6 if s == "diamond" else 0.88)
        size = node.size
        lines = wrap(node.label, inner_w, size)
        ref_lines = wrap(node.ref, inner_w, 9.5) if node.ref else []
        line_h, ref_h = size * 1.18, 11.5
        total = len(lines) * line_h + len(ref_lines) * ref_h
        ty = cy - total / 2 + size * 0.86 - (3 if s == "doc" else 0)
        text = []
        for i, line in enumerate(lines):
            text.append(f'<text x="{cx}" y="{ty + i * line_h:.1f}" font-size="{size}" font-weight="600" fill="{ink}" '
                        f'text-anchor="middle" font-family="{FONT}">{escape(line)}</text>')
        ry = ty + len(lines) * line_h - size * 0.86 + 9.5
        for i, line in enumerate(ref_lines):
            text.append(f'<text x="{cx}" y="{ry + i * ref_h:.1f}" font-size="9.5" fill="{stroke}" text-anchor="middle" '
                        f'font-family="{MONO}">{escape(line)}</text>')
        return body + "".join(text)

    def group_svg(self, g: Group) -> str:
        x = self.ox + g.col * self.cw
        y = self.oy + g.row * self.rh
        w, h = g.cols * self.cw, g.rows * self.rh
        fill, stroke, ink = KINDS[g.kind]
        bg = "#FAFBFC" if g.kind == "neutral" else fill
        dash = ' stroke-dasharray="6 5"' if g.dashed or g.kind == "proposal" else ""
        return (f'<rect x="{x + 4}" y="{y + 4}" width="{w - 8}" height="{h - 8}" rx="14" fill="{bg}" fill-opacity="0.55" '
                f'stroke="{stroke}" stroke-opacity="0.45" stroke-width="1.2"{dash}/>'
                f'<text x="{x + 18}" y="{y + 24}" font-size="12" font-weight="700" fill="{ink}" fill-opacity="0.85" '
                f'font-family="{FONT}">{escape(g.label)}</text>')

    def edge_svg(self, edge: Edge) -> str:
        pts = self.route(edge)
        color = {"err": "#DC2626", "dash": "#64748B"}.get(edge.style, "#334155")
        marker = self.uid + {"err": "arrow-err", "dash": "arrow-dash"}.get(edge.style, "arrow")
        dash = ' stroke-dasharray="6 4"' if edge.style in ("dash", "err") else ""
        d = "M" + " L".join(f"{x:.1f},{y:.1f}" for x, y in pts)
        out = [f'<path d="{d}" fill="none" stroke="{color}" stroke-width="1.5"{dash} marker-end="url(#{marker})" stroke-linejoin="round"/>']
        return "".join(out)

    def label_box(self, edge: Edge) -> tuple[float, float, float, float]:
        pts = self.route(edge)
        seg = max(range(len(pts) - 1), key=lambda i: abs(pts[i + 1][0] - pts[i][0]) + abs(pts[i + 1][1] - pts[i][1]))
        (x1, y1), (x2, y2) = pts[seg], pts[seg + 1]
        lines = wrap(edge.label, 120, 10.5)
        return (x1 + x2) / 2, (y1 + y2) / 2, max(len(line) for line in lines) * 5.9 + 12, len(lines) * 12.5 + 5

    def label_svg(self, edge: Edge) -> str:
        out: list[str] = []
        pts = self.route(edge)
        color = {"err": "#DC2626", "dash": "#64748B"}.get(edge.style, "#334155")
        if edge.label:
            seg = max(range(len(pts) - 1), key=lambda i: abs(pts[i + 1][0] - pts[i][0]) + abs(pts[i + 1][1] - pts[i][1]))
            (x1, y1), (x2, y2) = pts[seg], pts[seg + 1]
            mx, my = (x1 + x2) / 2, (y1 + y2) / 2
            lines = wrap(edge.label, 120, 10.5)
            tw = max(len(line) for line in lines) * 5.9 + 12
            th = len(lines) * 12.5 + 5
            out.append(f'<rect x="{mx - tw / 2:.1f}" y="{my - th / 2:.1f}" width="{tw:.1f}" height="{th:.1f}" rx="4" fill="#FFFFFF" '
                       f'stroke="{color}" stroke-opacity="0.25"/>')
            for i, line in enumerate(lines):
                out.append(f'<text x="{mx:.1f}" y="{my - th / 2 + 12 + i * 12.5:.1f}" font-size="10.5" fill="{color}" '
                           f'text-anchor="middle" font-family="{FONT}" font-weight="500">{escape(line)}</text>')
        return "".join(out)

    def svg(self) -> str:
        defs = "".join(
            f'<marker id="{self.uid}{mid}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">'
            f'<path d="M0,0 L10,5 L0,10 z" fill="{color}"/></marker>'
            for mid, color in (("arrow", "#334155"), ("arrow-err", "#DC2626"), ("arrow-dash", "#64748B"))
        )
        xs, ys = [], []
        for node in self.nodes.values():
            cx, cy, w, h = self.box(node)
            xs += [cx - w / 2, cx + w / 2]
            ys += [cy - h / 2, cy + h / 2]
        for g in self.groups:
            xs += [self.ox + g.col * self.cw, self.ox + (g.col + g.cols) * self.cw]
            ys += [self.oy + g.row * self.rh, self.oy + (g.row + g.rows) * self.rh]
        for e in self.edges:
            for x, y in self.route(e):
                xs.append(x)
                ys.append(y)
            if e.label:
                mx, my, tw, th = self.label_box(e)
                xs += [mx - tw / 2, mx + tw / 2]
                ys += [my - th / 2, my + th / 2]
        pad = 16
        x0, y0 = min(xs) - pad, min(ys) - pad
        vw, vh = max(xs) - x0 + pad, max(ys) - y0 + pad
        parts = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{x0:.0f} {y0:.0f} {vw:.0f} {vh:.0f}" '
                 f'preserveAspectRatio="xMidYMid meet"><defs>{defs}</defs>']
        parts += [self.group_svg(g) for g in self.groups]
        parts += [self.edge_svg(e) for e in self.edges]
        parts += [self.shape_svg(n) for n in self.nodes.values()]
        parts += [self.label_svg(e) for e in self.edges]
        parts.append("</svg>")
        return "".join(parts)


def legend_svg() -> str:
    """Symbol and colour legend used on the legend page and in each diagram footer."""
    d = Diagram(1480, 330, cw=185, rh=100, ox=0, oy=10, node_w=150, node_h=50)
    shapes = [("oval", "Inicio / fin"), ("rect", "Proceso o acción"), ("diamond", "Decisión"),
              ("para", "Entrada / salida"), ("cyl", "Almacenamiento"), ("doc", "Logs / reporte")]
    for i, (shape, label) in enumerate(shapes):
        d.n(f"s{i}", label, i, 0, shape, "neutral")
    d.n("a1", "Paso A", 6.15, 0, "rect", "neutral", w=70)
    d.n("a2", "Paso B", 7.05, 0, "rect", "neutral", w=70)
    d.e("a1", "a2")
    for i, kind in enumerate(["coolify", "own", "ext", "partial", "risk", "proposal"]):
        d.n(f"k{i}", KIND_LABEL[kind], i, 1.5, "rect", kind)
    d.n("e1", "Flujo normal", 6.15, 1.5, "rect", "neutral", w=70, size=10.5)
    d.n("e2", "Error", 7.05, 1.5, "rect", "risk", w=70, size=10.5)
    d.e("e1", "e2", style="err")
    d.n("p1", "Propuesta", 6.15, 2.6, "rect", "proposal", w=70, size=10.5)
    d.n("p2", "Opcional", 7.05, 2.6, "rect", "neutral", w=70, size=10.5)
    d.e("p1", "p2", style="dash")
    return d.svg()
