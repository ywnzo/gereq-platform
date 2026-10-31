package main

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"strings"
	"time"
)

const localDomainBase = "studio.gereq.cz"
const localDomainTarget = "ingress.studio.gereq.cz"

type siteDomain struct {
	Host        string `json:"hostname"`
	Site        string `json:"site_id"`
	Kind        string `json:"kind"`
	State       string `json:"state"`
	Primary     bool   `json:"primary"`
	Revision    int    `json:"revision"`
	Token       string `json:"verification_value,omitempty"`
	TXTName     string `json:"verification_name,omitempty"`
	Target      string `json:"dns_target"`
	DNS         bool   `json:"dns_verified"`
	Certificate bool   `json:"certificate_simulated"`
	Updated     string `json:"updated_at"`
}
type domainInput struct {
	Site        string `json:"site_id,omitempty"`
	Kind        string `json:"kind,omitempty"`
	Host        string `json:"hostname,omitempty"`
	Label       string `json:"label,omitempty"`
	Revision    int    `json:"revision,omitempty"`
	TXT         string `json:"txt_value,omitempty"`
	Target      string `json:"dns_target,omitempty"`
	Certificate bool   `json:"certificate_ok,omitempty"`
}
type domainStore struct{ db releaseRegistry }

func domainHostname(raw string) (string, error) {
	h := strings.ToLower(strings.TrimSpace(raw))
	if h == "" || len(h) > 253 || strings.HasSuffix(h, ".") || net.ParseIP(h) != nil || strings.ContainsAny(h, "/:@*\\%\r\n\t ") {
		return "", errors.New("invalid domain hostname; use ASCII/punycode without scheme or port")
	}
	labels := strings.Split(h, ".")
	if len(labels) < 2 {
		return "", errors.New("domain must contain a suffix")
	}
	for _, l := range labels {
		if len(l) < 1 || len(l) > 63 || l[0] == '-' || l[len(l)-1] == '-' {
			return "", errors.New("invalid domain label")
		}
		for _, c := range l {
			if !(c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '-') {
				return "", errors.New("invalid domain label")
			}
		}
	}
	return h, nil
}
func reservedDomainLabel(s string) bool {
	return contains([]string{"www", "admin", "api", "mail", "smtp", "support", "status", "ingress", "localhost", "root", "security", "billing"}, s)
}
func (s domainStore) initialize() error {
	db, e := s.db.open()
	if e != nil {
		return e
	}
	defer db.Close()
	var version int
	if e = db.QueryRow("PRAGMA user_version").Scan(&version); e != nil {
		return e
	}
	if version > 2 {
		return errors.New("unsupported domain registry version")
	}
	_, e = db.Exec(`CREATE TABLE IF NOT EXISTS domains(host TEXT PRIMARY KEY,site TEXT NOT NULL,record TEXT NOT NULL); CREATE INDEX IF NOT EXISTS domains_site ON domains(site); CREATE TABLE IF NOT EXISTS domain_events(id INTEGER PRIMARY KEY AUTOINCREMENT,host TEXT NOT NULL,action TEXT NOT NULL,occurred_at TEXT NOT NULL);`)
	if e != nil {
		return e
	}
	return s.migrateStudioBase(db)
}
func (s domainStore) list() ([]siteDomain, error) {
	db, e := s.db.open()
	if e != nil {
		return nil, e
	}
	defer db.Close()
	rows, e := db.Query("SELECT record FROM domains ORDER BY host")
	if e != nil {
		return nil, e
	}
	defer rows.Close()
	result := []siteDomain{}
	for rows.Next() {
		var raw string
		var d siteDomain
		if e = rows.Scan(&raw); e != nil {
			return nil, e
		}
		if e = json.Unmarshal([]byte(raw), &d); e != nil {
			return nil, e
		}
		result = append(result, d)
	}
	return result, rows.Err()
}
func readDomain(tx *sql.Tx, h string) (siteDomain, error) {
	var d siteDomain
	var raw string
	e := tx.QueryRow("SELECT record FROM domains WHERE host=?", h).Scan(&raw)
	if e == nil {
		e = json.Unmarshal([]byte(raw), &d)
	}
	return d, e
}
func writeDomain(tx *sql.Tx, d siteDomain, action string) error {
	raw, e := json.Marshal(d)
	if e != nil {
		return e
	}
	_, e = tx.Exec("INSERT INTO domains(host,site,record) VALUES(?,?,?) ON CONFLICT(host) DO UPDATE SET record=excluded.record,site=excluded.site", d.Host, d.Site, string(raw))
	if e != nil {
		return e
	}
	_, e = tx.Exec("INSERT INTO domain_events(host,action,occurred_at) VALUES(?,?,?)", d.Host, action, d.Updated)
	return e
}
func (s domainStore) change(host, op string, in domainInput) (siteDomain, error) {
	db, e := s.db.open()
	if e != nil {
		return siteDomain{}, e
	}
	defer db.Close()
	tx, e := db.Begin()
	if e != nil {
		return siteDomain{}, e
	}
	defer tx.Rollback()
	d, e := readDomain(tx, host)
	now := time.Now().UTC().Format(time.RFC3339Nano)
	if op == "assign" {
		if e == nil && d.State != "removed" {
			if d.Site == in.Site && d.Kind == in.Kind {
				return d, nil
			}
			return d, errors.New("domain already reserved by another site")
		}
		if e != nil && !errors.Is(e, sql.ErrNoRows) {
			return d, e
		}
		revision := d.Revision + 1
		d = siteDomain{Host: host, Site: in.Site, Kind: in.Kind, State: "awaiting_dns", Revision: revision, Target: localDomainTarget, Updated: now}
		if in.Kind == "subdomain" {
			d.State = "active"
			d.DNS = true
			d.Certificate = true
		} else {
			var token [24]byte
			if _, e = rand.Read(token[:]); e != nil {
				return d, e
			}
			d.Token = "gereq-verify-" + hex.EncodeToString(token[:])
			d.TXTName = "_gereq-verification." + host
		}
		if in.Kind == "subdomain" {
			items, err := domainTxList(tx, in.Site)
			if err != nil {
				return d, err
			}
			hasPrimary := false
			for _, x := range items {
				if x.Kind == "subdomain" && x.State != "removed" && x.Host != host {
					return d, errors.New("site already has its fallback subdomain")
				}
				hasPrimary = hasPrimary || x.Primary && x.State == "active"
			}
			d.Primary = !hasPrimary
		}
	} else {
		if e != nil {
			return d, errors.New("domain not found")
		}
		if d.State == "removed" {
			return d, errors.New("domain has been removed")
		}
		if d.Revision != in.Revision {
			return d, errors.New("domain changed; reload its revision")
		}
		d.Revision++
		d.Updated = now
		switch op {
		case "simulate":
			if d.Kind != "custom" {
				return d, errors.New("only custom domains use simulated validation")
			}
			d.DNS = in.TXT == d.Token && strings.EqualFold(in.Target, d.Target)
			d.Certificate = d.DNS && in.Certificate
			d.State = "awaiting_dns"
			if d.DNS {
				d.State = "issuing_certificate"
			}
			if d.Certificate {
				d.State = "active"
			}
		case "primary":
			if d.State != "active" || !d.Certificate {
				return d, errors.New("domain must be active before becoming primary")
			}
			d.Primary = true
		case "remove":
			if d.Kind == "subdomain" {
				return d, errors.New("the fallback subdomain cannot be removed")
			}
			d.State = "removed"
			d.DNS = false
			d.Certificate = false
		default:
			return d, errors.New("unsupported domain operation")
		}
		if op == "primary" || d.Primary && d.State != "active" {
			items, err := domainTxList(tx, d.Site)
			if err != nil {
				return d, err
			}
			for _, other := range items {
				if other.Host == d.Host {
					continue
				}
				primary := op != "primary" && other.Kind == "subdomain" && other.State == "active"
				if other.Primary != primary {
					other.Primary = primary
					other.Revision++
					other.Updated = now
					if err = writeDomain(tx, other, "primary-changed"); err != nil {
						return d, err
					}
				}
			}
			if op != "primary" {
				d.Primary = false
			}
		}
	}
	if e = writeDomain(tx, d, op); e != nil {
		return d, e
	}
	return d, tx.Commit()
}
func domainTxList(tx *sql.Tx, site string) ([]siteDomain, error) {
	rows, e := tx.Query("SELECT record FROM domains WHERE site=? ORDER BY host", site)
	if e != nil {
		return nil, e
	}
	defer rows.Close()
	var result []siteDomain
	for rows.Next() {
		var raw string
		var d siteDomain
		if e = rows.Scan(&raw); e != nil {
			return nil, e
		}
		if e = json.Unmarshal([]byte(raw), &d); e != nil {
			return nil, e
		}
		result = append(result, d)
	}
	return result, rows.Err()
}

