package main

import (
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

type managedUpdate struct {
	ID             string `json:"id"`
	Phase          string `json:"phase"`
	FromRelease    string `json:"from_release"`
	ToRelease      string `json:"to_release"`
	BackupReady    bool   `json:"backup_ready"`
	WritesPossible bool   `json:"writes_possible"`
	Active         bool   `json:"active"`
	CanRecover     bool   `json:"can_recover"`
	CanRollback    bool   `json:"can_rollback"`
	Error          string `json:"error,omitempty"`
}

// Read atomic journal snapshots without the registry lock, including while an update owns it.
func (e Engine) managedUpdateStatus(site string) (*managedUpdate, error) {
	p, err := ownedDir(e.Root, site)
	if err != nil {
		return nil, err
	}
	var state State
	if err = decode(filepath.Join(p, "state.json"), &state); err != nil || state.Request.SiteID != site {
		return nil, errors.New("invalid instance state")
	}
	id := state.LastUpdateID
	active := false
	var marker struct {
		ID string `json:"id"`
	}
	if err = decode(activeUpdate(p), &marker); err == nil {
		id = marker.ID
		active = true
	} else if !os.IsNotExist(err) {
		return nil, err
	}
	if !active {
		paths, _ := filepath.Glob(filepath.Join(e.Root, "updates", site, "upd-*", "journal.json"))
		var latestTime int64
		for _, path := range paths {
			info, err := os.Stat(path)
			if err == nil && info.ModTime().UnixNano() > latestTime {
				latestTime = info.ModTime().UnixNano()
				id = filepath.Base(filepath.Dir(path))
			}
		}
	}
	if id == "" {
		return nil, nil
	}
	dir, err := e.updateDir(site, id)
	if err != nil {
		return nil, err
	}
	var j UpdateJournal
	if err = decode(filepath.Join(dir, "journal.json"), &j); err != nil {
		return nil, err
	}
	if j.Schema != 1 || j.ID != id || j.Site != site {
		return nil, errors.New("invalid update journal")
	}
	result := &managedUpdate{ID: id, Phase: j.Phase, FromRelease: j.Original.Release.ID, ToRelease: j.Target.Release.ID, BackupReady: j.BackupReady, WritesPossible: j.Started, Active: active, CanRecover: active, CanRollback: !active && j.Phase == "complete" && j.BackupReady && state.Fingerprint == j.Target.Fingerprint && state.LastUpdateID == id}
	if j.Error != "" {
		result.Error = "Update did not complete. Review its phase and use recovery."
	}
	return result, nil
}
func (a *centralAPI) managementUpdatesHTTP(w http.ResponseWriter, r *http.Request, parts []string) bool {
	if r.Method == "GET" && len(parts) == 1 && parts[0] == "releases" {
		releases, err := a.registry.list(false)
		if err != nil {
			apiError(w, 503, "release registry unavailable")
		} else {
			apiJSON(w, 200, map[string]any{"releases": releases})
		}
		return true
	}
	if len(parts) != 3 || parts[0] != "instances" || !sitePattern.MatchString(parts[1]) {
		return false
	}
	site, op := parts[1], parts[2]
	if r.Method == "GET" && op == "updates" {

		status, err := a.backup.engine.managedUpdateStatus(site)
		if err != nil {
			apiError(w, 409, "update status unavailable")
		} else {
			apiJSON(w, 200, map[string]any{"update": status})
		}
		return true
	}
	if r.Method != "POST" || !contains([]string{"update-plan", "update", "update-recover", "update-rollback"}, op) {
		return false
	}
	key := r.Header.Get("Idempotency-Key")
	if !apiKeyPattern.MatchString(key) || len(key) > 100 {
		apiError(w, 400, "valid Idempotency-Key required")
		return true
	}
	var input struct {
		ReleaseID       string `json:"release_id,omitempty"`
		ExpectedImageID string `json:"expected_image_id,omitempty"`
		CurrentRelease  string `json:"current_release,omitempty"`
		UpdateID        string `json:"update_id,omitempty"`
		AcceptDataLoss  bool   `json:"accept_data_loss,omitempty"`
	}
	if !parseAPIJSON(w, r, &input) {
		return true
	}
	if op == "update-plan" || op == "update" {
		if input.UpdateID != "" || input.AcceptDataLoss || (op == "update-plan" && (input.ExpectedImageID != "" || input.CurrentRelease != "")) {
			apiError(w, 400, "invalid update options")
			return true
		}
		release, err := a.release(input.ReleaseID)
		if err != nil {
			apiError(w, 400, "select an approved release")
			return true
		}
		if op == "update" {
			if input.CurrentRelease == "" {
				apiError(w, 400, "source release required")
				return true
			}
			a.dispatchManagement(w, r, "/v1/central/jobs", centralRequest{Operation: "update", SiteID: site, ReleaseID: release.ID, ExpectedImageID: input.ExpectedImageID, ExpectedRelease: input.CurrentRelease}, false)
			return true
		}
		a.backup.mu.Lock()
		if a.backup.active {
			a.backup.mu.Unlock()
			apiError(w, 409, "another job is running")
			return true
		}
		a.backup.active = true
		a.backup.workers.Add(1)
		a.backup.mu.Unlock()
		defer func() { a.backup.mu.Lock(); a.backup.active = false; a.backup.mu.Unlock(); a.backup.workers.Done() }()
		plan, err := a.backup.engine.PlanUpdate(r.Context(), site, *release)
		if err != nil {
			apiError(w, 409, "update plan rejected: "+err.Error())
			return true
		}
		apiJSON(w, 200, plan)
		return true
	}
	if input.ReleaseID != "" || input.ExpectedImageID != "" || input.CurrentRelease != "" {
		apiError(w, 400, "invalid recovery options")
		return true
	}
	// An accepted recovery remains replayable after its journal has left the active state.
	replayInput := centralRequest{Operation: op, SiteID: site, UpdateID: input.UpdateID, AcceptDataLoss: input.AcceptDataLoss}
	if a.replayManagedUpdate(w, r, replayInput) {
		return true
	}
	status, err := a.backup.engine.managedUpdateStatus(site)
	if err != nil || status == nil {
		apiError(w, 409, "no update available")
		return true
	}
	if op == "update-recover" {
		if !status.CanRecover || input.UpdateID != "" || (status.WritesPossible && !input.AcceptDataLoss) {
			apiError(w, 409, "recovery requires an active update and acknowledgement of later writes")
			return true
		}
	} else if !status.CanRollback || input.UpdateID != status.ID || !input.AcceptDataLoss {
		apiError(w, 409, "rollback requires the latest completed update and data-loss acknowledgement")
		return true
	}
	a.dispatchManagement(w, r, "/v1/central/jobs", centralRequest{Operation: op, SiteID: site, UpdateID: input.UpdateID, AcceptDataLoss: input.AcceptDataLoss}, false)
	return true
}

func (a *centralAPI) replayManagedUpdate(w http.ResponseWriter, r *http.Request, input centralRequest) bool {
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	entries, err := os.ReadDir(filepath.Join(a.dir, "jobs"))
	if err != nil {
		apiError(w, 503, "job storage unavailable")
		return true
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var old centralJob
		if decode(filepath.Join(a.dir, "jobs", entry.Name()), &old) != nil {
			apiError(w, 503, "job storage invalid")
			return true
		}
		if old.IdempotencyKey == "management-"+r.Header.Get("Idempotency-Key") {
			if old.PayloadHash != requestHash(input) {
				apiError(w, 409, "idempotency key already used for another request")
				return true
			}
			apiJSON(w, 200, map[string]any{"kind": "action", "job": safeManagedAction(old)})
			return true
		}
	}
	return false
}
