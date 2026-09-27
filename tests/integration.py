"""Real HTTP integration checks. Run only against a disposable local test instance.

Usage: python tests/integration.py
Creates two uniquely named test accounts; prints their names for local cleanup.
Uses only the Python standard library. Never prints passwords or session IDs.
"""
import http.cookiejar
import json
import os
import secrets
import urllib.error
import urllib.request

BASE = os.environ.get('TEST_BASE_URL', 'http://127.0.0.1:8080').rstrip('/')
if not BASE.startswith(('http://localhost:', 'http://127.0.0.1:')):
    raise SystemExit('These tests are restricted to a local disposable instance.')

checks = 0


def check(condition, name):
    global checks
    if not condition:
        raise AssertionError(name)
    checks += 1
    print('PASS:', name)


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ''

    def request(self, path, method='GET', data=None, headers=None, raw=None):
        payload = raw if raw is not None else (None if data is None else json.dumps(data).encode())
        req_headers = {'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf}
        req_headers.update(headers or {})
        request = urllib.request.Request(BASE + '/php/' + path, data=payload, method=method, headers=req_headers)
        try:
            response = self.http.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        try:
            result = json.loads(body)
        except json.JSONDecodeError:
            raise AssertionError(f'{path} did not return JSON (status {response.code})')
        return response.code, result, response.headers

    def init(self):
        code, data, headers = self.request('login.php')
        check(code == 200 and len(data['csrf']) == 64, 'Session bootstrap returns CSRF token')
        self.csrf = data['csrf']
        return headers

    def sid(self):
        return next((cookie.value for cookie in self.jar if cookie.name == 'nura_session'), '')


client = Client()
for path in ['index.html', 'register.html', 'login.html', 'profile.html', 'assets/css/bootstrap.min.css', 'assets/js/jquery.min.js']:
    response = client.http.open(BASE + '/' + path, timeout=20)
    check(response.code == 200, 'Public asset is served: ' + path)
    check("script-src 'self'" in response.headers.get('Content-Security-Policy', ''), 'Content security policy is present: ' + path)
for path in ['.env', 'php/bootstrap.php', 'database/mysql.sql', 'deploy/php.ini']:
    try:
        response = client.http.open(BASE + '/' + path, timeout=20)
        status = response.code
    except urllib.error.HTTPError as error:
        status = error.code
    check(status in (403, 404), 'Nonpublic file cannot be downloaded: ' + path)
headers = client.init()
check('HttpOnly' in headers['Set-Cookie'] and 'SameSite=Lax' in headers['Set-Cookie'], 'Session cookie uses HttpOnly and SameSite')
check('no-store' in headers['Cache-Control'], 'Private API responses cannot be cached')
check(client.request('profile.php')[0] == 401, 'Anonymous profile access denied')
check(client.request('profile.php', 'PUT', {'name': 'Anonymous'})[0] == 401, 'Anonymous profile mutation denied')
check(client.request('register.php', 'GET')[0] == 405, 'Wrong HTTP method rejected')
suffix = secrets.token_hex(5)
username = 'test_' + suffix
print('Creating test accounts:', username, username + '_b', flush=True)
password = secrets.token_urlsafe(24)
account = {'username': username, 'email': username + '@example.test', 'password': password}
check(client.request('register.php', 'POST', account, {'X-CSRF-Token': ''})[0] == 403, 'Registration requires CSRF token')
check(client.request('register.php', 'POST', account, {'Origin': 'https://untrusted.example'})[0] == 403, 'Foreign request origin rejected')
check(client.request('register.php', 'POST', account, {'Content-Type': 'text/plain'})[0] == 415, 'Non-JSON submissions rejected')
check(client.request('register.php', 'POST', raw=b'{')[0] == 400, 'Malformed JSON rejected')
check(client.request('register.php', 'POST', {**account, 'email': 'not-an-email'})[0] == 422, 'Invalid email rejected')
check(client.request('register.php', 'POST', {**account, 'password': 'short'})[0] == 422, 'Weak-length password rejected')
check(client.request('register.php', 'POST', {**account, 'password': 'long-password\u0000invalid'})[0] == 422, 'Null bytes rejected before password hashing')
check(client.request('register.php', 'POST', account)[0] == 201, 'Valid registration succeeds')
check(client.request('register.php', 'POST', {**account, 'username': username.upper()})[0] == 409, 'Case-insensitive duplicate account rejected')
check(client.request('profile.php')[0] == 401, 'Registration does not implicitly log in')
check(client.request('login.php', 'POST', {'identifier': username, 'password': 'wrong-password'})[0] == 401, 'Incorrect password rejected')
check(client.request('login.php', 'POST', {'identifier': "' OR 1=1 --", 'password': password})[0] == 401, 'SQL injection login cannot authenticate')
old_sid = client.sid()
code, data, _ = client.request('login.php', 'POST', {'identifier': username, 'password': password})
check(code == 200 and client.sid() != old_sid, 'Successful login rotates session ID')
stale = Client()
check(stale.request('profile.php', headers={'Cookie': 'nura_session=' + old_sid})[0] == 401, 'Pre-login session ID cannot access the profile')
old_csrf = client.csrf
client.csrf = data['csrf']
code, data, _ = client.request('profile.php')
check(code == 200 and data['account']['username'] == username, 'Authenticated profile belongs to signed-in account')
check('password_hash' not in data['account'] and data['profile']['name'] == '', 'API excludes password hash and returns empty initial profile')
profile = {'name': 'Test Builder', 'age': 22, 'bio': '<script>window.xss=1</script>', 'interests': ['PHP', 'Design', 'PHP']}
check(client.request('profile.php', 'PUT', profile, {'X-CSRF-Token': old_csrf})[0] == 403, 'Pre-login CSRF token invalidated')
check(client.request('profile.php', 'PUT', {**profile, 'age': 121})[0] == 422, 'Out-of-range age rejected')
check(client.request('profile.php', 'PUT', {**profile, 'age': 1.5})[0] == 422, 'Fractional age rejected')
check(client.request('profile.php', 'PUT', {**profile, 'interests': ['x'] * 11})[0] == 422, 'Too many interests rejected')
check(client.request('profile.php', 'PUT', {**profile, 'bio': 'x' * 2001})[0] == 422, 'Oversized bio rejected')
check(client.request('profile.php', 'PUT', raw=json.dumps({**profile, 'bio': 'x' * 17000}).encode())[0] == 413, 'Oversized request body rejected')
check(client.request('profile.php', 'PUT', profile)[0] == 200, 'Profile update succeeds')
code, data, _ = client.request('profile.php')
check(data['profile']['bio'] == profile['bio'] and data['profile']['interests'] == ['PHP', 'Design'], 'MongoDB roundtrip preserves text and deduplicates interests')
other = Client()
other.init()
second = {**account, 'username': username + '_b', 'email': username + '_b@example.test'}
check(other.request('register.php', 'POST', second)[0] == 201, 'Second independent account created')
code, data, _ = other.request('login.php', 'POST', {'identifier': second['email'], 'password': password})
check(code == 200, 'Login accepts registered email')
other.csrf = data['csrf']
code, data, _ = other.request('profile.php')
check(data['profile']['name'] == '' and data['account']['username'] == second['username'], 'Accounts cannot read each other\'s profile')
check(other.request('profile.php', 'PUT', {**profile, 'name': 'Other Builder', 'user_id': '1'})[0] == 200, 'Submitted user IDs do not override authenticated identity')
check(client.request('profile.php')[1]['profile']['name'] == 'Test Builder', 'Other account update leaves first profile unchanged')
check(client.request('login.php', 'DELETE', headers={'X-CSRF-Token': ''})[0] == 403, 'Logout requires CSRF token')
logged_in_sid = client.sid()
check(client.request('login.php', 'DELETE')[0] == 200, 'Logout succeeds')
check(client.sid() == '', 'Logout removes browser session cookie')
check(stale.request('profile.php', headers={'Cookie': 'nura_session=' + logged_in_sid})[0] == 401, 'Revoked session ID cannot be replayed after logout')
check(client.request('profile.php')[0] == 401, 'Logout revokes profile access')
other.request('login.php', 'DELETE')
client.init()
code, data, _ = client.request('login.php', 'POST', {'identifier': account['email'], 'password': password})
check(code == 200, 'Account can sign in again after logout')
client.csrf = data['csrf']
check(client.request('profile.php')[1]['profile']['name'] == 'Test Builder', 'Profile persists across sessions')
client.request('login.php', 'DELETE')
client.init()
for _ in range(35):
    code, _, limit_headers = client.request('login.php', 'POST', {'identifier': username, 'password': 'incorrect-passphrase'})
    if code == 429:
        break
check(code == 429 and int(limit_headers['Retry-After']) > 0, 'Redis login rate limit returns 429 and Retry-After')
for _ in range(12):
    code, _, limit_headers = client.request('register.php', 'POST', {**account, 'email': 'invalid'})
    if code == 429:
        break
check(code == 429 and int(limit_headers['Retry-After']) > 0, 'Redis registration rate limit returns 429 and Retry-After')
print(f'\n{checks} checks passed.')
print('Test accounts:', username, second['username'])
