package modules

import (
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

var semverRe = regexp.MustCompile(`^v?(\d+)\.(\d+)\.(\d+)$`)

// normalizeVersion strips a leading "v" if present.
func normalizeVersion(v string) string {
	return strings.TrimPrefix(strings.TrimSpace(v), "v")
}

// parseSemver returns major, minor, patch or an error.
func parseSemver(v string) (int, int, int, error) {
	m := semverRe.FindStringSubmatch(normalizeVersion(v))
	if m == nil {
		return 0, 0, 0, fmt.Errorf("invalid semver %q", v)
	}
	maj, _ := strconv.Atoi(m[1])
	min, _ := strconv.Atoi(m[2])
	pat, _ := strconv.Atoi(m[3])
	return maj, min, pat, nil
}

// compareSemver returns -1 if a<b, 0 if a==b, 1 if a>b (ignores leading v).
func compareSemver(a, b string) (int, error) {
	a = normalizeVersion(a)
	b = normalizeVersion(b)
	if a == "NONE" || a == "" {
		if b == "NONE" || b == "" {
			return 0, nil
		}
		return -1, nil
	}
	if b == "NONE" || b == "" {
		return 1, nil
	}
	amaj, amin, apat, err := parseSemver(a)
	if err != nil {
		return 0, err
	}
	bmaj, bmin, bpat, err := parseSemver(b)
	if err != nil {
		return 0, err
	}
	switch {
	case amaj != bmaj:
		if amaj < bmaj {
			return -1, nil
		}
		return 1, nil
	case amin != bmin:
		if amin < bmin {
			return -1, nil
		}
		return 1, nil
	case apat != bpat:
		if apat < bpat {
			return -1, nil
		}
		return 1, nil
	default:
		return 0, nil
	}
}

// bumpPatch returns X.Y.(Z+1) for a valid semver.
func bumpPatch(v string) (string, error) {
	maj, min, pat, err := parseSemver(v)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("%d.%d.%d", maj, min, pat+1), nil
}

var (
	modulePHPVersionRe = regexp.MustCompile(`(['"]version['"]\s*=>\s*['"])([^'"]+)(['"])`)
	moduleClassVersionRe = regexp.MustCompile(`(?s)(function\s+version\s*\(\s*\)\s*:\s*string\s*\{.*?return\s+['"])([^'"]+)(['"])`)
)

// setModuleVersion updates module.php and *Module.php version strings.
func setModuleVersion(modRoot, newVer string) error {
	modPHP := filepath.Join(modRoot, "module.php")
	data, err := os.ReadFile(modPHP)
	if err != nil {
		return err
	}
	replaced := modulePHPVersionRe.ReplaceAll(data, []byte("${1}"+newVer+"${3}"))
	if string(replaced) == string(data) {
		return fmt.Errorf("module.php: version key not found")
	}
	if err := os.WriteFile(modPHP, replaced, 0o644); err != nil {
		return err
	}

	entries, err := os.ReadDir(modRoot)
	if err != nil {
		return err
	}
	updated := false
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), "Module.php") {
			continue
		}
		path := filepath.Join(modRoot, e.Name())
		body, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		next := moduleClassVersionRe.ReplaceAll(body, []byte("${1}"+newVer+"${3}"))
		if string(next) == string(body) {
			continue
		}
		if err := os.WriteFile(path, next, 0o644); err != nil {
			return err
		}
		updated = true
	}
	if !updated {
		return fmt.Errorf("no *Module.php version() return updated")
	}
	return nil
}
