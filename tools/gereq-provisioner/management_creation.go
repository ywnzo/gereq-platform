package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/mail"
	"os"
	"path/filepath"
	"strings"
)

// Private operator adapter. Reuses the customer workflow without creating a second account identity.
func (a *centralAPI) managementCreationHTTP(w http.ResponseWriter, r *http.Request, parts []string) bool {
	if len(parts) == 2 && parts[0] == "creation" && parts[1] == "options" && r.Method == "GET" {
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		a.selfCatalog(w)
		return true
	}
	if len(parts) != 1 || parts[0] != "creation" || r.Method != "POST" {
		return false
	}
	var in onboardingInput
	if !parseAPIJSON(w, r, &in) {
		return true
	}
	key := r.Header.Get("Idempotency-Key")
	if !apiKeyPattern.MatchString(key) || len(key) > 80 {
		apiError(w, 400, "invalid request identity")
		return true
	}
	if in.Domain == "" {
		in.Domain = domainFromName(in.Name)
	}
	brief := Brief{Schema: 1, Name: strings.TrimSpace(in.Name), Offer: strings.TrimSpace(in.Offer), Audience: strings.TrimSpace(in.Audience), Language: in.Language, Goal: in.Goal, BookingURL: strings.TrimSpace(in.BookingURL), Facts: in.Facts, Slug: "uvod"}
	address, err := mail.ParseAddress(in.Email)
	if !validOnboardingDomain(in.Domain) || err != nil || address.Address != in.Email || len(in.Email) > 180 || brief.validate() != nil {
		apiError(w, 400, "invalid website brief, email or domain")
		return true
	}
	raw, _ := json.Marshal(in)
	hash := accountHash(string(raw))
	a.selfMu.Lock()
	defer a.selfMu.Unlock()
	dir := filepath.Join(a.dir, "management-creation")
	if privateBackupDir(dir) != nil {
		apiError(w, 503, "reservation storage unavailable")
		return true
	}
	path := filepath.Join(dir, accountHash(key)+".json")
	var record onboardingRecord
	err = decode(path, &record)
	if err == nil {
		if record.Key != key || record.Hash != hash {
			apiError(w, 409, "request identity already used")
			return true
		}
	} else if !os.IsNotExist(err) {
		apiError(w, 503, "reservation unavailable")
		return true
	} else {
		a.backup.mu.Lock()
		release, e := a.selfRelease(in.PackageID)
		port := a.selfFreePort()
		if e != nil || port == 0 {
			a.backup.mu.Unlock()
			apiError(w, 409, "no compatible release or free port")
			return true
		}
		req := customerRequest{SiteID: in.Domain, CustomerRef: "admin-" + in.Domain, Email: in.Email, PackageID: in.PackageID, TemplateID: in.TemplateID, ReleaseID: release, Port: port, Brief: brief}
		_, e = a.prepareCustomer(req, "admin-"+key)
		entries, readErr := os.ReadDir(dir)
		if readErr != nil {
			e = readErr
		}
		for _, entry := range entries {
			var old onboardingRecord
			if decode(filepath.Join(dir, entry.Name()), &old) != nil {
				e = os.ErrInvalid
				break
			}
			if old.Request.SiteID == req.SiteID {
				e = os.ErrExist
				break
			}
		}
		a.backup.mu.Unlock()
		if e != nil {
			apiError(w, 409, "check domain availability, brief, package and template")
			return true
		}
		record = onboardingRecord{Key: key, Hash: hash, Request: req}
		if atomicJSON(path, &record) != nil {
			apiError(w, 503, "cannot save reservation")
			return true
		}
	}
	raw, _ = json.Marshal(record.Request)
	next, _ := http.NewRequestWithContext(r.Context(), "POST", "/v1/customers", bytes.NewReader(raw))
	next.Header.Set("Content-Type", "application/json")
	next.Header.Set("Idempotency-Key", "admin-"+key)
	a.customersHTTP(w, next)
	return true
}
