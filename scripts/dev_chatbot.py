"""Run the local chatbot stack. Ctrl+C stops only the processes started here."""
from pathlib import Path
import json
import os
import shutil
import socket
import subprocess
import sys
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
LOGS = ROOT / "back-end" / "storage" / "logs"


def main():
    php = shutil.which("php")
    node = shutil.which("node")
    python = ROOT / "AI-service" / "venv" / ("Scripts/python.exe" if os.name == "nt" else "bin/python")
    vite = ROOT / "front-end" / "node_modules" / "vite" / "bin" / "vite.js"
    if not php or not node or not python.is_file() or not vite.is_file():
        raise SystemExit("Install PHP, Node, frontend dependencies and AI-service/venv first.")
    for port in (8000, 8001, 8002, 5173):
        with socket.socket() as sock:
            sock.settimeout(.5)
            if sock.connect_ex(("127.0.0.1", port)) == 0:
                raise SystemExit(f"Port {port} is occupied. Stop its existing dev service before starting this stack.")
    env = os.environ.copy()
    env.update(LARAVEL_API_URL="http://127.0.0.1:8000", PYTHONIOENCODING="utf-8")
    processes = []
    logs = []
    services = [
        ("business", [php, "artisan", "serve", "--host=127.0.0.1", "--port=8000", "--tries=1"], "back-end", "http://127.0.0.1:8000/up"),
        ("chat-gateway", [php, "artisan", "serve", "--host=127.0.0.1", "--port=8002", "--tries=1"], "back-end", "http://127.0.0.1:8002/up"),
        ("ai", [str(python), "app.py"], "AI-service", "http://127.0.0.1:8001/api/v1/ready"),
        ("frontend", [node, str(vite), "--host", "127.0.0.1", "--strictPort"], "front-end", "http://127.0.0.1:5173"),
    ]
    try:
        for name, command, folder, url in services:
            log = (LOGS / f"chatbot-dev-{name}.log").open("w", encoding="utf-8")
            logs.append(log)
            process = subprocess.Popen(command, cwd=ROOT / folder, env=env, stdout=log, stderr=subprocess.STDOUT,
                                       creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0)
            processes.append(process)
            deadline = time.monotonic() + 40
            while True:
                if process.poll() is not None or time.monotonic() > deadline:
                    raise RuntimeError(f"{name} failed to start. See {log.name}")
                try:
                    with urllib.request.urlopen(url, timeout=2) as response:
                        if response.status == 200:
                            if name == "ai" and json.load(response).get("status") != "ready":
                                raise RuntimeError("AI configuration incomplete. Check AI-service/.env.")
                            break
                except (OSError, TimeoutError):
                    time.sleep(.3)
            print(f"Ready: {name} (PID {process.pid})", flush=True)
        print("Open http://127.0.0.1:5173 - Ctrl+C to stop. Logs: back-end/storage/logs/chatbot-dev-*.log", flush=True)
        while all(process.poll() is None for process in processes):
            time.sleep(1)
        raise RuntimeError("A service stopped unexpectedly; inspect the dev logs.")
    except KeyboardInterrupt:
        pass
    finally:
        for process in reversed(processes):
            if process.poll() is None:
                if os.name == "nt":
                    subprocess.run(["taskkill", "/PID", str(process.pid), "/T", "/F"], capture_output=True)
                else:
                    process.terminate()
                process.wait(timeout=10)
        for log in logs:
            log.close()


if __name__ == "__main__":
    main()
