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

type managementRuntime struct {
	base                   Runner
	running, setupRequired bool
	stopCalls, accessCalls int
}

func (r *managementRuntime) Run(ctx context.Context, args []string) ([]byte, error) {
	joined := strings.Join(args, " ")
	if len(args) > 2 && args[0] == "inspect" && args[2] == "{{json .State}}" {
		return json.Marshal(map[string]any{"Running": r.running})
	}
	if strings.HasSuffix(joined, "bridge.php health") {
		return json.Marshal(map[string]any{"ok": true, "setup_required": r.setupRequired})
	}
	if strings.Contains(joined, "bridge.php setup-link") {
		r.accessCalls++
	}
	if strings.Contains(joined, " up --detach") {
		r.running = true
	}
	if len(args) > 0 && args[len(args)-1] == "stop" {
		r.running = false
		r.stopCalls++
	}
	return r.base.Run(ctx, args)
}
func managementFixture(t *testing.T) (*centralAPI, customerRequest, *managementRuntime) {
	t.Helper()
	a, in, base := customerFixture(t)
	runtime := &managementRuntime{base: base, running: true, setupRequired: true}
	a.backup.engine.Runner = runtime
	submitCustomer(t, a, in, "management-create-001")
	a.backup.workers.Wait()
	return a, in, runtime
}
func managementSnapshot(t *testing.T, a *centralAPI) managementInventory {
	t.Helper()
	w := centralReq(t, a, "GET", "/v1/management/overview", "", "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	var out managementInventory
	if err := json.Unmarshal(w.Body.Bytes(), &out); err != nil {
		t.Fatal(err)
	}
	return out
}
func managementAction(t *testing.T, a *centralAPI, site, op, key string) string {
	t.Helper()
	w := centralReq(t, a, "POST", "/v1/management/instances/"+site+"/"+op, "", key)
	if w.Code != 202 && w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	var out struct {
		Job struct {
			ID string `json:"id"`
		} `json:"job"`
	}
	if json.Unmarshal(w.Body.Bytes(), &out) != nil || out.Job.ID == "" {
		t.Fatal(w.Body.String())
	}
	return out.Job.ID
}
func TestManagementInventoryGroupsCustomersAndOmitsPrivateJobData(t *testing.T) {
	a, in, _ := managementFixture(t)
	state, _, err := a.backup.engine.load(in.SiteID)
	if err != nil {
		t.Fatal(err)
	}
	next := state.Request
	next.SiteID = "customer-two"
	next.RequestID = "management-second-001"
	next.Port++
	if _, err = a.backup.engine.Create(context.Background(), next, state.Release); err != nil {
		t.Fatal(err)
	}
	inventory := managementSnapshot(t, a)
	if len(inventory.Customers) != 1 || len(inventory.Instances) != 2 || len(inventory.Customers[0].SiteIDs) != 2 || inventory.Customers[0].Email != in.Email {
		t.Fatalf("wrong grouping: %+v", inventory)
	}
	if inventory.Instances[0].Observation != nil || inventory.Instances[0].CanSetup {
		t.Fatal("claimed unverified setup")
	}
	w := centralReq(t, a, "GET", "/v1/management/customers/"+inventory.Customers[0].ID, "", "")
	if w.Code != 200 {
		t.Fatal(w.Code)
	}
	w = centralReq(t, a, "GET", "/v1/management/overview", "", "")
	for _, private := range []string{in.Brief.Offer, "secret-token", "setup_url", "idempotency_key", "fingerprint", "environment"} {
		if strings.Contains(w.Body.String(), private) {
			t.Fatal("private field leaked", private)
		}
	}
}
func TestManagementObservesSetupAndLifecycleIdempotency(t *testing.T) {
	a, in, runtime := managementFixture(t)
	runtime.setupRequired = false
	managementAction(t, a, in.SiteID, "inspect", "management-inspect-001")
	a.backup.workers.Wait()
	site := managementSnapshot(t, a).Instances[0]
	if site.Observation == nil || !site.Observation.Healthy || site.Observation.SetupRequired == nil || *site.Observation.SetupRequired || site.CanSetup {
		t.Fatalf("wrong observed setup: %+v", site)
	}
	first := managementAction(t, a, in.SiteID, "stop", "management-stop-001")
	a.backup.workers.Wait()
	second := managementAction(t, a, in.SiteID, "stop", "management-stop-001")
	if first != second || runtime.stopCalls != 1 {
		t.Fatal("stop not idempotent")
	}
	site = managementSnapshot(t, a).Instances[0]
	if !site.CanStart || site.CanStop || site.Observation.Runtime != "stopped" {
		t.Fatalf("wrong stopped controls: %+v", site)
	}
	managementAction(t, a, in.SiteID, "start", "management-start-001")
	a.backup.workers.Wait()
	site = managementSnapshot(t, a).Instances[0]
	if site.Observation.Runtime != "running" || !site.Observation.Healthy {
		t.Fatalf("start failed: %+v", site)
	}
}
func TestManagementSetupLinkRechecksLiveStatusAndDoesNotPersistSecrets(t *testing.T) {
	a, in, runtime := managementFixture(t)
	path := "/v1/management/instances/" + in.SiteID + "/setup-link"
	w := centralReq(t, a, "POST", path, "", "management-setup-001")
	if w.Code != 200 || runtime.accessCalls != 1 {
		t.Fatal(w.Code, w.Body.String())
	}
	runtime.setupRequired = false
	w = centralReq(t, a, "POST", path, "", "management-setup-002")
	if w.Code != 409 || runtime.accessCalls != 1 {
		t.Fatal("completed account reset", w.Code)
	}
	runtime.running = false
	w = centralReq(t, a, "POST", path, "", "management-setup-003")
	if w.Code != 409 || runtime.accessCalls != 1 {
		t.Fatal("stopped account reset", w.Code)
	}
	paths, _ := filepath.Glob(filepath.Join(a.dir, "management-health", "*.json"))
	for _, path := range paths {
		raw, _ := os.ReadFile(path)
		if strings.Contains(string(raw), "secret-token") {
			t.Fatal("persisted bearer token")
		}
	}
}
func TestManagementRetryPreservesCustomerWorkflow(t *testing.T) {
	a, in, _ := customerFixture(t)
	a.customerGenerate = func(context.Context, centralRequest, catalogTemplate, func(GenerationResult) error) (any, error) {
		return nil, errors.New("private-provider-secret")
	}
	id := submitCustomer(t, a, in, "management-failed-001")
	a.backup.workers.Wait()
	a.customerGenerate = func(ctx context.Context, req centralRequest, template catalogTemplate, before func(GenerationResult) error) (any, error) {
		if err := before(GenerationResult{JobID: "gen-0123456789abcdef01234567"}); err != nil {
			return nil, err
		}
		return customerGeneration(), nil
	}
	if retried := managementAction(t, a, in.SiteID, "retry", "management-retry-001"); retried != id {
		t.Fatal("created duplicate workflow")
	}
	a.backup.workers.Wait()
	w := centralReq(t, a, "GET", "/v1/management/workflows/"+id, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"status":"complete"`) || !strings.Contains(w.Body.String(), `"attempts":2`) || strings.Contains(w.Body.String(), "private-provider-secret") {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestManagementRejectsUnscopedAndConflictingMutations(t *testing.T) {
	a, in, runtime := managementFixture(t)
	for _, path := range []string{"/v1/management/instances/missing/stop", "/v1/management/instances/" + in.SiteID + "/delete"} {
		if w := centralReq(t, a, "POST", path, "", "management-invalid-001"); w.Code != 404 {
			t.Fatal(w.Code)
		}
	}
	path := "/v1/management/instances/" + in.SiteID + "/stop"
	if w := centralReq(t, a, "POST", path, `{"email":"attacker@example.invalid"}`, "management-invalid-002"); w.Code != 400 {
		t.Fatal(w.Code)
	}
	a.backup.mu.Lock()
	a.backup.active = true
	a.backup.mu.Unlock()
	if w := centralReq(t, a, "POST", path, "", "management-conflict-001"); w.Code != 409 || runtime.stopCalls != 0 {
		t.Fatal("active operation not protected", w.Code)
	}
	a.backup.mu.Lock()
	a.backup.active = false
	a.backup.mu.Unlock()
	if w := centralReq(t, a, "GET", "/v1/management/overview?secret=1", "", ""); w.Code != 400 {
		t.Fatal(w.Code)
	}
}

func TestManagementRejectsForeignRuntimeAndRedactsFailedAction(t *testing.T) {
	a, in, runtime := managementFixture(t)
	base := runtime.base.(*customerRunner).runtime.base
	base.containerIDs = "foreign-container\n"
	base.labels = map[string]string{"gereq.site": "someone-else", "gereq.instance": "private-runtime-path"}
	id := managementAction(t, a, in.SiteID, "inspect", "management-foreign-001")
	a.backup.workers.Wait()
	w := centralReq(t, a, "GET", "/v1/management/jobs/"+id, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"status":"failed"`) || strings.Contains(w.Body.String(), "private-runtime-path") || strings.Contains(w.Body.String(), "someone-else") {
		t.Fatal(w.Code, w.Body.String())
	}
	if runtime.accessCalls != 0 || runtime.stopCalls != 0 {
		t.Fatal("foreign runtime was mutated")
	}
}
