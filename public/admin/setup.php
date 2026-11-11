<?php
require __DIR__ . '/../../includes/bootstrap.php';
if (admin_exists()) { header('Location: ' . base_url() . 'admin/login.php'); exit; }
page_head('Create admin password', ['noindex' => true, 'csrf' => true]);
?>
  <main class="page page--narrow">
    <header class="page-header"><h1>Create the admin password</h1><p>This is the first start. Choose a password for the admin area. It is stored only as a secure hash.</p></header>
    <form class="card" id="form">
      <div class="field"><label for="pw">Password</label><input class="input" id="pw" type="password" autocomplete="new-password" minlength="10" required><span class="hint">At least 10 characters.</span></div>
      <div class="field"><label for="pw2">Repeat password</label><input class="input" id="pw2" type="password" autocomplete="new-password" minlength="10" required></div>
      <p class="error-text" id="error" role="alert" hidden></p>
      <div class="row"><button class="btn btn--primary" type="submit">Create password</button></div>
    </form>
  </main>
<?php page_foot('setup.js'); ?>
