package main

import (
	"context"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
)

func domainFixture(t *testing.T) domainStore {
	t.Helper()
	s := domainStore{db: releaseRegistry{path: filepath.Join(t.TempDir(), "domains.sqlite")}}
	if e := s.initialize(); e != nil {
		t.Fatal(e)
	}
	return s
}
func TestDomainHostnameBoundary(t *testing.T) {
	for _, v := range []string{"https://test.cz", "test.cz:80", "*.example.cz", "127.0.0.1", "[::1]", "user@example.cz", "a..cz", "-test.cz", "test.cz.", "test.cz/abc", "téšt.cz", "test%2ecz", "test.cz\r\nHost:bad.cz"} {
		if _, e := domainHostname(v); e == nil {
			t.Fatal("accepted", v)
		}
	}
	if h, e := domainHostname("WWW.Example.CZ"); e != nil || h != "www.example.cz" {
		t.Fatal(h, e)
	}
}
func TestDomainReservationAndSimulatedLifecycle(t *testing.T) {
	s := domainFixture(t)
	fallback, e := s.change("studio.studio.gereq.cz", "assign", domainInput{Site: "studio", Kind: "subdomain"})
	if e != nil || !fallback.Primary {
		t.Fatal(fallback, e)
	}
	if _, e = s.change(fallback.Host, "assign", domainInput{Site: "foreign", Kind: "subdomain"}); e == nil {
		t.Fatal("cross-site collision accepted")
	}
	if _, e = s.change("second.studio.gereq.cz", "assign", domainInput{Site: "studio", Kind: "subdomain"}); e == nil {
		t.Fatal("second fallback accepted")
	}
	d, e := s.change("www.example.test", "assign", domainInput{Site: "studio", Kind: "custom"})
	if e != nil || d.State != "awaiting_dns" || d.Token == "" {
		t.Fatal(d, e)
	}
	token := d.Token
	d, e = s.change(d.Host, "simulate", domainInput{Revision: d.Revision, TXT: "wrong", Target: localDomainTarget, Certificate: true})
	if e != nil || d.State == "active" || d.Certificate {
		t.Fatal("unverified host activated", d, e)
	}
	if _, e = s.change(d.Host, "simulate", domainInput{Revision: 1, TXT: token, Target: localDomainTarget, Certificate: true}); e == nil {
		t.Fatal("stale revision accepted")
	}
	d, e = s.change(d.Host, "simulate", domainInput{Revision: d.Revision, TXT: token, Target: localDomainTarget})
	if e != nil || d.State != "issuing_certificate" {
		t.Fatal(d, e)
	}
	if _, e = s.change(d.Host, "primary", domainInput{Revision: d.Revision}); e == nil {
		t.Fatal("certificate-less primary accepted")
	}
	d, e = s.change(d.Host, "simulate", domainInput{Revision: d.Revision, TXT: token, Target: localDomainTarget, Certificate: true})
	if e != nil || d.State != "active" {
		t.Fatal(d, e)
	}
	d, e = s.change(d.Host, "primary", domainInput{Revision: d.Revision})
	if e != nil || !d.Primary {
		t.Fatal(d, e)
	}
	items, _ := s.list()
	primaries := 0
	for _, x := range items {
		if x.Primary {
			primaries++
		}
	}
	if primaries != 1 {
		t.Fatal(items)
	}
	d, e = s.change(d.Host, "remove", domainInput{Revision: d.Revision})
	if e != nil || d.Primary || d.State != "removed" {
		t.Fatal(d, e)
	}
	items, _ = s.list()
	for _, x := range items {
		if x.Host == fallback.Host && !x.Primary {
			t.Fatal("fallback not restored")
		}
	}
	d, e = s.change(d.Host, "assign", domainInput{Site: "foreign", Kind: "custom"})
	if e != nil || d.Token == token || d.State != "awaiting_dns" {
		t.Fatal("ownership proof reused", d, e)
	}
	if _, e = s.change(fallback.Host, "remove", domainInput{Revision: 99}); e == nil {
		t.Fatal("fallback deleted")
	}
}
func TestDomainConcurrentClaim(t *testing.T) {
	s := domainFixture(t)
	var wg sync.WaitGroup
	success := make(chan string, 2)
	for _, site := range []string{"alpha", "bravo"} {
		wg.Add(1)
		go func(site string) {
			defer wg.Done()
			if _, e := s.change("shared.example.test", "assign", domainInput{Site: site, Kind: "custom"}); e == nil {
				success <- site
			}
		}(site)
	}
	wg.Wait()
	close(success)
	if len(success) != 1 {
		t.Fatal("expected one successful owner", len(success))
	}
}
func TestDomainAPIAndAutomaticAssignment(t *testing.T) {
	a, in, _ := managementFixture(t)
	result := centralReq(t, a, "GET", "/v1/domains", "", "")
	if result.Code != 200 || !strings.Contains(result.Body.String(), in.SiteID+"."+localDomainBase) {
		t.Fatal(result.Code, result.Body.String())
	}
	for _, body := range []string{`{"site_id":"missing-site","kind":"subdomain","label":"studio"}`, `{"site_id":"` + in.SiteID + `","kind":"subdomain","label":"admin"}`, `{"site_id":"` + in.SiteID + `","kind":"custom","hostname":"http://bad.test"}`, `{"site_id":"` + in.SiteID + `","kind":"custom","hostname":"bad.localhost"}`} {
		r := centralReq(t, a, "POST", "/v1/domains", body, "")
		if r.Code < 400 {
			t.Fatal(body, r.Code)
		}
	}
	r := centralReq(t, a, "POST", "/v1/domains", `{"site_id":"`+in.SiteID+`","kind":"custom","hostname":"shop.example.test"}`, "")
	if r.Code != 200 {
		t.Fatal(r.Code, r.Body.String())
	}
	if r = centralReq(t, a, "GET", "/v1/domain-route/shop.example.test", "", ""); r.Code != 404 {
		t.Fatal(r.Code)
	}
	if r = centralReq(t, a, "GET", "/v1/domain-route/unknown.example.test", "", ""); r.Code != 404 {
		t.Fatal(r.Code)
	}
	if r = centralReq(t, a, "GET", "/v1/domain-route/"+in.SiteID+"."+localDomainBase, "", ""); r.Code != 200 {
		t.Fatal(r.Code, r.Body.String())
	}
	managementAction(t, a, in.SiteID, "stop", "domain-stop-acceptance")
	a.backup.workers.Wait()
	if r = centralReq(t, a, "GET", "/v1/domain-route/"+in.SiteID+"."+localDomainBase, "", ""); r.Code != 503 {
		t.Fatal("stopped site routed", r.Code, r.Body.String())
	}
}
func TestDomainRouterIsolationAndCanonicalRedirect(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Forwarded-Host") != "" || r.Header.Get("Forwarded") != "" {
			t.Error("spoofed forwarding survived")
		}
		if r.Host != "studio.localhost:"+strings.Split(r.Host, ":")[1] {
			t.Error(r.Host)
		}
		if r.Header.Get("Origin") != "http://"+r.Host {
			t.Error("same-origin mapping", r.Header.Get("Origin"))
		}
		w.Write([]byte("isolated studio"))
	}))
	defer upstream.Close()
	_, port, _ := net.SplitHostPort(strings.TrimPrefix(upstream.URL, "http://"))
	number, _ := strconv.Atoi(port)
	router := &localDomainRouter{port: "18154", resolve: func(_ context.Context, host string) (domainResolution, int, error) {
		if host == "unknown.test" {
			return domainResolution{}, 404, nil
		}
		return domainResolution{Site: "studio", Port: number, Host: "studio.localhost", Primary: "studio.studio.gereq.cz"}, 200, nil
	}}
	req := httptest.NewRequest("GET", "http://unknown.test:18154/", nil)
	w := httptest.NewRecorder()
	router.ServeHTTP(w, req)
	if w.Code != 404 {
		t.Fatal(w.Code)
	}
	req = httptest.NewRequest("POST", "http://alias.test:18154/path?q=1", strings.NewReader("body"))
	w = httptest.NewRecorder()
	router.ServeHTTP(w, req)
	if w.Code != 307 || w.Header().Get("Location") != "http://studio.studio.gereq.cz:18154/path?q=1" {
		t.Fatal(w.Code, w.Header())
	}
	req = httptest.NewRequest("GET", "http://studio.studio.gereq.cz:18154/", nil)
	req.Header.Set("Origin", "http://studio.studio.gereq.cz:18154")
	req.Header.Set("Forwarded", "host=evil.test")
	req.Header.Set("X-Forwarded-Host", "evil.test")
	w = httptest.NewRecorder()
	router.ServeHTTP(w, req)
	if w.Code != 200 || w.Body.String() != "isolated studio" {
		t.Fatal(w.Code, w.Body.String())
	}
	req = httptest.NewRequest("GET", "http://studio.studio.gereq.cz:8888/", nil)
	w = httptest.NewRecorder()
	router.ServeHTTP(w, req)
	if w.Code != 400 {
		t.Fatal(w.Code)
	}
}
func TestDomainManagementAdapterProtectsMutations(t *testing.T) {
	u, _, in := managementUIFixture(t)
	call := func(method, path, body string, csrf bool) *httptest.ResponseRecorder {
		r := httptest.NewRequest(method, "http://"+u.bridge.host+path, strings.NewReader(body))
		r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
		r.Header.Set("Content-Type", "application/json")
		if csrf {
			r.Header.Set("Origin", "http://"+u.bridge.host)
			r.Header.Set("X-CSRF-Token", u.bridge.token)
		}
		w := httptest.NewRecorder()
		u.ServeHTTP(w, r)
		return w
	}
	if w := call("GET", "/ui/domains", "", false); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	raw, _ := json.Marshal(domainInput{Site: in.SiteID, Kind: "custom", Host: "secure.example.test"})
	if w := call("POST", "/ui/domains", string(raw), false); w.Code != 403 {
		t.Fatal(w.Code)
	}
	if w := call("POST", "/ui/domains", string(raw), true); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := call("POST", "/ui/domains/x.test/arbitrary", "{}", true); w.Code != 404 {
		t.Fatal(w.Code)
	}
}

