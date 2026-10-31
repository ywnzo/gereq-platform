package main

import (
	"bytes"
	"crypto/hmac"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

func selfJob(j customerJob) map[string]any {
	v := map[string]any{"id": j.ID, "site_id": j.Input.SiteID, "status": j.Status, "phase": j.Phase, "retryable": j.Status == "failed" || j.Status == "interrupted"}
	if j.Error != "" {
		v["failure_code"] = j.FailureCode
		switch j.FailureCode {
		case "invalid_generated_design":
			v["error"] = "AI návrh nesplnil pravidla stránky. Koncept nebyl uložen. Zkuste vytvoření opakovat."
		case "ai_timeout":
			v["error"] = "AI nestihla návrh dokončit. Instance je zachovaná; můžete generování zopakovat."
		case "ai_rejected":
			v["error"] = "AI služba požadavek odmítla. Obraťte se na podporu."
		default:
			v["error"] = "Vytvoření webu vyžaduje opakování nebo pomoc podpory."
		}
	}
	if j.Result != nil {
		v["admin_url"] = j.Result.AdminURL
		v["preview_url"] = j.Result.PreviewURL
		v["builder_url"] = j.Result.BuilderURL
	}
	return v
}
func (a *centralAPI) selfSubscription(account, site string) (map[string]any, bool) {
	ref, e := a.accounts.owned(account, site)
	if e != nil {
		return nil, false
	}
	s, e := a.subscriptions.get(site)
	if e != nil || s == nil || s.CustomerRef != ref {
		return nil, false
	}
	v := s.view(time.Now().UTC())
	out := map[string]any{"site_id": site, "package_id": s.PackageID, "status": v.Status, "entitled": v.Entitled, "suspended": s.Suspended, "trial_ends_at": s.TrialEndsAt, "paid_until": s.PaidUntil}
	if s.Package != nil {
		out["package_name"] = s.Package.Name
		out["currency"] = s.Package.Currency
		out["monthly_minor"] = s.Package.MonthlyMinor
		out["yearly_minor"] = s.Package.YearlyMinor
	}
	return out, true
}
func (a *centralAPI) selfSite(account, site string) (map[string]any, bool) {
	ref, e := a.accounts.owned(account, site)
	if e != nil {
		return nil, false
	}
	v := map[string]any{"site_id": site, "status": "pending"}
	s, _, e := a.backup.engine.load(site)
	if e == nil {
		if s.Request.CustomerRef != ref {
			return nil, false
		}
		v["name"] = s.Request.BrandName
		v["status"] = s.Status
		v["modules"] = instanceModules(s)
		base := fmt.Sprintf("http://%s.localhost:%d", site, s.Request.Port)
		v["storefront_url"] = base + "/"
		v["admin_url"] = base + "/admin"
	} else {
		// Fail closed on identity mismatch, including files under maintenance.
		dir, err := ownedDir(a.backup.engine.Root, site)
		if err != nil {
			return nil, false
		}
		var saved State
		if decode(filepath.Join(dir, "state.json"), &saved) == nil {
			if saved.Request.SiteID != site || saved.Request.CustomerRef != ref {
				return nil, false
			}
			v["status"] = "maintenance"
		}
	}
	if sub, ok := a.selfSubscription(account, site); ok {
		v["subscription"] = sub
	}
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	entries, _ := os.ReadDir(a.customerDir())
	for _, entry := range entries {
		var j customerJob
		if decode(filepath.Join(a.customerDir(), entry.Name()), &j) == nil && j.Input.SiteID == site && j.Input.CustomerRef == ref {
			v["job"] = selfJob(j)
		}
	}
	if _, ok := v["job"]; !ok && v["status"] == "pending" {
		v["creation_retryable"] = true
	}
	return v, true
}
func (a *centralAPI) selfServiceHTTP(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/v1/self-service/") {
		return false
	}
	w.Header().Set("Cache-Control", "no-store")
	if r.URL.RawQuery != "" {
		apiError(w, 400, "Parametry v adrese nejsou podporovány.")
		return true
	}
	path := strings.TrimPrefix(r.URL.Path, "/v1/self-service/")
	if path == "request-password-reset" {
		a.requestPasswordReset(w, r)
		return true
	}
	if path == "reset-password" {
		a.confirmAccountMail(w, r, "reset")
		return true
	}
	if path == "verify-email" {
		a.confirmAccountMail(w, r, "verify")
		return true
	}
	if path == "login" || path == "signup" {
		a.accountAuth(w, r, path)
		return true
	}
	token := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
	session, e := a.accounts.session(token, time.Now().UTC())
	if e != nil {
		apiError(w, 401, "Přihlaste se do zákaznického účtu.")
		return true
	}
	if r.Method != "GET" && !hmac.Equal([]byte(r.Header.Get("X-CSRF-Token")), []byte(session.CSRF)) {
		apiError(w, 403, "Požadavek nelze ověřit. Obnovte přihlášení.")
		return true
	}
	if path == "verification-email" {
		a.requestVerification(w, r, session)
		return true
	}
	if r.Method == "POST" && (path == "sites" || strings.HasPrefix(path, "sites/") || strings.HasPrefix(path, "jobs/")) && !session.EmailVerified {
		apiJSON(w, 403, map[string]any{"error": "Nejprve ověřte svůj e-mail. Ověřovací zprávu můžete poslat znovu v přehledu účtu.", "code": "email_verification_required"})
		return true
	}
	if path == "me" && r.Method == "GET" {
		apiJSON(w, 200, sessionView(session, false))
		return true
	}
	if path == "logout" && r.Method == "POST" {
		db, e := a.accounts.db.open()
		if e != nil {
			apiError(w, 503, "Odhlášení se nezdařilo.")
			return true
		}
		defer db.Close()
		if _, e = db.Exec("DELETE FROM sessions WHERE token_hash=?", accountHash(token)); e != nil {
			apiError(w, 503, "Odhlášení se nezdařilo.")
			return true
		}
		apiJSON(w, 200, map[string]any{"authenticated": false})
		return true
	}
	if path == "catalog" && r.Method == "GET" {
		a.selfCatalog(w)
		return true
	}
	if (path == "sites" || path == "subscriptions") && r.Method == "GET" {
		ids, e := a.accounts.sites(session.ID)
		if e != nil {
			apiError(w, 503, "Přehled není dostupný.")
			return true
		}
		out := []map[string]any{}
		for _, id := range ids {
			if path == "sites" {
				if v, ok := a.selfSite(session.ID, id); ok {
					out = append(out, v)
				}
			} else {
				if v, ok := a.selfSubscription(session.ID, id); ok {
					out = append(out, v)
				}
			}
		}
		apiJSON(w, 200, map[string]any{path: out})
		return true
	}
	if path == "sites" && r.Method == "POST" {
		a.selfCreate(w, r, session)
		return true
	}
	parts := strings.Split(path, "/")
	if len(parts) == 2 && parts[0] == "subscriptions" && r.Method == "GET" {
		if v, ok := a.selfSubscription(session.ID, parts[1]); ok {
			apiJSON(w, 200, v)
			return true
		}
	}
	if len(parts) >= 2 && parts[0] == "sites" && sitePattern.MatchString(parts[1]) {
		if v, ok := a.selfSite(session.ID, parts[1]); ok {
			if len(parts) == 3 && parts[2] == "retry-creation" && r.Method == "POST" {
				a.selfResumeCreation(w, r, session, parts[1])
				return true
			}
			if len(parts) == 2 && r.Method == "GET" {
				apiJSON(w, 200, v)
				return true
			}
			if len(parts) == 3 && parts[2] == "setup-link" && r.Method == "POST" {
				a.backup.mu.Lock()
				defer a.backup.mu.Unlock()
				if a.backup.active || a.subscriptionAllows(parts[1]) != nil {
					apiError(w, 409, "Web není připraven pro vytvoření přístupu.")
					return true
				}
				// A central account is not a platform password reset authority. Setup only works before the owner activates the instance.
				raw, e := a.backup.engine.Access(r.Context(), parts[1], "setup-link", session.Email)
				if e != nil {
					apiError(w, 409, "Přístup již byl nastaven nebo web není připraven.")
					return true
				}
				var out map[string]any
				if json.Unmarshal(raw, &out) != nil {
					apiError(w, 503, "Odkaz není dostupný.")
					return true
				}
				apiJSON(w, 200, map[string]any{"setup_url": out["setup_url"], "expires_at": out["expires_at"]})
				return true
			}
		}
	}
	if len(parts) >= 2 && parts[0] == "jobs" && apiJobPattern.MatchString(parts[1]) {
		a.backup.mu.Lock()
		var j customerJob
		e := decode(filepath.Join(a.customerDir(), parts[1]+".json"), &j)
		a.backup.mu.Unlock()
		ref, ownErr := a.accounts.owned(session.ID, j.Input.SiteID)
		if e == nil && ownErr == nil && ref == j.Input.CustomerRef {
			if len(parts) == 2 && r.Method == "GET" {
				apiJSON(w, 200, selfJob(j))
				return true
			}
			if len(parts) == 3 && parts[2] == "retry" && r.Method == "POST" {
				a.selfForwardJob(w, r, "/v1/customers/"+j.ID+"/retry", nil, "")
				return true
			}
		}
	}
	apiError(w, 404, "Web, předplatné nebo úloha nebyly nalezeny.")
	return true
}
func (a *centralAPI) selfForwardJob(w http.ResponseWriter, r *http.Request, path string, input any, key string) {
	raw, _ := json.Marshal(input)
	req, _ := http.NewRequestWithContext(r.Context(), r.Method, path, bytes.NewReader(raw))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Idempotency-Key", key)
	c := &managementCapture{headers: make(http.Header)}
	a.ServeHTTP(c, req)
	if c.status >= 300 {
		apiError(w, c.status, "Akci nyní nelze dokončit. Zkontrolujte údaje nebo ji zkuste později.")
		return
	}
	var view struct {
		ID string `json:"id"`
	}
	if json.Unmarshal(c.Bytes(), &view) != nil || !apiJobPattern.MatchString(view.ID) {
		apiError(w, 503, "Úloha není dostupná.")
		return
	}
	a.backup.mu.Lock()
	var j customerJob
	e := decode(filepath.Join(a.customerDir(), view.ID+".json"), &j)
	a.backup.mu.Unlock()
	if e != nil {
		apiError(w, 503, "Úloha není dostupná.")
		return
	}
	apiJSON(w, c.status, selfJob(j))
}

