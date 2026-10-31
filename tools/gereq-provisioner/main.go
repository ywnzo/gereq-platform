package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}
func run(args []string) error {
	if len(args) == 0 {
		return errors.New("usage: provisioner domain-router|management-ui|onboarding-ui|central-api|backup-api|backup-init|backup-create|backup-verify|backup-restore|generate|plan|create|resume|inspect|stop|start|import|setup-link|secure-access|relocate|update-plan|update|update-status|update-recover|update-rollback|update-export-retained|dashboard-catalog|dashboard-preset|analytics-config --root PATH [--request FILE --release FILE | --site ID --artifact FILE]")
	}
	op := args[0]
	fs := flag.NewFlagSet(op, flag.ContinueOnError)
	defaultListen := "127.0.0.1:18150"
	if op == "domain-router" {
		defaultListen = "127.0.0.1:18154"
	}
	if op == "management-ui" {
		defaultListen = "127.0.0.1:18152"
	}
	listen := fs.String("listen", defaultListen, "loopback address for local test UI")
	releaseID := fs.String("release-id", "", "approved customer release for local onboarding UI")
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
	acceptDataLoss := fs.Bool("accept-data-loss", false, "allow recovery after the updated runtime might have accepted writes")
	updateID := fs.String("update-id", "", "completed update ID for explicit rollback")
	catalog := fs.String("release-catalog", "", "optional legacy release directory to import once")
	socket := fs.String("socket", "", "absolute path to the private backup API Unix socket")
	backupConfig := fs.String("backup-config", "", "backup configuration JSON")
	snapshot := fs.String("snapshot", "", "full backup snapshot ID")
	expectedImageID := fs.String("expected-image-id", "", "exact image ID returned by update-plan")
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
	case "domain-router":
		serverCtx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
		defer stop()
		return serveDomainRouter(serverCtx, *socket, *listen)
	case "management-ui":
		serverCtx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
		defer stop()
		return serveManagementUI(serverCtx, *root, *socket, *listen)
	case "onboarding-ui":
		serverCtx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
		defer stop()
		return serveOnboardingUI(serverCtx, *root, *socket, *listen, *releaseID)
	case "central-api":
		if *socket == "" {
			return errors.New("--socket is required")
		}
		serverCtx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
		defer stop()
		return serveLocalAPI(serverCtx, e, *socket, *catalog, true)
	case "backup-api":
		if *socket == "" {
			return errors.New("--socket is required")
		}
		serverCtx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
		defer stop()
		return serveBackupAPI(serverCtx, e, *socket)
	case "backup-recover":
		result, err := e.BackupRecover(ctx, *site)
		if err != nil {
			return err
		}
		return emit(result)
	case "backup-init", "backup-create", "backup-verify", "backup-restore":
		if *backupConfig == "" {
			return errors.New("--backup-config is required")
		}
		config, err := loadBackupConfig(*backupConfig)
		if err != nil {
			return err
		}
		if op == "backup-init" {
			_, err = runRestic(ctx, config, "init")
			if err != nil {
				return err
			}
			return emit(map[string]any{"initialized": true})
		}
		if *site == "" {
			return errors.New("--site is required")
		}
		if op == "backup-create" {
			result, err := e.BackupCreate(ctx, *site, config)
			if err != nil {
				return err
			}
			return emit(result)
		}
		result, err := e.BackupVerify(ctx, *site, *snapshot, config, op == "backup-restore")
		if err != nil {
			return err
		}
		return emit(result)
	case "update-plan", "update":
		if *site == "" || *rel == "" {
			return errors.New("--site and --release are required")
		}
		var release Release
		if err := decode(*rel, &release); err != nil {
			return err
		}
		if op == "update-plan" {
			result, err := e.PlanUpdate(ctx, *site, release)
			if err != nil {
				return err
			}
			return emit(result)
		}
		result, err := e.Update(ctx, *site, release, *expectedImageID)
		if err != nil {
			return err
		}
		return emit(map[string]any{"site_id": result.Site, "update_id": result.ID, "status": result.Phase, "release": result.Target.Release.ID})
	case "update-export-retained":
		if *site == "" || *updateID == "" {
			return errors.New("--site and --update-id are required")
		}
		result, err := e.ExportRetainedUpdate(ctx, *site, *updateID)
		if err != nil {
			return err
		}
		return emit(result)
	case "update-status":
		if *site == "" {
			return errors.New("--site is required")
		}
		result, err := e.UpdateStatus(*site, *updateID)
		if err != nil {
			return err
		}
		return emit(result)
	case "update-rollback":
		if *site == "" || *updateID == "" {
			return errors.New("--site and --update-id are required")
		}
		result, err := e.RollbackUpdate(ctx, *site, *updateID, *acceptDataLoss)
		if err != nil {
			return err
		}
		return emit(map[string]any{"site_id": result.Site, "update_id": result.ID, "status": result.Phase, "retained_writes": true})
	case "update-recover":
		if *site == "" {
			return errors.New("--site is required")
		}
		result, err := e.RecoverUpdate(ctx, *site, *acceptDataLoss)
		if err != nil {
			return err
		}
		return emit(map[string]any{"site_id": result.Site, "update_id": result.ID, "status": result.Phase})
	case "generate":
		if _, _, err := e.load(*site); err != nil {
			return err
		}
		if *importDraft && *spec == "" {
			if err := e.requirePaletteSupport(ctx, *site); err != nil {
				return err
			}
		}
		model := os.Getenv("GEREQ_GENERATOR_MODEL")
		provider := OpenAIProvider{Key: os.Getenv("OPENAI_API_KEY"), Model: model}
		var media func(context.Context) (string, error)
		if *importDraft {
			media = func(ctx context.Context) (string, error) { return e.ensureEditorialMedia(ctx, *site) }
		}
		result, err := generate(ctx, *root, *site, *brief, *spec, provider, model, media)
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
