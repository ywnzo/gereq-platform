package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestPackageEditorCatalogRoundTripAndRevision(t *testing.T) {
	a, _, _ := managementFixture(t)
	c, _ := a.loadCatalog()
	p := c.Packages["funnel-v1"]
	p.ID = "editor-package"
	p.Name = "Editor package"
	p.Revision = 0
	monthly, yearly, limit := int64(129090), int64(1290000), 7
	p.MonthlyMinor = &monthly
	p.YearlyMinor = &yearly
	p.GenerationLimit = &limit
	p.Modules = funnelModules
	p.Features = map[string]bool{"instance_updates": false, "draft_import": true, "website_generation": true}
	raw, _ := json.Marshal(p)
	w := centralReq(t, a, "PUT", "/v1/catalog/packages/"+p.ID, string(raw), "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	c, err := a.loadCatalog()
	if err != nil {
		t.Fatal(err)
	}
	saved := c.Packages[p.ID]
	if saved.Revision != 1 || *saved.MonthlyMinor != monthly || *saved.YearlyMinor != yearly || *saved.GenerationLimit != limit || saved.Features["instance_updates"] || registryHash(saved.Modules) != registryHash(funnelModules) {
		t.Fatal(saved)
	}
	w = centralReq(t, a, "PUT", "/v1/catalog/packages/"+p.ID, string(raw), "")
	if w.Code != 409 {
		t.Fatal("lost update accepted", w.Code)
	}
	saved.Active = false
	saved.Modules = []string{"cms", "shop"}
	raw, _ = json.Marshal(saved)
	w = centralReq(t, a, "PUT", "/v1/catalog/packages/"+p.ID, string(raw), "")
	if w.Code != 200 {
		t.Fatal("draft modules rejected", w.Code, w.Body.String())
	}
	json.Unmarshal(w.Body.Bytes(), &saved)
	saved.Active = true
	saved.Modules = append(saved.Modules, "site_inquiries")
	raw, _ = json.Marshal(saved)
	w = centralReq(t, a, "PUT", "/v1/catalog/packages/"+p.ID, string(raw), "")
	if w.Code != 200 {
		t.Fatal("backend module catalog package rejected", w.Code, w.Body.String())
	}
	json.Unmarshal(w.Body.Bytes(), &saved)
	saved.Active = false
	saved.Features["unimplemented_permission"] = true
	raw, _ = json.Marshal(saved)
	if w = centralReq(t, a, "PUT", "/v1/catalog/packages/"+p.ID, string(raw), ""); w.Code != 400 {
		t.Fatal("unsupported feature accepted")
	}
}
func TestPackageFeaturesEnforcedOnAcceptanceExecutionAndGeneration(t *testing.T) {
	a, in, _ := managementFixture(t)
	packageTier(t, a, "no-features", 10, []string{"editorial-native"})
	c, _ := a.loadCatalog()
	p := c.Packages["no-features"]
	p.Features = map[string]bool{"website_generation": false, "draft_import": false, "instance_updates": false}
	c.Packages[p.ID] = p
	if err := a.saveCatalog(c); err != nil {
		t.Fatal(err)
	}
	plan := packagePreview(t, a, in.SiteID, p.ID)
	if packageApply(t, a, plan, "disable-features-001") != 200 {
		t.Fatal("assignment failed")
	}
	for _, operation := range []string{"generate", "update"} {
		req := centralRequest{Operation: operation, SiteID: in.SiteID}
		if _, err := a.validateRequest(req); err == nil || !strings.Contains(err.Error(), "feature disabled") {
			t.Fatal(operation, err)
		}
		if _, err := a.executeJob(t.Context(), req, nil); err == nil || !strings.Contains(err.Error(), "feature disabled") {
			t.Fatal("worker bypass", operation, err)
		}
	}
	_, err := a.generateSelected(t.Context(), centralRequest{Operation: "generate", SiteID: in.SiteID}, c.Templates["editorial-native"], nil)
	if err == nil || !strings.Contains(err.Error(), "feature disabled") {
		t.Fatal("generator bypass", err)
	}
	used, _ := a.generationUsed(in.SiteID)
	if used != 0 {
		t.Fatal("disabled feature consumed quota")
	}
	if err = a.packageRequestAllows(centralRequest{Operation: "update-recover", SiteID: in.SiteID}); err != nil {
		t.Fatal("recovery blocked", err)
	}
	p.Features = map[string]bool{"website_generation": false, "draft_import": true}
	if validatePackageControls(p) == nil {
		t.Fatal("feature dependency ignored")
	}
}
func TestPackageEditorUIScopedAuthorization(t *testing.T) {
	u, _, _ := managementUIFixture(t)
	for _, path := range []string{"/ui/catalog/packages", "/ui/catalog/templates", "/ui/catalog/package-capabilities"} {
		if w := managementUIRequest(u, "GET", path, true); w.Code != 200 {
			t.Fatal(path, w.Code, w.Body.String())
		}
		if w := managementUIRequest(u, "GET", path, false); w.Code != 401 {
			t.Fatal("anonymous catalog", path)
		}
	}
	for _, body := range []string{`{"id":"foo","unknown":"forged"}`, `{"id":"foo"}`} {
		r := httptest.NewRequest("PUT", "http://"+u.bridge.host+"/ui/catalog/packages/foo", strings.NewReader(body))
		r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
		r.Header.Set("Content-Type", "application/json")
		w := httptest.NewRecorder()
		u.ServeHTTP(w, r)
		if w.Code != 403 {
			t.Fatal("CSRF missing", w.Code)
		}
		r.Header.Set("Origin", "http://"+u.bridge.host)
		r.Header.Set("X-CSRF-Token", u.bridge.token)
		w = httptest.NewRecorder()
		u.ServeHTTP(w, r)
		if w.Code != 400 {
			t.Fatal("invalid payload", w.Code, w.Body.String())
		}
	}
	if w := managementUIRequest(u, "GET", "/package-editor.js", false); w.Code != 200 || !strings.Contains(w.Body.String(), "mountPackageEditor") {
		t.Fatal("missing editor asset")
	}
}
