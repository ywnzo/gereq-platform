package pr

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"gereq.dev/gereq-cli/internal/cursoragent"
	"gereq.dev/gereq-cli/internal/gitlab"
	"github.com/joho/godotenv"
)

type Options struct {
	Base     string
	Draft    bool
	DryRun   bool
	Fast     bool
	Push     bool
	NoAgent  bool
	Title    string
	Body     string
	OpenURL  bool
}

type Draft struct {
	Title string `json:"title"`
	Body  string `json:"body"`
}

func Run(root string, opt Options) error {
	_ = godotenv.Load(filepath.Join(root, ".env"))

	if opt.Base == "" {
		opt.Base = detectBase(root)
	}
	branch, err := gitOut(root, "rev-parse", "--abbrev-ref", "HEAD")
	if err != nil {
		return err
	}
	branch = strings.TrimSpace(branch)
	if branch == "" || branch == "HEAD" {
		return fmt.Errorf("detached HEAD — checkout a branch first")
	}
	if branch == opt.Base || branch == "main" || branch == "master" {
		return fmt.Errorf("current branch %q looks like the base — create a feature branch first", branch)
	}

	if opt.Push {
		fmt.Println("→ git push -u origin HEAD")
		cmd := exec.Command("git", "-C", root, "push", "-u", "origin", "HEAD")
		cmd.Stdout = os.Stdout
		cmd.Stderr = os.Stderr
		if err := cmd.Run(); err != nil {
			return fmt.Errorf("git push: %w", err)
		}
	}

	draft := Draft{Title: opt.Title, Body: opt.Body}
	if draft.Title == "" || draft.Body == "" {
		useAgent := !opt.NoAgent && cursoragent.Available()
		if !useAgent {
			if opt.NoAgent {
				fmt.Println("→ drafting without Cursor (--no-agent)")
			} else {
				fmt.Println("! Cursor CLI `agent` not on PATH — using local template draft")
			}
			d, err := localDraft(root, opt.Base, branch)
			if err != nil {
				return err
			}
			if draft.Title == "" {
				draft.Title = d.Title
			}
			if draft.Body == "" {
				draft.Body = d.Body
			}
		} else {
			fmt.Printf("→ drafting via Cursor agent (%s)\n", cursoragent.Model(opt.Fast))
			d, err := draftViaAgent(root, opt.Base, branch, opt.Fast)
			if err != nil {
				fmt.Fprintf(os.Stderr, "! Cursor agent failed (%v) — falling back to template\n", err)
				d2, err2 := localDraft(root, opt.Base, branch)
				if err2 != nil {
					return err
				}
				d = d2
			}
			if draft.Title == "" {
				draft.Title = d.Title
			}
			if draft.Body == "" {
				draft.Body = d.Body
			}
		}
	}

	draft.Title = strings.TrimSpace(draft.Title)
	draft.Body = strings.TrimSpace(draft.Body)
	if draft.Title == "" {
		return fmt.Errorf("empty PR title")
	}
	if !strings.Contains(draft.Body, "## Summary") || !strings.Contains(draft.Body, "## Test plan") {
		draft.Body = ensureGuidelineBody(draft.Body)
	}

	fmt.Println()
	fmt.Println("── title ──")
	fmt.Println(draft.Title)
	fmt.Println("── body ──")
	fmt.Println(draft.Body)
	fmt.Println("──────────")

	if opt.DryRun {
		fmt.Println("✓ dry-run (no MR created)")
		return nil
	}

	cfg := gitlab.LoadConfig(root)
	if cfg.Token == "" {
		return fmt.Errorf("GITLAB_TOKEN required to create merge request (or use --dry-run)")
	}
	project, err := detectGitLabProject(root, cfg)
	if err != nil {
		return err
	}
	mrURL, err := createMergeRequest(cfg, project, branch, opt.Base, draft, opt.Draft)
	if err != nil {
		return err
	}
	fmt.Println("✓ merge request:", mrURL)
	return nil
}

func detectBase(root string) string {
	for _, b := range []string{"main", "master"} {
		if exec.Command("git", "-C", root, "rev-parse", "--verify", b).Run() == nil {
			return b
		}
		if exec.Command("git", "-C", root, "rev-parse", "--verify", "origin/"+b).Run() == nil {
			return b
		}
	}
	return "main"
}

func draftViaAgent(root, base, branch string, fast bool) (Draft, error) {
	ctx, err := buildContext(root, base, branch)
	if err != nil {
		return Draft{}, err
	}
	prompt := `You draft a GitLab merge request for the GEREQ Platform repo.

Do NOT run git, do NOT edit files, do NOT create the MR.
Reply with ONLY a JSON object (no markdown fences, no commentary):

{"title":"short imperative summary, max ~72 chars, no trailing period","body":"markdown body"}

Body must follow these guidelines exactly:

## Summary
- 1 to 3 bullet points focusing on WHY / user impact (not a file list)
- Prefer "add" / "update" / "fix" accurately

## Test plan
- [ ] concrete checklist items a reviewer can run

Rules:
- English only for title and body
- No Co-authored-by, no AI attribution trailers
- No secrets, tokens, or .env values
- If the diff is mostly docs/tooling, say so in Summary
- Keep Test plan realistic (PHP/Bun/Docker/grq as relevant)

--- CONTEXT ---
` + ctx

	text, err := cursoragent.Ask(root, prompt, cursoragent.Model(fast))
	if err != nil {
		return Draft{}, err
	}
	raw, err := cursoragent.ExtractJSONObject(text)
	if err != nil {
		return Draft{}, err
	}
	var d Draft
	if err := json.Unmarshal(raw, &d); err != nil {
		return Draft{}, fmt.Errorf("parse draft JSON: %w", err)
	}
	if strings.TrimSpace(d.Title) == "" || strings.TrimSpace(d.Body) == "" {
		return Draft{}, fmt.Errorf("draft missing title or body")
	}
	_ = os.MkdirAll(filepath.Join(root, ".cache", "grq"), 0o775)
	_ = os.WriteFile(filepath.Join(root, ".cache", "grq", "pr-draft.json"), raw, 0o644)
	return d, nil
}

