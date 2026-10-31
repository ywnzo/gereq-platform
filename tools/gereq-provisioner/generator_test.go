package main

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func sampleBrief() Brief {
	return Brief{Schema: 1, IllustrationURL: "/media/00000000-0000-4000-8000-000000000000/file", Name: "Studio Jasno", Offer: "Strategic consultations", Audience: "Independent professionals", Language: "cs", Slug: "generated-funnel", Goal: "inquiry", Facts: []string{"Online consultations"}}
}
func sampleSpec() FunnelSpec {
	items := []CopyItem{{"Ujasněte si směr", "Pojmenujte to, co je pro vás důležité."}, {"Zvažte možnosti", "Prozkoumejte cesty, které dávají smysl."}, {"Udělejte další krok", "Připravte si konkrétní postup."}}
	return FunnelSpec{nil, "editorial", "Prostor pro váš další krok", "Jasnější směr začíná rozhovorem.", "Společně se podíváme na váš záměr a najdeme smysluplný další krok.", "Odeslat poptávku", "Co vám konzultace přinese", items, "Jak to probíhá", []CopyItem{{"Napište nám", "Popište nám svou situaci a téma."}, {"Probereme zadání", "Společně si upřesníme očekávání."}, {"Domluvíme další postup", "Najdeme vhodný způsob spolupráce."}}, "Na co se často ptáte", []CopyItem{{"Pro koho je konzultace?", "Pro samostatné profesionály, kteří hledají směr."}, {"Může konzultace proběhnout online?", "Ano, nabízíme online konzultace."}, {"Jak začít?", "Napište nám prostřednictvím formuláře."}}, "Pojďme probrat váš záměr", "Napište nám, co právě řešíte."}
}

func sampleDesign() *GeneratedDesign {
	p := map[string]string{"primary": "#f5c518", "secondary": "#101211", "accent": "#a88400", "light_background": "#fff6c8", "light_surface": "#fff6c8", "dark_background": "#101211", "dark_surface": "#161816"}
	node := func(kind, text, size string) DesignNode {
		return DesignNode{Kind: kind, Text: text, Background: "none", Foreground: "dark-background", Size: size, Gap: 0, Padding: 0, Children: []DesignNode{}}
	}
	section := func(children ...DesignNode) DesignNode {
		return DesignNode{Kind: "stack", Background: "light-background", Foreground: "dark-background", Size: "md", Gap: 24, Padding: 64, Children: children}
	}
	return &GeneratedDesign{Palette: p, Blocks: []DesignNode{section(node("heading", "A meaningful headline", "display"), node("cta", "Get in touch", "md")), section(node("heading", "Benefits", "lg"), node("text", "Helpful content.", "md")), section(node("form", "Send", "md"))}}
}

type fakeCopy struct {
	calls int
	err   error
}

