package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// BackupConfig contains no credentials. The password file and provider credentials
// are supplied by the central service, independently of customer instances.
type BackupConfig struct {
	Schema       int    `json:"schema_version"`
	Repository   string `json:"repository"`
	PasswordFile string `json:"password_file,omitempty"`
	CredentialID string `json:"credential_id,omitempty"`
	secret       *BackupSecrets
}

func loadBackupConfig(path string) (BackupConfig, error) {
	var c BackupConfig
	if err := decode(path, &c); err != nil {
		return c, err
	}
	if c.Schema != 1 || (c.PasswordFile == "") == (c.CredentialID == "") || (c.PasswordFile != "" && !filepath.IsAbs(c.PasswordFile)) || (c.CredentialID != "" && !credentialIDPattern.MatchString(c.CredentialID)) {
		return c, errors.New("invalid backup configuration")
	}
	if !filepath.IsAbs(c.Repository) {
		if !strings.HasPrefix(c.Repository, "s3:") {
			return c, errors.New("repository must be an absolute local path or s3 URL")
		}
		u, err := url.Parse(strings.TrimPrefix(c.Repository, "s3:"))
		if err != nil || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.Scheme != "https" {
			return c, errors.New("invalid s3 repository URL")
		}
	}
	if c.CredentialID != "" {
		return c, nil
	}
	info, err := os.Lstat(c.PasswordFile)
	if err != nil {
		return c, errors.New("backup password file unavailable")
	}
	if !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 {
		return c, errors.New("backup password must be a private regular file")
	}
	b, err := os.ReadFile(c.PasswordFile)
	if err != nil || len(bytes.TrimSpace(b)) < 16 || len(b) > 4096 {
		return c, errors.New("backup password must contain at least 16 characters")
	}
	return c, nil
}
func runRestic(ctx context.Context, c BackupConfig, args ...string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, "restic", args...)
	// Do not let inherited RESTIC_* variables silently change the operation.
	env := []string{}
	for _, entry := range os.Environ() {
		k, _, _ := strings.Cut(entry, "=")
		if strings.HasPrefix(k, "RESTIC_") {
			continue
		}
		if c.CredentialID != "" && c.secret != nil && c.secret.AccessKey != "" && (k == "AWS_ACCESS_KEY_ID" || k == "AWS_SECRET_ACCESS_KEY" || k == "AWS_SESSION_TOKEN" || k == "AWS_PROFILE") {
			continue
		}
		env = append(env, entry)
	}
	cmd.Env = append(env, "RESTIC_REPOSITORY="+c.Repository)
	if c.CredentialID != "" {
		if c.secret == nil {
			return nil, errors.New("encrypted credentials require the backup API")
		}
		reader, writer, err := os.Pipe()
		if err != nil {
			return nil, err
		}
		defer reader.Close()
		if _, err = writer.WriteString(c.secret.Password); err != nil {
			writer.Close()
			return nil, err
		}
		if err = writer.Close(); err != nil {
			return nil, err
		}
		cmd.ExtraFiles = []*os.File{reader}
		cmd.Env = append(cmd.Env, "RESTIC_PASSWORD_FILE=/proc/self/fd/3")
		if c.secret.AccessKey != "" {
			cmd.Env = append(cmd.Env, "AWS_ACCESS_KEY_ID="+c.secret.AccessKey, "AWS_SECRET_ACCESS_KEY="+c.secret.SecretKey)
			if c.secret.SessionToken != "" {
				cmd.Env = append(cmd.Env, "AWS_SESSION_TOKEN="+c.secret.SessionToken)
			}
		}
	} else {
		cmd.Env = append(cmd.Env, "RESTIC_PASSWORD_FILE="+c.PasswordFile)
	}
	out, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("backup operation %s failed; inspect provider and repository configuration", args[0])
	}
	return out, nil
}

var backupSnapshotPattern = regexp.MustCompile(`^[a-f0-9]{64}$`)

