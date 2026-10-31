package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"time"
)

type subscription struct {
	Package         *catalogPackage `json:"package_snapshot,omitempty"`
	SiteID          string          `json:"site_id"`
	CustomerRef     string          `json:"customer_ref"`
	PackageID       string          `json:"package_id"`
	PackageRevision int             `json:"package_revision"`
	TrialEndsAt     time.Time       `json:"trial_ends_at"`
	PaidUntil       *time.Time      `json:"paid_until,omitempty"`
	Suspended       bool            `json:"suspended"`
	ResumeOwned     bool            `json:"resume_owned"`
	Transition      string          `json:"transition,omitempty"`
	LastError       string          `json:"error,omitempty"`
	Revision        int             `json:"revision"`
	CreatedAt       time.Time       `json:"created_at"`
	UpdatedAt       time.Time       `json:"updated_at"`
}
type subscriptionView struct {
	subscription
	Status   string `json:"status"`
	Entitled bool   `json:"entitled"`
}

func (s subscription) view(now time.Time) subscriptionView {
	status := "expired"
	if s.PaidUntil != nil && now.Before(*s.PaidUntil) {
		status = "active"
	} else if now.Before(s.TrialEndsAt) {
		status = "trialing"
	}
	return subscriptionView{s, status, status != "expired"}
}

type simulatedPayment struct {
	ID        string    `json:"id"`
	SiteID    string    `json:"site_id"`
	Term      string    `json:"term"`
	Simulated bool      `json:"simulated"`
	PaidUntil time.Time `json:"paid_until"`
	CreatedAt time.Time `json:"created_at"`
}
type subscriptionPolicy struct {
	CheckIntervalSeconds int  `json:"check_interval_seconds"`
	SimulationEnabled    bool `json:"simulation_enabled"`
}
type subscriptionStore struct{ db releaseRegistry }

func (s subscriptionStore) initialize() error {
	db, err := s.db.open()
	if err != nil {
		return err
	}
	defer db.Close()
	var version int
	if err = db.QueryRow("PRAGMA user_version").Scan(&version); err != nil {
		return err
	}
	if version > 2 {
		return errors.New("unsupported subscription schema")
	}
	tx, err := db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	_, err = tx.Exec(`CREATE TABLE IF NOT EXISTS subscriptions(site_id TEXT PRIMARY KEY,record TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS subscription_events(sequence INTEGER PRIMARY KEY AUTOINCREMENT,site_id TEXT NOT NULL,action TEXT NOT NULL,occurred_at TEXT NOT NULL,details TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS subscription_requests(request_key TEXT PRIMARY KEY,payload_hash TEXT NOT NULL,site_id TEXT NOT NULL,payment TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS subscription_policy(id INTEGER PRIMARY KEY CHECK(id=1),check_interval_seconds INTEGER NOT NULL,simulation_enabled INTEGER NOT NULL);
 INSERT OR IGNORE INTO subscription_policy VALUES(1,15,1);
 CREATE TABLE IF NOT EXISTS generation_usage(site_id TEXT NOT NULL,request_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(site_id,request_id));
 PRAGMA user_version=2;`)
	if err != nil {
		return err
	}
	return tx.Commit()
}
func (s subscriptionStore) policy() (subscriptionPolicy, error) {
	db, err := s.db.open()
	if err != nil {
		return subscriptionPolicy{}, err
	}
	defer db.Close()
	var policy subscriptionPolicy
	err = db.QueryRow("SELECT check_interval_seconds,simulation_enabled FROM subscription_policy WHERE id=1").Scan(&policy.CheckIntervalSeconds, &policy.SimulationEnabled)
	return policy, err
}
func (s subscriptionStore) setPolicy(p subscriptionPolicy) error {
	db, err := s.db.open()
	if err != nil {
		return err
	}
	defer db.Close()
	tx, err := db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err = tx.Exec("UPDATE subscription_policy SET check_interval_seconds=?,simulation_enabled=? WHERE id=1", p.CheckIntervalSeconds, p.SimulationEnabled); err != nil {
		return err
	}
	raw, _ := json.Marshal(p)
	if _, err = tx.Exec("INSERT INTO subscription_events(site_id,action,occurred_at,details) VALUES('','policy',?,?)", time.Now().UTC().Format(time.RFC3339Nano), string(raw)); err != nil {
		return err
	}
	return tx.Commit()
}
func (s subscriptionStore) get(site string) (*subscription, error) {
	db, err := s.db.open()
	if err != nil {
		return nil, err
	}
	defer db.Close()
	var raw string
	if err = db.QueryRow("SELECT record FROM subscriptions WHERE site_id=?", site).Scan(&raw); errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	} else if err != nil {
		return nil, err
	}
	var sub subscription
	err = json.Unmarshal([]byte(raw), &sub)
	if err == nil && sub.SiteID != site {
		err = errors.New("invalid subscription identity")
	}
	return &sub, err
}
func (s subscriptionStore) list() ([]subscription, error) {
	db, err := s.db.open()
	if err != nil {
		return nil, err
	}
	defer db.Close()
	rows, err := db.Query("SELECT record FROM subscriptions ORDER BY site_id")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	result := []subscription{}
	for rows.Next() {
		var raw string
		var sub subscription
		if err = rows.Scan(&raw); err != nil {
			return nil, err
		}
		if err = json.Unmarshal([]byte(raw), &sub); err != nil {
			return nil, err
		}
		result = append(result, sub)
	}
	return result, rows.Err()
}

