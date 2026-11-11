<?php
require __DIR__ . '/../../includes/bootstrap.php';
require_admin_page();
page_head('Manage', ['noindex' => true, 'csrf' => true]);
?>
  <main class="page" id="main">
    <header class="page-header"><h1>Manage</h1><p>Hide or delete photos, and remove visitors on request.</p></header>
    <div class="row row--between">
      <div class="segmented" role="group" aria-label="View"><button id="tab-photos" aria-pressed="true">Photos</button><button id="tab-visitors" aria-pressed="false">Visitors</button></div>
    </div>
    <div class="row" id="filters">
      <div class="search"><span id="search-icon"></span><input class="input" id="q" type="search" placeholder="Search by visitor name" aria-label="Search by visitor name"></div>
      <select class="input input--auto" id="status" aria-label="Filter by status"><option value="">All photos</option><option value="approved">On display</option><option value="hidden">Hidden</option></select>
      <select class="input input--auto" id="sort" aria-label="Sort"></select>
    </div>
    <section id="content" aria-live="polite"></section>
  </main>
<?php page_foot('manage.js'); ?>
