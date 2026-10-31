package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

type packageTransitionRunner struct {
	base              *managementRuntime
	failApply, failed bool
	failRecovery      bool
	failStart         bool
}

func (r *packageTransitionRunner) Run(ctx context.Context, args []string) ([]byte, error) {
	if len(args) > 3 && strings.Contains(strings.Join(args, " "), "Package module transition failed.") && args[len(args)-3] == "apply" && r.failApply && !r.failed {
		r.failed = true
		return nil, errors.New("injected activation failure")
	}
	if r.failed && r.failRecovery && len(args) > 0 && args[len(args)-1] == "down" {
		return nil, errors.New("injected recovery failure")
	}
	if r.failStart && strings.Contains(strings.Join(args, " "), " up --detach") {
		return nil, errors.New("injected startup failure")
	}
	return r.base.Run(ctx, args)
}
func moduleChangeFixture(t *testing.T) (*centralAPI, string, *packageTransitionRunner) {
	t.Helper()
	if _, err := exec.LookPath("restic"); err != nil {
		t.Skip("restic required")
	}
	key := filepath.Join(t.TempDir(), "master")
	if err := os.WriteFile(key, bytes.Repeat([]byte{7}, 32), 0600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("GEREQ_BACKUP_MASTER_KEY_FILE", key)
	a, r, l := centralFixture(t)
	l.ModuleSelection = true
	l.Modules = []string{"cms", "site_inquiries", "analytics"}
	c, _ := a.loadCatalog()
	basic := c.Packages["funnel-v1"]
	basic.ID = "module-basic"
	basic.Name = "Module Basic"
	basic.Modules = []string{"cms", "site_inquiries"}
	c.Packages[basic.ID] = basic
	pro := basic
	pro.ID = "module-pro"
	pro.Name = "Module Pro"
	pro.Modules = l.Modules
	c.Packages[pro.ID] = pro
	if err := a.saveCatalog(c); err != nil {
		t.Fatal(err)
	}
	r.CatalogPackageID = basic.ID
	if err := a.resolvePackage(&r); err != nil {
		t.Fatal(err)
	}
	state, err := a.backup.engine.Create(t.Context(), r, l)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = a.enrollSubscription(state, nil, nil, "", ""); err != nil {
		t.Fatal(err)
	}
	runner := &packageTransitionRunner{base: &managementRuntime{base: a.backup.engine.Runner, running: true, setupRequired: true}}
	a.backup.engine.Runner = runner
	if err = os.WriteFile(filepath.Join(a.backup.engine.Root, "sites", r.SiteID, "storage", "customer-marker"), []byte("private-module-data-marker"), 0600); err != nil {
		t.Fatal(err)
	}
	return a, r.SiteID, runner
}
func TestPackageModuleChangeQueuedUpgradeDowngradeIdempotence(t *testing.T) {
	a, site, _ := moduleChangeFixture(t)
	before, _, _ := a.backup.engine.load(site)
	sub := mustSubscription(t, a, site)
	plan := packagePreview(t, a, site, "module-pro")
	if !plan.ModuleChange {
		t.Fatal("missing downtime preview")
	}
	w := centralReq(t, a, "POST", "/v1/package-changes/"+site+"/apply", `{"plan_id":"`+plan.ID+`"}`, "module-upgrade-001")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	a.backup.workers.Wait()
	var envelope struct {
		Job managedAction `json:"job"`
	}
	json.Unmarshal(w.Body.Bytes(), &envelope)
	var job centralJob
	err := decode(filepath.Join(a.dir, "jobs", envelope.Job.ID+".json"), &job)
	if err != nil || job.Status != "complete" {
		t.Fatal("upgrade failed", err, job)
	}
	after, _, err := a.backup.engine.load(site)
	if err != nil || !sameModules(instanceModules(after), []string{"cms", "site_inquiries", "analytics"}) || after.Fingerprint != before.Fingerprint || registryHash(after.Request) != registryHash(before.Request) {
		t.Fatal("selection or immutable ownership incorrect", err)
	}
	if !mustSubscription(t, a, site).TrialEndsAt.Equal(sub.TrialEndsAt) {
		t.Fatal("trial reset")
	}
	w = centralReq(t, a, "POST", "/v1/package-changes/"+site+"/apply", `{"plan_id":"`+plan.ID+`"}`, "module-upgrade-001")
	if w.Code != 200 {
		t.Fatal("idempotent replay", w.Code, w.Body.String())
	}
	down := packagePreview(t, a, site, "module-basic")
	if packageApply(t, a, down, "module-downgrade-001") != 202 {
		t.Fatal("downgrade not queued")
	}
	a.backup.workers.Wait()
	after, _, err = a.backup.engine.load(site)
	if err != nil || !sameModules(instanceModules(after), []string{"cms", "site_inquiries"}) {
		t.Fatal("downgrade failed", err)
	}
	if mustSubscription(t, a, site).PackageID != "module-basic" {
		t.Fatal("metadata not committed")
	}
	for _, id := range []string{plan.ID, down.ID} {
		var j packageModuleJournal
		if decode(filepath.Join(a.packageModuleDir(id), "journal.json"), &j) != nil || j.Phase != "complete" || j.Snapshot == "" {
			t.Fatal("missing verified recovery snapshot")
		}
		if _, err = os.Stat(filepath.Join(a.packageModuleDir(id), "backup")); !os.IsNotExist(err) {
			t.Fatal("plaintext backup retained")
		}
	}
}
func TestPackageModuleFailureRestoresOriginalDataEnvironmentAndPackage(t *testing.T) {
	a, site, runner := moduleChangeFixture(t)
	runner.failApply = true
	before, p, _ := a.backup.engine.load(site)
	env, _ := os.ReadFile(filepath.Join(p, ".env"))
	oldSub := mustSubscription(t, a, site)
	plan := packagePreview(t, a, site, "module-pro")
	if packageApply(t, a, plan, "module-failure-001") != 202 {
		t.Fatal("not queued")
	}
	a.backup.workers.Wait()
	after, _, err := a.backup.engine.load(site)
	if err != nil || registryHash(after) != registryHash(before) {
		t.Fatal("state not restored", err)
	}
	actual, _ := os.ReadFile(filepath.Join(p, ".env"))
	marker, _ := os.ReadFile(filepath.Join(p, "storage", "customer-marker"))
	if !bytes.Equal(actual, env) || string(marker) != "private-module-data-marker" {
		t.Fatal("credentials or data changed")
	}
	if registryHash(oldSub) != registryHash(mustSubscription(t, a, site)) {
		t.Fatal("subscription changed despite failure")
	}
	if !runner.base.running || a.packageModuleStatus(site) != nil {
		t.Fatal("runtime not restored")
	}
}
func TestPackageEnvironmentOnlyChangesModuleSelection(t *testing.T) {
	raw := []byte("APP_KEY=private-key\nDB_DATABASE=/app/storage/database.sqlite\nGEREQ_PACKAGE_MODULES=cms\n")
	want := packageEnvironment(raw, []string{"cms", "analytics"})
	if !strings.Contains(string(want), "APP_KEY=private-key\n") || strings.Count(string(want), "GEREQ_PACKAGE_MODULES=") != 1 {
		t.Fatal("unsafe environment rewrite")
	}
}

func TestInterruptedModuleRecoveryBlocksOtherWorkAndCanRetry(t *testing.T) {
	a, site, runner := moduleChangeFixture(t)
	runner.failApply = true
	runner.failRecovery = true
	plan := packagePreview(t, a, site, "module-pro")
	if packageApply(t, a, plan, "module-interrupted-001") != 202 {
		t.Fatal("not queued")
	}
	a.backup.workers.Wait()
	if _, _, err := a.backup.engine.load(site); err == nil {
		t.Fatal("pending recovery did not block operations")
	}
	status := a.packageModuleStatus(site)
	if status == nil || !status.CanRecover {
		t.Fatal("recovery not visible")
	}
	runner.failRecovery = false
	w := centralReq(t, a, "POST", "/v1/package-changes/"+site+"/recover", `{}`, "module-recover-001")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	a.backup.workers.Wait()
	if _, _, err := a.backup.engine.load(site); err != nil {
		t.Fatal("recovery failed", err)
	}
	if mustSubscription(t, a, site).PackageID != "module-basic" || a.packageModuleStatus(site) != nil || !runner.base.running {
		t.Fatal("original instance not restored")
	}
}
func TestCommittedModuleRecoveryPreservesNewPackageAndNewData(t *testing.T) {
	a, site, runner := moduleChangeFixture(t)
	runner.failStart = true
	plan := packagePreview(t, a, site, "module-pro")
	if packageApply(t, a, plan, "module-committed-001") != 202 {
		t.Fatal("not queued")
	}
	a.backup.workers.Wait()
	if mustSubscription(t, a, site).PackageID != "module-pro" || a.packageModuleStatus(site) == nil {
		t.Fatal("commit phase not retained")
	}
	p, _ := ownedDir(a.backup.engine.Root, site)
	if err := os.WriteFile(filepath.Join(p, "storage", "customer-marker"), []byte("post-commit-customer-data"), 0600); err != nil {
		t.Fatal(err)
	}
	runner.failStart = false
	if _, err := a.recoverPackageModuleChange(t.Context(), site); err != nil {
		t.Fatal(err)
	}
	data, _ := os.ReadFile(filepath.Join(p, "storage", "customer-marker"))
	if string(data) != "post-commit-customer-data" || mustSubscription(t, a, site).PackageID != "module-pro" {
		t.Fatal("committed package/data were rolled back")
	}
}

func TestPackageRecoveryNeverStartsExpiredCustomer(t *testing.T) {
	a, site, runner := moduleChangeFixture(t)
	state, p, err := a.backup.engine.load(site)
	if err != nil {
		t.Fatal(err)
	}
	expireClock(t, a, site)
	runner.base.running = false
	j := &packageModuleJournal{Original: *state, WasRunning: true, Plan: packageChangePlan{SiteID: site, Target: catalogPackage{Modules: instanceModules(state)}}}
	if err = a.finishPackageRuntime(t.Context(), j, p); err == nil || runner.base.running {
		t.Fatal("expired customer resumed")
	}
}
