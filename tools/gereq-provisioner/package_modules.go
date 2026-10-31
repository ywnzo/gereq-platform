package main

import (
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

//go:embed runtime/package-modules.php
var packageModuleScript string
var packagePlanPattern = regexp.MustCompile(`^pkg-[a-f0-9]{24}$`)

func activePackageModuleChange(dir string) string { return filepath.Join(dir, ".package-change.json") }

type packageModuleJournal struct {
	Plan       packageChangePlan `json:"plan"`
	Original   State             `json:"original"`
	WasRunning bool              `json:"was_running"`
	Phase      string            `json:"phase"`
	Snapshot   string            `json:"encrypted_snapshot,omitempty"`
	Error      string            `json:"error,omitempty"`
}
type packageModuleStatus struct {
	PlanID     string `json:"plan_id"`
	Phase      string `json:"phase"`
	CanRecover bool   `json:"can_recover"`
	Error      string `json:"error,omitempty"`
}

func (a *centralAPI) packageModuleDir(id string) string {
	return filepath.Join(a.dir, "package-module-changes", id)
}
func (a *centralAPI) savePackageModuleJournal(j *packageModuleJournal) error {
	return atomicJSON(filepath.Join(a.packageModuleDir(j.Plan.ID), "journal.json"), j)
}
func (a *centralAPI) packageModuleStatus(site string) *packageModuleStatus {
	p, err := ownedDir(a.backup.engine.Root, site)
	if err != nil {
		return nil
	}
	var marker struct {
		PlanID string `json:"plan_id"`
	}
	if decode(activePackageModuleChange(p), &marker) != nil {
		return nil
	}
	status := &packageModuleStatus{PlanID: marker.PlanID, Phase: "recovery_required", CanRecover: true}
	if !packagePlanPattern.MatchString(marker.PlanID) {
		status.Error = "Invalid package recovery marker"
		return status
	}
	var j packageModuleJournal
	if decode(filepath.Join(a.packageModuleDir(marker.PlanID), "journal.json"), &j) == nil {
		status.Phase = j.Phase
		status.Error = j.Error
	}
	return status
}
func packageModules(p catalogPackage, s *State) []string {
	if len(p.Modules) > 0 {
		return append([]string(nil), p.Modules...)
	}
	return append([]string(nil), s.Release.Modules...)
}
func (a *centralAPI) validatePackagePlan(state *State, sub *subscription, plan packageChangePlan, used int) error {
	if sub == nil || sub.CustomerRef != state.Request.CustomerRef || plan.SiteID != state.Request.SiteID || !a.subscriptionNow().Before(plan.ExpiresAt) || sub.Revision != plan.SubscriptionRevision || state.Fingerprint != plan.Fingerprint || used != plan.GenerationUsed {
		return errors.New("package plan is stale; preview again")
	}
	if len(plan.SourceModules) > 0 && !sameModules(plan.SourceModules, instanceModules(state)) {
		return errors.New("source modules changed; preview again")
	}
	target, err := a.packageTarget(state, plan.Target.ID)
	if err != nil {
		return err
	}
	if registryHash(target) != registryHash(plan.Target) {
		return errors.New("target package changed; preview again")
	}
	if target.GenerationLimit != nil && used > *target.GenerationLimit {
		return errors.New("target generation limit is below current usage")
	}
	return nil
}
func (a *centralAPI) commitModulePackage(j *packageModuleJournal) (*subscription, error) {
	sub, _, err := a.subscriptions.mutate(j.Plan.SiteID, "module-package:"+j.Plan.ID, registryHash(j.Plan), "package-modules-changed", a.subscriptionNow(), func(current *subscription) (*simulatedPayment, error) {
		if current.Revision != j.Plan.SubscriptionRevision {
			return nil, errors.New("subscription changed during module transition")
		}
		current.Package = &j.Plan.Target
		current.PackageID = j.Plan.Target.ID
		current.PackageRevision = j.Plan.Target.Revision
		return nil, nil
	})
	return sub, err
}
func (e Engine) packageModulePHP(ctx context.Context, s *State, p, op string, target, expected []string) error {
	want, _ := json.Marshal(target)
	old, _ := json.Marshal(expected)
	out, err := e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "-r", strings.TrimPrefix(packageModuleScript, "<?php\n"), op, string(want), string(old)))
	if err != nil {
		return errors.New("module transition failed; inspect the private local runtime")
	}
	return parseModuleCheck(out)
}

