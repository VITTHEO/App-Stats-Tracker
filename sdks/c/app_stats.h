/*
 * App Stats - Single-header C SDK (STB-style).
 *
 * Usage:
 *   In ONE .c file, define APP_STATS_IMPLEMENTATION before including:
 *     #define APP_STATS_IMPLEMENTATION
 *     #include "app_stats.h"
 *
 *     int main(void) {
 *         app_stats_track("https://your-site.com", "my-app", "1.0.0", "launch", NULL);
 *         return 0;
 *     }
 *
 * Compile:
 *   Windows (MSVC)  : cl example.c winhttp.lib
 *   Windows (MinGW) : gcc example.c -lwinhttp -o example.exe
 *   Linux / macOS   : gcc example.c -lcurl -o example
 *   Linux (no curl) : gcc -DAPP_STATS_NO_CURL example.c -o example  (HTTP only)
 *
 * Privacy:
 * - Sends only app_name, app_version, OS, arch, 2-letter ISO country code, and an
 *   anonymous hashed client_id (no IP stored, no precise location).
 * - Automatically disabled when APP_STATS_OPTOUT=1 or DO_NOT_TRACK=1 is set.
 */

#ifndef APP_STATS_H
#define APP_STATS_H

#ifdef __cplusplus
extern "C" {
#endif

/*
 * Sends a single usage event to the App Stats server.
 *
 * Parameters:
 *   endpoint    Base server URL (e.g. "https://stats.example.com" or "http://localhost:8080")
 *   app_name    Name of your application
 *   app_version Version string (e.g. "1.0.0")
 *   event       Event name (NULL defaults to "launch")
 *   api_key     Optional X-API-Key value (pass NULL if not configured on server)
 *
 * Returns:
 *   1 if the server responded with HTTP 2xx, 0 otherwise (including opt-out).
 */
int app_stats_track(
    const char *endpoint,
    const char *app_name,
    const char *app_version,
    const char *event,
    const char *api_key
);

#ifdef __cplusplus
}
#endif

#ifdef APP_STATS_IMPLEMENTATION

#if !defined(_WIN32) && !defined(_POSIX_C_SOURCE)
  #define _POSIX_C_SOURCE 200112L
#endif

#include <ctype.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#ifdef _WIN32
  #ifndef WIN32_LEAN_AND_MEAN
    #define WIN32_LEAN_AND_MEAN
  #endif
  #include <windows.h>
  #include <winhttp.h>
  #include <direct.h>
  #ifdef _MSC_VER
    #pragma comment(lib, "winhttp.lib")
  #endif
#else
  #include <sys/stat.h>
  #include <sys/utsname.h>
  #include <unistd.h>
  #ifndef APP_STATS_NO_CURL
    #include <curl/curl.h>
  #else
    #include <arpa/inet.h>
    #include <netdb.h>
    #include <sys/socket.h>
    #include <sys/time.h>
  #endif
#endif

static int app_stats__is_opted_out(void) {
    const char *vars[2] = { "APP_STATS_OPTOUT", "DO_NOT_TRACK" };
    for (int i = 0; i < 2; i++) {
        const char *val = getenv(vars[i]);
        if (val && (strcmp(val, "1") == 0 || strcmp(val, "true") == 0 || strcmp(val, "TRUE") == 0 || strcmp(val, "yes") == 0)) {
            return 1;
        }
    }
    return 0;
}

static const char *app_stats__detect_os(void) {
#if defined(_WIN32)
    return "Windows";
#elif defined(__APPLE__)
    return "macOS";
#elif defined(__ANDROID__)
    return "Android";
#elif defined(__linux__)
    return "Linux";
#elif defined(__FreeBSD__)
    return "FreeBSD";
#else
    return "Unknown";
#endif
}

static const char *app_stats__detect_arch(void) {
#if defined(__x86_64__) || defined(_M_X64)
    return "x86_64";
#elif defined(__aarch64__) || defined(_M_ARM64)
    return "arm64";
#elif defined(__i386__) || defined(_M_IX86)
    return "x86";
#elif defined(__arm__) || defined(_M_ARM)
    return "arm";
#else
    return "unknown";
#endif
}

static void app_stats__detect_os_version(char *out, size_t out_size) {
#ifdef _WIN32
    snprintf(out, out_size, "windows");
#else
    struct utsname u;
    if (uname(&u) == 0) {
        snprintf(out, out_size, "%s", u.release);
    } else {
        snprintf(out, out_size, "unknown");
    }
#endif
}

