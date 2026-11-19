"""Start the local Laravel, chatbot, Reverb, queue, and Vite stack.

Run from any directory: python scripts/dev_restart.py
Ctrl+C stops only the processes started by this launcher. Logs are written to
back-end/storage/logs/dev-restart-*.log.
"""

import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import time
import urllib.request

try:
    import psutil
except ImportError as exc:
    raise SystemExit("Install the restart helper with: python -m pip install psutil") from exc


ROOT = Path(__file__).resolve().parents[1]
LOG_DIR = ROOT / "back-end" / "storage" / "logs"


def require_command(name: str) -> str:
    command = shutil.which(name)
    if command is None:
        raise SystemExit(f"Missing {name} in PATH.")
    return command


def port_open(port: int) -> bool:
    with socket.socket() as sock:
        sock.settimeout(0.5)
        return sock.connect_ex(("127.0.0.1", port)) == 0


def free_ports(ports: tuple[int, ...]) -> None:
    listeners = {
        conn.pid
        for conn in psutil.net_connections(kind="tcp")
        if conn.status == psutil.CONN_LISTEN and conn.laddr
        and conn.laddr.port in ports and conn.pid
    }
    if os.getpid() in listeners:
        raise SystemExit("This launcher is listening on a required port; cannot restart itself.")
    processes = []
    for pid in sorted(listeners):
        try:
            process = psutil.Process(pid)
            print(f"Stopping process on a dev port (PID {pid})...", flush=True)
            process.terminate()
            processes.append(process)
        except psutil.NoSuchProcess:
            pass
    _, alive = psutil.wait_procs(processes, timeout=8)
    for process in alive:
        process.kill()
    psutil.wait_procs(alive, timeout=5)
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        if all(not port_open(port) for port in ports):
            return
        time.sleep(0.2)
    occupied = [port for port in ports if port_open(port)]
    raise SystemExit(f"Could not free dev ports: {occupied}")


def wait_for_http(process: subprocess.Popen, url: str, name: str) -> None:
    deadline = time.monotonic() + 40
    while time.monotonic() < deadline:
        if process.poll() is not None:
            break
        try:
            with urllib.request.urlopen(url, timeout=2) as response:
                if response.status == 200:
                    return
        except (OSError, TimeoutError):
            pass
        time.sleep(0.4)
    raise RuntimeError(f"{name} did not start. Check its dev-restart log.")


def wait_for_port(process: subprocess.Popen, port: int, name: str) -> None:
    deadline = time.monotonic() + 40
    while time.monotonic() < deadline:
        if process.poll() is not None:
            break
        if port_open(port):
            return
        time.sleep(0.4)
    raise RuntimeError(f"{name} did not start. Check its dev-restart log.")


def main() -> None:
    php = require_command("php")
    npm = require_command("npm.cmd" if os.name == "nt" else "npm")
    if not (ROOT / "front-end" / "node_modules").is_dir():
        raise SystemExit("Run npm install in front-end first.")
    free_ports((8000, 8001, 8002, 8080, 5173))

    LOG_DIR.mkdir(parents=True, exist_ok=True)
    processes: list[tuple[str, subprocess.Popen, object]] = []

    def start(name: str, command: list[str], folder: str) -> subprocess.Popen:
        log = (LOG_DIR / f"dev-restart-{name}.log").open("w", encoding="utf-8")
        env = os.environ.copy()
        env["LARAVEL_API_URL"] = "http://127.0.0.1:8000"
        env["PYTHONIOENCODING"] = "utf-8"
        process = subprocess.Popen(
            command,
            cwd=ROOT / folder,
            env=env,
            stdout=log,
            stderr=subprocess.STDOUT,
            creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
            start_new_session=os.name != "nt",
        )
        processes.append((name, process, log))
        print(f"Started {name} (PID {process.pid}) -> {log.name}", flush=True)
        return process

    try:
        business = start("laravel", [php, "artisan", "serve", "--host=0.0.0.0", "--port=8000"], "back-end")
        wait_for_http(business, "http://127.0.0.1:8000/up", "Laravel API")

        gateway = start("chat-gateway", [php, "artisan", "serve", "--host=0.0.0.0", "--port=8002"], "back-end")
        wait_for_http(gateway, "http://127.0.0.1:8002/up", "Chat gateway")

        chatbot = start("chatbot", [sys.executable, str(ROOT / "scripts" / "restart_chatbot.py")], ".")
        wait_for_http(chatbot, "http://127.0.0.1:8001/api/v1/ready", "AI chatbot")

        reverb = start("reverb", [php, "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"], "back-end")
        wait_for_port(reverb, 8080, "Reverb")

        queue = start("queue", [php, "artisan", "queue:work", "--tries=3", "--sleep=1"], "back-end")
        time.sleep(1)
        if queue.poll() is not None:
            raise RuntimeError("Queue worker did not start. Check its dev-restart log.")

        scheduler = start("scheduler", [php, "artisan", "schedule:work"], "back-end")
        time.sleep(1)
        if scheduler.poll() is not None:
            raise RuntimeError("Scheduler did not start. Check its dev-restart log.")

        vite = start("vite", [npm, "run", "dev", "--", "--host", "0.0.0.0"], "front-end")
        wait_for_http(vite, "http://127.0.0.1:5173", "Vite")

        print("All services started. Press Ctrl+C to stop them.", flush=True)
        while True:
            for name, process, _ in processes:
                if process.poll() is not None:
                    raise RuntimeError(f"{name} stopped (exit {process.returncode}). Check its dev-restart log.")
            time.sleep(1)
    except KeyboardInterrupt:
        print("Stopping services...", flush=True)
    finally:
        for _, process, log in reversed(processes):
            if process.poll() is None:
                if os.name == "nt":
                    subprocess.run(["taskkill", "/PID", str(process.pid), "/T", "/F"], capture_output=True)
                else:
                    os.killpg(process.pid, signal.SIGTERM)
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
            log.close()


if __name__ == "__main__":
    main()
