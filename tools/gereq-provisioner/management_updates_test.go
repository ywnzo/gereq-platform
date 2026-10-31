package main

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func managementUpdateFixture(t *testing.T) (*centralAPI, Request, Release, *updateRunner) {
	t.Helper()
	e, r, next, runner, _ := updateFixture(t)
	e.Runner = &managementRuntime{base: runner, running: true, setupRequired: true}
	b, err := newBackupAPI(e)
	if err != nil {
		t.Fatal(err)
	}
	catalog := filepath.Join(e.Root, "approved-releases")
	if err = os.Mkdir(catalog, 0700); err != nil {
		t.Fatal(err)
	}
	state, _, _ := e.load(r.SiteID)
	for _, release := range []Release{state.Release, next} {
		if err = atomicJSON(filepath.Join(catalog, release.ID+".json"), release); err != nil {
			t.Fatal(err)
		}
	}
	a, err := newCentralAPI(b, catalog)
	if err != nil {
		t.Fatal(err)
	}
	return a, r, next, runner
}
func updateManagementRequest(t *testing.T, a *centralAPI, site, op string, input any, key string) string {
	t.Helper()
	raw, _ := json.Marshal(input)
	w := centralReq(t, a, "POST", "/v1/management/instances/"+site+"/"+op, string(raw), key)
	if w.Code != 202 && w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	var out struct {
		Job managedAction `json:"job"`
	}
	if json.Unmarshal(w.Body.Bytes(), &out) != nil {
		t.Fatal(w.Body.String())
	}
	return out.Job.ID
}
func TestManagementUpdatePlanPinnedUpdateAndRollback(t *testing.T) {
	a, r, next, runner := managementUpdateFixture(t)
	w := centralReq(t, a, "POST", "/v1/management/instances/"+r.SiteID+"/update-plan", `{"release_id":"`+next.ID+`"}`, "management-plan-001")
	if w.Code != 200 || !strings.Contains(w.Body.String(), runner.targetID) {
		t.Fatal(w.Code, w.Body.String())
	}
	state, _, _ := a.backup.engine.load(r.SiteID)
	if state.Release.ID == next.ID {
		t.Fatal("plan mutated instance")
	}
	input := map[string]any{"release_id": next.ID, "current_release": r.Release, "expected_image_id": runner.targetID}
	id := updateManagementRequest(t, a, r.SiteID, "update", input, "management-update-001")
	a.backup.workers.Wait()
	if replay := updateManagementRequest(t, a, r.SiteID, "update", input, "management-update-001"); replay != id {
		t.Fatal("duplicate update")
	}
	site := managementSnapshot(t, a).Instances[0]
	if site.Update == nil || !site.Update.CanRollback || site.Update.Active || site.ReleaseID != next.ID || site.Observation == nil || !site.Observation.Healthy {
		t.Fatalf("update incomplete: %+v", site)
	}
	if w = centralReq(t, a, "POST", "/v1/management/instances/"+r.SiteID+"/update-rollback", `{"update_id":"`+site.Update.ID+`"}`, "management-rollback-unsafe"); w.Code != 409 {
		t.Fatal("unsafe rollback accepted")
	}
	rollbackInput := map[string]any{"update_id": site.Update.ID, "accept_data_loss": true}
	rollbackJob := updateManagementRequest(t, a, r.SiteID, "update-rollback", rollbackInput, "management-rollback-001")
	a.backup.workers.Wait()
	site = managementSnapshot(t, a).Instances[0]
	if site.ReleaseID != r.Release || site.Update == nil || site.Update.Phase != "recovered" || site.Update.CanRollback {
		t.Fatalf("rollback incomplete: %+v", site)
	}
	if replay := updateManagementRequest(t, a, r.SiteID, "update-rollback", rollbackInput, "management-rollback-001"); replay != rollbackJob {
		t.Fatal("duplicate rollback")
	}
}
func TestManagementFailedUpdateProgressAndRecovery(t *testing.T) {
	a, r, next, runner := managementUpdateFixture(t)
	runner.failUpgrade = true
	updateManagementRequest(t, a, r.SiteID, "update", map[string]any{"release_id": next.ID, "current_release": r.Release, "expected_image_id": runner.targetID}, "management-fail-001")
	a.backup.workers.Wait()
	site := managementSnapshot(t, a).Instances[0]
	if site.RecordStatus != "inspection_required" || site.Update == nil || !site.Update.CanRecover || !site.Update.BackupReady || site.Update.WritesPossible {
		t.Fatalf("missing recovery: %+v", site)
	}
	if strings.Contains(site.Update.Error, "migration failed") {
		t.Fatal("raw runtime error exposed")
	}
	// Progress remains available even while the updater holds the registry lock.
	unlock, err := a.backup.engine.lock()
	if err != nil {
		t.Fatal(err)
	}
	w := centralReq(t, a, "GET", "/v1/management/instances/"+r.SiteID+"/updates", "", "")
	if overview := managementSnapshot(t, a); len(overview.Instances) != 1 || overview.Instances[0].Update == nil {
		t.Fatal("inventory unavailable while mutation lock held")
	}
	unlock()
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"phase":"migrating"`) {
		t.Fatal(w.Code, w.Body.String())
	}
	recoverJob := updateManagementRequest(t, a, r.SiteID, "update-recover", map[string]any{}, "management-recover-001")
	a.backup.workers.Wait()
	site = managementSnapshot(t, a).Instances[0]
	if site.ReleaseID != r.Release || site.Update == nil || site.Update.Phase != "recovered" || site.RecordStatus == "inspection_required" {
		t.Fatalf("recovery incomplete: %+v", site)
	}
	if replay := updateManagementRequest(t, a, r.SiteID, "update-recover", map[string]any{}, "management-recover-001"); replay != recoverJob {
		t.Fatal("duplicate recovery")
	}
}
func TestManagementRejectsStaleSourceAndChangedImageWithoutStopping(t *testing.T) {
	a, r, next, runner := managementUpdateFixture(t)
	for i, input := range []map[string]any{{"release_id": next.ID, "current_release": "stale-release", "expected_image_id": runner.targetID}, {"release_id": next.ID, "current_release": r.Release, "expected_image_id": "sha256:" + strings.Repeat("c", 64)}} {
		id := updateManagementRequest(t, a, r.SiteID, "update", input, "management-stale-00"+string(rune('1'+i)))
		a.backup.workers.Wait()
		w := centralReq(t, a, "GET", "/v1/management/jobs/"+id, "", "")
		if !strings.Contains(w.Body.String(), `"status":"failed"`) {
			t.Fatal(w.Body.String())
		}
	}
	runtime := a.backup.engine.Runner.(*managementRuntime)
	if runtime.stopCalls != 0 {
		t.Fatal("rejected plan stopped site")
	}
}
func TestUpdatePlanRequiresBackupPrerequisites(t *testing.T) {
	e, r, next, _, _ := updateFixture(t)
	t.Setenv("GEREQ_BACKUP_MASTER_KEY_FILE", "")
	if _, err := e.PlanUpdate(context.Background(), r.SiteID, next); err == nil {
		t.Fatal("plan allowed missing key")
	}
}

type updateProvenanceRunner struct {
	Runner
	oldID string
}

func (r updateProvenanceRunner) Run(ctx context.Context, args []string) ([]byte, error) {
	if len(args) > 0 && args[0] == "run" && strings.Contains(strings.Join(args, " "), "PROVISION_SOURCE.json") {
		version := "2.65.0"
		release := "release-v2"
		if strings.Contains(strings.Join(args, " "), r.oldID) {
			version = "2.66.0"
			release = "local-v1"
		}
		return json.Marshal(map[string]any{"release_id": release, "platform_version": "0.6.0", "module_versions": map[string]string{"cms": version, "site_inquiries": "1.0.0"}})
	}
	return r.Runner.Run(ctx, args)
}
func TestUpdatePlanRejectsModuleDowngradeBeforeStopping(t *testing.T) {
	e, r, next, runner, _ := updateFixture(t)
	e.Runner = updateProvenanceRunner{Runner: runner, oldID: runner.oldID}
	if _, err := e.PlanUpdate(context.Background(), r.SiteID, next); err == nil || !strings.Contains(err.Error(), "module downgrade") {
		t.Fatal("module downgrade not rejected", err)
	}
	for _, args := range runner.base.calls {
		if len(args) > 0 && args[len(args)-1] == "stop" {
			t.Fatal("preflight stopped instance")
		}
	}
}
