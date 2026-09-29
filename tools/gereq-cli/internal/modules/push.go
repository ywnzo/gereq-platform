package modules

import (
	"bytes"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"gereq.dev/gereq-cli/internal/gitlab"
)

// UpdateOptions controls grq modules update.
//
//	--yes alone     → pull: checkout GitLab tag into submodule
//	--push [--yes]  → push: publish local version as tag on origin/main
//	--patch [--yes] → bump patch (X.Y.Z+1), then push
type UpdateOptions struct {
	Yes   bool
	Push  bool
	Patch bool
}

func Update(root string, ids []string, opt UpdateOptions) error {
	if opt.Patch {
		opt.Push = true
	}
	if opt.Push && opt.Yes {
		return pushModules(root, ids, opt.Patch)
	}
	if opt.Push && !opt.Yes {
		return reportPush(root, ids, opt.Patch)
	}
	return pullModules(root, ids, opt.Yes)
}

func reportPush(root string, ids []string, patch bool) error {
	res := RunScan(root)
	if res.Err != nil {
		return res.Err
	}
	cfg := gitlab.LoadConfig(root)
	want := idWant(ids)
	n := 0
	for _, row := range res.Compare {
		if !idMatch(want, cfg.Prefix, row) {
			continue
		}
		if row.Status == "MISSING" {
			continue
		}
		action, skip := pushPlan(row, patch)
		if skip {
			continue
		}
		n++
		fmt.Printf("%s  local=%s  gitlab=%s  [%s] → %s\n", row.Repo, row.Local, row.GitLab, row.Status, action)
	}
	if n == 0 {
		fmt.Println("✓ nothing to push (local not ahead, or no DIFF/NO_TAGS)")
		return nil
	}
	flag := "--push --yes"
	if patch {
		flag = "--patch --yes"
	}
	fmt.Printf("\nRe-run with %s to publish local version(s) to GitLab.\n", flag)
	return nil
}

func pushPlan(row CompareRow, patch bool) (action string, skip bool) {
	switch row.Status {
	case "ok":
		if !patch {
			return "", true
		}
		next, err := bumpPatch(row.Local)
		if err != nil {
			return "skip: " + err.Error(), false
		}
		return fmt.Sprintf("bump %s→%s + push tag v%s", row.Local, next, next), false
	case "NO_TAGS":
		ver := row.Local
		if patch {
			next, err := bumpPatch(ver)
			if err != nil {
				return "skip: " + err.Error(), false
			}
			ver = next
		}
		return fmt.Sprintf("create tag v%s + push main", ver), false
	case "DIFF":
		cmp, err := compareSemver(row.Local, row.GitLab)
		if err != nil {
			return "skip: " + err.Error(), false
		}
		if patch {
			next, err := bumpPatch(row.Local)
			if err != nil {
				return "skip: " + err.Error(), false
			}
			return fmt.Sprintf("bump %s→%s + push tag v%s", row.Local, next, next), false
		}
		if cmp < 0 {
			return "skip: local behind (use --yes to pull)", false
		}
		if cmp == 0 {
			return "", true
		}
		return fmt.Sprintf("push tag v%s", normalizeVersion(row.Local)), false
	default:
		return "", true
	}
}

func pushModules(root string, ids []string, patch bool) error {
	res := RunScan(root)
	if res.Err != nil {
		return res.Err
	}
	cfg := gitlab.LoadConfig(root)
	want := idWant(ids)
	done := 0
	for _, row := range res.Compare {
		if !idMatch(want, cfg.Prefix, row) {
			continue
		}
		if row.Status == "MISSING" {
			fmt.Printf("%s  [%s] — skip (no GitLab project)\n", row.Repo, row.Status)
			continue
		}
		action, skip := pushPlan(row, patch)
		if skip {
			continue
		}
		if strings.HasPrefix(action, "skip:") {
			fmt.Printf("%s  local=%s  gitlab=%s  [%s] → %s\n", row.Repo, row.Local, row.GitLab, row.Status, action)
			continue
		}
		fmt.Printf("%s  local=%s  gitlab=%s  [%s]\n", row.Repo, row.Local, row.GitLab, row.Status)
		if err := pushOne(root, row, patch); err != nil {
			fmt.Fprintf(os.Stderr, "  ✗ %v\n", err)
			continue
		}
		done++
	}
	if done == 0 {
		fmt.Println("✓ nothing pushed")
	}
	return nil
}

