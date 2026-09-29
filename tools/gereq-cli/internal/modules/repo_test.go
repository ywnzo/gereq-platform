package modules

import (
	"testing"

	"gereq.dev/gereq-cli/internal/gitlab"
)

func TestProjectPathCandidatesPrefersRepository(t *testing.T) {
	cfg := gitlab.Config{Prefix: "module-"}
	m := LocalModule{ID: "webplus_ai", Repository: "gereq/module-webplus-ai"}
	got := projectPathCandidates(cfg, m)
	if len(got) < 1 || got[0] != "module-webplus-ai" {
		t.Fatalf("first candidate = %v, want module-webplus-ai", got)
	}
}

func TestMatchProjectUnderscoreFallback(t *testing.T) {
	cfg := gitlab.Config{Prefix: "module-"}
	m := LocalModule{ID: "webplus_ai"}
	set := map[string]bool{"module-webplus-ai": true}
	repo, ok := matchProject(set, cfg, m)
	if !ok || repo != "module-webplus-ai" {
		t.Fatalf("matchProject = %q ok=%v, want module-webplus-ai true", repo, ok)
	}
}

func TestMatchProjectExactId(t *testing.T) {
	cfg := gitlab.Config{Prefix: "module-"}
	m := LocalModule{ID: "cms"}
	set := map[string]bool{"module-cms": true}
	repo, ok := matchProject(set, cfg, m)
	if !ok || repo != "module-cms" {
		t.Fatalf("matchProject = %q ok=%v, want module-cms true", repo, ok)
	}
}
