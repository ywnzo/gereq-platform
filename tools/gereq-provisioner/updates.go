package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"regexp"
	"strconv"
	"strings"
)

// UpdateJournal is a private, durable record. Storage snapshots may contain customer data.
type UpdateJournal struct {
	Schema              int    `json:"schema_version"`
	ID                  string `json:"id"`
	Site                string `json:"site_id"`
	Phase               string `json:"phase"`
	Original            State  `json:"original"`
	Target              State  `json:"target"`
	ImageID             string `json:"image_id"`
	OriginalImageID     string `json:"original_image_id"`
	EnvSHA256           string `json:"env_sha256"`
	BackupReady         bool   `json:"backup_ready"`
	EncryptedBackupID   string `json:"encrypted_backup_id,omitempty"`
	EncryptedRetainedID string `json:"encrypted_retained_id,omitempty"`
	Started             bool   `json:"started"`
	Error               string `json:"error,omitempty"`
}

var updateIDPattern = regexp.MustCompile(`^upd-[a-f0-9]{24}$`)
var imageIDPattern = regexp.MustCompile(`^sha256:[a-f0-9]{64}$`)

func normalizeImageID(raw string) string {
	id := strings.TrimSpace(raw)
	if regexp.MustCompile(`^[a-f0-9]{64}$`).MatchString(id) {
		id = "sha256:" + id
	}
	if !imageIDPattern.MatchString(id) {
		return ""
	}
	return id
}

func activeUpdate(p string) string { return filepath.Join(p, ".update-active.json") }
func (e Engine) updateDir(site, id string) (string, error) {
	if !sitePattern.MatchString(site) || !updateIDPattern.MatchString(id) {
		return "", errors.New("invalid update identifier")
	}
	root, err := filepath.Abs(e.Root)
	if err != nil {
		return "", err
	}
	p := filepath.Join(root, "updates", site, id)
	for q := p; ; q = filepath.Dir(q) {
		s, err := os.Lstat(q)
		if err == nil && s.Mode()&os.ModeSymlink != 0 {
			return "", errors.New("symlink in update path")
		}
		if err != nil && !os.IsNotExist(err) {
			return "", err
		}
		if q == filepath.Dir(q) {
			break
		}
	}
	return p, nil
}
func treeManifest(root string) (map[string]string, error) {
	files := map[string]string{}
	err := filepath.WalkDir(root, func(path string, ent fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(root, path)
		if err != nil {
			return err
		}
		info, err := ent.Info()
		if err != nil {
			return err
		}
		if info.IsDir() {
			return nil
		}
		if !info.Mode().IsRegular() {
			return errors.New("backup contains symlink or special file")
		}
		f, err := os.Open(path)
		if err != nil {
			return err
		}
		h := sha256.New()
		_, err = io.Copy(h, f)
		closeErr := f.Close()
		if err != nil {
			return err
		}
		if closeErr != nil {
			return closeErr
		}
		files[rel] = hex.EncodeToString(h.Sum(nil))
		return nil
	})
	return files, err
}
func fileSHA256(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err = io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}
func unchangedEnvironment(p, expected string) error {
	actual, err := fileSHA256(filepath.Join(p, ".env"))
	if err != nil {
		return err
	}
	if expected == "" || actual != expected {
		return errors.New("instance environment changed during update; manual reconciliation required")
	}
	return nil
}
func verifyBackup(job string) error {
	var expected map[string]string
	if err := decode(filepath.Join(job, "checksums.json"), &expected); err != nil {
		return err
	}
	actual, err := treeManifest(filepath.Join(job, "backup"))
	if err != nil {
		return err
	}
	if len(actual) != len(expected) {
		return errors.New("backup file count mismatch")
	}
	for k, v := range expected {
		if actual[k] != v {
			return errors.New("backup integrity verification failed")
		}
	}
	return nil
}

type imageProvenance struct {
	ModuleSelection bool                `json:"module_selection,omitempty"`
	ModuleRequires  map[string][]string `json:"module_requires,omitempty"`
	Development     bool                `json:"development"`
	ReleaseID       string              `json:"release_id"`
	PlatformVersion string              `json:"platform_version"`
	ModuleVersions  map[string]string   `json:"module_versions"`
}

var versionPattern = regexp.MustCompile(`^[0-9]+\.[0-9]+\.[0-9]+$`)

