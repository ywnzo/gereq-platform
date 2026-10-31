package main

import (
	"errors"
	"net/http"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
)

// Entitlements are central metadata; instance ownership fingerprints remain immutable.
func subscriptionPackageID(state *State, sub *subscription) string {
	if sub != nil && sub.Package != nil {
		return sub.Package.ID
	}
	if state.Request.CatalogPackageID != "" {
		return state.Request.CatalogPackageID
	}
	return state.Request.Package
}

type packageChangePlan struct {
	SourceModules        []string       `json:"source_modules,omitempty"`
	TargetModules        []string       `json:"target_modules,omitempty"`
	ModuleChange         bool           `json:"module_change,omitempty"`
	ID                   string         `json:"id"`
	SiteID               string         `json:"site_id"`
	SourcePackage        string         `json:"source_package"`
	SubscriptionRevision int            `json:"subscription_revision"`
	Fingerprint          string         `json:"fingerprint"`
	Target               catalogPackage `json:"target"`
	GenerationUsed       int            `json:"generation_used"`
	ExpiresAt            time.Time      `json:"expires_at"`
}

func (a *centralAPI) generationUsed(site string) (int, error) {
	db, err := a.subscriptions.db.open()
	if err != nil {
		return 0, err
	}
	defer db.Close()
	var n int
	err = db.QueryRow("SELECT COUNT(*) FROM generation_usage WHERE site_id=?", site).Scan(&n)
	return n, err
}

// Failed attempts count. Replaying the same worker does not consume another allowance.
func (a *centralAPI) reserveGeneration(site, key string) error {
	a.subscriptionMu.Lock()
	defer a.subscriptionMu.Unlock()
	sub, err := a.subscriptions.get(site)
	if err != nil {
		return err
	}
	if key == "" {
		key, err = randomSecret()
		if err != nil {
			return err
		}
	}
	db, err := a.subscriptions.db.open()
	if err != nil {
		return err
	}
	defer db.Close()
	tx, err := db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	var previous, count int
	if err = tx.QueryRow("SELECT COUNT(*) FROM generation_usage WHERE site_id=? AND request_id=?", site, key).Scan(&previous); err != nil {
		return err
	}
	if previous > 0 {
		return nil
	}
	if err = tx.QueryRow("SELECT COUNT(*) FROM generation_usage WHERE site_id=?", site).Scan(&count); err != nil {
		return err
	}
	if sub != nil && sub.Package != nil && sub.Package.GenerationLimit != nil && count >= *sub.Package.GenerationLimit {
		return errors.New("package generation limit reached")
	}
	if _, err = tx.Exec("INSERT INTO generation_usage VALUES(?,?,?)", site, key, a.subscriptionNow().Format(time.RFC3339Nano)); err != nil {
		return err
	}
	return tx.Commit()
}
func (a *centralAPI) packageTarget(state *State, id string) (catalogPackage, error) {
	c, err := a.loadCatalog()
	if err != nil {
		return catalogPackage{}, err
	}
	p, ok := c.Packages[id]
	if !ok || !p.Active || p.Archived {
		return p, errors.New("target package unavailable")
	}
	if err = validateCatalogPackage(p, c); err != nil {
		return p, err
	}
	candidateModules := p.Modules
	if len(candidateModules) == 0 {
		candidateModules = state.Release.Modules
	}
	if p.RuntimePackage != state.Request.Package {
		return p, errors.New("target runtime mismatch")
	}
	candidate := state.Request
	candidate.EnabledModules = candidateModules
	if err := validateSelectedModules(candidate, state.Release); err != nil {
		return p, err
	}
	if !sameModules(candidateModules, instanceModules(state)) && !state.Release.ModuleSelection {
		return p, errors.New("update the customer image before changing modules")
	}

	return p, nil
}

