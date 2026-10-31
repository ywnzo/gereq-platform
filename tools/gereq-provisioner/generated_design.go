package main

import (
	"crypto/sha256"
	"errors"
	"fmt"
	"math"
	"regexp"
	"strings"
)

// DesignNode is a constrained, recursive layout authored by the model. Only the
// compiler can create real CMS blocks, actions, links and native design properties.
type DesignNode struct {
	Kind       string       `json:"kind"`
	Text       string       `json:"text"`
	Background string       `json:"background"`
	Foreground string       `json:"foreground"`
	Size       string       `json:"size"`
	Gap        int          `json:"gap"`
	Padding    int          `json:"padding"`
	Children   []DesignNode `json:"children"`
}
type GeneratedDesign struct {
	Palette map[string]string `json:"palette"`
	Blocks  []DesignNode      `json:"blocks"`
}

var hexColor = regexp.MustCompile(`^#[0-9a-fA-F]{6}$`)

func colorLum(s string) float64 {
	var r, g, b uint8
	fmt.Sscanf(s, "#%02x%02x%02x", &r, &g, &b)
	channel := func(v uint8) float64 {
		f := float64(v) / 255
		if f <= 0.04045 {
			return f / 12.92
		}
		return math.Pow((f+0.055)/1.055, 2.4)
	}
	return .2126*channel(r) + .7152*channel(g) + .0722*channel(b)
}
func contrast(a, b string) float64 {
	x, y := colorLum(a), colorLum(b)
	if x < y {
		x, y = y, x
	}
	return (x + .05) / (y + .05)
}
func (d GeneratedDesign) validatePalette() error {
	keys := []string{"primary", "secondary", "accent", "light_background", "light_surface", "dark_background", "dark_surface"}
	if len(d.Palette) != len(keys) {
		return errors.New("invalid generated palette")
	}
	for _, k := range keys {
		if !hexColor.MatchString(d.Palette[k]) {
			return errors.New("invalid generated palette color")
		}
	}
	p := d.Palette
	if colorLum(p["light_background"]) < .55 || colorLum(p["light_surface"]) < .55 || colorLum(p["dark_background"]) > .08 || colorLum(p["dark_surface"]) > .12 || contrast(p["primary"], p["dark_background"]) < 4.5 || contrast(p["light_background"], p["dark_background"]) < 7 || contrast(p["light_background"], p["dark_surface"]) < 4.5 || contrast(p["dark_background"], p["light_surface"]) < 4.5 {
		return errors.New("generated palette lacks required contrast")
	}
	return nil
}
func (d GeneratedDesign) validate(goal string) error {
	if err := d.validatePalette(); err != nil {
		return err
	}
	p := d.Palette
	count, h1, form, cta := 0, 0, 0, 0
	var walk func([]DesignNode, int, string, string) error
	walk = func(nodes []DesignNode, depth int, inheritedBG, inheritedFG string) error {
		if depth > 5 {
			return errors.New("layout too deep")
		}
		for _, n := range nodes {
			count++
			if count > 100 {
				return errors.New("layout too large")
			}
			if !contains([]string{"stack", "row", "heading", "text", "cta", "image", "form", "icon", "divider"}, n.Kind) || !contains([]string{"none", "light-background", "dark-background", "brand", "light-surface", "dark-surface"}, n.Background) || !contains([]string{"inherit", "light-background", "dark-background", "brand"}, n.Foreground) || !contains([]string{"sm", "md", "lg", "display-sm", "display"}, n.Size) || !containsInt([]int{0, 8, 16, 24, 32, 48, 64, 80}, n.Gap) || !containsInt([]int{0, 16, 24, 32, 48, 64, 80}, n.Padding) {
				return errors.New("invalid generated block style")
			}
			if n.Kind != "stack" && n.Kind != "row" && len(n.Children) != 0 {
				return errors.New("leaf block contains children")
			}
			if n.Kind == "stack" || n.Kind == "row" {
				if len(n.Children) == 0 || len(n.Children) > 8 {
					return errors.New("invalid layout children")
				}
			} else if n.Kind == "heading" || n.Kind == "text" || n.Kind == "cta" {
				if !plain(n.Text, 500) {
					return errors.New("invalid generated text")
				}
			}
			if n.Kind == "heading" && n.Size == "display" {
				h1++
			}
			if n.Kind == "form" {
				form++
			}
			if n.Kind == "cta" {
				cta++
			}
			if n.Kind == "image" && !plain(n.Text, 200) {
				return errors.New("invalid image description")
			}
			if n.Kind == "icon" && !contains([]string{"arrow_outward", "explore", "lightbulb", "check_circle", "favorite", "bolt"}, n.Text) {
				return errors.New("invalid icon")
			}
			bg, fg := inheritedBG, inheritedFG
			tokens := map[string]string{"light-background": p["light_background"], "light-surface": p["light_surface"], "dark-background": p["dark_background"], "dark-surface": p["dark_surface"], "brand": p["primary"]}
			if n.Background != "none" {
				bg = tokens[n.Background]
			}
			if n.Foreground != "inherit" {
				fg = tokens[n.Foreground]
			}
			if contains([]string{"heading", "text", "cta", "form", "icon"}, n.Kind) && contrast(bg, fg) < 4.5 {
				return errors.New("generated block has insufficient contrast")
			}
			if err := walk(n.Children, depth+1, bg, fg); err != nil {
				return err
			}
		}
		return nil
	}
	if len(d.Blocks) < 1 || len(d.Blocks) > 8 {
		return errors.New("invalid number of sections")
	}
	if err := walk(d.Blocks, 0, p["light_background"], p["dark_background"]); err != nil {
		return err
	}
	if h1 != 1 || cta < 1 || (goal == "inquiry" && form != 1) || (goal == "booking" && form != 0) {
		return errors.New("invalid funnel structure")
	}
	return nil
}
func containsInt(a []int, v int) bool {
	for _, x := range a {
		if x == v {
			return true
		}
	}
	return false
}
func compileGeneratedDesign(b Brief, d GeneratedDesign, id string, submitLabel ...string) (FunnelArtifact, error) {
	if err := d.validate(b.Goal); err != nil {
		return FunnelArtifact{}, err
	}
	n := 0
	block := func(t string, p map[string]any) NativeBlock {
		n++
		h := sha256.Sum256([]byte(fmt.Sprintf("%s:%d", id, n)))
		h[6] = (h[6] & 15) | 64
		h[8] = (h[8] & 63) | 128
		return NativeBlock{ID: fmt.Sprintf("%x-%x-%x-%x-%x", h[:4], h[4:6], h[6:8], h[8:10], h[10:16]), ClientID: fmt.Sprintf("ai-%03d", n), Type: t, Payload: p}
	}
	// Reserve a stable form id before compiling CTA links.
	formID := ""
	if b.Goal == "inquiry" {
		h := sha256.Sum256([]byte(id + ":form"))
		h[6] = (h[6] & 15) | 64
		h[8] = (h[8] & 63) | 128
		formID = fmt.Sprintf("%x-%x-%x-%x-%x", h[:4], h[4:6], h[6:8], h[8:10], h[10:16])
	}
	var countCTAs func([]DesignNode) int
	countCTAs = func(nodes []DesignNode) int {
		total := 0
		for _, v := range nodes {
			if v.Kind == "cta" {
				total++
			}
			total += countCTAs(v.Children)
		}
		return total
	}
	remainingCTAs := countCTAs(d.Blocks)
	var walk func([]DesignNode, string, string) ([]NativeBlock, error)
	walk = func(nodes []DesignNode, inheritedFG, parentKind string) ([]NativeBlock, error) {
		out := []NativeBlock{}
		hasForm := false
		for _, node := range nodes {
			if node.Kind == "form" {
				hasForm = true
			}
		}
		for _, v := range nodes {
			if hasForm && v.Kind == "cta" && remainingCTAs > 1 {
				remainingCTAs--
				continue
			}
			fg := v.Foreground
			if fg == "inherit" {
				fg = inheritedFG
			}
			p := map[string]any{"bg_color": v.Background, "text_color": fg, "margin": 0, "padding": v.Padding}
			var x NativeBlock
			switch v.Kind {
			case "stack", "row":
				children, e := walk(v.Children, fg, v.Kind)
				if e != nil {
					return nil, e
				}
				p["children"] = children
				p["display"] = "flex"
				p["gap"] = v.Gap
				p["flex_wrap"] = "wrap"
				p["align_items"] = "stretch"
				if v.Kind == "row" {
					p["align_items"] = "flex-start"
				}
				if parentKind == "row" {
					p["flex_grow"] = "1"
					p["flex_shrink"] = "1"
					p["flex_basis"] = "240px"
					if hasDisplay(v.Children) {
						p["flex_grow"] = "2"
						p["flex_basis"] = "380px"
					}
				}
				if v.Kind == "row" {
					p["variant"] = "row"
					p["dir"] = "row"
				} else {
					p["variant"] = "stack"
					p["dir"] = "column"
				}
				x = block("layout", p)
			case "heading":
				p["text"] = v.Text
				p["font_size"] = v.Size
				if v.Size == "sm" || v.Size == "md" {
					p["font_size"] = "xl"
				}
				p["font_family"] = "georgia"
				p["font_weight"] = "normal"
				p["level"] = 2
				if v.Size == "display" {
					p["level"] = 1
				}
				x = block("heading", p)
			case "text":
				p["body"] = v.Text
				p["font_size"] = v.Size
				x = block("text", p)
			case "icon":
				p["name"] = v.Text
				p["size"] = "32"
				x = block("icon", p)
			case "divider":
				p["thickness"] = 1
				x = block("divider", p)
			case "image":
				if b.IllustrationURL == "" {
					return nil, errors.New("image requires an illustration")
				}
				p["url"] = b.IllustrationURL
				p["media_id"] = strings.TrimSuffix(strings.TrimPrefix(b.IllustrationURL, "/media/"), "/file")
				p["alt"] = v.Text
				p["width"] = "100"
				x = block("image", p)
			case "cta":
				if v.Background == "none" {
					p["bg_color"] = "dark-background"
					p["text_color"] = "light-background"
				}
				p["label"] = v.Text
				p["url"] = b.BookingURL
				if b.Goal == "inquiry" {
					p["url"] = "#cms-field-" + formID + "-name"
				}
				p["style"] = "primary"
				p["width"] = "auto"
				p["align_self"] = "flex-start"
				if v.Padding == 0 {
					p["padding"] = 16
				}
				x = block("cta", p)
			case "form":
				if parentKind == "row" {
					p["flex_grow"] = "1"
					p["flex_shrink"] = "1"
					p["flex_basis"] = "360px"
				}
				labels := []string{"Your name", "Email", "Phone", "What would you like to discuss?"}
				if b.Language == "cs" {
					labels = []string{"Vaše jméno", "E-mailová adresa", "Telefon", "O čem si chcete promluvit?"}
				}
				fields := []NativeBlock{}
				for i, f := range []struct{ name, control string }{{"name", "text"}, {"email", "email"}, {"phone", "tel"}, {"details", "textarea"}} {
					full := 1
					if i < 2 {
						full = 0
					}
					fields = append(fields, block("form_field", map[string]any{"name": f.name, "control": f.control, "label": labels[i], "required": 1, "full": full}))
				}
				p["children"] = fields
				p["action"] = "site_inquiries.create"
				label := "Send inquiry"
				if b.Language == "cs" {
					label = "Odeslat poptávku"
				}
				if len(submitLabel) > 0 && plain(submitLabel[0], 100) {
					label = submitLabel[0]
				}
				p["submit_label"] = label
				p["input_bg_color"] = "light-background"
				p["input_text_color"] = "dark-background"
				p["input_border_color"] = "dark-background"
				p["columns"] = 2
				p["input_padding"] = 14
				p["input_radius"] = 4
				p["submit_padding"] = 16
				p["submit_radius"] = 4
				p["submit_bg_color"] = "dark-background"
				p["submit_text_color"] = "light-background"
				x = block("form", p)
				x.ID = formID
			}
			out = append(out, x)
		}
		return out, nil
	}
	blocks, err := walk(d.Blocks, "dark-background", "")
	if err != nil {
		return FunnelArtifact{}, err
	}
	palette := map[string]string{}
	for k, v := range d.Palette {
		palette[k] = v
	}
	palette["storefront_mode"] = "light"
	headingText := ""
	var pageHeading func([]DesignNode)
	pageHeading = func(nodes []DesignNode) {
		for _, node := range nodes {
			if node.Kind == "heading" && node.Size == "display" {
				headingText = node.Text
			}
			pageHeading(node.Children)
		}
	}
	pageHeading(d.Blocks)
	return FunnelArtifact{Schema: 1, ID: id, Title: generatedPageTitle(b.Name, headingText), Slug: b.Slug, Blocks: blocks, Palette: palette}, nil
}
func designUsesImage(nodes []DesignNode) bool {
	for _, n := range nodes {
		if n.Kind == "image" || designUsesImage(n.Children) {
			return true
		}
	}
	return false
}

