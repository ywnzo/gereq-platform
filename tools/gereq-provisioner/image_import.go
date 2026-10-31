package main

import (
	"archive/tar"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"
	"time"
)

const maxImageArchive int64 = 2 << 30

var legacyLayerAlias = regexp.MustCompile(`^[a-f0-9]{64}/layer\.tar$`)
var legacyLayerTarget = regexp.MustCompile(`^\.\./[a-f0-9]{64}\.tar$`)
var imageImportID = regexp.MustCompile(`^img-[a-f0-9]{24}$`)
var archiveDigest = regexp.MustCompile(`^[a-f0-9]{64}$`)

type imageImportJob struct {
	ID        string    `json:"id"`
	Key       string    `json:"request_key"`
	SHA256    string    `json:"sha256"`
	Status    string    `json:"status"`
	Tags      []string  `json:"images"`
	CreatedAt time.Time `json:"created_at"`
}

func (a *centralAPI) imageImportDir() string { return filepath.Join(a.dir, "image-imports") }
func (a *centralAPI) saveImageImport(j imageImportJob) error {
	return atomicJSON(filepath.Join(a.imageImportDir(), j.ID+".json"), j)
}
func imageImportView(j imageImportJob) map[string]any {
	return map[string]any{"id": j.ID, "status": j.Status, "images": j.Tags, "created_at": j.CreatedAt}
}
func (a *centralAPI) recoverImageImports() error {
	if err := privateBackupDir(a.imageImportDir()); err != nil {
		return err
	}
	entries, err := os.ReadDir(a.imageImportDir())
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if strings.HasPrefix(entry.Name(), "upload-") && strings.HasSuffix(entry.Name(), ".tar") {
			if err = os.Remove(filepath.Join(a.imageImportDir(), entry.Name())); err != nil {
				return err
			}
			continue
		}
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var j imageImportJob
		if decode(filepath.Join(a.imageImportDir(), entry.Name()), &j) != nil || !imageImportID.MatchString(j.ID) || entry.Name() != j.ID+".json" {
			return errors.New("invalid image import state")
		}
		if j.Status == "running" {
			j.Status = "interrupted"
			if err = a.saveImageImport(j); err != nil {
				return err
			}
		}
		if err = os.Remove(filepath.Join(a.imageImportDir(), j.ID+".tar")); err != nil && !os.IsNotExist(err) {
			return err
		}
	}
	return nil
}
func (a *centralAPI) findImageImport(key string) (*imageImportJob, error) {
	entries, err := os.ReadDir(a.imageImportDir())
	if err != nil {
		return nil, err
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var j imageImportJob
		if err = decode(filepath.Join(a.imageImportDir(), entry.Name()), &j); err != nil {
			return nil, err
		}
		if j.Key == key {
			return &j, nil
		}
	}
	return nil, nil
}

