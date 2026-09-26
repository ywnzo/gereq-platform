package main

import (
	"fmt"
	"os"
	"strconv"
	"strings"

	"gereq.dev/gereq-cli/internal/commit"
	"gereq.dev/gereq-cli/internal/doctor"
	"gereq.dev/gereq-cli/internal/env"
	"gereq.dev/gereq-cli/internal/install"
	"gereq.dev/gereq-cli/internal/modules"
	"gereq.dev/gereq-cli/internal/pack"
	"gereq.dev/gereq-cli/internal/pr"
	"gereq.dev/gereq-cli/internal/root"
)

const version = "0.1.0"

func main() {
	args := os.Args[1:]
	if len(args) == 0 {
		printHelp()
		os.Exit(0)
	}
	cmd := args[0]
	rest := args[1:]

	switch cmd {
	case "-h", "--help", "help":
		printHelp()
	case "version", "-v", "--version":
		fmt.Println("grq", version)
	case "doctor":
		r, err := root.Chdir()
		must(err)
		_ = r
		os.Exit(doctor.Print(doctor.Run()))
	case "env":
		must(cmdEnv(rest))
	case "install":
		must(cmdInstall(rest))
	case "pack":
		must(cmdPack(rest))
	case "commit":
		must(cmdCommit(rest))
	case "pr":
		must(cmdPR(rest))
	case "modules":
		must(cmdModules(rest))
	default:
		fmt.Fprintf(os.Stderr, "unknown command: %s\n", cmd)
		printHelp()
		os.Exit(1)
	}
}

func must(err error) {
	if err != nil {
		fmt.Fprintf(os.Stderr, "grq: %v\n", err)
		os.Exit(1)
	}
}

func printHelp() {
	fmt.Print(`grq — GEREQ platform CLI

Usage:
  grq doctor
  grq env init [--docker] [--force] [--port N] [--no-secrets]
  grq env validate
  grq install
  grq install demo [--serve] [--port N]
  grq pack demo
  grq commit [-a|--all] [-m MSG | MSG...] [--install-hooks]
  grq pr [--base BRANCH] [--draft] [--dry-run] [--push] [--fast] [--no-agent]
  grq modules                 interactive TUI
  grq modules status [--plain]
  grq modules update [id…] [--yes]
  grq modules create <Name> [--kind KIND] [--no-agent] [--no-gitlab]
  grq version

Env:
  GEREQ_ROOT, GITLAB_* (MR + modules). Cursor: install CLI + "agent login" (optional CURSOR_API_KEY)
`)
}

func cmdEnv(args []string) error {
	r, err := root.Chdir()
	if err != nil {
		return err
	}
	if len(args) == 0 {
		return fmt.Errorf("usage: grq env init|validate")
	}
	switch args[0] {
	case "init":
		opt := env.InitOptions{GenerateSecrets: true, Port: "8080"}
		for i := 1; i < len(args); i++ {
			switch args[i] {
			case "--docker":
				opt.Docker = true
			case "--force", "-f":
				opt.Force = true
			case "--no-secrets":
				opt.GenerateSecrets = false
			case "--port":
				i++
				if i < len(args) {
					opt.Port = args[i]
				}
			default:
				if strings.HasPrefix(args[i], "--port=") {
					opt.Port = strings.TrimPrefix(args[i], "--port=")
				}
			}
		}
		return env.Init(r, opt)
	case "validate":
		problems := env.Validate(r)
		if len(problems) == 0 {
			fmt.Println("✓ .env matches env.schema.toml")
			return nil
		}
		for _, p := range problems {
			fmt.Println("•", p)
		}
		return fmt.Errorf("validation failed (%d)", len(problems))
	default:
		return fmt.Errorf("usage: grq env init|validate")
	}
}

func cmdInstall(args []string) error {
	r, err := root.Chdir()
	if err != nil {
		return err
	}
	if len(args) > 0 && args[0] == "demo" {
		port := 8080
		serve := false
		for _, a := range args[1:] {
			switch {
			case a == "--serve":
				serve = true
			case strings.HasPrefix(a, "--port="):
				port, _ = strconv.Atoi(strings.TrimPrefix(a, "--port="))
			}
		}
		return install.Demo(r, port, serve)
	}
	return install.Interactive(r)
}

func cmdPack(args []string) error {
	r, err := root.Chdir()
	if err != nil {
		return err
	}
	if len(args) == 0 || args[0] != "demo" {
		return fmt.Errorf("usage: grq pack demo")
	}
	return pack.Demo(r)
}

