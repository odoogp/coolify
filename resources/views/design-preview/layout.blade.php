<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('heading', 'Acceso') · GPSH</title>
    <script>
        (function () {
            var theme = localStorage.getItem('gpsh-preview-theme');
            if (theme === 'light' || theme === 'dark') {
                document.documentElement.dataset.theme = theme;
            }
        })();
    </script>
    <style>
        :root {
            --canvas: #050607;
            --elevated: #12141a;
            --recessed: #0c0e12;
            --ink: #f6f7fb;
            --muted: rgba(246, 247, 251, 0.64);
            --line: rgba(255, 255, 255, 0.08);
            --accent: #c4b6ff;
            --accent-soft: rgba(168, 140, 255, 0.2);
            --shadow: 0 18px 40px rgba(0, 0, 0, 0.38);
            --good: #3dd68c;
            --good-soft: rgba(61, 214, 140, 0.16);
            --progress: #8b7cff;
            --progress-soft: rgba(139, 124, 255, 0.16);
            --glass: rgba(12, 14, 28, 0.58);
            --glass-line: rgba(255, 255, 255, 0.16);
            --field: rgba(255, 255, 255, 0.05);
            --sidebar: #07080a;
            color-scheme: dark;
        }

        [data-theme="light"] {
            --canvas: #f3f1ec;
            --elevated: #ffffff;
            --recessed: #fff;
            --ink: #1a1524;
            --muted: #5e566c;
            --line: rgba(28, 23, 48, 0.08);
            --accent: #6d4dff;
            --accent-soft: rgba(109, 77, 255, 0.12);
            --shadow: 0 10px 28px rgba(40, 24, 80, 0.07);
            --good: #128a4e;
            --good-soft: rgba(18, 138, 78, 0.12);
            --progress: #6d4dff;
            --progress-soft: rgba(109, 77, 255, 0.12);
            --glass: rgba(255, 255, 255, 0.82);
            --glass-line: rgba(255, 255, 255, 0.72);
            --field: #ffffff;
            --sidebar: #ffffff;
            color-scheme: light;
        }

        * { box-sizing: border-box; }

        [hidden] { display: none !important; }

        .svg-sprite {
            position: absolute;
            width: 0;
            height: 0;
            overflow: hidden;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--canvas);
            color: var(--ink);
            font: 14px/1.45 "Segoe UI", ui-sans-serif, system-ui, sans-serif;
        }

        a { color: inherit; }

        button, input { font: inherit; color: inherit; }

        :focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .theme-toggle,
        .btn,
        .btn-gradient,
        .nav-link,
        .tab {
            cursor: pointer;
        }

        .theme-toggle {
            height: 36px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--elevated);
            color: var(--ink);
        }

        [data-theme="dark"] .when-light,
        [data-theme="light"] .when-dark { display: none; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: var(--elevated);
            color: var(--ink);
            text-decoration: none;
        }

        .btn-primary {
            border-color: transparent;
            background: var(--accent-soft);
            color: var(--accent);
        }

        .btn-gradient {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 48px;
            margin-top: 8px;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(90deg, #7c5cff, #4f7dff);
            color: #fff;
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 24px;
            padding: 0 8px;
            border-radius: 999px;
            background: var(--good-soft);
            color: var(--good);
            font-size: 12px;
            font-weight: 600;
        }

        .pill::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .pill-progress {
            background: var(--progress-soft);
            color: var(--progress);
        }

        .login {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
        }

        .stage {
            position: relative;
            overflow: hidden;
            display: grid;
            place-items: center;
            min-height: 100vh;
            background:
                radial-gradient(ellipse 58% 42% at 32% 16%, rgba(164, 142, 255, 0.48), transparent 68%),
                radial-gradient(ellipse 36% 28% at 74% 72%, rgba(78, 112, 230, 0.38), transparent 70%),
                linear-gradient(180deg, #2a1d55 0%, #14182e 56%, #0c101c 100%);
        }

        [data-theme="light"] .stage {
            background:
                radial-gradient(ellipse 58% 42% at 30% 14%, rgba(150, 120, 255, 0.38), transparent 68%),
                radial-gradient(ellipse 40% 30% at 72% 78%, rgba(130, 160, 255, 0.28), transparent 70%),
                linear-gradient(180deg, #ece6ff 0%, #f7f4ef 60%, #efe8df 100%);
        }

        .orbit {
            position: absolute;
            width: min(420px, 70%);
            height: 150px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 50%;
            transform: rotate(-16deg);
        }

        [data-theme="light"] .orbit { border-color: rgba(80, 50, 160, 0.18); }

        .mark {
            position: relative;
            z-index: 1;
            width: min(280px, 58vw);
            filter: drop-shadow(0 24px 40px rgba(40, 20, 120, 0.35));
        }

        .shard {
            position: absolute;
            width: 46px;
            height: 52px;
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.72), rgba(140, 120, 255, 0.18));
            clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%);
        }

        .shard-a { top: 18%; left: 16%; width: 36px; height: 42px; }
        .shard-b { top: 62%; left: 22%; width: 28px; height: 32px; opacity: 0.8; }
        .shard-c { top: 28%; right: 14%; width: 54px; height: 62px; }

        .ridge {
            position: absolute;
            bottom: -8px;
            height: 22%;
            background: #1a2140;
            border-radius: 60% 40% 0 0;
        }

        [data-theme="light"] .ridge { background: #ddd4ee; }

        .ridge-a { left: -4%; width: 46%; }
        .ridge-b { right: -6%; width: 38%; height: 16%; background: #14192e; }
        [data-theme="light"] .ridge-b { background: #e7e0f4; }

        .stage-caption {
            position: absolute;
            z-index: 1;
            left: 32px;
            bottom: 28px;
            margin: 0;
            color: rgba(255, 255, 255, 0.82);
            font-size: 13px;
        }

        [data-theme="light"] .stage-caption { color: #3d3558; }

        .login-pane {
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 18px;
            padding: 32px 28px;
            background:
                radial-gradient(circle at 80% 0%, rgba(124, 92, 255, 0.16), transparent 18rem),
                var(--canvas);
        }

        .login-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: min(420px, 100%);
            margin: 0 auto;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-decoration: none;
        }

        .brand-mark {
            width: 22px;
            height: 22px;
        }

        .auth-card {
            width: min(420px, 100%);
            margin: 0 auto;
            padding: 28px 26px 22px;
            border-radius: 24px;
            background: var(--glass);
            border: 1px solid var(--glass-line);
            box-shadow: var(--shadow), inset 0 1px 0 rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(22px);
        }

        [data-theme="light"] .auth-card {
            box-shadow: var(--shadow), 0 0 0 1px rgba(28, 23, 48, 0.04);
        }

        .auth-card h1 {
            margin: 0;
            font-size: 26px;
            letter-spacing: -0.03em;
        }

        .lede {
            margin: 6px 0 18px;
            color: var(--muted);
        }

        .field {
            display: flex;
            align-items: center;
            gap: 10px;
            height: 48px;
            margin-bottom: 12px;
            padding: 0 12px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: var(--field);
        }

        .field input {
            flex: 1;
            min-width: 0;
            border: 0;
            background: transparent;
            outline: none;
        }

        .icon-btn {
            display: grid;
            place-items: center;
            width: 32px;
            height: 32px;
            padding: 0;
            border: 0;
            background: transparent;
            color: var(--muted);
        }

        .row-end {
            display: flex;
            justify-content: flex-end;
            margin: 2px 0 8px;
        }

        .text-link {
            color: var(--accent);
            font-size: 13px;
            text-decoration: none;
        }

        .fine {
            width: min(420px, 100%);
            margin: 0 auto;
            color: var(--muted);
            font-size: 12px;
            text-align: center;
        }

        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 248px minmax(0, 1fr);
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 18px;
            padding: 18px 14px;
            background: var(--sidebar);
            border-right: 1px solid var(--line);
        }

        .nav-group { margin: 0; padding: 0; list-style: none; }
        .nav-label {
            margin: 14px 10px 6px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            height: 36px;
            padding: 0 10px;
            border-radius: 10px;
            color: var(--ink);
            text-decoration: none;
        }

        .nav-link svg { flex: none; opacity: 0.8; }
        .nav-link.is-active {
            background: var(--accent-soft);
            color: var(--accent);
            box-shadow: inset 2px 0 0 var(--accent);
        }
        .nav-link.is-active svg { opacity: 1; }
        .nav-link.is-disabled { opacity: 0.45; cursor: default; }

        .workspace { min-width: 0; }
        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 22px 28px 0;
        }

        .eyebrow {
            margin: 0 0 4px;
            color: var(--muted);
            font-size: 12px;
        }

        .topbar h1 {
            margin: 0;
            font-size: 24px;
            letter-spacing: -0.03em;
        }

        .summary {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .account {
            display: inline-flex;
            align-items: center;
            height: 36px;
            padding: 0 12px;
            border-radius: 999px;
            background: var(--recessed);
            color: var(--muted);
            font-size: 13px;
        }

        main {
            padding: 22px 28px 40px;
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .block h2 {
            margin: 0 0 10px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 14px;
        }

        .card {
            background: var(--elevated);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 16px;
        }

        .card h3 {
            margin: 0;
            font-size: 16px;
            letter-spacing: -0.02em;
        }

        .meta {
            margin: 4px 0 12px;
            color: var(--muted);
            font-size: 12px;
        }

        .card-foot {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .table-card { padding: 6px 6px 8px; }
        table { width: 100%; border-collapse: collapse; }
        th {
            padding: 10px 12px;
            text-align: left;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }
        td {
            padding: 12px;
            border-top: 1px solid var(--line);
        }

        .tabs {
            display: flex;
            gap: 6px;
            padding: 0 28px;
            margin-top: 16px;
        }

        .tab {
            display: inline-flex;
            align-items: center;
            height: 32px;
            padding: 0 12px;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: var(--muted);
        }

        .tab.is-active {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .split {
            display: grid;
            grid-template-columns: 210px minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }

        .side-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
            position: sticky;
            top: 18px;
        }

        .side-nav .tab { cursor: default; }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 14px;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            color: var(--muted);
            font-size: 12px;
        }

        label.wide { grid-column: 1 / -1; }

        .control {
            height: 40px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: var(--recessed);
            color: var(--ink);
        }

        .version-pills { display: flex; gap: 8px; flex-wrap: wrap; }
        .version-pills span,
        .choice {
            display: inline-flex;
            align-items: center;
            height: 32px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--recessed);
        }
        .choice.is-selected {
            border-color: transparent;
            background: var(--accent-soft);
            color: var(--accent);
        }

        .env-list { display: flex; flex-direction: column; gap: 10px; }
        .env {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.4fr) auto auto;
            gap: 12px;
            align-items: center;
        }
        .domain {
            color: var(--muted);
            font-size: 13px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .mosaic {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .tile {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 16px;
            min-height: 176px;
            padding: 18px;
            border-radius: 26px;
            color: #fff;
            text-decoration: none;
            position: relative;
            overflow: hidden;
            box-shadow: 0 22px 44px rgba(0, 0, 0, 0.32);
        }

        .tile-kicker { font-size: 14px; font-weight: 600; }
        .tile-value {
            margin: 8px 0 2px;
            font-size: 40px;
            line-height: 0.95;
            letter-spacing: -0.045em;
            font-weight: 600;
        }
        .tile-note { font-size: 13px; opacity: 0.78; }
        .tile-coral {
            background: linear-gradient(165deg, #ff7d68 0%, #ffb07a 46%, #ffe0b8 100%);
            color: #2b1420;
        }
        .tile-olive { background: linear-gradient(165deg, #3a3018 0%, #b08a3e 46%, #14110c 100%); }
        .tile-plum { background: linear-gradient(160deg, #7a5cff 0%, #2c2148 58%, #100e16 100%); }
        .tile-forest { background: linear-gradient(165deg, #123528 0%, #1c7a4d 40%, #0b120f 100%); }
        .tile-night {
            background:
                radial-gradient(90% 80% at 100% 0%, rgba(140, 90, 255, 0.35), transparent 46%),
                linear-gradient(180deg, #1a1c24 0%, #0c0e13 100%);
        }
        .tile-span-2 { grid-column: span 2; }

        [data-theme="light"] .tile-olive {
            background: linear-gradient(165deg, #fff6df 0%, #f3d48a 52%, #fffaf0 100%);
            color: #2a2112;
        }
        [data-theme="light"] .tile-plum {
            background: linear-gradient(165deg, #f3edff 0%, #d9ccff 55%, #fbf8ff 100%);
            color: #24184a;
        }
        [data-theme="light"] .tile-forest {
            background: linear-gradient(165deg, #e8fbf0 0%, #b7f0cc 42%, #f7fff9 100%);
            color: #123224;
        }
        [data-theme="light"] .tile-night {
            background: #fff;
            color: #1a1524;
            box-shadow: 0 16px 36px rgba(40, 24, 80, 0.08);
        }

        .gauge-row { display: flex; align-items: center; gap: 16px; }
        .gauge {
            width: 108px;
            height: 108px;
            border-radius: 50%;
            background: conic-gradient(from 220deg, #d6ff63 0 var(--p), rgba(255, 255, 255, 0.16) var(--p) 100%);
            display: grid;
            place-items: center;
            flex: none;
        }
        .gauge b {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: rgba(8, 12, 10, 0.78);
            font-size: 18px;
        }
        [data-theme="light"] .gauge {
            background: conic-gradient(from 220deg, #1f8a4a 0 var(--p), rgba(18, 50, 36, 0.12) var(--p) 100%);
        }
        [data-theme="light"] .gauge b { background: rgba(255, 255, 255, 0.86); color: #123224; }
        .segments { display: flex; height: 10px; gap: 4px; margin-top: 8px; }
        .segments span { border-radius: 99px; min-width: 8px; }
        .legend { display: flex; flex-wrap: wrap; gap: 10px 16px; font-size: 12px; }
        .legend i { display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; }

        @media (max-width: 900px) {
            .login { grid-template-columns: 1fr; }
            .stage { min-height: 240px; }
            .stage-caption { left: 16px; bottom: 16px; }
            .shell { grid-template-columns: 1fr; }
            .sidebar {
                position: sticky;
                top: 0;
                z-index: 2;
                flex-direction: row;
                align-items: center;
                gap: 8px;
                overflow: auto;
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }
            .nav { display: flex; align-items: center; gap: 4px; }
            .nav-group { display: flex; gap: 4px; }
            .nav-label { display: none; }
            .split, .env, .form-grid, .mosaic { grid-template-columns: 1fr; }
            .tile-span-2 { grid-column: auto; }
            .side-nav { position: static; flex-direction: row; overflow: auto; }
            .topbar, main, .tabs { padding-left: 16px; padding-right: 16px; }
        }
    </style>
</head>
<body>
    <svg xmlns="http://www.w3.org/2000/svg" class="svg-sprite" aria-hidden="true">
        <symbol id="i-hex" viewBox="0 0 24 24">
            <path fill="currentColor" d="M12 2.2 20.2 7v10L12 21.8 3.8 17V7L12 2.2Zm0 2.3L6.2 8v8L12 19.5 17.8 16V8L12 4.5Z"/>
        </symbol>
        <symbol id="i-panel" viewBox="0 0 24 24">
            <path fill="none" stroke="currentColor" stroke-width="1.6" d="M4 5.5h6.5V11H4V5.5Zm9.5 0H20V11h-6.5V5.5ZM4 13h6.5v5.5H4V13Zm9.5 0H20v5.5h-6.5V13Z"/>
        </symbol>
        <symbol id="i-projects" viewBox="0 0 24 24">
            <path fill="none" stroke="currentColor" stroke-width="1.6" d="M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Z"/>
            <path fill="none" stroke="currentColor" stroke-width="1.6" d="M12 11.2 20 7.5M12 11.2V20M12 11.2 4 7.5"/>
        </symbol>
        <symbol id="i-server" viewBox="0 0 24 24">
            <rect x="4" y="4" width="16" height="6" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/>
            <rect x="4" y="14" width="16" height="6" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/>
            <path stroke="currentColor" stroke-width="1.6" d="M7 7h.01M7 17h.01"/>
        </symbol>
        <symbol id="i-source" viewBox="0 0 24 24">
            <path fill="none" stroke="currentColor" stroke-width="1.6" d="M8 8a2.5 2.5 0 1 0-2.2 2.48A5 5 0 0 0 12 15a5 5 0 0 0 6.2-4.52A2.5 2.5 0 1 0 16 8"/>
        </symbol>
        <symbol id="i-settings" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/>
            <path fill="none" stroke="currentColor" stroke-width="1.6" d="M12 3.5v2.2M12 18.3v2.2M3.5 12h2.2M18.3 12h2.2M6 6l1.6 1.6M16.4 16.4 18 18M18 6l-1.6 1.6M7.6 16.4 6 18"/>
        </symbol>
        <symbol id="i-user" viewBox="0 0 24 24">
            <circle cx="12" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/>
            <path fill="none" stroke="currentColor" stroke-width="1.6" d="M5.5 19.2a6.5 6.5 0 0 1 13 0"/>
        </symbol>
        <symbol id="i-lock" viewBox="0 0 24 24">
            <rect x="5" y="10" width="14" height="9" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/>
            <path fill="none" stroke="currentColor" stroke-width="1.6" d="M8 10V8a4 4 0 0 1 8 0v2"/>
        </symbol>
        <symbol id="i-eye" viewBox="0 0 24 24">
            <path fill="none" stroke="currentColor" stroke-width="1.6" d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6Z"/>
            <circle cx="12" cy="12" r="2.2" fill="none" stroke="currentColor" stroke-width="1.6"/>
        </symbol>
    </svg>

    @if ($screen === 'login')
        @yield('page')
    @else
        <div class="shell">
            <aside class="sidebar">
                <a class="brand" href="{{ route('design-preview', ['screen' => 'panel']) }}">
                    <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-hex"/></svg>
                    GPSH
                </a>
                <div class="nav">
                    <p class="nav-label">Espacio de trabajo</p>
                    <ul class="nav-group">
                        <li><a class="nav-link {{ $screen === 'panel' ? 'is-active' : '' }}" href="{{ route('design-preview', ['screen' => 'panel']) }}"><svg width="18" height="18" aria-hidden="true"><use href="#i-panel"/></svg>Panel</a></li>
                        <li><a class="nav-link {{ $screen === 'proyectos' ? 'is-active' : '' }}" href="{{ route('design-preview', ['screen' => 'proyectos']) }}"><svg width="18" height="18" aria-hidden="true"><use href="#i-projects"/></svg>Proyectos</a></li>
                    </ul>
                    <p class="nav-label">Infraestructura</p>
                    <ul class="nav-group">
                        <li><a class="nav-link {{ $screen === 'servidor' ? 'is-active' : '' }}" href="{{ route('design-preview', ['screen' => 'servidor']) }}"><svg width="18" height="18" aria-hidden="true"><use href="#i-server"/></svg>Servidores</a></li>
                        <li><span class="nav-link is-disabled" title="Esta muestra no incluye Orígenes"><svg width="18" height="18" aria-hidden="true"><use href="#i-source"/></svg>Orígenes</span></li>
                    </ul>
                    <p class="nav-label">Gestión</p>
                    <ul class="nav-group">
                        <li><a class="nav-link {{ $screen === 'ajustes' ? 'is-active' : '' }}" href="{{ route('design-preview', ['screen' => 'ajustes']) }}"><svg width="18" height="18" aria-hidden="true"><use href="#i-settings"/></svg>Ajustes</a></li>
                    </ul>
                </div>
            </aside>
            <div class="workspace">
                <header class="topbar">
                    <div>
                        <p class="eyebrow">Muestra visual</p>
                        <h1>@yield('heading')</h1>
                        <p class="summary">@yield('summary')</p>
                    </div>
                    <div class="topbar-actions">
                        @yield('actions')
                        <button type="button" class="theme-toggle" data-theme-toggle>
                            <span class="when-dark">Modo claro</span>
                            <span class="when-light">Modo oscuro</span>
                        </button>
                        <span class="account">Propietario</span>
                    </div>
                </header>
                @yield('page')
            </div>
        </div>
    @endif

    <script>
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
                document.documentElement.dataset.theme = next;
                localStorage.setItem('gpsh-preview-theme', next);
            });
        });

        document.querySelectorAll('[data-reveal]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = button.parentElement.querySelector('input');
                var shown = input.type === 'text';
                input.type = shown ? 'password' : 'text';
                button.setAttribute('aria-pressed', shown ? 'false' : 'true');
            });
        });

        document.querySelectorAll('[data-tabs]').forEach(function (group) {
            group.addEventListener('click', function (event) {
                var tab = event.target.closest('[data-tab]');
                if (!tab || !group.contains(tab)) {
                    return;
                }
                var id = tab.dataset.tab;
                group.querySelectorAll('[data-tab]').forEach(function (item) {
                    item.classList.toggle('is-active', item === tab);
                });
                var root = group.parentElement;
                root.querySelectorAll('[data-panel]').forEach(function (panel) {
                    panel.hidden = panel.dataset.panel !== id;
                });
            });
        });
    </script>
</body>
</html>