func buildContext(root, base, branch string) (string, error) {
	status, _ := gitOut(root, "status", "-sb")
	logRange := base + "...HEAD"
	if exec.Command("git", "-C", root, "rev-parse", "--verify", base).Run() != nil {
		logRange = "origin/" + base + "...HEAD"
	}
	log, _ := gitOut(root, "log", "--oneline", logRange)
	diff, _ := gitOut(root, "diff", "--stat", logRange)
	diffPatch, _ := gitOut(root, "diff", logRange)
	if len(diffPatch) > 80_000 {
		diffPatch = diffPatch[:80_000] + "\n…[truncated]…"
	}
	return fmt.Sprintf(`Branch: %s
Base: %s

## git status
%s

## commits (%s)
%s

## diff --stat
%s

## diff (truncated)
%s
`, branch, base, status, logRange, log, diff, diffPatch), nil
}

func localDraft(root, base, branch string) (Draft, error) {
	logRange := base + "...HEAD"
	if exec.Command("git", "-C", root, "rev-parse", "--verify", base).Run() != nil {
		logRange = "origin/" + base + "...HEAD"
	}
	log, _ := gitOut(root, "log", "--oneline", logRange)
	stat, _ := gitOut(root, "diff", "--stat", logRange)
	title := strings.ReplaceAll(branch, "-", " ")
	title = strings.ReplaceAll(title, "_", " ")
	if title == "" {
		title = "Update platform"
	}
	// First commit subject if available
	if lines := strings.Split(strings.TrimSpace(log), "\n"); len(lines) > 0 && lines[0] != "" {
		parts := strings.SplitN(lines[0], " ", 2)
		if len(parts) == 2 {
			title = parts[1]
		}
	}
	body := "## Summary\n"
	for _, line := range strings.Split(strings.TrimSpace(log), "\n") {
		if line == "" {
			continue
		}
		body += "- " + line + "\n"
	}
	if strings.TrimSpace(stat) != "" {
		body += "\nChanged paths (stat):\n```\n" + strings.TrimSpace(stat) + "\n```\n"
	}
	body += "\n## Test plan\n- [ ] Review diff against " + base + "\n- [ ] Run relevant checks (`php gereq` / `./bin/grq doctor` / tests as applicable)\n"
	return Draft{Title: title, Body: body}, nil
}

func ensureGuidelineBody(body string) string {
	b := strings.TrimSpace(body)
	if !strings.Contains(b, "## Summary") {
		b = "## Summary\n- " + b
	}
	if !strings.Contains(b, "## Test plan") {
		b += "\n\n## Test plan\n- [ ] Review changes\n- [ ] Smoke-test affected flows\n"
	}
	return b
}

func detectGitLabProject(root string, cfg gitlab.Config) (string, error) {
	remote, err := gitOut(root, "remote", "get-url", "origin")
	if err != nil {
		return "", fmt.Errorf("no origin remote: %w", err)
	}
	remote = strings.TrimSpace(remote)
	// git@host:group/project.git or https://host/group/project.git
	remote = strings.TrimSuffix(remote, ".git")
	if i := strings.LastIndex(remote, ":"); i >= 0 && strings.Contains(remote, "@") {
		return remote[i+1:], nil
	}
	if i := strings.Index(remote, "://"); i >= 0 {
		rest := remote[i+3:]
		if j := strings.Index(rest, "/"); j >= 0 {
			return rest[j+1:], nil
		}
	}
	return cfg.Group + "/GEREQ_PLATFORM", nil
}

func createMergeRequest(cfg gitlab.Config, project, source, target string, draft Draft, asDraft bool) (string, error) {
	enc := url.PathEscape(project)
	payload := map[string]any{
		"source_branch": source,
		"target_branch": target,
		"title":         draft.Title,
		"description":   draft.Body,
	}
	if asDraft {
		payload["title"] = "Draft: " + draft.Title
		// GitLab 16+: draft bool
		payload["draft"] = true
	}
	body, _ := json.Marshal(payload)
	req, err := http.NewRequest(http.MethodPost, cfg.URL+"/api/v4/projects/"+enc+"/merge_requests", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("PRIVATE-TOKEN", cfg.Token)
	req.Header.Set("Content-Type", "application/json")
	client := &http.Client{Timeout: 45 * time.Second}
	res, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer res.Body.Close()
	data, _ := io.ReadAll(res.Body)
	if res.StatusCode < 200 || res.StatusCode >= 300 {
		return "", fmt.Errorf("GitLab MR HTTP %d: %s", res.StatusCode, truncate(string(data), 400))
	}
	var out struct {
		WebURL string `json:"web_url"`
	}
	if err := json.Unmarshal(data, &out); err != nil {
		return "", err
	}
	return out.WebURL, nil
}

func gitOut(root string, args ...string) (string, error) {
	cmd := exec.Command("git", append([]string{"-C", root}, args...)...)
	out, err := cmd.Output()
	return string(out), err
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
