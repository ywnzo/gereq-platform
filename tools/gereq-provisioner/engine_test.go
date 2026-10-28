package main

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type fakeDocker struct {
	calls        [][]string
	fail         string
	failed       bool
	containerIDs string
	labels       map[string]string
}

func (f *fakeDocker) Run(_ context.Context, a []string) ([]byte, error) {
	f.calls = append(f.calls, append([]string{}, a...))
	if len(a) > 0 && a[0] == "ps" {
		return []byte(f.containerIDs), nil
	}
	if len(a) > 0 && a[0] == "inspect" {
		return json.Marshal(f.labels)
	}
	if f.fail != "" && !f.failed && strings.Contains(strings.Join(a, " "), f.fail) {
		f.failed = true
		return nil, errors.New("simulated interruption")
	}
	return []byte(`{"ok":true,"page_id":"draft-id","draft":true}`), nil
}
func TestRuntimeOwnershipUsesInspectLabels(t *testing.T) {
	e, r, l, f := fixture(t)
	s, err := e.Create(context.Background(), r, l)
	if err != nil {
		t.Fatal(err)
	}
	p := filepath.Join(e.Root, "sites", r.SiteID)
	f.containerIDs = "abc123\n"
	f.labels = map[string]string{"gereq.site": r.SiteID, "gereq.fingerprint": s.Fingerprint, "gereq.instance": p}
	if err = e.checkOwner(context.Background(), s, p); err != nil {
		t.Fatal(err)
	}
	last := f.calls[len(f.calls)-1]
	if last[0] != "inspect" || last[len(last)-1] != "abc123" {
		t.Fatal(last)
	}
	f.labels["gereq.site"] = "another-site"
	if err = e.checkOwner(context.Background(), s, p); err == nil {
		t.Fatal("unowned runtime accepted")
	}
}
func fixture(t *testing.T) (Engine, Request, Release, *fakeDocker) {
	t.Helper()
	f := &fakeDocker{}
	return Engine{Root: filepath.Join(t.TempDir(), "state"), Runner: f}, Request{1, "request-0001", "customer-one", "customer-1", "funnel-v1", "local-v1", 18371}, Release{1, "local-v1", "gereq-local:dev", true, []string{"cms", "site_inquiries"}}, f
}
func TestRetryAndIdempotency(t *testing.T) {
	e, r, l, f := fixture(t)
	f.fail = "bootstrap"
	s, err := e.Create(context.Background(), r, l)
	if err == nil || s.Status != "failed" {
		t.Fatal(s, err)
	}
	p := filepath.Join(e.Root, "sites", r.SiteID)
	before, _ := os.ReadFile(filepath.Join(p, ".env"))
	f.fail = ""
	s, err = e.Create(context.Background(), r, l)
	if err != nil || s.Status != "needs_customer_setup" {
		t.Fatal(s, err)
	}
	after, _ := os.ReadFile(filepath.Join(p, ".env"))
	if string(before) != string(after) {
		t.Fatal("secrets rotated on retry")
	}
	n := len(f.calls)
	_, err = e.Create(context.Background(), r, l)
	if err != nil || len(f.calls) != n {
		t.Fatal("duplicate create not idempotent")
	}
	for _, name := range []string{"state.json", ".env", "compose.json"} {
		st, _ := os.Stat(filepath.Join(p, name))
		if st.Mode().Perm() != 0600 {
			t.Fatal(name, st.Mode())
		}
	}
	raw, _ := os.ReadFile(filepath.Join(p, "compose.json"))
	if strings.Contains(string(raw), "bootstrap-access") {
		t.Fatal("operator secret mounted in runtime")
	}
	if !strings.Contains(string(raw), "127.0.0.1:18371:8080") {
		t.Fatal("unsafe port exposure")
	}
}
func TestConflicts(t *testing.T) {
	e, r, l, _ := fixture(t)
	if _, err := e.Create(context.Background(), r, l); err != nil {
		t.Fatal(err)
	}
	changed := r
	changed.CustomerRef = "other"
	if _, err := e.Create(context.Background(), changed, l); err == nil {
		t.Fatal("changed request accepted")
	}
	changed = r
	changed.SiteID = "customer-two"
	changed.Port++
	if _, err := e.Create(context.Background(), changed, l); err == nil {
		t.Fatal("request ID reused")
	}
	changed.RequestID = "request-0002"
	changed.Port = r.Port
	if _, err := e.Create(context.Background(), changed, l); err == nil {
		t.Fatal("port reserved twice")
	}
}
func TestSeparateInstances(t *testing.T) {
	e, r, l, _ := fixture(t)
	if _, err := e.Create(context.Background(), r, l); err != nil {
		t.Fatal(err)
	}
	b := r
	b.SiteID = "customer-two"
	b.RequestID = "request-0002"
	b.Port++
	if _, err := e.Create(context.Background(), b, l); err != nil {
		t.Fatal(err)
	}
	one, _ := os.ReadFile(filepath.Join(e.Root, "sites", r.SiteID, ".env"))
	two, _ := os.ReadFile(filepath.Join(e.Root, "sites", b.SiteID, ".env"))
	if string(one) == string(two) {
		t.Fatal("shared config")
	}
	for _, site := range []string{r.SiteID, b.SiteID} {
		raw, _ := os.ReadFile(filepath.Join(e.Root, "sites", site, "compose.json"))
		var cfg map[string]any
		if err := json.Unmarshal(raw, &cfg); err != nil {
			t.Fatal(err)
		}
		other := r.SiteID
		if site == other {
			other = b.SiteID
		}
		if strings.Contains(string(raw), other) {
			t.Fatal("cross-site mount")
		}
	}
}
func TestRefuseUnownedAndSymlink(t *testing.T) {
	e, r, l, _ := fixture(t)
	os.MkdirAll(filepath.Join(e.Root, "sites", r.SiteID), 0700)
	if _, err := e.Create(context.Background(), r, l); err == nil {
		t.Fatal("adopted directory")
	}
	link := filepath.Join(t.TempDir(), "link")
	os.Symlink(e.Root, link)
	e.Root = link
	if _, err := e.Create(context.Background(), r, l); err == nil {
		t.Fatal("symlink root accepted")
	}
}
func TestLifecycleAndImport(t *testing.T) {
	e, r, l, _ := fixture(t)
	if _, err := e.Create(context.Background(), r, l); err != nil {
		t.Fatal(err)
	}
	s, err := e.Lifecycle(context.Background(), r.SiteID, "stop")
	if err != nil || s.Status != "stopped" {
		t.Fatal(s, err)
	}
	file := filepath.Join(t.TempDir(), "draft.json")
	os.WriteFile(file, []byte(`{}`), 0600)
	if _, err = e.Import(context.Background(), r.SiteID, file); err == nil {
		t.Fatal("stopped import accepted")
	}
	s, err = e.Lifecycle(context.Background(), r.SiteID, "start")
	if err != nil || s.Status != "needs_customer_setup" {
		t.Fatal(s, err)
	}
	if _, err = e.Import(context.Background(), r.SiteID, file); err != nil {
		t.Fatal(err)
	}
	if _, err = os.Stat(filepath.Join(e.Root, "sites", r.SiteID, "storage", "incoming.json")); !os.IsNotExist(err) {
		t.Fatal("artifact retained")
	}
}
func TestLock(t *testing.T) {
	e, r, l, _ := fixture(t)
	unlock, err := e.lock()
	if err != nil {
		t.Fatal(err)
	}
	defer unlock()
	if _, err = e.Create(context.Background(), r, l); err == nil {
		t.Fatal("concurrent operation accepted")
	}
}
func TestValidation(t *testing.T) {
	_, r, l, _ := fixture(t)
	bad := r
	bad.SiteID = "../../outside"
	if validate(bad, l) == nil {
		t.Fatal("traversal")
	}
	l.Development = false
	if validate(r, l) == nil {
		t.Fatal("mutable release")
	}
	l.Image = "registry/gereq@sha256:" + strings.Repeat("a", 64)
	if err := validate(r, l); err != nil {
		t.Fatal(err)
	}
	p := filepath.Join(t.TempDir(), "bad.json")
	os.WriteFile(p, []byte(`{"schema_version":1,"unknown":1}`), 0600)
	if decode(p, &Request{}) == nil {
		t.Fatal("unknown field accepted")
	}
}

