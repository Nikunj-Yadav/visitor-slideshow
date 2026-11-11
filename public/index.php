<?php
require __DIR__ . '/../includes/bootstrap.php';
page_head('Share your photos', ['description' => 'Upload photos and watch them appear on the live display.']);
?>
  <main class="page page--narrow">
    <header class="page-header">
      <h1>Share your photos</h1>
      <p>Pick a few favourites and they will appear on the live display within seconds.</p>
    </header>

    <form class="card" id="form" novalidate>
      <div class="row row--between">
        <div class="segmented" role="group" aria-label="Visitor type">
          <button type="button" id="mode-new" aria-pressed="true">I’m new here</button>
          <button type="button" id="mode-return" aria-pressed="false">I have a code</button>
        </div>
      </div>

      <div class="field" id="new-fields">
        <label for="name">Your name</label>
        <input class="input" id="name" name="name" maxlength="40" autocomplete="given-name" placeholder="For example, Asha">
        <span class="hint">You’ll get a personal code after your first upload so you can come back later.</span>
      </div>

      <div class="field" id="return-fields" hidden>
        <label for="code">Your visitor code</label>
        <input class="input" id="code" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXXX-XXXXX">
        <span class="hint" id="welcome">Your earlier photos will be shown again.</span>
      </div>

      <div class="dropzone" id="drop" tabindex="0" role="button" aria-label="Choose photos to upload">
        <span id="drop-icon"></span>
        <strong>Drop photos here or tap to choose</strong>
        <span id="limits" class="hint"></span>
      </div>
      <input type="file" id="file" accept="image/jpeg,image/png,image/webp" multiple hidden>

      <div class="previews" id="previews" aria-live="polite"></div>
      <div class="progress" id="overall" hidden role="progressbar" aria-valuemin="0" aria-valuemax="100"><span></span></div>

      <label class="check">
        <input type="checkbox" id="consent">
        <span><strong>Show my photos on the public display.</strong>
          <span class="hint hint--block">Anyone near the screen will be able to see them. You can remove them at any time with your visitor code on the “My photos” page.</span></span>
      </label>
      <p class="error-text" id="error" role="alert" hidden></p>

      <div class="row row--between">
        <span class="hint" id="count">No photos selected</span>
        <button class="btn btn--primary" id="submit" type="submit" disabled>Upload photos</button>
      </div>
    </form>
  </main>
<?php page_foot('upload.js'); ?>
