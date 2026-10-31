package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/mail"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Customer creation is an operator API on the private Unix socket, not a public signup API.
type customerRequest struct {
	SiteID      string `json:"site_id"`
	CustomerRef string `json:"customer_ref"`
	Email       string `json:"email"`
	PackageID   string `json:"package_id"`
	TemplateID  string `json:"template_id"`
	ReleaseID   string `json:"release_id"`
	Port        int    `json:"port"`
	Brief       Brief  `json:"brief"`
}
type customerResult struct {
	SiteID            string `json:"site_id"`
	GenerationID      string `json:"generation_id"`
	PageID            string `json:"page_id"`
	AdminURL          string `json:"admin_url"`
	PreviewURL        string `json:"preview_url"`
	BuilderURL        string `json:"builder_url"`
	SetupLinkEndpoint string `json:"setup_link_endpoint"`
	Published         bool   `json:"published"`
}
type customerJob struct {
	FailureCode    string          `json:"failure_code,omitempty"`
	GenerationID   string          `json:"generation_id,omitempty"`
	Model          string          `json:"model"`
	ID             string          `json:"id"`
	IdempotencyKey string          `json:"idempotency_key"`
	PayloadHash    string          `json:"payload_hash"`
	Input          customerRequest `json:"input"`
	Request        Request         `json:"request"`
	Release        Release         `json:"release"`
	Template       catalogTemplate `json:"template"`
	ImagePinned    bool            `json:"image_pinned"`
	Provisioned    bool            `json:"provisioned"`
	Status         string          `json:"status"`
	Phase          string          `json:"phase"`
	Attempts       int             `json:"attempts"`
	CreatedAt      time.Time       `json:"created_at"`
	UpdatedAt      time.Time       `json:"updated_at"`
	Result         *customerResult `json:"result,omitempty"`
	Error          string          `json:"error,omitempty"`
}

func (a *centralAPI) customerDir() string { return filepath.Join(a.dir, "customers") }
func (a *centralAPI) saveCustomer(j *customerJob) error {
	return atomicJSON(filepath.Join(a.customerDir(), j.ID+".json"), j)
}
func (a *centralAPI) recoverCustomers() error {
	if err := privateBackupDir(a.customerDir()); err != nil {
		return err
	}
	entries, err := os.ReadDir(a.customerDir())
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var j customerJob
		if err = decode(filepath.Join(a.customerDir(), entry.Name()), &j); err != nil {
			return errors.New("invalid customer workflow")
		}
		if j.Status == "running" {
			j.Status = "interrupted"
			j.Error = "service restarted; retry this workflow to resume"
			j.UpdatedAt = time.Now().UTC()
			if err = a.saveCustomer(&j); err != nil {
				return err
			}
		}
	}
	return nil
}

