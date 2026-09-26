#!/bin/bash
# ==============================================================================
# PSOBB Web Portal — setup_permissions.sh (Wrapper for setup.sh)
# ==============================================================================
# Delegates execution to setup.sh which handles directory creation,
# database migrations, NewServ connectivity checks, and POSIX permissions.
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "$SCRIPT_DIR/setup.sh" "$@"
