package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
)

var managedCustomerID = regexp.MustCompile(`^cus-[a-f0-9]{24}$`)

type managedObservation struct {
	CheckedAt     time.Time `json:"checked_at"`
	Runtime       string    `json:"runtime"`
	Healthy       bool      `json:"healthy"`
	SetupRequired *bool     `json:"setup_required"`
	Error         string    `json:"error,omitempty"`
}
type storedObservation struct {
	Fingerprint string             `json:"fingerprint"`
	Observation managedObservation `json:"observation"`
}
type managedWorkflow struct {
	ID        string `json:"id"`
	Status    string `json:"status"`
	Phase     string `json:"phase"`
	Attempts  int    `json:"attempts"`
	Retryable bool   `json:"retryable"`
	Error     string `json:"error,omitempty"`
}
type managedAction struct {
	ID         string     `json:"id"`
	SiteID     string     `json:"site_id"`
	Operation  string     `json:"operation"`
	Status     string     `json:"status"`
	CreatedAt  time.Time  `json:"created_at"`
	FinishedAt *time.Time `json:"finished_at,omitempty"`
	Error      string     `json:"error,omitempty"`
}
type managedInstance struct {
	PackageChange *packageModuleStatus `json:"package_change,omitempty"`
	Subscription  *subscriptionView    `json:"subscription,omitempty"`
	TrialDays     int                  `json:"trial_days"`
	Update        *managedUpdate       `json:"update,omitempty"`
	SiteID        string               `json:"site_id"`
	CustomerID    string               `json:"customer_id"`
	CustomerRef   string               `json:"customer_ref"`
	Name          string               `json:"name"`
	Email         string               `json:"email,omitempty"`
	PackageID     string               `json:"package_id"`
	PackageName   string               `json:"package_name"`
	TemplateID    string               `json:"template_id,omitempty"`
	ReleaseID     string               `json:"release_id,omitempty"`
	Modules       []string             `json:"modules"`
	RecordStatus  string               `json:"record_status"`
	Step          string               `json:"step,omitempty"`
	AdminURL      string               `json:"admin_url,omitempty"`
	StorefrontURL string               `json:"storefront_url,omitempty"`
	PreviewURL    string               `json:"preview_url,omitempty"`
	BuilderURL    string               `json:"builder_url,omitempty"`
	Workflow      *managedWorkflow     `json:"workflow,omitempty"`
	Observation   *managedObservation  `json:"observation,omitempty"`
	Actions       []managedAction      `json:"actions"`
	CanStart      bool                 `json:"can_start"`
	CanStop       bool                 `json:"can_stop"`
	CanInspect    bool                 `json:"can_inspect"`
	CanSetup      bool                 `json:"can_setup"`
}
type managedCustomer struct {
	ID      string   `json:"id"`
	Ref     string   `json:"ref"`
	Name    string   `json:"name"`
	Email   string   `json:"email,omitempty"`
	SiteIDs []string `json:"site_ids"`
}
type managementInventory struct {
	Schema    int               `json:"schema_version"`
	Busy      bool              `json:"busy"`
	Customers []managedCustomer `json:"customers"`
	Instances []managedInstance `json:"instances"`
	Actions   []managedAction   `json:"actions"`
}

func managementCustomerID(ref string) string {
	sum := sha256.Sum256([]byte(ref))
	return "cus-" + hex.EncodeToString(sum[:12])
}
func safeManagedAction(j centralJob) managedAction {
	result := managedAction{ID: j.ID, SiteID: j.Request.SiteID, Operation: j.Request.Operation, Status: j.Status, CreatedAt: j.CreatedAt, FinishedAt: j.FinishedAt}
	if j.Status == "failed" || j.Status == "interrupted" {
		result.Error = "Action did not complete; inspect the instance before retrying."
	}
	return result
}
func safeManagedWorkflow(j customerJob) managedWorkflow {
	result := managedWorkflow{ID: j.ID, Status: j.Status, Phase: j.Phase, Attempts: j.Attempts, Retryable: j.Status == "failed" || j.Status == "interrupted"}
	if result.Retryable {
		result.Error = "Customer creation did not complete; retry the existing workflow."
	}
	return result
}
func (a *centralAPI) customerJobs() ([]customerJob, error) {
	entries, err := os.ReadDir(a.customerDir())
	if err != nil {
		return nil, err
	}
	jobs := []customerJob{}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var j customerJob
		if decode(filepath.Join(a.customerDir(), entry.Name()), &j) != nil {
			return nil, errors.New("customer workflow storage invalid")
		}
		jobs = append(jobs, j)
	}
	sort.Slice(jobs, func(i, j int) bool { return jobs[i].CreatedAt.Before(jobs[j].CreatedAt) })
	return jobs, nil
}

