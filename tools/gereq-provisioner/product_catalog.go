package main

import (
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

type catalogTemplate struct {
	ID          string          `json:"id"`
	Name        string          `json:"name"`
	Description string          `json:"description"`
	Driver      string          `json:"driver"`
	Active      bool            `json:"active"`
	Archived    bool            `json:"archived"`
	Revision    int             `json:"revision"`
	Native      *NativeTemplate `json:"native,omitempty"`
}
type catalogPackage struct {
	Features        map[string]bool `json:"features,omitempty"`
	GenerationLimit *int            `json:"generation_limit,omitempty"`
	Modules         []string        `json:"modules,omitempty"`
	ID              string          `json:"id"`
	Name            string          `json:"name"`
	Description     string          `json:"description"`
	RuntimePackage  string          `json:"runtime_package"`
	TemplateIDs     []string        `json:"template_ids"`
	Currency        string          `json:"currency"`
	MonthlyMinor    *int64          `json:"monthly_minor"`
	YearlyMinor     *int64          `json:"yearly_minor"`
	TrialDays       int             `json:"trial_days"`
	Active          bool            `json:"active"`
	Archived        bool            `json:"archived"`
	Revision        int             `json:"revision"`
}
type productCatalog struct {
	Schema    int                        `json:"schema_version"`
	Templates map[string]catalogTemplate `json:"templates"`
	Packages  map[string]catalogPackage  `json:"packages"`
}

func defaultCatalog() productCatalog {
	return productCatalog{Schema: 1, Templates: map[string]catalogTemplate{
		"editorial-ai":     {ID: "editorial-ai", Name: "Editorial AI", Driver: "ai-editorial", Active: true, Revision: 1},
		"editorial-native": {ID: "editorial-native", Name: "Editorial", Driver: "native-editorial", Active: true, Revision: 1},
		"centered-native":  {ID: "centered-native", Name: "Centered", Driver: "native-centered", Active: true, Revision: 1},
	}, Packages: map[string]catalogPackage{"funnel-v1": {ID: "funnel-v1", Name: "Funnel", RuntimePackage: "funnel-v1", TemplateIDs: []string{"editorial-ai", "editorial-native", "centered-native"}, Currency: "CZK", TrialDays: 14, Active: true, Revision: 1}}}
}
func (a *centralAPI) loadCatalog() (productCatalog, error) {
	path := filepath.Join(a.dir, "product-catalog.json")
	var catalog productCatalog
	err := decode(path, &catalog)
	if os.IsNotExist(err) {
		catalog = defaultCatalog()
		err = atomicJSON(path, catalog)
	}
	if err != nil {
		return catalog, err
	}
	if catalog.Schema != 1 || catalog.Templates == nil || catalog.Packages == nil {
		return catalog, errors.New("invalid product catalog")
	}
	for id, t := range catalog.Templates {
		if id != t.ID || t.Revision < 1 || validateCatalogTemplate(t) != nil {
			return catalog, errors.New("invalid product catalog")
		}
	}
	for id, p := range catalog.Packages {
		if id != p.ID || p.Revision < 1 || validateCatalogPackage(p, catalog) != nil {
			return catalog, errors.New("invalid product catalog")
		}
	}
	return catalog, nil
}
func (a *centralAPI) saveCatalog(c productCatalog) error {
	raw, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	if len(raw) > 3<<20 {
		return errors.New("product catalog exceeds local size limit")
	}
	return atomicFile(filepath.Join(a.dir, "product-catalog.json"), append(raw, '\n'))
}
func validateCatalogTemplate(t catalogTemplate) error {
	if !sitePattern.MatchString(t.ID) || !plain(t.Name, 100) || len(t.Description) > 1000 || (t.Driver != "ai-editorial" && t.Driver != "native-editorial" && t.Driver != "native-centered" && t.Driver != "native-json") || (t.Active && t.Archived) {
		return errors.New("invalid template or unsupported driver")
	}
	if t.Driver == "native-json" {
		if t.Native == nil {
			return errors.New("native template blocks required")
		}
		if err := t.Native.validate(); err != nil {
			return err
		}
	} else if t.Native != nil {
		return errors.New("blocks are only valid for native-json templates")
	}
	return nil
}
func validateCatalogPackage(p catalogPackage, c productCatalog) error {
	if !sitePattern.MatchString(p.ID) || !plain(p.Name, 100) || len(p.Description) > 1000 || p.RuntimePackage != "funnel-v1" || !regexp.MustCompile(`^[A-Z]{3}$`).MatchString(p.Currency) || p.TrialDays < 0 || p.TrialDays > 365 || len(p.TemplateIDs) == 0 || len(p.TemplateIDs) > 30 || (p.Active && p.Archived) {
		return errors.New("invalid package or unsupported runtime")
	}
	if (p.MonthlyMinor != nil && *p.MonthlyMinor < 0) || (p.YearlyMinor != nil && *p.YearlyMinor < 0) || (p.MonthlyMinor != nil && *p.MonthlyMinor > 9007199254740991) || (p.YearlyMinor != nil && *p.YearlyMinor > 9007199254740991) {
		return errors.New("invalid price")
	}
	if p.GenerationLimit != nil && (*p.GenerationLimit < 0 || *p.GenerationLimit > 1000000) {
		return errors.New("invalid generation limit")
	}
	if err := validatePackageControls(p); err != nil {
		return err
	}
	seen := map[string]bool{}
	for _, id := range p.TemplateIDs {
		t, ok := c.Templates[id]
		if !ok || seen[id] || (p.Active && (!t.Active || t.Archived)) {
			return errors.New("package references unavailable or duplicate template")
		}
		seen[id] = true
	}
	return nil
}
func (a *centralAPI) catalogHTTP(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/v1/catalog/") {
		return false
	}
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/v1/catalog/"), "/")
	if len(parts) < 1 || len(parts) > 2 || (parts[0] != "templates" && parts[0] != "packages") {
		apiError(w, 404, "not found")
		return true
	}
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	c, err := a.loadCatalog()
	if err != nil {
		apiError(w, 500, "product catalog unavailable")
		return true
	}
	if len(parts) == 1 && r.Method == http.MethodGet {
		if parts[0] == "templates" {
			items := []catalogTemplate{}
			for _, v := range c.Templates {
				items = append(items, v)
			}
			sort.Slice(items, func(i, j int) bool { return items[i].ID < items[j].ID })
			apiJSON(w, 200, map[string]any{"templates": items})
		} else {
			items := []catalogPackage{}
			for _, v := range c.Packages {
				items = append(items, v)
			}
			sort.Slice(items, func(i, j int) bool { return items[i].ID < items[j].ID })
			apiJSON(w, 200, map[string]any{"packages": items})
		}
		return true
	}
	if len(parts) != 2 || !sitePattern.MatchString(parts[1]) {
		apiError(w, 404, "not found")
		return true
	}
	id := parts[1]
	if r.Method == http.MethodGet {
		if parts[0] == "templates" {
			v, ok := c.Templates[id]
			if !ok {
				apiError(w, 404, "not found")
				return true
			}
			apiJSON(w, 200, v)
		} else {
			v, ok := c.Packages[id]
			if !ok {
				apiError(w, 404, "not found")
				return true
			}
			apiJSON(w, 200, v)
		}
		return true
	}
	if a.backup.active {
		apiError(w, 409, "another job is running")
		return true
	}
	if r.Method == http.MethodDelete {
		revision, err := strconv.Atoi(r.Header.Get("If-Match"))
		if err != nil || revision < 1 {
			apiError(w, 400, "If-Match revision required")
			return true
		}
		if parts[0] == "templates" {
			t, ok := c.Templates[id]
			if !ok {
				apiError(w, 404, "not found")
				return true
			}
			if t.Revision != revision {
				apiError(w, 409, "revision conflict")
				return true
			}
			if templateReferencedByActivePackage(c, id) {
				apiError(w, 409, "template is used by an active package")
				return true
			}
			t.Active = false
			t.Archived = true
			t.Revision++
			c.Templates[id] = t
		} else {
			p, ok := c.Packages[id]
			if !ok {
				apiError(w, 404, "not found")
				return true
			}
			if p.Revision != revision {
				apiError(w, 409, "revision conflict")
				return true
			}
			p.Active = false
			p.Archived = true
			p.Revision++
			c.Packages[id] = p
		}
	} else if r.Method == http.MethodPut {
		if parts[0] == "templates" {
			var t catalogTemplate
			if !parseAPIJSONLimit(w, r, &t, 1<<20) {
				return true
			}
			old, exists := c.Templates[id]
			if t.ID != id {
				apiError(w, 400, "template ID mismatch")
				return true
			}
			if err = validateCatalogTemplate(t); err != nil {
				apiError(w, 400, err.Error())
				return true
			}
			if (!exists && t.Revision != 0) || (exists && t.Revision != old.Revision) {
				apiError(w, 409, "revision conflict")
				return true
			}
			if (!t.Active || t.Archived) && templateReferencedByActivePackage(c, id) {
				apiError(w, 409, "template is used by an active package")
				return true
			}
			if exists && t.Driver != old.Driver {
				apiError(w, 409, "template driver is immutable; create another template")
				return true
			}
			t.Revision++
			c.Templates[id] = t
		} else {
			var p catalogPackage
			if !parseAPIJSON(w, r, &p) {
				return true
			}
			old, exists := c.Packages[id]
			if p.ID != id {
				apiError(w, 400, "package ID mismatch")
				return true
			}
			if (!exists && p.Revision != 0) || (exists && p.Revision != old.Revision) {
				apiError(w, 409, "revision conflict")
				return true
			}
			if err = validateCatalogPackage(p, c); err != nil {
				apiError(w, 400, err.Error())
				return true
			}
			if err = a.validateBackendPackage(r.Context(), p); err != nil {
				apiError(w, 400, err.Error())
				return true
			}
			if exists && p.RuntimePackage != old.RuntimePackage {
				apiError(w, 409, "runtime package is immutable")
				return true
			}
			p.Revision++
			c.Packages[id] = p
		}
	} else {
		apiError(w, 405, "method not allowed")
		return true
	}
	if err = a.saveCatalog(c); err != nil {
		apiError(w, 500, "could not save product catalog")
		return true
	}
	if parts[0] == "templates" {
		apiJSON(w, 200, c.Templates[id])
	} else {
		apiJSON(w, 200, c.Packages[id])
	}
	return true
}
func templateReferencedByActivePackage(c productCatalog, id string) bool {
	for _, p := range c.Packages {
		if p.Active && !p.Archived {
			for _, v := range p.TemplateIDs {
				if v == id {
					return true
				}
			}
		}
	}
	return false
}
func (a *centralAPI) resolvePackage(r *Request) error {
	c, err := a.loadCatalog()
	if err != nil {
		return err
	}
	id := r.CatalogPackageID
	if id == "" {
		id = "funnel-v1"
	}
	p, ok := c.Packages[id]
	if !ok || !p.Active || p.Archived {
		return errors.New("package unavailable")
	}
	if r.Package != p.RuntimePackage {
		return errors.New("package runtime mismatch")
	}
	if len(r.AllowedTemplates) != 0 || r.CatalogPackageRevision != 0 || len(r.EnabledModules) != 0 {
		return errors.New("template entitlements are assigned by the service")
	}
	days := p.TrialDays
	r.TrialDays = &days
	r.CatalogPackageID = id
	r.CatalogPackageRevision = p.Revision
	r.AllowedTemplates = append([]string(nil), p.TemplateIDs...)
	r.EnabledModules = append([]string(nil), p.Modules...)
	return nil
}
func (a *centralAPI) resolveTemplate(req *centralRequest) error {
	c, err := a.loadCatalog()
	if err != nil {
		return err
	}
	state, _, err := a.backup.engine.load(req.SiteID)
	if err != nil {
		return err
	}
	assignment, err := a.templateAssignment(req.SiteID, state)
	if err != nil {
		return err
	}
	allowed := assignment.TemplateIDs
	id := req.TemplateID
	if id == "" {
		driver := "ai-editorial"
		if req.Spec != nil && req.Spec.Design == nil {
			driver = "native-" + req.Spec.Layout
		}
		for _, v := range allowed {
			if t, ok := c.Templates[v]; ok && t.Active && !t.Archived && t.Driver == driver {
				id = v
				break
			}
		}
	}
	t, ok := c.Templates[id]
	if !ok || !t.Active || t.Archived || !contains(allowed, id) {
		return errors.New("template unavailable for this instance")
	}
	if t.Driver == "native-json" {
		if req.Spec != nil && req.Spec.Design != nil {
			if err := req.Spec.Design.validatePalette(); err != nil {
				return err
			}
		}
	} else if t.Driver == "ai-editorial" {
		if req.Spec != nil && req.Spec.Design == nil {
			return errors.New("AI template requires generated design")
		}
	} else {
		if req.Spec == nil || req.Spec.Design != nil {
			return errors.New("native template requires an offline copy specification")
		}
		req.Spec.Layout = strings.TrimPrefix(t.Driver, "native-")
	}
	req.TemplateID = id
	return nil
}

