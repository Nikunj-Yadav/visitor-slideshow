(function () {
  App.mountNav('', 'visitor');
  const $ = (id) => document.getElementById(id);
  $('form').addEventListener('submit', async (e) => {
    e.preventDefault(); $('error').hidden = true;
    try { await App.api('/api/admin/login', { method: 'POST', body: { password: $('pw').value } }); location.href = '/admin'; }
    catch (err) { $('error').textContent = err.message; $('error').hidden = false; $('pw').select(); }
  });
})();