func cmdCommit(args []string) error {
	r, err := root.Chdir()
	if err != nil {
		return err
	}
	opt := commit.Options{}
	var msgParts []string
	for i := 0; i < len(args); i++ {
		a := args[i]
		switch a {
		case "--install-hooks":
			opt.InstallHooks = true
		case "--all":
			opt.StageAll = true
		case "-a":
			opt.CommitAll = true
		case "-m":
			i++
			if i < len(args) {
				msgParts = append(msgParts, args[i])
			}
		case "-h", "--help":
			fmt.Println("grq commit [-a|--all] [-m MSG | MSG...] [--install-hooks]")
			return nil
		default:
			if strings.HasPrefix(a, "-") {
				continue
			}
			msgParts = append(msgParts, a)
		}
	}
	opt.Message = strings.Join(msgParts, " ")
	if !opt.InstallHooks && opt.Message == "" {
		return fmt.Errorf("commit message required")
	}
	return commit.Run(r, opt)
}

func cmdPR(args []string) error {
	r, err := root.Chdir()
	if err != nil {
		return err
	}
	opt := pr.Options{}
	for i := 0; i < len(args); i++ {
		a := args[i]
		switch a {
		case "-h", "--help":
			fmt.Println(`grq pr — draft MR via Cursor (Composer 2.5) and create on GitLab

  grq pr [--base BRANCH] [--draft] [--dry-run] [--push] [--fast] [--no-agent]
         [--title TITLE] [--body-file PATH]

Guidelines enforced in the draft:
  ## Summary   (1–3 bullets, why / impact)
  ## Test plan (checklist)

Default model: composer-2.5 standard (cheaper). Use --fast for the faster variant.
Requires Cursor CLI "agent" on PATH ("agent login", or CURSOR_API_KEY).
GITLAB_TOKEN to create the MR. Without agent CLI (or with --no-agent) uses a local template.`)
			return nil
		case "--draft":
			opt.Draft = true
		case "--dry-run":
			opt.DryRun = true
		case "--push":
			opt.Push = true
		case "--fast":
			opt.Fast = true
		case "--no-agent":
			opt.NoAgent = true
		case "--base":
			i++
			if i < len(args) {
				opt.Base = args[i]
			}
		case "--title":
			i++
			if i < len(args) {
				opt.Title = args[i]
			}
		case "--body-file":
			i++
			if i < len(args) {
				raw, err := os.ReadFile(args[i])
				if err != nil {
					return err
				}
				opt.Body = string(raw)
			}
		default:
			if strings.HasPrefix(a, "--base=") {
				opt.Base = strings.TrimPrefix(a, "--base=")
			} else if strings.HasPrefix(a, "--title=") {
				opt.Title = strings.TrimPrefix(a, "--title=")
			}
		}
	}
	return pr.Run(r, opt)
}

func cmdModules(args []string) error {
	r, err := root.Chdir()
	if err != nil {
		return err
	}
	if len(args) == 0 {
		return modules.RunTUI(r)
	}
	switch args[0] {
	case "status":
		plain := true
		for _, a := range args[1:] {
			if a == "--plain" || a == "-plain" {
				plain = true
			}
		}
		res := modules.RunScan(r)
		if res.Err != nil && len(res.Compare) == 0 {
			return res.Err
		}
		if plain {
			modules.PrintPlain(r, res)
		}
		return nil
	case "update":
		yes := false
		var ids []string
		for _, a := range args[1:] {
			if a == "--yes" || a == "-y" {
				yes = true
			} else if !strings.HasPrefix(a, "-") {
				ids = append(ids, a)
			}
		}
		return modules.Update(r, ids, yes)
	case "create":
		if len(args) < 2 {
			return fmt.Errorf("usage: grq modules create <Name> [--kind KIND] [--no-agent] [--no-gitlab]")
		}
		opt := modules.CreateOptions{Name: args[1]}
		for i := 2; i < len(args); i++ {
			switch args[i] {
			case "--no-agent":
				opt.NoAgent = true
			case "--no-gitlab":
				opt.NoGitLab = true
			case "--kind":
				i++
				if i < len(args) {
					opt.Kind = args[i]
				}
			default:
				if strings.HasPrefix(args[i], "--kind=") {
					opt.Kind = strings.TrimPrefix(args[i], "--kind=")
				}
			}
		}
		return modules.Create(r, opt)
	case "--plain", "-plain":
		res := modules.RunScan(r)
		if res.Err != nil && len(res.Compare) == 0 {
			return res.Err
		}
		modules.PrintPlain(r, res)
		return nil
	case "-h", "--help":
		fmt.Println(`grq modules
  grq modules status [--plain]
  grq modules update [id…] [--yes]
  grq modules create <Name> [--kind KIND] [--no-agent] [--no-gitlab]`)
		return nil
	default:
		return fmt.Errorf("unknown modules subcommand: %s", args[0])
	}
}