// The existing container mounts this inode. A stopped, journaled write keeps its read-only mount valid.
func writePackageEnvironment(path string, data []byte) error {
	info, err := os.Lstat(path)
	if err != nil {
		return err
	}
	if !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 {
		return errors.New("unsafe instance environment")
	}
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_TRUNC, 0600)
	if err != nil {
		return err
	}
	defer f.Close()
	if _, err = f.Write(data); err != nil {
		return err
	}
	return f.Sync()
}
func packageEnvironment(raw []byte, modules []string) []byte {
	lines := []string{}
	for _, line := range strings.Split(strings.TrimSuffix(string(raw), "\n"), "\n") {
		if !strings.HasPrefix(line, "GEREQ_PACKAGE_MODULES=") {
			lines = append(lines, line)
		}
	}
	lines = append(lines, "GEREQ_PACKAGE_MODULES="+strings.Join(modules, ","))
	return []byte(strings.Join(lines, "\n") + "\n")
}

const packageIsolatedHealth = `php -S 127.0.0.1:8080 -t /app/public /opt/provision/router.php >/dev/null 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT
php -r 'for($i=0;$i<50;$i++){ $s=@fsockopen("127.0.0.1",8080);if($s){fclose($s);exit(0);}usleep(100000);}exit(1);' && php /opt/provision/bridge.php health`

