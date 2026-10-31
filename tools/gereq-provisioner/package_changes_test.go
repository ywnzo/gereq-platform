package main

import (
	"encoding/json"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func packagePreview(t *testing.T, a *centralAPI, site, target string) packageChangePlan {
	t.Helper()
	w := centralReq(t, a, "POST", "/v1/package-changes/"+site+"/preview", `{"package_id":"`+target+`"}`, "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	var p packageChangePlan
	if err := json.Unmarshal(w.Body.Bytes(), &p); err != nil {
		t.Fatal(err)
	}
	return p
}
func packageApply(t *testing.T, a *centralAPI, p packageChangePlan, key string) int {
	t.Helper()
	w := centralReq(t, a, "POST", "/v1/package-changes/"+p.SiteID+"/apply", `{"plan_id":"`+p.ID+`"}`, key)
	return w.Code
}
func packageTier(t *testing.T, a *centralAPI, id string, limit int, templates []string) {
	t.Helper()
	c, err := a.loadCatalog()
	if err != nil {
		t.Fatal(err)
	}
	p := c.Packages["funnel-v1"]
	p.ID = id
	p.Name = id
	p.GenerationLimit = &limit
	p.TemplateIDs = templates
	c.Packages[id] = p
	if err = atomicJSON(filepath.Join(a.dir, "product-catalog.json"), c); err != nil {
		t.Fatal(err)
	}
}
func TestPackageChangesPreserveOwnershipDatesAndEnforceEntitlements(t *testing.T) {
	a, in, _ := managementFixture(t)
	before := mustSubscription(t, a, in.SiteID)
	state, _, _ := a.backup.engine.load(in.SiteID)
	owner := registryHash(state)
	packageTier(t, a, "funnel-basic", 1, []string{"editorial-native"})
	packageTier(t, a, "funnel-pro", 3, []string{"editorial-native", "editorial-ai"})
	plan := packagePreview(t, a, in.SiteID, "funnel-basic")
	if packageApply(t, a, plan, "package-apply-001") != 200 {
		t.Fatal("apply failed")
	}
	next := mustSubscription(t, a, in.SiteID)
	rev := next.Revision
	if next.PackageID != "funnel-basic" || !next.TrialEndsAt.Equal(before.TrialEndsAt) || next.PaidUntil != nil {
		t.Fatal("changed dates or package")
	}
	if packageApply(t, a, plan, "package-apply-001") != 200 || mustSubscription(t, a, in.SiteID).Revision != rev {
		t.Fatal("retry reapplied")
	}
	if packageApply(t, a, plan, "package-apply-002") != 409 {
		t.Fatal("stale plan accepted")
	}
	assignment, err := a.templateAssignment(in.SiteID, state)
	if err != nil || len(assignment.TemplateIDs) != 1 || assignment.TemplateIDs[0] != "editorial-native" {
		t.Fatal(assignment, err)
	}
	w := centralReq(t, a, "PUT", "/v1/instances/"+in.SiteID+"/templates", `{"template_ids":["editorial-ai"],"revision":0}`, "")
	if w.Code != 400 {
		t.Fatal("template bypass", w.Code, w.Body.String())
	}
	if err = a.reserveGeneration(in.SiteID, "first-attempt"); err != nil {
		t.Fatal(err)
	}
	if err = a.reserveGeneration(in.SiteID, "first-attempt"); err != nil {
		t.Fatal("retry consumed quota", err)
	}
	if err = a.reserveGeneration(in.SiteID, "second-attempt"); err == nil {
		t.Fatal("quota ignored")
	}
	c, _ := a.loadCatalog()
	_, err = a.generateSelected(t.Context(), centralRequest{SiteID: in.SiteID, TemplateID: "editorial-native", Brief: &in.Brief, Spec: &FunnelSpec{Layout: "editorial"}, generationUsageKey: "blocked-real-entry"}, c.Templates["editorial-native"], nil)
	if err == nil || !strings.Contains(err.Error(), "generation limit") {
		t.Fatal("generation entry point bypassed quota", err)
	}
	plan = packagePreview(t, a, in.SiteID, "funnel-pro")
	if packageApply(t, a, plan, "package-upgrade-001") != 200 {
		t.Fatal("upgrade failed")
	}
	down := packagePreview(t, a, in.SiteID, "funnel-basic")
	if packageApply(t, a, down, "package-downgrade-001") != 200 {
		t.Fatal("safe downgrade failed")
	}
	up := packagePreview(t, a, in.SiteID, "funnel-pro")
	if packageApply(t, a, up, "package-upgrade-002") != 200 {
		t.Fatal("second upgrade failed")
	}
	if err = a.reserveGeneration(in.SiteID, "second-attempt"); err != nil {
		t.Fatal(err)
	}
	w = centralReq(t, a, "POST", "/v1/package-changes/"+in.SiteID+"/preview", `{"package_id":"funnel-basic"}`, "")
	if w.Code != 409 || !strings.Contains(w.Body.String(), "below current usage") {
		t.Fatal("unsafe downgrade", w.Code, w.Body.String())
	}
	state, _, _ = a.backup.engine.load(in.SiteID)
	if registryHash(state) != owner {
		t.Fatal("ownership mutated")
	}
	if _, err = a.enrollSubscription(state, nil, nil, "", ""); err != nil {
		t.Fatal("retry identity", err)
	}
	if _, err = a.reconcileSubscription(t.Context(), in.SiteID); err != nil {
		t.Fatal("reconcile identity", err)
	}
	a2, err := newCentralAPI(a.backup, "")
	if err != nil {
		t.Fatal(err)
	}
	if mustSubscription(t, a2, in.SiteID).PackageID != "funnel-pro" {
		t.Fatal("package lost on restart")
	}
	used, err := a2.generationUsed(in.SiteID)
	if err != nil || used != 2 {
		t.Fatal(used, err)
	}
}
func TestPackagePreviewRejectsCatalogChangesExpiryAndUnavailableModules(t *testing.T) {
	a, in, _ := managementFixture(t)
	packageTier(t, a, "funnel-basic", 10, []string{"editorial-native"})
	p := packagePreview(t, a, in.SiteID, "funnel-basic")
	c, _ := a.loadCatalog()
	tier := c.Packages["funnel-basic"]
	tier.Revision++
	c.Packages[tier.ID] = tier
	_ = atomicJSON(filepath.Join(a.dir, "product-catalog.json"), c)
	if packageApply(t, a, p, "package-stale-001") != 409 {
		t.Fatal("catalog change accepted")
	}
	p = packagePreview(t, a, in.SiteID, "funnel-basic")
	a.now = func() time.Time { return p.ExpiresAt }
	if packageApply(t, a, p, "package-expired-001") != 409 {
		t.Fatal("expired plan accepted")
	}
	c, _ = a.loadCatalog()
	tier = c.Packages["funnel-basic"]
	tier.Modules = []string{"shop"}
	if validateCatalogPackage(tier, c) == nil {
		t.Fatal("unsupported modules accepted")
	}
}