static int app_stats__parse_locale_country(const char *raw, char out[3]) {
    if (!raw || !*raw) return 0;
    const char *sep = strchr(raw, '_');
    if (!sep) sep = strchr(raw, '-');
    if (!sep || !sep[1] || !sep[2]) return 0;
    if (isalpha((unsigned char)sep[1]) && isalpha((unsigned char)sep[2])) {
        if (sep[3] == '\0' || sep[3] == '.' || sep[3] == '@' || sep[3] == '-' || sep[3] == '_') {
            out[0] = (char)toupper((unsigned char)sep[1]);
            out[1] = (char)toupper((unsigned char)sep[2]);
            out[2] = '\0';
            return 1;
        }
    }
    return 0;
}

static void app_stats__detect_country(char out[16]) {
    char code[3] = { 0, 0, 0 };
#ifdef _WIN32
    wchar_t wcountry[16] = { 0 };
    if (GetLocaleInfoEx(LOCALE_NAME_USER_DEFAULT, LOCALE_SISO3166CTRYNAME, wcountry, 16) > 0) {
        if (wcountry[0] >= L'A' && wcountry[0] <= L'Z' && wcountry[1] >= L'A' && wcountry[1] <= L'Z' && wcountry[2] == L'\0') {
            out[0] = (char)wcountry[0];
            out[1] = (char)wcountry[1];
            out[2] = '\0';
            return;
        }
    }
#endif
    const char *env_keys[3] = { "LC_ALL", "LC_CTYPE", "LANG" };
    for (int i = 0; i < 3; i++) {
        if (app_stats__parse_locale_country(getenv(env_keys[i]), code)) {
            snprintf(out, 16, "%s", code);
            return;
        }
    }
    snprintf(out, 16, "UNKNOWN");
}

static uint64_t app_stats__fnv1a64(const unsigned char *data, size_t len, uint64_t seed) {
    uint64_t h = 14695981039346656037ULL ^ seed;
    for (size_t i = 0; i < len; i++) {
        h ^= (uint64_t)data[i];
        h *= 1099511628211ULL;
    }
    return h;
}

static void app_stats__build_client_id(const char *app_name, char out[33]) {
    char seed_path[600] = { 0 };
    char seed_val[128] = { 0 };
    int loaded = 0;

#ifdef _WIN32
    const char *base = getenv("LOCALAPPDATA");
    if (!base) base = getenv("USERPROFILE");
    if (base) {
        char dir[512];
        snprintf(dir, sizeof(dir), "%.490s\\app_stats", base);
        _mkdir(dir);
        snprintf(seed_path, sizeof(seed_path), "%s\\client_seed", dir);
    }
#else
    const char *xdg = getenv("XDG_CONFIG_HOME");
    const char *home = getenv("HOME");
    if (xdg && *xdg) {
        char dir[512];
        snprintf(dir, sizeof(dir), "%.490s/app_stats", xdg);
        mkdir(dir, 0700);
        snprintf(seed_path, sizeof(seed_path), "%s/client_seed", dir);
    } else if (home && *home) {
        char cfg[512];
        char dir[540];
        snprintf(cfg, sizeof(cfg), "%.490s/.config", home);
        mkdir(cfg, 0700);
        snprintf(dir, sizeof(dir), "%s/app_stats", cfg);
        mkdir(dir, 0700);
        snprintf(seed_path, sizeof(seed_path), "%s/client_seed", dir);
    }
#endif

    if (seed_path[0]) {
        FILE *f = fopen(seed_path, "rb");
        if (f) {
            if (fgets(seed_val, (int)sizeof(seed_val), f) != NULL) {
                size_t len = strlen(seed_val);
                while (len > 0 && (seed_val[len - 1] == '\n' || seed_val[len - 1] == '\r' || seed_val[len - 1] == ' ')) {
                    seed_val[--len] = '\0';
                }
                if (len > 0) loaded = 1;
            }
            fclose(f);
        }
    }

    if (!loaded) {
        unsigned int r1 = (unsigned int)time(NULL);
        unsigned int r2 = (unsigned int)(uintptr_t)&seed_val;
        srand(r1 ^ r2);
        snprintf(seed_val, sizeof(seed_val), "%08x-%04x-%04x-%08x",
                 r1 ^ (unsigned int)rand(),
                 (unsigned int)(rand() & 0xffff),
                 (unsigned int)(rand() & 0xffff),
                 r2 ^ (unsigned int)rand());
        if (seed_path[0]) {
            FILE *f = fopen(seed_path, "wb");
            if (f) {
                fputs(seed_val, f);
                fclose(f);
            }
        }
    }

    char combined[300];
    snprintf(combined, sizeof(combined), "%.127s:%.127s", seed_val, app_name ? app_name : "app");
    size_t clen = strlen(combined);
    uint64_t h1 = app_stats__fnv1a64((const unsigned char *)combined, clen, 0x9e3779b97f4a7c15ULL);
    uint64_t h2 = app_stats__fnv1a64((const unsigned char *)combined, clen, 0xc2b2ae3d27d4eb4fULL);
    snprintf(out, 33, "%016llx%016llx", (unsigned long long)h1, (unsigned long long)h2);
}

