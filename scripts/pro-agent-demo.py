#!/usr/bin/env python3
"""LOCAL DEVELOPMENT-ONLY Pro capability probe; not a production daemon or video server."""
from http.server import BaseHTTPRequestHandler, HTTPServer
from hmac import compare_digest
import json
import os
import shutil
import sys

TOKEN = os.environ.get("AFTERBURN_PRO_AGENT_TOKEN", "")
if len(TOKEN) < 32:
    sys.exit("Set AFTERBURN_PRO_AGENT_TOKEN to a random secret of at least 32 characters.")

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        auth = self.headers.get("Authorization", "")
        if self.path != "/v1/status" or not compare_digest(auth, "Bearer " + TOKEN):
            self.send_error(404)
            return
        capabilities = ["persistent_process"]
        if shutil.which("ffmpeg"):
            capabilities.append("ffmpeg")
        payload = json.dumps({
            "service": "adult-core-pro-agent",
            "api_version": 1,
            "status": "ok",
            "capabilities": capabilities,
        }).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *args):
        pass

if __name__ == "__main__":
    # Never listen on 0.0.0.0; this demonstration only supports same-host tests.
    HTTPServer(("127.0.0.1", 8787), Handler).serve_forever()
