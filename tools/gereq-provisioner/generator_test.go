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
	return FunnelSpec{"editorial", "Prostor pro váš další krok", "Jasnější směr začíná rozhovorem.", "Společně se podíváme na váš záměr a najdeme smysluplný další krok.", "Odeslat poptávku", "Co vám konzultace přinese", items, "Jak to probíhá", []CopyItem{{"Napište nám", "Popište nám svou situaci a téma."}, {"Probereme zadání", "Společně si upřesníme očekávání."}, {"Domluvíme další postup", "Najdeme vhodný způsob spolupráce."}}, "Na co se často ptáte", []CopyItem{{"Pro koho je konzultace?", "Pro samostatné profesionály, kteří hledají směr."}, {"Může konzultace proběhnout online?", "Ano, nabízíme online konzultace."}, {"Jak začít?", "Napište nám prostřednictvím formuláře."}}, "Pojďme probrat váš záměr", "Napište nám, co právě řešíte."}
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
	copyJSON, _ := json.Marshal(sampleSpec())
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