// These endpoints are private operator APIs. DNS and certificates are simulations only.
func (a *centralAPI) domainsHTTP(w http.ResponseWriter, r *http.Request) bool {
	if r.URL.Path != "/v1/domains" && !strings.HasPrefix(r.URL.Path, "/v1/domains/") && !strings.HasPrefix(r.URL.Path, "/v1/domain-route/") {
		return false
	}
	if r.Method == "GET" && strings.HasPrefix(r.URL.Path, "/v1/domain-route/") {
		a.domainRoute(w, r)
		return true
	}
	a.domainsMu.Lock()
	defer a.domainsMu.Unlock()
	if r.URL.Path == "/v1/domains" && r.Method == "GET" {
		items, e := a.domains.list()
		if e != nil {
			apiError(w, 500, "domain registry unavailable")
		} else {
			apiJSON(w, 200, map[string]any{"domains": items, "base_domain": localDomainBase, "dns_target": localDomainTarget, "simulation": true, "router_port": 18154})
		}
		return true
	}
	var in domainInput
	if !parseAPIJSON(w, r, &in) {
		return true
	}
	host := ""
	op := ""
	if r.URL.Path == "/v1/domains" && r.Method == "POST" {
		op = "assign"
		if !sitePattern.MatchString(in.Site) {
			apiError(w, 400, "invalid site ID")
			return true
		}
		if _, _, e := a.backup.engine.load(in.Site); e != nil {
			apiError(w, 404, "instance not found")
			return true
		}
		if in.Kind == "subdomain" {
			if !sitePattern.MatchString(in.Label) || reservedDomainLabel(in.Label) {
				apiError(w, 400, "invalid or reserved subdomain")
				return true
			}
			host = in.Label + "." + localDomainBase
		} else if in.Kind == "custom" {
			var e error
			host, e = domainHostname(in.Host)
			if e != nil || strings.HasSuffix(host, ".localhost") || host == "localhost" || host == localDomainBase || strings.HasSuffix(host, "."+localDomainBase) {
				apiError(w, 400, "invalid custom domain")
				return true
			}
			items, err := a.domains.list()
			if err != nil {
				apiError(w, 500, "domain registry unavailable")
				return true
			}
			hasFallback := false
			for _, item := range items {
				if item.Site == in.Site && item.Kind == "subdomain" && item.State != "removed" {
					hasFallback = true
				}
			}
			if !hasFallback {
				if reservedDomainLabel(in.Site) {
					apiError(w, 409, "assign an unreserved fallback subdomain first")
					return true
				}
				if _, err = a.domains.change(in.Site+"."+localDomainBase, "assign", domainInput{Site: in.Site, Kind: "subdomain"}); err != nil {
					apiError(w, 409, err.Error())
					return true
				}
			}
		} else {
			apiError(w, 400, "kind must be subdomain or custom")
			return true
		}
	} else {
		parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/domains/"), "/")
		if len(parts) > 2 {
			apiError(w, 404, "domain operation not found")
			return true
		}
		var e error
		host, e = domainHostname(parts[0])
		if e != nil {
			apiError(w, 400, e.Error())
			return true
		}
		if len(parts) == 1 && r.Method == "DELETE" {
			op = "remove"
		} else if len(parts) == 2 && r.Method == "POST" && contains([]string{"simulate", "primary"}, parts[1]) {
			op = parts[1]
		} else {
			apiError(w, 404, "domain operation not found")
			return true
		}
	}
	d, e := a.domains.change(host, op, in)
	if e != nil {
		apiError(w, 409, e.Error())
	} else {
		apiJSON(w, 200, d)
	}
	return true
}
func (a *centralAPI) ensureSiteDomain(site string) error {
	if !sitePattern.MatchString(site) || reservedDomainLabel(site) {
		return errors.New("invalid or reserved subdomain")
	}
	a.domainsMu.Lock()
	defer a.domainsMu.Unlock()
	_, e := a.domains.change(site+"."+localDomainBase, "assign", domainInput{Site: site, Kind: "subdomain"})
	return e
}
func (a *centralAPI) domainRoute(w http.ResponseWriter, r *http.Request) {
	host, e := domainHostname(strings.TrimPrefix(r.URL.Path, "/v1/domain-route/"))
	if e != nil {
		apiError(w, 404, "unknown domain")
		return
	}
	a.domainsMu.Lock()
	d, primary, e := a.domains.route(host)
	a.domainsMu.Unlock()
	if errors.Is(e, sql.ErrNoRows) || e == nil && (d.State != "active" || !d.Certificate) {
		apiError(w, 404, "domain is not active")
		return
	}
	if e != nil {
		apiError(w, 503, "domain registry unavailable")
		return
	}
	state, _, e := a.backup.engine.load(d.Site)
	if e != nil || !contains([]string{"ready", "needs_customer_setup"}, state.Status) {
		apiError(w, 503, "instance is stopped or unavailable")
		return
	}
	if e = a.subscriptionAllows(d.Site); e != nil {
		apiError(w, 503, "instance is not entitled")
		return
	}
	apiJSON(w, 200, map[string]any{"site_id": d.Site, "port": state.Request.Port, "upstream_host": d.Site + ".localhost", "primary": primary})
}

