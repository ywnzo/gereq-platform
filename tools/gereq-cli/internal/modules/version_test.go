package modules

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestBumpPatch(t *testing.T) {
	got, err := bumpPatch("2.1.1")
	if err != nil || got != "2.1.2" {
		t.Fatalf("bumpPatch(2.1.1) = %q %v, want 2.1.2", got, err)
	}
	got, err = bumpPatch("v1.0.9")
	if err != nil || got != "1.0.10" {
		t.Fatalf("bumpPatch(v1.0.9) = %q %v, want 1.0.10", got, err)
	}
	if _, err := bumpPatch("nope"); err == nil {
		t.Fatal("expected error for invalid semver")
	}
}

func TestCompareSemver(t *testing.T) {
	cmp, err := compareSemver("2.1.2", "v2.1.1")
	if err != nil || cmp != 1 {
		t.Fatalf("2.1.2 vs v2.1.1 = %d %v, want 1", cmp, err)
	}
	cmp, err = compareSemver("2.0.0", "2.1.0")
	if err != nil || cmp != -1 {
		t.Fatalf("2.0.0 vs 2.1.0 = %d %v, want -1", cmp, err)
	}
	cmp, err = compareSemver("1.2.3", "v1.2.3")
	if err != nil || cmp != 0 {
		t.Fatalf("equal = %d %v, want 0", cmp, err)
	}
	cmp, err = compareSemver("1.0.0", "NONE")
	if err != nil || cmp != 1 {
		t.Fatalf("vs NONE = %d %v, want 1", cmp, err)
	}
}

func TestSetModuleVersion(t *testing.T) {
	dir := t.TempDir()
	modPHP := `<?php
declare(strict_types=1);
return [
    'id' => 'demo',
    'version' => '1.0.0',
    'name' => 'Demo',
];
`
	modClass := `<?php
declare(strict_types=1);
namespace Modules\Demo;
final class DemoModule
{
    public function version(): string
    {
        return '1.0.0';
    }
}
`
	if err := os.WriteFile(filepath.Join(dir, "module.php"), []byte(modPHP), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "DemoModule.php"), []byte(modClass), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := setModuleVersion(dir, "1.0.1"); err != nil {
		t.Fatal(err)
	}
	body, _ := os.ReadFile(filepath.Join(dir, "module.php"))
	if !strings.Contains(string(body), "'1.0.1'") {
		t.Fatalf("module.php not updated: %s", body)
	}
	body, _ = os.ReadFile(filepath.Join(dir, "DemoModule.php"))
	if !strings.Contains(string(body), "'1.0.1'") {
		t.Fatalf("DemoModule.php not updated: %s", body)
	}
}

func TestPushPlan(t *testing.T) {
	action, skip := pushPlan(CompareRow{Status: "ok", Local: "1.0.0", GitLab: "v1.0.0"}, false)
	if !skip || action != "" {
		t.Fatalf("ok without patch: action=%q skip=%v", action, skip)
	}
	action, skip = pushPlan(CompareRow{Status: "ok", Local: "1.0.0", GitLab: "v1.0.0"}, true)
	if skip || action == "" {
		t.Fatalf("ok with patch: action=%q skip=%v", action, skip)
	}
	action, skip = pushPlan(CompareRow{Status: "DIFF", Local: "1.0.0", GitLab: "v1.0.1"}, false)
	if skip || !strings.HasPrefix(action, "skip:") {
		t.Fatalf("behind: action=%q skip=%v", action, skip)
	}
	action, skip = pushPlan(CompareRow{Status: "DIFF", Local: "1.0.2", GitLab: "v1.0.1"}, false)
	if skip || action != "push tag v1.0.2" {
		t.Fatalf("ahead: action=%q skip=%v", action, skip)
	}
}