// Inventory reads atomic registry/journal files with the API mutex held. It remains
// available while a worker owns the mutation lock; commands recheck state and ownership.
func (a *centralAPI) managementInventory() (managementInventory, error) {
	result := managementInventory{Schema: 1, Busy: a.backup.active, Customers: []managedCustomer{}, Instances: []managedInstance{}, Actions: []managedAction{}}
	catalog, err := a.loadCatalog()
	if err != nil {
		return result, err
	}
	workflows, err := a.customerJobs()
	if err != nil {
		return result, err
	}
	items := map[string]*managedInstance{}
	for _, j := range workflows {
		item := &managedInstance{SiteID: j.Input.SiteID, CustomerRef: j.Input.CustomerRef, Name: j.Input.Brief.Name, Email: j.Input.Email, PackageID: j.Input.PackageID, TemplateID: j.Template.ID, RecordStatus: "not_provisioned", Modules: []string{}, Actions: []managedAction{}}
		item.Workflow = &managedWorkflow{ID: j.ID, Status: j.Status, Phase: j.Phase, Attempts: j.Attempts, Retryable: j.Status == "failed" || j.Status == "interrupted"}
		if item.Workflow.Retryable {
			item.Workflow.Error = "Customer creation did not complete; retry the existing workflow."
		}
		if j.Result != nil {
			item.PreviewURL = j.Result.PreviewURL
			item.BuilderURL = j.Result.BuilderURL
		}
		items[item.SiteID] = item
	}
	paths, err := filepath.Glob(filepath.Join(a.backup.engine.Root, "sites", "*", "state.json"))
	if err != nil {
		return result, err
	}
	for _, path := range paths {
		site := filepath.Base(filepath.Dir(path))
		if !sitePattern.MatchString(site) {
			continue
		}
		dir, err := ownedDir(a.backup.engine.Root, site)
		if err != nil {
			return result, err
		}
		var saved State
		if decode(filepath.Join(dir, "state.json"), &saved) != nil || saved.Request.SiteID != site {
			return result, errors.New("instance registry invalid")
		}
		item := items[site]
		if item == nil {
			item = &managedInstance{SiteID: site, CustomerRef: saved.Request.CustomerRef, Name: saved.Request.BrandName, Modules: []string{}, Actions: []managedAction{}}
			items[site] = item
		}
		item.RecordStatus = saved.Status
		item.Step = saved.Step
		item.ReleaseID = saved.Release.ID
		item.Modules = instanceModules(&saved)
		item.PackageID = saved.Request.CatalogPackageID
		if item.PackageID == "" {
			item.PackageID = saved.Request.Package
		}
		base := fmt.Sprintf("http://%s.localhost:%d", site, saved.Request.Port)
		item.StorefrontURL = base + "/"
		item.AdminURL = base + "/admin"
		item.PackageChange = a.packageModuleStatus(site)
		if item.PackageChange != nil {
			item.RecordStatus = "inspection_required"
			continue
		}
		item.Update, err = a.backup.engine.managedUpdateStatus(site)
		if err != nil {
			item.RecordStatus = "inspection_required"
			continue
		}
		if _, _, err = a.backup.engine.load(site); err != nil || saved.RelocatedTo != "" {
			item.RecordStatus = "inspection_required"
			if item.Update != nil && item.Update.Active && result.Busy {
				item.RecordStatus = "updating"
			}
			continue
		}
		var checked storedObservation
		if decode(filepath.Join(a.dir, "management-health", site+".json"), &checked) == nil && checked.Fingerprint == saved.Fingerprint {
			observation := checked.Observation
			item.Observation = &observation
		}
		item.CanInspect = contains(saved.Completed, "health")
		item.CanStart = item.CanInspect && (saved.Status == "stopped" || (item.Observation != nil && item.Observation.Runtime == "stopped"))
		item.CanStop = item.CanInspect && !item.CanStart
		item.CanSetup = item.Workflow != nil && item.Workflow.Status == "complete" && item.Email != "" && item.Observation != nil && item.Observation.Healthy && item.Observation.SetupRequired != nil && *item.Observation.SetupRequired
	}
	entries, err := os.ReadDir(filepath.Join(a.dir, "jobs"))
	if err != nil {
		return result, err
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var j centralJob
		if decode(filepath.Join(a.dir, "jobs", entry.Name()), &j) != nil {
			return result, errors.New("action storage invalid")
		}
		action := safeManagedAction(j)
		result.Actions = append(result.Actions, action)
		if item := items[action.SiteID]; item != nil {
			item.Actions = append(item.Actions, action)
		}
	}
	sort.Slice(result.Actions, func(i, j int) bool { return result.Actions[i].CreatedAt.After(result.Actions[j].CreatedAt) })
	if len(result.Actions) > 100 {
		result.Actions = result.Actions[:100]
	}
	customers := map[string]*managedCustomer{}
	siteIDs := make([]string, 0, len(items))
	for id := range items {
		siteIDs = append(siteIDs, id)
	}
	sort.Strings(siteIDs)
	for _, id := range siteIDs {
		item := items[id]
		if item.CustomerRef == "" {
			item.CustomerRef = "unassigned-" + item.SiteID
		}
		item.CustomerID = managementCustomerID(item.CustomerRef)
		if item.Name == "" {
			item.Name = item.CustomerRef
		}
		if p, ok := catalog.Packages[item.PackageID]; ok {
			item.PackageName = p.Name
			item.TrialDays = p.TrialDays
		} else {
			item.PackageName = item.PackageID
		}
		sort.Slice(item.Actions, func(i, j int) bool { return item.Actions[i].CreatedAt.After(item.Actions[j].CreatedAt) })
		if len(item.Actions) > 20 {
			item.Actions = item.Actions[:20]
		}
		sub, err := a.subscriptions.get(item.SiteID)
		if err != nil {
			return result, err
		}
		if sub != nil {
			item.PackageID = sub.PackageID
			if p, ok := catalog.Packages[sub.PackageID]; ok {
				item.PackageName = p.Name
			} else {
				item.PackageName = sub.PackageID
			}
			view := sub.view(a.subscriptionNow())
			item.Subscription = &view
			if !view.Entitled {
				item.CanStart = false
				item.CanSetup = false
			}
		}
		result.Instances = append(result.Instances, *item)
		customer := customers[item.CustomerID]
		if customer == nil {
			customer = &managedCustomer{ID: item.CustomerID, Ref: item.CustomerRef, Name: item.Name, Email: item.Email, SiteIDs: []string{}}
			customers[item.CustomerID] = customer
		}
		if item.Email != "" && customer.Email == "" {
			customer.Email = item.Email
		}
		customer.SiteIDs = append(customer.SiteIDs, item.SiteID)
	}
	for _, customer := range customers {
		sort.Strings(customer.SiteIDs)
		result.Customers = append(result.Customers, *customer)
	}
	sort.Slice(result.Instances, func(i, j int) bool { return result.Instances[i].SiteID < result.Instances[j].SiteID })
	sort.Slice(result.Customers, func(i, j int) bool { return result.Customers[i].ID < result.Customers[j].ID })
	return result, nil
}
func (a *centralAPI) inspectManaged(ctx context.Context, site string) (managedObservation, error) {
	e := a.backup.engine
	unlock, err := e.lock()
	if err != nil {
		return managedObservation{}, err
	}
	defer unlock()
	state, dir, err := e.load(site)
	if err != nil {
		return managedObservation{}, err
	}
	if state.RelocatedTo != "" {
		return managedObservation{}, errors.New("instance relocated")
	}
	if err = e.checkOwner(ctx, state, dir); err != nil {
		return managedObservation{}, err
	}
	observation := managedObservation{CheckedAt: time.Now().UTC(), Runtime: "unavailable"}
	raw, inspectErr := e.Runner.Run(ctx, []string{"inspect", "--format", "{{json .State}}", projectName(site, dir) + "-app"})
	var runtime struct {
		Running *bool  `json:"Running"`
		Status  string `json:"Status"`
	}
	if inspectErr != nil || json.Unmarshal(raw, &runtime) != nil || runtime.Running == nil {
		observation.Error = "Runtime status could not be verified."
	} else if !*runtime.Running {
		observation.Runtime = "stopped"
	} else {
		observation.Runtime = "running"
		raw, err = e.Runner.Run(ctx, compose(state, dir, "exec", "-T", "app", "php", "/opt/provision/bridge.php", "health"))
		var health struct {
			OK            bool  `json:"ok"`
			SetupRequired *bool `json:"setup_required"`
		}
		if err != nil || json.Unmarshal(raw, &health) != nil || !health.OK || health.SetupRequired == nil {
			observation.Error = "Platform health or setup status could not be verified."
		} else {
			observation.Healthy = true
			observation.SetupRequired = health.SetupRequired
		}
	}
	if err = atomicJSON(filepath.Join(a.dir, "management-health", site+".json"), storedObservation{Fingerprint: state.Fingerprint, Observation: observation}); err != nil {
		return observation, err
	}
	return observation, nil
}

