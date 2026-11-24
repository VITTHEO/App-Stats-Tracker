//! App Stats - Single-file zero-dependency Rust SDK.
//!
//! Drop this file into your project (`mod app_stats;`) and call:
//! ```rust
//! app_stats::AppStats::new("https://your-site.com", "my-app", "1.0.0").track();
//! ```
//!
//! Transport (zero external crates):
//! - `http://`  : native `std::net::TcpStream` with 3-second timeout.
//! - `https://` : invokes system `curl` safely via argument list (pre-installed on
//!                Windows 10/11, macOS, and Linux). Fails silently if unavailable.
//!
//! Privacy:
//! - Sends only app_name, app_version, OS, arch, 2-letter ISO country code, and an
//!   anonymous salted SHA-256 client_id (no IP stored, no precise location).
//! - Automatically disabled when `APP_STATS_OPTOUT=1` or `DO_NOT_TRACK=1` is set.

#![allow(dead_code)]

use std::env;
use std::fs;
use std::io::{Read, Write};
use std::net::{TcpStream, ToSocketAddrs};
use std::path::PathBuf;
use std::process::{Command, Stdio};
use std::thread;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

#[derive(Clone, Debug)]
pub struct AppStats {
    track_url: String,
    app_name: String,
    app_version: String,
    api_key: Option<String>,
    enabled: bool,
    client_id: String,
}

impl AppStats {
    pub fn new(endpoint: &str, app_name: &str, app_version: &str) -> Self {
        let trimmed = endpoint.trim_end_matches('/');
        let track_url = if trimmed.ends_with("/track")
            || trimmed.ends_with("api.php")
            || trimmed.contains("route=track")
        {
            trimmed.to_string()
        } else {
            format!("{}/api/v1/track", trimmed)
        };
        Self {
            track_url,
            app_name: app_name.to_string(),
            app_version: app_version.to_string(),
            api_key: None,
            enabled: true,
            client_id: build_anonymous_client_id(app_name),
        }
    }

    pub fn with_api_key(mut self, api_key: impl Into<String>) -> Self {
        let key = api_key.into();
        if !key.is_empty() {
            self.api_key = Some(key);
        }
        self
    }

    pub fn with_enabled(mut self, enabled: bool) -> Self {
        self.enabled = enabled;
        self
    }

    /// Sends a "launch" event asynchronously on a background thread (non-blocking).
    pub fn track(&self) {
        self.track_event("launch");
    }

    /// Sends a custom event asynchronously on a background thread (non-blocking).
    pub fn track_event(&self, event: &str) {
        if !self.enabled || is_opted_out() {
            return;
        }
        let cloned = self.clone();
        let ev = event.to_string();
        let _ = thread::Builder::new()
            .name("app-stats".to_string())
            .spawn(move || {
                let _ = cloned.track_event_sync(&ev);
            });
    }

    /// Sends a "launch" event synchronously. Returns true on HTTP 2xx.
    pub fn track_sync(&self) -> bool {
        self.track_event_sync("launch")
    }

    /// Sends a custom event synchronously. Returns true on HTTP 2xx.
    pub fn track_event_sync(&self, event: &str) -> bool {
        if !self.enabled || is_opted_out() {
            return false;
        }
        let payload = self.build_payload(event);
        if self.track_url.starts_with("http://") {
            post_http_tcp(&self.track_url, &payload, self.api_key.as_deref())
        } else if self.track_url.starts_with("https://") {
            post_https_curl(&self.track_url, &payload, self.api_key.as_deref())
        } else {
            false
        }
    }

    pub fn build_payload(&self, event: &str) -> String {
        let safe_event = if event.trim().is_empty() { "launch" } else { event };
        let os_name = detect_os();
        let arch = env::consts::ARCH;
        let country = detect_country();

        format!(
            "{{\"app_name\":\"{}\",\"app_version\":\"{}\",\"os\":\"{}\",\"os_version\":\"{}\",\"arch\":\"{}\",\"country\":\"{}\",\"client_id\":\"{}\",\"sdk\":\"rust\",\"event\":\"{}\"}}",
            escape_json(&self.app_name),
            escape_json(&self.app_version),
            escape_json(os_name),
            escape_json(env::consts::OS),
            escape_json(arch),
            escape_json(&country),
            escape_json(&self.client_id),
            escape_json(safe_event),
        )
    }
}

fn is_opted_out() -> bool {
    for var in &["APP_STATS_OPTOUT", "DO_NOT_TRACK"] {
        if let Ok(val) = env::var(var) {
            let v = val.trim().to_ascii_lowercase();
            if matches!(v.as_str(), "1" | "true" | "yes") {
                return true;
            }
        }
    }
    false
}

