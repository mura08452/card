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
trap 'rm -rf "$work"' EXIT
umask 077
printf '%s\n' "$DEPLOY_KEY" > "$work/key"
printf '%s\n' "$DEPLOY_KNOWN_HOSTS" > "$work/known_hosts"
unset DEPLOY_KEY DEPLOY_KNOWN_HOSTS
ssh_options=(-i "$work/key" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$work/known_hosts")
target="$DEPLOY_USER@$DEPLOY_HOST"
stage=".trail-card-deploy/$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
tar -czf "$work/release.tgz" public private/src private/bin private/schema.sql deploy/install.php
ssh "${ssh_options[@]}" "$target" "umask 077; mkdir -p '$stage'"
scp "${ssh_options[@]}" "$work/release.tgz" "$target:$stage/release.tgz"
ssh "${ssh_options[@]}" "$target" \
  "set -eu; cd '$stage'; tar -xzf release.tgz; '$DEPLOY_PHP' deploy/install.php '$DEPLOY_PRIVATE' '$DEPLOY_PUBLIC' '$GITHUB_SHA'"
echo 'Deployment completed. Check https://over.rulez.jp/card/ on your phone.'
