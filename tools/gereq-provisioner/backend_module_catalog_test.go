package main

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
)

func TestBackendModuleCatalogFeedsAllModulesAndDependencies(t *testing.T) {
	a, _, _ := centralFixture(t)
	a.moduleCatalog = func(context.Context) ([]backendModule, error) {
		return []backendModule{{ID: "cms", Name: "Content"}, {ID: "shop", Name: "Shop", Requires: []string{"cms"}}, {ID: "calendar", Name: "Calendar"}, {ID: "provider_sample", Name: "Provider", Kind: "integration"}}, nil
	}
	w := centralReq(t, a, "GET", "/v1/catalog/package-capabilities", "", "")
	if w.Code != 200 {
		t.Fatal(w.Code)
	}
	for _, id := range []string{"shop", "calendar", "provider_sample"} {
		if !strings.Contains(w.Body.String(), `"id":"`+id+`"`) {
			t.Fatal("backend module missing", id)
		}
	}
	if !strings.Contains(w.Body.String(), `"module_source":"backend"`) {
		t.Fatal("wrong source")
	}
	a.moduleCatalog = func(context.Context) ([]backendModule, error) { return nil, errors.New("offline") }
	if w = centralReq(t, a, "GET", "/v1/catalog/package-capabilities", "", ""); w.Code != 503 {
		t.Fatal("failed open with static modules", w.Code)
	}
}
func TestActivePackageUsesBackendCatalogWithoutWeakeningImageChecks(t *testing.T) {
	a, r, l := centralFixture(t)
	c, _ := a.loadCatalog()
	p := c.Packages["funnel-v1"]
	p.ID = "shop-catalog"
	p.Revision = 0
	p.Modules = []string{"cms", "site_inquiries", "analytics", "shop"}
	raw, _ := json.Marshal(p)
	w := centralReq(t, a, "PUT", "/v1/catalog/packages/"+p.ID, string(raw), "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	r.EnabledModules = p.Modules
	if validateSelectedModules(r, l) == nil {
		t.Fatal("image missing shop was accepted")
	}
	p.ID = "unknown-catalog"
	p.Modules = append(p.Modules, "not_in_backend")
	raw, _ = json.Marshal(p)
	if w = centralReq(t, a, "PUT", "/v1/catalog/packages/"+p.ID, string(raw), ""); w.Code != 400 {
		t.Fatal("unknown backend module accepted", w.Code)
	}
}
func TestGeneratedPageTitleIsDescriptiveAndUnicodeBounded(t *testing.T) {
	name := "Zahradní studio"
	title := generatedPageTitle(name, "Vaše zahrada může nabídnout víc")
	if title == name || !strings.Contains(title, "Vaše zahrada") {
		t.Fatal(title)
	}
	title = generatedPageTitle(strings.Repeat("č", 90), strings.Repeat("ř", 140))
	if len([]rune(title)) != 190 || !strings.HasSuffix(title, "…") {
		t.Fatal("title not bounded")
	}
	d := sampleDesign()
	artifact, e := compileGeneratedDesign(sampleBrief(), *d, "gen-0123456789abcdef01234567", "Contact us")
	if e != nil {
		t.Fatal(e)
	}
	if artifact.Title == sampleBrief().Name {
		t.Fatal("default page title used only company name")
	}
}
