package main

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"
)

type accountMailPolicy struct {
	Mode            string `json:"mode"`
	CustomerOrigin  string `json:"customer_origin"`
	VerificationTTL int    `json:"verification_ttl_minutes"`
	ResetTTL        int    `json:"reset_ttl_minutes"`
	RetentionHours  int    `json:"retention_hours"`
}

func (p accountMailPolicy) validate() error {
	if p.Mode != "disabled" && p.Mode != "simulator" {
		return errors.New("mail mode must be disabled or simulator")
	}
	if p.VerificationTTL < 5 || p.VerificationTTL > 1440 || p.ResetTTL < 5 || p.ResetTTL > 120 || p.RetentionHours < 1 || p.RetentionHours > 168 || p.RetentionHours*60 < p.VerificationTTL || p.RetentionHours*60 < p.ResetTTL {
		return errors.New("invalid email expiry or retention settings")
	}
	if p.Mode == "disabled" && p.CustomerOrigin == "" {
		return nil
	}
	u, e := url.Parse(p.CustomerOrigin)
	if e != nil || u.Scheme != "http" || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.Path != "" || u.Opaque != "" {
		return errors.New("simulator requires a trusted loopback HTTP origin without path, query or fragment")
	}
	host := u.Hostname()
	ip := net.ParseIP(host)
	if host != "localhost" && (ip == nil || !ip.IsLoopback()) {
		return errors.New("simulator links must remain local")
	}
	return nil
}
func (s accountStore) mailPolicy() (accountMailPolicy, error) {
	var p accountMailPolicy
	db, e := s.db.open()
	if e != nil {
		return p, e
	}
	defer db.Close()
	e = db.QueryRow("SELECT mode,customer_origin,verification_ttl_minutes,reset_ttl_minutes,retention_hours FROM account_mail_policy WHERE id=1").Scan(&p.Mode, &p.CustomerOrigin, &p.VerificationTTL, &p.ResetTTL, &p.RetentionHours)
	if e == nil {
		e = p.validate()
	}
	return p, e
}
func (s accountStore) setMailPolicy(p accountMailPolicy) error {
	if e := p.validate(); e != nil {
		return e
	}
	db, e := s.db.open()
	if e != nil {
		return e
	}
	defer db.Close()
	_, e = db.Exec("UPDATE account_mail_policy SET mode=?,customer_origin=?,verification_ttl_minutes=?,reset_ttl_minutes=?,retention_hours=? WHERE id=1", p.Mode, p.CustomerOrigin, p.VerificationTTL, p.ResetTTL, p.RetentionHours)
	return e
}
func (s accountStore) queueAccountMail(tx *sql.Tx, a customerAccount, kind string, p accountMailPolicy, now time.Time) error {
	if p.Mode != "simulator" || (kind != "verify" && kind != "reset") {
		return errors.New("account mail unavailable")
	}
	if _, e := tx.Exec("UPDATE account_challenges SET used_at=? WHERE account_id=? AND kind=? AND used_at IS NULL", now.Unix(), a.ID, kind); e != nil {
		return e
	}
	token := accountRandom()
	hash := accountHash(token)
	ttl := p.VerificationTTL
	subject := "Ověřte svůj e-mail · GEREQ"
	action := "verify"
	intro := "Potvrďte e-mail svého zákaznického účtu GEREQ."
	if kind == "reset" {
		ttl = p.ResetTTL
		subject = "Obnovení hesla · GEREQ"
		action = "reset"
		intro = "Požádali jste o obnovení hesla zákaznického účtu GEREQ."
	}
	expires := now.Add(time.Duration(ttl) * time.Minute)
	link := p.CustomerOrigin + "/#customer-view/" + action + "/" + token
	body := fmt.Sprintf("%s\n\n%s\n\nOdkaz je jednorázový a platí do %s.\nPokud jste o tuto akci nepožádali, zprávu ignorujte.\n", intro, link, expires.Format(time.RFC3339))
	if _, e := tx.Exec("INSERT INTO account_challenges(token_hash,account_id,kind,expires_at,created_at) VALUES(?,?,?,?,?)", hash, a.ID, kind, expires.Unix(), now.Unix()); e != nil {
		return e
	}
	if _, e := tx.Exec("INSERT INTO account_mail(id,account_id,recipient,kind,subject,body,action_url,token_hash,created_at) VALUES(?,?,?,?,?,?,?,?,?)", "mail-"+accountRandom()[:24], a.ID, a.Email, kind, subject, body, link, hash, now.Unix()); e != nil {
		return e
	}
	if _, e := tx.Exec("DELETE FROM account_mail WHERE created_at<?", now.Add(-time.Duration(p.RetentionHours)*time.Hour).Unix()); e != nil {
		return e
	}
	if _, e := tx.Exec("DELETE FROM account_challenges WHERE expires_at<?", now.Add(-time.Duration(p.RetentionHours)*time.Hour).Unix()); e != nil {
		return e
	}
	_, e := tx.Exec("INSERT INTO account_events(account_id,action,site_id,occurred_at) VALUES(?,?,?,?)", a.ID, "email-"+kind+"-queued", "", now.Format(time.RFC3339))
	return e
}
func (a *centralAPI) requestVerification(w http.ResponseWriter, r *http.Request, s accountSession) {
	if r.Method != "POST" {
		apiError(w, 405, "method not allowed")
		return
	}
	var in struct{}
	if !parseAPIJSON(w, r, &in) {
		return
	}
	if s.EmailVerified {
		apiJSON(w, 200, map[string]any{"accepted": true})
		return
	}
	now := time.Now().UTC()
	if !a.accounts.throttle("verify-mail:"+s.ID, 3, now) {
		apiError(w, 429, "Další ověřovací e-mail můžete vyžádat za 10 minut.")
		return
	}
	policy, e := a.accounts.mailPolicy()
	if e != nil || policy.Mode != "simulator" {
		apiError(w, 503, "Odesílání e-mailů není nastavené.")
		return
	}
	db, e := a.accounts.db.open()
	if e != nil {
		apiError(w, 503, "E-mail nelze připravit.")
		return
	}
	defer db.Close()
	tx, e := db.Begin()
	if e != nil {
		apiError(w, 503, "E-mail nelze připravit.")
		return
	}
	defer tx.Rollback()
	if e = a.accounts.queueAccountMail(tx, s.customerAccount, "verify", policy, now); e != nil || tx.Commit() != nil {
		apiError(w, 503, "E-mail nelze připravit.")
		return
	}
	apiJSON(w, 200, map[string]any{"accepted": true})
}
func (a *centralAPI) requestPasswordReset(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		apiError(w, 405, "method not allowed")
		return
	}
	var in struct {
		Email string `json:"email"`
	}
	if !parseAPIJSON(w, r, &in) {
		return
	}
	email, valid := accountEmail(in.Email)
	if !valid {
		apiError(w, 400, "Zadejte platný e-mail.")
		return
	}
	now := time.Now().UTC()
	client := r.Header.Get("X-Gereq-Client")
	if client == "" {
		client = "private-socket"
	}
	if !a.accounts.throttle("reset-request-client:"+accountHash(client), 20, now) {
		apiError(w, 429, "Příliš mnoho požadavků. Zkuste to za 10 minut.")
		return
	}
	policy, e := a.accounts.mailPolicy()
	if e != nil || policy.Mode != "simulator" {
		apiError(w, 503, "Odesílání e-mailů není nastavené.")
		return
	}
	accepted := func() {
		apiJSON(w, 200, map[string]any{"accepted": true, "message": "Pokud účet existuje, poslali jsme na jeho e-mail odkaz pro obnovení hesla."})
	}
	if !a.accounts.throttle("reset-mail:"+accountHash(email), 3, now) {
		accepted()
		return
	}
	db, e := a.accounts.db.open()
	if e != nil {
		apiError(w, 503, "Požadavek nyní nelze dokončit.")
		return
	}
	defer db.Close()
	var account customerAccount
	e = db.QueryRow("SELECT id,email,name,created_at FROM accounts WHERE email=?", email).Scan(&account.ID, &account.Email, &account.Name, &account.CreatedAt)
	if errors.Is(e, sql.ErrNoRows) {
		accepted()
		return
	}
	if e != nil {
		apiError(w, 503, "Požadavek nyní nelze dokončit.")
		return
	}
	tx, e := db.Begin()
	if e != nil {
		apiError(w, 503, "Požadavek nyní nelze dokončit.")
		return
	}
	defer tx.Rollback()
	if e = a.accounts.queueAccountMail(tx, account, "reset", policy, now); e != nil || tx.Commit() != nil {
		apiError(w, 503, "Požadavek nyní nelze dokončit.")
		return
	}
	accepted()
}
func validAccountToken(token string) bool {
	if len(token) != 64 {
		return false
	}
	_, e := hex.DecodeString(token)
	return e == nil
}
func (a *centralAPI) confirmAccountMail(w http.ResponseWriter, r *http.Request, kind string) {
	if r.Method != "POST" {
		apiError(w, 405, "method not allowed")
		return
	}
	var in struct {
		Token        string `json:"token"`
		Password     string `json:"password,omitempty"`
		Confirmation string `json:"password_confirmation,omitempty"`
	}
	if !parseAPIJSON(w, r, &in) {
		return
	}
	invalid := func() { apiError(w, 400, "Odkaz je neplatný, už použitý nebo vypršel. Vyžádejte si nový.") }
	if !validAccountToken(in.Token) {
		invalid()
		return
	}
	if kind == "verify" && (in.Password != "" || in.Confirmation != "") {
		invalid()
		return
	}
	if kind == "reset" && (len(in.Password) < 12 || len(in.Password) > 128 || in.Password != in.Confirmation) {
		apiError(w, 400, "Hesla se musí shodovat a mít 12–128 znaků.")
		return
	}
	now := time.Now().UTC()
	client := r.Header.Get("X-Gereq-Client")
	if client == "" {
		client = "private-socket"
	}
	if !a.accounts.throttle("confirm-client:"+accountHash(client), 30, now) {
		apiError(w, 429, "Příliš mnoho pokusů. Zkuste to za 10 minut.")
		return
	}
	db, e := a.accounts.db.open()
	if e != nil {
		apiError(w, 503, "Požadavek nyní nelze dokončit.")
		return
	}
	defer db.Close()
	var accountID string
	e = db.QueryRow("SELECT account_id FROM account_challenges WHERE token_hash=? AND kind=? AND used_at IS NULL AND expires_at>?", accountHash(in.Token), kind, now.Unix()).Scan(&accountID)
	if e != nil {
		invalid()
		return
	}
	var salt, hash []byte
	if kind == "reset" {
		salt = make([]byte, 32)
		if _, e = rand.Read(salt); e != nil {
			apiError(w, 503, "Heslo nelze změnit.")
			return
		}
		hash = accountPassword(in.Password, salt)
	}
	tx, e := db.Begin()
	if e != nil {
		apiError(w, 503, "Požadavek nyní nelze dokončit.")
		return
	}
	defer tx.Rollback()
	result, e := tx.Exec("UPDATE account_challenges SET used_at=? WHERE token_hash=? AND kind=? AND used_at IS NULL AND expires_at>?", now.Unix(), accountHash(in.Token), kind, now.Unix())
	if e != nil {
		apiError(w, 503, "Požadavek nyní nelze dokončit.")
		return
	}
	affected, _ := result.RowsAffected()
	if affected != 1 {
		invalid()
		return
	}
	if kind == "verify" {
		_, e = tx.Exec("UPDATE accounts SET email_verified_at=COALESCE(email_verified_at,?) WHERE id=?", now.Format(time.RFC3339), accountID)
	} else {
		_, e = tx.Exec("UPDATE accounts SET salt=?,password_hash=?,credential_version=credential_version+1 WHERE id=?", salt, hash, accountID)
		if e == nil {
			_, e = tx.Exec("DELETE FROM sessions WHERE account_id=?", accountID)
		}
	}
	if e == nil {
		if kind == "reset" {
			_, e = tx.Exec("UPDATE account_challenges SET used_at=? WHERE account_id=? AND used_at IS NULL", now.Unix(), accountID)
		} else {
			_, e = tx.Exec("UPDATE account_challenges SET used_at=? WHERE account_id=? AND kind='verify' AND used_at IS NULL", now.Unix(), accountID)
		}
	}
	if e == nil {
		_, e = tx.Exec("INSERT INTO account_events(account_id,action,site_id,occurred_at) VALUES(?,?,?,?)", accountID, "email-"+kind+"-confirmed", "", now.Format(time.RFC3339))
	}
	if e != nil || tx.Commit() != nil {
		apiError(w, 503, "Požadavek nyní nelze dokončit.")
		return
	}
	apiJSON(w, 200, map[string]any{"completed": true, "kind": kind, "sign_in_required": kind == "reset"})
}

