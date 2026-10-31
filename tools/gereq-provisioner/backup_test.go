package main

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestBackupConfigurationRejectsExposedSecretsAndInvalidDestinations(t *testing.T) {
	dir := t.TempDir()
	password := filepath.Join(dir, "password")
	config := filepath.Join(dir, "config.json")
	if err := os.WriteFile(password, []byte("a sufficiently long secret"), 0600); err != nil {
		t.Fatal(err)
	}
	good := BackupConfig{Schema: 1, Repository: "s3:https://example.com/bucket/path", PasswordFile: password}
	if err := atomicJSON(config, good); err != nil {
		t.Fatal(err)
	}
	if _, err := loadBackupConfig(config); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(password, 0644); err != nil {
		t.Fatal(err)
	}
	if _, err := loadBackupConfig(config); err == nil {
		t.Fatal("accepted exposed password file")
	}
	if err := os.Chmod(password, 0600); err != nil {
		t.Fatal(err)
	}
	for _, dest := range []string{"relative/path", "s3:https://user:secret@example.com/bucket", "s3:https://example.com/bucket?secret=bad"} {
		good.Repository = dest
		if err := atomicJSON(config, good); err != nil {
			t.Fatal(err)
		}
		if _, err := loadBackupConfig(config); err == nil {
			t.Fatalf("accepted unsafe destination %q", dest)
		}
	}
}

func TestBackupRecoveryBlocksOtherOperationsUntilRestart(t *testing.T) {
	e, r, _, _, p := updateFixture(t)
	s, _, err := e.load(r.SiteID)
	if err != nil {
		t.Fatal(err)
	}
	if err = atomicJSON(filepath.Join(p, ".backup-active.json"), map[string]string{"site_id": r.SiteID, "fingerprint": s.Fingerprint}); err != nil {
		t.Fatal(err)
	}
	if _, _, err = e.load(r.SiteID); err == nil {
		t.Fatal("unfinished backup not locked")
	}
	if _, err = e.BackupRecover(context.Background(), r.SiteID); err != nil {
		t.Fatal(err)
	}
	if _, _, err = e.load(r.SiteID); err != nil {
		t.Fatal(err)
	}
}

func TestCleanupBackupStagesRemovesOnlyOwnedCopies(t *testing.T) {
	root := t.TempDir()
	e := Engine{Root: root}
	dir := filepath.Join(root, "backup-staging")
	if err := os.Mkdir(dir, 0700); err != nil {
		t.Fatal(err)
	}
	owned := filepath.Join(dir, "snapshot-owned")
	unknown := filepath.Join(dir, "snapshot-unknown")
	for _, p := range []string{owned, unknown} {
		if err := os.Mkdir(p, 0700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(p, "customer-data"), []byte("private"), 0600); err != nil {
			t.Fatal(err)
		}
	}
	if err := atomicJSON(filepath.Join(owned, ".gereq-backup-stage.json"), map[string]string{"site_id": "customer-one", "kind": "backup-storage"}); err != nil {
		t.Fatal(err)
	}
	count, err := e.cleanupBackupStages()
	if err != nil || count != 1 {
		t.Fatal(count, err)
	}
	if _, err = os.Stat(owned); !os.IsNotExist(err) {
		t.Fatal("owned copy remains")
	}
	if _, err = os.Stat(unknown); err != nil {
		t.Fatal("unknown copy removed", err)
	}
}

func TestCleanupBackupVerificationPreservesUnownedDirectories(t *testing.T) {
	root := t.TempDir()
	e := Engine{Root: root}
	dir := filepath.Join(root, "backup-verification")
	if err := os.Mkdir(dir, 0700); err != nil {
		t.Fatal(err)
	}
	owned := filepath.Join(dir, "verify-owned")
	unknown := filepath.Join(dir, "verify-unknown")
	for _, p := range []string{owned, unknown} {
		if err := os.Mkdir(p, 0700); err != nil {
			t.Fatal(err)
		}
	}
	if err := atomicJSON(filepath.Join(owned, ".gereq-backup-verify.json"), map[string]string{"site_id": "customer-one", "kind": "backup-verification"}); err != nil {
		t.Fatal(err)
	}
	count, err := e.cleanupBackupVerification()
	if err != nil || count != 1 {
		t.Fatal(count, err)
	}
	if _, err = os.Stat(owned); !os.IsNotExist(err) {
		t.Fatal("temporary copy remains")
	}
	if _, err = os.Stat(unknown); err != nil {
		t.Fatal("unknown copy removed")
	}
}
