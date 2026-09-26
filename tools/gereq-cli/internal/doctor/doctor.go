package doctor

import (
	"fmt"
	"os"
	"os/exec"
	"strings"
)

type Check struct {
	Name    string
	OK      bool
	Detail  string
	Required bool
}

func Run() []Check {
	var out []Check
	out = append(out, checkPHP())
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