// Package changes share exclusion with provisioning, updates and catalog writes.
func (a *centralAPI) packageHTTP(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/v1/package-changes/") {
		return false
	}
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/package-changes/"), "/")
	if len(parts) != 2 || !sitePattern.MatchString(parts[0]) || !contains([]string{"preview", "apply", "options", "recover"}, parts[1]) || r.URL.RawQuery != "" {
		apiError(w, 404, "not found")
		return true
	}
	site, op := parts[0], parts[1]
	if (op == "options" && r.Method != "GET") || (op != "options" && r.Method != "POST") {
		apiError(w, 405, "method not allowed")
		return true
	}
	var input struct {
		PackageID string `json:"package_id,omitempty"`
		PlanID    string `json:"plan_id,omitempty"`
	}
	if op != "options" && !parseAPIJSON(w, r, &input) {
		return true
	}
	if (op == "preview" && (input.PackageID == "" || input.PlanID != "")) || (op == "apply" && (input.PackageID != "" || !regexp.MustCompile(`^pkg-[a-f0-9]{24}$`).MatchString(input.PlanID))) {
		apiError(w, 400, "invalid package change options")
		return true
	}
	if op == "recover" {
		if input.PackageID != "" || input.PlanID != "" || !apiKeyPattern.MatchString(r.Header.Get("Idempotency-Key")) {
			apiError(w, 400, "invalid package recovery request")
			return true
		}
		a.dispatchManagement(w, r, "/v1/central/jobs", centralRequest{Operation: "package-recover", SiteID: site}, false)
		return true
	}
	if op == "apply" {
		if !apiKeyPattern.MatchString(r.Header.Get("Idempotency-Key")) {
			apiError(w, 400, "valid Idempotency-Key required")
			return true
		}
		var plan packageChangePlan
		if err := decode(filepath.Join(a.dir, "package-plans", input.PlanID+".json"), &plan); err == nil && plan.SiteID == site && plan.ID == input.PlanID && plan.ModuleChange {
			a.dispatchManagement(w, r, "/v1/central/jobs", centralRequest{Operation: "package-change", SiteID: site, PackagePlanID: input.PlanID}, false)
			return true
		}
	}
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	if a.backup.active {
		apiError(w, 409, "another job is running")
		return true
	}
	unlock, err := a.backup.engine.lock()
	if err != nil {
		apiError(w, 409, "provisioner busy")
		return true
	}
	defer unlock()
	a.subscriptionMu.Lock()
	defer a.subscriptionMu.Unlock()
	state, _, err := a.backup.engine.load(site)
	if err != nil {
		apiError(w, 409, "instance requires recovery")
		return true
	}
	sub, err := a.subscriptions.get(site)
	if err != nil || sub == nil {
		apiError(w, 409, "subscription enrollment required")
		return true
	}
	if sub.CustomerRef != state.Request.CustomerRef || sub.PackageID != subscriptionPackageID(state, sub) {
		apiError(w, 409, "subscription identity mismatch")
		return true
	}
	used, err := a.generationUsed(site)
	if err != nil {
		apiError(w, 503, "generation usage unavailable")
		return true
	}
	if op == "options" {
		c, err := a.loadCatalog()
		if err != nil {
			apiError(w, 503, "catalog unavailable")
			return true
		}
		choices := []catalogPackage{}
		for id := range c.Packages {
			if p, err := a.packageTarget(state, id); err == nil {
				choices = append(choices, p)
			}
		}
		sort.Slice(choices, func(i, j int) bool { return choices[i].ID < choices[j].ID })
		apiJSON(w, 200, map[string]any{"packages": choices, "current": sub.PackageID, "generation_used": used, "current_package": sub.Package})
		return true
	}
	dir := filepath.Join(a.dir, "package-plans")
	if err = privateBackupDir(dir); err != nil {
		apiError(w, 503, "package plans unavailable")
		return true
	}
	if op == "preview" {
		p, err := a.packageTarget(state, input.PackageID)
		if err != nil {
			apiError(w, 409, err.Error())
			return true
		}
		if sub.PackageID == p.ID && sub.PackageRevision == p.Revision {
			apiError(w, 409, "package already assigned")
			return true
		}
		if p.GenerationLimit != nil && used > *p.GenerationLimit {
			apiError(w, 409, "target generation limit is below current usage")
			return true
		}
		token, err := randomSecret()
		if err != nil {
			apiError(w, 500, "could not create plan")
			return true
		}
		plan := packageChangePlan{SourceModules: instanceModules(state), TargetModules: packageModules(p, state), ModuleChange: !sameModules(instanceModules(state), packageModules(p, state)), ID: "pkg-" + token[:24], SiteID: site, SourcePackage: sub.PackageID, SubscriptionRevision: sub.Revision, Fingerprint: state.Fingerprint, Target: p, GenerationUsed: used, ExpiresAt: a.subscriptionNow().Add(30 * time.Minute)}
		if err = atomicJSON(filepath.Join(dir, plan.ID+".json"), plan); err != nil {
			apiError(w, 503, "could not save package plan")
			return true
		}
		apiJSON(w, 200, plan)
		return true
	}
	key := r.Header.Get("Idempotency-Key")
	if !apiKeyPattern.MatchString(key) {
		apiError(w, 400, "valid Idempotency-Key required")
		return true
	}
	var plan packageChangePlan
	if err = decode(filepath.Join(dir, input.PlanID+".json"), &plan); err != nil || plan.ID != input.PlanID || plan.SiteID != site {
		apiError(w, 404, "package plan not found")
		return true
	}
	next, _, err := a.subscriptions.mutate(site, "package:"+key, registryHash(input), "package-changed", a.subscriptionNow(), func(current *subscription) (*simulatedPayment, error) {
		if !a.subscriptionNow().Before(plan.ExpiresAt) || current.Revision != plan.SubscriptionRevision || state.Fingerprint != plan.Fingerprint || used != plan.GenerationUsed {
			return nil, errors.New("package plan is stale; preview again")
		}
		target, err := a.packageTarget(state, plan.Target.ID)
		if err != nil {
			return nil, err
		}
		if registryHash(target) != registryHash(plan.Target) {
			return nil, errors.New("target package changed; preview again")
		}
		if target.GenerationLimit != nil && used > *target.GenerationLimit {
			return nil, errors.New("target generation limit is below current usage")
		}
		current.Package = &target
		current.PackageID = target.ID
		current.PackageRevision = target.Revision
		return nil, nil
	})
	if err != nil {
		apiError(w, 409, err.Error())
		return true
	}
	apiJSON(w, 200, next.view(a.subscriptionNow()))
	return true
}