type instanceTemplateAssignment struct {
	SiteID      string   `json:"site_id"`
	TemplateIDs []string `json:"template_ids"`
	Revision    int      `json:"revision"`
}

func (a *centralAPI) templateAssignment(site string, state *State) (instanceTemplateAssignment, error) {
	assignment := instanceTemplateAssignment{SiteID: site, TemplateIDs: append([]string(nil), state.Request.AllowedTemplates...)}
	sub, subErr := a.subscriptions.get(site)
	if subErr != nil {
		return assignment, subErr
	}
	if sub != nil && sub.Package != nil {
		assignment.TemplateIDs = append([]string(nil), sub.Package.TemplateIDs...)
	}
	if len(assignment.TemplateIDs) == 0 {
		assignment.TemplateIDs = []string{"editorial-ai", "editorial-native", "centered-native"}
	}
	dir := filepath.Join(a.dir, "instance-templates")
	info, err := os.Lstat(dir)
	if os.IsNotExist(err) {
		return assignment, nil
	}
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm()&0077 != 0 {
		return assignment, errors.New("template assignment storage unavailable")
	}
	var stored instanceTemplateAssignment
	err = decode(filepath.Join(dir, site+".json"), &stored)
	if os.IsNotExist(err) {
		return assignment, nil
	}
	if err != nil || stored.SiteID != site || stored.Revision < 1 || len(stored.TemplateIDs) == 0 || len(stored.TemplateIDs) > 30 {
		return assignment, errors.New("invalid template assignment")
	}
	if sub != nil && sub.Package != nil {
		filtered := []string{}
		for _, id := range stored.TemplateIDs {
			if contains(sub.Package.TemplateIDs, id) {
				filtered = append(filtered, id)
			}
		}
		stored.TemplateIDs = filtered
	}
	return stored, nil
}
func (a *centralAPI) instanceTemplatesHTTP(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/v1/instances/") || !strings.HasSuffix(r.URL.Path, "/templates") {
		return false
	}
	site := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/v1/instances/"), "/templates")
	if !sitePattern.MatchString(site) {
		apiError(w, 400, "invalid site ID")
		return true
	}
	if r.Method != http.MethodGet && r.Method != http.MethodPut {
		apiError(w, 405, "method not allowed")
		return true
	}
	var input struct {
		TemplateIDs []string `json:"template_ids"`
		Revision    int      `json:"revision"`
	}
	if r.Method == http.MethodPut && !parseAPIJSON(w, r, &input) {
		return true
	}
	a.backup.mu.Lock()
	defer a.backup.mu.Unlock()
	if r.Method == http.MethodPut && a.backup.active {
		apiError(w, 409, "another job is running")
		return true
	}
	unlock, err := a.backup.engine.lock()
	if err != nil {
		apiError(w, 409, "provisioner busy")
		return true
	}
	defer unlock()
	state, _, err := a.backup.engine.load(site)
	if err != nil {
		apiError(w, 409, "instance unavailable or requires recovery")
		return true
	}
	current, err := a.templateAssignment(site, state)
	if err != nil {
		apiError(w, 500, "template assignment unavailable")
		return true
	}
	if r.Method == http.MethodGet {
		apiJSON(w, 200, current)
		return true
	}
	if input.Revision != current.Revision {
		apiError(w, 409, "revision conflict")
		return true
	}
	if len(input.TemplateIDs) == 0 || len(input.TemplateIDs) > 30 {
		apiError(w, 400, "invalid template assignment")
		return true
	}
	c, err := a.loadCatalog()
	if err != nil {
		apiError(w, 500, "product catalog unavailable")
		return true
	}
	packageID := state.Request.CatalogPackageID
	if packageID == "" {
		packageID = state.Request.Package
	}
	eligible := append([]string(nil), state.Request.AllowedTemplates...)
	if len(eligible) == 0 {
		eligible = []string{"editorial-ai", "editorial-native", "centered-native"}
	}
	if p, ok := c.Packages[packageID]; ok && !p.Archived {
		eligible = append(eligible, p.TemplateIDs...)
	}
	sub, err := a.subscriptions.get(site)
	if err != nil {
		apiError(w, 503, "package state unavailable")
		return true
	}
	if sub != nil && sub.Package != nil {
		eligible = sub.Package.TemplateIDs
	}
	seen := map[string]bool{}
	for _, id := range input.TemplateIDs {
		t, ok := c.Templates[id]
		if !ok || !t.Active || t.Archived || seen[id] || !contains(eligible, id) {
			apiError(w, 400, "template is unavailable for this package")
			return true
		}
		seen[id] = true
	}
	dir := filepath.Join(a.dir, "instance-templates")
	if err = privateBackupDir(dir); err != nil {
		apiError(w, 500, "template assignment storage unavailable")
		return true
	}
	next := instanceTemplateAssignment{SiteID: site, TemplateIDs: input.TemplateIDs, Revision: current.Revision + 1}
	if err = atomicJSON(filepath.Join(dir, site+".json"), next); err != nil {
		apiError(w, 500, "could not save template assignment")
		return true
	}
	apiJSON(w, 200, next)
	return true
}