fn detect_os() -> &'static str {
    match env::consts::OS {
        "windows" => "Windows",
        "macos" => "macOS",
        "linux" => "Linux",
        "android" => "Android",
        "ios" => "iOS",
        "freebsd" => "FreeBSD",
        other => other,
    }
}

fn parse_country_from_locale(raw: &str) -> Option<String> {
    let base = raw.trim().split('.').next()?.split('@').next()?;
    let sep_pos = base.find('_').or_else(|| base.find('-'))?;
    let part = base[sep_pos + 1..].split(['_', '-']).next()?;
    if part.len() == 2 && part.bytes().all(|b| b.is_ascii_alphabetic()) {
        return Some(part.to_ascii_uppercase());
    }
    None
}

#[cfg(target_os = "windows")]
fn detect_windows_country() -> Option<String> {
    extern "system" {
        fn GetUserDefaultLocaleName(lp_locale_name: *mut u16, cch_locale_name: i32) -> i32;
    }
    let mut buf = [0u16; 85];
    let len = unsafe { GetUserDefaultLocaleName(buf.as_mut_ptr(), buf.len() as i32) };
    if len > 1 {
        let s = String::from_utf16_lossy(&buf[..(len as usize - 1)]);
        return parse_country_from_locale(&s);
    }
    None
}

fn detect_country() -> String {
    #[cfg(target_os = "windows")]
    if let Some(code) = detect_windows_country() {
        return code;
    }

    for key in &["LC_ALL", "LC_CTYPE", "LANG"] {
        if let Ok(val) = env::var(key) {
            if let Some(code) = parse_country_from_locale(&val) {
                return code;
            }
        }
    }
    "UNKNOWN".to_string()
}

fn config_seed_path() -> Option<PathBuf> {
    #[cfg(target_os = "windows")]
    let base = env::var_os("LOCALAPPDATA")
        .or_else(|| env::var_os("USERPROFILE"))
        .map(PathBuf::from)?;

    #[cfg(not(target_os = "windows"))]
    let base = env::var_os("XDG_CONFIG_HOME")
        .map(PathBuf::from)
        .or_else(|| env::var_os("HOME").map(|h| PathBuf::from(h).join(".config")))?;

    Some(base.join("app_stats").join("client_seed"))
}

fn get_or_create_seed() -> String {
    if let Some(path) = config_seed_path() {
        if let Ok(existing) = fs::read_to_string(&path) {
            let trimmed = existing.trim();
            if !trimmed.is_empty() {
                return trimmed.to_string();
            }
        }
        let nanos = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_nanos())
            .unwrap_or(0);
        let pid = std::process::id();
        let seed = format!("{:032x}-{:08x}", nanos, pid);
        if let Some(parent) = path.parent() {
            let _ = fs::create_dir_all(parent);
        }
        let _ = fs::write(&path, &seed);
        return seed;
    }
    format!("ephemeral-{}", std::process::id())
}

fn build_anonymous_client_id(app_name: &str) -> String {
    let input = format!("{}:{}", get_or_create_seed(), app_name);
    let digest = sha256(input.as_bytes());
    let mut hex = String::with_capacity(32);
    for b in &digest[..16] {
        hex.push_str(&format!("{:02x}", b));
    }
    hex
}

fn escape_json(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 8);
    for ch in s.chars() {
        match ch {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c => out.push(c),
        }
    }
    out
}

fn post_http_tcp(url: &str, body: &str, api_key: Option<&str>) -> bool {
    let without_scheme = match url.strip_prefix("http://") {
        Some(v) => v,
        None => return false,
    };
    let (host_port, path) = match without_scheme.find('/') {
        Some(idx) => (&without_scheme[..idx], &without_scheme[idx..]),
        None => (without_scheme, "/"),
    };
    let addr_str = if host_port.contains(':') {
        host_port.to_string()
    } else {
        format!("{}:80", host_port)
    };

    let timeout = Duration::from_secs(3);
    let mut addrs = match addr_str.to_socket_addrs() {
        Ok(it) => it,
        Err(_) => return false,
    };
    let sock_addr = match addrs.next() {
        Some(a) => a,
        None => return false,
    };

    let mut stream = match TcpStream::connect_timeout(&sock_addr, timeout) {
        Ok(s) => s,
        Err(_) => return false,
    };
    let _ = stream.set_read_timeout(Some(timeout));
    let _ = stream.set_write_timeout(Some(timeout));

    let key_header = match api_key {
        Some(k) if !k.is_empty() => format!("X-API-Key: {}\r\n", k),
        _ => String::new(),
    };
    let req = format!(
        "POST {} HTTP/1.1\r\nHost: {}\r\nUser-Agent: app-stats-rust/1.0\r\nContent-Type: application/json\r\n{}Content-Length: {}\r\nConnection: close\r\n\r\n{}",
        path,
        host_port,
        key_header,
        body.len(),
        body
    );

    if stream.write_all(req.as_bytes()).is_err() {
        return false;
    }

    let mut buf = [0u8; 64];
    match stream.read(&mut buf) {
        Ok(n) if n > 12 => {
            let head = String::from_utf8_lossy(&buf[..n]);
            head.starts_with("HTTP/1.1 2") || head.starts_with("HTTP/1.0 2")
        }
        _ => false,
    }
}

