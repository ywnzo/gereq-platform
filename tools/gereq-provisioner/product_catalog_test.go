package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
)

func catalogCall(t *testing.T, a *centralAPI, method, path string, v any, revision int) int {
	t.Helper()
	body := ""
	if v != nil {
		b, err := json.Marshal(v)
		if err != nil {
			t.Fatal(err)
		}
		body = string(b)
	}
	if method != "DELETE" {
		return centralReq(t, a, method, path, body, "").Code
	}
	r := httptest.NewRequest(method, path, nil)
	if revision > 0 {
		r.Header.Set("If-Match", fmt.Sprint(revision))
	}
	w := httptest.NewRecorder()
	a.ServeHTTP(w, r)
	return w.Code
}
func TestCatalogCRUDAndExistingEntitlements(t *testing.T) {
	a, req, _ := centralFixture(t)
	tmpl := catalogTemplate{ID: "contrast", Name: "Contrast", Driver: "native-centered", Active: true}
	if code := catalogCall(t, a, "PUT", "/v1/catalog/templates/contrast", tmpl, 0); code != 200 {
		t.Fatal(code)
	}
	pkg := catalogPackage{ID: "funnel-plus", Name: "Funnel Plus", RuntimePackage: "funnel-v1", TemplateIDs: []string{"contrast"}, Currency: "CZK", TrialDays: 14, Active: true}
	if code := catalogCall(t, a, "PUT", "/v1/catalog/packages/funnel-plus", pkg, 0); code != 200 {
		t.Fatal(code)
	}
	req.CatalogPackageID = "funnel-plus"
	if err := a.resolvePackage(&req); err != nil {
		t.Fatal(err)
	}
	if req.CatalogPackageRevision != 1 || len(req.AllowedTemplates) != 1 || req.AllowedTemplates[0] != "contrast" {
		t.Fatal(req)
	}
	// A referenced active template cannot be retired until the package changes.
	if code := catalogCall(t, a, "DELETE", "/v1/catalog/templates/contrast", nil, 1); code != 409 {
		t.Fatal(code)
	}
	pkg.Revision = 1
	pkg.TemplateIDs = []string{"editorial-ai"}
	if code := catalogCall(t, a, "PUT", "/v1/catalog/packages/funnel-plus", pkg, 0); code != 200 {
		t.Fatal(code)
	}
	if len(req.AllowedTemplates) != 1 || req.AllowedTemplates[0] != "contrast" {
		t.Fatal("existing instance entitlement changed")
	}
	if code := catalogCall(t, a, "PUT", "/v1/catalog/packages/funnel-plus", pkg, 0); code != 409 {
		t.Fatal("stale revision accepted", code)
	}
	if code := catalogCall(t, a, "DELETE", "/v1/catalog/packages/funnel-plus", nil, 2); code != 200 {
		t.Fatal(code)
	}
	newReq := Request{Package: "funnel-v1", CatalogPackageID: "funnel-plus"}
	if err := a.resolvePackage(&newReq); err == nil {
		t.Fatal("retired package accepted")
	}
	if code := catalogCall(t, a, "DELETE", "/v1/catalog/templates/contrast", nil, 1); code != 200 {
		t.Fatal(code)
	}
}
func TestCatalogTemplateSelectionUsesAssignedDriver(t *testing.T) {
	a, req, release := centralFixture(t)
	if err := a.resolvePackage(&req); err != nil {
		t.Fatal(err)
	}
	if _, err := a.backup.engine.Create(context.Background(), req, release); err != nil {
		t.Fatal(err)
	}
	input := centralRequest{SiteID: req.SiteID, TemplateID: "centered-native", Spec: &FunnelSpec{Layout: "editorial"}}
	if err := a.resolveTemplate(&input); err != nil {
		t.Fatal(err)
	}
	if input.Spec.Layout != "centered" {
		t.Fatal("selected template driver not applied")
	}
	input.TemplateID = "missing"
	if err := a.resolveTemplate(&input); err == nil {
		t.Fatal("unavailable template accepted")
	}
	forged := Request{Package: "funnel-v1", AllowedTemplates: []string{"editorial-ai"}}
	if err := a.resolvePackage(&forged); err == nil {
		t.Fatal("caller forged entitlements")
	}
}
