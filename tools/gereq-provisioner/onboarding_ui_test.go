package main

import (
	"context"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"
)

func onboardingFixture(t *testing.T) (*onboardingUI, *centralAPI) {
	t.Helper()
	central, _, _ := customerFixture(t)
	socket := t.TempDir() + "/api.sock"
	listener, err := net.Listen("unix", socket)
	if err != nil {
		t.Fatal(err)
	}
	server := &http.Server{Handler: central}
	go func() { _ = server.Serve(listener) }()
	t.Cleanup(func() { central.backup.workers.Wait(); _ = server.Close() })
	ui, err := newOnboardingUI(t.TempDir()+"/ui", socket, "127.0.0.1:18150", "local-v1")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(ui.client.CloseIdleConnections)
	return ui, central
}
func onboardingReq(ui *onboardingUI, method, path, body, key string, authorized bool) *httptest.ResponseRecorder {
	req := httptest.NewRequest(method, "http://"+ui.host+path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if key != "" {
		req.Header.Set("Idempotency-Key", key)
	}
	if authorized {
		req.AddCookie(&http.Cookie{Name: "gereq-onboarding", Value: ui.token})
		req.Header.Set("Origin", "http://"+ui.host)
		req.Header.Set("X-CSRF-Token", ui.token)
	}
	result := httptest.NewRecorder()
	ui.ServeHTTP(result, req)
	return result
}
func TestOnboardingUIProtectsLocalSessionAndDoesNotProxyOperatorAPI(t *testing.T) {
	ui, _ := onboardingFixture(t)
	w := onboardingReq(ui, "GET", "/", "", "", false)
	if w.Code != 200 || !strings.Contains(w.Body.String(), "Jaký web bude ten váš?") || !strings.Contains(w.Header().Get("Set-Cookie"), "HttpOnly") || !strings.Contains(w.Header().Get("Content-Security-Policy"), "frame-ancestors 'none'") {
		t.Fatal(w.Code, w.Header())
	}
	if w = onboardingReq(ui, "GET", "/ui/catalog", "", "", false); w.Code != 401 {
		t.Fatal(w.Code)
	}
	r := httptest.NewRequest("POST", "http://"+ui.host+"/ui/create", strings.NewReader(`{}`))
	r.AddCookie(&http.Cookie{Name: "gereq-onboarding", Value: ui.token})
	r.Header.Set("Origin", "http://evil.example")
	r.Header.Set("X-CSRF-Token", ui.token)
	w = httptest.NewRecorder()
	ui.ServeHTTP(w, r)
	if w.Code != 403 {
		t.Fatal("cross-origin mutation accepted", w.Code)
	}
	r = httptest.NewRequest("GET", "http://evil.example/", nil)
	w = httptest.NewRecorder()
	ui.ServeHTTP(w, r)
	if w.Code != 403 {
		t.Fatal("host check bypassed", w.Code)
	}
	if w = onboardingReq(ui, "GET", "/v1/releases", "", "test-onboarding-key", true); w.Code == 200 {
		t.Fatal("operator API exposed")
	}
	if err := serveOnboardingUI(context.Background(), t.TempDir(), "/unused.sock", "0.0.0.0:18150", "local-v1"); err == nil {
		t.Fatal("public listener allowed")
	}
}
func TestOnboardingUICatalogOnlyOffersUsablePackagesAndTemplates(t *testing.T) {
	ui, central := onboardingFixture(t)
	c, err := central.loadCatalog()
	if err != nil {
		t.Fatal(err)
	}
	c.Templates["test-native"] = catalogTemplate{ID: "test-native", Name: "Native test", Driver: "native-json", Active: true, Revision: 1, Native: func() *NativeTemplate { v := exampleNativeTemplate(); return &v }()}
	p := c.Packages["funnel-v1"]
	p.TemplateIDs = append(p.TemplateIDs, "test-native")
	c.Packages[p.ID] = p
	if err = central.saveCatalog(c); err != nil {
		t.Fatal(err)
	}
	w := onboardingReq(ui, "GET", "/ui/catalog", "", "", true)
	if w.Code != 200 || strings.Contains(w.Body.String(), "centered-native") || strings.Contains(w.Body.String(), `"blocks"`) || !strings.Contains(w.Body.String(), "test-native") {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestOnboardingUICreatesAndRestoresSameWorkflowWithoutPersistingSetupLink(t *testing.T) {
	ui, central := onboardingFixture(t)
	input := onboardingInput{PackageID: "funnel-v1", TemplateID: "editorial-ai", Name: "Test studio", Offer: "Consultations", Audience: "Local businesses", Language: "en", Goal: "inquiry", Email: "owner@example.invalid"}
	raw, _ := json.Marshal(input)
	key := "onboarding-browser-test"
	w := onboardingReq(ui, "POST", "/ui/create", string(raw), key, true)
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	central.backup.workers.Wait()
	var first struct {
		ID string `json:"id"`
	}
	_ = json.Unmarshal(w.Body.Bytes(), &first)
	w = onboardingReq(ui, "GET", "/ui/current", "", key, true)
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"status":"complete"`) || strings.Contains(w.Body.String(), input.Email) {
		t.Fatal(w.Code, w.Body.String())
	}
	w = onboardingReq(ui, "POST", "/ui/create", string(raw), key, true)
	if w.Code != 200 || !strings.Contains(w.Body.String(), first.ID) {
		t.Fatal("duplicate submission", w.Code, w.Body.String())
	}
	input.Offer = "Different offer"
	changed, _ := json.Marshal(input)
	if w = onboardingReq(ui, "POST", "/ui/create", string(changed), key, true); w.Code != 409 {
		t.Fatal(w.Code)
	}
	record, err := ui.readRecord(key)
	if err != nil {
		t.Fatal(err)
	}
	if record.Request.Port < 18200 || record.Request.Port >= 18300 || record.JobID != first.ID {
		t.Fatal(record.Request.Port, record.JobID)
	}
	// The local adapter rejects a setup link pointing at a different customer.
	w = onboardingReq(ui, "POST", "/ui/setup-link", "", key, true)
	if w.Code != 502 {
		t.Fatal("wrong-customer link accepted", w.Code, w.Body.String())
	}
	original := ui.client.Transport
	ui.client.Transport = transportFunc(func(r *http.Request) (*http.Response, error) {
		if strings.HasSuffix(r.URL.Path, "/setup-link") {
			body, _ := json.Marshal(map[string]string{"setup_url": "http://" + record.Request.SiteID + ".localhost:" + strconv.Itoa(record.Request.Port) + "/admin/reset-password/test-token"})
			return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(string(body))), Header: make(http.Header)}, nil
		}
		return original.RoundTrip(r)
	})
	w = onboardingReq(ui, "POST", "/ui/setup-link", "", key, true)
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	saved, _ := os.ReadFile(ui.recordPath(key))
	if strings.Contains(string(saved), "test-token") || strings.Contains(string(saved), "secret-token") {
		t.Fatal("bearer link saved")
	}
	ui.client.Transport = original
	restored, err := newOnboardingUI(ui.root, "/unused.sock", ui.host, ui.release)
	if err != nil {
		t.Fatal(err)
	}
	restored.client = ui.client
	w = onboardingReq(restored, "GET", "/ui/current", "", key, true)
	if w.Code != 200 || !strings.Contains(w.Body.String(), first.ID) {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestOnboardingUIRecoversUnconfirmedSubmission(t *testing.T) {
	ui, central := onboardingFixture(t)
	in := customerRequest{SiteID: "recover-browser", CustomerRef: "local-browser", Email: "owner@example.invalid", PackageID: "funnel-v1", TemplateID: "editorial-ai", ReleaseID: "local-v1", Port: 18290, Brief: Brief{Schema: 1, Name: "Studio", Offer: "Consulting", Audience: "Teams", Language: "en", Slug: "uvod", Goal: "inquiry"}}
	record := onboardingRecord{Key: "recover-browser-key", Hash: "test", Request: in}
	if err := atomicJSON(ui.recordPath(record.Key), record); err != nil {
		t.Fatal(err)
	}
	w := onboardingReq(ui, "GET", "/ui/current", "", record.Key, true)
	if w.Code != 200 || !strings.Contains(w.Body.String(), "submission_pending") {
		t.Fatal(w.Code, w.Body.String())
	}
	w = onboardingReq(ui, "POST", "/ui/recover", "", record.Key, true)
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	central.backup.workers.Wait()
	w = onboardingReq(ui, "POST", "/ui/recover", "", record.Key, true)
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestOnboardingUIUnavailableAPIIsRecoverable(t *testing.T) {
	ui, err := newOnboardingUI(t.TempDir()+"/ui", "/nonexistent.sock", "127.0.0.1:18150", "local-v1")
	if err != nil {
		t.Fatal(err)
	}
	ui.client.Timeout = 100 * time.Millisecond
	w := onboardingReq(ui, "GET", "/ui/catalog", "", "", true)
	if w.Code != 503 {
		t.Fatal(w.Code)
	}
}

func TestOnboardingDomainUsesBriefNameAndRejectsDuplicates(t *testing.T) {
	ui, central := onboardingFixture(t)
	in := onboardingInput{PackageID: "funnel-v1", TemplateID: "editorial-ai", Name: "Žluťoučký kůň & spol.", Offer: "Consulting", Audience: "Teams", Language: "en", Goal: "inquiry", Email: "owner@example.invalid"}
	raw, _ := json.Marshal(in)
	w := onboardingReq(ui, "POST", "/ui/create", string(raw), "domain-first-key", true)
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	central.backup.workers.Wait()
	record, err := ui.readRecord("domain-first-key")
	if err != nil {
		t.Fatal(err)
	}
	if record.Request.SiteID != "zlutoucky-kun-spol" {
		t.Fatal(record.Request.SiteID)
	}
	state, _, err := central.backup.engine.load(record.Request.SiteID)
	if err != nil || state.Request.BrandName != in.Name {
		t.Fatal("business name not propagated", err)
	}
	w = onboardingReq(ui, "POST", "/ui/create", string(raw), "domain-second-key", true)
	if w.Code != 409 || !strings.Contains(w.Body.String(), `"field":"domain"`) {
		t.Fatal(w.Code, w.Body.String())
	}
	in.Domain = "chosen-domain"
	raw, _ = json.Marshal(in)
	w = onboardingReq(ui, "POST", "/ui/create", string(raw), "domain-chosen-key", true)
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	central.backup.workers.Wait()
	record, err = ui.readRecord("domain-chosen-key")
	if err != nil || record.Request.SiteID != in.Domain {
		t.Fatal(record.Request.SiteID, err)
	}
	in.Domain = "../bad.host"
	raw, _ = json.Marshal(in)
	if w = onboardingReq(ui, "POST", "/ui/create", string(raw), "domain-invalid-key", true); w.Code != 400 {
		t.Fatal(w.Code)
	}
}
func TestOnboardingDomainNormalization(t *testing.T) {
	for name, want := range map[string]string{"Žluťoučký kůň & spol.": "zlutoucky-kun-spol", "123 Studio": "web-123-studio", "AI": "ai-web", "": ""} {
		if got := domainFromName(name); got != want {
			t.Fatal(name, got, want)
		}
	}
	for _, value := range []string{"Studio", "aa", "trailing-", "../bad", "own.domain.cz"} {
		if validOnboardingDomain(value) {
			t.Fatal("invalid domain accepted", value)
		}
	}
}

func TestOnboardingReleasesUnacceptedDomainAfterCoreValidationFailure(t *testing.T) {
	ui, central := onboardingFixture(t)
	central.customerGenerate = nil
	t.Setenv("OPENAI_API_KEY", "")
	t.Setenv("GEREQ_GENERATOR_MODEL", "")
	in := onboardingInput{Domain: "unaccepted-domain", PackageID: "funnel-v1", TemplateID: "editorial-ai", Name: "Studio", Offer: "Consulting", Audience: "Teams", Language: "en", Goal: "inquiry", Email: "owner@example.invalid"}
	raw, _ := json.Marshal(in)
	key := "unaccepted-domain-key"
	w := onboardingReq(ui, "POST", "/ui/create", string(raw), key, true)
	if w.Code != 400 {
		t.Fatal(w.Code, w.Body.String())
	}
	if _, err := ui.readRecord(key); !os.IsNotExist(err) {
		t.Fatal("rejected request retained domain reservation", err)
	}
}
