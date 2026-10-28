package main

import (
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
)

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}
func run(args []string) error {
	if len(args) == 0 {
		return errors.New("usage: provisioner generate|plan|create|resume|inspect|stop|start|import|setup-link|secure-access|relocate|dashboard-catalog|dashboard-preset|analytics-config --root PATH [--request FILE --release FILE | --site ID --artifact FILE]")
	}
	op := args[0]
	fs := flag.NewFlagSet(op, flag.ContinueOnError)
	root := fs.String("root", "", "private local state directory (required)")
	req := fs.String("request", "", "request JSON")
	rel := fs.String("release", "", "release JSON")
	site := fs.String("site", "", "site ID")
	runtime := fs.String("runtime", "podman", "container runtime: podman or docker")
	destination := fs.String("destination", "", "persistent destination registry for relocation")
	email := fs.String("email", "", "owner email for a single-use setup link")
	brief := fs.String("brief", "", "business brief JSON")
	spec := fs.String("spec", "", "validated copy specification for offline compilation (no AI call)")
	importDraft := fs.Bool("import", false, "import generated artifact as a customer draft")
	artifact := fs.String("artifact", "", "native-block draft JSON")
	if err := fs.Parse(args[1:]); err != nil {
		return err
	}
	if fs.NArg() != 0 {
		return errors.New("unexpected arguments")
	}
	if *root == "" {
		return errors.New("--root is required")
	}
	if *runtime != "podman" && *runtime != "docker" {
		return errors.New("unsupported runtime")
	}
	e := Engine{Root: *root, Runner: ContainerCLI{Binary: *runtime}, Runtime: *runtime}
	ctx, cancel := timeout()
	defer cancel()
	emit := func(v any) error { return json.NewEncoder(os.Stdout).Encode(v) }
	switch op {
	case "generate":
		if _, _, err := e.load(*site); err != nil {
			return err
		}
		model := os.Getenv("GEREQ_GENERATOR_MODEL")
		provider := OpenAIProvider{Key: os.Getenv("OPENAI_API_KEY"), Model: model}
		result, err := generate(ctx, *root, *site, *brief, *spec, provider, model)
		if err != nil {
			return err
		}
		if *importDraft {
			out, err := e.Import(ctx, *site, result.Artifact)
			if err != nil {
				return err
			}
			result.Imported = json.RawMessage(out)
		}
		return emit(result)
	case "plan", "create":
		var r Request
		var l Release
		if err := decode(*req, &r); err != nil {
			return err
		}
		if err := decode(*rel, &l); err != nil {
			return err
		}
		if err := validate(r, l); err != nil {
			return err
		}
		if op == "plan" {
			p, err := ownedDir(*root, r.SiteID)
			if err != nil {
				return err
			}
			return emit(map[string]any{"site_id": r.SiteID, "fingerprint": fingerprint(r, l), "directory": p, "compose_project": projectName(r.SiteID, p), "image": l.Image, "database": "private SQLite file", "modules": l.Modules, "mutations": false, "runtime": "local development only"})
		}
		s, err := e.Create(ctx, r, l)
		if err != nil {
			return err
		}
		return emit(publicState(s))
	case "resume":
		s, _, err := e.load(*site)
		if err != nil {
			return err
		}
		s, err = e.Create(ctx, s.Request, s.Release)
		if err != nil {
			return err
		}
		return emit(publicState(s))
	case "inspect":
		s, _, err := e.load(*site)
		if err != nil {
			return err
		}
		return emit(publicState(s))
	case "start", "stop":
		s, err := e.Lifecycle(ctx, *site, op)
		if err != nil {
			return err
		}
		return emit(publicState(s))
	case "relocate":
		s, err := e.Relocate(ctx, *site, *destination)
		if err != nil {
			return err
		}
		return emit(publicState(s))
	case "setup-link", "secure-access", "dashboard-catalog", "dashboard-preset", "analytics-config":
		out, err := e.Access(ctx, *site, op, *email)
		if err != nil {
			return err
		}
		var result map[string]any
		if err = json.Unmarshal(out, &result); err != nil {
			return errors.New("invalid access response")
		}
		return emit(result)
	case "import":
		out, err := e.Import(ctx, *site, *artifact)
		if err != nil {
			return err
		}
		var result map[string]any
		if err = json.Unmarshal(out, &result); err != nil {
			return errors.New("invalid import response")
		}
		return emit(result)
	default:
		return fmt.Errorf("unknown command %q", op)
	}
}
