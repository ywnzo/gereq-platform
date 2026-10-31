package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

type Request struct {
	EnabledModules         []string `json:"enabled_modules,omitempty"`
	TrialDays              *int     `json:"trial_days,omitempty"`
	BrandName              string   `json:"brand_name,omitempty"`
	Schema                 int      `json:"schema_version"`
	RequestID              string   `json:"request_id"`
	SiteID                 string   `json:"site_id"`
	CustomerRef            string   `json:"customer_ref"`
	Package                string   `json:"package"`
	CatalogPackageID       string   `json:"catalog_package_id,omitempty"`
	CatalogPackageRevision int      `json:"catalog_package_revision,omitempty"`
	AllowedTemplates       []string `json:"allowed_templates,omitempty"`
	Release                string   `json:"release_id"`
	Port                   int      `json:"port"`
}
type Release struct {
	ModuleSelection bool                `json:"module_selection,omitempty"`
	ModuleRequires  map[string][]string `json:"module_requires,omitempty"`
	Schema          int                 `json:"schema_version"`
	ID              string              `json:"id"`
	Image           string              `json:"image"`
	Development     bool                `json:"development"`
	Modules         []string            `json:"modules"`
}
type State struct {
	ActiveModules []string          `json:"active_modules,omitempty"`
	RelocatedTo   string            `json:"relocated_to,omitempty"`
	LastUpdateID  string            `json:"last_update_id,omitempty"`
	Runtime       string            `json:"runtime"`
	Request       Request           `json:"request"`
	Release       Release           `json:"release"`
	Fingerprint   string            `json:"fingerprint"`
	Status        string            `json:"status"`
	Step          string            `json:"step"`
	Completed     []string          `json:"completed"`
	Error         string            `json:"error,omitempty"`
	Drafts        map[string]string `json:"drafts,omitempty"`
}

var sitePattern = regexp.MustCompile(`^[a-z][a-z0-9-]{2,39}$`)
var imagePattern = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9._/:@-]+$`)

func decode(path string, v any) error {
	f, e := os.Open(path)
	if e != nil {
		return e
	}
	defer f.Close()
	d := json.NewDecoder(io.LimitReader(f, 4<<20))
	d.DisallowUnknownFields()
	if e = d.Decode(v); e != nil {
		return e
	}
	if e = d.Decode(new(any)); e != io.EOF {
		return errors.New("expected exactly one JSON object")
	}
	return nil
}
func validate(r Request, l Release) error {
	if r.BrandName != "" && !validBusinessName(r.BrandName) {
		return errors.New("invalid business name")
	}
	if r.Schema != 1 || l.Schema != 1 {
		return errors.New("unsupported schema version")
	}
	if !sitePattern.MatchString(r.SiteID) {
		return errors.New("site_id must match [a-z][a-z0-9-]{2,39}")
	}
	if len(r.RequestID) < 8 || len(r.RequestID) > 128 || r.CustomerRef == "" || len(r.CustomerRef) > 128 {
		return errors.New("request_id/customer_ref missing or invalid")
	}
	if r.Package != "funnel-v1" || r.Release != l.ID || !sitePattern.MatchString(l.ID) {
		return errors.New("unsupported package or release")
	}
	if r.Port < 1024 || r.Port > 65535 {
		return errors.New("port must be 1024..65535")
	}
	if !imagePattern.MatchString(l.Image) {
		return errors.New("invalid image reference")
	}
	if !l.Development && !regexp.MustCompile(`@sha256:[a-f0-9]{64}$`).MatchString(l.Image) {
		return errors.New("non-development release requires an immutable image digest")
	}
	if err := validateSelectedModules(r, l); err != nil {
		return err
	}
	return nil
}
func fingerprint(r Request, l Release) string {
	b, _ := json.Marshal(struct {
		R Request
		L Release
	}{r, l})
	h := sha256.Sum256(b)
	return hex.EncodeToString(h[:])
}
func atomicJSON(path string, v any) error {
	b, e := json.MarshalIndent(v, "", "  ")
	if e != nil {
		return e
	}
	return atomicFile(path, append(b, '\n'))
}
func atomicFile(path string, b []byte) error {
	f, e := os.CreateTemp(filepath.Dir(path), ".pending-")
	if e != nil {
		return e
	}
	defer os.Remove(f.Name())
	if e = f.Chmod(0600); e == nil {
		_, e = f.Write(b)
	}
	if e == nil {
		e = f.Sync()
	}
	ce := f.Close()
	if e == nil {
		e = ce
	}
	if e != nil {
		return e
	}
	if e = os.Rename(f.Name(), path); e != nil {
		return e
	}
	d, e := os.Open(filepath.Dir(path))
	if e != nil {
		return e
	}
	defer d.Close()
	return d.Sync()
}
func ownedDir(root, site string) (string, error) {
	root, e := filepath.Abs(root)
	if e != nil {
		return "", e
	}
	if !sitePattern.MatchString(site) {
		return "", errors.New("invalid site ID")
	}
	p := filepath.Join(root, "sites", site)
	for q := p; ; q = filepath.Dir(q) {
		st, err := os.Lstat(q)
		if err == nil && st.Mode()&os.ModeSymlink != 0 {
			return "", fmt.Errorf("symlink refused: %s", q)
		}
		if err != nil && !os.IsNotExist(err) {
			return "", err
		}
		if q == filepath.Dir(q) {
			break
		}
	}
	if !strings.HasPrefix(p, root+string(os.PathSeparator)) {
		return "", errors.New("invalid root")
	}
	return p, nil
}
