import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.security.MessageDigest;
import java.time.Duration;
import java.util.Locale;
import java.util.UUID;

/**
 * App Stats - Single-file zero-dependency Java 11+ SDK.
 *
 * Usage:
 *   AppStats stats = new AppStats("https://your-site.com", "my-app", "1.0.0");
 *   stats.track();       // async non-blocking
 *   stats.trackSync();   // blocking (useful for short CLI tools)
 *
 * Privacy:
 * - Sends only app_name, app_version, OS, arch, 2-letter ISO country code, and an
 *   anonymous salted SHA-256 client_id (no IP stored, no precise location).
 * - Automatically disabled when APP_STATS_OPTOUT=1 or DO_NOT_TRACK=1 is set.
 * - Never throws exceptions to the host application.
 */
public final class AppStats {

    private final String trackUrl;
    private final String appName;
    private final String appVersion;
    private final String apiKey;
    private final Duration timeout;
    private final boolean enabled;
    private final String clientId;
    private final HttpClient httpClient;

    public AppStats(String endpoint, String appName, String appVersion) {
        this(endpoint, appName, appVersion, null, Duration.ofSeconds(3), true);
    }

    public AppStats(String endpoint, String appName, String appVersion, String apiKey) {
        this(endpoint, appName, appVersion, apiKey, Duration.ofSeconds(3), true);
    }

    public AppStats(
            String endpoint,
            String appName,
            String appVersion,
            String apiKey,
            Duration timeout,
            boolean enabled) {
        String base = endpoint.endsWith("/") ? endpoint.substring(0, endpoint.length() - 1) : endpoint;
        boolean isDirect = base.endsWith("/track") || base.endsWith("api.php") || base.contains("route=track");
        this.trackUrl = isDirect ? base : base + "/api/v1/track";
        this.appName = appName;
        this.appVersion = appVersion;
        this.apiKey = apiKey;
        this.timeout = timeout != null ? timeout : Duration.ofSeconds(3);
        this.enabled = enabled;
        this.clientId = buildAnonymousClientId(appName);
        this.httpClient = HttpClient.newBuilder()
                .connectTimeout(this.timeout)
                .build();
    }

    public void track() {
        track("launch");
    }

    public void track(String event) {
        if (!enabled || isOptedOut()) {
            return;
        }
        try {
            HttpRequest request = buildRequest(event);
            httpClient.sendAsync(request, HttpResponse.BodyHandlers.discarding())
                    .exceptionally(ex -> null);
        } catch (Exception ignored) {
            // Telemetry must never crash the host application
        }
    }

    public boolean trackSync() {
        return trackSync("launch");
    }

    public boolean trackSync(String event) {
        if (!enabled || isOptedOut()) {
            return false;
        }
        try {
            HttpRequest request = buildRequest(event);
            HttpResponse<Void> response = httpClient.send(request, HttpResponse.BodyHandlers.discarding());
            int code = response.statusCode();
            return code >= 200 && code < 300;
        } catch (Exception ignored) {
            return false;
        }
    }

    private HttpRequest buildRequest(String event) {
        String payload = buildPayload(event);
        HttpRequest.Builder builder = HttpRequest.newBuilder()
                .uri(URI.create(trackUrl))
                .timeout(timeout)
                .header("Content-Type", "application/json")
                .header("User-Agent", "app-stats-java/" + appVersion)
                .POST(HttpRequest.BodyPublishers.ofString(payload, StandardCharsets.UTF_8));
        if (apiKey != null && !apiKey.isEmpty()) {
            builder.header("X-API-Key", apiKey);
        }
        return builder.build();
    }