// Validate the outer save archive without extracting any member or running image code.
func imageArchiveTags(file string) ([]string, error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	reader := tar.NewReader(f)
	var tags []string
	seen := map[string]bool{}
	manifest := false
	regularFiles := map[string]bool{}
	aliases := []string{}
	legacyTags := []string{}
	count := 0
	for {
		h, e := reader.Next()
		if e == io.EOF {
			break
		}
		if e != nil {
			return nil, errors.New("invalid tar archive")
		}
		count++
		if count > 100000 {
			return nil, errors.New("too many archive entries")
		}
		name := strings.TrimSuffix(h.Name, "/")
		if name == "" || strings.HasPrefix(name, "/") || path.Clean(name) != name || name == ".." || strings.HasPrefix(name, "../") || strings.Contains(name, "\\") {
			return nil, errors.New("unsafe archive path")
		}
		// Podman save includes legacy <id>/layer.tar -> ../<digest>.tar aliases.
		// Permit only that exact one-level alias shape, never arbitrary links or directories.
		if h.Typeflag == tar.TypeSymlink && legacyLayerAlias.MatchString(name) && legacyLayerTarget.MatchString(h.Linkname) {
			aliases = append(aliases, strings.TrimPrefix(h.Linkname, "../"))
			continue
		}
		if h.Typeflag != tar.TypeReg && h.Typeflag != tar.TypeRegA && h.Typeflag != tar.TypeDir {
			return nil, errors.New("unsupported outer archive entry")
		}
		if h.Typeflag != tar.TypeDir {
			regularFiles[name] = true
		}
		if h.Name == "repositories" {
			if h.Size > 1<<20 {
				return nil, errors.New("invalid legacy image tags")
			}
			raw, e := io.ReadAll(io.LimitReader(reader, (1<<20)+1))
			if e != nil {
				return nil, e
			}
			var repositories map[string]map[string]string
			if json.Unmarshal(raw, &repositories) != nil {
				return nil, errors.New("invalid legacy image tags")
			}
			for repo, versions := range repositories {
				for tag := range versions {
					legacyTags = append(legacyTags, repo+":"+tag)
				}
			}
			continue
		}
		if h.Name != "manifest.json" {
			continue
		}
		if manifest || h.Size < 1 || h.Size > 1<<20 {
			return nil, errors.New("invalid image manifest")
		}
		manifest = true
		raw, e := io.ReadAll(io.LimitReader(reader, (1<<20)+1))
		if e != nil {
			return nil, e
		}
		var entries []struct {
			RepoTags []string `json:"RepoTags"`
		}
		if json.Unmarshal(raw, &entries) != nil || len(entries) != 1 || len(entries[0].RepoTags) < 1 || len(entries[0].RepoTags) > 8 {
			return nil, errors.New("one tagged Docker-save image required")
		}
		for _, tag := range entries[0].RepoTags {
			if !strings.HasPrefix(tag, "localhost/gereq-local:") || !imagePattern.MatchString(tag) || seen[tag] {
				return nil, errors.New("only unique localhost/gereq-local tags are allowed")
			}
			seen[tag] = true
			tags = append(tags, tag)
		}
	}
	for _, target := range aliases {
		if !regularFiles[target] {
			return nil, errors.New("legacy layer alias target missing")
		}
	}
	for _, tag := range legacyTags {
		if !seen[tag] {
			return nil, errors.New("legacy tag differs from manifest")
		}
	}
	if !manifest || len(tags) == 0 {
		return nil, errors.New("Docker-save manifest required")
	}
	return tags, nil
}
func (a *centralAPI) launchImageImport(j imageImportJob) {
	a.backup.active = true
	a.backup.workers.Add(1)
	go func() {
		defer a.backup.workers.Done()
		ctx, cancel := context.WithTimeout(a.backup.jobContext, 30*time.Minute)
		defer cancel()
		file := filepath.Join(a.imageImportDir(), j.ID+".tar")
		_, err := a.backup.engine.Runner.Run(ctx, []string{"load", "--input", file})
		if err == nil {
			for _, tag := range j.Tags {
				var raw []byte
				raw, err = a.backup.engine.Runner.Run(ctx, []string{"image", "inspect", "--format", "{{.Id}}", tag})
				if err != nil || normalizeImageID(string(raw)) == "" {
					err = errors.New("imported image unavailable")
					break
				}
			}
		}
		_ = os.Remove(file)
		j.Status = "complete"
		if err != nil {
			j.Status = "failed"
		}
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		if a.saveImageImport(j) == nil {
			a.backup.active = false
		}
	}()
}
func (a *centralAPI) imageImportsHTTP(w http.ResponseWriter, r *http.Request) bool {
	if r.URL.Path != "/v1/image-imports" && !strings.HasPrefix(r.URL.Path, "/v1/image-imports/") {
		return false
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters not supported")
		return true
	}
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/image-imports/"), "/")
	if r.Method == "GET" {
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		var j *imageImportJob
		var err error
		if len(parts) == 2 && parts[0] == "requests" && apiKeyPattern.MatchString(parts[1]) {
			j, err = a.findImageImport(parts[1])
		} else if len(parts) == 1 && imageImportID.MatchString(parts[0]) {
			var record imageImportJob
			err = decode(filepath.Join(a.imageImportDir(), parts[0]+".json"), &record)
			if err == nil {
				j = &record
			}
		}
		if err == nil && j == nil && len(parts) == 2 && parts[0] == "requests" {
			// A request may still be streaming/validating before its durable job exists.
			if !a.imageImportMu.TryLock() {
				apiError(w, 409, "upload is still being received")
				return true
			}
			a.imageImportMu.Unlock()
		}
		if err != nil || j == nil {
			apiError(w, 404, "image import not found")
		} else {
			apiJSON(w, 200, imageImportView(*j))
		}
		return true
	}
	if r.Method != "POST" || r.URL.Path != "/v1/image-imports" {
		apiError(w, 405, "method not supported")
		return true
	}
	key, digest := r.Header.Get("Idempotency-Key"), r.Header.Get("X-Archive-SHA256")
	if !apiKeyPattern.MatchString(key) || !archiveDigest.MatchString(digest) {
		apiError(w, 400, "request identity and SHA256 required")
		return true
	}
	if r.ContentLength < 1 || r.ContentLength > maxImageArchive {
		apiError(w, 413, "archive must be 1 byte to 2 GiB")
		return true
	}
	if !a.imageImportMu.TryLock() {
		apiError(w, 409, "another upload is running")
		return true
	}
	defer a.imageImportMu.Unlock()
	a.backup.mu.Lock()
	old, err := a.findImageImport(key)
	busy := a.backup.active
	a.backup.mu.Unlock()
	if err != nil {
		apiError(w, 503, "image import storage unavailable")
		return true
	}
	if old != nil {
		if old.SHA256 != digest {
			apiError(w, 409, "request identity already used")
		} else {
			apiJSON(w, 200, imageImportView(*old))
		}
		return true
	}
	if busy {
		apiError(w, 409, "another operation is running")
		return true
	}
	var space syscall.Statfs_t
	if syscall.Statfs(a.imageImportDir(), &space) != nil || space.Bavail*uint64(space.Bsize) < uint64(r.ContentLength)+(64<<20) {
		apiError(w, 409, "insufficient staging space")
		return true
	}
	controller := http.NewResponseController(w)
	_ = controller.SetReadDeadline(time.Now().Add(10 * time.Minute))
	_ = controller.SetWriteDeadline(time.Now().Add(10 * time.Minute))
	file, err := os.CreateTemp(a.imageImportDir(), "upload-*.tar")
	if err != nil {
		apiError(w, 503, "cannot stage archive")
		return true
	}
	defer os.Remove(file.Name())
	defer file.Close()
	hash := sha256.New()
	size, err := io.Copy(io.MultiWriter(file, hash), http.MaxBytesReader(w, r.Body, maxImageArchive))
	if err != nil || size != r.ContentLength || hex.EncodeToString(hash.Sum(nil)) != digest {
		apiError(w, 400, "incomplete archive or SHA256 mismatch")
		return true
	}
	if err = file.Close(); err != nil {
		apiError(w, 503, "archive unavailable")
		return true
	}
	tags, err := imageArchiveTags(file.Name())
	if err != nil {
		apiError(w, 400, "invalid Docker-save archive or unsupported tags")
		return true
	}
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	if a.backup.active {
		apiError(w, 409, "another operation is running")
		return true
	}
	for _, tag := range tags {
		if _, err = a.backup.engine.Runner.Run(r.Context(), []string{"image", "inspect", "--format", "{{.Id}}", tag}); err == nil {
			apiError(w, 409, "image tag already exists; use a new tag")
			return true
		}
	}
	token, err := randomSecret()
	if err != nil {
		apiError(w, 503, "cannot reserve import")
		return true
	}
	j := imageImportJob{ID: "img-" + token[:24], Key: key, SHA256: digest, Status: "running", Tags: tags, CreatedAt: time.Now().UTC()}
	archive := filepath.Join(a.imageImportDir(), j.ID+".tar")
	if os.Rename(file.Name(), archive) != nil {
		apiError(w, 503, "cannot retain archive")
		return true
	}
	if a.saveImageImport(j) != nil {
		_ = os.Remove(archive)
		apiError(w, 503, "cannot save import")
		return true
	}
	a.launchImageImport(j)
	apiJSON(w, 202, imageImportView(j))
	return true
}
