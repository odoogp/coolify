#!/usr/bin/env bash
# Agente de GetCloud: conecta este servidor a GetCloud con un túnel de salida de Cloudflare.
# Agrega la llave SSH de GetCloud para root, instala Docker si falta y arranca un
# contenedor pequeño (getcloud-agent). No hace falta abrir el puerto SSH.
set -euo pipefail

GETCLOUD_KEY='{!! $publicKey !!} getcloud-{!! $enrollment->uuid !!}'
GETCLOUD_MARK='getcloud-{!! $enrollment->uuid !!}'
GETCLOUD_TUNNEL_TOKEN='{!! $tunnelToken !!}'
GETCLOUD_READY_URL='{!! $readyUrl !!}'
GETCLOUD_AGENT_IMAGE='{!! $cloudflaredImage !!}'
@verbatim

say() { printf '\n\033[1m%s\033[0m\n' "$1"; }
warn() { printf '\033[33m%s\033[0m\n' "$1"; }
fail() { printf '\033[31m%s\033[0m\n' "$1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Ejecuta este comando con sudo o como root."
command -v curl >/dev/null 2>&1 || fail "Hace falta curl. Instálalo y vuelve a ejecutar el comando."

say "1/4 Revisando este servidor"
if command -v ss >/dev/null 2>&1; then
  for port in 80 443; do
    if ss -ltnH "( sport = :$port )" 2>/dev/null | grep -q .; then
      warn "El puerto $port ya está en uso. Tus apps necesitan los puertos 80 y 443 libres para recibir visitas."
    fi
  done
fi
if ! (command -v sshd >/dev/null 2>&1 || [ -x /usr/sbin/sshd ]); then
  fail "Falta el servidor OpenSSH. Instala openssh-server y vuelve a ejecutar el comando."
fi

say "2/4 Permitiendo que GetCloud administre este servidor"
mkdir -p /root/.ssh
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
sed -i "/ ${GETCLOUD_MARK}\$/d" /root/.ssh/authorized_keys
printf '%s\n' "$GETCLOUD_KEY" >> /root/.ssh/authorized_keys

say "3/4 Instalando Docker (se omite si ya está instalado)"
if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker >/dev/null 2>&1 || true

say "4/4 Arrancando el agente de GetCloud"
docker rm -f getcloud-agent >/dev/null 2>&1 || true
docker run -d --name getcloud-agent --restart unless-stopped --network host \
  "$GETCLOUD_AGENT_IMAGE" tunnel --no-autoupdate run --token "$GETCLOUD_TUNNEL_TOKEN" >/dev/null

PUBLIC_IP="$(curl -fsS --max-time 5 https://1.1.1.1/cdn-cgi/trace 2>/dev/null | sed -n 's/^ip=//p' || true)"
if [ -z "$PUBLIC_IP" ]; then
  PUBLIC_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
fi

curl -fsS -X POST --max-time 15 -d "public_ip=${PUBLIC_IP}" "$GETCLOUD_READY_URL" >/dev/null \
  || fail "El agente está corriendo, pero no se pudo avisar a GetCloud. Vuelve a ejecutar el comando."

say "Listo. Vuelve a GetCloud: tu servidor aparecerá conectado en un minuto."
echo "Para desconectarlo después: docker rm -f getcloud-agent && sed -i '/ ${GETCLOUD_MARK}\$/d' /root/.ssh/authorized_keys"
@endverbatim
