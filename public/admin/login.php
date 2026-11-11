<?php
require __DIR__ . '/../../includes/bootstrap.php';
if (!admin_exists()) { header('Location: ' . base_url() . 'admin/setup.php'); exit; }
if (is_admin()) { header('Location: ' . base_url() . 'admin/index.php'); exit; }
page_head('Staff sign in', ['noindex' => true, 'csrf' => true]);
?>
  <main class="page page--narrow">
    <header class="page-header"><h1>Staff sign in</h1><p>Sign in to manage visitors and photos.</p></header>
    <form class="card" id="form">
      <div class="field"><label for="pw">Password</label><input class="input" id="pw" type="password" autocomplete="current-password" required></div>
      <p class="error-text" id="error" role="alert" hidden></p>
      <div class="row"><button class="btn btn--primary" type="submit">Sign in</button></div>
    </form>
  </main>
<?php page_foot('login.js'); ?>