func (e Engine) packageHealth(ctx context.Context, s *State, p string) error {
	out, err := e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "sh", "-c", packageIsolatedHealth))
	if err != nil {
		return errors.New("isolated module health check failed")
	}
	return parseModuleCheck(out)
}
func (a *centralAPI) recordPackageObservation(ctx context.Context, s *State, p string, running bool) error {
	observation := managedObservation{CheckedAt: a.subscriptionNow(), Runtime: "stopped"}
	if running {
		raw, err := a.backup.engine.Runner.Run(ctx, compose(s, p, "exec", "-T", "app", "php", "/opt/provision/bridge.php", "health"))
		if err != nil {
			return err
		}
		var health struct {
			OK            bool  `json:"ok"`
			SetupRequired *bool `json:"setup_required"`
		}
		if json.Unmarshal(raw, &health) != nil || !health.OK {
			return errors.New("runtime observation failed")
		}
		observation.Runtime = "running"
		observation.Healthy = true
		observation.SetupRequired = health.SetupRequired
	}
	return atomicJSON(filepath.Join(a.dir, "management-health", s.Request.SiteID+".json"), storedObservation{Fingerprint: s.Fingerprint, Observation: observation})
}
func (a *centralAPI) finishPackageRuntime(ctx context.Context, j *packageModuleJournal, p string) error {
	e := a.backup.engine
	s := j.Original
	s.ActiveModules = packageModules(j.Plan.Target, &s)
	if j.WasRunning {
		if err := a.subscriptionAllows(j.Plan.SiteID); err != nil {
			return err
		}
		if err := e.step(ctx, &s, p, "health"); err != nil {
			return err
		}
	} else {
		if _, err := e.Runner.Run(ctx, compose(&s, p, "up", "--no-build", "--no-start", "--force-recreate")); err != nil {
			return err
		}
	}
	if err := a.recordPackageObservation(ctx, &s, p, j.WasRunning); err != nil {
		return err
	}
	j.Phase = "complete"
	j.Error = ""
	if err := a.savePackageModuleJournal(j); err != nil {
		return err
	}
	return os.Remove(activePackageModuleChange(p))
}
func (a *centralAPI) runPackageModuleChange(ctx context.Context, site, planID string) (any, error) {
	e := a.backup.engine
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	a.subscriptionMu.Lock()
	defer a.subscriptionMu.Unlock()
	state, p, err := e.load(site)
	if err != nil {
		return nil, err
	}
	var plan packageChangePlan
	if err = decode(filepath.Join(a.dir, "package-plans", planID+".json"), &plan); err != nil || plan.ID != planID || plan.SiteID != site {
		return nil, errors.New("invalid package plan")
	}
	job := a.packageModuleDir(planID)
	var previous packageModuleJournal
	if decode(filepath.Join(job, "journal.json"), &previous) == nil {
		if previous.Phase == "complete" {
			sub, err := a.subscriptions.get(site)
			if err != nil {
				return nil, err
			}
			return sub.view(a.subscriptionNow()), nil
		}
		return nil, errors.New("previous module change requires recovery or a new preview")
	}
	sub, err := a.subscriptions.get(site)
	if err != nil {
		return nil, err
	}
	used, err := a.generationUsed(site)
	if err != nil {
		return nil, err
	}
	if err = a.validatePackagePlan(state, sub, plan, used); err != nil {
		return nil, err
	}
	if err = a.subscriptionAllows(site); err != nil {
		return nil, err
	}
	if !state.Release.ModuleSelection {
		return nil, errors.New("update the customer image before changing its modules")
	}
	if !contains(state.Completed, "health") || state.RelocatedTo != "" {
		return nil, errors.New("instance is not ready for module changes")
	}
	cfg, err := os.ReadFile(filepath.Join(p, "compose.json"))
	if err != nil {
		return nil, err
	}
	if err = checkUpdateConfig(cfg, state, p); err != nil {
		return nil, err
	}
	if err = e.checkOwner(ctx, state, p); err != nil {
		return nil, err
	}
	raw, err := e.Runner.Run(ctx, []string{"inspect", "--format", "{{json .State}}", projectName(site, p) + "-app"})
	if err != nil {
		return nil, err
	}
	var observed struct {
		Running *bool `json:"Running"`
	}
	if json.Unmarshal(raw, &observed) != nil || observed.Running == nil {
		return nil, errors.New("cannot verify runtime state")
	}
	target := packageModules(plan.Target, state)
	if err = e.packageModulePHP(ctx, state, p, "preflight", target, instanceModules(state)); err != nil {
		return nil, err
	}
	if err = privateBackupDir(filepath.Dir(job)); err != nil {
		return nil, err
	}
	if err = os.Mkdir(job, 0700); err != nil {
		return nil, err
	}
	archive, err := createEncryptedUpdateRepository(ctx, job)
	if err != nil {
		return nil, err
	}
	j := &packageModuleJournal{Plan: plan, Original: *state, WasRunning: *observed.Running, Phase: "prepared"}
	if err = a.savePackageModuleJournal(j); err != nil {
		return nil, err
	}
	if err = atomicJSON(activePackageModuleChange(p), map[string]string{"plan_id": planID}); err != nil {
		return nil, err
	}
	fail := func(cause error) (any, error) {
		j.Error = "Module transition failed; recovery is required."
		_ = a.savePackageModuleJournal(j)
		recoveryCtx, cancel := context.WithTimeout(context.Background(), 3*time.Minute)
		defer cancel()
		if recoveryErr := a.restorePackageModuleChange(recoveryCtx, j, p); recoveryErr != nil {
			return nil, fmt.Errorf("package module change failed; use package recovery: %w", cause)
		}
		return nil, fmt.Errorf("package module change failed; original instance restored: %w", cause)
	}
	if _, err = e.Runner.Run(ctx, compose(state, p, "stop")); err != nil {
		return fail(err)
	}
	j.Phase = "stopped"
	if err = a.savePackageModuleJournal(j); err != nil {
		return fail(err)
	}
	backup := filepath.Join(job, "backup")
	if err = os.Mkdir(backup, 0700); err != nil {
		return fail(err)
	}
	for _, name := range []string{"storage", ".env", "state.json"} {
		if err = copyVerified(filepath.Join(p, name), filepath.Join(backup, name)); err != nil {
			return fail(err)
		}
	}
	j.Snapshot, err = encryptUpdateDirectory(ctx, archive, backup)
	if err != nil {
		return fail(err)
	}
	j.Phase = "backed_up"
	if err = a.savePackageModuleJournal(j); err != nil {
		return fail(err)
	}
	if err = os.RemoveAll(backup); err != nil {
		return fail(err)
	}
	j.Phase = "applying"
	if err = a.savePackageModuleJournal(j); err != nil {
		return fail(err)
	}
	if err = e.packageModulePHP(ctx, state, p, "apply", target, instanceModules(state)); err != nil {
		return fail(err)
	}
	env, err := os.ReadFile(filepath.Join(p, ".env"))
	if err != nil {
		return fail(err)
	}
	if err = writePackageEnvironment(filepath.Join(p, ".env"), packageEnvironment(env, target)); err != nil {
		return fail(err)
	}
	next := *state
	next.ActiveModules = target
	if err = e.save(&next, p); err != nil {
		return fail(err)
	}
	if contains(target, "analytics") && !contains(instanceModules(state), "analytics") {
		if _, err = e.Runner.Run(ctx, compose(&next, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "analytics-config")); err != nil {
			return fail(err)
		}
	}
	j.Phase = "checking"
	if err = a.savePackageModuleJournal(j); err != nil {
		return fail(err)
	}
	if err = e.packageHealth(ctx, &next, p); err != nil {
		return fail(err)
	}
	updated, err := a.commitModulePackage(j)
	if err != nil {
		return fail(err)
	}
	j.Phase = "committed"
	if err = a.savePackageModuleJournal(j); err != nil {
		return nil, errors.New("package committed; use package recovery to finish")
	}
	if err = a.finishPackageRuntime(ctx, j, p); err != nil {
		j.Error = "Package committed; runtime recovery is required."
		_ = a.savePackageModuleJournal(j)
		return nil, errors.New("package committed; use package recovery to finish runtime startup")
	}
	return updated.view(a.subscriptionNow()), nil
}

