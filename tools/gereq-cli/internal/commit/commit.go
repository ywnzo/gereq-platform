package commit

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

type Options struct {
	Message      string
	StageAll     bool
	CommitAll    bool
	InstallHooks bool
}

func Run(root string, opt Options) error {
	hooksDir := filepath.Join(root, ".githooks")
	if err := ensureHooks(root, hooksDir); err != nil {
		return err
	}
	if opt.InstallHooks {
		fmt.Println("Hooks OK.")
		return nil
	}
	msg := stripCoAuthors(opt.Message)
	if strings.TrimSpace(msg) == "" {
		return fmt.Errorf("empty commit message (after stripping Co-authored-by)")
	}
	if opt.StageAll {
		if err := runGit(root, "add", "-A"); err != nil {
			return err
		}
	}
	tmp, err := os.CreateTemp("", "grq-commit-*.txt")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.WriteString(msg + "\n"); err != nil {
		tmp.Close()
		return err
	}
	tmp.Close()

	args := []string{"commit"}
	if opt.CommitAll {
		args = append(args, "-a")
	}
	args = append(args, "-F", tmp.Name())
	if err := runGit(root, args...); err != nil {
		return err
	}
	out, _ := exec.Command("git", "-C", root, "log", "-1", "--oneline").Output()
	fmt.Printf("Commit: %s\n", strings.TrimSpace(string(out)))
	return nil
}

func ensureHooks(root, hooksDir string) error {
	if _, err := os.Stat(hooksDir); err != nil {
		return fmt.Errorf("missing %s", hooksDir)
	}
	_ = os.Chmod(filepath.Join(hooksDir, "commit-msg"), 0o755)
	out, _ := exec.Command("git", "-C", root, "config", "--local", "--get", "core.hooksPath").Output()
	current := strings.TrimSpace(string(out))
	if current != ".githooks" && current != hooksDir {
		if err := runGit(root, "config", "--local", "core.hooksPath", ".githooks"); err != nil {
			return err
		}
		fmt.Println("Set core.hooksPath=.githooks")
	}
	return nil
}

func stripCoAuthors(msg string) string {
	var lines []string
	for _, line := range strings.Split(msg, "\n") {
		if strings.HasPrefix(strings.ToLower(strings.TrimSpace(line)), "co-authored-by:") {
			continue
		}
		lines = append(lines, line)
	}
	for len(lines) > 0 && strings.TrimSpace(lines[len(lines)-1]) == "" {
		lines = lines[:len(lines)-1]
	}
	return strings.Join(lines, "\n")
}

func runGit(root string, args ...string) error {
	cmd := exec.Command("git", append([]string{"-C", root}, args...)...)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	return cmd.Run()
}
