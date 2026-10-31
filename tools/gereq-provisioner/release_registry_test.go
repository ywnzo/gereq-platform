package main

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type registryRunner struct {
	base               Runner
	imageID, releaseID string
	development        bool
	moduleSelection    bool
	modules            map[string]string
	inspections        int
	absent             bool
}

func (r *registryRunner) Run(ctx context.Context, args []string) ([]byte, error) {
	if len(args) > 1 && args[0] == "image" && args[1] == "inspect" {
		r.inspections++
		if r.absent {
			return nil, errors.New("private-runtime-diagnostic")
		}
		return []byte(r.imageID), nil
	}
	if len(args) > 1 && args[0] == "image" && args[1] == "ls" {
		return json.Marshal([]any{map[string]any{"Id": strings.TrimPrefix(r.imageID, "sha256:"), "Names": []string{"localhost/gereq-local:release-v2", "docker.io/foreign/image:latest"}}})
	}
	if len(args) > 0 && args[0] == "run" && strings.Contains(strings.Join(args, " "), "PROVISION_SOURCE.json") {
		return json.Marshal(map[string]any{"release_id": r.releaseID, "development": r.development, "platform_version": "0.6.0", "module_versions": r.modules, "module_selection": r.moduleSelection})
	}
	return r.base.Run(ctx, args)
}
func registryFixture(t *testing.T) (*centralAPI, *registryRunner) {
	t.Helper()
	a, _, _ := centralFixture(t)
	runner := &registryRunner{base: a.backup.engine.Runner, imageID: "sha256:" + strings.Repeat("b", 64), releaseID: "release-v2", development: true, modules: map[string]string{"cms": "2.65.1", "site_inquiries": "0.11.1", "analytics": "0.3.1"}}
	a.backup.engine.Runner = runner
	return a, runner
}
func registerTestRelease(t *testing.T, a *centralAPI, key string) *httptest.ResponseRecorder {
	t.Helper()
	return centralReq(t, a, "POST", "/v1/releases", `{"image":"localhost/gereq-local:release-v2"}`, key)
}
func TestReleaseRegistryRegistrationPinnedImmutableAndIdempotent(t *testing.T) {
	a, runner := registryFixture(t)
	w := registerTestRelease(t, a, "registry-register-001")
	if w.Code != 201 {
		t.Fatal(w.Code, w.Body.String())
	}
	var record registeredRelease
	if err := json.Unmarshal(w.Body.Bytes(), &record); err != nil {
		t.Fatal(err)
	}
	if !record.Verified || !record.Active || record.Image != runner.imageID || record.PlatformVersion != "0.6.0" || len(record.ModuleVersions) != 3 {
		t.Fatalf("not pinned: %+v", record)
	}
	runner.imageID = "sha256:" + strings.Repeat("c", 64)
	before := runner.inspections
	w = registerTestRelease(t, a, "registry-register-001")
	if w.Code != 200 || runner.inspections != before {
		t.Fatal("idempotency did not replay", w.Code)
	}
	if w = registerTestRelease(t, a, "registry-register-002"); w.Code != 409 {
		t.Fatal("immutable ID replaced", w.Code)
	}
	stored, err := a.registry.get(record.ID)
	if err != nil || stored.Image != record.Image {
		t.Fatal("stored image changed", err)
	}
	if w = centralReq(t, a, "POST", "/v1/releases", `{"image":"localhost/gereq-local:other"}`, "registry-register-001"); w.Code != 409 {
		t.Fatal("key conflict accepted")
	}
	info, _ := os.Stat(a.registry.path)
	if info.Mode().Perm() != 0600 {
		t.Fatal("registry not private")
	}
	db, err := a.registry.open()
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	var events int
	if err = db.QueryRow("SELECT count(*) FROM release_events WHERE release_id=?", record.ID).Scan(&events); err != nil || events != 1 {
		t.Fatal("audit duplicate or missing", events, err)
	}
}
func TestReleaseRegistryAvailabilityAndPersistenceWithoutFiles(t *testing.T) {
	a, runner := registryFixture(t)
	if w := registerTestRelease(t, a, "registry-register-001"); w.Code != 201 {
		t.Fatal(w.Body.String())
	}
	if w := centralReq(t, a, "PATCH", "/v1/releases/"+runner.releaseID, `{"active":false}`, ""); w.Code != 200 {
		t.Fatal(w.Body.String())
	}
	if _, err := a.release(runner.releaseID); err == nil {
		t.Fatal("inactive release selectable")
	}
	if w := centralReq(t, a, "GET", "/v1/releases", "", ""); strings.Contains(w.Body.String(), runner.releaseID) {
		t.Fatal("inactive release listed")
	}
	if w := registerTestRelease(t, a, "registry-register-002"); w.Code != 200 || !strings.Contains(w.Body.String(), `"active":false`) {
		t.Fatal("registration reactivated release", w.Body.String())
	}
	// Legacy files are imported once and never used as a second source of truth.
	if err := os.WriteFile(filepath.Join(a.catalog, "local-v1.json"), []byte("broken-json"), 0600); err != nil {
		t.Fatal(err)
	}
	next, err := newCentralAPI(a.backup, "")
	if err != nil {
		t.Fatal(err)
	}
	if _, err = next.release("local-v1"); err != nil {
		t.Fatal("legacy import lost", err)
	}
	record, err := next.registry.get(runner.releaseID)
	if err != nil || record.Active || !record.Verified {
		t.Fatal("registry not durable", err)
	}
	if w := centralReq(t, next, "PATCH", "/v1/releases/"+runner.releaseID, `{"active":true}`, ""); w.Code != 200 {
		t.Fatal(w.Body.String())
	}
	release, err := next.release(runner.releaseID)
	if err != nil || release.Image != runner.imageID {
		t.Fatal("reactivation lost pin", err)
	}
}
func TestReleaseRegistryRejectsBadImagesAndUntrustedMetadata(t *testing.T) {
	for _, scenario := range []string{"absent", "production", "missing-modules", "bad-id", "changed-selection"} {
		t.Run(scenario, func(t *testing.T) {
			a, runner := registryFixture(t)
			body := `{"image":"localhost/gereq-local:release-v2"}`
			expected := 400
			switch scenario {
			case "absent":
				runner.absent = true
			case "production":
				runner.development = false
			case "missing-modules":
				delete(runner.modules, "analytics")
			case "bad-id":
				runner.releaseID = "../../escape"
			case "changed-selection":
				body = `{"image":"localhost/gereq-local:release-v2","expected_image_id":"sha256:` + strings.Repeat("c", 64) + `"}`
				expected = 409
			}
			w := centralReq(t, a, "POST", "/v1/releases", body, "registry-invalid-001")
			if w.Code != expected || strings.Contains(w.Body.String(), "private-runtime-diagnostic") {
				t.Fatal(w.Code, w.Body.String())
			}
		})
	}
	a, _ := registryFixture(t)
	if w := centralReq(t, a, "POST", "/v1/releases", `{"image":"localhost/gereq-local:release-v2","release_id":"spoofed-id"}`, "registry-spoof-001"); w.Code != 400 {
		t.Fatal("spoofed metadata accepted", w.Code)
	}
	if w := centralReq(t, a, "DELETE", "/v1/releases/local-v1", "", ""); w.Code != 405 {
		t.Fatal("destructive deletion allowed")
	}
	if w := centralReq(t, a, "GET", "/v1/local-images", "", ""); w.Code != 200 || strings.Contains(w.Body.String(), "foreign") {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestReleaseRegistryEmptyStartupAndLegacyVerification(t *testing.T) {
	a, runner := registryFixture(t)
	runner.releaseID = "local-v1"
	if w := centralReq(t, a, "PATCH", "/v1/releases/local-v1", `{"active":false}`, ""); w.Code != 200 {
		t.Fatal(w.Code)
	}
	if w := registerTestRelease(t, a, "registry-legacy-001"); w.Code != 201 || !strings.Contains(w.Body.String(), `"active":false`) {
		t.Fatal(w.Code, w.Body.String())
	}
	e, _, _, _ := fixture(t)
	b, err := newBackupAPI(e)
	if err != nil {
		t.Fatal(err)
	}
	empty, err := newCentralAPI(b, "")
	if err != nil {
		t.Fatal(err)
	}
	records, err := empty.registry.list(false)
	if err != nil || len(records) != 0 {
		t.Fatal("empty startup requires files", err)
	}
}
func TestManagementRegistrySessionAndStrictInput(t *testing.T) {
	u, a, _ := managementUIFixture(t)
	runner := &registryRunner{base: a.backup.engine.Runner, imageID: "sha256:" + strings.Repeat("b", 64), releaseID: "release-v2", development: true, modules: map[string]string{"cms": "2.65.1", "site_inquiries": "0.11.1", "analytics": "0.3.1"}}
	a.backup.engine.Runner = runner
	if w := managementUIRequest(u, "GET", "/ui/local-images", false); w.Code != 401 {
		t.Fatal("unprotected images")
	}
	for _, tc := range []struct {
		body   string
		status int
	}{{`{"image":"localhost/gereq-local:release-v2","image_id":"forged"}`, 400}, {`{"image":"localhost/gereq-local:release-v2"}`, 201}} {
		r := httptest.NewRequest("POST", "http://"+u.bridge.host+"/ui/releases", strings.NewReader(tc.body))
		r.Header.Set("Content-Type", "application/json")
		r.Header.Set("Origin", "http://"+u.bridge.host)
		r.Header.Set("X-CSRF-Token", u.bridge.token)
		r.Header.Set("Idempotency-Key", "registry-ui-001")
		r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
		w := httptest.NewRecorder()
		u.ServeHTTP(w, r)
		if w.Code != tc.status {
			t.Fatal(w.Code, w.Body.String())
		}
	}
}

func TestReleaseRegistryRefusesUnsafeDatabaseAndFutureSchema(t *testing.T) {
	a, _ := registryFixture(t)
	if err := os.Chmod(a.registry.path, 0666); err != nil {
		t.Fatal(err)
	}
	if _, err := a.registry.list(false); err == nil {
		t.Fatal("public database accepted")
	}
	if err := os.Chmod(a.registry.path, 0600); err != nil {
		t.Fatal(err)
	}
	db, err := a.registry.open()
	if err != nil {
		t.Fatal(err)
	}
	if _, err = db.Exec("PRAGMA user_version=2"); err != nil {
		t.Fatal(err)
	}
	db.Close()
	if err = a.registry.initialize(""); err == nil {
		t.Fatal("future schema silently downgraded")
	}
	target := filepath.Join(t.TempDir(), "target")
	if err = os.WriteFile(target, []byte("untouched"), 0600); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(t.TempDir(), "registry.sqlite")
	if err = os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
	if _, err = (releaseRegistry{path: link}).open(); err == nil {
		t.Fatal("symlinked database accepted")
	}
}
func TestRegisteredReleaseFeedsProvisioningWithExactImage(t *testing.T) {
	a, runner := registryFixture(t)
	if w := registerTestRelease(t, a, "registry-provision-001"); w.Code != 201 {
		t.Fatal(w.Code, w.Body.String())
	}
	request := Request{Schema: 1, RequestID: "registry-provision-001", SiteID: "registry-site", CustomerRef: "registry-customer", Package: "funnel-v1", Release: runner.releaseID, Port: 18412}
	release, err := a.validateRequest(centralRequest{Operation: "create", SiteID: request.SiteID, Request: &request, ReleaseID: runner.releaseID})
	if err != nil || release.Image != runner.imageID {
		t.Fatal("provisioning did not use registered image", err)
	}
}

func TestReleaseRegistryPreservesModuleSelectionCapability(t *testing.T) {
	a, runner := registryFixture(t)
	runner.moduleSelection = true
	w := registerTestRelease(t, a, "registry-modules-001")
	if w.Code != 201 {
		t.Fatal(w.Code, w.Body.String())
	}
	record, err := a.registry.get(runner.releaseID)
	if err != nil || !record.ModuleSelection {
		t.Fatal("image capability lost", err)
	}
}
