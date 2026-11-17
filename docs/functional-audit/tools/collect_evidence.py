#!/usr/bin/env python3
"""Read-only evidence collector for the functional atlas.

Classifies files against the upstream Coolify fork base and counts the
building blocks of the application. Writes build/evidence.json. It only reads
the repository (git plumbing + file reads); it never changes it.
"""

from __future__ import annotations

import json
import re
import subprocess
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
OUT = Path(__file__).resolve().parent.parent / "build" / "evidence.json"
BASE = "3654f815bca5aba0835af227f2203dd64389e174"
UPSTREAM = "680f4e5d2"  # origin/main and origin/next mirror upstream Coolify (Andras Bacsai, 2026-09-29)
OWN_AUTHORS = ("Arielmim97", "odoogp", "Ronald")


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=ROOT, capture_output=True, text=True, check=False).stdout


def existed_at_base(path: str) -> bool:
    return subprocess.run(["git", "cat-file", "-e", f"{BASE}:{path}"], cwd=ROOT, capture_output=True).returncode == 0


def changed_since_base(path: str) -> bool:
    return subprocess.run(["git", "diff", "--quiet", BASE, "--", path], cwd=ROOT, capture_output=True).returncode != 0


def in_upstream(path: str) -> bool:
    return subprocess.run(["git", "cat-file", "-e", f"{UPSTREAM}:{path}"], cwd=ROOT, capture_output=True).returncode == 0


def same_as_upstream(path: str) -> bool:
    return subprocess.run(["git", "diff", "--quiet", UPSTREAM, "--", path], cwd=ROOT, capture_output=True).returncode == 0


def classify(path: str) -> str:
    """ORIGINAL / MODIFICADO / PROPIO, plus UPSTREAM_4.4 for upstream code imported after the fork."""
    if not (ROOT / path).exists():
        return "ELIMINADO" if existed_at_base(path) else "NO ENCONTRADO"
    if not existed_at_base(path):
        if in_upstream(path):
            return "UPSTREAM_4.4" if same_as_upstream(path) else "UPSTREAM_4.4_MODIFICADO"
        return "PROPIO"
    if not changed_since_base(path):
        return "ORIGINAL"
    return "UPSTREAM_4.4" if in_upstream(path) and same_as_upstream(path) else "MODIFICADO"


def files(pattern: str) -> list[str]:
    return sorted(str(p.relative_to(ROOT)) for p in ROOT.glob(pattern) if p.is_file())


def tally(pattern: str) -> dict:
    paths = files(pattern)
    counts = Counter(classify(p) for p in paths)
    return {"total": len(paths), **counts}


def routes(file: str) -> dict:
    text = (ROOT / file).read_text(errors="ignore")
    found = re.findall(r"Route::(get|post|put|patch|delete|match|any)\(\s*'([^']+)'", text)
    base_text = git("show", f"{BASE}:{file}")
    base_paths = set(re.findall(r"Route::(?:get|post|put|patch|delete|match|any)\(\s*'([^']+)'", base_text))
    own = sorted({path for _, path in found if path not in base_paths})
    return {"total": len(found), "added": len(own), "added_paths": own}


