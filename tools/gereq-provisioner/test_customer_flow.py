#!/usr/bin/env python3
"""Create one disposable local customer through the real private API (one live AI call)."""
import argparse
import http.client
import json
import pathlib
import socket
import sqlite3
import time
import urllib.parse


class UnixConnection(http.client.HTTPConnection):
    def __init__(self, path):
        super().__init__('localhost', timeout=120)
        self.path = path

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(self.path)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--socket', required=True)
    parser.add_argument('--root', required=True, help='Isolated test service state root')
    parser.add_argument('--release', required=True)
    parser.add_argument('--site', required=True, help='New disposable site; existing sites are rejected')
    parser.add_argument('--port', required=True, type=int)
    parser.add_argument('--template', default='editorial-ai')
    parser.add_argument('--report', required=True)
    parser.add_argument('--resume-job', help='Retry/resume this exact existing disposable workflow')
    args = parser.parse_args()
    state = pathlib.Path(args.root).resolve()
    if (state / 'sites' / args.site).exists() and not args.resume_job:
        raise SystemExit('Refusing to reuse an existing test site')
    env_path = state / 'sites' / args.site / '.env'
    env_before = env_path.read_bytes() if args.resume_job and env_path.exists() else None
    report_path = pathlib.Path(args.report)
    if report_path.exists():
        raise SystemExit('Refusing to overwrite an existing test report')

    def api(method, path, body=None, key=None):
        connection = UnixConnection(args.socket)
        headers = {'Content-Type': 'application/json'}
        if key:
            headers['Idempotency-Key'] = key
        connection.request(method, path, json.dumps(body) if body is not None else None, headers)
        response = connection.getresponse()
        result = json.loads(response.read())
        connection.close()
        return response.status, result

    payload = {
        'site_id': args.site, 'customer_ref': 'local-flow-' + args.site,
        'email': 'owner@example.invalid', 'package_id': 'funnel-v1',
        'template_id': args.template, 'release_id': args.release, 'port': args.port,
        'brief': {'schema_version': 1, 'name': 'Ateliér Lípa',
                  'offer': 'Konzultace k plánování menších zahrad.',
                  'audience': 'Majitelé menších zahrad, kteří hledají praktický další krok.',
                  'language': 'cs', 'slug': 'garden-consultation', 'goal': 'inquiry',
                  'booking_url': '', 'facts': ['Konzultace mohou proběhnout online.']}}
    key = 'local-customer-' + args.site
    status, job = api('POST', '/v1/customers', payload, key)
    assert status == (200 if args.resume_job else 202), (status, job)
    job_id = job['id']
    if args.resume_job:
        assert job_id == args.resume_job, 'Request does not match the workflow to resume'
        if job['status'] in {'failed', 'interrupted'}:
            status, job = api('POST', '/v1/customers/' + job_id + '/retry')
            assert status == 202, (status, job)
    status, duplicate = api('POST', '/v1/customers', payload, key)
    assert status == 200 and duplicate['id'] == job_id
    status, conflict = api('POST', '/v1/customers', {**payload, 'email': 'other@example.invalid'}, key)
    assert status == 409, (status, conflict)
    phases = []
    deadline = time.monotonic() + 900
    while time.monotonic() < deadline:
        status, job = api('GET', '/v1/customers/' + job_id)
        assert status == 200
        if job['phase'] not in phases:
            phases.append(job['phase'])
            print('Customer workflow:', job['status'], job['phase'], flush=True)
        assert payload['email'] not in json.dumps(job), 'Owner email leaked in status'
        if job['status'] == 'complete':
            break
        if job['status'] in {'failed', 'interrupted'}:
            raise AssertionError('Workflow failed; inspect private diagnostic: ' + job_id)
        time.sleep(2)
    else:
        raise AssertionError('Workflow timed out; retained for inspection: ' + job_id)
    if env_before is not None:
        assert env_path.read_bytes() == env_before, 'Retry changed instance secrets'
    result = job['result']
    assert result['published'] is False
    assert b'OPENAI_API_KEY' not in env_path.read_bytes(), 'Central provider key configuration reached the customer'
    status, artifact = api('GET', '/v1/generations/' + args.site + '/' + result['generation_id'])
    assert status == 200 and '{{' not in json.dumps(artifact)
    assert artifact['palette'] and artifact['blocks']
    storage = state / 'sites' / args.site / 'storage'
    with sqlite3.connect((storage / 'database.sqlite').as_uri() + '?mode=ro', uri=True) as db:
        assert db.execute('select count(*) from cms_pages').fetchone()[0] == 1
        assert db.execute('select status from cms_pages').fetchone()[0] == 'draft'
        assert db.execute("select count(*) from content_staging where resource_key='brand.palette'").fetchone()[0] == 1
        layout = db.execute("select value from settings where keyName='admin.dashboard.layout'").fetchone()[0]
        assert 'site_inquiries.count' in layout and 'site_inquiries.recent' in layout
    status, access = api('POST', result['setup_link_endpoint'])
    assert status == 200 and access['expires_at'] and access['email'] == payload['email']
    url = urllib.parse.urlsplit(access['setup_url'])
    assert url.hostname == args.site + '.localhost' and url.port == args.port
    customer_http = http.client.HTTPConnection('127.0.0.1', args.port, timeout=30)
    customer_http.request('GET', url.path, headers={'Host': url.netloc})
    response = customer_http.getresponse()
    body = response.read().decode()
    assert response.status == 200 and 'password' in body.lower(), 'Setup link did not render password setup'
    customer_http.close()
    token = url.path.rsplit('/', 1)[-1]
    for path in [state / 'central-api' / 'customers' / (job_id + '.json'), storage / 'database.sqlite']:
        assert token.encode() not in path.read_bytes(), 'Setup token persisted in plaintext'
    status, final = api('GET', '/v1/customers/' + job_id)
    assert status == 200 and 'setup_url' not in json.dumps(final)
    status, duplicate = api('POST', '/v1/customers', payload, key)
    assert status == 200 and duplicate['id'] == job_id and duplicate['attempts'] == final['attempts']
    report = {'checks': ['real provisioning', 'live AI generation', 'native draft import',
                         'staged brand palette', 'inquiry dashboard preset', 'idempotent replay',
                         'conflicting request rejected', 'setup link renders', 'setup token not persisted'],
              'job_id': job_id, 'site_id': args.site, 'phases_observed': phases, 'result': result}
    report_path.write_text(json.dumps(report, indent=2) + '\n')
    report_path.chmod(0o600)
    print('PASS: local customer creation flow; report:', report_path)
    print('Draft preview:', result['preview_url'])


if __name__ == '__main__':
    main()
