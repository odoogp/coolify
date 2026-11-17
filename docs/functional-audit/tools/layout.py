"""Page layout helpers: every page is a fixed A3 landscape sheet (420 x 297 mm)."""

from __future__ import annotations

from html import escape

from svgflow import KIND_LABEL, KINDS

DOC_NAME = "getcloud.sh — Atlas funcional de la plataforma basada en Coolify"

STATE = {
    "actual": ("ESTADO ACTUAL", "#1D4ED8", "#E7EFFD"),
    "futuro": ("ARQUITECTURA FUTURA · PROPUESTA", "#4B5563", "#F1F2F4"),
    "comparacion": ("COMPARACIÓN ACTUAL vs OBJETIVO", "#7C3AED", "#F1E8FD"),
    "referencia": ("REFERENCIA EXTERNA · RAILWAY", "#059669", "#E3F5EA"),
}

BADGE = {
    "ORIGINAL COOLIFY": "coolify",
    "COOLIFY 4.4 (SIN COMMIT)": "coolify",
    "PERSONALIZACIÓN PROPIA": "own",
    "MODIFICACIÓN": "partial",
    "INTEGRACIÓN": "ext",
    "PROPUESTA": "proposal",
    "PENDIENTE DE VERIFICACIÓN": "risk",
}

CSS = """
@page { size: 420mm 297mm; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Helvetica Neue', Arial, sans-serif; color: #0F172A;
       -webkit-print-color-adjust: exact; print-color-adjust: exact; font-variant-numeric: tabular-nums; }
.page { width: 420mm; height: 297mm; position: relative; overflow: hidden; page-break-after: always; break-after: page;
        padding: 15mm 16mm 14mm; background: #FFFFFF; }
.page:last-child { page-break-after: auto; break-after: auto; }
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12mm; border-bottom: 1px solid #E2E8F0;
        padding-bottom: 4mm; margin-bottom: 6mm; }
.head .sec { font-size: 12px; color: #64748B; font-weight: 600; margin-bottom: 1.5mm; }
.head h1 { margin: 0; font-size: 27px; letter-spacing: -0.02em; line-height: 1.1; }
.head .sub { margin-top: 1.5mm; font-size: 13px; color: #475569; max-width: 250mm; }
.state { font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: 999px; white-space: nowrap; letter-spacing: 0.02em; }
.foot { position: absolute; left: 16mm; right: 16mm; bottom: 7mm; display: flex; justify-content: space-between;
        font-size: 10px; color: #94A3B8; border-top: 1px solid #F1F5F9; padding-top: 2mm; }
.body { position: absolute; left: 16mm; right: 16mm; top: 38mm; bottom: 14mm; }
.grid { display: grid; gap: 6mm; height: 100%; }
.cols-diagram { grid-template-columns: 1fr 92mm; }
.panel { border: 1px solid #E2E8F0; border-radius: 10px; padding: 4mm 4.5mm; background: #FBFCFD; overflow: hidden; }
.panel h3 { margin: 0 0 2.5mm; font-size: 14.5px; letter-spacing: -0.01em; }
.panel p, .panel li { font-size: 13px; line-height: 1.5; color: #334155; }
.panel ul { margin: 0; padding-left: 4.5mm; }
.panel li { margin-bottom: 1.3mm; }
.side { display: flex; flex-direction: column; gap: 4mm; min-height: 0; }
.side .panel { flex: 0 0 auto; }
.side .panel.grow { flex: 1 1 auto; }
code, .mono { font-family: 'SF Mono', Menlo, Consolas, monospace; font-size: 10px; color: #1E293B; background: #F1F5F9;
              padding: 0 3px; border-radius: 3px; word-break: break-word; }
.refs li { font-size: 11.5px; }
.cols-diagram .panel li { font-size: 12px; }
.diagram { border: 1px solid #E2E8F0; border-radius: 12px; background: #FFFFFF; display: flex; align-items: center;
           justify-content: center; overflow: hidden; }
.diagram svg { width: 100%; height: 100%; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-size: 12px; color: #475569; font-weight: 700; padding: 3mm 3mm; border-bottom: 1.5px solid #CBD5E1;
     background: #F8FAFC; }
td { padding: 3.2mm 3mm; border-bottom: 1px solid #EEF2F6; vertical-align: top; line-height: 1.42; color: #1E293B; }
td.cap { font-weight: 650; white-space: nowrap; }
.tag { display: inline-block; font-size: 10px; font-weight: 700; padding: 1.5px 6px; border-radius: 999px; white-space: nowrap;
       border: 1px solid; margin: 0 2px 2px 0; }
.lead { font-size: 15px; line-height: 1.5; color: #1E293B; }
.kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 4mm; }
.kpi { border: 1px solid #E2E8F0; border-radius: 10px; padding: 4mm; }
.kpi b { display: block; font-size: 28px; letter-spacing: -0.02em; }
.kpi span { font-size: 11px; color: #64748B; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; }
.three { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 5mm; }
.toc { columns: 2; column-gap: 14mm; font-size: 13px; }
.toc div { display: flex; justify-content: space-between; border-bottom: 1px dotted #CBD5E1; padding: 1.6mm 0; break-inside: avoid; }
.toc div.h { font-weight: 700; border-bottom: none; padding-top: 4mm; color: #1D4ED8; }
.cover { background: radial-gradient(1200px 700px at 85% 10%, #EDE7FF 0%, rgba(255,255,255,0) 60%),
                     radial-gradient(900px 600px at 0% 100%, #E7EFFD 0%, rgba(255,255,255,0) 60%), #FFFFFF; }
.phase { border-radius: 10px; padding: 3.5mm; border: 1px solid #E2E8F0; }
.small { font-size: 10.5px; color: #64748B; }
table.tight td { padding: 2.3mm 2.4mm; font-size: 12.2px; line-height: 1.36; }
table.tight th { padding: 2.4mm 2.4mm; font-size: 11.5px; }
table.tight code { font-size: 10.5px; }
td code { font-size: 11px; }
table.dense td { padding: 1.25mm 2mm; }
table.dense .tag { margin: 0 2px 0 0; }
.stack { display: flex; flex-direction: column; gap: 5mm; height: 100%; }
.fill { flex: 1 1 auto; min-height: 0; }
.cell-ok { color: #047857; font-weight: 650; }
.cell-part { color: #B45309; font-weight: 650; }
.cell-no { color: #B91C1C; font-weight: 650; }
.cell-prop { color: #4B5563; font-weight: 650; }
.callout { border-left: 3px solid #7C3AED; background: #F7F3FE; padding: 3mm 4mm; border-radius: 6px; font-size: 12px;
           line-height: 1.45; color: #2E1065; }
.callout.risk { border-color: #DC2626; background: #FEF2F2; color: #7F1D1D; }
.big .panel li { font-size: 14px; line-height: 1.55; margin-bottom: 2.4mm; }
.big .panel h3 { font-size: 16px; margin-bottom: 3mm; }
.callout.blue { border-color: #1D4ED8; background: #EFF5FF; color: #1E3A8A; }
"""