func TestDomainStudioMigrationPreservesOwnershipAndRequiresNewDNS(t *testing.T) {
	s := domainFixture(t)
	db, e := s.db.open()
	if e != nil {
		t.Fatal(e)
	}
	tx, e := db.Begin()
	if e != nil {
		t.Fatal(e)
	}
	old := siteDomain{Host: "studio.sites.localhost", Site: "studio", Kind: "subdomain", State: "active", Primary: false, Revision: 1, Target: "ingress.sites.localhost", DNS: true, Certificate: true}
	custom := siteDomain{Host: "www.example.test", Site: "studio", Kind: "custom", State: "active", Primary: true, Revision: 2, Target: "ingress.sites.localhost", DNS: true, Certificate: true, Token: "retained-proof"}
	for _, d := range []siteDomain{old, custom} {
		if e = writeDomain(tx, d, "fixture"); e != nil {
			t.Fatal(e)
		}
	}
	if _, e = tx.Exec("PRAGMA user_version=1"); e != nil {
		t.Fatal(e)
	}
	if e = tx.Commit(); e != nil {
		t.Fatal(e)
	}
	db.Close()
	if e = s.initialize(); e != nil {
		t.Fatal(e)
	}
	items, _ := s.list()
	if len(items) != 2 {
		t.Fatal(items)
	}
	for _, d := range items {
		if d.Kind == "subdomain" && (d.Host != "studio.studio.gereq.cz" || !d.Primary || d.Site != "studio") {
			t.Fatal(d)
		}
		if d.Kind == "custom" && (d.State != "awaiting_dns" || d.Certificate || d.Primary || d.Token != "retained-proof") {
			t.Fatal(d)
		}
		if d.Target != localDomainTarget {
			t.Fatal(d)
		}
	}
	before, _ := json.Marshal(items)
	if e = s.initialize(); e != nil {
		t.Fatal(e)
	}
	items, _ = s.list()
	after, _ := json.Marshal(items)
	if string(before) != string(after) {
		t.Fatal("migration not idempotent")
	}
}