func (a *centralAPI) accountMailAdminHTTP(w http.ResponseWriter, r *http.Request) bool {
	if r.URL.Path == "/v1/customer-mail-policy" {
		if r.URL.RawQuery != "" {
			apiError(w, 400, "query parameters unsupported")
			return true
		}
		if r.Method == "GET" {
			p, e := a.accounts.mailPolicy()
			if e != nil {
				apiError(w, 503, "mail policy unavailable")
			} else {
				apiJSON(w, 200, p)
			}
			return true
		}
		if r.Method == "PATCH" {
			var p accountMailPolicy
			if !parseAPIJSON(w, r, &p) {
				return true
			}
			if e := p.validate(); e != nil {
				apiError(w, 400, e.Error())
				return true
			}
			if e := a.accounts.setMailPolicy(p); e != nil {
				apiError(w, 503, "mail policy unavailable")
				return true
			}
			apiJSON(w, 200, p)
			return true
		}
		apiError(w, 405, "method not allowed")
		return true
	}
	if r.URL.Path != "/v1/mail-simulator/messages" && !strings.HasPrefix(r.URL.Path, "/v1/mail-simulator/messages/") {
		return false
	}
	if r.Method != "GET" || r.URL.RawQuery != "" {
		apiError(w, 404, "message not found")
		return true
	}
	policy, e := a.accounts.mailPolicy()
	if e != nil || policy.Mode != "simulator" {
		apiError(w, 404, "local inbox disabled")
		return true
	}
	db, e := a.accounts.db.open()
	if e != nil {
		apiError(w, 503, "mailbox unavailable")
		return true
	}
	defer db.Close()
	cutoff := time.Now().Add(-time.Duration(policy.RetentionHours) * time.Hour).Unix()
	if _, e = db.Exec("DELETE FROM account_mail WHERE created_at<?", cutoff); e != nil {
		apiError(w, 503, "mailbox unavailable")
		return true
	}
	if r.URL.Path == "/v1/mail-simulator/messages" {
		rows, e := db.Query("SELECT id,recipient,kind,subject,created_at FROM account_mail ORDER BY created_at DESC,rowid DESC LIMIT 100")
		if e != nil {
			apiError(w, 503, "mailbox unavailable")
			return true
		}
		defer rows.Close()
		out := []map[string]any{}
		for rows.Next() {
			var id, to, kind, subject string
			var created int64
			if rows.Scan(&id, &to, &kind, &subject, &created) != nil {
				apiError(w, 503, "mailbox unavailable")
				return true
			}
			out = append(out, map[string]any{"id": id, "recipient": to, "kind": kind, "subject": subject, "created_at": time.Unix(created, 0).UTC()})
		}
		if rows.Err() != nil {
			apiError(w, 503, "mailbox unavailable")
			return true
		}
		apiJSON(w, 200, map[string]any{"messages": out, "mode": "simulator"})
		return true
	}
	id := strings.TrimPrefix(r.URL.Path, "/v1/mail-simulator/messages/")
	if len(id) != 29 || !strings.HasPrefix(id, "mail-") {
		apiError(w, 404, "message not found")
		return true
	}
	var to, kind, subject, body, link string
	var created int64
	var used sql.NullInt64
	var expires sql.NullInt64
	e = db.QueryRow("SELECT m.recipient,m.kind,m.subject,m.body,m.action_url,m.created_at,c.used_at,c.expires_at FROM account_mail m LEFT JOIN account_challenges c ON c.token_hash=m.token_hash WHERE m.id=?", id).Scan(&to, &kind, &subject, &body, &link, &created, &used, &expires)
	if e != nil {
		apiError(w, 404, "message not found")
		return true
	}
	status := "active"
	if used.Valid {
		status = "used"
	} else if !expires.Valid || expires.Int64 <= time.Now().Unix() {
		status = "expired"
	}
	apiJSON(w, 200, map[string]any{"id": id, "recipient": to, "kind": kind, "subject": subject, "body": body, "action_url": link, "created_at": time.Unix(created, 0).UTC(), "link_status": status})
	return true
}