// The progress response deliberately excludes the email, brief, runtime configuration and credentials.
func customerView(j customerJob) map[string]any {
	return map[string]any{"id": j.ID, "site_id": j.Input.SiteID, "status": j.Status, "phase": j.Phase, "attempts": j.Attempts, "created_at": j.CreatedAt, "updated_at": j.UpdatedAt, "error": j.Error, "retryable": j.Status == "failed" || j.Status == "interrupted", "result": j.Result, "status_url": "/v1/customers/" + j.ID}
}
func (a *centralAPI) prepareCustomer(in customerRequest, key string) (customerJob, error) {
	if a.customerGenerate == nil && (os.Getenv("OPENAI_API_KEY") == "" || os.Getenv("GEREQ_GENERATOR_MODEL") == "") {
		return customerJob{}, errors.New("central AI provider is not configured")
	}
	addr, err := mail.ParseAddress(in.Email)
	if err != nil || addr.Address != in.Email || len(in.Email) > 180 {
		return customerJob{}, errors.New("valid owner email required")
	}
	if err = in.Brief.validate(); err != nil {
		return customerJob{}, err
	}
	if in.Brief.IllustrationURL != "" {
		return customerJob{}, errors.New("a new customer cannot reference existing instance media")
	}
	l, err := a.release(in.ReleaseID)
	if err != nil {
		return customerJob{}, err
	}
	r := Request{BrandName: in.Brief.Name, Schema: 1, RequestID: "customer-" + key, SiteID: in.SiteID, CustomerRef: in.CustomerRef, Package: "funnel-v1", CatalogPackageID: in.PackageID, Release: in.ReleaseID, Port: in.Port}
	if in.PackageID == "" || in.TemplateID == "" {
		return customerJob{}, errors.New("package_id and template_id required")
	}
	if err = a.resolvePackage(&r); err != nil {
		return customerJob{}, err
	}
	if _, err = a.packageTarget(&State{Request: r, Release: *l}, r.CatalogPackageID); err != nil {
		return customerJob{}, err
	}
	if err = validate(r, *l); err != nil {
		return customerJob{}, err
	}
	catalog, err := a.loadCatalog()
	if err != nil {
		return customerJob{}, err
	}
	pkg := catalog.Packages[r.CatalogPackageID]
	if pkg.GenerationLimit != nil && *pkg.GenerationLimit == 0 {
		return customerJob{}, errors.New("customer creation requires a positive generation limit")
	}
	if !packageFeatureEnabled(&pkg, "website_generation") || !packageFeatureEnabled(&pkg, "draft_import") {
		return customerJob{}, errors.New("customer creation requires generation and draft import features")
	}
	t, ok := catalog.Templates[in.TemplateID]
	if !ok || !t.Active || t.Archived || !contains(r.AllowedTemplates, t.ID) {
		return customerJob{}, errors.New("template unavailable for selected package")
	}
	if t.Driver != "native-json" && t.Driver != "ai-editorial" {
		return customerJob{}, errors.New("customer creation requires an AI-capable template")
	}
	p, err := ownedDir(a.backup.engine.Root, in.SiteID)
	if err != nil {
		return customerJob{}, err
	}
	if _, err = os.Lstat(p); !os.IsNotExist(err) {
		return customerJob{}, errors.New("site already exists or is unavailable; retry its original workflow")
	}
	token, err := randomSecret()
	if err != nil {
		return customerJob{}, err
	}
	raw, _ := json.Marshal(in)
	sum := sha256.Sum256(raw)
	now := time.Now().UTC()
	return customerJob{Model: os.Getenv("GEREQ_GENERATOR_MODEL"), ID: "job-" + token[:24], IdempotencyKey: key, PayloadHash: hex.EncodeToString(sum[:]), Input: in, Request: r, Release: *l, Template: t, Status: "running", Phase: "provisioning", Attempts: 1, CreatedAt: now, UpdatedAt: now}, nil
}
func (a *centralAPI) customerCheckpoint(j *customerJob, phase string) error {
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	j.Phase = phase
	j.UpdatedAt = time.Now().UTC()
	return a.saveCustomer(j)
}
func (a *centralAPI) runCustomer(ctx context.Context, j *customerJob) error {
	if err := a.subscriptionAllows(j.Input.SiteID); err != nil {
		return err
	}
	if !j.Provisioned {
		if err := a.customerCheckpoint(j, "provisioning"); err != nil {
			return err
		}
		if !j.ImagePinned {
			image, err := a.backup.engine.updateImage(ctx, j.Release)
			if err != nil {
				return err
			}
			j.Release.Image = image
			j.ImagePinned = true
			if err = a.customerCheckpoint(j, "provisioning"); err != nil {
				return err
			}
		}
		if err := a.ensureSiteDomain(j.Input.SiteID); err != nil {
			return err
		}
		if _, err := a.backup.engine.Create(ctx, j.Request, j.Release); err != nil {
			return err
		}
		state, _, err := a.backup.engine.load(j.Input.SiteID)
		if err != nil {
			return err
		}
		if _, err = a.enrollSubscription(state, nil, nil, "", ""); err != nil {
			return err
		}
		j.Provisioned = true
	}
	if err := a.subscriptionAllows(j.Input.SiteID); err != nil {
		return err
	}
	if j.Result == nil {
		if err := a.packageFeatureAllows(j.Input.SiteID, "website_generation"); err != nil {
			return err
		}
		if err := a.packageFeatureAllows(j.Input.SiteID, "draft_import"); err != nil {
			return err
		}
		if err := a.customerCheckpoint(j, "generating"); err != nil {
			return err
		}
		req := centralRequest{generatorModel: j.Model, Operation: "generate", SiteID: j.Input.SiteID, Brief: &j.Input.Brief, TemplateID: j.Template.ID, ImportDraft: true}
		req.generationUsageKey = fmt.Sprintf("%s-attempt-%d", j.ID, j.Attempts)
		generate := a.generateSelected
		if a.customerGenerate != nil {
			generate = a.customerGenerate
		}
		var raw any
		var err error
		if j.GenerationID != "" {
			if err = a.customerCheckpoint(j, "importing"); err != nil {
				return err
			}
			path := filepath.Join(a.backup.engine.Root, "generations", j.Input.SiteID, j.GenerationID, "artifact.json")
			var artifact FunnelArtifact
			if err = decode(path, &artifact); err != nil || artifact.ID != j.GenerationID {
				return errors.New("saved generation unavailable; operator inspection required")
			}
			var imported []byte
			imported, err = a.backup.engine.Import(ctx, j.Input.SiteID, path)
			if err == nil {
				var data any
				if json.Unmarshal(imported, &data) != nil {
					return errors.New("invalid saved draft import response")
				}
				raw = map[string]any{"generation_id": j.GenerationID, "imported": true, "import_result": data}
			}
		} else {
			raw, err = generate(ctx, req, j.Template, func(result GenerationResult) error {
				j.GenerationID = result.JobID
				return a.customerCheckpoint(j, "importing")
			})
		}
		if err != nil {
			return err
		}
		data, err := json.Marshal(raw)
		if err != nil {
			return err
		}
		var result struct {
			GenerationID string `json:"generation_id"`
			Imported     bool   `json:"imported"`
			ImportResult struct {
				PageID string `json:"page_id"`
				Draft  bool   `json:"draft"`
			} `json:"import_result"`
		}
		if json.Unmarshal(data, &result) != nil || result.GenerationID == "" || !result.Imported || result.ImportResult.PageID == "" || !result.ImportResult.Draft {
			return errors.New("generation did not return an imported draft")
		}
		base := fmt.Sprintf("http://%s.localhost:%d", j.Input.SiteID, j.Input.Port)
		page := base + "/admin/cms/pages/" + result.ImportResult.PageID
		j.Result = &customerResult{SiteID: j.Input.SiteID, GenerationID: result.GenerationID, PageID: result.ImportResult.PageID, AdminURL: base + "/admin", PreviewURL: page + "/preview", BuilderURL: page + "/builder", SetupLinkEndpoint: "/v1/customers/" + j.ID + "/setup-link", Published: false}
	}
	return a.customerCheckpoint(j, "ready_for_review")
}
func (a *centralAPI) launchCustomer(j customerJob) {
	a.backup.active = true
	a.backup.workers.Add(1)
	go func() {
		defer a.backup.workers.Done()
		ctx, cancel := context.WithTimeout(a.backup.jobContext, 6*time.Hour)
		defer cancel()
		err := a.runCustomer(ctx, &j)
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		j.UpdatedAt = time.Now().UTC()
		if err != nil {
			j.Status = "failed"
			j.FailureCode = customerFailureCode(err)
			j.Error = "customer creation failed during " + j.Phase + "; inspect the private workflow and retry"
		} else {
			j.Status = "complete"
			j.Error = ""
		}
		// Keep diagnostics private; runner/provider errors may contain request or credential data.
		if err != nil {
			_ = atomicJSON(filepath.Join(a.customerDir(), j.ID+".diagnostic"), map[string]string{"phase": j.Phase, "error": err.Error()})
		}
		if a.saveCustomer(&j) == nil {
			a.backup.active = false
		}
	}()
}
func (a *centralAPI) customersHTTP(w http.ResponseWriter, r *http.Request) bool {
	if r.URL.Path != "/v1/customers" && !strings.HasPrefix(r.URL.Path, "/v1/customers/") {
		return false
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters are not supported")
		return true
	}
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	if r.URL.Path == "/v1/customers" && r.Method == http.MethodPost {
		var in customerRequest
		if !parseAPIJSON(w, r, &in) {
			return true
		}
		key := r.Header.Get("Idempotency-Key")
		if !apiKeyPattern.MatchString(key) || len(key) > 110 {
			apiError(w, 400, "valid Idempotency-Key (8..110 characters) required")
			return true
		}
		raw, _ := json.Marshal(in)
		sum := sha256.Sum256(raw)
		hash := hex.EncodeToString(sum[:])
		entries, err := os.ReadDir(a.customerDir())
		if err != nil {
			apiError(w, 500, "customer storage unavailable")
			return true
		}
		for _, entry := range entries {
			if !strings.HasSuffix(entry.Name(), ".json") {
				continue
			}
			var old customerJob
			if decode(filepath.Join(a.customerDir(), entry.Name()), &old) != nil {
				apiError(w, 500, "customer storage invalid")
				return true
			}
			if old.IdempotencyKey == key {
				if old.PayloadHash != hash {
					apiError(w, 409, "idempotency key already used for another request")
				} else {
					apiJSON(w, 200, customerView(old))
				}
				return true
			}
			if old.Input.SiteID == in.SiteID {
				apiError(w, 409, "site is reserved by another workflow; retry its original workflow")
				return true
			}
		}
		if a.backup.active {
			apiError(w, 409, "another job is running")
			return true
		}
		j, err := a.prepareCustomer(in, key)
		if err != nil {
			apiError(w, 400, err.Error())
			return true
		}
		if err = a.saveCustomer(&j); err != nil {
			apiError(w, 500, "cannot save customer workflow")
			return true
		}
		a.launchCustomer(j)
		w.Header().Set("Location", "/v1/customers/"+j.ID)
		apiJSON(w, 202, customerView(j))
		return true
	}
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/customers/"), "/")
	if len(parts) < 1 || len(parts) > 2 || !apiJobPattern.MatchString(parts[0]) {
		apiError(w, 404, "customer workflow not found")
		return true
	}
	var j customerJob
	if decode(filepath.Join(a.customerDir(), parts[0]+".json"), &j) != nil {
		apiError(w, 404, "customer workflow not found")
		return true
	}
	if len(parts) == 1 && r.Method == http.MethodGet {
		apiJSON(w, 200, customerView(j))
		return true
	}
	if len(parts) == 2 && r.Method == http.MethodPost && parts[1] == "retry" {
		if a.backup.active {
			apiError(w, 409, "another job is running")
			return true
		}
		if j.Status != "failed" && j.Status != "interrupted" {
			apiError(w, 409, "only failed or interrupted workflows can be retried")
			return true
		}
		j.Status = "running"
		j.FailureCode = ""
		j.Error = ""
		j.Attempts++
		j.UpdatedAt = time.Now().UTC()
		if err := a.saveCustomer(&j); err != nil {
			apiError(w, 500, "cannot save retry")
			return true
		}
		a.launchCustomer(j)
		apiJSON(w, 202, customerView(j))
		return true
	}
	if len(parts) == 2 && r.Method == http.MethodPost && parts[1] == "setup-link" {
		if err := a.subscriptionAllows(j.Input.SiteID); err != nil {
			apiError(w, 409, err.Error())
			return true
		}
		if a.backup.active || j.Status != "complete" {
			apiError(w, 409, "customer workflow must be complete and service idle")
			return true
		}
		// Issue on explicit retrieval, so secrets never enter job status and the expiry starts at delivery time.
		raw, err := a.backup.engine.Access(r.Context(), j.Input.SiteID, "setup-link", j.Input.Email)
		if err != nil {
			apiError(w, 409, "setup link could not be issued")
			return true
		}
		var result any
		if json.Unmarshal(raw, &result) != nil {
			apiError(w, 502, "invalid setup response")
			return true
		}
		apiJSON(w, 200, result)
		return true
	}
	apiError(w, 404, "not found")
	return true
}

func customerFailureCode(err error) string {
	message := err.Error()
	if strings.Contains(message, "AI request failed or timed out") {
		return "ai_timeout"
	}
	if strings.Contains(message, "AI request rejected") {
		return "ai_rejected"
	}
	if strings.Contains(message, "funnel structure") || strings.Contains(message, "generated ") || strings.Contains(message, "layout ") || strings.Contains(message, "content specification") || strings.Contains(message, "AI response") {
		return "invalid_generated_design"
	}
	return "creation_failed"
}