func pushOne(root string, row CompareRow, patch bool) error {
	if row.Dir == "" {
		return fmt.Errorf("empty module dir")
	}
	sub := filepath.Join(root, "modules", row.Dir)
	ver := normalizeVersion(row.Local)
	if patch {
		next, err := bumpPatch(ver)
		if err != nil {
			return err
		}
		fmt.Printf("→ bump %s → %s\n", ver, next)
		if err := setModuleVersion(sub, next); err != nil {
			return err
		}
		ver = next
	}
	tag := "v" + ver

	if err := gitCommitIfDirty(sub, fmt.Sprintf("chore: release %s %s", row.Dir, tag)); err != nil {
		return err
	}

	fmt.Printf("→ tagging %s @ %s\n", row.Dir, tag)
	_ = exec.Command("git", "-C", sub, "tag", "-d", tag).Run()
	if out, err := exec.Command("git", "-C", sub, "tag", "-a", tag, "-m", "Release "+tag).CombinedOutput(); err != nil {
		return fmt.Errorf("tag %s: %w (%s)", tag, err, strings.TrimSpace(string(out)))
	}

	fmt.Printf("→ push origin HEAD:main --tags\n")
	_ = exec.Command("git", "-C", sub, "fetch", "origin", "--tags").Run()
	out, err := exec.Command("git", "-C", sub, "push", "origin", "HEAD:main", "--tags").CombinedOutput()
	if err != nil {
		// Detached or diverged: land on main via cherry-pick, then push.
		fmt.Printf("  direct push failed (%s) — trying origin/main + cherry-pick\n", strings.TrimSpace(string(out)))
		if err2 := pushViaMain(sub, tag); err2 != nil {
			return fmt.Errorf("push: %w (fallback: %v)", err, err2)
		}
	} else {
		fmt.Printf("  ✓ pushed %s\n", tag)
	}

	_ = exec.Command("git", "-C", root, "add", filepath.Join("modules", row.Dir)).Run()
	fmt.Printf("  ✓ submodule pin staged (commit platform to keep)\n")
	fmt.Printf("  → then: php gereq module:enable %s  (or Admin → Modules → Update)\n", row.ID)
	return nil
}

func pushViaMain(sub, tag string) error {
	head, err := gitOutput(sub, "rev-parse", "HEAD")
	if err != nil {
		return err
	}
	_ = exec.Command("git", "-C", sub, "fetch", "origin").Run()
	if out, err := exec.Command("git", "-C", sub, "checkout", "-B", "main", "origin/main").CombinedOutput(); err != nil {
		return fmt.Errorf("checkout main: %w (%s)", err, strings.TrimSpace(string(out)))
	}
	cur, _ := gitOutput(sub, "rev-parse", "HEAD")
	if cur != head {
		if out, err := exec.Command("git", "-C", sub, "cherry-pick", head).CombinedOutput(); err != nil {
			_ = exec.Command("git", "-C", sub, "cherry-pick", "--abort").Run()
			return fmt.Errorf("cherry-pick: %w (%s)", err, strings.TrimSpace(string(out)))
		}
	}
	_ = exec.Command("git", "-C", sub, "tag", "-d", tag).Run()
	if out, err := exec.Command("git", "-C", sub, "tag", "-a", tag, "-m", "Release "+tag).CombinedOutput(); err != nil {
		return fmt.Errorf("retag: %w (%s)", err, strings.TrimSpace(string(out)))
	}
	if out, err := exec.Command("git", "-C", sub, "push", "origin", "main", "--tags").CombinedOutput(); err != nil {
		return fmt.Errorf("%w (%s)", err, strings.TrimSpace(string(out)))
	}
	fmt.Printf("  ✓ pushed %s via main\n", tag)
	return nil
}

func gitCommitIfDirty(sub, msg string) error {
	status, err := gitOutput(sub, "status", "--porcelain")
	if err != nil {
		return err
	}
	if strings.TrimSpace(status) == "" {
		return nil
	}
	if out, err := exec.Command("git", "-C", sub, "add", "-A").CombinedOutput(); err != nil {
		return fmt.Errorf("git add: %w (%s)", err, strings.TrimSpace(string(out)))
	}
	cmd := exec.Command("git", "-C", sub, "commit", "-m", msg)
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("git commit: %w (%s)", err, strings.TrimSpace(string(out)))
	}
	fmt.Printf("  ✓ committed dirty tree\n")
	return nil
}

func gitOutput(sub string, args ...string) (string, error) {
	cmd := exec.Command("git", append([]string{"-C", sub}, args...)...)
	var buf bytes.Buffer
	cmd.Stdout = &buf
	cmd.Stderr = &buf
	if err := cmd.Run(); err != nil {
		return "", fmt.Errorf("git %s: %w (%s)", strings.Join(args, " "), err, strings.TrimSpace(buf.String()))
	}
	return strings.TrimSpace(buf.String()), nil
}

func pullModules(root string, ids []string, yes bool) error {
	res := RunScan(root)
	if res.Err != nil {
		return res.Err
	}
	cfg := gitlab.LoadConfig(root)
	want := idWant(ids)
	reported := 0
	for _, row := range res.Compare {
		if !idMatch(want, cfg.Prefix, row) {
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
		fmt.Println("Or: --push --yes to publish local versions; --patch --yes to bump patch + push.")
	}
	return nil
}

func idWant(ids []string) map[string]bool {
	want := map[string]bool{}
	for _, id := range ids {
		want[strings.ToLower(id)] = true
	}
	return want
}

func idMatch(want map[string]bool, prefix string, row CompareRow) bool {
	if len(want) == 0 {
		return true
	}
	return want[strings.ToLower(row.ID)] ||
		want[strings.ToLower(row.Repo)] ||
		want[strings.ToLower(strings.TrimPrefix(row.Repo, prefix))] ||
		want[strings.ToLower(row.Dir)]
}
