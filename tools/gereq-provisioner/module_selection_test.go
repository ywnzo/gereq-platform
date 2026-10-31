package main

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestSelectedModulesArePinnedAndSkipOptionalSetup(t *testing.T) {
	e, r, l, f := fixture(t)
	l.Modules = []string{"cms", "site_inquiries", "analytics"}
	l.ModuleSelection = true
	r.EnabledModules = []string{"cms", "site_inquiries"}
	state, err := e.Create(context.Background(), r, l)
	if err != nil {
		t.Fatal(err)
	}
	if !sameModules(instanceModules(state), r.EnabledModules) {
		t.Fatal("selection lost")
	}
	for _, cmd := range f.calls {
		if strings.Contains(strings.Join(cmd, " "), "analytics-config") {
			t.Fatal("unselected analytics setup ran")
		}
	}
	data, err := os.ReadFile(filepath.Join(e.Root, "sites", r.SiteID, ".env"))
	if err != nil || !strings.Contains(string(data), "GEREQ_PACKAGE_MODULES=cms,site_inquiries\n") {
		t.Fatal("selection not passed to runtime", err)
	}
	r.EnabledModules = []string{"cms"}
	if _, err = e.Create(context.Background(), r, l); err == nil {
		t.Fatal("immutable instance selection changed")
	}
}
func TestSelectedModulesRejectOldImagesMissingModulesAndDependencies(t *testing.T) {
	_, r, l, _ := fixture(t)
	l.Modules = []string{"cms", "site_inquiries", "analytics"}
	r.EnabledModules = []string{"cms"}
	if validate(r, l) == nil {
		t.Fatal("legacy image accepted subset")
	}
	l.ModuleSelection = true
	if err := validate(r, l); err != nil {
		t.Fatal(err)
	}
	l.ModuleRequires = map[string][]string{"analytics": {"site_inquiries"}}
	r.EnabledModules = []string{"cms", "analytics"}
	if validate(r, l) == nil {
		t.Fatal("missing dependency accepted")
	}
	r.EnabledModules = []string{"cms", "shop"}
	if validate(r, l) == nil {
		t.Fatal("unpackaged module accepted")
	}
	r.EnabledModules = []string{"cms", "cms"}
	if validate(r, l) == nil {
		t.Fatal("duplicate accepted")
	}
}
func TestCatalogResolvesModuleSelectionAndPreventsModuleSwitch(t *testing.T) {
	a, in, _ := managementFixture(t)
	c, _ := a.loadCatalog()
	p := c.Packages["funnel-v1"]
	p.ID = "without-analytics"
	p.Name = "Without analytics"
	p.Modules = []string{"cms", "site_inquiries"}
	c.Packages[p.ID] = p
	if err := a.saveCatalog(c); err != nil {
		t.Fatal(err)
	}
	req := Request{Package: "funnel-v1", CatalogPackageID: p.ID}
	if err := a.resolvePackage(&req); err != nil || !sameModules(req.EnabledModules, p.Modules) {
		t.Fatal(err, req.EnabledModules)
	}
	w := centralReq(t, a, "POST", "/v1/package-changes/"+in.SiteID+"/preview", `{"package_id":"without-analytics"}`, "")
	state, _, _ := a.backup.engine.load(in.SiteID)
	if !sameModules(instanceModules(state), p.Modules) && w.Code != 409 {
		t.Fatal("existing runtime module switch accepted", w.Code)
	}
	p.Modules = []string{"cms"}
	p.Features = map[string]bool{"website_generation": false, "draft_import": false}
	if err := validateCatalogPackage(p, c); err != nil {
		t.Fatal("CMS-only package rejected", err)
	}
	p.Features = nil
	if validateCatalogPackage(p, c) == nil {
		t.Fatal("inquiry generator without inquiries accepted")
	}
}

func TestOnboardingOffersOnlyCompatibleGeneratablePackages(t *testing.T) {
	u, a := onboardingFixture(t)
	c, _ := a.loadCatalog()
	base := c.Packages["funnel-v1"]
	for _, id := range []string{"cms-only", "without-analytics"} {
		p := base
		p.ID = id
		p.Name = id
		p.Modules = []string{"cms"}
		if id == "cms-only" {
			p.Features = map[string]bool{"website_generation": false, "draft_import": false}
		} else {
			p.Modules = []string{"cms", "site_inquiries", "analytics"}
		}
		c.Packages[id] = p
	}
	if err := a.saveCatalog(c); err != nil {
		t.Fatal(err)
	}
	out, err := u.catalog(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	for _, p := range out["packages"].([]map[string]any) {
		if p["id"] == "cms-only" || p["id"] == "without-analytics" {
			t.Fatal("incompatible package offered", p)
		}
	}
}
