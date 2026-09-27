'use strict';
$(function () {
  Nura.session().done(data => {
    if (data.authenticated) location.replace('profile.html');
    else Nura.busy('#register-form', false);
  }).fail(xhr => Nura.error(xhr));
  $('#register-form').on('submit', function (event) {
    event.preventDefault();
    if (!this.reportValidity()) return;
    const password = $('#password').val();
    if (password !== $('#confirm-password').val()) return Nura.message('Your passwords do not match.');
    const bytes = new TextEncoder().encode(password).length;
    if (bytes < 12 || bytes > 72) return Nura.message('Use a password between 12 and 72 bytes long.');
    Nura.busy(this, true);
    Nura.request('php/register.php', 'POST', {username: $('#username').val().trim(), email: $('#email').val().trim(), password})
      .done(() => location.assign('login.html?registered=1'))
      .fail(xhr => Nura.error(xhr)).always(() => Nura.busy(this, false));
  });
});
