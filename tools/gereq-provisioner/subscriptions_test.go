package main

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func billingRequest(t *testing.T, a *centralAPI, site, op string, body any, key string) map[string]any {
	t.Helper()
	raw, _ := json.Marshal(body)
	w := centralReq(t, a, "POST", "/v1/subscriptions/"+site+"/"+op, string(raw), key)
	if w.Code != 200 && w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	var result map[string]any
	if json.Unmarshal(w.Body.Bytes(), &result) != nil {
		t.Fatal(w.Body.String())
	}
	return result
}
func mustSubscription(t *testing.T, a *centralAPI, site string) *subscription {
	t.Helper()
	sub, err := a.subscriptions.get(site)
	if err != nil || sub == nil {
		t.Fatal("subscription missing", err)
	}
	return sub
}
func expireClock(t *testing.T, a *centralAPI, site string) {
	t.Helper()
	end := mustSubscription(t, a, site).TrialEndsAt
	a.now = func() time.Time { return end }
}
func TestSubscriptionAutomaticTrialExpirySuspensionAndIdempotentRenewal(t *testing.T) {
	a, in, runtime := managementFixture(t)
	sub := mustSubscription(t, a, in.SiteID)
	if sub.CustomerRef != in.CustomerRef || sub.PackageID != in.PackageID || sub.TrialEndsAt.Sub(sub.CreatedAt) != 14*24*time.Hour {
		t.Fatalf("wrong automatic trial: %+v", sub)
	}
	a.now = func() time.Time { return sub.TrialEndsAt.Add(-time.Nanosecond) }
	if !sub.view(a.subscriptionNow()).Entitled {
		t.Fatal("trial expired early")
	}
	expireClock(t, a, in.SiteID)
	if a.subscriptionAllows(in.SiteID) == nil {
		t.Fatal("expiry not enforced at exact boundary")
	}
	for _, op := range []string{"create", "start", "resume", "generate", "update-recover", "update-rollback"} {
		w := centralReq(t, a, "POST", "/v1/central/jobs", `{"operation":"`+op+`","site_id":"`+in.SiteID+`"}`, "billing-block-"+op)
		if w.Code != 400 || !strings.Contains(w.Body.String(), "subscription expired") {
			t.Fatal(op, w.Code, w.Body.String())
		}
	}
	billingRequest(t, a, in.SiteID, "reconcile", map[string]any{}, "billing-reconcile-001")
	a.backup.workers.Wait()
	sub = mustSubscription(t, a, in.SiteID)
	if runtime.running || !sub.Suspended || !sub.ResumeOwned {
		t.Fatal("instance not suspended", sub)
	}
	first := billingRequest(t, a, in.SiteID, "simulate-renewal", map[string]any{"term": "monthly"}, "billing-payment-001")
	a.backup.workers.Wait()
	paid := *mustSubscription(t, a, in.SiteID).PaidUntil
	second := billingRequest(t, a, in.SiteID, "simulate-renewal", map[string]any{"term": "monthly"}, "billing-payment-001")
	a.backup.workers.Wait()
	sub = mustSubscription(t, a, in.SiteID)
	if !runtime.running || sub.Suspended || sub.ResumeOwned || !sub.PaidUntil.Equal(paid) || registryHash(first["payment"]) != registryHash(second["payment"]) {
		t.Fatal("renewal duplicated or runtime not restored", sub)
	}
	w := centralReq(t, a, "POST", "/v1/subscriptions/"+in.SiteID+"/simulate-renewal", `{"term":"yearly"}`, "billing-payment-001")
	if w.Code != 409 {
		t.Fatal("changed payment key accepted")
	}
	db, _ := a.subscriptions.db.open()
	defer db.Close()
	var count int
	db.QueryRow("SELECT count(*) FROM subscription_events WHERE action='simulate-renewal'").Scan(&count)
	if count != 1 {
		t.Fatal("payment applied more than once", count)
	}
}
func TestSubscriptionRenewalPreservesManualStop(t *testing.T) {
	a, in, runtime := managementFixture(t)
	managementAction(t, a, in.SiteID, "stop", "billing-manual-stop-001")
	a.backup.workers.Wait()
	expireClock(t, a, in.SiteID)
	billingRequest(t, a, in.SiteID, "reconcile", map[string]any{}, "billing-manual-check-001")
	a.backup.workers.Wait()
	sub := mustSubscription(t, a, in.SiteID)
	if sub.ResumeOwned {
		t.Fatal("claimed a manually stopped runtime")
	}
	billingRequest(t, a, in.SiteID, "simulate-renewal", map[string]any{"term": "yearly"}, "billing-manual-pay-001")
	a.backup.workers.Wait()
	sub = mustSubscription(t, a, in.SiteID)
	if runtime.running || sub.Suspended || !sub.view(a.subscriptionNow()).Entitled {
		t.Fatal("manual stop lost", sub)
	}
}
func TestSubscriptionDurableInterruptedSuspensionAndRenewal(t *testing.T) {
	a, in, runtime := managementFixture(t)
	expireClock(t, a, in.SiteID)
	sub := mustSubscription(t, a, in.SiteID)
	sub.ResumeOwned = true
	sub.Transition = "suspend"
	if err := a.saveSubscription(sub, "test-crash-marker"); err != nil {
		t.Fatal(err)
	}
	runtime.running = false
	next, err := newCentralAPI(a.backup, "")
	if err != nil {
		t.Fatal(err)
	}
	next.now = a.now
	billingRequest(t, next, in.SiteID, "reconcile", map[string]any{}, "billing-crash-check-001")
	next.backup.workers.Wait()
	sub = mustSubscription(t, next, in.SiteID)
	if !sub.ResumeOwned || !sub.Suspended || runtime.stopCalls != 0 {
		t.Fatal("interrupted ownership lost", sub)
	}
	billingRequest(t, next, in.SiteID, "simulate-renewal", map[string]any{"term": "monthly"}, "billing-crash-pay-001")
	next.backup.workers.Wait()
	if !runtime.running {
		t.Fatal("durable renewal did not restart")
	}
}
func TestSubscriptionSchedulerEnforcesWithoutClientRequest(t *testing.T) {
	a, in, runtime := managementFixture(t)
	expireClock(t, a, in.SiteID)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { defer close(done); a.runSubscriptionScheduler(ctx) }()
	deadline := time.Now().Add(5 * time.Second)
	suspended := false
	for time.Now().Before(deadline) {
		sub, err := a.subscriptions.get(in.SiteID)
		if err == nil && sub != nil && sub.Suspended {
			suspended = true
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	cancel()
	<-done
	a.backup.workers.Wait()
	if !suspended || runtime.running {
		t.Fatal("background enforcement failed")
	}
}
func TestSubscriptionUnmanagedLegacyOptInAndTrialCannotReset(t *testing.T) {
	a, r, l := centralFixture(t)
	if _, err := a.backup.engine.Create(context.Background(), r, l); err != nil {
		t.Fatal(err)
	}
	if sub, err := a.subscriptions.get(r.SiteID); err != nil || sub != nil {
		t.Fatal("legacy silently enrolled", err)
	}
	billingRequest(t, a, r.SiteID, "trial", map[string]any{"trial_days": 7}, "billing-opt-in-001")
	a.backup.workers.Wait()
	sub := mustSubscription(t, a, r.SiteID)
	end := sub.TrialEndsAt
	billingRequest(t, a, r.SiteID, "trial", map[string]any{"trial_days": 7}, "billing-opt-in-001")
	a.backup.workers.Wait()
	if !mustSubscription(t, a, r.SiteID).TrialEndsAt.Equal(end) {
		t.Fatal("trial replay reset deadline")
	}
	w := centralReq(t, a, "POST", "/v1/subscriptions/"+r.SiteID+"/trial", `{"trial_days":30}`, "billing-opt-in-002")
	if w.Code != 409 {
		t.Fatal("second free trial allowed")
	}
}
func TestSubscriptionSimulationPolicyStrictInputAndCalendarPeriods(t *testing.T) {
	a, in, runtime := managementFixture(t)
	w := centralReq(t, a, "PATCH", "/v1/subscription-policy", `{"simulation_enabled":false,"check_interval_seconds":1}`, "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	for _, op := range []string{"simulate-renewal", "simulate-expiry"} {
		w = centralReq(t, a, "POST", "/v1/subscriptions/"+in.SiteID+"/"+op, `{}`, "billing-disabled-"+op)
		if w.Code != 409 {
			t.Fatal("disabled simulation accepted", w.Code)
		}
	}
	expireClock(t, a, in.SiteID)
	billingRequest(t, a, in.SiteID, "reconcile", map[string]any{}, "billing-disabled-check")
	a.backup.workers.Wait()
	if runtime.running {
		t.Fatal("disabling simulation disabled enforcement")
	}
	for _, tc := range []struct {
		base   string
		months int
		want   string
	}{{"2027-01-31T12:30:00Z", 1, "2027-02-28T12:30:00Z"}, {"2028-01-31T12:30:00Z", 1, "2028-02-29T12:30:00Z"}, {"2028-02-29T12:30:00Z", 12, "2029-02-28T12:30:00Z"}} {
		base, _ := time.Parse(time.RFC3339, tc.base)
		if got := billingPeriodEnd(base, tc.months).Format(time.RFC3339); got != tc.want {
			t.Fatal(got, tc.want)
		}
	}
	w = centralReq(t, a, "PATCH", "/v1/subscription-policy", `{"check_interval_seconds":0}`, "")
	if w.Code != 400 {
		t.Fatal("invalid scheduler interval")
	}
	w = centralReq(t, a, "POST", "/v1/subscriptions/"+in.SiteID+"/simulate-renewal", `{"term":"monthly","package_id":"shop"}`, "billing-package-forge")
	if w.Code != 400 {
		t.Fatal("client changed package")
	}
}
func TestSubscriptionExpiryRecheckedAtWorkerExecution(t *testing.T) {
	a, in, runtime := managementFixture(t)
	sub := mustSubscription(t, a, in.SiteID)
	current := sub.TrialEndsAt.Add(-time.Second)
	a.now = func() time.Time { return current }
	entered := make(chan struct{})
	release := make(chan struct{})
	a.execute = func(ctx context.Context, req centralRequest, rel *Release) (any, error) {
		close(entered)
		<-release
		return a.executeJob(ctx, req, rel)
	}
	w := centralReq(t, a, "POST", "/v1/central/jobs", `{"operation":"start","site_id":"`+in.SiteID+`"}`, "billing-worker-guard-001")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	<-entered
	current = sub.TrialEndsAt
	close(release)
	a.backup.workers.Wait()
	var job centralJob
	json.Unmarshal(w.Body.Bytes(), &job)
	w = centralReq(t, a, "GET", "/v1/management/jobs/"+job.ID, "", "")
	if !strings.Contains(w.Body.String(), `"status":"failed"`) || !runtime.running {
		t.Fatal("late expiry bypassed")
	}
}

type billingFailRunner struct {
	Runner
	failStop bool
}

func (r *billingFailRunner) Run(ctx context.Context, args []string) ([]byte, error) {
	if r.failStop && len(args) > 0 && args[len(args)-1] == "stop" {
		r.failStop = false
		return nil, errors.New("private simulated runtime failure")
	}
	return r.Runner.Run(ctx, args)
}
func TestSubscriptionFailedStopRemainsPendingAndRetries(t *testing.T) {
	a, in, runtime := managementFixture(t)
	a.backup.engine.Runner = &billingFailRunner{Runner: runtime, failStop: true}
	expireClock(t, a, in.SiteID)
	billingRequest(t, a, in.SiteID, "reconcile", map[string]any{}, "billing-fail-check-001")
	a.backup.workers.Wait()
	sub := mustSubscription(t, a, in.SiteID)
	if sub.Suspended || !sub.ResumeOwned || sub.LastError == "" || !runtime.running || strings.Contains(sub.LastError, "private simulated") {
		t.Fatal("failed stop misreported", sub)
	}
	billingRequest(t, a, in.SiteID, "reconcile", map[string]any{}, "billing-fail-check-002")
	a.backup.workers.Wait()
	sub = mustSubscription(t, a, in.SiteID)
	if runtime.running || !sub.Suspended || sub.LastError != "" {
		t.Fatal("retry failed", sub)
	}
}
func TestManagementBillingProxySecurityAndPolicy(t *testing.T) {
	u, _, in := managementUIFixture(t)
	if w := managementUIRequest(u, "GET", "/ui/subscription-policy", false); w.Code != 401 {
		t.Fatal("policy unprotected")
	}
	r := httptest.NewRequest("POST", "http://"+u.bridge.host+"/ui/subscriptions/"+in.SiteID+"/simulate-renewal", strings.NewReader(`{"term":"monthly"}`))
	r.Header.Set("Content-Type", "application/json")
	r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
	r.Header.Set("Origin", "https://foreign.invalid")
	r.Header.Set("X-CSRF-Token", u.bridge.token)
	w := httptest.NewRecorder()
	u.ServeHTTP(w, r)
	if w.Code != 403 {
		t.Fatal("foreign payment origin allowed")
	}
	r.Header.Set("Origin", "http://"+u.bridge.host)
	r.Header.Set("Idempotency-Key", "billing-ui-001")
	r.Body = http.NoBody
	r.ContentLength = 0
	w = httptest.NewRecorder()
	u.ServeHTTP(w, r)
	if w.Code != 400 {
		t.Fatal("empty payment accepted", w.Code)
	}
}

func TestSubscriptionRenewalCommitsWhileRuntimeBusy(t *testing.T) {
	a, in, runtime := managementFixture(t)
	expireClock(t, a, in.SiteID)
	billingRequest(t, a, in.SiteID, "reconcile", map[string]any{}, "billing-busy-stop-001")
	a.backup.workers.Wait()
	a.backup.mu.Lock()
	a.backup.active = true
	a.backup.mu.Unlock()
	result := billingRequest(t, a, in.SiteID, "simulate-renewal", map[string]any{"term": "monthly"}, "billing-busy-payment-001")
	if result["reconciliation_pending"] != true || !mustSubscription(t, a, in.SiteID).view(a.subscriptionNow()).Entitled {
		t.Fatal("payment was lost while runtime busy")
	}
	a.backup.mu.Lock()
	a.backup.active = false
	a.backup.mu.Unlock()
	if err := a.queueSubscription(context.Background(), in.SiteID, "billing-busy-resume-001"); err != nil {
		t.Fatal(err)
	}
	a.backup.workers.Wait()
	if !runtime.running {
		t.Fatal("deferred renewal did not resume")
	}
}
func TestSubscriptionInvalidPaymentDateRollsBackWithoutCorruptingLedger(t *testing.T) {
	a, in, _ := managementFixture(t)
	sub := mustSubscription(t, a, in.SiteID)
	end := time.Date(9999, 12, 31, 12, 0, 0, 0, time.UTC)
	sub.PaidUntil = &end
	if err := a.saveSubscription(sub, "test-future-period"); err != nil {
		t.Fatal(err)
	}
	w := centralReq(t, a, "POST", "/v1/subscriptions/"+in.SiteID+"/simulate-renewal", `{"term":"yearly"}`, "billing-overflow-001")
	if w.Code != 409 {
		t.Fatal("unserializable period accepted", w.Code)
	}
	after := mustSubscription(t, a, in.SiteID)
	if after.PaidUntil == nil || !after.PaidUntil.Equal(end) {
		t.Fatal("failed payment corrupted existing period")
	}
}
