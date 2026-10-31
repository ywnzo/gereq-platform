package main

import (
	"crypto/hmac"
	"crypto/pbkdf2"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"errors"
	"net/http"
	"net/mail"
	"strings"
	"time"
)

type customerAccount struct {
	EmailVerified     bool   `json:"email_verified"`
	CredentialVersion int    `json:"-"`
	ID                string `json:"id"`
	Email             string `json:"email"`
	Name              string `json:"name"`
	CreatedAt         string `json:"created_at"`
}
type accountStore struct{ db releaseRegistry }
type accountSession struct {
	customerAccount
	Token     string
	CSRF      string
	ExpiresAt time.Time
}

func (s accountStore) initialize() error {
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
		return errors.New("unsupported account schema")
	}
	if version == 2 {
		return nil
	}
	existing := version == 1
	tx, e := db.Begin()
	if e != nil {
		return e
	}
	defer tx.Rollback()
	_, e = tx.Exec(`CREATE TABLE IF NOT EXISTS accounts(id TEXT PRIMARY KEY,email TEXT NOT NULL UNIQUE COLLATE NOCASE,name TEXT NOT NULL,salt BLOB NOT NULL,password_hash BLOB NOT NULL,created_at TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS sessions(token_hash TEXT PRIMARY KEY,account_id TEXT NOT NULL REFERENCES accounts(id),expires_at INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS account_sites(site_id TEXT PRIMARY KEY,account_id TEXT NOT NULL REFERENCES accounts(id),customer_ref TEXT NOT NULL,created_at TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS account_requests(account_id TEXT NOT NULL REFERENCES accounts(id),request_key TEXT NOT NULL,payload_hash TEXT NOT NULL,input TEXT NOT NULL,PRIMARY KEY(account_id,request_key));
 CREATE TABLE IF NOT EXISTS auth_throttle(bucket TEXT PRIMARY KEY,window_start INTEGER NOT NULL,count INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS account_policy (id INTEGER PRIMARY KEY CHECK(id=1),max_sites INTEGER NOT NULL CHECK(max_sites BETWEEN 1 AND 100));
 INSERT OR IGNORE INTO account_policy(id,max_sites) VALUES(1,5);
 CREATE TABLE IF NOT EXISTS account_events(sequence INTEGER PRIMARY KEY AUTOINCREMENT,account_id TEXT NOT NULL,action TEXT NOT NULL,site_id TEXT NOT NULL,occurred_at TEXT NOT NULL);
 PRAGMA user_version=1;`)
	if e != nil {
		return e
	}
	if e = tx.Commit(); e != nil {
		return e
	}
	return s.migrateEmailSchema(db, existing)
}
func accountRandom() string {
	b := make([]byte, 32)
	if _, e := rand.Read(b); e != nil {
		panic(e)
	}
	return hex.EncodeToString(b)
}
func accountHash(s string) string { h := sha256.Sum256([]byte(s)); return hex.EncodeToString(h[:]) }
func accountCSRF(token string) string {
	h := hmac.New(sha256.New, []byte(token))
	h.Write([]byte("gereq-self-service-csrf-v1"))
	return hex.EncodeToString(h.Sum(nil))
}
func accountPassword(password string, salt []byte) []byte {
	b, _ := pbkdf2.Key(sha256.New, password, salt, 600000, 32)
	return b
}
func accountEmail(email string) (string, bool) {
	email = strings.ToLower(strings.TrimSpace(email))
	a, e := mail.ParseAddress(email)
	return email, e == nil && a.Address == email && len(email) <= 180
}
func (s accountStore) throttle(bucket string, limit int, now time.Time) bool {
	db, e := s.db.open()
	if e != nil {
		return false
	}
	defer db.Close()
	tx, e := db.Begin()
	if e != nil {
		return false
	}
	defer tx.Rollback()
	if _, e = tx.Exec("DELETE FROM auth_throttle WHERE window_start < ?", now.Add(-10*time.Minute).Unix()); e != nil {
		return false
	}
	_, e = tx.Exec("INSERT INTO auth_throttle(bucket,window_start,count) VALUES(?,?,1) ON CONFLICT(bucket) DO UPDATE SET count=count+1", bucket, now.Unix())
	if e != nil {
		return false
	}
	var n int
	if tx.QueryRow("SELECT count FROM auth_throttle WHERE bucket=?", bucket).Scan(&n) != nil {
		return false
	}
	return tx.Commit() == nil && n <= limit
}
func (s accountStore) session(token string, now time.Time) (accountSession, error) {
	var v accountSession
	if len(token) != 64 {
		return v, errors.New("unauthenticated")
	}
	db, e := s.db.open()
	if e != nil {
		return v, e
	}
	defer db.Close()
	var expiry int64
	e = db.QueryRow(`SELECT a.id,a.email,a.name,a.created_at,a.email_verified_at IS NOT NULL,a.credential_version,s.expires_at FROM sessions s JOIN accounts a ON a.id=s.account_id WHERE s.token_hash=? AND s.expires_at>? AND s.credential_version=a.credential_version`, accountHash(token), now.Unix()).Scan(&v.ID, &v.Email, &v.Name, &v.CreatedAt, &v.EmailVerified, &v.CredentialVersion, &expiry)
	v.Token = token
	v.CSRF = accountCSRF(token)
	v.ExpiresAt = time.Unix(expiry, 0).UTC()
	return v, e
}
func (s accountStore) issue(a customerAccount, now time.Time) (accountSession, error) {
	token := accountRandom()
	expiry := now.Add(12 * time.Hour)
	db, e := s.db.open()
	if e != nil {
		return accountSession{}, e
	}
	defer db.Close()
	if _, e = db.Exec("DELETE FROM sessions WHERE expires_at<=?", now.Unix()); e != nil {
		return accountSession{}, e
	}
	result, e := db.Exec("INSERT INTO sessions(token_hash,account_id,expires_at,credential_version) SELECT ?,id,?,credential_version FROM accounts WHERE id=? AND credential_version=?", accountHash(token), expiry.Unix(), a.ID, a.CredentialVersion)
	if e == nil {
		if n, _ := result.RowsAffected(); n != 1 {
			e = errors.New("credentials changed during login")
		}
	}
	return accountSession{a, token, accountCSRF(token), expiry}, e
}
func sessionView(s accountSession, withToken bool) map[string]any {
	v := map[string]any{"authenticated": true, "account": s.customerAccount, "csrf_token": s.CSRF, "expires_at": s.ExpiresAt}
	if withToken {
		v["session_token"] = s.Token
	}
	return v
}
func (a *centralAPI) accountAuth(w http.ResponseWriter, r *http.Request, action string) {
	if r.Method != "POST" {
		apiError(w, 405, "method not allowed")
		return
	}
	var in struct {
		Email    string `json:"email"`
		Password string `json:"password"`
		Name     string `json:"name,omitempty"`
	}
	if !parseAPIJSON(w, r, &in) {
		return
	}
	email, valid := accountEmail(in.Email)
	now := time.Now().UTC()
	// The gateway supplies this header from its socket peer, never from forwarded browser headers.
	client := r.Header.Get("X-Gereq-Client")
	if client == "" {
		client = "private-socket"
	}
	if !a.accounts.throttle("client:"+accountHash(client), 40, now) || !a.accounts.throttle(action+":"+accountHash(email), 8, now) {
		apiError(w, 429, "Příliš mnoho pokusů. Zkuste to za 10 minut.")
		return
	}
	if !valid || len(in.Password) > 128 || len(in.Password) < 12 {
		apiError(w, 400, "Zadejte platný e-mail a heslo o délce 12–128 znaků.")
		return
	}
	db, e := a.accounts.db.open()
	if e != nil {
		apiError(w, 503, "Účty nejsou dostupné.")
		return
	}
	defer db.Close()
	var account customerAccount
	if action == "signup" {
		in.Name = strings.TrimSpace(in.Name)
		if in.Name == "" || len(in.Name) > 100 || strings.ContainsAny(in.Name, "\r\n") {
			apiError(w, 400, "Zadejte jméno (nejvýše 100 znaků).")
			return
		}
		salt := make([]byte, 32)
		if _, e = rand.Read(salt); e != nil {
			apiError(w, 503, "Účet nelze vytvořit.")
			return
		}
		account = customerAccount{ID: "acct-" + accountRandom()[:32], Email: email, Name: in.Name, CreatedAt: now.Format(time.RFC3339), CredentialVersion: 1}
		policy, policyErr := a.accounts.mailPolicy()
		if policyErr != nil || policy.Mode != "simulator" {
			apiError(w, 503, "Odesílání e-mailů není nastavené. Zkuste to později.")
			return
		}
		tx, txErr := db.Begin()
		if txErr != nil {
			apiError(w, 503, "Účet nelze vytvořit.")
			return
		}
		defer tx.Rollback()
		_, e = tx.Exec("INSERT INTO accounts(id,email,name,salt,password_hash,created_at) VALUES(?,?,?,?,?,?)", account.ID, email, in.Name, salt, accountPassword(in.Password, salt), account.CreatedAt)
		if e != nil {
			apiError(w, 409, "Účet nelze vytvořit. Zkuste přihlášení.")
			return
		}
		if e = a.accounts.queueAccountMail(tx, account, "verify", policy, now); e != nil {
			apiError(w, 503, "Ověřovací e-mail nelze připravit.")
			return
		}
		if e = tx.Commit(); e != nil {
			apiError(w, 503, "Účet nelze vytvořit.")
			return
		}

	} else {
		var salt, hash []byte
		e = db.QueryRow("SELECT id,email,name,created_at,email_verified_at IS NOT NULL,credential_version,salt,password_hash FROM accounts WHERE email=?", email).Scan(&account.ID, &account.Email, &account.Name, &account.CreatedAt, &account.EmailVerified, &account.CredentialVersion, &salt, &hash)
		if e != nil {
			salt = make([]byte, 32)
			hash = make([]byte, 32)
		}
		candidate := accountPassword(in.Password, salt)
		if subtle.ConstantTimeCompare(candidate, hash) != 1 || e != nil {
			apiError(w, 401, "Nesprávný e-mail nebo heslo.")
			return
		}
	}
	session, e := a.accounts.issue(account, now)
	if e != nil {
		apiError(w, 503, "Přihlášení se nezdařilo.")
		return
	}
	apiJSON(w, 200, sessionView(session, true))
}
func (s accountStore) owned(account, site string) (string, error) {
	db, e := s.db.open()
	if e != nil {
		return "", e
	}
	defer db.Close()
	var ref string
	e = db.QueryRow("SELECT customer_ref FROM account_sites WHERE account_id=? AND site_id=?", account, site).Scan(&ref)
	return ref, e
}
func (s accountStore) sites(account string) ([]string, error) {
	db, e := s.db.open()
	if e != nil {
		return nil, e
	}
	defer db.Close()
	rows, e := db.Query("SELECT site_id FROM account_sites WHERE account_id=? ORDER BY created_at,site_id", account)
	if e != nil {
		return nil, e
	}
	defer rows.Close()
	out := []string{}
	for rows.Next() {
		var id string
		if e = rows.Scan(&id); e != nil {
			return nil, e
		}
		out = append(out, id)
	}
	return out, rows.Err()
}
func (a *centralAPI) accountAdminHTTP(w http.ResponseWriter, r *http.Request) bool {
	if a.accountMailAdminHTTP(w, r) {
		return true
	}
	if r.URL.Path == "/v1/customer-account-policy" {
		a.accountPolicyHTTP(w, r)
		return true
	}
	if r.URL.Path != "/v1/customer-accounts" && !strings.HasPrefix(r.URL.Path, "/v1/customer-accounts/") {
		return false
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters unsupported")
		return true
	}
	db, e := a.accounts.db.open()
	if e != nil {
		apiError(w, 503, "account storage unavailable")
		return true
	}
	defer db.Close()
	if r.URL.Path == "/v1/customer-accounts" && r.Method == "GET" {
		rows, e := db.Query("SELECT id,email,name,created_at,email_verified_at IS NOT NULL,credential_version FROM accounts ORDER BY created_at")
		if e != nil {
			apiError(w, 503, "account storage unavailable")
			return true
		}
		defer rows.Close()
		out := []customerAccount{}
		for rows.Next() {
			var v customerAccount
			if rows.Scan(&v.ID, &v.Email, &v.Name, &v.CreatedAt, &v.EmailVerified, &v.CredentialVersion) != nil {
				apiError(w, 503, "account storage unavailable")
				return true
			}
			out = append(out, v)
		}
		apiJSON(w, 200, map[string]any{"accounts": out})
		return true
	}
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/customer-accounts/"), "/")
	if len(parts) == 2 && parts[1] == "sites" && r.Method == "POST" {
		var in struct {
			SiteID string `json:"site_id"`
		}
		if !parseAPIJSON(w, r, &in) {
			return true
		}
		a.selfMu.Lock()
		defer a.selfMu.Unlock()
		state, _, e := a.backup.engine.load(in.SiteID)
		if e != nil {
			apiError(w, 404, "instance unavailable")
			return true
		}
		tx, e := db.Begin()
		if e != nil {
			apiError(w, 503, "account storage unavailable")
			return true
		}
		defer tx.Rollback()
		_, e = tx.Exec("INSERT INTO account_sites(site_id,account_id,customer_ref,created_at) VALUES(?,?,?,?)", in.SiteID, parts[0], state.Request.CustomerRef, time.Now().UTC().Format(time.RFC3339))
		if e == nil {
			_, e = tx.Exec("INSERT INTO account_events(account_id,action,site_id,occurred_at) VALUES(?,?,?,?)", parts[0], "operator-assigned", in.SiteID, time.Now().UTC().Format(time.RFC3339))
		}
		if e != nil || tx.Commit() != nil {
			apiError(w, 409, "account missing or instance already assigned")
			return true
		}
		apiJSON(w, 200, map[string]any{"site_id": in.SiteID, "account_id": parts[0]})
		return true
	}
	apiError(w, 404, "not found")
	return true
}

