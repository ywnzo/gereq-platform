package main

import (
	"context"
	"encoding/json"
	"fmt"
	"path/filepath"
	"strings"
	"testing"
)

func exampleNativeTemplate() NativeTemplate {
	field := func(name, control string) NativeBlock {
		return NativeBlock{Type: "form_field", Payload: map[string]any{"name": name, "control": control, "label": name, "required": 1}}
	}
	return NativeTemplate{Blocks: []NativeBlock{{Type: "layout", Payload: map[string]any{"bg_color": "light-background", "children": []NativeBlock{
		{Type: "heading", Payload: map[string]any{"level": 1, "text": "{{headline}}", "text_color": "ink"}},
		{Type: "text", Payload: map[string]any{"body": "{{intro}}"}},
		{Type: "cta", Payload: map[string]any{"label": "{{cta}}", "url": "#old"}},
		{Type: "form", Payload: map[string]any{"action": "site_inquiries.create", "children": []NativeBlock{field("name", "text"), field("email", "email"), field("details", "textarea")}}},
	}}}}}
}
func TestNativeTemplatePersonalizesAndRewiresForm(t *testing.T) {
	tmpl := exampleNativeTemplate()
	if err := tmpl.validate(); err != nil {
		t.Fatal(err)
	}
	for _, goal := range []string{"inquiry", "booking"} {
		b := sampleBrief()
		b.Goal = goal
		if goal == "booking" {
			b.BookingURL = "https://example.test/book"
		}
		s := sampleSpec()
		art, err := compileNativeTemplate(b, s, tmpl, "gen-0123456789abcdef01234567")
		if err != nil {
			t.Fatal(err)
		}
		raw, _ := json.Marshal(art)
		str := string(raw)
		if strings.Contains(str, "{{") || !strings.Contains(str, s.Headline) || !strings.Contains(str, s.Intro) {
			t.Fatal("copy was not personalized")
		}
		if goal == "inquiry" {
			if !strings.Contains(str, "site_inquiries.create") || !strings.Contains(str, "#cms-field-") || !strings.Contains(str, `"name":"phone"`) {
				t.Fatal("form not connected")
			}
		} else if strings.Contains(str, "site_inquiries.create") || !strings.Contains(str, b.BookingURL) {
			t.Fatal("booking conversion failed")
		}
	}
}
func TestNativeTemplateRejectsUnsafeOrIncompatibleBlocks(t *testing.T) {
	cases := []func(*NativeTemplate){
		func(t *NativeTemplate) { t.Blocks[0].Type = "html" },
		func(t *NativeTemplate) { t.Blocks[0].Payload["custom_css"] = "body{}" },
		func(t *NativeTemplate) { t.Blocks[0].Payload["nested"] = map[string]any{"custom_css": "body{}"} },
		func(t *NativeTemplate) {
			children := t.Blocks[0].Payload["children"].([]NativeBlock)
			children[1].Payload["body"] = "{{intro}} {{ UNKNOWN }}"
		},
		func(t *NativeTemplate) { t.Blocks[0].Payload["bg_color"] = "#ffffff" },
		func(t *NativeTemplate) {
			children := t.Blocks[0].Payload["children"].([]NativeBlock)
			children[0].Payload["text"] = "<script>{{headline}}</script>"
		},
		func(t *NativeTemplate) {
			children := t.Blocks[0].Payload["children"].([]NativeBlock)
			children[1].Payload["body"] = "{{unknown}}"
		},
		func(t *NativeTemplate) {
			children := t.Blocks[0].Payload["children"].([]NativeBlock)
			children[3].Payload["action"] = "email.send"
		},
	}
	for i, change := range cases {
		tmpl := exampleNativeTemplate()
		change(&tmpl)
		if err := tmpl.validate(); err == nil {
			t.Fatalf("case %d accepted", i)
		}
	}
}
func TestNativeTemplateCatalogAndGenerationJob(t *testing.T) {
	a, req, release := centralFixture(t)
	tmpl := catalogTemplate{ID: "custom-native", Name: "Custom native", Driver: "native-json", Active: true, Native: ptrNative(exampleNativeTemplate())}
	if code := catalogCall(t, a, "PUT", "/v1/catalog/templates/custom-native", tmpl, 0); code != 200 {
		t.Fatal(code)
	}
	c, err := a.loadCatalog()
	if err != nil {
		t.Fatal(err)
	}
	pkg := c.Packages["funnel-v1"]
	pkg.TemplateIDs = append(pkg.TemplateIDs, tmpl.ID)
	if code := catalogCall(t, a, "PUT", "/v1/catalog/packages/funnel-v1", pkg, 0); code != 200 {
		t.Fatal(code)
	}
	if err = a.resolvePackage(&req); err != nil {
		t.Fatal(err)
	}
	if _, err = a.backup.engine.Create(context.Background(), req, release); err != nil {
		t.Fatal(err)
	}
	brief := sampleBrief()
	spec := sampleSpec()
	input := centralRequest{Operation: "generate", SiteID: req.SiteID, Brief: &brief, Spec: &spec, TemplateID: tmpl.ID}
	raw, _ := json.Marshal(input)
	w := centralReq(t, a, "POST", "/v1/central/jobs", string(raw), "native-test-1234")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	var job centralJob
	if err = json.Unmarshal(w.Body.Bytes(), &job); err != nil {
		t.Fatal(err)
	}
	a.backup.workers.Wait()
	w = centralReq(t, a, "GET", "/v1/central/jobs/"+job.ID, "", "")
	if err = json.Unmarshal(w.Body.Bytes(), &job); err != nil {
		t.Fatal(err)
	}
	if job.Status != "complete" {
		t.Fatal(job.Status, job.Error)
	}
	result, ok := job.Result.(map[string]any)
	if !ok {
		t.Fatal("missing generation result")
	}
	id, _ := result["generation_id"].(string)
	w = centralReq(t, a, "GET", "/v1/generations/"+req.SiteID+"/"+id, "", "")
	if w.Code != 200 || !strings.Contains(w.Body.String(), spec.Headline) {
		t.Fatal(w.Code, w.Body.String())
	}
	// Updating the template must change generation identity, not reuse the old artifact.
	tmpl.Revision = 1
	children := tmpl.Native.Blocks[0].Payload["children"].([]NativeBlock)
	children[1].Payload["body"] = "New {{intro}}"
	if code := catalogCall(t, a, "PUT", "/v1/catalog/templates/custom-native", tmpl, 0); code != 200 {
		t.Fatal(code)
	}
	raw, _ = json.Marshal(input)
	w = centralReq(t, a, "POST", "/v1/central/jobs", string(raw), "native-test-5678")
	if w.Code != 202 {
		t.Fatal(w.Code, w.Body.String())
	}
	if err = json.Unmarshal(w.Body.Bytes(), &job); err != nil {
		t.Fatal(err)
	}
	a.backup.workers.Wait()
	w = centralReq(t, a, "GET", "/v1/central/jobs/"+job.ID, "", "")
	if err = json.Unmarshal(w.Body.Bytes(), &job); err != nil {
		t.Fatal(err)
	}
	if job.Status != "complete" {
		t.Fatal(job.Error)
	}
	updated := job.Result.(map[string]any)["generation_id"].(string)
	if updated == id {
		t.Fatal("changed template reused stale artifact")
	}
}
func ptrNative(t NativeTemplate) *NativeTemplate { return &t }