static void app_stats__json_escape(const char *src, char *dst, size_t dst_size) {
    size_t j = 0;
    if (!src) src = "";
    for (size_t i = 0; src[i] != '\0' && j + 2 < dst_size; i++) {
        unsigned char c = (unsigned char)src[i];
        if (c == '"' || c == '\\') {
            if (j + 3 >= dst_size) break;
            dst[j++] = '\\';
            dst[j++] = (char)c;
        } else if (c >= 0x20 && c != 0x7f) {
            dst[j++] = (char)c;
        }
    }
    dst[j] = '\0';
}

static void app_stats__build_track_url(const char *endpoint, char *out, size_t out_size) {
    size_t len = strlen(endpoint);
    while (len > 0 && endpoint[len - 1] == '/') {
        len--;
    }
    if ((len >= 6 && strncmp(endpoint + len - 6, "/track", 6) == 0) ||
        (len >= 7 && strncmp(endpoint + len - 7, "api.php", 7) == 0) ||
        strstr(endpoint, "route=track") != NULL) {
        snprintf(out, out_size, "%.*s", (int)len, endpoint);
    } else {
        snprintf(out, out_size, "%.*s/api/v1/track", (int)len, endpoint);
    }
}

#ifdef _WIN32

static int app_stats__post_http(const char *url, const char *json_body, const char *api_key) {
    wchar_t wurl[1024];
    if (MultiByteToWideChar(CP_UTF8, 0, url, -1, wurl, 1024) == 0) return 0;

    URL_COMPONENTS uc;
    memset(&uc, 0, sizeof(uc));
    uc.dwStructSize = sizeof(uc);
    wchar_t host[256] = { 0 };
    wchar_t path[512] = { 0 };
    uc.lpszHostName = host;
    uc.dwHostNameLength = 256;
    uc.lpszUrlPath = path;
    uc.dwUrlPathLength = 512;

    if (!WinHttpCrackUrl(wurl, 0, 0, &uc)) return 0;

    HINTERNET session = WinHttpOpen(L"app-stats-c/1.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY, NULL, NULL, 0);
    if (!session) return 0;
    WinHttpSetTimeouts(session, 3000, 3000, 3000, 3000);

    HINTERNET connect = WinHttpConnect(session, host, uc.nPort, 0);
    if (!connect) {
        WinHttpCloseHandle(session);
        return 0;
    }

    DWORD flags = (uc.nScheme == INTERNET_SCHEME_HTTPS) ? WINHTTP_FLAG_SECURE : 0;
    HINTERNET request = WinHttpOpenRequest(connect, L"POST", path, NULL, WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES, flags);
    if (!request) {
        WinHttpCloseHandle(connect);
        WinHttpCloseHandle(session);
        return 0;
    }

    wchar_t headers[512] = L"Content-Type: application/json\r\n";
    if (api_key && *api_key) {
        wchar_t wkey[256] = { 0 };
        MultiByteToWideChar(CP_UTF8, 0, api_key, -1, wkey, 256);
        swprintf(headers, 512, L"Content-Type: application/json\r\nX-API-Key: %ls\r\n", wkey);
    }

    int ok = 0;
    DWORD body_len = (DWORD)strlen(json_body);
    if (WinHttpSendRequest(request, headers, (DWORD)-1L, (LPVOID)json_body, body_len, body_len, 0) &&
        WinHttpReceiveResponse(request, NULL)) {
        DWORD status = 0;
        DWORD status_size = sizeof(status);
        if (WinHttpQueryHeaders(request, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                                WINHTTP_HEADER_NAME_BY_INDEX, &status, &status_size, WINHTTP_NO_HEADER_INDEX)) {
            ok = (status >= 200 && status < 300) ? 1 : 0;
        }
    }

    WinHttpCloseHandle(request);
    WinHttpCloseHandle(connect);
    WinHttpCloseHandle(session);
    return ok;
}

#elif !defined(APP_STATS_NO_CURL)

static size_t app_stats__discard_cb(void *ptr, size_t size, size_t nmemb, void *userdata) {
    (void)ptr;
    (void)userdata;
    return size * nmemb;
}

static int app_stats__post_http(const char *url, const char *json_body, const char *api_key) {
    CURL *curl = curl_easy_init();
    if (!curl) return 0;

    struct curl_slist *headers = NULL;
    headers = curl_slist_append(headers, "Content-Type: application/json");
    char key_hdr[320];
    if (api_key && *api_key) {
        snprintf(key_hdr, sizeof(key_hdr), "X-API-Key: %s", api_key);
        headers = curl_slist_append(headers, key_hdr);
    }

    curl_easy_setopt(curl, CURLOPT_URL, url);
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, json_body);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 3L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 3L);
    curl_easy_setopt(curl, CURLOPT_USERAGENT, "app-stats-c/1.0");
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, app_stats__discard_cb);

    CURLcode res = curl_easy_perform(curl);
    long status = 0;
    if (res == CURLE_OK) {
        curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &status);
    }

    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);
    return (res == CURLE_OK && status >= 200 && status < 300) ? 1 : 0;
}

