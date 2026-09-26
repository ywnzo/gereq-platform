package doctor

import (
	"fmt"
	"os"
	"os/exec"
	"strings"
)

type Check struct {
	Name     string
	OK       bool
	Detail   string
	Required bool
}

var requiredPHPExtensions = []string{"gd", "zip", "json", "mbstring", "pdo"}

func Run() []Check {
	var out []Check
	out = append(out, checkPHP())
	out = append(out, checkPHPExtensions()...)
	out = append(out, checkCmd("composer", true))
	if _, err := exec.LookPath("bun"); err == nil {
		out = append(out, Check{Name: "bun", OK: true, Detail: which("bun"), Required: true})
	} else if _, err := exec.LookPath("npm"); err == nil {
		out = append(out, Check{Name: "npm", OK: true, Detail: which("npm") + " (bun preferred)", Required: true})
	} else {
		out = append(out, Check{Name: "bun|npm", OK: false, Detail: "missing", Required: true})
	}
	dockerOK := false
	if _, err := exec.LookPath("docker"); err == nil {
		if err := exec.Command("docker", "compose", "version").Run(); err == nil {
			dockerOK = true
			out = append(out, Check{Name: "docker compose", OK: true, Detail: which("docker"), Required: false})
		}
	}
	if !dockerOK {
		out = append(out, Check{Name: "docker compose", OK: false, Detail: "optional", Required: false})
	}
	out = append(out, checkCmd("git", false))
	return out
}

func checkPHP() Check {
	path, err := exec.LookPath("php")
	if err != nil {
		return Check{Name: "php", OK: false, Detail: "missing", Required: true}
	}
	out, err := exec.Command("php", "-r", "echo PHP_VERSION;").Output()
	if err != nil {
		return Check{Name: "php", OK: false, Detail: path, Required: true}
	}
	ver := strings.TrimSpace(string(out))
	ok := exec.Command("php", "-r", `exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);`).Run() == nil
	detail := ver
	if !ok {
		detail = ver + " (need >= 8.4)"
	}
	return Check{Name: "php", OK: ok, Detail: detail, Required: true}
}

func checkPHPExtensions() []Check {
	modules := phpLoadedModules()
	var out []Check
	var missing []string
	for _, ext := range requiredPHPExtensions {
		ok := modules != nil && modules[ext]
		detail := "loaded"
		if !ok {
			detail = "missing"
			missing = append(missing, ext)
		}
		out = append(out, Check{
			Name:     "php ext-" + ext,
			OK:       ok,
			Detail:   detail,
			Required: true,
		})
	}
	if hint := phpExtensionInstallHint(missing); hint != "" {
		out = append(out, Check{
			Name:     "php extensions",
			OK:       false,
			Detail:   hint,
			Required: true,
		})
	}
	ok := exec.Command("php", "-m").Run() == nil
	if ok {
		out2, _ := exec.Command("php", "-m").Output()
		if !strings.Contains(strings.ToLower(string(out2)), "redis") {
			out = append(out, Check{Name: "php ext-redis", OK: false, Detail: "optional (file/sync drivers OK)", Required: false})
		}
	}
	return out
}

func phpLoadedModules() map[string]bool {
	out, err := exec.Command("php", "-m").Output()
	if err != nil {
		return nil
	}
	modules := make(map[string]bool)
	for _, line := range strings.Split(string(out), "\n") {
		line = strings.ToLower(strings.TrimSpace(line))
		if line != "" {
			modules[line] = true
		}
	}
	return modules
}

func phpExtensionInstallHint(missing []string) string {
	if len(missing) == 0 {
		return ""
	}
	if _, err := os.Stat("/etc/fedora-release"); err == nil {
		return "Fedora: sudo dnf install " + strings.Join(fedoraPHPExtensionPackages(missing), " ")
	}
	if _, err := os.Stat("/etc/debian_version"); err == nil {
		return "Debian/Ubuntu: sudo apt install php-gd php-zip (add version suffix if needed, e.g. php8.4-gd)"
	}
	return "Install PHP extensions: " + strings.Join(missing, ", ")
}

func fedoraPHPExtensionPackages(missing []string) []string {
	pkgForExt := map[string]string{
		"gd":       "php-gd",
		"zip":      "php-pecl-zip",
		"mbstring": "php-mbstring",
		"pdo":      "php-pdo",
	}
	seen := make(map[string]bool)
	var pkgs []string
	for _, ext := range missing {
		pkg, ok := pkgForExt[ext]
		if !ok || seen[pkg] {
			continue
		}
		seen[pkg] = true
		pkgs = append(pkgs, pkg)
	}
	if len(pkgs) == 0 {
		return []string{"php-gd", "php-pecl-zip"}
	}
	return pkgs
}

func checkCmd(name string, required bool) Check {
	path, err := exec.LookPath(name)
	if err != nil {
		return Check{Name: name, OK: false, Detail: "missing", Required: required}
	}
	return Check{Name: name, OK: true, Detail: path, Required: required}
}

func which(name string) string {
	p, _ := exec.LookPath(name)
	return p
}

func Print(checks []Check) int {
	fail := 0
	for _, c := range checks {
		mark := "✓"
		if !c.OK {
			mark = "✗"
			if c.Required {
				fail++
			} else {
				mark = "!"
			}
		}
		fmt.Fprintf(os.Stdout, "%s %-16s %s\n", mark, c.Name, c.Detail)
	}
	if fail > 0 {
		return 1
	}
	return 0
}