func versionAtLeast(candidate, existing string) bool {
	if !versionPattern.MatchString(candidate) || !versionPattern.MatchString(existing) {
		return false
	}
	a, b := strings.Split(candidate, "."), strings.Split(existing, ".")
	for i := range a {
		x, xe := strconv.ParseUint(a[i], 10, 32)
		y, ye := strconv.ParseUint(b[i], 10, 32)
		if xe != nil || ye != nil {
			return false
		}
		if x > y {
			return true
		}
		if x < y {
			return false
		}
	}
	return true
}
func (e Engine) imageProvenance(ctx context.Context, id string) (imageProvenance, error) {
	var info imageProvenance
	out, err := e.Runner.Run(ctx, []string{"run", "--rm", "--network", "none", "--read-only", "--entrypoint", "cat", id, "/app/PROVISION_SOURCE.json"})
	if err != nil || json.Unmarshal(out, &info) != nil || !versionPattern.MatchString(info.PlatformVersion) || info.ReleaseID == "" {
		return info, errors.New("release image provenance unavailable or invalid")
	}
	return info, nil
}
func (e Engine) updateImage(ctx context.Context, release Release) (string, error) {
	out, err := e.Runner.Run(ctx, []string{"image", "inspect", "--format", "{{.Id}}", release.Image})
	if err != nil {
		return "", err
	}
	id := normalizeImageID(string(out))
	if id == "" {
		return "", errors.New("invalid image identity")
	}
	info, err := e.imageProvenance(ctx, id)
	if err != nil {
		return "", err
	}
	if release.ModuleSelection && !info.ModuleSelection {
		return "", errors.New("image does not support package module selection")
	}
	if info.ReleaseID != release.ID {
		return "", errors.New("release image does not match release metadata")
	}
	for _, module := range release.Modules {
		if !versionPattern.MatchString(info.ModuleVersions[module]) {
			return "", errors.New("release image missing valid module provenance")
		}
	}
	return id, nil
}
func (e Engine) checkPlatformUpgrade(ctx context.Context, oldID, newID string, oldRelease Release) error {
	before, err := e.imageProvenance(ctx, oldID)
	if err != nil {
		return err
	}
	after, err := e.imageProvenance(ctx, newID)
	if err != nil {
		return err
	}
	if before.ReleaseID != oldRelease.ID {
		return errors.New("running image does not match current release")
	}
	for _, module := range oldRelease.Modules {
		if !versionAtLeast(after.ModuleVersions[module], before.ModuleVersions[module]) {
			return errors.New("module downgrade or missing module provenance")
		}
	}
	if !versionAtLeast(after.PlatformVersion, before.PlatformVersion) {
		return errors.New("platform downgrade or unsupported version")
	}
	return nil
}

// Run inside the target image against the stopped customer database; output contains no customer data.
const moduleVersionCheck = `require "/app/vendor/autoload.php";
$source=json_decode(file_get_contents("/app/PROVISION_SOURCE.json"),true,32,JSON_THROW_ON_ERROR);
$versions=$source["module_versions"]??[];
$paths=["cms"=>"Cms","site_inquiries"=>"SiteInquiries","analytics"=>"Analytics"];
$db=new PDO("sqlite:file:/app/storage/database.sqlite?mode=ro");
$rows=$db->query("SELECT id,version,enabled,footprint FROM modules")->fetchAll(PDO::FETCH_ASSOC);
$required=json_decode($argv[2]??"null",true,32,JSON_THROW_ON_ERROR);
if(!is_array($required)||$required===[])throw new RuntimeException("Missing package module selection");
$seen=[];
foreach($rows as $row){
 $id=$row["id"];if(!$row["enabled"])continue;
 if(!in_array($id,$required,true))throw new RuntimeException("Unselected module is enabled");
 if(!isset($paths[$id],$versions[$id]))throw new RuntimeException("Enabled module missing from release");
 $seen[$id]=true;$old=(string)$row["version"];$next=(string)$versions[$id];
 if(!preg_match("/^\\d+\\.\\d+\\.\\d+$/D",$old)||!preg_match("/^\\d+\\.\\d+\\.\\d+$/D",$next))throw new RuntimeException("Unsupported module version");
 if(version_compare($next,$old,"<"))throw new RuntimeException("Module downgrade refused");
 if(($argv[1]??"")==="post" && $old!==$next)throw new RuntimeException("Module update incomplete");
 if($old===$next){$actual=Gereq\Module\ModuleFootprint::ofDirectory("/app/modules/".$paths[$id]);if(!$row["footprint"]||$actual!==$row["footprint"])throw new RuntimeException("Same-version module footprint differs");}
}
foreach($required as $id){if(!isset($seen[$id]))throw new RuntimeException("Required module is disabled or absent");}
if($db->query("PRAGMA integrity_check")->fetchColumn()!=="ok")throw new RuntimeException("Database integrity failed");
echo json_encode(["ok"=>true],JSON_THROW_ON_ERROR);`