#else

/* Pure POSIX socket HTTP transport (no libcurl; supports http:// URLs) */
static int app_stats__post_http(const char *url, const char *json_body, const char *api_key) {
    if (strncmp(url, "http://", 7) != 0) return 0;
    const char *p = url + 7;
    const char *slash = strchr(p, '/');
    char host_port[256] = { 0 };
    const char *path = slash ? slash : "/";
    size_t hp_len = slash ? (size_t)(slash - p) : strlen(p);
    if (hp_len == 0 || hp_len >= sizeof(host_port)) return 0;
    memcpy(host_port, p, hp_len);

    char host[256] = { 0 };
    char port[16] = "80";
    char *colon = strchr(host_port, ':');
    if (colon) {
        *colon = '\0';
        snprintf(port, sizeof(port), "%s", colon + 1);
    }
    snprintf(host, sizeof(host), "%s", host_port);

    struct addrinfo hints, *res = NULL;
    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    if (getaddrinfo(host, port, &hints, &res) != 0 || !res) return 0;

    int sock = socket(res->ai_family, res->ai_socktype, res->ai_protocol);
    if (sock < 0) {
        freeaddrinfo(res);
        return 0;
    }

    struct timeval tv;
    tv.tv_sec = 3;
    tv.tv_usec = 0;
    setsockopt(sock, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));
    setsockopt(sock, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof(tv));

    int ok = 0;
    if (connect(sock, res->ai_addr, res->ai_addrlen) == 0) {
        char req[2048];
        char key_line[320] = "";
        if (api_key && *api_key) {
            snprintf(key_line, sizeof(key_line), "X-API-Key: %s\r\n", api_key);
        }
        int n = snprintf(
            req, sizeof(req),
            "POST %s HTTP/1.1\r\n"
            "Host: %s:%s\r\n"
            "User-Agent: app-stats-c/1.0\r\n"
            "Content-Type: application/json\r\n"
            "%s"
            "Content-Length: %zu\r\n"
            "Connection: close\r\n\r\n%s",
            path, host, port, key_line, strlen(json_body), json_body
        );
        if (n > 0 && send(sock, req, (size_t)n, 0) == n) {
            char resp[128] = { 0 };
            ssize_t r = recv(sock, resp, sizeof(resp) - 1, 0);
            if (r > 12 && (strncmp(resp, "HTTP/1.1 2", 10) == 0 || strncmp(resp, "HTTP/1.0 2", 10) == 0)) {
                ok = 1;
            }
        }
    }

    close(sock);
    freeaddrinfo(res);
    return ok;
}

#endif

int app_stats_track(
    const char *endpoint,
    const char *app_name,
    const char *app_version,
    const char *event,
    const char *api_key
) {
    if (!endpoint || !app_name || !app_version || app_stats__is_opted_out()) {
        return 0;
    }

    char url[1024];
    app_stats__build_track_url(endpoint, url, sizeof(url));

    char os_ver[64];
    app_stats__detect_os_version(os_ver, sizeof(os_ver));

    char country[16];
    app_stats__detect_country(country);

    char client_id[33];
    app_stats__build_client_id(app_name, client_id);

    char e_app[128], e_ver[64], e_osver[128], e_event[96];
    app_stats__json_escape(app_name, e_app, sizeof(e_app));
    app_stats__json_escape(app_version, e_ver, sizeof(e_ver));
    app_stats__json_escape(os_ver, e_osver, sizeof(e_osver));
    app_stats__json_escape((event && *event) ? event : "launch", e_event, sizeof(e_event));

    char payload[1024];
    snprintf(
        payload, sizeof(payload),
        "{\"app_name\":\"%s\",\"app_version\":\"%s\",\"os\":\"%s\",\"os_version\":\"%s\","
        "\"arch\":\"%s\",\"country\":\"%s\",\"client_id\":\"%s\",\"sdk\":\"c\",\"event\":\"%s\"}",
        e_app, e_ver, app_stats__detect_os(), e_osver, app_stats__detect_arch(), country, client_id, e_event
    );

    return app_stats__post_http(url, payload, api_key);
}

#endif /* APP_STATS_IMPLEMENTATION */
#endif /* APP_STATS_H */
