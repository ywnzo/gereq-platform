package main

import (
	"database/sql"
	"errors"
	"os"
)

// Version 2 is additive. Existing accounts must verify their email; ownership and passwords are preserved.
func (s accountStore) migrateEmailSchema(db *sql.DB, backupExisting bool) error {
	if backupExisting {
		path := s.db.path + ".before-email-v2.sqlite"
		f, e := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
		if e == nil {
			f.Close()
			if _, e = db.Exec("VACUUM INTO ?", path); e != nil {
				os.Remove(path)
				return errors.New("could not snapshot account database before migration")
			}
		} else if !os.IsExist(e) {
			return e
		} else {
			info, err := os.Lstat(path)
			if err != nil || !info.Mode().IsRegular() || info.Mode().Perm() != 0600 {
				return errors.New("account migration snapshot is not a private regular file")
			}
		}
	}
	tx, e := db.Begin()
	if e != nil {
		return e
	}
	defer tx.Rollback()
	_, e = tx.Exec(`ALTER TABLE accounts ADD COLUMN email_verified_at TEXT;
 ALTER TABLE accounts ADD COLUMN credential_version INTEGER NOT NULL DEFAULT 1;
 ALTER TABLE sessions ADD COLUMN credential_version INTEGER NOT NULL DEFAULT 1;
 CREATE TABLE account_challenges(token_hash TEXT PRIMARY KEY,account_id TEXT NOT NULL REFERENCES accounts(id),kind TEXT NOT NULL CHECK(kind IN ('verify','reset')),expires_at INTEGER NOT NULL,used_at INTEGER,created_at INTEGER NOT NULL);
 CREATE INDEX account_challenges_account ON account_challenges(account_id,kind);
 CREATE TABLE account_mail_policy(id INTEGER PRIMARY KEY CHECK(id=1),mode TEXT NOT NULL,customer_origin TEXT NOT NULL,verification_ttl_minutes INTEGER NOT NULL,reset_ttl_minutes INTEGER NOT NULL,retention_hours INTEGER NOT NULL);
 INSERT INTO account_mail_policy VALUES(1,'disabled','',60,30,24);
 CREATE TABLE account_mail(id TEXT PRIMARY KEY,account_id TEXT NOT NULL REFERENCES accounts(id),recipient TEXT NOT NULL,kind TEXT NOT NULL,subject TEXT NOT NULL,body TEXT NOT NULL,action_url TEXT NOT NULL,token_hash TEXT NOT NULL,created_at INTEGER NOT NULL);
 PRAGMA user_version=2;`)
	if e != nil {
		return e
	}
	return tx.Commit()
}
