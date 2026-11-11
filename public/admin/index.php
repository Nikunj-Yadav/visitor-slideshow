<?php
require __DIR__ . '/../../includes/bootstrap.php';
require_admin_page();
page_head('Dashboard', ['noindex' => true, 'csrf' => true]);
?>
  <main class="page" id="main">
    <header class="page-header"><h1>Dashboard</h1><p>A live view of what visitors have shared.</p></header>
    <section class="grid-stats" id="stats" aria-label="Statistics"><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></section>
    <div class="grid-2">
      <section class="card"><h2>Recent uploads</h2><div id="recent"></div></section>
      <section class="card"><h2>Recent activity</h2><div id="activity"></div></section>
    </div>
    <form class="card" id="settings">
      <h2>Display settings</h2>
      <div class="grid-2">
        <div class="field"><label for="duration">Seconds per photo</label><input class="input" id="duration" type="number" min="3" max="120" step="1" inputmode="numeric"><span class="hint">Between 3 and 120 seconds.</span></div>
        <div class="field"><span class="label">Visitor names</span>
          <label class="switch"><input type="checkbox" id="shownames" role="switch"><span>Show first names on the display</span></label>
          <span class="hint">Only the first name is ever shown.</span></div>
      </div>
      <p class="error-text" id="serror" role="alert" hidden></p>
      <div class="row"><button class="btn btn--primary" type="submit">Save settings</button></div>
    </form>
  </main>
<?php page_foot('admin.js'); ?>