func (e Engine) checkModules(ctx context.Context, s *State, p, phase string, running bool) error {
	required, _ := json.Marshal(instanceModules(s))
	var args []string
	if running {
		args = compose(s, p, "exec", "-T", "app", "php", "-r", moduleVersionCheck, phase, string(required))
	} else {
		args = compose(s, p, "run", "--rm", "--no-deps", "app", "php", "-r", moduleVersionCheck, phase, string(required))
	}
	out, err := e.Runner.Run(ctx, args)
	if err != nil {
		return errors.New("module compatibility check failed; refusing upgrade")
	}
	return parseModuleCheck(out)
}

// podman-compose can print a newly created network ID before command output.
func parseModuleCheck(out []byte) error {
	lines := strings.Split(strings.TrimSpace(string(out)), "\n")
	if len(lines) == 0 {
		return errors.New("invalid module compatibility response")
	}
	for _, line := range lines[:len(lines)-1] {
		if !regexp.MustCompile(`^[a-f0-9]{64}$`).MatchString(strings.TrimSpace(line)) {
			return errors.New("invalid module compatibility response")
		}
	}
	var result struct {
		OK bool `json:"ok"`
	}
	if json.Unmarshal([]byte(strings.TrimSpace(lines[len(lines)-1])), &result) != nil || !result.OK {
		return errors.New("invalid module compatibility response")
	}
	return nil
}
func updateConfig(raw []byte, target State, imageID string) ([]byte, error) {
	var cfg map[string]any
	if json.Unmarshal(raw, &cfg) != nil {
		return nil, errors.New("invalid instance compose")
	}
	services, ok := cfg["services"].(map[string]any)
	if !ok {
		return nil, errors.New("invalid compose services")
	}
	app, ok := services["app"].(map[string]any)
	if !ok {
		return nil, errors.New("invalid compose app")
	}
	labels, ok := app["labels"].(map[string]any)
	if !ok {
		return nil, errors.New("missing instance labels")
	}
	if labels["gereq.site"] != target.Request.SiteID {
		return nil, errors.New("compose ownership mismatch")
	}
	volumes, ok := app["volumes"].([]any)
	if !ok {
		return nil, errors.New("invalid compose volumes")
	}
	for _, item := range volumes {
		volume, ok := item.(map[string]any)
		if !ok {
			return nil, errors.New("unsupported compose volume")
		}
		targetPath, ok := volume["target"].(string)
		if !ok {
			return nil, errors.New("invalid compose volume target")
		}
		if targetPath != "/app/storage" && targetPath != "/app/.env" {
			return nil, errors.New("custom mounts require manual upgrade reconciliation")
		}
	}
	app["image"] = imageID
	labels["gereq.fingerprint"] = target.Fingerprint
	b, err := json.MarshalIndent(cfg, "", "  ")
	return append(b, '\n'), err
}