func TestNativeTemplateAcceptsRealEditorialBuilderTree(t *testing.T) {
	var input struct {
		Data struct {
			Blocks []NativeBlock `json:"blocks"`
		} `json:"data"`
	}
	if err := json.Unmarshal(approvedEditorial, &input); err != nil {
		t.Fatal(err)
	}
	var transform func([]NativeBlock) ([]NativeBlock, error)
	transform = func(blocks []NativeBlock) ([]NativeBlock, error) {
		for i := range blocks {
			b := &blocks[i]
			if b.Type == "heading" && b.Payload["level"] == float64(1) {
				b.Payload["text"] = "{{headline}}"
			}
			if b.Type == "text" && strings.Contains(fmt.Sprint(b.Payload["body"]), "Máte nápad") {
				b.Payload["body"] = "{{intro}}"
			}
			if b.Type == "cta" {
				b.Payload["label"] = "{{cta}}"
			}
			if b.Type == "image" {
				b.Payload["url"] = "{{image_url}}"
			}
			if b.Type == "form" && b.Payload["submit_text_color"] == "#ffffff" {
				b.Payload["submit_text_color"] = "light-background"
			}
			children, err := nativeTemplateChildren(b.Payload)
			if err != nil {
				return nil, err
			}
			if len(children) > 0 {
				children, err = transform(children)
				if err != nil {
					return nil, err
				}
				b.Payload["children"] = children
			}
		}
		return blocks, nil
	}
	blocks, err := transform(input.Data.Blocks)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := NativeTemplate{Blocks: blocks}
	if err = tmpl.validate(); err != nil {
		t.Fatal(err)
	}
	if _, err = compileNativeTemplate(sampleBrief(), sampleSpec(), tmpl, "gen-0123456789abcdef01234567"); err != nil {
		t.Fatal(err)
	}
	a, _, _ := centralFixture(t)
	entry := catalogTemplate{ID: "editorial-export", Name: "Editorial export", Driver: "native-json", Active: true, Native: &tmpl}
	if code := catalogCall(t, a, "PUT", "/v1/catalog/templates/editorial-export", entry, 0); code != 200 {
		t.Fatal("CMS export rejected by catalog", code)
	}
}

