package main

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

type Runner interface {
	Run(context.Context, []string) ([]byte, error)
}
type ContainerCLI struct{ Binary string }

func (runtime ContainerCLI) Run(ctx context.Context, args []string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, runtime.Binary, args...)
	if filepath.Base(runtime.Binary) == "podman" && len(args) > 0 && args[0] == "compose" && os.Getenv("PODMAN_COMPOSE_PROVIDER") == "" {
		provider, err := exec.LookPath("podman-compose")
		if err != nil {
			return nil, errors.New("podman-compose is required, or explicitly set PODMAN_COMPOSE_PROVIDER")
		}
		cmd.Env = append(os.Environ(), "PODMAN_COMPOSE_PROVIDER="+provider)
	}
	out, err := cmd.Output()
	// Docker/bootstrap output may contain secrets. Never return it in public errors.
	if err != nil {
		return nil, fmt.Errorf("container operation failed (%s); inspect locally with credentials redacted", safeOperation(args))
	}
	return out, nil
}
func safeOperation(a []string) string {
	if len(a) > 0 {
		return a[0]
	}
	return "unknown"
}

type Engine struct {
	Root    string
	Runner  Runner
	Runtime string
}

func (e Engine) lock() (func(), error) {
	root, err := filepath.Abs(e.Root)
	if err != nil {
		return nil, err
	}
	// One host registry lock also prevents conflicting port/request reservations.
	if _, err = ownedDir(root, "validation"); err != nil {
		return nil, err
	}
	if err = os.MkdirAll(root, 0700); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(filepath.Join(root, ".lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, err
	}
	if err = syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		f.Close()
		return nil, errors.New("provisioner busy; retry after current operation completes")
	}
	return func() { syscall.Flock(int(f.Fd()), syscall.LOCK_UN); f.Close() }, nil
}
func (e Engine) runtimeName() string {
	if e.Runtime == "" {
		return "podman"
	}
	return e.Runtime
}
func (e Engine) load(site string) (*State, string, error) {
	p, err := ownedDir(e.Root, site)
	if err != nil {
		return nil, "", err
	}
	var s State
	if err = decode(filepath.Join(p, "state.json"), &s); err != nil {
		return nil, p, err
	}
	if s.Request.SiteID != site || s.Fingerprint != fingerprint(s.Request, s.Release) || s.Runtime != e.runtimeName() {
		return nil, p, errors.New("instance ownership/state mismatch")
	}
	return &s, p, nil
}
func projectName(site, p string) string {
	h := sha256.Sum256([]byte(p))
	return fmt.Sprintf("grq-%s-%x", site, h[:4])
}
func compose(s *State, p string, args ...string) []string {
	return append([]string{"compose", "--project-name", projectName(s.Request.SiteID, p), "--file", filepath.Join(p, "compose.json")}, args...)
}
func (e Engine) save(s *State, p string) error { return atomicJSON(filepath.Join(p, "state.json"), s) }
func randomSecret() (string, error) {
	b := make([]byte, 32)
	_, err := rand.Read(b)
	return hex.EncodeToString(b), err
}
func (e Engine) Create(ctx context.Context, r Request, l Release) (*State, error) {
	if err := validate(r, l); err != nil {
		return nil, err
	}
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	p, err := ownedDir(e.Root, r.SiteID)
	if err != nil {
		return nil, err
	}
	var s *State
	if _, err = os.Stat(p); err == nil {
		s, _, err = e.load(r.SiteID)
		if err != nil {
			return nil, errors.New("existing directory is not an owned instance")
		}
		if s.Fingerprint != fingerprint(r, l) {
			return nil, errors.New("site exists with different request or release")
		}
	} else if !os.IsNotExist(err) {
		return nil, err
	} else {
		entries, _ := filepath.Glob(filepath.Join(e.Root, "sites", "*", "state.json"))
		for _, path := range entries {
			var other State
			if err = decode(path, &other); err != nil {
				return nil, errors.New("cannot reconcile existing registry")
			}
			if other.Request.RequestID == r.RequestID {
				return nil, errors.New("request ID already belongs to another site")
			}
			if other.Request.Port == r.Port {
				return nil, errors.New("port already reserved")
			}
		}
		listener, err := net.Listen("tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(r.Port)))
		if err != nil {
			return nil, errors.New("port unavailable")
		}
		listener.Close()
		if err = os.MkdirAll(p, 0700); err != nil {
			return nil, err
		}
		s = &State{Request: r, Release: l, Fingerprint: fingerprint(r, l), Status: "requested", Runtime: e.runtimeName(), Completed: []string{}, Drafts: map[string]string{}}
		if err = e.save(s, p); err != nil {
			return nil, err
		}
	}
	if s.RelocatedTo != "" {
		return nil, errors.New("instance relocated to " + s.RelocatedTo)
	}
	if s.Status == "ready" || s.Status == "needs_customer_setup" {
		return s, nil
	}
	steps := []string{"allocate", "bootstrap", "modules", "analytics", "dashboard", "health"}
	for _, step := range steps {
		if contains(s.Completed, step) && step != "health" {
			continue
		}
		s.Status = "creating"
		s.Step = step
		s.Error = ""
		if err = e.save(s, p); err != nil {
			return s, err
		}
		if err = e.step(ctx, s, p, step); err != nil {
			s.Status = "failed"
			s.Error = err.Error()
			if se := e.save(s, p); se != nil {
				return s, fmt.Errorf("%v; checkpoint save also failed: %w", err, se)
			}
			return s, err
		}
		s.Completed = append(s.Completed, step)
		if err = e.save(s, p); err != nil {
			return s, err
		}
	}
	s.Status = "needs_customer_setup"
	s.Step = "complete"
	s.Error = ""
	return s, e.save(s, p)
}
func contains(a []string, b string) bool {
	for _, v := range a {
		if v == b {
			return true
		}
	}
	return false
}
func (e Engine) step(ctx context.Context, s *State, p, step string) error {
	switch step {
	case "allocate":
		if err := os.MkdirAll(filepath.Join(p, "storage"), 0700); err != nil {
			return err
		}
		if _, err := os.Stat(filepath.Join(p, ".env")); os.IsNotExist(err) {
			key, err := randomSecret()
			if err != nil {
				return err
			}
			publicKey, err := randomSecret()
			if err != nil {
				return err
			}
			env := fmt.Sprintf("APP_NAME=%s\nAPP_ENV=production\nAPP_DEBUG=false\nGEREQ_PACKAGE=%s\nAPP_KEY=%s\nAPI_PUBLIC_KEY=grq_pub_%s\nAPP_URL=http://%s.localhost:%d\nFRONTEND_ORIGIN=http://%s.localhost:%d\nDB_CONNECTION=sqlite\nDB_DATABASE=/app/storage/database.sqlite\nCACHE_DRIVER=file\nSESSION_DRIVER=file\nQUEUE_DRIVER=sync\nGEREQ_SKIP_AUTO_BOOTSTRAP=true\nMAILER_DSN=null://null\n", s.Request.SiteID, s.Request.Package, key, publicKey, s.Request.SiteID, s.Request.Port, s.Request.SiteID, s.Request.Port)
			if err = atomicFile(filepath.Join(p, ".env"), []byte(env)); err != nil {
				return err
			}
		} else if err != nil {
			return err
		}
		uid := fmt.Sprintf("%d:%d", os.Getuid(), os.Getgid())
		cfg := map[string]any{"services": map[string]any{"app": map[string]any{"container_name": projectName(s.Request.SiteID, p) + "-app", "image": s.Release.Image, "user": uid, "working_dir": "/app", "read_only": true, "cap_drop": []string{"ALL"}, "security_opt": []string{"no-new-privileges:true"}, "pids_limit": 128, "mem_limit": "384m", "cpus": 1.0, "tmpfs": []string{"/tmp:rw,noexec,nosuid,size=64m"}, "ports": []string{fmt.Sprintf("127.0.0.1:%d:8080", s.Request.Port)}, "volumes": []any{map[string]any{"type": "bind", "source": filepath.Join(p, "storage"), "target": "/app/storage"}, map[string]any{"type": "bind", "source": filepath.Join(p, ".env"), "target": "/app/.env", "read_only": true}}, "labels": map[string]string{"gereq.site": s.Request.SiteID, "gereq.fingerprint": s.Fingerprint, "gereq.instance": p}, "networks": []string{"private"}}}, "networks": map[string]any{"private": map[string]any{"internal": true}}}
		if e.runtimeName() == "podman" {
			cfg["services"].(map[string]any)["app"].(map[string]any)["userns_mode"] = "keep-id"
		}
		if err := atomicJSON(filepath.Join(p, "compose.json"), cfg); err != nil {
			return err
		}
	case "bootstrap":
		if err := e.checkOwner(ctx, s, p); err != nil {
			return err
		}
		_, err := e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "bootstrap"))
		if err != nil {
			return err
		}
		return removeLegacyAccess(p)
	case "modules":
		_, err := e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "modules"))
		return err
	case "analytics":
		_, err := e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "analytics-config"))
		return err
	case "dashboard":
		_, err := e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "dashboard-preset"))
		return err
	case "health":
		if err := e.checkOwner(ctx, s, p); err != nil {
			return err
		}
		if _, err := e.Runner.Run(ctx, compose(s, p, "up", "--detach", "--no-build")); err != nil {
			return err
		}
		var last error
		for i := 0; i < 20; i++ {
			if _, last = e.Runner.Run(ctx, compose(s, p, "exec", "-T", "app", "php", "/opt/provision/bridge.php", "health")); last == nil {
				return nil
			}
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(250 * time.Millisecond):
			}
		}
		return last
	default:
		return errors.New("unknown step")
	}
	return nil
}
func (e Engine) Lifecycle(ctx context.Context, site, op string) (*State, error) {
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	s, p, err := e.load(site)
	if err != nil {
		return nil, err
	}
	if s.RelocatedTo != "" {
		return nil, errors.New("instance relocated to " + s.RelocatedTo)
	}
	if err = e.checkOwner(ctx, s, p); err != nil {
		return s, err
	}
	switch op {
	case "stop":
		if _, err = e.Runner.Run(ctx, compose(s, p, "stop")); err != nil {
			return s, err
		}
		s.Status = "stopped"
	case "start":
		if !contains(s.Completed, "health") {
			return s, errors.New("incomplete instance; resume creation first")
		}
		if err = e.step(ctx, s, p, "health"); err != nil {
			return s, err
		}
		s.Status = "needs_customer_setup"
	default:
		return s, errors.New("unsupported lifecycle operation")
	}
	return s, e.save(s, p)
}
func (e Engine) Import(ctx context.Context, site, file string) ([]byte, error) {
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	s, p, err := e.load(site)
	if err != nil {
		return nil, err
	}
	if s.Status != "ready" && s.Status != "needs_customer_setup" {
		return nil, errors.New("instance must be running")
	}
	if s.RelocatedTo != "" {
		return nil, errors.New("instance relocated to " + s.RelocatedTo)
	}
	if err = e.checkOwner(ctx, s, p); err != nil {
		return nil, err
	}
	info, err := os.Stat(file)
	if err != nil {
		return nil, err
	}
	if info.Size() > 1<<20 {
		return nil, errors.New("artifact exceeds 1 MiB")
	}
	raw, err := os.ReadFile(file)
	if err != nil {
		return nil, err
	}
	if len(raw) > 1<<20 {
		return nil, errors.New("artifact exceeds 1 MiB")
	}
	var v map[string]any
	if err = json.Unmarshal(raw, &v); err != nil {
		return nil, err
	}
	artifact := filepath.Join(p, "storage", "incoming.json")
	if err = atomicFile(artifact, raw); err != nil {
		return nil, err
	}
	defer os.Remove(artifact)
	return e.Runner.Run(ctx, compose(s, p, "exec", "-T", "app", "php", "/opt/provision/bridge.php", "import", "/app/storage/incoming.json"))
}
func timeout() (context.Context, context.CancelFunc) {
	return context.WithTimeout(context.Background(), 5*time.Minute)
}
func publicState(s *State) map[string]any {
	result := map[string]any{"site_id": s.Request.SiteID, "status": s.Status, "step": s.Step, "completed": s.Completed, "release": s.Release.ID, "error": s.Error, "preview_url": fmt.Sprintf("http://%s.localhost:%d", s.Request.SiteID, s.Request.Port), "admin_url": fmt.Sprintf("http://%s.localhost:%d/admin", s.Request.SiteID, s.Request.Port)}
	if s.RelocatedTo != "" {
		result["relocated_to"] = s.RelocatedTo
	}
	return result
}

// Refuse a container name occupied by another provisioner root or an unowned app.
func (e Engine) checkOwner(ctx context.Context, s *State, p string) error {
	out, err := e.Runner.Run(ctx, []string{"ps", "--all", "--filter", "name=^" + projectName(s.Request.SiteID, p) + "-app$", "--format", "{{.ID}}"})
	if err != nil {
		return err
	}
	for _, line := range strings.Split(strings.TrimSpace(string(out)), "\n") {
		if line == "" {
			continue
		}
		// Unlike ps label formatting, inspect returns a label map in both runtimes.
		labelJSON, inspectErr := e.Runner.Run(ctx, []string{"inspect", "--format", "{{json .Config.Labels}}", line})
		if inspectErr != nil {
			return inspectErr
		}
		var labels map[string]string
		if err = json.Unmarshal(labelJSON, &labels); err != nil {
			return errors.New("cannot verify existing runtime ownership")
		}
		if labels["gereq.site"] != s.Request.SiteID || labels["gereq.fingerprint"] != s.Fingerprint || labels["gereq.instance"] != p {
			return errors.New("runtime name is occupied by an unowned instance")
		}
	}
	return nil
}
