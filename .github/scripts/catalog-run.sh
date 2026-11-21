#!/usr/bin/env bash
# Runs a catalog script the way SshRunner runs it in the container: variables from an env file,
# then "set -euo pipefail", DEBIAN_FRONTEND=noninteractive and cd /root (see SshRunner::runScript).
# Usage: sudo catalog-run.sh <env-file> <script>
set -u
env_file=$(realpath "$1")
script=$(realpath "$2")
dir=$(mktemp -d)
{
  echo 'set -a'
  echo ". '${env_file}'"
  echo 'set +a'
  echo 'set -euo pipefail'
  echo 'export DEBIAN_FRONTEND=noninteractive'
  echo 'cd /root'
  cat "$script"
} > "$dir/run.sh"
exec timeout --kill-after=15 3600 bash "$dir/run.sh"
