"""
App Stats - Single-file zero-dependency Python SDK.

Drop this file into your project and call:
    from app_stats import AppStats
    AppStats("https://your-site.com", "my-app", "1.0.0").track()

Privacy:
- Sends only app_name, app_version, OS, arch, 2-letter ISO country code, and an
  anonymous salted SHA-256 client_id (no IP stored, no precise location).
- Automatically disabled when APP_STATS_OPTOUT=1 or DO_NOT_TRACK=1 is set.
- Never raises exceptions to the host application.
"""

from __future__ import annotations

import hashlib
import json
import locale
import os
import platform
import re
import threading
import urllib.error
import urllib.request
import uuid
from pathlib import Path
from typing import Optional

_COUNTRY_RE = re.compile(r"^[A-Z]{2}$")


def _is_opted_out() -> bool:
    for var in ("APP_STATS_OPTOUT", "DO_NOT_TRACK"):
        val = os.environ.get(var, "").strip().lower()
        if val in ("1", "true", "yes"):
            return True
    return False


def _detect_os() -> tuple[str, str, str]:
    sys_name = platform.system()
    if sys_name == "Darwin":
        os_name = "macOS"
        os_ver = platform.mac_ver()[0] or platform.release()
    elif sys_name == "Windows":
        os_name = "Windows"
        os_ver = platform.release() or platform.version()
    elif sys_name == "Linux":
        os_name = "Linux"
        os_ver = platform.release()
    else:
        os_name = sys_name or "Unknown"
        os_ver = platform.release() or "unknown"
    arch = platform.machine() or "unknown"
    return os_name, os_ver, arch


def _parse_country_from_locale(raw: Optional[str]) -> Optional[str]:
    if not raw:
        return None
    cleaned = raw.strip().split(".", 1)[0].split("@", 1)[0]
    for sep in ("_", "-"):
        if sep in cleaned:
            part = cleaned.split(sep)[-1].upper()
            if _COUNTRY_RE.match(part):
                return part
    return None


def _detect_country() -> str:
    # 1. On Windows, prefer user geographic country via Win32 locale API (stdlib ctypes)
    if platform.system() == "Windows":
        try:
            import ctypes

            buf = ctypes.create_unicode_buffer(85)
            if ctypes.windll.kernel32.GetUserDefaultLocaleName(buf, len(buf)) > 0:
                code = _parse_country_from_locale(buf.value)
                if code:
                    return code
        except Exception:
            pass

    # 2. Check standard locale environment variables
    for env_key in ("LC_ALL", "LC_CTYPE", "LANG"):
        code = _parse_country_from_locale(os.environ.get(env_key))
        if code:
            return code

    # 3. Python locale module fallback
    try:
        loc, _ = locale.getlocale()
        code = _parse_country_from_locale(loc)
        if code:
            return code
    except Exception:
        pass

    return "UNKNOWN"


def _get_or_create_seed() -> str:
    try:
        if platform.system() == "Windows":
            base = Path(os.environ.get("LOCALAPPDATA") or Path.home()) / "app_stats"
        else:
            base = Path(os.environ.get("XDG_CONFIG_HOME") or (Path.home() / ".config")) / "app_stats"
        base.mkdir(parents=True, exist_ok=True)
        id_file = base / "client_seed"
        if id_file.is_file():
            existing = id_file.read_text(encoding="utf-8").strip()
            if existing:
                return existing
        seed = str(uuid.uuid4())
        id_file.write_text(seed, encoding="utf-8")
        return seed
    except Exception:
        return f"fallback-{uuid.getnode()}"


def _anonymous_client_id(app_name: str) -> str:
    seed = _get_or_create_seed()
    digest = hashlib.sha256(f"{seed}:{app_name}".encode("utf-8")).hexdigest()
    return digest[:32]


class AppStats:
    """Lightweight telemetry client for App Stats."""

    def __init__(
        self,
        endpoint: str,
        app_name: str,
        app_version: str,
        api_key: Optional[str] = None,
        timeout: float = 3.0,
        enabled: bool = True,
    ) -> None:
        base = endpoint.rstrip("/")
        if base.endswith("/track") or base.endswith("api.php") or "route=track" in base:
            self.track_url = base
        else:
            self.track_url = f"{base}/api/v1/track"
        self.app_name = app_name
        self.app_version = app_version
        self.api_key = api_key
        self.timeout = timeout
        self.enabled = enabled
        self._client_id = _anonymous_client_id(app_name)

    def build_payload(self, event: str = "launch", country: Optional[str] = None) -> dict[str, str]:
        os_name, os_ver, arch = _detect_os()
        return {
            "app_name": self.app_name,
            "app_version": self.app_version,
            "os": os_name,
            "os_version": os_ver,
            "arch": arch,
            "country": (country or _detect_country()).upper(),
            "client_id": self._client_id,
            "sdk": "python",
            "event": event,
        }

    def track_sync(self, event: str = "launch", country: Optional[str] = None) -> bool:
        """Send an event synchronously. Returns True on HTTP 2xx, False otherwise."""
        if not self.enabled or _is_opted_out():
            return False
        try:
            body = json.dumps(self.build_payload(event=event, country=country)).encode("utf-8")
            headers = {
                "Content-Type": "application/json",
                "User-Agent": f"app-stats-python/{self.app_version}",
            }
            if self.api_key:
                headers["X-API-Key"] = self.api_key
            req = urllib.request.Request(self.track_url, data=body, headers=headers, method="POST")
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return 200 <= resp.status < 300
        except (urllib.error.URLError, OSError, ValueError):
            return False

    def track(self, event: str = "launch", country: Optional[str] = None) -> None:
        """Send an event asynchronously in a background daemon thread (non-blocking)."""
        if not self.enabled or _is_opted_out():
            return
        thread = threading.Thread(
            target=self.track_sync,
            kwargs={"event": event, "country": country},
            daemon=True,
        )
        thread.start()
