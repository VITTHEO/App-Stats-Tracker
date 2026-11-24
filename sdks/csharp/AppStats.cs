using System;
using System.Globalization;
using System.IO;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

namespace AppStatsSdk
{
    /// <summary>
    /// App Stats - Single-file zero-dependency C# (.NET 6+) SDK.
    ///
    /// Usage:
    ///   var stats = new AppStats("https://your-site.com", "my-app", "1.0.0");
    ///   await stats.TrackAsync();         // awaitable
    ///   stats.TrackInBackground();        // fire-and-forget
    ///
    /// Privacy:
    /// - Sends only app_name, app_version, OS, arch, 2-letter ISO country code, and an
    ///   anonymous salted SHA-256 client_id (no IP stored, no precise location).
    /// - Automatically disabled when APP_STATS_OPTOUT=1 or DO_NOT_TRACK=1 is set.
    /// - Never throws exceptions to the host application.
    /// </summary>
    public sealed class AppStats
    {
        private static readonly HttpClient SharedHttpClient = new HttpClient
        {
            Timeout = TimeSpan.FromSeconds(3)
        };

        private readonly string _trackUrl;
        private readonly string _appName;
        private readonly string _appVersion;
        private readonly string? _apiKey;
        private readonly bool _enabled;
        private readonly string _clientId;

        public AppStats(
            string endpoint,
            string appName,
            string appVersion,
            string? apiKey = null,
            bool enabled = true)
        {
            var trimmed = endpoint.TrimEnd('/');
            var isDirect = trimmed.EndsWith("/track", StringComparison.OrdinalIgnoreCase)
                || trimmed.EndsWith("api.php", StringComparison.OrdinalIgnoreCase)
                || trimmed.Contains("route=track", StringComparison.OrdinalIgnoreCase);
            _trackUrl = isDirect ? trimmed : $"{trimmed}/api/v1/track";
            _appName = appName;
            _appVersion = appVersion;
            _apiKey = apiKey;
            _enabled = enabled;
            _clientId = BuildAnonymousClientId(appName);
        }

        public async Task<bool> TrackAsync(string eventName = "launch", string? countryOverride = null)
        {
            if (!_enabled || IsOptedOut())
            {
                return false;
            }

            try
            {
                var payload = new
                {
                    app_name = _appName,
                    app_version = _appVersion,
                    os = DetectOs(),
                    os_version = Environment.OSVersion.Version.ToString(),
                    arch = RuntimeInformation.OSArchitecture.ToString().ToLowerInvariant(),
                    country = (countryOverride ?? DetectCountry()).ToUpperInvariant(),
                    client_id = _clientId,
                    sdk = "csharp",
                    @event = string.IsNullOrWhiteSpace(eventName) ? "launch" : eventName
                };

                var json = JsonSerializer.Serialize(payload);
                using var request = new HttpRequestMessage(HttpMethod.Post, _trackUrl)
                {
                    Content = new StringContent(json, Encoding.UTF8, "application/json")
                };
                request.Headers.UserAgent.ParseAdd($"app-stats-csharp/{_appVersion}");
                if (!string.IsNullOrEmpty(_apiKey))
                {
                    request.Headers.Add("X-API-Key", _apiKey);
                }

                using var response = await SharedHttpClient.SendAsync(request).ConfigureAwait(false);
                return response.IsSuccessStatusCode;
            }
            catch
            {
                return false;
            }
        }

        public void TrackInBackground(string eventName = "launch", string? countryOverride = null)
        {
            if (!_enabled || IsOptedOut())
            {
                return;
            }

            _ = Task.Run(() => TrackAsync(eventName, countryOverride));
        }

        public bool Track(string eventName = "launch", string? countryOverride = null)
        {
            return TrackAsync(eventName, countryOverride).GetAwaiter().GetResult();
        }

        private static bool IsOptedOut()
        {
            foreach (var name in new[] { "APP_STATS_OPTOUT", "DO_NOT_TRACK" })
            {
                var value = Environment.GetEnvironmentVariable(name)?.Trim().ToLowerInvariant();
                if (value is "1" or "true" or "yes")
                {
                    return true;
                }
            }
            return false;
        }

        private static string DetectOs()
        {
            if (RuntimeInformation.IsOSPlatform(OSPlatform.Windows)) return "Windows";
            if (RuntimeInformation.IsOSPlatform(OSPlatform.OSX)) return "macOS";
            if (RuntimeInformation.IsOSPlatform(OSPlatform.Linux)) return "Linux";
            if (RuntimeInformation.IsOSPlatform(OSPlatform.FreeBSD)) return "FreeBSD";
            return "Unknown";
        }

        private static string DetectCountry()
        {
            try
            {
                var code = RegionInfo.CurrentRegion.TwoLetterISORegionName;
                if (!string.IsNullOrWhiteSpace(code) && code.Length == 2)
                {
                    return code.ToUpperInvariant();
                }
            }
            catch
            {
                // Fallback below
            }

            try
            {
                var name = CultureInfo.CurrentCulture.Name;
                var parts = name.Split('-', '_');
                if (parts.Length >= 2 && parts[^1].Length == 2)
                {
                    return parts[^1].ToUpperInvariant();
                }
            }
            catch
            {
                // Ignore
            }

            return "UNKNOWN";
        }

        private static string GetOrCreateSeed()
        {
            try
            {
                var appData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
                if (string.IsNullOrWhiteSpace(appData))
                {
                    appData = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
                }
                var dir = Path.Combine(appData, "app_stats");
                Directory.CreateDirectory(dir);
                var seedPath = Path.Combine(dir, "client_seed");
                if (File.Exists(seedPath))
                {
                    var existing = File.ReadAllText(seedPath, Encoding.UTF8).Trim();
                    if (!string.IsNullOrEmpty(existing))
                    {
                        return existing;
                    }
                }
                var seed = Guid.NewGuid().ToString("D");
                File.WriteAllText(seedPath, seed, Encoding.UTF8);
                return seed;
            }
            catch
            {
                return $"ephemeral-{Guid.NewGuid():N}";
            }
        }

        private static string BuildAnonymousClientId(string appName)
        {
            var input = $"{GetOrCreateSeed()}:{appName}";
            var hash = SHA256.HashData(Encoding.UTF8.GetBytes(input));
            return Convert.ToHexString(hash).ToLowerInvariant()[..32];
        }
    }
}
