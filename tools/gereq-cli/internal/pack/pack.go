package pack

import (
	"archive/zip"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"
)

func Demo(root string) error {
	stamp := time.Now().Format("20060102")
	name := "gereq-realestates-demo"
	outDir := filepath.Join(root, "dist")
	zipPath := filepath.Join(outDir, fmt.Sprintf("%s-%s.zip", name, stamp))
	stage := filepath.Join(outDir, ".stage-"+name)
	_ = os.RemoveAll(stage)
	_ = os.MkdirAll(filepath.Join(stage, name), 0o755)
	_ = os.MkdirAll(outDir, 0o755)

	fmt.Println("→ Copying files…")
	skip := skipper{}
	err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(root, path)
		if err != nil {
			return err
		}
		if rel == "." {
			return nil
		}
		if skip.shouldSkip(rel, d.IsDir()) {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		dest := filepath.Join(stage, name, rel)
		if d.IsDir() {
			return os.MkdirAll(dest, 0o755)
		}
		return copyFile(path, dest)
	})
	if err != nil {
		return err
	}

	for _, d := range []string{"cache", "logs", "sessions", "tmp/queue", "uploads", "invoices", "database"} {
		p := filepath.Join(stage, name, "storage", d)
		_ = os.MkdirAll(p, 0o755)
		_ = os.WriteFile(filepath.Join(filepath.Dir(p), ".gitkeep"), []byte{}, 0o644)
	}

	instalace := `#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
chmod +x bin/grq bin/grq.cmd 2>/dev/null || true
if [[ -x ./bin/grq ]]; then
  exec ./bin/grq install demo "$@"
fi
echo "Run: bin/grq install demo" >&2
exit 1
`
	_ = os.WriteFile(filepath.Join(stage, name, "INSTALACE.sh"), []byte(instalace), 0o755)

	readme := fmt.Sprintf(`GEREQ RealEstates — demo package
================================

Requirements:
  - PHP 8.4+ (pdo_sqlite, mbstring, json, gd, zip)
  - Composer
  - bun or npm

Install:
  1. Unzip
  2. ./INSTALACE.sh
     or: ./bin/grq install demo --serve --port=8080

Admin:
  URL:    http://127.0.0.1:8080/admin
  Email:  demo@gereq.cz
  Password: demo123

Packed: %s
`, stamp)
	_ = os.WriteFile(filepath.Join(stage, name, "DEMO-README.txt"), []byte(readme), 0o644)

	fmt.Println("→ Zipping…")
	_ = os.Remove(zipPath)
	if err := zipDir(filepath.Join(stage, name), zipPath, name); err != nil {
		return err
	}
	_ = os.RemoveAll(stage)
	fi, _ := os.Stat(zipPath)
	size := ""
	if fi != nil {
		size = fmt.Sprintf(" (%.1f MB)", float64(fi.Size())/1024/1024)
	}
	fmt.Printf("✓ Done: %s%s\n", zipPath, size)
	return nil
}

type skipper struct{}

func (skipper) shouldSkip(rel string, isDir bool) bool {
	base := filepath.Base(rel)
	parts := strings.Split(filepath.ToSlash(rel), "/")
	top := parts[0]
	switch top {
	case ".git", ".idea", ".vscode", ".cursor", ".cache", ".phpunit.cache", ".phpstan-tmp",
		"vendor", "node_modules", "dist", "docs":
		if top == "docs" && len(parts) > 1 && parts[1] == "proposals" {
			return true
		}
		if top != "docs" {
			return true
		}
	}
	if strings.Contains(rel, "node_modules") {
		return true
	}
	if rel == "public/build" || strings.HasPrefix(rel, "public/build/") || rel == "public/hot" {
		return true
	}
	if base == ".env" || strings.HasPrefix(base, ".env.") || base == ".env copy" {
		return true
	}
	if strings.HasPrefix(rel, "storage/database.sqlite") {
		return true
	}
	if strings.HasPrefix(rel, "storage/logs/") || strings.HasPrefix(rel, "storage/cache/") ||
		strings.HasPrefix(rel, "storage/sessions/") || strings.HasPrefix(rel, "storage/tmp/") {
		if isDir || base != ".gitkeep" {
			return !isDir
		}
	}
	if strings.HasSuffix(base, ".log") || base == ".DS_Store" {
		return true
	}
	if top == "docs" && len(parts) >= 2 && parts[1] == "proposals" {
		return true
	}
	return false
}

func copyFile(src, dst string) error {
	_ = os.MkdirAll(filepath.Dir(dst), 0o755)
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		return err
	}
	defer out.Close()
	_, err = io.Copy(out, in)
	return err
}

func zipDir(srcDir, zipPath, rootName string) error {
	f, err := os.Create(zipPath)
	if err != nil {
		return err
	}
	defer f.Close()
	w := zip.NewWriter(f)
	defer w.Close()
	return filepath.WalkDir(srcDir, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(srcDir, path)
		name := filepath.ToSlash(filepath.Join(rootName, rel))
		if d.IsDir() {
			if rel == "." {
				return nil
			}
			_, err := w.Create(name + "/")
			return err
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		hdr, err := zip.FileInfoHeader(info)
		if err != nil {
			return err
		}
		hdr.Name = name
		hdr.Method = zip.Deflate
		dst, err := w.CreateHeader(hdr)
		if err != nil {
			return err
		}
		src, err := os.Open(path)
		if err != nil {
			return err
		}
		_, err = io.Copy(dst, src)
		src.Close()
		return err
	})
}
