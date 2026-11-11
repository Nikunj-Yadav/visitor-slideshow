/* First-time admin setup: create the admin password, then go to the dashboard. */
(function () {
  App.mountNav('', 'visitor');
  const $ = (id) => document.getElementById(id);
  $('form').addEventListener('submit', async (e) => {
    e.preventDefault(); $('error').hidden = true;
    try {
      await App.api('api/admin/setup.php', { method: 'POST', body: { password: $('pw').value, confirm: $('pw2').value } });
      location.href = 'admin/index.php';
    } catch (err) { $('error').textContent = err.message; $('error').hidden = false; }
  });
})();
