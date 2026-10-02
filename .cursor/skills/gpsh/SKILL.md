---
name: gpsh
description: >-
  GPSH phase contract for this Coolify fork (phases 0–10): Odoo project,
  staging, optional GitHub, Jupyter, launch, servers, backups, and the screens
  that must match those phases. Use when editing Odoo, Jupyter, project launch,
  AddEmpty, service picker, GitHub App, repositories, staging, servers, team
  permissions, settings, lang/es.json, or GPSH docs.
---

# GPSH

The visible name is GPSH (`product_name()` / `product_text()`). Models, routes, and tables stay Coolify names.

The product contract is the phases below. Long form: `docs/gpsh-architecture.md`, `docs/gpsh-odoo-phase-1.md`, `docs/gpsh-odoo-phase-2.md`, `docs/gpsh-odoo-phases-3-10.md`, `docs/gpsh-odoo-architecture-decision.md`. `docs/v5/` (coold, flux) is not this runtime.

Before changing launch, a project screen, GitHub, or Odoo: check the phase that owns that screen. If the UI still looks like stock Coolify where a phase already defined the behavior, that is the bug. Do not add a second flow beside the phase.

The deployment experience to reach is Odoo.sh: one project, production and staging, one Odoo per environment, a branch, and a public HTTPS link. The client does not manage a scattered list of Coolify services. That project screen is phase 6 and is still the gap. Do not hide Coolify's own tools from the owner: adding servers, S3, and the cloud providers Coolify already has (Hetzner, Vultr, DigitalOcean, and the rest of the server create screen) stay available.

The owner runs the platform. Admin and member are client profiles. Their limits are specific and are set on the invitation, before the sign-in link, for admin and member: projects, environments, members, production branches, staging branches, and services. Admin also gets the GitHub account, `can_add_servers`, and `can_launch_on_instance_server`. Member also gets `odoo.*` abilities. Server flags stay off for a member. Only the owner can write those on the invite. An admin who invites someone does not grant servers. Members never gain server, S3, or terminal access. An admin's terminal is only the instances they created or that were assigned to them. Do not replace Coolify roles with a new role system.

`App\Livewire\Project\Resource\Index` keeps resource lists in `protected` properties. Livewire drops them on the next request, including Install Odoo. `render()` reloads them from the public project and environment. Install Odoo is only shown when that environment is empty, and it asks where to run: local only with permission, create a server when none exist, or which server when some exist. If another service is already there, the list shows that service's name and there is no Install Odoo button.

## Phase 0

Reuse Coolify. Do not build a second deploy engine, a second Git checkout, a second webhook, or a second user system.

- Odoo is a `Service` (template `odoo` + Postgres), started with `StartService`. Not an `Application` image build.
- Git source is `GithubApp`. Webhook stays `POST /source/github/events`.
- One `Application` marked `is_odoo_addons` per environment is how that webhook finds the branch (`SyncOdooAddonsJob` copies into `{serviceUuid}_odoo-extra-addons` and restarts only the Odoo container). Do not add `OdooGithubApp`. Do not create an extra Application in assign/launch on top of that one.
- Login OAuth (`OauthController`) does not list repositories.
- Do not call `ProvisionOdooEnvironment`.

## Phase 1 — profile and staging quota

- `odoo_profiles`: one row per project. `odoo_version` is 17, 18, 19, or 20. Enabling Odoo only saves the profile. It does not create an empty staging.
- Staging is an `Environment` named `staging` or `staging-N`. `production` is not staging. No `OdooStagingEnvironment` model.
- Quota is on the user, written only by the owner. Not the old project columns. `max_projects` is the ceiling. A second project is refused when that number is reached, even if environments or services still have room. One allowed project includes one instance, and that instance includes its production branch and its staging branch. Those two branches do not spend a second project and do not need spare `max_environments` or `max_services` to exist. Rule: `OdooStaging::canCreateStagingEnvironment()`. No profile or a member: no. Owner, or admin with a null limit: yes. Admin with a number: staging environments they created, under that number. Production does not count. Any admin on the team can finish a staging launch on a project they can use. The other admin's quota does not block it, and the overlay must reach Done.
- `Project::createNextStagingEnvironment()` is the only creator. Clone calls it. One new staging, never a second production. If `staging` exists, reuse it; the next is `staging-2`.
- A project without a profile stays production only.

## Phase 2 — what the user must see

GitHub is optional. `request_oauth_on_install` is false.

