#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${DEPLOY_HOST:?Set SAKURA_HOST}" "${DEPLOY_USER:?Set SAKURA_USER}"
: "${DEPLOY_PUBLIC:?Set SAKURA_PUBLIC_DIR}" "${DEPLOY_PRIVATE:?Set SAKURA_PRIVATE_DIR}"
: "${DEPLOY_KEY:?Set SAKURA_SSH_KEY}" "${DEPLOY_KNOWN_HOSTS:?Set SAKURA_KNOWN_HOSTS}"
DEPLOY_PHP=${DEPLOY_PHP:-/usr/local/bin/php}
[[ $DEPLOY_HOST =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]+$ ]]
[[ $DEPLOY_USER =~ ^[a-zA-Z0-9][a-zA-Z0-9_-]*$ ]]
for path in "$DEPLOY_PUBLIC" "$DEPLOY_PRIVATE" "$DEPLOY_PHP"; do
  [[ $path =~ ^/[a-zA-Z0-9_./-]+$ ]] && [[ $path != *..* ]]
done
[[ ${GITHUB_RUN_ID:?} =~ ^[0-9]+$ ]]
[[ ${GITHUB_RUN_ATTEMPT:?} =~ ^[0-9]+$ ]]
[[ ${GITHUB_SHA:?} =~ ^[a-f0-9]{40}$ ]]
work=$(mktemp -d)
phase=preparation
trap 'rm -rf "$work"' EXIT
trap 'status=$?; printf "::error::Deployment failed during: %s (exit %s).\n" "$phase" "$status" >&2; exit "$status"' ERR
umask 077
printf '%s\n' "$DEPLOY_KEY" > "$work/key"
printf '%s\n' "$DEPLOY_KNOWN_HOSTS" > "$work/known_hosts"
unset DEPLOY_KEY DEPLOY_KNOWN_HOSTS
ssh_options=(-i "$work/key" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 -o "UserKnownHostsFile=$work/known_hosts")
target="$DEPLOY_USER@$DEPLOY_HOST"
stage=".trail-card-deploy/$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"

phase='SSH connection and PHP check'
echo '[1/4] Checking SSH connection and PHP (maximum 60 seconds)...'
timeout 60s ssh -n "${ssh_options[@]}" "$target" \
  "sh -c 'set -eu; echo SSH-connected; \"$DEPLOY_PHP\" -v'"

phase='release packaging'
echo '[2/4] Creating release archive...'
tar -czf "$work/release.tgz" public private/src private/bin private/schema.sql deploy/install.php

phase='release upload'
echo '[3/4] Preparing remote directory (maximum 60 seconds)...'
timeout 60s ssh -n "${ssh_options[@]}" "$target" "sh -c 'umask 077; mkdir -p \"$stage\"'"
echo '[3/4] Uploading release (maximum 120 seconds)...'
# Use the working SSH command channel rather than SCP's SFTP subsystem.
# stdin carries the archive, so this SSH invocation must not use -n.
# Keep incomplete transfers under .part and publish only after cat succeeds.
timeout 120s ssh "${ssh_options[@]}" "$target" \
  "sh -c 'set -eu; umask 077; cat > \"$stage/release.tgz.part\"; mv \"$stage/release.tgz.part\" \"$stage/release.tgz\"; echo Upload-complete'" \
  < "$work/release.tgz"

phase='server installation'
echo '[4/4] Starting server backup, migration and installation...'
echo 'If this step fails, check the server maintenance state before retrying.'
# Explicit sh avoids depending on the account login shell. Do not impose a
# wall-clock timeout on DB backup/migration: interruption can leave maintenance enabled.
ssh -n "${ssh_options[@]}" "$target" \
  "sh -c 'set -eu; cd \"$stage\"; echo Extracting-release; tar -xzf release.tgz; echo Starting-PHP-installer; \"$DEPLOY_PHP\" deploy/install.php \"$DEPLOY_PRIVATE\" \"$DEPLOY_PUBLIC\" \"$GITHUB_SHA\"'"
echo 'Deployment completed. Check https://over.rulez.jp/card/ on your phone.'
