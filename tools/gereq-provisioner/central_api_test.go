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

func centralReq(t *testing.T, a *centralAPI, method, path, body, key string) *httptest.ResponseRecorder {
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
func centralFixture(t *testing.T) (*centralAPI, Request, Release) {
	t.Helper()
	e, r, l, _ := fixture(t)
	b, err := newBackupAPI(e)
	if err != nil {
		t.Fatal(err)
	}
	catalog := filepath.Join(e.Root, "approved-releases")
	if err = os.Mkdir(catalog, 0700); err != nil {
		t.Fatal(err)
	}
	if err = atomicJSON(filepath.Join(catalog, l.ID+".json"), l); err != nil {
		t.Fatal(err)
	}
	a, err := newCentralAPI(b, catalog)
	if err != nil {
		t.Fatal(err)
	}
	if err = a.accounts.setMailPolicy(accountMailPolicy{Mode: "simulator", CustomerOrigin: "http://127.0.0.1:18152", VerificationTTL: 60, ResetTTL: 30, RetentionHours: 24}); err != nil {
		t.Fatal(err)
	}
	a.moduleCatalog = func(context.Context) ([]backendModule, error) {
		return []backendModule{{ID: "cms", Name: "Obsah a stránky", Requires: []string{}}, {ID: "site_inquiries", Name: "Poptávky", Requires: []string{}}, {ID: "analytics", Name: "Analytika", Requires: []string{}}, {ID: "shop", Name: "E-Shop", Requires: []string{"cms"}}, {ID: "calendar", Name: "Kalendář", Requires: []string{}}}, nil
	}
	return a, r, l
}
func TestCentralJobsValidateCatalogIdempotencyAndSharedExclusion(t *testing.T) {
	a, r, l := centralFixture(t)
	started := make(chan struct{})
	finish := make(chan struct{})
	a.execute = func(_ context.Context, req centralRequest, rel *Release) (any, error) {
		if rel == nil || rel.ID != l.ID || req.SiteID != r.SiteID {
			t.Error("wrong request or release")
		}
		if req.Request == nil || req.Request.CatalogPackageID != "funnel-v1" || req.Request.CatalogPackageRevision != 1 || len(req.Request.AllowedTemplates) != 3 {
			t.Error("package assignment missing from queued job")
		}
		close(started)
		<-finish
		return map[string]any{"ok": true}, nil
	}
	payload := centralRequest{Operation: "create", SiteID: r.SiteID, Request: &r, ReleaseID: l.ID}
	raw, _ := json.Marshal(payload)
	w := centralReq(t, a, "POST", "/v1/central/jobs", string(raw), "request-1234")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	var j centralJob
	if err := json.Unmarshal(w.Body.Bytes(), &j); err != nil {
		t.Fatal(err)
	}
	<-started
	if w = centralReq(t, a, "POST", "/v1/central/jobs", string(raw), "request-1234"); w.Code != 200 || !bytes.Contains(w.Body.Bytes(), []byte(j.ID)) {
		t.Fatal(w.Code, w.Body.String())
	}
	if w = centralReq(t, a, "POST", "/v1/central/jobs", string(raw), "request-9999"); w.Code != 409 {
		t.Fatal(w.Code)
	}
	b := apiTestRequest(t, a.backup, "POST", "/v1/backup/jobs", `{"operation":"create","site_id":"customer-one"}`, "request-backup")
	if b.Code != 409 {
		t.Fatal("backup was not excluded", b.Code)
	}
	payload.SiteID = "customer-two"
	changed, _ := json.Marshal(payload)
	if w = centralReq(t, a, "POST", "/v1/central/jobs", string(changed), "request-1234"); w.Code != 409 {
		t.Fatal(w.Code)
	}
	close(finish)
	a.backup.workers.Wait()
	w = centralReq(t, a, "GET", "/v1/central/jobs/"+j.ID, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"complete"`) {
		t.Fatal(w.Code, w.Body.String())
	}
	payload.SiteID = r.SiteID
	payload.ReleaseID = "unapproved"
	changed, _ = json.Marshal(payload)
	w = centralReq(t, a, "POST", "/v1/central/jobs", string(changed), "request-7777")
	if w.Code != 400 {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestCentralRespectsRecoveryAndRejectsArbitraryImage(t *testing.T) {
	a, r, l := centralFixture(t)
	bad := `{"operation":"create","site_id":"customer-one","request":{"schema_version":1,"request_id":"request-0001","site_id":"customer-one","customer_ref":"customer-1","package":"funnel-v1","release_id":"local-v1","port":18371},"release_id":"local-v1","image":"malicious"}`
	if w := centralReq(t, a, "POST", "/v1/central/jobs", bad, "request-1234"); w.Code != 400 {
		t.Fatal(w.Code)
	}
	job := centralJob{ID: "job-0123456789abcdef01234567", Status: "running", CreatedAt: time.Now()}
	if err := a.save(&job); err != nil {
		t.Fatal(err)
	}
	b, err := newCentralAPI(a.backup, a.catalog)
	if err != nil {
		t.Fatal(err)
	}
	w := centralReq(t, b, "GET", "/v1/central/jobs/"+job.ID, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"interrupted"`) {
		t.Fatal(w.Code, w.Body.String())
	}
	payload := centralRequest{Operation: "update-rollback", SiteID: r.SiteID, UpdateID: "upd-0123456789abcdef01234567"}
	if _, err = b.validateRequest(payload); err == nil {
		t.Fatal("rollback without acknowledgement")
	}
	_ = l
}

func TestCentralGenerationCleanupAndArtifactPaths(t *testing.T) {
	a, _, _ := centralFixture(t)
	owned := filepath.Join(a.dir, ".generation-owned")
	unknown := filepath.Join(a.dir, ".generation-unknown")
	for _, p := range []string{owned, unknown} {
		if err := os.Mkdir(p, 0700); err != nil {
			t.Fatal(err)
		}
	}
	if err := atomicJSON(filepath.Join(owned, ".gereq-generation-stage.json"), map[string]string{"kind": "central-generation-stage"}); err != nil {
		t.Fatal(err)
	}
	b, err := newCentralAPI(a.backup, a.catalog)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = os.Stat(owned); !os.IsNotExist(err) {
		t.Fatal("temporary generation not cleaned")
	}
	if _, err = os.Stat(unknown); err != nil {
		t.Fatal("unowned data removed")
	}
	for _, p := range []string{"/v1/generations/customer-one/../../etc/passwd", "/v1/generations/customer-one/gen-invalid"} {
		w := centralReq(t, b, "GET", p, "", "")
		if w.Code == 200 {
			t.Fatal("unsafe artifact path accepted")
		}
	}
}
