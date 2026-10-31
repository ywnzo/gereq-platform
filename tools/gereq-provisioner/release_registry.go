package main

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	_ "github.com/mattn/go-sqlite3"
)

type registeredRelease struct {
	Release
	OriginalImage   string            `json:"original_image"`
	ImageID         string            `json:"image_id,omitempty"`
	PlatformVersion string            `json:"platform_version,omitempty"`
	ModuleVersions  map[string]string `json:"module_versions,omitempty"`
	Active          bool              `json:"active"`
	Verified        bool              `json:"verified"`
	Source          string            `json:"source"`
	RegisteredAt    string            `json:"registered_at"`
}
type releaseRegistry struct{ path string }

func (s releaseRegistry) open() (*sql.DB, error) {
	info, err := os.Lstat(s.path)
	if os.IsNotExist(err) {
		f, e := os.OpenFile(s.path, os.O_CREATE|os.O_EXCL|os.O_RDWR, 0600)
		if e != nil {
			return nil, e
		}
		f.Close()
	} else if err != nil || !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 {
		return nil, errors.New("release registry must be a private regular file")
	}
	u := url.URL{Scheme: "file", Path: s.path, RawQuery: "_foreign_keys=on&_busy_timeout=5000&_journal_mode=WAL"}
	db, err := sql.Open("sqlite3", u.String())
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1)
	return db, nil
}
func (s releaseRegistry) initialize(catalog string) error {
	db, err := s.open()
	if err != nil {
		return err
	}
	defer db.Close()
	var version int
	if err = db.QueryRow("PRAGMA user_version").Scan(&version); err != nil {
		return err
	}
	if version > 1 {
		return errors.New("unsupported release registry schema version")
	}
	if _, err = db.Exec(`CREATE TABLE IF NOT EXISTS registry_meta (name TEXT PRIMARY KEY, value TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS releases (id TEXT PRIMARY KEY, record TEXT NOT NULL, active INTEGER NOT NULL CHECK(active IN (0,1)));
 CREATE TABLE IF NOT EXISTS release_requests (request_key TEXT PRIMARY KEY, payload_hash TEXT NOT NULL, response TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS release_events (sequence INTEGER PRIMARY KEY AUTOINCREMENT, release_id TEXT NOT NULL, action TEXT NOT NULL, occurred_at TEXT NOT NULL, details TEXT NOT NULL);
 PRAGMA user_version=1;`); err != nil {
		return err
	}
	var done string
	err = db.QueryRow("SELECT value FROM registry_meta WHERE name='legacy_import_v1'").Scan(&done)
	if err == nil || catalog == "" {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	entries, err := os.ReadDir(catalog)
	if err != nil {
		return err
	}
	tx, err := db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		id := strings.TrimSuffix(entry.Name(), ".json")
		path := filepath.Join(catalog, entry.Name())
		info, err := os.Lstat(path)
		if err != nil || !info.Mode().IsRegular() || !sitePattern.MatchString(id) {
			return errors.New("invalid legacy release entry")
		}
		var release Release
		if decode(path, &release) != nil || release.ID != id || release.Schema != 1 || !release.Development || validate(Request{Schema: 1, RequestID: "registry-import-001", SiteID: "registry-import", CustomerRef: "registry-import", Package: "funnel-v1", Release: id, Port: 18499}, release) != nil {
			return errors.New("invalid legacy release metadata")
		}
		record := registeredRelease{Release: release, OriginalImage: release.Image, Active: true, Source: "legacy-import", RegisteredAt: time.Now().UTC().Format(time.RFC3339Nano)}
		raw, _ := json.Marshal(record)
		result, err := tx.Exec("INSERT OR IGNORE INTO releases(id,record,active) VALUES(?,?,1)", id, string(raw))
		if err != nil {
			return err
		}
		n, _ := result.RowsAffected()
		if n > 0 {
			if _, err = tx.Exec("INSERT INTO release_events(release_id,action,occurred_at,details) VALUES(?,?,?,?)", id, "legacy-import", record.RegisteredAt, `{"verified":false}`); err != nil {
				return err
			}
		}
	}
	if _, err = tx.Exec("INSERT INTO registry_meta(name,value) VALUES('legacy_import_v1','complete')"); err != nil {
		return err
	}
	return tx.Commit()
}
func (s releaseRegistry) list(activeOnly bool) ([]registeredRelease, error) {
	db, err := s.open()
	if err != nil {
		return nil, err
	}
	defer db.Close()
	query := "SELECT record,active FROM releases"
	if activeOnly {
		query += " WHERE active=1"
	}
	query += " ORDER BY id"
	rows, err := db.Query(query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []registeredRelease{}
	for rows.Next() {
		var raw string
		var active bool
		var item registeredRelease
		if err = rows.Scan(&raw, &active); err != nil {
			return nil, err
		}
		if err = json.Unmarshal([]byte(raw), &item); err != nil {
			return nil, err
		}
		item.Active = active
		out = append(out, item)
	}
	return out, rows.Err()
}
func (s releaseRegistry) get(id string) (*registeredRelease, error) {
	if !sitePattern.MatchString(id) {
		return nil, errors.New("invalid release ID")
	}
	db, err := s.open()
	if err != nil {
		return nil, err
	}
	defer db.Close()
	var raw string
	var active bool
	if err = db.QueryRow("SELECT record,active FROM releases WHERE id=?", id).Scan(&raw, &active); err != nil {
		return nil, err
	}
	var record registeredRelease
	if err = json.Unmarshal([]byte(raw), &record); err != nil {
		return nil, err
	}
	record.Active = active
	return &record, nil
}

var errReleaseConflict = errors.New("release ID or idempotency key already registered with different content")

func (s releaseRegistry) replay(key, hash string) (*registeredRelease, error) {
	db, err := s.open()
	if err != nil {
		return nil, err
	}
	defer db.Close()
	var storedHash, raw string
	if err = db.QueryRow("SELECT payload_hash,response FROM release_requests WHERE request_key=?", key).Scan(&storedHash, &raw); errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	} else if err != nil {
		return nil, err
	}
	if storedHash != hash {
		return nil, errReleaseConflict
	}
	var record registeredRelease
	err = json.Unmarshal([]byte(raw), &record)
	return &record, err
}
func (s releaseRegistry) register(record registeredRelease, key, hash string) (*registeredRelease, bool, error) {
	db, err := s.open()
	if err != nil {
		return nil, false, err
	}
	defer db.Close()
	tx, err := db.Begin()
	if err != nil {
		return nil, false, err
	}
	defer tx.Rollback()
	var previousRaw string
	var active bool
	created := true
	err = tx.QueryRow("SELECT record,active FROM releases WHERE id=?", record.ID).Scan(&previousRaw, &active)
	if err == nil {
		record.Active = active
		var previous registeredRelease
		if json.Unmarshal([]byte(previousRaw), &previous) != nil {
			return nil, false, errors.New("invalid release registry record")
		}
		if previous.Verified {
			if previous.ImageID != record.ImageID || previous.PlatformVersion != record.PlatformVersion || registryHash(previous.ModuleVersions) != registryHash(record.ModuleVersions) {
				return nil, false, errReleaseConflict
			}
			record = previous
			record.Active = active
			created = false
		}
	} else if !errors.Is(err, sql.ErrNoRows) {
		return nil, false, err
	}
	raw, _ := json.Marshal(record)
	if created {
		_, err = tx.Exec("INSERT INTO releases(id,record,active) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET record=excluded.record", record.ID, string(raw), record.Active)
		if err != nil {
			return nil, false, err
		}
		if _, err = tx.Exec("INSERT INTO release_events(release_id,action,occurred_at,details) VALUES(?,?,?,?)", record.ID, "registered", record.RegisteredAt, fmt.Sprintf(`{"image_id":%q}`, record.ImageID)); err != nil {
			return nil, false, err
		}
	}
	if _, err = tx.Exec("INSERT INTO release_requests(request_key,payload_hash,response) VALUES(?,?,?)", key, hash, string(raw)); err != nil {
		return nil, false, err
	}
	if err = tx.Commit(); err != nil {
		return nil, false, err
	}
	return &record, created, nil
}
func (s releaseRegistry) setActive(id string, active bool) error {
	db, err := s.open()
	if err != nil {
		return err
	}
	defer db.Close()
	tx, err := db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	result, err := tx.Exec("UPDATE releases SET active=? WHERE id=? AND active<>?", active, id, active)
	if err != nil {
		return err
	}
	n, _ := result.RowsAffected()
	if n == 0 {
		var found string
		if err = tx.QueryRow("SELECT id FROM releases WHERE id=?", id).Scan(&found); err != nil {
			return err
		}
	} else {
		if _, err = tx.Exec("INSERT INTO release_events(release_id,action,occurred_at,details) VALUES(?,?,?,?)", id, "availability", time.Now().UTC().Format(time.RFC3339Nano), fmt.Sprintf(`{"active":%t}`, active)); err != nil {
			return err
		}
	}
	return tx.Commit()
}
func (a *centralAPI) discoverRelease(ctx context.Context, image string) (registeredRelease, error) {
	var result registeredRelease
	if len(image) > 256 || !imagePattern.MatchString(image) {
		return result, errors.New("invalid local image reference")
	}
	raw, err := a.backup.engine.Runner.Run(ctx, []string{"image", "inspect", "--format", "{{.Id}}", image})
	if err != nil {
		return result, errors.New("image is not installed locally")
	}
	id := normalizeImageID(string(raw))
	if id == "" {
		return result, errors.New("invalid local image identity")
	}
	info, err := a.backup.engine.imageProvenance(ctx, id)
	if err != nil {
		return result, err
	}
	if !info.Development || !sitePattern.MatchString(info.ReleaseID) {
		return result, errors.New("a local development image with valid GEREQ provenance is required")
	}
	modules := []string{"cms", "site_inquiries", "analytics"}
	if len(info.ModuleVersions) != len(modules) {
		return result, errors.New("unsupported release module set")
	}
	for _, module := range modules {
		if !versionPattern.MatchString(info.ModuleVersions[module]) {
			return result, errors.New("image lacks required module versions")
		}
	}
	result = registeredRelease{Release: Release{Schema: 1, ID: info.ReleaseID, Image: id, Development: true, Modules: modules, ModuleSelection: info.ModuleSelection, ModuleRequires: info.ModuleRequires}, OriginalImage: image, ImageID: id, PlatformVersion: info.PlatformVersion, ModuleVersions: info.ModuleVersions, Active: true, Verified: true, Source: "local-image", RegisteredAt: time.Now().UTC().Format(time.RFC3339Nano)}
	return result, nil
}

