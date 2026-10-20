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
| Labels / entrypoints / certresolver | For Custom + `external_proxy_name`: Coolify omits `tls.certresolver` on **custom** Hosts so EasyPanel file-mounted certs match by SNI, and still sets `letsencrypt` on **sslip.io / nip.io** (default public URL without a project subdomain). Set `proxy.cert_resolver` to force ACME on every Host. Entrypoints stay `http`/`https` — EasyPanel must expose those names (or remap) and must have an ACME resolver named `letsencrypt` (or your override) for sslip HTTPS. |

## Rollback

- Do **not** switch back to Traefik/Caddy or Start proxy while EasyPanel holds `80`/`443`.
- To undo a bad network attach only: `docker network disconnect <network> <easypanel-traefik-task>`.
- Clear `external_proxy_name` in Custom proxy settings if you stop using auto-bridge (Custom itself can stay).

## Product hooks

- `Server::externalProxyName()` / `usesExternalProxyNetworkConnect()` / `shouldReconcileProxyNetworks()`
- `connectProxyToNetworks()` → `connectExternalProxyToNetworks()` when Custom + name set
- `coolifyProxyNetworkConnectCommand($uuid, $server)` on service start/deploy — attaches external Traefik (not `coolify-proxy`) when Custom + `external_proxy_name`
- Jobs: `ConnectProxyToNetworksJob`, `PushServerUpdateJob`, `ServerCheckJob`; Livewire Proxy + Destinations; StandaloneDocker create; API `ServerProxyController`

## Expose Coolify UI on a custom domain (e.g. getcloud.sh)

Setting **Settings → General → URL** to `https://getcloud.sh` only changes how Coolify generates links. It does **not** publish the domain. With EasyPanel on `80`/`443`, Traefik must route that Host to Coolify (`:8000` on the host, or the `coolify` container on `8080`).

### Option A — EasyPanel “App” / reverse proxy to Coolify (preferred if UI allows)

1. Create a small service (or use Domains → custom backend if available) that targets `http://HOST_IP:8000` or the Coolify container.
2. Attach domain `getcloud.sh` with HTTPS.
3. Keep Coolify instance URL as `https://getcloud.sh`.

### Option B — Traefik file in EasyPanel

1. Confirm Coolify listens: `curl -sI http://127.0.0.1:8000 | head`
2. Inspect Traefik entrypoints/certresolver names:

```bash
docker service inspect easypanel-traefik --format '{{json .Spec.TaskTemplate.ContainerSpec.Args}}' | jq .
ls /etc/easypanel/traefik/config/
```

3. Add a dynamic file (names must match your EasyPanel Traefik; adjust `web`/`websecure`/`https` and certresolver):

```yaml
# /etc/easypanel/traefik/config/coolify-getcloud.yml
http:
  routers:
    coolify-getcloud-http:
      rule: Host(`getcloud.sh`)
      entryPoints:
        - web
      middlewares:
        - coolify-getcloud-https
      service: coolify-ui
    coolify-getcloud-https:
      rule: Host(`getcloud.sh`)
      entryPoints:
        - websecure
      tls:
        certResolver: letsencrypt
      service: coolify-ui
  middlewares:
    coolify-getcloud-https:
      redirectScheme:
        scheme: https
  services:
    coolify-ui:
      loadBalancer:
        servers:
          # Docker host gateway from the Traefik task (common on Linux):
          - url: "http://172.17.0.1:8000"
```

4. Prefer attaching Coolify to the `easypanel` network and using `http://coolify:8080` instead of `172.17.0.1:8000` when possible:

```bash
docker network connect easypanel coolify 2>/dev/null || true
# then set servers url to http://coolify:8080
```

5. Verify:

```bash
docker service ls --format '{{.Name}}' | while read s; do
  docker service inspect "$s" --format '{{.Spec.Name}} {{json .Spec.TaskTemplate.ContainerSpec.Labels}}'
done | grep -i getcloud || true
curl -kI https://getcloud.sh | head -15
```

Expect `200`/`302` from Coolify login, not Traefik’s HTML 404.

## Local image build note

`docker/production/Dockerfile` does **not** require BuildKit cache mounts, but recent Docker CLI **requires the buildx plugin** (there is no usable classic builder). `scripts/upgrade-local.sh` installs `docker-buildx-plugin` (or a buildx binary) when missing. Manual fix on the host:

```bash
apt-get update && apt-get install -y docker-buildx-plugin
docker buildx version
```
