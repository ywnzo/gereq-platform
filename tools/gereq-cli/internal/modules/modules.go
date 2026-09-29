package modules

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"sync"

	"gereq.dev/gereq-cli/internal/cursoragent"
	"gereq.dev/gereq-cli/internal/gitlab"
	"gereq.dev/gereq-cli/internal/logo"
)

type LocalModule struct {
	Dir        string
	ID         string
	Version    string
	Name       string
	Repository string // optional module.php "repository" (group/project or project)
}

type CompareRow struct {
	Repo   string
	ID     string
	Dir    string
	Local  string
	GitLab string
	Status string
}

// projectPathCandidates returns GitLab project path names (no group) to try, in priority order.
// Mirrors Gereq\Module\GitLabModuleClient::projectPathFor, plus underscore→hyphen fallback
// when id is webplus_ai but the repo is module-webplus-ai.
func projectPathCandidates(cfg gitlab.Config, m LocalModule) []string {
	seen := map[string]bool{}
	var out []string
	add := func(s string) {
		s = strings.TrimSpace(s)
		if s == "" || seen[s] {
			return
		}
		seen[s] = true
		out = append(out, s)
	}
	if m.Repository != "" {
		path := strings.Trim(m.Repository, "/")
		if i := strings.LastIndex(path, "/"); i >= 0 {
			add(path[i+1:])
		} else {
			add(path)
		}
	}
	add(cfg.Prefix + m.ID)
	add(cfg.Prefix + strings.ReplaceAll(m.ID, "_", "-"))
	return out
}

func matchProject(projectSet map[string]bool, cfg gitlab.Config, m LocalModule) (string, bool) {
	cands := projectPathCandidates(cfg, m)
	for _, c := range cands {
		if projectSet[c] {
			return c, true
		}
	}
	if len(cands) > 0 {
		return cands[0], false
	}
	return cfg.Prefix + m.ID, false
}

type ScanResult struct {
	Local   []LocalModule
	GitLab  []string
	Missing []string
	Extra   []string
	Compare []CompareRow
	Err     error
}

func ScanLocal(root string) ([]LocalModule, error) {
	script := `
foreach (glob("modules/*/module.php") as $f) {
  $m = include $f;
  $dir = basename(dirname($f));
  echo $dir."|".($m["id"]??"")."|".($m["version"]??"")."|".($m["name"]??$dir)."|".($m["repository"]??"")."\n";
}
`
	cmd := exec.Command("php", "-r", script)
	cmd.Dir = root
	out, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("php scan: %w", err)
	}
	var mods []LocalModule
	sc := bufio.NewScanner(strings.NewReader(string(out)))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		parts := strings.SplitN(line, "|", 5)
		if len(parts) < 3 {
			continue
		}
		name := parts[1]
		if len(parts) >= 4 {
			name = parts[3]
		}
		repo := ""
		if len(parts) >= 5 {
			repo = parts[4]
		}
		mods = append(mods, LocalModule{
			Dir: parts[0], ID: parts[1], Version: parts[2], Name: name, Repository: repo,
		})
	}
	sort.Slice(mods, func(i, j int) bool {
		return strings.ToLower(mods[i].Dir) < strings.ToLower(mods[j].Dir)
	})
	return mods, nil
}

