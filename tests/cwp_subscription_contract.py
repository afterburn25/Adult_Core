#!/usr/bin/env python3
"""Loopback HTTP subscription-lifecycle contract for Cajun Web Pro licensing.

No network call leaves this process. No payment or live CWP license is created.
The real Adult_Core product resolver, signed-cache manager and license-replace
methods communicate with a stateful simulation of POST /api/validate.php.
"""
import json
import os
import pathlib
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = pathlib.Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests" / "fixtures" / "cwp_subscription_client.php"
LITE_ONCE = "SOFTWARE-ADULT-CORE-LITE"
LITE_MONTH = "SOFTWARE-ADULT-CORE-LITE-MONTH"
PRO_ONCE = "SOFTWARE-ADULT-CORE-PRO"
PRO_MONTH = "SOFTWARE-ADULT-CORE-PRO-MONTH"
PRODUCTS = {LITE_ONCE: ("lite", "once"), LITE_MONTH: ("lite", "month"),
            PRO_ONCE: ("pro", "once"), PRO_MONTH: ("pro", "month")}
DOMAIN = "example.test"


class FakeAuthority:
    def __init__(self):
        self.products = {}
        self.mode = "normal"
        self.requests = []

    def grant(self, key, sku, paid_through=None):
        self.products[key] = {"sku": sku, "domain": DOMAIN, "active": True,
                              "paid_through": paid_through}

    def handler(self):
        authority = self

        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                if self.path != "/api/validate.php":
                    self.send_error(404)
                    return
                size = int(self.headers.get("Content-Length", "0"))
                if size < 2 or size > 4096:
                    self.send_error(400)
                    return
                try:
                    data = json.loads(self.rfile.read(size))
                    assert isinstance(data, dict)
                    assert set(data) == {"license_key", "domain", "product_id"}
                    assert isinstance(data["license_key"], str)
                    assert isinstance(data["domain"], str)
                    assert isinstance(data["product_id"], str)
                except (ValueError, AssertionError, TypeError):
                    self.send_error(400)
                    return
                # No key is kept in the audit trail or printed in test output.
                authority.requests.append((data["product_id"], data["domain"]))
                if authority.mode in {"429", "503", "400", "302"}:
                    code = int(authority.mode)
                    self._send(code, {"authorized": False, "error": "temporarily inconclusive"})
                    return
                if authority.mode == "malformed":
                    self._send(200, {"authorized": "not a boolean"})
                    return
                match = authority.products.get(data["license_key"])
                valid = bool(match and match["active"]
                             and match["sku"] == data["product_id"]
                             and match["domain"] == data["domain"]
                             and (match["paid_through"] is None or match["paid_through"] > time.time()))
                self._send(200, {"authorized": valid, "error": None if valid else "not entitled"})

            def _send(self, status, payload):
                raw = json.dumps(payload).encode("utf-8")
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

            def log_message(self, format, *args):
                pass  # Do not leak test tokens or HTTP request bodies to CI logs.

        return Handler