// The engine and subscription locks are held. A committed package is completed, never rolled back over new customer writes.
func (a *centralAPI) restorePackageModuleChange(ctx context.Context, j *packageModuleJournal, p string) error {
	e := a.backup.engine
	sub, err := a.subscriptions.get(j.Plan.SiteID)
	if err != nil {
		return err
	}
	if sub != nil && sub.PackageID == j.Plan.Target.ID && sub.PackageRevision == j.Plan.Target.Revision {
		return a.finishPackageRuntime(ctx, j, p)
	}
	if _, err = e.Runner.Run(ctx, compose(&j.Original, p, "stop")); err != nil {
		return err
	}
	if j.Snapshot != "" {
		c, err := updateVaultConfig(a.packageModuleDir(j.Plan.ID))
		if err != nil {
			return err
		}
		temp, err := newUpdateTemp(a.packageModuleDir(j.Plan.ID), ".package-restore-")
		if err != nil {
			return err
		}
		defer os.RemoveAll(temp)
		candidate := filepath.Join(temp, "backup")
		if err = restoreEncryptedUpdateDirectory(ctx, c, j.Snapshot, filepath.Join(a.packageModuleDir(j.Plan.ID), "backup"), candidate); err != nil {
			return err
		}
		if _, err = e.Runner.Run(ctx, compose(&j.Original, p, "down")); err != nil {
			return err
		}
		// The service has remained stopped since the snapshot; no post-commit customer writes are discarded.
		if err = os.RemoveAll(filepath.Join(p, "storage")); err != nil {
			return err
		}
		if err = copyVerified(filepath.Join(candidate, "storage"), filepath.Join(p, "storage")); err != nil {
			return err
		}
		env, err := os.ReadFile(filepath.Join(candidate, ".env"))
		if err != nil {
			return err
		}
		if err = writePackageEnvironment(filepath.Join(p, ".env"), env); err != nil {
			return err
		}
		if err = e.save(&j.Original, p); err != nil {
			return err
		}
	}
	if j.WasRunning {
		if err = a.subscriptionAllows(j.Plan.SiteID); err != nil {
			return err
		}
		if err = e.step(ctx, &j.Original, p, "health"); err != nil {
			return err
		}
	} else {
		if _, err = e.Runner.Run(ctx, compose(&j.Original, p, "up", "--no-build", "--no-start")); err != nil {
			return err
		}
	}
	if err = a.recordPackageObservation(ctx, &j.Original, p, j.WasRunning); err != nil {
		return err
	}
	j.Phase = "rolled_back"
	j.Error = ""
	if err = a.savePackageModuleJournal(j); err != nil {
		return err
	}
	_ = os.RemoveAll(filepath.Join(a.packageModuleDir(j.Plan.ID), "backup"))
	return os.Remove(activePackageModuleChange(p))
}
func (a *centralAPI) recoverPackageModuleChange(ctx context.Context, site string) (any, error) {
	e := a.backup.engine
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	a.subscriptionMu.Lock()
	defer a.subscriptionMu.Unlock()
	state, p, err := e.loadPackageState(site, true)
	if err != nil {
		return nil, err
	}
	status := a.packageModuleStatus(site)
	if status == nil || !packagePlanPattern.MatchString(status.PlanID) {
		return nil, errors.New("no package module recovery pending")
	}
	var j packageModuleJournal
	if err = decode(filepath.Join(a.packageModuleDir(status.PlanID), "journal.json"), &j); err != nil {
		return nil, err
	}
	if j.Plan.SiteID != site || j.Original.Request.SiteID != site || j.Original.Fingerprint != state.Fingerprint || j.Original.Fingerprint != fingerprint(j.Original.Request, j.Original.Release) {
		return nil, errors.New("package recovery identity mismatch")
	}
	if err = e.checkOwner(ctx, state, p); err != nil {
		return nil, err
	}
	if err = a.restorePackageModuleChange(ctx, &j, p); err != nil {
		return nil, err
	}
	return map[string]string{"site_id": site, "phase": j.Phase}, nil
}

