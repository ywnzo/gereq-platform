package main

import (
	"bytes"
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

func TestEncryptedUpdateSnapshot(t *testing.T) {
	if _, err := exec.LookPath("restic"); err != nil {
		t.Skip("restic is required for encrypted snapshot integration test")
	}
	root := t.TempDir()
	master := filepath.Join(root, "key")
	if err := os.WriteFile(master, bytes.Repeat([]byte{9}, 32), 0600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("GEREQ_BACKUP_MASTER_KEY_FILE", master)
	job := filepath.Join(root, "updates", "customer-one", "upd-0123456789abcdef01234567")
	if err := os.MkdirAll(job, 0700); err != nil {
		t.Fatal(err)
	}
	source := filepath.Join(job, "backup")
	if err := os.Mkdir(source, 0700); err != nil {
		t.Fatal(err)
	}
	content := []byte("private-customer-test-marker-8392")
	if err := os.WriteFile(filepath.Join(source, "customer-data"), content, 0600); err != nil {
		t.Fatal(err)
	}
	c, err := createEncryptedUpdateRepository(context.Background(), job)
	if err != nil {
		t.Fatal(err)
	}
	id, err := encryptUpdateDirectory(context.Background(), c, source)
	if err != nil {
		t.Fatal(err)
	}
	journal := UpdateJournal{Schema: 1, ID: "upd-0123456789abcdef01234567", Site: "customer-one", BackupReady: true, EncryptedBackupID: id}
	if err = atomicJSON(filepath.Join(job, "journal.json"), journal); err != nil {
		t.Fatal(err)
	}
	if err = os.WriteFile(filepath.Join(source, "customer-data"), []byte("changed"), 0600); err != nil {
		t.Fatal(err)
	}
	e := Engine{Root: root}
	if _, err = e.cleanupUpdateStages(); err != nil {
		t.Fatal(err)
	}
	if _, err = os.Stat(source); err != nil {
		t.Fatal("unverifiable data was deleted", err)
	}
	if err = os.WriteFile(filepath.Join(source, "customer-data"), content, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err = e.cleanupUpdateStages(); err != nil {
		t.Fatal(err)
	}
	if _, err = os.Stat(source); !os.IsNotExist(err) {
		t.Fatal("verified plaintext copy was not removed")
	}
	if err = restoreEncryptedUpdateDirectory(context.Background(), c, id, source, source); err != nil {
		t.Fatal(err)
	}
	restored, err := os.ReadFile(filepath.Join(source, "customer-data"))
	if err != nil || !bytes.Equal(restored, content) {
		t.Fatal(err)
	}
	if err = os.WriteFile(master, bytes.Repeat([]byte{8}, 32), 0600); err != nil {
		t.Fatal(err)
	}
	wrong, err := updateVaultConfig(job)
	if err != nil {
		t.Fatal(err)
	}
	if err = restoreEncryptedUpdateDirectory(context.Background(), wrong, id, source, filepath.Join(job, "bad")); err == nil {
		t.Fatal("wrong key accepted")
	}
}

func TestCleanupUpdateStagesPreservesUnknownData(t *testing.T) {
	root := t.TempDir()
	job := filepath.Join(root, "updates", "customer-one", "upd-0123456789abcdef01234567")
	if err := os.MkdirAll(job, 0700); err != nil {
		t.Fatal(err)
	}
	stage, err := newUpdateTemp(job, ".restore-encrypted-")
	if err != nil {
		t.Fatal(err)
	}
	if err = os.WriteFile(filepath.Join(stage, "private"), []byte("data"), 0600); err != nil {
		t.Fatal(err)
	}
	unknown := filepath.Join(job, ".restore-unrelated")
	if err = os.Mkdir(unknown, 0700); err != nil {
		t.Fatal(err)
	}
	e := Engine{Root: root}
	count, err := e.cleanupUpdateStages()
	if err != nil || count != 1 {
		t.Fatal(count, err)
	}
	if _, err = os.Stat(stage); !os.IsNotExist(err) {
		t.Fatal("temporary stage remains")
	}
	if _, err = os.Stat(unknown); err != nil {
		t.Fatal("unknown data was removed", err)
	}
}