// Check that compose and SQLite point only to this provisioner's owned instance.
func checkUpdateConfig(raw []byte, s *State, p string) error {
	return checkUpdateConfigRecovery(raw, s, p, false)
}
func checkUpdateConfigRecovery(raw []byte, s *State, p string, allowMissingStorage bool) error {
	var cfg map[string]any
	if json.Unmarshal(raw, &cfg) != nil {
		return errors.New("invalid instance compose")
	}
	services, ok := cfg["services"].(map[string]any)
	if !ok || len(services) != 1 {
		return errors.New("custom compose services require manual reconciliation")
	}
	app, ok := services["app"].(map[string]any)
	if !ok {
		return errors.New("invalid compose app")
	}
	labels, ok := app["labels"].(map[string]any)
	if !ok || labels["gereq.site"] != s.Request.SiteID || labels["gereq.fingerprint"] != s.Fingerprint || labels["gereq.instance"] != p || app["container_name"] != projectName(s.Request.SiteID, p)+"-app" {
		return errors.New("compose ownership mismatch")
	}
	ports, ok := app["ports"].([]any)
	if !ok || len(ports) != 1 || ports[0] != fmt.Sprintf("127.0.0.1:%d:8080", s.Request.Port) {
		return errors.New("unexpected compose port")
	}
	volumes, ok := app["volumes"].([]any)
	if !ok || len(volumes) != 2 {
		return errors.New("custom mounts require manual reconciliation")
	}
	expected := map[string]string{"/app/storage": filepath.Join(p, "storage"), "/app/.env": filepath.Join(p, ".env")}
	seen := map[string]bool{}
	for _, v := range volumes {
		m, ok := v.(map[string]any)
		if !ok {
			return errors.New("unsupported volume")
		}
		target, ok := m["target"].(string)
		if !ok || seen[target] || m["type"] != "bind" || m["source"] != expected[target] || expected[target] == "" {
			return errors.New("unsafe instance mount")
		}
		if target == "/app/.env" && m["read_only"] != true {
			return errors.New("environment mount must be read only")
		}
		seen[target] = true
	}
	for _, name := range []string{".env", "storage"} {
		info, err := os.Lstat(filepath.Join(p, name))
		if err != nil {
			if name == "storage" && allowMissingStorage && os.IsNotExist(err) {
				continue
			}
			return err
		}
		if info.Mode()&os.ModeSymlink != 0 {
			return errors.New("symlinked instance data refused")
		}
	}
	env, err := os.ReadFile(filepath.Join(p, ".env"))
	if err != nil {
		return err
	}
	settings := map[string]string{}
	counts := map[string]int{}
	for _, line := range strings.Split(string(env), "\n") {
		pair := strings.SplitN(strings.TrimSpace(line), "=", 2)
		if len(pair) == 2 && (pair[0] == "DB_CONNECTION" || pair[0] == "DB_DATABASE") {
			counts[pair[0]]++
			settings[pair[0]] = strings.Trim(strings.TrimSpace(pair[1]), "\"'")
		}
	}
	if counts["DB_CONNECTION"] != 1 || counts["DB_DATABASE"] != 1 || settings["DB_CONNECTION"] != "sqlite" || settings["DB_DATABASE"] != "/app/storage/database.sqlite" {
		return errors.New("unsupported customer database configuration")
	}
	return nil
}
func (e Engine) PlanUpdate(ctx context.Context, site string, release Release) (map[string]any, error) {
	if _, err := exec.LookPath("restic"); err != nil {
		return nil, errors.New("restic is required for encrypted rollback backups")
	}
	if _, err := (&backupAPI{}).vaultKey(); err != nil {
		return nil, errors.New("configure the private backup master key before updating")
	}
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	s, p, err := e.load(site)
	if err != nil {
		return nil, err
	}
	if s.RelocatedTo != "" || (s.Status != "ready" && s.Status != "needs_customer_setup") {
		return nil, errors.New("instance must be running here")
	}
	if !s.Release.Development || !release.Development {
		return nil, errors.New("this update workflow is local development only")
	}
	request := s.Request
	if len(s.ActiveModules) > 0 {
		request.EnabledModules = instanceModules(s)
	}
	request.Release = release.ID
	if err = validate(request, release); err != nil {
		return nil, err
	}
	if s.Release.ID == release.ID {
		return nil, errors.New("target release is already installed")
	}
	if err = e.checkOwner(ctx, s, p); err != nil {
		return nil, err
	}
	id, err := e.updateImage(ctx, release)
	if err != nil {
		return nil, err
	}
	current, err := e.Runner.Run(ctx, []string{"inspect", "--format", "{{.Image}}", projectName(site, p) + "-app"})
	if err != nil || normalizeImageID(string(current)) == "" {
		return nil, errors.New("cannot pin current instance image")
	}
	oldID := normalizeImageID(string(current))
	if err = e.checkPlatformUpgrade(ctx, oldID, id, s.Release); err != nil {
		return nil, err
	}
	cfg, err := os.ReadFile(filepath.Join(p, "compose.json"))
	if err != nil {
		return nil, err
	}
	if err = checkUpdateConfig(cfg, s, p); err != nil {
		return nil, err
	}
	if _, err = updateConfig(cfg, *s, oldID); err != nil {
		return nil, err
	}
	return map[string]any{"site_id": site, "current_release": s.Release.ID, "target_release": release.ID, "image_id": id, "module_check": "runs after stopped backup", "mutations": false}, nil
}
func (e Engine) Update(ctx context.Context, site string, release Release, expectedImageID string) (*UpdateJournal, error) {
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	s, p, err := e.load(site)
	if err != nil {
		return nil, err
	}
	if s.RelocatedTo != "" || (s.Status != "ready" && s.Status != "needs_customer_setup") {
		return nil, errors.New("instance must be running here")
	}
	if !s.Release.Development || !release.Development {
		return nil, errors.New("this update workflow is local development only")
	}
	req := s.Request
	if len(s.ActiveModules) > 0 {
		req.EnabledModules = instanceModules(s)
	}
	req.Release = release.ID
	if err = validate(req, release); err != nil {
		return nil, err
	}
	if s.Release.ID == release.ID {
		return nil, errors.New("target release is already installed")
	}
	if err = e.checkOwner(ctx, s, p); err != nil {
		return nil, err
	}
	imageID, err := e.updateImage(ctx, release)
	if err != nil {
		return nil, err
	}
	if expectedImageID == "" || normalizeImageID(expectedImageID) != imageID {
		return nil, errors.New("target image changed or --expected-image-id does not match the update plan")
	}
	currentImage, err := e.Runner.Run(ctx, []string{"inspect", "--format", "{{.Image}}", projectName(site, p) + "-app"})
	if err != nil || normalizeImageID(string(currentImage)) == "" {
		return nil, errors.New("cannot pin current instance image")
	}
	originalImageID := normalizeImageID(string(currentImage))
	if err = e.checkPlatformUpgrade(ctx, originalImageID, imageID, s.Release); err != nil {
		return nil, err
	}
	configRaw, err := os.ReadFile(filepath.Join(p, "compose.json"))
	if err != nil {
		return nil, err
	}
	if err = checkUpdateConfig(configRaw, s, p); err != nil {
		return nil, err
	}
	if _, err = updateConfig(configRaw, *s, originalImageID); err != nil {
		return nil, err
	}
	secret, err := randomSecret()
	if err != nil {
		return nil, err
	}
	id := "upd-" + secret[:24]
	job, err := e.updateDir(site, id)
	if err != nil {
		return nil, err
	}
	if err = os.MkdirAll(filepath.Dir(job), 0700); err != nil {
		return nil, err
	}
	if err = os.Mkdir(job, 0700); err != nil {
		return nil, err
	}
	archive, err := createEncryptedUpdateRepository(ctx, job)
	if err != nil {
		return nil, err
	}
	envHash, err := fileSHA256(filepath.Join(p, ".env"))
	if err != nil {
		return nil, err
	}
	target := *s
	target.Request = req
	target.LastUpdateID = id
	target.Release = release
	target.Fingerprint = fingerprint(req, release)
	target.Status = s.Status
	target.Error = ""
	j := &UpdateJournal{Schema: 1, ID: id, Site: site, Phase: "prepared", Original: *s, Target: target, ImageID: imageID, OriginalImageID: originalImageID, EnvSHA256: envHash}
	save := func() error { return atomicJSON(filepath.Join(job, "journal.json"), j) }
	if err = save(); err != nil {
		return j, err
	}
	if err = atomicJSON(activeUpdate(p), map[string]string{"id": id}); err != nil {
		return j, err
	}
	fail := func(cause error) (*UpdateJournal, error) {
		j.Error = cause.Error()
		_ = save()
		return j, fmt.Errorf("update %s stopped at %s; use update-recover: %w", id, j.Phase, cause)
	}
	if _, err = e.Runner.Run(ctx, compose(s, p, "stop")); err != nil {
		return fail(err)
	}
	j.Phase = "stopped"
	if err = save(); err != nil {
		return fail(err)
	}
	if _, err = e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "storage-check")); err != nil {
		return fail(err)
	}
	backup := filepath.Join(job, "backup")
	if err = os.Mkdir(backup, 0700); err != nil {
		return fail(err)
	}
	for _, name := range []string{"storage", "state.json", "compose.json"} {
		if err = copyVerified(filepath.Join(p, name), filepath.Join(backup, name)); err != nil {
			return fail(err)
		}
	}
	// Pin rollback to the exact image that was actually running, not a mutable tag.
	rollbackConfig, err := updateConfig(configRaw, *s, originalImageID)
	if err != nil {
		return fail(err)
	}
	if err = atomicFile(filepath.Join(backup, "compose.json"), rollbackConfig); err != nil {
		return fail(err)
	}
	sums, err := treeManifest(backup)
	if err != nil {
		return fail(err)
	}
	if err = atomicJSON(filepath.Join(job, "checksums.json"), sums); err != nil {
		return fail(err)
	}
	if err = verifyBackup(job); err != nil {
		return fail(err)
	}
	if err = unchangedEnvironment(p, envHash); err != nil {
		return fail(err)
	}
	rollbackConfigData, err := os.ReadFile(filepath.Join(backup, "compose.json"))
	if err != nil {
		return fail(err)
	}
	j.EncryptedBackupID, err = encryptUpdateDirectory(ctx, archive, backup)
	if err != nil {
		return fail(err)
	}
	j.BackupReady = true
	j.Phase = "backed_up"
	if err = save(); err != nil {
		return fail(err)
	}
	if err = os.RemoveAll(backup); err != nil {
		return fail(err)
	}
	if _, err = e.Runner.Run(ctx, compose(s, p, "down")); err != nil {
		return fail(err)
	}
	nextConfig, err := updateConfig(rollbackConfigData, target, imageID)
	if err != nil {
		return fail(err)
	}
	j.Phase = "configuring"
	if err = save(); err != nil {
		return fail(err)
	}
	if err = atomicFile(filepath.Join(p, "compose.json"), nextConfig); err != nil {
		return fail(err)
	}
	if err = e.save(&target, p); err != nil {
		return fail(err)
	}
	j.Phase = "checking_modules"
	if err = save(); err != nil {
		return fail(err)
	}
	if err = e.checkModules(ctx, &target, p, "pre", false); err != nil {
		return fail(err)
	}
	j.Phase = "migrating"
	if err = save(); err != nil {
		return fail(err)
	}
	if _, err = e.Runner.Run(ctx, compose(&target, p, "run", "--rm", "--no-deps", "app", "php", "/opt/provision/bridge.php", "upgrade")); err != nil {
		return fail(err)
	}
	j.Started = true
	j.Phase = "starting"
	if err = save(); err != nil {
		return fail(err)
	}
	if err = e.step(ctx, &target, p, "health"); err != nil {
		return fail(err)
	}
	if err = e.checkModules(ctx, &target, p, "post", true); err != nil {
		return fail(err)
	}
	j.Phase = "complete"
	if err = save(); err != nil {
		return fail(err)
	}
	if err = os.Remove(activeUpdate(p)); err != nil {
		return fail(err)
	}
	return j, nil
}

