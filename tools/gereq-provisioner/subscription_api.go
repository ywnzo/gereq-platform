package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"time"
)

func (a *centralAPI) queueSubscription(ctx context.Context, site, key string) error {
	raw, _ := json.Marshal(centralRequest{Operation: "subscription-reconcile", SiteID: site})
	r, _ := http.NewRequestWithContext(ctx, "POST", "http://local/v1/central/jobs", bytes.NewReader(raw))
	r.Header.Set("Content-Type", "application/json")
	r.Header.Set("Idempotency-Key", key)
	capture := &managementCapture{headers: make(http.Header)}
	a.ServeHTTP(capture, r)
	if capture.status != 200 && capture.status != 202 {
		return errors.New("runtime reconciliation is pending")
	}
	return nil
}
func (a *centralAPI) subscriptionHTTP(w http.ResponseWriter, r *http.Request) bool {
	if r.URL.Path != "/v1/subscription-policy" && r.URL.Path != "/v1/subscriptions" && !strings.HasPrefix(r.URL.Path, "/v1/subscriptions/") {
		return false
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters are not supported")
		return true
	}
	if r.URL.Path == "/v1/subscription-policy" {
		if r.Method == "GET" {
			policy, err := a.subscriptions.policy()
			if err != nil {
				apiError(w, 503, "subscription policy unavailable")
			} else {
				apiJSON(w, 200, policy)
			}
			return true
		}
		if r.Method == "PATCH" {
			var input struct {
				Interval   *int  `json:"check_interval_seconds"`
				Simulation *bool `json:"simulation_enabled"`
			}
			if !parseAPIJSON(w, r, &input) {
				return true
			}
			a.subscriptionMu.Lock()
			defer a.subscriptionMu.Unlock()
			policy, err := a.subscriptions.policy()
			if err != nil {
				apiError(w, 503, "subscription policy unavailable")
				return true
			}
			if input.Interval != nil {
				if *input.Interval < 1 || *input.Interval > 3600 {
					apiError(w, 400, "check interval must be 1..3600 seconds")
					return true
				}
				policy.CheckIntervalSeconds = *input.Interval
			}
			if input.Simulation != nil {
				policy.SimulationEnabled = *input.Simulation
			}
			if a.subscriptions.setPolicy(policy) != nil {
				apiError(w, 503, "subscription policy could not be saved")
			} else {
				apiJSON(w, 200, policy)
			}
			return true
		}
	}
	if r.URL.Path == "/v1/subscriptions" && r.Method == "GET" {
		rows, err := a.subscriptions.list()
		if err != nil {
			apiError(w, 503, "subscriptions unavailable")
		} else {
			views := []subscriptionView{}
			for _, sub := range rows {
				views = append(views, sub.view(a.subscriptionNow()))
			}
			apiJSON(w, 200, map[string]any{"subscriptions": views})
		}
		return true
	}
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/subscriptions/"), "/")
	if len(parts) < 1 || len(parts) > 2 || !sitePattern.MatchString(parts[0]) {
		apiError(w, 404, "subscription not found")
		return true
	}
	site := parts[0]
	if len(parts) == 1 && r.Method == "GET" {
		sub, err := a.subscriptions.get(site)
		if err != nil {
			apiError(w, 503, "subscription unavailable")
		} else if sub == nil {
			apiError(w, 404, "subscription not found")
		} else {
			apiJSON(w, 200, sub.view(a.subscriptionNow()))
		}
		return true
	}
	if len(parts) != 2 || r.Method != "POST" || !contains([]string{"trial", "simulate-renewal", "simulate-expiry", "reconcile"}, parts[1]) {
		apiError(w, 405, "subscription operation not supported")
		return true
	}
	op := parts[1]
	key := r.Header.Get("Idempotency-Key")
	if !apiKeyPattern.MatchString(key) {
		apiError(w, 400, "valid Idempotency-Key required")
		return true
	}
	var input struct {
		TrialDays          *int   `json:"trial_days,omitempty"`
		ExpiresAt          string `json:"expires_at,omitempty"`
		Term               string `json:"term,omitempty"`
		ExpireAfterSeconds *int   `json:"expire_after_seconds,omitempty"`
	}
	if !parseAPIJSON(w, r, &input) {
		return true
	}
	if op != "simulate-expiry" && input.ExpireAfterSeconds != nil {
		apiError(w, 400, "invalid expiry options")
		return true
	}
	if input.ExpireAfterSeconds != nil && (*input.ExpireAfterSeconds < 0 || *input.ExpireAfterSeconds > 86400) {
		apiError(w, 400, "expiry delay must be 0..86400 seconds")
		return true
	}
	if op != "trial" && (input.TrialDays != nil || input.ExpiresAt != "") || op != "simulate-renewal" && input.Term != "" {
		apiError(w, 400, "invalid subscription options")
		return true
	}
	if op == "reconcile" {
		a.dispatchManagement(w, r, "/v1/central/jobs", centralRequest{Operation: "subscription-reconcile", SiteID: site}, false)
		return true
	}
	now := a.subscriptionNow()
	var sub *subscription
	var payment *simulatedPayment
	var err error
	hash := registryHash(map[string]any{"site_id": site, "operation": op, "input": input})
	if op == "trial" {
		if input.TrialDays != nil && (*input.TrialDays < 0 || *input.TrialDays > 365) {
			apiError(w, 400, "trial days must be 0..365")
			return true
		}
		var ends *time.Time
		if input.ExpiresAt != "" {
			parsed, e := time.Parse(time.RFC3339, input.ExpiresAt)
			if e != nil || parsed.After(now.AddDate(0, 0, 365)) {
				apiError(w, 400, "invalid trial expiry")
				return true
			}
			ends = &parsed
		}
		state, _, e := a.backup.engine.load(site)
		if e != nil {
			apiError(w, 409, "instance unavailable or requires recovery")
			return true
		}
		sub, err = a.enrollSubscription(state, input.TrialDays, ends, key, hash)
	} else {
		a.subscriptionMu.Lock()
		policy, e := a.subscriptions.policy()
		if e != nil || !policy.SimulationEnabled {
			a.subscriptionMu.Unlock()
			apiError(w, 409, "payment simulation is disabled")
			return true
		}
		now = a.subscriptionNow()
		sub, payment, err = a.subscriptions.mutate(site, key, hash, op, now, func(record *subscription) (*simulatedPayment, error) {
			if record.SiteID == "" {
				return nil, errors.New("subscription not enrolled")
			}
			if op == "simulate-expiry" {
				delay := 0
				if input.ExpireAfterSeconds != nil {
					delay = *input.ExpireAfterSeconds
				}
				record.TrialEndsAt = now.Add(time.Duration(delay) * time.Second)
				record.PaidUntil = nil
				return nil, nil
			}
			months := 0
			switch input.Term {
			case "monthly":
				months = 1
			case "yearly":
				months = 12
			default:
				return nil, errors.New("term must be monthly or yearly")
			}
			base := now
			if record.TrialEndsAt.After(base) {
				base = record.TrialEndsAt
			}
			if record.PaidUntil != nil && record.PaidUntil.After(base) {
				base = *record.PaidUntil
			}
			end := billingPeriodEnd(base, months)
			record.PaidUntil = &end
			return &simulatedPayment{ID: "sim-" + registryHash(key)[:24], SiteID: site, Term: input.Term, Simulated: true, PaidUntil: end, CreatedAt: now}, nil
		})
		a.subscriptionMu.Unlock()
	}
	if err != nil {
		if errors.Is(err, errSubscriptionConflict) {
			apiError(w, 409, "subscription already enrolled or idempotency key used for different input")
		} else {
			apiError(w, 409, "subscription mutation rejected")
		}
		return true
	}
	pending := a.queueSubscription(r.Context(), site, fmt.Sprintf("billing-%s-%d", site, sub.Revision)) != nil
	apiJSON(w, 200, map[string]any{"subscription": sub.view(a.subscriptionNow()), "payment": payment, "reconciliation_pending": pending})
	return true
}
