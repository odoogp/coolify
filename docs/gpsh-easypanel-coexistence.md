# Coolify + EasyPanel on the same host

EasyPanel Traefik owns `80`/`443`. Coolify’s localhost server stays on **Custom** (`proxy.type = NONE`) so `coolify-proxy` never starts. Coolify still attaches that external Traefik to every `coolify.managed` Docker network when `external_proxy_name` is set (UI: Servers → Proxy → Custom → External proxy container).

## Configure (once per host)

1. Servers → **localhost** (instance server) → **Proxy** → **Custom**. Do not Start proxy.
2. Set **External proxy container** to `easypanel-traefik` (Docker name filter; Swarm task names still match).
3. **Save external proxy** (or **Connect networks now** after save).
4. Leave **Labels for the active proxy only** off if EasyPanel already reads Coolify `traefik.*` labels (`http`/`https`, `letsencrypt`).

API equivalent: `PATCH /api/v1/servers/{uuid}/proxy` with `"proxy_type":"none"` and `"external_proxy_name":"easypanel-traefik"`.

## Verification

```bash
# Edge still EasyPanel; no coolify-proxy
ss -lntp | grep -E ':80|:443|:8000|:3000'
docker ps -a --filter name=coolify-proxy
docker service ls | grep -i traefik

# Traefik shares app networks (example UUID)
TRAEFIK=$(docker ps --filter name=easypanel-traefik --format '{{.Names}}' | head -1)
docker inspect "$TRAEFIK" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
docker network inspect coolify --format '{{range .Containers}}{{.Name}} {{end}}'
```

Expect: Coolify UI `:8000`, EasyPanel UI `:3000`, Traefik on `80`/`443`, Traefik listed on `coolify` and per-service UUID networks after reconcile. HTTPS for an Odoo/Jupyter FQDN should not return Gateway Timeout from a missing shared network.

## After Coolify or EasyPanel updates

| Check | Expected |
|---|---|
| Server Proxy type | Still **Custom** (`NONE`) |
| `external_proxy_name` | Still `easypanel-traefik` (or your filter) |
| `docker ps -a --filter name=coolify-proxy` | Empty / not publishing 80/443 |
| Traefik on managed networks | Reconnect via **Connect networks now** or wait for PushServerUpdate / ServerCheck |
| Labels / entrypoints / certresolver | Still compatible if you rely on Coolify labels |

## Rollback

- Do **not** switch back to Traefik/Caddy or Start proxy while EasyPanel holds `80`/`443`.
- To undo a bad network attach only: `docker network disconnect <network> <easypanel-traefik-task>`.
- Clear `external_proxy_name` in Custom proxy settings if you stop using auto-bridge (Custom itself can stay).

## Product hooks

- `Server::externalProxyName()` / `usesExternalProxyNetworkConnect()` / `shouldReconcileProxyNetworks()`
- `connectProxyToNetworks()` → `connectExternalProxyToNetworks()` when Custom + name set
- Jobs: `ConnectProxyToNetworksJob`, `PushServerUpdateJob`, `ServerCheckJob`; Livewire Proxy + Destinations; StandaloneDocker create; API `ServerProxyController`

## Local image build note

`docker/production/Dockerfile` does **not** require BuildKit cache mounts (legacy `docker build` works). Optional: install `docker-buildx-plugin` for faster builds via `docker buildx`.
