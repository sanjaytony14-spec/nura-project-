'use strict';
$(function () {
  function failure(xhr) {
    if (xhr.status === 401) location.replace('login.html?expired=1');
    else Nura.error(xhr);
  }
  function summary(profile, username) {
    $('#display-name').text(profile.name || username);
    $('#avatar').text((profile.name || username).slice(0, 2).toUpperCase());
    $('#display-bio').text(profile.bio || 'Your next chapter starts here. Add a little about yourself.');
  }
  Nura.request('php/profile.php').done(data => {
    Nura.csrf = data.csrf;
    $('#logout').prop('disabled', false);
    $('#username').text(data.account.username);
    $('#email').text(data.account.email);
    $('#joined').text(new Date(data.account.created_at.replace(' ', 'T') + 'Z').toLocaleDateString(undefined, {month:'long', year:'numeric'}));
    $('#name').val(data.profile.name);
    $('#age').val(data.profile.age ?? '');
    $('#bio').val(data.profile.bio);
    $('#interests').val(data.profile.interests.join(', '));
    summary(data.profile, data.account.username);
    $('#profile-content').prop('hidden', false);
    $('#loading').prop('hidden', true);
  }).fail(xhr => { $('#loading').text('Your profile could not be loaded. Refresh to try again.'); failure(xhr); });
  $('#profile-form').on('submit', function (event) {
    event.preventDefault();
    if (!this.reportValidity()) return;
    Nura.busy(this, true);
    Nura.request('php/profile.php', 'PUT', {name: $('#name').val().trim(), age: $('#age').val() === '' ? null : Number($('#age').val()), bio: $('#bio').val().trim(), interests: $('#interests').val().split(',').map(value => value.trim()).filter(Boolean)})
      .done(data => { summary(data.profile, $('#username').text()); Nura.message('Your profile is up to date.', true); })
      .fail(failure).always(() => Nura.busy(this, false));
  });
  $('#logout').on('click', function () {
    $(this).prop('disabled', true);
    Nura.request('php/login.php', 'DELETE').done(() => location.replace('login.html'))
      .fail(failure).always(() => $(this).prop('disabled', false));
  });
});