var errSubscriptionConflict = errors.New("subscription command conflicts with existing state")

// Metadata, audit and the simulated payment receipt commit together. A retry never grants another period.
func (s subscriptionStore) mutate(site, key, hash, action string, now time.Time, change func(*subscription) (*simulatedPayment, error)) (*subscription, *simulatedPayment, error) {
	db, err := s.db.open()
	if err != nil {
		return nil, nil, err
	}
	defer db.Close()
	tx, err := db.Begin()
	if err != nil {
		return nil, nil, err
	}
	defer tx.Rollback()
	var raw string
	var sub subscription
	err = tx.QueryRow("SELECT record FROM subscriptions WHERE site_id=?", site).Scan(&raw)
	if err == nil {
		if err = json.Unmarshal([]byte(raw), &sub); err != nil {
			return nil, nil, err
		}
	} else if !errors.Is(err, sql.ErrNoRows) {
		return nil, nil, err
	}
	if key != "" {
		var previousHash, previousSite, paymentRaw string
		err = tx.QueryRow("SELECT payload_hash,site_id,payment FROM subscription_requests WHERE request_key=?", key).Scan(&previousHash, &previousSite, &paymentRaw)
		if err == nil {
			if previousHash != hash || previousSite != site {
				return nil, nil, errSubscriptionConflict
			}
			var payment *simulatedPayment
			if err = json.Unmarshal([]byte(paymentRaw), &payment); err != nil {
				return nil, nil, err
			}
			return &sub, payment, nil
		}
		if !errors.Is(err, sql.ErrNoRows) {
			return nil, nil, err
		}
	}
	revision := sub.Revision
	payment, err := change(&sub)
	if err != nil {
		return nil, nil, err
	}
	sub.Revision = revision + 1
	sub.UpdatedAt = now
	rawBytes, marshalErr := json.Marshal(sub)
	if marshalErr != nil {
		return nil, nil, marshalErr
	}
	if _, err = tx.Exec("INSERT INTO subscriptions(site_id,record) VALUES(?,?) ON CONFLICT(site_id) DO UPDATE SET record=excluded.record", site, string(rawBytes)); err != nil {
		return nil, nil, err
	}
	detail, marshalErr := json.Marshal(map[string]any{"revision": sub.Revision, "trial_ends_at": sub.TrialEndsAt, "paid_until": sub.PaidUntil, "suspended": sub.Suspended, "transition": sub.Transition, "payment": payment, "package_id": sub.PackageID, "package_revision": sub.PackageRevision, "package_snapshot": sub.Package})
	if marshalErr != nil {
		return nil, nil, marshalErr
	}
	if _, err = tx.Exec("INSERT INTO subscription_events(site_id,action,occurred_at,details) VALUES(?,?,?,?)", site, action, now.Format(time.RFC3339Nano), string(detail)); err != nil {
		return nil, nil, err
	}
	if key != "" {
		receipt, marshalErr := json.Marshal(payment)
		if marshalErr != nil {
			return nil, nil, marshalErr
		}
		if _, err = tx.Exec("INSERT INTO subscription_requests(request_key,payload_hash,site_id,payment) VALUES(?,?,?,?)", key, hash, site, string(receipt)); err != nil {
			return nil, nil, err
		}
	}
	if err = tx.Commit(); err != nil {
		return nil, nil, err
	}
	return &sub, payment, nil
}
func (a *centralAPI) subscriptionNow() time.Time {
	if a.now != nil {
		return a.now().UTC()
	}
	return time.Now().UTC()
}
func (a *centralAPI) subscriptionAllows(site string) error {
	sub, err := a.subscriptions.get(site)
	if err != nil {
		return errors.New("subscription state unavailable")
	}
	if sub != nil && !sub.view(a.subscriptionNow()).Entitled {
		return errors.New("subscription expired; renew before this operation")
	}
	return nil
}
func (a *centralAPI) enrollSubscription(state *State, days *int, ends *time.Time, key, hash string) (*subscription, error) {
	a.subscriptionMu.Lock()
	defer a.subscriptionMu.Unlock()
	now := a.subscriptionNow()
	if key == "" {
		existing, err := a.subscriptions.get(state.Request.SiteID)
		if err != nil {
			return nil, err
		}
		if existing != nil {
			id := subscriptionPackageID(state, existing)
			if existing.CustomerRef != state.Request.CustomerRef || existing.PackageID != id {
				return nil, errors.New("subscription identity mismatch")
			}
			return existing, nil
		}
	}
	returnSub, _, err := a.subscriptions.mutate(state.Request.SiteID, key, hash, "trial-started", now, func(sub *subscription) (*simulatedPayment, error) {
		if sub.SiteID != "" {
			if key == "" {
				return nil, nil
			}
			return nil, errSubscriptionConflict
		}
		catalog, err := a.loadCatalog()
		if err != nil {
			return nil, err
		}
		id := state.Request.CatalogPackageID
		if id == "" {
			id = state.Request.Package
		}
		p, ok := catalog.Packages[id]
		if !ok {
			return nil, errors.New("package unavailable")
		}
		count := p.TrialDays
		if state.Request.TrialDays != nil {
			count = *state.Request.TrialDays
		}
		if days != nil {
			count = *days
		}
		end := now.AddDate(0, 0, count)
		if ends != nil {
			end = ends.UTC()
		}
		*sub = subscription{SiteID: state.Request.SiteID, CustomerRef: state.Request.CustomerRef, PackageID: id, PackageRevision: state.Request.CatalogPackageRevision, TrialEndsAt: end, CreatedAt: now, Package: &p}
		return nil, nil
	})
	return returnSub, err
}
func (a *centralAPI) saveSubscription(sub *subscription, action string) error {
	next, _, err := a.subscriptions.mutate(sub.SiteID, "", "", action, a.subscriptionNow(), func(existing *subscription) (*simulatedPayment, error) { *existing = *sub; return nil, nil })
	if err == nil {
		*sub = *next
	}
	return err
}
func (a *centralAPI) noteManualStop(site string) error {
	a.subscriptionMu.Lock()
	defer a.subscriptionMu.Unlock()
	sub, err := a.subscriptions.get(site)
	if err != nil || sub == nil {
		return err
	}
	sub.ResumeOwned = false
	sub.Transition = ""
	return a.saveSubscription(sub, "manual-stop")
}
func (a *centralAPI) reconcileSubscription(ctx context.Context, site string) (any, error) {
	a.subscriptionMu.Lock()
	defer a.subscriptionMu.Unlock()
	sub, err := a.subscriptions.get(site)
	if err != nil || sub == nil {
		return nil, errors.New("subscription not found")
	}
	state, _, err := a.backup.engine.load(site)
	fail := func(cause error) (any, error) {
		sub.LastError = "Runtime reconciliation failed; inspect the instance before retrying."
		_ = a.saveSubscription(sub, "reconcile-failed")
		return nil, cause
	}
	if err != nil {
		return fail(err)
	}
	id := subscriptionPackageID(state, sub)
	if sub.CustomerRef != state.Request.CustomerRef || sub.PackageID != id {
		return fail(errors.New("subscription does not match instance identity"))
	}
	view := sub.view(a.subscriptionNow())
	if view.Entitled && !sub.ResumeOwned {
		if sub.Suspended || sub.Transition != "" || sub.LastError != "" {
			sub.Suspended = false
			sub.Transition = ""
			sub.LastError = ""
			if err = a.saveSubscription(sub, "entitlement-restored"); err != nil {
				return nil, err
			}
		}
		return sub.view(a.subscriptionNow()), nil
	}
	observed, err := a.inspectManaged(ctx, site)
	if err != nil {
		return fail(err)
	}
	if observed.Runtime != "running" && observed.Runtime != "stopped" {
		return fail(errors.New("runtime status unavailable"))
	}
	if !view.Entitled {
		if observed.Runtime == "running" {
			sub.ResumeOwned = true
			sub.Transition = "suspend"
			if err = a.saveSubscription(sub, "suspend-requested"); err != nil {
				return nil, err
			}
			if _, err = a.backup.engine.Lifecycle(ctx, site, "stop"); err != nil {
				return fail(err)
			}
		}
		observed, err = a.inspectManaged(ctx, site)
		if err != nil || observed.Runtime != "stopped" {
			return fail(errors.New("suspension could not be verified"))
		}
		sub.Suspended = true
		sub.Transition = ""
		sub.LastError = ""
		if err = a.saveSubscription(sub, "suspended"); err != nil {
			return nil, err
		}
		return sub.view(a.subscriptionNow()), nil
	}
	sub.Transition = "resume"
	if err = a.saveSubscription(sub, "resume-requested"); err != nil {
		return nil, err
	}
	if observed.Runtime == "stopped" {
		if _, err = a.backup.engine.Lifecycle(ctx, site, "start"); err != nil {
			return fail(err)
		}
	}
	observed, err = a.inspectManaged(ctx, site)
	if err != nil || !observed.Healthy {
		return fail(errors.New("renewed runtime health could not be verified"))
	}
	sub.Suspended = false
	sub.ResumeOwned = false
	sub.Transition = ""
	sub.LastError = ""
	if err = a.saveSubscription(sub, "resumed"); err != nil {
		return nil, err
	}
	return sub.view(a.subscriptionNow()), nil
}
func billingPeriodEnd(base time.Time, months int) time.Time {
	first := time.Date(base.Year(), base.Month()+time.Month(months), 1, base.Hour(), base.Minute(), base.Second(), base.Nanosecond(), time.UTC)
	last := first.AddDate(0, 1, -1).Day()
	day := base.Day()
	if day > last {
		day = last
	}
	return first.AddDate(0, 0, day-1)
}
func (a *centralAPI) dueSubscriptions() ([]subscription, error) {
	rows, err := a.subscriptions.list()
	if err != nil {
		return nil, err
	}
	due := []subscription{}
	for _, sub := range rows {
		view := sub.view(a.subscriptionNow())
		if (!view.Entitled && !sub.Suspended) || (view.Entitled && (sub.ResumeOwned || sub.Suspended)) || sub.Transition != "" || sub.LastError != "" {
			due = append(due, sub)
		}
	}
	sort.Slice(due, func(i, j int) bool { return due[i].UpdatedAt.Before(due[j].UpdatedAt) })
	return due, nil
}
func (a *centralAPI) runSubscriptionScheduler(ctx context.Context) {
	for {
		policy, err := a.subscriptions.policy()
		interval := 15
		if err == nil && policy.CheckIntervalSeconds >= 1 && policy.CheckIntervalSeconds <= 3600 {
			interval = policy.CheckIntervalSeconds
		}
		a.backup.mu.Lock()
		busy := a.backup.active
		a.backup.mu.Unlock()
		if !busy {
			if rows, err := a.dueSubscriptions(); err == nil && len(rows) > 0 {
				_ = a.queueSubscription(ctx, rows[0].SiteID, fmt.Sprintf("subscription-%s-%d", rows[0].SiteID, a.subscriptionNow().UnixNano()))
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(time.Duration(interval) * time.Second):
		}
	}
}
