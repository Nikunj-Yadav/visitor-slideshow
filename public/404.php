<?php
require __DIR__ . '/../includes/bootstrap.php';
http_response_code(404);
page_head('Page not found');
?>
  <main class="page page--narrow"><div class="card empty"><h1>Page not found</h1><p>That page does not exist.</p><a class="btn btn--primary" href="index.php">Back to the start</a></div></main>
<?php page_foot('notfound.js'); ?>
