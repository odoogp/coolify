<?php

namespace App\Support;

use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * Beszel for one Odoo branch. One container runs the panel, the agent, and
 * the filter that keeps only that stack's Odoo and PostgreSQL containers.
 */
class OdooMonitor
{
    public const SERVICE_NAME = 'monitor';

    public static function inject(string $compose, string $project): string
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $project) !== 1) {
            return $compose;
        }

        try {
            $yaml = Yaml::parse($compose);
        } catch (\Throwable) {
            return $compose;
        }
        if (! is_array($yaml) || ! OdooJupyter::isOdooCompose($compose)) {
            return $compose;
        }

        $services = $yaml['services'] ?? null;
        if (! is_array($services)) {
            return $compose;
        }
        $image = (string) data_get($services, self::SERVICE_NAME.'.image', '');
        $command = (string) data_get($services, self::SERVICE_NAME.'.command.0', '');
        if ($image === 'python:3.12-alpine' && str_contains($command, 'universal-token') && ! isset($services['beszelagent']) && ! isset($services['beszelfilter'])) {
            return $compose;
        }

        unset($services['cadvisor'], $services['prometheus'], $services['beszelagent'], $services['beszelfilter']);
        $allow = 'odoo-'.$project.',postgresql-'.$project.',postgres-'.$project;
        $services[self::SERVICE_NAME] = [
            'image' => 'python:3.12-alpine',
            'restart' => 'unless-stopped',
            'user' => '0:0',
            'entrypoint' => ['python', '-c'],
            'command' => [self::filterScript()],
            'environment' => [
                'SERVICE_URL_MONITOR_8090',
                'APP_URL=https://${SERVICE_FQDN_MONITOR}',
                'AUTO_LOGIN=monitor@gpsh.local',
                'USER_EMAIL=monitor@gpsh.local',
                'USER_PASSWORD=${SERVICE_PASSWORD_MONITOR}',
                'TOKEN=${SERVICE_PASSWORD_MONITOR}',
                'HUB=http://127.0.0.1:8090',
                'ALLOW='.$allow,
                'RETIRE=cadvisor-'.$project.',prometheus-'.$project.',beszelagent-'.$project.',beszelfilter-'.$project,
                'SYSTEM_NAME=odoo-'.$project,
                'DISABLE_SSH=true',
                'CONTAINER_DETAILS=true',
                'SKIP_GPU=true',
                'DOCKER_IMAGE_CHECK=false',
            ],
            'volumes' => self::filterVolumes(),
        ];
        $yaml['services'] = $services;
        $volumes = $yaml['volumes'] ?? [];
        if (! is_array($volumes)) {
            $volumes = [];
        }
        unset($volumes['beszel-run']);
        $volumes['beszel-data'] = ['driver' => 'local'];
        $yaml['volumes'] = $volumes;

        return Yaml::dump($yaml, 8, 2);
    }

    /**
     * @param  list<string>  $present
     */
    public static function forgetExtraApplications(Service $service, array $present): void
    {
        $extra = array_values(array_diff(['beszelagent', 'beszelfilter', 'cadvisor', 'prometheus'], $present));
        if ($extra === []) {
            return;
        }

        $service->applications()->whereIn('name', $extra)->delete();
    }

    public static function urlFor(Service $service): ?string
    {
        if (! $service->supportsOdooJupyter()) {
            return null;
        }

        $monitor = $service->applications()->get()->firstWhere('name', self::SERVICE_NAME);
        if (! filled($monitor?->fqdn)) {
            return null;
        }

        $odoo = self::containerName($service, 'odoo');
        $postgres = self::containerName($service, 'postgres');
        if ($odoo === null || $postgres === null) {
            return null;
        }

        return self::dashboardUrl(getFqdnWithoutPort(firstDomainFromList((string) $monitor->fqdn)), $odoo, $postgres);
    }

    public static function dashboardUrl(string $base, string $odoo, string $postgres): ?string
    {
        $base = rtrim($base, '/');
        if (preg_match('#^https?://[A-Za-z0-9.-]+$#', $base) !== 1) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $odoo) !== 1 || preg_match('/^[A-Za-z0-9_.-]+$/', $postgres) !== 1) {
            return null;
        }

        return $base;
    }

    /**
     * The service parser rewrites host binds other than the Docker socket.
     * Monitor is the only container that may hold that socket.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    public static function alignServices(array $services): array
    {
        if (! isset($services[self::SERVICE_NAME]) || ! is_array($services[self::SERVICE_NAME])) {
            return $services;
        }
        if (($services[self::SERVICE_NAME]['image'] ?? '') !== 'python:3.12-alpine') {
            return $services;
        }

        $kept = [];
        foreach ($services[self::SERVICE_NAME]['volumes'] ?? [] as $volume) {
            if (is_string($volume) && ! str_starts_with($volume, '/var/run/docker.sock:')) {
                $kept[] = $volume;
            }
        }
        array_unshift($kept, '/var/run/docker.sock:/var/run/docker.sock:ro');
        $services[self::SERVICE_NAME]['volumes'] = $kept;

        return $services;
    }

    /**
     * @return list<string>
     */
    public static function filterVolumes(): array
    {
        return [
            '/var/run/docker.sock:/var/run/docker.sock:ro',
            'beszel-data:/beszel_data',
        ];
    }

    public static function hidesTerminal(string $name): bool
    {
        return in_array(strtolower($name), ['beszelagent', 'beszelfilter', self::SERVICE_NAME], true);
    }

    /**
     * ponytail: Beszel ships the panel and the agent as two binaries, and the
     * agent can exclude container names but not include them. This one process
     * downloads both binaries (cached in the data volume, pinned to 0.21.0)
     * and proxies the Docker socket. If Beszel grows an allow-list, drop the proxy.
     */
    private static function filterScript(): string
    {
        return <<<'PY'
import json, os, platform, signal, socket, subprocess, sys, tarfile, threading, time, urllib.parse, urllib.request
from http.client import HTTPConnection
from pathlib import Path

VERSION = "0.21.0"
ALLOW = {n for n in os.environ.get("ALLOW", "").split(",") if n}
RETIRE = {n for n in os.environ.get("RETIRE", "").split(",") if n}
HUB = os.environ.get("HUB", "http://127.0.0.1:8090")
TOKEN = os.environ.get("TOKEN", "")
SOCK = "/tmp/beszel.sock"
REAL = "/var/run/docker.sock"
KEY = Path("/beszel_data/key")
ARCH = {"x86_64": "amd64", "aarch64": "arm64", "armv7l": "armv7"}.get(platform.machine(), "")
ids = set()
lock = threading.Lock()
hub = None
agent = None

class UnixHTTP(HTTPConnection):
    def __init__(self, path):
        super().__init__("localhost")
        self._path = path
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(20)
        self.sock.connect(self._path)

def call(method, path):
    up = UnixHTTP(REAL)
    up.request(method, path)
    resp = up.getresponse()
    body = resp.read()
    ctype = (resp.getheader("Content-Type") or "application/octet-stream").split("\n", 1)[0]
    up.close()
    return resp.status, ctype, body

def retire():
    try:
        status, _ctype, body = call("GET", "/containers/json?all=1")
        if status != 200:
            return
        for item in json.loads(body):
            names = [n.lstrip("/") for n in item.get("Names") or []]
            if not any(n in RETIRE for n in names):
                continue
            cid = item.get("Id") or ""
            if cid:
                call("POST", "/containers/" + cid + "/stop?t=2")
                call("DELETE", "/containers/" + cid + "?force=1")
    except Exception:
        pass

def fetch(member, filename):
    dest = Path("/beszel_data/bin") / member
    if dest.is_file() and dest.stat().st_size > 100000:
        return dest
    if not ARCH:
        raise SystemExit("unsupported cpu")
    dest.parent.mkdir(parents=True, exist_ok=True)
    url = "https://github.com/henrygd/beszel/releases/download/v" + VERSION + "/" + filename
    with urllib.request.urlopen(url, timeout=60) as res:
        raw = res.read()
    import io
    with tarfile.open(fileobj=io.BytesIO(raw), mode="r:gz") as tar:
        picked = None
        for item in tar.getmembers():
            if item.isfile() and item.name.split("/")[-1] == member and ".." not in item.name:
                picked = item
                break
        if picked is None:
            raise SystemExit("missing " + member)
        src = tar.extractfile(picked)
        dest.write_bytes(src.read())
    os.chmod(dest, 0o755)
    return dest

def bootstrap():
    if not TOKEN:
        return
    auth = None
    for _ in range(40):
        if hub is not None and hub.poll() is not None:
            return
        try:
            req = urllib.request.Request(
                HUB + "/api/collections/users/auth-with-password",
                data=json.dumps({"identity": "monitor@gpsh.local", "password": TOKEN}).encode(),
                headers={"Content-Type": "application/json"},
            )
            with urllib.request.urlopen(req, timeout=3) as res:
                auth = json.loads(res.read().decode()).get("token")
            if auth:
                break
        except Exception:
            time.sleep(2)
    if not auth:
        return
    headers = {"Authorization": auth}
    q = urllib.parse.urlencode({"enable": "1", "permanent": "1", "token": TOKEN})
    for _ in range(10):
        try:
            req = urllib.request.Request(HUB + "/api/beszel/universal-token?" + q, headers=headers)
            with urllib.request.urlopen(req, timeout=3) as res:
                data = json.loads(res.read().decode())
            if data.get("active"):
                break
        except Exception:
            time.sleep(1)
    for _ in range(10):
        try:
            req = urllib.request.Request(HUB + "/api/beszel/getkey", headers=headers)
            with urllib.request.urlopen(req, timeout=3) as res:
                key = json.loads(res.read().decode()).get("key", "").strip()
            if key:
                KEY.with_name("key.tmp").write_text(key + "\n")
                os.replace(KEY.with_name("key.tmp"), KEY)
                return
        except Exception:
            time.sleep(1)

def id_ok(cid):
    if len(cid) < 12:
        return False
    with lock:
        return any(cid == known or known.startswith(cid) for known in ids)

def handle(conn):
    try:
        data = b""
        while b"\r\n\r\n" not in data and len(data) < 65536:
            chunk = conn.recv(4096)
            if not chunk:
                break
            data += chunk
        head = data.split(b"\r\n", 1)[0].decode("latin1", "replace")
        parts = head.split(" ")
        if len(parts) < 2 or parts[0] != "GET":
            conn.sendall(b"HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
            return
        path = parts[1].split("?", 1)[0]
        kind = None
        if path in ("/version", "/info", "/_ping"):
            kind = "pass"
        elif path == "/containers/json":
            kind = "list"
        else:
            bits = path.strip("/").split("/")
            if len(bits) == 3 and bits[0] == "containers" and bits[2] in ("json", "stats", "logs") and id_ok(bits[1]):
                kind = "pass"
        if kind is None:
            conn.sendall(b"HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
            return
        status, ctype, body = call("GET", parts[1] if kind == "pass" else "/containers/json")
        if kind == "list" and status == 200:
            kept = []
            found = set()
            for item in json.loads(body):
                names = [n.lstrip("/") for n in item.get("Names") or []]
                if not any(n in ALLOW for n in names):
                    continue
                kept.append(item)
                cid = item.get("Id") or ""
                if cid:
                    found.add(cid)
                    found.add(cid[:12])
            with lock:
                ids.clear()
                ids.update(found)
            body = json.dumps(kept).encode()
            ctype = "application/json"
            status = 200
        payload = b"HTTP/1.1 " + str(status).encode() + b" OK\r\nContent-Type: " + ctype.encode() + b"\r\nContent-Length: " + str(len(body)).encode() + b"\r\nConnection: close\r\n\r\n" + body
        conn.sendall(payload)
    except Exception:
        try:
            conn.sendall(b"HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
        except Exception:
            pass
    finally:
        conn.close()

def serve():
    try:
        os.unlink(SOCK)
    except FileNotFoundError:
        pass
    srv = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    srv.bind(SOCK)
    os.chmod(SOCK, 0o666)
    srv.listen(32)
    while True:
        conn, _ = srv.accept()
        threading.Thread(target=handle, args=(conn,), daemon=True).start()

def stop(*_args):
    if hub is not None:
        hub.terminate()
    if agent is not None:
        agent.terminate()
    sys.exit(0)

def main():
    global hub, agent
    hub_bin = fetch("beszel", "beszel_linux_" + ARCH + ".tar.gz")
    agent_bin = fetch("beszel-agent", "beszel-agent_linux_" + ARCH + ".tar.gz")
    hub = subprocess.Popen([str(hub_bin), "serve", "--http=0.0.0.0:8090"])
    signal.signal(signal.SIGTERM, stop)
    threading.Thread(target=retire, daemon=True).start()
    threading.Thread(target=serve, daemon=True).start()
    bootstrap()
    if not KEY.is_file():
        hub.terminate()
        sys.exit(1)
    env = os.environ.copy()
    env.update({
        "HUB_URL": HUB,
        "KEY_FILE": str(KEY),
        "DOCKER_HOST": "unix://" + SOCK,
        "DATA_DIR": "/beszel_data/agent",
    })
    agent = subprocess.Popen([str(agent_bin)], env=env)
    while True:
        if hub.poll() is not None or agent.poll() is not None:
            stop()
        time.sleep(2)

main()
PY;
    }

    private static function containerName(Service $service, string $kind): ?string
    {
        $uuid = (string) $service->uuid;
        if (preg_match('/^[A-Za-z0-9_-]+$/', $uuid) !== 1) {
            return null;
        }

        if ($kind === 'odoo') {
            $row = $service->applications()->get()->first(function ($application): bool {
                $name = strtolower((string) $application->name);

                return str_contains($name, 'odoo') && ! str_contains($name, 'jupyter');
            });
        } else {
            $row = $service->databases()->get()->first(function ($database): bool {
                return str_contains(strtolower((string) $database->name), 'postgres');
            });
        }

        $name = (string) ($row->name ?? '');
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            return null;
        }

        return $name.'-'.$uuid;
    }
}