type managementCapture struct {
	headers http.Header
	status  int
	bytes.Buffer
}

func (c *managementCapture) Header() http.Header    { return c.headers }
func (c *managementCapture) WriteHeader(status int) { c.status = status }
func (c *managementCapture) Write(data []byte) (int, error) {
	if c.status == 0 {
		c.status = 200
	}
	return c.Buffer.Write(data)
}
func (a *centralAPI) dispatchManagement(w http.ResponseWriter, r *http.Request, path string, input any, workflow bool) {
	raw, _ := json.Marshal(input)
	if input == nil {
		raw = nil
	}
	next := r.Clone(r.Context())
	next.URL.Path = path
	next.Body = io.NopCloser(bytes.NewReader(raw))
	next.ContentLength = int64(len(raw))
	next.Header = next.Header.Clone()
	next.Header.Set("Content-Type", "application/json")
	if !workflow {
		next.Header.Set("Idempotency-Key", "management-"+r.Header.Get("Idempotency-Key"))
	}
	capture := &managementCapture{headers: make(http.Header)}
	a.ServeHTTP(capture, next)
	if capture.status != 200 && capture.status != 202 {
		var errorBody struct {
			Error string `json:"error"`
		}
		_ = json.Unmarshal(capture.Bytes(), &errorBody)
		if errorBody.Error == "" {
			errorBody.Error = "Management action could not be accepted"
		}
		apiError(w, capture.status, errorBody.Error)
		return
	}
	if workflow {
		var status managedWorkflow
		if json.Unmarshal(capture.Bytes(), &status) != nil {
			apiError(w, 502, "invalid workflow response")
			return
		}
		status.Retryable = status.Status == "failed" || status.Status == "interrupted"
		apiJSON(w, capture.status, map[string]any{"kind": "workflow", "job": status})
		return
	}
	var j centralJob
	if json.Unmarshal(capture.Bytes(), &j) != nil {
		apiError(w, 502, "invalid action response")
		return
	}
	apiJSON(w, capture.status, map[string]any{"kind": "action", "job": safeManagedAction(j)})
}
func (a *centralAPI) managementHTTP(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/v1/management/") {
		return false
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters are not supported")
		return true
	}
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/management/"), "/")
	if a.managementPreviewHTTP(w, r, parts) {
		return true
	}
	if a.managementCreationHTTP(w, r, parts) {
		return true
	}
	if a.managementUpdatesHTTP(w, r, parts) {
		return true
	}
	if r.Method == http.MethodGet && len(parts) == 2 && parts[0] == "workflows" {
		if !apiJobPattern.MatchString(parts[1]) {
			apiError(w, 404, "workflow not found")
			return true
		}
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		var workflow customerJob
		if decode(filepath.Join(a.customerDir(), parts[1]+".json"), &workflow) != nil {
			apiError(w, 404, "workflow not found")
			return true
		}
		apiJSON(w, 200, safeManagedWorkflow(workflow))
		return true
	}
	if r.Method == http.MethodGet && len(parts) == 2 && parts[0] == "jobs" {
		if !apiJobPattern.MatchString(parts[1]) {
			apiError(w, 404, "job not found")
			return true
		}
		a.backup.mu.Lock()
		defer a.backup.mu.Unlock()
		var job centralJob
		if decode(filepath.Join(a.dir, "jobs", parts[1]+".json"), &job) != nil {
			apiError(w, 404, "job not found")
			return true
		}
		apiJSON(w, 200, safeManagedAction(job))
		return true
	}
	a.backup.mu.Lock()
	inventory, err := a.managementInventory()
	a.backup.mu.Unlock()
	if err != nil {
		apiError(w, 409, "management inventory unavailable; retry after current operation")
		return true
	}
	if r.Method == http.MethodGet {
		if len(parts) == 1 {
			switch parts[0] {
			case "overview":
				apiJSON(w, 200, inventory)
				return true
			case "customers":
				apiJSON(w, 200, map[string]any{"customers": inventory.Customers})
				return true
			case "instances":
				apiJSON(w, 200, map[string]any{"instances": inventory.Instances})
				return true
			}
		}
		if len(parts) == 2 && parts[0] == "customers" && managedCustomerID.MatchString(parts[1]) {
			for _, customer := range inventory.Customers {
				if customer.ID == parts[1] {
					sites := []managedInstance{}
					for _, site := range inventory.Instances {
						if site.CustomerID == customer.ID {
							sites = append(sites, site)
						}
					}
					apiJSON(w, 200, map[string]any{"customer": customer, "instances": sites})
					return true
				}
			}
		}
		if len(parts) == 2 && parts[0] == "instances" {
			for _, site := range inventory.Instances {
				if site.SiteID == parts[1] {
					apiJSON(w, 200, site)
					return true
				}
			}
		}
		apiError(w, 404, "management resource not found")
		return true
	}
	if r.Method != http.MethodPost || len(parts) != 3 || parts[0] != "instances" || !sitePattern.MatchString(parts[1]) {
		apiError(w, 404, "management action not found")
		return true
	}
	var selected *managedInstance
	for i := range inventory.Instances {
		if inventory.Instances[i].SiteID == parts[1] {
			selected = &inventory.Instances[i]
			break
		}
	}
	if selected == nil {
		apiError(w, 404, "instance not found")
		return true
	}
	if r.ContentLength != 0 {
		body, readErr := io.ReadAll(io.LimitReader(r.Body, 1))
		if readErr != nil || len(body) > 0 {
			apiError(w, 400, "management actions do not accept a request body")
			return true
		}
	}
	key := r.Header.Get("Idempotency-Key")
	if !apiKeyPattern.MatchString(key) || len(key) > 100 {
		apiError(w, 400, "valid Idempotency-Key required")
		return true
	}
	switch parts[2] {
	case "inspect", "start", "stop":
		if selected.RecordStatus == "inspection_required" {
			apiError(w, 409, "instance requires update recovery before lifecycle actions")
			return true
		}
		a.dispatchManagement(w, r, "/v1/central/jobs", centralRequest{Operation: parts[2], SiteID: selected.SiteID}, false)
	case "retry":
		if selected.Workflow == nil {
			apiError(w, 409, "no customer workflow available for retry")
			return true
		}
		a.dispatchManagement(w, r, "/v1/customers/"+selected.Workflow.ID+"/retry", nil, true)
	case "setup-link":
		a.managementSetupLink(w, r, *selected)
	default:
		apiError(w, 404, "management action not found")
	}
	return true
}
func (a *centralAPI) managementSetupLink(w http.ResponseWriter, r *http.Request, site managedInstance) {
	if err := a.subscriptionAllows(site.SiteID); err != nil {
		apiError(w, 409, err.Error())
		return
	}
	if site.Workflow == nil || site.Workflow.Status != "complete" || site.Email == "" {
		apiError(w, 409, "completed customer workflow with an owner email is required")
		return
	}
	a.backup.mu.Lock()
	if a.backup.active {
		a.backup.mu.Unlock()
		apiError(w, 409, "another job is running")
		return
	}
	a.backup.active = true
	a.backup.workers.Add(1)
	a.backup.mu.Unlock()
	defer func() { a.backup.mu.Lock(); a.backup.active = false; a.backup.mu.Unlock(); a.backup.workers.Done() }()
	observation, err := a.inspectManaged(r.Context(), site.SiteID)
	if err != nil || !observation.Healthy || observation.SetupRequired == nil {
		apiError(w, 409, "start and verify the instance before issuing setup access")
		return
	}
	if !*observation.SetupRequired {
		apiError(w, 409, "customer setup is already complete; use the normal sign-in or password recovery flow")
		return
	}
	raw, err := a.backup.engine.Access(r.Context(), site.SiteID, "setup-link", site.Email)
	if err != nil {
		apiError(w, 409, "setup link could not be issued")
		return
	}
	var access struct {
		SetupURL  string `json:"setup_url"`
		Email     string `json:"email"`
		ExpiresAt string `json:"expires_at"`
	}
	if json.Unmarshal(raw, &access) != nil || access.SetupURL == "" || access.Email != site.Email {
		apiError(w, 502, "invalid setup response")
		return
	}
	apiJSON(w, 200, access)
}