func (s accountStore) maxSites() (int, error) {
	db, e := s.db.open()
	if e != nil {
		return 0, e
	}
	defer db.Close()
	var n int
	e = db.QueryRow("SELECT max_sites FROM account_policy WHERE id=1").Scan(&n)
	return n, e
}
func (a *centralAPI) accountPolicyHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters unsupported")
		return
	}
	if r.Method == "GET" {
		n, e := a.accounts.maxSites()
		if e != nil {
			apiError(w, 503, "account policy unavailable")
			return
		}
		apiJSON(w, 200, map[string]any{"max_sites_per_account": n})
		return
	}
	if r.Method == "PATCH" {
		var in struct {
			Max int `json:"max_sites_per_account"`
		}
		if !parseAPIJSON(w, r, &in) {
			return
		}
		if in.Max < 1 || in.Max > 100 {
			apiError(w, 400, "max_sites_per_account must be 1..100")
			return
		}
		db, e := a.accounts.db.open()
		if e != nil {
			apiError(w, 503, "account policy unavailable")
			return
		}
		defer db.Close()
		if _, e = db.Exec("UPDATE account_policy SET max_sites=? WHERE id=1", in.Max); e != nil {
			apiError(w, 503, "account policy unavailable")
			return
		}
		apiJSON(w, 200, map[string]any{"max_sites_per_account": in.Max})
		return
	}
	apiError(w, 405, "method not allowed")
}