def run():
    issuer = FakeAuthority()
    server = ThreadingHTTPServer(("127.0.0.1", 0), issuer.handler())
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    env = os.environ.copy()
    env["AC_TEST_CWP_URL"] = f"http://127.0.0.1:{server.server_port}/api/validate.php"
    checks = 0

    def ensure(name, result):
        nonlocal checks
        if not result:
            raise AssertionError(name)
        checks += 1

    def call(action, root, key, domain=DOMAIN, ok=True):
        result = subprocess.run(["php", str(FIXTURE), action, str(root), key, domain],
                                cwd=ROOT, env=env, capture_output=True, text=True, timeout=18)
        if key in result.stdout or key in result.stderr:
            raise AssertionError("Synthetic license key leaked into test output")
        try:
            parsed = json.loads(result.stdout)
        except json.JSONDecodeError as exc:
            raise AssertionError(f"Fixture did not return JSON for {action}: {result.stderr[:200]}") from exc
        ensure(f"Fixture exit for {action} (expected success {ok})", (result.returncode == 0) == ok)
        ensure(f"Fixture payload status for {action}", parsed.get("ok") is ok)
        return parsed.get("result")

    try:
        with tempfile.TemporaryDirectory(prefix="adult-core-contract-") as temp:
            temp = pathlib.Path(temp)
            future = time.time() + 31 * 86400
            for i, (sku, identity) in enumerate(PRODUCTS.items()):
                key = f"TEST-EDITION-KEY-{i + 1:02d}"
                issuer.grant(key, sku, future if identity[1] == "month" else None)
                result = call("resolve", temp / f"resolve-{i}", key)
                ensure(f"Verified {sku} resolves correct edition", result ==
                       {"edition": identity[0], "term": identity[1], "sku": sku})
                ensure("Apex hostname normalized in simulator", all(host == DOMAIN for _, host in issuer.requests[-4:]))
                result = call("resolve", temp / f"wrong-domain-{i}", key, "other.example")
                ensure("Correct key on wrong domain does not authorize", result is None)

            monthly = "TEST-SUBSCRIPTION-LITE"
            monthly_root = temp / "monthly-site"
            issuer.grant(monthly, LITE_MONTH, time.time() - 1)
            call("init", monthly_root, monthly, ok=False)
            ensure("Failed initial purchase cannot write a license", not (monthly_root / "config/license.php").exists())
            issuer.products[monthly]["paid_through"] = time.time() + 31 * 86400
            result = call("init", monthly_root, monthly)
            ensure("Verified paid term chooses Lite without browser edition parameter",
                   result == {"sku": LITE_MONTH, "edition": "lite", "term": "month"})
            count = len(issuer.requests)
            ensure("Cache serves a recently authorized subscription", call("status", monthly_root, monthly)["status"] == "valid")
            ensure("No extra issuer calls when local cache is fresh", len(issuer.requests) == count)

            # Customer cancels auto-renewal. Paid-through time is still valid.
            issuer.products[monthly]["cancelled_at_period_end"] = True
            ensure("Cancelled subscription stays authorized until paid-through time",
                   call("force", monthly_root, monthly)["status"] == "valid")
            issuer.products[monthly]["paid_through"] = time.time() - 60
            denied = call("force", monthly_root, monthly)
            ensure("Expired paid-through period causes definite denial", denied["status"] == "denied" and denied["issuer"] == "denied")
            ensure("Signed denied cache blocks without a new request", call("status", monthly_root, monthly)["status"] == "denied")
            issuer.mode = "503"
            denied_outage = call("force", monthly_root, monthly)
            ensure("Issuer outage cannot turn a confirmed revocation into grace",
                   denied_outage["status"] == "denied" and denied_outage["issuer"] == "transient")
            issuer.mode = "normal"

            # Renewals must be confirmed by the issuer; a local change cannot clear a signed denial.
            issuer.products[monthly]["paid_through"] = time.time() + 31 * 86400
            ensure("Renewed issuer entitlement does not bypass still-fresh denied cache",
                   call("status", monthly_root, monthly)["status"] == "denied")
            restored = call("force", monthly_root, monthly)
            ensure("Verified renewal allows manual recovery", restored["status"] == "valid" and restored["issuer"] == "valid")
            issuer.products[monthly]["active"] = False
            ensure("Issuer revocation blocks even though previously paid", call("force", monthly_root, monthly)["status"] == "denied")
            issuer.products[monthly]["active"] = True
            ensure("Issuer reactivation requires fresh positive check", call("force", monthly_root, monthly)["status"] == "valid")

            for response in ("429", "503", "400", "302", "malformed"):
                issuer.mode = response
                grace = call("force", monthly_root, monthly)
                ensure(f"Temporary/malformed HTTP {response} is not a definitive rejection",
                       grace["status"] == "valid" and grace["issuer"] == "transient")
            issuer.mode = "503"
            call("age", monthly_root, monthly)
            expired_grace = call("force", monthly_root, monthly)
            ensure("Expired outage grace locks without pretending cancellation",
                   expired_grace["status"] == "unavailable" and expired_grace["issuer"] == "transient")
            issuer.mode = "normal"
            ensure("Vendor recovery restores site only on positive confirmation",
                   call("force", monthly_root, monthly)["status"] == "valid")

            count = len(issuer.requests)
            wrong = call("wronghost", monthly_root, monthly)
            ensure("Wrong HTTP Host blocked before contacting the issuer",
                   wrong["status"] == "domain_mismatch" and len(issuer.requests) == count)

            # Same-edition plan changes are allowed only with a product-bound license.
            once_key = "TEST-LITE-ONE-TIME"
            issuer.grant(once_key, LITE_ONCE)
            changed = call("replace", monthly_root, once_key)
            ensure("Same edition monthly to one-time retains installation", changed ==
                   {"sku": LITE_ONCE, "edition": "lite", "term": "once"})
            ensure("Replaced key has its own signed authorization", call("status", monthly_root, once_key)["status"] == "valid")
            pro_key = "TEST-PRO-UPGRADE"
            issuer.grant(pro_key, PRO_MONTH, future)
            call("replace", monthly_root, pro_key, ok=False)
            ensure("Wrong edition does not overwrite existing license", call("status", monthly_root, once_key)["sku"] == LITE_ONCE)
            # Domain transfer is authorized by Cajun Web Pro, not by changing Host headers.
            issuer.products[once_key]["domain"] = "moved.example"
            ensure("Old domain denied after issuer-approved domain move",
                   call("force", monthly_root, once_key)["status"] == "denied")
            call("replace", monthly_root, once_key, ok=False)
            transferred = call("replace", monthly_root, once_key, domain="moved.example")
            ensure("License domain replacement records only the issuer-approved domain",
                   transferred == {"sku": LITE_ONCE, "edition": "lite", "term": "once"})
            ensure("New domain works with re-bound signed license cache",
                   call("status", monthly_root, once_key, domain="moved.example")["status"] == "valid")
            ensure("Old domain cannot reuse the transferred key",
                   call("status", monthly_root, once_key)["status"] == "domain_mismatch")
            issuer.products[once_key]["active"] = False
            ensure("One-time licenses also honor issuer revocation", call("force", monthly_root, once_key, domain="moved.example")["status"] == "denied")
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)
    print(f"Passed {checks} loopback HTTP Cajun Web Pro subscription contract checks.")


if __name__ == "__main__":
    run()
