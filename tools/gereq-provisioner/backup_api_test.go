package main

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func apiTestRequest(t *testing.T, a *backupAPI, method, path, body, key string) *httptest.ResponseRecorder {
	t.Helper()
	r := httptest.NewRequest(method, path, strings.NewReader(body))
	if body != "" {
		r.Header.Set("Content-Type", "application/json")
	}
	if key != "" {
		r.Header.Set("Idempotency-Key", key)
	}
	w := httptest.NewRecorder()
	a.ServeHTTP(w, r)
	return w
}
func setupBackupAPI(t *testing.T) *backupAPI {
	t.Helper()
	root := t.TempDir()
	a, err := newBackupAPI(Engine{Root: root})
	if err != nil {
		t.Fatal(err)
	}
	password := filepath.Join(root, "secret")
	if err = os.WriteFile(password, []byte("a long private test secret"), 0600); err != nil {
		t.Fatal(err)
	}
	c := BackupConfig{Schema: 1, Repository: filepath.Join(root, "repo"), PasswordFile: password}
	b, _ := json.Marshal(c)
	w := apiTestRequest(t, a, "PUT", "/v1/backup/config", string(b), "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	return a
}
func TestBackupAPIJobsAreIdempotentAndExclusive(t *testing.T) {
	a := setupBackupAPI(t)
	started := make(chan struct{})
	finish := make(chan struct{})
	a.execute = func(context.Context, backupAPIRequest, BackupConfig) (any, error) {
		close(started)
		<-finish
		return map[string]any{"done": true}, nil
	}
	body := `{"operation":"create","site_id":"customer-one"}`
	first := apiTestRequest(t, a, "POST", "/v1/backup/jobs", body, "request-1234")
	if first.Code != 202 {
		t.Fatal(first.Code, first.Body.String())
	}
	var job backupAPIJob
	if err := json.Unmarshal(first.Body.Bytes(), &job); err != nil {
		t.Fatal(err)
	}
	<-started
	if w := apiTestRequest(t, a, "POST", "/v1/backup/jobs", body, "request-1234"); w.Code != 200 || !bytes.Contains(w.Body.Bytes(), []byte(job.ID)) {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := apiTestRequest(t, a, "POST", "/v1/backup/jobs", body, "request-5678"); w.Code != 409 {
		t.Fatal(w.Code)
	}
	if w := apiTestRequest(t, a, "POST", "/v1/backup/jobs", `{"operation":"create","site_id":"customer-two"}`, "request-1234"); w.Code != 409 {
		t.Fatal(w.Code)
	}
	if w := apiTestRequest(t, a, "GET", "/v1/backup/config", "", ""); w.Code != 200 || strings.Contains(w.Body.String(), "secret") {
		t.Fatal(w.Code, w.Body.String())
	}
	close(finish)
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		w := apiTestRequest(t, a, "GET", "/v1/backup/jobs/"+job.ID, "", "")
		if strings.Contains(w.Body.String(), `"complete"`) {
			return
		}
		time.Sleep(time.Millisecond * 5)
	}
	t.Fatal("job did not complete")
}
func TestBackupAPIRejectsInvalidAndMarksInterruptedJobs(t *testing.T) {
	a := setupBackupAPI(t)
	if w := apiTestRequest(t, a, "POST", "/v1/backup/jobs", `{"operation":"restore","site_id":"customer-one","snapshot_id":"bad"}`, "request-1234"); w.Code != 400 {
		t.Fatal(w.Code)
	}
	if w := apiTestRequest(t, a, "POST", "/v1/backup/jobs", `{"operation":"init","unexpected":true}`, "request-1234"); w.Code != 400 {
		t.Fatal(w.Code)
	}
	j := backupAPIJob{ID: "job-0123456789abcdef01234567", IdempotencyKey: "request-1234", Status: "running"}
	if err := a.saveJob(&j); err != nil {
		t.Fatal(err)
	}
	reloaded, err := newBackupAPI(a.engine)
	if err != nil {
		t.Fatal(err)
	}
	w := apiTestRequest(t, reloaded, "GET", "/v1/backup/jobs/"+j.ID, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"interrupted"`) {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestBackupAPIUsesPrivateUnixSocket(t *testing.T) {
	root := t.TempDir()
	socketDir := filepath.Join(root, "socket")
	if err := os.Mkdir(socketDir, 0700); err != nil {
		t.Fatal(err)
	}
	sock := filepath.Join(socketDir, "api.sock")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- serveBackupAPI(ctx, Engine{Root: root}, sock) }()
	deadline := time.Now().Add(2 * time.Second)
	for {
		info, err := os.Lstat(sock)
		if err == nil {
			if info.Mode().Perm() != 0600 {
				t.Fatalf("socket mode %o", info.Mode().Perm())
			}
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("socket not created")
		}
		time.Sleep(5 * time.Millisecond)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestEncryptedCredentialsAndScheduledJobs(t *testing.T) {
	a := setupBackupAPI(t)
	key := filepath.Join(a.engine.Root, "master.key")
	if err := os.WriteFile(key, bytes.Repeat([]byte{0x5a}, 32), 0600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("GEREQ_BACKUP_MASTER_KEY_FILE", key)
	w := apiTestRequest(t, a, "POST", "/v1/backup/credentials", `{"password":"strong test password value","aws_access_key_id":"test-access","aws_secret_access_key":"test-secret"}`, "")
	if w.Code != 201 || strings.Contains(w.Body.String(), "strong test") {
		t.Fatal(w.Code, w.Body.String())
	}
	var result struct {
		ID string `json:"credential_id"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &result); err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile(filepath.Join(a.dir, "credentials", result.ID+".json"))
	if err != nil || bytes.Contains(data, []byte("test-secret")) || bytes.Contains(data, []byte("strong test")) {
		t.Fatal("secret stored in plaintext", err)
	}
	secret, err := a.loadCredential(result.ID)
	if err != nil || secret.Password != "strong test password value" {
		t.Fatal(err)
	}
	config := BackupConfig{Schema: 1, Repository: filepath.Join(a.engine.Root, "repo"), CredentialID: result.ID}
	cfg, _ := json.Marshal(config)
	w = apiTestRequest(t, a, "PUT", "/v1/backup/config", string(cfg), "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	started := make(chan struct{}, 1)
	a.execute = func(_ context.Context, r backupAPIRequest, c BackupConfig) (any, error) {
		if r.Operation != "scheduled" || r.KeepLast != 3 || c.secret == nil {
			t.Errorf("invalid scheduled job or credential")
		}
		started <- struct{}{}
		return map[string]any{"ok": true}, nil
	}
	w = apiTestRequest(t, a, "PUT", "/v1/backup/schedules/customer-one", `{"enabled":true,"interval_seconds":3600,"keep_last":3}`, "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	dir, err := a.scheduleDir()
	if err != nil {
		t.Fatal(err)
	}
	var policy backupPolicy
	if err = decode(filepath.Join(dir, "customer-one.json"), &policy); err != nil {
		t.Fatal(err)
	}
	policy.NextRun = time.Now().Add(-time.Minute)
	if err = atomicJSON(filepath.Join(dir, "customer-one.json"), policy); err != nil {
		t.Fatal(err)
	}
	a.processSchedules()
	select {
	case <-started:
	case <-time.After(2 * time.Second):
		t.Fatal("scheduled job not started")
	}
	a.workers.Wait()
	a.processSchedules()
	select {
	case <-started:
		t.Fatal("duplicate scheduled job")
	default:
	}
	if err = os.WriteFile(key, bytes.Repeat([]byte{0x6b}, 32), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err = a.loadCredential(result.ID); err == nil {
		t.Fatal("wrong master key accepted")
	}
}
