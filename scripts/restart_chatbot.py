"""Restart only the local AI chatbot service and supervise it in this console.

Run from the repository root: python scripts/restart_chatbot.py
Only a process recognizable as this project's AI service is stopped.
"""

import json
import os
from pathlib import Path
import socket
import subprocess
import time
import urllib.request

try:
    import psutil
except ImportError as exc:
    raise SystemExit("Install the restart helper with: python -m pip install psutil") from exc


ROOT = Path(__file__).resolve().parents[1]
AI_ROOT = ROOT / "AI-service"
AI_PORT = 8001
READY_URL = f"http://127.0.0.1:{AI_PORT}/api/v1/ready"


def service_root(process: psutil.Process) -> psutil.Process | None:
    """Return the process only when it is this project's AI service."""
    try:
        command = [part.lower().replace("\\", "/") for part in process.cmdline()]
        cwd = Path(process.cwd()).resolve()
        return process if cwd == AI_ROOT.resolve() and "app.py" in command else None
    except (psutil.Error, OSError):
        return None


def stop_process(process: psutil.Process) -> None:
    children = process.children(recursive=True)
    print(f"Stopping AI Service PID {process.pid}...", flush=True)
    for item in children + [process]:
        try:
            item.terminate()
        except psutil.NoSuchProcess:
            pass
    _, alive = psutil.wait_procs(children + [process], timeout=8)
    for item in alive:
        item.kill()


def port_open() -> bool:
    with socket.socket() as sock:
        sock.settimeout(0.3)
        return sock.connect_ex(("127.0.0.1", AI_PORT)) == 0


def wait_until_stopped() -> None:
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        if not port_open():
            return
        time.sleep(0.2)
    raise SystemExit(f"Port {AI_PORT} did not become free after stopping the AI Service.")


def wait_until_ready(process: subprocess.Popen) -> None:
    deadline = time.monotonic() + 40
    while process.poll() is None and time.monotonic() < deadline:
        try:
            with urllib.request.urlopen(READY_URL, timeout=2) as response:
                if response.status == 200 and json.load(response).get("status") == "ready":
                    return
        except (OSError, TimeoutError, json.JSONDecodeError):
            pass
        time.sleep(0.3)
    raise RuntimeError("AI Service failed to become ready. Check its console output and AI-service/.env.")


def main() -> None:
    listeners = {
        conn.pid
        for conn in psutil.net_connections(kind="tcp")
        if conn.status == psutil.CONN_LISTEN and conn.laddr
        and conn.laddr.port == AI_PORT and conn.pid
    }
    roots = []
    for pid in listeners:
        root = service_root(psutil.Process(pid))
        if root is None:
            raise SystemExit(
                f"Port {AI_PORT} is used by an unrecognized process (PID {pid}); nothing was stopped."
            )
        roots.append(root)

    for root in roots:
        stop_process(root)
    if roots:
        wait_until_stopped()

    python = AI_ROOT / "venv" / ("Scripts/python.exe" if os.name == "nt" else "bin/python")
    if not python.is_file():
        raise SystemExit("Create AI-service/venv and install its dependencies first.")

    env = os.environ.copy()
    env.update(LARAVEL_API_URL="http://127.0.0.1:8000", PYTHONIOENCODING="utf-8")
    print("Starting AI Service...", flush=True)
    process = subprocess.Popen([str(python), "app.py"], cwd=AI_ROOT, env=env)
    try:
        wait_until_ready(process)
        print(f"Ready: AI Service (PID {process.pid}) - Ctrl+C to stop.", flush=True)
        exit_code = process.wait()
        raise RuntimeError(f"AI Service stopped unexpectedly with exit code {exit_code}.")
    except KeyboardInterrupt:
        pass
    finally:
        if process.poll() is None:
            stop_process(psutil.Process(process.pid))


if __name__ == "__main__":
    main()
