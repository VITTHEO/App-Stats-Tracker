"""Example usage of the App Stats Python SDK."""

import os
from app_stats import AppStats

ENDPOINT = os.environ.get("APP_STATS_URL", "http://localhost:8080")
API_KEY = os.environ.get("APP_STATS_API_KEY")


def main() -> None:
    stats = AppStats(
        endpoint=ENDPOINT,
        app_name="my-python-app",
        app_version="1.0.0",
        api_key=API_KEY,
    )

    # For CLI scripts that exit immediately, use track_sync().
    # For GUI apps or long-running services, use stats.track() (non-blocking).
    ok = stats.track_sync("launch")
    print(f"Stats sent: {ok}")


if __name__ == "__main__":
    main()
