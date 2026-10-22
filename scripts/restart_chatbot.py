"""Restart the local chatbot stack, then keep it supervised in this console.

Run from the repository root: python scripts/restart_chatbot.py
Only processes recognizable as this project's development services are stopped.
"""

from pathlib import Path
import socket
import time

try:
    import psutil
except ImportError as exc:
    raise SystemExit("Install the restart helper with: python -m pip install psutil") from exc

import dev_chatbot


ROOT = Path(__file__).resolve().parents[1]
PORTS = (8000, 8001, 8002, 5173)


def service_root(port: int, process: psutil.Process) -> psutil.Process | None:
    """Find the project launcher for a listener without trusting its port alone."""
    try:
        command = [part.lower().replace("\\", "/") for part in process.cmdline()]
        cwd = Path(process.cwd()).resolve()
        if port in (8000, 8002):
            expected = (ROOT / "back-end").resolve()
            if cwd != expected / "public" or "-s" not in command or f"127.0.0.1:{port}" not in command:
                return None
            for parent in process.parents():
                args = [part.lower() for part in parent.cmdline()]
                if (Path(parent.cwd()).resolve() == expected and len(args) >= 3
                        and args[1:3] == ["artisan", "serve"]
                        and (f"--port={port}" in args or
                             (port == 8000 and not any(arg.startswith("--port") for arg in args)))):
                    return parent
            return None
        if port == 8001:
            return process if cwd == (ROOT / "AI-service").resolve() and "app.py" in command else None
        if port == 5173:
            return process if cwd == (ROOT / "front-end").resolve() and any("vite.js" in arg for arg in command) else None
    except (psutil.Error, OSError):
        return None
    return None


def main() -> None:
    listeners = {
        conn.laddr.port: conn.pid
        for conn in psutil.net_connections(kind="tcp")
        if conn.status == psutil.CONN_LISTEN and conn.laddr
        and conn.laddr.port in PORTS and conn.pid
    }
    roots = {}
    for port, pid in listeners.items():
        root = service_root(port, psutil.Process(pid))
        if root is None:
            raise SystemExit(f"Port {port} is used by an unrecognized process (PID {pid}); nothing was stopped.")
        roots[root.pid] = root

    # Validate every occupied port before changing any process.
    for root in roots.values():
        children = root.children(recursive=True)
        print(f"Stopping project service PID {root.pid}...", flush=True)
        for process in children + [root]:
            try:
                process.terminate()
            except psutil.NoSuchProcess:
                pass
        _, alive = psutil.wait_procs(children + [root], timeout=8)
        for process in alive:
            process.kill()

    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        if all(not dev_chatbot_port_open(port) for port in PORTS):
            break
        time.sleep(0.2)
    else:
        raise SystemExit("A port did not become free after stopping the project services.")

    print("Starting Laravel, AI Service, and frontend...", flush=True)
    dev_chatbot.main()


def dev_chatbot_port_open(port: int) -> bool:
    with socket.socket() as sock:
        sock.settimeout(0.3)
        return sock.connect_ex(("127.0.0.1", port)) == 0


if __name__ == "__main__":
    main()
