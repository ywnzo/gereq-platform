package main

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type importRuntime struct {
	base   Runner
	loaded bool
	loads  int
}

func (r *importRuntime) Run(ctx context.Context, args []string) ([]byte, error) {
	if len(args) > 0 && args[0] == "load" {
		r.loaded = true
		r.loads++
		return []byte("private import output"), nil
	}
	if len(args) > 0 && args[0] == "image" && args[len(args)-1] == "localhost/gereq-local:upload-test" {
		if r.loaded {
			return []byte("sha256:" + strings.Repeat("a", 64)), nil
		}
		return nil, errors.New("not present")
	}
	return r.base.Run(ctx, args)
}
func archiveBytes(t *testing.T, tag, name string, kind byte) []byte {
	t.Helper()
	var out bytes.Buffer
	w := tar.NewWriter(&out)
	raw, _ := json.Marshal([]map[string]any{{"RepoTags": []string{tag}}})
	if name == "" {
		name = "manifest.json"
	}
	h := &tar.Header{Name: name, Mode: 0600, Typeflag: kind, Size: int64(len(raw))}
	if kind == tar.TypeSymlink {
		h.Size = 0
		h.Linkname = "/private/file"
	}
	if err := w.WriteHeader(h); err != nil {
		t.Fatal(err)
	}
	if h.Size > 0 {
		if _, err := w.Write(raw); err != nil {
			t.Fatal(err)
		}
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	return out.Bytes()
}
func importReq(a *centralAPI, body []byte, key, digest string) *httptest.ResponseRecorder {
	r := httptest.NewRequest("POST", "/v1/image-imports", bytes.NewReader(body))
	r.Header.Set("Idempotency-Key", key)
	r.Header.Set("X-Archive-SHA256", digest)
	w := httptest.NewRecorder()
	a.ServeHTTP(w, r)
	return w
}
func digestArchive(raw []byte) string { x := sha256.Sum256(raw); return hex.EncodeToString(x[:]) }
func TestImageImportIdempotencyProtectedTagsAndCleanup(t *testing.T) {
	a, _, _ := centralFixture(t)
	runtime := &importRuntime{base: a.backup.engine.Runner}
	a.backup.engine.Runner = runtime
	raw := archiveBytes(t, "localhost/gereq-local:upload-test", "", tar.TypeReg)
	key := "image-import-test-001"
	w := importReq(a, raw, key, digestArchive(raw))
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	var j imageImportJob
	json.Unmarshal(w.Body.Bytes(), &j)
	a.backup.workers.Wait()
	w = centralReq(t, a, "GET", "/v1/image-imports/"+j.ID, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"status":"complete"`) || strings.Contains(w.Body.String(), "request_key") {
		t.Fatal(w.Code, w.Body.String())
	}
	if _, err := os.Stat(filepath.Join(a.imageImportDir(), j.ID+".tar")); !os.IsNotExist(err) {
		t.Fatal("archive retained")
	}
	w = importReq(a, raw, key, digestArchive(raw))
	if w.Code != 200 || runtime.loads != 1 {
		t.Fatal("replay imported twice", w.Code)
	}
	w = centralReq(t, a, "GET", "/v1/image-imports/requests/"+key, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), j.ID) {
		t.Fatal(w.Code)
	}
	if w = importReq(a, raw, "image-import-test-002", digestArchive(raw)); w.Code != 409 {
		t.Fatal("tag overwrite accepted", w.Code)
	}
	if w = importReq(a, raw, key, strings.Repeat("b", 64)); w.Code != 409 {
		t.Fatal("changed input accepted", w.Code)
	}
}
func TestImageArchiveRejectsUnsafePathsLinksAndForeignTags(t *testing.T) {
	for _, input := range []struct {
		tag, name string
		kind      byte
	}{{"docker.io/library/alpine:latest", "", tar.TypeReg}, {"localhost/gereq-local:upload-test", "../manifest.json", tar.TypeReg}, {"localhost/gereq-local:upload-test", "manifest.json", tar.TypeSymlink}} {
		p := filepath.Join(t.TempDir(), "archive.tar")
		os.WriteFile(p, archiveBytes(t, input.tag, input.name, input.kind), 0600)
		if _, err := imageArchiveTags(p); err == nil {
			t.Fatal("unsafe archive accepted")
		}
	}
	a, _, _ := centralFixture(t)
	raw := archiveBytes(t, "localhost/gereq-local:upload-test", "", tar.TypeReg)
	if w := importReq(a, raw, "image-invalid-sha-001", strings.Repeat("0", 64)); w.Code != 400 {
		t.Fatal(w.Code)
	}
	r := httptest.NewRequest("POST", "/v1/image-imports", bytes.NewReader(raw))
	r.ContentLength = maxImageArchive + 1
	r.Header.Set("Idempotency-Key", "image-over-limit-001")
	r.Header.Set("X-Archive-SHA256", digestArchive(raw))
	w := httptest.NewRecorder()
	a.ServeHTTP(w, r)
	if w.Code != 413 {
		t.Fatal(w.Code)
	}
	entries, _ := os.ReadDir(a.imageImportDir())
	if len(entries) != 0 {
		t.Fatal("rejected upload retained files")
	}
}
func TestImageImportRestartMarksInterruptedAndRemovesArchive(t *testing.T) {
	a, _, _ := centralFixture(t)
	j := imageImportJob{ID: "img-" + strings.Repeat("a", 24), Key: "interrupted-import", Status: "running"}
	if err := a.saveImageImport(j); err != nil {
		t.Fatal(err)
	}
	file := filepath.Join(a.imageImportDir(), j.ID+".tar")
	os.WriteFile(file, []byte("private archive"), 0600)
	if err := a.recoverImageImports(); err != nil {
		t.Fatal(err)
	}
	if err := decode(filepath.Join(a.imageImportDir(), j.ID+".json"), &j); err != nil || j.Status != "interrupted" {
		t.Fatal(err, j.Status)
	}
	if _, err := os.Stat(file); !os.IsNotExist(err) {
		t.Fatal("interrupted archive retained")
	}
}

func TestImageArchiveAcceptsOnlyStandardInternalLayerAliases(t *testing.T) {
	for _, foreign := range []bool{false, true} {
		var out bytes.Buffer
		w := tar.NewWriter(&out)
		layer := strings.Repeat("a", 64) + ".tar"
		alias := strings.Repeat("b", 64) + "/layer.tar"
		put := func(name string, raw []byte) {
			if err := w.WriteHeader(&tar.Header{Name: name, Mode: 0600, Size: int64(len(raw)), Typeflag: tar.TypeReg}); err != nil {
				t.Fatal(err)
			}
			if _, err := w.Write(raw); err != nil {
				t.Fatal(err)
			}
		}
		put(layer, []byte("layer data"))
		if err := w.WriteHeader(&tar.Header{Name: alias, Typeflag: tar.TypeSymlink, Linkname: "../" + layer}); err != nil {
			t.Fatal(err)
		}
		put("manifest.json", []byte(`[{"RepoTags":["localhost/gereq-local:upload-test"]}]`))
		if foreign {
			put("repositories", []byte(`{"docker.io/library/alpine":{"latest":"layer"}}`))
		} else {
			put("repositories", []byte(`{"localhost/gereq-local":{"upload-test":"layer"}}`))
		}
		if err := w.Close(); err != nil {
			t.Fatal(err)
		}
		file := filepath.Join(t.TempDir(), "image.tar")
		os.WriteFile(file, out.Bytes(), 0600)
		tags, err := imageArchiveTags(file)
		if foreign && err == nil {
			t.Fatal("foreign legacy tag accepted")
		}
		if !foreign && (err != nil || len(tags) != 1) {
			t.Fatal(err, tags)
		}
	}
}

func TestImageImportRecoveryWaitsForAnInFlightUpload(t *testing.T) {
	a, _, _ := centralFixture(t)
	a.imageImportMu.Lock()
	w := centralReq(t, a, "GET", "/v1/image-imports/requests/upload-being-received", "", "")
	a.imageImportMu.Unlock()
	if w.Code != 409 {
		t.Fatal("in-flight upload was reported missing", w.Code)
	}
	w = centralReq(t, a, "GET", "/v1/image-imports/requests/upload-being-received", "", "")
	if w.Code != 404 {
		t.Fatal(w.Code)
	}
}