def tag(label: str) -> str:
    kind = BADGE.get(label, "neutral")
    fill, stroke, ink = KINDS[kind]
    return f'<span class="tag" style="background:{fill};border-color:{stroke};color:{ink}">{escape(label)}</span>'


def kind_tag(kind: str, label: str | None = None) -> str:
    fill, stroke, ink = KINDS[kind]
    return (f'<span class="tag" style="background:{fill};border-color:{stroke};color:{ink}">'
            f'{escape(label or KIND_LABEL[kind])}</span>')


def ul(items: list[str]) -> str:
    return "<ul>" + "".join(f"<li>{item}</li>" for item in items) + "</ul>"


def panel(title: str, inner: str, grow: bool = False, cls: str = "") -> str:
    return f'<div class="panel {"grow" if grow else ""} {cls}"><h3>{escape(title)}</h3>{inner}</div>'


class Book:
    def __init__(self) -> None:
        self.pages: list[dict] = []

    def add(self, section: str, title: str, body: str, sub: str = "", state: str | None = None, toc: str | None = None,
            raw: bool = False, cls: str = "") -> None:
        self.pages.append({"section": section, "title": title, "body": body, "sub": sub, "state": state,
                           "toc": toc if toc is not None else title, "raw": raw, "cls": cls})

    def diagram(self, section: str, title: str, state: str, svg: str, reading: list[str], refs: list[str],
                risks: list[str] | None = None, sub: str = "", toc: str | None = None) -> None:
        side = panel("Cómo leerlo", ul(reading))
        side += panel("Evidencia en el código", f'<div class="refs">{ul(refs)}</div>', grow=True)
        if risks:
            side += panel("Límites y riesgos", ul(risks))
        body = (f'<div class="grid cols-diagram"><div class="diagram">{svg}</div>'
                f'<div class="side">{side}</div></div>')
        self.add(section, title, body, sub=sub, state=state, toc=toc or "")

    def render(self) -> str:
        total = len(self.pages)
        html = [f"<!doctype html><html lang='es'><head><meta charset='utf-8'><title>{escape(DOC_NAME)}</title>"
                f"<style>{CSS}</style></head><body>"]
        for index, page in enumerate(self.pages, start=1):
            if page["raw"]:
                html.append(f'<section class="page {page["cls"]}">{page["body"]}'
                            f'<div class="foot"><span>{escape(DOC_NAME)}</span><span>{index} / {total}</span></div></section>')
                continue
            state = ""
            if page["state"]:
                label, ink, bg = STATE[page["state"]]
                state = f'<div class="state" style="color:{ink};background:{bg};border:1px solid {ink}33">{label}</div>'
            sub = f'<div class="sub">{page["sub"]}</div>' if page["sub"] else ""
            html.append(
                f'<section class="page {page["cls"]}"><div class="head"><div><div class="sec">{escape(page["section"])}</div>'
                f'<h1>{escape(page["title"])}</h1>{sub}</div>{state}</div>'
                f'<div class="body">{page["body"]}</div>'
                f'<div class="foot"><span>{escape(DOC_NAME)}</span><span>{escape(page["section"])} · página {index} de {total}</span></div>'
                f"</section>")
        html.append("</body></html>")
        return "".join(html)

    def toc_entries(self) -> list[tuple[str, str, int]]:
        return [(p["section"], p["toc"], i) for i, p in enumerate(self.pages, start=1) if p["toc"]]
