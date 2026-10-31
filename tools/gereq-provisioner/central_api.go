package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

type centralRequest struct {
	PackagePlanID      string `json:"package_plan_id,omitempty"`
	ExpectedRelease    string `json:"expected_release,omitempty"`
	generatorModel     string
	generationUsageKey string
	Operation          string      `json:"operation"`
	SiteID             string      `json:"site_id"`
	Request            *Request    `json:"request,omitempty"`
	ReleaseID          string      `json:"release_id,omitempty"`
	ExpectedImageID    string      `json:"expected_image_id,omitempty"`
	UpdateID           string      `json:"update_id,omitempty"`
	AcceptDataLoss     bool        `json:"accept_data_loss,omitempty"`
	Brief              *Brief      `json:"brief,omitempty"`
	Spec               *FunnelSpec `json:"spec,omitempty"`
	ImportDraft        bool        `json:"import_draft,omitempty"`
	TemplateID         string      `json:"template_id,omitempty"`
}
type centralJob struct {
	ID             string         `json:"id"`
	IdempotencyKey string         `json:"idempotency_key"`
	PayloadHash    string         `json:"payload_hash"`
	Request        centralRequest `json:"request"`
	Release        *Release       `json:"release,omitempty"`
	Status         string         `json:"status"`
	CreatedAt      time.Time      `json:"created_at"`
	FinishedAt     *time.Time     `json:"finished_at,omitempty"`
	Result         any            `json:"result,omitempty"`
	Error          string         `json:"error,omitempty"`
}
type centralAPI struct {
	imageImportMu    sync.Mutex
	domains          domainStore
	domainsMu        sync.Mutex
	moduleCatalog    func(context.Context) ([]backendModule, error)
	accounts         accountStore
	selfMu           sync.Mutex
	subscriptions    subscriptionStore
	subscriptionMu   sync.Mutex
	now              func() time.Time
	backup           *backupAPI
	registry         releaseRegistry
	catalog          string
	dir              string
	execute          func(context.Context, centralRequest, *Release) (any, error)
	customerGenerate func(context.Context, centralRequest, catalogTemplate, func(GenerationResult) error) (any, error)
}

func newCentralAPI(backup *backupAPI, catalog string) (*centralAPI, error) {
	if catalog != "" {
		if !filepath.IsAbs(catalog) {
			return nil, errors.New("legacy release catalog must be absolute")
		}
		info, err := os.Lstat(catalog)
		if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
			return nil, errors.New("legacy release catalog unavailable")
		}
	}
	var err error
	dir := filepath.Join(backup.engine.Root, "central-api")
	if err = privateBackupDir(dir); err != nil {
		return nil, err
	}
	if err = privateBackupDir(filepath.Join(dir, "jobs")); err != nil {
		return nil, err
	}
	entriesAtRoot, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	for _, entry := range entriesAtRoot {
		if !entry.IsDir() || !strings.HasPrefix(entry.Name(), ".generation-") {
			continue
		}
		path := filepath.Join(dir, entry.Name())
		var marker struct {
			Kind string `json:"kind"`
		}
		if decode(filepath.Join(path, ".gereq-generation-stage.json"), &marker) == nil && marker.Kind == "central-generation-stage" {
			if err = os.RemoveAll(path); err != nil {
				return nil, err
			}
		}
	}
	a := &centralAPI{backup: backup, catalog: catalog, dir: dir}
	a.domains = domainStore{db: releaseRegistry{path: filepath.Join(dir, "domains.sqlite")}}
	if err = a.domains.initialize(); err != nil {
		return nil, err
	}
	a.accounts = accountStore{db: releaseRegistry{path: filepath.Join(dir, "accounts.sqlite")}}
	if err = a.accounts.initialize(); err != nil {
		return nil, err
	}
	a.registry = releaseRegistry{path: filepath.Join(dir, "releases.sqlite")}
	if err = a.registry.initialize(catalog); err != nil {
		return nil, err
	}
	a.subscriptions = subscriptionStore{db: releaseRegistry{path: filepath.Join(dir, "subscriptions.sqlite")}}
	if err = a.subscriptions.initialize(); err != nil {
		return nil, err
	}
	if err = a.cleanupPackageModuleStages(); err != nil {
		return nil, err
	}
	a.execute = a.executeJob
	entries, err := os.ReadDir(filepath.Join(dir, "jobs"))
	if err != nil {
		return nil, err
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var j centralJob
		if err = decode(filepath.Join(dir, "jobs", entry.Name()), &j); err != nil {
			return nil, errors.New("invalid central job")
		}
		if j.Status == "running" {
			now := time.Now().UTC()
			j.Status = "interrupted"
			j.FinishedAt = &now
			j.Error = "service restarted; inspect instance before requesting recovery"
			if err = a.save(&j); err != nil {
				return nil, err
			}
		}
	}
	if err = privateBackupDir(filepath.Join(a.dir, "management-health")); err != nil {
		return nil, err
	}
	if err = a.recoverCustomers(); err != nil {
		return nil, err
	}
	if err = a.recoverImageImports(); err != nil {
		return nil, err
	}
	return a, nil
}
func (a *centralAPI) save(j *centralJob) error {
	return atomicJSON(filepath.Join(a.dir, "jobs", j.ID+".json"), j)
}
func (a *centralAPI) release(id string) (*Release, error) {
	record, err := a.registry.get(id)
	if err != nil || !record.Active {
		return nil, errors.New("release not active in registry")
	}
	release := record.Release
	return &release, nil
}

