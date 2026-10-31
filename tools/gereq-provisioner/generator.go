package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"unicode/utf8"
)

const generatorVersion = "funnel-ai-layout-19"

var slugPattern = regexp.MustCompile(`^[a-z][a-z0-9-]{2,79}$`)

type Brief struct {
	IllustrationURL string   `json:"illustration_url,omitempty"`
	Schema          int      `json:"schema_version"`
	Name            string   `json:"name"`
	Offer           string   `json:"offer"`
	Audience        string   `json:"audience"`
	Language        string   `json:"language"`
	Slug            string   `json:"slug"`
	Goal            string   `json:"goal"`
	BookingURL      string   `json:"booking_url"`
	Facts           []string `json:"facts"`
}
type CopyItem struct {
	Title string `json:"title"`
	Body  string `json:"body"`
}
type FunnelSpec struct {
	Design        *GeneratedDesign `json:"design,omitempty"`
	Layout        string           `json:"layout"`
	Eyebrow       string           `json:"eyebrow"`
	Headline      string           `json:"headline"`
	Intro         string           `json:"intro"`
	CTA           string           `json:"cta"`
	BenefitsTitle string           `json:"benefits_title"`
	Benefits      []CopyItem       `json:"benefits"`
	ProcessTitle  string           `json:"process_title"`
	Process       []CopyItem       `json:"process"`
	FAQTitle      string           `json:"faq_title"`
	FAQ           []CopyItem       `json:"faq"`
	ContactTitle  string           `json:"contact_title"`
	ContactBody   string           `json:"contact_body"`
}
type NativeBlock struct {
	ID        string         `json:"id"`
	ClientID  string         `json:"client_id"`
	SortOrder int            `json:"sort_order,omitempty"`
	Type      string         `json:"type"`
	Payload   map[string]any `json:"payload"`
}
type FunnelArtifact struct {
	Schema  int               `json:"schema_version"`
	ID      string            `json:"artifact_id"`
	Title   string            `json:"title"`
	Slug    string            `json:"slug"`
	Blocks  []NativeBlock     `json:"blocks"`
	Palette map[string]string `json:"palette,omitempty"`
}
type CopyProvider interface {
	Generate(context.Context, Brief) (FunnelSpec, error)
}

func plain(s string, max int) bool {
	return strings.TrimSpace(s) != "" && utf8.ValidString(s) && utf8.RuneCountInString(s) <= max && !strings.ContainsAny(s, "<>\x00")
}
func (b Brief) validate() error {
	if b.IllustrationURL != "" && !regexp.MustCompile(`^/media/[a-f0-9-]{36}/file$`).MatchString(b.IllustrationURL) {
		return errors.New("illustration_url must reference a native media asset")
	}
	if b.Schema != 1 || !plain(b.Name, 100) || !plain(b.Offer, 1800) || !plain(b.Audience, 800) || (b.Language != "cs" && b.Language != "en") || !slugPattern.MatchString(b.Slug) || (b.Goal != "inquiry" && b.Goal != "booking") || len(b.Facts) > 12 {
		return errors.New("invalid brief: require schema 1, name, offer, audience, cs/en language, slug and inquiry/booking goal")
	}
	for _, f := range b.Facts {
		if !plain(f, 400) {
			return errors.New("invalid brief fact")
		}
	}
	if b.Goal == "booking" {
		u, e := url.Parse(b.BookingURL)
		if e != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || len(b.BookingURL) > 2000 {
			return errors.New("booking_url must be an absolute HTTPS URL without credentials")
		}
	} else if b.BookingURL != "" {
		return errors.New("booking_url is only supported for booking goal")
	}
	return nil
}
func (s FunnelSpec) validate() error {
	if s.Layout != "editorial" && s.Layout != "centered" {
		return errors.New("unsupported funnel layout")
	}
	for _, v := range []string{s.Eyebrow, s.CTA, s.BenefitsTitle, s.ProcessTitle, s.FAQTitle, s.ContactTitle} {
		if !plain(v, 100) {
			return errors.New("invalid short copy")
		}
	}
	if !plain(s.Headline, 140) || !plain(s.Intro, 500) || !plain(s.ContactBody, 400) {
		return errors.New("invalid headline or body")
	}
	for _, group := range [][]CopyItem{s.Benefits, s.Process, s.FAQ} {
		if len(group) != 3 {
			return errors.New("each content group requires exactly three items")
		}
		for _, v := range group {
			if !plain(v.Title, 100) || !plain(v.Body, 400) {
				return errors.New("invalid content item")
			}
		}
	}
	return nil
}