func (p *fakeCopy) Generate(context.Context, Brief) (FunnelSpec, error) {
	p.calls++
	return sampleSpec(), p.err
}
func saveTestJSON(t *testing.T, p string, v any) {
	t.Helper()
	raw, _ := json.Marshal(v)
	if err := os.WriteFile(p, raw, 0600); err != nil {
		t.Fatal(err)
	}
}
func TestGenerationReuseIsolationAndFailure(t *testing.T) {
	root := t.TempDir()
	brief := filepath.Join(root, "brief.json")
	saveTestJSON(t, brief, sampleBrief())
	p := &fakeCopy{}
	a, err := generate(context.Background(), root, "customer-one", brief, "", p, "model-a")
	if err != nil {
		t.Fatal(err)
	}
	again, err := generate(context.Background(), root, "customer-one", brief, "", p, "model-a")
	if err != nil || !again.Reused || p.calls != 1 || again.Artifact != a.Artifact {
		t.Fatal("generation was not reused", err)
	}
	other, err := generate(context.Background(), root, "customer-two", brief, "", p, "model-a")
	if err != nil || other.JobID == a.JobID {
		t.Fatal("customer isolation", err)
	}
	info, _ := os.Stat(a.Artifact)
	if info.Mode().Perm() != 0600 {
		t.Fatal("artifact permissions")
	}
	p.err = errors.New("unavailable")
	if _, err = generate(context.Background(), root, "customer-one", brief, "", p, "other-model"); err == nil {
		t.Fatal("provider failure accepted")
	}
}
func TestCompileNativeLayoutsAndGoals(t *testing.T) {
	for _, layout := range []string{"editorial", "centered"} {
		for _, goal := range []string{"inquiry", "booking"} {
			b := sampleBrief()
			b.Goal = goal
			if goal == "booking" {
				b.BookingURL = "https://example.test/book"
			}
			s := sampleSpec()
			s.Layout = layout
			a, err := compileFunnel(b, s, "generation-test")
			if err != nil {
				t.Fatal(err)
			}
			forms, ctas, h1 := 0, 0, 0
			var walk func([]NativeBlock)
			walk = func(blocks []NativeBlock) {
				for _, v := range blocks {
					if len(v.ID) != 36 {
						t.Fatal("missing stable native ID", v.ID)
					}
					switch v.Type {
					case "form":
						forms++
						if v.Payload["action"] != "site_inquiries.create" {
							t.Fatal("wrong action")
						}
					case "cta":
						ctas++
						if goal == "booking" && v.Payload["url"] != b.BookingURL {
							t.Fatal("wrong CTA")
						}
					case "heading":
						if v.Payload["level"] == 1 || v.Payload["level"] == float64(1) {
							h1++
						}
					case "layout", "text", "form_field", "icon", "divider", "image":
					default:
						t.Fatal("unsupported native block", v.Type)
					}
					if child, ok := v.Payload["children"].([]NativeBlock); ok {
						walk(child)
					}
				}
			}
			walk(a.Blocks)
			if h1 != 1 || (goal == "inquiry" && forms != 1) || (goal == "booking" && (forms != 0 || ctas == 0)) {
				t.Fatal("invalid funnel structure", layout, goal, forms, ctas, h1)
			}
		}
	}
}
func TestRejectUnsafeAndMalformedGeneration(t *testing.T) {
	b := sampleBrief()
	s := sampleSpec()
	for _, bad := range []string{"<script>bad</script>", strings.Repeat("x", 141), ""} {
		s.Headline = bad
		if _, err := compileFunnel(b, s, "test"); err == nil {
			t.Fatal("bad copy accepted")
		}
	}
	s = sampleSpec()
	s.Layout = "custom-html"
	if _, err := compileFunnel(b, s, "test"); err == nil {
		t.Fatal("unknown layout accepted")
	}
	for _, link := range []string{"javascript:alert(1)", "http://example.test", "https://user:secret@example.test", "//example.test"} {
		b.Goal = "booking"
		b.BookingURL = link
		if b.validate() == nil {
			t.Fatal("unsafe booking link")
		}
	}
	var spec FunnelSpec
	if strictJSON([]byte(`{"css":"bad"}`), &spec) == nil {
		t.Fatal("unknown field accepted")
	}
}

type transportFunc func(*http.Request) (*http.Response, error)

func (f transportFunc) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func TestOpenAIStructuredResponseAndFailures(t *testing.T) {
	example := sampleSpec()
	example.Design = sampleDesign()
	copyJSON, _ := json.Marshal(example)
	body, _ := json.Marshal(map[string]any{"status": "completed", "output": []any{map[string]any{"type": "message", "content": []any{map[string]any{"type": "output_text", "text": string(copyJSON)}}}}})
	status := 200
	response := string(body)
	p := OpenAIProvider{Key: "test-secret", Model: "configured-model", Client: &http.Client{Transport: transportFunc(func(r *http.Request) (*http.Response, error) {
		if r.URL.String() != "https://api.openai.com/v1/responses" || r.Header.Get("Authorization") != "Bearer test-secret" {
			t.Fatal("invalid provider request")
		}
		raw, _ := io.ReadAll(r.Body)
		var v map[string]any
		json.Unmarshal(raw, &v)
		if v["store"] != false || v["model"] != "configured-model" || v["text"] == nil {
			t.Fatal("missing structured request")
		}
		return &http.Response{StatusCode: status, Body: io.NopCloser(strings.NewReader(response)), Header: make(http.Header)}, nil
	})}}
	if _, err := p.Generate(context.Background(), sampleBrief()); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{`{"status":"incomplete"}`, `{"status":"completed","output":[{"type":"message","content":[{"type":"refusal"}]}]}`, `{"status":"completed","output":[]}`} {
		response = bad
		if _, err := p.Generate(context.Background(), sampleBrief()); err == nil {
			t.Fatal("invalid provider output accepted")
		}
	}
	status = 401
	response = "test-secret"
	_, err := p.Generate(context.Background(), sampleBrief())
	if err == nil || strings.Contains(err.Error(), "test-secret") {
		t.Fatal("provider secret exposed")
	}
}

