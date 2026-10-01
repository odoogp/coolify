---
name: gpsh
description: >-
  Locked GPSH decisions for this Coolify fork: Odoo stays a Service, GitHub App
  launch flow, server booleans, staging clone, and what not to change. Use when
  editing Odoo, Jupyter, project launch, AddEmpty, service picker, GitHub App,
  repositories, staging, servers, team permissions, lang/es.json, or GPSH docs.
---

# GPSH

The visible name is GPSH (`product_name()` / `product_text()`). Models, routes, and tables stay Coolify names.

Read `docs/gpsh-architecture.md` for the map. `docs/v5/` (coold, flux) is not this runtime. Do not mix it into the Odoo layer.

Phases 3–10 already exist. Do not extend them or rip them out unless they block the connection flow.

## Do not touch

- Do not commit or push unless the user asks.
- This Mac has no `php` and no `docker`. Do not invent Pest, `php -l`, curl, or HTTP results. Say what was not run.
- Do not `docker compose down` or `down -v`. Do not delete volumes.
- Do not change the local updater, `COOLIFY_IMAGE=coolify-custom:local`, `COOLIFY_PULL_POLICY=never`, instance Postgres/Redis/Realtime/Sentinel, or sentinel rows `id = 0`.
- Do not edit a migration after it has been applied.
- Source edits are not live until `coolify-custom:local` is rebuilt. Say that when the user must see the change.
- Do not change Odoo listen `0.0.0.0:8069`. Do not re-enable the Odoo image healthcheck. Do not reintroduce `gpsh-enter`.
- Do not change `S3StoragePolicy` or `canAccessTerminal`. S3 create stays owner-only, checked on submit, not on `Storage\Create::mount` (that form is mounted on every page).
- Members never get `server.create` / `server.update` / `server.delete` or S3 create, even if a pivot flag is true. Server update and delete stay owner-only.
- Do not call `ProvisionOdooEnvironment`. Do not create an `Application` to represent the Git connection. Do not use `OauthController` for repository access. Do not add `OdooGithubApp`. `GithubApp` is the Git source. Odoo stays a `Service`.
- `request_oauth_on_install` is false. GitHub is optional. One repository per project. Environment name and `odoo_environment_branches.git_branch` are different. The database name is the environment name.
- Do not re-download the Odoo image (`docker run --pull never`, `pullLatestImages false`).
- Queue workers: treat the user as authenticated only when `auth()->id() !== null`. Otherwise `User::whereKey` and `Auth::setUser`. `(int) null === 0` selects the instance owner and server id 0. `Auth::onceUsingId(0)` is unsafe. An empty server id must throw "Choose a server." before `(int)` cast.

## Launch a project

Form: `app/Livewire/Project/AddEmpty.php` and `resources/views/livewire/project/add-empty.blade.php`. The service field is `x-forms.searchable-listbox` with `live` and `portal` (the create modal clips a normal listbox). Odoo first, then the other templates. Choosing Odoo reveals version, Connect GitHub, and the server list.

1. Ask which server when the team has one the user may use. Server id 0 only if `canLaunchOnInstanceServer()`. No usable server: do not pretend a service was created.
2. GitHub app already installed (`app_id`, `installation_id`, private key; `webhook_secret` is not required): do not send them to install the app or to "Install repositories". Go to the service with `launch=choose`.
3. App not installed: create the GitHub App, then send them to install repositories (`getInstallationPath`). Do not stop on the Coolify page that only shows the button.
4. After repositories are installed, return to the environment they configured. Create the Odoo service if it is missing, then open `project.service.configuration?launch=choose` (new repository or search an existing one). Confirming either path runs `LaunchOdooProjectJob` (containers, then HTTPS). Do not dump them on an empty resource index.
5. Without GitHub, start `LaunchOdooProjectJob` immediately after create.

`App\Livewire\Project\Resource\Index` keeps resource collections in `protected` properties. Livewire does not restore those on the next request. Initialize them before `render()` reads them.

## Permissions on the user

Pivot `team_user`, set by the owner on an admin:

- `can_add_servers`: admin may create servers. `ServerPolicy::create` is `canAddServers()`.
- `can_launch_on_instance_server`: admin may launch on server id 0.

Owners always can. Members never can.

## Staging and HTTPS

- Clone copies the database and the filestore, then Odoo neutralize. Staging is writable. The only `:ro` is the production filestore during the copy.
- Do not find Postgres by a guessed name `postgresql-{uuid}`. Match image/name `*postgres*` or label `coolify.service.subType=database`. Odoo is image `odoo:` or a name containing `odoo` but not jupyter. Do not stop Jupyter.
- Filestore chown uses `docker exec` on the source Odoo for uid/gid. Not hardcoded `101:101`.
- After compose up, wait until Odoo, Jupyter (if enabled), and PostgreSQL are running before HTTPS.
- A later copy failure after start keeps the staging environment.
- Deleting an environment returns to the project page.
- Launch is finished only when HTTPS is Let's Encrypt (not the Traefik default) and the public URL answers (not 503).
- A push to the saved `git_branch` git-clones addons and restarts only the Odoo container. Pull requests do nothing for Odoo.
- New GPSH strings go in `lang/es.json`. Validate with `python3 -c 'import json; json.load(open("lang/es.json"))'`.

## Support

WhatsApp support is a floating button on the logged-in layout. It asks for a topic, then opens `wa.me` with that text. The number is `instance_settings.whatsapp_support_number`, edited only by the instance owner at `settings.whatsapp`. Empty number hides the button. GitHub App name and icon live at `settings.github`, not on the general form.
