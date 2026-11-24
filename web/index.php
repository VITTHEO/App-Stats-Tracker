<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>App Stats Tracker — Dashboard</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <div class="brand">
        <div class="brand-logo" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M7 15l4-4 3 3 6-7" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div>
          <h1 class="brand-title">App Stats Tracker</h1>
          <p class="brand-subtitle" data-i18n="subtitle">Statistiques anonymes &amp; multi-langages (PHP)</p>
        </div>
      </div>

      <div class="controls">
        <select id="appFilter" class="select" aria-label="Application">
          <option value="all" data-i18n="allApps">Toutes les apps</option>
        </select>

        <select id="daysFilter" class="select" aria-label="Période">
          <option value="7" data-i18n="last7">7 derniers jours</option>
          <option value="30" selected data-i18n="last30">30 derniers jours</option>
          <option value="90" data-i18n="last90">90 derniers jours</option>
          <option value="all" data-i18n="allTime">Tout l'historique</option>
        </select>

        <button id="refreshBtn" class="btn btn-secondary" type="button" data-i18n="refresh">Actualiser</button>
        <button id="sdkModalBtn" class="btn btn-primary" type="button" data-i18n="connectCode">Relier mon code</button>
        <a id="exportCsvBtn" class="btn btn-ghost" href="api.php?route=export.csv" download="app-stats.csv" data-i18n="exportCsv">CSV</a>
        <button id="langToggle" class="btn btn-ghost" type="button" title="Switch language">EN</button>
      </div>
    </div>
  </header>

  <main class="container">
    <div id="statusBanner" class="banner hidden"></div>

    <!-- KPI Cards -->
    <section class="kpi-grid" aria-label="Indicateurs clés">
      <article class="card kpi-card">
        <span class="kpi-label" data-i18n="totalUses">Nombre d'utilisations</span>
        <strong class="kpi-value" id="kpiTotalUses">—</strong>
        <span class="kpi-meta" id="kpiTodayMeta">—</span>
      </article>

      <article class="card kpi-card">
        <span class="kpi-label" data-i18n="uniqueUsers">Appareils uniques</span>
        <strong class="kpi-value" id="kpiUniqueUsers">—</strong>
        <span class="kpi-meta" data-i18n="anonymousHash">Identifiant anonyme SHA-256</span>
      </article>

      <article class="card kpi-card">
        <span class="kpi-label" data-i18n="activeVersions">Versions actives</span>
        <strong class="kpi-value" id="kpiVersions">—</strong>
        <span class="kpi-meta" data-i18n="trackedVersions">Versions suivies</span>
      </article>

      <article class="card kpi-card">
        <span class="kpi-label" data-i18n="countries">Pays</span>
        <strong class="kpi-value" id="kpiCountries">—</strong>
        <span class="kpi-meta" data-i18n="noLocation">Sans géolocalisation précise</span>
      </article>
    </section>

    <!-- Daily Chart -->
    <section class="card chart-card">
      <div class="card-header">
        <div>
          <h2 data-i18n="usageOverTime">Utilisations par jour</h2>
          <p class="card-subtitle" data-i18n="usageSubtitle">Évolution quotidienne des lancements et appareils uniques</p>
        </div>
        <button id="sendTestBtn" class="btn btn-ghost btn-sm" type="button" data-i18n="sendTest">+ Ping de test</button>
      </div>
      <div id="dailyChart" class="daily-chart"></div>
    </section>

    <!-- Breakdown Grid -->
    <section class="grid-2">
      <article class="card">
        <div class="card-header">
          <h2 data-i18n="byVersion">Versions de l'application</h2>
        </div>
        <div id="versionList" class="bar-list"></div>
      </article>

      <article class="card">
        <div class="card-header">
          <h2 data-i18n="byOs">Systèmes d'exploitation (OS)</h2>
        </div>
        <div id="osList" class="bar-list"></div>
      </article>

      <article class="card">
        <div class="card-header">
          <h2 data-i18n="byCountry">Pays</h2>
        </div>
        <div id="countryList" class="bar-list"></div>
      </article>

      <article class="card">
        <div class="card-header">
          <h2 data-i18n="bySdk">Langages / SDK</h2>
        </div>
        <div id="sdkList" class="bar-list"></div>
      </article>
    </section>

    <!-- Recent Events -->
    <section class="card">
      <div class="card-header">
        <div>
          <h2 data-i18n="recentEvents">Dernières utilisations</h2>
          <p class="card-subtitle" data-i18n="recentSubtitle">30 derniers événements enregistrés</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th data-i18n="colDate">Date (UTC)</th>
              <th data-i18n="colApp">Application</th>
              <th data-i18n="colVersion">Version</th>
              <th data-i18n="colOs">OS</th>
              <th data-i18n="colCountry">Pays</th>
              <th data-i18n="colSdk">SDK</th>
              <th data-i18n="colEvent">Événement</th>
            </tr>
          </thead>
          <tbody id="recentTableBody">
            <tr><td colspan="7" class="empty-cell">Chargement...</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <!-- SDK Integration Modal -->
  <div id="sdkModal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="sdkModalTitle">
    <div class="modal-backdrop" id="sdkModalBackdrop"></div>
    <div class="modal-panel">
      <div class="modal-header">
        <div>
          <h2 id="sdkModalTitle" data-i18n="sdkModalTitle">Relier votre application</h2>
          <p class="card-subtitle" data-i18n="sdkModalDesc">Copiez le fichier SDK correspondant dans votre projet puis appelez-le au démarrage.</p>
        </div>
        <button id="closeSdkModal" class="btn btn-ghost btn-sm" type="button" aria-label="Fermer">✕</button>
      </div>

      <div class="tabs" id="sdkTabs">
        <button class="tab active" data-lang="python" type="button">Python</button>
        <button class="tab" data-lang="java" type="button">Java</button>
        <button class="tab" data-lang="c" type="button">C</button>
        <button class="tab" data-lang="csharp" type="button">C#</button>
        <button class="tab" data-lang="rust" type="button">Rust</button>
      </div>

      <div class="code-box">
        <div class="code-meta">
          <span id="sdkFilePath" class="mono">sdks/python/app_stats.py</span>
          <button id="copySnippetBtn" class="btn btn-secondary btn-sm" type="button" data-i18n="copy">Copier</button>
        </div>
        <pre><code id="sdkSnippet"></code></pre>
      </div>
    </div>
  </div>

  <footer class="footer">
    <span>App Stats Tracker by <strong>VITTHEO</strong> — PHP · Python · Java · C · C# · Rust</span>
  </footer>

  <script src="app.js"></script>
</body>
</html>
