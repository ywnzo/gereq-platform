package root

import (
	"fmt"
	"os"
	"path/filepath"
)

// Find walks up from cwd (or GEREQ_ROOT) looking for platform markers.
func Find() (string, error) {
	if v := os.Getenv("GEREQ_ROOT"); v != "" {
		abs, err := filepath.Abs(v)
		if err != nil {
			return "", err
		}
		if looksLikeRoot(abs) {
			return abs, nil
		}
		return "", fmt.Errorf("GEREQ_ROOT=%s is not a GEREQ platform root", abs)
	}
	wd, err := os.Getwd()
	if err != nil {
		return "", err
	}
	dir := wd
	for {
		if looksLikeRoot(dir) {
			return dir, nil
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			break
		}
		dir = parent
	}
	if self, err := os.Executable(); err == nil {
		dir = filepath.Dir(self)
		for i := 0; i < 6; i++ {
			if looksLikeRoot(dir) {
				return dir, nil
			}
			dir = filepath.Dir(dir)
		}
	}
	return "", fmt.Errorf("GEREQ platform root not found (need gereq + config/ + modules/)")
}

func looksLikeRoot(dir string) bool {
	return fileExists(filepath.Join(dir, "gereq")) &&
		fileExists(filepath.Join(dir, "config")) &&
		fileExists(filepath.Join(dir, "modules"))
}

func fileExists(p string) bool {
	_, err := os.Stat(p)
	return err == nil
}

// Chdir sets working directory to the platform root.
func Chdir() (string, error) {
	r, err := Find()
	if err != nil {
		return "", err
	}
	return r, os.Chdir(r)
}
