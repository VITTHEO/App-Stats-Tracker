#include <stdio.h>
#include <stdlib.h>

#define APP_STATS_IMPLEMENTATION
#include "app_stats.h"

int main(void) {
    const char *endpoint = getenv("APP_STATS_URL");
    if (!endpoint || !*endpoint) {
        endpoint = "http://localhost:8080";
    }
    const char *api_key = getenv("APP_STATS_API_KEY");

    int ok = app_stats_track(endpoint, "my-c-app", "1.0.0", "launch", api_key);
    printf("Stats sent: %s\n", ok ? "true" : "false");
    return 0;
}
