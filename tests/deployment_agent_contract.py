#!/usr/bin/env python3
"""Real loopback Pro-agent probe and safe CLI diagnostics; no production service."""
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = ["php", str(ROOT / "scripts" / "deployment-preflight.php")]
CHECKS = 0

def check(test, message):
    global CHECKS
    if not test:
        raise AssertionError(message)
    CHECKS += 1


def scan(expected="lite", document_root=None, phase="preinstall", env=None):
    args = PHP + [f"--document-root={document_root or ROOT / 'public'}", f"--expected-edition={expected}", f"--phase={phase}", "--json=1"]
    proc = subprocess.run(args, env=env, capture_output=True, text=True, timeout=15)
    return proc.returncode, json.loads(proc.stdout), proc.stderr


def main():
    code, lite, err = scan()
    check(code in (0, 2) and not err, "Lite diagnostic emits JSON and stable exit code")
    check(lite["schema"] == "adult-core-deployment-preflight-v1", "Versioned report")
    check(lite["phase"] == "preinstall", "Expected preinstall status")
    check(next(x for x in lite["checks"] if x["id"] == "installation_gate")["pass"], "Installer locked until completed")
    check(lite["external_verification_required"], "Does not claim hosting certification")
    code, wrong, _ = scan(document_root=ROOT)
    check(code == 2 and wrong["result"] == "blocked", "Wrong docroot fails")
    check(not next(x for x in wrong["checks"] if x["id"] == "host_secure_public_document_root")["pass"], "Docroot mismatch explicit")
    bad = subprocess.run(PHP + ["--expected-edition=lite"], capture_output=True, text=True)
    check(bad.returncode == 64, "Missing docroot refused")
    unknown = subprocess.run(PHP + ["--document-root=/tmp", "--expected-edition=lite", "--phase=preinstall", "--extra=value"], capture_output=True, text=True)
    check(unknown.returncode == 64, "Unknown options refused")

    # Pro status must originate from a live token-authenticated loopback service,
    # not an edition flag. This is only the demo and never production certification.
    token = secrets.token_urlsafe(42)
    env = dict(os.environ, AFTERBURN_PRO_AGENT_TOKEN=token)
    server = subprocess.Popen([sys.executable, str(ROOT / "scripts" / "pro-agent-demo.py")],
                              env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        import urllib.request
        alive = False
        for _ in range(40):
            try:
                req = urllib.request.Request("http://127.0.0.1:8787/v1/status", headers={"Authorization": "Bearer " + token})
                with urllib.request.urlopen(req, timeout=0.25) as resp:
                    alive = json.loads(resp.read())["status"] == "ok"
                if alive: break
            except OSError:
                time.sleep(0.1)
        check(alive, "Authenticated loopback demo running")
        _, pro, _ = scan(expected="pro", env=env)
        by_id = {x["id"]: x for x in pro["checks"]}
        check(by_id["host_verified_pro_background_service"]["pass"], "Agent accepted only with matching token")
        capability = by_id["edition_capabilities"]
        check(capability["pass"] or "pro_agent" not in capability["detail"],
              "Pro agent capability satisfied whether all requirements pass or another dependency is missing")
        wrong_env = dict(env, AFTERBURN_PRO_AGENT_TOKEN=secrets.token_urlsafe(42))
        _, badpro, _ = scan(expected="pro", env=wrong_env)
        check(not next(x for x in badpro["checks"] if x["id"] == "host_verified_pro_background_service")["pass"],
              "Wrong Pro agent token refused")
        check("pro_agent" in next(x["detail"] for x in badpro["checks"] if x["id"] == "edition_capabilities"),
              "Pro capability missing with wrong token")
        check("production-supervised" in " ".join(pro["external_verification_required"]),
              "Local demo not treated as production")
    finally:
        server.terminate()
        try: server.wait(timeout=3)
        except subprocess.TimeoutExpired:
            server.kill(); server.wait(timeout=3)
    print(f"Passed {CHECKS} loopback deployment checks.")

if __name__ == "__main__":
    main()