type selfCreateInput struct {
	Domain     string `json:"domain"`
	PackageID  string `json:"package_id"`
	TemplateID string `json:"template_id"`
	Brief      Brief  `json:"brief"`
}

func (a *centralAPI) selfRelease(packageID string) (string, error) {
	catalog, e := a.loadCatalog()
	if e != nil {
		return "", e
	}
	pkg, ok := catalog.Packages[packageID]
	if !ok || !pkg.Active || pkg.Archived {
		return "", fmt.Errorf("package unavailable")
	}
	releases, e := a.registry.list(true)
	if e != nil {
		return "", e
	}
	sort.Slice(releases, func(i, j int) bool { return releases[i].RegisteredAt > releases[j].RegisteredAt })
	for _, release := range releases {
		if !release.Verified {
			continue
		}
		req := Request{Package: "funnel-v1", CatalogPackageID: packageID}
		if a.resolvePackage(&req) == nil {
			if _, e = a.packageTarget(&State{Request: req, Release: release.Release}, packageID); e == nil {
				return release.ID, nil
			}
		}
	}
	return "", fmt.Errorf("no compatible verified release")
}
func (a *centralAPI) selfCatalog(w http.ResponseWriter) {
	catalog, e := a.loadCatalog()
	if e != nil {
		apiError(w, 503, "Nabídka není dostupná.")
		return
	}
	packages := []catalogPackage{}
	templates := map[string]map[string]any{}
	for _, pkg := range catalog.Packages {
		if !pkg.Active || pkg.Archived || !packageFeatureEnabled(&pkg, "website_generation") || !packageFeatureEnabled(&pkg, "draft_import") || (pkg.GenerationLimit != nil && *pkg.GenerationLimit == 0) {
			continue
		}
		if _, e = a.selfRelease(pkg.ID); e != nil {
			continue
		}
		ids := []string{}
		for _, id := range pkg.TemplateIDs {
			t := catalog.Templates[id]
			if t.Active && !t.Archived && (t.Driver == "native-json" || t.Driver == "ai-editorial") {
				ids = append(ids, id)
				templates[id] = map[string]any{"id": id, "name": t.Name, "description": t.Description}
			}
		}
		if len(ids) > 0 {
			pkg.TemplateIDs = ids
			packages = append(packages, pkg)
		}
	}
	sort.Slice(packages, func(i, j int) bool { return packages[i].ID < packages[j].ID })
	apiJSON(w, 200, map[string]any{"packages": packages, "templates": templates})
}
func (a *centralAPI) selfCreate(w http.ResponseWriter, r *http.Request, session accountSession) {
	var input selfCreateInput
	if !parseAPIJSON(w, r, &input) {
		return
	}
	key := r.Header.Get("Idempotency-Key")
	if !apiKeyPattern.MatchString(key) {
		apiError(w, 400, "Chybí identifikátor požadavku.")
		return
	}
	if input.Domain == "" {
		input.Domain = domainFromName(input.Brief.Name)
	}
	if !validOnboardingDomain(input.Domain) {
		apiError(w, 400, "Neplatná doména. Použijte 3–39 malých písmen, číslic a pomlček.")
		return
	}
	raw, _ := json.Marshal(input)
	hash := accountHash(string(raw))
	key = "self-" + session.ID + "-" + accountHash(key)[:24]
	a.selfMu.Lock()
	defer a.selfMu.Unlock()
	db, e := a.accounts.db.open()
	if e != nil {
		apiError(w, 503, "Účty nejsou dostupné.")
		return
	}
	defer db.Close()
	var oldHash, stored string
	e = db.QueryRow("SELECT payload_hash,input FROM account_requests WHERE account_id=? AND request_key=?", session.ID, key).Scan(&oldHash, &stored)
	var req customerRequest
	if e != nil && !errors.Is(e, sql.ErrNoRows) {
		apiError(w, 503, "Požadavek nelze obnovit.")
		return
	}
	if e == nil {
		if oldHash != hash {
			apiError(w, 409, "Identifikátor již patří jinému požadavku.")
			return
		}
		if json.Unmarshal([]byte(stored), &req) != nil {
			apiError(w, 503, "Požadavek nelze obnovit.")
			return
		}
	} else {
		ids, e := a.accounts.sites(session.ID)
		if e != nil {
			apiError(w, 503, "Účty nejsou dostupné.")
			return
		}
		limit, limitErr := a.accounts.maxSites()
		if limitErr != nil {
			apiError(w, 503, "Pravidla účtu nejsou dostupná.")
			return
		}
		if len(ids) >= limit {
			apiError(w, 409, "Byl dosažen limit webů pro zákaznický účet.")
			return
		}
		release, e := a.selfRelease(input.PackageID)
		if e != nil {
			apiError(w, 409, "Pro balíček není dostupná ověřená verze.")
			return
		}
		port := a.selfFreePort()
		if port == 0 {
			apiError(w, 503, "Není dostupný port pro další web.")
			return
		}
		req = customerRequest{SiteID: input.Domain, CustomerRef: session.ID, Email: session.Email, PackageID: input.PackageID, TemplateID: input.TemplateID, ReleaseID: release, Port: port, Brief: input.Brief}
		a.backup.mu.Lock()
		_, e = a.prepareCustomer(req, key)
		entries, _ := os.ReadDir(a.customerDir())
		for _, entry := range entries {
			var j customerJob
			if decode(filepath.Join(a.customerDir(), entry.Name()), &j) == nil && j.Input.SiteID == req.SiteID {
				e = fmt.Errorf("site reserved")
			}
		}
		a.backup.mu.Unlock()
		if e != nil {
			apiError(w, 400, "Web nelze vytvořit. Zkontrolujte zadání, balíček, šablonu a dostupnost domény.")
			return
		}
		tx, e := db.Begin()
		if e != nil {
			apiError(w, 503, "Požadavek nelze uložit.")
			return
		}
		defer tx.Rollback()
		now := time.Now().UTC().Format(time.RFC3339)
		_, e = tx.Exec("INSERT INTO account_sites(site_id,account_id,customer_ref,created_at) VALUES(?,?,?,?)", req.SiteID, session.ID, req.CustomerRef, now)
		storedRaw, _ := json.Marshal(req)
		if e == nil {
			_, e = tx.Exec("INSERT INTO account_requests(account_id,request_key,payload_hash,input) VALUES(?,?,?,?)", session.ID, key, hash, string(storedRaw))
		}
		if e == nil {
			_, e = tx.Exec("INSERT INTO account_events(account_id,action,site_id,occurred_at) VALUES(?,?,?,?)", session.ID, "creation-reserved", req.SiteID, now)
		}
		if e != nil || tx.Commit() != nil {
			apiError(w, 409, "Doména již patří jinému účtu nebo požadavku.")
			return
		}
	}
	a.selfForwardJob(w, r, "/v1/customers", req, key)
}
func (a *centralAPI) selfFreePort() int {
	used := map[int]bool{}
	entries, _ := os.ReadDir(filepath.Join(a.backup.engine.Root, "sites"))
	for _, entry := range entries {
		var state State
		if decode(filepath.Join(a.backup.engine.Root, "sites", entry.Name(), "state.json"), &state) == nil {
			used[state.Request.Port] = true
		}
	}
	entries, _ = os.ReadDir(a.customerDir())
	for _, entry := range entries {
		var j customerJob
		if decode(filepath.Join(a.customerDir(), entry.Name()), &j) == nil {
			used[j.Input.Port] = true
		}
	}
	adminDir := filepath.Join(a.dir, "management-creation")
	entries, err := os.ReadDir(adminDir)
	if err != nil && !os.IsNotExist(err) {
		return 0
	}
	for _, entry := range entries {
		var record onboardingRecord
		if decode(filepath.Join(adminDir, entry.Name()), &record) != nil {
			return 0
		}
		used[record.Request.Port] = true
	}
	db, e := a.accounts.db.open()
	if e != nil {
		return 0
	}
	defer db.Close()
	rows, e := db.Query("SELECT input FROM account_requests")
	if e != nil {
		return 0
	}
	defer rows.Close()
	for rows.Next() {
		var raw string
		if rows.Scan(&raw) != nil {
			return 0
		}
		var req customerRequest
		if json.Unmarshal([]byte(raw), &req) == nil {
			used[req.Port] = true
		}
	}
	for port := 18200; port < 19000; port++ {
		if used[port] {
			continue
		}
		listener, e := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", port))
		if e == nil {
			listener.Close()
			return port
		}
	}
	return 0
}

// Reservations survive a busy service or a lost HTTP response before the workflow is queued.
func (a *centralAPI) selfResumeCreation(w http.ResponseWriter, r *http.Request, s accountSession, site string) {
	a.selfMu.Lock()
	defer a.selfMu.Unlock()
	db, e := a.accounts.db.open()
	if e != nil {
		apiError(w, 503, "Požadavek nelze obnovit.")
		return
	}
	defer db.Close()
	rows, e := db.Query("SELECT request_key,input FROM account_requests WHERE account_id=?", s.ID)
	if e != nil {
		apiError(w, 503, "Požadavek nelze obnovit.")
		return
	}
	defer rows.Close()
	var found customerRequest
	key := ""
	for rows.Next() {
		var k, raw string
		if rows.Scan(&k, &raw) != nil {
			apiError(w, 503, "Požadavek nelze obnovit.")
			return
		}
		var req customerRequest
		if json.Unmarshal([]byte(raw), &req) == nil && req.SiteID == site && req.CustomerRef == s.ID {
			found = req
			key = k
			break
		}
	}
	if key == "" {
		apiError(w, 404, "Požadavek nebyl nalezen.")
		return
	}
	a.selfForwardJob(w, r, "/v1/customers", found, key)
}
