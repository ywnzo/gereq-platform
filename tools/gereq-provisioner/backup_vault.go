package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

type BackupSecrets struct {
	Password     string `json:"password"`
	AccessKey    string `json:"aws_access_key_id,omitempty"`
	SecretKey    string `json:"aws_secret_access_key,omitempty"`
	SessionToken string `json:"aws_session_token,omitempty"`
}
type sealedBackupSecrets struct {
	Version    int    `json:"version"`
	Nonce      string `json:"nonce"`
	Ciphertext string `json:"ciphertext"`
}

var credentialIDPattern = regexp.MustCompile(`^cred-[a-f0-9]{24}$`)

func (a *backupAPI) vaultKey() ([]byte, error) {
	path := os.Getenv("GEREQ_BACKUP_MASTER_KEY_FILE")
	if !filepath.IsAbs(path) {
		return nil, errors.New("backup master key file is not configured")
	}
	info, err := os.Lstat(path)
	if err != nil || !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 {
		return nil, errors.New("backup master key must be a private regular file")
	}
	key, err := os.ReadFile(path)
	if err != nil || len(key) != 32 {
		return nil, errors.New("backup master key must contain exactly 32 random bytes")
	}
	return key, nil
}
func (a *backupAPI) vaultCipher() (cipher.AEAD, error) {
	key, err := a.vaultKey()
	if err != nil {
		return nil, err
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	return cipher.NewGCM(block)
}
func (a *backupAPI) createCredential(secret BackupSecrets) (string, error) {
	if strings.ContainsAny(secret.Password, "\x00\r\n") || strings.ContainsRune(secret.AccessKey, '\x00') || strings.ContainsRune(secret.SecretKey, '\x00') || strings.ContainsRune(secret.SessionToken, '\x00') {
		return "", errors.New("invalid backup credentials")
	}
	if len(strings.TrimSpace(secret.Password)) < 16 || len(secret.Password) > 4096 || len(secret.AccessKey) > 1024 || len(secret.SecretKey) > 4096 || len(secret.SessionToken) > 8192 || ((secret.AccessKey == "") != (secret.SecretKey == "")) {
		return "", errors.New("invalid backup credentials")
	}
	cipher, err := a.vaultCipher()
	if err != nil {
		return "", err
	}
	id, err := randomSecret()
	if err != nil {
		return "", err
	}
	id = "cred-" + id[:24]
	nonce := make([]byte, cipher.NonceSize())
	if _, err = rand.Read(nonce); err != nil {
		return "", err
	}
	raw, err := json.Marshal(secret)
	if err != nil {
		return "", err
	}
	encrypted := cipher.Seal(nil, nonce, raw, []byte(id))
	dir := filepath.Join(a.dir, "credentials")
	if err = privateBackupDir(dir); err != nil {
		return "", err
	}
	doc := sealedBackupSecrets{Version: 1, Nonce: base64.StdEncoding.EncodeToString(nonce), Ciphertext: base64.StdEncoding.EncodeToString(encrypted)}
	if err = atomicJSON(filepath.Join(dir, id+".json"), doc); err != nil {
		return "", err
	}
	return id, nil
}
func (a *backupAPI) loadCredential(id string) (*BackupSecrets, error) {
	if !credentialIDPattern.MatchString(id) {
		return nil, errors.New("invalid credential reference")
	}
	cipher, err := a.vaultCipher()
	if err != nil {
		return nil, err
	}
	var doc sealedBackupSecrets
	if err = decode(filepath.Join(a.dir, "credentials", id+".json"), &doc); err != nil {
		return nil, errors.New("backup credentials unavailable")
	}
	nonce, err := base64.StdEncoding.DecodeString(doc.Nonce)
	if err != nil || len(nonce) != cipher.NonceSize() || doc.Version != 1 {
		return nil, errors.New("invalid encrypted credentials")
	}
	encrypted, err := base64.StdEncoding.DecodeString(doc.Ciphertext)
	if err != nil {
		return nil, errors.New("invalid encrypted credentials")
	}
	raw, err := cipher.Open(nil, nonce, encrypted, []byte(id))
	if err != nil {
		return nil, errors.New("cannot decrypt backup credentials")
	}
	var secret BackupSecrets
	if err = json.Unmarshal(raw, &secret); err != nil {
		return nil, errors.New("invalid encrypted credentials")
	}
	return &secret, nil
}
