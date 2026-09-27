'use strict';
window.Nura = {
  csrf: '',
  request(url, method = 'GET', data) {
    return $.ajax({url, method, contentType: 'application/json', dataType: 'json',
      headers: method === 'GET' ? {} : {'X-CSRF-Token': this.csrf},
      data: data === undefined ? undefined : JSON.stringify(data), timeout: 15000});
  },
  session() {
    return this.request('php/login.php').then(data => { this.csrf = data.csrf; return data; });
  },
  message(text, success = false) {
    $('#status').removeClass('alert-success alert-danger').addClass('alert ' + (success ? 'alert-success' : 'alert-danger')).text(text).trigger('focus');
  },
  error(xhr) { this.message(xhr.responseJSON?.error || 'Unable to reach the service. Please try again.'); },
  busy(form, busy) { $(form).find('button[type="submit"]').prop('disabled', busy).attr('aria-busy', String(busy)); }
};
$(function () {
  $('.password-toggle').on('click', function () {
    const field = document.getElementById(this.dataset.target);
    const show = field.type === 'password';
    field.type = show ? 'text' : 'password';
    $(this).text(show ? 'Hide' : 'Show').attr('aria-pressed', String(show));
  });
});
