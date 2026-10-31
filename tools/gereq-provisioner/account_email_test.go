package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func signupEmailFixture(t *testing.T, a *centralAPI, email string) (selfCredentials, string) {
	t.Helper()
	password := accountRandom()
	raw, _ := json.Marshal(map[string]string{"email": email, "password": password, "name": "Email test"})
	w := selfReq(a, selfCredentials{}, "POST", "signup", string(raw), "")
	var c selfCredentials
	if w.Code != 200 || json.Unmarshal(w.Body.Bytes(), &c) != nil {
		t.Fatal("signup failed", w.Code)
	}
	if c.Account.EmailVerified {
		t.Fatal("new account already verified")
	}
	return c, password
}
func mailFixture(t *testing.T, a *centralAPI, email, kind string) map[string]any {
	t.Helper()
	w := centralReq(t, a, "GET", "/v1/mail-simulator/messages", "", "")
	var out struct {
		Messages []struct {
			ID        string `json:"id"`
			Recipient string `json:"recipient"`
			Kind      string `json:"kind"`
		}
	}
	if w.Code != 200 || json.Unmarshal(w.Body.Bytes(), &out) != nil {
		t.Fatal("inbox failed", w.Code)
	}
	for _, m := range out.Messages {
		if m.Recipient == email && m.Kind == kind {
			w = centralReq(t, a, "GET", "/v1/mail-simulator/messages/"+m.ID, "", "")
			var message map[string]any
			if w.Code != 200 || json.Unmarshal(w.Body.Bytes(), &message) != nil {
				t.Fatal("message failed", w.Code)
			}
			return message
		}
	}
	t.Fatal("expected message missing")
	return nil
}
func emailTokenFixture(t *testing.T, message map[string]any) string {
	t.Helper()
	u, e := url.Parse(message["action_url"].(string))
	if e != nil {
		t.Fatal(e)
	}
	parts := strings.Split(u.Fragment, "/")
	if len(parts) != 3 || !validAccountToken(parts[2]) {
		t.Fatal("invalid link shape")
	}
	return parts[2]
}
func verifyAccountEmailFixture(t *testing.T, a *centralAPI, email string) {
	t.Helper()
	m := mailFixture(t, a, email, "verify")
	token := emailTokenFixture(t, m)
	raw, _ := json.Marshal(map[string]string{"token": token})
	if w := selfReq(a, selfCredentials{}, "POST", "verify-email", string(raw), ""); w.Code != 200 {
		t.Fatal("verification failed", w.Code, w.Body.String())
	}
}
func resetBody(token, password string) string {
	raw, _ := json.Marshal(map[string]string{"token": token, "password": password, "password_confirmation": password})
	return string(raw)
}
func requestResetFixture(t *testing.T, a *centralAPI, email string) *httptest.ResponseRecorder {
	t.Helper()
	raw, _ := json.Marshal(map[string]string{"email": email})
	return selfReq(a, selfCredentials{}, "POST", "request-password-reset", string(raw), "")
}
func TestEmailVerificationGateResendExpiryAndSingleUse(t *testing.T) {
	a, _, _ := customerFixture(t)
	one, _ := signupEmailFixture(t, a, "unverified@example.invalid")
	if w := selfReq(a, one, "POST", "sites", "{}", "email-create-001"); w.Code != 403 || !strings.Contains(w.Body.String(), "email_verification_required") {
		t.Fatal(w.Code, w.Body.String())
	}
	if ids, e := a.accounts.sites(one.Account.ID); e != nil || len(ids) != 0 {
		t.Fatal("unverified account reserved a website")
	}
	message := mailFixture(t, a, one.Account.Email, "verify")
	token := emailTokenFixture(t, message)
	for _, path := range []string{"verify-email", "reset-password"} {
		if w := selfReq(a, selfCredentials{}, "GET", path, "", ""); w.Code != 405 {
			t.Fatal(path, w.Code)
		}
	}
	if w := selfReq(a, selfCredentials{}, "POST", "reset-password", resetBody(token, accountRandom()), ""); w.Code != 400 {
		t.Fatal("verification token accepted as reset", w.Code)
	}
	if w := selfReq(a, one, "POST", "verification-email", "{}", ""); w.Code != 200 {
		t.Fatal(w.Code)
	}
	newer := emailTokenFixture(t, mailFixture(t, a, one.Account.Email, "verify"))
	if newer == token {
		t.Fatal("resend reused token")
	}
	raw, _ := json.Marshal(map[string]string{"token": token})
	if w := selfReq(a, selfCredentials{}, "POST", "verify-email", string(raw), ""); w.Code != 400 {
		t.Fatal("superseded link accepted", w.Code)
	}
	raw, _ = json.Marshal(map[string]string{"token": newer})
	if w := selfReq(a, selfCredentials{}, "POST", "verify-email", string(raw), ""); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w := selfReq(a, selfCredentials{}, "POST", "verify-email", string(raw), ""); w.Code != 400 {
		t.Fatal("link replay", w.Code)
	}
	if w := selfReq(a, one, "GET", "me", "", ""); w.Code != 200 || !strings.Contains(w.Body.String(), `"email_verified":true`) {
		t.Fatal(w.Code, w.Body.String())
	}
	two, _ := signupEmailFixture(t, a, "expired@example.invalid")
	expired := emailTokenFixture(t, mailFixture(t, a, two.Account.Email, "verify"))
	db, _ := a.accounts.db.open()
	db.Exec("UPDATE account_challenges SET expires_at=? WHERE token_hash=?", time.Now().Add(-time.Minute).Unix(), accountHash(expired))
	db.Close()
	raw, _ = json.Marshal(map[string]string{"token": expired})
	if w := selfReq(a, selfCredentials{}, "POST", "verify-email", string(raw), ""); w.Code != 400 {
		t.Fatal("expired verification accepted", w.Code)
	}
	if w := selfReq(a, two, "GET", "me", "", ""); w.Code != 200 || strings.Contains(w.Body.String(), `"email_verified":true`) {
		t.Fatal("expiry changed another account")
	}
}
func TestPasswordRecoveryRevokesAllSessionsAndIsAccountScoped(t *testing.T) {
	a, _, _ := customerFixture(t)
	one, password := signupEmailFixture(t, a, "recovery-one@example.invalid")
	verifyAccountEmailFixture(t, a, one.Account.Email)
	two, _ := signupEmailFixture(t, a, "recovery-two@example.invalid")
	login, _ := json.Marshal(map[string]string{"email": one.Account.Email, "password": password})
	w := selfReq(a, selfCredentials{}, "POST", "login", string(login), "")
	var extra selfCredentials
	if w.Code != 200 || json.Unmarshal(w.Body.Bytes(), &extra) != nil {
		t.Fatal(w.Code)
	}
	known := requestResetFixture(t, a, one.Account.Email)
	unknown := requestResetFixture(t, a, "unknown@example.invalid")
	if known.Code != 200 || known.Code != unknown.Code || known.Body.String() != unknown.Body.String() {
		t.Fatal("account enumeration in recovery")
	}
	first := emailTokenFixture(t, mailFixture(t, a, one.Account.Email, "reset"))
	if w = requestResetFixture(t, a, one.Account.Email); w.Code != 200 {
		t.Fatal(w.Code)
	}
	token := emailTokenFixture(t, mailFixture(t, a, one.Account.Email, "reset"))
	if first == token {
		t.Fatal("recovery resend reused token")
	}
	if w = selfReq(a, selfCredentials{}, "POST", "reset-password", resetBody(first, accountRandom()), ""); w.Code != 400 {
		t.Fatal("old recovery link valid")
	}
	if w = selfReq(a, selfCredentials{}, "POST", "verify-email", `{"token":"`+token+`"}`, ""); w.Code != 400 {
		t.Fatal("reset token accepted for verification")
	}
	if w = selfReq(a, selfCredentials{}, "POST", "reset-password", `{"token":"`+token+`","password":"short","password_confirmation":"different"}`, ""); w.Code != 400 {
		t.Fatal("invalid password accepted")
	}
	newPassword := accountRandom()
	if w = selfReq(a, selfCredentials{}, "POST", "reset-password", resetBody(token, newPassword), ""); w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	for _, c := range []selfCredentials{one, extra} {
		if w = selfReq(a, c, "GET", "me", "", ""); w.Code != 401 {
			t.Fatal("session survived reset", w.Code)
		}
	}
	if w = selfReq(a, two, "GET", "me", "", ""); w.Code != 200 {
		t.Fatal("other account session revoked")
	}
	if w = selfReq(a, selfCredentials{}, "POST", "reset-password", resetBody(token, accountRandom()), ""); w.Code != 400 {
		t.Fatal("reset token replay")
	}
	if w = selfReq(a, selfCredentials{}, "POST", "login", string(login), ""); w.Code != 401 {
		t.Fatal("old password works")
	}
	login, _ = json.Marshal(map[string]string{"email": one.Account.Email, "password": newPassword})
	if w = selfReq(a, selfCredentials{}, "POST", "login", string(login), ""); w.Code != 200 {
		t.Fatal("new password rejected", w.Code, w.Body.String())
	}
	// A login that verified an old password before reset cannot insert a session afterward.
	stale := one.Account
	stale.CredentialVersion = 1
	if _, e := a.accounts.issue(stale, time.Now()); e == nil {
		t.Fatal("stale login race allowed")
	}
	db, _ := a.accounts.db.open()
	var hash string
	if db.QueryRow("SELECT token_hash FROM account_challenges WHERE token_hash=?", accountHash(token)).Scan(&hash) != nil || hash == token {
		t.Fatal("challenge is not hashed")
	}
	db.Close()
}
func TestRecoveryTokenConcurrentConsumptionAndExpiredToken(t *testing.T) {
	a, _, _ := customerFixture(t)
	account, _ := signupEmailFixture(t, a, "concurrent@example.invalid")
	requestResetFixture(t, a, account.Account.Email)
	token := emailTokenFixture(t, mailFixture(t, a, account.Account.Email, "reset"))
	password := accountRandom()
	body := resetBody(token, password)
	var wg sync.WaitGroup
	codes := make(chan int, 2)
	for i := 0; i < 2; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			codes <- selfReq(a, selfCredentials{}, "POST", "reset-password", body, "").Code
		}()
	}
	wg.Wait()
	close(codes)
	ok, bad := 0, 0
	for code := range codes {
		if code == 200 {
			ok++
		} else if code == 400 {
			bad++
		} else {
			t.Fatal(code)
		}
	}
	if ok != 1 || bad != 1 {
		t.Fatal("token consumption was not atomic", ok, bad)
	}
	requestResetFixture(t, a, account.Account.Email)
	expired := emailTokenFixture(t, mailFixture(t, a, account.Account.Email, "reset"))
	db, _ := a.accounts.db.open()
	db.Exec("UPDATE account_challenges SET expires_at=? WHERE token_hash=?", time.Now().Add(-time.Second).Unix(), accountHash(expired))
	db.Close()
	if w := selfReq(a, selfCredentials{}, "POST", "reset-password", resetBody(expired, accountRandom()), ""); w.Code != 400 {
		t.Fatal("expired reset accepted", w.Code)
	}
}
func TestEmailPolicySimulatorIsolationAndRetention(t *testing.T) {
	a, _, _ := centralFixture(t)
	account, _ := signupEmailFixture(t, a, "private-mail@example.invalid")
	message := mailFixture(t, a, account.Account.Email, "verify")
	for _, path := range []string{"mail-simulator/messages", "customer-mail-policy"} {
		if w := selfReq(a, account, "GET", path, "", ""); w.Code != 404 {
			t.Fatal("customer reached operator email endpoint", w.Code)
		}
	}
	p, e := a.accounts.mailPolicy()
	if e != nil {
		t.Fatal(e)
	}
	for _, origin := range []string{"https://external.invalid", "http://external.invalid", "http://127.0.0.1:18152/evil", "http://user@127.0.0.1:18152", "http://127.0.0.1:18152?redirect=evil", "http://127.0.0.1:18152/#evil"} {
		bad := p
		bad.CustomerOrigin = origin
		if bad.validate() == nil {
			t.Fatal("unsafe callback", origin)
		}
	}
	db, _ := a.accounts.db.open()
	db.Exec("UPDATE account_mail SET created_at=? WHERE id=?", time.Now().Add(-48*time.Hour).Unix(), message["id"])
	db.Close()
	if w := centralReq(t, a, "GET", "/v1/mail-simulator/messages/"+message["id"].(string), "", ""); w.Code != 404 {
		t.Fatal("retention not applied", w.Code)
	}
	p.Mode = "disabled"
	if e = a.accounts.setMailPolicy(p); e != nil {
		t.Fatal(e)
	}
	if w := requestResetFixture(t, a, "unknown@example.invalid"); w.Code != 503 {
		t.Fatal("disabled sender accepted", w.Code)
	}
	if w := centralReq(t, a, "GET", "/v1/mail-simulator/messages", "", ""); w.Code != 404 {
		t.Fatal("disabled inbox exposed", w.Code)
	}
}
func TestAccountEmailMigrationPreservesLegacyOwnershipAndSessions(t *testing.T) {
	dir := t.TempDir()
	store := accountStore{db: releaseRegistry{path: filepath.Join(dir, "accounts.sqlite")}}
	db, e := store.db.open()
	if e != nil {
		t.Fatal(e)
	}
	_, e = db.Exec(`CREATE TABLE accounts(id TEXT PRIMARY KEY,email TEXT NOT NULL UNIQUE,name TEXT NOT NULL,salt BLOB NOT NULL,password_hash BLOB NOT NULL,created_at TEXT NOT NULL);
 CREATE TABLE sessions(token_hash TEXT PRIMARY KEY,account_id TEXT NOT NULL REFERENCES accounts(id),expires_at INTEGER NOT NULL);
 CREATE TABLE account_sites(site_id TEXT PRIMARY KEY,account_id TEXT NOT NULL REFERENCES accounts(id),customer_ref TEXT NOT NULL,created_at TEXT NOT NULL);
 INSERT INTO accounts VALUES('legacy','legacy@example.invalid','Legacy',X'00',X'00','2026-10-29');
 INSERT INTO account_sites VALUES('legacy-site','legacy','legacy-ref','2026-10-29');
 PRAGMA user_version=1;`)
	if e != nil {
		t.Fatal(e)
	}
	token := accountRandom()
	db.Exec("INSERT INTO sessions VALUES(?,?,?)", accountHash(token), "legacy", time.Now().Add(time.Hour).Unix())
	db.Close()
	if e = store.initialize(); e != nil {
		t.Fatal(e)
	}
	if e = store.initialize(); e != nil {
		t.Fatal("migration not repeat safe", e)
	}
	session, e := store.session(token, time.Now())
	if e != nil || session.EmailVerified || session.CredentialVersion != 1 {
		t.Fatal("legacy session/verification", e)
	}
	if ref, e := store.owned("legacy", "legacy-site"); e != nil || ref != "legacy-ref" {
		t.Fatal("ownership lost")
	}
	info, e := os.Stat(store.db.path + ".before-email-v2.sqlite")
	if e != nil || info.Mode().Perm() != 0600 {
		t.Fatal("private snapshot missing", e)
	}
}
func TestRecoveryGatewayCSRFAndCookieRevocation(t *testing.T) {
	u, a, _ := managementUIFixture(t)
	account, _ := signupEmailFixture(t, a, "csrf-mail@example.invalid")
	request := func(path, origin, csrf, body string) *httptest.ResponseRecorder {
		r := httptest.NewRequest("POST", "http://"+u.bridge.host+"/ui/self-service/"+path, strings.NewReader(body))
		r.Header.Set("Origin", origin)
		r.Header.Set("X-CSRF-Token", csrf)
		r.AddCookie(&http.Cookie{Name: "gereq-customer-session", Value: account.Token})
		w := httptest.NewRecorder()
		u.ServeHTTP(w, r)
		return w
	}
	for _, path := range []string{"verify-email", "request-password-reset", "reset-password"} {
		if w := request(path, "https://foreign.invalid", u.bridge.token, "{}"); w.Code != 403 {
			t.Fatal(path, w.Code)
		}
		if w := request(path, "http://"+u.bridge.host, "wrong", "{}"); w.Code != 403 {
			t.Fatal(path, w.Code)
		}
	}
	requestResetFixture(t, a, account.Account.Email)
	token := emailTokenFixture(t, mailFixture(t, a, account.Account.Email, "reset"))
	w := request("reset-password", "http://"+u.bridge.host, u.bridge.token, resetBody(token, accountRandom()))
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	cookies := w.Result().Cookies()
	if len(cookies) != 1 || cookies[0].Name != "gereq-customer-session" || cookies[0].MaxAge != -1 {
		t.Fatal("customer cookie not cleared")
	}
}