// Layouts contain only known native properties. Model output never becomes properties or markup.
func compileFunnel(b Brief, s FunnelSpec, id string) (FunnelArtifact, error) {
	if err := b.validate(); err != nil {
		return FunnelArtifact{}, err
	}
	if s.Design != nil {
		return compileGeneratedDesign(b, *s.Design, id, s.CTA)
	}
	if err := s.validate(); err != nil {
		return FunnelArtifact{}, err
	}
	if s.Layout == "editorial" {
		return compileApprovedEditorial(b, s, id)
	}
	n := 0
	block := func(t string, p map[string]any) NativeBlock {
		n++
		p["margin"] = 0
		sum := sha256.Sum256([]byte(fmt.Sprintf("%s:%d", id, n)))
		sum[6] = (sum[6] & 15) | 64
		sum[8] = (sum[8] & 63) | 128
		uuid := fmt.Sprintf("%x-%x-%x-%x-%x", sum[0:4], sum[4:6], sum[6:8], sum[8:10], sum[10:16])
		return NativeBlock{ID: uuid, ClientID: fmt.Sprintf("gen-%03d", n), Type: t, Payload: p}
	}
	heading := func(t string, l int) NativeBlock {
		return block("heading", map[string]any{"text": t, "level": l, "font_weight": "normal", "font_family": "georgia", "text_color": "ink"})
	}
	text := func(t string) NativeBlock { return block("text", map[string]any{"body": t, "text_color": "muted"}) }
	label := func(t string) NativeBlock {
		return block("text", map[string]any{"body": t, "text_color": "brand", "font_size": "sm", "font_weight": "semibold"})
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
	section := func(children []NativeBlock, bg string) NativeBlock {
		v := stack(children, 32)
		for k, val := range map[string]any{"padding_top": 48, "padding_bottom": 48, "padding_left": 24, "padding_right": 24, "bg_color": bg} {
			v.Payload[k] = val
		}
		return v
	}
	about, process, faqLabel, contactLabel, heroCTA := "01 / The possibilities", "02 / Working together", "03 / Good to know", "04 / Start a conversation", "Get in touch"
	fieldsLabel := map[string]string{"name": "Your name", "email": "Email address", "details": "What would you like to discuss?"}
	if b.Language == "cs" {
		about, process, faqLabel, contactLabel, heroCTA = "01 / Nový pohled", "02 / Společný postup", "03 / Než začneme", "04 / Váš další krok", "Pojďme si promluvit"
		fieldsLabel = map[string]string{"name": "Vaše jméno", "email": "E-mailová adresa", "details": "Co chcete společně probrat?"}
	}
	var contactAction NativeBlock
	target := b.BookingURL
	if b.Goal == "inquiry" {
		fields := []NativeBlock{}
		for _, f := range []struct{ Name, Control string }{{"name", "text"}, {"email", "email"}, {"details", "textarea"}} {
			fields = append(fields, block("form_field", map[string]any{"name": f.Name, "control": f.Control, "label": fieldsLabel[f.Name], "required": 1, "full": 1}))
		}
		contactAction = block("form", map[string]any{"action": "site_inquiries.create", "submit_label": s.CTA, "submit_full": 1, "error_summary": 1, "input_bg_color": "background", "input_text_color": "ink", "input_border_color": "muted", "submit_bg_color": "brand", "children": fields})
		// The native form renderer gives its name input this stable DOM id.
		target = "#cms-field-" + contactAction.ID + "-name"
	} else {
		heroCTA = s.CTA
		contactAction = block("cta", map[string]any{"label": s.CTA, "url": target, "style": "primary", "bg_color": "brand", "text_color": "ink", "padding": 16, "align_self": "flex-start", "radius": 3})
	}
	button := func() NativeBlock {
		return block("cta", map[string]any{"label": heroCTA, "url": target, "style": "primary", "bg_color": "brand", "text_color": "ink", "padding": 16, "align_self": "flex-start", "radius": 3})
	}
	title := heading(s.Headline, 1)
	intro := text(s.Intro)
	intro.Payload["font_size"] = "lg"
	var hero NativeBlock
	if s.Layout == "editorial" {
		titleCol := item(stack([]NativeBlock{label(s.Eyebrow), title}, 24), "520px")
		introCol := item(stack([]NativeBlock{intro, button()}, 28), "280px")
		hero = row([]NativeBlock{titleCol, introCol}, 64)
		hero.Payload["align_items"] = "flex-end"
	} else {
		title.Payload["align"] = "center"
		intro.Payload["align"] = "center"
		hero = stack([]NativeBlock{label(s.Eyebrow), title, intro, button()}, 24)
		hero.Payload["align_items"] = "center"
	}
	heroSection := section([]NativeBlock{hero}, "background")
	heroSection.Payload["padding_top"] = 64
	heroSection.Payload["padding_bottom"] = 64
	benefits := []NativeBlock{}
	for i, v := range s.Benefits {
		icon := block("icon", map[string]any{"name": []string{"explore", "lightbulb", "arrow_outward"}[i], "size": "32", "text_color": "brand"})
		card := stack([]NativeBlock{icon, heading(v.Title, 3), text(v.Body)}, 20)
		card.Payload["padding"] = 28
		card.Payload["bg_color"] = "surface"
		card.Payload["radius"] = 2
		benefits = append(benefits, item(card, "240px"))
	}
	benefitSection := section([]NativeBlock{label(about), heading(s.BenefitsTitle, 2), row(benefits, 20)}, "background")
	steps := []NativeBlock{}
	for i, v := range s.Process {
		num := label(fmt.Sprintf("%02d", i+1))
		num.Payload["font_size"] = "xl"
		numberCol := item(stack([]NativeBlock{num}, 0), "40px")
		numberCol.Payload["flex_grow"] = "0"
		numberCol.Payload["flex_shrink"] = "0"
		line := row([]NativeBlock{numberCol, item(stack([]NativeBlock{heading(v.Title, 3), text(v.Body)}, 12), "300px")}, 20)
		steps = append(steps, line)

	}
	processHead := item(stack([]NativeBlock{label(process), heading(s.ProcessTitle, 2)}, 20), "300px")
	processBody := item(stack(steps, 28), "440px")
	processSection := section([]NativeBlock{row([]NativeBlock{processHead, processBody}, 64)}, "surface")
	faqs := []NativeBlock{}
	for _, v := range s.FAQ {
		faqs = append(faqs, stack([]NativeBlock{heading(v.Title, 3), text(v.Body)}, 12))

	}
	faqSection := section([]NativeBlock{row([]NativeBlock{item(stack([]NativeBlock{label(faqLabel), heading(s.FAQTitle, 2)}, 20), "300px"), item(stack(faqs, 24), "440px")}, 64)}, "background")
	contactHead := item(stack([]NativeBlock{label(contactLabel), heading(s.ContactTitle, 2), text(s.ContactBody)}, 24), "300px")
	contactPanel := item(stack([]NativeBlock{contactAction}, 0), "400px")
	contactPanel.Payload["padding"] = 28
	contactPanel.Payload["bg_color"] = "background"
	contactPanel.Payload["radius"] = 2
	contactSection := section([]NativeBlock{row([]NativeBlock{contactHead, contactPanel}, 64)}, "surface")
	return FunnelArtifact{Schema: 1, ID: id, Title: generatedPageTitle(b.Name, s.Headline), Slug: b.Slug, Blocks: []NativeBlock{heroSection, benefitSection, processSection, faqSection, contactSection}}, nil
}

func strictJSON(raw []byte, v any) error {
	d := json.NewDecoder(bytes.NewReader(raw))
	d.DisallowUnknownFields()
	if err := d.Decode(v); err != nil {
		return err
	}
	if d.Decode(new(any)) != io.EOF {
		return errors.New("expected one JSON value")
	}
	return nil
}

type GenerationResult struct {
	Artifact string          `json:"artifact"`
	JobID    string          `json:"job_id"`
	Reused   bool            `json:"reused"`
	Imported json.RawMessage `json:"imported,omitempty"`
}

func generate(ctx context.Context, root, site, briefFile, specFile string, p CopyProvider, model string, media ...func(context.Context) (string, error)) (GenerationResult, error) {
	return generateWithNativeTemplate(ctx, root, site, briefFile, specFile, p, model, nil, media...)
}
func generateWithNativeTemplate(ctx context.Context, root, site, briefFile, specFile string, p CopyProvider, model string, template *NativeTemplate, media ...func(context.Context) (string, error)) (GenerationResult, error) {
	result := GenerationResult{}
	if !sitePattern.MatchString(site) {
		return result, errors.New("invalid site ID")
	}
	var b Brief
	if err := decode(briefFile, &b); err != nil {
		return result, err
	}
	if err := b.validate(); err != nil {
		return result, err
	}
	var supplied []byte
	var err error
	if specFile != "" {
		supplied, err = os.ReadFile(specFile)
		if err != nil {
			return result, err
		}
		if len(supplied) > 32<<10 {
			return result, errors.New("spec too large")
		}
		model = "provided-spec"
	}
	input, _ := json.Marshal(struct {
		Version, Site, Model string
		Brief                Brief
		Spec                 json.RawMessage
		Template             *NativeTemplate `json:"template,omitempty"`
	}{generatorVersion, site, model, b, supplied, template})
	hash := sha256.Sum256(input)
	id := "gen-" + hex.EncodeToString(hash[:12])
	result.JobID = id
	// Use a job-specific lock; model calls never hold the registry-wide provisioning lock.
	job := filepath.Join(root, "generations", site, id)
	if err = os.MkdirAll(job, 0700); err != nil {
		return result, err
	}
	lockEngine := Engine{Root: job}
	unlock, err := lockEngine.lock()
	if err != nil {
		return result, err
	}
	defer unlock()
	result.Artifact = filepath.Join(job, "artifact.json")
	if raw, e := os.ReadFile(result.Artifact); e == nil {
		var a FunnelArtifact
		if strictJSON(raw, &a) != nil || a.ID != id {
			return result, errors.New("invalid saved generation")
		}
		result.Reused = true
		return result, nil
	} else if !os.IsNotExist(e) {
		return result, e
	}
	var spec FunnelSpec
	if supplied != nil {
		err = strictJSON(supplied, &spec)
	} else {
		if p == nil {
			return result, errors.New("AI provider is not configured")
		}
		spec, err = p.Generate(ctx, b)
	}
	if err != nil {
		return result, err
	}
	if ((template != nil && templateHasImages(template.Blocks)) || (template == nil && ((spec.Design != nil && designUsesImage(spec.Design.Blocks)) || (spec.Design == nil && spec.Layout == "editorial")))) && b.IllustrationURL == "" && len(media) > 0 && media[0] != nil {
		b.IllustrationURL, err = media[0](ctx)
		if err != nil {
			return result, err
		}
	}
	var a FunnelArtifact
	if template != nil {
		a, err = compileNativeTemplate(b, spec, *template, id)
	} else {
		a, err = compileFunnel(b, spec, id)
	}
	if err != nil {
		return result, err
	}
	a.Title = generatedPageTitle(b.Name, spec.Headline)
	if template != nil {
		if err = atomicJSON(filepath.Join(job, "template.json"), template); err != nil {
			return result, err
		}
	}
	// Persist copy and provenance first. The artifact is the commit point for a reusable job.
	for name, v := range map[string]any{"brief.json": b, "spec.json": spec, "provenance.json": map[string]any{"compiler": generatorVersion, "model": model, "input_sha256": hex.EncodeToString(hash[:])}} {
		raw, _ := json.MarshalIndent(v, "", "  ")
		if err = atomicFile(filepath.Join(job, name), raw); err != nil {
			return result, err
		}
	}
	raw, _ := json.MarshalIndent(a, "", "  ")
	if err = atomicFile(result.Artifact, raw); err != nil {
		return result, err
	}
	return result, nil
}

func generatedPageTitle(name, headline string) string {
	name = strings.TrimSpace(name)
	headline = strings.TrimSpace(headline)
	if headline == "" {
		headline = name
	}
	title := headline
	if name != "" && name != headline {
		title = name + " — " + headline
	}
	runes := []rune(title)
	if len(runes) > 190 {
		title = string(runes[:189]) + "…"
	}
	return title
}