// Resolve one indexed hostname and only that site's aliases in one consistent read transaction.
func (s domainStore) route(host string) (siteDomain, string, error) {
	db, e := s.db.open()
	if e != nil {
		return siteDomain{}, "", e
	}
	defer db.Close()
	tx, e := db.Begin()
	if e != nil {
		return siteDomain{}, "", e
	}
	defer tx.Rollback()
	d, e := readDomain(tx, host)
	if e != nil {
		return d, "", e
	}
	items, e := domainTxList(tx, d.Site)
	if e != nil {
		return d, "", e
	}
	primary := ""
	for _, item := range items {
		if item.Primary && item.State == "active" && item.Certificate {
			primary = item.Host
		}
	}
	return d, primary, nil
}

// Upgrade only local simulation assignments; conflicting claims are never overwritten.
func (s domainStore) migrateStudioBase(db *sql.DB) error {
	tx, e := db.Begin()
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var version int
	if e = tx.QueryRow("PRAGMA user_version").Scan(&version); e != nil {
		return e
	}
	if version == 2 {
		return nil
	}
	rows, e := tx.Query("SELECT record FROM domains")
	if e != nil {
		return e
	}
	var items []siteDomain
	for rows.Next() {
		var raw string
		var d siteDomain
		if e = rows.Scan(&raw); e != nil {
			rows.Close()
			return e
		}
		if e = json.Unmarshal([]byte(raw), &d); e != nil {
			rows.Close()
			return e
		}
		items = append(items, d)
	}
	e = rows.Err()
	rows.Close()
	if e != nil {
		return e
	}
	now := time.Now().UTC().Format(time.RFC3339Nano)
	for i := range items {
		d := &items[i]
		changed := false
		if d.Kind == "subdomain" && strings.HasSuffix(d.Host, ".sites.localhost") {
			previous := d.Host
			next := strings.TrimSuffix(previous, ".sites.localhost") + "." + localDomainBase
			var exists int
			if e = tx.QueryRow("SELECT count(*) FROM domains WHERE host=?", next).Scan(&exists); e != nil {
				return e
			}
			if exists != 0 {
				return errors.New("studio domain migration conflicts with an existing hostname")
			}
			if _, e = tx.Exec("DELETE FROM domains WHERE host=?", previous); e != nil {
				return e
			}
			d.Host = next
			changed = true
		}
		if d.Target == "ingress.sites.localhost" {
			d.Target = localDomainTarget
			changed = true
			if d.Kind == "custom" && d.State != "removed" {
				d.DNS = false
				d.Certificate = false
				d.State = "awaiting_dns"
				d.Primary = false
			}
		}
		if changed {
			d.Revision++
			d.Updated = now
			if e = writeDomain(tx, *d, "studio-base-migrated"); e != nil {
				return e
			}
		}
	}
	primarySites := map[string]bool{}
	for _, d := range items {
		if d.Primary && d.State == "active" {
			primarySites[d.Site] = true
		}
	}
	for _, d := range items {
		if d.Kind == "subdomain" && d.State == "active" && !primarySites[d.Site] {
			d.Primary = true
			d.Revision++
			d.Updated = now
			if e = writeDomain(tx, d, "fallback-restored"); e != nil {
				return e
			}
			primarySites[d.Site] = true
		}
	}
	if _, e = tx.Exec("PRAGMA user_version=2"); e != nil {
		return e
	}
	return tx.Commit()
}