func hasDisplay(nodes []DesignNode) bool {
	for _, n := range nodes {
		if n.Kind == "heading" && n.Size == "display" {
			return true
		}
		if hasDisplay(n.Children) {
			return true
		}
	}
	return false
}

// Conversion requirements belong to the compiler, rather than model cardinality guesses.
// Only native semantic roles are normalized; style, content and contrast validation remain strict.
func (d *GeneratedDesign) normalizeStructure(goal, headline, ctaText string) {
	var headings []*DesignNode
	var walk func([]DesignNode) []DesignNode
	forms, ctas := 0, 0
	walk = func(nodes []DesignNode) []DesignNode {
		out := make([]DesignNode, 0, len(nodes))
		for _, node := range nodes {
			if node.Kind == "form" {
				if goal != "inquiry" || forms > 0 {
					continue
				}
				forms++
			}
			if node.Kind == "cta" {
				ctas++
			}
			if node.Kind == "stack" || node.Kind == "row" {
				node.Children = walk(node.Children)
				if len(node.Children) == 0 {
					continue
				}
			}
			out = append(out, node)
		}
		return out
	}
	d.Blocks = walk(d.Blocks)
	var collect func([]DesignNode)
	collect = func(nodes []DesignNode) {
		for i := range nodes {
			if nodes[i].Kind == "heading" {
				headings = append(headings, &nodes[i])
			}
			collect(nodes[i].Children)
		}
	}
	collect(d.Blocks)
	rank := map[string]int{"sm": 0, "md": 1, "lg": 2, "display-sm": 3, "display": 4}
	var hero *DesignNode
	for _, heading := range headings {
		if hero == nil || rank[heading.Size] > rank[hero.Size] {
			hero = heading
		}
	}
	for _, heading := range headings {
		if heading == hero {
			heading.Size = "display"
		} else if heading.Size == "display" {
			heading.Size = "display-sm"
		}
	}
	// Missing conversion elements use known schema-safe nodes and validated copy, never markup.
	appendNode := func(node DesignNode, first bool) {
		index := len(d.Blocks) - 1
		if first {
			index = 0
		}
		if index >= 0 && (d.Blocks[index].Kind == "stack" || d.Blocks[index].Kind == "row") && len(d.Blocks[index].Children) < 8 {
			node.Foreground = d.Blocks[index].Foreground
			if node.Foreground == "inherit" {
				switch d.Blocks[index].Background {
				case "dark-background", "dark-surface":
					node.Foreground = "light-background"
				default:
					node.Foreground = "dark-background"
				}
			}
			d.Blocks[index].Children = append(d.Blocks[index].Children, node)
		} else {
			d.Blocks = append(d.Blocks, node)
		}
	}
	native := func(kind, text string) DesignNode {
		return DesignNode{Kind: kind, Text: text, Background: "none", Foreground: "inherit", Size: "md", Children: []DesignNode{}}
	}
	if hero == nil {
		heading := native("heading", headline)
		heading.Size = "display"
		appendNode(heading, true)
	}
	if ctas == 0 {
		appendNode(native("cta", ctaText), true)
	}
	if goal == "inquiry" && forms == 0 {
		appendNode(native("form", ""), false)
	}
}