    public String buildPayload(String event) {
        String osName = normalizeOs(System.getProperty("os.name", "Unknown"));
        String osVersion = System.getProperty("os.version", "unknown");
        String arch = System.getProperty("os.arch", "unknown");
        String country = detectCountry();
        String safeEvent = (event == null || event.isBlank()) ? "launch" : event;

        return "{"
                + "\"app_name\":\"" + escapeJson(appName) + "\","
                + "\"app_version\":\"" + escapeJson(appVersion) + "\","
                + "\"os\":\"" + escapeJson(osName) + "\","
                + "\"os_version\":\"" + escapeJson(osVersion) + "\","
                + "\"arch\":\"" + escapeJson(arch) + "\","
                + "\"country\":\"" + escapeJson(country) + "\","
                + "\"client_id\":\"" + escapeJson(clientId) + "\","
                + "\"sdk\":\"java\","
                + "\"event\":\"" + escapeJson(safeEvent) + "\""
                + "}";
    }

    private static boolean isOptedOut() {
        for (String key : new String[]{"APP_STATS_OPTOUT", "DO_NOT_TRACK"}) {
            String val = System.getenv(key);
            if (val != null) {
                String v = val.trim().toLowerCase(Locale.ROOT);
                if ("1".equals(v) || "true".equals(v) || "yes".equals(v)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static String normalizeOs(String raw) {
        String low = raw.toLowerCase(Locale.ROOT);
        if (low.contains("win")) return "Windows";
        if (low.contains("mac") || low.contains("darwin")) return "macOS";
        if (low.contains("nux") || low.contains("nix")) return "Linux";
        return raw;
    }

    private static String detectCountry() {
        String country = Locale.getDefault().getCountry();
        if (country != null && country.length() == 2) {
            return country.toUpperCase(Locale.ROOT);
        }
        return "UNKNOWN";
    }

    private static String getOrCreateSeed() {
        try {
            String os = System.getProperty("os.name", "").toLowerCase(Locale.ROOT);
            Path dir;
            if (os.contains("win")) {
                String localAppData = System.getenv("LOCALAPPDATA");
                dir = Paths.get(localAppData != null ? localAppData : System.getProperty("user.home"), "app_stats");
            } else {
                String xdg = System.getenv("XDG_CONFIG_HOME");
                dir = xdg != null
                        ? Paths.get(xdg, "app_stats")
                        : Paths.get(System.getProperty("user.home"), ".config", "app_stats");
            }
            Files.createDirectories(dir);
            Path seedFile = dir.resolve("client_seed");
            if (Files.isRegularFile(seedFile)) {
                String existing = Files.readString(seedFile, StandardCharsets.UTF_8).trim();
                if (!existing.isEmpty()) {
                    return existing;
                }
            }
            String created = UUID.randomUUID().toString();
            Files.writeString(seedFile, created, StandardCharsets.UTF_8);
            return created;
        } catch (IOException | SecurityException ignored) {
            return "ephemeral-" + UUID.randomUUID();
        }
    }

    private static String buildAnonymousClientId(String appName) {
        String input = getOrCreateSeed() + ":" + appName;
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hash = digest.digest(input.getBytes(StandardCharsets.UTF_8));
            StringBuilder hex = new StringBuilder(32);
            for (int i = 0; i < 16; i++) {
                hex.append(String.format("%02x", hash[i]));
            }
            return hex.toString();
        } catch (Exception ignored) {
            return Integer.toHexString(input.hashCode());
        }
    }

    private static String escapeJson(String s) {
        if (s == null) return "";
        StringBuilder sb = new StringBuilder(s.length() + 8);
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"': sb.append("\\\""); break;
                case '\\': sb.append("\\\\"); break;
                case '\b': sb.append("\\b"); break;
                case '\f': sb.append("\\f"); break;
                case '\n': sb.append("\\n"); break;
                case '\r': sb.append("\\r"); break;
                case '\t': sb.append("\\t"); break;
                default:
                    if (c < 0x20) {
                        sb.append(String.format("\\u%04x", (int) c));
                    } else {
                        sb.append(c);
                    }
            }
        }
        return sb.toString();
    }
}
