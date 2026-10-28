#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 4 ]; then
  echo "Usage: accept-local.sh PLATFORM FRONTEND_DIST NEW_STATE_ROOT NEW_BUILD_ROOT" >&2
  exit 2
fi
provision_dir=$(cd -- "$(dirname -- "$0")" && pwd)
platform_dir=$(realpath -- "$1")
frontend_dir=$(realpath -- "$2")
state_root=$(realpath -m -- "$3")
build_root=$(realpath -m -- "$4")
if [ -e "$state_root" ] || [ -e "$build_root" ]; then
  echo "State/build roots must be new; refusing to modify existing paths" >&2
  exit 2
fi
export PODMAN_COMPOSE_PROVIDER="${PODMAN_COMPOSE_PROVIDER:-podman-compose}"
podman info >/dev/null
podman compose version
mkdir -m 700 -p -- "$build_root"
(cd "$provision_dir" && go build -o "$build_root/provisioner" .)
python3 "$provision_dir/prepare-release.py" --platform "$platform_dir" --frontend-dist "$frontend_dir" --output "$build_root/context"
podman build -t localhost/gereq-local:dev "$build_root/context"
for customer in customer-one customer-two; do
  "$build_root/provisioner" create --root "$state_root" --request "$provision_dir/examples/$customer.json" --release "$provision_dir/examples/release.json"
done
# Repeating create must preserve the same instance and credentials.
"$build_root/provisioner" create --root "$state_root" --request "$provision_dir/examples/customer-one.json" --release "$provision_dir/examples/release.json"
for item in customer-one:18081 customer-two:18082; do
  customer=${item%:*};port=${item#*:}
  curl --fail --silent --show-error --noproxy '*' --resolve "$customer.localhost:$port:127.0.0.1" "http://$customer.localhost:$port/_provision/health" | python3 -c 'import sys; assert sys.stdin.read()=="ok"'
  curl --fail --silent --show-error --noproxy '*' --resolve "$customer.localhost:$port:127.0.0.1" "http://$customer.localhost:$port/api/v1/health" >/dev/null
done
"$build_root/provisioner" import --root "$state_root" --site customer-one --artifact "$provision_dir/examples/draft.json" > "$build_root/import-one.json"
"$build_root/provisioner" import --root "$state_root" --site customer-one --artifact "$provision_dir/examples/draft.json" > "$build_root/import-two.json"
python3 - "$state_root" "$build_root" <<'PY'
import json,pathlib,sqlite3,sys
root=pathlib.Path(sys.argv[1]);build=pathlib.Path(sys.argv[2])
assert json.loads((build/'import-one.json').read_text())['page_id']==json.loads((build/'import-two.json').read_text())['page_id']
for name,expected in [('customer-one',1),('customer-two',0)]:
 with sqlite3.connect(root/'sites'/name/'storage/database.sqlite') as c:
  assert c.execute('select count(*) from cms_pages').fetchone()[0]==expected
  if expected: assert c.execute('select status from cms_pages').fetchone()[0]=='draft'
one=(root/'sites/customer-one/.env').read_text();two=(root/'sites/customer-two/.env').read_text()
def key(s):return next(x for x in s.splitlines() if x.startswith('APP_KEY='))
assert key(one)!=key(two)
(root/'sites/customer-one/storage/isolation-canary.txt').write_text('customer-one only')
assert not (root/'sites/customer-two/storage/isolation-canary.txt').exists()
PY
"$build_root/provisioner" stop --root "$state_root" --site customer-one
"$build_root/provisioner" start --root "$state_root" --site customer-one
python3 - "$state_root" <<'PY'
import pathlib,sqlite3,sys
root=pathlib.Path(sys.argv[1]);assert (root/'sites/customer-one/storage/isolation-canary.txt').read_text()=='customer-one only'
with sqlite3.connect(root/'sites/customer-one/storage/database.sqlite') as c: assert c.execute('select count(*) from cms_pages').fetchone()[0]==1
PY
printf 'PASS: two local instances, separate SQLite/storage/keys, retry/import idempotency and restart persistence.\n'
printf 'Operator setup is still required. Instances remain running for inspection.\n'