func TestEditorialGenerationSuppliesMediaAndRequiredPhone(t *testing.T) {
	root := t.TempDir()
	brief := filepath.Join(root, "brief.json")
	b := sampleBrief()
	b.IllustrationURL = ""
	saveTestJSON(t, brief, b)
	provider := &fakeCopy{}
	calls := 0
	prepare := func(context.Context) (string, error) {
		calls++
		return "/media/00000000-0000-4000-8000-000000000001/file", nil
	}
	result, err := generate(context.Background(), root, "customer-one", brief, "", provider, "model", prepare)
	if err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(result.Artifact)
	if err != nil {
		t.Fatal(err)
	}
	var artifact FunnelArtifact
	if err := json.Unmarshal(raw, &artifact); err != nil {
		t.Fatal(err)
	}
	foundPhone, foundImage := false, false
	var walk func([]NativeBlock)
	walk = func(blocks []NativeBlock) {
		for _, block := range blocks {
			if block.Type == "form_field" && block.Payload["name"] == "phone" && block.Payload["control"] == "tel" && block.Payload["required"] == float64(1) {
				foundPhone = true
			}
			if block.Type == "image" && block.Payload["url"] == "/media/00000000-0000-4000-8000-000000000001/file" {
				foundImage = true
			}
			if children, ok := block.Payload["children"].([]any); ok {
				for _, child := range children {
					raw, _ := json.Marshal(child)
					var b NativeBlock
					if json.Unmarshal(raw, &b) == nil {
						walk([]NativeBlock{b})
					}
				}
			}
		}
	}
	walk(artifact.Blocks)
	if !foundPhone || !foundImage || calls != 1 {
		t.Fatalf("phone=%t image=%t media_calls=%d", foundPhone, foundImage, calls)
	}
	if _, err = generate(context.Background(), root, "customer-one", brief, "", provider, "model", prepare); err != nil || calls != 1 {
		t.Fatalf("generation retry uploaded again: calls=%d err=%v", calls, err)
	}
}

func TestGeneratedDesignValidatesAndCompilesDifferentLayouts(t *testing.T) {
	b := sampleBrief()
	d := sampleDesign()
	first, err := compileGeneratedDesign(b, *d, "test-a")
	if err != nil {
		t.Fatal(err)
	}
	d.Blocks[1].Kind = "row"
	second, err := compileGeneratedDesign(b, *d, "test-b")
	if err != nil {
		t.Fatal(err)
	}
	if first.Blocks[1].Payload["dir"] == second.Blocks[1].Payload["dir"] {
		t.Fatal("model layout ignored")
	}
	d.Palette["light_background"] = "#111111"
	if _, err = compileGeneratedDesign(b, *d, "bad"); err == nil {
		t.Fatal("unreadable palette accepted")
	}
}

func TestGeneratedSizingDependsOnParentDirection(t *testing.T) {
	b := sampleBrief()
	d := sampleDesign()
	original := d.Blocks[0].Children
	hero := DesignNode{Kind: "stack", Background: "none", Foreground: "inherit", Size: "md", Gap: 24, Padding: 0, Children: original}
	side := DesignNode{Kind: "stack", Background: "light-surface", Foreground: "dark-background", Size: "md", Gap: 16, Padding: 24, Children: []DesignNode{{Kind: "text", Text: "A short note.", Background: "none", Foreground: "inherit", Size: "md", Children: []DesignNode{}}}}
	d.Blocks[0].Kind = "row"
	d.Blocks[0].Children = []DesignNode{hero, side}
	result, err := compileGeneratedDesign(b, *d, "sizes")
	if err != nil {
		t.Fatal(err)
	}
	heroBlocks := result.Blocks[0].Payload["children"].([]NativeBlock)
	if heroBlocks[0].Payload["flex_grow"] != "2" || heroBlocks[0].Payload["flex_basis"] != "380px" {
		t.Fatal("headline column did not receive enough width")
	}
	for _, section := range result.Blocks[1:] {
		if _, ok := section.Payload["flex_basis"]; ok {
			t.Fatal("vertical section received a flex basis")
		}
	}
	form := result.Blocks[2].Payload["children"].([]NativeBlock)[0]
	fields := form.Payload["children"].([]NativeBlock)
	if fields[0].Payload["full"] != 0 || fields[1].Payload["full"] != 0 || fields[2].Payload["full"] != 1 {
		t.Fatal("unexpected form field widths")
	}
}

