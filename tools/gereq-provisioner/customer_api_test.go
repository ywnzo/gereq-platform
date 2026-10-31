package main

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type customerRunner struct {
	runtime    *updateRunner
	setupCalls int
}

func (r *customerRunner) Run(ctx context.Context, args []string) ([]byte, error) {
	if strings.Contains(strings.Join(args, " "), "bridge.php setup-link") {
		r.setupCalls++
		return []byte(`{"setup_url":"http://customer-one.localhost:18371/admin/reset-password/secret-token","email":"owner@example.invalid","expires_at":"2026-10-29T12:00:00Z"}`), nil
	}
	return r.runtime.Run(ctx, args)
}
func customerFixture(t *testing.T) (*centralAPI, customerRequest, *customerRunner) {
	t.Helper()
	a, r, l := centralFixture(t)
	base := a.backup.engine.Runner.(*fakeDocker)
	runner := &customerRunner{runtime: &updateRunner{base: base, releaseID: l.ID, oldID: "sha256:" + strings.Repeat("a", 64), targetID: "sha256:" + strings.Repeat("b", 64)}}
	a.backup.engine.Runner = runner
	in := customerRequest{SiteID: r.SiteID, CustomerRef: r.CustomerRef, Email: "owner@example.invalid", PackageID: "funnel-v1", TemplateID: "editorial-ai", ReleaseID: l.ID, Port: r.Port, Brief: Brief{Schema: 1, Name: "Example studio", Offer: "Garden consultation", Audience: "Garden owners", Language: "en", Slug: "garden-consultation", Goal: "inquiry"}}
	a.customerGenerate = func(ctx context.Context, req centralRequest, template catalogTemplate, beforeImport func(GenerationResult) error) (any, error) {
		if err := beforeImport(GenerationResult{JobID: "gen-0123456789abcdef01234567"}); err != nil {
			return nil, err
		}
		return customerGeneration(), nil
	}
	return a, in, runner
}
func customerGeneration() any {
	return map[string]any{"generation_id": "gen-0123456789abcdef01234567", "imported": true, "import_result": map[string]any{"page_id": "01a1873d-345b-726d-83b4-515681f74e9d", "draft": true}}
}
func submitCustomer(t *testing.T, a *centralAPI, in customerRequest, key string) string {
	t.Helper()
	raw, _ := json.Marshal(in)
	w := centralReq(t, a, "POST", "/v1/customers", string(raw), key)
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	var out struct {
		ID string `json:"id"`
	}
	if json.Unmarshal(w.Body.Bytes(), &out) != nil || out.ID == "" {
		t.Fatal(w.Body.String())
	}
	return out.ID
}
func TestCustomerCreationProgressIdempotencyAndPrivateAccess(t *testing.T) {
	a, in, runner := customerFixture(t)
	entered := make(chan struct{})
	finish := make(chan struct{})
	a.customerGenerate = func(ctx context.Context, req centralRequest, template catalogTemplate, beforeImport func(GenerationResult) error) (any, error) {
		if req.SiteID != in.SiteID || !req.ImportDraft || template.ID != in.TemplateID {
			t.Error("generation lost input")
		}
		close(entered)
		<-finish
		if err := beforeImport(GenerationResult{JobID: "gen-0123456789abcdef01234567"}); err != nil {
			return nil, err
		}
		return customerGeneration(), nil
	}
	id := submitCustomer(t, a, in, "customer-e2e-001")
	<-entered
	w := centralReq(t, a, "GET", "/v1/customers/"+id, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"phase":"generating"`) || strings.Contains(w.Body.String(), in.Email) || strings.Contains(w.Body.String(), in.Brief.Offer) {
		t.Fatal(w.Code, w.Body.String())
	}
	raw, _ := json.Marshal(in)
	if w = centralReq(t, a, "POST", "/v1/customers", string(raw), "customer-e2e-001"); w.Code != 200 || !strings.Contains(w.Body.String(), id) {
		t.Fatal(w.Code, w.Body.String())
	}
	if w = centralReq(t, a, "POST", "/v1/customers", string(raw), "customer-e2e-002"); w.Code != 409 {
		t.Fatal(w.Code)
	}
	if w = apiTestRequest(t, a.backup, "POST", "/v1/backup/jobs", `{"operation":"create","site_id":"customer-one"}`, "customer-backup-001"); w.Code != 409 {
		t.Fatal("backup not excluded", w.Code)
	}
	close(finish)
	a.backup.workers.Wait()
	w = centralReq(t, a, "GET", "/v1/customers/"+id, "", "")
	for _, fragment := range []string{`"status":"complete"`, `"phase":"ready_for_review"`, `"published":false`, `/admin/cms/pages/01a1873d-345b-726d-83b4-515681f74e9d/preview`, `/setup-link`} {
		if !strings.Contains(w.Body.String(), fragment) {
			t.Fatal("missing", fragment, w.Body.String())
		}
	}
	if runner.setupCalls != 0 {
		t.Fatal("issued credentials during background creation")
	}
	w = centralReq(t, a, "POST", "/v1/customers/"+id+"/setup-link", "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), "secret-token") || runner.setupCalls != 1 {
		t.Fatal(w.Code, w.Body.String())
	}
	raw, _ = os.ReadFile(filepath.Join(a.customerDir(), id+".json"))
	if strings.Contains(string(raw), "secret-token") || strings.Contains(string(raw), "setup_url") {
		t.Fatal("credentials persisted")
	}
	if w = centralReq(t, a, "POST", "/v1/customers/"+id+"/retry", "", ""); w.Code != 409 {
		t.Fatal("completed workflow retried")
	}
}
func TestCustomerRetryKeepsInstanceAndPinnedTemplateAfterRestart(t *testing.T) {
	a, in, runner := customerFixture(t)
	a.customerGenerate = func(context.Context, centralRequest, catalogTemplate, func(GenerationResult) error) (any, error) {
		return nil, errors.New("simulated provider failure containing private-credential")
	}
	id := submitCustomer(t, a, in, "customer-retry-001")
	a.backup.workers.Wait()
	w := centralReq(t, a, "GET", "/v1/customers/"+id, "", "")
	if !strings.Contains(w.Body.String(), `"retryable":true`) || strings.Contains(w.Body.String(), "private-credential") {
		t.Fatal(w.Body.String())
	}
	before, err := os.ReadFile(filepath.Join(a.backup.engine.Root, "sites", in.SiteID, ".env"))
	if err != nil {
		t.Fatal(err)
	}
	calls := len(runner.runtime.base.calls)
	var saved customerJob
	if err = decode(filepath.Join(a.customerDir(), id+".json"), &saved); err != nil {
		t.Fatal(err)
	}
	saved.Status = "running"
	if err = a.saveCustomer(&saved); err != nil {
		t.Fatal(err)
	}
	next, err := newCentralAPI(a.backup, a.catalog)
	if err != nil {
		t.Fatal(err)
	}
	next.customerGenerate = func(ctx context.Context, req centralRequest, template catalogTemplate, beforeImport func(GenerationResult) error) (any, error) {
		if template.ID != in.TemplateID || template.Revision != 1 {
			t.Error("lost accepted template revision")
		}
		if err := beforeImport(GenerationResult{JobID: "gen-0123456789abcdef01234567"}); err != nil {
			return nil, err
		}
		return customerGeneration(), nil
	}
	w = centralReq(t, next, "GET", "/v1/customers/"+id, "", "")
	if !strings.Contains(w.Body.String(), `"status":"interrupted"`) {
		t.Fatal(w.Body.String())
	}
	catalog, err := next.loadCatalog()
	if err != nil {
		t.Fatal(err)
	}
	changed := catalog.Templates[in.TemplateID]
	changed.Revision = 2
	catalog.Templates[in.TemplateID] = changed
	if err = next.saveCatalog(catalog); err != nil {
		t.Fatal(err)
	}
	w = centralReq(t, next, "POST", "/v1/customers/"+id+"/retry", "", "")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	next.backup.workers.Wait()
	w = centralReq(t, next, "GET", "/v1/customers/"+id, "", "")
	if !strings.Contains(w.Body.String(), `"status":"complete"`) || !strings.Contains(w.Body.String(), `"attempts":2`) {
		t.Fatal(w.Body.String())
	}
	after, _ := os.ReadFile(filepath.Join(a.backup.engine.Root, "sites", in.SiteID, ".env"))
	if string(before) != string(after) || len(runner.runtime.base.calls) != calls {
		t.Fatal("retry reprovisioned the instance or rotated its secrets")
	}
}
func TestCustomerValidationBeforeRuntimeMutation(t *testing.T) {
	a, in, runner := customerFixture(t)
	for name, mutate := range map[string]func(*customerRequest){"email": func(r *customerRequest) { r.Email = "Owner <owner@example.invalid>" }, "package": func(r *customerRequest) { r.PackageID = "unknown-package" }, "template": func(r *customerRequest) { r.TemplateID = "unknown-template" }, "offline": func(r *customerRequest) { r.TemplateID = "editorial-native" }, "brief": func(r *customerRequest) { r.Brief.Goal = "unknown" }, "media": func(r *customerRequest) { r.Brief.IllustrationURL = "/media/01234567-0123-4123-8123-0123456789ab/file" }} {
		t.Run(name, func(t *testing.T) {
			bad := in
			mutate(&bad)
			raw, _ := json.Marshal(bad)
			w := centralReq(t, a, "POST", "/v1/customers", string(raw), "invalid-input-"+name)
			if w.Code != 400 {
				t.Fatal(w.Code, w.Body.String())
			}
		})
	}
	if len(runner.runtime.base.calls) != 0 {
		t.Fatal("runtime mutated before validation")
	}
	if w := centralReq(t, a, "POST", "/v1/customers", `{"site_id":"customer-one","password":"secret"}`, "invalid-unknown-field"); w.Code != 400 {
		t.Fatal(w.Code)
	}
	id := submitCustomer(t, a, in, "customer-validation-001")
	a.backup.workers.Wait()
	in.Email = "other@example.invalid"
	raw, _ := json.Marshal(in)
	if w := centralReq(t, a, "POST", "/v1/customers", string(raw), "customer-validation-001"); w.Code != 409 {
		t.Fatal("idempotency conflict accepted", w.Code)
	}
	if w := centralReq(t, a, "GET", "/v1/customers/"+id+"?secret=x", "", ""); w.Code != 400 {
		t.Fatal(w.Code)
	}
}

func TestCustomerProvisionFailureResumesWithoutChangingCredentials(t *testing.T) {
	a, in, runner := customerFixture(t)
	runner.runtime.base.fail = "bootstrap"
	id := submitCustomer(t, a, in, "customer-bootstrap-001")
	a.backup.workers.Wait()
	w := centralReq(t, a, "GET", "/v1/customers/"+id, "", "")
	if !strings.Contains(w.Body.String(), `"status":"failed"`) || !strings.Contains(w.Body.String(), `"phase":"provisioning"`) {
		t.Fatal(w.Body.String())
	}
	before, err := os.ReadFile(filepath.Join(a.backup.engine.Root, "sites", in.SiteID, ".env"))
	if err != nil {
		t.Fatal(err)
	}
	w = centralReq(t, a, "POST", "/v1/customers/"+id+"/retry", "", "")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	a.backup.workers.Wait()
	w = centralReq(t, a, "GET", "/v1/customers/"+id, "", "")
	if !strings.Contains(w.Body.String(), `"status":"complete"`) {
		t.Fatal(w.Body.String())
	}
	after, _ := os.ReadFile(filepath.Join(a.backup.engine.Root, "sites", in.SiteID, ".env"))
	if string(before) != string(after) {
		t.Fatal("bootstrap retry rotated credentials")
	}
}

func TestCustomerMissingProviderRejectedBeforeProvisioning(t *testing.T) {
	a, in, runner := customerFixture(t)
	a.customerGenerate = nil
	t.Setenv("OPENAI_API_KEY", "")
	t.Setenv("GEREQ_GENERATOR_MODEL", "")
	raw, _ := json.Marshal(in)
	w := centralReq(t, a, "POST", "/v1/customers", string(raw), "customer-no-provider-001")
	if w.Code != 400 || len(runner.runtime.base.calls) != 0 {
		t.Fatal(w.Code, w.Body.String())
	}
}

func TestCustomerImportRetryUsesCheckpointedArtifactWithoutRegeneration(t *testing.T) {
	a, in, _ := customerFixture(t)
	const generationID = "gen-0123456789abcdef01234567"
	a.customerGenerate = func(ctx context.Context, req centralRequest, template catalogTemplate, beforeImport func(GenerationResult) error) (any, error) {
		dir := filepath.Join(a.backup.engine.Root, "generations", req.SiteID, generationID)
		if err := os.MkdirAll(dir, 0700); err != nil {
			return nil, err
		}
		if err := atomicJSON(filepath.Join(dir, "artifact.json"), FunnelArtifact{Schema: 1, ID: generationID, Title: "Test draft", Slug: "test-draft", Blocks: []NativeBlock{}}); err != nil {
			return nil, err
		}
		if err := beforeImport(GenerationResult{JobID: generationID}); err != nil {
			return nil, err
		}
		return nil, errors.New("simulated draft import failure")
	}
	id := submitCustomer(t, a, in, "customer-import-retry-001")
	a.backup.workers.Wait()
	w := centralReq(t, a, "GET", "/v1/customers/"+id, "", "")
	if !strings.Contains(w.Body.String(), `"phase":"importing"`) {
		t.Fatal(w.Body.String())
	}
	a.customerGenerate = func(context.Context, centralRequest, catalogTemplate, func(GenerationResult) error) (any, error) {
		t.Error("paid model call repeated for saved artifact")
		return nil, errors.New("unexpected generation")
	}
	w = centralReq(t, a, "POST", "/v1/customers/"+id+"/retry", "", "")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	a.backup.workers.Wait()
	w = centralReq(t, a, "GET", "/v1/customers/"+id, "", "")
	if !strings.Contains(w.Body.String(), `"status":"complete"`) || !strings.Contains(w.Body.String(), generationID) {
		t.Fatal(w.Body.String())
	}
}