KEY_FILES = [
    # Original Coolify engine
    "app/Jobs/ApplicationDeploymentJob.php",
    "app/Models/Application.php",
    "app/Models/ApplicationDeploymentQueue.php",
    "app/Enums/ApplicationDeploymentStatus.php",
    "app/Models/Server.php",
    "app/Models/Service.php",
    "app/Models/Project.php",
    "app/Models/Environment.php",
    "app/Models/Team.php",
    "app/Models/User.php",
    "app/Models/EnvironmentVariable.php",
    "app/Models/SharedEnvironmentVariable.php",
    "app/Models/ScheduledDatabaseBackup.php",
    "app/Jobs/DatabaseBackupJob.php",
    "app/Jobs/ServerCheckJob.php",
    "app/Jobs/PushServerUpdateJob.php",
    "app/Actions/Service/StartService.php",
    "app/Actions/Proxy/StartProxy.php",
    "app/Http/Controllers/Webhook/Github.php",
    "app/Http/Controllers/Api/ApplicationsController.php",
    "app/Http/Controllers/Api/DeployController.php",
    "app/Http/Middleware/ApiAbility.php",
    "app/Providers/AuthServiceProvider.php",
    "app/Services/ContainerStatusAggregator.php",
    "bootstrap/helpers/docker.php",
    "bootstrap/helpers/proxy.php",
    "bootstrap/helpers/shared.php",
    "routes/web.php",
    "routes/api.php",
    "routes/webhooks.php",
    # Our layer
    "app/Support/OdooGit.php",
    "app/Support/OdooJupyter.php",
    "app/Support/GpshNotices.php",
    "app/Support/ServiceTemplateCatalog.php",
    "app/Domain/Odoo/OdooDomains.php",
    "app/Domain/Odoo/OdooContainers.php",
    "app/Domain/Odoo/OdooMail.php",
    "app/Jobs/LaunchOdooProjectJob.php",
    "app/Jobs/CloneOdooStagingJob.php",
    "app/Jobs/SyncOdooAddonsJob.php",
    "app/Jobs/MigrateOdooShJob.php",
    "app/Jobs/CreateOdooBackupJob.php",
    "app/Livewire/Dashboard.php",
    "app/Services/DashboardOverview.php",
    "app/Livewire/Deployments/Index.php",
    "app/Livewire/Project/Show.php",
    "app/Livewire/GlobalSearch.php",
    "app/Livewire/Settings/GetOdooPlans.php",
    "app/Livewire/Notifications/Center.php",
    "app/Http/Controllers/Api/OdooProjectController.php",
    "app/Http/Controllers/Api/AuditEventsController.php",
    "app/Models/AuditEvent.php",
    "app/Traits/HasMetrics.php",
    "app/Actions/Server/ValidateServer.php",
    "app/Actions/Database/StartDatabaseProxy.php",
    "app/Jobs/VolumeBackupJob.php",
    "app/Livewire/Project/Application/Rollback.php",
    "app/Services/Cloud/AdditionalCloudCatalog.php",
    "app/Http/Controllers/Webhook/Wompi.php",
    "app/Http/Controllers/Webhook/N1co.php",
    "app/Support/DetectRequestCountry.php",
    "app/Support/OdooMonitor.php",
    "app/Services/AdminCreationQuota.php",
    "routes/ai.php",
    "routes/channels.php",
    "app/Policies/ProjectPolicy.php",
    "app/Policies/EnvironmentPolicy.php",
    "bootstrap/helpers/applications.php",
    "scripts/dev",
]


def main() -> None:
    base_info = git("log", "-1", "--format=%H|%ad|%an|%s", "--date=short", BASE).strip().split("|")
    own_commits = git("log", "--oneline", f"{BASE}..HEAD", *[f"--author={a}" for a in OWN_AUTHORS]).strip().splitlines()
    all_commits = git("log", "--oneline", f"{BASE}..HEAD").strip().splitlines()
    head = git("log", "-1", "--format=%h|%ad|%s", "--date=short").strip().split("|")
    status = Counter(line[:2].strip() for line in git("status", "--porcelain").splitlines())
    migrations = files("database/migrations/*.php")
    own_migrations = [m for m in migrations if classify(m) == "PROPIO"]
    upstream_migrations = [m for m in migrations if classify(m).startswith("UPSTREAM")]

    evidence = {
        "base": {"sha": base_info[0], "date": base_info[1], "author": base_info[2], "subject": base_info[3]},
        "head": {"sha": head[0], "date": head[1], "subject": head[2]},
        "branch": git("branch", "--show-current").strip(),
        "version": re.search(r"'4\.\d+\.\d+'", (ROOT / "config/constants.php").read_text()).group(0).strip("'"),
        "commits": {"after_base": len(all_commits), "own": len(own_commits)},
        "upstream": {
            "ref": UPSTREAM,
            "date": git("log", "-1", "--format=%ad", "--date=short", UPSTREAM).strip(),
            "version": (re.search(r"'version' => [^\n]+", git("show", f"{UPSTREAM}:config/constants.php")) or [""])[0],
            "commits_not_merged": len(git("log", "--oneline", f"HEAD..{UPSTREAM}").strip().splitlines()),
        },
        "worktree": dict(status),
        "counts": {
            "models": tally("app/Models/*.php"),
            "jobs": tally("app/Jobs/*.php"),
            "actions": tally("app/Actions/**/*.php"),
            "livewire": tally("app/Livewire/**/*.php"),
            "api_controllers": tally("app/Http/Controllers/Api/*.php"),
            "policies": tally("app/Policies/*.php"),
            "events": tally("app/Events/*.php"),
            "support": tally("app/Support/**/*.php"),
            "domain": tally("app/Domain/**/*.php"),
            "services": tally("app/Services/**/*.php"),
            "migrations": {"total": len(migrations), "PROPIO": len(own_migrations), "UPSTREAM_4.4": len(upstream_migrations)},
            "feature_tests": tally("tests/Feature/**/*.php"),
            "unit_tests": tally("tests/Unit/**/*.php"),
            "views": tally("resources/views/**/*.blade.php"),
        },
        "own_migrations": [Path(m).name for m in own_migrations],
        "routes": {"web": routes("routes/web.php"), "api": routes("routes/api.php")},
        "key_files": {path: classify(path) for path in KEY_FILES},
    }

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(evidence, indent=2, ensure_ascii=False))
    print(f"wrote {OUT.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
