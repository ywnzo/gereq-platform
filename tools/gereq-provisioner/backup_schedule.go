package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

type backupPolicy struct {
	Enabled         bool      `json:"enabled"`
	IntervalSeconds int       `json:"interval_seconds"`
	KeepLast        int       `json:"keep_last"`
	NextRun         time.Time `json:"next_run"`
}
type verifiedBackup struct {
	Site           string    `json:"site_id"`
	RepositoryHash string    `json:"repository_hash"`
	Snapshot       string    `json:"snapshot_id"`
	VerifiedAt     time.Time `json:"verified_at"`
}

func repositoryHash(s string) string { h := sha256.Sum256([]byte(s)); return hex.EncodeToString(h[:]) }
func (a *backupAPI) scheduleDir() (string, error) {
	p := filepath.Join(a.dir, "schedules")
	if err := privateBackupDir(p); err != nil {
		return "", err
	}
	return p, nil
}
func (a *backupAPI) verifiedDir() (string, error) {
	p := filepath.Join(a.dir, "verified")
	if err := privateBackupDir(p); err != nil {
		return "", err
	}
	return p, nil
}
func (a *backupAPI) saveVerified(site, id string, c BackupConfig) error {
	dir, err := a.verifiedDir()
	if err != nil {
		return err
	}
	entry := verifiedBackup{Site: site, Snapshot: id, RepositoryHash: repositoryHash(c.Repository), VerifiedAt: time.Now().UTC()}
	return atomicJSON(filepath.Join(dir, id+".json"), entry)
}
func (a *backupAPI) applyRetention(ctx context.Context, site string, c BackupConfig, keep int) (int, error) {
	if keep < 2 || keep > 1000 {
		return 0, errors.New("retention must keep at least two verified backups")
	}
	out, err := runRestic(ctx, c, "snapshots", "--json", "--host", "gereq-saas", "--tag", "gereq-site-"+site)
	if err != nil {
		return 0, err
	}
	var snapshots []struct {
		ID   string    `json:"id"`
		Time time.Time `json:"time"`
	}
	if json.Unmarshal(out, &snapshots) != nil {
		return 0, errors.New("invalid snapshot listing")
	}
	dir, err := a.verifiedDir()
	if err != nil {
		return 0, err
	}
	verified := make([]struct {
		ID   string
		Time time.Time
	}, 0)
	for _, s := range snapshots {
		if !backupSnapshotPattern.MatchString(s.ID) {
			continue
		}
		var v verifiedBackup
		if decode(filepath.Join(dir, s.ID+".json"), &v) == nil && v.Site == site && v.Snapshot == s.ID && v.RepositoryHash == repositoryHash(c.Repository) {
			verified = append(verified, struct {
				ID   string
				Time time.Time
			}{s.ID, s.Time})
		}
	}
	sort.Slice(verified, func(i, j int) bool { return verified[i].Time.After(verified[j].Time) })
	if len(verified) <= keep {
		return 0, nil
	}
	args := []string{"forget"}
	for _, v := range verified[keep:] {
		args = append(args, v.ID)
	}
	if _, err = runRestic(ctx, c, args...); err != nil {
		return 0, err
	}
	return len(verified) - keep, nil
}
func (a *backupAPI) runScheduled(ctx context.Context, r backupAPIRequest, c BackupConfig) (any, error) {
	result, err := a.engine.BackupCreate(ctx, r.Site, c)
	if err != nil {
		return nil, err
	}
	id, ok := result["snapshot_id"].(string)
	if !ok {
		return result, errors.New("backup snapshot ID unavailable")
	}
	if _, err = a.engine.BackupVerify(ctx, r.Site, id, c, false); err != nil {
		return result, fmt.Errorf("snapshot created but verification failed: %w", err)
	}
	if err = a.saveVerified(r.Site, id, c); err != nil {
		return result, errors.New("snapshot verified but verification record could not be saved")
	}
	count, err := a.applyRetention(ctx, r.Site, c, r.KeepLast)
	result["verified"] = true
	result["forgotten_verified_snapshots"] = count
	if err != nil {
		return result, fmt.Errorf("backup verified but retention failed: %w", err)
	}
	return result, nil
}
func (a *backupAPI) processSchedules() {
	a.mu.Lock()
	dir, err := a.scheduleDir()
	if err != nil {
		a.mu.Unlock()
		return
	}
	entries, err := os.ReadDir(dir)
	a.mu.Unlock()
	if err != nil {
		return
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		site := strings.TrimSuffix(entry.Name(), ".json")
		if !sitePattern.MatchString(site) {
			continue
		}
		a.mu.Lock()
		var policy backupPolicy
		err = decode(filepath.Join(dir, entry.Name()), &policy)
		a.mu.Unlock()
		if err != nil || !policy.Enabled || policy.NextRun.After(time.Now().UTC()) {
			continue
		}
		request := backupAPIRequest{Operation: "scheduled", Site: site, KeepLast: policy.KeepLast}
		body, _ := json.Marshal(request)
		keyHash := sha256.Sum256([]byte(site + "/" + policy.NextRun.UTC().Format(time.RFC3339Nano)))
		key := "schedule-" + hex.EncodeToString(keyHash[:])
		httpReq, _ := http.NewRequest("POST", "http://local/v1/backup/jobs", bytes.NewReader(body))
		httpReq.Header.Set("Content-Type", "application/json")
		httpReq.Header.Set("Idempotency-Key", key)
		w := httptest.NewRecorder()
		a.ServeHTTP(w, httpReq)
		if w.Code != http.StatusAccepted && w.Code != http.StatusOK {
			continue
		}
		a.mu.Lock()
		var latest backupPolicy
		if decode(filepath.Join(dir, entry.Name()), &latest) == nil && latest.Enabled && latest.NextRun.Equal(policy.NextRun) {
			latest.NextRun = time.Now().UTC().Add(time.Duration(latest.IntervalSeconds) * time.Second)
			_ = atomicJSON(filepath.Join(dir, entry.Name()), latest)
		}
		a.mu.Unlock()
	}
}
func (a *backupAPI) startSchedules(ctx context.Context) {
	ticker := time.NewTicker(15 * time.Second)
	defer ticker.Stop()
	a.processSchedules()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			a.processSchedules()
		}
	}
}
