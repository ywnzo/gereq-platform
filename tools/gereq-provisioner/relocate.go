package main

import (
	"context"
	"crypto/sha256"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
)

func removeLegacyAccess(p string) error {
	err := os.Remove(filepath.Join(p, "access.json"))
	if os.IsNotExist(err) {
		return nil
	}
	return err
}

// Access only returns a bearer setup link for an explicit operator request.
// The caller must deliver it privately; it is never written to the registry.
func (e Engine) Access(ctx context.Context, site, op, email string) ([]byte, error) {
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
	if op != "setup-link" && op != "secure-access" && op != "dashboard-catalog" && op != "dashboard-preset" && op != "analytics-config" {
		return nil, errors.New("unsupported access operation")
	}
	if err = e.checkOwner(ctx, s, p); err != nil {
		return nil, err
	}
	args := compose(s, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", op)
	if op == "setup-link" {
		if email == "" {
			return nil, errors.New("--email is required")
		}
		args = append(args, email)
	}
	out, err := e.Runner.Run(ctx, args)
	if err != nil {
		return nil, err
	}
	if err = removeLegacyAccess(p); err != nil {
		return nil, err
	}
	return out, nil
}

func nested(a, b string) bool {
	rel, err := filepath.Rel(a, b)
	return err == nil && (rel == "." || (rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))))
}

// Relocate stops the source, verifies a private copy, then retires the source
// journal before starting the destination. The source data is retained for recovery.
func (e Engine) Relocate(ctx context.Context, site, destination string) (*State, error) {
	source, err := filepath.Abs(e.Root)
	if err != nil {
		return nil, err
	}
	dest, err := filepath.Abs(destination)
	if err != nil || destination == "" {
		return nil, errors.New("--destination is required")
	}
	if nested(source, dest) || nested(dest, source) {
		return nil, errors.New("registries must be separate, non-nested directories")
	}
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	target := Engine{Root: dest, Runner: e.Runner, Runtime: e.Runtime}
	release, err := target.lock()
	if err != nil {
		return nil, err
	}
	defer release()
	s, p, err := e.load(site)
	if err != nil {
		return nil, err
	}
	to, err := ownedDir(dest, site)
	if err != nil {
		return nil, err
	}
	if s.RelocatedTo != "" {
		if s.RelocatedTo != to {
			return nil, errors.New("instance already relocated elsewhere")
		}
		existing, _, loadErr := target.load(site)
		return existing, loadErr
	}
	if !contains(s.Completed, "health") {
		return nil, errors.New("cannot relocate incomplete instance")
	}
	if _, err = os.Lstat(to); !os.IsNotExist(err) {
		return nil, errors.New("destination instance already exists; reconcile without overwriting")
	}
	entries, err := filepath.Glob(filepath.Join(dest, "sites", "*", "state.json"))
	if err != nil {
		return nil, err
	}
	for _, path := range entries {
		var other State
		if err = decode(path, &other); err != nil {
			return nil, errors.New("cannot reconcile destination registry")
		}
		if other.Request.Port == s.Request.Port || other.Request.RequestID == s.Request.RequestID {
			return nil, errors.New("destination port or request ID already reserved")
		}
	}
	if err = e.checkOwner(ctx, s, p); err != nil {
		return nil, err
	}
	if _, err = e.Runner.Run(ctx, compose(s, p, "stop")); err != nil {
		return nil, err
	}
	s.Status = "stopped"
	if err = e.save(s, p); err != nil {
		return nil, err
	}
	for _, op := range []string{"secure-access", "storage-check"} {
		if _, err = e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", op)); err != nil {
			return nil, err
		}
	}
	if err = removeLegacyAccess(p); err != nil {
		return nil, err
	}
	parent := filepath.Dir(to)
	if err = os.MkdirAll(parent, 0700); err != nil {
		return nil, err
	}
	stage, err := os.MkdirTemp(parent, ".relocate-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(stage)
	for _, name := range []string{"storage", ".env"} {
		if err = copyVerified(filepath.Join(p, name), filepath.Join(stage, name)); err != nil {
			return nil, err
		}
	}
	if err = target.save(s, stage); err != nil {
		return nil, err
	}
	if err = os.Rename(stage, to); err != nil {
		return nil, err
	}
	if err = target.step(ctx, s, to, "allocate"); err != nil {
		return nil, err
	}
	if _, err = target.Runner.Run(ctx, compose(s, to, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "storage-check")); err != nil {
		return nil, err
	}
	// Once this record is durable the original instance cannot be resumed by the CLI.
	s.RelocatedTo = to
	s.Status = "relocated"
	if err = e.save(s, p); err != nil {
		return nil, err
	}
	moved := *s
	moved.RelocatedTo = ""
	moved.Status = "stopped"
	if err = target.save(&moved, to); err != nil {
		return nil, err
	}
	if err = target.step(ctx, &moved, to, "health"); err != nil {
		return &moved, fmt.Errorf("copy retained at %s; start destination after resolving health failure: %w", to, err)
	}
	moved.Status = "needs_customer_setup"
	return &moved, target.save(&moved, to)
}

func copyVerified(source, destination string) error {
	return filepath.WalkDir(source, func(path string, entry fs.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		rel, err := filepath.Rel(source, path)
		if err != nil {
			return err
		}
		to := destination
		if rel != "." {
			to = filepath.Join(destination, rel)
		}
		if entry.IsDir() {
			return os.MkdirAll(to, 0700)
		}
		info, err := entry.Info()
		if err != nil {
			return err
		}
		if !info.Mode().IsRegular() {
			return errors.New("refusing symlink or special file in instance data")
		}
		in, err := os.Open(path)
		if err != nil {
			return err
		}
		defer in.Close()
		out, err := os.OpenFile(to, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
		if err != nil {
			return err
		}
		hash := sha256.New()
		_, err = io.Copy(io.MultiWriter(out, hash), in)
		if err == nil {
			err = out.Sync()
		}
		closeErr := out.Close()
		if err != nil {
			return err
		}
		if closeErr != nil {
			return closeErr
		}
		copied, err := os.Open(to)
		if err != nil {
			return err
		}
		defer copied.Close()
		actual := sha256.New()
		if _, err = io.Copy(actual, copied); err != nil {
			return err
		}
		if string(hash.Sum(nil)) != string(actual.Sum(nil)) {
			return errors.New("instance copy checksum mismatch")
		}
		return nil
	})
}