// Interrupted decrypted verification/restoration stages are disposable; durable snapshots remain encrypted.
func (a *centralAPI) cleanupPackageModuleStages() error {
	root := filepath.Join(a.dir, "package-module-changes")
	info, err := os.Lstat(root)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return errors.New("unsafe package journal directory")
	}
	unlock, err := a.backup.engine.lock()
	if err != nil {
		return err
	}
	defer unlock()
	jobs, err := os.ReadDir(root)
	if err != nil {
		return err
	}
	for _, job := range jobs {
		if !job.IsDir() || !packagePlanPattern.MatchString(job.Name()) {
			continue
		}
		dir := filepath.Join(root, job.Name())
		entries, err := os.ReadDir(dir)
		if err != nil {
			return err
		}
		for _, entry := range entries {
			if !entry.IsDir() || (!strings.HasPrefix(entry.Name(), ".verify-encrypted-") && !strings.HasPrefix(entry.Name(), ".restore-encrypted-") && !strings.HasPrefix(entry.Name(), ".package-restore-")) {
				continue
			}
			path := filepath.Join(dir, entry.Name())
			var marker struct {
				Kind string `json:"kind"`
			}
			if decode(filepath.Join(path, ".gereq-update-stage.json"), &marker) == nil && marker.Kind == "temporary-update-stage" {
				if err = os.RemoveAll(path); err != nil {
					return err
				}
			}
		}
	}
	return nil
}