New project: pick the service (search by name; the menu must not be clipped). Odoo asks version 17–20 and Connect GitHub. Skipping GitHub still creates the project. JupyterLab stays on and shows addon files. One folder: Odoo `/mnt/extra-addons`, Jupyter `/workspace/addons`.

Connect GitHub uses the existing GitHub App register. A row without `installation_id` is not connected. Do not ask for `app_id`, installation id, or the private key in the project form. The app name comes from instance settings (default `gpsh1`), stored on `team_user.github_app_id`. The screen shows that account's login and repositories, not a list of apps. The owner can switch the account. The manifest for this path asks `contents: write` and `administration: write`. A GitHub App created from Coolify's normal source screen stays read-only.

Already installed (`app_id`, `installation_id`, private key; `webhook_secret` is not required): do not send them to install the app or to "Install repositories". Ask: launch production on a new repository, or search an existing one (reuse the repository search, up to five pages). Then start containers and wait for HTTPS.

Not installed: create the app, then send them to install repositories (`getInstallationPath`). Do not stop on the Coolify page that only shows the button. After GitHub returns, create the Odoo service if it is missing and open the same new-vs-existing choice. Do not leave them on an empty resource index.

One repository per project (`owner/name` on the profile). It cannot belong to two projects. New repo slug is the project name (`Mi Empresa` → `mi-empresa`). Each environment stores its own `git_branch` in `odoo_environment_branches`. Two environments cannot share a branch. Without GitHub there is no branch row; the panel says JupyterLab.

Inside the project there is one list. A row is selected with one click. Clone and Open environment appear on that selection, not in the header. Clone is only there when the selection is production and it already has Odoo; the button says Clone. The choices open in a dialog, not a box above the list. While it runs, the screen says it is creating that staging and starting Odoo. An Odoo project does not offer other service types. Open environment opens the selected branch straight on the service panel (status, logs, actions), not the resource list. The gear on the environment name is that environment's settings. A member can open the environment and Odoo. If that branch has no service yet, the empty environment still asks where to install. While a launch is in progress, the service panel shows the loading steps. Do not redirect that panel back to the project.

Production is the environment, not a list of Compose services. Open Odoo from the project (`project.service.odoo.enter`). GitHub on `launch=choose` is the repository choice, not the mounted-service list. The public URL is `https://` without port `:8069` and opens the admin session at `/_odoo/paas/connect`. Odoo starts with `--proxy-mode` and `--no-database-list`, filtered to its own database. Each start writes `web.base.url` as `https://` in Postgres. HTTPS redirect must still allow `/.well-known/acme-challenge/`. A new Odoo version is a template under Settings.

A push to the saved `git_branch` reclones addons and restarts only the Odoo container. Pull requests do nothing. No second webhook.

Launch is not done until the certificate is Let's Encrypt (not the Traefik default) and `https://host/web/login` is the Odoo login, not the auto-refresh page and not 503 / "no available server". Open Odoo stays hidden while the container is exited. While Odoo installs, the public URL shows a Spanish auto-refresh page. A client whose service runs on server id 0 may use that server even though the server row belongs to the instance team. Do not re-download the image (`pullLatestImages false`, `--pull never`). Do not re-enable the image healthcheck. Do not reintroduce `gpsh-enter`. Listen stays `0.0.0.0:8069`.

In the new-project wizard and on an empty Odoo environment (Install Odoo), ask where it runs. Local is server id 0, included even when that server belongs to the instance team, and only when `canLaunchOnInstanceServer()`. Without that permission and with no other server, the only question is whether to create a server. With permission, also offer creating a new server. If servers already exist, ask which one. Read the flag from `team_user`, not a stale team list. Owners can always use server id 0.

## Phases 3–10 — do not drop these

These are already started. Do not rip them out. Do not extend them unless the current phase flow is blocked.

- 3. Production and staging are two environments, two services, separate domains, databases, filestores, and addon volumes. The existing webhook dispatches the matching branch. A push does not build an image when the addons Application exists. Statuses: `queued`, `in_progress`, `finished`, `failed`, `cancelled-by-user`.
- 4. Deploy history reuses that queue or the service deploy, with those same statuses.
- 5. `OdooBackup` is `complete` only when the database execution and the filestore volume execution are both `success`. Restore or clone of data without that pair is refused.
- 6. Project view for member and owner: domain, version, workers, addon path, Jupyter, last status. Not the raw Docker inventory. No new server screens.
- 7. Version on the profile, workers, addon path, Jupyter optional per environment. The 17–20 selector on the service already exists.
- 8. Audit log (user, action, project, environment, result, metadata, no secrets) and the notification channels that already exist.
- 9. API `/api/v1/projects/{uuid}/odoo` calls the same actions as the UI.
- 10. A member cannot create or change a server or S3. Staging does not write to production. Abilities `odoo.*` granted by the owner do not open servers, S3, or the terminal. An admin's terminal is still limited to instances they created or were assigned.

