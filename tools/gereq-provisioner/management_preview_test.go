package main

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

type previewRuntime struct {
	base  Runner
	reads int
	wrong bool
}

func (p *previewRuntime) Run(ctx context.Context, args []string) ([]byte, error) {
	if strings.Contains(strings.Join(args, " "), "Read-only CLI helper embedded by the central service") {
		p.reads++
		id := args[len(args)-1]
		if p.wrong {
			id = "00000000-0000-0000-0000-000000000000"
		}
		return json.Marshal(map[string]any{"page_id": id, "title": "Draft studio", "slug": "welcome", "publication_status": "draft", "blocks": []any{map[string]any{"id": "heading-one", "type": "heading", "payload": map[string]any{"text": "Private draft"}}}, "palette": map[string]any{"storefront_mode": "light"}})
	}
	return p.base.Run(ctx, args)
}
func TestManagementPreviewUsesOwnedRunningInstanceAndCurrentNativeDraft(t *testing.T) {
	a, in, runtime := managementFixture(t)
	reader := &previewRuntime{base: runtime}
	a.backup.engine.Runner = reader
	path := "/v1/management/instances/" + in.SiteID + "/preview"
	w := centralReq(t, a, "GET", path, "", "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	var out draftPreview
	json.Unmarshal(w.Body.Bytes(), &out)
	if out.SiteID != in.SiteID || out.Publication != "draft" || len(out.Blocks) != 1 || out.Storefront == "" || reader.reads != 1 {
		t.Fatal(out)
	}
	if strings.Contains(w.Body.String(), in.Email) || strings.Contains(w.Body.String(), "setup_url") {
		t.Fatal("private fields leaked")
	}
	reader.wrong = true
	if w = centralReq(t, a, "GET", path, "", ""); w.Code != 409 {
		t.Fatal(w.Code)
	}
	a.backup.active = true
	before := reader.reads
	if w = centralReq(t, a, "GET", path, "", ""); w.Code != 409 || reader.reads != before {
		t.Fatal("preview bypassed active-operation guard")
	}
	a.backup.active = false
	reader.wrong = false
	managementAction(t, a, in.SiteID, "stop", "preview-stop-test-001")
	a.backup.workers.Wait()
	before = reader.reads
	if w = centralReq(t, a, "GET", path, "", ""); w.Code != 409 || reader.reads != before {
		t.Fatal("stopped instance was started for preview", w.Code)
	}
	if w = centralReq(t, a, "GET", "/v1/management/instances/not-a-site/preview", "", ""); w.Code != 404 {
		t.Fatal(w.Code)
	}
}
