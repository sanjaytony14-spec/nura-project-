'use strict';
$(function () {
  if (new URLSearchParams(location.search).has('registered')) Nura.message('Account created. Sign in to make yourself at home.', true);
  if (new URLSearchParams(location.search).has('expired')) Nura.message('Please sign in to access your profile.');
  Nura.session().done(data => {
    if (data.authenticated) location.replace('profile.html');
    else Nura.busy('#login-form', false);
  }).fail(xhr => Nura.error(xhr));
  $('#login-form').on('submit', function (event) {
    event.preventDefault();
    if (!this.reportValidity()) return;
    Nura.busy(this, true);
    Nura.request('php/login.php', 'POST', {identifier: $('#identifier').val().trim(), password: $('#password').val()})
      .done(() => location.assign('profile.html'))
      .fail(xhr => Nura.error(xhr)).always(() => Nura.busy(this, false));
  });
});