func RunScan(root string) ScanResult {
	cfg := gitlab.LoadConfig(root)
	local, err := ScanLocal(root)
	if err != nil {
		return ScanResult{Err: err}
	}
	if cfg.Token == "" {
		return ScanResult{Local: local, Err: fmt.Errorf("GITLAB_TOKEN missing in .env")}
	}
	gl := gitlab.New(cfg)
	projects, err := gl.ListModuleProjects()
	if err != nil {
		return ScanResult{Local: local, Err: err}
	}
	projectSet := map[string]bool{}
	for _, p := range projects {
		projectSet[p] = true
	}
	localRepos := map[string]bool{}
	var missing, extra []string
	resolved := make([]string, len(local))
	found := make([]bool, len(local))
	for i, m := range local {
		repo, ok := matchProject(projectSet, cfg, m)
		resolved[i] = repo
		found[i] = ok
		localRepos[repo] = true
		if !ok {
			missing = append(missing, fmt.Sprintf("%s (local %s %s)", repo, m.Dir, m.Version))
		}
	}
	for _, p := range projects {
		if !localRepos[p] {
			extra = append(extra, p)
		}
	}

	type tagRes struct {
		idx    int
		latest string
	}
	rows := make([]CompareRow, len(local))
	var wg sync.WaitGroup
	ch := make(chan tagRes, len(local))
	sem := make(chan struct{}, 8)
	for i, m := range local {
		wg.Add(1)
		go func(i int, m LocalModule, repo string, ok bool) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			latest := "NONE"
			if ok {
				if t, err := gl.LatestTag(repo); err == nil {
					latest = t
				}
			}
			ch <- tagRes{idx: i, latest: latest}
		}(i, m, resolved[i], found[i])
	}
	wg.Wait()
	close(ch)
	latestByIdx := map[int]string{}
	for r := range ch {
		latestByIdx[r.idx] = r.latest
	}
	for i, m := range local {
		repo := resolved[i]
		latest := latestByIdx[i]
		status := "ok"
		switch {
		case !found[i]:
			status = "MISSING"
		case latest == "NONE":
			status = "NO_TAGS"
		case latest != "v"+m.Version && latest != m.Version:
			status = "DIFF"
		}
		rows[i] = CompareRow{
			Repo: repo, ID: m.ID, Dir: m.Dir, Local: m.Version, GitLab: latest, Status: status,
		}
	}
	return ScanResult{Local: local, GitLab: projects, Missing: missing, Extra: extra, Compare: rows}
}

func PrintPlain(root string, res ScanResult) {
	cfg := gitlab.LoadConfig(root)
	fmt.Println("=== LOCAL MODULES ===")
	for _, m := range res.Local {
		fmt.Printf("%s|%s|%s|%s\n", m.Dir, m.ID, m.Version, m.Name)
	}
	fmt.Println()
	fmt.Printf("=== GITLAB PROJECTS (group=%s, prefix=%s) ===\n", cfg.Group, cfg.Prefix)
	for _, p := range res.GitLab {
		fmt.Println(p)
	}
	fmt.Println()
	fmt.Println("=== MISSING ON GITLAB ===")
	if len(res.Missing) == 0 {
		fmt.Println("(none)")
	} else {
		for _, m := range res.Missing {
			fmt.Println("MISSING:", m)
		}
	}
	fmt.Println()
	fmt.Println("=== EXTRA ON GITLAB (no local) ===")
	if len(res.Extra) == 0 {
		fmt.Println("(none)")
	} else {
		for _, e := range res.Extra {
			fmt.Println("EXTRA:", e)
		}
	}
	fmt.Println()
	fmt.Println("=== VERSION COMPARE (latest tag vs local) ===")
	for _, r := range res.Compare {
		fmt.Printf("%-28s local=%-8s gitlab=%-10s %s\n", r.Repo, r.Local, r.GitLab, r.Status)
	}
}

func Update(root string, ids []string, yes bool) error {
	res := RunScan(root)
	if res.Err != nil {
		return res.Err
	}
	cfg := gitlab.LoadConfig(root)
	want := map[string]bool{}
	for _, id := range ids {
		want[strings.ToLower(id)] = true
	}
	reported := 0
	for _, row := range res.Compare {
		if len(want) > 0 &&
			!want[strings.ToLower(row.ID)] &&
			!want[strings.ToLower(row.Repo)] &&
			!want[strings.ToLower(strings.TrimPrefix(row.Repo, cfg.Prefix))] &&
			!want[strings.ToLower(row.Dir)] {
			continue
		}
		if row.Status == "ok" {
			continue
		}
		reported++
		fmt.Printf("%s  local=%s  gitlab=%s  [%s]\n", row.Repo, row.Local, row.GitLab, row.Status)
		if row.Status == "DIFF" && yes {
			tag := row.GitLab
			modDir := row.Dir
			if modDir == "" {
				continue
			}
			sub := filepath.Join(root, "modules", modDir)
			fmt.Printf("→ fetching %s @ %s\n", modDir, tag)
			_ = exec.Command("git", "-C", sub, "fetch", "--tags", "origin").Run()
			if err := exec.Command("git", "-C", sub, "checkout", tag).Run(); err != nil {
				fmt.Fprintf(os.Stderr, "  checkout failed: %v\n", err)
				continue
			}
			_ = exec.Command("git", "-C", root, "add", filepath.Join("modules", modDir)).Run()
			fmt.Printf("  ✓ submodule pin updated (commit platform to keep)\n")
			fmt.Printf("  → then: php gereq module:enable %s  (or Admin → Modules → Update)\n", row.ID)
		}
	}
	if reported == 0 {
		fmt.Println("✓ all selected modules ok (or nothing to report)")
	} else if !yes {
		fmt.Println("\nRe-run with --yes to checkout matching GitLab tags into submodule dirs.")
	}
	return nil
}