## Corrections that override an older phase sentence

- Clone copies the database and the filestore, then Odoo neutralize. Staging stays writable. Phase 1's "the clone copies nothing" is stale. The only `:ro` is the production filestore during the copy. After start succeeds, a later copy failure keeps the staging environment.
- The environment name and `git_branch` are different fields. The database name is the environment name, not `{project}_{branch}`.
- Do not find Postgres as `postgresql-{uuid}`. Match image or name `*postgres*`, or label `coolify.service.subType=database`. Odoo is image `odoo:` or a name containing `odoo` but not jupyter. Do not stop Jupyter. Filestore ownership comes from `docker exec` uid/gid on the source Odoo, not hardcoded `101:101`.
- Wait until Odoo and PostgreSQL are running before the HTTPS check. Client Jupyter, Grafana, Prometheus, and cAdvisor are not part of that wait.
- A clone stays on the loading screen until that Odoo container is running and `https://host/web/login` is the real login. The job then stores `config_hash` for the configuration it just applied, so "The latest configuration has not been applied" does not appear because of the clone itself.
- Open Odoo and Open Jupyter keep those names and open outside GPSH. An external-link icon is the signal. The generic Links menu is hidden on an Odoo service. Logs is an extra panel action and stays on the service logs screen, so it does not use that icon.
- Jupyter is not an iframe. Its URL carries the token and there is no anonymous session. Idle kernels and terminals are culled. `odoo-logs.sh` in the Jupyter folder tails `/workspace/addons/.gpsh/odoo.log`, which Odoo writes on start.
- Choosing Odoo in Terminal runs `odoo shell` for that branch's database. PostgreSQL and Jupyter stay on the container shell.
- Owner modules are a list on Settings → Odoo. They live at `/data/coolify/gpsh-owner-modules` on the instance, mounted read-only, and each start symlinks them into `/mnt/extra-addons`. They are not copied into the client repository. Redeploy refreshes the links.
- Owner Jupyter is an extra button on the Odoo service for an instance admin (`isInstanceAdmin()`, team 0 owner or admin). A client admin or member does not see it. It is not the client Jupyter. The workspace has `odoo` (that image's `/usr/lib/python3/dist-packages/odoo/addons`), `owner` (the owner modules), and `custom` (this branch's extra-addons), all read-only. A sleeping `stdlib` container uses the same Odoo image so Docker seeds a volume; the volume name includes the image tag. Both use the Compose profile `gpsh-later` and start in the background after Odoo answers HTTPS. The team's existing notification channels get one general notice. The readiness check and the database copy ignore `stdlib` and `jupyterowner`. Neither appears in the terminal.
- While Odoo installs, the public page rotates short Spanish lines ("Estamos preparando todo.", "No se vaya, todo comenzará pronto.", "Es mejor que vayas por un café.") and still contains "Esta página se actualiza sola." so the HTTPS check can tell it from the real login.
- A copied staging database drops `orm_signaling_*` before neutralize and before the next Odoo start. Odoo recreates those counters. Leaving them makes startup fail with `relation "orm_signaling_assets" already exists`.
- On an Odoo service, a client sees runtime logs only for the Odoo and PostgreSQL containers. Jupyter, Grafana, Prometheus, cAdvisor, and the stdlib container are visible to the instance owner.
- Grafana's public URL is `https://` plus the monitor host. Gzip stays off for that application so Traefik does not break Grafana's scripts.
- The project environment cards and the list show icons for that environment only. Editor opens its Jupyter, Monitor opens its Grafana, Logs opens only the Odoo container log (`?only=odoo`), and Terminal opens the Odoo shell for someone who can use the terminal. The name appears when the pointer is on the icon. The service heading buttons stay as they are.
- Jupyter, the owner Jupyter, and Grafana are HTTPS on the certificate Traefik issues for Odoo. That certificate lists their hosts as alternate names. Their own routers do not request a second certificate.
- Launching Odoo adds Grafana (`monitor`), Prometheus, and cAdvisor to that stack. Coolify assigns the Grafana URL. Monitor opens that dashboard for this branch's Odoo and PostgreSQL containers only. Prometheus keeps metrics whose compose project is this service and whose service is `odoo`, `postgresql`, or `postgres`. Jupyter is not collected. cAdvisor is privileged and mounts the Docker socket, `/sys`, and `/var/lib/docker` read-only; those host paths are applied after the service parser, which would otherwise rewrite them. The terminal does not offer cAdvisor, Prometheus, or Grafana. Gzip stays off and the Grafana root URL is `https://` plus the monitor host.
- Runtime logs allow the instance owner on any server. A client can read logs for the server that resource already runs on, including server id 0, even when that server row belongs to the instance team.
- Deleting an environment returns to the project page.
- `(int) null === 0` selects the instance owner and server id 0. An empty server id must throw "Choose a server." before the cast. In a queue worker, trust `auth()->id()` only when it is not null; otherwise `User::whereKey` and `Auth::setUser`. `Auth::onceUsingId(0)` is unsafe.

