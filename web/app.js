(() => {
  const I18N = {
    fr: {
      subtitle: "Statistiques anonymes & multi-langages (PHP)",
      allApps: "Toutes les apps",
      last7: "7 derniers jours",
      last30: "30 derniers jours",
      last90: "90 derniers jours",
      allTime: "Tout l'historique",
      refresh: "Actualiser",
      connectCode: "Relier mon code",
      exportCsv: "CSV",
      totalUses: "Nombre d'utilisations",
      uniqueUsers: "Appareils uniques",
      anonymousHash: "Identifiant anonyme SHA-256",
      activeVersions: "Versions actives",
      trackedVersions: "Versions suivies",
      countries: "Pays",
      noLocation: "Sans géolocalisation précise",
      usageOverTime: "Utilisations par jour",
      usageSubtitle: "Évolution quotidienne des lancements et appareils uniques",
      sendTest: "+ Ping de test",
      byVersion: "Versions de l'application",
      byOs: "Systèmes d'exploitation (OS)",
      byCountry: "Pays",
      bySdk: "Langages / SDK",
      recentEvents: "Dernières utilisations",
      recentSubtitle: "30 derniers événements enregistrés",
      colDate: "Date (UTC)",
      colApp: "Application",
      colVersion: "Version",
      colOs: "OS",
      colCountry: "Pays",
      colSdk: "SDK",
      colEvent: "Événement",
      sdkModalTitle: "Relier votre application",
      sdkModalDesc: "Copiez le fichier SDK correspondant dans votre projet puis appelez-le au démarrage.",
      copy: "Copier",
      copied: "Copié !",
      todayMeta: (n) => `${n.toLocaleString()} aujourd'hui`,
      usesWord: "utilisations",
      usersWord: "uniques",
      noData: "Aucune donnée pour cette période. Cliquez sur « + Ping de test » ou reliez un SDK.",
      unknownCountry: "Inconnu",
      errorLoad: "Impossible de charger les statistiques :",
    },
    en: {
      subtitle: "Privacy-first multi-language analytics (PHP)",
      allApps: "All apps",
      last7: "Last 7 days",
      last30: "Last 30 days",
      last90: "Last 90 days",
      allTime: "All time",
      refresh: "Refresh",
      connectCode: "Connect my code",
      exportCsv: "CSV",
      totalUses: "Total uses",
      uniqueUsers: "Unique devices",
      anonymousHash: "Anonymous SHA-256 ID",
      activeVersions: "Active versions",
      trackedVersions: "Tracked versions",
      countries: "Countries",
      noLocation: "No precise geolocation",
      usageOverTime: "Daily usage",
      usageSubtitle: "Daily launches and unique devices",
      sendTest: "+ Test ping",
      byVersion: "App versions",
      byOs: "Operating systems (OS)",
      byCountry: "Countries",
      bySdk: "Languages / SDKs",
      recentEvents: "Recent uses",
      recentSubtitle: "Latest 30 recorded events",
      colDate: "Date (UTC)",
      colApp: "App",
      colVersion: "Version",
      colOs: "OS",
      colCountry: "Country",
      colSdk: "SDK",
      colEvent: "Event",
      sdkModalTitle: "Connect your application",
      sdkModalDesc: "Drop the single-file SDK into your project and call it at startup.",
      copy: "Copy",
      copied: "Copied!",
      todayMeta: (n) => `${n.toLocaleString()} today`,
      usesWord: "uses",
      usersWord: "unique",
      noData: "No data for this period yet. Click “+ Test ping” or connect an SDK.",
      unknownCountry: "Unknown",
      errorLoad: "Failed to load statistics:",
    },
  };

  let currentLang = (navigator.language || "fr").toLowerCase().startsWith("fr") ? "fr" : "en";
  let activeSdkTab = "python";
  let lastData = null;

  const els = {
    appFilter: document.getElementById("appFilter"),
    daysFilter: document.getElementById("daysFilter"),
    refreshBtn: document.getElementById("refreshBtn"),
    exportCsvBtn: document.getElementById("exportCsvBtn"),
    langToggle: document.getElementById("langToggle"),
    statusBanner: document.getElementById("statusBanner"),
    kpiTotalUses: document.getElementById("kpiTotalUses"),
    kpiTodayMeta: document.getElementById("kpiTodayMeta"),
    kpiUniqueUsers: document.getElementById("kpiUniqueUsers"),
    kpiVersions: document.getElementById("kpiVersions"),
    kpiCountries: document.getElementById("kpiCountries"),
    dailyChart: document.getElementById("dailyChart"),
    versionList: document.getElementById("versionList"),
    osList: document.getElementById("osList"),
    countryList: document.getElementById("countryList"),
    sdkList: document.getElementById("sdkList"),
    recentTableBody: document.getElementById("recentTableBody"),
    sendTestBtn: document.getElementById("sendTestBtn"),
    sdkModal: document.getElementById("sdkModal"),
    sdkModalBtn: document.getElementById("sdkModalBtn"),
    sdkModalBackdrop: document.getElementById("sdkModalBackdrop"),
    closeSdkModal: document.getElementById("closeSdkModal"),
    sdkTabs: document.getElementById("sdkTabs"),
    sdkFilePath: document.getElementById("sdkFilePath"),
    sdkSnippet: document.getElementById("sdkSnippet"),
    copySnippetBtn: document.getElementById("copySnippetBtn"),
  };

  function t(key) {
    return I18N[currentLang][key] ?? key;
  }

  function getBaseUrl() {
    const url = new URL(window.location.href);
    const dir = url.pathname.replace(/\/index\.php$/i, "").replace(/\/+$/, "");
    return `${url.origin}${dir}`;
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function countryFlag(code) {
    if (!code || code.length !== 2 || code === "UNKNOWN") return "🌐";
    const upper = code.toUpperCase();
    const a = upper.charCodeAt(0);
    const b = upper.charCodeAt(1);
    if (a < 65 || a > 90 || b < 65 || b > 90) return "🌐";
    return String.fromCodePoint(0x1f1e6 + (a - 65), 0x1f1e6 + (b - 65));
  }

  function countryName(code) {
    if (!code || code === "UNKNOWN") return t("unknownCountry");
    try {
      const dn = new Intl.DisplayNames([currentLang], { type: "region" });
      return `${dn.of(code)} (${code})`;
    } catch {
      return code;
    }
  }

  function applyTranslations() {
    document.documentElement.lang = currentLang;
    els.langToggle.textContent = currentLang === "fr" ? "EN" : "FR";
    document.querySelectorAll("[data-i18n]").forEach((node) => {
      const key = node.getAttribute("data-i18n");
      const val = t(key);
      if (typeof val === "string") node.textContent = val;
    });
    if (lastData) renderStats(lastData);
    renderSdkSnippet();
  }

  function renderBarList(container, rows, labelFn, total) {
    if (!rows || rows.length === 0) {
      container.innerHTML = `<div class="empty-state">${escapeHtml(t("noData"))}</div>`;
      return;
    }
    const maxVal = Math.max(...rows.map((r) => r.uses), 1);
    container.innerHTML = rows
      .map((row) => {
        const pct = total > 0 ? Math.round((row.uses / total) * 100) : 0;
        const width = Math.max(4, Math.round((row.uses / maxVal) * 100));
        return `
          <div class="bar-item">
            <div class="bar-row">
              <span class="bar-label">${labelFn(row)}</span>
              <span class="bar-stats">${row.uses.toLocaleString()} ${escapeHtml(t("usesWord"))} (${pct}%) · ${row.unique_users.toLocaleString()} ${escapeHtml(t("usersWord"))}</span>
            </div>
            <div class="bar-track">
              <div class="bar-fill" style="width:${width}%"></div>
            </div>
          </div>
        `;
      })
      .join("");
  }

  function renderDailyChart(daily) {
    if (!daily || daily.length === 0) {
      els.dailyChart.innerHTML = `<div class="empty-state">${escapeHtml(t("noData"))}</div>`;
      return;
    }

    const width = 920;
    const height = 210;
    const padL = 42;
    const padR = 16;
    const padT = 18;
    const padB = 34;
    const chartW = width - padL - padR;
    const chartH = height - padT - padB;
    const maxUses = Math.max(...daily.map((d) => d.uses), 1);
    const barSlot = chartW / daily.length;
    const barW = Math.max(6, Math.min(36, barSlot * 0.62));

    const gridLines = [0, 0.25, 0.5, 0.75, 1]
      .map((ratio) => {
        const y = padT + chartH * (1 - ratio);
        const val = Math.round(maxUses * ratio);
        return `
          <line x1="${padL}" y1="${y}" x2="${width - padR}" y2="${y}" stroke="#243154" stroke-dasharray="3 4" />
          <text x="${padL - 8}" y="${y + 4}" text-anchor="end" fill="#93a3c3" font-size="11">${val}</text>
        `;
      })
      .join("");

    const labelStep = Math.max(1, Math.ceil(daily.length / 8));
    const bars = daily
      .map((d, i) => {
        const xCenter = padL + i * barSlot + barSlot / 2;
        const barH = Math.max(2, (d.uses / maxUses) * chartH);
        const y = padT + chartH - barH;
        const showLabel = i % labelStep === 0 || i === daily.length - 1;
        const label = showLabel
          ? `<text x="${xCenter}" y="${height - 10}" text-anchor="middle" fill="#93a3c3" font-size="11">${escapeHtml(d.date.slice(5))}</text>`
          : "";
        return `
          <g>
            <rect x="${xCenter - barW / 2}" y="${y}" width="${barW}" height="${barH}" rx="4" fill="url(#barGrad)">
              <title>${escapeHtml(d.date)}: ${d.uses} ${escapeHtml(t("usesWord"))} (${d.unique_users} ${escapeHtml(t("usersWord"))})</title>
            </rect>
            ${label}
          </g>
        `;
      })
      .join("");

    els.dailyChart.innerHTML = `
      <svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" role="img" aria-label="${escapeHtml(t("usageOverTime"))}">
        <defs>
          <linearGradient id="barGrad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#22d3ee" />
            <stop offset="100%" stop-color="#4f8cff" />
          </linearGradient>
        </defs>
        ${gridLines}
        ${bars}
      </svg>
    `;
  }

  function renderStats(data) {
    lastData = data;
    const totals = data.totals || {};
    els.kpiTotalUses.textContent = (totals.total_uses ?? 0).toLocaleString();
    els.kpiTodayMeta.textContent = I18N[currentLang].todayMeta(totals.uses_today ?? 0);
    els.kpiUniqueUsers.textContent = (totals.unique_users ?? 0).toLocaleString();
    els.kpiVersions.textContent = (totals.versions_count ?? 0).toLocaleString();
    els.kpiCountries.textContent = (totals.countries_count ?? 0).toLocaleString();

    const currentApp = els.appFilter.value;
    const apps = data.apps || [];
    els.appFilter.innerHTML =
      `<option value="all">${escapeHtml(t("allApps"))}</option>` +
      apps.map((a) => `<option value="${escapeHtml(a)}">${escapeHtml(a)}</option>`).join("");
    if (apps.includes(currentApp)) {
      els.appFilter.value = currentApp;
    }

    const params = new URLSearchParams({
      route: "export.csv",
      app: els.appFilter.value,
      days: els.daysFilter.value,
    });
    els.exportCsvBtn.href = `api.php?${params.toString()}`;

    renderDailyChart(data.daily_uses);

    const totalUses = totals.total_uses || 0;
    renderBarList(
      els.versionList,
      data.by_version,
      (r) => `<span class="badge">v${escapeHtml(r.version)}</span>`,
      totalUses
    );
    renderBarList(els.osList, data.by_os, (r) => escapeHtml(r.os), totalUses);
    renderBarList(
      els.countryList,
      data.by_country,
      (r) => `${countryFlag(r.country)} ${escapeHtml(countryName(r.country))}`,
      totalUses
    );
    renderBarList(
      els.sdkList,
      data.by_sdk,
      (r) => `<span class="badge badge-sdk">${escapeHtml(r.sdk)}</span>`,
      totalUses
    );

    const recent = data.recent_events || [];
    if (recent.length === 0) {
      els.recentTableBody.innerHTML = `<tr><td colspan="7" class="empty-cell">${escapeHtml(t("noData"))}</td></tr>`;
    } else {
      els.recentTableBody.innerHTML = recent
        .map(
          (ev) => `
          <tr>
            <td class="mono">${escapeHtml(ev.created_at.replace("T", " ").replace("Z", ""))}</td>
            <td><strong>${escapeHtml(ev.app_name)}</strong></td>
            <td><span class="badge">v${escapeHtml(ev.app_version)}</span></td>
            <td>${escapeHtml(ev.os)} <span class="bar-stats">${escapeHtml(ev.arch)}</span></td>
            <td>${countryFlag(ev.country)} ${escapeHtml(ev.country)}</td>
            <td><span class="badge badge-sdk">${escapeHtml(ev.sdk)}</span></td>
            <td>${escapeHtml(ev.event)}</td>
          </tr>
        `
        )
        .join("");
    }
  }

  function showError(msg) {
    els.statusBanner.textContent = msg;
    els.statusBanner.classList.remove("hidden");
  }

  function clearError() {
    els.statusBanner.classList.add("hidden");
  }

  async function loadStats() {
    clearError();
    const params = new URLSearchParams({
      route: "stats",
      app: els.appFilter.value,
      days: els.daysFilter.value,
    });
    try {
      const res = await fetch(`api.php?${params.toString()}`);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      renderStats(data);
    } catch (err) {
      showError(`${t("errorLoad")} ${err.message}`);
    }
  }

  async function sendTestPing() {
    const sampleCountries = ["FR", "US", "CA", "DE", "GB", "JP", "BE", "CH"];
    const sampleOs = ["Windows", "Linux", "macOS"];
    const sampleSdks = ["python", "java", "c", "csharp", "rust"];
    const sampleVersions = ["1.0.0", "1.1.0", "1.2.0"];
    const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];

    try {
      const res = await fetch("api.php?route=track", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          app_name: "demo-app",
          app_version: pick(sampleVersions),
          os: pick(sampleOs),
          os_version: "test",
          arch: "x86_64",
          country: pick(sampleCountries),
          client_id: "demo-" + Math.floor(Math.random() * 15),
          sdk: pick(sampleSdks),
          event: "launch",
        }),
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      await loadStats();
    } catch (err) {
      showError(err.message);
    }
  }

  function getSnippets() {
    const base = getBaseUrl();
    return {
      python: {
        file: "sdks/python/app_stats.py",
        code: `from app_stats import AppStats\n\nstats = AppStats("${base}", app_name="my-app", app_version="1.0.0")\nstats.track()  # non-blocking background call`,
      },
      java: {
        file: "sdks/java/AppStats.java",
        code: `AppStats stats = new AppStats("${base}", "my-app", "1.0.0");\nstats.track(); // async non-blocking call`,
      },
      c: {
        file: "sdks/c/app_stats.h",
        code: `#define APP_STATS_IMPLEMENTATION\n#include "app_stats.h"\n\nint main(void) {\n    app_stats_track("${base}", "my-app", "1.0.0", "launch", NULL);\n    return 0;\n}`,
      },
      csharp: {
        file: "sdks/csharp/AppStats.cs",
        code: `using AppStatsSdk;\n\nvar stats = new AppStats("${base}", "my-app", "1.0.0");\nawait stats.TrackAsync(); // or stats.TrackInBackground();`,
      },
      rust: {
        file: "sdks/rust/app_stats.rs",
        code: `mod app_stats;\n\nfn main() {\n    app_stats::AppStats::new("${base}", "my-app", "1.0.0").track();\n}`,
      },
    };
  }

  function renderSdkSnippet() {
    const snippet = getSnippets()[activeSdkTab];
    els.sdkFilePath.textContent = snippet.file;
    els.sdkSnippet.textContent = snippet.code;
  }

  els.appFilter.addEventListener("change", loadStats);
  els.daysFilter.addEventListener("change", loadStats);
  els.refreshBtn.addEventListener("click", loadStats);
  els.sendTestBtn.addEventListener("click", sendTestPing);

  els.langToggle.addEventListener("click", () => {
    currentLang = currentLang === "fr" ? "en" : "fr";
    applyTranslations();
  });

  els.sdkModalBtn.addEventListener("click", () => {
    renderSdkSnippet();
    els.sdkModal.classList.remove("hidden");
  });
  els.closeSdkModal.addEventListener("click", () => els.sdkModal.classList.add("hidden"));
  els.sdkModalBackdrop.addEventListener("click", () => els.sdkModal.classList.add("hidden"));

  els.sdkTabs.addEventListener("click", (e) => {
    const btn = e.target.closest(".tab");
    if (!btn) return;
    activeSdkTab = btn.dataset.lang;
    els.sdkTabs.querySelectorAll(".tab").forEach((b) => b.classList.toggle("active", b === btn));
    renderSdkSnippet();
  });

  els.copySnippetBtn.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(els.sdkSnippet.textContent);
      els.copySnippetBtn.textContent = t("copied");
      setTimeout(() => {
        els.copySnippetBtn.textContent = t("copy");
      }, 1500);
    } catch {
      // Clipboard API unavailable
    }
  });

  applyTranslations();
  loadStats();
})();