// Recover stops the current runtime and restores the verified pre-update snapshot.
// Data produced since the snapshot is retained in failed-storage and is not merged.
func (e Engine) RecoverUpdate(ctx context.Context, site string, acceptDataLoss bool) (*UpdateJournal, error) {
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	p, err := ownedDir(e.Root, site)
	if err != nil {
		return nil, err
	}
	var marker struct {
		ID string `json:"id"`
	}
	if err = decode(activeUpdate(p), &marker); err != nil {
		return nil, errors.New("no active update to recover")
	}
	job, err := e.updateDir(site, marker.ID)
	if err != nil {
		return nil, err
	}
	var j UpdateJournal
	if err = decode(filepath.Join(job, "journal.json"), &j); err != nil {
		return nil, err
	}
	if j.Site != site || j.ID != marker.ID || j.Schema != 1 {
		return nil, errors.New("invalid update journal")
	}
	if err = unchangedEnvironment(p, j.EnvSHA256); err != nil {
		return &j, err
	}
	configRaw, err := os.ReadFile(filepath.Join(p, "compose.json"))
	if err != nil {
		return &j, err
	}
	if checkUpdateConfigRecovery(configRaw, &j.Original, p, j.BackupReady && j.Phase == "restoring") != nil && checkUpdateConfigRecovery(configRaw, &j.Target, p, j.BackupReady && j.Phase == "restoring") != nil {
		return &j, errors.New("instance configuration does not match update journal")
	}
	if j.Phase == "complete" || j.Phase == "recovered" {
		current := &j.Target
		if j.Phase == "recovered" {
			current = &j.Original
		}
		if err = checkUpdateConfig(configRaw, current, p); err != nil {
			return &j, err
		}
		if err = e.checkOwner(ctx, current, p); err != nil {
			return &j, err
		}
		if err = e.step(ctx, current, p, "health"); err != nil {
			return &j, err
		}
		return &j, os.Remove(activeUpdate(p))
	}
	if j.Started && !acceptDataLoss {
		return &j, errors.New("new runtime may have accepted writes; retry with --accept-data-loss after reviewing recovery implications")
	}
	if !j.BackupReady {
		if j.Phase != "prepared" && j.Phase != "stopped" {
			return &j, errors.New("no verified backup; manual reconciliation required")
		}
		if err = checkUpdateConfig(configRaw, &j.Original, p); err != nil {
			return &j, err
		}
		if normalizeImageID(j.OriginalImageID) == "" {
			return &j, errors.New("original image is not pinned; manual reconciliation required")
		}
		pinned, err := updateConfig(configRaw, j.Original, j.OriginalImageID)
		if err != nil {
			return &j, err
		}
		if err = atomicFile(filepath.Join(p, "compose.json"), pinned); err != nil {
			return &j, err
		}
		if err = e.checkOwner(ctx, &j.Original, p); err != nil {
			return &j, err
		}
		if err = e.step(ctx, &j.Original, p, "health"); err != nil {
			return &j, err
		}
		if err = os.RemoveAll(filepath.Join(job, "backup")); err != nil {
			return &j, err
		}
		j.Phase = "recovered"
		if err = atomicJSON(filepath.Join(job, "journal.json"), j); err != nil {
			return &j, err
		}
		return &j, os.Remove(activeUpdate(p))
	}
	if err = materializeUpdateBackup(ctx, job, &j); err != nil {
		return &j, err
	}
	if j.EncryptedBackupID != "" {
		defer os.RemoveAll(filepath.Join(job, "backup"))
	}
	if err = verifyBackup(job); err != nil {
		return &j, err
	}
	// Check ownership against both possible revisions; do not touch an unfamiliar container.
	if err = e.checkOwner(ctx, &j.Target, p); err != nil {
		if err = e.checkOwner(ctx, &j.Original, p); err != nil {
			return &j, err
		}
	}
	if _, err = e.Runner.Run(ctx, compose(&j.Original, p, "down")); err != nil {
		return &j, err
	}
	retained := filepath.Join(job, "failed-storage")
	storage := filepath.Join(p, "storage")
	if j.Phase != "restoring" && j.Phase != "restored_storage" {
		j.Phase = "restoring"
		if err = atomicJSON(filepath.Join(job, "journal.json"), j); err != nil {
			return &j, err
		}
	}
	if j.Phase == "restoring" {
		if _, err = os.Lstat(retained); os.IsNotExist(err) {
			if err = os.Rename(storage, retained); err != nil {
				return &j, err
			}
		} else if err != nil {
			return &j, err
		}
		if _, err = os.Lstat(storage); os.IsNotExist(err) {
			stage, stageErr := newUpdateTemp(job, ".restore-")
			if stageErr != nil {
				return &j, stageErr
			}
			candidate := filepath.Join(stage, "storage")
			if err = copyVerified(filepath.Join(job, "backup", "storage"), candidate); err != nil {
				return &j, err
			}
			if err = os.Rename(candidate, storage); err != nil {
				return &j, err
			}
			_ = os.RemoveAll(stage)
		} else if err != nil {
			return &j, err
		} else {
			actual, err := treeManifest(storage)
			if err != nil {
				return &j, err
			}
			expected, err := treeManifest(filepath.Join(job, "backup", "storage"))
			if err != nil {
				return &j, err
			}
			if !reflect.DeepEqual(actual, expected) {
				return &j, errors.New("restored storage changed; manual reconciliation required")
			}
		}
		j.Phase = "restored_storage"
		if err = atomicJSON(filepath.Join(job, "journal.json"), j); err != nil {
			return &j, err
		}
	} else {
		if _, err = os.Lstat(retained); err != nil && (j.EncryptedRetainedID == "" || !os.IsNotExist(err)) {
			return &j, errors.New("retained data unavailable")
		}
		if _, err = os.Lstat(storage); err != nil {
			return &j, errors.New("restored storage unavailable")
		}
		if _, err = os.Lstat(retained); os.IsNotExist(err) && j.EncryptedRetainedID != "" {
			c, keyErr := updateVaultConfig(job)
			if keyErr != nil {
				return &j, keyErr
			}
			if verifyErr := verifyEncryptedUpdateExists(ctx, c, j.EncryptedRetainedID, retained); verifyErr != nil {
				return &j, verifyErr
			}
		}
	}
	if j.EncryptedBackupID != "" {
		c, err := updateVaultConfig(job)
		if err != nil {
			return &j, err
		}
		if j.EncryptedRetainedID == "" {
			j.EncryptedRetainedID, err = encryptUpdateDirectory(ctx, c, retained)
			if err != nil {
				return &j, err
			}
			if err = atomicJSON(filepath.Join(job, "journal.json"), j); err != nil {
				return &j, err
			}
		}
		if _, err = os.Lstat(retained); err == nil {
			if err = verifyEncryptedUpdateDirectory(ctx, c, j.EncryptedRetainedID, retained); err != nil {
				return &j, err
			}
			if err = os.RemoveAll(retained); err != nil {
				return &j, err
			}
		} else if !os.IsNotExist(err) {
			return &j, err
		}
	}
	for _, name := range []string{"compose.json", "state.json"} {
		raw, readErr := os.ReadFile(filepath.Join(job, "backup", name))
		if readErr != nil {
			return &j, readErr
		}
		if err = atomicFile(filepath.Join(p, name), raw); err != nil {
			return &j, err
		}
	}
	if err = e.step(ctx, &j.Original, p, "health"); err != nil {
		return &j, err
	}
	j.Phase = "recovered"
	j.Error = ""
	if err = atomicJSON(filepath.Join(job, "journal.json"), j); err != nil {
		return &j, err
	}
	if err = os.Remove(activeUpdate(p)); err != nil {
		return &j, err
	}
	return &j, nil
}

