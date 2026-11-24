using System;
using System.Threading.Tasks;
using AppStatsSdk;

var endpoint = Environment.GetEnvironmentVariable("APP_STATS_URL") ?? "http://localhost:8080";
var apiKey = Environment.GetEnvironmentVariable("APP_STATS_API_KEY");

var stats = new AppStats(endpoint, "my-csharp-app", "1.0.0", apiKey);
bool ok = await stats.TrackAsync("launch");
Console.WriteLine($"Stats sent: {ok}");