func TestIndependentRegistriesHaveDistinctRuntimeNames(t *testing.T) {
	e, r, _, _ := fixture(t)
	one, _ := ownedDir(e.Root, r.SiteID)
	two, _ := ownedDir(t.TempDir(), r.SiteID)
	if projectName(r.SiteID, one) == projectName(r.SiteID, two) {
		t.Fatal("registry roots collide in runtime namespace")
	}
}
func TestPlanDoesNotMutateFilesystem(t *testing.T) {
	dir := t.TempDir()
	root := filepath.Join(dir, "not-created")
	if err := run([]string{"plan", "--root", root, "--request", "examples/customer-one.json", "--release", "examples/release.json"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(root); !os.IsNotExist(err) {
		t.Fatal("plan mutated filesystem")
	}
}

func TestNoRetainedBootstrapPassword(t *testing.T) {
	e, r, l, _ := fixture(t)
	if _, err := e.Create(context.Background(), r, l); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(e.Root, "sites", r.SiteID, "access.json")); !os.IsNotExist(err) {
		t.Fatal("retained bootstrap credentials")
	}
}

func TestRelocationPreservesDataAndRetiresSource(t *testing.T) {
	e, r, l, _ := fixture(t)
	ctx := context.Background()
	if _, err := e.Create(ctx, r, l); err != nil {
		t.Fatal(err)
	}
	p := filepath.Join(e.Root, "sites", r.SiteID)
	os.WriteFile(filepath.Join(p, "storage", "canary"), []byte("customer data"), 0600)
	before, _ := os.ReadFile(filepath.Join(p, ".env"))
	os.WriteFile(filepath.Join(p, "access.json"), []byte(`{"password":"legacy"}`), 0600)
	dest := filepath.Join(t.TempDir(), "persistent")
	if _, err := e.Relocate(ctx, r.SiteID, dest); err != nil {
		t.Fatal(err)
	}
	moved := filepath.Join(dest, "sites", r.SiteID)
	after, _ := os.ReadFile(filepath.Join(moved, ".env"))
	if string(before) != string(after) {
		t.Fatal("application key changed")
	}
	data, _ := os.ReadFile(filepath.Join(moved, "storage", "canary"))
	if string(data) != "customer data" {
		t.Fatal("data lost")
	}
	for _, root := range []string{p, moved} {
		if _, err := os.Stat(filepath.Join(root, "access.json")); !os.IsNotExist(err) {
			t.Fatal("legacy credentials retained")
		}
	}
	if _, err := e.Lifecycle(ctx, r.SiteID, "start"); err == nil {
		t.Fatal("retired source restarted")
	}
	if _, err := e.Create(ctx, r, l); err == nil {
		t.Fatal("retired source recreated")
	}
	if _, err := e.Relocate(ctx, r.SiteID, dest); err != nil {
		t.Fatal("retry failed", err)
	}
	target := Engine{Root: dest, Runner: e.Runner}
	if _, err := target.Lifecycle(ctx, r.SiteID, "start"); err != nil {
		t.Fatal(err)
	}
}

func TestFailedHardeningRetainsRecoveryMaterial(t *testing.T) {
	e, r, l, f := fixture(t)
	ctx := context.Background()
	if _, err := e.Create(ctx, r, l); err != nil {
		t.Fatal(err)
	}
	p := filepath.Join(e.Root, "sites", r.SiteID)
	os.WriteFile(filepath.Join(p, "access.json"), []byte(`{"password":"legacy"}`), 0600)
	f.fail = "secure-access"
	if _, err := e.Access(ctx, r.SiteID, "secure-access", ""); err == nil {
		t.Fatal("failure ignored")
	}
	if _, err := os.Stat(filepath.Join(p, "access.json")); err != nil {
		t.Fatal("recovery material deleted before invalidation")
	}
}

func TestPrivateCopyRefusesSymlinks(t *testing.T) {
	source := t.TempDir()
	os.Symlink("/etc/passwd", filepath.Join(source, "link"))
	if err := copyVerified(source, filepath.Join(t.TempDir(), "copy")); err == nil {
		t.Fatal("followed symlink")
	}
}

func TestPodmanComposeProviderIsExplicit(t *testing.T) {
	dir := t.TempDir()
	for _, name := range []string{"podman", "podman-compose"} {
		if err := os.WriteFile(filepath.Join(dir, name), []byte("#!/bin/sh\nprintf '%s' \"$PODMAN_COMPOSE_PROVIDER\"\n"), 0700); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))
	t.Setenv("PODMAN_COMPOSE_PROVIDER", "")
	runner := ContainerCLI{Binary: filepath.Join(dir, "podman")}
	out, err := runner.Run(context.Background(), []string{"compose", "version"})
	if err != nil || string(out) != filepath.Join(dir, "podman-compose") {
		t.Fatal(string(out), err)
	}
	t.Setenv("PODMAN_COMPOSE_PROVIDER", "/explicit/provider")
	out, err = runner.Run(context.Background(), []string{"compose", "version"})
	if err != nil || string(out) != "/explicit/provider" {
		t.Fatal(string(out), err)
	}
}

func TestPublicStorefrontKeysAreUniquePerInstance(t *testing.T) {
	e, r, l, _ := fixture(t)
	if _, err := e.Create(context.Background(), r, l); err != nil {
		t.Fatal(err)
	}
	read := func(site string) string {
		raw, err := os.ReadFile(filepath.Join(e.Root, "sites", site, ".env"))
		if err != nil {
			t.Fatal(err)
		}
		for _, line := range strings.Split(string(raw), "\n") {
			if strings.HasPrefix(line, "API_PUBLIC_KEY=") {
				return strings.TrimPrefix(line, "API_PUBLIC_KEY=")
			}
		}
		t.Fatal("public API key missing")
		return ""
	}
	first := read(r.SiteID)
	if !strings.HasPrefix(first, "grq_pub_") || len(first) != 72 {
		t.Fatal("invalid public API key format")
	}
	r.SiteID = "customer-two"
	r.RequestID = "request-0002"
	r.Port++
	if _, err := e.Create(context.Background(), r, l); err != nil {
		t.Fatal(err)
	}
	if read(r.SiteID) == first {
		t.Fatal("public API key shared across customer instances")
	}
}
