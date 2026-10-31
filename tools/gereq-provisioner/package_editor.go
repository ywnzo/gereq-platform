package main

import (
	"errors"
	"net/http"
	"regexp"
)

type packageCapability struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
}

var packageFeatures = []packageCapability{
	{"website_generation", "Generování webu", "Povolit nové generování přes centrální službu."},
	{"draft_import", "Import návrhu", "Povolit uložení vygenerovaného obsahu do zákaznické instance."},
	{"instance_updates", "Aktualizace instance", "Povolit aktualizaci platformy. Obnova a vrácení verze zůstávají dostupné."},
}
var funnelModules = []string{"cms", "site_inquiries", "analytics"}

func packageFeatureEnabled(p *catalogPackage, id string) bool {
	if p == nil {
		return true
	}
	enabled, present := p.Features[id]
	return !present || enabled
}
func validatePackageControls(p catalogPackage) error {
	if len(p.Modules) > 250 {
		return errors.New("too many package modules")
	}
	seen := map[string]bool{}
	for _, id := range p.Modules {
		if !regexp.MustCompile(`^[a-z][a-z0-9_]{1,63}$`).MatchString(id) || seen[id] {
			return errors.New("invalid or duplicate package module")
		}
		seen[id] = true
	}
	if p.Active && len(p.Modules) > 0 {
		if !contains(p.Modules, "cms") {
			return errors.New("active website package requires cms")
		}

		if (packageFeatureEnabled(&p, "website_generation") || packageFeatureEnabled(&p, "draft_import")) && !contains(p.Modules, "site_inquiries") {
			return errors.New("website generation requires site_inquiries")
		}
	}

	for id := range p.Features {
		known := false
		for _, f := range packageFeatures {
			known = known || id == f.ID
		}
		if !known {
			return errors.New("unsupported package feature")
		}
	}
	if packageFeatureEnabled(&p, "draft_import") && !packageFeatureEnabled(&p, "website_generation") {
		return errors.New("draft import requires website generation")
	}
	return nil
}
func (a *centralAPI) packageFeatureAllows(site, feature string) error {
	sub, err := a.subscriptions.get(site)
	if err != nil {
		return errors.New("package entitlements unavailable")
	}
	if sub != nil && !packageFeatureEnabled(sub.Package, feature) {
		return errors.New("package feature disabled: " + feature)
	}
	return nil
}
func (a *centralAPI) packageRequestAllows(req centralRequest) error {
	if req.Operation == "update" {
		return a.packageFeatureAllows(req.SiteID, "instance_updates")
	}
	if req.Operation == "generate" {
		if err := a.packageFeatureAllows(req.SiteID, "website_generation"); err != nil {
			return err
		}
		if req.ImportDraft {
			return a.packageFeatureAllows(req.SiteID, "draft_import")
		}
	}
	return nil
}
func (a *centralAPI) packageCapabilitiesHTTP(w http.ResponseWriter, r *http.Request) bool {
	if r.URL.Path != "/v1/catalog/package-capabilities" {
		return false
	}
	if r.Method != "GET" || r.URL.RawQuery != "" {
		apiError(w, 405, "method not allowed")
		return true
	}
	capabilities, err := a.catalogCapabilities(r.Context())
	if err != nil {
		apiError(w, 503, "Katalog modulů backendu není dostupný. Ověřte nastavení GEREQ_PLATFORM_SOURCE.")
		return true
	}
	apiJSON(w, 200, capabilities)
	return true
}

func packageEditorError(source string) string {
	switch source {
	case "revision conflict":
		return "Balíček mezitím změnil někdo jiný. Znovu načtěte katalog; vaše úpravy zůstávají ve formuláři."
	case "active package requires supported funnel modules; save unsupported modules as inactive":
		return "Aktivní funnel vyžaduje moduly Obsah a stránky, Poptávky a Analytika. Jinou kombinaci uložte jako neaktivní návrh."
	case "active website package requires cms":
		return "Aktivní web vyžaduje modul Obsah a stránky."
	case "website generation requires site_inquiries":
		return "Generování formulářů vyžaduje modul Poptávky. Přidejte ho nebo vypněte generování a import."
	case "unsupported active package module":
		return "Tento modul zatím není v podporovaném runtime. Uložte kombinaci jako neaktivní návrh."
	case "unsupported package feature":
		return "Balíček obsahuje nepodporovanou funkci. Obnovte nabídku funkcí."
	case "draft import requires website generation":
		return "Import návrhu vyžaduje zapnuté generování webu."
	case "invalid price":
		return "Cena musí být nezáporná a mít nejvýše dvě desetinná místa."
	case "invalid generation limit":
		return "Limit generování musí být celé číslo od 0 do 1 000 000."
	case "package references unavailable or duplicate template":
		return "Vyberte dostupné šablony. Aktivní balíček nemůže používat neaktivní šablonu."
	case "invalid or duplicate package module":
		return "Identifikátory modulů musí být jedinečné: malá písmena, číslice a podtržítka."
	default:
		return "Balíček se nepodařilo uložit. Zkontrolujte identifikátor, název, měnu, zkušební dobu a vybrané šablony."
	}
}
