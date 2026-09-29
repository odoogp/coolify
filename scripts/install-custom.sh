#!/bin/bash
# Install this Coolify fork from the git checkout.
# Builds docker/production/Dockerfile as coolify-custom:local and does not pull
# docker.io/coollabsio/coolify.
#
# Fresh server:
#   git clone https://github.com/odoogp/coolify.git
#   cd coolify
#   git checkout 11366-terminal-websocket-connection
#   ./scripts/install-custom.sh
#
# Equivalent:
#   COOLIFY_LOCAL_BUILD=true ./scripts/install.sh
#
# Update an existing local install:
#   cd /path/to/coolify
#   git pull
#   COOLIFY_LOCAL_BUILD=true ./scripts/install.sh

export COOLIFY_LOCAL_BUILD=true
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
exec "$SCRIPT_DIR/install.sh" "$@"
