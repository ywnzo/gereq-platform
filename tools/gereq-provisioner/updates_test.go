package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

type updateRunner struct {
	base                   *fakeDocker
	releaseID              string
	oldID, targetID        string
	failUpgrade, failStart bool
	storage                string
}

func (r *updateRunner) Run(ctx context.Context, a []string) ([]byte, error) {
	joined := strings.Join(a, " ")
	if len(a) > 2 && a[0] == "inspect" && a[2] == "{{.Image}}" {
		return []byte(r.oldID), nil
	}
	if len(a) > 1 && a[0] == "image" && a[1] == "inspect" {
		return []byte(r.targetID), nil
	}
	if len(a) > 0 && a[0] == "run" && strings.Contains(joined, "PROVISION_SOURCE.json") {
		release := r.releaseID
		if strings.Contains(joined, r.oldID) {
			release = "local-v1"
		}
		return json.Marshal(map[string]any{"release_id": release, "platform_version": "0.6.0", "module_versions": map[string]string{"cms": "2.65.0", "site_inquiries": "1.0.0"}})
	}
	if r.failUpgrade && strings.HasSuffix(joined, "bridge.php upgrade") {
		r.failUpgrade = false
		_ = os.WriteFile(filepath.Join(r.storage, "migration-write"), []byte("changed"), 0600)
		return nil, errors.New("migration failed")
	}
	if r.failStart && strings.Contains(joined, " up --detach") {
		r.failStart = false
		_ = os.WriteFile(filepath.Join(r.storage, "new-write"), []byte("new"), 0600)
		return nil, errors.New("start failed")
	}
	return r.base.Run(ctx, a)
}
func updateFixture(t *testing.T) (Engine, Request, Release, *updateRunner, string) {
	t.Helper()
	if _, err := exec.LookPath("restic"); err != nil {
		t.Skip("restic required for encrypted update tests")
	}
	master := filepath.Join(t.TempDir(), "master.key")
	if err := os.WriteFile(master, []byte(strings.Repeat("x", 32)), 0600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("GEREQ_BACKUP_MASTER_KEY_FILE", master)
	e, request, release, base := fixture(t)
	_, err := e.Create(context.Background(), request, release)
	if err != nil {
		t.Fatal(err)
	}
	p := filepath.Join(e.Root, "sites", request.SiteID)
	if err = os.WriteFile(filepath.Join(p, "storage", "customer-data"), []byte("original"), 0600); err != nil {
		t.Fatal(err)
	}
	runner := &updateRunner{base: base, releaseID: "release-v2", oldID: "sha256:" + strings.Repeat("a", 64), targetID: "sha256:" + strings.Repeat("b", 64), storage: filepath.Join(p, "storage")}
	e.Runner = runner
	next := release
	next.ID = runner.releaseID
	next.Image = "gereq-local:release-v2"
	return e, request, next, runner, p
}
func exportedRetained(t *testing.T, e Engine, r Request, j *UpdateJournal) string {
	t.Helper()
	result, err := e.ExportRetainedUpdate(context.Background(), r.SiteID, j.ID)
	if err != nil {
		t.Fatal(err)
	}
	path, ok := result["restore_path"].(string)
	if !ok {
		t.Fatal("missing export path")
	}
	return path
}
func TestUpdateBackupAndExplicitRollback(t *testing.T) {
	e, r, next, runner, p := updateFixture(t)
	ctx := context.Background()
	envBefore, _ := os.ReadFile(filepath.Join(p, ".env"))
	plan, err := e.PlanUpdate(ctx, r.SiteID, next)
	if err != nil || plan["mutations"] != false {
		t.Fatal(plan, err)
	}
	j, err := e.Update(ctx, r.SiteID, next, runner.targetID)
	if err != nil || j.Phase != "complete" {
		t.Fatal(j, err)
	}
	state, _, err := e.load(r.SiteID)
	if err != nil || state.Release.ID != next.ID {
		t.Fatal(state, err)
	}
	job, _ := e.updateDir(r.SiteID, j.ID)
	if err = materializeUpdateBackup(context.Background(), job, j); err != nil {
		t.Fatal(err)
	}
	if err = verifyBackup(job); err != nil {
		t.Fatal(err)
	}
	cfg, _ := os.ReadFile(filepath.Join(job, "backup", "compose.json"))
	if !strings.Contains(string(cfg), runner.oldID) {
		t.Fatal("rollback image not pinned")
	}
	if _, err = os.Stat(filepath.Join(job, "backup", ".env")); !os.IsNotExist(err) {
		t.Fatal("environment copied into backup")
	}
	if err = os.WriteFile(filepath.Join(p, "storage", "after-update"), []byte("new"), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err = e.RollbackUpdate(ctx, r.SiteID, j.ID, false); err == nil {
		t.Fatal("unsafe rollback accepted")
	}
	j, err = e.RollbackUpdate(ctx, r.SiteID, j.ID, true)
	if err != nil || j.Phase != "recovered" {
		t.Fatal(j, err)
	}
	restored, _, err := e.load(r.SiteID)
	if err != nil || restored.Release.ID != r.Release {
		t.Fatal(restored, err)
	}
	if _, err = os.Stat(filepath.Join(p, "storage", "after-update")); !os.IsNotExist(err) {
		t.Fatal("new data unexpectedly merged")
	}
	if _, err = os.Stat(filepath.Join(exportedRetained(t, e, r, j), "after-update")); err != nil {
		t.Fatal("post-update data not retained", err)
	}
	envAfter, _ := os.ReadFile(filepath.Join(p, ".env"))
	if string(envAfter) != string(envBefore) {
		t.Fatal("environment changed")
	}
}
func TestUpdateFailureRecoveryAndBackupIntegrity(t *testing.T) {
	e, r, next, runner, p := updateFixture(t)
	ctx := context.Background()
	runner.failUpgrade = true
	j, err := e.Update(ctx, r.SiteID, next, runner.targetID)
	if err == nil || j == nil || !j.BackupReady || j.Started {
		t.Fatal(j, err)
	}
	if _, _, err = e.load(r.SiteID); err == nil {
		t.Fatal("unfinished update was not locked")
	}
	job, _ := e.updateDir(r.SiteID, j.ID)
	if err = materializeUpdateBackup(context.Background(), job, j); err != nil {
		t.Fatal(err)
	}
	backupFile := filepath.Join(job, "backup", "storage", "customer-data")
	original, _ := os.ReadFile(backupFile)
	_ = os.WriteFile(backupFile, []byte("tampered"), 0600)
	if _, err = e.RecoverUpdate(ctx, r.SiteID, false); err == nil {
		t.Fatal("corrupt backup accepted")
	}
	_ = os.WriteFile(backupFile, original, 0600)
	j, err = e.RecoverUpdate(ctx, r.SiteID, false)
	if err != nil || j.Phase != "recovered" {
		t.Fatal(j, err)
	}
	if _, err = os.Stat(filepath.Join(exportedRetained(t, e, r, j), "migration-write")); err != nil {
		t.Fatal("failed migration data not retained", err)
	}
	if _, err = os.Stat(filepath.Join(p, "storage", "migration-write")); !os.IsNotExist(err) {
		t.Fatal("failed migration persisted")
	}
}
func TestRecoveryAfterStartRequiresExplicitAcceptance(t *testing.T) {
	e, r, next, runner, _ := updateFixture(t)
	ctx := context.Background()
	runner.failStart = true
	j, err := e.Update(ctx, r.SiteID, next, runner.targetID)
	if err == nil || !j.Started {
		t.Fatal(j, err)
	}
	if _, err = e.RecoverUpdate(ctx, r.SiteID, false); err == nil {
		t.Fatal("recovery discarded possible writes without acceptance")
	}
	j, err = e.RecoverUpdate(ctx, r.SiteID, true)
	if err != nil || j.Phase != "recovered" {
		t.Fatal(j, err)
	}
	job, _ := e.updateDir(r.SiteID, j.ID)
	if err = materializeUpdateBackup(context.Background(), job, j); err != nil {
		t.Fatal(err)
	}
	if _, err = os.Stat(filepath.Join(exportedRetained(t, e, r, j), "new-write")); err != nil {
		t.Fatal(err)
	}
}
func TestUpdateRejectsWrongProvenanceAndCustomMounts(t *testing.T) {
	e, r, next, runner, p := updateFixture(t)
	ctx := context.Background()
	runner.releaseID = "wrong-release"
	if _, err := e.Update(ctx, r.SiteID, next, runner.targetID); err == nil {
		t.Fatal("wrong image accepted")
	}
	if _, err := os.Stat(activeUpdate(p)); !os.IsNotExist(err) {
		t.Fatal("started before provenance validation")
	}
	runner.releaseID = next.ID
	cfgPath := filepath.Join(p, "compose.json")
	var cfg map[string]any
	raw, _ := os.ReadFile(cfgPath)
	_ = json.Unmarshal(raw, &cfg)
	app := cfg["services"].(map[string]any)["app"].(map[string]any)
	app["volumes"] = append(app["volumes"].([]any), map[string]any{"type": "bind", "source": "/tmp", "target": "/frontend"})
	_ = atomicJSON(cfgPath, cfg)
	if _, err := e.Update(ctx, r.SiteID, next, runner.targetID); err == nil {
		t.Fatal("custom code mount accepted")
	}
	if _, err := os.Stat(activeUpdate(p)); !os.IsNotExist(err) {
		t.Fatal("started with custom mount")
	}
}

func TestModuleCheckAcceptsOnlyKnownPodmanPrefix(t *testing.T) {
	for _, out := range []string{`{"ok":true}`, strings.Repeat("a", 64) + "\n" + `{"ok":true}`} {
		if err := parseModuleCheck([]byte(out)); err != nil {
			t.Fatal(err)
		}
	}
	for _, out := range []string{"warning\n" + `{"ok":true}`, `{"ok":false}`, strings.Repeat("z", 64) + "\n" + `{"ok":true}`} {
		if parseModuleCheck([]byte(out)) == nil {
			t.Fatal("unexpected response accepted")
		}
	}
}
func TestImageIDNormalizationAndVersions(t *testing.T) {
	hex := strings.Repeat("a", 64)
	if normalizeImageID(hex) != "sha256:"+hex || normalizeImageID("sha256:"+hex) != "sha256:"+hex {
		t.Fatal("bad ID normalization")
	}
	if normalizeImageID("not-an-id") != "" {
		t.Fatal("invalid ID accepted")
	}
	if !versionAtLeast("0.6.1", "0.6.0") || versionAtLeast("0.5.9", "0.6.0") || versionAtLeast("v0.6.0", "0.6.0") {
		t.Fatal("incorrect version comparison")
	}
}

func TestCompletedJournalRecoveryDoesNotRollback(t *testing.T) {
	e, r, next, runner, p := updateFixture(t)
	ctx := context.Background()
	j, err := e.Update(ctx, r.SiteID, next, runner.targetID)
	if err != nil {
		t.Fatal(err)
	}
	if err = os.WriteFile(filepath.Join(p, "storage", "post-completion"), []byte("kept"), 0600); err != nil {
		t.Fatal(err)
	}
	if err = atomicJSON(activeUpdate(p), map[string]string{"id": j.ID}); err != nil {
		t.Fatal(err)
	}
	status, err := e.UpdateStatus(r.SiteID, "")
	if err != nil || status["status"] != "complete" {
		t.Fatal(status, err)
	}
	recovered, err := e.RecoverUpdate(ctx, r.SiteID, false)
	if err != nil || recovered.Phase != "complete" {
		t.Fatal(recovered, err)
	}
	state, _, err := e.load(r.SiteID)
	if err != nil || state.Release.ID != next.ID {
		t.Fatal(state, err)
	}
	if _, err = os.Stat(filepath.Join(p, "storage", "post-completion")); err != nil {
		t.Fatal("completed update lost later data")
	}
}
func TestUpdateRefusesUnownedDatabaseMount(t *testing.T) {
	e, r, next, _, p := updateFixture(t)
	envPath := filepath.Join(p, ".env")
	raw, _ := os.ReadFile(envPath)
	raw = append(raw, []byte("\nDB_DATABASE=/tmp/elsewhere.sqlite\n")...)
	if err := atomicFile(envPath, raw); err != nil {
		t.Fatal(err)
	}
	if _, err := e.Update(context.Background(), r.SiteID, next, "sha256:"+strings.Repeat("b", 64)); err == nil {
		t.Fatal("unowned database accepted")
	}
	if _, err := os.Stat(activeUpdate(p)); !os.IsNotExist(err) {
		t.Fatal("update started before mount validation")
	}
}

func TestUpdateRejectsChangedImageAndEnvironment(t *testing.T) {
	e, r, next, runner, p := updateFixture(t)
	ctx := context.Background()
	if _, err := e.Update(ctx, r.SiteID, next, "sha256:"+strings.Repeat("c", 64)); err == nil {
		t.Fatal("changed image accepted")
	}
	if _, err := os.Stat(activeUpdate(p)); !os.IsNotExist(err) {
		t.Fatal("started with different image")
	}
	j, err := e.Update(ctx, r.SiteID, next, runner.targetID)
	if err != nil {
		t.Fatal(err)
	}
	envPath := filepath.Join(p, ".env")
	raw, err := os.ReadFile(envPath)
	if err != nil {
		t.Fatal(err)
	}
	raw = append(raw, []byte("\nTEST_SETTING=changed\n")...)
	if err = atomicFile(envPath, raw); err != nil {
		t.Fatal(err)
	}
	if _, err = e.RollbackUpdate(ctx, r.SiteID, j.ID, true); err == nil {
		t.Fatal("rollback accepted modified environment")
	}
	if _, err = os.Stat(activeUpdate(p)); !os.IsNotExist(err) {
		t.Fatal("rollback started after environment changed")
	}
}

func TestRecoveryResumesAfterStorageWasRetainedOrRestored(t *testing.T) {
	for _, alreadyRestored := range []bool{false, true} {
		t.Run(fmt.Sprintf("restored_%t", alreadyRestored), func(t *testing.T) {
			e, r, next, runner, p := updateFixture(t)
			ctx := context.Background()
			runner.failUpgrade = true
			j, err := e.Update(ctx, r.SiteID, next, runner.targetID)
			if err == nil {
				t.Fatal("expected failure")
			}
			job, _ := e.updateDir(r.SiteID, j.ID)
			if err = materializeUpdateBackup(context.Background(), job, j); err != nil {
				t.Fatal(err)
			}
			j.Phase = "restoring"
			if err = atomicJSON(filepath.Join(job, "journal.json"), j); err != nil {
				t.Fatal(err)
			}
			if err = os.Rename(filepath.Join(p, "storage"), filepath.Join(job, "failed-storage")); err != nil {
				t.Fatal(err)
			}
			if alreadyRestored {
				if err = copyVerified(filepath.Join(job, "backup", "storage"), filepath.Join(p, "storage")); err != nil {
					t.Fatal(err)
				}
			}
			j, err = e.RecoverUpdate(ctx, r.SiteID, false)
			if err != nil || j.Phase != "recovered" {
				t.Fatal(j, err)
			}
			if _, err = os.Stat(filepath.Join(exportedRetained(t, e, r, j), "migration-write")); err != nil {
				t.Fatal(err)
			}
		})
	}
}
func TestRecoveryDoesNotOverwriteChangedRestoredStorage(t *testing.T) {
	e, r, next, runner, p := updateFixture(t)
	ctx := context.Background()
	runner.failUpgrade = true
	j, err := e.Update(ctx, r.SiteID, next, runner.targetID)
	if err == nil {
		t.Fatal("expected failure")
	}
	job, _ := e.updateDir(r.SiteID, j.ID)
	if err = materializeUpdateBackup(context.Background(), job, j); err != nil {
		t.Fatal(err)
	}
	j.Phase = "restoring"
	_ = atomicJSON(filepath.Join(job, "journal.json"), j)
	_ = os.Rename(filepath.Join(p, "storage"), filepath.Join(job, "failed-storage"))
	_ = copyVerified(filepath.Join(job, "backup", "storage"), filepath.Join(p, "storage"))
	_ = os.WriteFile(filepath.Join(p, "storage", "unexpected"), []byte("preserve"), 0600)
	if _, err = e.RecoverUpdate(ctx, r.SiteID, false); err == nil {
		t.Fatal("changed storage accepted")
	}
	if _, err = os.Stat(filepath.Join(p, "storage", "unexpected")); err != nil {
		t.Fatal("changed storage lost")
	}
}

func TestRecoveryResumesAfterRetainedDataWasEncrypted(t *testing.T) {
	e, r, next, runner, p := updateFixture(t)
	ctx := context.Background()
	runner.failUpgrade = true
	j, err := e.Update(ctx, r.SiteID, next, runner.targetID)
	if err == nil {
		t.Fatal("expected migration failure")
	}
	job, _ := e.updateDir(r.SiteID, j.ID)
	if err = materializeUpdateBackup(ctx, job, j); err != nil {
		t.Fatal(err)
	}
	retained := filepath.Join(job, "failed-storage")
	if err = os.Rename(filepath.Join(p, "storage"), retained); err != nil {
		t.Fatal(err)
	}
	if err = copyVerified(filepath.Join(job, "backup", "storage"), filepath.Join(p, "storage")); err != nil {
		t.Fatal(err)
	}
	c, err := updateVaultConfig(job)
	if err != nil {
		t.Fatal(err)
	}
	j.EncryptedRetainedID, err = encryptUpdateDirectory(ctx, c, retained)
	if err != nil {
		t.Fatal(err)
	}
	j.Phase = "restored_storage"
	if err = atomicJSON(filepath.Join(job, "journal.json"), j); err != nil {
		t.Fatal(err)
	}
	if err = os.RemoveAll(retained); err != nil {
		t.Fatal(err)
	}
	j, err = e.RecoverUpdate(ctx, r.SiteID, false)
	if err != nil || j.Phase != "recovered" {
		t.Fatal(j, err)
	}
	path := exportedRetained(t, e, r, j)
	if _, err = os.Stat(filepath.Join(path, "migration-write")); err != nil {
		t.Fatal(err)
	}
}