func (a *centralAPI) validateRequest(req centralRequest) (*Release, error) {
	if req.PackagePlanID != "" && req.Operation != "package-change" {
		return nil, errors.New("unexpected package plan")
	}
	if err := a.packageRequestAllows(req); err != nil {
		return nil, err
	}
	if contains([]string{"create", "start", "resume", "update", "update-recover", "update-rollback", "generate"}, req.Operation) {
		if err := a.subscriptionAllows(req.SiteID); err != nil {
			return nil, err
		}
	}
	if req.ExpectedRelease != "" && req.Operation != "update" {
		return nil, errors.New("unexpected source release")
	}
	if !sitePattern.MatchString(req.SiteID) {
		return nil, errors.New("invalid site ID")
	}
	switch req.Operation {
	case "package-change", "package-recover":
		if req.Request != nil || req.ReleaseID != "" || req.ExpectedRelease != "" || req.ExpectedImageID != "" || req.UpdateID != "" || req.AcceptDataLoss || req.Brief != nil || req.Spec != nil || req.ImportDraft || req.TemplateID != "" {
			return nil, errors.New("invalid package operation")
		}
		if req.Operation == "package-change" && !packagePlanPattern.MatchString(req.PackagePlanID) {
			return nil, errors.New("invalid package plan")
		}
		return nil, nil
	case "create":
		if req.Request == nil || req.Request.SiteID != req.SiteID || req.ReleaseID == "" || req.Brief != nil || req.Spec != nil || req.ImportDraft || req.UpdateID != "" || req.AcceptDataLoss || req.ExpectedImageID != "" || req.TemplateID != "" {
			return nil, errors.New("invalid create request")
		}
		l, err := a.release(req.ReleaseID)
		if err != nil {
			return nil, err
		}
		if err = a.resolvePackage(req.Request); err != nil {
			return nil, err
		}
		if _, err = a.packageTarget(&State{Request: *req.Request, Release: *l}, req.Request.CatalogPackageID); err != nil {
			return nil, err
		}
		if err = validate(*req.Request, *l); err != nil {
			return nil, err
		}
		return l, nil
	case "update":
		if req.Request != nil || req.Brief != nil || req.Spec != nil || req.ImportDraft || req.UpdateID != "" || req.AcceptDataLoss || req.TemplateID != "" || normalizeImageID(req.ExpectedImageID) == "" {
			return nil, errors.New("invalid update request")
		}
		return a.release(req.ReleaseID)
	case "generate":
		if req.Brief == nil || req.Request != nil || req.ReleaseID != "" || req.UpdateID != "" || req.AcceptDataLoss || req.ExpectedImageID != "" {
			return nil, errors.New("invalid generation request")
		}
		if err := req.Brief.validate(); err != nil {
			return nil, err
		}
		if err := a.resolveTemplate(&req); err != nil {
			return nil, err
		}
		return nil, nil
	case "subscription-reconcile", "inspect", "start", "stop", "resume", "update-recover", "update-rollback", "update-export-retained":
		if req.Request != nil || req.Brief != nil || req.Spec != nil || req.ImportDraft || req.ReleaseID != "" || req.ExpectedImageID != "" || req.TemplateID != "" {
			return nil, errors.New("invalid lifecycle request")
		}
		if req.Operation == "update-export-retained" {
			if !updateIDPattern.MatchString(req.UpdateID) || req.AcceptDataLoss {
				return nil, errors.New("retained export requires update ID")
			}
			return nil, nil
		}
		if req.Operation == "update-rollback" {
			if !updateIDPattern.MatchString(req.UpdateID) || !req.AcceptDataLoss {
				return nil, errors.New("rollback requires update ID and data-loss acknowledgement")
			}
		} else if req.UpdateID != "" || (req.AcceptDataLoss && req.Operation != "update-recover") {
			return nil, errors.New("invalid recovery options")
		}
		return nil, nil
	default:
		return nil, errors.New("unsupported operation")
	}
}
func (a *centralAPI) executeJob(ctx context.Context, req centralRequest, l *Release) (any, error) {
	if err := a.packageRequestAllows(req); err != nil {
		return nil, err
	}
	e := a.backup.engine
	if contains([]string{"create", "start", "resume", "update", "update-recover", "update-rollback", "generate"}, req.Operation) {
		if err := a.subscriptionAllows(req.SiteID); err != nil {
			return nil, err
		}
	}
	switch req.Operation {
	case "package-change":
		return a.runPackageModuleChange(ctx, req.SiteID, req.PackagePlanID)
	case "package-recover":
		return a.recoverPackageModuleChange(ctx, req.SiteID)
	case "subscription-reconcile":
		return a.reconcileSubscription(ctx, req.SiteID)
	case "create":
		image, err := e.updateImage(ctx, *l)
		if err != nil {
			return nil, err
		}
		pinned := *l
		pinned.Image = image
		if err := a.ensureSiteDomain(req.Request.SiteID); err != nil {
			return nil, err
		}
		s, err := e.Create(ctx, *req.Request, pinned)
		if err != nil {
			return nil, err
		}
		if _, err = a.enrollSubscription(s, nil, nil, "", ""); err != nil {
			return nil, err
		}
		return publicState(s), nil
	case "resume":
		s, _, err := e.load(req.SiteID)
		if err != nil {
			return nil, err
		}
		s, err = e.Create(ctx, s.Request, s.Release)
		if err != nil {
			return nil, err
		}
		return publicState(s), nil
	case "inspect":
		return a.inspectManaged(ctx, req.SiteID)
	case "start", "stop":
		s, err := e.Lifecycle(ctx, req.SiteID, req.Operation)
		if err != nil {
			return nil, err
		}
		if req.Operation == "stop" {
			if err = a.noteManualStop(req.SiteID); err != nil {
				return publicState(s), err
			}
		}
		if _, err = a.inspectManaged(ctx, req.SiteID); err != nil {
			return publicState(s), err
		}
		return publicState(s), nil
	case "update":
		if req.ExpectedRelease != "" {
			state, _, err := e.load(req.SiteID)
			if err != nil || state.Release.ID != req.ExpectedRelease {
				return nil, errors.New("instance changed; create a new update plan")
			}
		}
		j, err := e.Update(ctx, req.SiteID, *l, req.ExpectedImageID)
		if err != nil {
			return nil, err
		}
		if _, err = a.inspectManaged(ctx, req.SiteID); err != nil {
			return nil, err
		}
		return map[string]any{"site_id": j.Site, "update_id": j.ID, "status": j.Phase}, nil
	case "update-recover":
		j, err := e.RecoverUpdate(ctx, req.SiteID, req.AcceptDataLoss)
		if err != nil {
			return nil, err
		}
		if _, err = a.inspectManaged(ctx, req.SiteID); err != nil {
			return nil, err
		}
		return map[string]any{"site_id": j.Site, "update_id": j.ID, "status": j.Phase}, nil
	case "update-export-retained":
		return e.ExportRetainedUpdate(ctx, req.SiteID, req.UpdateID)
	case "update-rollback":
		j, err := e.RollbackUpdate(ctx, req.SiteID, req.UpdateID, req.AcceptDataLoss)
		if err != nil {
			return nil, err
		}
		if _, err = a.inspectManaged(ctx, req.SiteID); err != nil {
			return nil, err
		}
		return map[string]any{"site_id": j.Site, "update_id": j.ID, "status": j.Phase, "retained_writes": true}, nil
	case "generate":
		return a.generate(ctx, req)
	}
	return nil, errors.New("unsupported operation")
}
func (a *centralAPI) generate(ctx context.Context, req centralRequest) (any, error) {
	catalog, err := a.loadCatalog()
	if err != nil {
		return nil, err
	}
	selected, ok := catalog.Templates[req.TemplateID]
	if !ok || !selected.Active || selected.Archived {
		return nil, errors.New("template unavailable")
	}
	return a.generateSelected(ctx, req, selected, nil)
}
func (a *centralAPI) generateSelected(ctx context.Context, req centralRequest, selected catalogTemplate, beforeImport func(GenerationResult) error) (any, error) {
	if err := a.packageRequestAllows(req); err != nil {
		return nil, err
	}
	e := a.backup.engine
	state, _, loadErr := e.load(req.SiteID)
	if loadErr != nil {
		return nil, loadErr
	}
	if !contains(instanceModules(state), "site_inquiries") {
		return nil, errors.New("funnel generation requires site_inquiries in the package")
	}
	check := req
	check.TemplateID = selected.ID
	if err := a.resolveTemplate(&check); err != nil {
		return nil, err
	}
	if err := a.reserveGeneration(req.SiteID, req.generationUsageKey); err != nil {
		return nil, err
	}
	var native *NativeTemplate
	if selected.Driver == "native-json" {
		native = selected.Native
	}
	if _, _, err := e.load(req.SiteID); err != nil {
		return nil, err
	}
	if req.ImportDraft && req.Spec == nil {
		if err := e.requirePaletteSupport(ctx, req.SiteID); err != nil {
			return nil, err
		}
	}
	dir, err := os.MkdirTemp(a.dir, ".generation-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(dir)
	if err = atomicJSON(filepath.Join(dir, ".gereq-generation-stage.json"), map[string]string{"kind": "central-generation-stage"}); err != nil {
		return nil, err
	}
	brief := filepath.Join(dir, "brief.json")
	if err = atomicJSON(brief, req.Brief); err != nil {
		return nil, err
	}
	spec := ""
	if req.Spec != nil {
		spec = filepath.Join(dir, "spec.json")
		if err = atomicJSON(spec, req.Spec); err != nil {
			return nil, err
		}
	}
	model := req.generatorModel
	if model == "" {
		model = os.Getenv("GEREQ_GENERATOR_MODEL")
	}
	provider := OpenAIProvider{Key: os.Getenv("OPENAI_API_KEY"), Model: model, NativeTemplate: native != nil}
	var media func(context.Context) (string, error)
	if req.ImportDraft {
		media = func(ctx context.Context) (string, error) { return e.ensureEditorialMedia(ctx, req.SiteID) }
	}
	result, err := generateWithNativeTemplate(ctx, e.Root, req.SiteID, brief, spec, provider, model, native, media)
	if err != nil {
		return nil, err
	}
	answer := map[string]any{"site_id": req.SiteID, "generation_id": result.JobID, "reused": result.Reused, "imported": false}
	if req.ImportDraft {
		if beforeImport != nil {
			if err := beforeImport(result); err != nil {
				return answer, err
			}
		}
		raw, err := e.Import(ctx, req.SiteID, result.Artifact)
		if err != nil {
			return answer, err
		}
		var data any
		if json.Unmarshal(raw, &data) != nil {
			return answer, errors.New("invalid import response")
		}
		answer["imported"] = true
		answer["import_result"] = data
	}
	return answer, nil
}
func requestHash(r centralRequest) string {
	b, _ := json.Marshal(r)
	h := sha256.Sum256(b)
	return hex.EncodeToString(h[:])
}
func (a *centralAPI) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if a.selfServiceHTTP(w, r) || a.accountAdminHTTP(w, r) {
		return
	}
	if a.packageCapabilitiesHTTP(w, r) {
		return
	}
	if a.packageHTTP(w, r) {
		return
	}
	if a.subscriptionHTTP(w, r) {
		return
	}
	if a.domainsHTTP(w, r) {
		return
	}
	if a.imageImportsHTTP(w, r) {
		return
	}
	if a.releaseRegistryHTTP(w, r) {
		return
	}
	if a.managementHTTP(w, r) {
		return
	}
	if a.customersHTTP(w, r) {
		return
	}
	if a.instanceTemplatesHTTP(w, r) {
		return
	}
	if strings.HasPrefix(r.URL.Path, "/v1/catalog/") {
		a.catalogHTTP(w, r)
		return
	}
	if strings.HasPrefix(r.URL.Path, "/v1/backup/") {
		a.backup.ServeHTTP(w, r)
		return
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters are not supported")
		return
	}
	switch {
	case strings.HasPrefix(r.URL.Path, "/v1/setup-links/") && r.Method == http.MethodPost:
		site := strings.TrimPrefix(r.URL.Path, "/v1/setup-links/")
		if err := a.subscriptionAllows(site); err != nil {
			apiError(w, 409, err.Error())
			return
		}
		var input struct {
			Email string `json:"email"`
		}
		if !sitePattern.MatchString(site) {
			apiError(w, 400, "invalid site ID")
			return
		}
		if !parseAPIJSON(w, r, &input) {
			return
		}
		if len(input.Email) == 0 || len(input.Email) > 180 {
			apiError(w, 400, "invalid email")
			return
		}
		a.backup.mu.Lock()
		if a.backup.active {
			a.backup.mu.Unlock()
			apiError(w, 409, "another job is running")
			return
		}
		a.backup.active = true
		a.backup.mu.Unlock()
		defer func() { a.backup.mu.Lock(); a.backup.active = false; a.backup.mu.Unlock() }()
		raw, err := a.backup.engine.Access(r.Context(), site, "setup-link", input.Email)
		if err != nil {
			apiError(w, 409, "setup link could not be issued")
			return
		}
		var result any
		if json.Unmarshal(raw, &result) != nil {
			apiError(w, 502, "invalid setup response")
			return
		}
		apiJSON(w, 200, result)
	case strings.HasPrefix(r.URL.Path, "/v1/update-status/") && r.Method == http.MethodGet:
		parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/update-status/"), "/")
		if len(parts) < 1 || len(parts) > 2 || !sitePattern.MatchString(parts[0]) {
			apiError(w, 400, "invalid update status path")
			return
		}
		id := ""
		if len(parts) == 2 {
			id = parts[1]
			if !updateIDPattern.MatchString(id) {
				apiError(w, 400, "invalid update ID")
				return
			}
		}
		result, err := a.backup.engine.UpdateStatus(parts[0], id)
		if err != nil {
			apiError(w, 409, "update status unavailable")
			return
		}
		apiJSON(w, 200, result)
	case strings.HasPrefix(r.URL.Path, "/v1/generations/") && r.Method == http.MethodGet:
		parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/generations/"), "/")
		if len(parts) != 2 || !sitePattern.MatchString(parts[0]) || len(parts[1]) != 28 || !strings.HasPrefix(parts[1], "gen-") {
			apiError(w, 400, "invalid generation ID")
			return
		}
		for _, ch := range parts[1][4:] {
			if !strings.ContainsRune("0123456789abcdef", ch) {
				apiError(w, 400, "invalid generation ID")
				return
			}
		}
		path := filepath.Join(a.backup.engine.Root, "generations", parts[0], parts[1], "artifact.json")
		info, err := os.Lstat(path)
		if err != nil || !info.Mode().IsRegular() {
			apiError(w, 404, "generation not found")
			return
		}
		var artifact FunnelArtifact
		if err = decode(path, &artifact); err != nil || artifact.ID != parts[1] {
			apiError(w, 500, "generation artifact invalid")
			return
		}
		apiJSON(w, 200, artifact)
	case r.URL.Path == "/v1/instances" && r.Method == http.MethodGet:
		unlock, err := a.backup.engine.lock()
		if err != nil {
			apiError(w, 409, "provisioner busy")
			return
		}
		defer unlock()
		root := filepath.Join(a.backup.engine.Root, "sites")
		entries, err := os.ReadDir(root)
		if os.IsNotExist(err) {
			apiJSON(w, 200, map[string]any{"instances": []any{}})
			return
		}
		if err != nil {
			apiError(w, 500, "instance registry unavailable")
			return
		}
		items := []any{}
		for _, entry := range entries {
			if !entry.IsDir() || !sitePattern.MatchString(entry.Name()) {
				continue
			}
			s, _, err := a.backup.engine.load(entry.Name())
			if err != nil {
				items = append(items, map[string]any{"site_id": entry.Name(), "status": "inspection_required"})
				continue
			}
			items = append(items, publicState(s))
		}
		apiJSON(w, 200, map[string]any{"instances": items})
	case strings.HasPrefix(r.URL.Path, "/v1/instances/") && r.Method == http.MethodGet:
		site := strings.TrimPrefix(r.URL.Path, "/v1/instances/")
		if !sitePattern.MatchString(site) {
			apiError(w, 404, "instance not found")
			return
		}
		unlock, err := a.backup.engine.lock()
		if err != nil {
			apiError(w, 409, "provisioner busy")
			return
		}
		defer unlock()
		s, _, err := a.backup.engine.load(site)
		if err != nil {
			apiError(w, 409, "instance unavailable or requires recovery")
			return
		}
		apiJSON(w, 200, publicState(s))
	case strings.HasPrefix(r.URL.Path, "/v1/update-plans/") && r.Method == http.MethodPost:
		site := strings.TrimPrefix(r.URL.Path, "/v1/update-plans/")
		var input struct {
			ReleaseID string `json:"release_id"`
		}
		if !sitePattern.MatchString(site) {
			apiError(w, 400, "invalid site ID")
			return
		}
		if !parseAPIJSON(w, r, &input) {
			return
		}
		l, err := a.release(input.ReleaseID)
		if err != nil {
			apiError(w, 400, err.Error())
			return
		}
		out, err := a.backup.engine.PlanUpdate(r.Context(), site, *l)
		if err != nil {
			apiError(w, 409, err.Error())
			return
		}
		apiJSON(w, 200, out)
	case r.URL.Path == "/v1/central/jobs" && r.Method == http.MethodPost:
		var input centralRequest
		if !parseAPIJSON(w, r, &input) {
			return
		}
		key := r.Header.Get("Idempotency-Key")
		if !apiKeyPattern.MatchString(key) {
			apiError(w, 400, "valid Idempotency-Key required")
			return
		}
		hash := requestHash(input)
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		entries, err := os.ReadDir(filepath.Join(a.dir, "jobs"))
		if err != nil {
			apiError(w, 500, "job storage unavailable")
			return
		}
		for _, entry := range entries {
			if !strings.HasSuffix(entry.Name(), ".json") {
				continue
			}
			var old centralJob
			if decode(filepath.Join(a.dir, "jobs", entry.Name()), &old) != nil {
				apiError(w, 500, "job storage invalid")
				return
			}
			if old.IdempotencyKey == key {
				if old.PayloadHash != hash {
					apiError(w, 409, "idempotency key already used for another request")
					return
				}
				apiJSON(w, 200, old)
				return
			}
		}
		if a.backup.active {
			apiError(w, 409, "another job is running")
			return
		}
		l, err := a.validateRequest(input)
		if err == nil && input.Operation == "generate" {
			err = a.resolveTemplate(&input)
		}
		if err != nil {
			apiError(w, 400, err.Error())
			return
		}
		token, err := randomSecret()
		if err != nil {
			apiError(w, 500, "cannot create job")
			return
		}
		j := centralJob{ID: "job-" + token[:24], IdempotencyKey: key, PayloadHash: hash, Request: input, Release: l, Status: "running", CreatedAt: time.Now().UTC()}
		if err = a.save(&j); err != nil {
			apiError(w, 500, "cannot save job")
			return
		}
		a.backup.active = true
		a.backup.workers.Add(1)
		go func() {
			defer a.backup.workers.Done()
			ctx, cancel := context.WithTimeout(a.backup.jobContext, 6*time.Hour)
			defer cancel()
			input.generationUsageKey = j.ID
			result, err := a.execute(ctx, input, l)
			a.backup.mu.Lock()
			defer a.backup.mu.Unlock()
			now := time.Now().UTC()
			j.FinishedAt = &now
			j.Result = result
			if err != nil {
				j.Status = "failed"
				j.Error = err.Error()
			} else {
				j.Status = "complete"
			}
			if a.save(&j) == nil {
				a.backup.active = false
			}
		}()
		apiJSON(w, 202, j)
	case r.URL.Path == "/v1/central/jobs" && r.Method == http.MethodGet:
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		entries, err := os.ReadDir(filepath.Join(a.dir, "jobs"))
		if err != nil {
			apiError(w, 500, "job storage unavailable")
			return
		}
		jobs := []centralJob{}
		for _, entry := range entries {
			if !strings.HasSuffix(entry.Name(), ".json") {
				continue
			}
			var j centralJob
			if decode(filepath.Join(a.dir, "jobs", entry.Name()), &j) != nil {
				apiError(w, 500, "job storage invalid")
				return
			}
			jobs = append(jobs, j)
		}
		sort.Slice(jobs, func(i, j int) bool { return jobs[i].CreatedAt.After(jobs[j].CreatedAt) })
		if len(jobs) > 100 {
			jobs = jobs[:100]
		}
		apiJSON(w, 200, map[string]any{"jobs": jobs})
	case strings.HasPrefix(r.URL.Path, "/v1/central/jobs/") && r.Method == http.MethodGet:
		id := strings.TrimPrefix(r.URL.Path, "/v1/central/jobs/")
		if !apiJobPattern.MatchString(id) {
			apiError(w, 404, "job not found")
			return
		}
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		var j centralJob
		if err := decode(filepath.Join(a.dir, "jobs", id+".json"), &j); err != nil {
			apiError(w, 404, "job not found")
			return
		}
		apiJSON(w, 200, j)
	default:
		apiError(w, 404, "not found")
	}
}