fn post_https_curl(url: &str, body: &str, api_key: Option<&str>) -> bool {
    let mut cmd = Command::new("curl");
    cmd.args([
        "-sS",
        "-o",
        if cfg!(windows) { "NUL" } else { "/dev/null" },
        "-w",
        "%{http_code}",
        "--max-time",
        "3",
        "-X",
        "POST",
        "-H",
        "Content-Type: application/json",
        "-H",
        "User-Agent: app-stats-rust/1.0",
    ]);
    if let Some(key) = api_key {
        if !key.is_empty() {
            cmd.arg("-H").arg(format!("X-API-Key: {}", key));
        }
    }
    cmd.arg("-d").arg(body).arg(url);
    cmd.stderr(Stdio::null());

    match cmd.output() {
        Ok(out) if out.status.success() => {
            let code = String::from_utf8_lossy(&out.stdout);
            code.trim().starts_with('2')
        }
        _ => false,
    }
}

/// Compact pure-Rust SHA-256 (FIPS 180-4) so the SDK has zero crate dependencies.
fn sha256(input: &[u8]) -> [u8; 32] {
    const K: [u32; 64] = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ];

    let mut h: [u32; 8] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
        0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
    ];

    let bit_len = (input.len() as u64) * 8;
    let mut msg = input.to_vec();
    msg.push(0x80);
    while (msg.len() % 64) != 56 {
        msg.push(0);
    }
    msg.extend_from_slice(&bit_len.to_be_bytes());

    for chunk in msg.chunks_exact(64) {
        let mut w = [0u32; 64];
        for i in 0..16 {
            w[i] = u32::from_be_bytes([
                chunk[4 * i],
                chunk[4 * i + 1],
                chunk[4 * i + 2],
                chunk[4 * i + 3],
            ]);
        }
        for i in 16..64 {
            let s0 = w[i - 15].rotate_right(7) ^ w[i - 15].rotate_right(18) ^ (w[i - 15] >> 3);
            let s1 = w[i - 2].rotate_right(17) ^ w[i - 2].rotate_right(19) ^ (w[i - 2] >> 10);
            w[i] = w[i - 16].wrapping_add(s0).wrapping_add(w[i - 7]).wrapping_add(s1);
        }

        let [mut a, mut b, mut c, mut d, mut e, mut f, mut g, mut hh] = h;
        for i in 0..64 {
            let s1 = e.rotate_right(6) ^ e.rotate_right(11) ^ e.rotate_right(25);
            let ch = (e & f) ^ ((!e) & g);
            let t1 = hh.wrapping_add(s1).wrapping_add(ch).wrapping_add(K[i]).wrapping_add(w[i]);
            let s0 = a.rotate_right(2) ^ a.rotate_right(13) ^ a.rotate_right(22);
            let maj = (a & b) ^ (a & c) ^ (b & c);
            let t2 = s0.wrapping_add(maj);

            hh = g;
            g = f;
            f = e;
            e = d.wrapping_add(t1);
            d = c;
            c = b;
            b = a;
            a = t1.wrapping_add(t2);
        }

        h[0] = h[0].wrapping_add(a);
        h[1] = h[1].wrapping_add(b);
        h[2] = h[2].wrapping_add(c);
        h[3] = h[3].wrapping_add(d);
        h[4] = h[4].wrapping_add(e);
        h[5] = h[5].wrapping_add(f);
        h[6] = h[6].wrapping_add(g);
        h[7] = h[7].wrapping_add(hh);
    }

    let mut out = [0u8; 32];
    for (i, word) in h.iter().enumerate() {
        out[4 * i..4 * i + 4].copy_from_slice(&word.to_be_bytes());
    }
    out
}
