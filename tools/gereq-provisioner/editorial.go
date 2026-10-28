package main

import (
	"crypto/sha256"
	"fmt"
	"html"
	"strings"
)

// compileEditorial translates the approved HTML concept into editable native blocks.
func compileEditorial(b Brief, s FunnelSpec, id string) FunnelArtifact {
	n := 0
	block := func(t string, p map[string]any) NativeBlock {
		n++
		p["margin"] = 0
		sum := sha256.Sum256([]byte(fmt.Sprintf("%s:%d", id, n)))
		sum[6] = (sum[6] & 15) | 64
		sum[8] = (sum[8] & 63) | 128
		uuid := fmt.Sprintf("%x-%x-%x-%x-%x", sum[:4], sum[4:6], sum[6:8], sum[8:10], sum[10:16])
		return NativeBlock{ID: uuid, ClientID: fmt.Sprintf("editorial-%03d", n), Type: t, Payload: p}
	}
	rich := func(t string) string { return strings.ReplaceAll(html.EscapeString(t), "\n", "<br>") }
	heading := func(t string, l int, size, color string) NativeBlock {
		return block("heading", map[string]any{"text": rich(t), "level": l, "font_family": "georgia", "font_weight": "normal", "font_size": size, "text_color": color})
	}
	text := func(t, color string) NativeBlock {
		return block("text", map[string]any{"body": rich(t), "font_size": "md", "text_color": color})
	}
	label := func(t, color string) NativeBlock {
		return block("text", map[string]any{"body": strings.ToUpper(html.EscapeString(t)), "font_size": "sm", "font_weight": "semibold", "text_color": color})
	}
	stack := func(children []NativeBlock, gap int) NativeBlock {
		return block("layout", map[string]any{"variant": "stack", "display": "flex", "dir": "column", "gap": gap, "children": children})
	}
	item := func(v NativeBlock, basis string) NativeBlock {
		v.Payload["flex_basis"] = basis
		v.Payload["flex_grow"] = "1"
		v.Payload["flex_shrink"] = "1"
		return v
	}
	row := func(children []NativeBlock, gap int) NativeBlock {
		return block("layout", map[string]any{"variant": "row", "display": "flex", "dir": "row", "flex_wrap": "wrap", "gap": gap, "align_items": "stretch", "children": children})
	}
	section := func(children []NativeBlock, bg string, space int) NativeBlock {
		v := stack(children, 32)
		v.Payload["bg_color"] = bg
		v.Payload["padding_top"] = space
		v.Payload["padding_bottom"] = space
		v.Payload["padding_left"] = 24
		v.Payload["padding_right"] = 24
		return v
	}
	line := func(color string) NativeBlock {
		v := stack([]NativeBlock{}, 0)
		v.Payload["min_height"] = 1
		v.Payload["bg_color"] = color
		v.Payload["opacity"] = 20
		return v
	}
	light, dark := "light-background", "dark-background"
	contactKicker, benefitKicker, processKicker, faqKicker, heroCTA := "04 / Your next step", "What you take away", "02 / How it works", "03 / Before we begin", "Let’s talk"
	names := []string{"Your name", "Email address", "What would you like to discuss?"}
	if b.Language == "cs" {
		contactKicker, benefitKicker, processKicker, faqKicker, heroCTA = "04 / Teď je řada na vás", "Co si odnesete", "02 / Jak to probíhá", "03 / Ještě vás zajímá", "Pojďme si promluvit"
		names = []string{"Vaše jméno", "E-mailová adresa", "O čem si chcete promluvit?"}
	}
	fields := []NativeBlock{}
	for i, f := range []struct{ Name, Control string }{{"name", "text"}, {"email", "email"}, {"details", "textarea"}} {
		fields = append(fields, block("form_field", map[string]any{"name": f.Name, "control": f.Control, "label": names[i], "required": 1, "full": func() int {
			if f.Name == "details" {
				return 1
			}
			return 0
		}()}))
	}
	form := block("form", map[string]any{"action": "site_inquiries.create", "submit_label": s.CTA, "submit_full": 0, "submit_bg_color": dark, "submit_text_color": light, "submit_radius": 30, "submit_padding": 18, "input_bg_color": "brand", "input_text_color": dark, "input_border_color": dark, "input_focus_color": dark, "input_radius": 0, "input_padding": 14, "error_summary": 1, "children": fields})
	target := b.BookingURL
	if b.Goal == "inquiry" {
		target = "#cms-field-" + form.ID + "-name"
	} else {
		heroCTA = s.CTA
	}
	button := func() NativeBlock {
		return block("cta", map[string]any{"label": heroCTA + "  ↗", "url": target, "style": "primary", "bg_color": dark, "text_color": light, "padding": 18, "radius": 12, "align_self": "flex-start", "width": "auto", "font_size": "sm", "font_weight": "semibold"})
	}
	heroTitle := heading(s.Headline, 1, "display", dark)
	if parts := strings.SplitN(s.Headline, ". ", 2); len(parts) == 2 {
		heroTitle.Payload["text"] = html.EscapeString(parts[0]+".") + "<br><em>" + html.EscapeString(parts[1]) + "</em>"
	}
	intro := text(s.Intro, "muted")
	heroCopy := item(stack([]NativeBlock{label(s.Eyebrow, dark), heroTitle, intro, button()}, 28), "420px")
	var artwork NativeBlock
	if b.IllustrationURL != "" {
		artwork = block("image", map[string]any{"url": b.IllustrationURL, "alt": "Ilustrace cesty od nápadu k jasnému směru. Aha. Tudy.", "img_fit": "contain", "ratio": "20 / 21", "priority": 1})
	} else {
		artwork = stack([]NativeBlock{heading("Aha. Tudy.", 2, "display-sm", dark), block("icon", map[string]any{"name": "arrow_outward", "size": "64", "text_color": dark})}, 32)
		artwork.Payload["bg_color"] = "brand"
		artwork.Payload["padding"] = 48
		artwork.Payload["min_height"] = 400
		artwork.Payload["radius"] = 20
	}
	hero := row([]NativeBlock{heroCopy, item(artwork, "400px")}, 64)
	hero.Payload["align_items"] = "center"
	blocks := []NativeBlock{section([]NativeBlock{hero}, light, 64)}
	tags := []string{"✳  New projects", "✳  Important decisions", "✳  Clear priorities", "✳  Practical steps"}
	if b.Language == "cs" {
		tags = []string{"✳  Nové projekty", "✳  Důležitá rozhodnutí", "✳  Jasné priority", "✳  Konkrétní kroky"}
	}
	tagBlocks := []NativeBlock{}
	for _, tag := range tags {
		tagBlocks = append(tagBlocks, item(label(tag, dark), "170px"))
	}
	blocks = append(blocks, section([]NativeBlock{line(dark), row(tagBlocks, 20), line(dark)}, light, 0))
	benefitHeader := stack([]NativeBlock{label(benefitKicker, dark), heading(s.BenefitsTitle, 2, "display-sm", dark)}, 20)
	cards := []NativeBlock{}
	for i, v := range s.Benefits {
		symbol := block("icon", map[string]any{"name": []string{"filter_alt", "lightbulb", "explore"}[i], "size": "24", "text_color": dark, "bg_color": "brand", "padding": 12, "radius": 50, "width": "auto", "align_self": "flex-start"})
		card := stack([]NativeBlock{symbol, heading(v.Title, 3, "2xl", dark), text(v.Body, "muted")}, 20)
		cards = append(cards, item(card, "240px"))
	}
	benefits := section([]NativeBlock{benefitHeader, row(cards, 40)}, light, 64)
	benefits.Payload["gap"] = 36
	blocks = append(blocks, benefits)
	processTitle := item(stack([]NativeBlock{label(processKicker, light), heading(s.ProcessTitle, 2, "display-sm", light), block("icon", map[string]any{"name": "arrow_outward", "size": "64", "text_color": "brand"})}, 28), "330px")
	steps := []NativeBlock{}
	for i, v := range s.Process {
		num := item(label(fmt.Sprintf("%02d", i+1), "brand"), "32px")
		num.Payload["flex_grow"] = "0"
		step := row([]NativeBlock{num, item(stack([]NativeBlock{heading(v.Title, 3, "2xl", light), text(v.Body, light)}, 16), "260px")}, 18)
		steps = append(steps, step)
		if i < 2 {
			steps = append(steps, line(light))
		}
	}
	blocks = append(blocks, section([]NativeBlock{row([]NativeBlock{processTitle, item(stack(steps, 28), "380px")}, 72)}, dark, 80))
	faqItems := []NativeBlock{}
	for _, v := range s.FAQ {
		faqItems = append(faqItems, stack([]NativeBlock{heading(v.Title, 3, "xl", dark), text(v.Body, "muted"), line(dark)}, 18))
	}
	faqHead := item(stack([]NativeBlock{label(faqKicker, dark), heading(s.FAQTitle, 2, "display-sm", dark)}, 24), "330px")
	blocks = append(blocks, section([]NativeBlock{row([]NativeBlock{faqHead, item(stack(faqItems, 28), "380px")}, 72)}, light, 88))
	contactHead := item(stack([]NativeBlock{label(contactKicker, dark), heading(s.ContactTitle, 2, "display-sm", dark), text(s.ContactBody, dark)}, 24), "330px")
	var action NativeBlock
	if b.Goal == "inquiry" {
		action = form
	} else {
		action = button()
	}
	blocks = append(blocks, section([]NativeBlock{row([]NativeBlock{contactHead, item(stack([]NativeBlock{action}, 0), "380px")}, 72)}, "brand", 72))
	return FunnelArtifact{1, id, b.Name + " — " + s.Headline, b.Slug, blocks}
}
