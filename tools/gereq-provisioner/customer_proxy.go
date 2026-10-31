package main

import (
	"bytes"
	"crypto/hmac"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"strings"
	"time"
)

// This is a local test gateway. Never expose the operator management listener publicly.
func (u *managementUI) customerProxy(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/ui/self-service/") {
		return false
	}
	b := u.bridge
	path := strings.TrimPrefix(r.URL.Path, "/ui/self-service/")
	parts := strings.Split(path, "/")
	allowed := false
	if r.Method == "GET" {
		allowed = contains([]string{"me", "sites", "subscriptions", "catalog"}, path) || (len(parts) == 2 && contains([]string{"sites", "subscriptions", "jobs"}, parts[0]) && siteOrJobID(parts[1]))
	}
	if r.Method == "POST" {
		allowed = contains([]string{"login", "signup", "logout", "sites", "verification-email", "verify-email", "request-password-reset", "reset-password"}, path) || (len(parts) == 3 && ((parts[0] == "jobs" && parts[2] == "retry" && apiJobPattern.MatchString(parts[1])) || (parts[0] == "sites" && (parts[2] == "setup-link" || parts[2] == "retry-creation") && sitePattern.MatchString(parts[1]))))
	}
	if !allowed {
		apiError(w, 404, "Zákaznická akce nebyla nalezena.")
		return true
	}
	preauth := contains([]string{"login", "signup", "verify-email", "request-password-reset", "reset-password"}, path)
	credentialsResponse := path == "login" || path == "signup"
	if r.Method != "GET" && (r.Header.Get("Origin") != "http://"+b.host || (preauth && !hmac.Equal([]byte(r.Header.Get("X-CSRF-Token")), []byte(b.token)))) {
		apiError(w, 403, "Požadavek nelze ověřit.")
		return true
	}
	var body []byte
	var e error
	if r.Method != "GET" {
		body, e = io.ReadAll(io.LimitReader(r.Body, 128<<10))
		if e != nil || len(body) >= 128<<10 {
			apiError(w, 400, "Požadavek je příliš velký.")
			return true
		}
	}
	req, e := http.NewRequestWithContext(r.Context(), r.Method, "http://unix/v1/self-service/"+path, bytes.NewReader(body))
	if e != nil {
		apiError(w, 503, "Služba není dostupná.")
		return true
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-CSRF-Token", r.Header.Get("X-CSRF-Token"))
	req.Header.Set("Idempotency-Key", r.Header.Get("Idempotency-Key"))
	host, _, _ := net.SplitHostPort(r.RemoteAddr)
	req.Header.Set("X-Gereq-Client", host)
	if cookie, e := r.Cookie("gereq-customer-session"); e == nil {
		req.Header.Set("Authorization", "Bearer "+cookie.Value)
	}
	resp, e := b.client.Do(req)
	if e != nil {
		apiError(w, 503, "Zákaznická služba není dostupná.")
		return true
	}
	defer resp.Body.Close()
	raw, e := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if e != nil {
		apiError(w, 503, "Odpověď není dostupná.")
		return true
	}
	if path == "me" && resp.StatusCode == 401 {
		apiJSON(w, 200, map[string]any{"authenticated": false, "csrf_token": b.token})
		return true
	}
	if credentialsResponse && resp.StatusCode == 200 {
		var out map[string]any
		if json.Unmarshal(raw, &out) != nil {
			apiError(w, 503, "Přihlášení se nezdařilo.")
			return true
		}
		token, _ := out["session_token"].(string)
		if len(token) != 64 {
			apiError(w, 503, "Přihlášení se nezdařilo.")
			return true
		}
		delete(out, "session_token")
		http.SetCookie(w, &http.Cookie{Name: "gereq-customer-session", Value: token, Path: "/ui/self-service/", HttpOnly: true, SameSite: http.SameSiteStrictMode, MaxAge: 43200, Expires: time.Now().Add(12 * time.Hour)})
		apiJSON(w, 200, out)
		return true
	}
	if (path == "logout" || path == "reset-password") && resp.StatusCode == 200 {
		http.SetCookie(w, &http.Cookie{Name: "gereq-customer-session", Value: "", Path: "/ui/self-service/", HttpOnly: true, SameSite: http.SameSiteStrictMode, MaxAge: -1})
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(resp.StatusCode)
	_, _ = w.Write(raw)
	return true
}
func siteOrJobID(id string) bool { return sitePattern.MatchString(id) || apiJobPattern.MatchString(id) }