type CreateOptions struct {
	Name     string
	Kind     string
	NoAgent  bool
	NoGitLab bool
}

func Create(root string, opt CreateOptions) error {
	_ = gitlab.LoadConfig(root) // loads .env (CURSOR_API_KEY, GITLAB_*)

	name := studly(opt.Name)
	id := strings.ToLower(name)
	modRoot := filepath.Join(root, "modules", name)
	if _, err := os.Stat(modRoot); err == nil {
		return fmt.Errorf("module already exists: modules/%s", name)
	}
	kind := opt.Kind
	if kind == "" {
		kind = logo.InferKind(name, id)
	}
	fmt.Printf("→ Creating modules/%s (id=%s, kind=%s)\n", name, id, kind)

	dirs := []string{
		"Application", "Application/Widgets", "Domain", "Infrastructure",
		"Http", "Admin", "Database/Migrations", "Resources/views",
		"Resources/translations", "assets", "Tests",
	}
	for _, d := range dirs {
		if err := os.MkdirAll(filepath.Join(modRoot, d), 0o775); err != nil {
			return err
		}
	}
	icon := logo.IconHint(kind)
	manifest := fmt.Sprintf(`<?php

declare(strict_types=1);

return [
    'id' => '%s',
    'name' => '%s',
    'icon' => '%s',
    'version' => '0.1.0',
    'description' => '',
    'class' => Modules\\%s\\%sModule::class,
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => [],
    'capabilities' => [],
    'permissions' => [],
    // 'repository' => 'gereq/module-%s',
];
`, id, name, icon, name, name, id)
	if err := os.WriteFile(filepath.Join(modRoot, "module.php"), []byte(manifest), 0o644); err != nil {
		return err
	}
	class := fmt.Sprintf(`<?php

declare(strict_types=1);

namespace Modules\%s;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;

final class %sModule implements ModuleInterface
{
    public function id(): string
    {
        return '%s';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}
`, name, name, id)
	if err := os.WriteFile(filepath.Join(modRoot, name+"Module.php"), []byte(class), 0o644); err != nil {
		return err
	}
	_ = os.WriteFile(filepath.Join(modRoot, "Resources", "translations", "messages.cs.php"), []byte("<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n"), 0o644)
	_ = os.WriteFile(filepath.Join(modRoot, "Resources", "translations", "messages.en.php"), []byte("<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n"), 0o644)

	if err := logo.Write(modRoot, id, name, kind); err != nil {
		return err
	}
	fmt.Println("✓ skeleton + logo")

	if !opt.NoAgent {
		if err := runCursorAgent(root, name, id, kind); err != nil {
			fmt.Fprintf(os.Stderr, "! Cursor agent skipped: %v\n", err)
		}
	}

	cfg := gitlab.LoadConfig(root)
	repoPath := cfg.Prefix + id
	fullRepo := cfg.Group + "/" + repoPath
	_ = gitlab.PatchREADME(modRoot, id, name, "0.1.0", fullRepo)

	if !opt.NoGitLab {
		if cfg.Token == "" {
			fmt.Println("! GITLAB_TOKEN empty — skip remote project (use --no-gitlab to silence)")
		} else {
			gl := gitlab.New(cfg)
			desc := fmt.Sprintf("GEREQ module %s (%s)", name, kind)
			if err := gl.EnsureProject(repoPath, desc); err != nil {
				fmt.Fprintf(os.Stderr, "! GitLab project: %v\n", err)
			} else {
				fmt.Printf("✓ GitLab project %s\n", fullRepo)
				_ = gl.SyncBadges(fullRepo, id, "0.1.0")
				fmt.Println("✓ badges")
			}
			// init git + push-to-create / push
			_ = exec.Command("git", "-C", modRoot, "init", "-b", "main").Run()
			_ = exec.Command("git", "-C", modRoot, "add", "-A").Run()
			msg := fmt.Sprintf("chore: initial %s v0.1.0", name)
			_ = exec.Command("git", "-C", modRoot, "-c", "user.email=dev@gereq.cz", "-c", "user.name=GEREQ", "commit", "-m", msg).Run()
			_ = exec.Command("git", "-C", modRoot, "tag", "-a", "v0.1.0", "-m", "Release v0.1.0").Run()
			remote := fmt.Sprintf("git@%s:%s.git", strings.TrimPrefix(strings.TrimPrefix(cfg.URL, "https://"), "http://"), fullRepo)
			_ = exec.Command("git", "-C", modRoot, "remote", "add", "origin", remote).Run()
			if err := exec.Command("git", "-C", modRoot, "push", "-u", "origin", "main", "--tags").Run(); err != nil {
				fmt.Fprintf(os.Stderr, "! git push: %v (remote=%s)\n", err, remote)
			} else {
				fmt.Printf("✓ pushed %s\n", remote)
			}
			// register submodule from platform root if not already
			gitmodules := filepath.Join(root, ".gitmodules")
			if data, err := os.ReadFile(gitmodules); err != nil || !strings.Contains(string(data), "modules/"+name) {
				httpsRemote := strings.TrimRight(cfg.URL, "/") + "/" + fullRepo + ".git"
				fmt.Printf("→ git submodule add %s modules/%s\n", httpsRemote, name)
				// module already has .git — convert to submodule carefully:
				// remove nested .git and add as submodule is complex; document manual step if fails
				_ = os.RemoveAll(filepath.Join(modRoot, ".git"))
				cmd := exec.Command("git", "-C", root, "submodule", "add", httpsRemote, "modules/"+name)
				cmd.Stdout = os.Stdout
				cmd.Stderr = os.Stderr
				if err := cmd.Run(); err != nil {
					fmt.Fprintf(os.Stderr, "! submodule add failed (module files kept locally): %v\n", err)
				}
			}
		}
	}

	fmt.Printf("\n✓ Module ready: modules/%s\n  Next: php gereq module:enable %s\n", name, id)
	return nil
}

