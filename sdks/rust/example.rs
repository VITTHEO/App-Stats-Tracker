mod app_stats;

use std::env;

fn main() {
    let endpoint = env::var("APP_STATS_URL").unwrap_or_else(|_| "http://localhost:8080".to_string());
    let mut stats = app_stats::AppStats::new(&endpoint, "my-rust-app", "1.0.0");

    if let Ok(key) = env::var("APP_STATS_API_KEY") {
        stats = stats.with_api_key(key);
    }

    // Use track_sync() for short CLI programs, or track() for non-blocking background tracking.
    let ok = stats.track_sync();
    println!("Stats sent: {}", ok);
}
