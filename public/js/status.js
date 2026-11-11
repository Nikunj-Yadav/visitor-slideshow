/* Upload result page. Reads the summary saved by upload.js (never contains photos). */
(function () {
  App.mountNav('share'); App.footer();
  const main = document.getElementById('main');
  let data = null;
  try { data = JSON.parse(sessionStorage.getItem('mw_result')); } catch (e) { /* none */ }
  if (!data) {
    main.innerHTML = `<div class="card empty">${App.icon('image')}<h3>Nothing to show yet</h3><p>Upload some photos and your result will appear here.</p><a class="btn btn--primary" href="index.php">Share photos</a></div>`;
    return;
  }
  const done = data.results.filter((r) => r.state === 'done').length;
  const problems = data.results.filter((r) => r.state !== 'done');
  main.innerHTML = `
    <header class="page-header"><h1 id="h"></h1><p id="sub"></p></header>
    <section class="card" id="codecard" hidden>
      <h2>Your visitor code</h2>
      <div class="code-box" id="code"></div>
      <p class="hint">Keep this code. It lets you come back, see your photos again and remove them. We only store a scrambled version of it, so it cannot be recovered if you lose it.</p>
      <div class="row"><button class="btn" id="copy">${App.icon('copy')} Copy code</button></div>
    </section>
    <section class="card" id="problems" hidden><h2>Needs attention</h2><ul class="list" id="plist"></ul></section>
    <div class="row">
      <a class="btn btn--primary" href="slideshow.php">Watch the slideshow</a>
      <a class="btn" href="my-photos.php">My photos</a>
      <a class="btn" href="index.php">Add more</a>
    </div>`;
  const $ = (id) => document.getElementById(id);
  $('h').textContent = done ? (done === 1 ? 'Your photo is on the wall' : `${done} photos are on the wall`) : 'No photos were added';
  $('sub').textContent = done ? 'They are in the live queue and will appear on the display shortly.' : 'See below for what went wrong and how to fix it.';
  if (data.code) {
    $('codecard').hidden = false; $('code').textContent = data.code;
    $('copy').onclick = async () => { try { await navigator.clipboard.writeText(data.code); App.toast('Code copied.', 'success'); } catch (e) { App.toast('Select the code and copy it manually.', 'info'); } };
  }
  if (problems.length) {
    $('problems').hidden = false;
    for (const p of problems) {
      const li = document.createElement('li');
      const a = document.createElement('span'); a.textContent = p.name;
      const b = document.createElement('span'); b.className = p.state === 'dup' ? 'state--warn' : 'state--bad'; b.textContent = p.text || 'Not added';
      li.append(a, b); $('plist').appendChild(li);
    }
  }
})();
