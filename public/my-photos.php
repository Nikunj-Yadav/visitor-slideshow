<?php
require __DIR__ . '/../includes/bootstrap.php';
page_head('My photos');
?>
  <main class="page" id="main">
    <header class="page-header">
      <h1>My photos</h1>
      <p>Enter your visitor code to see, remove or add to the photos you shared.</p>
    </header>
    <form class="card card--form" id="form">
      <div class="field">
        <label for="code">Your visitor code</label>
        <input class="input" id="code" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXXX-XXXXX">
      </div>
      <p class="error-text" id="error" role="alert" hidden></p>
      <div class="row"><button class="btn btn--primary" type="submit">Show my photos</button></div>
    </form>
    <section id="result" hidden></section>
  </main>
<?php page_foot('my-photos.js'); ?>
