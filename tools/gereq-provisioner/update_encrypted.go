package main

import (
	"bytes"
	"context"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"time"
)

// updateVaultConfig derives a separate restic password from the service master
// key. The key itself never appears in command arguments or persisted metadata.
func updateVaultConfig(job string) (BackupConfig, error) {
	key, err := (&backupAPI{}).vaultKey()
	if err != nil {
		return BackupConfig{}, err
	}
	return BackupConfig{Schema: 1, Repository: filepath.Join(job, "encrypted-repository"), CredentialID: "cred-000000000000000000000000", secret: &BackupSecrets{Password: hex.EncodeToString(key)}}, nil
}
func createEncryptedUpdateRepository(ctx context.Context, job string) (BackupConfig, error) {
	c, err := updateVaultConfig(job)
	if err != nil {
		return c, err
	}
	if _, err = runRestic(ctx, c, "init"); err != nil {
		return c, err
	}
	return c, nil
}
func updateTreeDirectories(root string) ([]string, error) {
	result := []string{}
	err := filepath.WalkDir(root, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if entry.IsDir() {
			rel, err := filepath.Rel(root, path)
			if err != nil {
				return err
			}
			result = append(result, rel)
		}
		return nil
	})
	return result, err
}
func encryptUpdateDirectory(ctx context.Context, c BackupConfig, source string) (string, error) {
	expected, err := treeManifest(source)
	if err != nil {
		return "", err
	}
	dirs, err := updateTreeDirectories(source)
	if err != nil {
		return "", err
	}
	out, err := runRestic(ctx, c, "backup", "--json", source)
	if err != nil {
		return "", err
	}
	var id string
	for _, line := range bytes.Split(out, []byte("\n")) {
		var v struct {
			Type string `json:"message_type"`
			ID   string `json:"snapshot_id"`
		}
		if json.Unmarshal(line, &v) == nil && v.Type == "summary" {
			id = v.ID
		}
	}
	if !backupSnapshotPattern.MatchString(id) {
		return "", errors.New("encrypted update snapshot ID missing")
	}
	temp, err := newUpdateTemp(filepath.Dir(c.Repository), ".verify-encrypted-")
	if err != nil {
		return "", err
	}
	defer os.RemoveAll(temp)
	if _, err = runRestic(ctx, c, "restore", id, "--verify", "--target", temp); err != nil {
		return "", err
	}
	restored := filepath.Join(temp, strings.TrimPrefix(source, string(filepath.Separator)))
	actual, err := treeManifest(restored)
	if err != nil {
		return "", err
	}
	actualDirs, err := updateTreeDirectories(restored)
	if err != nil {
		return "", err
	}
	if !reflect.DeepEqual(actual, expected) || !reflect.DeepEqual(actualDirs, dirs) {
		return "", errors.New("encrypted update snapshot verification failed")
	}
	return id, nil
}
func restoreEncryptedUpdateDirectory(ctx context.Context, c BackupConfig, id, source, dest string) error {
	if !backupSnapshotPattern.MatchString(id) || !filepath.IsAbs(source) {
		return errors.New("invalid encrypted update snapshot")
	}
	temp, err := newUpdateTemp(filepath.Dir(c.Repository), ".restore-encrypted-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(temp)
	if _, err = runRestic(ctx, c, "restore", id, "--verify", "--target", temp); err != nil {
		return err
	}
	restored := filepath.Join(temp, strings.TrimPrefix(source, string(filepath.Separator)))
	return copyVerified(restored, dest)
}

func materializeUpdateBackup(ctx context.Context, job string, j *UpdateJournal) error {
	if j.EncryptedBackupID == "" {
		return nil
	}
	backup := filepath.Join(job, "backup")
	if _, err := os.Lstat(backup); err == nil {
		return verifyBackup(job)
	} else if !os.IsNotExist(err) {
		return err
	}
	c, err := updateVaultConfig(job)
	if err != nil {
		return err
	}
	stage, err := newUpdateTemp(job, ".restore-encrypted-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(stage)
	candidate := filepath.Join(stage, "backup")
	if err = restoreEncryptedUpdateDirectory(ctx, c, j.EncryptedBackupID, backup, candidate); err != nil {
		return err
	}
	var expected map[string]string
	if err = decode(filepath.Join(job, "checksums.json"), &expected); err != nil {
		return err
	}
	actual, err := treeManifest(candidate)
	if err != nil {
		return err
	}
	if !reflect.DeepEqual(actual, expected) {
		return errors.New("encrypted backup integrity verification failed")
	}
	if err = os.Rename(candidate, backup); err != nil {
		return err
	}
	return nil
}

func verifyEncryptedUpdateDirectory(ctx context.Context, c BackupConfig, id, source string) error {
	expected, err := treeManifest(source)
	if err != nil {
		return err
	}
	dirs, err := updateTreeDirectories(source)
	if err != nil {
		return err
	}
	temp, err := newUpdateTemp(filepath.Dir(c.Repository), ".verify-retained-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(temp)
	target := filepath.Join(temp, "data")
	if err = restoreEncryptedUpdateDirectory(ctx, c, id, source, target); err != nil {
		return err
	}
	actual, err := treeManifest(target)
	if err != nil {
		return err
	}
	actualDirs, err := updateTreeDirectories(target)
	if err != nil {
		return err
	}
	if !reflect.DeepEqual(expected, actual) || !reflect.DeepEqual(actualDirs, dirs) {
		return errors.New("retained encrypted data verification failed")
	}
	return nil
}
func (e Engine) ExportRetainedUpdate(ctx context.Context, site, id string) (map[string]any, error) {
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	job, err := e.updateDir(site, id)
	if err != nil {
		return nil, err
	}
	var j UpdateJournal
	if err = decode(filepath.Join(job, "journal.json"), &j); err != nil {
		return nil, err
	}
	if j.Site != site || j.ID != id || j.Phase != "recovered" || j.EncryptedRetainedID == "" {
		return nil, errors.New("encrypted retained data unavailable")
	}
	target := filepath.Join(job, "retained-export")
	if _, err = os.Lstat(target); !os.IsNotExist(err) {
		return nil, errors.New("retained export already exists or is inaccessible")
	}
	c, err := updateVaultConfig(job)
	if err != nil {
		return nil, err
	}
	stage, err := newUpdateTemp(job, ".restore-encrypted-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(stage)
	candidate := filepath.Join(stage, "data")
	if err = restoreEncryptedUpdateDirectory(ctx, c, j.EncryptedRetainedID, filepath.Join(job, "failed-storage"), candidate); err != nil {
		return nil, err
	}
	if err = os.Rename(candidate, target); err != nil {
		return nil, err
	}
	return map[string]any{"site_id": site, "update_id": id, "restore_path": target}, nil
}

func newUpdateTemp(job, prefix string) (string, error) {
	path, err := os.MkdirTemp(job, prefix)
	if err != nil {
		return "", err
	}
	if err = atomicJSON(filepath.Join(path, ".gereq-update-stage.json"), map[string]string{"kind": "temporary-update-stage"}); err != nil {
		os.RemoveAll(path)
		return "", err
	}
	return path, nil
}
func (e Engine) cleanupUpdateStages() (int, error) {
	root := filepath.Join(e.Root, "updates")
	info, err := os.Lstat(root)
	if os.IsNotExist(err) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return 0, errors.New("invalid updates directory")
	}
	unlock, err := e.lock()
	if err != nil {
		return 0, err
	}
	defer unlock()
	sites, err := os.ReadDir(root)
	if err != nil {
		return 0, err
	}
	count := 0
	for _, site := range sites {
		if !site.IsDir() || !sitePattern.MatchString(site.Name()) {
			continue
		}
		jobs, err := os.ReadDir(filepath.Join(root, site.Name()))
		if err != nil {
			return count, err
		}
		for _, job := range jobs {
			if !job.IsDir() || !updateIDPattern.MatchString(job.Name()) {
				continue
			}
			dir, err := e.updateDir(site.Name(), job.Name())
			if err != nil {
				return count, err
			}
			var journal UpdateJournal
			if decode(filepath.Join(dir, "journal.json"), &journal) == nil && journal.Site == site.Name() && journal.ID == job.Name() {
				backup := filepath.Join(dir, "backup")
				if !journal.BackupReady && (journal.Phase == "prepared" || journal.Phase == "stopped") {
					instance, instanceErr := ownedDir(e.Root, site.Name())
					var marker struct {
						ID string `json:"id"`
					}
					if instanceErr == nil && decode(activeUpdate(instance), &marker) == nil && marker.ID == job.Name() {
						if err = os.RemoveAll(backup); err != nil {
							return count, err
						}
					}
				} else if journal.BackupReady && journal.EncryptedBackupID != "" {
					c, keyErr := updateVaultConfig(dir)
					if keyErr == nil {
						for _, item := range []struct{ name, id string }{{"backup", journal.EncryptedBackupID}, {"failed-storage", journal.EncryptedRetainedID}} {
							if item.id == "" {
								continue
							}
							path := filepath.Join(dir, item.name)
							if info, statErr := os.Lstat(path); statErr == nil && info.IsDir() && info.Mode()&os.ModeSymlink == 0 {
								ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
								verifyErr := verifyEncryptedUpdateDirectory(ctx, c, item.id, path)
								cancel()
								if verifyErr == nil {
									if err = os.RemoveAll(path); err != nil {
										return count, err
									}
									count++
								}
							}
						}
					}
				}
			}
			entries, err := os.ReadDir(dir)
			if err != nil {
				return count, err
			}
			for _, entry := range entries {
				if !entry.IsDir() {
					continue
				}
				name := entry.Name()
				if !strings.HasPrefix(name, ".verify-encrypted-") && !strings.HasPrefix(name, ".restore-encrypted-") && !strings.HasPrefix(name, ".verify-retained-") && !strings.HasPrefix(name, ".restore-") {
					continue
				}
				path := filepath.Join(dir, name)
				var marker struct {
					Kind string `json:"kind"`
				}
				if decode(filepath.Join(path, ".gereq-update-stage.json"), &marker) != nil || marker.Kind != "temporary-update-stage" {
					continue
				}
				if err = os.RemoveAll(path); err != nil {
					return count, err
				}
				count++
			}
		}
	}
	return count, nil
}

func verifyEncryptedUpdateExists(ctx context.Context, c BackupConfig, id, source string) error {
	stage, err := newUpdateTemp(filepath.Dir(c.Repository), ".verify-retained-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(stage)
	target := filepath.Join(stage, "data")
	if err = restoreEncryptedUpdateDirectory(ctx, c, id, source, target); err != nil {
		return err
	}
	_, err = treeManifest(target)
	return err
}