func runCursorAgent(root, name, id, kind string) error {
	if !cursoragent.Available() {
		return fmt.Errorf("Cursor CLI `agent` not on PATH")
	}
	fmt.Println("→ Cursor Agent polish…")
	prompt := fmt.Sprintf(`You are polishing a newly scaffolded GEREQ Platform module.

Module directory: modules/%s/
Module id: %s
Kind/domain: %s

Tasks (do all that apply, keep scaffolding intact):
1. Improve assets/logo.svg — 512×512 rounded square, white glyph on branded gradient, same visual language as other modules/*/assets/logo.svg.
2. Set a short English 'description' in module.php and pick a sensible Lucide-style 'icon' string for kind=%s.
3. Add minimal cs + en translation stub keys under Resources/translations/ if useful (keep arrays valid PHP).
4. Do NOT delete Module class, migrations folder, or break PHP syntax.
5. Do NOT run destructive git commands.

Make the file edits, then stop.`, name, id, kind, kind)
	_, err := cursoragent.Edit(root, prompt, cursoragent.DefaultModel)
	return err
}

func studly(s string) string {
	s = strings.TrimSpace(s)
	if s == "" {
		return "Module"
	}
	// Already Studly/Camel without separators — keep casing, fix first rune.
	if !strings.ContainsAny(s, "-_ ") {
		r := []rune(s)
		r[0] = []rune(strings.ToUpper(string(r[0])))[0]
		return string(r)
	}
	parts := strings.FieldsFunc(s, func(r rune) bool {
		return r == '-' || r == '_' || r == ' '
	})
	var b strings.Builder
	for _, p := range parts {
		if p == "" {
			continue
		}
		r := []rune(p)
		b.WriteString(strings.ToUpper(string(r[0])) + strings.ToLower(string(r[1:])))
	}
	out := b.String()
	if out == "" {
		return "Module"
	}
	return out
}