## Permissions

The invite form (`InviteLink`) is where the owner sets this, before Generate link. The user is attached to the team with those values already. Empty number means no limit. An admin who invites cannot grant servers.

Written for admin and member: `max_projects`, `max_environments`, `max_members`, `max_production_branches`, `max_staging_branches`, `max_services`.

Admin only: `github_app_id`, `can_add_servers`, `can_launch_on_instance_server`.

Member only: `odoo_abilities` (grantable list). A member stays without server flags even if the form sends them.

`can_add_servers`: `ServerPolicy::create` is `canAddServers()`. `can_launch_on_instance_server`: may launch on server id 0. Read the flag with a fresh `teams()` query (`User::teamFlag`), not the in-memory team list. `OdooGit::allowedLaunchServers()` must `find(0)` when that flag is on. Server id 0 is the instance server and is often not on the client's team.

Owners always can. Members never can, even if the column is true. Server update/delete and S3 create stay owner-only. `S3StoragePolicy` stays as it is. `Storage\Create` is mounted on every page: authorize on submit, not on mount.

Terminal (`canAccessTerminal` plus the resource check): members never. The instance owner can open any terminal. An admin can open the terminal only for an instance they created (`created_by`) or that the owner assigned to them. Another admin on the same team does not inherit that terminal. The websocket allowlist is every server that user can use, including server id 0 when a service of their team runs there (`host.docker.internal`). It is not only `currentTeam()->servers`.

Odoo stays the container process. Start as root only to `chown` `/var/lib/odoo` to the `odoo` user, then drop privileges. Do not pass `gpsh_autoconnect` with `--load`. Install it in that branch's database (`-i gpsh_autoconnect`) so `/_odoo/paas/connect` exists on staging as well as production. A copied branch gets its own `ODOO_DATABASE` and `ODOO_LOGIN_TOKEN`; it must not keep production's. The image healthcheck stays disabled. Listen stays `0.0.0.0:8069`. The terminal allowlist includes server id 0 and `host.docker.internal` when that user can use the server, including when the service points at it through its destination.

## Support

WhatsApp: floating button on the logged-in layout. It asks the topic, then opens `wa.me`. The mark is the WhatsApp glyph, not a hand-drawn phone. Number is `instance_settings.whatsapp_support_number`, owner only, at `settings.whatsapp`. Empty hides the button.

GitHub App administration for an app a client created on their GitHub lives only in the owner's settings (`settings.github`: name, icon, and that app). Remove create/manage GitHub App from the project, the service page, the invite form, the sources navbar, and the new-repository modal. When an admin invites someone, attach them to the team with their limits and also add them as a collaborator on that project's main repository. An invite sent by a different admin on the same team does the same.

## Do not touch

- Do not commit or push unless asked.
- This Mac has no `php` and no `docker`. Do not invent test results.
- Do not `docker compose down` or `down -v`. Do not delete volumes.
- Do not change the local updater, `COOLIFY_IMAGE=coolify-custom:local`, `COOLIFY_PULL_POLICY=never`, or sentinel rows `id = 0`.
- Do not edit a migration after it is applied.
- Source edits are not live until `coolify-custom:local` is rebuilt. Say so.
- New GPSH strings go in `lang/es.json`. Validate with `python3 -c 'import json; json.load(open("lang/es.json"))'`.
