#!/usr/bin/env python3
"""Check the standalone scanner independently of application workers."""
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import shlex
import socket
import subprocess
import time


def verdict(container, version, now, max_age):
    problems = []
    state = container.get("State", {})
    if not state.get("Running"):
        problems.append("container_down")
    if state.get("Health", {}).get("Status") != "healthy":
        problems.append("container_unhealthy")
    try:
        engine, definitions, stamp = version.split("/", 2)
        if not engine.startswith("ClamAV ") or not definitions.isdigit():
            raise ValueError("Invalid engine metadata")
        published = dt.datetime.strptime(stamp.strip(), "%a %b %d %H:%M:%S %Y").replace(tzinfo=dt.timezone.utc).timestamp()
        if published > now + 3600:
            problems.append("signature_date_invalid")
        elif now - published > max_age:
            problems.append("signatures_stale")
    except (ValueError, AttributeError):
        problems.append("scanner_unreachable_or_invalid_version")
    return sorted(set(problems))


def probe(name, max_age):
    try:
        result = subprocess.run(["docker", "inspect", name], capture_output=True, text=True, timeout=15, check=True)
        container = json.loads(result.stdout)[0]
        network = container["NetworkSettings"]["Networks"]["gereq-malware-scanner"]
        with socket.create_connection((network["IPAddress"], 3310), timeout=5) as connection:
            connection.sendall(b"zVERSION\0")
            response = b""
            while len(response) < 8192 and not response.endswith(b"\0"):
                part = connection.recv(1024)
                if not part:
                    break
                response += part
        if not response.endswith(b"\0"):
            raise ValueError("Incomplete VERSION response")
        version = response[:-1].decode("ascii")
        return verdict(container, version, time.time(), max_age), version
    except (subprocess.SubprocessError, OSError, ValueError, KeyError, IndexError):
        return ["scanner_down_or_unreachable"], ""


def transition(state, problems, now):
    fingerprint = ",".join(problems)
    failures = state.get("failures", 0) + 1 if problems else 0
    event = None
    if problems and failures >= 2:
        if fingerprint != state.get("notified_problem") or now - state.get("notified_at", 0) >= 21600:
            event = "alert"
    elif not problems and state.get("notified_problem"):
        event = "recovery"
    return {**state, "failures": failures, "problems": problems, "checked_at": now}, event


def save(path, state):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(state) + "\n")
    temporary.chmod(0o600)
    temporary.replace(path)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--container", default="gereq-clamav")
    parser.add_argument("--state", type=Path, default=Path("/var/lib/gereq-malware-monitor/state.json"))
    parser.add_argument("--max-age-hours", type=int, default=48)
    parser.add_argument("--check-only", action="store_true")
    args = parser.parse_args()
    problems, version = probe(args.container, max(1, args.max_age_hours) * 3600)
    now = time.time()
    print(json.dumps({"container": args.container, "problems": problems, "version": version}), flush=True)
    if args.check_only:
        return int(bool(problems))
    state = json.loads(args.state.read_text()) if args.state.exists() else {}
    state, event = transition(state, problems, now)
    if event:
        payload = json.dumps({"event": event, "host": socket.gethostname(), "container": args.container,
                              "problems": problems, "version": version, "time": dt.datetime.now(dt.timezone.utc).isoformat()})
        command = os.environ.get("MALWARE_NOTIFY_COMMAND", "")
        delivered = False
        if command:
            try:
                result = subprocess.run(shlex.split(command), input=payload + "\n", text=True,
                                        capture_output=True, timeout=30, check=False)
                delivered = result.returncode == 0
            except (OSError, subprocess.SubprocessError, ValueError):
                pass
        if delivered:
            state["notified_problem"] = ",".join(problems)
            state["notified_at"] = now
        else:
            print("ALERT_DELIVERY_PENDING " + payload, flush=True)
        save(args.state, state)
        if not delivered:
            return 1
    else:
        save(args.state, state)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