func TestAssignNewNativeTemplateToExistingInstance(t *testing.T) {
	a, req, release := centralFixture(t)
	if err := a.resolvePackage(&req); err != nil {
		t.Fatal(err)
	}
	state, err := a.backup.engine.Create(context.Background(), req, release)
	if err != nil {
		t.Fatal(err)
	}
	before := state.Fingerprint
	tmpl := catalogTemplate{ID: "fresh-native", Name: "Fresh", Driver: "native-json", Active: true, Native: ptrNative(exampleNativeTemplate())}
	if code := catalogCall(t, a, "PUT", "/v1/catalog/templates/fresh-native", tmpl, 0); code != 200 {
		t.Fatal(code)
	}
	c, err := a.loadCatalog()
	if err != nil {
		t.Fatal(err)
	}
	pkg := c.Packages["funnel-v1"]
	pkg.TemplateIDs = append(pkg.TemplateIDs, tmpl.ID)
	if code := catalogCall(t, a, "PUT", "/v1/catalog/packages/funnel-v1", pkg, 0); code != 200 {
		t.Fatal(code)
	}
	body := `{"template_ids":["fresh-native"],"revision":0}`
	w := centralReq(t, a, "PUT", "/v1/instances/"+req.SiteID+"/templates", body, "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	w = centralReq(t, a, "PUT", "/v1/instances/"+req.SiteID+"/templates", body, "")
	if w.Code != 409 {
		t.Fatal("stale assignment accepted", w.Code)
	}
	input := centralRequest{SiteID: req.SiteID, TemplateID: tmpl.ID, Brief: ptrBrief(sampleBrief()), Spec: ptrSpec(sampleSpec())}
	if err = a.resolveTemplate(&input); err != nil {
		t.Fatal(err)
	}
	state, _, err = a.backup.engine.load(req.SiteID)
	if err != nil || state.Fingerprint != before {
		t.Fatal("instance state changed", err)
	}
}
func ptrBrief(b Brief) *Brief          { return &b }
func ptrSpec(s FunnelSpec) *FunnelSpec { return &s }

type templateFakeProvider struct{ calls int }

func (p *templateFakeProvider) Generate(_ context.Context, _ Brief) (FunnelSpec, error) {
	p.calls++
	s := sampleSpec()
	s.Design = sampleDesign()
	return s, nil
}
func TestNativeTemplateUsesProviderCopyAndPalette(t *testing.T) {
	root := t.TempDir()
	briefPath := filepath.Join(root, "brief.json")
	saveTestJSON(t, briefPath, sampleBrief())
	provider := &templateFakeProvider{}
	tmpl := exampleNativeTemplate()
	result, err := generateWithNativeTemplate(context.Background(), root, "customer-one", briefPath, "", provider, "test-model", &tmpl)
	if err != nil {
		t.Fatal(err)
	}
	var artifact FunnelArtifact
	if err = decode(result.Artifact, &artifact); err != nil {
		t.Fatal(err)
	}
	if provider.calls != 1 || len(artifact.Palette) != 8 || artifact.Palette["storefront_mode"] != "light" {
		t.Fatal("provider copy or palette missing")
	}
	raw, _ := json.Marshal(artifact)
	if !strings.Contains(string(raw), sampleSpec().Headline) {
		t.Fatal("provider copy missing")
	}
}