type localReleaseImage struct {
	Image   string `json:"image"`
	ImageID string `json:"image_id"`
}

func (a *centralAPI) localReleaseImages(ctx context.Context) ([]localReleaseImage, error) {
	raw, err := a.backup.engine.Runner.Run(ctx, []string{"image", "ls", "--format", "json"})
	if err != nil {
		return nil, errors.New("local image inventory unavailable")
	}
	var images []struct {
		ID       string   `json:"Id"`
		Names    []string `json:"Names"`
		RepoTags []string `json:"RepoTags"`
	}
	if json.Unmarshal(raw, &images) != nil {
		return nil, errors.New("invalid local image inventory")
	}
	result := []localReleaseImage{}
	seen := map[string]bool{}
	for _, image := range images {
		id := normalizeImageID(image.ID)
		if id == "" {
			continue
		}
		for _, name := range append(image.Names, image.RepoTags...) {
			if !strings.HasPrefix(name, "localhost/gereq-local:") || !imagePattern.MatchString(name) || seen[name] {
				continue
			}
			seen[name] = true
			result = append(result, localReleaseImage{name, id})
		}
	}
	sort.Slice(result, func(i, j int) bool { return result[i].Image < result[j].Image })
	return result, nil
}
func (a *centralAPI) releaseRegistryHTTP(w http.ResponseWriter, r *http.Request) bool {
	if r.URL.Path != "/v1/releases" && r.URL.Path != "/v1/local-images" && !strings.HasPrefix(r.URL.Path, "/v1/releases/") {
		return false
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters are not supported")
		return true
	}
	if r.Method == "GET" && r.URL.Path == "/v1/local-images" {
		images, err := a.localReleaseImages(r.Context())
		if err != nil {
			apiError(w, 503, err.Error())
		} else {
			apiJSON(w, 200, map[string]any{"images": images})
		}
		return true
	}
	if r.Method == "GET" && r.URL.Path == "/v1/releases" {
		records, err := a.registry.list(true)
		if err != nil {
			apiError(w, 503, "release registry unavailable")
		} else {
			releases := []Release{}
			for _, record := range records {
				releases = append(releases, record.Release)
			}
			apiJSON(w, 200, map[string]any{"releases": releases})
		}
		return true
	}
	if r.Method == "GET" && strings.HasPrefix(r.URL.Path, "/v1/releases/") {
		record, err := a.registry.get(strings.TrimPrefix(r.URL.Path, "/v1/releases/"))
		if err != nil {
			apiError(w, 404, "release not found")
		} else {
			apiJSON(w, 200, record)
		}
		return true
	}
	if r.Method == "POST" && r.URL.Path == "/v1/releases" {
		var input struct {
			Image           string `json:"image"`
			ExpectedImageID string `json:"expected_image_id,omitempty"`
		}
		if !parseAPIJSON(w, r, &input) {
			return true
		}
		key := r.Header.Get("Idempotency-Key")
		if !apiKeyPattern.MatchString(key) {
			apiError(w, 400, "valid Idempotency-Key required")
			return true
		}
		hash := registryHash(input)
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		old, err := a.registry.replay(key, hash)
		if err != nil {
			if errors.Is(err, errReleaseConflict) {
				apiError(w, 409, "idempotency key already used for different input")
			} else {
				apiError(w, 503, "release registry unavailable")
			}
			return true
		}
		if old != nil {
			apiJSON(w, 200, old)
			return true
		}
		record, err := a.discoverRelease(r.Context(), input.Image)
		if err != nil {
			apiError(w, 400, err.Error())
			return true
		}
		if input.ExpectedImageID != "" && (normalizeImageID(input.ExpectedImageID) == "" || normalizeImageID(input.ExpectedImageID) != record.ImageID) {
			apiError(w, 409, "selected image changed; refresh local images")
			return true
		}
		saved, created, err := a.registry.register(record, key, hash)
		if err != nil {
			if errors.Is(err, errReleaseConflict) {
				apiError(w, 409, "release ID already registered with different content")
			} else {
				apiError(w, 503, "release registry unavailable")
			}
			return true
		}
		status := 200
		if created {
			status = 201
		}
		apiJSON(w, status, saved)
		return true
	}
	if r.Method == "PATCH" && strings.HasPrefix(r.URL.Path, "/v1/releases/") {
		id := strings.TrimPrefix(r.URL.Path, "/v1/releases/")
		if !sitePattern.MatchString(id) {
			apiError(w, 404, "release not found")
			return true
		}
		var input struct {
			Active *bool `json:"active"`
		}
		if !parseAPIJSON(w, r, &input) {
			return true
		}
		if input.Active == nil {
			apiError(w, 400, "active is required")
			return true
		}
		if err := a.registry.setActive(id, *input.Active); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				apiError(w, 404, "release not found")
			} else {
				apiError(w, 503, "release registry unavailable")
			}
		} else {
			record, _ := a.registry.get(id)
			apiJSON(w, 200, record)
		}
		return true
	}
	apiError(w, 405, "release operation not supported")
	return true
}

func registryHash(value any) string {
	raw, _ := json.Marshal(value)
	return fmt.Sprintf("%x", sha256.Sum256(raw))
}
