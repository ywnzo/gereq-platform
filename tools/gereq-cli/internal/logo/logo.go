package logo

import (
	"crypto/sha256"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// Kind maps domain types to lucide-ish icon hints and palette keys.
var KindIcons = map[string]string{
	"realestate":  "home",
	"fleet":       "truck",
	"commerce":    "shopping-bag",
	"crm":         "users",
	"content":     "file-text",
	"integration": "plug",
	"generic":     "modules",
	"warehouse":   "warehouse",
	"payments":    "credit-card",
}

func InferKind(name, id string) string {
	s := strings.ToLower(name + " " + id)
	switch {
	case strings.Contains(s, "list") || strings.Contains(s, "viewing") || strings.Contains(s, "real") || strings.Contains(s, "estate"):
		return "realestate"
	case strings.Contains(s, "fleet") || strings.Contains(s, "car") || strings.Contains(s, "bike") || strings.Contains(s, "vehicle"):
		return "fleet"
	case strings.Contains(s, "shop") || strings.Contains(s, "order") || strings.Contains(s, "product") || strings.Contains(s, "coupon"):
		return "commerce"
	case strings.Contains(s, "crm") || strings.Contains(s, "customer") || strings.Contains(s, "loyalty"):
		return "crm"
	case strings.Contains(s, "cms") || strings.Contains(s, "media") || strings.Contains(s, "document") || strings.Contains(s, "news"):
		return "content"
	case strings.Contains(s, "webhook") || strings.Contains(s, "pay") || strings.Contains(s, "mail") || strings.Contains(s, "api") || strings.Contains(s, "idoklad") || strings.Contains(s, "money"):
		return "integration"
	case strings.Contains(s, "ware"):
		return "warehouse"
	default:
		return "generic"
	}
}

func Write(moduleDir, moduleID, displayName, kind string) error {
	assets := filepath.Join(moduleDir, "assets")
	if err := os.MkdirAll(assets, 0o775); err != nil {
		return err
	}
	svg := BuildSVG(moduleID, displayName, kind)
	svgPath := filepath.Join(assets, "logo.svg")
	if err := os.WriteFile(svgPath, []byte(svg), 0o644); err != nil {
		return err
	}
	pngPath := filepath.Join(assets, "logo.png")
	if _, err := exec.LookPath("magick"); err == nil {
		_ = exec.Command("magick", "-background", "none", svgPath, "-resize", "512x512", pngPath).Run()
	}
	return nil
}

func BuildSVG(moduleID, name, kind string) string {
	bg, accent := colorsFor(moduleID, kind)
	mark := glyphFor(moduleID, kind)
	return fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-label="%s module logo">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%%" stop-color="%s"/>
      <stop offset="100%%" stop-color="%s" stop-opacity="0.35"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" rx="112" fill="url(#bg)"/>
  <circle cx="256" cy="256" r="168" fill="%s" opacity="0.16"/>
  <g fill="#fff" stroke="none">%s</g>
</svg>
`, name, bg, accent, accent, mark)
}

func colorsFor(id, kind string) (string, string) {
	kindPalette := map[string][2]string{
		"realestate":  {"#20301c", "#8fd47a"},
		"fleet":       {"#1a2030", "#7aa2ff"},
		"commerce":    {"#281c18", "#f0a070"},
		"crm":         {"#1a2230", "#8aa4ff"},
		"content":     {"#1a2433", "#8bb4ff"},
		"integration": {"#1c3028", "#5ed6a8"},
		"warehouse":   {"#28301c", "#b4d46a"},
		"payments":    {"#1a3028", "#5ed0a8"},
		"generic":     {"#0f1a14", "#d7ff45"},
	}
	if p, ok := kindPalette[kind]; ok {
		return p[0], p[1]
	}
	sum := sha256.Sum256([]byte(id))
	hue := int(sum[0]) * 360 / 255
	return fmt.Sprintf("hsl(%d 28%% 14%%)", hue), fmt.Sprintf("hsl(%d 70%% 62%%)", (hue+40)%360)
}

func glyphFor(id, kind string) string {
	// Prefer known module glyphs by id substring / kind defaults.
	glyphs := map[string]string{
		"realestate": `
            <path d="M256 120l140 120v160a24 24 0 0 1-24 24H140a24 24 0 0 1-24-24V240z" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/>
            <rect x="210" y="280" width="92" height="100" rx="10"/>`,
		"fleet": `
            <path d="M120 300h272M150 300v45M370 300v45" stroke="#fff" stroke-width="24" stroke-linecap="round"/>
            <path d="M140 300c18-65 48-95 90-95h60c42 0 68 30 90 95" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="190" cy="300" r="26" fill="none" stroke="#fff" stroke-width="18"/>
            <circle cx="340" cy="300" r="26" fill="none" stroke="#fff" stroke-width="18"/>`,
		"commerce": `
            <path d="M160 180h192l-20 180H180z" fill="none" stroke="#fff" stroke-width="26" stroke-linejoin="round"/>
            <path d="M200 180v-20a56 56 0 0 1 112 0v20" fill="none" stroke="#fff" stroke-width="26"/>`,
		"crm": `
            <rect x="150" y="150" width="212" height="260" rx="24" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="256" cy="230" r="40"/>
            <path d="M190 340c20-40 50-55 66-55s46 15 66 55" fill="none" stroke="#fff" stroke-width="22" stroke-linecap="round"/>`,
		"content": `
            <rect x="120" y="130" width="272" height="252" rx="28" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M120 200h272" stroke="#fff" stroke-width="28"/>
            <rect x="155" y="235" width="120" height="18" rx="6"/>
            <rect x="155" y="275" width="200" height="14" rx="6" opacity=".8"/>`,
		"integration": `
            <circle cx="180" cy="256" r="50" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="332" cy="256" r="50" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M230 256h52" stroke="#fff" stroke-width="26" stroke-linecap="round"/>`,
		"warehouse": `
            <path d="M120 220l136-80 136 80v160H120z" fill="none" stroke="#fff" stroke-width="26" stroke-linejoin="round"/>
            <path d="M200 380V260h112v120" fill="none" stroke="#fff" stroke-width="26"/>`,
		"payments": `
            <rect x="110" y="180" width="292" height="160" rx="28" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M110 230h292" stroke="#fff" stroke-width="28"/>
            <rect x="150" y="280" width="90" height="28" rx="8"/>`,
		"generic": `
            <path d="M256 110l120 70v140l-120 70-120-70V180z" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/>
            <path d="M256 200v120M210 250h92" stroke="#fff" stroke-width="26" stroke-linecap="round"/>`,
	}
	if g, ok := glyphs[id]; ok {
		return g
	}
	if g, ok := glyphs[kind]; ok {
		return g
	}
	return glyphs["generic"]
}

func IconHint(kind string) string {
	if v, ok := KindIcons[kind]; ok {
		return v
	}
	return "modules"
}
