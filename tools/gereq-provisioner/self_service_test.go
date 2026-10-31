package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type selfCredentials struct {
	Token   string          `json:"session_token"`
	CSRF    string          `json:"csrf_token"`
	Account customerAccount `json:"account"`
}

func selfReq(a *centralAPI, creds selfCredentials, method, path, body, key string) *httptest.ResponseRecorder {
	r := httptest.NewRequest(method, "/v1/self-service/"+path, strings.NewReader(body))
	r.Header.Set("Content-Type", "application/json")
	r.Header.Set("Authorization", "Bearer "+creds.Token)
	r.Header.Set("X-CSRF-Token", creds.CSRF)
	r.Header.Set("Idempotency-Key", key)
	w := httptest.NewRecorder()
	a.ServeHTTP(w, r)
	return w
}

// Ownership fixtures use verified accounts; email tests exercise unverified signup separately.
func selfSignup(t *testing.T, a *centralAPI, email string) selfCredentials {
	t.Helper()
	raw, _ := json.Marshal(map[string]string{"email": email, "password": accountRandom(), "name": "Test customer"})
	w := selfReq(a, selfCredentials{}, "POST", "signup", string(raw), "")
	var c selfCredentials
	if w.Code != 200 || json.Unmarshal(w.Body.Bytes(), &c) != nil || len(c.Token) != 64 {
		t.Fatal(w.Code, w.Body.String())
	}
	verifyAccountEmailFixture(t, a, email)
	return c
}
func verifySelfRelease(t *testing.T, a *centralAPI, id string) {
	t.Helper()
	db, e := a.registry.open()
	if e != nil {
		t.Fatal(e)
	}
	defer db.Close()
	var raw string
	if e = db.QueryRow("SELECT record FROM releases WHERE id=?", id).Scan(&raw); e != nil {
		t.Fatal(e)
	}
	var rel registeredRelease
	json.Unmarshal([]byte(raw), &rel)
	rel.Verified = true
	rel.Active = true
	rawBytes, _ := json.Marshal(rel)
	if _, e = db.Exec("UPDATE releases SET record=?,active=1 WHERE id=?", string(rawBytes), id); e != nil {
		t.Fatal(e)
	}
}
func TestSelfServiceTwoAccountIsolationAndCreation(t *testing.T) {
	a, in, runner := customerFixture(t)
	verifySelfRelease(t, a, in.ReleaseID)
	one := selfSignup(t, a, "one@example.invalid")
	two := selfSignup(t, a, "two@example.invalid")
	create := func(c selfCredentials, domain string) *httptest.ResponseRecorder {
		raw, _ := json.Marshal(selfCreateInput{Domain: domain, PackageID: in.PackageID, TemplateID: in.TemplateID, Brief: in.Brief})
		return selfReq(a, c, "POST", "sites", string(raw), "same-client-request")
	}
	first := create(one, "account-one")
	if first.Code != 202 {
		t.Fatal(first.Code, first.Body.String())
	}
	a.backup.workers.Wait()
	var job map[string]any
	json.Unmarshal(first.Body.Bytes(), &job)
	jobID := job["id"].(string)
	again := create(one, "account-one")
	if again.Code != 200 || !strings.Contains(again.Body.String(), jobID) {
		t.Fatal(again.Code, again.Body.String())
	}
	second := create(two, "account-two")
	if second.Code != 202 {
		t.Fatal(second.Code, second.Body.String())
	}
	a.backup.workers.Wait()
	for _, c := range []selfCredentials{one, two} {
		own, other := "account-one", "account-two"
		if c.Account.ID == two.Account.ID {
			own, other = other, own
		}
		for _, path := range []string{"sites", "subscriptions"} {
			w := selfReq(a, c, "GET", path, "", "")
			if w.Code != 200 || !strings.Contains(w.Body.String(), own) || strings.Contains(w.Body.String(), other) {
				t.Fatal(path, w.Code, w.Body.String())
			}
		}
		for _, path := range []string{"sites/" + own, "subscriptions/" + own} {
			if w := selfReq(a, c, "GET", path, "", ""); w.Code != 200 {
				t.Fatal(path, w.Code, w.Body.String())
			}
		}
		for _, path := range []string{"sites/" + other, "subscriptions/" + other, "sites/not-found"} {
			if w := selfReq(a, c, "GET", path, "", ""); w.Code != 404 {
				t.Fatal(path, w.Code, w.Body.String())
			}
		}
		if w := selfReq(a, c, "POST", "sites/"+other+"/setup-link", "{}", ""); w.Code != 404 {
			t.Fatal(w.Code, w.Body.String())
		}
	}
	for _, path := range []string{"jobs/" + jobID, "jobs/" + jobID + "/retry"} {
		method := "GET"
		if strings.HasSuffix(path, "retry") {
			method = "POST"
		}
		if w := selfReq(a, two, method, path, "{}", ""); w.Code != 404 {
			t.Fatal(path, w.Code, w.Body.String())
		}
	}
	if runner.setupCalls != 0 {
		t.Fatal("foreign setup reached runtime")
	}
	if w := selfReq(a, one, "GET", "jobs/"+jobID, "", ""); w.Code != 200 || strings.Contains(w.Body.String(), "customer_ref") || strings.Contains(w.Body.String(), "setup-link") || strings.Contains(w.Body.String(), in.Email) {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := selfReq(a, one, "POST", "sites", `{"customer_ref":"foreign"}`, "tamper-request"); w.Code != 400 {
		t.Fatal(w.Code)
	}
	bad := one
	bad.CSRF = ""
	if w := selfReq(a, bad, "POST", "logout", "{}", ""); w.Code != 403 {
		t.Fatal(w.Code)
	}
	if w := selfReq(a, one, "POST", "logout", "{}", ""); w.Code != 200 {
		t.Fatal(w.Code)
	}
	if w := selfReq(a, one, "GET", "sites", "", ""); w.Code != 401 {
		t.Fatal(w.Code)
	}
	db, e := a.accounts.db.open()
	if e != nil {
		t.Fatal(e)
	}
	db.Exec("UPDATE sessions SET expires_at=? WHERE account_id=?", time.Now().Add(-time.Hour).Unix(), two.Account.ID)
	db.Close()
	if w := selfReq(a, two, "GET", "me", "", ""); w.Code != 401 {
		t.Fatal(w.Code)
	}
	raw, e := os.ReadFile(a.accounts.db.path)
	if e != nil {
		t.Fatal(e)
	}
	if strings.Contains(string(raw), one.Token) {
		t.Fatal("plaintext session stored")
	}
	info, _ := os.Stat(a.accounts.db.path)
	if info.Mode().Perm() != 0600 {
		t.Fatal(info.Mode())
	}
}
func TestSelfServiceAdoptionIsExplicitAndCannotTransfer(t *testing.T) {
	a, in, _ := customerFixture(t)
	submitCustomer(t, a, in, "existing-operator-customer")
	a.backup.workers.Wait()
	one := selfSignup(t, a, "owner-one@example.invalid")
	two := selfSignup(t, a, "owner-two@example.invalid")
	if w := selfReq(a, one, "GET", "sites/"+in.SiteID, "", ""); w.Code != 404 {
		t.Fatal(w.Code)
	}
	body := `{"site_id":"` + in.SiteID + `"}`
	w := centralReq(t, a, "POST", "/v1/customer-accounts/"+one.Account.ID+"/sites", body, "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := centralReq(t, a, "POST", "/v1/customer-accounts/"+two.Account.ID+"/sites", body, ""); w.Code != 409 {
		t.Fatal(w.Code)
	}
	if w := selfReq(a, one, "GET", "sites/"+in.SiteID, "", ""); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := selfReq(a, two, "GET", "subscriptions/"+in.SiteID, "", ""); w.Code != 404 {
		t.Fatal(w.Code)
	}
	// A changed immutable customer identity must fail closed even if a site ID is reused.
	var state State
	path := filepath.Join(a.backup.engine.Root, "sites", in.SiteID, "state.json")
	if decode(path, &state) != nil {
		t.Fatal("state")
	}
	state.Request.CustomerRef = "different-owner"
	atomicJSON(path, state)
	if w := selfReq(a, one, "GET", "sites/"+in.SiteID, "", ""); w.Code != 404 {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestSelfServicePasswordLoginThrottleAndNoPlaintext(t *testing.T) {
	a, _, _ := centralFixture(t)
	password := accountRandom()
	body, _ := json.Marshal(map[string]string{"email": "Login@Example.invalid", "password": password, "name": "Owner"})
	w := selfReq(a, selfCredentials{}, "POST", "signup", string(body), "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	var first selfCredentials
	json.Unmarshal(w.Body.Bytes(), &first)
	login, _ := json.Marshal(map[string]string{"email": "login@example.invalid", "password": password})
	w = selfReq(a, selfCredentials{}, "POST", "login", string(login), "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	var second selfCredentials
	json.Unmarshal(w.Body.Bytes(), &second)
	if first.Token == second.Token || second.Account.ID != first.Account.ID {
		t.Fatal("session identity")
	}
	db, _ := a.accounts.db.open()
	var hash, salt []byte
	if db.QueryRow("SELECT password_hash,salt FROM accounts WHERE id=?", first.Account.ID).Scan(&hash, &salt) != nil || len(hash) != 32 || len(salt) != 32 || string(hash) == password {
		t.Fatal("password storage")
	}
	db.Close()
	for i := 0; i < 9; i++ {
		w = selfReq(a, selfCredentials{}, "POST", "login", `{"email":"login@example.invalid","password":"incorrect-password"}`, "")
	}
	if w.Code != 429 {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestCustomerGatewayUsesOnlyCustomerCookie(t *testing.T) {
	u, a, in := managementUIFixture(t)
	one := selfSignup(t, a, "gateway-one@example.invalid")
	two := selfSignup(t, a, "gateway-two@example.invalid")
	body := `{"site_id":"` + in.SiteID + `"}`
	if w := centralReq(t, a, "POST", "/v1/customer-accounts/"+one.Account.ID+"/sites", body, ""); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	request := func(token, method, path, origin, csrf, body string) *httptest.ResponseRecorder {
		r := httptest.NewRequest(method, "http://"+u.bridge.host+"/ui/self-service/"+path, strings.NewReader(body))
		r.AddCookie(&http.Cookie{Name: "gereq-management", Value: u.bridge.token})
		if token != "" {
			r.AddCookie(&http.Cookie{Name: "gereq-customer-session", Value: token})
		}
		r.Header.Set("Authorization", "Bearer "+one.Token)
		r.Header.Set("Origin", origin)
		r.Header.Set("X-CSRF-Token", csrf)
		w := httptest.NewRecorder()
		u.ServeHTTP(w, r)
		return w
	}
	if w := request("", "GET", "sites", "", "", ""); w.Code != 401 {
		t.Fatal("operator cookie or browser bearer accepted", w.Code)
	}
	if w := request(two.Token, "GET", "sites/"+in.SiteID, "", "", ""); w.Code != 404 {
		t.Fatal(w.Code)
	}
	if w := request(one.Token, "GET", "sites/"+in.SiteID, "", "", ""); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := request(one.Token, "POST", "logout", "https://foreign.invalid", one.CSRF, "{}"); w.Code != 403 {
		t.Fatal(w.Code)
	}
	for _, path := range []string{"../management/overview", "sites/" + in.SiteID + "/stop", "subscriptions/" + in.SiteID + "/simulate-renewal", "customer-accounts"} {
		if w := request(one.Token, "POST", path, "http://"+u.bridge.host, one.CSRF, "{}"); w.Code != 404 {
			t.Fatal(path, w.Code, w.Body.String())
		}
	}
	password := accountRandom()
	signup, _ := json.Marshal(map[string]string{"email": "gateway-signup@example.invalid", "password": password, "name": "Gateway"})
	w := request("", "POST", "signup", "http://"+u.bridge.host, u.bridge.token, string(signup))
	if w.Code != 200 || strings.Contains(w.Body.String(), "session_token") {
		t.Fatal(w.Code, w.Body.String())
	}
	cookies := w.Result().Cookies()
	if len(cookies) != 1 || !cookies[0].HttpOnly || cookies[0].SameSite != http.SameSiteStrictMode || cookies[0].Path != "/ui/self-service/" {
		t.Fatal("unsafe session cookie")
	}
}

func TestSelfServiceBusyReservationRecoversAfterReloadAndPolicyIsAdminOnly(t *testing.T) {
	a, in, _ := customerFixture(t)
	verifySelfRelease(t, a, in.ReleaseID)
	one := selfSignup(t, a, "reservation-one@example.invalid")
	two := selfSignup(t, a, "reservation-two@example.invalid")
	if w := centralReq(t, a, "PATCH", "/v1/customer-account-policy", `{"max_sites_per_account":1}`, ""); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := selfReq(a, one, "PATCH", "customer-account-policy", `{"max_sites_per_account":100}`, ""); w.Code != 404 {
		t.Fatal(w.Code)
	}
	a.backup.mu.Lock()
	a.backup.active = true
	a.backup.mu.Unlock()
	input := selfCreateInput{Domain: "reserved-account", PackageID: in.PackageID, TemplateID: in.TemplateID, Brief: in.Brief}
	raw, _ := json.Marshal(input)
	if w := selfReq(a, one, "POST", "sites", string(raw), "reserved-create-001"); w.Code != 409 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := selfReq(a, one, "GET", "sites/reserved-account", "", ""); w.Code != 200 || !strings.Contains(w.Body.String(), `"creation_retryable":true`) {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := selfReq(a, two, "POST", "sites/reserved-account/retry-creation", "{}", ""); w.Code != 404 {
		t.Fatal(w.Code)
	}
	input.Domain = "second-account-site"
	raw, _ = json.Marshal(input)
	if w := selfReq(a, one, "POST", "sites", string(raw), "reserved-create-002"); w.Code != 409 {
		t.Fatal(w.Code, w.Body.String())
	}
	a.backup.mu.Lock()
	a.backup.active = false
	a.backup.mu.Unlock()
	if w := selfReq(a, one, "POST", "sites/reserved-account/retry-creation", "{}", ""); w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	a.backup.workers.Wait()
	if w := selfReq(a, one, "POST", "sites/reserved-account/retry-creation", "{}", ""); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := selfReq(a, one, "GET", "sites/reserved-account", "", ""); w.Code != 200 || strings.Contains(w.Body.String(), `"creation_retryable":true`) {
		t.Fatal(w.Code, w.Body.String())
	}
}
