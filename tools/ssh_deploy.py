#!/usr/bin/env python3
"""Deploy via SSH (password or key). Reads credentials from .env without printing secrets."""

from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip().strip('"').strip("'")
        values[key.strip()] = value
    return values


def try_paramiko(target: dict) -> bool:
    try:
        import paramiko  # type: ignore
    except ImportError:
        subprocess.check_call([sys.executable, "-m", "pip", "install", "paramiko", "-q"])
        import paramiko  # type: ignore

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        connect_kwargs = {
            "hostname": target["host"],
            "port": target["port"],
            "username": target["user"],
            "timeout": 12,
            "allow_agent": False,
            "look_for_keys": False,
        }
        if target.get("password"):
            connect_kwargs["password"] = target["password"]
        if target.get("key"):
            connect_kwargs["key_filename"] = target["key"]
        client.connect(**connect_kwargs)
        for cmd in target.get("commands", ["hostname", "docker ps --format '{{.Names}}' 2>/dev/null || true"]):
            stdin, stdout, stderr = client.exec_command(cmd)
            out = stdout.read().decode("utf-8", errors="replace").strip()
            err = stderr.read().decode("utf-8", errors="replace").strip()
            print(f"[{target['label']}] $ {cmd}")
            if out:
                print(out)
            if err:
                print(err, file=sys.stderr)
        client.close()
        return True
    except Exception as exc:
        print(f"[{target['label']}] FAIL: {exc}", file=sys.stderr)
        client.close()
        return False


def main() -> int:
    env = load_env(ROOT / ".env")
    password = env.get("DEPLOY_SSH_PASSWORD") or env.get("SSH_PASSWORD") or env.get("DB_PASSWORD") or ""
    key = os.path.expanduser(r"~/.ssh/id_ed25519_coresuite")
    key = key if os.path.isfile(key) else ""

    targets = [
        {
            "label": "docker-local",
            "host": env.get("DEPLOY_HOST", "192.168.1.50"),
            "port": int(env.get("DEPLOY_PORT", "22")),
            "user": env.get("DEPLOY_USER", "Carmine"),
            "password": password,
            "key": key,
            "commands": [
                "hostname",
                "docker ps --format '{{.Names}}' 2>/dev/null || echo 'docker non disponibile'",
            ],
        },
        {
            "label": "hostinger-shared",
            "host": env.get("HOSTINGER_SSH_HOST", "188.114.97.7"),
            "port": int(env.get("HOSTINGER_SSH_PORT", "65002")),
            "user": env.get("HOSTINGER_SSH_USER", env.get("DB_USERNAME", "u427445037")),
            "password": password,
            "key": key,
            "commands": [
                "hostname",
                f"ls -la {env.get('HOSTINGER_DEPLOY_PATH', '/home/u427445037/domains/coresuite.it/public_html/demobusiness')} | head -5",
            ],
        },
    ]

    ok = False
    for target in targets:
        if try_paramiko(target):
            ok = True
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