func backupSQLiteCheck(ctx context.Context, path string) error {
	cmd := exec.CommandContext(ctx, "sqlite3", "-readonly", path, "PRAGMA integrity_check;")
	out, err := cmd.Output()
	if err != nil || strings.TrimSpace(string(out)) != "ok" {
		return errors.New("backup SQLite integrity check failed")
	}
	return nil
}
func (e Engine) BackupCreate(ctx context.Context, site string, c BackupConfig) (map[string]any, error) {
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	s, p, err := e.load(site)
	if err != nil {
		return nil, err
	}
	if s.RelocatedTo != "" {
		return nil, errors.New("instance has been relocated")
	}
	if err = e.checkOwner(ctx, s, p); err != nil {
		return nil, err
	}
	running, err := e.Runner.Run(ctx, []string{"ps", "--filter", "name=^" + projectName(site, p) + "-app$", "--format", "{{.ID}}"})
	if err != nil {
		return nil, err
	}
	isRunning := strings.TrimSpace(string(running)) != ""
	if !isRunning && s.Status != "stopped" {
		return nil, errors.New("instance runtime is unexpectedly stopped")
	}
	if isRunning && s.Status == "stopped" {
		return nil, errors.New("instance state does not match runtime")
	}
	config, err := os.ReadFile(filepath.Join(p, "compose.json"))
	if err != nil {
		return nil, err
	}
	if err = checkUpdateConfig(config, s, p); err != nil {
		return nil, err
	}

	backupStorage := filepath.Join(p, "storage")
	if isRunning {
		stagingRoot := filepath.Join(e.Root, "backup-staging")
		if err = privateBackupDir(stagingRoot); err != nil {
			return nil, err
		}
		stage, stageErr := os.MkdirTemp(stagingRoot, "snapshot-")
		if stageErr != nil {
			return nil, stageErr
		}
		defer os.RemoveAll(stage)
		if err = atomicJSON(filepath.Join(stage, ".gereq-backup-stage.json"), map[string]string{"site_id": site, "kind": "backup-storage"}); err != nil {
			return nil, err
		}
		marker := filepath.Join(p, ".backup-active.json")
		if err = atomicJSON(marker, map[string]string{"site_id": site, "fingerprint": s.Fingerprint}); err != nil {
			return nil, err
		}
		stopErr := func() error {
			if _, err = e.Runner.Run(ctx, compose(s, p, "stop")); err != nil {
				return err
			}
			if _, err = e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "storage-check")); err != nil {
				return err
			}
			if err = copyVerified(filepath.Join(p, "storage"), filepath.Join(stage, "storage")); err != nil {
				return err
			}
			return backupSQLiteCheck(ctx, filepath.Join(stage, "storage", "database.sqlite"))
		}()
		// Always attempt to resume, even if cancellation interrupted the snapshot.
		recoveryCtx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		restartErr := e.step(recoveryCtx, s, p, "health")
		cancel()
		if restartErr != nil {
			return nil, errors.New("backup could not restart the instance; use backup-recover")
		}
		if err = os.Remove(marker); err != nil {
			return nil, errors.New("instance restarted but backup marker could not be cleared; use backup-recover")
		}
		if stopErr != nil {
			return nil, fmt.Errorf("backup snapshot failed after instance restart: %w", stopErr)
		}
		backupStorage = filepath.Join(stage, "storage")
	} else {
		if _, err = treeManifest(backupStorage); err != nil {
			return nil, err
		}
		if err = backupSQLiteCheck(ctx, filepath.Join(backupStorage, "database.sqlite")); err != nil {
			return nil, err
		}
	}
	// The encrypted repository receives a consistent storage copy and instance metadata.
	out, err := runRestic(ctx, c, "backup", "--json", "--host", "gereq-saas", "--tag", "gereq-site-"+site, backupStorage, filepath.Join(p, ".env"), filepath.Join(p, "state.json"), filepath.Join(p, "compose.json"))
	if err != nil {
		return nil, err
	}

	var id string
	for _, line := range bytes.Split(out, []byte("\n")) {
		var v struct {
			MessageType string `json:"message_type"`
			SnapshotID  string `json:"snapshot_id"`
		}
		if json.Unmarshal(line, &v) == nil && v.MessageType == "summary" {
			id = v.SnapshotID
		}
	}
	if !backupSnapshotPattern.MatchString(id) {
		return nil, errors.New("backup completed but snapshot ID was unavailable; inspect repository")
	}
	return map[string]any{"site_id": site, "snapshot_id": id}, nil
}
func (e Engine) BackupVerify(ctx context.Context, site, id string, c BackupConfig, export bool) (map[string]any, error) {
	if !sitePattern.MatchString(site) || !backupSnapshotPattern.MatchString(id) {
		return nil, errors.New("invalid site or snapshot identifier")
	}
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	out, err := runRestic(ctx, c, "snapshots", "--json", "--host", "gereq-saas", "--tag", "gereq-site-"+site)
	if err != nil {
		return nil, err
	}
	var snapshots []struct {
		ID    string   `json:"id"`
		Paths []string `json:"paths"`
	}
	if json.Unmarshal(out, &snapshots) != nil {
		return nil, errors.New("invalid snapshot list")
	}
	found := false
	var original string
	var metadataDir string
	for _, v := range snapshots {
		if v.ID == id {
			found = true
			for _, path := range v.Paths {
				if filepath.IsAbs(path) && filepath.Clean(path) == path && filepath.Base(path) == "storage" {
					original = filepath.Dir(path)
				}
				if filepath.IsAbs(path) && filepath.Clean(path) == path && filepath.Base(path) == "state.json" {
					metadataDir = filepath.Dir(path)
				}
			}
		}
	}
	if !found || original == "" || metadataDir == "" {
		return nil, errors.New("snapshot does not belong to this site or has no storage")
	}
	tmpRoot := filepath.Join(e.Root, "backup-verification")
	if err = privateBackupDir(tmpRoot); err != nil {
		return nil, err
	}
	tmp, err := os.MkdirTemp(tmpRoot, "verify-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(tmp)
	if err = atomicJSON(filepath.Join(tmp, ".gereq-backup-verify.json"), map[string]string{"site_id": site, "kind": "backup-verification"}); err != nil {
		return nil, err
	}
	if _, err = runRestic(ctx, c, "restore", id, "--target", tmp, "--verify"); err != nil {
		return nil, err
	}
	restored := filepath.Join(tmp, strings.TrimPrefix(metadataDir, string(filepath.Separator)))
	storageRestored := filepath.Join(tmp, strings.TrimPrefix(original, string(filepath.Separator)), "storage")
	if filepath.Dir(storageRestored) != restored {
		if err = os.Rename(storageRestored, filepath.Join(restored, "storage")); err != nil {
			return nil, errors.New("restored storage could not be assembled")
		}
	}
	var state State
	if err = decode(filepath.Join(restored, "state.json"), &state); err != nil {
		return nil, errors.New("restored state missing or invalid")
	}
	if state.Request.SiteID != site || state.Fingerprint != fingerprint(state.Request, state.Release) {
		return nil, errors.New("restored state ownership mismatch")
	}
	for _, name := range []string{".env", "compose.json"} {
		info, err := os.Lstat(filepath.Join(restored, name))
		if err != nil || !info.Mode().IsRegular() {
			return nil, errors.New("restored instance configuration missing")
		}
	}
	if _, err = treeManifest(filepath.Join(restored, "storage")); err != nil {
		return nil, err
	}
	if err = backupSQLiteCheck(ctx, filepath.Join(restored, "storage", "database.sqlite")); err != nil {
		return nil, err
	}
	result := map[string]any{"site_id": site, "snapshot_id": id, "verified": true}
	if export {
		parent := filepath.Join(e.Root, "backup-restores", site)
		for q := parent; q != filepath.Dir(q); q = filepath.Dir(q) {
			info, statErr := os.Lstat(q)
			if statErr == nil && info.Mode()&os.ModeSymlink != 0 {
				return nil, errors.New("symlink in restore path")
			}
			if statErr != nil && !os.IsNotExist(statErr) {
				return nil, statErr
			}
		}
		if err = privateBackupDir(filepath.Dir(parent)); err != nil {
			return nil, err
		}
		if err = privateBackupDir(parent); err != nil {
			return nil, err
		}
		dest := filepath.Join(parent, id)
		if _, err = os.Lstat(dest); !os.IsNotExist(err) {
			return nil, errors.New("restore destination already exists or is inaccessible")
		}
		if err = os.Rename(restored, dest); err != nil {
			return nil, err
		}
		result["restore_path"] = dest
	}
	return result, nil
}

func privateBackupDir(path string) error {
	info, err := os.Lstat(path)
	if os.IsNotExist(err) {
		if err = os.Mkdir(path, 0700); err != nil {
			return err
		}
		info, err = os.Lstat(path)
	}
	if err != nil {
		return err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm()&0077 != 0 {
		return errors.New("backup working directory must be private and not a symlink")
	}
	return nil
}

// BackupRecover resumes an instance left in the backup stop window by a crash.
func (e Engine) BackupRecover(ctx context.Context, site string) (map[string]any, error) {
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	p, err := ownedDir(e.Root, site)
	if err != nil {
		return nil, err
	}
	var marker struct {
		Site        string `json:"site_id"`
		Fingerprint string `json:"fingerprint"`
	}
	if err = decode(filepath.Join(p, ".backup-active.json"), &marker); err != nil {
		return nil, errors.New("no backup recovery marker")
	}
	var state State
	if err = decode(filepath.Join(p, "state.json"), &state); err != nil {
		return nil, err
	}
	if marker.Site != site || state.Request.SiteID != site || marker.Fingerprint != state.Fingerprint || state.Fingerprint != fingerprint(state.Request, state.Release) || state.Runtime != e.runtimeName() {
		return nil, errors.New("backup recovery ownership mismatch")
	}
	cfg, err := os.ReadFile(filepath.Join(p, "compose.json"))
	if err != nil {
		return nil, err
	}
	if err = checkUpdateConfig(cfg, &state, p); err != nil {
		return nil, err
	}
	if err = e.step(ctx, &state, p, "health"); err != nil {
		return nil, errors.New("backup recovery could not restart the instance")
	}
	if err = os.Remove(filepath.Join(p, ".backup-active.json")); err != nil {
		return nil, err
	}
	return map[string]any{"site_id": site, "recovered": true}, nil
}

// cleanupBackupStages removes only provisioner-owned disposable copies, under the
// registry lock. Unrecognized entries are deliberately left untouched.
func (e Engine) cleanupBackupStages() (int, error) {
	root := filepath.Join(e.Root, "backup-staging")
	info, err := os.Lstat(root)
	if os.IsNotExist(err) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm()&0077 != 0 {
		return 0, errors.New("backup staging directory is not private")
	}
	unlock, err := e.lock()
	if err != nil {
		return 0, err
	}
	defer unlock()
	entries, err := os.ReadDir(root)
	if err != nil {
		return 0, err
	}
	removed := 0
	for _, entry := range entries {
		if !entry.IsDir() || !strings.HasPrefix(entry.Name(), "snapshot-") {
			continue
		}
		path := filepath.Join(root, entry.Name())
		var marker struct {
			Site string `json:"site_id"`
			Kind string `json:"kind"`
		}
		if decode(filepath.Join(path, ".gereq-backup-stage.json"), &marker) != nil || !sitePattern.MatchString(marker.Site) || marker.Kind != "backup-storage" {
			continue
		}
		if err = os.RemoveAll(path); err != nil {
			return removed, err
		}
		removed++
	}
	return removed, nil
}

func (e Engine) cleanupBackupVerification() (int, error) {
	root := filepath.Join(e.Root, "backup-verification")
	info, err := os.Lstat(root)
	if os.IsNotExist(err) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm()&0077 != 0 {
		return 0, errors.New("backup verification directory is not private")
	}
	unlock, err := e.lock()
	if err != nil {
		return 0, err
	}
	defer unlock()
	entries, err := os.ReadDir(root)
	if err != nil {
		return 0, err
	}
	count := 0
	for _, entry := range entries {
		if !entry.IsDir() || !strings.HasPrefix(entry.Name(), "verify-") {
			continue
		}
		path := filepath.Join(root, entry.Name())
		var m struct {
			Site string `json:"site_id"`
			Kind string `json:"kind"`
		}
		if decode(filepath.Join(path, ".gereq-backup-verify.json"), &m) != nil || !sitePattern.MatchString(m.Site) || m.Kind != "backup-verification" {
			continue
		}
		if err = os.RemoveAll(path); err != nil {
			return count, err
		}
		count++
	}
	return count, nil
}