func TestGeneratedCTAAndFormHaveUsableRowDefaults(t *testing.T) {
	b := sampleBrief()
	d := sampleDesign()
	d.Blocks[2].Kind = "row"
	result, err := compileGeneratedDesign(b, *d, "cta-default")
	if err != nil {
		t.Fatal(err)
	}
	hero := result.Blocks[0].Payload["children"].([]NativeBlock)
	button := hero[1]
	if button.Payload["bg_color"] != "dark-background" || button.Payload["text_color"] != "light-background" {
		t.Fatal("CTA lacks visual emphasis")
	}
	form := result.Blocks[2].Payload["children"].([]NativeBlock)[0]
	if form.Payload["flex_basis"] != "360px" {
		t.Fatal("form has no row width")
	}
}

func TestOpenAINativeTemplateRequestsCopyAndPaletteWithoutLayout(t *testing.T) {
	spec := sampleSpec()
	spec.Design = &GeneratedDesign{Palette: sampleDesign().Palette}
	p := OpenAIProvider{Key: "test-secret", Model: "configured-model", NativeTemplate: true, Client: &http.Client{Transport: transportFunc(func(r *http.Request) (*http.Response, error) {
		var request map[string]any
		if json.NewDecoder(r.Body).Decode(&request) != nil {
			t.Fatal("invalid request")
		}
		schema := request["text"].(map[string]any)["format"].(map[string]any)["schema"].(map[string]any)
		design := schema["properties"].(map[string]any)["design"].(map[string]any)
		if _, exists := design["properties"].(map[string]any)["blocks"]; exists {
			t.Error("unused layout requested for fixed native template")
		}
		if _, exists := schema["$defs"]; exists {
			t.Error("unused layout node schema retained")
		}
		raw, _ := json.Marshal(spec)
		body, _ := json.Marshal(map[string]any{"status": "completed", "output": []any{map[string]any{"type": "message", "content": []any{map[string]any{"type": "output_text", "text": string(raw)}}}}})
		return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(string(body))), Header: make(http.Header)}, nil
	})}}
	got, err := p.Generate(context.Background(), sampleBrief())
	if err != nil {
		t.Fatal(err)
	}
	if _, err = compileNativeTemplate(sampleBrief(), got, exampleNativeTemplate(), "template-copy-test"); err != nil {
		t.Fatal("valid copy/palette rejected by native compiler", err)
	}
	if err = got.Design.validate("inquiry"); err == nil {
		t.Fatal("freeform generation accepted missing layout")
	}
	spec.Design.Palette["light_background"] = "#000000"
	if _, err = p.Generate(context.Background(), sampleBrief()); err == nil {
		t.Fatal("native mode accepted an unreadable palette")
	}
}

func TestGeneratedStructureUsesOneHeroAndRequiredConversionNodes(t *testing.T) {
	for _, goal := range []string{"inquiry", "booking"} {
		d := sampleDesign()
		d.Blocks[0].Children = append(d.Blocks[0].Children, DesignNode{Kind: "heading", Text: "Another heading", Background: "none", Foreground: "inherit", Size: "display", Children: []DesignNode{}})
		var strip func([]DesignNode) []DesignNode
		strip = func(nodes []DesignNode) []DesignNode {
			out := []DesignNode{}
			for _, n := range nodes {
				if n.Kind == "cta" || n.Kind == "form" {
					continue
				}
				n.Children = strip(n.Children)
				out = append(out, n)
			}
			return out
		}
		d.Blocks = strip(d.Blocks)
		d.normalizeStructure(goal, "A headline", "Contact us")
		if err := d.validate(goal); err != nil {
			t.Fatal(goal, err)
		}
		d.Palette["primary"] = "#000000"
		if d.validate(goal) == nil {
			t.Fatal("normalization weakened contrast validation")
		}
	}
}
