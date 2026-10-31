package main

import (
	"bytes"
	"context"
	_ "embed"
	"errors"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"time"
)

//go:embed runtime/module-catalog.php
var backendModuleCatalogScript string

type backendModule struct {
	ID                string   `json:"id"`
	Name              string   `json:"name"`
	Description       string   `json:"description"`
	Version           string   `json:"version"`
	Kind              string   `json:"kind"`
	ParentID          *string  `json:"parent_id"`
	Requires          []string `json:"requires"`
	AvailableReleases []string `json:"available_releases,omitempty"`
}

// Catalog source is an operator deployment setting. Requests never choose executable paths.
func readBackendModules(ctx context.Context) ([]backendModule, error) {
	source := os.Getenv("GEREQ_PLATFORM_SOURCE")
	if !filepath.IsAbs(source) {
		return nil, errors.New("GEREQ_PLATFORM_SOURCE must identify the canonical backend")
	}
	source, e := filepath.EvalSymlinks(source)
	if e != nil {
		return nil, errors.New("backend source unavailable")
	}
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "php", "-r", backendModuleCatalogScript[len("<?php\n"):], source)
	cmd.Dir = source
	cmd.Stderr = io.Discard
	// Bound stdout even if a broken backend manifest unexpectedly emits output.
	var raw bytes.Buffer
	cmd.Stdout = &catalogOutput{writer: &raw, remaining: 2 << 20}
	if e = cmd.Run(); e != nil {
		return nil, errors.New("backend module discovery failed")
	}
	var result struct {
		Modules []backendModule `json:"modules"`
	}
	if strictJSON(raw.Bytes(), &result) != nil || len(result.Modules) == 0 || len(result.Modules) > 250 {
		return nil, errors.New("invalid backend module catalog")
	}
	seen := map[string]bool{}
	for _, m := range result.Modules {
		if !moduleIDPattern.MatchString(m.ID) || seen[m.ID] || m.Name == "" || len(m.Name) > 300 || len(m.Requires) > 100 {
			return nil, errors.New("invalid backend module catalog")
		}
		seen[m.ID] = true
	}
	return result.Modules, nil
}

type catalogOutput struct {
	writer    io.Writer
	remaining int
}

func (b *catalogOutput) Write(p []byte) (int, error) {
	if len(p) > b.remaining {
		return 0, errors.New("backend catalog exceeds size limit")
	}
	n, e := b.writer.Write(p)
	b.remaining -= n
	return n, e
}
func (a *centralAPI) backendModules(ctx context.Context) ([]backendModule, error) {
	if a.moduleCatalog != nil {
		return a.moduleCatalog(ctx)
	}
	return readBackendModules(ctx)
}
func (a *centralAPI) validateBackendPackage(ctx context.Context, p catalogPackage) error {
	if !p.Active {
		return nil
	}
	modules, e := a.backendModules(ctx)
	if e != nil {
		return e
	}
	known := map[string]backendModule{}
	for _, m := range modules {
		known[m.ID] = m
	}
	selected := p.Modules
	if len(selected) == 0 {
		selected = funnelModules
	}
	for _, id := range selected {
		m, ok := known[id]
		if !ok {
			return errors.New("package references a module unavailable in backend: " + id)
		}
		for _, dependency := range m.Requires {
			if !contains(selected, dependency) {
				return errors.New("package module dependency missing: " + id + " requires " + dependency)
			}
		}
	}
	return nil
}
func (a *centralAPI) catalogCapabilities(ctx context.Context) (map[string]any, error) {
	modules, e := a.backendModules(ctx)
	if e != nil {
		return nil, e
	}
	releases, e := a.registry.list(true)
	if e != nil {
		return nil, e
	}
	for i := range modules {
		for _, release := range releases {
			if release.Verified && contains(release.Modules, modules[i].ID) {
				modules[i].AvailableReleases = append(modules[i].AvailableReleases, release.ID)
			}
		}
		sort.Strings(modules[i].AvailableReleases)
	}
	sort.Slice(modules, func(i, j int) bool { return modules[i].ID < modules[j].ID })
	return map[string]any{"modules": modules, "module_source": "backend", "default_modules": funnelModules, "features": packageFeatures, "limits": []string{"generation_limit", "trial_days"}, "supported_runtime": "funnel-v1"}, nil
}
