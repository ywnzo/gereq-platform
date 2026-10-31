package main

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestManagementCreationUsesExistingWorkflowAndStableReservation(t *testing.T) {
	a, in, _ := customerFixture(t)
	verifySelfRelease(t, a, in.ReleaseID)
	w := centralReq(t, a, "GET", "/v1/management/creation/options", "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), in.TemplateID) {
		t.Fatal(w.Code, w.Body.String())
	}
	input := onboardingInput{Name: in.Brief.Name, Offer: in.Brief.Offer, Audience: in.Brief.Audience, Email: in.Email, Language: "en", Goal: "inquiry", PackageID: in.PackageID, TemplateID: in.TemplateID, Domain: "admin-example"}
	raw, _ := json.Marshal(input)
	a.backup.active = true
	w = centralReq(t, a, "POST", "/v1/management/creation", string(raw), "admin-create-test-001")
	if w.Code != 409 {
		t.Fatal(w.Code, w.Body.String())
	}
	// The reservation survives a busy worker and remains part of shared port allocation.
	reserved := a.selfFreePort()
	if reserved != 18201 {
		t.Fatalf("reserved port was reused: %d", reserved)
	}
	a.backup.active = false
	w = centralReq(t, a, "POST", "/v1/management/creation", string(raw), "admin-create-test-001")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	var job customerJob
	var view struct {
		ID string `json:"id"`
	}
	json.Unmarshal(w.Body.Bytes(), &view)
	a.backup.workers.Wait()
	w = centralReq(t, a, "GET", "/v1/customers/"+view.ID, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"status":"complete"`) {
		t.Fatal(w.Code, w.Body.String())
	}
	w = centralReq(t, a, "POST", "/v1/management/creation", string(raw), "admin-create-test-001")
	if w.Code != 200 || !strings.Contains(w.Body.String(), view.ID) {
		t.Fatal(w.Code, w.Body.String())
	}
	jobs, e := a.customerJobs()
	if e != nil || len(jobs) != 1 {
		t.Fatal("replay created extra workflow", e)
	}
	job = jobs[0]
	if job.Input.Port != 18200 || job.Input.Brief.Name != input.Name || job.Input.ReleaseID != in.ReleaseID || job.Input.CustomerRef != "admin-admin-example" {
		t.Fatal("lost server-owned runtime selection")
	}
	input.Offer = "Changed offer"
	raw, _ = json.Marshal(input)
	if w = centralReq(t, a, "POST", "/v1/management/creation", string(raw), "admin-create-test-001"); w.Code != 409 {
		t.Fatal(w.Code)
	}
	if w = centralReq(t, a, "POST", "/v1/management/creation", string(raw), "admin-create-test-002"); w.Code != 409 {
		t.Fatal(w.Code)
	}
	if w = centralReq(t, a, "POST", "/v1/management/creation", `{"image":"untrusted"}`, "admin-create-test-003"); w.Code != 400 {
		t.Fatal(w.Code)
	}
}
