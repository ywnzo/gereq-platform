package main

import (
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"regexp"
	"strings"
)

//go:embed runtime/preview-read.php
var previewReader string
var previewPageID = regexp.MustCompile(`^[a-f0-9-]{36}$`)

type draftPreview struct {
	SiteID      string            `json:"site_id"`
	PageID      string            `json:"page_id"`
	Title       string            `json:"title"`
	Slug        string            `json:"slug"`
	Publication string            `json:"publication_status"`
	Blocks      []json.RawMessage `json:"blocks"`
	Palette     map[string]any    `json:"palette"`
	Storefront  string            `json:"storefront_url"`
}

func (e Engine) draftPreview(ctx context.Context, site, page string) (*draftPreview, error) {
	if !sitePattern.MatchString(site) || !previewPageID.MatchString(page) {
		return nil, errors.New("invalid preview identity")
	}
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	state, dir, err := e.load(site)
	if err != nil {
		return nil, err
	}
	if state.RelocatedTo != "" || (state.Status != "ready" && state.Status != "needs_customer_setup") {
		return nil, errors.New("instance must be running")
	}
	if err = e.checkOwner(ctx, state, dir); err != nil {
		return nil, err
	}
	// Constant PHP source and a validated page ID are separate argv entries; no shell or credential exchange.
	raw, err := e.Runner.Run(ctx, compose(state, dir, "exec", "-T", "app", "php", "-r", strings.TrimPrefix(previewReader, "<?php"), page))
	if err != nil || len(raw) > 2<<20 {
		return nil, errors.New("draft could not be read")
	}
	var out draftPreview
	if json.Unmarshal(raw, &out) != nil || out.PageID != page || len(out.Blocks) == 0 || out.Palette == nil || (out.Publication != "draft" && out.Publication != "published" && out.Publication != "published_with_changes") {
		return nil, errors.New("invalid draft snapshot")
	}
	out.SiteID = site
	out.Storefront = fmt.Sprintf("http://%s.localhost:%d/", site, state.Request.Port)
	return &out, nil
}
func (a *centralAPI) managementPreviewHTTP(w http.ResponseWriter, r *http.Request, parts []string) bool {
	if len(parts) != 3 || parts[0] != "instances" || parts[2] != "preview" || r.Method != "GET" {
		return false
	}
	if !sitePattern.MatchString(parts[1]) {
		apiError(w, 404, "instance not found")
		return true
	}
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	if a.backup.active {
		apiError(w, 409, "another operation is running")
		return true
	}
	jobs, err := a.customerJobs()
	if err != nil {
		apiError(w, 503, "workflows unavailable")
		return true
	}
	for _, job := range jobs {
		if job.Input.SiteID == parts[1] && job.Status == "complete" && job.Result != nil {
			out, err := a.backup.engine.draftPreview(r.Context(), parts[1], job.Result.PageID)
			if err != nil {
				apiError(w, 409, "draft preview unavailable; instance must be running")
				return true
			}
			apiJSON(w, 200, out)
			return true
		}
	}
	apiError(w, 404, "generated page not found")
	return true
}