// RollbackUpdate is an explicit operator action: all later customer writes remain
// in failed-storage for manual reconciliation, but are not present after rollback.
func (e Engine) RollbackUpdate(ctx context.Context, site, id string, acceptDataLoss bool) (*UpdateJournal, error) {
	if !acceptDataLoss {
		return nil, errors.New("rollback may discard later writes; --accept-data-loss is required")
	}
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	s, p, err := e.load(site)
	if err != nil {
		unlock()
		return nil, err
	}
	job, err := e.updateDir(site, id)
	if err != nil {
		unlock()
		return nil, err
	}
	var j UpdateJournal
	if err = decode(filepath.Join(job, "journal.json"), &j); err != nil {
		unlock()
		return nil, err
	}
	if j.Schema != 1 || j.ID != id || j.Site != site || j.Phase != "complete" || !j.BackupReady || s.Fingerprint != j.Target.Fingerprint || s.LastUpdateID != id {
		unlock()
		return nil, errors.New("update is not the active completed release")
	}
	if err = materializeUpdateBackup(ctx, job, &j); err != nil {
		unlock()
		return nil, err
	}
	if j.EncryptedBackupID != "" {
		defer os.RemoveAll(filepath.Join(job, "backup"))
	}
	if err = verifyBackup(job); err != nil {
		unlock()
		return nil, err
	}
	if err = unchangedEnvironment(p, j.EnvSHA256); err != nil {
		unlock()
		return nil, err
	}
	if err = e.checkOwner(ctx, s, p); err != nil {
		unlock()
		return nil, err
	}
	if err = atomicJSON(activeUpdate(p), map[string]string{"id": id}); err != nil {
		unlock()
		return nil, err
	}
	j.Phase = "rollback_requested"
	j.Started = true
	if err = atomicJSON(filepath.Join(job, "journal.json"), j); err != nil {
		unlock()
		return nil, err
	}
	unlock()
	return e.RecoverUpdate(ctx, site, true)
}

// UpdateStatus exposes only operational metadata; never customer data or secrets.
func (e Engine) UpdateStatus(site, id string) (map[string]any, error) {
	unlock, err := e.lock()
	if err != nil {
		return nil, err
	}
	defer unlock()
	p, err := ownedDir(e.Root, site)
	if err != nil {
		return nil, err
	}
	if id == "" {
		var marker struct {
			ID string `json:"id"`
		}
		if err = decode(activeUpdate(p), &marker); err != nil {
			return nil, errors.New("no active update; specify --update-id")
		}
		id = marker.ID
	}
	job, err := e.updateDir(site, id)
	if err != nil {
		return nil, err
	}
	var j UpdateJournal
	if err = decode(filepath.Join(job, "journal.json"), &j); err != nil {
		return nil, err
	}
	if j.Schema != 1 || j.ID != id || j.Site != site {
		return nil, errors.New("invalid update journal")
	}
	return map[string]any{"site_id": site, "update_id": id, "status": j.Phase, "from_release": j.Original.Release.ID, "to_release": j.Target.Release.ID, "backup_ready": j.BackupReady, "runtime_may_have_accepted_writes": j.Started, "error": j.Error}, nil
}
