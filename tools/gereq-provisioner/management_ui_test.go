package main

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func managementUIFixture(t *testing.T) (*managementUI, *centralAPI, customerRequest) {
	t.Helper()
	a, in, _ := managementFixture(t)
	root, err := os.MkdirTemp("/tmp", "gereq-mgmt-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(root) })
	socket := filepath.Join(root, "api.sock")
	listener, err := net.Listen("unix", socket)
	if err != nil {
		t.Fatal(err)
	}
	server := &http.Server{Handler: a}
	go server.Serve(listener)
	t.Cleanup(func() { server.Close(); a.backup.workers.Wait() })
	bridge, err := newOnboardingUI(filepath.Join(root, "ui"), socket, "127.0.0.1:18152", "local-management")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(bridge.client.CloseIdleConnections)
	return &managementUI{bridge}, a, in
}
func managementUIRequest(u *managementUI, method, path string, authorized bool) *httptest.ResponseRecorder {
	r := httptest.NewRequest(method, "http://"+u.bridge.host+path, nil)
	if authorized {
		r.Header.Set("Content-Type", "application/json")
		r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
		r.Header.Set("Origin", "http://"+u.bridge.host)
		r.Header.Set("X-CSRF-Token", u.bridge.token)
		r.Header.Set("Idempotency-Key", "management-ui-action-001")
	}
	w := httptest.NewRecorder()
	u.ServeHTTP(w, r)
	return w
}
func TestManagementUILocalSecurityAndScopedProxy(t *testing.T) {
	u, _, _ := managementUIFixture(t)
	w := managementUIRequest(u, "GET", "/", false)
	if w.Code != 200 || !strings.Contains(w.Header().Get("Set-Cookie"), "gereq-management=") || strings.Contains(w.Header().Get("Set-Cookie"), "gereq-onboarding=") {
		t.Fatal(w.Code, w.Header())
	}
	if w = managementUIRequest(u, "GET", "/ui/overview", false); w.Code != 401 {
		t.Fatal(w.Code)
	}
	r := httptest.NewRequest("POST", "http://"+u.bridge.host+"/ui/instances/customer-one/stop", nil)
	r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
	r.Header.Set("Origin", "https://foreign.invalid")
	r.Header.Set("X-CSRF-Token", u.bridge.token)
	w = httptest.NewRecorder()
	u.ServeHTTP(w, r)
	if w.Code != 403 {
		t.Fatal(w.Code)
	}
	r = httptest.NewRequest("GET", "http://foreign.invalid/ui/overview", nil)
	w = httptest.NewRecorder()
	u.ServeHTTP(w, r)
	if w.Code != 403 {
		t.Fatal(w.Code)
	}
	for _, path := range []string{"/ui/backup/jobs", "/ui/instances/customer-one/delete"} {
		if w = managementUIRequest(u, "POST", path, true); w.Code != 404 {
			t.Fatal(path, w.Code)
		}
	}
	if err := serveManagementUI(context.Background(), t.TempDir(), "/missing.sock", "0.0.0.0:18152"); err == nil {
		t.Fatal("public bind allowed")
	}
}
func TestManagementUIControlsInstanceWithoutExposingSecretsInOverview(t *testing.T) {
	u, a, in := managementUIFixture(t)
	w := managementUIRequest(u, "GET", "/ui/overview", true)
	if w.Code != 200 || !strings.Contains(w.Body.String(), in.Email) {
		t.Fatal(w.Code, w.Body.String())
	}
	w = managementUIRequest(u, "POST", "/ui/instances/"+in.SiteID+"/inspect", true)
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	a.backup.workers.Wait()
	w = managementUIRequest(u, "POST", "/ui/instances/"+in.SiteID+"/setup-link", true)
	if w.Code != 200 || !strings.Contains(w.Body.String(), "secret-token") {
		t.Fatal(w.Code, w.Body.String())
	}
	w = managementUIRequest(u, "GET", "/ui/overview", true)
	if w.Code != 200 || strings.Contains(w.Body.String(), "secret-token") {
		t.Fatal("credential exposed in overview", w.Code)
	}
}

func TestManagementUIUpdateRejectsArbitraryRuntimeInput(t *testing.T) {
	u, _, _ := managementUIFixture(t)
	for _, body := range []string{`{"release_id":"local-v2","image":"arbitrary-image"}`, `{"release_id":"local-v2","accept_data_loss":"yes"}`} {
		r := httptest.NewRequest("POST", "http://"+u.bridge.host+"/ui/instances/customer-one/update-plan", strings.NewReader(body))
		r.Header.Set("Content-Type", "application/json")
		r.Header.Set("Content-Type", "application/json")
		r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
		r.Header.Set("Origin", "http://"+u.bridge.host)
		r.Header.Set("X-CSRF-Token", u.bridge.token)
		r.Header.Set("Idempotency-Key", "management-invalid-plan-001")
		w := httptest.NewRecorder()
		u.ServeHTTP(w, r)
		if w.Code != 400 {
			t.Fatal(w.Code, w.Body.String())
		}
	}
}

func TestManagementUIStylesheetIsCSS(t *testing.T) {
	u, _, _ := managementUIFixture(t)
	w := managementUIRequest(u, "GET", "/app.css", false)
	if w.Code != 200 || !strings.HasPrefix(w.Header().Get("Content-Type"), "text/css") || !strings.Contains(w.Body.String(), ":root{") || strings.Contains(w.Body.String(), "document.getElementById") {
		t.Fatal("management stylesheet is missing or contains script")
	}
}

func TestManagementUIPackageScopedProxy(t *testing.T) {
	u, a, in := managementUIFixture(t)
	packageTier(t, a, "funnel-basic", 10, []string{"editorial-native"})
	if w := managementUIRequest(u, "GET", "/ui/packages/"+in.SiteID+"/options", true); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := managementUIRequest(u, "GET", "/ui/packages/"+in.SiteID+"/options", false); w.Code != 401 {
		t.Fatal("anonymous package access")
	}
	for _, body := range []string{`{"package_id":"funnel-basic"}`, `{"package_id":"funnel-basic","image":"forged"}`} {
		r := httptest.NewRequest("POST", "http://"+u.bridge.host+"/ui/packages/"+in.SiteID+"/preview", strings.NewReader(body))
		r.Header.Set("Content-Type", "application/json")
		r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
		r.Header.Set("Origin", "http://"+u.bridge.host)
		r.Header.Set("X-CSRF-Token", u.bridge.token)
		w := httptest.NewRecorder()
		u.ServeHTTP(w, r)
		expected := 200
		if strings.Contains(body, "forged") {
			expected = 400
		}
		if w.Code != expected {
			t.Fatal(w.Code, w.Body.String())
		}
	}
}
